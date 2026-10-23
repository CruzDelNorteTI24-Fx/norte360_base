(function () {
  'use strict';
  const cfg = window.N360ContractConfig || {};
  const state = { rows: [], tab: 'reserved', busy: false, confirm: null,
    loaded: Array.isArray(window.horariosInitialData?.contratos),
    offices: window.horariosInitialData?.oficinas_destino || [], origins: window.horariosInitialData?.oficinas_origen || [] };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const q = (s, root = document) => root.querySelector(s);
  const date = (s) => s ? `${String(s).slice(8, 10)}/${String(s).slice(5, 7)}/${String(s).slice(0, 4)} ${String(s).slice(11, 16)}` : '-';
  const released = (r) => Number(r.released) === 1;
  const pending = (r) => released(r) && r.outbound_id && !r.return_id;
  const awaitingReturn = (r) => released(r) && !r.return_id;
  const ready = () => !!cfg.ready;
  const notice = (text, good = false) => {
    const el = q('[data-contract-notice]');
    if (!el) return;
    el.className = `alert ${good ? 'alert-success' : 'alert-danger'}`;
    el.textContent = text;
  };
  async function request(action = 'list', values = null) {
    const options = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    let url = `${cfg.endpoint}?action=${encodeURIComponent(action)}`;
    if (values) {
      options.method = 'POST';
      options.body = new FormData();
      Object.entries({ ...values, action, contrato_csrf: cfg.csrf }).forEach(([k, v]) => options.body.append(k, String(v)));
    }
    const res = await fetch(url, options);
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.message || 'No se pudo consultar contratos.');
    return json;
  }
  async function load() {
    if (!ready()) return [];
    const json = await request();
    state.rows = json.data.rows || [];
    state.loaded = true;
    render();
    return state.rows;
  }
  function render() {
    const reserved = state.rows.filter((r) => !released(r));
    document.querySelectorAll('[data-contract-count]').forEach((el) => { el.textContent = state.loaded ? String(reserved.length) : '-'; });
    const count = q('[data-contract-reserved-count]');
    if (!count) return;
    count.textContent = reserved.length;
    q('[data-contract-pending-count]').textContent = state.rows.filter(awaitingReturn).length;
    const search = String(q('[data-contract-search]')?.value || '').toLocaleLowerCase('es');
    const rows = state.rows.filter((r) => (state.tab === 'reserved' ? !released(r) : awaitingReturn(r))
      && `${r.bus} ${r.plate} ${r.origin} ${r.destination} ${r.id}`.toLocaleLowerCase('es').includes(search));
    q('[data-contract-rows]').innerHTML = rows.map((r) => `<tr>
      <td><strong>${esc(r.bus)} / ${esc(r.plate)}</strong><small>Contrato #${Number(r.id)}${r.outbound_id ? ` / Viaje #${Number(r.outbound_id)}` : ' / Salida pendiente de cierre'}</small></td>
      <td>${esc(r.origin)} <i class="bi bi-arrow-right"></i><br>${esc(r.destination)}</td>
      <td>${date(r.departure)}</td>
      <td>${cfg.admin && !released(r) ? `<input type="datetime-local" value="${esc(String(r.return_expected).slice(0, 16).replace(' ', 'T'))}" data-contract-end="${Number(r.id)}" aria-label="Retorno previsto del contrato ${Number(r.id)}"><button type="button" class="btn btn-outline-primary btn-sm ms-1" data-contract-save-end="${Number(r.id)}" title="Guardar retorno previsto" aria-label="Guardar retorno previsto"><i class="bi bi-floppy"></i></button>` : date(r.return_expected)}</td>
      <td><span class="n360-contract-status ${released(r) ? 'is-free' : Number(r.expired) ? 'is-late' : ''}"><i class="bi bi-${released(r) ? 'unlock' : 'lock'}"></i> ${released(r) ? 'LIBERADO' : Number(r.expired) ? 'POR LIBERAR' : 'RESERVADO'}</span>${r.released_at ? `<small>${date(r.released_at)}</small>` : ''}</td>
      <td>${cfg.canEdit && !released(r) ? `<button type="button" class="btn btn-outline-success btn-sm" data-contract-release="${Number(r.id)}"><i class="bi bi-unlock"></i> Liberar</button>` : '-'}</td>
    </tr>`).join('') || '<tr><td colspan="6" class="n360-contract-empty">Sin contratos para esta seleccion.</td></tr>';
    document.querySelectorAll('[data-contract-tab]').forEach((b) => {
      b.classList.toggle('btn-primary', b.dataset.contractTab === state.tab);
      b.classList.toggle('btn-outline-secondary', b.dataset.contractTab !== state.tab);
    });
  }
  async function change(action, row, values = {}) {
    if (state.busy) return;
    state.busy = true;
    const modal = document.getElementById('n360ContractsModal');
    modal.querySelectorAll('button, input').forEach((b) => { b.disabled = true; });
    try {
      const json = await request(action, { id: row.id, version: row.version, ...values });
      state.rows = json.data.rows || [];
      render();
      notice(json.message, true);
      document.dispatchEvent(new CustomEvent('n360:contracts-changed'));
    } catch (error) { notice(error.message); }
    finally {
      state.busy = false;
      modal.querySelectorAll('button, input').forEach((b) => { b.disabled = false; });
      q('[data-contract-confirm]').classList.add('d-none');
    }
  }
  let placeSequence = 0;
  function placeControl(select, side) {
    if (!select.id) select.id = `n360ContractOffice${++placeSequence}`;
    let title = null;
    if (select.parentElement.tagName === 'LABEL') {
      const old = select.parentElement;
      const field = document.createElement('div');
      field.className = `${old.className} n360-contract-place-field`;
      field.append(...Array.from(old.childNodes));
      old.replaceWith(field);
      const caption = q('span', field);
      title = document.createElement('label');
      title.className = 'n360-contract-place-title'; title.textContent = caption?.textContent || side;
      title.htmlFor = select.id;
      if (caption) caption.replaceWith(title); else field.prepend(title);
    }
    const input = document.createElement('input');
    input.id = `${select.id}Custom`;
    input.type = 'text'; input.maxLength = 160;
    input.className = 'form-control n360-contract-place-input';
    input.placeholder = `Lugar de ${side}`; input.hidden = true;
    input.setAttribute('aria-label', `Lugar personalizado de ${side}`);
    const label = document.createElement('label');
    label.className = 'n360-contract-place-toggle'; label.hidden = true;
    label.innerHTML = '<input type="checkbox"> Lugar personalizado';
    select.after(label, input);
    const toggle = q('input', label);
    const update = () => {
      const custom = !label.hidden && toggle.checked;
      select.hidden = custom; select.disabled = custom; select.required = !custom;
      input.hidden = !custom; input.required = custom;
      if (title) title.htmlFor = custom ? input.id : select.id;
      if (custom) select.value = '';
      if (!custom) input.value = '';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    };
    toggle.addEventListener('change', update);
    input.addEventListener('input', () => select.dispatchEvent(new Event('change', { bubbles: true })));
    return { input, label, toggle, update };
  }
  let create = null;
  function setupCreate() {
    const modal = document.getElementById('modalCrearHorario');
    if (!modal) return;
    const body = q('.modal-body', modal);
    const mode = document.createElement('label');
    mode.className = 'n360-contract-mode';
    mode.innerHTML = `<input type="checkbox" id="n360CreateContract" ${ready() ? '' : 'disabled'}> <i class="bi bi-briefcase"></i> Es contrato`;
    if (!ready()) mode.title = 'Instala primero la query de contratos de Flota';
    body.prepend(mode);
    const fields = document.createElement('div');
    fields.className = 'n360-contract-create'; fields.hidden = true;
    fields.innerHTML = `<div class="row g-3"><div class="col-12"><label class="form-label fw-bold" for="n360ContractBus">Unidad reservada</label><select id="n360ContractBus" class="form-select"></select></div>
      <div class="col-md-6"><label class="form-label fw-bold" for="n360ContractDeparture">Fecha y hora de salida real</label><input type="datetime-local" id="n360ContractDeparture" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-bold" for="n360ContractEnd">Fecha y hora de retorno previsto</label><input type="datetime-local" id="n360ContractEnd" class="form-control"></div>
      <div class="col-12"><small>Dia operativo de salida: <output data-contract-operative>-</output></small></div></div>`;
    mode.after(fields);
    const origin = placeControl(document.getElementById('crearOrigen'), 'origen');
    const destination = placeControl(document.getElementById('crearDestino'), 'destino');
    const checkbox = q('input', mode);
    const subtitle = q('.modal-header .small', modal);
    const ordinarySubtitle = subtitle?.textContent || '';
    const update = () => {
      fields.hidden = !checkbox.checked;
      modal.classList.toggle('n360-contract-creating', checkbox.checked);
      if (subtitle) subtitle.textContent = checkbox.checked ? 'Programacion por contrato' : ordinarySubtitle;
      const originSelect = document.getElementById('crearOrigen');
      const selected = originSelect.value;
      originSelect.innerHTML = '<option value="">Selecciona origen</option>' + (checkbox.checked ? state.offices : state.origins)
        .map((o) => `<option value="${Number(o.clm_sedes_id)}">${esc(o.oficina)}</option>`).join('');
      originSelect.value = selected;
      [origin, destination].forEach((c) => { c.label.hidden = !checkbox.checked; if (!checkbox.checked) c.toggle.checked = false; c.update(); });
      document.getElementById('crearHora').closest('.row').hidden = checkbox.checked;
    };
    checkbox.addEventListener('change', update);
    document.getElementById('btnSwapOficinas').addEventListener('click', (event) => {
      if (!checkbox.checked) return;
      event.stopImmediatePropagation();
      const a = { custom: origin.toggle.checked, text: origin.input.value, office: document.getElementById('crearOrigen').value };
      const b = { custom: destination.toggle.checked, text: destination.input.value, office: document.getElementById('crearDestino').value };
      origin.toggle.checked = b.custom; origin.input.value = b.text; document.getElementById('crearOrigen').value = b.office;
      destination.toggle.checked = a.custom; destination.input.value = a.text; document.getElementById('crearDestino').value = a.office;
      origin.update(); destination.update();
    }, true);
    const fill = async () => {
      if (modal.dataset.contractAuthorizing) return;
      checkbox.checked = false; update();
      q('#n360ContractDeparture').value = ''; q('#n360ContractEnd').value = '';
      const bus = q('#n360ContractBus');
      bus.innerHTML = '<option value="">Seleccionar unidad...</option>';
      if (!ready()) return;
      try {
        const res = await fetch('programacion_horarios.php?ajax=buses_disponibles', { credentials: 'same-origin' });
        const json = await res.json();
        if (!json.ok) throw new Error(json.message || 'No se pudieron cargar unidades.');
        bus.innerHTML += (json.data.buses || []).filter((b) => String(b.estado_programacion || '').toUpperCase() !== 'TALLER')
          .map((b) => {
            const reserved = state.rows.some((r) => !released(r) && Number(r.bus_id) === Number(b.clm_placas_id));
            return `<option value="${Number(b.clm_placas_id)}" ${reserved ? 'disabled' : ''}>${esc(b.clm_placas_BUS || b.bus)} / ${esc(b.clm_placas_PLACA || b.placa)}${reserved ? ' / RESERVADO' : ''}</option>`;
          }).join('');
      } catch (error) { bus.innerHTML = `<option value="">${esc(error.message)}</option>`; }
    };
    modal.addEventListener('show.bs.modal', fill);
    q('#n360ContractDeparture').addEventListener('input', () => {
      const value = q('#n360ContractDeparture').value;
      if (value) document.getElementById('crearHora').value = value.slice(11, 16);
      q('#n360ContractEnd').min = value;
      let operative = '-';
      if (value) {
        const day = new Date(`${value.slice(0, 10)}T12:00:00Z`);
        if (value.slice(11, 16) < '05:00') day.setUTCDate(day.getUTCDate() - 1);
        const iso = day.toISOString().slice(0, 10);
        operative = `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
      }
      q('[data-contract-operative]').textContent = operative;
      document.getElementById('crearHora').dispatchEvent(new Event('input', { bubbles: true }));
    });
    create = { checkbox, origin, destination };
  }
  function createData() {
    if (!create?.checkbox.checked) return { active: false };
    const values = { escontrato: '1', contrato_csrf: cfg.csrf,
      contrato_bus: q('#n360ContractBus').value, contrato_salida: q('#n360ContractDeparture').value,
      contrato_retorno: q('#n360ContractEnd').value,
      origen_libre: create.origin.toggle.checked ? create.origin.input.value.trim() : '',
      destino_libre: create.destination.toggle.checked ? create.destination.input.value.trim() : '' };
    let error = '';
    if (!values.contrato_bus || !values.contrato_salida || !values.contrato_retorno) error = 'Completa unidad, salida y retorno previsto.';
    if (values.contrato_retorno < values.contrato_salida) error = 'El retorno previsto no puede ser anterior a la salida.';
    if ((!values.origen_libre && !document.getElementById('crearOrigen').value) || (!values.destino_libre && !document.getElementById('crearDestino').value)) error = 'Completa el origen y destino del contrato.';
    return { active: true, values, error };
  }
  async function authorize(bus, knownRow) {
    if (!ready()) return { ok: true, password: '' };
    if (knownRow === undefined) await load();
    const row = knownRow || state.rows.find((r) => Number(r.bus_id) === Number(bus) && !released(r));
    if (!row) return { ok: true, password: '' };
    const el = document.getElementById('n360ContractAuthorization');
    const modal = bootstrap.Modal.getOrCreateInstance(el);
    q('[data-contract-auth-summary]', el).innerHTML = `<strong>${esc(row.bus)} / ${esc(row.plate)}</strong><p class="mb-1">${esc(row.origin)} &rarr; ${esc(row.destination)}</p><span class="n360-contract-status">Contrato #${Number(row.id)} / ${Number(row.expired) ? 'Pendiente de liberacion' : 'Reserva vigente'}</span><small class="d-block mt-2">Retorno previsto: ${date(row.return_expected)}</small>`;
    const input = q('input[type="password"]', el); input.value = '';
    return new Promise((resolve) => {
      let result = { ok: false, password: '' };
      const form = q('form', el);
      const submit = (event) => { event.preventDefault(); if (!input.value) return; result = { ok: true, password: input.value }; modal.hide(); };
      form.addEventListener('submit', submit);
      el.addEventListener('hidden.bs.modal', () => { form.removeEventListener('submit', submit); input.value = ''; resolve(result); }, { once: true });
      el.addEventListener('shown.bs.modal', () => input.focus(), { once: true });
      modal.show();
    });
  }
  function setupReturn() {
    const form = q('[data-csb-manual-form]');
    if (!form || !ready()) return;
    const block = document.createElement('div');
    block.className = 'n360-contract-return-picker';
    block.innerHTML = '<label for="n360ContractReturn">Retorno de contrato liberado</label><select id="n360ContractReturn" name="contrato_id" class="form-select form-select-sm"><option value="">Viaje manual independiente</option></select>';
    q('.modal-body', form).prepend(block);
    const select = q('select', block);
    const origin = placeControl(q('[name="idorigen"]', form), 'origen');
    const destination = placeControl(q('[name="iddestino"]', form), 'destino');
    origin.input.name = 'origen_libre'; destination.input.name = 'destino_libre';
    const update = () => {
      [origin, destination].forEach((c) => { c.label.hidden = !select.value; if (!select.value) c.toggle.checked = false; c.update(); });
      const row = state.rows.find((r) => Number(r.id) === Number(select.value));
      if (row) { q('[name="idplaca"]', form).value = row.bus_id; }
    };
    select.addEventListener('change', update);
    document.getElementById('csbManualTripModal').addEventListener('show.bs.modal', async () => {
      if (document.getElementById('csbManualTripModal').dataset.contractAuthorizing) return;
      try {
        await load();
        select.innerHTML = '<option value="">Viaje manual independiente</option>' + state.rows.filter(pending).map((r) => `<option value="${Number(r.id)}">#${Number(r.id)} / ${esc(r.bus)} - ${esc(r.plate)} / ${esc(r.origin)} - ${esc(r.destination)}</option>`).join('');
        update();
      } catch (error) { select.innerHTML = `<option value="">${esc(error.message)}</option>`; }
    });
    document.getElementById('csbManualTripModal').addEventListener('hidden.bs.modal', () => { if (!document.getElementById('csbManualTripModal').dataset.contractAuthorizing) { select.value = ''; update(); } });
  }
  function init() {
    if (!window.bootstrap) return;
    setupCreate(); setupReturn();
    if (window.horariosInitialData?.contratos) state.rows = window.horariosInitialData.contratos;
    render();
    document.querySelectorAll('[data-contract-open]').forEach((button) => button.addEventListener('click', async () => {
      bootstrap.Modal.getOrCreateInstance(document.getElementById('n360ContractsModal')).show();
      q('[data-contract-notice]').classList.add('d-none');
      try { await load(); } catch (error) { notice(error.message); }
    }));
    q('[data-contract-refresh]')?.addEventListener('click', () => load().catch((e) => notice(e.message)));
    q('[data-contract-search]')?.addEventListener('input', render);
    document.querySelectorAll('[data-contract-tab]').forEach((b) => b.addEventListener('click', () => { state.tab = b.dataset.contractTab; render(); }));
    q('[data-contract-rows]')?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-contract-release], [data-contract-save-end]');
      if (!button || state.busy) return;
      const id = Number(button.dataset.contractRelease || button.dataset.contractSaveEnd);
      const row = state.rows.find((r) => Number(r.id) === id);
      if (!row) return;
      if (button.hasAttribute('data-contract-save-end')) {
        change('end', row, { return_expected: q(`[data-contract-end="${id}"]`).value });
      } else {
        state.confirm = row;
        q('[data-contract-confirm-label]').textContent = `Liberar ${row.bus} / ${row.plate}, contrato #${id}?`;
        q('[data-contract-confirm]').classList.remove('d-none');
      }
    });
    q('[data-contract-confirm-yes]')?.addEventListener('click', () => { if (state.confirm) change('release', state.confirm); });
    q('[data-contract-confirm-no]')?.addEventListener('click', () => q('[data-contract-confirm]').classList.add('d-none'));
  }
  async function authorizeManual(form) {
    if (!ready()) return { ok: true, password: '' };
    await load();
    const bus = q('[name="idplaca"]', form).value;
    const row = state.rows.find((r) => Number(r.bus_id) === Number(bus) && !released(r));
    if (!row) return { ok: true, password: '' };
    const el = form.closest('.modal');
    const modal = bootstrap.Modal.getOrCreateInstance(el);
    el.dataset.contractAuthorizing = '1';
    try {
      await new Promise((resolve) => { el.addEventListener('hidden.bs.modal', resolve, { once: true }); modal.hide(); });
      return await authorize(bus, row);
    } finally {
      await new Promise((resolve) => { el.addEventListener('shown.bs.modal', resolve, { once: true }); modal.show(); });
      delete el.dataset.contractAuthorizing;
    }
  }
  window.N360Contracts = { createData, authorize, authorizeManual,
    csrf: () => cfg.csrf || '',
    update: (snapshot) => {
      if (snapshot.oficinas_destino) state.offices = snapshot.oficinas_destino;
      if (snapshot.oficinas_origen) state.origins = snapshot.oficinas_origen;
      if (snapshot.contratos) { state.rows = snapshot.contratos; state.loaded = true; render(); }
    },
    open: () => q('[data-contract-open]')?.click() };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})();
