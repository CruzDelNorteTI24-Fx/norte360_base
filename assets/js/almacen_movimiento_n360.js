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
  const isAccountingOrigin = String(originId) === '12';
  const originArea = page.dataset.originArea || (originId === '4' ? 'RRHH' : (isAccountingOrigin ? 'ACTIVOS' : 'ALMACEN'));
  const originTipo = page.dataset.originTipo || (originId === '4' ? 'BIEN_CONTROLADO' : (isAccountingOrigin ? 'ACTIVO_FIJO' : 'CONSUMIBLE'));
  const originSerieEntrada = page.dataset.serieEntrada || (originId === '4' ? 'RE' : (isAccountingOrigin ? 'CE' : 'NE'));
  const originSerieSalida = page.dataset.serieSalida || (originId === '4' ? 'RS' : (isAccountingOrigin ? 'CS' : 'NS'));
  const originModule = page.dataset.noteModule || (originId === '4' ? 'RRHH' : (isAccountingOrigin ? 'Contabilidad' : 'Almacen'));
  const barcodeLogo = page.dataset.barcodeLogo || '';
  const sessionUserName = page.dataset.userName || page.dataset.user || '';
  const sessionUserDni = page.dataset.userDni || '';
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
    salidaBusBlocked: false,
    salidaLabels: [],
  };

  const shouldDefaultBlockBus = () => isRrhhContext() || isAccountingOrigin;

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

  const isRrhhContext = () => String(originId) === '4';

  const todayIso = () => {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  };

  const parsePersonText = (value) => {
    const text = String(value || '').trim();
    const match = text.match(/^(.*?)\s*\(([^)]+)\)\s*$/);
    if (match) {
      return { name: match[1].trim(), dni: match[2].trim() };
    }
    return { name: text, dni: '' };
  };

  const checkedValue = (name, fallback = '') => {
    return $(`input[name="${name}"]:checked`)?.value || fallback;
  };

  function applyRrhhSalidaSkin() {
    const modal = $('#almSalidaModal');
    modal?.classList.toggle('alm-salida--rrhh', isRrhhContext());

    const title = $('#almSalidaTitle');
    const entregado = $('#almSalidaEntregado');
    const busHelp = $('#almSalidaBusField .alm-help');

    if (isRrhhContext()) {
      if (title) title.textContent = 'Salida de bienes RRHH';
      if (entregado) entregado.placeholder = 'Trabajador que recibe los bienes';
      if (busHelp) busHelp.textContent = 'Activa "Sin bus" cuando la entrega no corresponde a una unidad.';
      return;
    }

    if (isAccountingOrigin) {
      if (title) title.textContent = 'Salida de activos fijos';
      if (entregado) entregado.placeholder = 'Responsable, area o destino';
      if (busHelp) busHelp.textContent = 'Activa "Sin bus" cuando el activo no corresponde a una unidad.';
      return;
    }

    if (title) title.textContent = 'Armar salida de almacen';
    if (entregado) entregado.placeholder = 'Taller, responsable o destino';
    if (busHelp) busHelp.textContent = 'Usa "Sin bus" cuando la nota no debe asociarse a una unidad.';
  }

  function syncActaDiscountState() {
    if (!isRrhhContext()) return;
    const enabled = Boolean($('#almActaDescuenta')?.checked);
    const cuotas = $('#almActaCuotas');
    const fecha = $('#almActaFechaDescuento');
    if (cuotas) cuotas.disabled = !enabled;
    if (fecha) fecha.disabled = !enabled;
    $('.alm-acta__cuotas')?.classList.toggle('is-disabled', !enabled);
    $('.alm-acta__first-payment')?.classList.toggle('is-disabled', !enabled);
  }

  function syncActaRecibeFromEntregado(force = false, cargo = '') {
    if (!isRrhhContext()) return;
    const parsed = parsePersonText($('#almSalidaEntregado')?.value || '');
    const name = $('#almActaRecibeNombre');
    const dni = $('#almActaRecibeDni');
    const cargoInput = $('#almActaRecibeCargo');
    if (name && (force || !name.value.trim())) name.value = parsed.name || '';
    if (dni && (force || !dni.value.trim())) dni.value = parsed.dni || '';
    if (cargoInput && cargo && (force || cargoInput.value === 'EMPLEADO')) cargoInput.value = cargo;
  }

  function initActaFields() {
    applyRrhhSalidaSkin();
    const section = $('#almActaSection');
    if (!section) return;
    section.hidden = !isRrhhContext();
    if (!isRrhhContext()) return;

    const fecha = $('#almActaFechaEntrega');
    if (fecha && !fecha.value) fecha.value = todayIso();
    const cuotas = $('#almActaCuotas');
    if (cuotas && !cuotas.value) cuotas.value = '1';
    const entregaNombre = $('#almActaEntregaNombre');
    const entregaDni = $('#almActaEntregaDni');
    if (entregaNombre && !entregaNombre.value) entregaNombre.value = sessionUserName || 'admin';
    if (entregaDni && !entregaDni.value) entregaDni.value = sessionUserDni || '';
    syncActaRecibeFromEntregado(false);
    syncActaDiscountState();
  }

  function resetActaFields() {
    if (!isRrhhContext()) return;
    $('#almActaFechaEntrega') && ($('#almActaFechaEntrega').value = todayIso());
    $$('input[name="alm_acta_area"]').forEach((input) => { input.checked = input.value === 'OFICINA'; });
    $$('input[name="alm_acta_posicion"]').forEach((input) => { input.checked = input.value === 'FULL_TIME'; });
    $$('input[name="alm_acta_motivo"]').forEach((input) => { input.checked = input.value === 'INICIO_CONTRATO_CORTESIA'; });
    $('#almActaDescuenta') && ($('#almActaDescuenta').checked = false);
    $('#almActaCuotas') && ($('#almActaCuotas').value = '1');
    $('#almActaFechaDescuento') && ($('#almActaFechaDescuento').value = '');
    $('#almActaObservaciones') && ($('#almActaObservaciones').value = '');
    $('#almActaRecibeNombre') && ($('#almActaRecibeNombre').value = '');
    $('#almActaRecibeDni') && ($('#almActaRecibeDni').value = '');
    $('#almActaRecibeCargo') && ($('#almActaRecibeCargo').value = 'EMPLEADO');
    $('#almActaEntregaNombre') && ($('#almActaEntregaNombre').value = sessionUserName || 'admin');
    $('#almActaEntregaDni') && ($('#almActaEntregaDni').value = sessionUserDni || '');
    $('#almActaEntregaCargo') && ($('#almActaEntregaCargo').value = 'ASISTENTE');
    syncActaDiscountState();
  }

  function buildActaPayload() {
    if (!isRrhhContext()) return null;
    return {
      fecha_entrega: $('#almActaFechaEntrega')?.value || todayIso(),
      area: checkedValue('alm_acta_area', 'OFICINA'),
      posicion: checkedValue('alm_acta_posicion', 'FULL_TIME'),
      motivo: checkedValue('alm_acta_motivo', 'INICIO_CONTRATO_CORTESIA'),
      descuenta: $('#almActaDescuenta')?.checked ? 1 : 0,
      cuotas: $('#almActaCuotas')?.value || '1',
      fecha_descuento: $('#almActaFechaDescuento')?.value || '',
      observaciones: $('#almActaObservaciones')?.value || '',
      recibe_nombre: $('#almActaRecibeNombre')?.value || '',
      recibe_dni: $('#almActaRecibeDni')?.value || '',
      recibe_cargo: $('#almActaRecibeCargo')?.value || '',
      entrega_nombre: $('#almActaEntregaNombre')?.value || '',
      entrega_dni: $('#almActaEntregaDni')?.value || '',
      entrega_cargo: $('#almActaEntregaCargo')?.value || '',
    };
  }

  const getEntradaForm = () => {
    const form = document.getElementById('almEntradaForm');
    return form instanceof HTMLFormElement ? form : null;
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

  const safeBarcodeFile = (value) => String(value ?? 'ETQ')
    .trim()
    .replace(/[^\w.\- ]+/g, '')
    .replace(/\s+/g, '_')
    .slice(0, 90) || 'ETQ';

  async function downloadGeneratedLabels(codes, product) {
    if (!isAccountingOrigin || !Array.isArray(codes) || !codes.length || !window.N360Barcode?.downloadPng) {
      return;
    }

    const name = product?.producto || product?.nombre || 'Activo fijo';
    const category = [product?.categoria, product?.unidad].filter(Boolean).join(' - ') || originArea;

    for (const code of codes) {
      const temp = document.createElement('div');
      temp.dataset.n360Barcode = '';
      temp.dataset.barcodeKind = 'etiqueta';
      temp.dataset.barcodeLogo = barcodeLogo;
      temp.dataset.barcodeCode = String(code || '').trim();
      temp.dataset.barcodeName = name;
      temp.dataset.barcodeCategory = category;
      temp.dataset.barcodeFilename = `ETQ_${safeBarcodeFile(code)}_${safeBarcodeFile(name)}.png`;
      await window.N360Barcode.downloadPng(temp);
    }
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

  function clearSalidaLabels(message = 'Selecciona un activo fijo para ver sus etiquetas disponibles.') {
    state.salidaLabels = [];
    const picker = $('#almSalidaLabelPicker');
    const rows = $('#almSalidaLabelRows');
    const counter = $('#almSalidaLabelCounter');
    if (picker) picker.hidden = !isAccountingOrigin || !state.selectedSalida;
    if (rows) rows.innerHTML = `<p>${esc(message)}</p>`;
    if (counter) counter.textContent = '0 seleccionadas';
  }

  function getSelectedSalidaLabels() {
    return $$('.alm-salida-label-check:checked')
      .map((input) => state.salidaLabels.find((row) => Number(row.id) === Number(input.value)))
      .filter(Boolean);
  }

  function syncSalidaLabelCounter() {
    if (!isAccountingOrigin) return;
    const selected = getSelectedSalidaLabels();
    const counter = $('#almSalidaLabelCounter');
    if (counter) {
      counter.textContent = `${selected.length} seleccionada${selected.length === 1 ? '' : 's'}`;
    }
    if (selected.length && $('#almSalidaCantidad')) {
      $('#almSalidaCantidad').value = String(selected.length);
    }
  }

  function renderSalidaLabels(rows) {
    const picker = $('#almSalidaLabelPicker');
    const rowsBox = $('#almSalidaLabelRows');
    if (!picker || !rowsBox || !isAccountingOrigin) {
      clearSalidaLabels();
      return;
    }

    picker.hidden = false;
    state.salidaLabels = rows || [];
    const pickedIds = new Set(state.salidaItems.flatMap((item) => item.label_ids || []).map((id) => Number(id)));

    if (!state.salidaLabels.length) {
      rowsBox.innerHTML = '<p>No hay etiquetas disponibles para este activo. Revisa trazabilidad antes de registrar una salida.</p>';
      syncSalidaLabelCounter();
      return;
    }

    rowsBox.innerHTML = state.salidaLabels.map((row) => {
      const id = Number(row.id);
      const picked = pickedIds.has(id);
      return `
        <label class="alm-label-option${picked ? ' is-disabled' : ''}">
          <input class="alm-salida-label-check" type="checkbox" value="${esc(id)}" ${picked ? 'disabled' : ''}>
          <span>
            <strong>${esc(row.codigo || `ETQ-${id}`)}</strong>
            <small>${esc(row.sede || 'Sin ubicacion')} ${row.fecha ? `- ${esc(row.fecha)}` : ''}</small>
          </span>
          <span class="alm-label-option__note">${picked ? 'Agregada' : esc(row.nota || 'Disponible')}</span>
        </label>
      `;
    }).join('');
    syncSalidaLabelCounter();
  }

  async function loadSalidaLabels(productId) {
    if (!isAccountingOrigin) {
      clearSalidaLabels();
      return;
    }

    const picker = $('#almSalidaLabelPicker');
    const rows = $('#almSalidaLabelRows');
    if (picker) picker.hidden = false;
    if (rows) rows.innerHTML = '<p>Cargando etiquetas disponibles...</p>';

    const data = await fetchJson(`etiquetas_contabilidad&product_id=${encodeURIComponent(productId)}&orgn_id=${encodeURIComponent(originId)}`);
    renderSalidaLabels(data.rows || []);
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
    if (isAccountingOrigin) {
      loadSalidaLabels(product.id).catch((error) => {
        clearSalidaLabels('No se pudieron cargar las etiquetas disponibles.');
        alertBox(error.message, 'error');
      });
    } else {
      clearSalidaLabels();
    }
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
    applyRrhhSalidaSkin();
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


  document.addEventListener('n360:product-created', (event) => {
    const product = event.detail?.product;
    if (!product) return;

    const area = String(product.area_control || '').toUpperCase();
    const tipo = String(product.tipo_control || '').toUpperCase();
    if (area !== String(originArea).toUpperCase() || tipo !== String(originTipo).toUpperCase()) {
      loadProducts($('#almCatalogSearch')?.value || '').catch((error) => alertBox(error.message, 'error'));
      return;
    }

    if (state.catalogTarget === 'salida') applySalidaProduct(product);
    else applyEntradaProduct(product);

    closeModal($('#almProductCatalog'));
    loadProducts($('#almCatalogSearch')?.value || '').catch((error) => alertBox(error.message, 'error'));
  });
  function calculateEntradaMonto() {
    const cantidad = toNumber($('#almEntradaCantidad')?.value);
    const precio = toNumber($('#almEntradaPrecio')?.value);
    const monto = cantidad * precio;
    if ($('#almEntradaMonto')) $('#almEntradaMonto').value = monto ? monto.toFixed(4) : '';
  }

  function setupAccountingQuantity() {
    if (!isAccountingOrigin) return;
    ['#almEntradaCantidad', '#almSalidaCantidad'].forEach((selector) => {
      const input = $(selector);
      if (!input) return;
      input.min = '1';
      input.step = '1';
      input.inputMode = 'numeric';
      input.addEventListener('keydown', (event) => {
        if (['.', ',', '-', '+', 'e', 'E'].includes(event.key)) {
          event.preventDefault();
        }
      });
      input.addEventListener('input', () => {
        const cleaned = String(input.value || '').split(/[.,]/)[0].replace(/[^\d]/g, '');
        if (input.value !== cleaned) input.value = cleaned;
        if (selector === '#almEntradaCantidad') calculateEntradaMonto();
      });
    });
  }

  ['#almEntradaCantidad', '#almEntradaPrecio'].forEach((selector) => {
    $(selector)?.addEventListener('input', calculateEntradaMonto);
  });
  setupAccountingQuantity();

  function buildEntradaDebugPayload() {
    calculateEntradaMonto();
    const form = getEntradaForm();
    if (!form) {
      throw new Error('No se encontro el formulario de entrada.');
    }
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
        clm_nota_espacio: $('#almEntradaUbicacionLabel')?.value || originLabel,
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
        clm_alm_mov_gen_etq: isAccountingOrigin ? 1 : ($('#almEntradaGenEtq')?.checked ? 1 : 0),
        trazabilidad_contable: isAccountingOrigin ? {
          tabla_etiquetas: 'tb_alm_etiquetado',
          tabla_historial_ubicacion: 'tb_alm_etiquetadoofi',
          tabla_secuencia: 'tb_alm_etq_seq',
          etiquetas_a_generar: cantidad,
        } : null,
      },
    };
  }

  function buildSalidaPayload(extra = {}) {
    return {
      orgn_id: originId,
      placa_id: state.salidaBusBlocked ? '' : ($('#almSalidaPlacaId')?.value || ''),
      bus_bloqueado: state.salidaBusBlocked ? 1 : 0,
      entregado_a: $('#almSalidaEntregado')?.value || '',
      personal_id: $('#almSalidaPersonalId')?.value || '',
      motivo: $('#almSalidaMotivo')?.value || '',
      items: state.salidaItems.map((item) => ({
        producto_id: item.id,
        cantidad: item.cantidad,
        label_ids: item.label_ids || [],
      })),
      acta: buildActaPayload(),
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
        clm_nota_serie: originSerieSalida,
        clm_nota_modulo: originModule,
        clm_nota_motivo: payload.motivo,
        clm_nota_placa: payload.placa_id,
        clm_nota_espacio: originLabel,
        clm_nota_proveedor: payload.entregado_a,
      },
      acta_uniformes: payload.acta,
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
        etiquetas_seleccionadas: item.label_codes || [],
      })),
      trazabilidad_contable: isAccountingOrigin ? {
        accion: 'consumir etiquetas disponibles del producto',
        criterio: 'etiquetas seleccionadas por usuario; si no hay seleccion, FIFO por clm_alm_etiquetado_id',
        tabla_etiquetas: 'tb_alm_etiquetado',
        tabla_historial_ubicacion: 'tb_alm_etiquetadoofi',
      } : null,
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
    const rawField = $('#almEntradaUbicacionRaw');
    const labelField = $('#almEntradaUbicacionLabel');
    const preview = $('#almLocationPreview');

    if (isRrhhContext()) {
      if (rawField) rawField.value = '';
      if (labelField) labelField.value = originLabel;
      if (preview) {
        preview.innerHTML = `<i class="bi bi-geo-alt-fill"></i><span>${esc(originLabel)}</span>`;
      }
      return;
    }

    if (isAccountingOrigin) {
      const sede = $('#almEntradaSede');
      const sedeId = sede?.value || '';
      const sedeLabel = sedeId ? selectedOptionText(sede) : '';
      const raw = sedeId ? `OFI-${sedeId}` : '';
      if (rawField) rawField.value = raw;
      if (labelField) labelField.value = sedeLabel;
      if (preview) {
        preview.innerHTML = raw
          ? `<i class="bi bi-geo-alt-fill"></i><span>${esc(sedeLabel || raw)}</span>`
          : '<i class="bi bi-geo-alt"></i><span>Sin ubicacion seleccionada</span>';
      }
      return;
    }

    const anaquel = $('#almEntradaAnaquel');
    const option = anaquel?.selectedOptions?.[0];
    const code = option?.dataset.code || '';
    const mode = $('input[name="ubicacion_modo"]:checked')?.value || 'anaquel';

    const bbInput = $('#almEntradaBloque');
    const nnInput = $('#almEntradaNivel');
    const ssssInput = $('#almEntradaSeccion');

    if (!anaquel || !bbInput || !nnInput || !ssssInput) {
      if (rawField) rawField.value = '';
      if (labelField) labelField.value = originLabel;
      if (preview) {
        preview.innerHTML = `<i class="bi bi-geo-alt-fill"></i><span>${esc(originLabel)}</span>`;
      }
      return;
    }

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
    if (rawField) rawField.value = raw;
    if (labelField) labelField.value = raw ? `${selectedOptionText($('#almEntradaSede'))} | ${selectedOptionText(anaquel)}` : '';
    if (preview) {
      preview.innerHTML = raw
        ? `<i class="bi bi-geo-alt-fill"></i><span>${esc(raw.replace(/\./g, ' '))}</span>`
        : '<i class="bi bi-geo-alt"></i><span>Sin ubicacion seleccionada</span>';
    }
  }

  function loadAnaquelesForSede() {
    const sedeId = $('#almEntradaSede')?.value || '';
    const select = $('#almEntradaAnaquel');
    if (!select) {
      updateLocationPreview();
      return;
    }

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
    if (isAccountingOrigin && !Number.isInteger(cantidad)) {
      await alertBox('En Contabilidad la cantidad debe ser entera para generar etiquetas trazables.', 'warning');
      $('#almEntradaCantidad').focus();
      return;
    }
    if (isAccountingOrigin && !($('#almEntradaSede')?.value || '').trim()) {
      await alertBox('Selecciona la ubicacion/oficina destino del activo fijo.', 'warning');
      $('#almEntradaSede')?.focus();
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

    const form = getEntradaForm();
    if (!form) {
      await alertBox('No se encontro el formulario de entrada. Actualiza la pagina e intenta nuevamente.', 'error');
      return;
    }
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
      await downloadGeneratedLabels(data.etiquetas || [], state.selectedEntrada);
      const labelText = data.etiquetas_generadas
        ? `\nEtiquetas generadas: ${(data.etiquetas || []).join(', ')}`
        : '';
      await alertBox(`Movimiento registrado correctamente.\nNota: ${data.nota_codigo || data.nota_id}${labelText}`, 'success', 'Entrada guardada');
      window.location.reload();
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  });

  $('#almEntradaReset')?.addEventListener('click', () => {
    state.selectedEntrada = null;
    updateContextLabels();
    initActaFields();
    renderSelectedProduct('entrada', null);
    $('#almEntradaForm')?.reset();
    $('#almEntradaProductoId').value = '';
    setEntradaTipo('');
    updateRefsVisibility();
    loadAnaquelesForSede();
  });

  $('#almActaDescuenta')?.addEventListener('change', syncActaDiscountState);

  $('#almOpenSalida')?.addEventListener('click', () => {
    initActaFields();
    setSalidaBusBlocked(shouldDefaultBlockBus());
    openModal($('#almSalidaModal'));
    window.setTimeout(() => (state.salidaBusBlocked ? $('#almSalidaEntregado') : $('#almSalidaBusInput'))?.focus(), 40);
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

  function setSalidaBusBlocked(blocked) {
    state.salidaBusBlocked = Boolean(blocked);
    const field = $('#almSalidaBusField');
    const input = $('#almSalidaBusInput');
    const hiddenId = $('#almSalidaPlacaId');
    const hiddenBlocked = $('#almSalidaBusBloqueado');
    const button = $('#almSalidaBlockBus');
    const suggest = $('#almSalidaBusSuggest');

    if (hiddenBlocked) hiddenBlocked.value = state.salidaBusBlocked ? '1' : '0';
    if (hiddenId && state.salidaBusBlocked) hiddenId.value = '';
    if (input) {
      input.disabled = state.salidaBusBlocked;
      input.value = state.salidaBusBlocked ? 'Sin bus asociado' : '';
      input.placeholder = state.salidaBusBlocked ? 'Bus bloqueado para esta nota' : 'Ej. 158 o ABC-321';
    }
    if (button) {
      button.classList.toggle('is-active', state.salidaBusBlocked);
      button.setAttribute('aria-pressed', state.salidaBusBlocked ? 'true' : 'false');
      const label = button.querySelector('span');
      if (label) label.textContent = state.salidaBusBlocked ? 'Bus bloqueado' : 'Sin bus';
    }
    field?.classList.toggle('is-bus-blocked', state.salidaBusBlocked);
    if (suggest) {
      suggest.hidden = true;
      suggest.innerHTML = '';
    }
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
      <button type="button" data-worker-id="${esc(row.id)}" data-worker-name="${esc(row.nombre)}" data-worker-dni="${esc(row.dni)}" data-worker-cargo="${esc(row.cargo || row.tipo || '')}">
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
    syncActaRecibeFromEntregado(true, button.dataset.workerCargo || '');
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
    if (state.salidaBusBlocked) return;
    $('#almSalidaPlacaId').value = '';
    window.clearTimeout(state.busTimer);
    state.busTimer = window.setTimeout(() => {
      searchBuses(event.target.value).catch((error) => alertBox(error.message, 'error'));
    }, 240);
  });

  $('#almSalidaBlockBus')?.addEventListener('click', () => {
    setSalidaBusBlocked(!state.salidaBusBlocked);
    const nextFocus = state.salidaBusBlocked ? $('#almSalidaEntregado') : $('#almSalidaBusInput');
    nextFocus?.focus();
  });

  $('#almSalidaLabelRows')?.addEventListener('change', (event) => {
    if (!event.target.closest('.alm-salida-label-check')) return;
    syncSalidaLabelCounter();
  });

  function updateSalidaSubmitState() {
    const submit = $('#almSalidaSubmit');
    if (!submit) return;
    submit.disabled = !$('#almSalidaConfirm')?.checked;
  }

  function resetSalidaForm() {
    const form = $('#almSalidaForm');
    if (form) form.reset();
    setSalidaBusBlocked(shouldDefaultBlockBus());
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
    resetActaFields();
    renderSelectedProduct('salida', null);
    clearSalidaLabels();
    renderSalidaItems();
    updateSalidaSubmitState();
  }

  $('#almSalidaBusSuggest')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-bus-id]');
    if (!button || state.salidaBusBlocked) return;
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

    tbody.innerHTML = state.salidaItems.map((item, index) => {
      const labelChips = item.label_codes?.length
        ? `<div class="alm-item-labels">${item.label_codes.map((code) => `<span>${esc(code)}</span>`).join('')}</div>`
        : '';
      return `
        <tr>
          <td>${index + 1}</td>
          <td><strong>(${esc(item.codigo)}) ${esc(item.producto)}</strong>${labelChips}</td>
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
      `;
    }).join('');
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
    if (isAccountingOrigin && !Number.isInteger(cantidad)) {
      await alertBox('En Contabilidad la cantidad de salida debe ser entera.', 'warning');
      $('#almSalidaCantidad').focus();
      return;
    }

    const stock = toNumber(product.stock);
    if (cantidad > stock) {
      await alertBox(`La cantidad supera el stock actual (${fmtQty(stock)}).`, 'warning');
      $('#almSalidaCantidad').focus();
      return;
    }

    const selectedLabels = isAccountingOrigin ? getSelectedSalidaLabels() : [];
    if (isAccountingOrigin && selectedLabels.length && selectedLabels.length !== cantidad) {
      await alertBox('La cantidad debe coincidir con las etiquetas seleccionadas.', 'warning');
      $('#almSalidaCantidad').focus();
      return;
    }

    const existing = state.salidaItems.find((item) => Number(item.id) === Number(product.id));
    if (existing) {
      if (isAccountingOrigin) {
        const existingHasLabels = Boolean(existing.label_ids?.length);
        const nextHasLabels = Boolean(selectedLabels.length);
        if (existingHasLabels !== nextHasLabels) {
          await alertBox('No mezcles en el mismo producto una salida con etiquetas seleccionadas y otra sin seleccion. Quita el item y vuelve a agregarlo completo.', 'warning');
          return;
        }
      }
      const nextQty = toNumber(existing.cantidad) + cantidad;
      if (nextQty > stock) {
        await alertBox(`La suma de items supera el stock actual (${fmtQty(stock)}).`, 'warning');
        return;
      }
      existing.cantidad = nextQty;
      existing.monto = nextQty * toNumber(existing.precio);
      if (selectedLabels.length) {
        const existingIds = new Set(existing.label_ids || []);
        selectedLabels.forEach((label) => {
          const id = Number(label.id);
          if (!existingIds.has(id)) {
            existing.label_ids.push(id);
            existing.label_codes.push(label.codigo || `ETQ-${id}`);
            existingIds.add(id);
          }
        });
      }
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
        label_ids: selectedLabels.map((label) => Number(label.id)),
        label_codes: selectedLabels.map((label) => label.codigo || `ETQ-${label.id}`),
      });
    }

    $('#almSalidaCantidad').value = '';
    state.selectedSalida = null;
    renderSelectedProduct('salida', null);
    clearSalidaLabels();
    renderSalidaItems();
  });

  $('#almSalidaConfirm')?.addEventListener('change', updateSalidaSubmitState);
  $('#almSalidaEntregado')?.addEventListener('input', () => syncActaRecibeFromEntregado(false));

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

    if (!state.salidaBusBlocked && !$('#almSalidaPlacaId').value) {
      await alertBox('Selecciona una unidad valida para la salida o activa Sin bus.', 'warning');
      $('#almSalidaBusInput').focus();
      return;
    }

    if (isRrhhContext() && !String($('#almSalidaEntregado')?.value || '').trim()) {
      await alertBox('Selecciona o escribe el trabajador que recibira los bienes.', 'warning');
      $('#almSalidaEntregado')?.focus();
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

    const confirmed = await confirmBox('Se registrara una nota de salida ' + originSerieSalida + ' con los items indicados. Continuamos?', {
      title: 'Confirmar salida',
      confirmText: 'Registrar salida',
      cancelText: 'Cancelar',
      variant: 'danger',
    });
    if (!confirmed) return;

    const password = await promptBox('Ingresa la contrasena de seguridad para registrar esta salida.', {
      title: 'Validar salida',
      inputType: 'password',
      inputLabel: 'Contrasena de seguridad',
      placeholder: 'Clave de nota de salida',
      confirmText: 'Validar y guardar',
      cancelText: 'Cancelar',
      variant: 'danger',
      required: true,
      autocomplete: 'one-time-code',
      name: 'n360_salida_security_code',
      preventAutofill: true,
    });
    if (password === null) return;
    payload.password = password;

    try {
      const data = await withLoader(
        () => fetchJson('save_salida', { method: 'POST', json: payload }),
        { title: 'Registrando salida', detail: 'Validando stock y guardando nota...', button: '#almSalidaSubmit' }
      );
      const downloadWarnings = [];
      if (data.nota_id && window.N360NotaPDF) {
        try {
          await window.N360NotaPDF.downloadByNotaId(data.nota_id);
        } catch (downloadError) {
          downloadWarnings.push(`nota PDF: ${downloadError.message}`);
        }
      }
      if (data.acta_id && window.N360ActaUniformes) {
        try {
          await window.N360ActaUniformes.downloadByActaId(data.acta_id);
        } catch (downloadError) {
          downloadWarnings.push(`acta PDF: ${downloadError.message}`);
        }
      }
      const warningText = downloadWarnings.length
        ? `\nGuardado OK, pero revisa descarga de ${downloadWarnings.join(' / ')}.`
        : '';
      await alertBox(`Salida registrada correctamente.\nNota: ${data.nota_codigo || data.nota_id}${warningText}`, downloadWarnings.length ? 'warning' : 'success', 'Salida guardada');
      window.location.reload();
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  });

  updateContextLabels();
  initActaFields();
  renderSelectedProduct('entrada', null);
  renderSelectedProduct('salida', null);
  renderSalidaItems();
})();
