(function () {
  'use strict';

  const page = document.getElementById('contaTracePage');
  if (!page) return;

  const api = page.dataset.api || 'trazabilidad_activos_api.php';
  const csrf = page.dataset.csrf || '';
  const itemName = page.dataset.itemName === 'artículo' ? 'artículo' : 'activo';
  const moveModal = document.getElementById('traceMoveModal');
  const historyModal = document.getElementById('traceHistoryModal');
  const barcodeModal = document.getElementById('traceBarcodeModal');
  const infoModal = document.getElementById('traceInfoModal');
  const barcodeCard = document.getElementById('traceBarcodeCard');
  const moveForm = document.getElementById('traceMoveForm');
  const moveReason = document.getElementById('traceMoveMotivo');
  const moveReasonEnabled = !moveReason.disabled;
  const assetLocation = window.N360AssetLocation;
  const locationDetailEnabled = page.dataset.locationDetail === '1';
  const locationSpacesEnabled = page.dataset.locationSpaces === '1';
  const refreshSpaces = assetLocation.bindSpaces(document.getElementById('traceMoveEspacio'), document.getElementById('traceMoveSede'));
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
    barcodeCard.dataset.barcodeMonth = button.dataset.month || '';
    barcodeCard.dataset.barcodeName = button.dataset.name || 'Activo fijo';
    barcodeCard.dataset.barcodeCategory = button.dataset.category || 'Contabilidad';
    barcodeCard.dataset.barcodeCategoryCode = button.dataset.categoryCode || '';
    barcodeCard.dataset.barcodeCategoryName = button.dataset.categoryName || '';
    barcodeCard.dataset.barcodeFilename = button.dataset.filename || `ETQ_${code}`;
    const codeText = $('#traceBarcodeCode');
    if (codeText) codeText.textContent = code;
    $('#traceBarcodeHeroCode').textContent = code;
    rerenderBarcodeCard();
    openModal(barcodeModal);
  }

  function updateMovePreview() {
    const sede = $('#traceMoveSede');
    const sedeText = sede?.value ? sede.selectedOptions[0]?.textContent?.trim() || '' : '';
    const detail = locationDetailEnabled ? assetLocation.detail($('#traceMoveDetalle')?.value || '') : '';
    const preview = $('#traceMovePreview');
    if (preview) preview.textContent = sedeText ? assetLocation.location(sedeText,
      assetLocation.description(assetLocation.selectedSpace($('#traceMoveEspacio')), detail)) : 'Selecciona una sede/oficina.';
  }

  function setMoveSaving(value) {
    moveSaving = value;
    $('#traceMoveSubmit').disabled = value;
    $('#traceMoveSede').disabled = value;
    $('#traceMoveDetalle').disabled = value || !locationDetailEnabled;
    moveReason.disabled = value || !moveReasonEnabled;
    refreshSpaces.setDisabled(value || !locationSpacesEnabled);
    moveModal.querySelectorAll('[data-trace-close]').forEach((button) => { button.disabled = value; });
  }

  function openMove(button) {
    if (!moveModal || !moveForm) return;

    $('#traceMoveLabelId').value = button.dataset.labelId || '';
    $('#traceMoveCode').textContent = button.dataset.labelCode || '-';
    $('#traceMoveProduct').textContent = button.dataset.product || '-';
    const currentDetail = button.dataset.currentDetalle || '';
    $('#traceMoveCurrent').textContent = assetLocation.location(button.dataset.currentSede || 'Sin ubicacion',
      assetLocation.description(button.dataset.currentEspacio, currentDetail));
    $('#traceMoveExpectedSede').value = button.dataset.currentSedeId || '0';
    $('#traceMoveExpectedDetalle').value = currentDetail;
    $('#traceMoveExpectedEspacio').value = button.dataset.currentEspacioId || '0';
    $('#traceMoveDetalle').value = currentDetail;
    moveReason.value = '';

    const select = $('#traceMoveSede');
    if (select) {
      select.value = String(button.dataset.currentSedeId || '');
      Array.from(select.options).forEach((option) => {
        option.disabled = false;
      });
    }

    refreshSpaces(button.dataset.currentEspacioId || '');
    assetLocation.renderPersonnel($('#traceMoveCurrentPersonal'), assetLocation.spacePersonnel($('#traceMoveEspacio'), button.dataset.currentEspacioId),
      Number(button.dataset.currentEspacioId) > 0);
    refreshDetailSuggestions();
    updateMovePreview();
    openModal(moveModal);
  }

  $('#traceMoveSede')?.addEventListener('change', updateMovePreview);
  $('#traceMoveDetalle')?.addEventListener('input', updateMovePreview);
  $('#traceMoveEspacio')?.addEventListener('change', updateMovePreview);

  async function openHistory(button) {
    if (!historyModal) return;
    const labelId = button.dataset.labelId || '';
    const labelCode = button.dataset.labelCode || 'Etiqueta';

    $('#traceHistoryCode').textContent = labelCode;
    $('#traceHistoryCount').textContent = '-';
    $('#traceHistoryLatest').textContent = '-';
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
    if (body.id === 'traceHistoryBody') {
      $('#traceHistoryCount').textContent = rows.length;
      $('#traceHistoryLatest').textContent = rows.length ? formatDate(rows[0].fecha) : 'Sin registros';
    }

    if (!rows.length) {
      body.innerHTML = '<div class="trace-empty">Esta etiqueta aun no tiene historial de ubicacion.</div>';
      return;
    }

    body.innerHTML = rows.map((row) => `
      <article class="trace-history-item">
        <div class="trace-history-meta"><time><i class="bi bi-calendar3" aria-hidden="true"></i>${esc(formatDate(row.fecha))}</time><span class="trace-history-user"><i class="bi bi-person" aria-hidden="true"></i> ${esc(row.usuario || 'Usuario')}</span></div>
          <div class="trace-history-route">
            <div class="trace-history-place"><span>Origen</span><strong>${esc(assetLocation.location(row.sede_antes || 'Sin ubicacion', assetLocation.description(row.espacio_antes, row.detalle_antes)))}</strong></div>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
            <div class="trace-history-place"><span>Destino</span><strong>${esc(assetLocation.location(row.sede_despues || 'Sin ubicacion', assetLocation.description(row.espacio_despues, row.detalle_despues)))}</strong></div>
          </div>
          ${row.motivo ? `<p class="trace-history-reason"><strong>Motivo del traslado</strong><span>${esc(row.motivo)}</span></p>` : ''}
      </article>
    `).join('');
  }

  const fieldIcons = {'Producto': 'bi-box-seam', 'Categoria': 'bi-grid', 'Unidad': 'bi-rulers', 'Estado de etiqueta': 'bi-shield-check', 'Creacion de etiqueta': 'bi-calendar3', 'Ubicacion actual': 'bi-geo-alt', 'Clasificación de ubicación': 'bi-diagram-3', 'Descripcion del producto': 'bi-card-text', 'Movimiento': 'bi-arrow-left-right', 'Tipo': 'bi-tag', 'Fecha de registro': 'bi-calendar3', 'Registrado por': 'bi-person-badge', 'Nota de origen': 'bi-receipt', 'Cantidad total del movimiento': 'bi-boxes', 'Documento de referencia': 'bi-file-earmark-text', 'Proveedor': 'bi-building', 'Observacion del movimiento': 'bi-chat-left-text'};
  const field = (name, value, wide = false) => `<div${wide ? ' class="trace-field-wide"' : ''}><dt><i class="bi ${fieldIcons[name] || 'bi-info-circle'}" aria-hidden="true"></i><span>${esc(name)}</span></dt><dd>${esc(value == null || String(value).trim() === '' ? '-' : value)}</dd></div>`;

  function renderInfo(data) {
    const label = data.label;
    const movements = data.product_movements || [];
    $('#traceInfoBody').innerHTML = `
      <section class="trace-info-section"><h3><i class="bi bi-box-seam" aria-hidden="true"></i> Producto y etiqueta</h3><dl class="trace-info-grid">
        ${field('Producto', `(${label.producto_codigo || '-'}) ${label.producto_nombre || '-'}`, true)}
        ${field('Categoria', label.categoria)}${field('Unidad', label.producto_unidad)}
        ${field('Estado de etiqueta', label.etiqueta_estado)}${field('Creacion de etiqueta', formatDate(label.etiqueta_fecha))}
        ${field('Ubicacion actual', assetLocation.location(label.sede_nombre || 'Sin ubicacion', assetLocation.description(label.espacio_nombre, label.ubicacion_detalle)), true)}
        ${Number(label.espacio_id) > 0 ? field('Personal del espacio - Turno 1', assetLocation.personnelNames(label.personal, 1))
          + field('Personal del espacio - Turno 2', assetLocation.personnelNames(label.personal, 2)) : ''}
        ${field('Clasificación de ubicación', label.clasificacion_nombre || 'Sin clasificar', true)}
      </dl><dl class="trace-info-grid trace-info-grid--full">${field('Descripcion del producto', label.producto_descripcion)}</dl></section>
      <section class="trace-info-section"><h3><i class="bi bi-receipt" aria-hidden="true"></i> Movimiento de origen</h3><dl class="trace-info-grid">
        ${field('Movimiento', `MOV-${label.movimiento_id}`)}${field('Tipo', label.movimiento_tipo)}
        ${field('Fecha de registro', formatDate(label.movimiento_fecha))}${field('Registrado por', label.movimiento_usuario)}
        ${field('Nota de origen', label.nota_codigo)}${field('Cantidad total del movimiento', label.movimiento_cantidad)}
        ${field('Documento de referencia', label.documento_referencia)}${field('Proveedor', label.proveedor)}
      </dl><p class="trace-info-help">La cantidad corresponde al movimiento de origen y puede incluir varias etiquetas.</p>
      <dl class="trace-info-grid trace-info-grid--full">${field('Observacion del movimiento', label.movimiento_observacion)}</dl></section>
      <section class="trace-info-section"><h3><i class="bi bi-clock-history" aria-hidden="true"></i> Historial de ubicaciones (${(data.history || []).length})</h3><div class="trace-history-list" id="traceInfoHistory"></div></section>
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
    $('#traceInfoBody').textContent = `Consultando información del ${itemName}...`;
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
  const spaceFilter = $('#traceFilterEspacio');
  const classificationFilter = $('#traceFilterClasificacion');
  const spaceFilterOptions = Array.from(spaceFilter?.options || []).map(option => option.cloneNode(true));
  function filterSpaces() {
    if (!spaceFilter || !sedeFilter) return;
    const selected = spaceFilter.value;
    const classification = classificationFilter?.value || '0';
    const options = spaceFilterOptions.filter(option => !option.dataset.sede || (
      (sedeFilter.value === '0' || option.dataset.sede === sedeFilter.value)
      && (classification === '0' || (classification === '-1' ? option.dataset.clasificacion === '0' : option.dataset.clasificacion === classification))
    ));
    spaceFilter.replaceChildren(...options.map(option => option.cloneNode(true)));
    spaceFilter.value = options.some(option => option.value === selected) ? selected : '0';
  }
  sedeFilter?.addEventListener('change', filterSpaces);
  classificationFilter?.addEventListener('change', filterSpaces);
  filterSpaces();
  filterDetailSuggestions();

  function visibleReport() {
    const report = JSON.parse($('#traceExportData').textContent);
    const byId = new Map(report.rows.map(row => [String(row.etiqueta_id), row]));
    report.rows = Array.from(page.querySelectorAll('[data-trace-row]')).filter(row => !row.hidden).map(row => byId.get(row.dataset.labelId)).filter(Boolean);
    return report;
  }

  $('#traceExportPdf')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    if (button.disabled) return;
    const original = button.innerHTML;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i><span>Generando PDF...</span>';
    try {
      if (!window.N360ActivosPDF) throw new Error('No se cargo el generador de PDF. Actualiza la pagina.');
      await window.N360ActivosPDF.exportPdf(visibleReport());
    } catch (error) {
      await alertBox(error.message || 'No se pudo generar el PDF.', 'error');
    } finally {
      button.innerHTML = original;
      button.removeAttribute('aria-busy');
      button.disabled = false;
    }
  });

  $('#traceExportExcel')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      if (!window.N360ActivosExcel) throw new Error('No se cargo el generador de Excel. Actualiza la pagina.');
      window.N360ActivosExcel.exportExcel(visibleReport());
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
    const activeModal = [infoModal, moveModal, historyModal, barcodeModal].find(modal => modal && !modal.hidden);
    if (event.key === 'Tab' && activeModal && !moveSaving) {
      const focusable = Array.from(activeModal.querySelectorAll('button:not(:disabled), summary, [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]')).filter(item => item.getClientRects().length);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || !activeModal.contains(document.activeElement))) { event.preventDefault(); last?.focus(); }
      if (!event.shiftKey && (document.activeElement === last || !activeModal.contains(document.activeElement))) { event.preventDefault(); first?.focus(); }
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
    const reason = moveReasonEnabled ? moveReason.value.trim() : '';
    const targetText = assetLocation.location($('#traceMoveSede')?.selectedOptions?.[0]?.textContent?.trim() || 'la nueva ubicacion',
      assetLocation.description(assetLocation.selectedSpace($('#traceMoveEspacio')), detail));

    if (!labelId || !newSedeId) {
      await alertBox(`Selecciona la nueva ubicación del ${itemName}.`, 'warning');
      return;
    }

    if (assetLocation.length(detail) > 220) {
      await alertBox('El detalle de ubicación admite hasta 220 caracteres.', 'warning');
      return;
    }
    if (assetLocation.length(reason) > 500) {
      await alertBox('El motivo del traslado admite hasta 500 caracteres.', 'warning');
      return;
    }

    setMoveSaving(true);
    try {
      const confirmed = await confirmBox(`Se movera ${labelCode} hacia ${targetText}. No se creara movimiento de almacen.`, {
        title: 'Confirmar traslado',
        confirmText: `Mover ${itemName}`,
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
          motivo: reason,
          espacio_id: Number($('#traceMoveEspacio').value) || null,
          expected_espacio_id: Number($('#traceMoveExpectedEspacio').value) || null,
          expected_sede_id: Number($('#traceMoveExpectedSede').value),
          expected_detalle: $('#traceMoveExpectedDetalle').value,
        }),
      }), {
        title: `Moviendo ${itemName}`,
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
