(function () {
  'use strict';
  const cfg = window.N360_CONDT_FECHAS;
  if (!cfg?.enabled) return;
  const escape = condtEscapeHtml;
  const empty = () => ({ trabajo_desde: null, trabajo_hasta: null, descansos: [], dias_descanso: 0, version: '0' });
  const date = value => /^\d{4}-\d{2}-\d{2}$/.test(value || '') ? value.split('-').reverse().join('/') : '-';
  const range = (from, to) => !from || !to ? 'Sin fechas' : from === to ? date(from) : `${date(from)} al ${date(to)}`;
  const rest = data => (data.descansos || []).map(p => range(p.desde, p.hasta));
  const current = id => CONDT_STATE.panel?.fechas?.[id] || empty();
  const snapshot = data => ({ trabajo_desde: data.trabajo_desde || null, trabajo_hasta: data.trabajo_hasta || null,
    descansos: (data.descansos || []).map(p => ({ desde: p.desde, hasta: p.hasta })) });
  let state = null, busy = false, allowClose = false, confirming = false;

  function cell(id) {
    const data = current(id), ready = CONDT_STATE.panel?.fechas_disponibles === true;
    const periods = rest(data);
    return `<div class="condt-fechas" data-condt-fechas="${Number(id)}">
      <div><span class="condt-fechas-label">Trabajo autorizado</span><span>${escape(ready ? range(data.trabajo_desde, data.trabajo_hasta) : 'No disponible')}</span></div>
      <div><span class="condt-fechas-label">Descanso permitido</span><div class="condt-fechas-periods">${(ready && periods.length ? periods : [ready ? 'Sin fechas' : 'No disponible']).map(p => `<span>${escape(p)}</span>`).join('')}</div>${ready && periods.length ? `<small>${Number(data.dias_descanso)} dia(s)</small>` : ''}</div>
      <button type="button" class="condt-fechas-icon" data-condt-fechas-open="${Number(id)}" ${ready ? '' : 'disabled'} title="${ready ? 'Editar trabajo y descanso' : 'Pendiente de instalar la query de fechas'}" aria-label="Editar trabajo y descanso"><i class="bi bi-calendar3" aria-hidden="true"></i></button>
    </div>`;
  }

  function sync(panel) {
    ['btnExportarPdfFechas', 'btnCalendarioConductores'].forEach(id => {
      const button = document.getElementById(id);
      if (!button) return;
      button.disabled = panel?.fechas_disponibles !== true;
      button.title = button.disabled ? 'Pendiente de instalar la query de fechas' : button.textContent.trim();
    });
  }

  function history(row) {
    if (row.tipo_evento !== 'FECHAS_TRABAJO_DESCANSO') return '';
    return `<div class="condt-fechas-history">${[['Antes', row.fechas_antes], ['Despues', row.fechas_despues]].map(([label, data]) => {
      if (!data) return `<div><strong>${label}</strong><p>Sin datos</p></div>`;
      return `<div><strong>${label}</strong><p>Trabajo: ${escape(range(data.trabajo_desde, data.trabajo_hasta))}</p><p>Descanso: ${escape(rest(data).join('; ') || 'Sin fechas')}</p></div>`;
    }).join('')}</div>`;
  }

  window.CondtFechas = { cell, sync, history };
  const modalEl = document.createElement('div');
  modalEl.id = 'condtFechasModal';
  modalEl.className = 'modal fade condt-fechas-modal';
  modalEl.tabIndex = -1;
  modalEl.setAttribute('aria-hidden', 'true');
  modalEl.setAttribute('aria-labelledby', 'condtFechasTitle');
  modalEl.innerHTML = `<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" data-condt-fechas-form novalidate>
    <div class="modal-header"><div><h5 class="modal-title" id="condtFechasTitle"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Trabajo y descanso</h5><div data-condt-fechas-name></div></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body"><div class="alert alert-danger" data-condt-fechas-error role="alert" hidden></div>
      <fieldset class="condt-fechas-work"><legend>Trabajo autorizado</legend><div class="condt-fechas-work-fields">
        <label>Desde<input class="form-control" type="date" min="1000-01-01" max="9999-12-31" data-condt-work-from></label>
        <label>Hasta<input class="form-control" type="date" min="1000-01-01" max="9999-12-31" data-condt-work-to></label></div></fieldset>
      <div class="condt-fechas-rest-head"><h6>Descanso permitido</h6><button type="button" class="condt-btn condt-btn-light condt-btn-xs" data-condt-rest-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Agregar descanso</button></div>
      <div data-condt-rest-list></div><div class="condt-fechas-none" data-condt-rest-empty>Sin descansos registrados</div>
    </div><div class="modal-footer"><button type="button" class="condt-btn condt-btn-light" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="condt-btn condt-btn-primary" data-condt-fechas-save disabled><i class="bi bi-check2 me-1" aria-hidden="true"></i>Guardar fechas</button></div>
  </form></div>`;
  document.body.append(modalEl);
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const form = modalEl.querySelector('form');
  const list = modalEl.querySelector('[data-condt-rest-list]');
  const from = modalEl.querySelector('[data-condt-work-from]');
  const to = modalEl.querySelector('[data-condt-work-to]');
  const save = modalEl.querySelector('[data-condt-fechas-save]');
  const error = modalEl.querySelector('[data-condt-fechas-error]');

  function values() {
    return { trabajo_desde: from.value || null, trabajo_hasta: to.value || null,
      descansos: Array.from(list.children).map(row => ({ desde: row.querySelector('[data-rest-from]').value,
        hasta: row.dataset.mode === 'dia' ? row.querySelector('[data-rest-from]').value : row.querySelector('[data-rest-to]').value })) };
  }
  function changed() { return !!state && JSON.stringify(values()) !== state.baseline; }
  function refresh() {
    save.disabled = busy || !changed();
    modalEl.querySelector('[data-condt-rest-empty]').hidden = list.children.length > 0;
    modalEl.querySelector('[data-condt-rest-add]').disabled = busy || list.children.length >= 100;
  }
  function addPeriod(period = { desde: '', hasta: '' }) {
    if (list.children.length >= 100) return;
    const row = document.createElement('div');
    row.className = 'condt-fechas-editor-row';
    row.innerHTML = `<div class="condt-fechas-modes" role="group" aria-label="Tipo de descanso"><button type="button" data-rest-mode="dia">Dia</button><button type="button" data-rest-mode="rango">Rango</button></div>
      <label><span data-rest-start-label>Fecha</span><input class="form-control" type="date" min="1000-01-01" max="9999-12-31" data-rest-from required></label>
      <label data-rest-end-label>Hasta<input class="form-control" type="date" min="1000-01-01" max="9999-12-31" data-rest-to required></label>
      <button type="button" class="condt-fechas-icon condt-fechas-remove" data-rest-remove title="Quitar descanso" aria-label="Quitar descanso"><i class="bi bi-trash" aria-hidden="true"></i></button>`;
    row.querySelector('[data-rest-from]').value = period.desde;
    row.querySelector('[data-rest-to]').value = period.hasta;
    list.append(row);
    setMode(row, period.desde === period.hasta ? 'dia' : 'rango');
    refresh();
  }
  function setMode(row, mode) {
    row.dataset.mode = mode;
    row.querySelectorAll('[data-rest-mode]').forEach(button => {
      const active = button.dataset.restMode === mode;
      button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active));
    });
    row.querySelector('[data-rest-start-label]').textContent = mode === 'dia' ? 'Fecha' : 'Desde';
    row.querySelector('[data-rest-end-label]').hidden = mode === 'dia';
    row.querySelector('[data-rest-to]').disabled = mode === 'dia';
    row.querySelector('[data-rest-to]').min = row.querySelector('[data-rest-from]').value || '1000-01-01';
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-condt-fechas-open]');
    if (!button || button.disabled || busy) return;
    const id = Number(button.dataset.condtFechasOpen);
    const panel = CONDT_STATE.panel;
    const person = [...panel.flotas, ...panel.retenes].find(p => Number(p.clm_progconductores_idconductor) === id);
    if (!person) return;
    const data = current(id);
    state = { id, version: data.version, baseline: JSON.stringify(snapshot(data)) };
    allowClose = false;
    from.value = data.trabajo_desde || ''; to.value = data.trabajo_hasta || '';
    list.replaceChildren();
    data.descansos.forEach(addPeriod);
    modalEl.querySelector('[data-condt-fechas-name]').textContent = `${person.conductor} (${person.dni || '-'})`;
    error.hidden = true; refresh(); modal.show();
  });
  modalEl.querySelector('[data-condt-rest-add]').addEventListener('click', () => { if (!busy) addPeriod(); });
  list.addEventListener('click', event => {
    if (busy) return;
    const mode = event.target.closest('[data-rest-mode]');
    if (mode) setMode(mode.closest('.condt-fechas-editor-row'), mode.dataset.restMode);
    event.target.closest('[data-rest-remove]')?.closest('.condt-fechas-editor-row').remove();
    refresh();
  });
  form.addEventListener('input', event => {
    if (event.target.matches('[data-rest-from]')) setMode(event.target.closest('.condt-fechas-editor-row'), event.target.closest('.condt-fechas-editor-row').dataset.mode);
    error.hidden = true; refresh();
  });
  form.addEventListener('change', refresh);
  modalEl.addEventListener('show.bs.modal', () => document.body.classList.add('condt-fechas-open'));
  modalEl.addEventListener('hide.bs.modal', event => {
    if (busy || confirming) { event.preventDefault(); return; }
    if (!allowClose && changed()) {
      event.preventDefault();
      confirming = true;
      const confirmation = condtConfirm('Descartar los cambios pendientes de fechas?', { title: 'Cambios sin guardar', confirmText: 'Descartar', cancelText: 'Seguir editando' });
      // Keep the shared confirmation within Bootstrap's focus boundary.
      const dialog = document.querySelector('.n360-dialog');
      if (dialog) { modalEl.append(dialog); form.inert = true; }
      confirmation.then(confirmed => {
        if (dialog) document.body.append(dialog);
        form.inert = false; confirming = false;
        if (confirmed) { allowClose = true; modal.hide(); } else { save.focus(); }
      });
    }
  });
  modalEl.addEventListener('hidden.bs.modal', () => { state = null; document.body.classList.remove('condt-fechas-open'); });
  window.addEventListener('beforeunload', event => { if (busy || changed()) { event.preventDefault(); event.returnValue = ''; } });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || confirming || !state || !changed() || !form.reportValidity()) return;
    const data = values();
    if (!!data.trabajo_desde !== !!data.trabajo_hasta || (data.trabajo_desde && data.trabajo_desde > data.trabajo_hasta)) {
      error.textContent = 'Completa el inicio y fin del trabajo en orden cronologico.'; error.hidden = false; return;
    }
    busy = true;
    const controls = Array.from(form.querySelectorAll('input,button'));
    const disabled = controls.map(control => control.disabled);
    controls.forEach(control => { control.disabled = true; });
    const html = save.innerHTML;
    save.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Guardando';
    let saved = false;
    try {
      const result = await condtPost({ ajax_action: 'guardar_fechas', id_conductor: state.id, csrf: cfg.csrf,
        version: state.version, fechas: JSON.stringify(data) }, false);
      CONDT_STATE.panel.fechas[state.id] = result.data;
      condtRenderFlotas(CONDT_STATE.panel.flotas);
      condtRenderRetenes(CONDT_STATE.panel.retenes);
      state.baseline = JSON.stringify(snapshot(result.data));
      saved = true;
    } catch (err) {
      error.textContent = err.message || 'No se pudieron guardar las fechas.'; error.hidden = false;
    } finally {
      controls.forEach((control, index) => { control.disabled = disabled[index]; });
      busy = false; save.innerHTML = html; refresh();
      if (saved) { allowClose = true; modal.hide(); }
    }
  });

  document.getElementById('btnExportarPdfFechas')?.addEventListener('click', () => {
    const panel = CONDT_STATE.panel;
    if (panel?.fechas_disponibles !== true) return;
    const search = (document.getElementById('txtBuscarUnidadPanel').value || '').trim().toLowerCase();
    const groups = condtGroupFlotas(panel.flotas).filter(group => condtMatchesUnitSearch(group, search));
    const reserves = panel.retenes || [];
    if (!groups.length && !reserves.length) { condtAlert('No hay unidades ni conductores para exportar.', 'warning', 'Sin datos'); return; }
    try {
      const doc = new jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      const personRow = person => {
        if (!person.clm_progconductores_idconductor) return { conductor: 'Sin conductor asignado', trabajo: '-', descanso: '-', sin_conductor: true };
        const data = current(person.clm_progconductores_idconductor);
        const phone = String(person.celular || '').trim();
        return { conductor: `${person.conductor || '-'}\nLic.: ${person.licencia || '-'}${phone ? ` | Tel.: (${phone})` : ''}`,
          trabajo: range(data.trabajo_desde, data.trabajo_hasta),
          descanso: rest(data).join('\n') + (data.dias_descanso ? `\n${data.dias_descanso} dia(s)` : '') || 'Sin fechas' };
      };
      const dateColumns = [
        { header: 'CONDUCTOR', dataKey: 'conductor' },
        { header: 'TRABAJO AUTORIZADO', dataKey: 'trabajo' }, { header: 'DESCANSO PERMITIDO', dataKey: 'descanso' }
      ];
      const options = {
        theme: 'grid', margin: { top: 34, bottom: 23, left: 15, right: 15 }, tableWidth: 180,
        styles: { font: 'times', fontSize: 8, cellPadding: 2, textColor: [0, 0, 0], lineWidth: .25, lineColor: [0, 0, 0], overflow: 'linebreak', valign: 'middle' },
        headStyles: { fillColor: [255, 242, 0], textColor: [0, 0, 0], fontStyle: 'bold', halign: 'center', minCellHeight: 9 },
        columnStyles: { bus: { cellWidth: 15 }, placa: { cellWidth: 23 },
          conductor: { cellWidth: 72, fontStyle: 'bold' }, trabajo: { cellWidth: 33 }, descanso: { cellWidth: 37 } },
        showHead: 'everyPage', rowPageBreak: 'avoid'
      };
      doc.setFont('times', 'normal'); doc.setFontSize(8); doc.setTextColor(75, 85, 99);
      doc.text(`Generado: ${condtFormatDateTimeHuman()}`, 105, 31, { align: 'center' });
      doc.text(`Unidades: ${groups.length} | Conductores asignados: ${groups.reduce((count, group) => count + group.slots.filter(slot => slot.clm_progconductores_idconductor).length, 0)} | Retenes disponibles: ${reserves.length}`, 105, 35, { align: 'center' });
      let y = 39;
      const filters = ['txtBuscarUnidadPanel', 'txtBuscarRetenPanel'].map(id => document.getElementById(id).value.trim());
      if (filters.some(Boolean)) {
        const brief = value => value.length > 80 ? value.slice(0, 77) + '...' : value;
        const lines = doc.splitTextToSize(`Unidad: ${brief(filters[0]) || 'Todas'} | Retenes: ${brief(filters[1]) || 'Todos'}`, 180);
        doc.text(lines, 105, y, { align: 'center' }); y += lines.length * 3.5 + 2;
      }
      if (groups.length) {
        const segments = new Map();
        doc.autoTable({ ...options, startY: y,
          columns: [{ header: 'BUS', dataKey: 'bus' }, { header: 'PLACA', dataKey: 'placa' }, ...dateColumns],
          body: groups.flatMap((group, index) => group.slots.map(person => ({ bus: '', placa: '', ...personRow(person), group: index }))),
          didParseCell: cell => {
            if (cell.section === 'body') cell.cell.styles.fillColor = cell.row.raw.group % 2 ? [248, 250, 252] : [255, 255, 255];
            if (cell.section === 'body' && cell.row.raw.sin_conductor) {
              cell.cell.styles.textColor = [100, 116, 139]; cell.cell.styles.fontStyle = 'italic';
            }
          },
          didDrawCell: cell => {
            if (cell.section !== 'body' || cell.column.dataKey !== 'bus') return;
            const key = cell.row.raw.group, top = cell.cell.y, bottom = top + cell.cell.height;
            const segment = segments.get(key);
            if (segment) { segment.top = Math.min(segment.top, top); segment.bottom = Math.max(segment.bottom, bottom); }
            else segments.set(key, { top, bottom });
          },
          didDrawPage: () => {
            // Merge only the visible part of each unit, including continuations on later pages.
            segments.forEach(({ top, bottom }, index) => {
              const group = groups[index], height = bottom - top;
              const fillColor = index % 2 ? [248, 250, 252] : [255, 255, 255];
              const unitCell = (x, width, value, fontSize, textColor) => {
                value = String(value || '-');
                doc.setFont('times', 'bolditalic');
                while (fontSize > 6) {
                  doc.setFontSize(fontSize);
                  if (value.split(/\s+/).every(word => doc.getTextWidth(word) <= width - 2)
                    && doc.splitTextToSize(value, width - 2).length * (fontSize * .35 + 2) <= height - 2) break;
                  fontSize--;
                }
                condtDrawCell(doc, x, top, width, height, value, { fillColor, textColor, align: 'center', fontStyle: 'bolditalic', fontSize, paddingX: 1, paddingY: 1, lineWidth: .25 });
              };
              unitCell(15, 15, group.bus, 18, [255, 0, 0]);
              unitCell(30, 23, group.placa, 14, [0, 32, 96]);
              doc.setDrawColor(0, 0, 0); doc.setLineWidth(.45); doc.line(15, bottom, 195, bottom);
            });
            segments.clear();
          }
        });
        y = doc.lastAutoTable.finalY + 8;
      }
      if (reserves.length) {
        doc.autoTable({ ...options, startY: y,
          columns: [{ header: 'RETENES', dataKey: 'reten' }, ...dateColumns],
          body: reserves.map(person => ({ reten: '', ...personRow(person) })),
          headStyles: { ...options.headStyles, fillColor: [44, 62, 80], textColor: [255, 255, 255] },
          columnStyles: { ...options.columnStyles, reten: { cellWidth: 38 } },
          alternateRowStyles: { fillColor: [248, 250, 252] }
        });
      }
      const total = doc.internal.getNumberOfPages();
      for (let page = 1; page <= total; page++) {
        doc.setPage(page);
        condtDrawHeaderFooter(doc, page, total, 'Trabajo y descanso de conductores', 'PRG_RPT_TRABAJO_DESCANSO');
      }
      doc.save(`Trabajo_Descanso_Conductores_${condtFormatDateTimeForFile()}.pdf`);
    } catch (err) { condtAlert('No se pudo generar el PDF de trabajo y descanso.', 'danger', 'Error de PDF'); }
  });
  if (CONDT_STATE.panel) {
    sync(CONDT_STATE.panel); condtRenderFlotas(CONDT_STATE.panel.flotas); condtRenderRetenes(CONDT_STATE.panel.retenes);
  }
})();
