(() => {
  'use strict';
  const root = document.querySelector('[data-enc-inventory]');
  const rowsScript = document.getElementById('encInventoryRows');
  if (!root || !rowsScript) return;
  try {
  const rows = JSON.parse(rowsScript.textContent);
  const labels = JSON.parse(document.getElementById('encInventoryColumns').textContent);
  const element = document.getElementById('encInventoryModal');
  const body = root.querySelector('[data-inventory-modal-body]');
  const title = document.getElementById('encInventoryModalTitle');
  const historyCache = new Map();
  const historyRequests = new Map();
  const olderHistoryRequests = new Map();
  const expandedHistory = new Map();
  const reviewDrafts = new Map();
  let activeId = 0;
  let activeMode = '';
  let modalOpener = null;
  const node = (tag, text, cls) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (cls) result.className = cls;
    return result;
  };
  const valueText = (value, key) => {
    if (value === null || value === undefined || value === '') return '-';
    if (key === 'total' || key === 'importe_cobrado') {
      const parts = /^(-?)(\d+)(?:\.(\d+))?$/.exec(String(value));
      if (!parts) return `S/ ${String(value)}`;
      let fraction = (parts[3] || '').padEnd(2, '0');
      while (fraction.length > 2 && fraction.endsWith('0')) fraction = fraction.slice(0, -1);
      return `S/ ${parts[1]}${parts[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
    }
    if (key === 'activo') return Number(value) ? 'Sí' : 'No';
    if (['fecha', 'fecha_creacion_externa', 'fecha_cobro_externa'].includes(key) && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value.split('-').reverse().join('/');
    return String(value);
  };
  const config = JSON.parse(document.getElementById('encInventoryConfig').textContent);
  if (config.load_error) Object.keys(rows).forEach(id => { delete rows[id]; });
  rowsScript.remove();
  const filterForm = root.querySelector('[data-inventory-filters]');
  const searchForm = root.querySelector('[data-inventory-search]');
  const tableBody = root.querySelector('.enc-inventory-table tbody');
  const pagers = Array.from(root.querySelectorAll('[data-inventory-pager]'));
  const excelButton = root.querySelector('[data-inventory-excel]');
  const exportStatus = root.querySelector('[data-inventory-export-status]');
  const pageSize = 500;
  const missing = '__N360_SIN_DATO__';
  const filterFields = ['tipo_envio', 'pago', 'tipo_pago', 'seguimiento', 'estatus', 'revision'];
  const multiFields = { sucursal: 'Sucursal de origen informada', oficina_destino: 'Oficina de destino' };
  const screenInput = filterForm.elements.texto_pantalla;
  const normalize = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es').trim();
  const screenFields = ['tipo_origen', 'codigo_envio', 'doc_fiscal', 'codigo_control', 'remitente', 'destinatario', 'origen', 'destino', 'sucursal', 'oficina_destino', 'seguimiento', 'estatus', 'estado_pago', 'tipo_pago', 'revision_estado', 'referencia_envio', 'guia_remision', 'numero_operacion', 'fecha', 'total', 'importe_cobrado', 'articulos'];
  const sedeNames = new Map(config.sedes.map(sede => [String(sede.id), sede.nombre]));
  // Las claves numéricas del JSON se reordenan en JS; conservar fecha/ID descendentes del SQL.
  const records = Object.values(rows).sort((a, b) => String(b.fecha || '').localeCompare(String(a.fecha || '')) || Number(b.id) - Number(a.id)).map(row => {
    const ids = [...new Set(['sede_sucursal_id', 'sede_oficina_destino_id', 'sede_origen_id', 'sede_destino_id']
      .map(key => Number(row[key])).filter(id => id > 0))].map(String);
    return { row, sedeIds: ids, modality: /^(CE|CP)-/i.exec(row.codigo_envio || '')?.[1].toUpperCase() || 'OTRO', screenText: normalize([...screenFields.map(field => row[field]), valueText(row.fecha, 'fecha')].join(' ')) };
  });
  const fieldMap = { pago: 'estado_pago', tipo_pago: 'tipo_pago', seguimiento: 'seguimiento', estatus: 'estatus' };
  const numberText = value => Number(value).toLocaleString('es-PE');
  let filtered = [];
  let page = Math.max(1, Number(root.dataset.initialPage) || 1);
  const selectOption = (select, value, label) => { const option = node('option', label); option.value = value; select.append(option); };
  Object.entries(fieldMap).forEach(([filter, field]) => {
    const values = [...new Set(records.map(({ row }) => row[field]).filter(value => value !== null && value !== ''))];
    values.sort((a, b) => String(a).localeCompare(String(b), 'es', { sensitivity: 'base' }));
    values.forEach(value => selectOption(filterForm.elements[filter], String(value), String(value)));
    if (records.some(({ row }) => row[field] === null || row[field] === '')) selectOption(filterForm.elements[filter], missing, 'Sin informar');
  });
  filterFields.forEach(field => {
    const value = String(config.filters[field] || '');
    const select = filterForm.elements[field];
    if (value && !Array.from(select.options).some(option => option.value === value)) selectOption(select, value, value === missing ? 'Sin informar' : value);
    select.value = value;
  });
  filterForm.elements.archivados.checked = !!config.filters.archivados;
  screenInput.value = String(config.filters.texto_pantalla || '');
  const multiPickers = new Map();
  const closeMultiPickers = except => multiPickers.forEach(picker => { if (picker !== except) picker.close(); });
  const applyLocalFilters = () => { page = 1; render(); };
  Object.keys(multiFields).forEach(field => {
    const container = root.querySelector(`[data-inventory-multi=${field}]`);
    const trigger = container.querySelector('[data-multi-toggle]');
    const panel = container.querySelector('[data-multi-panel]');
    const search = container.querySelector('[data-multi-search]');
    const list = container.querySelector('[data-multi-list]');
    const count = container.querySelector('[data-multi-count]');
    const initial = config.filters[field] || [];
    const selected = new Set((Array.isArray(initial) ? initial : [initial]).map(String).filter(Boolean));
    const values = new Set(records.map(({ row }) => row[field]).filter(value => value !== null && value !== '').map(String));
    if (records.some(({ row }) => row[field] === null || row[field] === '')) values.add(missing);
    selected.forEach(value => values.add(value));
    const labelFor = value => value === missing ? 'Sin informar' : value;
    const choices = [...values].sort((a, b) => labelFor(a).localeCompare(labelFor(b), 'es', { sensitivity: 'base' })).map(value => {
      const label = node('label', undefined, 'enc-multi-option');
      const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.value = value; checkbox.checked = selected.has(value);
      label.append(checkbox, node('span', labelFor(value))); list.append(label);
      return { value, label, checkbox, text: normalize(labelFor(value)) };
    });
    const syncCount = () => {
      const values = [...selected]; count.textContent = values.length === 0 ? 'Todas' : values.length === 1 ? labelFor(values[0]) : `${values.length} seleccionadas`;
      container.classList.toggle('has-value', selected.size > 0); trigger.title = values.length ? values.map(labelFor).join(' / ') : 'Todas';
    };
    const searchOptions = () => {
      const query = normalize(search.value); let visible = 0;
      choices.forEach(choice => { choice.label.hidden = !choice.text.includes(query); if (!choice.label.hidden) visible++; });
      container.querySelector('[data-multi-empty]').hidden = visible > 0;
      container.querySelector('[data-multi-visible]').disabled = visible === 0;
    };
    const picker = {
      values: () => [...selected],
      text: () => selected.size ? [...selected].map(labelFor).join(' / ') : 'Todas',
      close: () => { panel.hidden = true; container.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false'); },
      clear: (notify = true) => { selected.clear(); choices.forEach(choice => { choice.checkbox.checked = false; }); search.value = ''; searchOptions(); syncCount(); if (notify) applyLocalFilters(); }
    };
    multiPickers.set(field, picker);
    trigger.addEventListener('click', () => {
      if (!panel.hidden) { picker.close(); return; }
      closeMultiPickers(picker); search.value = ''; searchOptions(); panel.hidden = false; container.classList.add('is-open'); trigger.setAttribute('aria-expanded', 'true'); search.focus();
    });
    search.addEventListener('input', searchOptions);
    list.addEventListener('change', event => {
      if (event.target.type !== 'checkbox') return;
      const input = event.target; if (input.checked) selected.add(input.value); else selected.delete(input.value);
      syncCount(); applyLocalFilters();
    });
    container.querySelector('[data-multi-visible]').addEventListener('click', () => { choices.forEach(choice => { if (!choice.label.hidden) { selected.add(choice.value); choice.checkbox.checked = true; } }); syncCount(); applyLocalFilters(); });
    container.querySelector('[data-multi-clear]').addEventListener('click', () => picker.clear());
    container.addEventListener('keydown', event => { if (event.key === 'Escape') { picker.close(); trigger.focus(); event.stopPropagation(); } });
    container.addEventListener('focusout', () => { queueMicrotask(() => { if (!container.contains(document.activeElement)) picker.close(); }); });
    syncCount(); searchOptions();
  });
  document.addEventListener('click', event => { if (!event.target.closest('[data-inventory-multi]')) closeMultiPickers(); });
  root.querySelector('.enc-filter-accordion').addEventListener('toggle', event => { if (!event.target.open) closeMultiPickers(); });
  const currentFilters = () => ({ ...Object.fromEntries(filterFields.map(field => [field, filterForm.elements[field].value])),
    ...Object.fromEntries([...multiPickers].map(([field, picker]) => [field, picker.values()])), texto_pantalla: screenInput.value.trim() });
  const syncLocation = values => {
    const url = new URL(location.href); url.search = '';
    Object.entries(config.search).forEach(([key, value]) => { if (value) url.searchParams.set(key, value); });
    Object.entries(values).forEach(([key, value]) => { if (Array.isArray(value)) value.forEach(item => url.searchParams.append(`${key}[]`, item)); else if (value) url.searchParams.set(key, value); });
    if (filterForm.elements.archivados.checked) url.searchParams.set('archivados', '1');
    if (page > 1) url.searchParams.set('pagina', page);
    window.history.replaceState(null, '', url);
    const state = root.querySelector('[data-inventory-search-state]'); state.replaceChildren();
    [...Object.entries(values), ['archivados', filterForm.elements.archivados.checked ? '1' : '']].forEach(([key, value]) => {
      const items = Array.isArray(value) ? value : value ? [value] : [];
      items.forEach(item => { const input = node('input'); input.type = 'hidden'; input.name = Array.isArray(value) ? `${key}[]` : key; input.value = item; state.append(input); });
    });
    root.querySelectorAll('[data-inventory-source]').forEach(link => {
      const source = new URL(url); source.searchParams.delete('pagina'); source.searchParams.delete('origen_registro');
      if (link.dataset.inventorySource) source.searchParams.set('origen_registro', link.dataset.inventorySource);
      link.href = source.toString();
    });
    const clear = new URL(url); ['buscar', 'desde', 'hasta', 'pagina'].forEach(key => clear.searchParams.delete(key));
    root.querySelector('[data-inventory-clear-search]').href = clear.toString();
  };
  const addText = (parent, tag, text, cls) => { const child = node(tag, text, cls); parent.append(child); return child; };
  const appendRow = row => {
    const tr = node('tr'); tr.dataset.inventoryRow = row.id;
    const cells = Array.from({ length: 8 }, () => { const td = node('td'); tr.append(td); return td; });
    addText(cells[0], 'span', row.tipo_origen, 'enc-inventory-source');
    const control = addText(cells[0], 'strong');
    const controlUrl = row.tipo_origen === 'KUPOS' && row.ultimo_lote_id ? `importaciones.php?id=${Number(row.ultimo_lote_id)}`
      : row.legacy_guia_id ? `detalle.php?id=${Number(row.legacy_guia_id)}` : '';
    if (controlUrl) addText(control, 'a', row.codigo_control || '-').href = controlUrl; else control.textContent = row.codigo_control || '-';
    addText(cells[0], 'small', valueText(row.fecha, 'fecha'));
    addText(cells[1], 'strong', row.codigo_envio || row.doc_fiscal || '-');
    addText(cells[1], 'small', row.codigo_envio ? row.doc_fiscal || 'Sin documento fiscal' : row.guia_remision || '');
    cells[2].append(document.createTextNode(row.remitente || '-')); addText(cells[2], 'small', `Para: ${row.destinatario || '-'}`);
    if (row.articulos !== null) addText(cells[2], 'small', `${row.articulos} artículo(s)`);
    cells[3].append(document.createTextNode(`${row.origen || '-'} - ${row.destino || '-'}`));
    addText(cells[3], 'small', row.oficina_destino || '-'); if (row.sucursal) addText(cells[3], 'small', `Sucursal: ${row.sucursal}`);
    cells[4].append(document.createTextNode(row.seguimiento || '-')); addText(cells[4], 'small', row.estatus || '');
    if (!Number(row.activo)) addText(cells[4], 'small', 'Retirado del manifiesto');
    addText(cells[5], 'small', row.tipo_origen === 'KUPOS' ? 'Total' : 'Importe cobrado');
    const amount = row.tipo_origen === 'KUPOS' ? row.total : row.importe_cobrado;
    addText(cells[5], 'strong', amount === null || amount === '' ? '-' : `S/ ${Number(amount).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`);
    addText(cells[5], 'small', row.estado_pago || '-'); addText(cells[5], 'small', row.tipo_pago || '');
    addText(cells[6], 'span', row.revision_estado, `enc-state ${row.revision_estado === 'REZAGADO' ? 'enc-state--danger' : 'enc-state--muted'}`);
    const actions = addText(cells[7], 'div', undefined, 'enc-inventory-actions');
    [['info', 'Información', 'bi-info-circle'], ['history', 'Historial', 'bi-clock-history']].forEach(([key, label, icon]) => {
      const button = addText(actions, 'button', undefined, 'stock-btn stock-btn--soft stock-btn--sm'); button.type = 'button'; button.setAttribute(`data-inventory-${key}`, row.id);
      const i = addText(button, 'i', undefined, `bi ${icon}`); i.setAttribute('aria-hidden', 'true'); button.append(document.createTextNode(` ${label}`));
    });
    if (row.legacy_documento_id) addText(actions, 'a', 'Revisar PDF', 'stock-btn stock-btn--soft stock-btn--sm').href = `revision_manifiesto.php?documento=${Number(row.legacy_documento_id)}`;
    return tr;
  };
  const renderPagers = () => {
    const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    pagers.forEach((pager, index) => {
      pager.replaceChildren();
      const first = filtered.length ? (page - 1) * pageSize + 1 : 0;
      addText(pager, 'p', `${numberText(first)}–${numberText(Math.min(page * pageSize, filtered.length))} de ${numberText(filtered.length)} envíos`, 'enc-pager__count');
      const nav = addText(pager, 'nav'); nav.setAttribute('aria-label', index ? 'Páginas inferiores del inventario' : 'Páginas del inventario');
      const button = (label, target, disabled, current = false) => {
        const b = addText(nav, 'button', label); b.type = 'button'; b.disabled = disabled;
        if (current) b.setAttribute('aria-current', 'page');
        b.setAttribute('aria-label', /^\d+$/.test(label) ? `Ir a página ${target}` : label);
        b.addEventListener('click', () => { page = target; render(); pagers[index].querySelector('[aria-current=page]')?.focus({ preventScroll: true }); });
      };
      button('Anterior', page - 1, page === 1);
      const numbers = [...new Set([1, pages, page - 1, page, page + 1])].filter(n => n >= 1 && n <= pages).sort((a, b) => a - b);
      let last = 0; numbers.forEach(n => { if (last && n > last + 1) addText(nav, 'span', '…'); button(String(n), n, n === page, n === page); last = n; });
      button('Siguiente', page + 1, page === pages);
      if (pages > 1) {
        const jump = addText(pager, 'form', undefined, 'enc-pager__jump'); const label = addText(jump, 'label', 'Ir a página ');
        const input = addText(label, 'input'); input.type = 'number'; input.min = '1'; input.max = String(pages); input.value = page; input.required = true; input.setAttribute('aria-label', 'Número de página');
        const go = addText(jump, 'button', 'Ir', 'stock-btn stock-btn--soft stock-btn--sm'); go.type = 'submit';
        jump.addEventListener('submit', event => { event.preventDefault(); page = Math.min(pages, Math.max(1, Number(input.value) || 1)); render(); });
      }
    });
  };
  const render = () => {
    const values = currentFilters();
    const words = normalize(values.texto_pantalla).split(/\s+/).filter(Boolean);
    filtered = records.filter(record => {
      const row = record.row;
      if (!filterForm.elements.archivados.checked && !Number(row.activo)) return false;
      if (values.tipo_envio && record.modality !== values.tipo_envio) return false;
      if (values.revision && row.revision_estado !== values.revision) return false;
      if (!words.every(word => record.screenText.includes(word))) return false;
      if (!Object.keys(multiFields).every(field => !values[field].length || values[field].some(value => value === missing ? row[field] === null || row[field] === '' : String(row[field]) === value))) return false;
      return Object.entries(fieldMap).every(([filter, field]) => !values[filter] || (values[filter] === missing ? row[field] === null || row[field] === '' : String(row[field]) === values[filter]));
    });
    page = Math.min(page, Math.max(1, Math.ceil(filtered.length / pageSize)));
    const counts = { total: filtered.length, articulos: 0, rezagados: 0, kupos: 0 };
    filtered.forEach(({ row }) => { counts.articulos += Number(row.articulos) || 0; counts.rezagados += Number(row.revision_estado === 'REZAGADO'); counts.kupos += Number(row.tipo_origen === 'KUPOS'); });
    Object.entries(counts).forEach(([key, value]) => { root.querySelector(`[data-inventory-kpi=${key}]`).textContent = numberText(value); });
    root.querySelector('[data-inventory-count]').textContent = `${numberText(filtered.length)} de ${numberText(records.length)} envíos cargados`;
    const active = Object.values(values).filter(value => Array.isArray(value) ? value.length > 0 : !!value).length + Number(filterForm.elements.archivados.checked);
    root.querySelector('[data-inventory-filter-count]').textContent = active ? `${active} ${active === 1 ? 'filtro aplicado' : 'filtros aplicados'}` : 'Todos los resultados cargados';
    const fragment = document.createDocumentFragment();
    filtered.slice((page - 1) * pageSize, page * pageSize).forEach(({ row }) => fragment.append(appendRow(row)));
    if (!filtered.length) { const tr = node('tr'); const td = addText(tr, 'td', config.load_error ? 'La búsqueda no pudo completarse. Vuelve a buscar.' : records.length ? 'No hay encomiendas para estos filtros. Puedes limpiarlos sin repetir la búsqueda.' : 'No hay encomiendas para esta búsqueda.', 'enc-inventory-empty'); td.colSpan = 8; fragment.append(tr); }
    tableBody.replaceChildren(fragment); excelButton.disabled = !filtered.length;
    exportStatus.textContent = ''; renderPagers(); syncLocation(values);
  };
  filterForm.addEventListener('submit', event => event.preventDefault());
  filterForm.addEventListener('change', event => { if (!event.target.closest('[data-inventory-multi]')) applyLocalFilters(); });
  screenInput.addEventListener('input', applyLocalFilters);
  root.querySelector('[data-inventory-clear-filters]').addEventListener('click', () => { filterFields.forEach(field => { filterForm.elements[field].value = ''; }); multiPickers.forEach(picker => picker.clear(false)); closeMultiPickers(); screenInput.value = ''; filterForm.elements.archivados.checked = false; page = 1; render(); });
  excelButton.addEventListener('click', () => {
    if (!filtered.length) return;
    try {
      if (!window.XLSX?.utils) throw new Error('No se cargó el generador de Excel. Actualiza la página e intenta nuevamente.');
      const headers = { id: 'ID inventario', codigo_control: 'Control encomienda', ...labels, sedes_vinculadas: 'Sedes vinculadas Norte360' };
      const keys = Object.keys(headers);
      const numeric = new Set(['articulos', 'total', 'importe_cobrado', 'peso']);
      const data = filtered.map(({ row, sedeIds }) => keys.map(key => {
        if (key === 'sedes_vinculadas') return sedeIds.map(id => sedeNames.get(id) || `Sede ${id}`).join(' / ');
        const value = row[key]; if (value === null || value === undefined || value === '') return '';
        if (key === 'activo') return Number(value) ? 'Sí' : 'No';
        // Excel conserva 15 cifras numéricas: los importes mayores se mantienen como texto exacto.
        if (numeric.has(key) && Number.isFinite(Number(value)) && String(value).replace(/[^0-9]/g, '').length <= 15) return Number(value);
        return String(value);
      }));
      const sheet = XLSX.utils.aoa_to_sheet([Object.values(headers), ...data]);
      sheet['!cols'] = keys.map(key => ({ wch: ['remitente', 'destinatario', 'revision_observacion', 'sedes_vinculadas'].includes(key) ? 35 : 22 }));
      sheet['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: data.length, c: keys.length - 1 } }) };
      keys.forEach((key, column) => { if (!['total', 'importe_cobrado', 'peso'].includes(key)) return; for (let r = 1; r <= data.length; r++) { const cell = sheet[XLSX.utils.encode_cell({ r, c: column })]; if (cell?.t === 'n') cell.z = '#,##0.0000'; } });
      const query = [['Consulta', 'Valor'], ['Origen del registro', config.search.origen_registro || 'Todos'], ['Buscar', config.search.buscar || ''], ['Fecha del envío desde', config.search.desde || ''], ['Fecha del envío hasta', config.search.hasta || ''], ['Control seleccionado (ID)', config.search.lote || ''], ['Envíos exportados', filtered.length], ['Resultados cargados', records.length]];
      filterFields.forEach(field => { const select = filterForm.elements[field]; query.push([select.closest('label').querySelector('span').textContent, select.selectedOptions[0]?.textContent || 'Todos']); });
      multiPickers.forEach((picker, field) => query.push([multiFields[field], picker.text()]));
      query.push(['Texto en pantalla', screenInput.value.trim()]);
      query.push(['Incluir retirados de manifiestos', filterForm.elements.archivados.checked ? 'Sí' : 'No']);
      const workbook = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(workbook, sheet, 'Encomiendas');
      const querySheet = XLSX.utils.aoa_to_sheet(query); querySheet['!cols'] = [{ wch: 36 }, { wch: 70 }]; XLSX.utils.book_append_sheet(workbook, querySheet, 'Consulta');
      const date = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
      XLSX.writeFile(workbook, `Inventario_Encomiendas_${date}.xlsx`, { compression: true });
      exportStatus.textContent = `${numberText(filtered.length)} envíos exportados, incluidas todas las páginas.`;
    } catch (error) { exportStatus.textContent = error.message || 'No se pudo generar el Excel.'; }
  });
  render();
  const rememberHistoryView = () => {
    if (activeId && activeMode === 'history' && body.querySelector('[data-history-event]')) expandedHistory.set(activeId, new Set(Array.from(body.querySelectorAll('[data-history-event][open]')).map(details => details.dataset.historyEvent)));
  };
  const show = (id, heading, mode = 'info') => {
    rememberHistoryView();
    activeId = id;
    activeMode = mode;
    title.textContent = heading;
    const row = rows[id] || {};
    element.querySelector('[data-inventory-modal-kicker]').textContent = mode === 'history' ? 'Historial de encomienda' : 'Información de encomienda';
    element.querySelector('[data-inventory-modal-subtitle]').textContent = [row.tipo_origen, row.codigo_control, row.fecha ? valueText(row.fecha, 'fecha') : ''].filter(Boolean).join(' | ');
    element.querySelectorAll('[data-inventory-mode]').forEach(button => { const selected = button.dataset.inventoryMode === mode; button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); });
    body.scrollTop = 0;
    if (window.bootstrap?.Modal) bootstrap.Modal.getOrCreateInstance(element).show();
  };
  const hasValue = value => value !== null && value !== undefined && value !== '';
  const reviewLabel = state => ({ PENDIENTE: 'Pendiente', OK: 'Revisado', REZAGADO: 'Rezagado', OBSERVADO: 'Observado' }[state] || state || 'Sin revisión');
  const disclosure = (text, cls = '') => {
    const details = node('details', undefined, `enc-info-disclosure ${cls}`);
    const summary = addText(details, 'summary', text); addText(summary, 'i', undefined, 'bi bi-chevron-down').setAttribute('aria-hidden', 'true');
    return details;
  };
  const information = id => {
    const row = rows[id];
    if (!row) return;
    show(id, row.codigo_envio || row.doc_fiscal || `Encomienda ${id}`);
    body.replaceChildren();
    const used = new Set(['codigo_envio', 'fecha', 'tipo_origen', 'revision_estado']);
    const badges = node('div', undefined, 'enc-shipment-badges');
    if (hasValue(row.seguimiento)) { addText(badges, 'span', row.seguimiento, 'enc-shipment-badge'); used.add('seguimiento'); }
    if (hasValue(row.estatus)) { addText(badges, 'span', `Estado: ${row.estatus}`, 'enc-shipment-badge enc-shipment-badge--review'); used.add('estatus'); }
    addText(badges, 'span', `Revisión: ${reviewLabel(row.revision_estado)}`, 'enc-shipment-badge enc-shipment-badge--review');
    if (!Number(row.activo)) addText(badges, 'span', 'Retirado del manifiesto', 'enc-shipment-badge enc-shipment-badge--warning');
    body.append(badges);
    const people = node('dl', undefined, 'enc-shipment-people');
    [['remitente', 'Remitente'], ['destinatario', 'Destinatario']].forEach(([field, label]) => {
      const entry = addText(people, 'div'); entry.append(node('dt', label), node('dd', row[field] || 'No informado')); used.add(field);
    });
    body.append(people);
    const route = node('div', undefined, 'enc-shipment-route');
    const origin = addText(route, 'div'); addText(origin, 'small', 'Origen'); addText(origin, 'strong', row.origen || 'No informado');
    if (hasValue(row.sucursal)) addText(origin, 'span', row.sucursal);
    addText(route, 'i', undefined, 'bi bi-arrow-right').setAttribute('aria-hidden', 'true');
    const destination = addText(route, 'div'); addText(destination, 'small', 'Destino'); addText(destination, 'strong', row.destino || 'No informado');
    if (hasValue(row.oficina_destino)) addText(destination, 'span', row.oficina_destino);
    ['origen', 'destino', 'sucursal', 'oficina_destino'].forEach(field => used.add(field)); body.append(route);
    const facts = node('dl', undefined, 'enc-shipment-facts');
    const fact = (label, value, secondary) => {
      const entry = addText(facts, 'div'); entry.append(node('dt', label), node('dd', value));
      if (hasValue(secondary)) addText(entry, 'small', secondary);
    };
    fact('Artículos', hasValue(row.articulos) ? String(row.articulos) : 'No informado'); used.add('articulos');
    const amount = row.tipo_origen === 'KUPOS' ? 'total' : 'importe_cobrado';
    fact(amount === 'total' ? 'Total' : 'Importe cobrado', hasValue(row[amount]) ? valueText(row[amount], amount) : 'No informado'); used.add(amount);
    fact('Pago', row.estado_pago || row.tipo_pago || 'No informado', row.estado_pago ? row.tipo_pago : ''); used.add('estado_pago'); used.add('tipo_pago');
    fact('Documento', row.doc_fiscal || 'Sin documento fiscal', row.documento_fiscal); used.add('doc_fiscal'); used.add('documento_fiscal');
    body.append(facts);
    if (hasValue(row.revision_observacion)) {
      const note = node('section', undefined, 'enc-shipment-note'); note.append(node('strong', 'Observación Norte360'), node('p', row.revision_observacion)); body.append(note); used.add('revision_observacion');
    }
    const extra = Object.entries(labels).filter(([field]) => !used.has(field) && hasValue(row[field]));
    if (extra.length) {
      const more = disclosure('Más datos'); const grid = addText(more, 'dl', undefined, 'enc-shipment-extra');
      extra.forEach(([field, label]) => { const entry = addText(grid, 'div'); entry.append(node('dt', label), node('dd', valueText(row[field], field))); });
      body.append(more);
    }
    if (row.tipo_origen === 'KUPOS') {
      const review = disclosure('Revisar encomienda', 'enc-shipment-review');
      const form = node('form', undefined, 'enc-review-local');
      const stateLabel = node('label', undefined, 'stock-field');
      stateLabel.append(node('span', 'Revisión Norte360'));
      const select = node('select'); select.name = 'estado';
      ['PENDIENTE', 'OK', 'REZAGADO', 'OBSERVADO'].forEach(state => {
        const option = node('option', reviewLabel(state)); option.value = state; option.selected = row.revision_estado === state; select.append(option);
      });
      stateLabel.append(select);
      const observationLabel = node('label', undefined, 'stock-field');
      observationLabel.append(node('span', 'Observación Norte360'));
      const observation = node('textarea'); observation.name = 'observacion'; observation.maxLength = 2000; observation.rows = 3;
      const draft = reviewDrafts.get(id);
      if (draft) select.value = draft.estado;
      observation.value = draft ? draft.observacion : row.revision_observacion || ''; observationLabel.append(observation);
      const save = node('button', 'Guardar revisión', 'stock-btn stock-btn--primary'); save.type = 'submit';
      const status = node('p'); status.setAttribute('role', 'status');
      if (draft) { status.textContent = 'Hay cambios sin guardar.'; review.open = true; }
      form.append(stateLabel, observationLabel, save, status);
      const rememberDraft = () => {
        const changed = select.value !== row.revision_estado || observation.value !== (row.revision_observacion || '');
        if (changed) reviewDrafts.set(id, { estado: select.value, observacion: observation.value }); else reviewDrafts.delete(id);
        status.textContent = changed ? 'Hay cambios sin guardar.' : '';
      };
      form.addEventListener('input', rememberDraft); form.addEventListener('change', rememberDraft);
      form.addEventListener('submit', async event => {
        event.preventDefault(); save.disabled = true;
        const data = new FormData(form);
        data.append('id', id); data.append('version', row.version); data.append('csrf_token', root.dataset.csrf);
        try {
          const response = await fetch('actions/inventario.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
          const result = await response.json();
          if (!result.ok) throw new Error(result.message || 'No se pudo guardar.');
          window.location.reload();
        } catch (error) { status.textContent = error.message; save.disabled = false; }
      });
      review.append(form); body.append(review);
    }
  };
  const renderHistory = result => {
    const expanded = body.querySelector('[data-history-event]') ? new Set(Array.from(body.querySelectorAll('[data-history-event][open]')).map(detail => detail.dataset.historyEvent)) : expandedHistory.get(activeId) || new Set();
    expandedHistory.set(activeId, expanded);
    body.replaceChildren();
    if (!result.historial.length) { body.append(node('p', 'Todavía no hay cambios registrados para esta encomienda.', 'enc-history-empty')); return; }
    addText(body, 'p', `${result.historial.length} ${result.historial.length === 1 ? 'evento' : 'eventos'}${result.hay_mas ? ' cargados' : ''}`, 'enc-history-count');
    const names = { ALTA: 'Encomienda registrada', INCORPORACION: 'Registro incorporado', CAMBIO: 'Datos actualizados', REVISION: 'Revisión actualizada', ARCHIVADO: 'Retirada del manifiesto', PDF_REEMPLAZADO: 'Manifiesto reemplazado' };
    result.historial.forEach(event => {
      const entry = node('article', undefined, 'enc-history-event');
      const heading = addText(entry, 'header'); addText(heading, 'strong', names[event.accion] || String(event.accion || 'Evento').replaceAll('_', ' '));
      const date = String(event.fecha || '').replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3/$2/$1'); const time = addText(heading, 'time', date); time.dateTime = String(event.fecha || '').replace(' ', 'T');
      addText(entry, 'p', [event.usuario || 'Usuario', event.codigo_control].filter(Boolean).join(' | '), 'enc-history-meta');
      const changes = Object.entries(event.cambios || {}).filter(([field]) => labels[field]);
      const labelFor = field => field === 'total' && event.importe_heredado ? 'Importe cobrado del manifiesto' : labels[field];
      const initial = ['ALTA', 'INCORPORACION'].includes(event.accion);
      addText(entry, 'p', initial ? 'Datos iniciales de la encomienda.' : changes.length ? `Cambios en ${changes.slice(0, 3).map(([field]) => labelFor(field)).join(', ')}${changes.length > 3 ? ` y ${changes.length - 3} más` : ''}.` : 'Evento conservado en el historial.', 'enc-history-description');
      if (changes.length) {
        const details = disclosure(`${initial ? 'Ver datos registrados' : 'Ver cambios'} (${changes.length})`, 'enc-history-details'); details.dataset.historyEvent = event.id;
        const buildDetails = () => {
          if (details.dataset.rendered) return;
          details.dataset.rendered = '1';
          const table = addText(details, 'table', undefined, `enc-history-table${initial ? ' is-initial' : ''}`);
          const head = addText(addText(table, 'thead'), 'tr');
          (initial ? ['Dato', 'Valor registrado'] : ['Dato', 'Antes', 'Después']).forEach(label => { addText(head, 'th', label).scope = 'col'; });
          const tbody = addText(table, 'tbody');
          changes.forEach(([field, change]) => { const tr = addText(tbody, 'tr'); addText(tr, 'th', labelFor(field)).scope = 'row'; if (!initial) addText(tr, 'td', valueText(change.antes, field)); addText(tr, 'td', valueText(change.despues, field)); });
        };
        details.addEventListener('toggle', () => { if (details.open) buildDetails(); });
        details.open = expanded.has(String(event.id)); if (details.open) buildDetails(); entry.append(details);
      }
      body.append(entry);
    });
    if (result.hay_mas) {
      const more = node('button', 'Cargar eventos anteriores', 'stock-btn stock-btn--soft');
      more.type = 'button';
      more.dataset.historyMore = '1';
      const key = `${activeId}:${result.siguiente}`; more.disabled = olderHistoryRequests.has(key);
      more.addEventListener('click', async () => {
        const id = activeId; more.disabled = true;
        try {
          let request = olderHistoryRequests.get(key);
          if (!request) {
            request = (async () => { const response = await fetch(`actions/inventario.php?id=${id}&antes_de=${result.siguiente}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }); const data = await response.json(); if (!data.ok) throw new Error(data.message || 'No se pudo consultar.'); return data; })();
            olderHistoryRequests.set(key, request);
          }
          const older = await request;
          const base = historyCache.get(id) || result;
          const combined = { ...older, historial: [...new Map([...base.historial, ...older.historial].map(event => [String(event.id), event])).values()] };
          historyCache.set(id, combined);
          if (activeId === id && activeMode === 'history') { const scroll = body.scrollTop; renderHistory(combined); body.scrollTop = scroll; body.querySelector('[data-history-more]')?.focus({ preventScroll: true }); }
        } catch (error) { if (activeId === id && activeMode === 'history') { const button = body.querySelector('[data-history-more]'); if (button) { button.disabled = false; button.textContent = 'Reintentar cargar eventos anteriores'; } } }
        finally { olderHistoryRequests.delete(key); }
      });
      body.append(more);
    }
  };
  const history = async id => {
    show(id, rows[id]?.codigo_envio || rows[id]?.doc_fiscal || `Encomienda ${id}`, 'history');
    body.replaceChildren(node('p', 'Consultando historial...', 'enc-history-empty'));
    if (historyCache.has(id)) { renderHistory(historyCache.get(id)); return; }
    let request = historyRequests.get(id);
    if (!request) {
      request = (async () => {
        const response = await fetch(`actions/inventario.php?id=${id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'No se pudo consultar.');
        historyCache.set(id, result);
        return result;
      })();
      historyRequests.set(id, request);
    }
    try {
      const result = await request;
      if (activeId === id && activeMode === 'history') renderHistory(result);
    } catch (error) {
      if (activeId === id && activeMode === 'history') {
        body.replaceChildren(node('p', 'No se pudo consultar el historial. Puedes volver a intentarlo.', 'enc-history-empty'));
        const retry = node('button', 'Reintentar', 'stock-btn stock-btn--soft'); retry.type = 'button'; retry.addEventListener('click', () => history(id)); body.append(retry);
      }
    }
    finally { if (historyRequests.get(id) === request) historyRequests.delete(id); }
  };
  root.addEventListener('click', event => {
    const info = event.target.closest('[data-inventory-info]');
    const historyButton = event.target.closest('[data-inventory-history]');
    if (info) { modalOpener = info; information(Number(info.dataset.inventoryInfo)); }
    else if (historyButton) { modalOpener = historyButton; history(Number(historyButton.dataset.inventoryHistory)); }
  });
  element.querySelectorAll('[data-inventory-mode]').forEach(button => button.addEventListener('click', () => {
    if (!activeId || button.dataset.inventoryMode === activeMode) return;
    if (button.dataset.inventoryMode === 'history') history(activeId); else information(activeId);
  }));
  element.addEventListener('hidden.bs.modal', () => { rememberHistoryView(); activeId = 0; activeMode = ''; if (modalOpener?.isConnected) modalOpener.focus({ preventScroll: true }); modalOpener = null; });
  root.dataset.inventoryReady = '1';
  } catch (error) {
    const message = 'No se pudo cargar la vista del inventario. Vuelve a pulsar Buscar para intentarlo de nuevo.';
    const tbody = root.querySelector('.enc-inventory-table tbody');
    if (tbody) {
      const tr = document.createElement('tr'); const td = document.createElement('td');
      td.colSpan = 8; td.className = 'enc-inventory-empty'; td.textContent = message; tr.append(td); tbody.replaceChildren(tr);
    }
    root.querySelectorAll('[data-inventory-kpi]').forEach(kpi => { kpi.textContent = '-'; });
    const count = root.querySelector('[data-inventory-count]'); if (count) count.textContent = 'No se pudo cargar';
    const button = root.querySelector('[data-inventory-excel]'); if (button) button.disabled = true;
    const status = root.querySelector('[data-inventory-export-status]'); if (status) { status.setAttribute('role', 'alert'); status.textContent = message; }
    console.error('No se pudo inicializar Inventario de encomiendas.', error.name);
  }
})();
