(function () {
  const root = document.querySelector('[data-enc-review]');
  if (!root) return;

  const form = root.querySelector('[data-enc-review-form]');
  const states = ['PENDIENTE', 'OK', 'REZAGADO', 'OBSERVADO'];

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
  };

  document.addEventListener('change', (event) => {
    if (event.target.closest('[data-enc-review-state]')) {
      refreshCounts();
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
