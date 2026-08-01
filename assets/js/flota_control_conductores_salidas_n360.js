(function () {
  const cfg = window.N360_FCC || {};
  const endpoint = cfg.endpoint || 'control_conductores_salidas.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};

  const clean = (value) => String(value || '').replace(/[ \t]+/g, ' ').replace(/\n\s+/g, '\n').trim();
  const compact = (value) => clean(value).replace(/\s+/g, ' ');
  const stamp = () => new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');

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
    const rows = Array.from(card.querySelectorAll('tbody tr')).map((row) => ({
      dia: cellText(row, '[data-fcc-col="dia"]'),
      revision: cellText(row, '[data-fcc-col="revision"]'),
      cond1: cellText(row, '[data-fcc-col="cond1"]'),
      cond1Estado: cellText(row, '[data-fcc-field="cond1_estado"]'),
      cond1Obs: cellText(row, '[data-fcc-field="cond1_observacion"]'),
      cond2: cellText(row, '[data-fcc-col="cond2"]'),
      cond2Estado: cellText(row, '[data-fcc-field="cond2_estado"]'),
      cond2Obs: cellText(row, '[data-fcc-field="cond2_observacion"]')
    }));
    return { title, rows };
  }

  function visibleUnits() {
    return Array.from(document.querySelectorAll('[data-fcc-unit]'))
      .filter((card) => !card.classList.contains('is-hidden'))
      .map(collectUnitFromCard);
  }

  function drawInfo(doc, left, y, width, unitsCount) {
    doc.setDrawColor(210, 224, 238);
    doc.setFillColor(247, 250, 253);
    doc.roundedRect(left, y, width, 18, 2, 2, 'FD');
    doc.setTextColor(15, 42, 64);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8.5);
    doc.text('Resumen del reporte', left + 5, y + 7);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7.2);
    doc.text(`Mes operativo: ${cfg.monthLabel || cfg.month || ''}`, left + 5, y + 12.5);
    doc.text(`Unidades visibles: ${unitsCount}`, left + width - 5, y + 12.5, { align: 'right' });
  }

  function tableBody(unit) {
    return (unit.rows || []).map((row) => [
      row.dia || '-',
      row.revision || '-',
      row.cond1 || '-',
      row.cond1Estado || '-',
      row.cond1Obs || '-',
      row.cond2 || '-',
      row.cond2Estado || '-',
      row.cond2Obs || '-'
    ]);
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
          const pageH = doc.internal.pageSize.getHeight();
          const width = pageW - left - right;
          let y = 34;

          drawInfo(doc, left, y, width, units.length);
          y += 25;

          units.forEach((unit, index) => {
            if (index > 0 && y > pageH - 72) {
              doc.addPage();
              y = 34;
            }

            doc.setTextColor(15, 42, 64);
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10);
            doc.text(unit.title || 'Unidad', left, y);
            y += 4;

            doc.autoTable({
              head: [['Dia', 'Trabajo', 'Cond. 1', 'Estado 1', 'Obs. 1', 'Cond. 2', 'Estado 2', 'Obs. 2']],
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
                1: { cellWidth: 20, halign: 'center' },
                2: { cellWidth: 32 },
                3: { cellWidth: 15, halign: 'center' },
                4: { cellWidth: 25 },
                5: { cellWidth: 32 },
                6: { cellWidth: 15, halign: 'center' },
                7: { cellWidth: 24 }
              },
              didParseCell: function (data) {
                if (data.section !== 'body') return;
                const raw = String(data.cell.raw || '').toUpperCase();
                if (data.column.index === 3 || data.column.index === 6) {
                  data.cell.styles.fontStyle = 'bold';
                  if (raw.includes('PAGADO')) data.cell.styles.textColor = [5, 112, 68];
                  if (raw.includes('PENDIENTE')) data.cell.styles.textColor = [146, 86, 0];
                }
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
})();