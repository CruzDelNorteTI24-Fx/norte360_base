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
  let unitAnalysisState = null;
  let unitAnalysisRequest = 0;
  let activeChecklistDetail = null;
  let analysisActiveUnits = [];
  let analysisSelectedIds = new Set();
  let analysisAllActive = true;

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

  function percentage(value, fallback) {
    if (value === null || value === undefined || value === '') return fallback || 'No aplica';
    const number = Number(value);
    if (!Number.isFinite(number)) return fallback || 'No aplica';
    const formatted = Number.isInteger(number) ? String(number) : number.toFixed(1);
    return `${formatted}%`;
  }

  function boundedPercentage(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    return Math.max(0, Math.min(100, number));
  }

  function metricScore(metrics) {
    return metrics?.conformidad === null || metrics?.conformidad === undefined
      ? boundedPercentage(metrics?.completitud)
      : boundedPercentage(metrics.conformidad);
  }

  function metricLabel(metrics) {
    return metrics?.conformidad === null || metrics?.conformidad === undefined
      ? 'Completitud'
      : 'Conformidad';
  }

  function metricTone(metrics) {
    return ['ok', 'warn', 'bad'].includes(metrics?.estado) ? metrics.estado : 'neutral';
  }

  function zoneIcon(name) {
    const value = String(name || '').toLowerCase();
    if (value.includes('1') || value.includes('primer')) return 'bi-1-square-fill';
    if (value.includes('2') || value.includes('segundo')) return 'bi-2-square-fill';
    if (value.includes('baño') || value.includes('bano')) return 'bi-droplet-half';
    if (value.includes('exterior')) return 'bi-bus-front-fill';
    if (value.includes('conductor')) return 'bi-person-badge-fill';
    return 'bi-grid-1x2-fill';
  }

  function itemStatusLabel(item) {
    const metric = item?.kpi || {};
    return metric.label || (item?.respondido ? 'Registrado' : 'Pendiente');
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
          kpiCell(chk.kpi),
          chk.observaciones || '-'
        ]);
        y = drawTable(doc, ['Unidad', 'Fecha', 'Checklist', 'Items', 'KPI', 'Observaciones'], rows, [26, 23, 32, 20, 39, 42], y, {
          orientation: 'portrait',
          fontSize: 5.8,
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
        chk.observaciones,
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

  function populateUnitAnalysisVersions(report, preserveValue) {
    const select = $('unitAnalysisVersion');
    if (!select) return;

    const previous = preserveValue ? select.value : '';
    const versions = (report.versiones || [])
      .filter(version => Number(version.id || 0) > 0)
      .map(version => [String(version.id), version.nombre || `Versión ${version.id}`]);

    select.innerHTML = '<option value="0">Todas las versiones</option>'
      + versions.map(([id, label]) => `<option value="${esc(id)}">${esc(label)}</option>`).join('');
    if (previous && Array.from(select.options).some(option => option.value === previous)) {
      select.value = previous;
    } else {
      select.value = '0';
    }
  }

  function prepareUnitAnalysis(report) {
    const section = $('unitQuality');
    const typeSelect = $('unitAnalysisType');
    if (!section || !typeSelect) return false;

    const previousType = typeSelect.value;
    const types = (report.tipos || []).map(type => [String(type.id || ''), type.nombre || `Tipo ${type.id}`]);

    typeSelect.innerHTML = types.length
      ? types.map(([id, label]) => `<option value="${esc(id)}">${esc(label)}</option>`).join('')
      : '<option value="">Sin checklists</option>';

    const responseType = String(report.tipo?.id || '');
    if (responseType && types.some(([id]) => id === responseType)) {
      typeSelect.value = responseType;
    } else if (previousType && types.some(([id]) => id === previousType)) {
      typeSelect.value = previousType;
    } else {
      const cleaning = types.find(([id]) => id === '1');
      typeSelect.value = cleaning ? cleaning[0] : (types[0]?.[0] || '');
    }

    populateUnitAnalysisVersions(report, true);
    $('unitQualityPeriod').textContent = `${text(report.filtros?.desde, '-')} al ${text(report.filtros?.hasta, '-')}`;
    section.classList.remove('check-report-hidden');
    return types.length > 0;
  }

  function renderUnitAnalysis() {
    const data = unitAnalysisState || {};
    const metrics = data.metricas || {};
    const scope = data.alcance || {};
    const zones = Array.isArray(data.zonas) ? data.zonas : [];
    const query = ($('unitAnalysisSearch')?.value || '').trim().toLowerCase();
    const status = $('unitAnalysisStatus')?.value || '';

    $('unitQualityVersion').textContent = (data.versiones || []).length
      ? `Versión: ${(data.versiones || []).map(version => version.nombre).join(' / ')}`
      : 'Sin versión en el periodo';

    $('unitQualityMetrics').innerHTML = `
      <article class="check-analysis-kpi is-units"><span class="check-analysis-kpi__icon"><i class="bi bi-bus-front"></i></span><div><span>Unidades</span><strong>${esc(scope.unidades_con_datos || 0)} / ${esc(scope.unidades_seleccionadas || 0)}</strong><small>${esc(scope.unidades_sin_datos || 0)} sin registros</small></div></article>
      <article class="check-analysis-kpi is-runs"><span class="check-analysis-kpi__icon"><i class="bi bi-clipboard2-check"></i></span><div><span>Ejecuciones</span><strong>${esc(data.checklists || 0)}</strong><small>${esc(data.tipo?.nombre || 'Checklist seleccionado')}</small></div></article>
      <article class="check-analysis-kpi is-answers"><span class="check-analysis-kpi__icon"><i class="bi bi-ui-checks-grid"></i></span><div><span>Ítems respondidos</span><strong>${esc(metrics.respondidos || 0)} / ${esc(metrics.total || 0)}</strong><small>${esc(percentage(metrics.completitud, '0%'))} de completitud</small></div></article>
      <article class="check-analysis-kpi is-quality"><span class="check-analysis-kpi__icon"><i class="bi bi-shield-check"></i></span><div><span>Conformidad</span><strong>${esc(percentage(metrics.conformidad, '0%'))}</strong><small>${esc(metrics.conformes || 0)} de ${esc(metrics.evaluables || 0)} evaluables</small></div></article>
      <article class="check-analysis-kpi is-findings"><span class="check-analysis-kpi__icon"><i class="bi bi-exclamation-triangle"></i></span><div><span>Hallazgos</span><strong>${esc(metrics.no_conformes || 0)}</strong><small>${esc(metrics.pendientes || 0)} pendientes</small></div></article>
    `;

    const filtered = zones.reduce((acc, zone) => {
      if (status && metricTone(zone.metricas) !== status) return acc;
      const zoneMatch = String(zone.nombre || '').toLowerCase().includes(query);
      const matchingItems = query
        ? (zone.items || []).filter(item => String(item.nombre || '').toLowerCase().includes(query))
        : (zone.items || []);
      if (query && !zoneMatch && !matchingItems.length) return acc;
      acc.push(Object.assign({}, zone, {visibleItems: zoneMatch ? (zone.items || []) : matchingItems}));
      return acc;
    }, []);

    const grid = $('unitZoneGrid');
    if (!filtered.length) {
      grid.innerHTML = '<div class="check-report-empty">No hay zonas que coincidan con los filtros seleccionados.</div>';
      return;
    }

    grid.innerHTML = filtered.map((zone, index) => {
      const zoneMetrics = zone.metricas || {};
      const tone = metricTone(zoneMetrics);
      const open = Number(scope.unidades_seleccionadas || 0) === 1 && index === 0;
      const total = Math.max(0, Number(zoneMetrics.total) || 0);
      const segmentWidth = value => total > 0 ? boundedPercentage((Number(value) || 0) * 100 / total) : 0;
      const itemRows = (zone.visibleItems || []).map(item => {
        const itemMetrics = item.metricas || {};
        const itemScore = metricScore(itemMetrics);
        const itemTone = metricTone(itemMetrics);
        return `<div class="check-report-zone-item is-${itemTone}">
          <div class="check-report-zone-item__main">
            <strong>${esc(item.nombre || 'Item sin nombre')}</strong>
          </div>
          <span class="check-report-zone-item__count is-ok" data-label="C">${esc(itemMetrics.conformes || 0)}</span>
          <span class="check-report-zone-item__count is-bad" data-label="NC">${esc(itemMetrics.no_conformes || 0)}</span>
          <span class="check-report-zone-item__count is-na" data-label="NA">${esc(itemMetrics.no_aplica || 0)}</span>
          <span class="check-report-zone-item__count is-pending" data-label="Pend.">${esc(itemMetrics.pendientes || 0)}</span>
          <div class="check-report-zone-item__score">
            <strong>${esc(percentage(itemMetrics.conformidad ?? itemMetrics.completitud, '0%'))}</strong>
            <span>${esc(metricLabel(itemMetrics))}</span>
            <div class="check-report-progress"><span style="width:${itemScore}%"></span></div>
          </div>
        </div>`;
      }).join('');

      return `<article class="check-report-zone is-${tone}">
        <button type="button" class="check-report-zone__head" data-zone-toggle aria-expanded="${open ? 'true' : 'false'}">
          <span class="check-report-zone__index">${esc(index + 1)}</span>
          <span class="check-report-zone__title">
            <strong>${esc(zone.nombre || 'Zona sin nombre')}</strong>
            <small>${esc(zone.version || 'Sin versión')} &middot; ${esc(zone.items_catalogo || 0)} ítems &middot; ${esc(zone.checklists || 0)} ejecuciones</small>
          </span>
          <span class="check-report-zone__result"><strong>${esc(percentage(zoneMetrics.conformidad ?? zoneMetrics.completitud, '0%'))}</strong><small>${esc(metricLabel(zoneMetrics))}</small></span>
          <i class="bi bi-chevron-down check-report-zone__chevron" aria-hidden="true"></i>
        </button>
        <div class="check-report-zone__distribution" aria-label="Distribucion de resultados">
          <span class="is-ok" style="width:${segmentWidth(zoneMetrics.conformes)}%"></span>
          <span class="is-bad" style="width:${segmentWidth(zoneMetrics.no_conformes)}%"></span>
          <span class="is-na" style="width:${segmentWidth(zoneMetrics.no_aplica)}%"></span>
          <span class="is-pending" style="width:${segmentWidth(zoneMetrics.pendientes)}%"></span>
        </div>
        <div class="check-report-zone__legend">
          <span class="is-ok"><i></i><b>${esc(zoneMetrics.conformes || 0)}</b> Conformes</span>
          <span class="is-bad"><i></i><b>${esc(zoneMetrics.no_conformes || 0)}</b> No conformes</span>
          <span class="is-na"><i></i><b>${esc(zoneMetrics.no_aplica || 0)}</b> No aplica</span>
          <span class="is-pending"><i></i><b>${esc(zoneMetrics.pendientes || 0)}</b> Pendientes</span>
        </div>
        <div class="check-report-zone__items${open ? '' : ' check-report-hidden'}">
          <div class="check-report-zone-item check-report-zone-item--head" aria-hidden="true">
            <span>Item evaluado</span><span>C</span><span>NC</span><span>NA</span><span>Pend.</span><span>Resultado</span>
          </div>
          ${itemRows || '<div class="check-report-empty">No hay items visibles para esta zona.</div>'}
        </div>
      </article>`;
    }).join('');
  }

  function openChecklistDetailModal() {
    const modal = $('checklistDetailModal');
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('check-report-modal-open');
  }

  function closeChecklistDetailModal() {
    const modal = $('checklistDetailModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('check-report-modal-open');
  }

  function renderChecklistDetail(detail) {
    activeChecklistDetail = detail;
    const checklist = detail.checklist || {};
    const quality = checklist.calidad || {};
    $('checklistDetailTitle').textContent = `${text(checklist.tipo, 'Checklist')} - ${text(checklist.corr, 'Sin correlativo')}`;

    const categories = (detail.categorias || []).map((category, index) => {
      const metrics = category.metricas || {};
      const tone = metricTone(metrics);
      const rows = (category.items || []).map(item => {
        const itemTone = ['ok', 'warn', 'bad', 'neutral'].includes(item.kpi?.status) ? item.kpi.status : 'neutral';
        return `<div class="check-report-detail-item is-${itemTone}">
          <span class="check-report-detail-item__status"><i class="bi ${itemTone === 'ok' ? 'bi-check-lg' : (itemTone === 'bad' ? 'bi-x-lg' : (itemTone === 'warn' ? 'bi-exclamation-lg' : 'bi-dash-lg'))}" aria-hidden="true"></i></span>
          <div class="check-report-detail-item__name">
            <strong>${esc(item.item || 'Item sin nombre')}</strong>
            <small>${esc(item.observacion || 'Sin observaciones')}</small>
          </div>
          <div class="check-report-detail-item__answer">
            <span>${esc(itemStatusLabel(item))}</span>
            <strong>${esc(item.kpi?.value || item.valor || 'Sin respuesta')}</strong>
          </div>
          <div class="check-report-detail-item__audit">
            <span>${esc(item.usuario || 'Sin usuario')}</span>
            <small>${esc(item.fecha_registro || 'Sin fecha')}</small>
          </div>
        </div>`;
      }).join('');

      return `<details class="check-report-detail-zone is-${tone}" ${index === 0 ? 'open' : ''}>
        <summary>
          <span class="check-report-detail-zone__icon"><i class="bi ${zoneIcon(category.nombre)}" aria-hidden="true"></i></span>
          <span><small>Zona ${index + 1}</small><strong>${esc(category.nombre || 'Categoria')}</strong></span>
          <span class="check-report-detail-zone__metric"><strong>${esc(percentage(metrics.conformidad ?? metrics.completitud, '0%'))}</strong><small>${esc(metricLabel(metrics))}</small></span>
          <span class="check-report-detail-zone__counts">${esc(metrics.conformes || 0)} C &nbsp; ${esc(metrics.no_conformes || 0)} NC &nbsp; ${esc(metrics.pendientes || 0)} pendientes</span>
        </summary>
        <div class="check-report-detail-zone__items">${rows || '<div class="check-report-empty">Sin items registrados.</div>'}</div>
      </details>`;
    }).join('');

    $('checklistDetailBody').innerHTML = `
      <section class="check-report-detail-overview">
        <div><span>Unidad</span><strong>${esc(text(checklist.bus, 'Unidad'))}</strong><small>${esc(text(checklist.placa, 'Sin placa'))}</small></div>
        <div><span>Fecha</span><strong>${esc(text(checklist.fecha, '-'))}</strong><small>${esc(text(checklist.hora, ''))}</small></div>
        <div><span>Version</span><strong>${esc(checklist.version || 'Estructura heredada')}</strong><small>${esc(checklist.tipo || '')}</small></div>
        <div><span>Responsable</span><strong>${esc(text(checklist.responsable, '-'))}</strong><small>${esc(text(checklist.usuario_registro, ''))}</small></div>
      </section>
      <section class="check-report-detail-metrics">
        <div><span>Completitud</span><strong>${esc(percentage(quality.completitud, '0%'))}</strong><small>${esc(quality.respondidos || 0)} / ${esc(quality.total || 0)} respuestas</small></div>
        <div class="is-green"><span>Conformidad</span><strong>${esc(percentage(quality.conformidad))}</strong><small>${esc(quality.conformes || 0)} conformes</small></div>
        <div class="is-red"><span>No conformes</span><strong>${esc(quality.no_conformes || 0)}</strong><small>${esc(quality.evaluables || 0)} evaluables</small></div>
        <div class="is-gold"><span>No aplica / pendientes</span><strong>${esc((quality.no_aplica || 0) + (quality.pendientes || 0))}</strong><small>${esc(quality.no_aplica || 0)} NA y ${esc(quality.pendientes || 0)} pendientes</small></div>
      </section>
      <div class="check-report-detail-actions">
        <a class="check-report-btn check-report-btn--soft" href="${esc(checklistViewHref(checklist.id))}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir ficha</a>
        <button type="button" class="check-report-btn check-report-btn--primary" data-detail-pdf><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</button>
      </div>
      <section class="check-report-detail-zones">${categories || '<div class="check-report-empty">Este checklist no contiene categorias.</div>'}</section>
    `;
  }

  async function openChecklistDetail(checklistId, button) {
    await during(async () => {
      const detail = await fetchJson('checklist', {id_checklist: checklistId});
      renderChecklistDetail(detail);
      openChecklistDetailModal();
    }, {title: 'Cargando items...', detail: 'Organizando zonas y resultados', button});
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
        <td>${esc(text(chk.observaciones, '-'))}</td>
      </tr>
    `).join('');
    $('fleetBody').innerHTML = rows || `<tr><td colspan="7">No hay informacion en el periodo.</td></tr>`;
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

  function analysisScopeIds() {
    return analysisAllActive
      ? analysisActiveUnits.map(unit => Number(unit.id_bus))
      : Array.from(analysisSelectedIds);
  }

  function filteredAnalysisUnits() {
    const query = ($('unitPickerSearch')?.value || '').trim().toLowerCase();
    if (!query) return analysisActiveUnits;
    return analysisActiveUnits.filter(unit => [unit.bus, unit.placa, unit.servicio].join(' ').toLowerCase().includes(query));
  }

  function renderAnalysisUnitPicker() {
    const visible = filteredAnalysisUnits();
    $('visibleUnitsCount').textContent = `${visible.length} disponible${visible.length === 1 ? '' : 's'}`;
    $('unitPickerList').innerHTML = visible.map(unit => {
      const id = Number(unit.id_bus);
      return `<label class="check-analysis-picker__option">
        <input type="checkbox" value="${esc(id)}" data-analysis-unit${analysisSelectedIds.has(id) ? ' checked' : ''}>
        <span><strong>${esc(unit.bus || 'Unidad')} <em>${esc(unit.placa || 'Sin placa')}</em></strong><small>${esc(unit.servicio || 'Sin servicio')}</small></span>
      </label>`;
    }).join('') || '<div class="check-analysis-picker__none">No hay unidades que coincidan.</div>';
  }

  function renderAnalysisSelection() {
    const selected = analysisActiveUnits.filter(unit => analysisSelectedIds.has(Number(unit.id_bus)));
    $('activeUnitsCount').textContent = `${analysisActiveUnits.length} unidad${analysisActiveUnits.length === 1 ? '' : 'es'} activa${analysisActiveUnits.length === 1 ? '' : 's'}`;
    $('allScopeCount').textContent = `${analysisActiveUnits.length} unidad${analysisActiveUnits.length === 1 ? '' : 'es'}`;
    $('manualSelectionLabel').textContent = selected.length
      ? `${selected.length} unidad${selected.length === 1 ? '' : 'es'} seleccionada${selected.length === 1 ? '' : 's'}`
      : 'Seleccionar unidades';

    const summary = $('unitSelectionSummary');
    if (analysisAllActive || !selected.length) {
      summary.classList.add('check-report-hidden');
      summary.innerHTML = '';
    } else {
      const shown = selected.slice(0, 12);
      summary.innerHTML = shown.map(unit => `<span>${esc(unit.bus || 'Unidad')} <small>${esc(unit.placa || '')}</small><button type="button" data-remove-analysis-unit="${esc(unit.id_bus)}" aria-label="Quitar ${esc(unit.bus || 'unidad')}" title="Quitar"><i class="bi bi-x" aria-hidden="true"></i></button></span>`).join('')
        + (selected.length > shown.length ? `<b>+${selected.length - shown.length} más</b>` : '');
      summary.classList.remove('check-report-hidden');
    }

    $('btnUnitLoad').disabled = !analysisActiveUnits.length || (!analysisAllActive && !selected.length);
    renderAnalysisUnitPicker();
  }

  function invalidateAnalysisOutput() {
    if (!unitAnalysisState) return;
    unitAnalysisState = null;
    $('unitQuality').classList.add('check-report-hidden');
    $('analysisEmpty').classList.remove('check-report-hidden');
    const message = $('analysisEmpty').querySelector('.check-analysis-empty__message');
    if (message) message.textContent = 'La selección cambió. Pulsa Analizar para actualizar los resultados.';
  }

  function setAnalysisScope(mode) {
    const changed = analysisAllActive !== (mode === 'all');
    analysisAllActive = mode === 'all';
    document.querySelectorAll('[data-analysis-scope]').forEach(button => {
      const active = button.dataset.analysisScope === mode;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    $('allScopeSummary').classList.toggle('check-report-hidden', !analysisAllActive);
    $('manualScopeControls').classList.toggle('check-report-hidden', analysisAllActive);
    renderAnalysisSelection();
    if (changed) invalidateAnalysisOutput();
  }

  function setAnalysisView(view) {
    document.querySelectorAll('[data-analysis-view]').forEach(button => {
      const active = button.dataset.analysisView === view;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-analysis-panel]').forEach(panel => {
      panel.classList.toggle('check-report-hidden', panel.dataset.analysisPanel !== view);
    });
  }

  async function loadActiveAnalysisUnits() {
    const data = await fetchJson('unidades_activas');
    analysisActiveUnits = Array.isArray(data.unidades) ? data.unidades : [];
    analysisSelectedIds = new Set();
    renderAnalysisSelection();
  }

  function renderAnalysisUnits() {
    const rows = unitAnalysisState?.unidades || [];
    $('analysisUnitsCount').textContent = String(rows.length);
    $('analysisUnitsBody').innerHTML = rows.map(unit => {
      const metrics = unit.metricas || {};
      const hasData = Number(unit.checklists || 0) > 0;
      return `<tr>
        <td><strong>${esc(unit.bus || 'Unidad')}</strong><br><small>${esc(unit.placa || 'Sin placa')}</small></td>
        <td>${esc(unit.servicio || '-')}</td>
        <td>${hasData ? `<strong>${esc(unit.checklists)}</strong>` : chip('Sin registros', 'warn')}</td>
        <td>${esc(metrics.respondidos || 0)} / ${esc(metrics.total || 0)}<br><small>${esc(percentage(metrics.completitud, '0%'))}</small></td>
        <td>${hasData ? `<strong>${esc(percentage(metrics.conformidad, '0%'))}</strong>` : '-'}</td>
        <td>${hasData ? esc(metrics.no_conformes || 0) : '-'}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="6">No hay unidades para mostrar.</td></tr>';
  }

  function renderAnalysisHistory() {
    const rows = unitAnalysisState?.ejecuciones || [];
    $('analysisHistoryCount').textContent = String(rows.length);
    $('analysisChecklistBody').innerHTML = rows.map(checklist => `
      <tr>
        <td><strong>${esc(checklist.bus || 'Unidad')}</strong><br><small>${esc(checklist.placa || '-')}</small></td>
        <td>${esc(checklist.fecha || '-')}<br><small>${esc(checklist.hora || '')}</small></td>
        <td><strong>${esc(checklist.tipo || '-')}</strong><br><small>${esc(checklist.corr || '-')}</small></td>
        <td>${chip(checklist.version || 'Estructura heredada', checklist.version_id ? 'version' : 'warn')}</td>
        <td>${esc(text(checklist.responsable, '-'))}</td>
        <td><button type="button" class="check-report-icon-btn" data-checklist-detail="${esc(checklist.id)}" title="Ver ítems por zona" aria-label="Ver ítems por zona"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i></button></td>
      </tr>
    `).join('') || '<tr><td colspan="6">No hay ejecuciones para los filtros seleccionados.</td></tr>';
  }

  async function loadAnalysisReport() {
    const ids = analysisScopeIds();
    if (!ids.length) throw new Error('Selecciona al menos una unidad activa.');
    const requestId = ++unitAnalysisRequest;
    $('unitZoneGrid').innerHTML = '<div class="check-report-empty"><span class="check-report-inline-loader"></span> Consolidando resultados...</div>';

    const data = await fetchJson('analisis_unidades', {
      todas_activas: analysisAllActive ? 1 : 0,
      id_buses: analysisAllActive ? '' : ids.join(','),
      desde: $('unitDesde').value,
      hasta: $('unitHasta').value,
      tipo_id: $('unitAnalysisType')?.value || 0,
      version_id: $('unitAnalysisVersion')?.value || 0
    });
    if (requestId !== unitAnalysisRequest) return;

    unitState = data;
    unitAnalysisState = data;
    $('analysisEmpty').classList.add('check-report-hidden');
    $('analysisUnitName').textContent = analysisAllActive
      ? 'Todas las unidades activas'
      : `${ids.length} unidad${ids.length === 1 ? '' : 'es'} seleccionada${ids.length === 1 ? '' : 's'}`;
    prepareUnitAnalysis(data);
    renderUnitAnalysis();
    renderAnalysisUnits();
    renderAnalysisHistory();
    setAnalysisView('zones');
  }

  function bindAnalysisPage() {
    document.querySelectorAll('[data-analysis-scope]').forEach(button => {
      button.addEventListener('click', () => setAnalysisScope(button.dataset.analysisScope));
    });

    $('unitPickerToggle').addEventListener('click', () => {
      const panel = $('unitPickerPanel');
      const opening = panel.classList.contains('check-report-hidden');
      panel.classList.toggle('check-report-hidden', !opening);
      $('unitPickerToggle').setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) $('unitPickerSearch').focus();
    });
    $('unitPickerSearch').addEventListener('input', renderAnalysisUnitPicker);
    $('unitPickerList').addEventListener('change', event => {
      const input = event.target.closest('[data-analysis-unit]');
      if (!input) return;
      const id = Number(input.value);
      if (input.checked) analysisSelectedIds.add(id);
      else analysisSelectedIds.delete(id);
      renderAnalysisSelection();
      invalidateAnalysisOutput();
    });
    $('btnSelectVisible').addEventListener('click', () => {
      filteredAnalysisUnits().forEach(unit => analysisSelectedIds.add(Number(unit.id_bus)));
      renderAnalysisSelection();
      invalidateAnalysisOutput();
    });
    $('btnClearUnits').addEventListener('click', () => {
      analysisSelectedIds.clear();
      renderAnalysisSelection();
      invalidateAnalysisOutput();
    });
    $('unitSelectionSummary').addEventListener('click', event => {
      const button = event.target.closest('[data-remove-analysis-unit]');
      if (!button) return;
      analysisSelectedIds.delete(Number(button.dataset.removeAnalysisUnit));
      renderAnalysisSelection();
      invalidateAnalysisOutput();
    });

    ['unitDesde', 'unitHasta'].forEach(id => {
      $(id).addEventListener('change', invalidateAnalysisOutput);
    });

    $('btnUnitLoad').addEventListener('click', function () {
      const button = this;
      during(() => loadAnalysisReport(), {title: 'Analizando checklists...', detail: 'Consolidando unidades, zonas e ítems', button})
        .catch(err => alert(err.message));
    });

    $('unitAnalysisType').addEventListener('change', () => {
      $('unitAnalysisVersion').value = '0';
      during(() => loadAnalysisReport(), {title: 'Actualizando checklist...', detail: 'Recalculando resultados'}).catch(err => alert(err.message));
    });
    $('unitAnalysisVersion').addEventListener('change', () => {
      during(() => loadAnalysisReport(), {title: 'Actualizando versión...', detail: 'Recalculando resultados'}).catch(err => alert(err.message));
    });
    $('unitAnalysisStatus').addEventListener('change', renderUnitAnalysis);
    $('unitAnalysisSearch').addEventListener('input', renderUnitAnalysis);

    document.querySelectorAll('[data-analysis-view]').forEach(button => {
      button.addEventListener('click', () => setAnalysisView(button.dataset.analysisView));
    });
    $('unitZoneGrid').addEventListener('click', event => {
      const button = event.target.closest('[data-zone-toggle]');
      if (!button) return;
      const items = button.closest('.check-report-zone')?.querySelector('.check-report-zone__items');
      if (!items) return;
      const willOpen = items.classList.contains('check-report-hidden');
      items.classList.toggle('check-report-hidden', !willOpen);
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
    $('analysisChecklistBody').addEventListener('click', event => {
      const button = event.target.closest('[data-checklist-detail]');
      if (!button) return;
      openChecklistDetail(button.dataset.checklistDetail, button).catch(err => alert(err.message));
    });

    $('btnCloseChecklistDetail').addEventListener('click', closeChecklistDetailModal);
    $('checklistDetailModal').addEventListener('click', event => {
      if (event.target === $('checklistDetailModal')) closeChecklistDetailModal();
    });
    $('checklistDetailBody').addEventListener('click', event => {
      const button = event.target.closest('[data-detail-pdf]');
      if (!button || !activeChecklistDetail) return;
      during(() => generateChecklistPdf(activeChecklistDetail), {title: 'Generando PDF...', detail: 'Preparando checklist unitario', button})
        .catch(err => alert(err.message));
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.check-analysis-picker')) {
        $('unitPickerPanel').classList.add('check-report-hidden');
        $('unitPickerToggle').setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        closeChecklistDetailModal();
        $('unitPickerPanel').classList.add('check-report-hidden');
        $('unitPickerToggle').setAttribute('aria-expanded', 'false');
      }
    });

    setAnalysisScope('all');
    loadActiveAnalysisUnits().catch(err => {
      $('activeUnitsCount').textContent = 'No se pudieron cargar';
      alert(err.message);
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
    if (CFG.mode === 'analysis') bindAnalysisPage();
    if (CFG.mode === 'fleet') bindFleetPage();
    if (CFG.mode === 'single') bindSinglePage();
  });

  window.N360ChecklistReports = {
    generateChecklistPdf,
    generateUnitPdf,
    generateFleetPdf
  };
})(window, document);
