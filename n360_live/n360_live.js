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
    if (m <= 0) return `${h} h`;
    return `${h} h ${pad(m)} min`;
  };

  const clockLabel = () => {
    const now = new Date();
    return `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  };

  const dateLabel = () => {
    const now = new Date();
    return new Intl.DateTimeFormat('es-PE', {
      weekday: 'long',
      day: '2-digit',
      month: 'long',
      year: 'numeric',
    }).format(now);
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
        label: 'Próxima salida',
        detail: diff <= 0 ? 'Salida inmediata' : `Sale en ${formatDuration(diff)}`,
        diff,
        sort: diff,
      };
    }

    if (diff < 0 && diff >= -480) {
      return {
        key: 'ruta',
        label: 'En ruta',
        detail: `Salió hace ${formatDuration(diff)}`,
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
      key: 'salido',
      label: 'Ya salio',
      detail: `Salio hace ${formatDuration(diff)}`,
      diff,
      sort: 9000 + Math.abs(diff),
    };
  };

  const routeLabel = (row) => {
    const route = text(row.ruta, '');
    if (route) return route;
    return `${text(row.origen)} → ${text(row.destino)}`;
  };

  const rowHtml = (row, compact = false) => {
    const live = evaluateRow(row);

    if (compact) {
      return `
        <article class="n360-live-row is-${esc(live.key)} is-compact">
          <div class="n360-live-row__time">
            <small>Hora</small>
            <strong>${esc(text(row.hora_salida, '--:--'))}</strong>
          </div>
          <div class="n360-live-row__unit">
            <small>Unidad</small>
            <strong>${esc(text(row.bus, 'SIN ASIGNAR'))}</strong>
            <span>${esc(text(row.origen))} → ${esc(text(row.destino))}</span>
          </div>
          <div class="n360-live-row__status">
            <span class="n360-live-chip is-${esc(live.key)}"><i></i>${esc(live.label)}</span>
          </div>
        </article>
      `;
    }

    return `
      <article class="n360-live-row is-${esc(live.key)}">
        <div class="n360-live-row__time">
          <small>Hora</small>
          <strong>${esc(text(row.hora_salida, '--:--'))}</strong>
        </div>
        <div class="n360-live-row__unit">
          <small>Unidad</small>
          <strong>${esc(text(row.bus, 'SIN ASIGNAR'))}</strong>
        </div>
        <div class="n360-live-row__origin">
          <small>Origen</small>
          <strong>${esc(text(row.origen))}</strong>
        </div>
        <div class="n360-live-row__destination">
          <small>Destino</small>
          <strong>${esc(text(row.destino))}</strong>
        </div>
        <div class="n360-live-row__route">
          <small>Recorrido</small>
          <strong title="${esc(routeLabel(row))}">${esc(routeLabel(row))}</strong>
        </div>
        <div class="n360-live-row__status">
          <span class="n360-live-chip is-${esc(live.key)}"><i></i>${esc(live.label)}</span>
          <small>${esc(live.detail)}</small>
        </div>
      </article>
    `;
  };

  const viewerHtml = (viewer) => `
    <article class="n360-live-viewer">
      <div class="n360-live-viewer__icon">
        <i class="bi ${viewer.dispositivo === 'Celular' ? 'bi-phone' : viewer.dispositivo === 'Tablet' ? 'bi-tablet' : 'bi-pc-display'}"></i>
      </div>
      <div class="n360-live-viewer__body">
        <strong>${esc(text(viewer.usuario || viewer.nombre, 'Usuario'))}</strong>
        <span>${esc(text(viewer.dispositivo, 'Dispositivo'))} · IP ${esc(text(viewer.ip))}</span>
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

  const fetchHistory = async (endpoint) => {
    const url = new URL(endpoint, window.location.href);
    url.searchParams.set('action', 'history');
    url.searchParams.set('limit', '300');

    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await res.json();
    if (!res.ok || !payload.ok) {
      throw new Error(payload.message || 'No se pudo cargar el historial del Live.');
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

  const historyEventLabel = (event) => {
    const key = String(event || '').toUpperCase();
    if (key === 'ENTER') return 'Ingreso';
    if (key === 'LEAVE') return 'Salida';
    if (key === 'DENIED') return 'Denegado';
    if (key === 'HISTORY_VIEW') return 'Historial';
    return key || 'Evento';
  };

  const historyEventClass = (event) => {
    const key = String(event || '').toUpperCase();
    if (key === 'DENIED') return 'denied';
    if (key === 'LEAVE') return 'leave';
    if (key === 'HISTORY_VIEW') return 'history';
    return 'enter';
  };

  const historyHtml = (row) => {
    const eventClass = historyEventClass(row.event);
    const label = historyEventLabel(row.event);
    const source = text(row.source, '');
    const reason = text(row.reason, '');
    const path = text(row.path, '-');
    const meta = [text(row.dispositivo, 'Dispositivo'), text(row.rol, 'Sin rol')]
      .filter(Boolean)
      .map(esc)
      .join(' &middot; ');

    return `
      <article class="n360-live-history-row is-${esc(eventClass)}">
        <div class="n360-live-history-row__event">
          <span>${esc(label)}</span>
          <strong>${esc(text(row.fecha_label, 'Sin fecha'))}</strong>
        </div>
        <div class="n360-live-history-row__user">
          <span>Usuario</span>
          <strong>${esc(text(row.usuario || row.nombre, 'SIN_SESION'))}</strong>
          <small title="${esc(text(row.nombre, ''))}">${esc(text(row.nombre, ''))}</small>
        </div>
        <div class="n360-live-history-row__ip">
          <span>IP</span>
          <strong>${esc(text(row.ip, '-'))}</strong>
          <small>${meta}</small>
        </div>
        <div class="n360-live-history-row__path">
          <span>${esc(text(row.method, 'REQ'))}${source ? ` &middot; ${esc(source)}` : ''}</span>
          <strong title="${esc(path)}">${esc(path)}</strong>
          ${reason ? `<small>${esc(reason)}</small>` : ''}
        </div>
      </article>
    `;
  };

  const renderHistory = (modal, data) => {
    const history = data.history || {};
    const rows = Array.isArray(history.rows) ? history.rows : [];
    const list = modal.querySelector('[data-live-history-list]');
    const summary = modal.querySelector('[data-live-history-summary]');

    if (summary) {
      const label = rows.length === 1 ? 'registro reciente' : 'registros recientes';
      summary.textContent = rows.length
        ? `${rows.length} ${label} del historial Live.`
        : 'No hay registros en el historial Live.';
    }

    if (list) {
      list.innerHTML = rows.length
        ? rows.map(historyHtml).join('')
        : '<p class="n360-live-empty">Sin registros historicos para mostrar.</p>';
    }
  };

  const setHistoryOpen = (modal, open) => {
    modal.hidden = !open;
    document.body.classList.toggle('n360-live-history-open', open);
  };

  const initHistory = (root, endpoint) => {
    const openButton = root.querySelector('[data-live-history-open]');
    const modal = root.querySelector('[data-live-history-modal]');
    if (!openButton || !modal || modal.dataset.ready === '1') return;
    modal.dataset.ready = '1';

    const list = modal.querySelector('[data-live-history-list]');
    const refresh = modal.querySelector('[data-live-history-refresh]');
    let loaded = false;

    const loadHistory = async () => {
      if (refresh) refresh.disabled = true;
      if (list) list.innerHTML = '<p class="n360-live-empty">Cargando historial...</p>';
      try {
        const data = await fetchHistory(endpoint);
        renderHistory(modal, data);
        loaded = true;
      } catch (error) {
        if (list) list.innerHTML = `<div class="n360-live-error">${esc(error.message)}</div>`;
      } finally {
        if (refresh) refresh.disabled = false;
      }
    };

    const close = () => setHistoryOpen(modal, false);
    const open = () => {
      setHistoryOpen(modal, true);
      if (!loaded) loadHistory();
    };

    openButton.addEventListener('click', open);
    refresh?.addEventListener('click', loadHistory);
    modal.querySelectorAll('[data-live-history-close]').forEach((button) => {
      button.addEventListener('click', close);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.hidden) close();
    });
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

    if (summary) summary.textContent = `${total} ${total === 1 ? 'salida activa' : 'salidas activas'} en pizarra`;
    if (totalNode) totalNode.textContent = `${total} ${total === 1 ? 'horario' : 'horarios'}`;

    const snapshot = state.snapshot || {};
    if (cache) {
      const cacheMsg = snapshot.cache_hit
        ? `Datos en caché · hace ${snapshot.cache_age || 0}s · ${snapshot.generated_label || ''}`
        : `Última actualización · ${snapshot.generated_label || ''}`;
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
        next.innerHTML = '<div class="n360-live-empty">No hay próxima salida disponible.</div>';
      } else {
        const item = candidate.row;
        const live = candidate.live;
        next.innerHTML = `
          <div class="n360-live-next-card is-${esc(live.key)}">
            <div class="n360-live-next-accent"></div>

            <div class="n360-live-next-heading">
              <span class="n360-live-next-kicker">PRÓXIMA SALIDA</span>
              <strong>${live.key === 'ruta' ? 'Servicio en ruta' : live.key === 'salido' ? 'Servicio ya salido' : 'Servicio programado'}</strong>
            </div>

            <div class="n360-live-next-time">
              <span>HORA</span>
              <strong>${esc(text(item.hora_salida, '--:--'))}</strong>
              <small>${esc(live.detail)}</small>
            </div>

            <div class="n360-live-next-trip">
              <div class="n360-live-next-unit">
                <span>UNIDAD</span>
                <strong>${esc(text(item.bus, 'SIN ASIGNAR'))}</strong>
              </div>

              <div class="n360-live-next-route">
                <div>
                  <span>ORIGEN</span>
                  <strong>${esc(text(item.origen))}</strong>
                </div>
                <div class="n360-live-next-route__line" aria-hidden="true">
                  <i class="bi bi-arrow-right"></i>
                </div>
                <div>
                  <span>DESTINO</span>
                  <strong>${esc(text(item.destino))}</strong>
                </div>
              </div>

              <p title="${esc(routeLabel(item))}">${esc(routeLabel(item))}</p>
            </div>

            <div class="n360-live-next-state">
              <span>ESTADO</span>
              <div class="n360-live-status-block is-${esc(live.key)}">
                <i></i>
                <strong>${esc(live.label)}</strong>
              </div>
            </div>
          </div>
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
    if (!isGuide) initHistory(root, endpoint);

    const renderClock = () => {
      const clock = root.querySelector('[data-live-clock]');
      const date = root.querySelector('[data-live-date]');
      if (clock) clock.textContent = clockLabel();
      if (date) date.textContent = dateLabel();
    };

    const render = () => {
      renderClock();
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
    window.setInterval(renderClock, 1000);
    window.setInterval(() => {
      if (isGuide) renderGuide(root, state);
      else renderFull(root, state);
    }, 30000);

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
