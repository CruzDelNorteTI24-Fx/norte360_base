(function () {
  const cfg = window.N360_FCC || {};
  const endpoint = cfg.endpoint || 'control_conductores_salidas.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};
  const tripMap = new Map();
  const rowBaselines = new Map();
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

  function normalizeMoneyValue(value) {
    const raw = String(value ?? '').trim().replace(',', '.');
    if (!raw) return '';
    const match = raw.match(/^(\d{1,16})(?:\.(\d{0,4}))?$/);
    if (!match) return raw;
    const integer = (match[1].replace(/^0+(?=\d)/, '') || '0');
    const decimal = String(match[2] || '').padEnd(4, '0').slice(0, 4);
    return `${integer}.${decimal}`;
  }

  function moneyText(value) {
    const normalized = normalizeMoneyValue(value);
    return normalized ? `S/ ${normalized}` : '-';
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
    trip.cond1_estado = row.querySelector('[data-fcc-field="cond1_estado"]')?.value || trip.cond1_estado || '';
    trip.cond1_importe = row.querySelector('[data-fcc-field="cond1_importe"]')?.value || trip.cond1_importe || '';
    trip.cond1_observacion = row.querySelector('[data-fcc-field="cond1_observacion"]')?.value || trip.cond1_observacion || '';
    trip.cond2_estado = row.querySelector('[data-fcc-field="cond2_estado"]')?.value || trip.cond2_estado || '';
    trip.cond2_importe = row.querySelector('[data-fcc-field="cond2_importe"]')?.value || trip.cond2_importe || '';
    trip.cond2_observacion = row.querySelector('[data-fcc-field="cond2_observacion"]')?.value || trip.cond2_observacion || '';
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
      cond1_estado: fields.cond1_estado?.value || 'PENDIENTE',
      cond1_importe: fields.cond1_importe?.value || '',
      cond1_observacion: fields.cond1_observacion?.value || '',
      cond2_estado: fields.cond2_estado?.value || 'PENDIENTE',
      cond2_importe: fields.cond2_importe?.value || '',
      cond2_observacion: fields.cond2_observacion?.value || ''
    };
  }

  function comparableRowValues(values) {
    return {
      cond1_estado: compact(values.cond1_estado).toUpperCase(),
      cond1_importe: normalizeMoneyValue(values.cond1_importe),
      cond1_observacion: String(values.cond1_observacion || ''),
      cond2_estado: compact(values.cond2_estado).toUpperCase(),
      cond2_importe: normalizeMoneyValue(values.cond2_importe),
      cond2_observacion: String(values.cond2_observacion || '')
    };
  }

  function rememberRow(row) {
    const id = row?.dataset?.fccRow || '';
    if (!id || id === '0') return;
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
    row.querySelectorAll('select').forEach(syncSelectClass);
    row.classList.remove('is-bulk-dirty');
  }

  function dirtyRows() {
    return Array.from(document.querySelectorAll('[data-fcc-row].is-bulk-dirty'))
      .filter((row) => row.dataset.fccRow && row.dataset.fccRow !== '0');
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
    if (!row || !bulkMode) return;
    const id = row.dataset.fccRow || '';
    if (!id || id === '0') return;
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
    const amountInputs = Array.from(row.querySelectorAll('[data-fcc-field="cond1_importe"], [data-fcc-field="cond2_importe"]'));
    const invalidAmount = amountInputs.find((input) => !input.disabled && !input.checkValidity());
    if (invalidAmount) {
      invalidAmount.reportValidity();
      return false;
    }
    amountInputs.forEach((input) => {
      if (!input.disabled && input.value !== '') input.value = normalizeMoneyValue(input.value);
    });
    return true;
  }

  function applySavedRow(row, data) {
    if (!row) return;
    const fields = rowFields(row);
    if (fields.cond1_estado && data?.cond1_estado) fields.cond1_estado.value = data.cond1_estado;
    if (fields.cond1_importe) fields.cond1_importe.value = normalizeMoneyValue(data?.cond1_importe ?? fields.cond1_importe.value);
    if (fields.cond2_estado && data?.cond2_estado) fields.cond2_estado.value = data.cond2_estado;
    if (fields.cond2_importe) fields.cond2_importe.value = normalizeMoneyValue(data?.cond2_importe ?? fields.cond2_importe.value);
    row.querySelectorAll('select').forEach(syncSelectClass);

    const id = row.dataset.fccRow || '';
    const savedTrip = tripMap.get(String(id));
    if (savedTrip) {
      syncTripFromRow(savedTrip, row);
      savedTrip.cond1_estado = data?.cond1_estado || savedTrip.cond1_estado;
      savedTrip.cond1_importe = data?.cond1_importe ?? savedTrip.cond1_importe;
      savedTrip.cond2_estado = data?.cond2_estado || savedTrip.cond2_estado;
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

    if (!validateAndNormalizeRow(row)) {
      return;
    }

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'update_driver_status');
    fd.append('id', id);
    fd.append('cond1_estado', row.querySelector('[data-fcc-field="cond1_estado"]')?.value || 'PENDIENTE');
    fd.append('cond1_importe', row.querySelector('[data-fcc-field="cond1_importe"]')?.value || '');
    fd.append('cond1_observacion', row.querySelector('[data-fcc-field="cond1_observacion"]')?.value || '');
    fd.append('cond2_estado', row.querySelector('[data-fcc-field="cond2_estado"]')?.value || 'PENDIENTE');
    fd.append('cond2_importe', row.querySelector('[data-fcc-field="cond2_importe"]')?.value || '');
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
    const ok = window.confirm(`Deseas actualizar ${items.length} registro${items.length === 1 ? '' : 's'} de estados, pagos y observaciones?`);
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
    document.querySelectorAll('[data-fcc-row]').forEach((row) => rememberRow(row));

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
        if (field.matches('select')) syncSelectClass(field);
        markRowChange(field.closest('[data-fcc-row]'));
      });
    });

    setBulkMode(false);
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

      return {
        dia: [dayNumber, weekday].filter(Boolean).join(' '),
        date,
        dayNumber,
        weekday,
        tripIndex,
        tripsDay,
        hora,
        revision: cellText(row, '[data-fcc-col="revision"]'),
        cond1: cellText(row, '[data-fcc-col="cond1"]'),
        cond1Estado: cellText(row, '[data-fcc-field="cond1_estado"]'),
        cond1Importe: cellText(row, '[data-fcc-field="cond1_importe"]'),
        cond1Obs: cellText(row, '[data-fcc-field="cond1_observacion"]'),
        cond2: cellText(row, '[data-fcc-col="cond2"]'),
        cond2Estado: cellText(row, '[data-fcc-field="cond2_estado"]'),
        cond2Importe: cellText(row, '[data-fcc-field="cond2_importe"]'),
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

  function setupTripDetailModal() {
    buildTripMap();

    const modalEl = document.getElementById('fccTripDetailModal');
    if (!modalEl) return;

    const modal = window.bootstrap && window.bootstrap.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
      : null;

    document.querySelectorAll('[data-fcc-view-trip]').forEach((button) => {
      button.addEventListener('click', () => {
        const id = String(button.dataset.fccTripId || '');
        const trip = tripMap.get(id);
        if (!trip) {
          showNotice('No se encontró el detalle del viaje.', false);
          return;
        }

        syncTripFromRow(trip, button.closest('[data-fcc-row]'));

        const bus = nullableText(trip.bus || trip.unitBus);
        const placa = nullableText(trip.placa || trip.unitPlaca);
        const title = document.querySelector('[data-fcc-trip-title]');
        const subtitle = document.querySelector('[data-fcc-trip-subtitle]');
        if (title) title.textContent = `${bus} (${placa})`;
        if (subtitle) {
          subtitle.textContent = `Viaje ${Number(trip.trip_index || 1)} de ${Number(trip.trips_day || 1)} · día operativo ${formatDateValue(trip.date)}`;
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
        setTripField('origen', trip.origen);
        setTripField('destino', trip.destino);
        setTripField('fecha_programacion', formatDateTimeValue(trip.fecha_programacion));
        setTripField('ruta_texto', trip.ruta_texto);
        setTripField('comentario_horario', trip.comentario_horario);
        setTripField('cond1', trip.cond1);
        setTripField('cond1_estado', trip.cond1_estado);
        setTripField('cond1_importe', moneyText(trip.cond1_importe));
        setTripField('cond1_observacion', trip.cond1_observacion);
        setTripField('cond2', trip.cond2);
        setTripField('cond2_estado', trip.cond2_estado);
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
          status.className = 'fcc-status';
          if (value === 'VALIDADO') status.classList.add('fcc-status--ok');
          else if (value === 'OBSERVADO') status.classList.add('fcc-status--warn');
          else if (value === 'CORREGIDO') status.classList.add('fcc-status--info');
          else status.classList.add('fcc-status--pending');
        }

        if (modal) {
          modal.show();
        } else {
          modalEl.classList.add('show');
          modalEl.style.display = 'block';
        }
      });
    });
  }

  function drawInfo(doc, left, y, width, unit, unitIndex, unitsCount) {
    const summary = summarizeDrivers([unit]);
    const totals = driverSummaryTotals(summary);
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
          { label: 'Conductores / viajes', value: `${totals.drivers} / ${totals.trips}` }
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
    doc.setDrawColor(210, 224, 238);
    doc.line(left, y + 16, left + width, y + 16);
    return y + 23;
  }

  function tableBody(unit) {
    let previousDate = '';
    return (unit.rows || []).map((row) => {
      const dateKey = compact(row.date || row.dia || '');
      const showDate = dateKey !== previousDate;
      previousDate = dateKey;

      return [
        showDate ? (row.dia || '-') : '',
        `${row.revision || '-'}${row.hora ? `` : ''}`,
        row.cond1 || '-',
        row.cond1Obs || '-',
        row.cond2 || '-',
        row.cond2Obs || '-'
      ];
    });
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
              head: [['Dia', 'Trabajo', 'Cond. 1', 'Obs. 1', 'Cond. 2', 'Obs. 2']],
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
                0: { cellWidth: 9, halign: 'center' },
                1: { cellWidth: 18, halign: 'center' },
                2: { cellWidth: 39 },
                3: { cellWidth: 39.3 },
                4: { cellWidth: 39 },
                5: { cellWidth: 39.3 }
              },
              didParseCell: function (data) {
                if (data.section !== 'body') return;
                const raw = String(data.cell.raw || '').toUpperCase();
                if (data.column.index === 1) {
                  data.cell.styles.fontStyle = 'bold';
                  if (raw.includes('VALIDADO')) data.cell.styles.textColor = [5, 112, 68];
                  if (raw.includes('OBSERVADO')) data.cell.styles.textColor = [170, 36, 31];
                  if (raw.includes('CORREGIDO')) data.cell.styles.textColor = [7, 89, 133];
                }
              }
            });

            y = (doc.lastAutoTable && doc.lastAutoTable.finalY ? doc.lastAutoTable.finalY : y) + 10;
          });

          doc.addPage();
          drawDriverSummaryPage(doc, left, 34, width, driverSummary);
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

  document.querySelectorAll('[data-fcc-save]').forEach((button) => {
    button.addEventListener('click', () => saveRow(button));
  });
  document.querySelectorAll('[data-fcc-field="cond1_estado"], [data-fcc-field="cond2_estado"]').forEach((select) => {
    syncSelectClass(select);
    select.addEventListener('change', () => syncSelectClass(select));
  });
  document.querySelectorAll('[data-fcc-field="cond1_importe"], [data-fcc-field="cond2_importe"]').forEach((input) => {
    if (input.value !== '') input.value = normalizeMoneyValue(input.value);
    input.addEventListener('blur', () => {
      if (input.value !== '' && input.checkValidity()) input.value = normalizeMoneyValue(input.value);
      markRowChange(input.closest('[data-fcc-row]'));
    });
  });
  setupBulkEdit();
  setupSearch();
  setupPdfButtons();
  setupDriverSummaryModal();
  setupTripDetailModal();
})();
