(function () {
  const root = document.querySelector('[data-enc-review]');
  if (!root) return;

  const form = root.querySelector('[data-enc-review-form]');
  const states = ['PENDIENTE', 'OK', 'REZAGADO', 'OBSERVADO'];

  const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));

  const stateOptions = (selected) => states.map((state) => {
    const label = state === 'REZAGADO' ? 'Rezagado' : state === 'OBSERVADO' ? 'Observado' : state === 'PENDIENTE' ? 'Pendiente' : 'OK';
    return `<option value="${escapeHtml(state)}"${state === selected ? ' selected' : ''}>${escapeHtml(label)}</option>`;
  }).join('');

  const parseJson = async (response) => {
    const text = await response.text();
    try {
      return JSON.parse(text.replace(/^\uFEFF/, ''));
    } catch (error) {
      throw new Error('El servidor devolvio una respuesta no valida.');
    }
  };

  const showDialog = (message, variant, title) => {
    if (window.N360Dialog && typeof window.N360Dialog.alert === 'function') {
      return window.N360Dialog.alert(message, { variant: variant || 'info', title: title || 'Encomiendas' });
    }
    console.log(title || 'Encomiendas', message);
    return Promise.resolve();
  };

  const confirmDialog = (message, title) => {
    if (window.N360Dialog && typeof window.N360Dialog.confirm === 'function') {
      return window.N360Dialog.confirm(message, {
        variant: 'warning',
        title: title || 'Confirmar',
        confirmText: 'Guardar',
        cancelText: 'Cancelar'
      });
    }
    return Promise.resolve(window.confirm(message));
  };

  const refreshCounts = () => {
    const counts = states.reduce((carry, state) => {
      carry[state] = 0;
      return carry;
    }, {});
    const rows = root.querySelectorAll('[data-enc-review-row]');
    root.querySelectorAll('[data-enc-review-state]').forEach((select) => {
      const state = states.includes(select.value) ? select.value : 'PENDIENTE';
      counts[state] += 1;
      const row = select.closest('[data-enc-review-row]');
      if (row) {
        states.forEach((item) => row.classList.remove(`is-${item.toLowerCase()}`));
        row.classList.add(`is-${state.toLowerCase()}`);
      }
    });
    states.forEach((state) => {
      root.querySelectorAll(`[data-enc-review-count="${state}"]`).forEach((node) => {
        node.textContent = counts[state];
      });
    });
    root.querySelectorAll('[data-enc-review-count-total]').forEach((node) => {
      node.textContent = rows.length;
    });
    root.querySelectorAll('[data-enc-review-sheet]').forEach((sheet) => {
      const sheetRows = sheet.querySelectorAll('[data-enc-review-row]');
      const countNode = sheet.querySelector('[data-enc-review-sheet-count]');
      const emptyNode = sheet.querySelector('[data-enc-review-empty]');
      if (countNode) countNode.textContent = sheetRows.length;
      if (emptyNode) emptyNode.hidden = sheetRows.length > 0;
      sheetRows.forEach((row, idx) => {
        const numberNode = row.querySelector('.enc-review-row__num');
        if (numberNode) numberNode.textContent = String(idx + 1).padStart(2, '0');
      });
    });
    const submit = root.querySelector('[data-enc-review-submit]');
    if (submit) {
      submit.disabled = rows.length <= 0;
    }
  };

  const manualRowHtml = (sheetIndex, itemIndex) => {
    const base = `sheets[${sheetIndex}][items][${itemIndex}]`;
    return `
      <div class="enc-review-row__num">${String(itemIndex + 1).padStart(2, '0')}</div>
      <input type="hidden" name="${base}[manual]" value="1">
      <div class="enc-review-row__manual">
        <label><span>Documento</span><input type="text" name="${base}[documento]" maxlength="100" autocomplete="off" placeholder="Codigo o comprobante"></label>
        <label><span>Consignado</span><input type="text" name="${base}[consignado]" maxlength="255" autocomplete="off" placeholder="Cliente o destinatario"></label>
        <label class="is-wide"><span>Referencia</span><input type="text" name="${base}[referencia_envio]" maxlength="1000" autocomplete="off" placeholder="Detalle de encomienda"></label>
        <label><span>Peso</span><input type="number" name="${base}[peso]" step="0.0001" min="0" inputmode="decimal" placeholder="0.00"></label>
        <label><span>Pago</span><input type="text" name="${base}[tipo_pago]" maxlength="80" autocomplete="off" placeholder="Efectivo, Yape..."></label>
        <label><span>Importe</span><input type="number" name="${base}[importe_cobrado]" step="0.0001" min="0" inputmode="decimal" placeholder="0.00"></label>
        <label><span>Guia remision</span><input type="text" name="${base}[guia_remision]" maxlength="100" autocomplete="off" placeholder="Opcional"></label>
      </div>
      <button class="stock-btn stock-btn--soft stock-btn--sm enc-review-row__remove" type="button" data-enc-review-remove-manual><i class="bi bi-trash3"></i> Quitar</button>
      <label class="stock-field enc-review-row__state">
        <span>Estado</span>
        <select name="${base}[estado]" data-enc-review-state>${stateOptions('PENDIENTE')}</select>
      </label>
      <label class="stock-field enc-review-row__obs">
        <span>Observacion</span>
        <input type="text" name="${base}[observacion]" maxlength="1000" autocomplete="off">
      </label>
    `;
  };

  document.addEventListener('change', (event) => {
    if (event.target.closest('[data-enc-review-state]')) {
      refreshCounts();
    }
  });

  document.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-enc-review-add-item]');
    if (addButton) {
      const sheet = addButton.closest('[data-enc-review-sheet]');
      const list = sheet ? sheet.querySelector('[data-enc-review-list]') : null;
      if (!sheet || !list) return;
      const sheetIndex = sheet.dataset.encReviewSheetIndex || '0';
      const itemIndex = Number.parseInt(sheet.dataset.encReviewNextIndex || String(list.querySelectorAll('[data-enc-review-row]').length), 10);
      const row = document.createElement('article');
      row.className = 'enc-review-row enc-review-row--manual is-pendiente';
      row.setAttribute('data-enc-review-row', '');
      row.innerHTML = manualRowHtml(sheetIndex, Number.isFinite(itemIndex) ? itemIndex : 0);
      list.appendChild(row);
      sheet.dataset.encReviewNextIndex = String((Number.isFinite(itemIndex) ? itemIndex : 0) + 1);
      refreshCounts();
      const firstInput = row.querySelector('input[name$="[documento]"]');
      if (firstInput) firstInput.focus();
      return;
    }

    const removeButton = event.target.closest('[data-enc-review-remove-manual]');
    if (removeButton) {
      const row = removeButton.closest('[data-enc-review-row]');
      if (row) {
        row.remove();
        refreshCounts();
      }
    }
  });

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const confirmed = await confirmDialog(form.dataset.confirm || 'Guardar cambios de la revision.', 'Guardar revision');
      if (!confirmed) return;

      const task = async () => {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const payload = await parseJson(response);
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || 'No se pudo guardar la revision.');
        }
        return payload;
      };

      try {
        const payload = window.N360Loader && window.N360Loader.during
          ? await window.N360Loader.during(task(), { title: 'Guardando revision', detail: 'Actualizando items del manifiesto...' })
          : await task();
        await showDialog(payload.message || 'Revision guardada correctamente.', 'success', 'Revision guardada');
        if (payload.redirect) {
          window.location.href = payload.redirect;
        }
      } catch (error) {
        await showDialog(error.message || 'No se pudo guardar la revision.', 'danger', 'Operacion no completada');
      }
    });
  }

  refreshCounts();
})();
