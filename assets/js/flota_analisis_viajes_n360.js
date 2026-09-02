(function () {
  const rows = Array.from(document.querySelectorAll('[data-fav-row]'));
  const textFilter = document.querySelector('[data-fav-filter-text]');
  const selects = Array.from(document.querySelectorAll('[data-fav-filter]'));
  const clearFilters = document.querySelector('[data-fav-clear-filters]');
  const visibleLabel = document.querySelector('[data-fav-visible-label]');
  const busSearch = document.querySelector('[data-fav-bus-search]');
  const busClear = document.querySelector('[data-fav-bus-clear]');
  const busCount = document.querySelector('[data-fav-bus-count]');
  const busPicker = document.querySelector('.fav-bus-picker');
  const busToggle = document.querySelector('[data-fav-bus-toggle]');
  const filterToggle = document.querySelector('[data-fav-toggle-filters]');
  const filterToggleLabel = document.querySelector('[data-fav-toggle-filters-label]');
  const filterBody = document.querySelector('[data-fav-filter-body]');
  const visualTotal = document.querySelector('[data-fav-visual-total]');
  const topUnits = document.querySelector('[data-fav-top-units]');
  const moneyBars = document.querySelector('[data-fav-money-bars]');
  const charts = {
    daily: document.querySelector('[data-fav-chart="daily"]'),
    states: document.querySelector('[data-fav-chart="states"]'),
    directions: document.querySelector('[data-fav-chart="directions"]'),
    routes: document.querySelector('[data-fav-chart="routes"]')
  };
  const legends = {
    states: document.querySelector('[data-fav-legend="states"]'),
    directions: document.querySelector('[data-fav-legend="directions"]')
  };
  const palette = ['#1f96db', '#149267', '#d99b17', '#c43838', '#4c63b6', '#16a3b8', '#8b5cf6', '#64748b'];
  const stateColors = {
    VALIDADO: '#149267',
    PENDIENTE: '#d99b17',
    OBSERVADO: '#d66b3d',
    CORREGIDO: '#16a3b8',
    ANULADO: '#c43838',
    MANUAL: '#4c63b6',
    TRANSBORDADO: '#0f766e',
    TRANSBORDO: '#2563eb'
  };
  const directionColors = {
    IDA: '#149267',
    RETORNO: '#4c63b6',
    PENDIENTE: '#d99b17'
  };

  const normalize = value => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const money = value => {
    const number = Number(String(value || '0').replace(/[^\d.-]/g, ''));
    return Number.isFinite(number) ? number : 0;
  };

  const moneyText = value => {
    const number = money(value);
    const sign = number < 0 ? '-' : '';
    return `${sign}S/ ${Math.abs(number).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    })}`;
  };

  const uniqueSorted = values => Array.from(new Set(values.filter(Boolean)))
    .sort((a, b) => a.localeCompare(b, 'es', { numeric: true, sensitivity: 'base' }));

  function drivers(row) {
    return String(row.dataset.favConductores || '')
      .split('|')
      .map(item => item.trim())
      .filter(Boolean);
  }

  function fillSelect(name, values) {
    const select = document.querySelector(`[data-fav-filter="${name}"]`);
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">Todos</option>' + uniqueSorted(values)
      .map(value => `<option value="${escapeAttr(value)}">${escapeHtml(value)}</option>`)
      .join('');
    select.value = Array.from(select.options).some(option => option.value === current) ? current : '';
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, char => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[char]));
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
  }

  function labelDate(value) {
    const parts = String(value || '').split('-');
    if (parts.length !== 3) return value || '-';
    return `${parts[2]}/${parts[1]}`;
  }

  function textEllipsis(ctx, text, maxWidth) {
    text = String(text || '-');
    if (ctx.measureText(text).width <= maxWidth) return text;
    let out = text;
    while (out.length > 3 && ctx.measureText(`${out}...`).width > maxWidth) {
      out = out.slice(0, -1);
    }
    return `${out}...`;
  }

  function countBy(items, getter) {
    const map = new Map();
    items.forEach(item => {
      const key = String(getter(item) || '').trim() || 'SIN DATO';
      map.set(key, (map.get(key) || 0) + 1);
    });
    return Array.from(map.entries())
      .map(([label, value]) => ({ label, value }))
      .sort((a, b) => b.value - a.value || a.label.localeCompare(b.label, 'es', { numeric: true }));
  }

  function canvasContext(canvas, minHeight = 230) {
    if (!canvas) return null;
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(260, Math.floor(rect.width || canvas.parentElement?.clientWidth || 320));
    const height = Math.max(minHeight, Math.floor(rect.height || minHeight));
    const ratio = window.devicePixelRatio || 1;
    if (canvas.width !== Math.floor(width * ratio) || canvas.height !== Math.floor(height * ratio)) {
      canvas.width = Math.floor(width * ratio);
      canvas.height = Math.floor(height * ratio);
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    return { ctx, width, height };
  }

  function drawEmpty(canvas, message = 'Sin datos') {
    const state = canvasContext(canvas);
    if (!state) return;
    const { ctx, width, height } = state;
    ctx.fillStyle = '#f5f9fd';
    ctx.fillRect(0, 0, width, height);
    ctx.fillStyle = '#60758a';
    ctx.font = '800 13px Inter, Segoe UI, Arial';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(message, width / 2, height / 2);
  }

  function drawDailyChart(rowsVisible) {
    const canvas = charts.daily;
    if (!canvas) return;
    const entries = countBy(rowsVisible, row => row.dataset.favFecha)
      .sort((a, b) => a.label.localeCompare(b.label));
    if (!entries.length) {
      drawEmpty(canvas);
      return;
    }
    const state = canvasContext(canvas, 250);
    if (!state) return;
    const { ctx, width, height } = state;
    const pad = { top: 18, right: 14, bottom: 34, left: 34 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;
    const max = Math.max(...entries.map(item => item.value), 1);
    const step = Math.max(1, Math.ceil(entries.length / 8));

    ctx.strokeStyle = '#d8e6f2';
    ctx.lineWidth = 1;
    ctx.font = '700 11px Inter, Segoe UI, Arial';
    ctx.fillStyle = '#6b7f93';
    ctx.textAlign = 'right';
    for (let i = 0; i <= 4; i += 1) {
      const y = pad.top + innerH - (innerH * i / 4);
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(width - pad.right, y);
      ctx.stroke();
      ctx.fillText(String(Math.round(max * i / 4)), pad.left - 7, y + 4);
    }

    const slot = innerW / entries.length;
    const barW = Math.max(5, Math.min(30, slot * .62));
    entries.forEach((item, idx) => {
      const h = innerH * item.value / max;
      const x = pad.left + slot * idx + (slot - barW) / 2;
      const y = pad.top + innerH - h;
      const gradient = ctx.createLinearGradient(0, y, 0, pad.top + innerH);
      gradient.addColorStop(0, '#1f96db');
      gradient.addColorStop(1, '#16a3b8');
      ctx.fillStyle = gradient;
      ctx.beginPath();
      ctx.roundRect(x, y, barW, h, 5);
      ctx.fill();
      if (idx % step === 0 || entries.length <= 8) {
        ctx.fillStyle = '#425b74';
        ctx.textAlign = 'center';
        ctx.font = '800 10px Inter, Segoe UI, Arial';
        ctx.fillText(labelDate(item.label), x + barW / 2, height - 12);
      }
    });
  }

  function renderLegend(container, entries, colors, filterType) {
    if (!container) return;
    container.innerHTML = entries.map((item, idx) => {
      const color = colors[item.label] || palette[idx % palette.length];
      return `<button type="button" class="fav-legend-item" data-fav-legend-filter="${filterType}" data-fav-legend-value="${escapeAttr(item.label)}">
        <i style="--fav-dot:${escapeAttr(color)}"></i>
        <span>${escapeHtml(item.label)}</span>
        <strong>${item.value.toLocaleString('es-PE')}</strong>
      </button>`;
    }).join('') || '<span class="fav-legend-empty">Sin datos</span>';
  }

  function drawDonut(canvas, entries, colors, centerText) {
    if (!entries.length) {
      drawEmpty(canvas);
      return;
    }
    const state = canvasContext(canvas, 230);
    if (!state) return;
    const { ctx, width, height } = state;
    const total = entries.reduce((sum, item) => sum + item.value, 0);
    const cx = width / 2;
    const cy = height / 2;
    const radius = Math.min(width, height) * .34;
    const lineWidth = Math.max(22, radius * .34);
    let start = -Math.PI / 2;

    entries.forEach((item, idx) => {
      const angle = total > 0 ? (Math.PI * 2 * item.value / total) : 0;
      ctx.beginPath();
      ctx.strokeStyle = colors[item.label] || palette[idx % palette.length];
      ctx.lineWidth = lineWidth;
      ctx.arc(cx, cy, radius, start, start + angle);
      ctx.stroke();
      start += angle;
    });

    ctx.fillStyle = '#061b32';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = '900 28px Inter, Segoe UI, Arial';
    ctx.fillText(total.toLocaleString('es-PE'), cx, cy - 5);
    ctx.fillStyle = '#60758a';
    ctx.font = '800 11px Inter, Segoe UI, Arial';
    ctx.fillText(centerText, cx, cy + 22);
  }

  function drawRoutesChart(rowsVisible) {
    const canvas = charts.routes;
    if (!canvas) return;
    const entries = countBy(rowsVisible, row => row.dataset.favRutaLabel || `${row.dataset.favOrigen || '-'} -> ${row.dataset.favDestino || '-'}`).slice(0, 8);
    if (!entries.length) {
      drawEmpty(canvas);
      return;
    }
    const state = canvasContext(canvas, 270);
    if (!state) return;
    const { ctx, width, height } = state;
    const pad = { top: 14, right: 44, bottom: 16, left: 180 };
    const rowH = Math.min(28, (height - pad.top - pad.bottom) / entries.length);
    const max = Math.max(...entries.map(item => item.value), 1);
    const barW = Math.max(80, width - pad.left - pad.right);

    ctx.font = '800 11px Inter, Segoe UI, Arial';
    entries.forEach((item, idx) => {
      const y = pad.top + idx * rowH + 5;
      const w = Math.max(6, barW * item.value / max);
      ctx.fillStyle = '#425b74';
      ctx.textAlign = 'right';
      ctx.fillText(textEllipsis(ctx, item.label, pad.left - 14), pad.left - 12, y + 13);
      ctx.fillStyle = '#edf6fd';
      ctx.beginPath();
      ctx.roundRect(pad.left, y, barW, 15, 8);
      ctx.fill();
      ctx.fillStyle = palette[idx % palette.length];
      ctx.beginPath();
      ctx.roundRect(pad.left, y, w, 15, 8);
      ctx.fill();
      ctx.fillStyle = '#061b32';
      ctx.textAlign = 'left';
      ctx.fillText(item.value.toLocaleString('es-PE'), pad.left + w + 8, y + 13);
    });
  }

  function renderTopUnits(rowsVisible) {
    if (!topUnits) return;
    const entries = countBy(rowsVisible, row => row.dataset.favBus || row.dataset.favPlaca || 'SIN BUS').slice(0, 7);
    if (!entries.length) {
      topUnits.innerHTML = '<div class="fav-visual-empty">Sin datos visibles</div>';
      return;
    }
    const max = Math.max(...entries.map(item => item.value), 1);
    topUnits.innerHTML = entries.map((item, idx) => {
      const pct = Math.round(item.value * 100 / max);
      return `<div class="fav-top-row">
        <span>${String(idx + 1).padStart(2, '0')}</span>
        <div>
          <strong>${escapeHtml(item.label)}</strong>
          <i><b style="width:${pct}%"></b></i>
        </div>
        <em>${item.value.toLocaleString('es-PE')}</em>
      </div>`;
    }).join('');
  }

  function renderMoneyBars(metrics) {
    if (!moneyBars) return;
    const items = [
      { label: 'Total viaje', value: metrics.total, color: '#149267' },
      { label: 'Pago conductores', value: metrics.condTotal, color: '#1f96db' },
      { label: 'Diferencia', value: metrics.total - metrics.condTotal, color: Math.abs(metrics.total - metrics.condTotal) > .009 ? '#c43838' : '#149267' }
    ];
    const max = Math.max(...items.map(item => Math.abs(item.value)), 1);
    moneyBars.innerHTML = items.map(item => {
      const pct = Math.max(3, Math.round(Math.abs(item.value) * 100 / max));
      return `<article class="fav-money-bar">
        <div><span>${escapeHtml(item.label)}</span><strong>${moneyText(item.value)}</strong></div>
        <i><b style="width:${pct}%;--fav-bar:${escapeAttr(item.color)}"></b></i>
      </article>`;
    }).join('');
  }

  function renderVisuals(visibleRows, metrics) {
    if (visualTotal) visualTotal.textContent = visibleRows.length.toLocaleString('es-PE');
    const states = countBy(visibleRows, row => row.dataset.favEstado || 'PENDIENTE');
    const directions = countBy(visibleRows, row => row.dataset.favIda || 'PENDIENTE');
    drawDailyChart(visibleRows);
    drawDonut(charts.states, states, stateColors, 'viajes');
    drawDonut(charts.directions, directions, directionColors, 'viajes');
    drawRoutesChart(visibleRows);
    renderLegend(legends.states, states, stateColors, 'estado');
    renderLegend(legends.directions, directions, directionColors, 'ida');
    renderTopUnits(visibleRows);
    renderMoneyBars(metrics);
  }

  function hydrateFilters() {
    fillSelect('estado', rows.map(row => row.dataset.favEstado || 'PENDIENTE'));
    fillSelect('ida', rows.map(row => row.dataset.favIda || 'PENDIENTE'));
    fillSelect('origen', rows.map(row => row.dataset.favOrigen || ''));
    fillSelect('destino', rows.map(row => row.dataset.favDestino || ''));
    fillSelect('conductor', rows.flatMap(drivers));
  }

  function paymentState(row) {
    const driverList = drivers(row);
    if (!driverList.length || row.dataset.favIda === 'RETORNO' || row.dataset.favEstado === 'ANULADO') {
      return 'neutro';
    }
    const states = [
      row.dataset.favCond1Estado || '',
      row.dataset.favCond2Estado || ''
    ].slice(0, driverList.length);
    return states.every(state => state === 'PAGADO') ? 'ok' : 'pendiente';
  }

  function matches(row) {
    const query = normalize(textFilter?.value || '');
    if (query && !normalize(row.dataset.favSearch || '').includes(query)) return false;

    for (const select of selects) {
      const value = select.value;
      if (!value) continue;
      const type = select.dataset.favFilter;

      if (type === 'estado' && (row.dataset.favEstado || '') !== value) return false;
      if (type === 'ida' && (row.dataset.favIda || '') !== value) return false;
      if (type === 'origen' && (row.dataset.favOrigen || '') !== value) return false;
      if (type === 'destino' && (row.dataset.favDestino || '') !== value) return false;
      if (type === 'conductor' && !drivers(row).includes(value)) return false;
      if (type === 'hoja' && value === 'con' && row.dataset.favHoja !== '1') return false;
      if (type === 'hoja' && value === 'sin' && row.dataset.favHoja === '1') return false;
      if (type === 'balance' && value === 'ok' && Math.abs(money(row.dataset.favDiferencia)) > 0.009) return false;
      if (type === 'balance' && value === 'diff' && Math.abs(money(row.dataset.favDiferencia)) <= 0.009) return false;
      if (type === 'pagos' && paymentState(row) !== value) return false;
    }

    return true;
  }

  function setKpi(name, value) {
    const el = document.querySelector(`[data-fav-kpi="${name}"]`);
    if (el) el.textContent = value;
  }

  function recalc(visibleRows) {
    const units = new Set();
    let hojas = 0;
    let retornos = 0;
    let anulados = 0;
    let total = 0;
    let condTotal = 0;

    visibleRows.forEach(row => {
      units.add(row.dataset.favBusId || row.dataset.favBus || row.dataset.favPlaca || row.dataset.favId);
      if (row.dataset.favHoja === '1') hojas += 1;
      if (row.dataset.favIda === 'RETORNO') retornos += 1;
      if (row.dataset.favEstado === 'ANULADO') anulados += 1;
      total += money(row.dataset.favTotal);
      condTotal += money(row.dataset.favCondTotal);
    });

    setKpi('viajes', visibleRows.length.toLocaleString('es-PE'));
    setKpi('unidades', units.size.toLocaleString('es-PE'));
    setKpi('hojas', hojas.toLocaleString('es-PE'));
    setKpi('retornos', retornos.toLocaleString('es-PE'));
    setKpi('anulados', anulados.toLocaleString('es-PE'));
    setKpi('total', moneyText(total));
    setKpi('conductores', moneyText(condTotal));
    setKpi('diferencia', moneyText(total - condTotal));
    if (visibleLabel) visibleLabel.textContent = `${visibleRows.length.toLocaleString('es-PE')} visible(s)`;
    renderVisuals(visibleRows, { total, condTotal });
  }

  function applyFilters() {
    const visibleRows = [];
    rows.forEach(row => {
      const ok = matches(row);
      row.hidden = !ok;
      if (ok) visibleRows.push(row);
    });
    recalc(visibleRows);
  }

  function closeBusPicker() {
    if (!busPicker) return;
    busPicker.classList.remove('is-open');
    busToggle?.setAttribute('aria-expanded', 'false');
  }

  function toggleBusPicker() {
    if (!busPicker) return;
    const open = !busPicker.classList.contains('is-open');
    busPicker.classList.toggle('is-open', open);
    busToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) setTimeout(() => busSearch?.focus(), 50);
  }

  function toggleFilterBody() {
    if (!filterBody) return;
    const collapsed = !filterBody.classList.contains('is-collapsed');
    filterBody.classList.toggle('is-collapsed', collapsed);
    filterToggle?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    if (filterToggleLabel) filterToggleLabel.textContent = collapsed ? 'Mostrar filtros' : 'Ocultar filtros';
  }

  function syncBusCount() {
    const checked = document.querySelectorAll('[data-fav-bus-list] input[type="checkbox"]:checked').length;
    if (!busCount) return;
    busCount.textContent = checked ? `${checked} unidad(es)` : 'Todos los buses';
  }

  function filterBusOptions() {
    const query = normalize(busSearch?.value || '');
    document.querySelectorAll('[data-fav-bus-option]').forEach(option => {
      option.classList.toggle('is-hidden', query !== '' && !normalize(option.textContent).includes(query));
    });
  }

  hydrateFilters();
  applyFilters();
  syncBusCount();

  textFilter?.addEventListener('input', applyFilters);
  selects.forEach(select => select.addEventListener('change', applyFilters));
  busToggle?.addEventListener('click', toggleBusPicker);
  filterToggle?.addEventListener('click', toggleFilterBody);
  clearFilters?.addEventListener('click', () => {
    if (textFilter) textFilter.value = '';
    selects.forEach(select => { select.value = ''; });
    applyFilters();
  });

  busSearch?.addEventListener('input', filterBusOptions);
  document.querySelectorAll('[data-fav-bus-list] input[type="checkbox"]').forEach(input => {
    input.addEventListener('change', syncBusCount);
  });
  busClear?.addEventListener('click', () => {
    document.querySelectorAll('[data-fav-bus-list] input[type="checkbox"]').forEach(input => {
      input.checked = false;
    });
    syncBusCount();
  });
  document.addEventListener('click', event => {
    const legendButton = event.target.closest('[data-fav-legend-filter]');
    if (!legendButton) return;
    const type = legendButton.dataset.favLegendFilter || '';
    const value = legendButton.dataset.favLegendValue || '';
    const select = document.querySelector(`[data-fav-filter="${type}"]`);
    if (!select) return;
    select.value = select.value === value ? '' : value;
    applyFilters();
  });
  document.addEventListener('click', event => {
    if (busPicker && !busPicker.contains(event.target)) closeBusPicker();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeBusPicker();
  });
  window.addEventListener('resize', () => {
    window.clearTimeout(window.__favResizeTimer);
    window.__favResizeTimer = window.setTimeout(applyFilters, 120);
  });
})();
