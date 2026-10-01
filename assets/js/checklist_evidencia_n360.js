(function () {
  const maxBytes = 8 * 1024 * 1024;
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

  document.querySelectorAll('[data-n360-evidence-picker]').forEach(function (picker) {
    const input = picker.querySelector('input[type="file"]');
    const preview = picker.querySelector('[data-n360-evidence-preview]');
    const previewName = picker.querySelector('[data-n360-evidence-name]');
    const previewMeta = picker.querySelector('[data-n360-evidence-meta]');

    if (!input) return;

    input.addEventListener('change', function () {
      input.setCustomValidity('');
      const file = input.files && input.files[0];
      if (!file) {
        if (preview) preview.classList.remove('is-visible');
        return;
      }

      if (!allowedTypes.includes(file.type)) {
        input.setCustomValidity('Selecciona una imagen JPG, PNG, WEBP o un archivo PDF.');
      } else if (file.size > maxBytes) {
        input.setCustomValidity('La evidencia no puede superar los 8 MB.');
      }

      if (previewName) previewName.textContent = file.name;
      if (previewMeta) {
        const size = file.size >= 1048576
          ? (file.size / 1048576).toFixed(1) + ' MB'
          : Math.max(1, Math.round(file.size / 1024)) + ' KB';
        previewMeta.textContent = (file.type === 'application/pdf' ? 'Documento PDF' : 'Imagen') + ' - ' + size;
      }
      if (preview) preview.classList.add('is-visible');

      if (!input.checkValidity()) input.reportValidity();
    });
  });
})();

