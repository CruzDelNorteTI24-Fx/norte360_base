(function () {
  const roots = Array.from(document.querySelectorAll('[data-n360-live-app], [data-n360-live-guide]'));
  if (!roots.length) return;

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const text = (value, fallback = '-') => {
    const clean = String(value ?? '').trim();
    return clean || fallback;
  };

  const pad = (value) => String(value).padStart(2, '0');

  const formatDuration = (minutes) => {
    const abs = Math.max(0, Math.abs(Math.round(minutes)));
    const h = Math.floor(abs / 60);
    const m = abs % 60;
    if (h <= 0) return `${m} min`;
    if (m <= 0) return `${h}h`;
    return `${h}h ${pad(m)}min`;
  };

  const clockLabel = () => {
    const now = new Date();
    return `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  };

  const currentOperationalMinutes = () => {
    const now = new Date();
    let total = (now.getHours() * 60) + now.getMinutes();
    if (now.getHours() < 5) total += 1440;
    return total;
  };

  const rowOperationalMinutes = (row) => {
    const order = Number(row.orden_operativo || 0);
    if (Number.isFinite(order) && order > 0) {
      return order > 2000 ? Math.round(order / 60) : order;
    }

    const raw = String(row.hora_salida || '');
    const match = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!match) return 0;

    const h = Number(match[1]);
    const m = Number(match[2]);
    let total = (h * 60) + m;
    if (h < 5) total += 1440;
    return total;
  };

  const evaluateRow = (row) => {
    const now = currentOperationalMinutes();
    const target = rowOperationalMinutes(row);
    const diff = target - now;

    if (diff >= 0 && diff <= 15) {
      return {
        key: 'proximo',
        label: 'Proximo en ruta',
        detail: diff <= 0 ? 'Salida inmediata' : `Sale en ${formatDuration(diff)}`,
        diff,
        sort: diff,
      };
    }

    if (diff < 0 && diff >= -480) {
      return {
        key: 'ruta',
        label: 'En ruta',
        detail: `Salio hace ${formatDuration(diff)}`,
        diff,
        sort: 1000 + Math.abs(diff),
      };
    }

    if (diff > 15) {
      return {
        key: 'programado',
        label: 'Programado',
        detail: `Sale en ${formatDuration(diff)}`,
        diff,
        sort: 2000 + diff,
      };
    }

    return {
      key: 'fuera',
      label: 'Fuera de ventana',
      detail: `Hace ${formatDuration(diff)}`,
      diff,
      sort: 9000 + Math.abs(diff),
    };
  };

  const routeLabel = (row) => {
    const route = text(row.ruta, '');
    if (route) return route;
    return `${text(row.origen)} -> ${text(row.destino)}`;
  };

  const rowHtml = (row, compact = false) => {
    const live = evaluateRow(row);
    return `
      <article class="n360-live-row is-${esc(live.key)}">
        <div class="n360-live-row__time">
          <small>Hora</small>
          <strong>${esc(text(row.hora_salida, '--:--'))}</strong>
        </div>
        <div class="n360-live-row__main">
          <small>Unidad</small>
          <strong>${esc(text(row.bus, 'SIN ASIGNAR'))}</strong>
          <span>${esc(text(row.origen))} -> ${esc(text(row.destino))}</span>
        </div>
        <div class="n360-live-row__route">
          <small>${compact ? 'Ruta' : 'Recorrido'}</small>
          <strong title="${esc(routeLabel(row))}">${esc(routeLabel(row))}</strong>
          <span>${esc(text(row.origen))} -> ${esc(text(row.destino))}</span>
        </div>
        <div class="n360-live-row__status">
          <span class="n360-live-chip is-${esc(live.key)}">${esc(live.label)}</span>
          <small>${esc(live.detail)}</small>
        </div>
      </article>
    `;
  };

  const viewerHtml = (viewer) => `
    <article class="n360-live-viewer">
      <i class="bi ${viewer.dispositivo === 'Celular' ? 'bi-phone' : viewer.dispositivo === 'Tablet' ? 'bi-tablet' : 'bi-pc-display'}"></i>
      <div>
        <strong>${esc(text(viewer.usuario || viewer.nombre, 'Usuario'))}</strong>
        <span>${esc(text(viewer.dispositivo, 'Dispositivo'))} | IP ${esc(text(viewer.ip))}</span>
        <small>${esc(text(viewer.last_seen_label, 'Activo recientemente'))}</small>
      </div>
    </article>
  `;

  const setBusy = (root, busy) => {
    const btn = root.querySelector('[data-live-refresh]');
    if (btn) btn.disabled = busy;
  };

  const fetchLive = async (endpoint, force = false) => {
    const url = new URL(endpoint, window.location.href);
    url.searchParams.set('action', 'snapshot');
    if (force) url.searchParams.set('refresh', '1');

    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await res.json();
    if (!res.ok || !payload.ok) {
      throw new Error(payload.message || 'No se pudo cargar Norte360 Live.');
    }
    return payload.data || {};
  };

  const postLive = async (endpoint, action) => {
    const body = new URLSearchParams();
    body.set('action', action);
    const res = await fetch(endpoint, {
      method: 'POST',
      body,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      keepalive: action === 'leave',
    });
    if (!res.ok) return null;
    const payload = await res.json().catch(() => null);
    return payload && payload.ok ? payload.data : null;
  };

  const renderViewers = (root, viewers) => {
    const list = root.querySelector('[data-live-viewers]');
    const count = root.querySelector('[data-live-viewer-count]');
    if (count) count.textContent = String((viewers || []).length);
    if (!list) return;
    list.innerHTML = viewers && viewers.length
      ? viewers.map(viewerHtml).join('')
      : '<p class="n360-live-empty">Sin visualizadores activos.</p>';
  };

  const renderFull = (root, state) => {
    const rows = (state.rows || []).map((row) => ({ row, live: evaluateRow(row) }))
      .sort((a, b) => a.live.sort - b.live.sort);
    const total = rows.length;
    const counts = rows.reduce((acc, item) => {
      acc[item.live.key] = (acc[item.live.key] || 0) + 1;
      return acc;
    }, {});

    const summary = root.querySelector('[data-live-summary]');
    const cache = root.querySelector('[data-live-cache]');
    const totalNode = root.querySelector('[data-live-total]');
    const list = root.querySelector('[data-live-list]');
    const next = root.querySelector('[data-live-next]');

    if (summary) summary.textContent = `${total} horarios activos en pizarra`;
    if (totalNode) totalNode.textContent = `${total} horarios`;

    const snapshot = state.snapshot || {};
    if (cache) {
      const cacheMsg = snapshot.cache_hit
        ? `Cache reutilizado hace ${snapshot.cache_age || 0}s. Generado ${snapshot.generated_label || ''}.`
        : `Snapshot generado ${snapshot.generated_label || ''}.`;
      cache.textContent = cacheMsg.trim();
    }

    root.querySelector('[data-live-kpi="programados"]')?.replaceChildren(document.createTextNode(String(counts.programado || 0)));
    root.querySelector('[data-live-kpi="proximos"]')?.replaceChildren(document.createTextNode(String(counts.proximo || 0)));
    root.querySelector('[data-live-kpi="ruta"]')?.replaceChildren(document.createTextNode(String(counts.ruta || 0)));

    if (list) {
      list.innerHTML = rows.length
        ? rows.map((item) => rowHtml(item.row)).join('')
        : '<div class="n360-live-empty">No hay horarios activos para mostrar.</div>';
    }

    if (next) {
      const candidate = rows.find((item) => item.live.key === 'proximo')
        || rows.find((item) => item.live.key === 'programado')
        || rows.find((item) => item.live.key === 'ruta')
        || rows[0];

      if (!candidate) {
        next.innerHTML = '<div class="n360-live-empty">No hay proxima salida disponible.</div>';
      } else {
        const item = candidate.row;
        const live = candidate.live;
        next.innerHTML = `
          <article class="n360-live-next-card is-${esc(live.key === 'proximo' ? 'warning' : live.key)}">
            <div class="n360-live-next-main">
              <div class="n360-live-next-terminal">
                <span><i class="bi bi-signpost-split-fill"></i> Proxima salida</span>
                <strong>Gate N360</strong>
              </div>
              <div class="n360-live-next-time">${esc(text(item.hora_salida, '--:--'))}</div>
              <h2>${esc(text(item.bus, 'SIN ASIGNAR'))}</h2>
              <div class="n360-live-next-route">
                <article>
                  <span>Origen</span>
                  <strong>${esc(text(item.origen))}</strong>
                </article>
                <article>
                  <span>Destino</span>
                  <strong>${esc(text(item.destino))}</strong>
                </article>
              </div>
              <p>${esc(routeLabel(item))}</p>
            </div>
            <div class="n360-live-next-status">
              <strong>${esc(live.label)}</strong>
              <span>${esc(live.detail)}</span>
              <small>Actualiza manualmente cuando quieras refrescar la programacion.</small>
            </div>
          </article>
        `;
      }
    }

    renderViewers(root, state.viewers || []);
  };

  const renderGuide = (root, state) => {
    const rows = (state.rows || []).map((row) => ({ row, live: evaluateRow(row) }))
      .sort((a, b) => a.live.sort - b.live.sort)
      .slice(0, 5);
    const list = root.querySelector('[data-live-guide-list]');
    const stamp = root.querySelector('[data-live-guide-stamp]');
    if (stamp) {
      const snapshot = state.snapshot || {};
      stamp.textContent = snapshot.generated_label ? `Actualizado ${snapshot.generated_label}` : 'Live operativo';
    }
    if (!list) return;
    list.innerHTML = rows.length
      ? rows.map((item) => rowHtml(item.row, true)).join('')
      : '<div class="n360-live-empty">Sin horarios activos.</div>';
  };

  const initRoot = (root) => {
    if (root.dataset.liveReady === '1') return;
    root.dataset.liveReady = '1';

    const endpoint = root.dataset.liveEndpoint || 'api.php';
    const isGuide = root.hasAttribute('data-n360-live-guide');
    const state = { rows: [], viewers: [], snapshot: null };

    const render = () => {
      const clock = root.querySelector('[data-live-clock]');
      if (clock) clock.textContent = clockLabel();
      if (isGuide) renderGuide(root, state);
      else renderFull(root, state);
    };

    const load = async (force = false) => {
      setBusy(root, true);
      try {
        const data = await fetchLive(endpoint, force);
        const snapshot = data.snapshot || {};
        state.snapshot = snapshot;
        state.rows = Array.isArray(snapshot.rows) ? snapshot.rows : [];
        state.viewers = Array.isArray(data.viewers) ? data.viewers : [];
        render();
      } catch (error) {
        const target = root.querySelector('[data-live-list], [data-live-guide-list], [data-live-next]');
        if (target) target.innerHTML = `<div class="n360-live-error">${esc(error.message)}</div>`;
      } finally {
        setBusy(root, false);
      }
    };

    root.querySelector('[data-live-refresh]')?.addEventListener('click', () => load(true));

    load(false);
    render();
    window.setInterval(render, 30000);

    window.setInterval(async () => {
      const data = await postLive(endpoint, 'heartbeat');
      if (data && Array.isArray(data.viewers)) {
        state.viewers = data.viewers;
        renderViewers(root, state.viewers);
      }
    }, 300000);

    window.addEventListener('beforeunload', () => {
      const body = new URLSearchParams();
      body.set('action', 'leave');
      if (navigator.sendBeacon) {
        navigator.sendBeacon(endpoint, body);
      }
    });
  };

  roots.forEach(initRoot);
})();
