(function () {
  const root = document.querySelector('[data-enc-rezagados]');
  if (!root) return;

  const modalNode = document.getElementById('encManualRezagadoModal');
  if (modalNode && modalNode.parentElement !== document.body) {
    document.body.appendChild(modalNode);
  }

  const form = document.querySelector('[data-enc-manual-form]');

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

  const closeModal = () => {
    if (!modalNode || !window.bootstrap) return;
    const modal = window.bootstrap.Modal.getInstance(modalNode);
    if (modal) modal.hide();
  };

  document.addEventListener('shown.bs.modal', (event) => {
    if (event.target && event.target.id === 'encManualRezagadoModal') {
      const first = event.target.querySelector('select[name="revision_id"]');
      if (first) first.focus();
    }
  });

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const confirmed = await confirmDialog(form.dataset.confirm || 'Guardar encomienda manual.', 'Agregar manual');
      if (!confirmed) return;

      const submitButton = form.querySelector('button[type="submit"]');
      const task = async () => {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const payload = await parseJson(response);
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || 'No se pudo guardar la encomienda manual.');
        }
        return payload;
      };

      try {
        const payload = window.N360Loader && typeof window.N360Loader.during === 'function'
          ? await window.N360Loader.during(task(), { button: submitButton, title: 'Guardando encomienda', detail: 'Registrando item manual...' })
          : await task();

        closeModal();
        await showDialog(payload.message || 'Encomienda manual agregada correctamente.', 'success', 'Registro listo');
        if (payload.redirect) {
          window.location.href = payload.redirect;
          return;
        }
        window.location.reload();
      } catch (error) {
        await showDialog(error.message || 'No se pudo guardar la encomienda manual.', 'danger', 'Operacion no completada');
      }
    });
  }
})();
