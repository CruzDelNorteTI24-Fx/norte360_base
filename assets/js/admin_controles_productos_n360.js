(function () {
  'use strict';
  const dialog = document.getElementById('controlesCatalogDialog');
  if (!dialog) return;
  const title = document.getElementById('controlesCatalogTitle');
  const context = document.getElementById('controlesCatalogContext');
  const filters = document.getElementById('controlesCatalogFilters');
  const form = document.getElementById('controlesCatalogSearch');
  const query = document.getElementById('controlesCatalogQuery');
  const status = document.getElementById('controlesCatalogStatus');
  const error = document.getElementById('controlesCatalogError');
  const retry = document.getElementById('controlesCatalogRetry');
  const rows = document.getElementById('controlesCatalogRows');
  const prev = document.getElementById('controlesCatalogPrev');
  const next = document.getElementById('controlesCatalogNext');
  const pageText = document.getElementById('controlesCatalogPage');
  const formatter = new Intl.NumberFormat('es-PE');
  let selected = null, opener = null, page = 1, pages = 1, controller = null;
  let sequence = 0, timer = null, busy = false, backdropStart = false;

  function message(text) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 5;
    cell.className = 'controles-catalog__empty';
    cell.textContent = text;
    row.append(cell);
    rows.replaceChildren(row);
  }
  function setBusy(value) {
    busy = value;
    rows.parentElement.setAttribute('aria-busy', String(value));
    prev.disabled = value || page <= 1;
    next.disabled = value || page >= pages;
    retry.disabled = value;
  }
  function renderProducts(products) {
    const fragment = document.createDocumentFragment();
    products.forEach(product => {
      const row = document.createElement('tr');
      const code = document.createElement('td');
      code.className = 'controles-catalog__code';
      code.textContent = product.codigo;
      const name = document.createElement('td');
      const label = document.createElement('strong');
      label.textContent = product.nombre;
      const category = document.createElement('small');
      category.textContent = product.categoria || 'Sin categoría';
      name.append(label, category);
      row.append(code, name);
      ['unidad', 'area', 'tipo'].forEach(key => {
        const cell = document.createElement('td');
        cell.textContent = product[key] || '-';
        row.append(cell);
      });
      fragment.append(row);
    });
    rows.replaceChildren(fragment);
  }
  async function load(requestedPage) {
    if (!selected || !dialog.open) return;
    clearTimeout(timer);
    controller?.abort();
    controller = new AbortController();
    const version = ++sequence;
    const search = query.value.trim();
    const url = new URL(dialog.dataset.api, window.location.href);
    url.searchParams.set('scope', selected.scope);
    url.searchParams.set('id', selected.id);
    url.searchParams.set('q', search);
    url.searchParams.set('page', requestedPage);
    error.hidden = true;
    status.textContent = 'Consultando productos...';
    message('Cargando catálogo...');
    setBusy(true);
    try {
      const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, signal: controller.signal});
      const data = await response.json();
      if (version !== sequence || !dialog.open) return;
      if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo consultar el catálogo.');
      title.textContent = data.context.title;
      filters.replaceChildren();
      [['Categoría', data.context.categoria], ['Área', data.context.area], ['Tipo', data.context.tipo]].forEach(([name, value]) => {
        if (!value) return;
        const badge = document.createElement('span');
        badge.textContent = name + ': ' + value;
        filters.append(badge);
      });
      page = data.page;
      pages = data.pages;
      pageText.textContent = `Página ${formatter.format(page)} de ${formatter.format(pages)}`;
      if (!data.rows.length) {
        message(search ? 'No se encontraron productos para esta búsqueda.' : (selected.scope === 'categorias'
          ? 'Esta categoría no tiene productos registrados.' : 'No hay productos asignados a este control.'));
        status.textContent = '0 productos encontrados';
      } else {
        renderProducts(data.rows);
        const start = (page - 1) * data.page_size + 1;
        const end = start + data.rows.length - 1;
        status.textContent = `Mostrando ${formatter.format(start)}-${formatter.format(end)} de ${formatter.format(data.total)} productos`;
      }
      dialog.querySelector('.controles-catalog__body').scrollTop = 0;
    } catch (failure) {
      if (failure.name === 'AbortError' || version !== sequence || !dialog.open) return;
      status.textContent = '';
      pageText.textContent = '';
      pages = page = 1;
      message('El catálogo no está disponible temporalmente.');
      error.querySelector('span').textContent = failure instanceof SyntaxError ? 'No se pudo consultar el catálogo. Actualiza la página e intenta nuevamente.' : failure.message;
      error.hidden = false;
    } finally {
      if (version === sequence && dialog.open) setBusy(false);
    }
  }
  document.querySelectorAll('[data-control-catalog]').forEach(button => button.addEventListener('click', () => {
    if (dialog.open) return;
    opener = button;
    selected = {scope: button.dataset.controlScope, id: button.dataset.controlId};
    page = pages = 1;
    form.reset();
    filters.replaceChildren();
    title.textContent = 'Catálogo de productos';
    context.textContent = button.dataset.controlLabel;
    pageText.textContent = '';
    dialog.showModal();
    document.body.classList.add('controles-catalog-open');
    query.focus();
    load(1);
  }));
  dialog.querySelectorAll('[data-catalog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      dialog.close();
    }
  });
  dialog.addEventListener('close', () => {
    // Un cierre anterior puede notificarse después de abrir otra categoría.
    if (dialog.open) return;
    clearTimeout(timer);
    sequence++;
    controller?.abort();
    selected = null;
    document.body.classList.remove('controles-catalog-open');
    if (opener?.isConnected) opener.focus({preventScroll: true});
  });
  const outside = event => {
    const bounds = dialog.getBoundingClientRect();
    return event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
  };
  dialog.addEventListener('pointerdown', event => { backdropStart = event.target === dialog && outside(event); });
  dialog.addEventListener('click', event => {
    if (backdropStart && event.target === dialog && outside(event)) dialog.close();
    backdropStart = false;
  });
  form.addEventListener('submit', event => { event.preventDefault(); load(1); });
  query.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(1), 300); });
  prev.addEventListener('click', () => { if (!busy && page > 1) load(page - 1); });
  next.addEventListener('click', () => { if (!busy && page < pages) load(page + 1); });
  retry.addEventListener('click', () => load(page));
})();
