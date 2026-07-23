(function () {
  const cfg = window.N360_CSB || {};
  const endpoint = cfg.endpoint || 'consolidado_salidas_buses.php';
  const csrf = cfg.csrf || '';

  function showNotice(message, ok) {
    let box = document.querySelector('[data-csb-notice]');
    if (!box) {
      box = document.createElement('div');
      box.dataset.csbNotice = '1';
      box.className = 'csb-notice';
      document.body.appendChild(box);
    }
    box.textContent = message;
    box.classList.toggle('csb-notice--ok', !!ok);
    box.classList.toggle('csb-notice--bad', !ok);
    box.classList.add('is-visible');
    window.clearTimeout(box._csbTimer);
    box._csbTimer = window.setTimeout(() => box.classList.remove('is-visible'), 2800);
  }

  async function saveRow(button) {
    const row = button.closest('[data-csb-row]');
    if (!row) return;

    const id = button.dataset.csbSave || row.dataset.csbRow || '';
    const estado = row.querySelector('[data-csb-field="estado"]')?.value || 'PENDIENTE';
    const comentario = row.querySelector('[data-csb-field="comentario"]')?.value || '';
    const correccion = row.querySelector('[data-csb-field="correccion"]')?.value || '';
    const originalHtml = button.innerHTML;

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'update_revision');
    fd.append('id', id);
    fd.append('estado', estado);
    fd.append('comentario', comentario);
    fd.append('correccion', correccion);

    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando';

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.ok) {
        throw new Error(json.message || 'No se pudo guardar.');
      }

      const status = row.querySelector('[data-csb-status]');
      if (status) {
        status.textContent = json.data?.estado || estado;
        status.className = 'csb-status ' + (json.data?.clase || 'csb-status--pending');
      }

      const saved = row.querySelector('[data-csb-saved]');
      if (saved) {
        saved.textContent = json.data?.actualizado || '';
      }
      showNotice(json.message || 'Cambios guardados.', true);
    } catch (err) {
      showNotice(err.message || 'No se pudo guardar.', false);
    } finally {
      button.disabled = false;
      button.innerHTML = originalHtml;
    }
  }

  document.querySelectorAll('[data-csb-save]').forEach((button) => {
    button.addEventListener('click', () => saveRow(button));
  });
})();
