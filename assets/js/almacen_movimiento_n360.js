(function () {
  'use strict';

  const page = document.getElementById('almMovPage');
  if (!page) return;

  const api = page.dataset.api || 'movimiento_api.php';
  const csrf = page.dataset.csrf || '';
  const canEditPrices = page.dataset.canEditPrices === '1';
  const isAdmin = page.dataset.isAdmin === '1';
  const originId = page.dataset.originId || '1';
  const originLabel = page.dataset.originLabel || 'ALMACEN (ALM)';
  const originArea = page.dataset.originArea || (originId === '4' ? 'RRHH' : 'ALMACEN');
  const originTipo = page.dataset.originTipo || (originId === '4' ? 'BIEN_CONTROLADO' : 'CONSUMIBLE');
  const originSerieEntrada = page.dataset.serieEntrada || (originId === '4' ? 'RE' : 'NE');
  const originSerieSalida = page.dataset.serieSalida || (originId === '4' ? 'RS' : 'NS');
  const originModule = page.dataset.noteModule || (originId === '4' ? 'RRHH' : 'Almacen');
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const state = {
    catalogTarget: 'entrada',
    selectedEntrada: null,
    selectedSalida: null,
    salidaItems: [],
    busTimer: null,
    productTimer: null,
    workerTimer: null,
  };

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const toNumber = (value) => {
    const n = Number(String(value ?? '').replace(',', '.'));
    return Number.isFinite(n) ? n : 0;
  };

  const fmtQty = (value) => {
    const n = toNumber(value);
    return n.toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  };

  const fmtMoney = (value) => {
    const n = toNumber(value);
    return 'S/ ' + n.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const alertBox = (message, variant = 'info', title = '') => {
    if (window.N360Dialog) {
      return window.N360Dialog.alert(message, { variant, title });
    }
    window.alert(message);
    return Promise.resolve(true);
  };

  const confirmBox = (message, options = {}) => {
    if (window.N360Dialog) {
      return window.N360Dialog.confirm(message, options);
    }
    return Promise.resolve(window.confirm(message));
  };

  const promptBox = (message, options = {}) => {
    if (window.N360Dialog?.prompt) {
      return window.N360Dialog.prompt(message, options);
    }
    const value = window.prompt(message);
    return Promise.resolve(value === null ? null : value);
  };

  const withLoader = async (task, options = {}) => {
    if (window.N360Loader) {
      return window.N360Loader.during(task, options);
    }
    return task();
  };

  const filePreview = (file) => {
    if (!file || !file.name) return '';
    return { name: file.name, size: file.size, type: file.type || 'sin tipo' };
  };

  const formDataSnapshot = (formData) => {
    const snapshot = {};
    for (const [key, value] of formData.entries()) {
      snapshot[key] = value instanceof File ? filePreview(value) : value;
    }
    return snapshot;
  };

  const printDebugPayload = (title, payload) => {
    console.groupCollapsed(`[N360][almacen][pruebas] ${title}`);
    console.log(payload);
    if (Array.isArray(payload.movimientos)) console.table(payload.movimientos);
    if (Array.isArray(payload.items)) console.table(payload.items);
    console.groupEnd();
  };

  async function sendDebugPayload(source, payload) {
    printDebugPayload(source, payload);
    try {
      await fetchJson('debug_payload', {
        method: 'POST',
        json: { source, payload },
      });
      await alertBox('Payload impreso en consola y enviado al log del servidor.', 'info', 'Pruebas');
    } catch (error) {
      await alertBox(`Payload impreso en consola, pero no se pudo enviar al log: ${error.message}`, 'warning', 'Pruebas');
    }
  }

  async function fetchJson(action, options = {}) {
    let actionName = String(action);
    let suffix = '';
    const ampIndex = actionName.indexOf('&');
    if (ampIndex !== -1) {
      suffix = '&' + actionName.slice(ampIndex + 1);
      actionName = actionName.slice(0, ampIndex);
    }
    const url = `${api}?action=${encodeURIComponent(actionName)}${suffix}`;
    const headers = new Headers(options.headers || {});
    headers.set('X-N360-CSRF', csrf);

    if (options.json) {
      headers.set('Content-Type', 'application/json');
      options.body = JSON.stringify({ ...options.json, csrf });
    }

    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers,
    });

    const text = await response.text();
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

  function syncModalOpenClass() {
    document.body.classList.toggle('alm-modal-open', $$('.alm-modal.is-open').length > 0);
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    syncModalOpenClass();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (modal.id === 'almSalidaModal') {
      resetSalidaForm();
    }
    syncModalOpenClass();
  }

  function closeAllModals() {
    $$('.alm-modal.is-open').forEach(closeModal);
  }

  document.addEventListener('click', (event) => {
    const closeBtn = event.target.closest('[data-alm-modal-close]');
    if (closeBtn) {
      event.preventDefault();
      closeModal(closeBtn.closest('.alm-modal'));
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeAllModals();
  });

  function renderSelectedProduct(target, product) {
    const box = target === 'salida' ? $('#almSalidaProductBox') : $('#almEntradaProductBox');
    if (!box) return;

    if (!product) {
      box.innerHTML = '<span class="alm-selected-product__empty">Selecciona un producto desde el catalogo.</span>';
      return;
    }

    const priceChip = canEditPrices
      ? `<span class="alm-chip"><i class="bi bi-cash"></i> ${esc(fmtMoney(product.precio))}</span>`
      : '';
    const controlChip = isAdmin
      ? `<span class="alm-chip"><i class="bi bi-sliders"></i> ${esc(product.area_control || originArea)} / ${esc(product.tipo_control || originTipo)}</span>`
      : '';

    box.innerHTML = `
      <strong>(${esc(product.codigo || product.id)}) ${esc(product.producto)}</strong>
      <div class="alm-selected-product__meta">${esc(product.categoria)} - ${esc(product.unidad || '-')}</div>
      <div class="alm-product-chips">
        <span class="alm-chip"><i class="bi bi-boxes"></i> Stock ${esc(fmtQty(product.stock))}</span>
        ${priceChip}
        ${controlChip}
      </div>
    `;
  }
  function setEntradaTipo(tipo) {
    const input = $('#almEntradaTipo');
    const label = $('#almEntradaTipoLabel');
    const help = $('#almEntradaTipoHelp');
    const value = tipo === 'INVENTARIADO' ? 'INVENTARIADO' : (tipo === 'ENTRADA' ? 'ENTRADA' : '');
    if (input) input.value = value;
    if (label) {
      label.dataset.tipo = value;
      label.innerHTML = value
        ? `<i class="bi ${value === 'INVENTARIADO' ? 'bi-clipboard-plus' : 'bi-box-arrow-in-down'}"></i><strong>${esc(value)}</strong>`
        : '<i class="bi bi-magic"></i><strong>Pendiente</strong>';
    }
    if (help) {
      help.textContent = value === 'INVENTARIADO'
        ? 'Producto sin historial: se guardara como inventariado inicial.'
        : value === 'ENTRADA'
          ? ' '
          : ' ';
    }
  }
  function applyEntradaProduct(product) {
    state.selectedEntrada = product;
    $('#almEntradaProductoId').value = product.id;
    $('#almEntradaPrecio').value = Number(product.precio || 0).toFixed(4);
    setEntradaTipo(Number(product.tiene_movimientos || product.movimientos || 0) > 0 ? 'ENTRADA' : 'INVENTARIADO');
    renderSelectedProduct('entrada', product);
    calculateEntradaMonto();
    $('#almEntradaCantidad')?.focus();
  }

  function applySalidaProduct(product) {
    state.selectedSalida = product;
    renderSelectedProduct('salida', product);
    $('#almSalidaCantidad')?.focus();
  }

  function renderCatalogRows(rows) {
    const tbody = $('#almCatalogRows');
    const status = $('#almCatalogStatus');
    if (!tbody) return;

    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="alm-table__empty">No se encontraron productos para este origen.</td></tr>';
      if (status) status.textContent = 'Sin resultados para el filtro actual.';
      return;
    }

    tbody.innerHTML = rows.map((row, index) => {
      const controlLine = isAdmin
        ? `<br><small class="alm-catalog-control">${esc(row.area_control || originArea)} - ${esc(row.tipo_control || originTipo)}</small>`
        : '';
      return `
      <tr data-index="${index}">
        <td><span class="alm-badge">${esc(row.codigo || row.id)}</span></td>
        <td><strong>${esc(row.producto)}</strong><br><small>${esc(row.descripcion || '')}</small>${controlLine}</td>
        <td>${esc(row.categoria || '-')}</td>
        <td>${esc(row.unidad || '-')}</td>
        <td>${esc(fmtQty(row.stock))}</td>
        <td>${canEditPrices ? esc(fmtMoney(row.precio)) : '-'}</td>
        <td>
          <button class="alm-icon-btn" type="button" data-select-product="${index}" aria-label="Seleccionar producto">
            <i class="bi bi-check2"></i>
          </button>
        </td>
      </tr>
    `; }).join('');

    tbody.dataset.rows = JSON.stringify(rows);
    if (status) status.textContent = `${rows.length} productos visibles para ${originArea}.`;
  }
  async function loadProducts(query = '') {
    const status = $('#almCatalogStatus');
    if (status) status.textContent = 'Cargando catalogo...';

    const data = await fetchJson('catalogo_productos&q=' + encodeURIComponent(query) + '&origin_id=' + encodeURIComponent(originId));
    renderCatalogRows(data.rows || []);
  }

  function updateContextLabels() {
    const catalogOrigin = $('#almCatalogOrigin');
    if (catalogOrigin) {
      catalogOrigin.innerHTML = `<i class="bi bi-compass"></i><span>Abierto desde <strong>${esc(originArea)}</strong> - ${esc(originTipo)}</span>`;
    }
    const salidaEyebrow = $('#almSalidaEyebrow');
    if (salidaEyebrow) salidaEyebrow.textContent = `Nota de salida ${originSerieSalida}`;
  }

  function openCatalog(target) {
    state.catalogTarget = target || 'entrada';
    updateContextLabels();
    const modal = $('#almProductCatalog');
    modal?.classList.toggle('is-stacked', state.catalogTarget === 'salida');
    openModal(modal);
    window.setTimeout(() => $('#almCatalogSearch')?.focus(), 40);
    loadProducts($('#almCatalogSearch')?.value || '').catch((error) => {
      renderCatalogRows([]);
      alertBox(error.message, 'error');
    });
  }

  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-alm-open-catalog]');
    if (!opener) return;
    event.preventDefault();
    openCatalog(opener.dataset.target || 'entrada');
  });

  $('#almCatalogSearch')?.addEventListener('input', (event) => {
    window.clearTimeout(state.productTimer);
    state.productTimer = window.setTimeout(() => {
      loadProducts(event.target.value).catch((error) => alertBox(error.message, 'error'));
    }, 240);
  });

  $('#almCatalogRows')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-select-product]');
    if (!button) return;

    const rows = JSON.parse($('#almCatalogRows').dataset.rows || '[]');
    const product = rows[Number(button.dataset.selectProduct)];
    if (!product) return;

    if (state.catalogTarget === 'salida') applySalidaProduct(product);
    else applyEntradaProduct(product);

    closeModal($('#almProductCatalog'));
  });

  $('#almCatalogRows')?.addEventListener('dblclick', (event) => {
    const tr = event.target.closest('tr[data-index]');
    if (!tr) return;
    const button = tr.querySelector('[data-select-product]');
    button?.click();
  });

  function calculateEntradaMonto() {
    const cantidad = toNumber($('#almEntradaCantidad')?.value);
    const precio = toNumber($('#almEntradaPrecio')?.value);
    const monto = cantidad * precio;
    if ($('#almEntradaMonto')) $('#almEntradaMonto').value = monto ? monto.toFixed(4) : '';
  }

  ['#almEntradaCantidad', '#almEntradaPrecio'].forEach((selector) => {
    $(selector)?.addEventListener('input', calculateEntradaMonto);
  });

  function buildEntradaDebugPayload() {
    calculateEntradaMonto();
    const form = $('#almEntradaForm');
    const formData = new FormData(form);
    formData.set('csrf', csrf);
    const cantidad = toNumber($('#almEntradaCantidad')?.value);
    const precio = canEditPrices ? toNumber($('#almEntradaPrecio')?.value) : '(backend: precio del producto)';
    const monto = canEditPrices ? cantidad * toNumber($('#almEntradaPrecio')?.value) : '(backend)';
    const tipo = $('#almEntradaTipo')?.value || '(backend: se define por historial)';

    return {
      origen: 'entrada',
      orgn_id: originId,
      orgn_label: originLabel,
      producto_seleccionado: state.selectedEntrada,
      formulario: formDataSnapshot(formData),
      nota: {
        tabla: 'tb_notas_salida',
        clm_nota_serie: originSerieEntrada,
        clm_nota_modulo: originModule,
        clm_nota_motivo: $('#almEntradaObservacion')?.value || '',
        clm_nota_espacio: $('#almEntradaUbicacionLabel')?.value || 'ALMACEN (ALM)',
        clm_nota_proveedor: $('#almEntradaProveedor')?.value || '',
      },
      movimiento: {
        tabla: 'tb_alm_movimientos',
        clm_alm_mov_idPRODUCTO: $('#almEntradaProductoId')?.value || '',
        clm_alm_mov_TIPO: tipo,
        clm_alm_mov_cantidad: cantidad,
        clm_alm_mov_preciounitario: precio,
        clm_alm_mov_monto: monto,
        clm_alm_mov_OBSERVACION: $('#almEntradaObservacion')?.value || '',
        clm_mov_factura: $('#almEntradaFactura')?.value || '',
        clm_mov_ruc: $('#almEntradaProveedor')?.value || '',
        clm_alm_mov_ofic_destino: $('#almEntradaSede')?.value || $('#almEntradaSedeLocked')?.value || null,
        clm_alm_mov_anaquel: $('#almEntradaAnaquel')?.value || null,
        clm_alm_mov_ubicacion: $('#almEntradaUbicacionRaw')?.value || null,
        clm_alm_mov_gen_etq: $('#almEntradaGenEtq')?.checked ? 1 : 0,
      },
    };
  }

  function buildSalidaPayload(extra = {}) {
    return {
      orgn_id: originId,
      placa_id: $('#almSalidaPlacaId')?.value || '',
      entregado_a: $('#almSalidaEntregado')?.value || '',
      personal_id: $('#almSalidaPersonalId')?.value || '',
      motivo: $('#almSalidaMotivo')?.value || '',
      items: state.salidaItems.map((item) => ({
        producto_id: item.id,
        cantidad: item.cantidad,
      })),
      ...extra,
    };
  }

  function buildSalidaDebugPayload() {
    const payload = buildSalidaPayload();
    return {
      origen: 'salida',
      payload,
      nota: {
        tabla: 'tb_notas_salida',
        clm_nota_serie: 'NS',
        clm_nota_modulo: originModule,
        clm_nota_motivo: payload.motivo,
        clm_nota_placa: payload.placa_id,
        clm_nota_espacio: originLabel,
        clm_nota_proveedor: payload.entregado_a,
      },
      movimientos: state.salidaItems.map((item, index) => ({
        tabla: 'tb_alm_movimientos',
        orden_item: index + 1,
        clm_alm_mov_orgn: originId,
        clm_alm_mov_itmtable: index + 1,
        clm_alm_mov_TIPO: 'SALIDA',
        clm_alm_mov_idPRODUCTO: item.id,
        clm_alm_mov_cantidad: item.cantidad,
        clm_alm_mov_preciounitario: canEditPrices ? item.precio : '(backend)',
        clm_alm_mov_monto: canEditPrices ? item.monto : '(backend)',
        clm_alm_mov_placa: payload.placa_id,
        clm_alm_mov_OBSERVACION: payload.motivo,
      })),
    };
  }

  function updateRefsVisibility() {
    const toggle = $('#almToggleRefs');
    const group = $('#almRefsGroup');
    const visible = !toggle || toggle.checked;
    group?.classList.toggle('is-hidden', !visible);
    ['#almEntradaProveedor', '#almEntradaFactura'].forEach((selector) => {
      const field = $(selector);
      if (!field) return;
      field.disabled = !visible;
      if (!visible) field.value = '';
    });
  }

  function focusAndSelect(field) {
    if (!field) return;
    field.focus();
    if (typeof field.select === 'function') {
      field.select();
    }
  }

  function updateLocationFieldState() {
    const mode = $('input[name="ubicacion_modo"]:checked')?.value || 'anaquel';
    const controls = [
      ['#almEntradaBloque', !(mode === 'bloque' || mode === 'nivel' || mode === 'completo')],
      ['#almEntradaNivel', !(mode === 'nivel' || mode === 'completo' || mode === 'fila')],
      ['#almEntradaSeccion', mode !== 'completo']
    ];

    controls.forEach(([selector, disabled]) => {
      const input = $(selector);
      const field = input?.closest('.alm-field');
      field?.classList.toggle('is-disabled', disabled);
      field?.classList.toggle('is-active', !disabled);
    });

    $$('.alm-radio-pill').forEach((pill) => {
      const radio = pill.querySelector('input[type="radio"]');
      pill.classList.toggle('is-active', Boolean(radio?.checked));
    });
  }

  function selectedOptionText(select) {
    const option = select?.selectedOptions?.[0];
    return option ? option.textContent.trim() : '';
  }

  function normalizeBB(value) {
    const raw = String(value || '').trim().toUpperCase();
    if (!raw) return '00';
    if (raw === 'X' || raw === '00') return raw;
    return raw.replace(/[^A-Z]/g, '').slice(0, 2).padEnd(2, 'A');
  }

  function normalizeNN(value) {
    const raw = String(value || '').replace(/\D/g, '');
    return (raw || '00').slice(0, 2).padStart(2, '0');
  }

  function normalizeSSSS(value) {
    const raw = String(value || '').replace(/\D/g, '');
    return (raw || '0000').slice(0, 4).padStart(4, '0');
  }

  function updateLocationPreview() {
    const anaquel = $('#almEntradaAnaquel');
    const option = anaquel?.selectedOptions?.[0];
    const code = option?.dataset.code || '';
    const mode = $('input[name="ubicacion_modo"]:checked')?.value || 'anaquel';

    const bbInput = $('#almEntradaBloque');
    const nnInput = $('#almEntradaNivel');
    const ssssInput = $('#almEntradaSeccion');

    let bb = '00';
    let nn = '00';
    let ssss = '0000';

    if (mode === 'bloque') {
      bb = normalizeBB(bbInput.value);
    } else if (mode === 'nivel') {
      bb = normalizeBB(bbInput.value);
      nn = normalizeNN(nnInput.value);
    } else if (mode === 'completo') {
      bb = normalizeBB(bbInput.value);
      nn = normalizeNN(nnInput.value);
      ssss = normalizeSSSS(ssssInput.value);
    } else if (mode === 'fila') {
      bb = 'X';
      nn = normalizeNN(nnInput.value);
    }

    bbInput.value = bb;
    nnInput.value = nn;
    ssssInput.value = ssss;

    bbInput.disabled = mode === 'anaquel' || mode === 'fila';
    nnInput.disabled = mode === 'anaquel' || mode === 'bloque';
    ssssInput.disabled = mode !== 'completo';
    updateLocationFieldState();

    const raw = code ? `${code}-${bb}.${nn}.${ssss}` : '';
    $('#almEntradaUbicacionRaw').value = raw;
    $('#almEntradaUbicacionLabel').value = raw ? `${selectedOptionText($('#almEntradaSede'))} | ${selectedOptionText(anaquel)}` : '';
    $('#almLocationPreview').innerHTML = raw
      ? `<i class="bi bi-geo-alt-fill"></i><span>${esc(raw.replace(/\./g, ' '))}</span>`
      : '<i class="bi bi-geo-alt"></i><span>Sin ubicacion seleccionada</span>';
  }

  function loadAnaquelesForSede() {
    const sedeId = $('#almEntradaSede')?.value || '';
    const select = $('#almEntradaAnaquel');
    if (!select) return;

    $$('#almEntradaAnaquel option').forEach((option) => {
      if (!option.value) return;
      option.hidden = Boolean(sedeId) && option.dataset.sede !== sedeId;
    });

    const currentStillVisible = select.value && !select.selectedOptions?.[0]?.hidden;
    const firstVisible = Array.from(select.options).find((option) => option.value && !option.hidden);
    if (!currentStillVisible) {
      select.value = firstVisible ? firstVisible.value : '';
    }
    updateLocationPreview();
  }

  $('#almEntradaSede')?.addEventListener('change', loadAnaquelesForSede);
  $('#almEntradaAnaquel')?.addEventListener('change', updateLocationPreview);
  $$('input[name="ubicacion_modo"]').forEach((input) => input.addEventListener('change', updateLocationPreview));
  ['#almEntradaBloque', '#almEntradaNivel', '#almEntradaSeccion'].forEach((selector) => {
    $(selector)?.addEventListener('input', updateLocationPreview);
    $(selector)?.addEventListener('blur', updateLocationPreview);
  });
  setEntradaTipo($('#almEntradaTipo')?.value || '');
  loadAnaquelesForSede();

  $('#almToggleRefs')?.addEventListener('change', updateRefsVisibility);
  updateRefsVisibility();

  $('#almEntradaCantidad')?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    focusAndSelect($('#almEntradaObservacion'));
  });

  $('#almEntradaObservacion')?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.shiftKey) return;
    event.preventDefault();
    $('#almEntradaForm')?.requestSubmit();
  });

  $('#almEntradaDebug')?.addEventListener('click', () => {
    sendDebugPayload('entrada', buildEntradaDebugPayload());
  });

  $('#almEntradaForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!$('#almEntradaProductoId').value) {
      await alertBox('Selecciona un producto del catalogo.', 'warning');
      openCatalog('entrada');
      return;
    }

    const cantidad = toNumber($('#almEntradaCantidad').value);
    if (cantidad <= 0) {
      await alertBox('Ingresa una cantidad mayor a cero.', 'warning');
      $('#almEntradaCantidad').focus();
      return;
    }

    calculateEntradaMonto();
    const confirmed = await confirmBox('Se registrara una nota de entrada y el movimiento asociado. ¿Deseas continuar?', {
      title: 'Confirmar entrada',
      confirmText: 'Registrar entrada',
      cancelText: 'Cancelar',
      variant: 'warning',
    });
    if (!confirmed) return;

    const form = event.currentTarget;
    const formData = new FormData(form);
    formData.set('csrf', csrf);

    try {
      const data = await withLoader(
        () => fetchJson('save_entrada', { method: 'POST', body: formData }),
        { title: 'Registrando entrada', detail: 'Guardando nota y movimiento...', button: '#almEntradaSubmit' }
      );
      if (data.auto_pdf && data.nota_id && window.N360NotaPDF) {
        await window.N360NotaPDF.downloadByNotaId(data.nota_id);
      }
      await alertBox(`Movimiento registrado correctamente.\nNota: ${data.nota_codigo || data.nota_id}`, 'success', 'Entrada guardada');
      window.location.reload();
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  });

  $('#almEntradaReset')?.addEventListener('click', () => {
    state.selectedEntrada = null;
    updateContextLabels();
  renderSelectedProduct('entrada', null);
    $('#almEntradaForm')?.reset();
    $('#almEntradaProductoId').value = '';
    setEntradaTipo('');
    updateRefsVisibility();
    loadAnaquelesForSede();
  });

  $('#almOpenSalida')?.addEventListener('click', () => {
    openModal($('#almSalidaModal'));
    window.setTimeout(() => $('#almSalidaBusInput')?.focus(), 40);
  });

  async function searchBuses(query) {
    const suggest = $('#almSalidaBusSuggest');
    if (!suggest) return;

    if (!query.trim()) {
      suggest.hidden = true;
      suggest.innerHTML = '';
      return;
    }

    const data = await fetchJson('buses&q=' + encodeURIComponent(query));
    const rows = data.rows || [];
    if (!rows.length) {
      suggest.hidden = false;
      suggest.innerHTML = '<button type="button" disabled>Sin unidades coincidentes</button>';
      return;
    }

    suggest.hidden = false;
    suggest.innerHTML = rows.map((row) => `
      <button type="button" data-bus-id="${esc(row.id)}" data-bus-label="${esc(row.bus)}" data-bus-placa="${esc(row.placa)}">
        ${esc(row.bus || 'Unidad')} (${esc(row.placa || '-')})
        <small>${esc(row.dueno || 'Sin dueno registrado')}</small>
      </button>
    `).join('');
  }

  function openWorkerPanel() {
    const panel = $('#almWorkerPanel');
    if (!panel) return;
    panel.hidden = false;
    window.setTimeout(() => $('#almWorkerSearch')?.focus(), 30);
    searchWorkers($('#almWorkerSearch')?.value || $('#almSalidaEntregado')?.value || '').catch((error) => alertBox(error.message, 'error'));
  }

  function closeWorkerPanel() {
    const panel = $('#almWorkerPanel');
    if (!panel) return;
    panel.hidden = true;
  }

  function renderWorkers(rows) {
    const box = $('#almWorkerRows');
    if (!box) return;
    if (!rows.length) {
      box.innerHTML = '<p>No se encontraron trabajadores.</p>';
      return;
    }
    box.innerHTML = rows.map((row) => `
      <button type="button" data-worker-id="${esc(row.id)}" data-worker-name="${esc(row.nombre)}" data-worker-dni="${esc(row.dni)}">
        <strong>${esc(row.nombre)}</strong>
        <small>DNI ${esc(row.dni || '-')} ${row.cargo ? '- ' + esc(row.cargo) : ''}</small>
      </button>
    `).join('');
  }

  async function searchWorkers(query = '') {
    const data = await fetchJson('trabajadores&q=' + encodeURIComponent(query));
    renderWorkers(data.rows || []);
  }

  $('#almOpenWorkerSearch')?.addEventListener('click', (event) => {
    event.preventDefault();
    openWorkerPanel();
  });

  $('#almCloseWorkerPanel')?.addEventListener('click', closeWorkerPanel);

  $('#almWorkerSearch')?.addEventListener('input', (event) => {
    window.clearTimeout(state.workerTimer);
    state.workerTimer = window.setTimeout(() => {
      searchWorkers(event.target.value).catch((error) => alertBox(error.message, 'error'));
    }, 240);
  });

  $('#almWorkerRows')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-worker-id]');
    if (!button) return;
    $('#almSalidaPersonalId').value = button.dataset.workerId || '';
    const workerName = button.dataset.workerName || '';
    const workerDni = button.dataset.workerDni || '';
    $('#almSalidaEntregado').value = workerDni && workerDni !== '-'
      ? `${workerName} (${workerDni})`
      : workerName;
    closeWorkerPanel();
    $('#almSalidaMotivo')?.focus();
  });

  document.addEventListener('click', (event) => {
    const panel = $('#almWorkerPanel');
    if (!panel || panel.hidden) return;
    if (event.target.closest('.alm-field--worker')) return;
    closeWorkerPanel();
  });

  $('#almSalidaBusInput')?.addEventListener('input', (event) => {
    $('#almSalidaPlacaId').value = '';
    window.clearTimeout(state.busTimer);
    state.busTimer = window.setTimeout(() => {
      searchBuses(event.target.value).catch((error) => alertBox(error.message, 'error'));
    }, 240);
  });

  function updateSalidaSubmitState() {
    const submit = $('#almSalidaSubmit');
    if (!submit) return;
    submit.disabled = !$('#almSalidaConfirm')?.checked;
  }

  function resetSalidaForm() {
    const form = $('#almSalidaForm');
    if (form) form.reset();
    state.selectedSalida = null;
    state.salidaItems = [];
    const hiddenBus = $('#almSalidaPlacaId');
    if (hiddenBus) hiddenBus.value = '';
    const suggest = $('#almSalidaBusSuggest');
    if (suggest) {
      suggest.hidden = true;
      suggest.innerHTML = '';
    }
    const workerId = $('#almSalidaPersonalId');
    if (workerId) workerId.value = '';
    const workerSearch = $('#almWorkerSearch');
    if (workerSearch) workerSearch.value = '';
    const workerRows = $('#almWorkerRows');
    if (workerRows) workerRows.innerHTML = '<p>Busca por nombre o DNI para asignar el responsable.</p>';
    closeWorkerPanel();
    renderSelectedProduct('salida', null);
    renderSalidaItems();
    updateSalidaSubmitState();
  }

  $('#almSalidaBusSuggest')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-bus-id]');
    if (!button) return;
    $('#almSalidaPlacaId').value = button.dataset.busId;
    $('#almSalidaBusInput').value = `${button.dataset.busLabel} (${button.dataset.busPlaca})`;
    $('#almSalidaBusSuggest').hidden = true;
    $('#almSalidaEntregado').focus();
  });

  function renderSalidaItems() {
    const tbody = $('#almSalidaItems');
    if (!tbody) return;

    if (!state.salidaItems.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="alm-table__empty">Aun no hay items en la salida.</td></tr>';
      updateSalidaSubmitState();
      return;
    }

    tbody.innerHTML = state.salidaItems.map((item, index) => `
      <tr>
        <td>${index + 1}</td>
        <td><strong>(${esc(item.codigo)}) ${esc(item.producto)}</strong></td>
        <td>${esc(item.unidad || '-')}</td>
        <td>${esc(fmtQty(item.cantidad))}</td>
        <td>${canEditPrices ? esc(fmtMoney(item.precio)) : '-'}</td>
        <td>${canEditPrices ? esc(fmtMoney(item.monto)) : '-'}</td>
        <td>
          <button class="alm-icon-btn" type="button" data-remove-salida="${index}" aria-label="Quitar item">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>
    `).join('');
    updateSalidaSubmitState();
  }

  $('#almSalidaAddItem')?.addEventListener('click', async () => {
    const product = state.selectedSalida;
    if (!product) {
      await alertBox('Selecciona un producto para la salida.', 'warning');
      openCatalog('salida');
      return;
    }

    const cantidad = toNumber($('#almSalidaCantidad').value);
    if (cantidad <= 0) {
      await alertBox('Ingresa una cantidad mayor a cero.', 'warning');
      $('#almSalidaCantidad').focus();
      return;
    }

    const stock = toNumber(product.stock);
    if (cantidad > stock) {
      await alertBox(`La cantidad supera el stock actual (${fmtQty(stock)}).`, 'warning');
      $('#almSalidaCantidad').focus();
      return;
    }

    const existing = state.salidaItems.find((item) => Number(item.id) === Number(product.id));
    if (existing) {
      const nextQty = toNumber(existing.cantidad) + cantidad;
      if (nextQty > stock) {
        await alertBox(`La suma de items supera el stock actual (${fmtQty(stock)}).`, 'warning');
        return;
      }
      existing.cantidad = nextQty;
      existing.monto = nextQty * toNumber(existing.precio);
    } else {
      const precio = toNumber(product.precio);
      state.salidaItems.push({
        id: product.id,
        codigo: product.codigo || product.id,
        producto: product.producto,
        unidad: product.unidad,
        cantidad,
        precio,
        monto: cantidad * precio,
      });
    }

    $('#almSalidaCantidad').value = '';
    state.selectedSalida = null;
    renderSelectedProduct('salida', null);
    renderSalidaItems();
  });

  $('#almSalidaConfirm')?.addEventListener('change', updateSalidaSubmitState);

  $('#almSalidaDebug')?.addEventListener('click', () => {
    sendDebugPayload('salida', buildSalidaDebugPayload());
  });

  $('#almSalidaItems')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-salida]');
    if (!button) return;
    state.salidaItems.splice(Number(button.dataset.removeSalida), 1);
    renderSalidaItems();
  });

  $('#almSalidaForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!$('#almSalidaPlacaId').value) {
      await alertBox('Selecciona una unidad valida para la salida.', 'warning');
      $('#almSalidaBusInput').focus();
      return;
    }

    if (!state.salidaItems.length) {
      await alertBox('Agrega al menos un item a la salida.', 'warning');
      return;
    }

    if (!$('#almSalidaConfirm').checked) {
      await alertBox('Confirma que los items y cantidades estan correctos.', 'warning');
      $('#almSalidaConfirm').focus();
      return;
    }

    const payload = buildSalidaPayload();

    const confirmed = await confirmBox('Se registrara una nota de salida NS con los items indicados. ¿Continuamos?', {
      title: 'Confirmar salida',
      confirmText: 'Registrar salida',
      cancelText: 'Cancelar',
      variant: 'danger',
    });
    if (!confirmed) return;

    const password = await promptBox('Ingresa tu contrasena de sesion para registrar esta salida.', {
      title: 'Validar salida',
      inputType: 'password',
      inputLabel: 'Contrasena',
      placeholder: 'Contrasena de tu usuario',
      confirmText: 'Validar y guardar',
      cancelText: 'Cancelar',
      variant: 'danger',
      required: true,
      autocomplete: 'current-password',
    });
    if (password === null) return;
    payload.password = password;

    try {
      const data = await withLoader(
        () => fetchJson('save_salida', { method: 'POST', json: payload }),
        { title: 'Registrando salida', detail: 'Validando stock y guardando nota...', button: '#almSalidaSubmit' }
      );
      await alertBox(`Salida registrada correctamente.\nNota: ${data.nota_codigo || data.nota_id}`, 'success', 'Salida guardada');
      window.location.reload();
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  });

  updateContextLabels();
  renderSelectedProduct('entrada', null);
  renderSelectedProduct('salida', null);
  renderSalidaItems();
})();
