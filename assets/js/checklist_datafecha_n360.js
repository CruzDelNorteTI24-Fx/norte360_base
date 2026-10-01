(function () {
  const modal = document.getElementById('cdfDateModal');
  const form = document.getElementById('cdfDateForm');

  if (!modal || !form) return;

  const idInput = form.querySelector('[name="checklist_id"]');
  const dateInput = form.querySelector('[name="nueva_fecha"]');
  const reference = modal.querySelector('[data-cdf-modal-reference]');
  const currentDate = modal.querySelector('[data-cdf-modal-current]');
  const submit = form.querySelector('[data-cdf-submit]');

  modal.addEventListener('show.bs.modal', function (event) {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    if (idInput) idInput.value = trigger.dataset.cdfId || '';
    if (dateInput) dateInput.value = trigger.dataset.cdfDate || '';
    if (reference) reference.textContent = trigger.dataset.cdfReference || '';
    if (currentDate) currentDate.textContent = trigger.dataset.cdfDateDisplay || '-';
    if (submit) {
      submit.disabled = false;
      submit.classList.remove('is-loading');
    }
  });

  modal.addEventListener('shown.bs.modal', function () {
    if (dateInput) dateInput.focus();
  });

  form.addEventListener('submit', function () {
    if (!submit) return;
    submit.disabled = true;
    submit.classList.add('is-loading');
  });
})();

(function () {
  const modal = document.getElementById('cdfEvidenceModal');
  const form = document.getElementById('cdfEvidenceForm');

  if (!modal || !form) return;

  const idInput = form.querySelector('[name="checklist_id"]');
  const fileInput = form.querySelector('[name="evidencia_checklist"]');
  const reference = modal.querySelector('[data-cdf-evidence-reference]');
  const currentWrap = modal.querySelector('[data-cdf-evidence-current-wrap]');
  const currentName = modal.querySelector('[data-cdf-evidence-current]');
  const title = modal.querySelector('#cdfEvidenceModalTitle');
  const preview = modal.querySelector('[data-n360-evidence-preview]');
  const submit = form.querySelector('[data-cdf-evidence-submit]');

  modal.addEventListener('show.bs.modal', function (event) {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const evidenceName = trigger.dataset.cdfEvidenceName || '';
    if (idInput) idInput.value = trigger.dataset.cdfEvidenceId || '';
    if (reference) reference.textContent = trigger.dataset.cdfEvidenceReference || '';
    if (currentName) currentName.textContent = evidenceName || '-';
    if (currentWrap) currentWrap.hidden = evidenceName === '';
    if (title) title.textContent = evidenceName ? 'Reemplazar evidencia' : 'Adjuntar evidencia';
    if (fileInput) {
      fileInput.value = '';
      fileInput.setCustomValidity('');
    }
    if (preview) preview.classList.remove('is-visible');
    if (submit) {
      submit.disabled = false;
      submit.classList.remove('is-loading');
    }
  });

  modal.addEventListener('shown.bs.modal', function () {
    if (fileInput) fileInput.focus();
  });

  form.addEventListener('submit', function () {
    if (!submit || !fileInput || !fileInput.checkValidity()) return;
    submit.disabled = true;
    submit.classList.add('is-loading');
  });
})();
