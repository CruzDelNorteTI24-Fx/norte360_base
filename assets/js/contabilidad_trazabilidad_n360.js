(function () {
  'use strict';

  const page = document.getElementById('contaTracePage');
  if (!page) return;

  const api = page.dataset.api || 'trazabilidad_activos_api.php';
  const csrf = page.dataset.csrf || '';
  const moveModal = document.getElementById('traceMoveModal');
  const historyModal = document.getElementById('traceHistoryModal');
  const barcodeModal = document.getElementById('traceBarcodeModal');
  const barcodeCard = document.getElementById('traceBarcodeCard');
  const moveForm = document.getElementById('traceMoveForm');
  const assetLocation = window.N360AssetLocation;
  const locationDetailEnabled = page.dataset.locationDetail === '1';
  let moveSaving = false;

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
    if (!raw) return '-';
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
    if (![moveModal, historyModal, barcodeModal].some((item) => item && !item.hidden)) {
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

  function renderHistory(rows) {
    const body = $('#traceHistoryBody');
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

  document.addEventListener('click', (event) => {
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
      closeModal(moveModal);
      closeModal(historyModal);
      closeModal(barcodeModal);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
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
