(function () {
  const cfg = window.N360_CSB || {};
  const endpoint = cfg.endpoint || 'consolidado_salidas_buses.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};
  const conductores = Array.isArray(cfg.conductores) ? cfg.conductores : [];
  const rows = Array.from(document.querySelectorAll('[data-csb-row]'));
  const visiblePill = document.querySelector('[data-csb-visible-pill]');
  const sortHojaRutaButton = document.querySelector('[data-csb-sort-hojaruta]');
  const originalRowOrder = new Map(rows.map((row, index) => [row, index]));
  let hojaRutaSortActive = false;

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

  function currentVisibleRows() {
    return Array.from(document.querySelectorAll('[data-csb-row]'))
      .filter((row) => !row.hidden);
  }

  function activeGroupValue() {
    const active = document.querySelector('[data-csb-group-filter] [data-csb-group].is-active');
    return active?.dataset.csbGroup || '__ALL__';
  }

  function activeGroupLabel() {
    const group = activeGroupValue();
    return group === '__ALL__' ? 'Todos' : compact(group || 'SIN GRUPO');
  }

  function rowGroupLabel(row) {
    const active = activeGroupValue();
    if (active && active !== '__ALL__') {
      return compact(active) || 'SIN GRUPO';
    }

    const groups = String(row.dataset.csbGroups || '')
      .split('|')
      .map((item) => compact(item))
      .filter(Boolean);
    return groups[0] || 'SIN GRUPO';
  }

  function formatIsoDate(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : compact(value || '-');
  }

  function rowDriversText(row) {
    const drivers = Array.from(row.querySelectorAll('[data-csb-driver-text]'))
      .map((span) => compact(span.textContent))
      .filter((value) => value && value.toLowerCase() !== 'sin conductor asignado');
    return drivers.length ? drivers.join('\n') : '-';
  }

  function rowRevisionText(row) {
    return compact(
      row.dataset.csbDbRevision
      || row.querySelector('[data-csb-status]')?.textContent
      || row.querySelector('[data-csb-field="estado"]')?.value
      || 'PENDIENTE'
    ).toUpperCase();
  }

  function rowRouteReportData(row) {
    const origen = compact(row.dataset.csbTransferOrigin || '');
    const destino = compact(row.dataset.csbTransferDestination || '');
    const rutaExtra = compact(row.dataset.csbTransferRoute || '');
    const baseRoute = `${origen || '-'} -> ${destino || '-'}`;
    const routeLines = [baseRoute];
    if (rutaExtra && rutaExtra !== baseRoute) {
      routeLines.push(rutaExtra);
    }

    const comentario = compact(row.querySelector('[data-csb-field="comentario"]')?.value || '');
    const correccion = compact(row.querySelector('[data-csb-field="correccion"]')?.value || '');
    const notes = [
      comentario ? `Comentario: ${comentario}` : '',
      correccion ? `Correccion: ${correccion}` : ''
    ].filter(Boolean).join(' | ');
    if (notes) {
      routeLines.push(`Obs: ${notes}`);
    }

    return {
      group: rowGroupLabel(row),
      fecha: compact(row.dataset.csbTransferDate || ''),
      hora: compact(row.dataset.csbTransferHour || ''),
      unidad: compact(row.dataset.csbTransferUnit || ''),
      servicio: compact(row.dataset.csbTransferService || ''),
      hojaRuta: compact(row.querySelector('[data-csb-field="hojaruta"]')?.value || ''),
      conductores: rowDriversText(row),
      estado: rowRevisionText(row),
      ruta: routeLines.join('\n')
    };
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
        bus: compact(localDuplicate.dataset.csbTransferUnit || ''),
        fecha: compact(localDuplicate.dataset.csbTransferDate || report.period || ''),
        hora: compact(localDuplicate.dataset.csbTransferHour || '')
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
      if (hojaRutaSortActive) {
        applyHojaRutaSort();
      }
      showNotice(json.message || 'Cambios guardados.', true);
    } catch (err) {
      showNotice(err.message || 'No se pudo guardar.', false);
    } finally {
      button.disabled = false;
      button.innerHTML = originalHtml;
    }
  }

  function hojaRutaSortValue(row) {
    return compact(row.querySelector('[data-csb-field="hojaruta"]')?.value || '');
  }

  function applyHojaRutaSort() {
    const tableBody = document.querySelector('[data-csb-table] tbody');
    if (!tableBody || !rows.length) return;

    const orderedRows = [...rows];

    if (hojaRutaSortActive) {
      orderedRows.sort((a, b) => {
        const valueA = hojaRutaSortValue(a);
        const valueB = hojaRutaSortValue(b);
        const emptyA = valueA === '';
        const emptyB = valueB === '';

        if (emptyA !== emptyB) return emptyA ? 1 : -1;

        if (!emptyA && !emptyB) {
          const compared = valueA.localeCompare(valueB, 'es-PE', {
            numeric: true,
            sensitivity: 'base'
          });
          if (compared !== 0) return compared;
        }

        return (originalRowOrder.get(a) ?? 0) - (originalRowOrder.get(b) ?? 0);
      });
    } else {
      orderedRows.sort((a, b) =>
        (originalRowOrder.get(a) ?? 0) - (originalRowOrder.get(b) ?? 0)
      );
    }

    orderedRows.forEach((row) => tableBody.appendChild(row));
  }

  function syncHojaRutaSortButton() {
    if (!sortHojaRutaButton) return;

    const label = sortHojaRutaButton.querySelector('[data-csb-sort-hojaruta-label]');
    const icon = sortHojaRutaButton.querySelector('i');

    sortHojaRutaButton.classList.toggle('is-active', hojaRutaSortActive);
    sortHojaRutaButton.setAttribute('aria-pressed', hojaRutaSortActive ? 'true' : 'false');
    sortHojaRutaButton.title = hojaRutaSortActive
      ? 'Restaurar el orden operativo original'
      : 'Ordenar visualmente por Hoja de Ruta';

    if (label) {
      label.textContent = hojaRutaSortActive
        ? 'Restaurar orden horario'
        : 'Ordenar por Hoja de Ruta';
    }

    if (icon) {
      icon.className = hojaRutaSortActive
        ? 'bi bi-arrow-counterclockwise'
        : 'bi bi-sort-numeric-down';
    }
  }

  function setupHojaRutaSort() {
    if (!sortHojaRutaButton) return;

    sortHojaRutaButton.addEventListener('click', () => {
      hojaRutaSortActive = !hojaRutaSortActive;
      applyHojaRutaSort();
      syncHojaRutaSortButton();

      showNotice(
        hojaRutaSortActive
          ? 'Orden visual aplicado por Hoja de Ruta. Las filas sin Hoja de Ruta quedan al final.'
          : 'Se restauró el orden operativo original.',
        true
      );
    });

    syncHojaRutaSortButton();
  }

  function setupHojaRutaList() {
    const openButton = document.querySelector('[data-csb-hojarutas-open]');
    const modalEl = document.getElementById('csbHojaRutaListModal');
    const listEl = modalEl?.querySelector('[data-csb-hojarutas-list]');
    const totalEl = modalEl?.querySelector('[data-csb-hojarutas-total]');
    const completasEl = modalEl?.querySelector('[data-csb-hojarutas-completas]');
    const pendientesEl = modalEl?.querySelector('[data-csb-hojarutas-pendientes]');
    const excelButton = modalEl?.querySelector('[data-csb-hojarutas-excel]');

    if (!openButton || !modalEl || !listEl || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);

    // Se toma el DOM en el orden visual actual para respetar filtros y ordenamientos de pantalla.
    const visibleRowsNow = () => Array.from(document.querySelectorAll('[data-csb-row]'))
      .filter((row) => !row.hidden);

    const routeRowData = (row) => {
      const fecha = compact(row.dataset.csbTransferDate || '');
      const hora = compact(row.dataset.csbTransferHour || '');
      const unidad = compact(row.dataset.csbTransferUnit || '');
      const servicio = compact(row.dataset.csbTransferService || '');
      const origen = compact(row.dataset.csbTransferOrigin || '');
      const destino = compact(row.dataset.csbTransferDestination || '');
      const rutaExtra = compact(row.dataset.csbTransferRoute || '');
      const hojaRuta = compact(row.querySelector('[data-csb-field="hojaruta"]')?.value || '');
      const revision = compact(row.dataset.csbDbRevision || row.querySelector('[data-csb-field="estado"]')?.value || '');
      const duplicate = row.dataset.csbHojarutaDuplicate === '1';

      return {
        fecha,
        hora,
        unidad,
        servicio,
        origen,
        destino,
        rutaExtra,
        hojaRuta,
        revision,
        duplicate,
        estadoHojaRuta: duplicate ? 'DUPLICADA' : (hojaRuta ? 'REGISTRADA' : 'PENDIENTE')
      };
    };

    const excelSafe = (value) => {
      const text = compact(value);
      return /^[=+\-@]/.test(text) ? `'${text}` : text;
    };

    const renderList = () => {
      const visibleRows = visibleRowsNow();
      let completas = 0;

      const html = visibleRows.map((row) => {
        const data = routeRowData(row);
        if (data.hojaRuta) completas += 1;

        const routeLabel = `${data.origen || '-'} → ${data.destino || '-'}${data.rutaExtra ? ` · ${data.rutaExtra}` : ''}`;
        const statusClass = data.duplicate
          ? 'is-duplicate'
          : (data.hojaRuta ? 'is-complete' : 'is-pending');

        return `<article class="csb-route-list-item ${statusClass}">
          <div class="csb-route-list-main">
            <div class="csb-route-list-trip">
              <span>${escapeHtml([data.fecha, data.hora].filter(Boolean).join(' · ') || '-')}</span>
              <strong>${escapeHtml(data.unidad || 'Unidad sin identificar')}</strong>
              <small>${escapeHtml(routeLabel)}</small>
            </div>
            <span class="csb-route-list-status">${escapeHtml(data.estadoHojaRuta)}</span>
          </div>
          <div class="csb-route-list-code">
            <span>Hoja de Ruta</span>
            <strong>${escapeHtml(data.hojaRuta || 'Sin Hoja de Ruta registrada')}</strong>
          </div>
        </article>`;
      }).join('');

      const total = visibleRows.length;
      if (totalEl) totalEl.textContent = new Intl.NumberFormat('es-PE').format(total);
      if (completasEl) completasEl.textContent = new Intl.NumberFormat('es-PE').format(completas);
      if (pendientesEl) pendientesEl.textContent = new Intl.NumberFormat('es-PE').format(total - completas);

      listEl.innerHTML = html || '<div class="csb-route-list-empty">No hay viajes visibles con los filtros actuales.</div>';
    };

    const exportExcel = () => {
      const visibleRows = visibleRowsNow();
      if (!visibleRows.length) {
        showNotice('No hay viajes visibles para exportar.', false);
        return;
      }

      if (!window.XLSX || !window.XLSX.utils) {
        showNotice('No se pudo cargar el generador de Excel.', false);
        return;
      }

      const data = visibleRows.map(routeRowData);
      const completas = data.filter((item) => item.hojaRuta).length;
      const pendientes = data.length - completas;

      const table = data.map((item, index) => [
        index + 1,
        excelSafe(item.fecha),
        excelSafe(item.hora),
        excelSafe(item.unidad),
        excelSafe(item.servicio),
        excelSafe(item.origen),
        excelSafe(item.destino),
        excelSafe(item.rutaExtra),
        excelSafe(item.hojaRuta),
        excelSafe(item.estadoHojaRuta),
        excelSafe(item.revision)
      ]);

      const aoa = [
        ['NORTE360 - HOJAS DE RUTA'],
        ['Periodo operativo', excelSafe(report.period || '-')],
        ['Viajes visibles', data.length, 'Con Hoja de Ruta', completas, 'Pendientes', pendientes],
        [],
        ['N°', 'Fecha operativa', 'Hora', 'Unidad', 'Servicio', 'Origen', 'Destino', 'Ruta intermedia', 'Hoja de Ruta', 'Estado H.R.', 'Revisión'],
        ...table
      ];

      const ws = window.XLSX.utils.aoa_to_sheet(aoa);

      // Presentación tabular y cómoda al abrir en Excel.
      ws['!cols'] = [
        { wch: 6 },
        { wch: 16 },
        { wch: 10 },
        { wch: 24 },
        { wch: 22 },
        { wch: 22 },
        { wch: 22 },
        { wch: 34 },
        { wch: 24 },
        { wch: 16 },
        { wch: 16 }
      ];

      ws['!autofilter'] = {
        ref: `A5:K${Math.max(5, table.length + 5)}`
      };

      const wb = window.XLSX.utils.book_new();
      window.XLSX.utils.book_append_sheet(wb, ws, 'Hojas de Ruta');

      const fechaIni = String(cfg.fechaInicio || cfg.fechaOperativa || '').replace(/-/g, '');
      const fechaFin = String(cfg.fechaFin || cfg.fechaOperativa || '').replace(/-/g, '');
      const rango = fechaIni && fechaFin && fechaIni !== fechaFin
        ? `${fechaIni}_${fechaFin}`
        : (fechaIni || fechaFin || moneyDate().slice(0, 8));

      window.XLSX.writeFile(wb, `hojas_de_ruta_${rango}.xlsx`, {
        compression: true
      });

      showNotice(`Excel generado con ${data.length} viajes visibles.`, true);
    };

    openButton.addEventListener('click', () => {
      renderList();
      modal.show();
    });

    excelButton?.addEventListener('click', exportExcel);
  }

  function setupOperationalSummary() {
    const openButton = document.querySelector('[data-csb-general-open]');
    const modalEl = document.getElementById('csbGeneralSummaryModal');
    const bodyEl = modalEl?.querySelector('[data-csb-general-body]');
    const totalEl = modalEl?.querySelector('[data-csb-general-total]');
    const datesEl = modalEl?.querySelector('[data-csb-general-dates]');
    const statusesEl = modalEl?.querySelector('[data-csb-general-statuses]');
    const excelButton = modalEl?.querySelector('[data-csb-general-excel]');
    const filterForm = document.querySelector('.csb-filter form');

    if (!openButton || !modalEl || !bodyEl || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    const stateKeys = [
      'PENDIENTE',
      'VALIDADO',
      'OBSERVADO',
      'CORREGIDO',
      'ANULADO',
      'MANUAL',
      'TRANSBORDADO',
      'TRANSBORDO'
    ];

    const visibleRowsNow = () => Array.from(document.querySelectorAll('[data-csb-row]'))
      .filter((row) => !row.hidden);

    const normalizeState = (row) => {
      const state = compact(
        row.dataset.csbDbRevision
        || row.querySelector('[data-csb-status]')?.textContent
        || row.querySelector('[data-csb-field="estado"]')?.value
        || 'PENDIENTE'
      ).toUpperCase();

      return stateKeys.includes(state) ? state : 'PENDIENTE';
    };

    const formatDate = (isoDate) => {
      const match = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
      return match ? `${match[3]}/${match[2]}/${match[1]}` : compact(isoDate || '-');
    };

    const buildSummary = () => {
      const summaryMap = new Map();

      visibleRowsNow().forEach((row) => {
        const fecha = compact(row.dataset.csbTransferDate || '');
        if (!fecha) return;

        if (!summaryMap.has(fecha)) {
          const counters = { TOTAL: 0 };
          stateKeys.forEach((key) => { counters[key] = 0; });
          summaryMap.set(fecha, counters);
        }

        const counters = summaryMap.get(fecha);
        const estado = normalizeState(row);
        counters.TOTAL += 1;
        counters[estado] += 1;
      });

      return Array.from(summaryMap.entries())
        .sort(([dateA], [dateB]) => dateA.localeCompare(dateB))
        .map(([fecha, counts]) => ({ fecha, ...counts }));
    };

    const statusCell = (value, state) => {
      const n = Number(value || 0);
      return `<span class="csb-general-count ${n ? `is-${state.toLowerCase()}` : 'is-zero'}">${new Intl.NumberFormat('es-PE').format(n)}</span>`;
    };

    const renderSummary = () => {
      const summary = buildSummary();
      const visibleRows = visibleRowsNow();
      const presentStates = new Set();

      visibleRows.forEach((row) => presentStates.add(normalizeState(row)));

      if (totalEl) totalEl.textContent = new Intl.NumberFormat('es-PE').format(visibleRows.length);
      if (datesEl) datesEl.textContent = new Intl.NumberFormat('es-PE').format(summary.length);
      if (statusesEl) statusesEl.textContent = new Intl.NumberFormat('es-PE').format(presentStates.size);

      if (!summary.length) {
        bodyEl.innerHTML = '<tr><td colspan="10" class="csb-general-empty">No hay viajes visibles con los filtros actuales.</td></tr>';
        return;
      }

      bodyEl.innerHTML = summary.map((item) => `
        <tr>
          <td>
            <div class="csb-general-date-cell">
              <div class="csb-general-date">
                <i class="bi bi-calendar2-check"></i>
                <strong>${escapeHtml(formatDate(item.fecha))}</strong>
              </div>
              <button
                type="button"
                class="csb-general-filter-btn"
                data-csb-general-filter-date="${escapeHtml(item.fecha)}"
                title="Filtrar la vista únicamente por ${escapeHtml(formatDate(item.fecha))}"
              >
                <i class="bi bi-funnel"></i>
                Ver fecha
              </button>
            </div>
          </td>
          <td><strong class="csb-general-total">${new Intl.NumberFormat('es-PE').format(item.TOTAL)}</strong></td>
          <td>${statusCell(item.PENDIENTE, 'PENDIENTE')}</td>
          <td>${statusCell(item.VALIDADO, 'VALIDADO')}</td>
          <td>${statusCell(item.OBSERVADO, 'OBSERVADO')}</td>
          <td>${statusCell(item.CORREGIDO, 'CORREGIDO')}</td>
          <td>${statusCell(item.ANULADO, 'ANULADO')}</td>
          <td>${statusCell(item.MANUAL, 'MANUAL')}</td>
          <td>${statusCell(item.TRANSBORDADO, 'TRANSBORDADO')}</td>
          <td>${statusCell(item.TRANSBORDO, 'TRANSBORDO')}</td>
        </tr>
      `).join('');
    };

    const applyDateFilter = (date) => {
      if (!filterForm) {
        showNotice('No se encontró el formulario de filtros.', false);
        return;
      }

      const startInput = filterForm.querySelector('[name="fecha_inicio"]');
      const endInput = filterForm.querySelector('[name="fecha_fin"]');
      if (!startInput || !endInput) {
        showNotice('No se encontraron los filtros de fecha operativa.', false);
        return;
      }

      startInput.value = date;
      endInput.value = date;
      modal.hide();

      if (typeof filterForm.requestSubmit === 'function') {
        filterForm.requestSubmit();
      } else {
        filterForm.submit();
      }
    };

    const excelSafe = (value) => {
      const text = compact(value);
      return /^[=+\-@]/.test(text) ? `'${text}` : text;
    };

    const exportExcel = () => {
      const summary = buildSummary();
      if (!summary.length) {
        showNotice('No hay datos visibles para exportar.', false);
        return;
      }

      if (!window.XLSX || !window.XLSX.utils) {
        showNotice('No se pudo cargar el generador de Excel.', false);
        return;
      }

      const aoa = [
        ['NORTE360 - RESUMEN DE ESTADOS POR FECHA OPERATIVA'],
        ['Periodo visible', excelSafe(report.period || '-')],
        ['Viajes visibles', summary.reduce((total, item) => total + Number(item.TOTAL || 0), 0), 'Fechas operativas', summary.length],
        [],
        ['Fecha operativa', 'Total', 'Pendiente', 'Validado', 'Observado', 'Corregido', 'Anulado', 'Manual', 'Transbordado', 'Transbordo'],
        ...summary.map((item) => [
          excelSafe(formatDate(item.fecha)),
          item.TOTAL,
          item.PENDIENTE,
          item.VALIDADO,
          item.OBSERVADO,
          item.CORREGIDO,
          item.ANULADO,
          item.MANUAL,
          item.TRANSBORDADO,
          item.TRANSBORDO
        ])
      ];

      const ws = window.XLSX.utils.aoa_to_sheet(aoa);
      ws['!cols'] = [
        { wch: 18 },
        { wch: 10 },
        { wch: 12 },
        { wch: 12 },
        { wch: 12 },
        { wch: 12 },
        { wch: 12 },
        { wch: 12 },
        { wch: 15 },
        { wch: 13 }
      ];
      ws['!autofilter'] = {
        ref: `A5:J${Math.max(5, summary.length + 5)}`
      };

      const wb = window.XLSX.utils.book_new();
      window.XLSX.utils.book_append_sheet(wb, ws, 'Resumen por fecha');

      const fechaIni = String(cfg.fechaInicio || cfg.fechaOperativa || '').replace(/-/g, '');
      const fechaFin = String(cfg.fechaFin || cfg.fechaOperativa || '').replace(/-/g, '');
      const rango = fechaIni && fechaFin && fechaIni !== fechaFin
        ? `${fechaIni}_${fechaFin}`
        : (fechaIni || fechaFin || moneyDate().slice(0, 8));

      window.XLSX.writeFile(wb, `resumen_estados_viajes_${rango}.xlsx`, {
        compression: true
      });

      showNotice(`Excel generado con ${summary.length} fechas operativas.`, true);
    };

    openButton.addEventListener('click', () => {
      renderSummary();
      modal.show();
    });

    bodyEl.addEventListener('click', (event) => {
      const button = event.target.closest('[data-csb-general-filter-date]');
      if (!button) return;
      const date = compact(button.dataset.csbGeneralFilterDate || '');
      if (date) applyDateFilter(date);
    });

    excelButton?.addEventListener('click', exportExcel);
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
    row.querySelectorAll('[data-csb-driver-add]').forEach((button) => {
      const driverCount = row.querySelectorAll('[data-csb-driver-line]').length;
      const canAdd = dbValue === 'OBSERVADO' && driverCount === 1;
      button.hidden = !canAdd;
      button.disabled = !canAdd;
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
      `Periodo operativo: ${report.period || '-'}`,
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
              0: { cellWidth: 24, halign: 'center' },
              1: { cellWidth: 15, halign: 'center' },
              2: { cellWidth: 28 },
              3: { cellWidth: 44 },
              4: { cellWidth: 34 },
              5: { cellWidth: 48 },
              6: { cellWidth: 24, halign: 'center' },
              7: { cellWidth: 44 }
            },
            didParseCell: function (data) {
              if (data.section !== 'body') return;
              if (data.column.index === 6) {
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

  function routeReportGroups(data) {
    const map = new Map();
    data.forEach((item) => {
      const group = compact(item.group || 'SIN GRUPO') || 'SIN GRUPO';
      if (!map.has(group)) map.set(group, []);
      map.get(group).push(item);
    });
    return Array.from(map.entries()).map(([group, items]) => ({ group, items }));
  }

  function routeReportBody(items) {
    return items.map((item, index) => [
      String(index + 1).padStart(2, '0'),
      [formatIsoDate(item.fecha), item.hora || '-'].filter(Boolean).join('\n'),
      [item.unidad || '-', item.servicio || ''].filter(Boolean).join('\n'),
      item.ruta || '-',
      item.hojaRuta || 'PENDIENTE',
      item.conductores || '-',
      item.estado || 'PENDIENTE'
    ]);
  }

  function routeReportStateColor(value) {
    const raw = String(value || '').toUpperCase();
    if (raw.includes('VALIDADO') || raw.includes('CORREGIDO')) return [5, 112, 68];
    if (raw.includes('OBSERVADO') || raw.includes('ANULADO')) return [170, 36, 31];
    if (raw.includes('MANUAL') || raw.includes('TRANSBORDO')) return [7, 89, 133];
    return [146, 64, 14];
  }

  function drawRouteGroupHeader(doc, left, y, width, group, total) {
    doc.setFillColor(20, 38, 61);
    doc.setDrawColor(20, 38, 61);
    doc.rect(left, y, width, 8, 'FD');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7.4);
    doc.text(String(group || 'SIN GRUPO').toUpperCase(), left + 3, y + 5.4);
    doc.text(`${Number(total || 0).toLocaleString('es-PE')} viajes`, left + width - 3, y + 5.4, { align: 'right' });
    return y + 8;
  }

  async function exportRoutePdf() {
    const data = currentVisibleRows().map(rowRouteReportData);
    if (!data.length) {
      showNotice('No hay registros visibles para exportar.', false);
      return;
    }
    if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
      showNotice('No se pudo cargar el generador PDF.', false);
      return;
    }

    try {
      const groups = routeReportGroups(data);
      const totalHojaRuta = data.filter((item) => item.hojaRuta).length;
      const totalPendiente = data.length - totalHojaRuta;
      const formatter = new Intl.NumberFormat('es-PE');

      const doc = await window.N360PDF.createDocument({
        orientation: 'portrait',
        title: 'HOJA DE RUTA - CONSOLIDADO DE SALIDAS',
        secondTitle: report.period || 'Consolidado operativo',
        description: 'Reporte vertical generado con los viajes visibles del consolidado.',
        docCode: 'FLOTA_HOJA_RUTA_CONS',
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
          const pageH = doc.internal.pageSize.getHeight();
          const width = pageW - left - right;
          const bottomLimit = pageH - 24;
          let y = 34;

          if (window.N360PDF && typeof window.N360PDF.drawReportSummary === 'function') {
            y = window.N360PDF.drawReportSummary(doc, {
              x: left,
              y,
              width,
              title: 'Resumen de Hoja de Ruta',
              rows: [
                { label: 'Periodo', value: report.period || '-' },
                { label: 'Grupo visible', value: activeGroupLabel() },
                { label: 'Viajes visibles', value: formatter.format(data.length) },
                { label: 'Con Hoja de Ruta', value: formatter.format(totalHojaRuta) },
                { label: 'Pendientes', value: formatter.format(totalPendiente) },
                { label: 'Generado por', value: report.generatedBy || '-' }
              ],
              columns: 2,
              bottomGap: 7
            });
          }

          groups.forEach((groupBlock) => {
            if (y + 38 > bottomLimit) {
              doc.addPage();
              y = 34;
            }

            y = drawRouteGroupHeader(doc, left, y, width, groupBlock.group, groupBlock.items.length);

            doc.autoTable({
              head: [['#', 'Fecha / Hora', 'Unidad', 'Ruta', 'Hoja de ruta', 'Conductores', 'Estado']],
              body: routeReportBody(groupBlock.items),
              startY: y,
              margin: { left, right, top: 32, bottom: 22 },
              tableWidth: width,
              rowPageBreak: 'avoid',
              styles: {
                fontSize: 6.2,
                cellPadding: 1.15,
                overflow: 'linebreak',
                valign: 'middle',
                lineColor: [226, 232, 240],
                lineWidth: 0.08
              },
              headStyles: {
                fillColor: [235, 243, 250],
                textColor: [15, 42, 64],
                fontStyle: 'bold',
                halign: 'center'
              },
              alternateRowStyles: { fillColor: [249, 251, 253] },
              columnStyles: {
                0: { cellWidth: 9, halign: 'center' },
                1: { cellWidth: 23, halign: 'center' },
                2: { cellWidth: 28 },
                3: { cellWidth: 49 },
                4: { cellWidth: 25 },
                5: { cellWidth: 32 },
                6: { cellWidth: width - 166, halign: 'center' }
              },
              didParseCell: function (cellData) {
                if (cellData.section !== 'body') return;
                if (cellData.column.index === 4) {
                  const raw = String(cellData.cell.raw || '').toUpperCase();
                  cellData.cell.styles.fontStyle = 'bold';
                  cellData.cell.styles.textColor = raw === 'PENDIENTE' ? [146, 64, 14] : [5, 112, 68];
                }
                if (cellData.column.index === 6) {
                  cellData.cell.styles.fontStyle = 'bold';
                  const color = routeReportStateColor(cellData.cell.raw);
                  cellData.cell.styles.textColor = color;
                }
              }
            });

            y = (doc.lastAutoTable && doc.lastAutoTable.finalY ? doc.lastAutoTable.finalY : y) + 7;
          });
        }
      });

      doc.save(`${report.fileBase || 'consolidado_salidas_buses'}_hoja_ruta_${moneyDate()}.pdf`);
      showNotice(`PDF Hoja de Ruta generado con ${data.length.toLocaleString('es-PE')} viajes visibles.`, true);
    } catch (error) {
      console.error(error);
      showNotice('No se pudo generar el PDF de Hoja de Ruta.', false);
    }
  }

  function driverDisplay(driver) {
    const dni = compact(driver.dni || '');
    const licencia = compact(driver.licencia || '');
    const inactive = driver.es_activo === false || String(driver.estado_contrato || '').toUpperCase() !== 'ACTIVO';
    return {
      title: compact(driver.label || driver.conductor || ''),
      inactive,
      meta: [
        dni ? `DNI ${dni}` : '',
        licencia ? `Lic. ${licencia}` : '',
        inactive ? `INACTIVO${driver.estado_contrato && String(driver.estado_contrato).toUpperCase() !== 'INACTIVO' ? ` · ${String(driver.estado_contrato).toUpperCase()}` : ''}` : ''
      ].filter(Boolean).join(' · ')
    };
  }

  function setupDriverEditor() {
    const modalEl = document.getElementById('csbDriverModal');
    if (!modalEl || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    const titleEl = modalEl.querySelector('[data-csb-driver-modal-title]');
    const currentLabelEl = modalEl.querySelector('[data-csb-driver-current-label]');
    const currentEl = modalEl.querySelector('[data-csb-driver-current]');
    const searchInput = modalEl.querySelector('[data-csb-driver-search]');
    const listEl = modalEl.querySelector('[data-csb-driver-list]');
    const saveButton = modalEl.querySelector('[data-csb-driver-save]');
    if (!currentEl || !searchInput || !listEl || !saveButton) return;

    const setSaveLabel = (text) => {
      const label = modalEl.querySelector('[data-csb-driver-save-label]');
      if (label) label.textContent = text;
    };

    let state = {
      mode: 'edit',
      row: null,
      line: null,
      index: -1,
      selected: null
    };

    const existingDriverTexts = () => {
      if (!state.row) return [];
      return Array.from(state.row.querySelectorAll('[data-csb-driver-text]'))
        .map((el) => compact(el.textContent).toLowerCase())
        .filter(Boolean);
    };

    const renderList = () => {
      const term = compact(searchInput.value).toLowerCase();
      const existing = existingDriverTexts();
      const matches = conductores
        .filter((driver) => {
          if (state.mode === 'add') {
            const display = driverDisplay(driver);
            if (existing.includes(compact(display.title).toLowerCase())) return false;
          }
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
        listEl.innerHTML = '<div class="csb-driver-empty">No se encontraron conductores disponibles.</div>';
        return;
      }

      listEl.innerHTML = matches.map((driver) => {
        const display = driverDisplay(driver);
        const selected = state.selected && Number(state.selected.id) === Number(driver.id);
        return `<button type="button" class="csb-driver-choice ${selected ? 'is-selected' : ''} ${display.inactive ? 'is-inactive' : ''}" data-csb-driver-choice="${Number(driver.id)}">
          <strong>${escapeHtml(display.title || 'Conductor sin nombre')}</strong>
          <span>${escapeHtml(display.meta || 'Sin DNI ni licencia registrada')}</span>
        </button>`;
      }).join('');
    };

    const openDriverModal = ({ mode, row, line = null, index = -1 }) => {
      const estadoDb = String(row?.dataset.csbDbRevision || row?.querySelector('[data-csb-field="estado"]')?.value || '').toUpperCase();
      if (!row) return;
      if (estadoDb !== 'OBSERVADO') {
        showNotice(`Guarda la revision como OBSERVADO antes de ${mode === 'add' ? 'agregar' : 'editar'} conductores.`, false);
        return;
      }
      if (!conductores.length) {
        showNotice('No hay conductores disponibles.', false);
        return;
      }

      if (mode === 'add') {
        const driverLines = row.querySelectorAll('[data-csb-driver-line]');
        if (driverLines.length !== 1) {
          showNotice('Solo puedes agregar un conductor cuando el viaje tiene uno asignado.', false);
          return;
        }
      }

      state = { mode, row, line, index, selected: null };
      if (titleEl) titleEl.textContent = mode === 'add' ? 'Agregar conductor al consolidado' : 'Editar conductor del consolidado';
      if (currentLabelEl) currentLabelEl.textContent = mode === 'add' ? 'Conductor ya asignado' : 'Conductor actual';
      setSaveLabel(mode === 'add' ? 'Agregar conductor' : 'Guardar conductor');

      if (mode === 'add') {
        currentEl.textContent = compact(row.querySelector('[data-csb-driver-text]')?.textContent || 'Sin conductor asignado');
      } else {
        currentEl.textContent = compact(line?.querySelector('[data-csb-driver-text]')?.textContent || 'Sin conductor asignado');
      }

      searchInput.value = '';
      saveButton.disabled = true;
      renderList();
      modal.show();
      setTimeout(() => searchInput.focus(), 180);
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
      const editButton = event.target.closest('[data-csb-driver-edit]');
      if (editButton) {
        const row = editButton.closest('[data-csb-row]');
        const line = editButton.closest('[data-csb-driver-line]');
        const index = Number(editButton.dataset.csbDriverIndex ?? line?.dataset.csbDriverIndex ?? -1);
        if (!row || !line || index < 0) return;
        openDriverModal({ mode: 'edit', row, line, index });
        return;
      }

      const addButton = event.target.closest('[data-csb-driver-add]');
      if (addButton) {
        const row = addButton.closest('[data-csb-row]');
        if (!row) return;
        openDriverModal({ mode: 'add', row });
      }
    });

    saveButton.addEventListener('click', async () => {
      if (!state.row || !state.selected) return;
      if (state.mode === 'edit' && !state.line) return;

      const originalHtml = saveButton.innerHTML;
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', state.mode === 'add' ? 'add_driver' : 'update_driver');
      fd.append('id', state.row.dataset.csbRow || '');
      if (state.mode === 'edit') {
        fd.append('driver_index', String(state.index));
      }
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
          throw new Error(json.message || (state.mode === 'add' ? 'No se pudo agregar el conductor.' : 'No se pudo actualizar el conductor.'));
        }

        const driverLabel = json.data?.driver_label || state.selected.label || state.selected.conductor || '';

        if (state.mode === 'add') {
          const driversBox = state.row.querySelector('[data-csb-drivers]');
          const addButton = driversBox?.querySelector('[data-csb-driver-add]');
          const index = Number(json.data?.driver_index ?? 1);
          if (driversBox) {
            const line = document.createElement('span');
            line.className = 'csb-driver-line';
            line.dataset.csbDriverLine = '';
            line.dataset.csbDriverIndex = String(index);
            line.innerHTML = `
              <span data-csb-driver-text>${escapeHtml(driverLabel)}</span>
              <button type="button" class="csb-driver-edit" data-csb-driver-edit data-csb-driver-index="${index}" title="Editar conductor" aria-label="Editar conductor">
                <i class="bi bi-pencil-square"></i>
              </button>`;
            if (addButton) {
              driversBox.insertBefore(line, addButton);
              addButton.remove();
            } else {
              driversBox.appendChild(line);
            }
          }
        } else {
          const target = state.line.querySelector('[data-csb-driver-text]');
          if (target) target.textContent = driverLabel;
        }

        syncStateButtons(state.row, state.row.dataset.csbDbRevision || 'OBSERVADO');
        showNotice(json.message || (state.mode === 'add' ? 'Conductor agregado.' : 'Conductor actualizado.'), true);
        modal.hide();
      } catch (error) {
        showNotice(error.message || (state.mode === 'add' ? 'No se pudo agregar el conductor.' : 'No se pudo actualizar el conductor.'), false);
        saveButton.disabled = false;
      } finally {
        saveButton.innerHTML = originalHtml;
      }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      state = { mode: 'edit', row: null, line: null, index: -1, selected: null };
      if (titleEl) titleEl.textContent = 'Editar conductor del consolidado';
      if (currentLabelEl) currentLabelEl.textContent = 'Conductor actual';
      setSaveLabel('Guardar conductor');
      currentEl.textContent = 'Sin seleccionar';
      searchInput.value = '';
      listEl.innerHTML = '';
      saveButton.disabled = true;
    });
  }
  function setupTransferTrip() {
    const modalEl = document.getElementById('csbTransferModal');
    const form = modalEl?.querySelector('[data-csb-transfer-form]');
    if (!modalEl || !form || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    const saveButton = form.querySelector('[data-csb-transfer-save]');
    const sourceIdInput = form.querySelector('[name="source_id"]');
    const sourceUnitEl = modalEl.querySelector('[data-csb-transfer-source-unit]');
    const sourceIdEl = modalEl.querySelector('[data-csb-transfer-source-id]');
    const sourceDateTimeEl = modalEl.querySelector('[data-csb-transfer-source-datetime]');
    const sourceServiceEl = modalEl.querySelector('[data-csb-transfer-source-service]');
    const sourceRouteEl = modalEl.querySelector('[data-csb-transfer-source-route]');
    const counterpartTitle = modalEl.querySelector('[data-csb-transfer-counterpart-title]');
    const counterpartRole = modalEl.querySelector('[data-csb-transfer-counterpart-role]');
    const origin = form.querySelector('[name="idorigen"]');
    const destination = form.querySelector('[name="iddestino"]');
    const routes = form.querySelector('[name="ruta_ids[]"]');
    const unit = form.querySelector('[name="idplaca"]');
    let sourceRow = null;

    const selectedRoutes = () => Array.from(routes?.selectedOptions || [])
      .map((option) => String(option.value || ''))
      .filter(Boolean);

    const currentRole = () => String(form.querySelector('[name="source_role"]:checked')?.value || 'TRANSBORDADO').toUpperCase();

    const syncRole = () => {
      const sourceRole = currentRole();
      const relatedRole = sourceRole === 'TRANSBORDADO' ? 'TRANSBORDO' : 'TRANSBORDADO';
      if (counterpartRole) counterpartRole.textContent = relatedRole;
      if (counterpartTitle) {
        counterpartTitle.textContent = relatedRole === 'TRANSBORDO'
          ? 'Unidad que realizó el TRANSBORDO'
          : 'Unidad que fue TRANSBORDADA';
      }
      modalEl.querySelectorAll('.csb-transfer-role-card').forEach((card) => {
        const input = card.querySelector('input[type="radio"]');
        card.classList.toggle('is-selected', !!input?.checked);
      });
    };

    const validateTransfer = () => {
      const origen = String(origin?.value || '');
      const destino = String(destination?.value || '');
      const idPlaca = String(unit?.value || '');
      const sourceIdPlaca = String(sourceRow?.dataset.csbTransferIdplaca || '');
      const rutaIds = selectedRoutes();

      if (idPlaca && sourceIdPlaca && idPlaca === sourceIdPlaca) {
        return 'La unidad relacionada debe ser diferente a la unidad seleccionada.';
      }
      if (origen && destino && origen === destino) {
        return 'El origen y destino no pueden ser iguales.';
      }
      if (origen && rutaIds.includes(origen)) {
        return 'Las rutas intermedias no deben repetir el origen.';
      }
      if (destino && rutaIds.includes(destino)) {
        return 'Las rutas intermedias no deben repetir el destino.';
      }
      return '';
    };

    form.querySelectorAll('[name="source_role"]').forEach((radio) => radio.addEventListener('change', syncRole));
    [origin, destination, routes, unit].forEach((field) => field?.addEventListener('change', () => {
      const message = validateTransfer();
      if (message) showNotice(message, false);
    }));

    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-csb-transfer-open]');
      if (!button) return;

      const row = button.closest('[data-csb-row]');
      if (!row) return;

      const estado = String(row.dataset.csbDbRevision || '').toUpperCase();
      if (estado === 'TRANSBORDADO' || estado === 'TRANSBORDO') {
        showNotice(`Este viaje ya está marcado como ${estado}.`, false);
        return;
      }

      sourceRow = row;
      form.reset();
      if (sourceIdInput) sourceIdInput.value = row.dataset.csbRow || '';

      const sourceId = row.dataset.csbRow || '0';
      const unitLabel = compact(row.dataset.csbTransferUnit || 'Unidad sin identificar');
      const date = compact(row.dataset.csbTransferDate || cfg.fechaOperativa || '');
      const hour = compact(row.dataset.csbTransferHour || '');
      const service = compact(row.dataset.csbTransferService || '-');
      const originLabel = compact(row.dataset.csbTransferOrigin || '-');
      const destinationLabel = compact(row.dataset.csbTransferDestination || '-');
      const routeExtra = compact(row.dataset.csbTransferRoute || '');

      if (sourceUnitEl) sourceUnitEl.textContent = unitLabel;
      if (sourceIdEl) sourceIdEl.textContent = `#${sourceId}`;
      if (sourceDateTimeEl) sourceDateTimeEl.textContent = [date, hour].filter(Boolean).join(' · ') || '-';
      if (sourceServiceEl) sourceServiceEl.textContent = service || '-';
      if (sourceRouteEl) {
        sourceRouteEl.textContent = `${originLabel} → ${destinationLabel}${routeExtra ? ` · ${routeExtra}` : ''}`;
      }

      // La fecha y los datos superiores salen de la fila ya cargada; no se hace consulta al abrir.
      // Como ayuda operativa, heredamos únicamente el destino final. El origen del transbordo se registra manualmente.
      if (destination) destination.value = String(row.dataset.csbTransferIddestino || '');
      if (origin) origin.value = '';
      if (routes) Array.from(routes.options).forEach((option) => { option.selected = false; });

      syncRole();
      modal.show();
      window.setTimeout(() => form.querySelector('[name="hora_salida"]')?.focus(), 180);
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!sourceRow) {
        showNotice('No se encontró el viaje seleccionado.', false);
        return;
      }

      const validation = validateTransfer();
      if (validation) {
        showNotice(validation, false);
        return;
      }

      const originalHtml = saveButton?.innerHTML || '';
      const fd = new FormData(form);
      fd.append('csrf', csrf);
      fd.append('action', 'create_transfer_trip');

      if (saveButton) {
        saveButton.disabled = true;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Registrando...';
      }

      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!json.ok) {
          throw new Error(json.message || 'No se pudo registrar el transbordo.');
        }

        modal.hide();
        showNotice(json.message || 'Transbordo registrado correctamente.', true);
        const redirect = json.data?.redirect || '';
        window.setTimeout(() => {
          if (redirect) window.location.href = redirect;
          else window.location.reload();
        }, 700);
      } catch (error) {
        showNotice(error.message || 'No se pudo registrar el transbordo.', false);
      } finally {
        if (saveButton) {
          saveButton.disabled = false;
          saveButton.innerHTML = originalHtml;
        }
      }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      sourceRow = null;
      form.reset();
      if (sourceIdInput) sourceIdInput.value = '';
      if (sourceUnitEl) sourceUnitEl.textContent = 'Sin seleccionar';
      if (sourceIdEl) sourceIdEl.textContent = '#0';
      if (sourceDateTimeEl) sourceDateTimeEl.textContent = '-';
      if (sourceServiceEl) sourceServiceEl.textContent = '-';
      if (sourceRouteEl) sourceRouteEl.textContent = '-';
      syncRole();
    });
  }

  function setupManualTrip() {
    const open = document.querySelector('[data-csb-manual-open]');
    const modalEl = document.getElementById('csbManualTripModal');
    const form = modalEl?.querySelector('[data-csb-manual-form]');
    if (!open || !modalEl || !form || !window.bootstrap) return;

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    const saveButton = form.querySelector('[data-csb-manual-save]');
    const origin = form.querySelector('[name="idorigen"]');
    const destination = form.querySelector('[name="iddestino"]');
    const routes = form.querySelector('[name="ruta_ids[]"]');

    const selectedRoutes = () => Array.from(routes?.selectedOptions || [])
      .map((option) => String(option.value || ''))
      .filter(Boolean);

    const validateManual = () => {
      const origen = String(origin?.value || '');
      const destino = String(destination?.value || '');
      const rutaIds = selectedRoutes();
      if (origen && destino && origen === destino) {
        return 'El origen y destino no pueden ser iguales.';
      }
      if (origen && rutaIds.includes(origen)) {
        return 'Las rutas intermedias no deben repetir el origen.';
      }
      if (destino && rutaIds.includes(destino)) {
        return 'Las rutas intermedias no deben repetir el destino.';
      }
      return '';
    };

    const softValidate = () => {
      const message = validateManual();
      if (message) showNotice(message, false);
    };

    [origin, destination, routes].forEach((field) => field?.addEventListener('change', softValidate));

    open.addEventListener('click', () => {
      modal.show();
      window.setTimeout(() => form.querySelector('[name="hora_salida"]')?.focus(), 180);
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const validation = validateManual();
      if (validation) {
        showNotice(validation, false);
        return;
      }

      const originalHtml = saveButton?.innerHTML || '';
      const fd = new FormData(form);
      fd.append('csrf', csrf);
      fd.append('action', 'create_manual_trip');

      if (saveButton) {
        saveButton.disabled = true;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando...';
      }

      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!json.ok) {
          throw new Error(json.message || 'No se pudo registrar el viaje manual.');
        }

        modal.hide();
        showNotice(json.message || 'Viaje manual registrado.', true);
        const redirect = json.data?.redirect || '';
        window.setTimeout(() => {
          if (redirect) window.location.href = redirect;
          else window.location.reload();
        }, 700);
      } catch (error) {
        showNotice(error.message || 'No se pudo registrar el viaje manual.', false);
      } finally {
        if (saveButton) {
          saveButton.disabled = false;
          saveButton.innerHTML = originalHtml;
        }
      }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      form.reset();
      const fecha = form.querySelector('[name="fecha_operativa"]');
      if (fecha && (cfg.fechaInicio || cfg.fechaOperativa)) fecha.value = cfg.fechaInicio || cfg.fechaOperativa;
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
        const url = `${endpoint}?fecha_inicio=${encodeURIComponent(date)}&fecha_fin=${encodeURIComponent(date)}`;
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
  document.querySelector('[data-csb-export-route-pdf]')?.addEventListener('click', exportRoutePdf);
  setupGroupFilter();
  setupHojaRutaSort();
  setupHojaRutaList();
  setupOperationalSummary();
  setupDriverEditor();
  setupTransferTrip();
  setupManualTrip();
  setupCalendar();
})();
