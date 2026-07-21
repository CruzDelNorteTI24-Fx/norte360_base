(function () {
    'use strict';

    const alertBox = (message, variant = 'info', title = '') => {
        if (window.N360Dialog) {
            return window.N360Dialog.alert(message, { variant, title });
        }
        window.alert(message);
        return Promise.resolve(true);
    };

    const withLoader = async (task, options = {}) => {
        if (window.N360Loader) {
            return window.N360Loader.during(task, options);
        }
        return task();
    };

    document.addEventListener('click', async (event) => {
        const actaBtn = event.target.closest('[data-acta-pdf]');
        const notaBtn = event.target.closest('[data-nota-pdf]');
        if (!actaBtn && !notaBtn) return;
        event.preventDefault();

        try {
            if (actaBtn) {
                await withLoader(
                    () => window.N360ActaUniformes.downloadByActaId(actaBtn.dataset.actaPdf),
                    { title: 'Generando acta', detail: 'Preparando PDF A4...' }
                );
            } else if (notaBtn) {
                await withLoader(
                    () => window.N360NotaPDF.downloadByNotaId(notaBtn.dataset.notaPdf),
                    { title: 'Generando nota', detail: 'Preparando formato de nota...' }
                );
            }
        } catch (error) {
            await alertBox(error.message || 'No se pudo generar el documento.', 'error', 'Documento');
        }
    });
})();