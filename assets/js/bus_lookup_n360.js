(function () {
  const modal = document.getElementById('n360BusLookupModal');
  if (!modal || modal.dataset.ready === '1') return;

  modal.dataset.ready = '1';

  const endpoint = modal.dataset.endpoint || '';
  const form = document.getElementById('n360BusLookupForm');
  const input = document.getElementById('n360BusLookupInput');
  const statusEl = document.getElementById('n360BusLookupStatus');
  const resultEl = document.getElementById('n360BusLookupResult');
  const openers = document.querySelectorAll('[data-n360-bus-open]');
  const closers = modal.querySelectorAll('[data-n360-bus-close]');
  let currentController = null;

  document.querySelectorAll('a.btn-flotante[href*="wa.me"]').forEach((legacy) => {
    legacy.hidden = true;
    legacy.style.display = 'none';
  });

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

  const setStatus = (message, type = '') => {
    statusEl.textContent = message;
    statusEl.classList.toggle('is-error', type === 'error');
    statusEl.classList.toggle('is-ok', type === 'ok');
  };

  const setBusy = (busy) => {
    form?.querySelectorAll('input, button').forEach((el) => {
      el.disabled = busy;
    });
  };

  const open = (seed = '') => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('n360-modal-open');
    if (seed) input.value = seed;
    window.setTimeout(() => input?.focus(), 60);
  };

  const close = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('n360-modal-open');
  };

  const section = (title, count, html) => `
    <section class="n360-bus-section">
      <div class="n360-bus-section__head">
        <h3>${esc(title)}</h3>
        <span>${esc(count)}</span>
      </div>
      ${html}
    </section>
  `;

  const renderEmpty = (message) => `<div class="n360-bus-empty">${esc(message)}</div>`;

  const renderSuggestions = (data) => {
    const items = data.sugerencias || data.suggestions || [];
    if (!items.length) {
      resultEl.innerHTML = '';
      setStatus('No encontre unidades con ese dato.', 'error');
      return;
    }

    setStatus(`Encontre ${items.length} coincidencia(s). Elige una unidad.`, 'ok');
    resultEl.innerHTML = `
      <div class="n360-bus-suggestions">
        ${items.map((bus) => `
          <button class="n360-bus-suggestion" type="button" data-bus-id="${esc(bus.id || bus.id_bus)}">
            <strong>${esc(text(bus.bus || bus.nombre, 'Unidad'))} | ${esc(text(bus.placa))}</strong>
            <span>${esc(text(bus.servicio, 'Servicio no registrado'))}</span>
          </button>
        `).join('')}
      </div>
    `;

    resultEl.querySelectorAll('[data-bus-id]').forEach((btn) => {
      btn.addEventListener('click', () => searchById(btn.dataset.busId));
    });
  };

  const renderDetail = (data) => {
    const bus = data.bus || {};
    const resumen = data.resumen || {};
    const conductores = (data.programacion && data.programacion.conductores) || [];
    const horarios = (data.programacion && data.programacion.horarios) || [];
    const checklists = (data.programacion && data.programacion.checklists) || [];
    const fumigacion = data.programacion && data.programacion.ultima_fumigacion;

    const conductoresHtml = conductores.length ? `
      <div class="n360-bus-list">
        ${conductores.map((item) => `
          <div class="n360-bus-row">
            <span class="n360-bus-row__icon"><i class="bi bi-person-vcard-fill"></i></span>
            <span class="n360-bus-row__main">
              <strong>${esc(text(item.conductor, 'Conductor sin nombre'))}</strong>
              <small>DNI ${esc(text(item.dni))} | Lic. ${esc(text(item.licencia))}</small>
            </span>
            <span class="n360-bus-row__tag">${esc(text(item.fecha_asignacion || item.fecha_programacion, 'Asignacion activa'))}</span>
          </div>
        `).join('')}
      </div>
    ` : renderEmpty('No hay conductor activo asignado a esta unidad.');

    const horariosHtml = horarios.length ? `
      <div class="n360-bus-list">
        ${horarios.map((item) => `
          <div class="n360-bus-row">
            <span class="n360-bus-row__icon"><i class="bi bi-signpost-split-fill"></i></span>
            <span class="n360-bus-row__main">
              <strong>${esc(text(item.hora))} | ${esc(text(item.origen))} -> ${esc(text(item.destino))}</strong>
              <small>${esc(text(item.ruta_texto, 'Ruta sin detalle'))}${item.comentario ? ' | ' + esc(item.comentario) : ''}</small>
            </span>
            <span class="n360-bus-row__tag">${esc(text(item.fecha_operativa, 'Operativo actual'))}</span>
          </div>
        `).join('')}
      </div>
    ` : renderEmpty('No hay horarios activos para esta unidad.');

    const checklistsHtml = checklists.length ? `
      <div class="n360-bus-list">
        ${checklists.map((item) => `
          <div class="n360-bus-row">
            <span class="n360-bus-row__icon"><i class="bi bi-clipboard2-check-fill"></i></span>
            <span class="n360-bus-row__main">
              <strong>${esc(text(item.tipo, 'Checklist'))} | ${esc(text(item.corr))}</strong>
              <small>${esc(text(item.responsable, 'Sin responsable'))}${item.observaciones ? ' | ' + esc(item.observaciones) : ''}</small>
            </span>
            <span class="n360-bus-row__tag">${esc(text(item.fecha))} ${esc(text(item.hora, ''))}</span>
          </div>
        `).join('')}
      </div>
    ` : renderEmpty('Todavia no hay checklist recientes para esta unidad.');

    const fumigacionHtml = fumigacion ? `
      <div class="n360-bus-info">
        <span>Ultima fumigacion</span>
        <strong>${esc(text(fumigacion.vigencia))}</strong>
        <small>${esc(text(fumigacion.fecha_fumigacion || fumigacion.fecha))}${fumigacion.dias !== null && fumigacion.dias !== undefined ? ' | ' + esc(fumigacion.dias) + ' dia(s)' : ''}</small>
      </div>
    ` : `
      <div class="n360-bus-info">
        <span>Ultima fumigacion</span>
        <strong>Sin registro</strong>
        <small>No se encontro fumigacion reciente.</small>
      </div>
    `;

    setStatus('Unidad encontrada. Informacion operativa actualizada.', 'ok');
    resultEl.innerHTML = `
      <article class="n360-bus-card">
        <div class="n360-bus-card__hero">
          <div>
            <h3 class="n360-bus-card__title" style="color: #ffffff;">${esc(text(bus.nombre || bus.bus, 'Unidad'))}</h3>
            <p class="n360-bus-card__subtitle">
              ${esc(text(bus.servicio, 'Servicio sin registrar'))} | ${esc(text(bus.tipo, 'Tipo sin registrar'))} | ${esc(text(bus.dueno, 'Dueno sin registrar'))}
            </p>
          </div>
          <div class="n360-bus-card__plate">
            <small>Placa</small>
            <strong>${esc(text(bus.placa))}</strong>
          </div>
        </div>

        <div class="n360-bus-grid">
          <div class="n360-bus-kpi"><span>Conductores</span><strong>${esc(resumen.conductores ?? conductores.length)}</strong></div>
          <div class="n360-bus-kpi"><span>Horarios activos</span><strong>${esc(resumen.horarios ?? horarios.length)}</strong></div>
          <div class="n360-bus-kpi"><span>Checklist recientes</span><strong>${esc(resumen.checklists ?? checklists.length)}</strong></div>
          ${fumigacionHtml}
        </div>

        ${section('Conductores actuales', `${conductores.length} asignado(s)`, conductoresHtml)}
        ${section('Pizarra actual de horarios', `${horarios.length} horario(s)`, horariosHtml)}
        ${section('Checklist mas actuales', `${checklists.length} registro(s)`, checklistsHtml)}
      </article>
    `;
  };

  const fetchData = async (params) => {
    if (currentController) currentController.abort();
    currentController = new AbortController();
    setBusy(true);
    setStatus('Consultando unidad...');

    try {
      const url = `${endpoint}?${new URLSearchParams(params).toString()}`;
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: currentController.signal,
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'No se pudo consultar la unidad.');
      }
      return payload.data || {};
    } finally {
      setBusy(false);
      currentController = null;
    }
  };

  const search = async (query) => {
    const q = String(query ?? input.value ?? '').trim();
    if (q.length < 2) {
      setStatus('Escribe al menos 2 caracteres para buscar.', 'error');
      input?.focus();
      return;
    }

    resultEl.innerHTML = '';

    try {
      const data = await fetchData({ q });
      if (data.mode === 'suggestions') renderSuggestions(data);
      else renderDetail(data);
    } catch (error) {
      setStatus(error.message || 'No se pudo consultar la unidad.', 'error');
      resultEl.innerHTML = '';
    }
  };

  const searchById = async (busId) => {
    try {
      const data = await fetchData({ id_bus: busId });
      renderDetail(data);
    } catch (error) {
      setStatus(error.message || 'No se pudo abrir la unidad.', 'error');
    }
  };

  openers.forEach((btn) => btn.addEventListener('click', () => open()));
  closers.forEach((btn) => btn.addEventListener('click', close));

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    search();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) close();
  });

  window.N360BusLookup = {
    open,
    close,
    search: (query) => {
      open(query || '');
      return search(query);
    },
  };
})();
