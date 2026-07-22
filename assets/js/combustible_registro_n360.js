(function () {
  'use strict';

  const page = document.getElementById('combRegPage');
  if (!page) return;

  const api = page.dataset.api || 'api/registro_combustible_api.php';
  const csrf = page.dataset.csrf || '';
  const bootstrap = window.N360_COMB_REG_BOOTSTRAP || {};
  const isAdmin = page.dataset.isAdmin === '1' || bootstrap.isAdmin === true;
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const el = {
    producto: $('#combProducto'),
    grifo: $('#combGrifo'),
    fuelSearch: $('#fuelSearch'),
    productCode: $('[data-product-code]'),
    productName: $('[data-product-name]'),
    productUnit: $('[data-product-unit]'),
    grifoCurrent: $('[data-grifo-current]'),
    stockProducto: $('[data-stock-producto]'),
    stockGrifo: $('[data-stock-grifo]'),
    puRef: $('[data-pu-ref]'),
    entradaForm: $('#entradaForm'),
    entradaCantidad: $('#entradaCantidad'),
    entradaPrecio: $('#entradaPrecio'),
    entradaMonto: $('#entradaMonto'),
    entradaSaldoEstimado: $('#entradaSaldoEstimado'),
    entradaAbastecedor: $('#entradaAbastecedor'),
    entradaObs: $('#entradaObs'),
    salidaForm: $('#salidaForm'),
    salidaBusSearch: $('#salidaBusSearch'),
    salidaBusId: $('#salidaBusId'),
    salidaBusResults: $('[data-bus-results]'),
    salidaConductorSearch: $('#salidaConductorSearch'),
    salidaConductorId: $('#salidaConductorId'),
    salidaConductorResults: $('[data-conductor-results]'),
    salidaCantidad: $('#salidaCantidad'),
    salidaPuRef: $('#salidaPuRef'),
    salidaPuExtra: $('#salidaPuExtra'),
    salidaPuFinal: $('#salidaPuFinal'),
    salidaMonto: $('#salidaMonto'),
    salidaSaldoEstimado: $('#salidaSaldoEstimado'),
    salidaObs: $('#salidaObs'),
    selectedBus: $('[data-selected-bus]'),
    selectedConductor: $('[data-selected-conductor]'),
    recentModal: $('#combRecentModal'),
    recentBody: $('[data-recent-body]'),
    chooserModal: $('#combChooserModal'),
    chooserTitle: $('#combChooserTitle'),
    chooserEyebrow: $('[data-chooser-eyebrow]'),
    chooserInput: $('#combChooserInput'),
    chooserStatus: $('#combChooserStatus'),
    chooserResults: $('#combChooserResults'),
  };

  const state = {
    product: null,
    fuelStocks: bootstrap.fuelStocks || {},
    stockProducto: 0,
    stockGrifo: 0,
    puRef: 0,
    busTimer: null,
    conductorTimer: null,
    chooserTimer: null,
    chooserKind: '',
    loadingState: false,
  };

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const toNumber = (value) => {
    const text = String(value ?? '').replace(/[^\d.,-]/g, '').replace(',', '.');
    const number = Number(text);
    return Number.isFinite(number) ? number : 0;
  };

  const text = (value, fallback = '-') => {
    const clean = String(value ?? '').trim();
    return clean || fallback;
  };

  const fmtQty = (value) => {
    const number = toNumber(value);
    return number.toLocaleString('es-PE', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 4,
    });
  };

  const fmtMoney = (value) => {
    const number = toNumber(value);
    return 'S/ ' + number.toLocaleString('es-PE', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  };

  const fmtMoney4 = (value) => {
    const number = toNumber(value);
    return 'S/ ' + number.toLocaleString('es-PE', {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4,
    });
  };

  const setDisplayValue = (node, value) => {
    if (!node) return;
    if ('value' in node) node.value = value;
    else node.textContent = value;
  };

  const fmtDateTime = (value) => {
    const raw = String(value ?? '').trim();
    if (!raw) return '-';
    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleString('es-PE', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const alertBox = (message, variant = 'info', title = '') => {
    if (window.N360Dialog?.alert) {
      return window.N360Dialog.alert(message, { variant, title });
    }
    window.alert(message);
    return Promise.resolve(true);
  };

  const confirmBox = (message, options = {}) => {
    if (window.N360Dialog?.confirm) {
      return window.N360Dialog.confirm(message, options);
    }
    return Promise.resolve(window.confirm(message));
  };

  const promptPassword = (message, series) => {
    if (window.N360Dialog?.prompt) {
      return window.N360Dialog.prompt(message, {
        title: 'Validar nota ' + series,
        inputType: 'password',
        inputLabel: 'Contrasena de seguridad',
        placeholder: 'Clave autorizada',
        confirmText: 'Validar y guardar',
        cancelText: 'Cancelar',
        variant: 'danger',
        required: true,
        autocomplete: 'one-time-code',
        name: 'n360_comb_' + series.toLowerCase() + '_' + Date.now(),
        preventAutofill: true,
      });
    }
    const value = window.prompt(message);
    return Promise.resolve(value === null ? null : value);
  };

  const withLoader = async (task, options = {}) => {
    if (window.N360Loader?.during) {
      return window.N360Loader.during(task, options);
    }
    return task();
  };

  const buildUrl = (action, params = {}) => {
    const url = new URL(api, window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value) !== '') {
        url.searchParams.set(key, value);
      }
    });
    return url;
  };

  async function fetchJson(action, options = {}) {
    const method = options.method || 'GET';
    const headers = {
      Accept: 'application/json',
      ...(options.headers || {}),
    };
    const request = {
      method,
      headers,
      credentials: 'same-origin',
    };

    if (options.json) {
      headers['Content-Type'] = 'application/json';
      request.body = JSON.stringify(options.json);
    } else if (options.body) {
      request.body = options.body;
    }

    const response = await fetch(buildUrl(action, options.params || {}), request);
    const rawText = await response.text();
    const parsedText = rawText.replace(/^\uFEFF/, '').trim();
    let payload = null;

    try {
      payload = parsedText ? JSON.parse(parsedText) : {};
    } catch (error) {
      throw new Error('El servidor no devolvio JSON valido. Revisa errores PHP o salida inesperada.');
    }

    if (!response.ok || !payload.ok) {
      throw new Error(payload.message || 'No se pudo completar la operacion.');
    }

    return payload;
  }

  function selectedProductFromOption() {
    const option = el.producto?.selectedOptions?.[0];
    if (!option || !option.value) return null;
    return {
      id: Number(option.value),
      codigo: option.dataset.code || '',
      nombre: option.dataset.name || '',
      unidad: option.dataset.unit || '',
      precio_unitario: toNumber(option.dataset.price || 0),
    };
  }

  function selectedGrifoLabel() {
    const option = el.grifo?.selectedOptions?.[0];
    return option?.dataset?.label || option?.textContent?.trim() || 'Selecciona grifo';
  }

  function setProductDisplay(product) {
    const data = product || selectedProductFromOption();
    state.product = data;

    if (!data) {
      if (el.productCode) el.productCode.textContent = '--';
      if (el.productName) el.productName.textContent = 'Selecciona un combustible';
      if (el.productUnit) el.productUnit.textContent = 'Unidad';
      return;
    }

    if (el.productCode) el.productCode.textContent = data.codigo || ('COMB' + data.id);
    if (el.productName) el.productName.textContent = data.nombre || 'Combustible';
    if (el.productUnit) el.productUnit.textContent = data.unidad || 'GLN';

    const currentPrice = toNumber(el.entradaPrecio?.value);
    if (el.entradaPrecio && currentPrice <= 0 && toNumber(data.precio_unitario) > 0) {
      el.entradaPrecio.value = Number(data.precio_unitario).toFixed(4);
    }
  }

  function setActiveProductButton(productId) {
    $$('[data-product-button]').forEach((button) => {
      button.classList.toggle('is-active', Number(button.dataset.productId || 0) === Number(productId || 0));
    });
  }

  function setActiveGrifoButton(grifoId) {
    $$('[data-grifo-button]').forEach((button) => {
      button.classList.toggle('is-active', Number(button.dataset.grifoId || 0) === Number(grifoId || 0));
    });
    if (el.grifoCurrent) el.grifoCurrent.textContent = selectedGrifoLabel();
  }

  function updateFuelStocks(map = {}) {
    state.fuelStocks = map || {};
    $$('[data-product-stock-badge]').forEach((badge) => {
      const productId = badge.dataset.productStockBadge;
      badge.textContent = fmtQty(state.fuelStocks[productId] || 0);
    });
  }

  function setStats(stats = {}) {
    const values = {
      movimientos: Number(stats.movimientos || 0).toLocaleString('es-PE'),
      entradas: fmtQty(stats.entradas || 0),
      salidas: fmtQty(stats.salidas || 0),
      balance: fmtQty(stats.balance || 0),
      monto_salidas: fmtMoney(stats.monto_salidas || 0),
    };

    Object.entries(values).forEach(([key, value]) => {
      const target = $('[data-stat="' + key + '"]');
      if (target) target.textContent = value;
    });
  }

  function applyState(next = {}) {
    state.stockProducto = toNumber(next.stock_producto_grifo || 0);
    state.stockGrifo = toNumber(next.stock_grifo || 0);
    state.puRef = toNumber(next.pu_ref_salida || 0);

    if (next.fuel_stocks) {
      updateFuelStocks(next.fuel_stocks);
    }

    if (next.product) {
      setProductDisplay(next.product);
    } else {
      setProductDisplay(selectedProductFromOption());
    }

    setActiveProductButton(el.producto?.value || 0);
    setActiveGrifoButton(el.grifo?.value || 0);

    if (el.stockProducto) el.stockProducto.textContent = fmtQty(state.stockProducto);
    if (el.stockGrifo) el.stockGrifo.textContent = fmtQty(state.stockGrifo);
    if (el.puRef) el.puRef.textContent = fmtMoney4(state.puRef);
    if (el.salidaPuRef) el.salidaPuRef.value = fmtMoney4(state.puRef);
    recalcEntrada();
    recalcSalida();
  }

  async function refreshState(showError = true) {
    const productId = el.producto?.value || '';
    const grifoId = el.grifo?.value || '';
    if (!productId || !grifoId) {
      applyState({
        product: selectedProductFromOption(),
        stock_producto_grifo: 0,
        stock_grifo: 0,
        pu_ref_salida: 0,
        fuel_stocks: {},
      });
      return;
    }

    if (state.loadingState) return;
    state.loadingState = true;

    try {
      const data = await fetchJson('state', {
        params: {
          producto_id: productId,
          grifo_id: grifoId,
        },
      });
      applyState(data);
    } catch (error) {
      if (showError) await alertBox(error.message, 'error');
    } finally {
      state.loadingState = false;
    }
  }

  function renderRecent(rows = []) {
    if (!el.recentBody) return;

    if (!rows.length) {
      el.recentBody.innerHTML = '<tr><td colspan="7" class="stock-empty">Aun no hay movimientos recientes.</td></tr>';
      return;
    }

    el.recentBody.innerHTML = rows.map((row) => {
      const tipo = String(row.tipo || '').toLowerCase();
      const product = String((row.codigo || '') + ' ' + (row.producto || '')).trim();
      const grifo = String((row.grifo_codigo || '') + ' ' + (row.grifo_nombre || '')).trim();
      return `
        <tr>
          <td><span class="stock-code">${esc(row.nota || '-')}</span></td>
          <td>${esc(fmtDateTime(row.fecha))}</td>
          <td><span class="comb-reg-chip comb-reg-chip--${esc(tipo)}">${esc(row.tipo || '-')}</span></td>
          <td>${esc(grifo || '-')}</td>
          <td>${esc(product || '-')}</td>
          <td class="stock-num">${esc(fmtQty(row.cantidad || 0))}</td>
          <td class="stock-num">${esc(fmtMoney(row.monto || 0))}</td>
        </tr>
      `;
    }).join('');
  }

  function recalcEntrada() {
    const qty = toNumber(el.entradaCantidad?.value);
    const price = toNumber(el.entradaPrecio?.value);
    const saldo = Math.round((state.stockProducto + qty) * 10000) / 10000;
    if (el.entradaMonto) el.entradaMonto.value = fmtMoney4(qty * price);
    setDisplayValue(el.entradaSaldoEstimado, fmtQty(saldo));
  }

  function recalcSalida() {
    const qty = toNumber(el.salidaCantidad?.value);
    const extra = toNumber(el.salidaPuExtra?.value);
    const puFinal = Math.round((state.puRef + extra) * 10000) / 10000;
    const monto = Math.round(qty * puFinal * 10000) / 10000;
    const saldo = Math.round((state.stockProducto - qty) * 10000) / 10000;

    if (el.salidaPuFinal) el.salidaPuFinal.value = fmtMoney4(puFinal);
    if (el.salidaMonto) el.salidaMonto.value = fmtMoney4(monto);
    if (el.salidaSaldoEstimado) {
      setDisplayValue(el.salidaSaldoEstimado, fmtQty(saldo));
      el.salidaSaldoEstimado.classList.toggle('is-negative', saldo < 0);
    }
  }

  function selectMode(mode) {
    $$('[data-mode-btn]').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.modeBtn === mode);
    });
    $$('[data-process]').forEach((form) => {
      form.classList.toggle('is-active', form.dataset.process === mode);
    });
    $$('[data-money-process]').forEach((panel) => {
      panel.classList.toggle('is-active', panel.dataset.moneyProcess === mode);
    });
    $$('[data-stock-estimate]').forEach((panel) => {
      panel.classList.toggle('is-active', panel.dataset.stockEstimate === mode);
    });
  }

  function clearLookup(kind) {
    if (kind === 'bus') {
      if (el.salidaBusId) el.salidaBusId.value = '';
      if (el.selectedBus) el.selectedBus.textContent = 'Sin seleccionar';
      if (el.salidaBusResults) {
        el.salidaBusResults.hidden = true;
        el.salidaBusResults.innerHTML = '';
      }
      return;
    }

    if (el.salidaConductorId) el.salidaConductorId.value = '';
    if (el.selectedConductor) el.selectedConductor.textContent = 'Sin seleccionar';
    if (el.salidaConductorResults) {
      el.salidaConductorResults.hidden = true;
      el.salidaConductorResults.innerHTML = '';
    }
  }

  function renderLookup(container, rows, emptyText, type) {
    if (!container) return;
    container.hidden = false;

    if (!rows.length) {
      container.innerHTML = `<button type="button" disabled><strong>${esc(emptyText)}</strong></button>`;
      return;
    }

    if (type === 'bus') {
      container.innerHTML = rows.map((row) => {
        const label = `${row.bus || 'Unidad'} (${row.placa || '-'})`;
        return `
          <button type="button" data-id="${esc(row.id)}" data-label="${esc(label)}">
            <strong>${esc(label)}</strong>
            <small>${esc(row.servicio || 'Unidad activa')}</small>
          </button>
        `;
      }).join('');
      return;
    }

    container.innerHTML = rows.map((row) => {
      const label = `${row.nombre || 'Conductor'} (${row.dni || '-'})`;
      return `
        <button type="button" data-id="${esc(row.id)}" data-label="${esc(label)}">
          <strong>${esc(label)}</strong>
          <small>${esc(row.cargo || row.tipo || 'Conductor activo')}</small>
        </button>
      `;
    }).join('');
  }

  async function searchBuses(query) {
    if (!query.trim()) {
      clearLookup('bus');
      return;
    }
    const data = await fetchJson('buses', { params: { q: query } });
    renderLookup(el.salidaBusResults, data.rows || [], 'Sin unidades coincidentes', 'bus');
  }

  async function searchConductores(query) {
    if (!query.trim()) {
      clearLookup('conductor');
      return;
    }
    const data = await fetchJson('conductores', { params: { q: query } });
    renderLookup(el.salidaConductorResults, data.rows || [], 'Sin conductores coincidentes', 'conductor');
  }

  function setBusSelection(id, label) {
    if (el.salidaBusId) el.salidaBusId.value = id || '';
    if (el.salidaBusSearch) el.salidaBusSearch.value = label || '';
    if (el.selectedBus) el.selectedBus.textContent = label || 'Sin seleccionar';
    if (el.salidaBusResults) el.salidaBusResults.hidden = true;
  }

  function setBus(row) {
    const label = `${text(row.bus || row.nombre, 'Unidad')} (${text(row.placa)})`;
    setBusSelection(row.id || '', label);
  }

  function setConductor(row) {
    const label = `${text(row.nombre, 'Conductor')} (${text(row.dni)})`;
    if (el.salidaConductorId) el.salidaConductorId.value = row.id || '';
    if (el.salidaConductorSearch) el.salidaConductorSearch.value = label;
    if (el.selectedConductor) el.selectedConductor.textContent = label;
    if (el.salidaConductorResults) el.salidaConductorResults.hidden = true;
  }

  function resetEntrada() {
    if (el.entradaCantidad) el.entradaCantidad.value = '';
    if (el.entradaObs) el.entradaObs.value = '';
    if (el.entradaAbastecedor) el.entradaAbastecedor.value = '';
    recalcEntrada();
    el.entradaCantidad?.focus();
  }

  function resetSalida() {
    if (el.salidaBusSearch) el.salidaBusSearch.value = '';
    if (el.salidaConductorSearch) el.salidaConductorSearch.value = '';
    if (el.salidaCantidad) el.salidaCantidad.value = '';
    if (el.salidaPuExtra) el.salidaPuExtra.value = '';
    if (el.salidaObs) el.salidaObs.value = '';
    clearLookup('bus');
    clearLookup('conductor');
    recalcSalida();
    el.salidaBusSearch?.focus();
  }

  async function maybeDownloadNota(notaId) {
    if (!notaId || !window.N360NotaPDF?.downloadByNotaId) return;
    await window.N360NotaPDF.downloadByNotaId(notaId);
  }

  function syncAfterSave(data) {
    if (data.stats) setStats(data.stats);
    if (isAdmin && data.recent) renderRecent(data.recent);
    if (data.state) applyState(data.state);
  }

  async function handleEntradaSubmit(event) {
    event.preventDefault();

    const productId = Number(el.producto?.value || 0);
    const grifoId = Number(el.grifo?.value || 0);
    const cantidad = toNumber(el.entradaCantidad?.value);
    const precio = toNumber(el.entradaPrecio?.value);
    const abastecedor = String(el.entradaAbastecedor?.value || '').trim();

    if (!productId) {
      await alertBox('Selecciona un combustible.', 'warning');
      return;
    }
    if (!grifoId) {
      await alertBox('Selecciona el grifo de abastecimiento.', 'warning');
      return;
    }
    if (cantidad <= 0) {
      await alertBox('Ingresa una cantidad mayor a cero.', 'warning');
      el.entradaCantidad?.focus();
      return;
    }
    if (precio < 0) {
      await alertBox('El precio unitario no puede ser negativo.', 'warning');
      el.entradaPrecio?.focus();
      return;
    }
    if (!abastecedor) {
      await alertBox('Ingresa el suministrador del abastecimiento.', 'warning');
      el.entradaAbastecedor?.focus();
      return;
    }

    const confirmed = await confirmBox('Se registrara una nota AB y una entrada de combustible al grifo seleccionado.', {
      title: 'Confirmar abastecimiento',
      confirmText: 'Continuar',
      cancelText: 'Cancelar',
      variant: 'warning',
    });
    if (!confirmed) return;

    const password = await promptPassword('Ingresa la contrasena para registrar el abastecimiento AB.', 'AB');
    if (password === null) return;

    const payload = {
      csrf,
      producto_id: productId,
      grifo_id: grifoId,
      cantidad,
      precio_unitario: precio,
      abastecedor,
      observacion: el.entradaObs?.value || '',
      password,
    };

    try {
      const data = await withLoader(
        () => fetchJson('save_entrada', { method: 'POST', json: payload }),
        { title: 'Registrando AB', detail: 'Guardando abastecimiento y nota...', button: '#btnGuardarEntrada' }
      );
      syncAfterSave(data);
      await maybeDownloadNota(data.nota_id);
      resetEntrada();
      await alertBox('Abastecimiento registrado correctamente.\nNota: ' + (data.nota_codigo || data.nota_id), 'success', 'AB guardada');
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  }

  async function handleSalidaSubmit(event) {
    event.preventDefault();

    const productId = Number(el.producto?.value || 0);
    const grifoId = Number(el.grifo?.value || 0);
    const busId = Number(el.salidaBusId?.value || 0);
    const conductorId = Number(el.salidaConductorId?.value || 0);
    const cantidad = toNumber(el.salidaCantidad?.value);
    const extra = toNumber(el.salidaPuExtra?.value);
    const puFinal = Math.round((state.puRef + extra) * 10000) / 10000;

    if (!productId) {
      await alertBox('Selecciona un combustible.', 'warning');
      return;
    }
    if (!grifoId) {
      await alertBox('Selecciona el grifo de salida.', 'warning');
      return;
    }
    if (!busId) {
      await alertBox('Selecciona una unidad valida para la tanqueada.', 'warning');
      el.salidaBusSearch?.focus();
      return;
    }
    if (!conductorId) {
      await alertBox('Selecciona el conductor.', 'warning');
      el.salidaConductorSearch?.focus();
      return;
    }
    if (cantidad <= 0) {
      await alertBox('Ingresa una cantidad mayor a cero.', 'warning');
      el.salidaCantidad?.focus();
      return;
    }
    if (cantidad > state.stockProducto) {
      await alertBox('La cantidad supera el stock disponible del combustible en este grifo.', 'warning');
      el.salidaCantidad?.focus();
      return;
    }
    if (state.puRef <= 0 || puFinal <= 0) {
      await alertBox('No hay PU de referencia valido para esta salida. Registra primero un abastecimiento.', 'warning');
      return;
    }
    if (extra < 0) {
      await alertBox('El PU extra no puede ser negativo.', 'warning');
      el.salidaPuExtra?.focus();
      return;
    }

    const confirmed = await confirmBox('Se registrara una nota CM y se descontara stock del grifo seleccionado.', {
      title: 'Confirmar tanqueada',
      confirmText: 'Continuar',
      cancelText: 'Cancelar',
      variant: 'danger',
    });
    if (!confirmed) return;

    const password = await promptPassword('Ingresa la contrasena para registrar la tanqueada CM.', 'CM');
    if (password === null) return;

    const payload = {
      csrf,
      producto_id: productId,
      grifo_id: grifoId,
      bus_id: busId,
      conductor_id: conductorId,
      cantidad,
      precio_extra: extra,
      observacion: el.salidaObs?.value || '',
      password,
    };

    try {
      const data = await withLoader(
        () => fetchJson('save_salida', { method: 'POST', json: payload }),
        { title: 'Registrando CM', detail: 'Validando stock, PU y nota...', button: '#btnGuardarSalida' }
      );
      syncAfterSave(data);
      await maybeDownloadNota(data.nota_id);
      resetSalida();
      await alertBox('Tanqueada registrada correctamente.\nNota: ' + (data.nota_codigo || data.nota_id), 'success', 'CM guardada');
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  }

  function addEnterNavigation(form) {
    if (!form) return;
    form.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.target.matches('textarea')) return;
      const fields = $$('input:not([type="hidden"]), textarea, button[type="submit"]', form)
        .filter((field) => !field.disabled && !field.hidden && field.offsetParent !== null);
      const index = fields.indexOf(event.target);
      if (index < 0) return;
      event.preventDefault();
      const next = fields[index + 1];
      if (next) {
        next.focus();
      } else {
        form.requestSubmit();
      }
    });
  }

  function openRecentModal() {
    if (!isAdmin || !el.recentModal) return;
    el.recentModal.classList.add('is-open');
    el.recentModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('n360-modal-open');
  }

  function closeRecentModal() {
    if (!el.recentModal) return;
    el.recentModal.classList.remove('is-open');
    el.recentModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('n360-modal-open');
  }

  async function refreshRecent() {
    if (!isAdmin || !el.recentBody) return;
    try {
      const data = await fetchJson('recent');
      renderRecent(data.rows || []);
    } catch (error) {
      await alertBox(error.message, 'error');
    }
  }

  function openChooser(kind) {
    state.chooserKind = kind;
    if (!el.chooserModal) return;

    const isBus = kind === 'bus';
    if (el.chooserTitle) el.chooserTitle.textContent = isBus ? 'Seleccionar unidad' : 'Seleccionar conductor';
    if (el.chooserEyebrow) el.chooserEyebrow.textContent = isBus ? 'Tanqueada CM' : 'Conductor activo';
    if (el.chooserInput) {
      el.chooserInput.value = isBus ? (el.salidaBusSearch?.value || '') : (el.salidaConductorSearch?.value || '');
      el.chooserInput.placeholder = isBus ? 'Bus o placa...' : 'Nombre, DNI o cargo...';
    }
    if (el.chooserStatus) el.chooserStatus.textContent = isBus ? 'Escribe el bus o placa para buscar.' : 'Busca y selecciona el conductor.';
    if (el.chooserResults) el.chooserResults.innerHTML = '';

    el.chooserModal.classList.add('is-open');
    el.chooserModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('n360-modal-open');
    window.setTimeout(() => {
      el.chooserInput?.focus();
      const value = String(el.chooserInput?.value || '').trim();
      if (kind === 'conductor' || value.length >= 1) {
        searchChooser(value).catch((error) => {
          if (el.chooserStatus) el.chooserStatus.textContent = error.message;
        });
      }
    }, 80);
  }

  function closeChooser() {
    if (!el.chooserModal) return;
    el.chooserModal.classList.remove('is-open');
    el.chooserModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('n360-modal-open');
  }

  async function searchChooser(query) {
    const kind = state.chooserKind;
    if (!kind) return;
    const q = String(query || '').trim();

    if (kind === 'bus' && q.length < 1) {
      if (el.chooserStatus) el.chooserStatus.textContent = 'Escribe el bus o placa para buscar.';
      if (el.chooserResults) el.chooserResults.innerHTML = '';
      return;
    }

    if (el.chooserStatus) el.chooserStatus.textContent = 'Buscando...';
    const data = await fetchJson(kind === 'bus' ? 'buses' : 'conductores', { params: { q } });
    const rows = data.rows || [];

    if (!rows.length) {
      if (el.chooserStatus) el.chooserStatus.textContent = kind === 'bus' ? 'No hay unidades coincidentes.' : 'No hay conductores coincidentes.';
      if (el.chooserResults) el.chooserResults.innerHTML = '';
      return;
    }

    if (el.chooserStatus) el.chooserStatus.textContent = rows.length + ' coincidencia(s).';
    if (!el.chooserResults) return;

    el.chooserResults.innerHTML = rows.map((row) => {
      if (kind === 'bus') {
        const label = `${text(row.bus || row.nombre, 'Unidad')} (${text(row.placa)})`;
        return `
          <button type="button" class="comb-reg-chooser__item" data-choose-id="${esc(row.id)}" data-choose-label="${esc(label)}" data-choose-json="${esc(JSON.stringify(row))}">
            <strong>${esc(label)}</strong>
            <small>${esc(row.servicio || 'Unidad activa')}</small>
          </button>
        `;
      }

      const label = `${text(row.nombre, 'Conductor')} (${text(row.dni)})`;
      return `
        <button type="button" class="comb-reg-chooser__item" data-choose-id="${esc(row.id)}" data-choose-label="${esc(label)}" data-choose-json="${esc(JSON.stringify(row))}">
          <strong>${esc(label)}</strong>
          <small>${esc(row.cargo || row.tipo || 'Conductor activo')}</small>
        </button>
      `;
    }).join('');
  }

  el.producto?.addEventListener('change', () => refreshState());
  el.grifo?.addEventListener('change', () => refreshState());
  el.entradaCantidad?.addEventListener('input', recalcEntrada);
  el.entradaPrecio?.addEventListener('input', recalcEntrada);
  el.salidaCantidad?.addEventListener('input', recalcSalida);
  el.salidaPuExtra?.addEventListener('input', recalcSalida);

  $$('[data-product-button]').forEach((button) => {
    button.addEventListener('click', () => {
      if (el.producto) el.producto.value = button.dataset.productId || '';
      setActiveProductButton(button.dataset.productId || 0);
      refreshState();
    });
  });

  $$('[data-grifo-button]').forEach((button) => {
    button.addEventListener('click', () => {
      if (el.grifo) el.grifo.value = button.dataset.grifoId || '';
      setActiveGrifoButton(button.dataset.grifoId || 0);
      refreshState();
    });
  });

  el.fuelSearch?.addEventListener('input', () => {
    const q = String(el.fuelSearch.value || '').trim().toLowerCase();
    $$('[data-product-button]').forEach((button) => {
      const haystack = `${button.dataset.code || ''} ${button.dataset.name || ''} ${button.dataset.unit || ''}`.toLowerCase();
      button.hidden = q !== '' && !haystack.includes(q);
    });
  });

  $$('[data-mode-btn]').forEach((button) => {
    button.addEventListener('click', () => selectMode(button.dataset.modeBtn || 'entrada'));
  });

  $$('[data-reset-form]').forEach((button) => {
    button.addEventListener('click', () => {
      if (button.dataset.resetForm === 'salida') resetSalida();
      else resetEntrada();
    });
  });

  el.salidaBusSearch?.addEventListener('input', (event) => {
    clearLookup('bus');
    window.clearTimeout(state.busTimer);
    state.busTimer = window.setTimeout(() => {
      searchBuses(event.target.value).catch((error) => alertBox(error.message, 'error'));
    }, 240);
  });

  el.salidaConductorSearch?.addEventListener('input', (event) => {
    clearLookup('conductor');
    window.clearTimeout(state.conductorTimer);
    state.conductorTimer = window.setTimeout(() => {
      searchConductores(event.target.value).catch((error) => alertBox(error.message, 'error'));
    }, 240);
  });

  el.salidaBusResults?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-id]');
    if (!button) return;
    setBusSelection(button.dataset.id || '', button.dataset.label || '');
    el.salidaConductorSearch?.focus();
  });

  el.salidaConductorResults?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-id]');
    if (!button) return;
    if (el.salidaConductorId) el.salidaConductorId.value = button.dataset.id || '';
    if (el.salidaConductorSearch) el.salidaConductorSearch.value = button.dataset.label || '';
    if (el.selectedConductor) el.selectedConductor.textContent = button.dataset.label || 'Sin seleccionar';
    el.salidaConductorResults.hidden = true;
    el.salidaCantidad?.focus();
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.comb-reg-lookup')) {
      if (el.salidaBusResults) el.salidaBusResults.hidden = true;
      if (el.salidaConductorResults) el.salidaConductorResults.hidden = true;
    }
  });

  $$('[data-open-chooser]').forEach((button) => {
    button.addEventListener('click', () => openChooser(button.dataset.openChooser || 'conductor'));
  });

  el.chooserInput?.addEventListener('input', () => {
    window.clearTimeout(state.chooserTimer);
    state.chooserTimer = window.setTimeout(() => {
      searchChooser(el.chooserInput.value).catch((error) => {
        if (el.chooserStatus) el.chooserStatus.textContent = error.message;
      });
    }, 220);
  });

  el.chooserResults?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-choose-json]');
    if (!button) return;
    let row = {};
    try {
      row = JSON.parse(button.dataset.chooseJson || '{}');
    } catch (error) {
      row = { id: button.dataset.chooseId, nombre: button.dataset.chooseLabel };
    }

    if (state.chooserKind === 'bus') {
      setBus(row);
      closeChooser();
      el.salidaConductorSearch?.focus();
      return;
    }

    setConductor(row);
    closeChooser();
    el.salidaCantidad?.focus();
  });

  $$('[data-chooser-close]').forEach((button) => {
    button.addEventListener('click', closeChooser);
  });

  $('[data-recent-open]')?.addEventListener('click', openRecentModal);
  $$('[data-recent-close]').forEach((button) => button.addEventListener('click', closeRecentModal));
  $('[data-recent-refresh]')?.addEventListener('click', () => refreshRecent());

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (el.chooserModal?.classList.contains('is-open')) closeChooser();
    if (el.recentModal?.classList.contains('is-open')) closeRecentModal();
  });

  el.entradaForm?.addEventListener('submit', handleEntradaSubmit);
  el.salidaForm?.addEventListener('submit', handleSalidaSubmit);
  addEnterNavigation(el.entradaForm);
  addEnterNavigation(el.salidaForm);

  setStats(bootstrap.stats || {});
  updateFuelStocks(bootstrap.fuelStocks || {});
  renderRecent(bootstrap.recent || []);
  applyState({
    product: selectedProductFromOption(),
    stock_producto_grifo: 0,
    stock_grifo: 0,
    pu_ref_salida: 0,
    fuel_stocks: bootstrap.fuelStocks || {},
  });
  refreshState(false);
})();
