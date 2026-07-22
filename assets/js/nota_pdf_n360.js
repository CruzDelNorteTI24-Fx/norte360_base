(function (window, document) {
    'use strict';

    const config = window.N360_NOTA_PDF_CONFIG || {};
    const DEFAULT_ENDPOINT = '../php/nota_pdf_data.php';

    function endpointUrl(params) {
        const url = new URL(config.endpoint || DEFAULT_ENDPOINT, window.location.href);
        Object.entries(params || {}).forEach(([key, value]) => {
            if (value !== undefined && value !== null && String(value) !== '') {
                url.searchParams.set(key, value);
            }
        });
        return url;
    }

    function generatorFor(series) {
        const key = String(series || '').trim().toUpperCase();
        const generators = {
            NS: window.N360NotaSalidaAlmacen,
            NE: window.N360NotaEntradaAlmacen,
            RE: window.N360NotaEntradaBienes,
            RS: window.N360NotaSalidaBienes,
            CE: window.N360NotaEntradaBienes,
            CS: window.N360NotaSalidaBienes,
            CM: window.N360NotaTanqueada,
            AB: window.N360NotaAbastecimiento
        };

        return generators[key] || null;
    }

    async function fetchNote(params) {
        const response = await fetch(endpointUrl(params), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        });
        const text = await response.text();
        let payload = null;

        try {
            payload = JSON.parse(text);
        } catch (error) {
            throw new Error('El servidor no devolvio un JSON valido para la nota.');
        }

        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'No se pudo preparar el PDF de la nota.');
        }

        return payload;
    }

    function toast(message, type) {
        if (!document.querySelector('#n360-note-pdf-style')) {
            const style = document.createElement('style');
            style.id = 'n360-note-pdf-style';
            style.textContent = `
                .n360-note-pdf-toast{
                    position:fixed;
                    right:22px;
                    bottom:24px;
                    z-index:1000020;
                    max-width:min(360px,calc(100vw - 32px));
                    padding:13px 16px;
                    border-radius:14px;
                    color:#fff;
                    background:#123047;
                    box-shadow:0 18px 40px rgba(15,23,42,.24);
                    font-family:'Segoe UI',system-ui,sans-serif;
                    font-size:.92rem;
                    font-weight:750;
                }
                .n360-note-pdf-toast[data-type="error"]{background:#991b1b;}
                .n360-note-pdf-toast[hidden]{display:none!important;}
            `;
            document.head.appendChild(style);
        }

        let box = document.querySelector('.n360-note-pdf-toast');

        if (!box) {
            box = document.createElement('div');
            box.className = 'n360-note-pdf-toast';
            box.setAttribute('role', 'status');
            document.body.appendChild(box);
        }

        box.textContent = message;
        box.dataset.type = type || 'info';
        box.hidden = false;
        window.clearTimeout(box._timer);
        box._timer = window.setTimeout(() => {
            box.hidden = true;
        }, 4200);
    }

    function setBusy(button, busy) {
        if (!button) return () => {};

        if (busy) {
            const previous = {
                html: button.innerHTML,
                disabled: button.disabled
            };
            button.dataset.n360PdfBusy = '1';
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Generando...';
            return () => {
                button.innerHTML = previous.html;
                button.disabled = previous.disabled;
                delete button.dataset.n360PdfBusy;
            };
        }

        return () => {};
    }

    async function generate(params, button) {
        const restore = setBusy(button, true);

        try {
            const payload = await fetchNote(params);
            const noteData = payload.noteData || {};
            const series = payload.series || noteData.series;
            const generator = generatorFor(series);

            if (!generator || typeof generator.generate !== 'function') {
                throw new Error('No hay formato PDF configurado para la serie ' + (series || '-'));
            }

            await generator.generate({
                ...config,
                noteData
            });
        } catch (error) {
            console.error(error);
            toast(error.message || 'No se pudo generar el PDF.', 'error');
        } finally {
            restore();
        }
    }

    function downloadByNotaId(idNota, button) {
        return generate({ id_nota: idNota }, button || null);
    }

    function downloadByMovimientoId(idMovimiento, button) {
        return generate({ id_movimiento: idMovimiento }, button || null);
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-n360-note-download]');

        if (!button || button.dataset.n360PdfBusy === '1') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const idNota = button.getAttribute('data-note-id');
        const idMovimiento = button.getAttribute('data-movement-id');

        if (idNota) {
            downloadByNotaId(idNota, button);
            return;
        }

        if (idMovimiento) {
            downloadByMovimientoId(idMovimiento, button);
            return;
        }

        toast('No se encontro el identificador de la nota.', 'error');
    }, true);

    window.N360NotaPDF = {
        downloadByNotaId,
        downloadByMovimientoId,
        generate
    };
})(window, document);
