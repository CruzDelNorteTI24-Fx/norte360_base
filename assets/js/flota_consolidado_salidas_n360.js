(function () {
  const cfg = window.N360_CSB || {};
  const endpoint = cfg.endpoint || 'consolidado_salidas_buses.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};
  const conductores = Array.isArray(cfg.conductores) ? cfg.conductores : [];
  const rows = Array.from(document.querySelectorAll('[data-csb-row]'));
  const visiblePill = document.querySelector('[data-csb-visible-pill]');

  const clean = (value) => String(value || '').replace(/[ \t]+/g, ' ').replace(/\n\s+/g, '\n').trim();
  const compact = (value) => clean(value).replace(/\s+/g, ' ');
  const moneyDate = () => new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');
  const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[char]);

  function showNotice(message, ok) {
    let box = document.querySelector('[data-csb-notice]');
    if (!box) {
      box = document.createElement('div');
      box.dataset.csbNotice = '1';
      box.className = 'csb-notice';
      document.body.appendChild(box);
    }
    box.textContent = message;
    box.classList.toggle('csb-notice--ok', !!ok);
    box.classList.toggle('csb-notice--bad', !ok);
    box.classList.add('is-visible');
    window.clearTimeout(box._csbTimer);
    box._csbTimer = window.setTimeout(() => box.classList.remove('is-visible'), 2800);
  }

  function updateVisibleCount() {
    const count = rows.filter((row) => !row.hidden).length;
    if (visiblePill) {
      visiblePill.textContent = `${new Intl.NumberFormat('es-PE').format(count)} registros`;
    }
  }

  const hojaRutaTimers = new WeakMap();

  function hojaRutaKey(value) {
    return compact(value).toLocaleLowerCase('es-PE');
  }

  function duplicateRefLabel(info) {
    if (!info) return '';
    const unidad = compact(info.bus || info.placa || '');
    const fechaHora = [compact(info.fecha || ''), compact(info.hora || '')].filter(Boolean).join(' ');
    const ruta = [compact(info.origen || ''), compact(info.destino || '')].filter(Boolean).join(' → ');
    return [unidad, fechaHora, ruta].filter(Boolean).join(' · ');
  }

  function setHojaRutaValidation(row, mode, info = null) {
    const state = row.querySelector('[data-csb-hojaruta-state]');
    const input = row.querySelector('[data-csb-field="hojaruta"]');
    const duplicate = mode === 'duplicate';
    const checking = mode === 'checking';

    row.dataset.csbHojarutaDuplicate = duplicate ? '1' : '0';
    row.classList.toggle('csb-row--hojaruta-duplicate', duplicate);
    row.classList.toggle('csb-row--hojaruta-checking', checking);
    input?.classList.toggle('is-duplicate', duplicate);
    input?.classList.toggle('is-checking', checking);

    if (!state) return;
    const icon = state.querySelector('i');
    const text = state.querySelector('span');
    state.classList.toggle('is-duplicate', duplicate);
    state.classList.toggle('is-checking', checking);

    if (mode === 'duplicate') {
      if (icon) icon.className = 'bi bi-exclamation-triangle-fill';
      if (text) {
        const ref = duplicateRefLabel(info);
        text.textContent = ref ? `Duplicada en ${ref}` : 'Hoja de ruta duplicada';
      }
      return;
    }

    if (mode === 'checking') {
      if (icon) icon.className = 'bi bi-arrow-repeat';
      if (text) text.textContent = 'Validando duplicados...';
      return;
    }

    if (mode === 'unique') {
      if (icon) icon.className = 'bi bi-check-circle-fill';
      if (text) {
        text.textContent = row.dataset.csbHasHojaruta === '1'
          ? 'Hoja de ruta registrada · sin duplicados'
          : 'Sin duplicados detectados';
      }
      return;
    }

    if (icon) icon.className = 'bi bi-circle';
    if (text) text.textContent = 'Pendiente de revisión';
  }

  function findLocalHojaRutaDuplicate(row, value) {
    const key = hojaRutaKey(value);
    if (!key) return null;
    return rows.find((otherRow) => {
      if (otherRow === row) return false;
      const otherValue = otherRow.querySelector('[data-csb-field="hojaruta"]')?.value || '';
      return hojaRutaKey(otherValue) === key;
    }) || null;
  }

  async function validateHojaRuta(row, remote = true) {
    const input = row.querySelector('[data-csb-field="hojaruta"]');
    if (!input) return true;

    const value = input.value || '';
    if (!hojaRutaKey(value)) {
      setHojaRutaValidation(row, 'empty');
      return true;
    }

    const localDuplicate = findLocalHojaRutaDuplicate(row, value);
    if (localDuplicate) {
      const localInfo = {
        bus: compact(localDuplicate.children?.[1]?.querySelector('strong')?.textContent || ''),
        fecha: report.period || '',
        hora: compact(localDuplicate.children?.[0]?.querySelector('strong')?.textContent || '')
      };
      setHojaRutaValidation(row, 'duplicate', localInfo);
      return false;
    }

    if (!remote) {
      setHojaRutaValidation(row, 'unique');
      return true;
    }

    setHojaRutaValidation(row, 'checking');
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'check_hojaruta');
    fd.append('id', row.dataset.csbRow || '');
    fd.append('hojaruta', value);

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.ok) {
        throw new Error(json.message || 'No se pudo validar la Hoja de Ruta.');
      }
      if (json.data?.duplicada) {
        setHojaRutaValidation(row, 'duplicate', json.data?.duplicado || null);
        return false;
      }
      setHojaRutaValidation(row, 'unique');
      return true;
    } catch (error) {
      row.classList.remove('csb-row--hojaruta-checking');
      input.classList.remove('is-checking');
      showNotice(error.message || 'No se pudo validar la Hoja de Ruta.', false);
      return true; // El servidor vuelve a validar obligatoriamente al guardar.
    }
  }

  async function saveRow(button) {
    const row = button.closest('[data-csb-row]');
    if (!row) return;

    const id = button.dataset.csbSave || row.dataset.csbRow || '';
    const estado = row.querySelector('[data-csb-field="estado"]')?.value || 'PENDIENTE';
    const comentario = row.querySelector('[data-csb-field="comentario"]')?.value || '';
    const correccion = row.querySelector('[data-csb-field="correccion"]')?.value || '';
    const hojarutaInput = row.querySelector('[data-csb-field="hojaruta"]');
    const hojaruta = hojarutaInput?.value || '';
    const originalHtml = button.innerHTML;

    const hojaRutaValida = await validateHojaRuta(row, true);
    if (!hojaRutaValida) {
      showNotice('Esa Hoja de Ruta ya está registrada en otro viaje.', false);
      hojarutaInput?.focus();
      return;
    }

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'update_revision');
    fd.append('id', id);
    fd.append('estado', estado);
    fd.append('comentario', comentario);
    fd.append('correccion', correccion);
    fd.append('hojaruta', hojaruta);

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
        if (json.data?.duplicada) {
          setHojaRutaValidation(row, 'duplicate');
          hojarutaInput?.focus();
        }
        throw new Error(json.message || 'No se pudo guardar.');
      }

      const status = row.querySelector('[data-csb-status]');
      if (status) {
        status.textContent = json.data?.estado || estado;
        status.className = `csb-status ${json.data?.clase || 'csb-status--pending'}`;
      }

      const saved = row.querySelector('[data-csb-saved]');
      if (saved) {
        saved.textContent = json.data?.actualizado || '';
      }
      row.dataset.csbDbRevision = String(json.data?.estado || estado || 'PENDIENTE').toUpperCase();
      syncStateButtons(row, row.dataset.csbDbRevision);
      syncHojaRutaState(row, json.data?.tiene_hojaruta ?? compact(hojaruta) !== '');
      setHojaRutaValidation(row, compact(hojaruta) !== '' ? 'unique' : 'empty');
      showNotice(json.message || 'Cambios guardados.', true);
    } catch (err) {
      showNotice(err.message || 'No se pudo guardar.', false);
    } finally {
      button.disabled = false;
      button.innerHTML = originalHtml;
    }
  }

  function setupGroupFilter() {
    const wrap = document.querySelector('[data-csb-group-filter]');
    if (!wrap) {
      updateVisibleCount();
      return;
    }

    wrap.querySelectorAll('[data-csb-group]').forEach((button) => {
      button.addEventListener('click', () => {
        const group = button.dataset.csbGroup || '__ALL__';
        wrap.querySelectorAll('[data-csb-group]').forEach((btn) => btn.classList.toggle('is-active', btn === button));

        rows.forEach((row) => {
          if (group === '__ALL__') {
            row.hidden = false;
            return;
          }
          const groups = String(row.dataset.csbGroups || '').split('|').map((item) => item.trim()).filter(Boolean);
          row.hidden = !groups.includes(group);
        });
        updateVisibleCount();
      });
    });

    updateVisibleCount();
  }

  function syncStateButtons(row, estado) {
    const value = String(estado || 'PENDIENTE').toUpperCase();
    const dbValue = String(row.dataset.csbDbRevision || value).toUpperCase();
    const hidden = row.querySelector('[data-csb-field="estado"]');
    if (hidden) {
      hidden.value = value;
    }
    row.querySelectorAll('[data-csb-state-option]').forEach((button) => {
      button.classList.toggle('is-active', String(button.dataset.csbStateOption || '').toUpperCase() === value);
    });
    row.querySelectorAll('[data-csb-driver-edit]').forEach((button) => {
      const canEdit = dbValue === 'OBSERVADO';
      button.hidden = !canEdit;
      button.disabled = !canEdit;
    });
  }


  function syncHojaRutaState(row, hasHojaRuta) {
    const active = Boolean(hasHojaRuta);
    row.dataset.csbHasHojaruta = active ? '1' : '0';
    row.classList.toggle('csb-row--hojaruta', active);

    if (row.dataset.csbHojarutaDuplicate === '1') {
      return;
    }
    setHojaRutaValidation(row, active ? 'unique' : 'empty');
  }

  function cellText(td) {
    const drivers = td.querySelector('.csb-drivers');
    if (drivers) {
      const lines = Array.from(drivers.querySelectorAll('[data-csb-driver-text]'))
        .map((span) => compact(span.textContent))
        .filter(Boolean);
      return lines.length ? lines.join('\n') : compact(drivers.textContent);
    }
    const textareas = td.querySelectorAll('textarea');
    if (textareas.length) {
      return Array.from(textareas).map((area) => compact(area.value)).filter(Boolean).join('\n');
    }
    return compact(td.textContent);
  }

  function tablePayload() {
    const table = document.querySelector('[data-csb-table]');
    if (!table) return { head: [], body: [] };

    const ths = Array.from(table.querySelectorAll('thead th'));
    const head = ths.slice(0, -1).map((th) => compact(th.textContent));
    const body = rows
      .filter((row) => !row.hidden)
      .map((row) => Array.from(row.children).slice(0, -1).map(cellText));
    return { head, body };
  }

  function drawReportInfo(doc, left, top, width) {
    doc.setFillColor(245, 248, 251);
    doc.setDrawColor(214, 226, 239);
    doc.roundedRect(left, top, width, 18, 2, 2, 'FD');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8.2);
    doc.setTextColor(18, 42, 64);
    doc.text('Resumen del consolidado', left + 4, top + 5.8);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7.2);
    doc.setTextColor(71, 85, 105);
    const filters = [
      `Fecha operativa: ${report.period || '-'}`,
      `Revision: ${report.revision || 'TODOS'}`,
      report.buscar ? `Busqueda: ${report.buscar}` : ''
    ].filter(Boolean).join(' | ');
    doc.text(doc.splitTextToSize(filters, width - 8), left + 4, top + 11);
  }

  async function exportPdf() {
    const payload = tablePayload();
    if (!payload.body.length) {
      showNotice('No hay registros visibles para exportar.', false);
      return;
    }
    if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
      showNotice('No se pudo cargar el generador PDF.', false);
      return;
    }

    try {
      const doc = await window.N360PDF.createDocument({
        orientation: 'landscape',
        title: report.title || 'CONSOLIDADO DE SALIDAS DE BUSES',
        secondTitle: report.subtitle || 'Buses con programacion cerrada',
        description: 'Consolidado generado desde la tabla auxiliar del cierre operativo diario.',
        docCode: report.docCode || 'FLOTA_CONS_SALIDAS',
        userName: report.generatedBy || '',
        dni: report.dni || '',
        logoLeft: report.logoLeft,
        logoRight: report.logoRight,
        useCover: false,
        content: function (doc) {
          const left = 12.7;
          const right = 12.7;
          const width = doc.internal.pageSize.getWidth() - left - right;
          const y = 34;

          if (typeof doc.autoTable !== 'function') {
            throw new Error('No se pudo cargar jsPDF AutoTable.');
          }

          drawReportInfo(doc, left, y, width);
          doc.autoTable({
            head: [payload.head],
            body: payload.body,
            startY: y + 25,
            margin: { left, right, top: 32, bottom: 22 },
            rowPageBreak: 'avoid',
            styles: {
              fontSize: 6.4,
              cellPadding: 1.25,
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
              0: { cellWidth: 16, halign: 'center' },
              1: { cellWidth: 30 },
              2: { cellWidth: 50 },
              3: { cellWidth: 40 },
              4: { cellWidth: 55 },
              5: { cellWidth: 26, halign: 'center' },
              6: { cellWidth: 50 }
            },
            didParseCell: function (data) {
              if (data.section !== 'body') return;
              if (data.column.index === 5) {
                data.cell.styles.fontStyle = 'bold';
                const raw = String(data.cell.raw || '').toUpperCase();
                if (raw.includes('VALIDADO')) data.cell.styles.textColor = [5, 112, 68];
                if (raw.includes('OBSERVADO')) data.cell.styles.textColor = [170, 36, 31];
                if (raw.includes('CORREGIDO')) data.cell.styles.textColor = [7, 89, 133];
              }
            }
          });
        }
      });

      doc.save(`${report.fileBase || 'consolidado_salidas_buses'}_${moneyDate()}.pdf`);
    } catch (error) {
      console.error(error);
      showNotice('No se pudo generar el PDF.', false);
    }
  }

  function driverDisplay(driver) {
    const dni = compact(driver.dni || '');
    const licencia = compact(driver.licencia || '');
    return {
      title: compact(driver.label || driver.conductor || ''),
      meta: [
        dni ? `DNI ${dni}` : '',
        licencia ? `Lic. ${licencia}` : ''
      ].filter(Boolean).join(' · ')
    };
  }

  function setupDriverEditor() {
    const modalEl = document.getElementById('csbDriverModal');
    if (!modalEl || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    const currentEl = modalEl.querySelector('[data-csb-driver-current]');
    const searchInput = modalEl.querySelector('[data-csb-driver-search]');
    const listEl = modalEl.querySelector('[data-csb-driver-list]');
    const saveButton = modalEl.querySelector('[data-csb-driver-save]');
    if (!currentEl || !searchInput || !listEl || !saveButton) return;

    let state = {
      row: null,
      line: null,
      index: -1,
      selected: null
    };

    const renderList = () => {
      const term = compact(searchInput.value).toLowerCase();
      const matches = conductores
        .filter((driver) => {
          if (!term) return true;
          return [
            driver.label,
            driver.conductor,
            driver.dni,
            driver.licencia
          ].map(compact).join(' ').toLowerCase().includes(term);
        })
        .slice(0, 80);

      if (!matches.length) {
        listEl.innerHTML = '<div class="csb-driver-empty">No se encontraron conductores activos.</div>';
        return;
      }

      listEl.innerHTML = matches.map((driver) => {
        const display = driverDisplay(driver);
        const selected = state.selected && Number(state.selected.id) === Number(driver.id);
        return `<button type="button" class="csb-driver-choice ${selected ? 'is-selected' : ''}" data-csb-driver-choice="${Number(driver.id)}">
          <strong>${escapeHtml(display.title || 'Conductor sin nombre')}</strong>
          <span>${escapeHtml(display.meta || 'Sin DNI ni licencia registrada')}</span>
        </button>`;
      }).join('');
    };

    listEl.addEventListener('click', (event) => {
      const choice = event.target.closest('[data-csb-driver-choice]');
      if (!choice) return;
      const id = Number(choice.dataset.csbDriverChoice || 0);
      state.selected = conductores.find((driver) => Number(driver.id) === id) || null;
      saveButton.disabled = !state.selected;
      renderList();
    });

    searchInput.addEventListener('input', renderList);

    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-csb-driver-edit]');
      if (!button) return;

      const row = button.closest('[data-csb-row]');
      const line = button.closest('[data-csb-driver-line]');
      const index = Number(button.dataset.csbDriverIndex ?? line?.dataset.csbDriverIndex ?? -1);
      const estadoDb = String(row?.dataset.csbDbRevision || row?.querySelector('[data-csb-field="estado"]')?.value || '').toUpperCase();

      if (!row || !line || index < 0) return;
      if (estadoDb !== 'OBSERVADO') {
        showNotice('Guarda la revision como OBSERVADO antes de editar conductores.', false);
        return;
      }
      if (!conductores.length) {
        showNotice('No hay conductores activos disponibles.', false);
        return;
      }

      state = { row, line, index, selected: null };
      currentEl.textContent = compact(line.querySelector('[data-csb-driver-text]')?.textContent || 'Sin conductor asignado');
      searchInput.value = '';
      saveButton.disabled = true;
      renderList();
      modal.show();
      setTimeout(() => searchInput.focus(), 180);
    });

    saveButton.addEventListener('click', async () => {
      if (!state.row || !state.line || !state.selected) return;

      const originalHtml = saveButton.innerHTML;
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'update_driver');
      fd.append('id', state.row.dataset.csbRow || '');
      fd.append('driver_index', String(state.index));
      fd.append('driver_id', String(state.selected.id));

      saveButton.disabled = true;
      saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando';

      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!json.ok) {
          throw new Error(json.message || 'No se pudo actualizar el conductor.');
        }

        const target = state.line.querySelector('[data-csb-driver-text]');
        if (target) {
          target.textContent = json.data?.driver_label || state.selected.label || state.selected.conductor || '';
        }
        showNotice(json.message || 'Conductor actualizado.', true);
        modal.hide();
      } catch (error) {
        showNotice(error.message || 'No se pudo actualizar el conductor.', false);
        saveButton.disabled = false;
      } finally {
        saveButton.innerHTML = originalHtml;
      }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      state = { row: null, line: null, index: -1, selected: null };
      currentEl.textContent = 'Sin seleccionar';
      searchInput.value = '';
      listEl.innerHTML = '';
      saveButton.disabled = true;
    });
  }

  function setupCalendar() {
    const open = document.querySelector('[data-csb-calendar-open]');
    const modalEl = document.getElementById('csbCalendarModal');
    const monthInput = modalEl?.querySelector('[data-csb-calendar-month]');
    const grid = modalEl?.querySelector('[data-csb-calendar-grid]');
    if (!open || !modalEl || !monthInput || !grid || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    const weekdays = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];

    const loadCalendar = async () => {
      const month = monthInput.value || new Date().toISOString().slice(0, 7);
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'calendar_counts');
      fd.append('month', month);

      grid.innerHTML = '<div class="csb-calendar-loading">Cargando calendario...</div>';
      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.message || 'No se pudo cargar el calendario.');
        renderCalendar(month, json.data?.counts || {});
      } catch (error) {
        grid.innerHTML = `<div class="csb-calendar-loading">${compact(error.message || 'No se pudo cargar el calendario.')}</div>`;
      }
    };

    const renderCalendar = (month, counts) => {
      const [year, monthNumber] = month.split('-').map(Number);
      const first = new Date(year, monthNumber - 1, 1);
      const last = new Date(year, monthNumber, 0);
      let html = weekdays.map((day) => `<div class="csb-calendar-weekday">${day}</div>`).join('');

      for (let i = 0; i < first.getDay(); i += 1) {
        html += '<div class="csb-calendar-empty" aria-hidden="true"></div>';
      }

      for (let day = 1; day <= last.getDate(); day += 1) {
        const date = `${year}-${String(monthNumber).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const total = Number(counts[date] || 0);
        const cls = total > 0 ? 'csb-calendar-day has-data' : 'csb-calendar-day';
        const url = `${endpoint}?fecha_operativa=${encodeURIComponent(date)}`;
        html += `<a class="${cls}" href="${url}">
          <strong>${day}</strong>
          <span>${total ? `${total} programaciones` : 'Sin datos'}</span>
        </a>`;
      }

      grid.innerHTML = html;
    };

    open.addEventListener('click', () => {
      modal.show();
      loadCalendar();
    });
    monthInput.addEventListener('change', loadCalendar);
  }

  document.querySelectorAll('[data-csb-save]').forEach((button) => {
    button.addEventListener('click', () => saveRow(button));
  });
  document.querySelectorAll('[data-csb-state-option]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-csb-row]');
      if (!row) return;
      syncStateButtons(row, button.dataset.csbStateOption || 'PENDIENTE');
    });
  });
  rows.forEach((row) => {
    syncStateButtons(row, row.querySelector('[data-csb-field="estado"]')?.value || 'PENDIENTE');
    const initialDuplicate = row.dataset.csbHojarutaDuplicate === '1';
    syncHojaRutaState(row, row.dataset.csbHasHojaruta === '1');
    if (initialDuplicate) {
      setHojaRutaValidation(row, 'duplicate');
    }

    const hojaRutaInput = row.querySelector('[data-csb-field="hojaruta"]');
    if (hojaRutaInput) {
      hojaRutaInput.addEventListener('input', () => {
        const oldTimer = hojaRutaTimers.get(row);
        if (oldTimer) window.clearTimeout(oldTimer);

        const localOk = validateHojaRuta(row, false);
        Promise.resolve(localOk).then((ok) => {
          if (!ok || !hojaRutaKey(hojaRutaInput.value)) return;
          const timer = window.setTimeout(() => validateHojaRuta(row, true), 550);
          hojaRutaTimers.set(row, timer);
        });
      });

      hojaRutaInput.addEventListener('blur', () => {
        const oldTimer = hojaRutaTimers.get(row);
        if (oldTimer) window.clearTimeout(oldTimer);
        validateHojaRuta(row, true);
      });
    }
  });
  document.querySelector('[data-csb-export-pdf]')?.addEventListener('click', exportPdf);
  setupGroupFilter();
  setupDriverEditor();
  setupCalendar();
})();
