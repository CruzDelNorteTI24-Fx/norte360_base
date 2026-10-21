(function () {
  'use strict';
  if (!window.N360_CONDT_FECHAS?.enabled) return;
  const open = document.getElementById('btnCalendarioConductores');
  if (!open) return;
  const escape = condtEscapeHtml;
  const labels = { trabajo: 'Trabajo autorizado', descanso: 'Descanso permitido', sin: 'Sin indicaci\u00f3n' };
  const shortLabels = { trabajo: 'Trabajo', descanso: 'Descanso', sin: 'Sin indicar' };
  const icons = { trabajo: 'bi-briefcase', descanso: 'bi-moon', sin: 'bi-dash-circle' };
  const keys = Object.keys(labels);
  const peruToday = () => {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(new Date()).reduce((result, part) => ({ ...result, [part.type]: part.value }), {});
    return `${parts.year}-${parts.month}-${parts.day}`;
  };
  let todayKey = peruToday();
  const utcDate = value => new Date(`${value}T00:00:00Z`);
  const iso = value => value.toISOString().slice(0, 10);
  const monthLength = value => new Date(Date.UTC(Number(value.slice(0, 4)), Number(value.slice(5, 7)), 0)).getUTCDate();
  const fullDate = value => new Intl.DateTimeFormat('es-PE', { dateStyle: 'full', timeZone: 'UTC' }).format(utcDate(value));
  const monthName = value => new Intl.DateTimeFormat('es-PE', { year: 'numeric', month: 'long', timeZone: 'UTC' }).format(utcDate(`${value}-01`));
  const count = value => value > 999 ? '999+' : String(value);
  let people = [], month = todayKey.slice(0, 7), selected = todayKey, rows = [], days = [], counts = [];

  const root = document.createElement('div');
  root.id = 'condtCalendarioModal';
  root.className = 'modal fade condt-fechas-modal condt-cal-modal';
  root.tabIndex = -1;
  root.setAttribute('aria-hidden', 'true');
  root.setAttribute('aria-labelledby', 'condtCalTitle');
  root.innerHTML = `<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title" id="condtCalTitle"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Calendario de conductores</h5><div class="condt-cal-scope" data-cal-scope></div></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <div class="condt-cal-toolbar"><div class="condt-cal-navigation">
        <button type="button" class="condt-fechas-icon" data-cal-step="-1" title="Mes anterior" aria-label="Mes anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <label>Mes<input type="month" min="1000-01" max="9999-12" class="form-control" data-cal-month></label>
        <button type="button" class="condt-fechas-icon" data-cal-step="1" title="Mes siguiente" aria-label="Mes siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        <button type="button" class="condt-btn condt-btn-light condt-btn-xs" data-cal-today>Hoy</button>
      </div><label class="condt-cal-person">Conductor<select class="form-select" data-cal-person></select></label></div>
      <div class="condt-cal-tabs nav" role="tablist" aria-label="Vista del calendario">
        <button class="active" id="condtCalTabMonth" data-bs-toggle="tab" data-bs-target="#condtCalMonthView" type="button" role="tab" aria-controls="condtCalMonthView" aria-selected="true"><i class="bi bi-calendar3" aria-hidden="true"></i>Calendario</button>
        <button id="condtCalTabPeople" data-bs-toggle="tab" data-bs-target="#condtCalPeopleView" type="button" role="tab" aria-controls="condtCalPeopleView" aria-selected="false"><i class="bi bi-people" aria-hidden="true"></i>Resumen mensual</button>
      </div>
      <div class="tab-content"><div class="tab-pane show active" id="condtCalMonthView" role="tabpanel" aria-labelledby="condtCalTabMonth">
        <div class="condt-cal-overview"><div class="condt-cal-date-heading"><span class="condt-cal-eyebrow">D&iacute;a seleccionado</span><h6 data-cal-date></h6></div><div class="condt-cal-totals" data-cal-totals aria-live="polite"></div></div>
        <div class="condt-cal-layout"><div class="condt-cal-month"><div class="condt-cal-month-heading"><h6 data-cal-month-title></h6>
          <div class="condt-cal-legend">${keys.map(key => `<span class="condt-cal-${key}" title="${labels[key]}"><i class="bi ${icons[key]}" aria-hidden="true"></i>${shortLabels[key]}</span>`).join('')}</div></div>
          <div class="condt-cal-week" aria-hidden="true">${['Lun', 'Mar', 'Mi\u00e9', 'Jue', 'Vie', 'S\u00e1b', 'Dom'].map(day => `<span>${day}</span>`).join('')}</div>
          <div class="condt-cal-grid" data-cal-grid role="group" aria-label="Dias del mes"></div>
        </div><div class="condt-cal-detail"><h6>Conductores del d&iacute;a</h6>
          <label>Situaci&oacute;n<select class="form-select" data-cal-status><option value="">Todos</option>${keys.map(key => `<option value="${key}">${labels[key]}</option>`).join('')}</select></label>
          <div class="condt-cal-day-list" data-cal-list></div>
        </div></div>
      </div><div class="tab-pane" id="condtCalPeopleView" role="tabpanel" aria-labelledby="condtCalTabPeople">
        <h6 class="condt-cal-report-title" data-cal-report-title></h6><div class="condt-cal-table-wrap"><table class="condt-cal-table"><thead><tr><th scope="col">Conductor</th><th scope="col" class="condt-cal-trabajo">Trabajo<small>D&iacute;as</small></th><th scope="col" class="condt-cal-descanso">Descanso<small>D&iacute;as</small></th><th scope="col" class="condt-cal-sin"><span class="condt-cal-full-label">Sin indicaci&oacute;n</span><abbr class="condt-cal-short-label" title="Sin indicaci&oacute;n">Sin indic.</abbr><small>D&iacute;as</small></th></tr></thead><tbody data-cal-table></tbody><tfoot data-cal-foot></tfoot></table></div>
      </div></div>
    </div><div class="modal-footer"><button type="button" class="condt-btn condt-btn-light" data-bs-dismiss="modal">Cerrar</button></div>
  </div></div>`;
  document.body.append(root);
  const modal = bootstrap.Modal.getOrCreateInstance(root);
  const field = name => root.querySelector(`[data-cal-${name}]`);

  function collect() {
    const panel = CONDT_STATE.panel;
    const query = document.getElementById('txtBuscarUnidadPanel').value.trim().toLowerCase();
    const groups = condtGroupFlotas(panel.flotas).filter(group => condtMatchesUnitSearch(group, query));
    const map = new Map();
    const add = (person, unit) => {
      const id = Number(person.clm_progconductores_idconductor);
      if (!Number.isInteger(id) || id <= 0) return;
      if (map.has(id)) { map.get(id).units.add(unit); return; }
      map.set(id, { id, name: person.conductor || '-', units: new Set([unit]), data: panel.fechas?.[id] || {} });
    };
    groups.forEach(group => group.slots.forEach(person => add(person, `${group.bus || '-'} (${group.placa || '-'})`)));
    (panel.retenes || []).forEach(person => add(person, 'Ret\u00e9n'));
    people = Array.from(map.values()).map(person => ({ ...person, unit: Array.from(person.units).join(' / ') }));
    const reserves = (panel.retenes || []).length;
    field('scope').textContent = `${people.length} ${people.length === 1 ? 'conductor' : 'conductores'} | ${groups.length} ${groups.length === 1 ? 'unidad' : 'unidades'} | ${reserves} ${reserves === 1 ? 'ret\u00e9n' : 'retenes'}`;
    field('person').innerHTML = `<option value="">Todos (${people.length})</option>${people.map(person => `<option value="${person.id}">${escape(person.name)} - ${escape(person.unit)}</option>`).join('')}`;
  }

  function status(person, day) {
    const data = person.data;
    if ((data.descansos || []).some(period => period.desde <= day && day <= period.hasta)) return 'descanso';
    if (data.trabajo_desde && data.trabajo_hasta && data.trabajo_desde <= day && day <= data.trabajo_hasta) return 'trabajo';
    return 'sin';
  }

  function render() {
    todayKey = peruToday();
    const length = monthLength(month);
    days = Array.from({ length }, (_,index) => `${month}-${String(index + 1).padStart(2, '0')}`);
    if (!days.includes(selected)) selected = days[Math.min(Number(selected.slice(8)) || 1, length) - 1];
    const id = Number(field('person').value);
    rows = people.filter(person => !id || person.id === id).map(person => {
      const states = days.map(day => status(person, day));
      const totals = { trabajo: 0, descanso: 0, sin: 0 };
      states.forEach(key => totals[key]++);
      return { ...person, states, totals };
    });
    counts = days.map((_, index) => rows.reduce((totals, person) => { totals[person.states[index]]++; return totals; }, { trabajo: 0, descanso: 0, sin: 0 }));
    field('month').value = month;
    field('month-title').textContent = monthName(month);
    field('report-title').textContent = `Resumen mensual - ${monthName(month)}`;
    root.querySelector('[data-cal-step="-1"]').disabled = month === '1000-01';
    root.querySelector('[data-cal-step="1"]').disabled = month === '9999-12';
    const offset = (utcDate(`${month}-01`).getUTCDay() + 6) % 7;
    field('grid').innerHTML = Array.from({ length: 42 }, (_, cell) => {
      const index = cell - offset;
      if (index < 0 || index >= length) return '<span class="condt-cal-blank" aria-hidden="true"></span>';
      const day = days[index], totals = counts[index];
      const label = `${fullDate(day)}. ${keys.map(key => `${labels[key]}: ${totals[key]}`).join('. ')}`;
      return `<button type="button" class="condt-cal-day${day === todayKey ? ' is-today' : ''}" data-cal-day="${day}" aria-label="${escape(label)}" title="${escape(label)}" aria-pressed="${day === selected}" tabindex="${day === selected ? '0' : '-1'}"${day === todayKey ? ' aria-current="date"' : ''}>
        <span class="condt-cal-day-top"><span class="condt-cal-number">${index + 1}</span><i class="bi bi-check2 condt-cal-selected-mark" aria-hidden="true"></i></span>${keys.map(key => `<span class="condt-cal-count condt-cal-${key}"><i class="bi ${icons[key]}" aria-hidden="true"></i><span class="condt-cal-count-label">${shortLabels[key]}</span><b>${count(totals[key])}</b></span>`).join('')}</button>`;
    }).join('');
    field('table').innerHTML = rows.length ? rows.map(person => {
      const description = keys.map(key => `${labels[key]}: ${person.totals[key]} d\u00edas`).join('. ');
      return `<tr data-cal-person-row="${person.id}"><th scope="row">${escape(person.name)}<small>${escape(person.unit)}</small><span class="condt-cal-distribution" role="img" aria-label="${escape(description)}" title="${escape(description)}">${keys.map(key => `<span class="condt-cal-${key}" style="width:${person.totals[key] / days.length * 100}%"></span>`).join('')}</span></th>${keys.map(key => `<td data-cal-total="${key}" class="condt-cal-${key}">${person.totals[key]}</td>`).join('')}</tr>`;
    }).join('') : '<tr><td colspan="4" class="condt-cal-empty">No hay conductores en los resultados actuales.</td></tr>';
    const totals = rows.reduce((result, person) => { keys.forEach(key => { result[key] += person.totals[key]; }); return result; }, { trabajo: 0, descanso: 0, sin: 0 });
    field('foot').innerHTML = `<tr><th scope="row">Total de d&iacute;as<small>${rows.length} ${rows.length === 1 ? 'conductor' : 'conductores'}</small></th>${keys.map(key => `<td class="condt-cal-${key}">${totals[key]}</td>`).join('')}</tr>`;
    renderDay();
  }

  function renderDay() {
    const index = days.indexOf(selected), totals = counts[index];
    field('date').textContent = fullDate(selected);
    field('totals').innerHTML = keys.map(key => `<span class="condt-cal-${key}"><strong data-cal-day-total="${key}">${totals[key]}</strong><span>${labels[key]}</span></span>`).join('');
    const filter = field('status').value;
    const list = rows.filter(person => !filter || person.states[index] === filter);
    field('list').innerHTML = list.length ? keys.map(key => {
      const group = list.filter(person => person.states[index] === key);
      if (!group.length) return '';
      return `<div class="condt-cal-group" role="group" aria-label="${labels[key]}"><div class="condt-cal-group-title condt-cal-${key}"><span><i class="bi ${icons[key]}" aria-hidden="true"></i>${labels[key]}</span><strong>${group.length}</strong></div>${group.map(person => `<div class="condt-cal-person-row"><strong>${escape(person.name)}</strong><small>${escape(person.unit)}</small></div>`).join('')}</div>`;
    }).join('') : `<p class="condt-cal-empty">${rows.length ? 'No hay conductores con este estado.' : 'No hay conductores en los resultados actuales.'}</p>`;
  }

  function selectDay(value, focus = false) {
    selected = value;
    if (value.slice(0, 7) !== month) { month = value.slice(0, 7); render(); }
    else {
      field('grid').querySelectorAll('[data-cal-day]').forEach(button => {
        const active = button.dataset.calDay === value;
        button.setAttribute('aria-pressed', String(active)); button.tabIndex = active ? 0 : -1;
      });
      renderDay();
    }
    if (focus) field('grid').querySelector(`[data-cal-day="${selected}"]`)?.focus();
  }

  function moveMonth(delta) {
    const value = utcDate(`${month}-01`);
    value.setUTCMonth(value.getUTCMonth() + delta);
    if (value.getUTCFullYear() < 1000 || value.getUTCFullYear() > 9999) return;
    month = iso(value).slice(0, 7); render();
  }

  open.addEventListener('click', () => {
    if (open.disabled || CONDT_STATE.panel?.fechas_disponibles !== true || document.querySelector('.condt-fechas-modal.show')) return;
    collect(); field('status').value = ''; render(); modal.show(open);
  });
  field('month').addEventListener('change', () => {
    const value = field('month').value;
    if (/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/.test(value)) { month = value; render(); }
    else field('month').value = month;
  });
  field('person').addEventListener('change', render);
  field('status').addEventListener('change', renderDay);
  root.querySelectorAll('[data-cal-step]').forEach(button => button.addEventListener('click', () => moveMonth(Number(button.dataset.calStep))));
  field('today').addEventListener('click', () => { todayKey = peruToday(); month = todayKey.slice(0, 7); selected = todayKey; render(); });
  field('grid').addEventListener('click', event => { const day = event.target.closest('[data-cal-day]'); if (day) selectDay(day.dataset.calDay); });
  field('grid').addEventListener('keydown', event => {
    const button = event.target.closest('[data-cal-day]');
    if (!button) return;
    const value = utcDate(button.dataset.calDay), weekday = (value.getUTCDay() + 6) % 7;
    const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -weekday, End: 6 - weekday };
    if (!Object.hasOwn(steps, event.key)) return;
    event.preventDefault(); value.setUTCDate(value.getUTCDate() + steps[event.key]);
    if (value.getUTCFullYear() >= 1000 && value.getUTCFullYear() <= 9999) selectDay(iso(value), true);
  });
  root.addEventListener('show.bs.modal', () => document.body.classList.add('condt-fechas-open'));
  root.addEventListener('hidden.bs.modal', () => { document.body.classList.remove('condt-fechas-open'); open.focus(); });
  if (CONDT_STATE.panel) window.CondtFechas?.sync(CONDT_STATE.panel);
})();
