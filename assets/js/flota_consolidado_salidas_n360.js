(function () {
  const cfg = window.N360_CSB || {};
  const endpoint = cfg.endpoint || 'consolidado_salidas_buses.php';
  const csrf = cfg.csrf || '';
  const report = cfg.report || {};
  const rows = Array.from(document.querySelectorAll('[data-csb-row]'));
  const visiblePill = document.querySelector('[data-csb-visible-pill]');

  const clean = (value) => String(value || '').replace(/[ \t]+/g, ' ').replace(/\n\s+/g, '\n').trim();
  const compact = (value) => clean(value).replace(/\s+/g, ' ');
  const moneyDate = () => new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');

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

  async function saveRow(button) {
    const row = button.closest('[data-csb-row]');
    if (!row) return;

    const id = button.dataset.csbSave || row.dataset.csbRow || '';
    const estado = row.querySelector('[data-csb-field="estado"]')?.value || 'PENDIENTE';
    const comentario = row.querySelector('[data-csb-field="comentario"]')?.value || '';
    const correccion = row.querySelector('[data-csb-field="correccion"]')?.value || '';
    const originalHtml = button.innerHTML;

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'update_revision');
    fd.append('id', id);
    fd.append('estado', estado);
    fd.append('comentario', comentario);
    fd.append('correccion', correccion);

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

      const status = row.querySelector('[data-csb-status]');
      if (status) {
        status.textContent = json.data?.estado || estado;
        status.className = `csb-status ${json.data?.clase || 'csb-status--pending'}`;
      }

      const saved = row.querySelector('[data-csb-saved]');
      if (saved) {
        saved.textContent = json.data?.actualizado || '';
      }
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

  function cellText(td) {
    const drivers = td.querySelector('.csb-drivers');
    if (drivers) {
      return Array.from(drivers.querySelectorAll('span')).map((span) => compact(span.textContent)).filter(Boolean).join('\n');
    }
    const select = td.querySelector('select');
    if (select) {
      return select.value || compact(td.textContent);
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
              0: { cellWidth: 18, halign: 'center' },
              1: { cellWidth: 34 },
              2: { cellWidth: 82 },
              3: { cellWidth: 62 },
              4: { cellWidth: 28, halign: 'center' },
              5: { cellWidth: 62 }
            },
            didParseCell: function (data) {
              if (data.section !== 'body') return;
              if (data.column.index === 4) {
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
  document.querySelector('[data-csb-export-pdf]')?.addEventListener('click', exportPdf);
  setupGroupFilter();
  setupCalendar();
})();
