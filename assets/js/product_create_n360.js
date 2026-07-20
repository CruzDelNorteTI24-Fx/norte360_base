(function () {
  'use strict';

  const modal = document.getElementById('n360ProductCreateModal');
  const form = document.getElementById('n360ProductCreateForm');
  if (!modal || !form) return;

  const configNode = document.getElementById('n360ProductCreateConfig') || document.getElementById('almMovPage');
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const getConfig = () => {
    const dataset = configNode?.dataset || {};
    const originId = dataset.productOriginId || dataset.originId || '1';
    const originArea = dataset.productOriginArea || dataset.originArea || (originId === '4' ? 'RRHH' : 'ALMACEN');
    const originTipo = dataset.productOriginTipo || dataset.originTipo || (originId === '4' ? 'BIEN_CONTROLADO' : 'CONSUMIBLE');

    return {
      api: dataset.productApi || dataset.api || 'movimiento_api.php',
      csrf: dataset.productCsrf || dataset.csrf || '',
      originId,
      originLabel: dataset.productOriginLabel || dataset.originLabel || (originId === '4' ? 'RRHH' : 'ALMACEN (ALM)'),
      originArea,
      originTipo,
      isAdmin: (dataset.productIsAdmin || dataset.isAdmin) === '1',
      afterCreate: dataset.productAfterCreate || 'event',
    };
  };

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const alertBox = (message, variant = 'info', title = '') => {
    if (window.N360Dialog?.alert) {
      return window.N360Dialog.alert(message, { variant, title });
    }
    window.alert(message);
    return Promise.resolve(true);
  };

  const withLoader = async (task, options = {}) => {
    if (window.N360Loader?.during) {
      return window.N360Loader.during(task, options);
    }
    return task();
  };

  const status = (message, variant = 'info') => {
    const box = $('#n360ProductCreateStatus');
    if (!box) return;
    box.textContent = message;
    box.dataset.variant = variant;
  };

  const syncModalOpenClass = () => {
    document.body.classList.toggle('alm-modal-open', $$('.alm-modal.is-open').length > 0);
  };

  const openModal = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    syncModalOpenClass();
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    syncModalOpenClass();
  };

  const setBusy = (busy) => {
    const btn = $('#n360ProductCreateSubmit');
    if (btn) btn.disabled = busy;
    form.dataset.busy = busy ? '1' : '0';
  };

  async function fetchJson(action, options = {}) {
    const cfg = getConfig();
    let actionName = String(action);
    let suffix = '';
    const ampIndex = actionName.indexOf('&');
    if (ampIndex !== -1) {
      suffix = '&' + actionName.slice(ampIndex + 1);
      actionName = actionName.slice(0, ampIndex);
    }

    const headers = new Headers(options.headers || {});
    headers.set('X-N360-CSRF', cfg.csrf);

    const response = await fetch(`${cfg.api}?action=${encodeURIComponent(actionName)}${suffix}`, {
      credentials: 'same-origin',
      ...options,
      headers,
    });

    const text = (await response.text()).replace(/^\uFEFF/, '');
    let data = null;
    try {
      data = JSON.parse(text);
    } catch (error) {
      throw new Error('Respuesta invalida del servidor.');
    }

    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'No se pudo completar la operacion.');
    }
    return data;
  }

  function applyContext() {
    const cfg = getConfig();
    $('#n360ProductCreateCsrf').value = cfg.csrf;
    $('#n360ProductCreateOriginId').value = cfg.originId;
    $('#n360ProductCreateArea').value = cfg.originArea;
    $('#n360ProductCreateTipo').value = cfg.originTipo;

    const origin = $('#n360ProductCreateOriginText');
    const control = $('#n360ProductCreateControlText');
    if (origin) origin.textContent = cfg.originLabel;
    if (control) control.textContent = `${cfg.originArea} / ${cfg.originTipo}`;
  }

  function resetForm() {
    form.reset();
    $('#n360ProductCreateStockMin').value = '0';
    $('#n360ProductCreateFileHint').textContent = 'Opcional. Maximo 4 MB.';
    status('Completa los datos para crear el producto.');
    applyContext();
  }

  async function loadCategories() {
    const select = $('#n360ProductCreateCategoria');
    if (!select) return;
    select.innerHTML = '<option value="">Cargando categorias...</option>';
    const cfg = getConfig();
    const data = await fetchJson(`categorias_producto&origin_id=${encodeURIComponent(cfg.originId)}`);
    const rows = data.rows || [];

    if (!rows.length) {
      select.innerHTML = '<option value="">Sin categorias disponibles</option>';
      return;
    }

    select.innerHTML = '<option value="">Selecciona categoria</option>' + rows.map((row) => {
      const group = row.grupo ? ` - ${row.grupo}` : '';
      return `<option value="${esc(row.id)}">${esc(row.descripcion || row.nombre || 'Categoria')}${esc(group)}</option>`;
    }).join('');
  }

  async function openCreate() {
    resetForm();
    openModal();
    try {
      await loadCategories();
      window.setTimeout(() => $('#n360ProductCreateCategoria')?.focus(), 50);
    } catch (error) {
      status(error.message, 'error');
      alertBox(error.message, 'error', 'Nuevo producto');
    }
  }

  function handleAfterCreate(data) {
    const cfg = getConfig();
    document.dispatchEvent(new CustomEvent('n360:product-created', {
      detail: {
        product: data.product || null,
        context: data.context || null,
      },
    }));

    if (cfg.afterCreate === 'reload') {
      const product = data.product || {};
      const url = new URL(window.location.href);
      if (product.codigo) {
        url.searchParams.set('codigo', product.codigo);
      }
      url.searchParams.delete('pagina');
      window.location.href = url.toString();
    }
  }

  document.addEventListener('click', (event) => {
    const openBtn = event.target.closest('[data-n360-product-create-open]');
    if (openBtn) {
      event.preventDefault();
      openCreate();
      return;
    }

    const closeBtn = event.target.closest('[data-n360-product-create-close]');
    if (closeBtn) {
      event.preventDefault();
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal();
    }
  });

  $('#n360ProductCreateImagen')?.addEventListener('change', (event) => {
    const file = event.target.files?.[0];
    const hint = $('#n360ProductCreateFileHint');
    if (!hint) return;
    hint.textContent = file ? `${file.name} (${Math.round(file.size / 1024)} KB)` : 'Opcional. Maximo 4 MB.';
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (form.dataset.busy === '1') return;

    const cfg = getConfig();
    const payload = new FormData(form);
    payload.set('csrf', cfg.csrf);
    payload.set('orgn_id', cfg.originId);
    payload.set('area_control', cfg.originArea);
    payload.set('tipo_control', cfg.originTipo);

    setBusy(true);
    status('Guardando producto...', 'loading');

    try {
      const data = await withLoader(
        () => fetchJson('crear_producto', { method: 'POST', body: payload }),
        { text: 'Guardando producto...' }
      );
      closeModal();
      handleAfterCreate(data);
    } catch (error) {
      status(error.message, 'error');
      await alertBox(error.message, 'error', 'Nuevo producto');
    } finally {
      setBusy(false);
    }
  });
})();