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
  setupGroupFilter();
  setupHojaRutaSort();
  setupHojaRutaList();
  setupDriverEditor();
  setupTransferTrip();
  setupManualTrip();
  setupCalendar();
})();
