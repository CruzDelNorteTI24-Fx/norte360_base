(function () {
  const cfg = window.N360_FCC || {};
  const endpoint = cfg.endpoint || 'control_conductores_salidas.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};
  const tripMap = new Map();
  const rowBaselines = new Map();
  let pendingPaymentExport = '';
  let bulkMode = false;

  const clean = (value) => String(value || '').replace(/[ \t]+/g, ' ').replace(/\n\s+/g, '\n').trim();
  const compact = (value) => clean(value).replace(/\s+/g, ' ');
  const keyText = (value) => compact(value).toLowerCase();
  const stamp = () => new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');
  const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
  const cssEscape = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
    return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  };
  const isCanceledRevision = (value) => compact(value).toUpperCase() === 'ANULADO';
  const isReturnTrip = (value) => compact(value).toUpperCase() === 'RETORNO';
  const tripDirection = (value) => {
    const direction = compact(value).toUpperCase();
    return ['PENDIENTE', 'IDA', 'RETORNO'].includes(direction) ? direction : 'PENDIENTE';
  };

  function statusClass(value) {
    const status = compact(value).toUpperCase() || 'PENDIENTE';
    if (status === 'VALIDADO') return 'fcc-status--ok';
    if (status === 'OBSERVADO') return 'fcc-status--warn';
    if (status === 'CORREGIDO') return 'fcc-status--info';
    if (status === 'ANULADO') return 'fcc-status--void';
    if (status === 'MANUAL') return 'fcc-status--manual';
    if (status === 'TRANSBORDADO' || status === 'TRANSBORDO') return 'fcc-status--transfer';
    if (status === 'SIN SALIDA') return 'fcc-status--muted';
    return 'fcc-status--pending';
  }

  function driverStateText(value) {
    const state = compact(value).toUpperCase();
    if (state === 'PAGADO') return 'OK';
    return state || '-';
  }

  function normalizeMoneyValue(value) {
    const raw = String(value ?? '').trim().replace(',', '.');
    if (!raw) return '';
    const match = raw.match(/^(\d{1,16})(?:\.(\d{0,4}))?$/);
    if (!match) return raw;
    const integer = (match[1].replace(/^0+(?=\d)/, '') || '0');
    const decimal = String(match[2] || '').padEnd(4, '0').slice(0, 4);
    return `${integer}.${decimal}`;
  }

  function moneyDisplayValue(value) {
    const raw = String(value ?? '').trim().replace(',', '.');
    if (!raw) return '0.00';
    const match = raw.match(/^(\d{1,17})(?:\.(\d+))?$/);
    if (!match) return raw;
    let integer = match[1].replace(/^0+(?=\d)/, '') || '0';
    const decimals = String(match[2] || '');
    if (!decimals) return `${integer}.00`;
    if (decimals.length <= 2) return `${integer}.${decimals.padEnd(2, '0')}`;
    return `${integer}.${decimals}`;
  }

  function moneyInputRaw(input) {
    if (!input) return '';
    const source = document.activeElement === input ? input.value : (input.dataset.fccMoneyRaw ?? input.value);
    return normalizeMoneyValue(source);
  }

  function setMoneyInputValue(input, value, showRaw, displaySource) {
    if (!input) return;
    const normalized = normalizeMoneyValue(value);
    const display = String(displaySource ?? value ?? '').trim().replace(',', '.');
    input.dataset.fccMoneyRaw = normalized;
    input.dataset.fccMoneyDisplay = display;
    input.value = showRaw ? normalized : moneyDisplayValue(display || normalized);
  }

  function displayMoneyInput(input) {
    setMoneyInputValue(input, moneyInputRaw(input), false, input?.dataset?.fccMoneyDisplay || '');
  }

  function editMoneyInput(input) {
    setMoneyInputValue(input, moneyInputRaw(input), true);
  }

  function moneyText(value) {
    const raw = String(value ?? '').trim();
    return raw ? `S/ ${moneyDisplayValue(raw)}` : '-';
  }

  function moneyNumber(value) {
    const raw = normalizeMoneyValue(value);
    if (!raw || !/^\d+(?:\.\d+)?$/.test(raw)) return 0;
    const amount = Number(raw);
    return Number.isFinite(amount) ? amount : 0;
  }

  function formatMoneyAmount(amount) {
    const value = Number(amount || 0);
    if (!Number.isFinite(value)) return '0.00';
    const rounded4 = Math.round(value * 10000) / 10000;
    const rounded2 = Math.round(rounded4 * 100) / 100;
    const maxDecimals = Math.abs(rounded4 - rounded2) > 0.000001 ? 4 : 2;
    return rounded4.toLocaleString('es-PE', {
      minimumFractionDigits: 2,
      maximumFractionDigits: maxDecimals
    });
  }

  function moneyReportText(value) {
    return `S/ ${formatMoneyAmount(moneyNumber(value))}`;
  }

  function signedMoneyReportText(value) {
    const amount = Number(value || 0);
    if (!Number.isFinite(amount)) return 'S/ 0.00';
    const sign = amount < 0 ? '-' : '';
    return `${sign}S/ ${formatMoneyAmount(Math.abs(amount))}`;
  }

  function hojaRutaStateText(trip) {
    const hojaRuta = compact(trip?.hoja_ruta || '');
    if (!hojaRuta) return 'PENDIENTE';
    if (trip?.hoja_ruta_duplicada) return 'DUPLICADA';
    if (trip?.hoja_ruta_validada) return 'VALIDADA';
    return 'REGISTRADA';
  }

  function monthParts() {
    const match = String(cfg.month || '').match(/^(\d{4})-(\d{2})$/);
    return match ? { year: match[1], month: match[2] } : null;
  }

  function driverDateFromRow(row) {
    const rawDate = compact(row.date || '');
    if (/^\d{4}-\d{2}-\d{2}$/.test(rawDate)) {
      const [year, month, day] = rawDate.split('-');
      return { key: rawDate, label: `${day}/${month}/${year}` };
    }

    const dayMatch = String(row.dayNumber || row.dia || '').match(/\d{1,2}/);
    const parts = monthParts();
    if (!dayMatch || !parts) {
      const fallback = compact(row.dia || '-');
      return { key: fallback, label: fallback };
    }

    const day = dayMatch[0].padStart(2, '0');
    return { key: `${parts.year}-${parts.month}-${day}`, label: `${day}/${parts.month}/${parts.year}` };
  }

  function formatDateValue(value) {
    const raw = compact(value);
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : (raw || '-');
  }

  function normalizeDateKey(value) {
    const raw = compact(value);
    return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : '';
  }

  function selectedMonthBounds() {
    const match = String(cfg.month || '').match(/^(\d{4})-(\d{2})$/);
    if (!match) return { from: '', to: '' };
    const year = Number(match[1]);
    const month = Number(match[2]);
    const lastDay = new Date(year, month, 0).getDate();
    const lastDayText = String(lastDay).padStart(2, '0');
    return {
      from: `${match[1]}-${match[2]}-01`,
      to: `${match[1]}-${match[2]}-${lastDayText}`
    };
  }

  function visiblePaymentDateBounds(units) {
    const dates = [];
    (units || []).forEach((unit) => {
      (unit.rows || []).forEach((row) => {
        if (!row || !row.id || row.id === '0') return;
        const date = normalizeDateKey(row.date);
        if (date) dates.push(date);
      });
    });
    dates.sort();
    return { from: dates[0] || '', to: dates[dates.length - 1] || '' };
  }

  function paymentRangeDefaults(units) {
    const month = selectedMonthBounds();
    const visible = visiblePaymentDateBounds(units);
    return {
      from: month.from || visible.from,
      to: month.to || visible.to,
      min: month.from || visible.from,
      max: month.to || visible.to
    };
  }

  function dateInRange(value, from, to) {
    const date = normalizeDateKey(value);
    if (!date) return false;
    if (from && date < from) return false;
    if (to && date > to) return false;
    return true;
  }

  function filterUnitsByPaymentRange(units, range) {
    const from = normalizeDateKey(range?.from);
    const to = normalizeDateKey(range?.to);
    return (units || []).map((unit) => ({
      ...unit,
      rows: (unit.rows || []).filter((row) => dateInRange(row.date, from, to))
    })).filter((unit) => (unit.rows || []).length > 0);
  }

  function paymentRangeLabel(range) {
    const from = normalizeDateKey(range?.from);
    const to = normalizeDateKey(range?.to);
    if (!from && !to) return cfg.monthLabel || cfg.month || '-';
    if (from && to) return `${formatDateValue(from)} al ${formatDateValue(to)}`;
    if (from) return `Desde ${formatDateValue(from)}`;
    return `Hasta ${formatDateValue(to)}`;
  }

  function paymentRangeFileSuffix(range) {
    const from = normalizeDateKey(range?.from).replace(/-/g, '');
    const to = normalizeDateKey(range?.to).replace(/-/g, '');
    return from && to ? `${from}_${to}` : 'rango';
  }

  function formatDateTimeValue(value) {
    const raw = compact(value);
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : (raw || '-');
  }

  function nullableText(value) {
    const text = compact(value);
    return text && text.toUpperCase() !== 'NULL' ? text : '-';
  }

  function buildTripMap() {
    tripMap.clear();
    (Array.isArray(cfg.units) ? cfg.units : []).forEach((unit) => {
      (Array.isArray(unit.rows) ? unit.rows : []).forEach((trip) => {
        const id = String(trip.id || '');
        if (!id || id === '0') return;
        tripMap.set(id, {
          ...trip,
          unitLabel: unit.label || '',
          unitBus: unit.bus || '',
          unitPlaca: unit.placa || ''
        });
      });
    });
  }

  function setTripField(name, value) {
    const target = document.querySelector(`[data-fcc-trip-field="${name}"]`);
    if (target) target.textContent = nullableText(value);
  }

  function syncTripFromRow(trip, row) {
    if (!trip || !row) return trip;
    const idaVuelta = row.querySelector('[data-fcc-field="ida_vuelta"]');
    const viajeImporte = row.querySelector('[data-fcc-field="viaje_importe"]');
    const viajeComentario = row.querySelector('[data-fcc-field="viaje_comentario"]');
    const cond1Estado = row.querySelector('[data-fcc-field="cond1_estado"]');
    const cond1Importe = row.querySelector('[data-fcc-field="cond1_importe"]');
    const cond1Obs = row.querySelector('[data-fcc-field="cond1_observacion"]');
    const cond2Estado = row.querySelector('[data-fcc-field="cond2_estado"]');
    const cond2Importe = row.querySelector('[data-fcc-field="cond2_importe"]');
    const cond2Obs = row.querySelector('[data-fcc-field="cond2_observacion"]');
    if (idaVuelta) trip.ida_vuelta = tripDirection(idaVuelta.value);
    if (viajeImporte) trip.viaje_importe = moneyInputRaw(viajeImporte);
    if (viajeComentario) trip.viaje_comentario = viajeComentario.value;
    if (cond1Estado) trip.cond1_estado = cond1Estado.value;
    if (cond1Importe) trip.cond1_importe = moneyInputRaw(cond1Importe);
    if (cond1Obs) trip.cond1_observacion = cond1Obs.value;
    if (cond2Estado) trip.cond2_estado = cond2Estado.value;
    if (cond2Importe) trip.cond2_importe = moneyInputRaw(cond2Importe);
    if (cond2Obs) trip.cond2_observacion = cond2Obs.value;
    return trip;
  }

  function showNotice(message, ok) {
    let box = document.querySelector('[data-fcc-notice]');
    if (!box) {
      box = document.createElement('div');
      box.dataset.fccNotice = '1';
      box.className = 'fcc-notice';
      document.body.appendChild(box);
    }
    box.textContent = message;
    box.classList.toggle('fcc-notice--ok', !!ok);
    box.classList.toggle('fcc-notice--bad', !ok);
    box.classList.add('is-visible');
    window.clearTimeout(box._fccTimer);
    box._fccTimer = window.setTimeout(() => box.classList.remove('is-visible'), 3000);
  }

  function syncSelectClass(select) {
    if (!select) return;
    if (!select.matches('[data-fcc-field="cond1_estado"], [data-fcc-field="cond2_estado"]')) return;
    select.classList.remove('fcc-pay--ok', 'fcc-pay--pending', 'fcc-pay--empty');
    const value = String(select.value || '').toUpperCase();
    if (select.disabled || !value) {
      select.classList.add('fcc-pay--empty');
    } else if (value === 'PAGADO') {
      select.classList.add('fcc-pay--ok');
    } else {
      select.classList.add('fcc-pay--pending');
    }
  }

  function rowFields(row) {
    return {
      ida_vuelta: row.querySelector('[data-fcc-field="ida_vuelta"]'),
      viaje_importe: row.querySelector('[data-fcc-field="viaje_importe"]'),
      viaje_comentario: row.querySelector('[data-fcc-field="viaje_comentario"]'),
      cond1_estado: row.querySelector('[data-fcc-field="cond1_estado"]'),
      cond1_importe: row.querySelector('[data-fcc-field="cond1_importe"]'),
      cond1_observacion: row.querySelector('[data-fcc-field="cond1_observacion"]'),
      cond2_estado: row.querySelector('[data-fcc-field="cond2_estado"]'),
      cond2_importe: row.querySelector('[data-fcc-field="cond2_importe"]'),
      cond2_observacion: row.querySelector('[data-fcc-field="cond2_observacion"]')
    };
  }

  function rowValues(row) {
    const fields = rowFields(row);
    return {
      id: row.dataset.fccRow || '',
      ida_vuelta: tripDirection(fields.ida_vuelta?.value),
      viaje_importe: moneyInputRaw(fields.viaje_importe),
      viaje_comentario: fields.viaje_comentario?.value || '',
      cond1_estado: fields.cond1_estado?.value || '',
      cond1_importe: moneyInputRaw(fields.cond1_importe),
      cond1_observacion: fields.cond1_observacion?.value || '',
      cond2_estado: fields.cond2_estado?.value || '',
      cond2_importe: moneyInputRaw(fields.cond2_importe),
      cond2_observacion: fields.cond2_observacion?.value || ''
    };
  }

  function comparableRowValues(values) {
    return {
      ida_vuelta: tripDirection(values.ida_vuelta),
      viaje_importe: normalizeMoneyValue(values.viaje_importe),
      viaje_comentario: String(values.viaje_comentario || ''),
      cond1_estado: compact(values.cond1_estado).toUpperCase(),
      cond1_importe: normalizeMoneyValue(values.cond1_importe),
      cond1_observacion: String(values.cond1_observacion || ''),
      cond2_estado: compact(values.cond2_estado).toUpperCase(),
      cond2_importe: normalizeMoneyValue(values.cond2_importe),
      cond2_observacion: String(values.cond2_observacion || '')
    };
  }

  function updateTripTotalState(row) {
    if (!row) return;
    const fields = rowFields(row);
    const totalInput = fields.viaje_importe;
    const diff = row.querySelector('[data-fcc-total-diff]');
    const wrapper = totalInput?.closest('.fcc-money-field');
    const retorno = row.dataset.fccRetorno === '1' || isReturnTrip(fields.ida_vuelta?.value);
    const skip = row.dataset.fccAnulado === '1' || !totalInput || retorno;

    row.classList.remove('is-total-mismatch', 'is-total-match');
    wrapper?.classList.remove('is-mismatch', 'is-match');
    if (diff) diff.classList.remove('is-bad', 'is-ok');

    if (skip) {
      if (diff) diff.textContent = retorno ? 'Retorno sin pagos' : '';
      return;
    }

    const rawTotal = moneyInputRaw(totalInput);
    const rawCond1 = moneyInputRaw(fields.cond1_importe);
    const rawCond2 = moneyInputRaw(fields.cond2_importe);
    const hasAnyAmount = rawTotal !== '' || rawCond1 !== '' || rawCond2 !== '';
    const total = moneyNumber(rawTotal);
    const condSum = moneyNumber(rawCond1) + moneyNumber(rawCond2);
    const balance = Math.round((total - condSum) * 10000) / 10000;
    const mismatch = hasAnyAmount && Math.abs(balance) >= 0.0001;
    const match = hasAnyAmount && !mismatch;

    row.classList.toggle('is-total-mismatch', mismatch);
    row.classList.toggle('is-total-match', match);
    wrapper?.classList.toggle('is-mismatch', mismatch);
    wrapper?.classList.toggle('is-match', match);

    if (diff) {
      diff.classList.toggle('is-bad', mismatch);
      diff.classList.toggle('is-ok', match);
      diff.textContent = !hasAnyAmount ? 'Esperando pagos' : (mismatch ? `Dif. ${signedMoneyReportText(balance)}` : 'Cuadra');
    }
  }

  function totalStatusFromTrip(trip) {
    if (!trip) return '-';
    if (isReturnTrip(trip.ida_vuelta)) return 'Retorno sin pagos';
    const rawTotal = normalizeMoneyValue(trip.viaje_importe);
    const rawCond1 = normalizeMoneyValue(trip.cond1_importe);
    const rawCond2 = normalizeMoneyValue(trip.cond2_importe);
    const hasAnyAmount = rawTotal !== '' || rawCond1 !== '' || rawCond2 !== '';
    if (!hasAnyAmount) return 'Esperando pagos';
    const balance = Math.round((moneyNumber(rawTotal) - moneyNumber(rawCond1) - moneyNumber(rawCond2)) * 10000) / 10000;
    return Math.abs(balance) >= 0.0001 ? `Diferencia ${signedMoneyReportText(balance)}` : 'Cuadra';
  }

  function rememberRow(row) {
    const id = row?.dataset?.fccRow || '';
    if (!id || id === '0') return;
    if (row.dataset.fccAnulado === '1') return;
    rowBaselines.set(id, comparableRowValues(rowValues(row)));
    row.classList.remove('is-bulk-dirty');
  }

  function restoreRow(row) {
    const id = row?.dataset?.fccRow || '';
    const baseline = rowBaselines.get(id);
    if (!baseline) return;
    const fields = rowFields(row);
    Object.entries(baseline).forEach(([name, value]) => {
      if (fields[name]) fields[name].value = value;
    });
    setMoneyInputValue(fields.viaje_importe, baseline.viaje_importe || '', false);
    setMoneyInputValue(fields.cond1_importe, baseline.cond1_importe || '', false);
    setMoneyInputValue(fields.cond2_importe, baseline.cond2_importe || '', false);
    applyRoundTripState(row, false);
    row.querySelectorAll('select').forEach(syncSelectClass);
    row.classList.remove('is-bulk-dirty');
  }

  function applyRoundTripState(row, clearDriverFields) {
    if (!row) return;
    const fields = rowFields(row);
    const direction = tripDirection(fields.ida_vuelta?.value);
    const retorno = isReturnTrip(direction);
    const outbound = direction === 'IDA';
    const editable = row.dataset.fccEditable === '1';

    row.dataset.fccRetorno = retorno ? '1' : '0';
    row.classList.toggle('is-retorno', retorno);
    if (fields.ida_vuelta) {
      fields.ida_vuelta.value = direction;
      fields.ida_vuelta.classList.toggle('is-return', retorno);
      fields.ida_vuelta.classList.toggle('is-outbound', outbound);
      fields.ida_vuelta.classList.toggle('is-pending', direction === 'PENDIENTE');
    }

    [
      { hasDriver: row.dataset.fccCond1 === '1', state: fields.cond1_estado, amount: fields.cond1_importe },
      { hasDriver: row.dataset.fccCond2 === '1', state: fields.cond2_estado, amount: fields.cond2_importe }
    ].forEach((driver) => {
      if (retorno) {
        if (clearDriverFields) {
          if (driver.state) driver.state.value = '';
          if (driver.amount) setMoneyInputValue(driver.amount, '', false);
        }
        if (driver.state) driver.state.disabled = true;
        if (driver.amount) driver.amount.disabled = true;
        return;
      }

      const canEdit = editable && driver.hasDriver;
      if (driver.state) {
        driver.state.disabled = !canEdit;
        if (canEdit && driver.state.value === '') {
          driver.state.value = 'PENDIENTE';
        }
      }
      if (driver.amount) {
        driver.amount.disabled = !canEdit;
      }
    });

    row.querySelectorAll('[data-fcc-field="cond1_estado"], [data-fcc-field="cond2_estado"]').forEach(syncSelectClass);
    updateTripTotalState(row);
  }

  function dirtyRows() {
    return Array.from(document.querySelectorAll('[data-fcc-row].is-bulk-dirty'))
      .filter((row) => row.dataset.fccRow && row.dataset.fccRow !== '0' && row.dataset.fccAnulado !== '1');
  }

  function updateBulkUi() {
    const panel = document.querySelector('[data-fcc-bulk-panel]');
    const count = dirtyRows().length;
    const countText = document.querySelector('[data-fcc-bulk-count]');
    const save = document.querySelector('[data-fcc-bulk-save]');
    const cancel = document.querySelector('[data-fcc-bulk-cancel]');
    const toggle = document.querySelector('[data-fcc-bulk-toggle]');
    if (panel) panel.classList.toggle('is-active', bulkMode);
    if (countText) countText.textContent = `${count} fila${count === 1 ? '' : 's'} modificada${count === 1 ? '' : 's'}`;
    if (save) save.disabled = !bulkMode || count === 0;
    if (cancel) cancel.disabled = !bulkMode || count === 0;
    if (toggle) {
      toggle.classList.toggle('fcc-btn--primary', bulkMode);
      toggle.classList.toggle('fcc-btn--soft', !bulkMode);
      toggle.innerHTML = bulkMode
        ? '<i class="bi bi-toggles2"></i> Desactivar masivo'
        : '<i class="bi bi-toggles"></i> Activar masivo';
    }
  }

  function markRowChange(row) {
    updateTripTotalState(row);
    if (!row || !bulkMode) return;
    const id = row.dataset.fccRow || '';
    if (!id || id === '0') return;
    if (row.dataset.fccAnulado === '1') return;
    const baseline = rowBaselines.get(id) || comparableRowValues(rowValues(row));
    const changed = JSON.stringify(comparableRowValues(rowValues(row))) !== JSON.stringify(baseline);
    row.classList.toggle('is-bulk-dirty', changed);
    updateBulkUi();
  }

  function setBulkMode(active) {
    bulkMode = !!active;
    document.body.classList.toggle('fcc-bulk-mode', bulkMode);
    if (bulkMode) {
      document.querySelectorAll('[data-fcc-row]').forEach(markRowChange);
    }
    updateBulkUi();
  }

  function validateAndNormalizeRow(row) {
    const amountInputs = Array.from(row.querySelectorAll('[data-fcc-field="viaje_importe"], [data-fcc-field="cond1_importe"], [data-fcc-field="cond2_importe"]'));
    const invalidAmount = amountInputs.find((input) => !input.disabled && !input.checkValidity());
    if (invalidAmount) {
      invalidAmount.reportValidity();
      return false;
    }
    amountInputs.forEach((input) => {
      if (!input.disabled && moneyInputRaw(input) !== '') displayMoneyInput(input);
    });
    return true;
  }

  function applySavedRow(row, data) {
    if (!row) return;
    const fields = rowFields(row);
    if (fields.ida_vuelta && data && Object.prototype.hasOwnProperty.call(data, 'ida_vuelta')) fields.ida_vuelta.value = tripDirection(data.ida_vuelta);
    if (fields.viaje_importe) setMoneyInputValue(fields.viaje_importe, data?.viaje_importe ?? moneyInputRaw(fields.viaje_importe), false);
    if (fields.viaje_comentario && data && Object.prototype.hasOwnProperty.call(data, 'viaje_comentario')) fields.viaje_comentario.value = data.viaje_comentario || '';
    if (fields.cond1_estado && data && Object.prototype.hasOwnProperty.call(data, 'cond1_estado')) fields.cond1_estado.value = data.cond1_estado || '';
    if (fields.cond1_importe) setMoneyInputValue(fields.cond1_importe, data?.cond1_importe ?? moneyInputRaw(fields.cond1_importe), false);
    if (fields.cond2_estado && data && Object.prototype.hasOwnProperty.call(data, 'cond2_estado')) fields.cond2_estado.value = data.cond2_estado || '';
    if (fields.cond2_importe) setMoneyInputValue(fields.cond2_importe, data?.cond2_importe ?? moneyInputRaw(fields.cond2_importe), false);
    applyRoundTripState(row, false);
    row.querySelectorAll('select').forEach(syncSelectClass);

    const id = row.dataset.fccRow || '';
    const savedTrip = tripMap.get(String(id));
    if (savedTrip) {
      syncTripFromRow(savedTrip, row);
      savedTrip.ida_vuelta = data && Object.prototype.hasOwnProperty.call(data, 'ida_vuelta') ? tripDirection(data.ida_vuelta) : savedTrip.ida_vuelta;
      savedTrip.viaje_importe = data?.viaje_importe ?? savedTrip.viaje_importe;
      savedTrip.viaje_comentario = data && Object.prototype.hasOwnProperty.call(data, 'viaje_comentario') ? (data.viaje_comentario || '') : savedTrip.viaje_comentario;
      savedTrip.cond1_estado = data && Object.prototype.hasOwnProperty.call(data, 'cond1_estado') ? (data.cond1_estado || '') : savedTrip.cond1_estado;
      savedTrip.cond1_importe = data?.cond1_importe ?? savedTrip.cond1_importe;
      savedTrip.cond2_estado = data && Object.prototype.hasOwnProperty.call(data, 'cond2_estado') ? (data.cond2_estado || '') : savedTrip.cond2_estado;
      savedTrip.cond2_importe = data?.cond2_importe ?? savedTrip.cond2_importe;
    }
    rememberRow(row);
  }

  async function saveRow(button) {
    const row = button.closest('[data-fcc-row]');
    if (!row) return;
    const id = row.dataset.fccRow || '';
    if (!id || id === '0') {
      showNotice('Este dia no tiene salida capturada.', false);
      return;
    }
    if (row.dataset.fccAnulado === '1') {
      showNotice('Este viaje esta anulado y no entra al control de pagos.', false);
      return;
    }

    if (!validateAndNormalizeRow(row)) {
      return;
    }

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'update_driver_status');
    fd.append('id', id);
    fd.append('ida_vuelta', tripDirection(row.querySelector('[data-fcc-field="ida_vuelta"]')?.value));
    fd.append('viaje_importe', moneyInputRaw(row.querySelector('[data-fcc-field="viaje_importe"]')));
    fd.append('viaje_comentario', row.querySelector('[data-fcc-field="viaje_comentario"]')?.value || '');
    fd.append('cond1_estado', row.querySelector('[data-fcc-field="cond1_estado"]')?.value || '');
    fd.append('cond1_importe', moneyInputRaw(row.querySelector('[data-fcc-field="cond1_importe"]')));
    fd.append('cond1_observacion', row.querySelector('[data-fcc-field="cond1_observacion"]')?.value || '');
    fd.append('cond2_estado', row.querySelector('[data-fcc-field="cond2_estado"]')?.value || '');
    fd.append('cond2_importe', moneyInputRaw(row.querySelector('[data-fcc-field="cond2_importe"]')));
    fd.append('cond2_observacion', row.querySelector('[data-fcc-field="cond2_observacion"]')?.value || '');

    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.ok) {
        throw new Error(json.message || 'No se pudo guardar.');
      }
      applySavedRow(row, json.data || rowValues(row));
      updateBulkUi();
      showNotice(json.message || 'Cambios guardados.', true);
    } catch (err) {
      showNotice(err.message || 'No se pudo guardar.', false);
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  }

  async function saveBulkRows(button) {
    const rows = dirtyRows();
    if (!rows.length) {
      showNotice('No hay filas modificadas para guardar.', false);
      return;
    }

    for (const row of rows) {
      if (!validateAndNormalizeRow(row)) return;
    }

    const items = rows.map(rowValues);
    const ok = window.confirm(`Deseas actualizar ${items.length} registro${items.length === 1 ? '' : 's'} de estados, pagos, comentarios y observaciones?`);
    if (!ok) return;

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'bulk_update_driver_status');
    fd.append('items', JSON.stringify(items));

    const original = button?.innerHTML || '';
    const toggle = document.querySelector('[data-fcc-bulk-toggle]');
    const cancel = document.querySelector('[data-fcc-bulk-cancel]');
    if (button) {
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando...';
    }
    if (toggle) toggle.disabled = true;
    if (cancel) cancel.disabled = true;

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.ok) {
        throw new Error(json.message || 'No se pudo guardar el lote.');
      }

      const savedRows = Array.isArray(json.data?.rows) ? json.data.rows : items;
      savedRows.forEach((item) => {
        const row = document.querySelector(`[data-fcc-row="${cssEscape(String(item.id || ''))}"]`);
        applySavedRow(row, item);
      });
      updateBulkUi();
      showNotice(json.message || 'Cambios masivos guardados.', true);
    } catch (err) {
      showNotice(err.message || 'No se pudo guardar el lote.', false);
    } finally {
      if (button) {
        button.innerHTML = original;
      }
      if (toggle) toggle.disabled = false;
      updateBulkUi();
    }
  }

  function cancelBulkChanges() {
    const rows = dirtyRows();
    if (!rows.length) {
      updateBulkUi();
      return;
    }
    const ok = window.confirm(`Deseas cancelar ${rows.length} cambio${rows.length === 1 ? '' : 's'} sin guardar?`);
    if (!ok) return;
    rows.forEach(restoreRow);
    updateBulkUi();
    showNotice('Cambios masivos cancelados.', true);
  }

  function setupBulkEdit() {
    document.querySelectorAll('[data-fcc-row]').forEach((row) => {
      applyRoundTripState(row, false);
      rememberRow(row);
      updateTripTotalState(row);
    });

    const toggle = document.querySelector('[data-fcc-bulk-toggle]');
    const save = document.querySelector('[data-fcc-bulk-save]');
    const cancel = document.querySelector('[data-fcc-bulk-cancel]');

    toggle?.addEventListener('click', () => {
      if (bulkMode && dirtyRows().length) {
        const ok = window.confirm('Hay cambios masivos sin guardar. Deseas salir y descartarlos?');
        if (!ok) return;
        dirtyRows().forEach(restoreRow);
      }
      setBulkMode(!bulkMode);
    });

    save?.addEventListener('click', () => saveBulkRows(save));
    cancel?.addEventListener('click', cancelBulkChanges);

    document.querySelectorAll('[data-fcc-field]').forEach((field) => {
      const eventName = field.matches('select') ? 'change' : 'input';
      field.addEventListener(eventName, () => {
        const row = field.closest('[data-fcc-row]');
        if (field.matches('[data-fcc-field="ida_vuelta"]')) {
          applyRoundTripState(row, true);
        }
        if (field.matches('select')) syncSelectClass(field);
        updateTripTotalState(row);
        markRowChange(row);
      });
    });

    setBulkMode(!!toggle && !toggle.disabled);
  }

  function setupSearch() {
    const input = document.querySelector('[data-fcc-search]');
    if (!input) return;
    const cards = Array.from(document.querySelectorAll('[data-fcc-unit]'));
    input.addEventListener('input', () => {
      const q = compact(input.value).toLowerCase();
      cards.forEach((card) => {
        const haystack = String(card.dataset.unitSearch || '').toLowerCase();
        card.classList.toggle('is-hidden', q !== '' && !haystack.includes(q));
      });
    });
  }

  function cellText(row, selector) {
    const el = row.querySelector(selector);
    if (!el) return '';
    if (el.matches('select, textarea, input')) return clean(el.value);
    return clean(el.textContent);
  }

  function collectUnitFromCard(card) {
    const title = clean(card.querySelector('.fcc-unit-toggle strong')?.textContent || 'Unidad');
    const rows = Array.from(card.querySelectorAll('tbody tr')).map((row) => {
      const dayCell = row.querySelector('[data-fcc-col="dia"]');
      const dayNumber = compact(row.dataset.fccDay || dayCell?.querySelector('strong')?.textContent || '');
      const weekday = compact(row.dataset.fccWeekday || dayCell?.querySelector('span')?.textContent || '');
      const date = compact(row.dataset.fccDate || (cfg.month && dayNumber ? `${cfg.month}-${dayNumber.padStart(2, '0')}` : ''));
      const tripIndex = Number(row.dataset.fccTripIndex || 0);
      const tripsDay = Number(row.dataset.fccTripsDay || 0);
      const hora = compact(row.dataset.fccHora || '');
      const revision = compact(row.dataset.fccRevision || cellText(row, '[data-fcc-col="revision"]'));
      const anulado = row.dataset.fccAnulado === '1' || isCanceledRevision(revision);
      const idaVuelta = tripDirection(cellText(row, '[data-fcc-field="ida_vuelta"]') || cellText(row, '[data-fcc-col="ida_vuelta"]'));
      const retorno = row.dataset.fccRetorno === '1' || isReturnTrip(idaVuelta);
      const origen = compact(row.dataset.fccOrigen || '');
      const destino = compact(row.dataset.fccDestino || '');
      const rutaSimple = (origen || destino) ? `${origen || '-'} -> ${destino || '-'}` : '-';

      return {
        id: row.dataset.fccRow || '',
        dia: [dayNumber, weekday].filter(Boolean).join(' '),
        date,
        dayNumber,
        weekday,
        tripIndex,
        tripsDay,
        hora,
        revision,
        anulado,
        idaVuelta,
        retorno,
        origen,
        destino,
        rutaSimple,
        viajeImporte: moneyInputRaw(row.querySelector('[data-fcc-field="viaje_importe"]')),
        viajeComentario: cellText(row, '[data-fcc-field="viaje_comentario"]'),
        cond1: cellText(row, '[data-fcc-col="cond1"]'),
        cond1Estado: cellText(row, '[data-fcc-field="cond1_estado"]'),
        cond1Importe: moneyInputRaw(row.querySelector('[data-fcc-field="cond1_importe"]')),
        cond1Obs: cellText(row, '[data-fcc-field="cond1_observacion"]'),
        cond2: cellText(row, '[data-fcc-col="cond2"]'),
        cond2Estado: cellText(row, '[data-fcc-field="cond2_estado"]'),
        cond2Importe: moneyInputRaw(row.querySelector('[data-fcc-field="cond2_importe"]')),
        cond2Obs: cellText(row, '[data-fcc-field="cond2_observacion"]')
      };
    });
    return { title, rows };
  }

  function visibleUnits() {
    return Array.from(document.querySelectorAll('[data-fcc-unit]'))
      .filter((card) => !card.classList.contains('is-hidden'))
      .map(collectUnitFromCard);
  }

  function summarizeDrivers(units) {
    const map = new Map();

    units.forEach((unit) => {
      const unitName = compact(unit.title || 'Unidad');
      (unit.rows || []).forEach((row) => {
        if (row.anulado || isCanceledRevision(row.revision)) return;
        if (row.retorno || isReturnTrip(row.idaVuelta)) return;
        [
          { name: row.cond1, estado: row.cond1Estado, obs: row.cond1Obs },
          { name: row.cond2, estado: row.cond2Estado, obs: row.cond2Obs }
        ].forEach((driver) => {
          const name = compact(driver.name);
          if (!name || name === '-') return;

          const key = keyText(name);
          if (!map.has(key)) {
            map.set(key, {
              conductor: name,
              trips: 0,
              pending: 0,
              paid: 0,
              observations: 0,
              buses: new Map()
            });
          }

          const item = map.get(key);
          const estado = compact(driver.estado).toUpperCase();
          const busKey = keyText(unitName);
          const workDate = driverDateFromRow(row);
          const busItem = item.buses.get(busKey) || {
            bus: unitName,
            trips: 0,
            dates: new Map()
          };
          item.trips += 1;
          busItem.trips += 1;
          if (workDate.label && workDate.label !== '-') {
            const dateKey = workDate.key || workDate.label;
            const dateItem = busItem.dates.get(dateKey) || {
              key: dateKey,
              label: workDate.label,
              trips: 0
            };
            dateItem.trips += 1;
            busItem.dates.set(dateKey, dateItem);
          }
          item.buses.set(busKey, busItem);
          if (estado === 'PAGADO') {
            item.paid += 1;
          } else {
            item.pending += 1;
          }
          if (compact(driver.obs)) {
            item.observations += 1;
          }
        });
      });
    });

    return Array.from(map.values()).map((item) => {
      const buses = Array.from(item.buses.values())
        .filter((bus) => bus && bus.bus)
        .map((bus) => {
          const datesDetail = Array.from(bus.dates?.values?.() || [])
            .sort((a, b) => String(a.key || '').localeCompare(String(b.key || '')));
          return {
            bus: bus.bus,
            trips: bus.trips,
            datesDetail,
            datesText: datesDetail.map((date) => {
              const trips = Number(date.trips || 0);
              return `${date.label} (${trips.toLocaleString('es-PE')} viaje${trips === 1 ? '' : 's'})`;
            }).join(', ')
          };
        })
        .sort((a, b) => {
          if (b.trips !== a.trips) return b.trips - a.trips;
          return a.bus.localeCompare(b.bus);
        });
      return {
        conductor: item.conductor,
        trips: item.trips,
        pending: item.pending,
        paid: item.paid,
        observations: item.observations,
        busesTotal: buses.length,
        busesText: buses.map((bus) => bus.bus).join(', '),
        busesDetail: buses
      };
    }).sort((a, b) => {
      if (b.trips !== a.trips) return b.trips - a.trips;
      return a.conductor.localeCompare(b.conductor);
    });
  }

  function driverSummaryTotals(summary) {
    const busSet = new Set();
    summary.forEach((item) => {
      String(item.busesText || '').split(',').forEach((bus) => {
        const value = compact(bus);
        if (value) busSet.add(value);
      });
    });
    return summary.reduce((acc, item) => {
      acc.drivers += 1;
      acc.trips += Number(item.trips || 0);
      acc.pending += Number(item.pending || 0);
      acc.paid += Number(item.paid || 0);
      return acc;
    }, { drivers: 0, trips: 0, buses: busSet.size, pending: 0, paid: 0 });
  }

  function renderDriverSummary(summary) {
    const body = document.querySelector('[data-fcc-driver-summary-body]');
    if (!body) return;

    const totals = driverSummaryTotals(summary);
    Object.entries(totals).forEach(([key, value]) => {
      const el = document.querySelector(`[data-fcc-driver-kpi="${key}"]`);
      if (el) el.textContent = Number(value || 0).toLocaleString('es-PE');
    });

    if (!summary.length) {
      body.innerHTML = '<tr><td colspan="6" class="fcc-driver-empty">No hay conductores en las unidades visibles.</td></tr>';
      return;
    }

    body.innerHTML = summary.map((item) => {
      const detailText = (item.busesDetail || []).map((bus) => `${bus.bus} ${bus.datesText || ''}`).join(' ');
      const haystack = escapeHtml(`${item.conductor} ${item.busesText} ${detailText}`.toLowerCase());
      const detailRows = (item.busesDetail || []).map((bus) => `
        <tr class="fcc-driver-bus-row" data-driver-summary-row data-driver-search="${haystack}">
          <td></td>
          <td colspan="5">
            <div class="fcc-driver-bus-detail">
              <span class="fcc-driver-bus-line"><i class="bi bi-bus-front"></i> ${escapeHtml(bus.bus)} <strong>${Number(bus.trips || 0).toLocaleString('es-PE')} viaje${Number(bus.trips || 0) === 1 ? '' : 's'}</strong></span>
              <small class="fcc-driver-bus-dates"><i class="bi bi-calendar2-week"></i> Fechas: ${escapeHtml(bus.datesText || '-')}</small>
            </div>
          </td>
        </tr>
      `).join('');

      return `
        <tr class="fcc-driver-main-row" data-driver-summary-row data-driver-search="${haystack}">
          <td><strong>${escapeHtml(item.conductor)}</strong></td>
          <td>${Number(item.trips || 0).toLocaleString('es-PE')}</td>
          <td><span>${Number(item.busesTotal || 0).toLocaleString('es-PE')}</span><small>${escapeHtml(item.busesText || '-')}</small></td>
          <td><span class="fcc-mini fcc-mini--pending">${Number(item.pending || 0).toLocaleString('es-PE')}</span></td>
          <td><span class="fcc-mini fcc-mini--paid">${Number(item.paid || 0).toLocaleString('es-PE')}</span></td>
          <td>${Number(item.observations || 0).toLocaleString('es-PE')}</td>
        </tr>
        ${detailRows}
      `;
    }).join('');
  }

  function setupDriverSummaryModal() {
    const button = document.querySelector('[data-fcc-driver-summary]');
    const modalEl = document.getElementById('fccDriverSummaryModal');
    const search = document.querySelector('[data-fcc-driver-search]');
    if (!button || !modalEl) return;

    const modal = window.bootstrap && window.bootstrap.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;

    button.addEventListener('click', () => {
      if (search) search.value = '';
      renderDriverSummary(summarizeDrivers(visibleUnits()));
      if (modal) {
        modal.show();
      } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
      }
    });

    if (search) {
      search.addEventListener('input', () => {
        const q = keyText(search.value);
        document.querySelectorAll('[data-driver-summary-row]').forEach((row) => {
          const haystack = String(row.dataset.driverSearch || '');
          row.classList.toggle('is-hidden', q !== '' && !haystack.includes(q));
        });
      });
    }
  }

  function activeTripRow(id) {
    return document.querySelector(`[data-fcc-row="${cssEscape(String(id || ''))}"]`);
  }

  function showTripDetail(id, sourceRow) {
    const modalEl = document.getElementById('fccTripDetailModal');
    const trip = tripMap.get(String(id || ''));
    if (!modalEl || !trip) {
      showNotice('No se encontro el detalle del viaje.', false);
      return;
    }

    syncTripFromRow(trip, sourceRow || activeTripRow(id));

    const bus = nullableText(trip.bus || trip.unitBus);
    const placa = nullableText(trip.placa || trip.unitPlaca);
    const title = document.querySelector('[data-fcc-trip-title]');
    const subtitle = document.querySelector('[data-fcc-trip-subtitle]');
    if (title) title.textContent = `${bus} (${placa})`;
    if (subtitle) {
      subtitle.textContent = `Viaje ${Number(trip.trip_index || 1)} de ${Number(trip.trips_day || 1)} - dia operativo ${formatDateValue(trip.date)}`;
    }

    setTripField('id', trip.id);
    setTripField('cierre_id', trip.cierre_id);
    setTripField('progid', trip.progid);
    setTripField('run_id', trip.run_id);
    setTripField('fecha_operativa', formatDateValue(trip.date));
    setTripField('fecha_salida_real', formatDateTimeValue(trip.fecha_salida_real));
    setTripField('horasalida', trip.hora || '-');
    setTripField('fecha_ejecucion', formatDateTimeValue(trip.fecha_ejecucion));
    setTripField('hora_orden', trip.hora_orden);
    setTripField('bus', bus);
    setTripField('placa', placa);
    setTripField('servicio', trip.servicio);
    setTripField('ida_vuelta', tripDirection(trip.ida_vuelta));
    setTripField('origen', trip.origen);
    setTripField('destino', trip.destino);
    setTripField('fecha_programacion', formatDateTimeValue(trip.fecha_programacion));
    setTripField('hoja_ruta', trip.hoja_ruta);
    setTripField('hoja_ruta_estado', hojaRutaStateText(trip));
    setTripField('ruta_texto', trip.ruta_texto);
    setTripField('comentario_horario', trip.comentario_horario);
    setTripField('viaje_importe', moneyText(trip.viaje_importe));
    setTripField('viaje_importe_estado', totalStatusFromTrip(trip));
    setTripField('viaje_comentario', trip.viaje_comentario);
    setTripField('cond1', trip.cond1);
    setTripField('cond1_estado', driverStateText(trip.cond1_estado));
    setTripField('cond1_importe', moneyText(trip.cond1_importe));
    setTripField('cond1_observacion', trip.cond1_observacion);
    setTripField('cond2', trip.cond2);
    setTripField('cond2_estado', driverStateText(trip.cond2_estado));
    setTripField('cond2_importe', moneyText(trip.cond2_importe));
    setTripField('cond2_observacion', trip.cond2_observacion);
    setTripField('comentario_revision', trip.comentario_revision);
    setTripField('correccion', trip.correccion);
    setTripField('usuario_revision', trip.usuario_revision);
    setTripField('datetime_revision', formatDateTimeValue(trip.datetime_revision));
    setTripField('usuario_creacion', trip.usuario_creacion);
    setTripField('fecha_creacion', formatDateTimeValue(trip.fecha_creacion));

    const status = document.querySelector('[data-fcc-trip-status]');
    if (status) {
      const value = compact(trip.revision || '').toUpperCase() || 'PENDIENTE';
      status.textContent = value;
      status.className = `fcc-status ${statusClass(value)}`;
    }

    const modal = window.bootstrap && window.bootstrap.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;
    if (modal) {
      modal.show();
    } else {
      modalEl.classList.add('show');
      modalEl.style.display = 'block';
    }
  }

  function tripRouteText(trip) {
    const origen = compact(trip.origen || '');
    const destino = compact(trip.destino || '');
    return (origen || destino) ? `${origen || '-'} -> ${destino || '-'}` : '-';
  }

  function tripConductorsText(trip) {
    return [trip.cond1, trip.cond2]
      .map(nullableText)
      .filter((value) => value && value !== '-')
      .join(' / ') || '-';
  }

  function collectCanceledTrips(units) {
    const canceled = [];
    units.forEach((unit) => {
      (unit.rows || []).forEach((row) => {
        if (!(row.anulado || isCanceledRevision(row.revision))) return;
        const detail = tripMap.get(String(row.id || '')) || {};
        const trip = {
          ...detail,
          ...row,
          unitTitle: unit.title || detail.unitLabel || detail.bus || 'Unidad',
          bus: detail.bus || detail.unitBus || '',
          placa: detail.placa || detail.unitPlaca || '',
          cond1: detail.cond1 || row.cond1 || '',
          cond2: detail.cond2 || row.cond2 || '',
          routeText: tripRouteText({ ...detail, ...row }),
          conductorsText: tripConductorsText(detail),
          dateLabel: formatDateValue(row.date || detail.date),
          timeLabel: row.hora || detail.hora || '-'
        };
        trip.searchText = keyText([
          trip.dateLabel,
          trip.unitTitle,
          trip.bus,
          trip.placa,
          trip.timeLabel,
          trip.routeText,
          trip.conductorsText,
          trip.revision
        ].join(' '));
        canceled.push(trip);
      });
    });
    return canceled.sort((a, b) => {
      const dateCompare = String(a.date || '').localeCompare(String(b.date || ''));
      if (dateCompare !== 0) return dateCompare;
      const timeCompare = String(a.timeLabel || '').localeCompare(String(b.timeLabel || ''));
      if (timeCompare !== 0) return timeCompare;
      return String(a.unitTitle || '').localeCompare(String(b.unitTitle || ''));
    });
  }

  function canceledTotals(trips) {
    const units = new Set();
    const drivers = new Set();
    trips.forEach((trip) => {
      const unit = compact(trip.unitTitle || trip.bus || '');
      if (unit) units.add(keyText(unit));
      [trip.cond1, trip.cond2].forEach((name) => {
        const driver = compact(name);
        if (driver && driver !== '-') drivers.add(keyText(driver));
      });
    });
    return { trips: trips.length, units: units.size, drivers: drivers.size };
  }

  function renderCanceledTrips(trips) {
    const body = document.querySelector('[data-fcc-canceled-body]');
    const totals = canceledTotals(trips);
    Object.entries(totals).forEach(([key, value]) => {
      const el = document.querySelector(`[data-fcc-canceled-kpi="${key}"]`);
      if (el) el.textContent = Number(value || 0).toLocaleString('es-PE');
    });
    const count = document.querySelector('[data-fcc-canceled-count]');
    if (count) count.textContent = Number(trips.length || 0).toLocaleString('es-PE');
    if (!body) return;

    if (!trips.length) {
      body.innerHTML = '<tr><td colspan="6" class="fcc-driver-empty">No hay viajes anulados en las unidades visibles.</td></tr>';
      return;
    }

    body.innerHTML = trips.map((trip) => `
      <tr data-canceled-row data-canceled-search="${escapeHtml(trip.searchText || '')}">
        <td><strong>${escapeHtml(trip.dateLabel || '-')}</strong></td>
        <td><span>${escapeHtml(trip.unitTitle || '-')}</span><small>${escapeHtml([trip.bus, trip.placa].map(compact).filter(Boolean).join(' / ') || '-')}</small></td>
        <td>${escapeHtml(trip.timeLabel || '-')}</td>
        <td>${escapeHtml(trip.routeText || '-')}</td>
        <td>${escapeHtml(trip.conductorsText || '-')}</td>
        <td><button type="button" class="fcc-icon-detail" data-fcc-canceled-view data-fcc-trip-id="${escapeHtml(String(trip.id || ''))}" title="Ver detalle del viaje" aria-label="Ver detalle del viaje"><i class="bi bi-eye-fill"></i></button></td>
      </tr>
    `).join('');
  }

  function setupCanceledTripsModal() {
    const button = document.querySelector('[data-fcc-canceled-summary]');
    const modalEl = document.getElementById('fccCanceledTripsModal');
    const search = document.querySelector('[data-fcc-canceled-search]');
    const body = document.querySelector('[data-fcc-canceled-body]');
    if (!button || !modalEl) return;

    const modal = window.bootstrap && window.bootstrap.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;

    button.addEventListener('click', () => {
      if (search) search.value = '';
      renderCanceledTrips(collectCanceledTrips(visibleUnits()));
      if (modal) {
        modal.show();
      } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
      }
    });

    search?.addEventListener('input', () => {
      const q = keyText(search.value);
      document.querySelectorAll('[data-canceled-row]').forEach((row) => {
        const haystack = String(row.dataset.canceledSearch || '');
        row.classList.toggle('is-hidden', q !== '' && !haystack.includes(q));
      });
    });

    body?.addEventListener('click', (event) => {
      const view = event.target.closest('[data-fcc-canceled-view]');
      if (!view) return;
      const id = view.dataset.fccTripId || '';
      const openDetail = () => showTripDetail(id, activeTripRow(id));
      if (modal) {
        modal.hide();
        window.setTimeout(openDetail, 160);
      } else {
        openDetail();
      }
    });

    renderCanceledTrips(collectCanceledTrips(visibleUnits()));
  }

  function setupTripDetailModal() {
    buildTripMap();

    document.querySelectorAll('[data-fcc-view-trip]').forEach((button) => {
      button.addEventListener('click', () => {
        showTripDetail(button.dataset.fccTripId || '', button.closest('[data-fcc-row]'));
      });
    });

    document.querySelectorAll('[data-fcc-row]').forEach((row) => {
      row.addEventListener('dblclick', (event) => {
        if (event.target.closest('button, a, input, select, textarea, label, [data-fcc-field]')) return;
        const id = row.dataset.fccRow || '';
        if (!id || id === '0') return;
        showTripDetail(id, row);
      });
    });
  }

  function drawInfo(doc, left, y, width, unit, unitIndex, unitsCount) {
    const summary = summarizeDrivers([unit]);
    const totals = driverSummaryTotals(summary);
    const canceled = collectCanceledTrips([unit]).length;
    if (window.N360PDF && typeof window.N360PDF.drawReportSummary === 'function') {
      return window.N360PDF.drawReportSummary(doc, {
        x: left,
        y,
        width,
        title: 'Unidad del reporte',
        rows: [
          { label: 'Mes operativo', value: cfg.monthLabel || cfg.month || '-' },
          { label: 'Unidad', value: unit.title || '-' },
          { label: 'Pagina de unidad', value: `${unitIndex + 1} de ${unitsCount}` },
          { label: 'Conductores / viajes', value: `${totals.drivers} / ${totals.trips}` },
          { label: 'Anulados', value: canceled.toLocaleString('es-PE') }
        ],
        columns: 2,
        bottomGap: 7
      });
    }


    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7.2);
    doc.text('UNIDAD DEL REPORTE', left, y + 6.5);
    doc.setTextColor(8, 36, 61);
    doc.setFontSize(7.5);
    doc.text(`Mes operativo: ${cfg.monthLabel || cfg.month || '-'}`, left, y + 12);
    doc.text(`Unidad: ${unit.title || '-'}`, left + width, y + 12, { align: 'right' });
    doc.text(`Anulados: ${canceled.toLocaleString('es-PE')}`, left, y + 17);
    doc.setDrawColor(210, 224, 238);
    doc.line(left, y + 21, left + width, y + 21);
    return y + 23;
  }

  function tableBody(unit) {
    let previousDate = '';
    return (unit.rows || []).map((row) => {
      const dateKey = compact(row.date || row.dia || '');
      const showDate = dateKey !== previousDate;
      previousDate = dateKey;
      const canceled = row.anulado || isCanceledRevision(row.revision);

      return [
        showDate ? (row.dia || '-') : '',
        `${row.revision || '-'}${row.hora ? `` : ''}`,
        tripDirection(row.idaVuelta),
        row.rutaSimple || '-',
        canceled ? '-' : (row.cond1 || '-'),
        canceled ? '-' : (row.cond1Obs || '-'),
        canceled ? '-' : (row.cond2 || '-'),
        canceled ? '-' : (row.cond2Obs || '-')
      ];
    });
  }

  function canceledPdfBody(trips) {
    return trips.map((trip) => [
      trip.dateLabel || '-',
      trip.unitTitle || '-',
      trip.timeLabel || '-',
      trip.routeText || '-',
      trip.conductorsText || '-',
      compact(trip.comentario_revision || trip.correccion || '') || '-'
    ]);
  }

  function driverSummaryBody(summary) {
    const rows = [];
    summary.forEach((item) => {
      rows.push([
        item.conductor || '-',
        Number(item.trips || 0).toLocaleString('es-PE'),
        `${Number(item.busesTotal || 0).toLocaleString('es-PE')} bus(es)`
      ]);
      (item.busesDetail || []).forEach((bus) => {
        rows.push([
          '',
          '',
          {
            content: `${bus.bus} - ${Number(bus.trips || 0).toLocaleString('es-PE')} viaje${Number(bus.trips || 0) === 1 ? '' : 's'} | Fechas: ${bus.datesText || '-'}`,
            styles: { fontSize: 6.5, textColor: [82, 105, 130], fillColor: [248, 251, 255] }
          }
        ]);
      });
    });
    return rows;
  }

  function paymentDetailRows(units) {
    const rows = [];
    units.forEach((unit) => {
      const unitName = compact(unit.title || 'Unidad');
      (unit.rows || []).forEach((row) => {
        if (!row || !row.id || row.id === '0') return;
        if (row.anulado || isCanceledRevision(row.revision)) return;
        if (row.retorno || isReturnTrip(row.idaVuelta)) return;

        const workDate = driverDateFromRow(row);
        [
          {
            role: 'Conductor 1',
            name: row.cond1,
            estado: row.cond1Estado,
            importe: row.cond1Importe,
            obs: row.cond1Obs
          },
          {
            role: 'Conductor 2',
            name: row.cond2,
            estado: row.cond2Estado,
            importe: row.cond2Importe,
            obs: row.cond2Obs
          }
        ].forEach((driver) => {
          const name = compact(driver.name);
          if (!name || name === '-') return;
          const amount = moneyNumber(driver.importe);
          rows.push({
            dateKey: workDate.key || row.date || '',
            fecha: workDate.label || formatDateValue(row.date),
            hora: row.hora || '-',
            unidad: unitName || '-',
            ruta: row.rutaSimple || '-',
            idaVuelta: tripDirection(row.idaVuelta),
            conductor: name,
            rol: driver.role,
            estado: driverStateText(driver.estado),
            estadoRaw: compact(driver.estado).toUpperCase(),
            importe: amount,
            importeTexto: moneyReportText(driver.importe),
            observacion: compact(driver.obs) || '-',
            revision: row.revision || '-'
          });
        });
      });
    });

    return rows.sort((a, b) => {
      const dateCompare = String(a.dateKey || '').localeCompare(String(b.dateKey || ''));
      if (dateCompare !== 0) return dateCompare;
      const timeCompare = String(a.hora || '').localeCompare(String(b.hora || ''));
      if (timeCompare !== 0) return timeCompare;
      const unitCompare = String(a.unidad || '').localeCompare(String(b.unidad || ''));
      if (unitCompare !== 0) return unitCompare;
      return String(a.rol || '').localeCompare(String(b.rol || ''));
    });
  }

  function paymentSummaryRows(rows) {
    const map = new Map();
    rows.forEach((row) => {
      const key = keyText(row.conductor);
      if (!map.has(key)) {
        map.set(key, {
          conductor: row.conductor,
          registros: 0,
          ok: 0,
          pendientes: 0,
          total: 0,
          unidades: new Set()
        });
      }
      const item = map.get(key);
      item.registros += 1;
      item.total += Number(row.importe || 0);
      item.unidades.add(keyText(row.unidad));
      if (row.estadoRaw === 'PAGADO' || row.estado === 'OK') {
        item.ok += 1;
      } else {
        item.pendientes += 1;
      }
    });

    return Array.from(map.values()).map((item) => ({
      conductor: item.conductor,
      registros: item.registros,
      ok: item.ok,
      pendientes: item.pendientes,
      total: item.total,
      unidades: item.unidades.size
    })).sort((a, b) => {
      if (b.total !== a.total) return b.total - a.total;
      if (b.registros !== a.registros) return b.registros - a.registros;
      return a.conductor.localeCompare(b.conductor);
    });
  }

  function paymentTotals(rows) {
    const drivers = new Set();
    const units = new Set();
    return rows.reduce((acc, row) => {
      const conductor = keyText(row.conductor);
      const unidad = keyText(row.unidad);
      if (conductor) drivers.add(conductor);
      if (unidad) units.add(unidad);
      acc.registros += 1;
      acc.total += Number(row.importe || 0);
      if (row.estadoRaw === 'PAGADO' || row.estado === 'OK') {
        acc.ok += 1;
      } else {
        acc.pendientes += 1;
      }
      acc.conductores = drivers.size;
      acc.unidades = units.size;
      return acc;
    }, { registros: 0, conductores: 0, unidades: 0, ok: 0, pendientes: 0, total: 0 });
  }

  function paymentPdfSummaryBody(summary) {
    return summary.map((item) => [
      item.conductor || '-',
      Number(item.registros || 0).toLocaleString('es-PE'),
      Number(item.ok || 0).toLocaleString('es-PE'),
      Number(item.pendientes || 0).toLocaleString('es-PE'),
      moneyReportText(item.total),
      Number(item.unidades || 0).toLocaleString('es-PE')
    ]);
  }

  function paymentPdfDetailBody(rows) {
    return rows.map((row) => [
      row.fecha || '-',
      row.hora || '-',
      row.unidad || '-',
      row.ruta || '-',
      row.conductor || '-',
      row.rol || '-',
      row.estado || '-',
      moneyReportText(row.importe),
      row.observacion || '-'
    ]);
  }

  function excelSafeSheetName(name) {
    return compact(name).replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || 'Hoja';
  }

  function autoWidthFromAoa(rows, fallback) {
    const widths = [];
    rows.forEach((row) => {
      row.forEach((cell, index) => {
        const length = String(cell ?? '').length;
        widths[index] = Math.max(widths[index] || 0, Math.min(42, length + 3));
      });
    });
    return (fallback || widths).map((width, index) => ({ wch: Math.max(widths[index] || 0, Number(width || 10)) }));
  }

  async function exportPaymentsPdf(units, range) {
    const rows = paymentDetailRows(units || visibleUnits());
    if (!rows.length) {
      showNotice('No hay pagos visibles para exportar.', false);
      return;
    }
    if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
      showNotice('No se pudo cargar el generador PDF.', false);
      return;
    }

    try {
      const summary = paymentSummaryRows(rows);
      const totals = paymentTotals(rows);
      const doc = await window.N360PDF.createDocument({
        orientation: 'landscape',
        title: 'REPORTE DE CONDUCTORES',
        secondTitle: cfg.monthLabel || cfg.month || 'Control mensual',
        description: 'Pagos de conductores segun las unidades visibles en pantalla.',
        docCode: 'FLOTA_PAGOS_CONDUCTORES',
        userName: report.generatedBy || '',
        dni: report.dni || '',
        logoLeft: report.logoLeft,
        logoRight: report.logoRight,
        useCover: false,
        content: function (doc) {
          if (typeof doc.autoTable !== 'function') {
            throw new Error('No se pudo cargar jsPDF AutoTable.');
          }

          const left = 12.7;
          const right = 12.7;
          const pageW = doc.internal.pageSize.getWidth();
          const width = pageW - left - right;
          let y = 34;

          if (window.N360PDF && typeof window.N360PDF.drawReportSummary === 'function') {
            y = window.N360PDF.drawReportSummary(doc, {
              x: left,
              y,
              width,
              title: 'Importes de conductores',
              rows: [
                { label: 'Mes operativo', value: cfg.monthLabel || cfg.month || '-' },
                { label: 'Rango', value: paymentRangeLabel(range) },
                { label: 'Unidades visibles', value: totals.unidades.toLocaleString('es-PE') },
                { label: 'Conductores', value: totals.conductores.toLocaleString('es-PE') },
                { label: 'Registros', value: totals.registros.toLocaleString('es-PE') },
                { label: 'OK / Pendientes', value: `${totals.ok.toLocaleString('es-PE')} / ${totals.pendientes.toLocaleString('es-PE')}` },
                { label: 'Total visible', value: moneyReportText(totals.total) }
              ],
              columns: 3,
              bottomGap: 7
            });
          } else {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10);
            doc.text(`Mes operativo: ${cfg.monthLabel || cfg.month || '-'}`, left, y);
            doc.text(`Rango: ${paymentRangeLabel(range)}`, left, y + 5);
            doc.text(`Total visible: ${moneyReportText(totals.total)}`, left + width, y, { align: 'right' });
            y += 14;
          }

          doc.setTextColor(15, 42, 64);
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(11);
          doc.text('Resumen por conductor', left, y);
          y += 5;

          doc.autoTable({
            head: [['Conductor', 'Registros', 'OK', 'Pend.', 'Total S/', 'Unid.']],
            body: paymentPdfSummaryBody(summary),
            startY: y,
            margin: { left, right, top: 32, bottom: 22 },
            rowPageBreak: 'avoid',
            styles: {
              fontSize: 7.2,
              cellPadding: 1.5,
              overflow: 'linebreak',
              valign: 'middle',
              lineColor: [226, 232, 240],
              lineWidth: 0.08
            },
            headStyles: {
              fillColor: [20, 38, 61],
              textColor: 255,
              fontStyle: 'bold',
              halign: 'center'
            },
            alternateRowStyles: { fillColor: [249, 251, 253] },
            columnStyles: {
              0: { cellWidth: 76 },
              1: { cellWidth: 22, halign: 'center' },
              2: { cellWidth: 18, halign: 'center' },
              3: { cellWidth: 20, halign: 'center' },
              4: { cellWidth: 30, halign: 'right' },
              5: { cellWidth: 18, halign: 'center' }
            }
          });

          y = (doc.lastAutoTable && doc.lastAutoTable.finalY ? doc.lastAutoTable.finalY : y) + 10;
          doc.setTextColor(15, 42, 64);
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(11);
          doc.text('Detalle', left, y);
          y += 5;

          doc.autoTable({
            head: [['Fecha', 'Hora', 'Unidad', 'Ruta', 'Conductor', 'Rol', 'Estado', 'Importe', 'Observacion']],
            body: paymentPdfDetailBody(rows),
            startY: y,
            margin: { left, right, top: 32, bottom: 22 },
            rowPageBreak: 'avoid',
            styles: {
              fontSize: 6.2,
              cellPadding: 1.2,
              overflow: 'linebreak',
              valign: 'middle',
              lineColor: [226, 232, 240],
              lineWidth: 0.08
            },
            headStyles: {
              fillColor: [20, 38, 61],
              textColor: 255,
              fontStyle: 'bold',
              halign: 'center'
            },
            alternateRowStyles: { fillColor: [249, 251, 253] },
            columnStyles: {
              0: { cellWidth: 19, halign: 'center' },
              1: { cellWidth: 14, halign: 'center' },
              2: { cellWidth: 31 },
              3: { cellWidth: 44 },
              4: { cellWidth: 43 },
              5: { cellWidth: 23, halign: 'center' },
              6: { cellWidth: 17, halign: 'center' },
              7: { cellWidth: 24, halign: 'right' },
              8: { cellWidth: width - 215 }
            },
            didParseCell: function (data) {
              if (data.section !== 'body' || data.column.index !== 6) return;
              const raw = String(data.cell.raw || '').toUpperCase();
              data.cell.styles.fontStyle = 'bold';
              if (raw === 'OK') {
                data.cell.styles.textColor = [5, 112, 68];
              } else {
                data.cell.styles.textColor = [146, 64, 14];
              }
            }
          });
        }
      });

      doc.save(`${report.fileBase || 'control_conductores'}_pagos_${paymentRangeFileSuffix(range)}_${stamp()}.pdf`);
    } catch (error) {
      console.error(error);
      showNotice('No se pudo generar el PDF de pagos.', false);
    }
  }

  function exportPaymentsExcel(units, range) {
    const rows = paymentDetailRows(units || visibleUnits());
    if (!rows.length) {
      showNotice('No hay pagos visibles para exportar.', false);
      return;
    }
    if (!window.XLSX) {
      showNotice('No se pudo cargar el generador Excel.', false);
      return;
    }

    const summary = paymentSummaryRows(rows);
    const detailAoa = [
      ['Fecha', 'Hora', 'Unidad', 'Ruta', 'Ida/Vuelta', 'Conductor', 'Rol', 'Estado', 'Importe S/', 'Observacion', 'Estado revision'],
      ...rows.map((row) => [
        row.fecha || '-',
        row.hora || '-',
        row.unidad || '-',
        row.ruta || '-',
        row.idaVuelta || '-',
        row.conductor || '-',
        row.rol || '-',
        row.estado || '-',
        Number(row.importe || 0),
        row.observacion || '-',
        row.revision || '-'
      ])
    ];
    const summaryAoa = [
      ['Rango', paymentRangeLabel(range), '', '', '', ''],
      [],
      ['Conductor', 'Registros', 'OK', 'Pendientes', 'Total S/', 'Unidades visibles'],
      ...summary.map((item) => [
        item.conductor || '-',
        Number(item.registros || 0),
        Number(item.ok || 0),
        Number(item.pendientes || 0),
        Number(item.total || 0),
        Number(item.unidades || 0)
      ])
    ];

    const wb = window.XLSX.utils.book_new();
    const detailSheet = window.XLSX.utils.aoa_to_sheet(detailAoa);
    const summarySheet = window.XLSX.utils.aoa_to_sheet(summaryAoa);
    detailSheet['!cols'] = autoWidthFromAoa(detailAoa, [12, 9, 24, 34, 12, 30, 14, 13, 13, 34, 18]);
    summarySheet['!cols'] = autoWidthFromAoa(summaryAoa, [32, 12, 10, 12, 13, 16]);

    for (let r = 2; r <= detailAoa.length; r += 1) {
      const cell = detailSheet[`I${r}`];
      if (cell) cell.z = '"S/ "#,##0.00##';
    }
    for (let r = 4; r <= summaryAoa.length; r += 1) {
      const cell = summarySheet[`E${r}`];
      if (cell) cell.z = '"S/ "#,##0.00##';
    }

    window.XLSX.utils.book_append_sheet(wb, summarySheet, excelSafeSheetName('Resumen'));
    window.XLSX.utils.book_append_sheet(wb, detailSheet, excelSafeSheetName('Pagos visibles'));
    window.XLSX.writeFile(wb, `${report.fileBase || 'control_conductores'}_pagos_${paymentRangeFileSuffix(range)}_${stamp()}.xlsx`);
    showNotice('Excel de pagos generado.', true);
  }

  function openPaymentRangeModal(type) {
    const modalEl = document.getElementById('fccPaymentRangeModal');
    if (!modalEl) {
      if (type === 'pdf') exportPaymentsPdf(visibleUnits(), null);
      else exportPaymentsExcel(visibleUnits(), null);
      return;
    }

    pendingPaymentExport = type;
    const units = visibleUnits();
    const defaults = paymentRangeDefaults(units);
    const inputFrom = modalEl.querySelector('[data-fcc-payment-from]');
    const inputTo = modalEl.querySelector('[data-fcc-payment-to]');
    const title = modalEl.querySelector('#fccPaymentRangeTitle');
    const confirm = modalEl.querySelector('[data-fcc-payment-confirm]');

    if (title) title.textContent = type === 'pdf' ? 'Exportar pagos en PDF' : 'Exportar pagos en Excel';
    if (confirm) confirm.innerHTML = type === 'pdf'
      ? '<i class="bi bi-file-earmark-pdf"></i> Descargar PDF'
      : '<i class="bi bi-file-earmark-spreadsheet"></i> Descargar Excel';

    if (inputFrom) {
      inputFrom.min = defaults.min || '';
      inputFrom.max = defaults.max || '';
      inputFrom.value = defaults.from || '';
    }
    if (inputTo) {
      inputTo.min = defaults.min || '';
      inputTo.max = defaults.max || '';
      inputTo.value = defaults.to || '';
    }

    const modal = window.bootstrap && window.bootstrap.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;
    if (modal) {
      modal.show();
    } else {
      modalEl.classList.add('show');
      modalEl.style.display = 'block';
    }
  }

  function confirmPaymentRange() {
    const modalEl = document.getElementById('fccPaymentRangeModal');
    if (!modalEl || !pendingPaymentExport) return;

    const inputFrom = modalEl.querySelector('[data-fcc-payment-from]');
    const inputTo = modalEl.querySelector('[data-fcc-payment-to]');
    const range = {
      from: normalizeDateKey(inputFrom?.value),
      to: normalizeDateKey(inputTo?.value)
    };

    if (!range.from || !range.to) {
      showNotice('Selecciona fecha desde y hasta.', false);
      return;
    }
    if (range.from > range.to) {
      showNotice('La fecha desde no puede ser mayor que hasta.', false);
      return;
    }

    const units = filterUnitsByPaymentRange(visibleUnits(), range);
    const modal = window.bootstrap && window.bootstrap.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;
    if (modal) modal.hide();
    else modalEl.style.display = 'none';

    if (pendingPaymentExport === 'pdf') {
      exportPaymentsPdf(units, range);
    } else {
      exportPaymentsExcel(units, range);
    }
    pendingPaymentExport = '';
  }

  function setupPaymentRangeModal() {
    const modalEl = document.getElementById('fccPaymentRangeModal');
    if (!modalEl) return;

    document.addEventListener('click', (event) => {
      if (!event.target.closest('[data-fcc-payment-confirm]')) return;
      event.preventDefault();
      confirmPaymentRange();
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      pendingPaymentExport = '';
    });
    modalEl.querySelectorAll('[data-fcc-payment-from], [data-fcc-payment-to]').forEach((input) => {
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          confirmPaymentRange();
        }
      });
    });
  }

  function drawDriverSummaryPage(doc, left, y, width, summary) {
    const totals = driverSummaryTotals(summary);

    doc.setTextColor(15, 42, 64);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('Resumen de conductores', left, y);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(82, 105, 130);
    doc.text(`Mes operativo: ${cfg.monthLabel || cfg.month || '-'}`, left, y + 6);
    doc.text(`Conductores: ${totals.drivers} | Viajes: ${totals.trips} | Buses: ${totals.buses}`, left, y + 11);

    if (!summary.length) {
      doc.setTextColor(82, 105, 130);
      doc.text('No hay conductores para las unidades visibles.', left, y + 24);
      return;
    }

    doc.autoTable({
      head: [['Conductor', 'Viajes', 'Buses']],
      body: driverSummaryBody(summary),
      startY: y + 18,
      margin: { left, right: left, top: 32, bottom: 22 },
      rowPageBreak: 'avoid',
      styles: {
        fontSize: 7,
        cellPadding: 1.5,
        overflow: 'linebreak',
        valign: 'middle',
        lineColor: [226, 232, 240],
        lineWidth: 0.08
      },
      headStyles: {
        fillColor: [20, 38, 61],
        textColor: 255,
        fontStyle: 'bold',
        halign: 'center'
      },
      alternateRowStyles: { fillColor: [249, 251, 253] },
      columnStyles: {
        0: { cellWidth: 62 },
        1: { cellWidth: 20, halign: 'center' },
        2: { cellWidth: width - 82 }
      }
    });
  }

  function drawCanceledTripsPage(doc, left, y, width, trips) {
    const totals = canceledTotals(trips);

    doc.setTextColor(15, 42, 64);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('Viajes anulados', left, y);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(82, 105, 130);
    doc.text(`Mes operativo: ${cfg.monthLabel || cfg.month || '-'}`, left, y + 6);
    doc.text(`Anulados: ${totals.trips} | Unidades: ${totals.units} | Conductores asociados: ${totals.drivers}`, left, y + 11);

    if (!trips.length) {
      doc.setTextColor(82, 105, 130);
      doc.text('No hay viajes anulados para las unidades visibles.', left, y + 24);
      return;
    }

    doc.autoTable({
      head: [['Fecha', 'Unidad', 'Hora', 'Ruta', 'Conductores', 'Obs. revision']],
      body: canceledPdfBody(trips),
      startY: y + 18,
      margin: { left, right: left, top: 32, bottom: 22 },
      rowPageBreak: 'avoid',
      styles: {
        fontSize: 6.4,
        cellPadding: 1.4,
        overflow: 'linebreak',
        valign: 'middle',
        lineColor: [226, 232, 240],
        lineWidth: 0.08
      },
      headStyles: {
        fillColor: [112, 26, 26],
        textColor: 255,
        fontStyle: 'bold',
        halign: 'center'
      },
      alternateRowStyles: { fillColor: [255, 247, 247] },
      columnStyles: {
        0: { cellWidth: 17, halign: 'center' },
        1: { cellWidth: 34 },
        2: { cellWidth: 14, halign: 'center' },
        3: { cellWidth: 48 },
        4: { cellWidth: 38 },
        5: { cellWidth: width - 151 }
      }
    });
  }

  async function exportPdf(units, fileSuffix) {
    if (!units.length) {
      showNotice('No hay unidades visibles para exportar.', false);
      return;
    }
    if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
      showNotice('No se pudo cargar el generador PDF.', false);
      return;
    }

    try {
      const driverSummary = summarizeDrivers(units);
      const canceledTrips = collectCanceledTrips(units);
      const doc = await window.N360PDF.createDocument({
        orientation: 'portrait',
        title: report.title || 'CONTROL MENSUAL DE CONDUCTORES',
        secondTitle: report.subtitle || 'Consolidado de salidas por unidad',
        description: 'Estado de trabajo y observaciones por conductor segun el consolidado de salidas.',
        docCode: report.docCode || 'FLOTA_CONDUCTORES_MES',
        userName: report.generatedBy || '',
        dni: report.dni || '',
        logoLeft: report.logoLeft,
        logoRight: report.logoRight,
        useCover: false,
        content: function (doc) {
          if (typeof doc.autoTable !== 'function') {
            throw new Error('No se pudo cargar jsPDF AutoTable.');
          }

          const left = 12.7;
          const right = 12.7;
          const pageW = doc.internal.pageSize.getWidth();
          const width = pageW - left - right;

          units.forEach((unit, index) => {
            if (index > 0) {
              doc.addPage();
            }

            let y = 34;
            y = drawInfo(doc, left, y, width, unit, index, units.length);

            doc.setTextColor(15, 42, 64);
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10);
            doc.text(unit.title || 'Unidad', left, y);
            y += 4;

            doc.autoTable({
              head: [['Dia', 'Trabajo', 'Ida/Vuelta', 'Ruta', 'Cond. 1', 'Obs. 1', 'Cond. 2', 'Obs. 2']],
              body: tableBody(unit),
              startY: y,
              margin: { left, right, top: 32, bottom: 22 },
              rowPageBreak: 'avoid',
              styles: {
                fontSize: 5.5,
                cellPadding: 1,
                overflow: 'linebreak',
                valign: 'middle',
                lineColor: [226, 232, 240],
                lineWidth: 0.08
              },
              headStyles: {
                fillColor: [20, 38, 61],
                textColor: 255,
                fontStyle: 'bold',
                halign: 'center'
              },
              alternateRowStyles: { fillColor: [249, 251, 253] },
              columnStyles: {
                0: { cellWidth: 8, halign: 'center' },
                1: { cellWidth: 17, halign: 'center' },
                2: { cellWidth: 17, halign: 'center' },
                3: { cellWidth: 36 },
                4: { cellWidth: 28 },
                5: { cellWidth: 27 },
                6: { cellWidth: 28 },
                7: { cellWidth: 23.6 }
              },
              didParseCell: function (data) {
                if (data.section !== 'body') return;
                const raw = String(data.cell.raw || '').toUpperCase();
                if (data.column.index === 1) {
                  data.cell.styles.fontStyle = 'bold';
                  if (raw.includes('VALIDADO')) data.cell.styles.textColor = [5, 112, 68];
                  if (raw.includes('OBSERVADO')) data.cell.styles.textColor = [170, 36, 31];
                  if (raw.includes('CORREGIDO')) data.cell.styles.textColor = [7, 89, 133];
                  if (raw.includes('ANULADO')) data.cell.styles.textColor = [176, 39, 39];
                }
              }
            });

            y = (doc.lastAutoTable && doc.lastAutoTable.finalY ? doc.lastAutoTable.finalY : y) + 10;
          });

          doc.addPage();
          drawDriverSummaryPage(doc, left, 34, width, driverSummary);
          if (canceledTrips.length) {
            doc.addPage();
            drawCanceledTripsPage(doc, left, 34, width, canceledTrips);
          }
        }
      });

      doc.save(`${report.fileBase || 'control_conductores'}_${fileSuffix || 'reporte'}_${stamp()}.pdf`);
    } catch (error) {
      console.error(error);
      showNotice('No se pudo generar el PDF.', false);
    }
  }

  function setupPdfButtons() {
    document.querySelectorAll('[data-fcc-export-unit]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.stopPropagation();
        const card = button.closest('[data-fcc-unit]');
        if (!card) return;
        const unit = collectUnitFromCard(card);
        const slug = compact(unit.title).replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '').toLowerCase() || 'unidad';
        exportPdf([unit], slug);
      });
    });

    const allButton = document.querySelector('[data-fcc-export-all]');
    if (allButton) {
      allButton.addEventListener('click', () => exportPdf(visibleUnits(), 'consolidado'));
    }
  }

  function setupPaymentExportButtons() {
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-fcc-export-payments-pdf], [data-fcc-export-payments-excel]');
      if (!button) return;

      event.preventDefault();
      event.stopPropagation();
      openPaymentRangeModal(button.matches('[data-fcc-export-payments-pdf]') ? 'pdf' : 'excel');
    });
  }

  document.querySelectorAll('[data-fcc-save]').forEach((button) => {
    button.addEventListener('click', () => saveRow(button));
  });
  document.querySelectorAll('[data-fcc-field="cond1_estado"], [data-fcc-field="cond2_estado"]').forEach((select) => {
    syncSelectClass(select);
    select.addEventListener('change', () => syncSelectClass(select));
  });
  document.querySelectorAll('[data-fcc-field="viaje_importe"], [data-fcc-field="cond1_importe"], [data-fcc-field="cond2_importe"]').forEach((input) => {
    setMoneyInputValue(input, input.value, false);
    input.addEventListener('focus', () => editMoneyInput(input));
    input.addEventListener('input', () => {
      input.dataset.fccMoneyDisplay = input.value;
      input.dataset.fccMoneyRaw = normalizeMoneyValue(input.value);
      updateTripTotalState(input.closest('[data-fcc-row]'));
    });
    input.addEventListener('blur', () => {
      if (input.checkValidity()) displayMoneyInput(input);
      markRowChange(input.closest('[data-fcc-row]'));
    });
  });
  setupBulkEdit();
  setupSearch();
  setupPdfButtons();
  setupPaymentExportButtons();
  setupPaymentRangeModal();
  setupDriverSummaryModal();
  setupTripDetailModal();
  setupCanceledTripsModal();
})();
