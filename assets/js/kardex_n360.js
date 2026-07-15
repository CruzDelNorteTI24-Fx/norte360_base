(function (window, document) {
    'use strict';

    const config = window.N360_KARDEX_REPORT || {};
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

    function setButtonBusy(button, label) {
        if (!button) return () => {};

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
        if (document.getElementById('n360-kardex-toast-style')) return;

        const style = document.createElement('style');
        style.id = 'n360-kardex-toast-style';
        style.textContent = `
            .n360-kardex-toast {
                position: fixed;
                right: 22px;
                bottom: 22px;
                z-index: 30000;
                max-width: min(380px, calc(100vw - 32px));
                padding: 13px 16px;
                border-radius: 12px;
                color: #fff;
                background: #0f172a;
                box-shadow: 0 18px 38px rgba(15, 23, 42, .28);
                font: 800 13px/1.35 "Segoe UI", sans-serif;
            }
            .n360-kardex-toast[data-type="error"] { background: #b91c1c; }
            .n360-kardex-toast[data-type="success"] { background: #047857; }
            .n360-kardex-toast[hidden] { display: none; }
        `;
        document.head.appendChild(style);
    }

    function toast(message, type) {
        ensureToastStyles();
        let box = $('.n360-kardex-toast');

        if (!box) {
            box = document.createElement('div');
            box.className = 'n360-kardex-toast';
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

    function visibleRows() {
        return $$('[data-kardex-row]').filter(row => !row.hidden);
    }

    function updateVisibleCount() {
        const movementCount = visibleRows().filter(row => row.dataset.rowType === 'movement').length;
        const products = new Set(
            visibleRows()
                .filter(row => row.dataset.rowType === 'initial')
                .map(row => row.cells[4] ? row.cells[4].textContent.trim() : '')
                .filter(Boolean)
        );

        const countTarget = $('[data-kardex-visible-count]');
        const productsTarget = $('[data-kardex-visible-products]');
        if (countTarget) countTarget.textContent = String(movementCount);
        if (productsTarget) productsTarget.textContent = String(products.size);
    }

    function initLiveSearch() {
        const input = $('[data-kardex-search]');
        const rows = $$('[data-kardex-row]');

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

    function initRangeToggle() {
        const toggle = $('[data-kardex-range-toggle]');
        const dates = $$('[data-kardex-date]');
        if (!toggle) return;

        const apply = () => {
            dates.forEach(input => {
                input.disabled = !toggle.checked;
                input.closest('.stock-field')?.classList.toggle('is-disabled', !toggle.checked);
            });
        };

        toggle.addEventListener('change', apply);
        apply();
    }

    function tablePayload(table) {
        const skipIndexes = [];
        const headers = $$('thead th', table).reduce((acc, th, index) => {
            if (th.hasAttribute('data-export-hidden')) {
                skipIndexes.push(index);
                return acc;
            }
            acc.push(th.textContent.trim());
            return acc;
        }, []);

        const rows = $$('tbody tr', table)
            .filter(row => !row.hidden && !row.classList.contains('kardex-empty-row'))
            .map(row => {
                return $$('td', row).reduce((acc, td, index) => {
                    if (skipIndexes.includes(index) || td.hasAttribute('data-export-hidden')) {
                        return acc;
                    }
                    acc.push(td.textContent.replace(/\s+/g, ' ').trim());
                    return acc;
                }, []);
            });

        return { headers, rows };
    }

    function numericColumns(headers) {
        const names = new Set(['Entrada', 'Salida', 'Saldo']);
        return headers.reduce((acc, header, index) => {
            if (names.has(header)) acc[index] = { halign: 'right' };
            return acc;
        }, {});
    }

    async function exportPdf(button) {
        const table = document.getElementById(button.getAttribute('data-table-id'));
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

        const restore = setButtonBusy(button, 'Generando...');

        try {
            const reportTitle = table.dataset.reportTitle || 'Kardex general';
            const reportSubtitle = table.dataset.reportSubtitle || config.period || '';
            const doc = await window.N360PDF.createDocument({
                ...(config.pdf || {}),
                useCover: false,
                title: (config.pdf && config.pdf.title) || 'KARDEX GENERAL',
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
                            fontSize: 5.7,
                            cellPadding: 1.15,
                            lineColor: [214, 226, 239],
                            lineWidth: .1,
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
                            const tipo = String(data.row.raw[2] || '').toUpperCase();
                            if (tipo.includes('INVENTARIO INICIAL')) {
                                data.cell.styles.fillColor = [239, 246, 255];
                                data.cell.styles.fontStyle = 'bold';
                            }
                            if (tipo === 'SALDO') {
                                data.cell.styles.fillColor = [226, 232, 240];
                                data.cell.styles.fontStyle = 'bold';
                            }
                        }
                    });
                }
            });

            doc.save((config.pdf && config.pdf.fileName) || 'kardex.pdf');
        } catch (error) {
            console.error(error);
            toast(error.message || 'No se pudo generar el PDF.', 'error');
        } finally {
            restore();
        }
    }

    function exportExcel(button) {
        const table = document.getElementById(button.getAttribute('data-table-id'));
        if (!table) {
            toast('No se encontro la tabla para exportar.', 'error');
            return;
        }

        if (!window.XLSX) {
            toast('El modulo Excel no esta cargado.', 'error');
            return;
        }

        const payload = tablePayload(table);
        if (!payload.rows.length) {
            toast('No hay filas visibles para exportar.', 'error');
            return;
        }

        const restore = setButtonBusy(button, 'Generando...');

        try {
            const now = new Date().toLocaleString('es-PE');
            const aoa = [
                ['KARDEX GENERAL'],
                [config.period || ''],
                [`Usuario: ${config.userName || ''} | DNI: ${config.dni || ''}`],
                [`Fecha de impresion: ${now}`],
                [],
                payload.headers,
                ...payload.rows
            ];
            const ws = window.XLSX.utils.aoa_to_sheet(aoa);
            ws['!cols'] = payload.headers.map(header => ({
                wch: Math.min(Math.max(String(header).length + 6, 12), header === 'Producto' ? 44 : 28)
            }));

            const wb = window.XLSX.utils.book_new();
            window.XLSX.utils.book_append_sheet(wb, ws, 'Kardex');
            window.XLSX.writeFile(wb, config.excelFileName || 'kardex.xlsx');
        } catch (error) {
            console.error(error);
            toast(error.message || 'No se pudo generar el Excel.', 'error');
        } finally {
            restore();
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initLiveSearch();
        initRangeToggle();

        document.addEventListener('click', event => {
            const pdf = event.target.closest('[data-kardex-export-pdf]');
            if (pdf) {
                event.preventDefault();
                exportPdf(pdf);
                return;
            }

            const excel = event.target.closest('[data-kardex-export-excel]');
            if (excel) {
                event.preventDefault();
                exportExcel(excel);
            }
        });
    });
})(window, document);
