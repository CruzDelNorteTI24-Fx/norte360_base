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
