(function () {
  'use strict';

  const page = document.getElementById('contaTracePage');
  if (!page) return;

  const api = page.dataset.api || 'trazabilidad_activos_api.php';
  const csrf = page.dataset.csrf || '';
  const moveModal = document.getElementById('traceMoveModal');
  const historyModal = document.getElementById('traceHistoryModal');
  const barcodeModal = document.getElementById('traceBarcodeModal');
  const infoModal = document.getElementById('traceInfoModal');
  const barcodeCard = document.getElementById('traceBarcodeCard');
  const moveForm = document.getElementById('traceMoveForm');
  const assetLocation = window.N360AssetLocation;
  const locationDetailEnabled = page.dataset.locationDetail === '1';
  const refreshDetailSuggestions = assetLocation.bindSuggestions(
    document.getElementById('traceMoveDetalle'),
    document.getElementById('traceMoveDetalleSugerencias'),
    document.getElementById('traceMoveSede')
  );
  let moveSaving = false;
  let infoRequest = null;
  let infoVersion = 0;
  let infoOpener = null;

  const $ = (selector, root = document) => root.querySelector(selector);

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const alertBox = (message, variant = 'info', title = '') => {
    if (window.N360Dialog) {
      return window.N360Dialog.alert(message, { variant, title });
    }
    window.alert(message);
    return Promise.resolve();
  };

  const confirmBox = (message, options = {}) => {
    if (window.N360Dialog) {
      return window.N360Dialog.confirm(message, options);
    }
    return Promise.resolve(window.confirm(message));
  };

  const withLoader = (task, options = {}) => {
    if (window.N360Loader?.during) {
      return window.N360Loader.during(task, options);
    }
    return task();
  };

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text.replace(/^\uFEFF/, ''));
    } catch (error) {
      throw new Error('Respuesta invalida del servidor.');
    }

    if (!response.ok || data.ok === false) {
      throw new Error(data.message || 'No se pudo completar la operacion.');
    }
    return data;
  }

  function formatDate(value) {
    const raw = String(value || '').trim();
    if (!raw || raw.startsWith('0000-00-00')) return '-';
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return raw;
    return `${match[3]}/${match[2]}/${match[1]}${match[4] ? ` ${match[4]}:${match[5]}` : ''}`;
  }

  function openModal(modal) {
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('trace-modal-open');
    window.setTimeout(() => {
      const first = modal.querySelector('select, button, input');
      first?.focus();
    }, 30);
  }

  function closeModal(modal) {
    if (!modal) return;
    if (modal === moveModal && moveSaving) return;
    modal.hidden = true;
    if (modal === infoModal) {
      infoVersion += 1;
      infoRequest?.abort();
      infoRequest = null;
      infoOpener?.focus();
    }
    if (![moveModal, historyModal, barcodeModal, infoModal].some((item) => item && !item.hidden)) {
      document.body.classList.remove('trace-modal-open');
    }
  }

  function rerenderBarcodeCard() {
    if (!barcodeCard) return;
    delete barcodeCard.dataset.barcodeRendered;
    window.N360Barcode?.renderElement(barcodeCard);
  }

  function openBarcode(button) {
    if (!barcodeModal || !barcodeCard) return;
    const code = button.dataset.code || 'ETQ';
    barcodeCard.dataset.barcodeCode = code;
    barcodeCard.dataset.barcodeYear = button.dataset.year || '';
    barcodeCard.dataset.barcodeName = button.dataset.name || 'Activo fijo';
    barcodeCard.dataset.barcodeCategory = button.dataset.category || 'Contabilidad';
    barcodeCard.dataset.barcodeFilename = button.dataset.filename || `ETQ_${code}`;
    const codeText = $('#traceBarcodeCode');
    if (codeText) codeText.textContent = code;
    rerenderBarcodeCard();
    openModal(barcodeModal);
  }

  function updateMovePreview() {
    const sede = $('#traceMoveSede');
    const sedeText = sede?.value ? sede.selectedOptions[0]?.textContent?.trim() || '' : '';
    const detail = locationDetailEnabled ? assetLocation.detail($('#traceMoveDetalle')?.value || '') : '';
    const preview = $('#traceMovePreview');
    if (preview) preview.textContent = sedeText ? assetLocation.location(sedeText, detail) : 'Selecciona una sede/oficina.';
  }

  function setMoveSaving(value) {
    moveSaving = value;
    $('#traceMoveSubmit').disabled = value;
    $('#traceMoveSede').disabled = value;
    $('#traceMoveDetalle').disabled = value || !locationDetailEnabled;
    moveModal.querySelectorAll('[data-trace-close]').forEach((button) => { button.disabled = value; });
  }

  function openMove(button) {
    if (!moveModal || !moveForm) return;

    $('#traceMoveLabelId').value = button.dataset.labelId || '';
    $('#traceMoveCode').textContent = button.dataset.labelCode || '-';
    $('#traceMoveProduct').textContent = button.dataset.product || '-';
    const currentDetail = button.dataset.currentDetalle || '';
    $('#traceMoveCurrent').textContent = assetLocation.location(button.dataset.currentSede || 'Sin ubicacion', currentDetail);
    $('#traceMoveExpectedSede').value = button.dataset.currentSedeId || '0';
    $('#traceMoveExpectedDetalle').value = currentDetail;
    $('#traceMoveDetalle').value = currentDetail;

    const select = $('#traceMoveSede');
    if (select) {
      select.value = String(button.dataset.currentSedeId || '');
      Array.from(select.options).forEach((option) => {
        option.disabled = false;
      });
    }

    refreshDetailSuggestions();
    updateMovePreview();
    openModal(moveModal);
  }

  $('#traceMoveSede')?.addEventListener('change', updateMovePreview);
  $('#traceMoveDetalle')?.addEventListener('input', updateMovePreview);

  async function openHistory(button) {
    if (!historyModal) return;
    const labelId = button.dataset.labelId || '';
    const labelCode = button.dataset.labelCode || 'Etiqueta';

    $('#traceHistoryCode').textContent = labelCode;
    $('#traceHistoryBody').innerHTML = '<div class="trace-empty">Cargando historial...</div>';
    openModal(historyModal);

    try {
      const url = `${api}?action=history&id=${encodeURIComponent(labelId)}`;
      const data = await withLoader(() => fetchJson(url), {
        title: 'Cargando historial',
        detail: 'Consultando ubicaciones de la etiqueta...'
      });
      renderHistory(data.rows || []);
    } catch (error) {
      $('#traceHistoryBody').innerHTML = `<div class="trace-alert"><i class="bi bi-exclamation-triangle-fill"></i><span>${esc(error.message)}</span></div>`;
    }
  }

  function renderHistory(rows, body = $('#traceHistoryBody')) {
    if (!body) return;

    if (!rows.length) {
      body.innerHTML = '<div class="trace-empty">Esta etiqueta aun no tiene historial de ubicacion.</div>';
      return;
    }

    body.innerHTML = rows.map((row) => `
      <article class="trace-history-item">
        <time>${esc(formatDate(row.fecha))}</time>
        <div>
          <div class="trace-history-route">
            <strong>${esc(assetLocation.location(row.sede_antes || 'Sin ubicacion', row.detalle_antes || ''))}</strong>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
            <strong>${esc(assetLocation.location(row.sede_despues || 'Sin ubicacion', row.detalle_despues || ''))}</strong>
          </div>
          <div class="trace-history-user">Registrado por ${esc(row.usuario || 'Usuario')}</div>
        </div>
      </article>
    `).join('');
  }

  const field = (name, value) => `<div><dt>${esc(name)}</dt><dd>${esc(value == null || String(value).trim() === '' ? '-' : value)}</dd></div>`;

  function renderInfo(data) {
    const label = data.label;
    const movements = data.product_movements || [];
    $('#traceInfoBody').innerHTML = `
      <section class="trace-info-section"><h3>Producto y etiqueta</h3><dl class="trace-info-grid">
        ${field('Producto', `(${label.producto_codigo || '-'}) ${label.producto_nombre || '-'}`)}
        ${field('Categoria', label.categoria)}${field('Unidad', label.producto_unidad)}
        ${field('Estado de etiqueta', label.etiqueta_estado)}${field('Creacion de etiqueta', formatDate(label.etiqueta_fecha))}
        ${field('Ubicacion actual', assetLocation.location(label.sede_nombre || 'Sin ubicacion', label.ubicacion_detalle || ''))}
      </dl><dl class="trace-info-grid trace-info-grid--full">${field('Descripcion del producto', label.producto_descripcion)}</dl></section>
      <section class="trace-info-section"><h3>Movimiento que genero la etiqueta</h3><dl class="trace-info-grid">
        ${field('Movimiento', `MOV-${label.movimiento_id}`)}${field('Tipo', label.movimiento_tipo)}
        ${field('Fecha de registro', formatDate(label.movimiento_fecha))}${field('Registrado por', label.movimiento_usuario)}
        ${field('Nota de origen', label.nota_codigo)}${field('Cantidad total del movimiento', label.movimiento_cantidad)}
        ${field('Documento de referencia', label.documento_referencia)}${field('Proveedor', label.proveedor)}
      </dl><p class="trace-info-help">La cantidad corresponde al movimiento de origen y puede incluir varias etiquetas.</p>
      <dl class="trace-info-grid trace-info-grid--full">${field('Observacion del movimiento', label.movimiento_observacion)}</dl></section>
      <section class="trace-info-section"><h3>Historial de ubicaciones (${(data.history || []).length})</h3><div class="trace-history-list" id="traceInfoHistory"></div></section>
      <details class="trace-info-section trace-product-movements"><summary>Movimientos generales del producto (${movements.length})</summary>
        <p class="trace-info-help">Incluye entradas, salidas e inventariados de todas las unidades de este producto. El movimiento que genero esta etiqueta se identifica como Origen.</p>
        <div class="trace-info-table-wrap"><table class="trace-info-table"><thead><tr><th>Movimiento</th><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Nota</th><th>Observacion</th></tr></thead><tbody>
        ${movements.map(row => `<tr${Number(row.id_movimiento) === Number(label.movimiento_id) ? ' class="trace-info-origin"' : ''}>
          <td>MOV-${esc(row.id_movimiento)}${Number(row.id_movimiento) === Number(label.movimiento_id) ? '<strong>Origen</strong>' : ''}</td>
          <td>${esc(formatDate(row.fecha_registro_MOV))}</td><td>${esc(row.tipo_MOV)}</td><td>${esc(row.cantidad_MOV)}</td>
          <td>${esc(row.nota_label || `MOV-${row.id_movimiento}`)}</td><td>${esc(row.observacion_MOV || '-')}</td></tr>`).join('')}
        </tbody></table></div>
      </details>`;
    renderHistory(data.history || [], $('#traceInfoHistory'));
  }

  async function openInfo(button) {
    if (!infoModal) return;
    infoRequest?.abort();
    const version = ++infoVersion;
    const request = new AbortController();
    infoRequest = request;
    if (button.matches('[data-trace-info]')) infoOpener = button;
    const id = button.dataset.labelId || '';
    const code = button.dataset.labelCode || 'Etiqueta';
    $('#traceInfoCode').textContent = code;
    $('#traceInfoBody').innerHTML = '<p class="trace-empty">Consultando informacion del activo...</p>';
    openModal(infoModal);
    try {
      const data = await fetchJson(`${api}?action=detail&id=${encodeURIComponent(id)}`, {credentials: 'same-origin', signal: request.signal});
      if (version !== infoVersion || infoModal.hidden) return;
      renderInfo(data);
    } catch (error) {
      if (version !== infoVersion || error.name === 'AbortError') return;
      $('#traceInfoBody').innerHTML = `<div class="trace-alert">${esc(error.message)}</div><button type="button" class="trace-btn trace-btn--soft" data-trace-info-retry data-label-id="${esc(id)}" data-label-code="${esc(code)}">Volver a intentar</button>`;
    } finally {
      if (version === infoVersion) infoRequest = null;
    }
  }

  const detailList = $('#traceFilterDetalleList');
  const sedeFilter = $('#traceFilters select[name=sede]');
  const detailOptions = Array.from(detailList?.options || []).map(option => ({value: option.value, sede: option.dataset.sede}));
  function filterDetailSuggestions() {
    if (!detailList || !sedeFilter) return;
    const seen = new Set();
    const options = [];
    for (const option of detailOptions) {
      if ((sedeFilter.value === '0' || option.sede === sedeFilter.value) && !seen.has(option.value)) {
        seen.add(option.value);
        options.push(new Option(option.value));
      }
    }
    detailList.replaceChildren(...options);
  }
  sedeFilter?.addEventListener('change', filterDetailSuggestions);
  filterDetailSuggestions();

  $('#traceExportExcel')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      if (!window.N360ActivosExcel) throw new Error('No se cargo el generador de Excel. Actualiza la pagina.');
      const report = JSON.parse($('#traceExportData').textContent);
      const byId = new Map(report.rows.map(row => [String(row.etiqueta_id), row]));
      report.rows = Array.from(page.querySelectorAll('[data-trace-row]')).filter(row => !row.hidden).map(row => byId.get(row.dataset.labelId)).filter(Boolean);
      window.N360ActivosExcel.exportExcel(report);
    } catch (error) {
      await alertBox(error.message || 'No se pudo exportar el Excel.', 'error');
    } finally {
      button.disabled = false;
    }
  });

  document.addEventListener('click', (event) => {
    const infoButton = event.target.closest('[data-trace-info], [data-trace-info-retry]');
    if (infoButton) {
      openInfo(infoButton);
      return;
    }
    const moveButton = event.target.closest('[data-trace-move]');
    if (moveButton) {
      openMove(moveButton);
      return;
    }

    const historyButton = event.target.closest('[data-trace-history]');
    if (historyButton) {
      openHistory(historyButton);
      return;
    }

    const labelButton = event.target.closest('[data-trace-label]');
    if (labelButton) {
      openBarcode(labelButton);
      return;
    }

    const rerenderButton = event.target.closest('[data-trace-barcode-rerender]');
    if (rerenderButton) {
      rerenderBarcodeCard();
      return;
    }

    const closer = event.target.closest('[data-trace-close]');
    if (closer) {
      closeModal(closer.closest('.trace-modal'));
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Tab' && infoModal && !infoModal.hidden) {
      const focusable = Array.from(infoModal.querySelectorAll('button:not(:disabled), summary, [href], input, select, [tabindex="0"]')).filter(item => item.getClientRects().length);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || !infoModal.contains(document.activeElement))) { event.preventDefault(); last?.focus(); }
      if (!event.shiftKey && (document.activeElement === last || !infoModal.contains(document.activeElement))) { event.preventDefault(); first?.focus(); }
    }
    if (event.key !== 'Escape') return;
    if (infoModal && !infoModal.hidden) { closeModal(infoModal); return; }
    closeModal(moveModal);
    closeModal(historyModal);
    closeModal(barcodeModal);
  });

  moveForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (moveSaving) return;

    const labelId = $('#traceMoveLabelId')?.value || '';
    const newSedeId = $('#traceMoveSede')?.value || '';
    const labelCode = $('#traceMoveCode')?.textContent || 'Etiqueta';
    const detail = locationDetailEnabled ? assetLocation.detail($('#traceMoveDetalle')?.value || '') : '';
    const targetText = assetLocation.location($('#traceMoveSede')?.selectedOptions?.[0]?.textContent?.trim() || 'la nueva ubicacion', detail);

    if (!labelId || !newSedeId) {
      await alertBox('Selecciona la nueva ubicacion del activo.', 'warning');
      return;
    }

    if (assetLocation.length(detail) > 220) {
      await alertBox('El detalle de ubicación admite hasta 220 caracteres.', 'warning');
      return;
    }

    setMoveSaving(true);
    try {
      const confirmed = await confirmBox(`Se movera ${labelCode} hacia ${targetText}. No se creara movimiento de almacen.`, {
        title: 'Confirmar traslado',
        confirmText: 'Mover activo',
        cancelText: 'Cancelar',
        variant: 'warning',
      });
      if (!confirmed) return;

      const data = await withLoader(() => fetchJson(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'move_location',
          csrf,
          label_id: Number(labelId),
          new_sede_id: Number(newSedeId),
          ubicacion_detalle: detail,
          expected_sede_id: Number($('#traceMoveExpectedSede').value),
          expected_detalle: $('#traceMoveExpectedDetalle').value,
        }),
      }), {
        title: 'Moviendo activo',
        detail: 'Actualizando ubicacion y bitacora...',
        button: '#traceMoveSubmit',
      });

      setMoveSaving(false);
      closeModal(moveModal);
      await alertBox(data.message || 'Ubicacion actualizada correctamente.', 'success', 'Traslado registrado');
      window.location.reload();
    } catch (error) {
      await alertBox(error.message, 'error');
    } finally {
      setMoveSaving(false);
    }
  });
})();
