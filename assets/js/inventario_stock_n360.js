(function (window, document) {
    'use strict';

    const config = window.N360_STOCK_REPORT || {};
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setButtonBusy(button, busy, label) {
        if (!button) return () => {};

        if (!busy) return () => {};

        const previous = {
            html: button.innerHTML,
            disabled: button.disabled
        };
        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ${escapeHtml(label || 'Procesando...')}`;

        return () => {
            button.disabled = previous.disabled;
            button.innerHTML = previous.html;
        };
    }

    function ensureToastStyles() {
        if (document.getElementById('n360-stock-toast-style')) return;

        const style = document.createElement('style');
        style.id = 'n360-stock-toast-style';
        style.textContent = `
            .n360-note-pdf-toast {
                position: fixed;
                right: 22px;
                bottom: 22px;
                z-index: 30000;
                max-width: min(360px, calc(100vw - 32px));
                padding: 13px 16px;
                border-radius: 12px;
                color: #fff;
                background: #0f172a;
                box-shadow: 0 18px 38px rgba(15, 23, 42, .28);
                font: 800 13px/1.35 "Segoe UI", sans-serif;
            }

            .n360-note-pdf-toast[data-type="error"] { background: #b91c1c; }
            .n360-note-pdf-toast[data-type="success"] { background: #047857; }
            .n360-note-pdf-toast[hidden] { display: none; }
        `;
        document.head.appendChild(style);
    }

    function toast(message, type) {
        ensureToastStyles();
        let box = $('.n360-note-pdf-toast');

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

    function updateVisibleCount() {
        const target = $('[data-stock-visible-count]');
        if (!target) return;

        const visible = $$('[data-stock-row]').filter(row => !row.hidden).length;
        target.textContent = String(visible);
    }

    function initLiveFilter() {
        const input = $('[data-stock-search]');
        const rows = $$('[data-stock-row]');

        if (!input || !rows.length) return;

        const apply = () => {
            const term = input.value.trim().toLowerCase();
            rows.forEach(row => {
                const haystack = row.dataset.search || '';
                row.hidden = term !== '' && !haystack.includes(term);
            });
            updateVisibleCount();
        };

        input.addEventListener('input', apply);
        apply();
    }

    function drawerLoading(message) {
        return `
            <div class="drawer-loading">
                <div class="spinner"></div>
                <strong>${escapeHtml(message || 'Cargando informacion...')}</strong>
                <small>Un momento por favor</small>
            </div>
        `;
    }

    function verMovimientos(idProducto) {
        const modal = $('#modal-movimientos');
        const content = $('#contenido-movimientos');

        if (!modal || !content || !idProducto) return;

        content.innerHTML = drawerLoading('Cargando movimientos del producto...');
        modal.classList.add('active');
        document.body.classList.add('drawer-open');

        const endpoint = config.historyEndpoint || '../php/ver_movimientos_producto.php';
        const url = `${endpoint}${endpoint.includes('?') ? '&' : '?'}id=${encodeURIComponent(idProducto)}`;

        fetch(url, { credentials: 'same-origin' })
            .then(response => response.text())
            .then(html => {
                content.innerHTML = html;
                if (window.N360Barcode && typeof window.N360Barcode.renderAll === 'function') {
                    window.N360Barcode.renderAll(content);
                }
            })
            .catch(error => {
                console.error(error);
                content.innerHTML = `
                    <div class="drawer-loading">
                        <strong style="color:#dc2626;">No se pudo cargar el historial.</strong>
                        <small>Intenta nuevamente.</small>
                    </div>
                `;
            });
    }

    function cerrarModal() {
        const modal = $('#modal-movimientos');
        const content = $('#contenido-movimientos');

        if (!modal) return;

        modal.classList.remove('active');
        document.body.classList.remove('drawer-open');

        window.setTimeout(() => {
            if (content) content.innerHTML = 'Cargando movimientos...';
        }, 220);
    }

    function verNotaSalida(idMovimiento) {
        const modal = $('#modal-nota');
        const content = $('#contenido-nota');

        if (!modal || !content || !idMovimiento) return;

        content.innerHTML = drawerLoading('Cargando nota...');
        modal.classList.add('active');

        const endpoint = config.noteEndpoint || '../php/ver_nota_salida.php';
        const url = `${endpoint}${endpoint.includes('?') ? '&' : '?'}id=${encodeURIComponent(idMovimiento)}`;

        fetch(url, { credentials: 'same-origin' })
            .then(response => response.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(error => {
                console.error(error);
                content.innerHTML = `
                    <div class="drawer-loading">
                        <strong style="color:#dc2626;">No se pudo cargar la nota.</strong>
                        <small>Intenta nuevamente.</small>
                    </div>
                `;
            });
    }

    function cerrarNotaModal() {
        const modal = $('#modal-nota');
        const content = $('#contenido-nota');

        if (!modal) return;

        modal.classList.remove('active');
        window.setTimeout(() => {
            if (content) content.innerHTML = 'Cargando nota...';
        }, 220);
    }

    function initHistoryTriggers() {
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-product-history]');
            if (!button) return;

            event.preventDefault();
            event.stopPropagation();
            verMovimientos(button.getAttribute('data-product-history'));
        });

        document.addEventListener('dblclick', event => {
            const row = event.target.closest('[data-stock-row]');
            if (!row) return;
            verMovimientos(row.dataset.productId);
        });

        document.addEventListener('click', event => {
            const drawer = $('#modal-movimientos');
            const note = $('#modal-nota');

            if (drawer && event.target === drawer) cerrarModal();
            if (note && event.target === note) cerrarNotaModal();
        });

        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            cerrarNotaModal();
            cerrarModal();
        });
    }

    function tablePayload(table) {
        const skipIndexes = [];
        const headers = $$('thead th', table).reduce((acc, th, index) => {
            if (th.hasAttribute('data-pdf-skip')) {
                skipIndexes.push(index);
                return acc;
            }
            acc.push(th.textContent.trim());
            return acc;
        }, []);

        const rows = $$('tbody tr', table)
            .filter(row => !row.hidden && !row.classList.contains('stock-empty-row'))
            .map(row => {
                return $$('td', row).reduce((acc, td, index) => {
                    if (skipIndexes.includes(index) || td.hasAttribute('data-pdf-skip')) {
                        return acc;
                    }
                    acc.push(td.textContent.replace(/\s+/g, ' ').trim());
                    return acc;
                }, []);
            });

        return { headers, rows };
    }

    function numericColumns(headers) {
        const names = new Set(['Stock Min', 'Stock Actual', 'Diferencia', 'Saldo Inicial', 'Entradas', 'Salidas', 'Saldo Final']);
        return headers.reduce((acc, header, index) => {
            if (names.has(header)) acc[index] = { halign: 'right' };
            return acc;
        }, {});
    }

    async function exportPdf(button) {
        const tableId = button.getAttribute('data-table-id');
        const table = document.getElementById(tableId);

        if (!table) {
            toast('No se encontro la tabla para exportar.', 'error');
            return;
        }

        if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
            toast('El modulo PDF no esta cargado.', 'error');
            return;
        }

        const payload = tablePayload(table);
        if (!payload.rows.length) {
            toast('No hay filas visibles para exportar.', 'error');
            return;
        }

        const restore = setButtonBusy(button, true, 'Generando...');

        try {
            const reportTitle = table.dataset.reportTitle || config.pdf?.title || 'Reporte';
            const reportSubtitle = table.dataset.reportSubtitle || config.pdf?.secondTitle || '';

            const doc = await window.N360PDF.createDocument({
                ...(config.pdf || {}),
                useCover: false,
                title: (config.pdf && config.pdf.title) || reportTitle.toUpperCase(),
                secondTitle: reportTitle,
                description: reportSubtitle,
                content: function (pdfDoc) {
                    const width = pdfDoc.internal.pageSize.getWidth();
                    const left = 12.7;
                    let y = 34;

                    pdfDoc.setTextColor(15, 23, 42);
                    pdfDoc.setFont('helvetica', 'bold');
                    pdfDoc.setFontSize(10.5);
                    pdfDoc.text(reportTitle, left, y);

                    if (reportSubtitle) {
                        pdfDoc.setFont('helvetica', 'normal');
                        pdfDoc.setFontSize(7.8);
                        pdfDoc.setTextColor(71, 85, 105);
                        pdfDoc.text(pdfDoc.splitTextToSize(reportSubtitle, width - left * 2), left, y + 5);
                    }

                    pdfDoc.autoTable({
                        head: [payload.headers],
                        body: payload.rows,
                        startY: y + 12,
                        margin: { left, right: left, top: 31, bottom: 22 },
                        theme: 'grid',
                        styles: {
                            font: 'helvetica',
                            fontSize: payload.headers.length > 9 ? 6.1 : 7,
                            cellPadding: 1.6,
                            lineColor: [214, 226, 239],
                            lineWidth: .12,
                            valign: 'middle',
                            overflow: 'linebreak'
                        },
                        headStyles: {
                            fillColor: [23, 32, 51],
                            textColor: [255, 255, 255],
                            fontStyle: 'bold',
                            halign: 'center'
                        },
                        alternateRowStyles: {
                            fillColor: [248, 251, 255]
                        },
                        columnStyles: numericColumns(payload.headers),
                        didParseCell: function (data) {
                            if (data.section !== 'body') return;
                            const header = payload.headers[data.column.index];
                            if (header === 'Estado' && String(data.cell.raw).toUpperCase().includes('REPOSICION')) {
                                data.cell.styles.textColor = [194, 65, 12];
                                data.cell.styles.fontStyle = 'bold';
                            }
                            if (header === 'Estado' && String(data.cell.raw).toUpperCase().includes('OK')) {
                                data.cell.styles.textColor = [4, 120, 87];
                                data.cell.styles.fontStyle = 'bold';
                            }
                        }
                    });
                }
            });

            const filename = (config.pdf && config.pdf.fileName) || `${reportTitle.toLowerCase().replace(/[^a-z0-9]+/g, '_')}.pdf`;
            doc.save(filename);
        } catch (error) {
            console.error(error);
            toast(error.message || 'No se pudo generar el PDF.', 'error');
        } finally {
            restore();
        }
    }

    function initPdfButtons() {
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-stock-export-pdf]');
            if (!button) return;

            event.preventDefault();
            exportPdf(button);
        });
    }

    window.verMovimientos = verMovimientos;
    window.cerrarModal = cerrarModal;
    window.verNotaSalida = verNotaSalida;
    window.cerrarNotaModal = cerrarNotaModal;

    document.addEventListener('DOMContentLoaded', () => {
        initLiveFilter();
        initHistoryTriggers();
        initPdfButtons();
    });
})(window, document);
