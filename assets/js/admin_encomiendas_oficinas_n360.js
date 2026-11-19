(() => {
  'use strict';
  const root = document.querySelector('[data-enc-offices]');
  if (!root) return;
  const query = root.querySelector('[data-office-search]');
  const size = root.querySelector('[data-office-size]');
  const range = root.querySelector('[data-office-range]');
  const pager = root.querySelector('[data-office-pages]');
  const empty = root.querySelector('[data-office-empty]');
  const feedback = root.querySelector('[data-offices-feedback]');
  const rows = Array.from(root.querySelectorAll('[data-office-row]'));
  const filters = Array.from(root.querySelectorAll('[data-office-filter]'));
  let state = filters.find(button => button.getAttribute('aria-pressed') === 'true')?.dataset.officeFilter || 'todas';
  let page = Math.max(1, Number(root.dataset.initialPage) || 1);
  const normalize = text => String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
  const notice = (text, error = false) => {
    feedback.hidden = false;
    feedback.classList.toggle('enc-offices-notice--error', error);
    feedback.classList.toggle('enc-offices-notice--success', !error);
    feedback.setAttribute('role', error ? 'alert' : 'status');
    feedback.querySelector('[data-offices-feedback-text]').textContent = text;
    feedback.querySelector('i').className = `bi ${error ? 'bi-exclamation-circle' : 'bi-check-circle'}`;
  };
  const pageButton = (label, number, disabled = false, current = false) => {
    const button = document.createElement('button');
    button.type = 'button'; button.textContent = label; button.disabled = disabled;
    if (current) button.setAttribute('aria-current', 'page');
    button.setAttribute('aria-label', /^\d+$/.test(label) ? `Ir a página ${number}` : label);
    button.addEventListener('click', () => { page = number; render(); });
    pager.append(button);
  };
  const render = () => {
    const tokens = normalize(query.value.trim()).split(/\s+/).filter(Boolean);
    const linked = rows.filter(row => row.dataset.officeLinked === '1').length;
    const counts = { total: rows.length, pending: rows.length - linked, linked };
    Object.entries(counts).forEach(([key, count]) => { root.querySelector(`[data-office-count="${key}"]`).textContent = count; });
    const tabCounts = { todas: counts.total, pendientes: counts.pending, vinculadas: counts.linked };
    filters.forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.officeFilter === state));
      button.querySelector('[data-office-filter-count]').textContent = tabCounts[button.dataset.officeFilter];
    });
    const matching = rows.filter(row => {
      const isLinked = row.dataset.officeLinked === '1';
      const text = normalize(`${row.dataset.officeName} ${row.dataset.officeSede}`);
      return (state === 'todas' || (state === 'vinculadas' ? isLinked : !isLinked)) && tokens.every(token => text.includes(token));
    });
    const perPage = size.value === 'todos' ? Math.max(1, matching.length) : Number(size.value);
    const pages = Math.max(1, Math.ceil(matching.length / perPage));
    page = Math.min(page, pages);
    rows.forEach(row => { row.hidden = true; });
    matching.slice((page - 1) * perPage, page * perPage).forEach(row => { row.hidden = false; });
    empty.hidden = matching.length > 0;
    range.textContent = matching.length ? `${(page - 1) * perPage + 1}–${Math.min(page * perPage, matching.length)} de ${matching.length} oficinas` : '0 oficinas';
    pager.replaceChildren();
    if (rows.length) {
      pageButton('Anterior', Math.max(1, page - 1), page === 1);
      let previous = 0;
      const numbers = [...new Set([1, ...Array.from({ length: Math.min(pages, page + 2) - Math.max(1, page - 2) + 1 }, (_, n) => n + Math.max(1, page - 2)), pages])];
      numbers.forEach(number => {
        if (previous && number > previous + 1) { const dots = document.createElement('span'); dots.textContent = '…'; pager.append(dots); }
        pageButton(String(number), number, false, number === page); previous = number;
      });
      pageButton('Siguiente', Math.min(pages, page + 1), page === pages);
    }
    const url = new URL(location.href);
    const params = { buscar: query.value.trim(), estado: state === 'todas' ? '' : state, pagina: page > 1 ? page : '', tamano: size.value === '10' ? '' : size.value };
    url.search = '';
    Object.entries(params).forEach(([key, value]) => { if (value !== '') url.searchParams.set(key, value); });
    history.replaceState(null, '', url);
    const refresh = root.querySelector('[data-office-refresh]');
    if (refresh) refresh.href = url.toString();
  };
  query.addEventListener('input', () => { page = 1; render(); });
  root.querySelector('[data-office-search-form]').addEventListener('submit', event => { event.preventDefault(); page = 1; render(); });
  root.querySelector('[data-office-clear]').addEventListener('click', () => { query.value = ''; state = 'todas'; page = 1; render(); query.focus(); });
  size.addEventListener('change', () => { page = 1; render(); });
  filters.forEach(button => button.addEventListener('click', () => { state = button.dataset.officeFilter; page = 1; render(); }));
  rows.forEach(row => {
    const form = row.querySelector('[data-office-form]');
    const select = form.elements.sede_id;
    const expected = form.elements.sede_esperada;
    const button = form.querySelector('button[type=submit]');
    const message = form.querySelector('[data-office-message]');
    let busy = false;
    const sync = () => {
      const changed = select.value !== expected.value;
      button.disabled = busy || !changed;
      row.classList.toggle('has-changes', changed);
      message.classList.remove('is-error', 'is-success');
      message.textContent = changed ? 'Cambio pendiente de guardar' : '';
    };
    select.addEventListener('change', sync); sync();
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (busy || button.disabled) return;
      const data = new FormData(form); busy = true; button.disabled = true; select.disabled = true;
      button.querySelector('span').textContent = 'Guardando…'; form.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar la equivalencia.');
        const office = result.office;
        form.elements.id.value = String(office.id);
        expected.value = String(office.sede_id); select.value = String(office.sede_id);
        row.querySelector('[data-office-source-note]').textContent = 'Nombre en Kupos';
        row.dataset.officeLinked = office.sede_id ? '1' : '0'; row.dataset.officeSede = office.sede_nombre;
        const badge = row.querySelector('[data-office-status]');
        badge.classList.toggle('is-linked', !!office.sede_id); badge.classList.toggle('is-pending', !office.sede_id);
        badge.querySelector('span').textContent = office.sede_id ? 'Vinculada' : 'Sin vincular';
        badge.querySelector('i').className = `bi ${office.sede_id ? 'bi-check2-circle' : 'bi-link-45deg'}`;
        row.querySelector('[data-office-current]').textContent = office.sede_nombre || 'Pendiente de asignación';
        row.classList.remove('has-changes'); message.classList.remove('is-error'); message.classList.add('is-success'); message.textContent = 'Guardado';
        notice(`${office.nombre}: ${office.sede_nombre ? `vinculada a ${office.sede_nombre}` : 'sin vincular'}.`);
        render();
      } catch (error) {
        message.classList.remove('is-success'); message.classList.add('is-error');
        message.textContent = error.message || 'No se pudo guardar. Inténtalo nuevamente.';
        notice(message.textContent, true);
      } finally {
        busy = false; select.disabled = false; button.disabled = select.value === expected.value;
        button.querySelector('span').textContent = 'Guardar'; form.removeAttribute('aria-busy');
        if (row.hidden && (form.contains(document.activeElement) || document.activeElement === document.body)) {
          const next = rows.find(candidate => !candidate.hidden); (next?.querySelector('select') || query).focus({ preventScroll: true });
        }
      }
    });
  });
  render();
})();
