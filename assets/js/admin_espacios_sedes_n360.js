(function () {
  'use strict';
  const dialog = document.getElementById('sedeespCreateDialog');
  const form = document.getElementById('sedeespCreateForm');
  if (!dialog || !form) return;

  const sede = document.getElementById('sedeespCreateSede');
  const area = document.getElementById('sedeespCreateArea');
  const espacio = document.getElementById('sedeespCreateEspacio');
  const pabellon = document.getElementById('sedeespCreatePabellon');
  const preview = document.getElementById('sedeespCreatePreview');
  const suggestions = document.getElementById('sedeespAreas');
  const submit = document.getElementById('sedeespCreateSubmit');
  const options = Array.from(suggestions.options).map(option => option.cloneNode(true));
  let saving = false;
  let opener = null;
  let backdropStart = false;
  const clean = value => String(value).trim().replace(/\s+/g, ' ');

  function updatePreview() {
    const sedeName = sede.value ? sede.selectedOptions[0].textContent.trim() : '';
    const place = [clean(pabellon?.value || ''), clean(area.value), clean(espacio.value)].filter(Boolean).join(' / ');
    preview.textContent = sedeName ? sedeName + (place ? '\n' + place : '\nCompleta el nombre del área.') : 'Selecciona una sede y completa el área.';
  }

  function updateSuggestions() {
    const seen = new Set();
    suggestions.replaceChildren(...options.filter(option => {
      if (option.dataset.sede !== sede.value || seen.has(option.value)) return false;
      seen.add(option.value);
      return true;
    }).map(option => option.cloneNode(true)));
  }

  function open(button) {
    if (dialog.open) return;
    opener = button || document.activeElement;
    const filter = document.getElementById('sedeespFilterSede');
    if (!sede.value && Number(filter?.value) > 0) sede.value = filter.value;
    updateSuggestions();
    updatePreview();
    dialog.showModal();
    document.body.classList.add('sedeesp-dialog-open');
    (sede.value ? area : sede).focus();
  }

  function close() {
    if (!saving) dialog.close();
  }

  document.querySelectorAll('[data-sedeesp-open]').forEach(button => {
    button.addEventListener('click', () => open(button));
  });
  dialog.querySelectorAll('[data-sedeesp-close]').forEach(button => button.addEventListener('click', close));
  dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('sedeesp-dialog-open');
    if (opener?.isConnected) opener.focus({preventScroll: true});
  });
  const outside = event => {
    const rect = dialog.getBoundingClientRect();
    return event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
  };
  dialog.addEventListener('pointerdown', event => { backdropStart = event.target === dialog && outside(event); });
  dialog.addEventListener('click', event => {
    if (backdropStart && event.target === dialog && outside(event)) close();
    backdropStart = false;
  });
  sede.addEventListener('change', updateSuggestions);
  [sede, area, espacio, pabellon].filter(Boolean).forEach(field => field.addEventListener('input', () => {
    const error = document.getElementById('sedeespCreateError');
    if (error) error.hidden = true;
    updatePreview();
  }));
  sede.addEventListener('change', updatePreview);
  form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    saving = true;
    submit.disabled = true;
    submit.classList.add('is-saving');
    submit.querySelector('i').className = 'bi bi-arrow-repeat';
    submit.querySelector('span').textContent = 'Guardando...';
    dialog.querySelectorAll('[data-sedeesp-close]').forEach(button => { button.disabled = true; });
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    saving = false;
    submit.disabled = false;
    submit.classList.remove('is-saving');
    submit.querySelector('i').className = 'bi bi-check2';
    submit.querySelector('span').textContent = 'Guardar espacio';
    dialog.querySelectorAll('[data-sedeesp-close]').forEach(button => { button.disabled = false; });
  });
  if (dialog.dataset.open === '1') open();
})();

(function () {
  'use strict';
  const dialog = document.getElementById('sedeespClassifyDialog');
  const form = document.getElementById('sedeespClassifyForm');
  if (!dialog || !form) return;
  const id = document.getElementById('sedeespClassifyId');
  const expected = document.getElementById('sedeespClassifyExpected');
  const location = document.getElementById('sedeespClassifyLocation');
  const select = document.getElementById('sedeespClassifySelect');
  const submit = form.querySelector('[type=submit]');
  let saving = false;
  let opener = null;
  let backdropStart = false;
  function open(button) {
    if (dialog.open) return;
    opener = button || document.activeElement;
    if (button) {
      id.value = button.dataset.id;
      expected.value = button.dataset.classification;
      select.value = Number(button.dataset.classification) > 0 ? button.dataset.classification : '';
      location.textContent = button.dataset.location;
      const error = form.querySelector('[role=alert]');
      if (error) error.hidden = true;
    }
    dialog.showModal();
    document.body.classList.add('sedeesp-dialog-open');
    select.focus();
  }
  function close() { if (!saving) dialog.close(); }
  document.querySelectorAll('[data-sedeesp-classify]').forEach(button => {
    button.addEventListener('click', () => open(button));
  });
  dialog.querySelectorAll('[data-classify-close]').forEach(button => button.addEventListener('click', close));
  dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('sedeesp-dialog-open');
    if (opener?.isConnected) opener.focus({preventScroll: true});
  });
  const outside = event => {
    const rect = dialog.getBoundingClientRect();
    return event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
  };
  dialog.addEventListener('pointerdown', event => { backdropStart = event.target === dialog && outside(event); });
  dialog.addEventListener('click', event => {
    if (backdropStart && event.target === dialog && outside(event)) close();
    backdropStart = false;
  });
  form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    saving = true;
    submit.disabled = true;
    submit.textContent = 'Guardando...';
    dialog.querySelectorAll('[data-classify-close]').forEach(button => { button.disabled = true; });
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    saving = false;
    submit.disabled = false;
    submit.textContent = 'Guardar clasificación';
    dialog.querySelectorAll('[data-classify-close]').forEach(button => { button.disabled = false; });
  });
  if (dialog.dataset.open === '1') open();
})();
(function () {
  'use strict';
  const dialog = document.getElementById('sedeespPavilionDialog');
  if (!dialog) return;
  const form = dialog.querySelector('form');
  let opener, saving = false;
  document.querySelectorAll('[data-sedeesp-pavilion]').forEach(button => button.addEventListener('click', () => {
    opener = button;
    form.elements.id.value = button.dataset.id;
    form.elements.pabellon.value = '';
    dialog.querySelector('[data-pavilion-location]').textContent = button.dataset.location;
    dialog.showModal();
    document.body.classList.add('sedeesp-dialog-open');
    form.elements.pabellon.focus();
  }));
  dialog.querySelectorAll('[data-pavilion-close]').forEach(button => button.addEventListener('click', () => { if (!saving) dialog.close(); }));
  dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
  dialog.addEventListener('close', () => { document.body.classList.remove('sedeesp-dialog-open'); if (opener?.isConnected) opener.focus(); });
  form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    saving = true;
    form.querySelector('[type=submit]').disabled = true;
  });
  window.addEventListener('pageshow', event => { if (event.persisted) { saving = false; form.querySelector('[type=submit]').disabled = false; } });
})();
