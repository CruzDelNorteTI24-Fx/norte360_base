(function () {
  const cfg = window.N360_FCC || {};
  const endpoint = cfg.endpoint || 'control_conductores_salidas.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};

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

  async function saveRow(button) {
    const row = button.closest('[data-fcc-row]');
    if (!row) return;
    const id = row.dataset.fccRow || '';
    if (!id || id === '0') {
      showNotice('Este dia no tiene salida capturada.', false);
      return;
    }

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'update_driver_status');
    fd.append('id', id);
    fd.append('cond1_estado', row.querySelector('[data-fcc-field="cond1_estado"]')?.value || 'PENDIENTE');
    fd.append('cond1_observacion', row.querySelector('[data-fcc-field="cond1_observacion"]')?.value || '');
    fd.append('cond2_estado', row.querySelector('[data-fcc-field="cond2_estado"]')?.value || 'PENDIENTE');
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
      row.querySelectorAll('select').forEach(syncSelectClass);
      showNotice(json.message || 'Cambios guardados.', true);
    } catch (err) {
      showNotice(err.message || 'No se pudo guardar.', false);
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
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
      const dayNumber = clean(dayCell?.querySelector('strong')?.textContent || '');
      const weekday = clean(dayCell?.querySelector('span')?.textContent || '');
      const date = cfg.month && dayNumber ? `${cfg.month}-${dayNumber.padStart(2, '0')}` : '';

      return {
        dia: cellText(row, '[data-fcc-col="dia"]'),
        date,
        dayNumber,
        weekday,
        revision: cellText(row, '[data-fcc-col="revision"]'),
        cond1: cellText(row, '[data-fcc-col="cond1"]'),
        cond1Estado: cellText(row, '[data-fcc-field="cond1_estado"]'),
        cond1Obs: cellText(row, '[data-fcc-field="cond1_observacion"]'),
        cond2: cellText(row, '[data-fcc-col="cond2"]'),
        cond2Estado: cellText(row, '[data-fcc-field="cond2_estado"]'),
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
            busItem.dates.set(workDate.key || workDate.label, workDate);
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
            datesText: datesDetail.map((date) => date.label).join(', ')
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
    return (unit.rows || []).map((row) => [
      row.dia || '-',
      row.revision || '-',
      row.cond1 || '-',
      row.cond1Obs || '-',
      row.cond2 || '-',
      row.cond2Obs || '-'
    ]);
  }

  function driverSummaryBody(summary) {
    const rows = [];
    summary.forEach((item) => {
      rows.push([
        item.conductor || '-',
        Number(item.trips || 0).toLocaleString('es-PE'),
        `${Number(item.busesTotal || 0).toLocaleString('es-PE')} bus(es)`,
        Number(item.pending || 0).toLocaleString('es-PE'),
        Number(item.paid || 0).toLocaleString('es-PE'),
        Number(item.observations || 0).toLocaleString('es-PE')
      ]);
      (item.busesDetail || []).forEach((bus) => {
        rows.push([
          '',
          '',
          {
            content: `${bus.bus} - ${Number(bus.trips || 0).toLocaleString('es-PE')} viaje${Number(bus.trips || 0) === 1 ? '' : 's'} | Fechas: ${bus.datesText || '-'}`,
            colSpan: 4,
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
      head: [['Conductor', 'Viajes', 'Buses', 'Pend.', 'Pag.', 'Obs.']],
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
        0: { cellWidth: 48 },
        1: { cellWidth: 16, halign: 'center' },
        2: { cellWidth: width - 118 },
        3: { cellWidth: 18, halign: 'center' },
        4: { cellWidth: 18, halign: 'center' },
        5: { cellWidth: 18, halign: 'center' }
      },
      didParseCell: function (data) {
        if (data.section !== 'body') return;
        if (data.column.index === 3) {
          data.cell.styles.fontStyle = 'bold';
          data.cell.styles.textColor = [146, 86, 0];
        }
        if (data.column.index === 4) {
          data.cell.styles.fontStyle = 'bold';
          data.cell.styles.textColor = [5, 112, 68];
        }
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
        description: 'Estado de trabajo y pago por conductor segun el consolidado de salidas.',
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
                0: { cellWidth: 11, halign: 'center' },
                1: { cellWidth: 21, halign: 'center' },
                2: { cellWidth: 55 },
                3: { cellWidth: 24 },
                4: { cellWidth: 55 },
                5: { cellWidth: 24 }
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
  setupSearch();
  setupPdfButtons();
  setupDriverSummaryModal();
})();
