(function (window, document) {
  'use strict';

  const CFG = window.N360_CHECK_REPORT || {};
  const $ = id => document.getElementById(id);
  const API = CFG.apiUrl || 'api_checklist_reportes.php';
  let unitState = null;
  let fleetState = null;
  let fleetSource = null;
  let selectedUnit = null;
  let searchTimer = null;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    })[ch]);
  }

  function text(value, fallback) {
    const out = String(value ?? '').trim();
    return out || fallback || 'No registrado';
  }

  function chip(label, type) {
    const cls = type ? ` check-report-chip--${type}` : '';
    return `<span class="check-report-chip${cls}">${esc(label)}</span>`;
  }

  function apiUrl(action, params) {
    const url = new URL(API, window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value) !== '') {
        url.searchParams.set(key, value);
      }
    });
    return url.toString();
  }

  function checklistViewHref(id) {
    const url = new URL(CFG.checklistViewUrl || 'ver_checklist.php', window.location.href);
    url.searchParams.set('id', id);
    return url.toString();
  }

  async function fetchJson(action, params) {
    const res = await fetch(apiUrl(action, params), {headers: {'Accept': 'application/json'}});
    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok === false) {
      throw new Error((json && json.message) || 'No se pudo cargar la informacion.');
    }
    return json.data || {};
  }

  async function during(fn, options) {
    if (window.N360Loader && typeof window.N360Loader.during === 'function') {
      return window.N360Loader.during(fn, options || {});
    }
    return fn();
  }

  function fileSlug(value) {
    return String(value || 'reporte')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'reporte';
  }

  function drawText(doc, value, x, y, opts) {
    doc.text(String(value || ''), x, y, opts || {});
  }

  function statusRgb(status) {
    if (status === 'ok') return [23, 100, 58];
    if (status === 'bad') return [163, 59, 43];
    if (status === 'warn') return [138, 90, 0];
    return [16, 42, 67];
  }

  function tableCell(value, status) {
    return {text: value, status: status || ''};
  }

  function cellText(cell) {
    return cell && typeof cell === 'object' && !Array.isArray(cell) ? (cell.text ?? cell.value ?? '') : cell;
  }

  function cellStatus(cell) {
    return cell && typeof cell === 'object' && !Array.isArray(cell) ? (cell.status || '') : '';
  }

  function wrap(doc, value, width) {
    return doc.splitTextToSize(String(value || ''), width);
  }

  function pageBottom(doc) {
    return doc.internal.pageSize.getHeight() - 24;
  }

  function ensurePage(doc, y, needed, orientation) {
    if (y + needed <= pageBottom(doc)) return y;
    doc.addPage('a4', orientation || 'portrait');
    return 34;
  }

  function sectionTitle(doc, title, y, orientation) {
    y = ensurePage(doc, y, 12, orientation);
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(238, 247, 255);
    doc.setDrawColor(199, 225, 244);
    doc.roundedRect(12.7, y, W - 25.4, 9, 1.5, 1.5, 'FD');
    doc.setTextColor(18, 42, 64);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    drawText(doc, title, 16, y + 6);
    return y + 14;
  }

  function infoLine(doc, label, value, x, y, w, status) {
    doc.setTextColor(95, 114, 135);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7);
    drawText(doc, label.toUpperCase(), x, y);
    doc.setTextColor(...statusRgb(status));
    doc.setFont('helvetica', status ? 'bold' : 'normal');
    doc.setFontSize(8.2);
    doc.text(wrap(doc, text(value), w), x, y + 4.5);
  }

  function drawInfoGrid(doc, items, y, orientation) {
    y = ensurePage(doc, y, 24, orientation);
    const W = doc.internal.pageSize.getWidth();
    const left = 12.7;
    const gap = 3;
    const cols = Math.min(items.length, orientation === 'landscape' ? 4 : 3);
    const colW = (W - 25.4 - gap * (cols - 1)) / cols;
    const rowH = 18;
    items.forEach((item, idx) => {
      const col = idx % cols;
      const row = Math.floor(idx / cols);
      const x = left + col * (colW + gap);
      const cy = y + row * (rowH + 3);
      doc.setDrawColor(214, 226, 238);
      doc.line(x, cy + rowH, x + colW, cy + rowH);
      infoLine(doc, item.label, item.value, x, cy + 5, colW - 2, item.status || '');
    });
    return y + Math.ceil(items.length / cols) * (rowH + 3) + 2;
  }

  function drawTable(doc, headers, rows, widths, y, options) {
    const cfg = options || {};
    const orientation = cfg.orientation || 'portrait';
    const left = cfg.left || 12.7;
    const headerH = 8;
    const minRowH = cfg.rowH || 8;
    const fontSize = cfg.fontSize || 6.8;

    y = ensurePage(doc, y, headerH + minRowH, orientation);

    function drawHeader() {
      let x = left;
      doc.setFillColor(18, 42, 64);
      doc.rect(left, y, widths.reduce((a, b) => a + b, 0), headerH, 'F');
      doc.setTextColor(255, 255, 255);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(fontSize);
      headers.forEach((h, i) => {
        drawText(doc, h, x + 1.5, y + 5.3);
        x += widths[i];
      });
      y += headerH;
    }

    drawHeader();
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(fontSize);

    rows.forEach((row, index) => {
      const parsed = row.map(cell => ({
        text: cellText(cell),
        status: cellStatus(cell)
      }));
      const cells = parsed.map((cell, i) => wrap(doc, cell.text, widths[i] - 3));
      const maxLines = Math.max(...cells.map(lines => Math.min(lines.length, cfg.maxLines || 3)), 1);
      const rowH = Math.max(minRowH, maxLines * (fontSize * 0.42) + 4);
      y = ensurePage(doc, y, rowH, orientation);
      if (y === 34) drawHeader();

      doc.setFillColor(index % 2 ? 255 : 249, index % 2 ? 255 : 251, index % 2 ? 255 : 253);
      doc.rect(left, y, widths.reduce((a, b) => a + b, 0), rowH, 'F');
      doc.setDrawColor(226, 232, 240);
      doc.line(left, y + rowH, left + widths.reduce((a, b) => a + b, 0), y + rowH);
      let x = left;
      cells.forEach((lines, i) => {
        doc.setTextColor(...statusRgb(parsed[i].status));
        doc.setFont('helvetica', parsed[i].status ? 'bold' : 'normal');
        doc.text(lines.slice(0, cfg.maxLines || 3), x + 1.5, y + 4.5);
        x += widths[i];
      });
      doc.setFont('helvetica', 'normal');
      y += rowH;
    });

    return y + 4;
  }

  function basePdfConfig(extra) {
    return Object.assign({
      userName: CFG.userName || 'Usuario',
      dni: CFG.dni || 'No registrado',
      logoLeft: CFG.logoLeft,
      logoRight: CFG.logoRight,
      coverImage: CFG.coverImage,
      cover: false,
      useCover: false
    }, extra || {});
  }

  function kpiText(kpi) {
    if (!kpi) return 'Sin KPI';
    return `${kpi.titulo || 'KPI'}: ${kpi.valor || '-'} | ${kpi.texto || '-'}`;
  }

  function kpiStatus(kpi) {
    return kpi?.estado || '';
  }

  function kpiCell(kpi) {
    return tableCell(kpiText(kpi), kpiStatus(kpi));
  }

  function kpiChip(kpi) {
    if (!kpi) return chip('Sin KPI', 'warn');
    const type = kpi.estado === 'ok' ? 'ok' : (kpi.estado === 'bad' ? 'bad' : 'warn');
    return chip(`${kpi.valor || '-'} | ${kpi.texto || '-'}`, type);
  }

  async function generateChecklistPdf(detail) {
    if (!window.N360PDF) throw new Error('N360PDF no esta cargado.');
    const chk = detail.checklist || {};
    const doc = await window.N360PDF.createDocument(basePdfConfig({
      orientation: 'portrait',
      title: 'REPORTE DE CHECKLIST',
      secondTitle: text(chk.tipo, 'Checklist'),
      docCode: 'N360-CAL-CHK',
      coverTitle: 'REPORTE DE CHECKLIST',
      coverMain: `${text(chk.bus, 'Unidad')} (${text(chk.placa, 'Sin placa')})`,
      coverSecond: `${text(chk.tipo, 'Checklist')} | ${text(chk.corr, 'Sin correlativo')}`,
      description: 'Reporte unitario generado desde Norte 360 con el formato estandar A4.',
      content(doc, cfg) {
        let y = 36;
        y = drawInfoGrid(doc, [
          {label: 'Unidad', value: chk.bus},
          {label: 'Placa', value: chk.placa},
          {label: 'Servicio', value: chk.servicio},
          {label: 'Checklist', value: chk.corr},
          {label: 'Tipo', value: chk.tipo},
          {label: 'Fecha', value: `${text(chk.fecha)} ${text(chk.hora, '')}`},
          {label: 'Responsable', value: chk.responsable},
          {label: 'Resultado', value: `${chk.completion?.respondidos || 0}/${chk.completion?.total || 0} - ${chk.completion?.estado || '-'}`}
        ], y, 'portrait');

        y = sectionTitle(doc, 'KPI del checklist', y + 2, 'portrait');
        y = drawInfoGrid(doc, [
          {label: 'Indicador', value: chk.kpi?.titulo || 'Sin KPI'},
          {label: 'Valor', value: chk.kpi?.valor || '-', status: kpiStatus(chk.kpi)},
          {label: 'Lectura', value: chk.kpi?.texto || '-', status: kpiStatus(chk.kpi)}
        ], y, 'portrait');

        if (Array.isArray(chk.kpi?.detalle) && chk.kpi.detalle.length) {
          const kpiRows = chk.kpi.detalle.map(item => [item.label || '-', String(item.value ?? '-'), tableCell(item.estado || item.status || '-', item.status || kpiStatus(chk.kpi))]);
          y = drawTable(doc, ['Metrica', 'Valor', 'Estado'], kpiRows, [72, 50, 60], y, {
            orientation: 'portrait',
            fontSize: 7,
            maxLines: 3
          });
        }

        y = sectionTitle(doc, 'Detalle de respuestas', y + 2, 'portrait');
        (detail.categorias || []).forEach(cat => {
          y = sectionTitle(doc, cat.nombre || 'Categoria', y, 'portrait');
          const rows = (cat.items || []).map((item, idx) => [
            String(idx + 1),
            item.item || '',
            item.valor || (item.respondido ? 'Registrado' : 'Sin respuesta'),
            item.kpi ? tableCell(`${item.kpi.label}: ${item.kpi.value}`, item.kpi.status) : '-',
            item.observacion || '-',
            item.usuario || '-'
          ]);
          y = drawTable(doc, ['#', 'Item', 'Respuesta', 'KPI item', 'Observacion', 'Usuario'], rows, [8, 50, 30, 34, 36, 24], y, {
            orientation: 'portrait',
            fontSize: 6.2,
            maxLines: 4
          });
        });
      }
    }));

    doc.save(`checklist_${fileSlug(chk.corr)}_${fileSlug(chk.bus)}.pdf`);
  }

  async function generateUnitPdf(report) {
    if (!window.N360PDF) throw new Error('N360PDF no esta cargado.');
    const bus = report.bus || {};
    const doc = await window.N360PDF.createDocument(basePdfConfig({
      orientation: 'portrait',
      title: 'REPORTE DE CHECKLIST POR UNIDAD',
      secondTitle: `${text(bus.bus, 'Unidad')} | ${text(bus.placa, 'Sin placa')}`,
      docCode: 'N360-CAL-UNI',
      coverTitle: 'REPORTE POR UNIDAD',
      coverMain: `${text(bus.bus, 'Unidad')} (${text(bus.placa, 'Sin placa')})`,
      coverSecond: `${text(report.filtros?.desde)} al ${text(report.filtros?.hasta)}`,
      description: 'Consolidado de checklists, ultima fumigacion, conductores y rutas vigentes.',
      content(doc, cfg) {
        let y = 34;
        y = drawInfoGrid(doc, [
          {label: 'Unidad', value: bus.bus},
          {label: 'Placa', value: bus.placa},
          {label: 'Servicio', value: bus.servicio},
          {label: 'Periodo', value: `${report.filtros?.desde} al ${report.filtros?.hasta}`},
          {label: 'Checklists', value: report.resumen?.checklists},
          {label: 'Completos', value: report.resumen?.completos},
          {label: 'Incompletos', value: report.resumen?.incompletos},
          {label: 'Fumigacion', value: report.ultima_fumigacion ? `${report.ultima_fumigacion.fecha_fumigacion} (${report.ultima_fumigacion.vigencia})` : 'Sin registro'}
        ], y, 'portrait');

        y = sectionTitle(doc, 'Programacion vigente', y + 2, 'portrait');
        const drivers = (report.conductores || []).map((d, i) => [`Conductor ${i + 1}`, d.conductor || '-', d.licencia || '-', d.dni || '-', d.fecha_asignacion || d.fecha_programacion || '-']);
        y = drawTable(doc, ['Slot', 'Conductor', 'Licencia', 'DNI', 'Asignacion'], drivers.length ? drivers : [['-', 'Sin conductor asignado', '-', '-', '-']], [20, 55, 28, 28, 50], y, {
          orientation: 'portrait',
          fontSize: 6.2,
          maxLines: 3
        });

        const routes = (report.rutas || []).map(r => [r.hora || '-', r.origen || '-', r.ruta_texto || '-', r.destino || '-', r.fecha_operativa || '-', r.fecha_actualizacion || r.fecha_programacion || '-']);
        y = drawTable(doc, ['Hora', 'Origen', 'Ruta', 'Destino', 'Dia op.', 'Actualizada'], routes.length ? routes : [['-', 'Sin rutas asignadas', '-', '-', '-', '-']], [17, 25, 48, 25, 26, 40], y, {
          orientation: 'portrait',
          fontSize: 5.9,
          maxLines: 3
        });

        y = sectionTitle(doc, 'Ultimos checklists por tipo', y, 'portrait');
        const latest = (report.ultimos_por_tipo || []).map(t => [
          t.tipo || `Tipo ${t.tipo_id}`,
          t.ultimo ? t.ultimo.corr : 'Sin registro',
          t.ultimo ? `${t.ultimo.fecha} ${t.ultimo.hora}` : '-',
          t.ultimo ? `${t.ultimo.completion.respondidos}/${t.ultimo.completion.total}` : '-',
          t.ultimo ? t.ultimo.completion.estado : '-',
          t.ultimo ? kpiCell(t.ultimo.kpi) : '-'
        ]);
        y = drawTable(doc, ['Tipo', 'Checklist', 'Fecha', 'Items', 'Estado', 'KPI'], latest, [34, 28, 28, 18, 22, 52], y, {
          orientation: 'portrait',
          fontSize: 5.9,
          maxLines: 3
        });

        y = sectionTitle(doc, 'Historial del periodo', y, 'portrait');
        const rows = (report.checklists || []).map(chk => [
          `${chk.fecha} ${chk.hora}`,
          `${chk.tipo}\n${chk.corr}`,
          `${chk.completion.respondidos}/${chk.completion.total}`,
          chk.completion.estado,
          kpiCell(chk.kpi),
          chk.responsable || '-'
        ]);
        y = drawTable(doc, ['Fecha', 'Checklist', 'Items', 'Estado', 'KPI', 'Responsable'], rows, [26, 38, 18, 22, 54, 24], y, {
          orientation: 'portrait',
          fontSize: 5.8,
          maxLines: 3
        });
      }
    }));

    doc.save(`consolidado_unidad_${fileSlug(bus.bus)}_${fileSlug(bus.placa)}.pdf`);
  }

  async function generateFleetPdf(report) {
    if (!window.N360PDF) throw new Error('N360PDF no esta cargado.');
    const doc = await window.N360PDF.createDocument(basePdfConfig({
      orientation: 'portrait',
      title: 'CONSOLIDADO DE CHECKLIST',
      secondTitle: `${text(report.filtros?.desde)} al ${text(report.filtros?.hasta)}`,
      docCode: 'N360-CAL-CONS',
      description: 'Resumen consolidado de calidad por checklist y metricas KPI.',
      content(doc, cfg) {
        let y = 34;
        y = drawInfoGrid(doc, [
          {label: 'Periodo', value: `${report.filtros?.desde} al ${report.filtros?.hasta}`},
          {label: 'Unidades', value: report.resumen?.unidades},
          {label: 'Checklists', value: report.resumen?.checklists},
          {label: 'Completos', value: report.resumen?.completos},
          {label: 'Incompletos', value: report.resumen?.incompletos}
        ], y, 'portrait');

        y = sectionTitle(doc, 'KPIs por checklist', y + 2, 'portrait');
        const rows = (report.checklists || []).map(chk => [
          `${chk.bus || '-'}\n${chk.placa || '-'}`,
          `${chk.fecha || '-'}\n${chk.hora || '-'}`,
          `${chk.tipo || '-'}\n${chk.corr || '-'}`,
          `${chk.completion?.respondidos || 0}/${chk.completion?.total || 0}\n${chk.completion?.estado || '-'}`,
          kpiCell(chk.kpi)
        ]);
        y = drawTable(doc, ['Unidad', 'Fecha', 'Checklist', 'Items', 'KPI'], rows, [32, 30, 42, 28, 50], y, {
          orientation: 'portrait',
          fontSize: 6.2,
          maxLines: 4
        });
      }
    }));

    doc.save(`consolidado_checklist_calidad_${fileSlug(report.filtros?.desde)}_${fileSlug(report.filtros?.hasta)}.pdf`);
  }

  function renderSummary(prefix, resumen) {
    const box = $(`${prefix}Summary`);
    if (!box) return;
    const data = resumen || {};
    box.innerHTML = `
      <div class="check-report-metric"><span>Checklists</span><strong>${esc(data.checklists || 0)}</strong></div>
      <div class="check-report-metric"><span>Completos</span><strong>${esc(data.completos || 0)}</strong></div>
      <div class="check-report-metric"><span>Incompletos</span><strong>${esc(data.incompletos || 0)}</strong></div>
      <div class="check-report-metric"><span>Unidades</span><strong>${esc(data.unidades || (unitState ? 1 : 0))}</strong></div>
    `;
  }

  function computeChecklistSummary(checklists) {
    const rows = checklists || [];
    const unidades = new Set(rows.map(chk => String(chk.id_bus || `${chk.bus || ''}|${chk.placa || ''}`)));
    const completos = rows.filter(chk => chk.completion?.estado === 'Completo').length;
    return {
      checklists: rows.length,
      completos,
      incompletos: Math.max(rows.length - completos, 0),
      unidades: unidades.size
    };
  }

  function populateFleetFilters(checklists) {
    const box = $('fleetLocalFilters');
    const tipo = $('fleetTipoFilter');
    if (!box || !tipo) return;
    const current = tipo.value;
    const tipos = Array.from(new Map((checklists || []).map(chk => [String(chk.tipo_id || chk.tipo || ''), chk.tipo || `Tipo ${chk.tipo_id}`])).entries())
      .filter(([id]) => id !== '')
      .sort((a, b) => a[1].localeCompare(b[1], 'es'));
    tipo.innerHTML = '<option value="">Todos</option>' + tipos.map(([id, label]) => `<option value="${esc(id)}">${esc(label)}</option>`).join('');
    tipo.value = tipos.some(([id]) => id === current) ? current : '';
    box.classList.remove('check-report-hidden');
  }

  function filteredFleetReport() {
    if (!fleetSource) return null;
    const q = ($('fleetLocalSearch')?.value || '').trim().toLowerCase();
    const tipo = $('fleetTipoFilter')?.value || '';
    const kpi = $('fleetKpiFilter')?.value || '';
    const rows = (fleetSource.checklists || []).filter(chk => {
      const haystack = [
        chk.bus,
        chk.placa,
        chk.tipo,
        chk.corr,
        chk.responsable,
        chk.kpi?.titulo,
        chk.kpi?.texto,
        chk.kpi?.valor
      ].join(' ').toLowerCase();
      if (q && !haystack.includes(q)) return false;
      if (tipo && String(chk.tipo_id || chk.tipo || '') !== tipo) return false;
      if (kpi && (chk.kpi?.estado || '') !== kpi) return false;
      return true;
    });
    return Object.assign({}, fleetSource, {
      checklists: rows,
      resumen: computeChecklistSummary(rows)
    });
  }

  function applyFleetFilters() {
    const report = filteredFleetReport();
    if (report) renderFleetReport(report);
  }

  function renderUnitReport(report) {
    unitState = report;
    const bus = report.bus || {};
    renderSummary('unit', report.resumen);

    $('unitAside').innerHTML = `
      <div class="check-report-bus">
        <div class="check-report-unit-head">
          <div class="check-report-unit-head__icon"><i class="bi bi-bus-front-fill"></i></div>
          <div>
            <span>Unidad auditada</span>
            <h3>${esc(text(bus.bus, 'Unidad'))}</h3>
          </div>
        </div>
        <div class="check-report-list">
          <div class="check-report-item"><span>Placa</span><strong>${esc(text(bus.placa))}</strong></div>
          <div class="check-report-item"><span>Servicio</span><strong>${esc(text(bus.servicio))}</strong></div>
          <div class="check-report-item"><span>Ultima fumigacion</span><strong>${report.ultima_fumigacion ? esc(`${report.ultima_fumigacion.fecha_fumigacion} - ${report.ultima_fumigacion.vigencia}`) : 'Sin registro'}</strong></div>
        </div>
      </div>
    `;

    const drivers = (report.conductores || []).map((d, idx) => `
      <article class="check-report-person-card">
        <div class="check-report-person-card__icon"><i class="bi bi-person-vcard-fill"></i></div>
        <div>
          <span>Conductor ${idx + 1}</span>
          <strong>${esc(d.conductor || 'Sin conductor asignado')}</strong>
          <small>Lic: ${esc(d.licencia || '-')} | DNI: ${esc(d.dni || '-')}</small>
          <small><i class="bi bi-calendar-check"></i> Asignado: ${esc(d.fecha_asignacion || d.fecha_programacion || 'Sin fecha')}</small>
        </div>
      </article>
    `).join('');

    const routes = (report.rutas || []).map(r => `
      <article class="check-report-route-card">
        <div class="check-report-route-card__time"><i class="bi bi-clock-fill"></i><strong>${esc(r.hora || '-')}</strong></div>
        <div>
          <span>${esc(r.origen || '-')} -> ${esc(r.destino || '-')}</span>
          <strong>${esc(r.ruta_texto || 'Ruta directa')}</strong>
          <small><i class="bi bi-calendar2-week"></i> Dia operativo: ${esc(r.fecha_operativa || 'Sin fecha')}</small>
          <small><i class="bi bi-arrow-repeat"></i> Actualizada: ${esc(r.fecha_actualizacion || r.fecha_programacion || 'Sin fecha')}</small>
        </div>
      </article>
    `).join('');

    $('unitProgramming').innerHTML = `
      <div class="check-report-program-grid">
        <div>
          <h3><i class="bi bi-people-fill"></i> Conductores</h3>
          ${drivers || '<div class="check-report-empty">Sin conductores asignados.</div>'}
        </div>
        <div>
          <h3><i class="bi bi-signpost-split-fill"></i> Rutas</h3>
          ${routes || '<div class="check-report-empty">Sin rutas asignadas.</div>'}
        </div>
      </div>
    `;

    $('unitLatest').innerHTML = (report.ultimos_por_tipo || []).map(t => `
      <div class="check-report-item">
        <span>${esc(t.tipo || `Tipo ${t.tipo_id}`)}</span>
        <strong>${t.ultimo ? esc(`${t.ultimo.corr} | ${t.ultimo.fecha} | ${t.ultimo.completion.estado}`) : 'Sin registro'}</strong>
        ${t.ultimo ? `<small>${kpiChip(t.ultimo.kpi)}</small>` : ''}
      </div>
    `).join('');

    const rows = (report.checklists || []).map(chk => `
      <tr>
        <td>${esc(chk.fecha)}<br><small>${esc(chk.hora)}</small></td>
        <td><strong>${esc(chk.tipo)}</strong><br><small>${esc(chk.corr)}</small></td>
        <td>${chip(chk.completion.estado, chk.completion.estado === 'Completo' ? 'ok' : 'bad')}<br><small>${esc(chk.completion.respondidos)} / ${esc(chk.completion.total)}</small></td>
        <td>${kpiChip(chk.kpi)}<br><small>${esc(chk.kpi?.titulo || 'Sin KPI')}</small></td>
        <td>${esc(text(chk.responsable))}</td>
        <td>${esc(text(chk.observaciones, '-'))}</td>
        <td>
          <div class="check-report-actions">
            <a class="check-report-btn check-report-btn--soft" href="${esc(checklistViewHref(chk.id))}" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Ver</a>
            <button type="button" class="check-report-btn check-report-btn--soft" data-checklist-pdf="${esc(chk.id)}"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
          </div>
        </td>
      </tr>
    `).join('');

    $('unitChecklistBody').innerHTML = rows || `<tr><td colspan="7">No hay checklists en el periodo.</td></tr>`;
    $('btnUnitPdf').disabled = !(report.checklists || []).length;
  }

  function renderFleetReport(report) {
    fleetState = report;
    renderSummary('fleet', report.resumen);
    const rows = (report.checklists || []).map(chk => `
      <tr>
        <td><strong>${esc(chk.bus || '-')}</strong><br><small>${esc(chk.placa || '-')}</small></td>
        <td>${esc(chk.fecha || '-')}<br><small>${esc(chk.hora || '')}</small></td>
        <td><strong>${esc(chk.tipo || '-')}</strong><br><small>${esc(chk.corr || '-')}</small></td>
        <td>${chip(chk.completion?.estado || '-', chk.completion?.estado === 'Completo' ? 'ok' : 'bad')}<br><small>${esc(chk.completion?.respondidos || 0)} / ${esc(chk.completion?.total || 0)}</small></td>
        <td>${kpiChip(chk.kpi)}<br><small>${esc(chk.kpi?.titulo || 'Sin KPI')}</small></td>
        <td>${esc(text(chk.responsable, '-'))}</td>
      </tr>
    `).join('');
    $('fleetBody').innerHTML = rows || `<tr><td colspan="6">No hay informacion en el periodo.</td></tr>`;
    $('btnFleetPdf').disabled = !(report.checklists || []).length;
  }

  async function loadUnitReport(button) {
    if (!selectedUnit) throw new Error('Selecciona una unidad.');
    const data = await fetchJson('unidad', {
      id_bus: selectedUnit.id_bus,
      desde: $('unitDesde').value,
      hasta: $('unitHasta').value
    });
    renderUnitReport(data);
  }

  async function loadFleetReport(button) {
    const data = await fetchJson('flota', {
      desde: $('fleetDesde').value,
      hasta: $('fleetHasta').value
    });
    fleetSource = data;
    populateFleetFilters(data.checklists || []);
    applyFleetFilters();
  }

  async function searchUnits() {
    const q = $('unitSearch').value.trim();
    const box = $('unitResults');
    if (q.length < 1) {
      box.classList.add('check-report-hidden');
      box.innerHTML = '';
      return;
    }
    const data = await fetchJson('buscar_unidades', {q});
    box.innerHTML = (data.unidades || []).map(u => `
      <button type="button" class="check-report-option" data-unit-id="${esc(u.id_bus)}" data-unit-bus="${esc(u.bus)}" data-unit-placa="${esc(u.placa)}" data-unit-servicio="${esc(u.servicio)}">
        <strong>${esc(u.bus || 'Unidad')} (${esc(u.placa || 'Sin placa')})</strong>
        <span>${esc(u.servicio || 'Sin servicio')}</span>
      </button>
    `).join('') || '<div class="check-report-option"><strong>Sin resultados</strong><span>Prueba con otro bus o placa.</span></div>';
    box.classList.remove('check-report-hidden');
  }

  function bindUnitPage() {
    $('unitSearch').addEventListener('input', () => {
      selectedUnit = null;
      $('btnUnitLoad').disabled = true;
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => searchUnits().catch(err => alert(err.message)), 220);
    });

    $('unitResults').addEventListener('click', event => {
      const btn = event.target.closest('[data-unit-id]');
      if (!btn) return;
      selectedUnit = {
        id_bus: btn.dataset.unitId,
        bus: btn.dataset.unitBus,
        placa: btn.dataset.unitPlaca,
        servicio: btn.dataset.unitServicio
      };
      $('unitSearch').value = `${selectedUnit.bus} (${selectedUnit.placa})`;
      $('unitResults').classList.add('check-report-hidden');
      $('btnUnitLoad').disabled = false;
    });

    $('btnUnitLoad').addEventListener('click', function () {
      const button = this;
      during(() => loadUnitReport(button), {title: 'Cargando unidad...', detail: 'Consultando checklists y programacion', button})
        .catch(err => alert(err.message));
    });

    $('btnUnitPdf').addEventListener('click', function () {
      const button = this;
      during(() => generateUnitPdf(unitState), {title: 'Generando PDF...', detail: 'Preparando consolidado de unidad', button})
        .catch(err => alert(err.message));
    });

    $('unitChecklistBody').addEventListener('click', event => {
      const btn = event.target.closest('[data-checklist-pdf]');
      if (!btn) return;
      const id = btn.dataset.checklistPdf;
      during(async () => {
        const detail = await fetchJson('checklist', {id_checklist: id});
        await generateChecklistPdf(detail);
      }, {title: 'Generando PDF...', detail: 'Preparando checklist unitario', button: btn}).catch(err => alert(err.message));
    });
  }

  function bindFleetPage() {
    $('btnFleetLoad').addEventListener('click', function () {
      const button = this;
      during(() => loadFleetReport(button), {title: 'Cargando consolidado...', detail: 'Consultando checklists de calidad', button})
        .catch(err => alert(err.message));
    });

    $('btnFleetPdf').addEventListener('click', function () {
      const button = this;
      during(() => generateFleetPdf(fleetState), {title: 'Generando PDF...', detail: 'Preparando consolidado de calidad', button})
        .catch(err => alert(err.message));
    });

    ['fleetLocalSearch', 'fleetTipoFilter', 'fleetKpiFilter'].forEach(id => {
      const el = $(id);
      if (el) el.addEventListener(id === 'fleetLocalSearch' ? 'input' : 'change', applyFleetFilters);
    });

    const clear = $('btnFleetClearFilters');
    if (clear) {
      clear.addEventListener('click', () => {
        if ($('fleetLocalSearch')) $('fleetLocalSearch').value = '';
        if ($('fleetTipoFilter')) $('fleetTipoFilter').value = '';
        if ($('fleetKpiFilter')) $('fleetKpiFilter').value = '';
        applyFleetFilters();
      });
    }
  }

  function bindSinglePage() {
    const id = CFG.checklistId;
    const status = $('singleStatus');
    during(async () => {
      const detail = await fetchJson('checklist', {id_checklist: id});
      if (status) {
        status.innerHTML = `<strong>${esc(detail.checklist.tipo)}</strong><span>${esc(detail.checklist.bus)} (${esc(detail.checklist.placa)}) - ${esc(detail.checklist.corr)}</span>`;
      }
      if (CFG.autoDownload) await generateChecklistPdf(detail);
    }, {title: 'Generando PDF...', detail: 'Preparando checklist unitario'})
      .catch(err => {
        if (status) status.innerHTML = `<strong>No se pudo generar</strong><span>${esc(err.message)}</span>`;
        alert(err.message);
      });
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (CFG.mode === 'unit') bindUnitPage();
    if (CFG.mode === 'fleet') bindFleetPage();
    if (CFG.mode === 'single') bindSinglePage();
  });

  window.N360ChecklistReports = {
    generateChecklistPdf,
    generateUnitPdf,
    generateFleetPdf
  };
})(window, document);
