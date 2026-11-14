(function () {
  'use strict';
  const dialog = document.getElementById('sedeespPersonnelDialog');
  if (!dialog) return;
  const location = document.getElementById('sedeespPersonnelLocation');
  const status = document.getElementById('sedeespPersonnelStatus');
  const error = document.getElementById('sedeespPersonnelError');
  const forms = Array.from(dialog.querySelectorAll('[data-personnel-form]'));
  const searches = new Map(forms.map(form => [form, {timer: null, controller: null, version: 0}]));
  const personnelCache = new Map(), workerResults = new Map();
  let spaceId = '', opener = null, loaded = false, active = false, rrhh = true, busy = false;
  let loadController = null, loadVersion = 0;
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  const date = value => {
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/.exec(String(value || ''));
    return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}` : '';
  };
  const showError = message => { error.textContent = message; error.hidden = !message; };
  async function request(params, body, signal) {
    const url = new URL(dialog.dataset.api, window.location.href);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal,
      method: body ? 'POST' : 'GET', headers: {'Accept': 'application/json', ...(body ? {'Content-Type': 'application/json'} : {})},
      body: body ? JSON.stringify({...body, csrf: dialog.dataset.csrf}) : undefined});
    let data;
    try { data = await response.json(); } catch { throw new Error('No se pudo leer la respuesta. Actualiza la lista antes de reintentar.'); }
    if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo procesar la solicitud.');
    return data;
  }
  function clearSearch(form) {
    const state = searches.get(form);
    clearTimeout(state.timer); state.version++; state.controller?.abort();
    const results = form.querySelector('[role=listbox]');
    results.replaceChildren(); results.hidden = true;
    form.elements.busqueda.setAttribute('aria-expanded', 'false');
  }
  function source(form) {
    clearSearch(form);
    const free = form.elements.origen.value === 'LIBRE';
    form.querySelector('[data-personnel-worker]').hidden = free;
    form.querySelector('[data-personnel-free]').hidden = !free;
    form.elements.nombre.required = free;
    form.elements.trabajador_id.value = '';
    form.elements.busqueda.value = '';
    form.querySelector('[data-personnel-search-status]').textContent = 'Escribe al menos 2 caracteres y elige una persona.';
  }
  function controls() {
    forms.forEach(form => {
      form.querySelector('fieldset').disabled = busy || !loaded || !active;
      form.elements.origen.querySelector('[value=RRHH]').disabled = !rrhh;
    });
    dialog.querySelectorAll('[data-personnel-remove]').forEach(button => { button.disabled = busy || !loaded; });
    dialog.querySelectorAll('[data-personnel-close], [data-personnel-reload]').forEach(button => { button.disabled = busy; });
    dialog.setAttribute('aria-busy', String(busy || !loaded));
  }
  function render(data) {
    active = data.space.activo; rrhh = data.rrhh;
    location.textContent = data.space.nombre;
    for (const turn of [1, 2]) {
      const panel = dialog.querySelector(`[data-personnel-turn="${turn}"]`);
      const rows = data.rows.filter(row => row.turno === turn);
      const list = panel.querySelector('[data-personnel-people]');
      list.replaceChildren();
      panel.querySelector('[data-personnel-count]').textContent = rows.length + (rows.length === 1 ? ' persona' : ' personas');
      if (!rows.length) list.append(node('li', 'Sin personas asignadas a este turno.', 'sedeesp-personnel__empty'));
      rows.forEach(row => {
        const item = node('li');
        const initials = String(row.nombre).split(/\s+/).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0]).join('').toLocaleUpperCase('es-PE');
        const avatar = node('span', initials, 'sedeesp-personnel__avatar'); avatar.setAttribute('aria-hidden', 'true');
        const person = node('div', undefined, 'sedeesp-personnel__person');
        person.append(node('strong', row.nombre), node('small', (row.trabajador_id ? 'RRHH' : 'Nombre libre') + ' · Desde ' + date(row.desde)));
        const remove = node('button', 'Quitar', 'sedeesp-personnel__remove');
        remove.type = 'button'; remove.dataset.personnelRemove = row.id;
        remove.setAttribute('aria-label', `Retirar a ${row.nombre} del turno ${turn}`);
        remove.addEventListener('click', () => mutate({action: 'retire', id: row.id}));
        item.append(avatar, person, remove); list.append(item);
      });
      document.querySelectorAll(`[data-personnel-summary="${spaceId}"] [data-summary-turn="${turn}"]`).forEach(label => {
        label.textContent = `T${turn}: ${rows.length}`;
        label.title = rows.map(row => row.nombre).join(', ') || 'Sin personas asignadas';
        label.setAttribute('aria-label', `Turno ${turn}: ${rows.length} personas`);
      });
    }
    forms.forEach(form => { if (!rrhh && form.elements.origen.value === 'RRHH') { form.elements.origen.value = 'LIBRE'; source(form); } });
    const history = dialog.querySelector('[data-personnel-history]');
    history.replaceChildren();
    if (!data.history.length) history.append(node('p', 'No hay asignaciones retiradas.'));
    data.history.forEach(row => {
      const item = node('article');
      item.append(node('strong', row.nombre), node('div', `Turno ${row.turno} · ${row.trabajador_id ? 'RRHH' : 'Nombre libre'}`),
        node('small', `Asignó: ${row.asignado_por} · ${date(row.desde)}`), node('small', `Retiró: ${row.retirado_por} · ${date(row.hasta)}`));
      history.append(item);
    });
  }
  const readyMessage = message => active ? (message || 'Puedes agregar varias personas de RRHH o con nombre libre en cada turno.')
    : 'Espacio inactivo. Puedes consultar y retirar asignaciones; para agregar personal, activa primero el espacio.';
  async function load(message = '', refresh = false) {
    loadController?.abort(); loadController = new AbortController();
    const version = ++loadVersion;
    forms.forEach(clearSearch);
    if (refresh) { personnelCache.clear(); workerResults.clear(); }
    loaded = false; controls(); showError(''); status.textContent = 'Consultando personal del espacio...';
    try {
      const data = personnelCache.get(spaceId) || await request({action: 'list', espacio_id: spaceId, ...(refresh ? {refresh: '1'} : {})}, null, loadController.signal);
      if (version !== loadVersion || !dialog.open) return false;
      personnelCache.set(spaceId, data);
      render(data); loaded = true;
      status.textContent = readyMessage(message);
      return true;
    } catch (failure) {
      if (failure.name !== 'AbortError' && version === loadVersion && dialog.open) {
        showError((message ? 'Cambio guardado. ' : '') + failure.message); status.textContent = 'Usa Actualizar para volver a consultar.';
      }
      return false;
    } finally { if (version === loadVersion) controls(); }
  }
  async function mutate(payload, form) {
    if (busy || !loaded) return;
    busy = true; forms.forEach(clearSearch); controls(); showError('');
    status.textContent = 'Guardando asignación...';
    let saved = false;
    try {
      const data = await request({}, {...payload, espacio_id: Number(spaceId)});
      saved = true;
      personnelCache.clear(); workerResults.clear();
      if (form) { form.elements.nombre.value = ''; source(form); }
      loaded = false;
      if (data.personnel) {
        personnelCache.set(spaceId, data.personnel);
        render(data.personnel); loaded = true;
        status.textContent = readyMessage(data.message);
      } else {
        showError(data.refresh_error || 'El cambio se guardó. Pulsa Actualizar para consultar la lista.');
        status.textContent = data.message;
      }
    } catch (failure) {
      showError(failure.message); status.textContent = 'Revisa la lista antes de reintentar. Tus datos permanecen en el formulario.';
    } finally { busy = false; controls(); }
    if (saved && form && loaded && active) (form.elements.origen.value === 'LIBRE' ? form.elements.nombre : form.elements.busqueda).focus();
  }
  async function search(form) {
    const state = searches.get(form), input = form.elements.busqueda;
    const query = input.value.trim();
    clearSearch(form);
    const text = form.querySelector('[data-personnel-search-status]');
    if (Array.from(query).length < 2) { text.textContent = 'Escribe al menos 2 caracteres y elige una persona.'; return; }
    const version = ++state.version;
    state.controller = new AbortController(); text.textContent = 'Buscando trabajadores...';
    try {
      const key = query.toLocaleLowerCase('es-PE');
      const data = workerResults.get(key) || await request({action: 'workers', q: query}, null, state.controller.signal);
      if (version !== state.version || !dialog.open || form.elements.origen.value !== 'RRHH' || busy) return;
      workerResults.set(key, data);
      const results = form.querySelector('[role=listbox]');
      data.rows.forEach(row => {
        const button = node('button'); button.type = 'button'; button.setAttribute('role', 'option'); button.setAttribute('aria-selected', 'false');
        button.append(node('strong', row.nombre), node('small', row.nombre_completo || 'Trabajador de RRHH'));
        button.addEventListener('click', () => {
          clearSearch(form); input.value = row.nombre; form.elements.trabajador_id.value = row.id;
          text.textContent = 'Trabajador seleccionado: ' + row.nombre; input.focus();
        });
        results.append(button);
      });
      results.hidden = data.rows.length === 0; input.setAttribute('aria-expanded', String(data.rows.length > 0));
      text.textContent = data.rows.length ? (data.more ? 'Hay más coincidencias. Precisa la búsqueda o elige una persona.' : 'Selecciona una persona de la lista.') : 'No se encontraron trabajadores. Puedes usar un nombre libre.';
    } catch (failure) { if (failure.name !== 'AbortError' && version === state.version && dialog.open) text.textContent = failure.message; }
  }
  forms.forEach(form => {
    form.elements.origen.addEventListener('change', () => source(form));
    form.elements.busqueda.addEventListener('input', () => {
      form.elements.trabajador_id.value = ''; clearSearch(form);
      searches.get(form).timer = setTimeout(() => search(form), 300);
    });
    form.elements.busqueda.addEventListener('keydown', event => {
      if (event.key !== 'ArrowDown') return;
      const first = form.querySelector('[role=option]');
      if (first) { event.preventDefault(); first.focus(); }
    });
    form.querySelector('[role=listbox]').addEventListener('keydown', event => {
      if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
      const buttons = Array.from(form.querySelectorAll('[role=option]'));
      const position = buttons.indexOf(document.activeElement);
      event.preventDefault(); buttons[(position + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length]?.focus();
    });
    form.addEventListener('submit', event => {
      event.preventDefault();
      const mode = form.elements.origen.value;
      if (mode === 'RRHH' && !form.elements.trabajador_id.value) { showError('Busca y selecciona un trabajador de la lista de RRHH.'); form.elements.busqueda.focus(); return; }
      mutate({action: 'assign', turno: Number(form.dataset.turn), origen: mode,
        trabajador_id: mode === 'RRHH' ? Number(form.elements.trabajador_id.value) : null,
        nombre: mode === 'LIBRE' ? form.elements.nombre.value : ''}, form);
    });
  });
  document.querySelectorAll('[data-sedeesp-personnel]').forEach(button => button.addEventListener('click', () => {
    if (dialog.open || button.disabled) return;
    opener = button; spaceId = button.dataset.id; loaded = false; active = false;
    forms.forEach(form => { form.reset(); source(form); });
    dialog.querySelectorAll('[data-personnel-people], [data-personnel-history]').forEach(el => el.replaceChildren());
    dialog.querySelectorAll('[data-personnel-count]').forEach(el => { el.textContent = '0 personas'; });
    dialog.querySelector('.sedeesp-personnel__history').open = false;
    location.textContent = button.dataset.location;
    dialog.showModal(); document.body.classList.add('sedeesp-dialog-open');
    dialog.querySelector('[data-personnel-close]').focus(); load();
  }));
  dialog.querySelectorAll('[data-personnel-close]').forEach(button => button.addEventListener('click', () => { if (!busy) dialog.close(); }));
  dialog.querySelector('[data-personnel-reload]').addEventListener('click', () => { if (!busy) load('', true); });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  // Al deshabilitar el formulario, el foco puede pasar al documento durante el POST.
  document.addEventListener('keydown', event => {
    if (!dialog.open || event.key !== 'Escape') return;
    event.preventDefault(); event.stopPropagation();
    if (!busy) dialog.close();
  }, true);
  dialog.addEventListener('close', () => {
    if (dialog.open) return;
    loadVersion++; loadController?.abort(); forms.forEach(clearSearch);
    if (!document.querySelector('dialog[open]')) document.body.classList.remove('sedeesp-dialog-open');
    if (opener?.isConnected) opener.focus({preventScroll: true});
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) { busy = false; personnelCache.clear(); workerResults.clear(); if (dialog.open) load('', true); }
  });
})();
