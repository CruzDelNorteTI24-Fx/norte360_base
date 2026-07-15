(function () {
    const normalize = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    const report = window.N360_COMBUSTIBLE_REPORT || {};
    const rows = Array.from(document.querySelectorAll('[data-combustible-row]'));
    const searchInput = document.querySelector('[data-combustible-search]');
    const countNode = document.querySelector('[data-combustible-visible-count]');
    const pillNode = document.querySelector('[data-combustible-visible-pill]');
    const table = document.querySelector('[data-combustible-table]');

    const visibleRows = () => rows.filter((row) => row.style.display !== 'none');
    const clean = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const limit = (value, size) => {
        const text = clean(value);
        return text.length > size ? `${text.slice(0, size - 1)}...` : text;
    };

    const updateCount = () => {
        const count = visibleRows().length;
        const formatted = new Intl.NumberFormat('es-PE').format(count);
        if (countNode) countNode.textContent = formatted;
        if (pillNode) pillNode.textContent = `${formatted} registros`;
    };

    const applySearch = () => {
        const needle = normalize(searchInput ? searchInput.value : '');
        rows.forEach((row) => {
            const blob = normalize(row.dataset.search || row.textContent || '');
            row.style.display = !needle || blob.includes(needle) ? '' : 'none';
        });
        updateCount();
    };

    const getTablePayload = () => {
        if (!table) return { head: [], body: [] };
        const head = Array.from(table.querySelectorAll('thead th')).map((th) => clean(th.textContent));
        const body = visibleRows().map((row) => Array.from(row.children).map((td) => clean(td.textContent)));
        return { head, body };
    };

    const getPdfRows = (body) => body.map((row) => row.map((cell, index) => {
        if (index === 4) return limit(cell, 86);
        if (index === 5) return limit(cell, 110);
        if (index === 11) return limit(cell, 160);
        return cell || '-';
    }));

    const toast = (message, type = 'info') => {
        if (window.N360Loader && typeof window.N360Loader.toast === 'function') {
            window.N360Loader.toast(message, type);
            return;
        }
        console[type === 'danger' ? 'error' : 'log'](message);
    };

    const drawFilters = (doc, left, top, width, filters) => {
        doc.setFillColor(245, 248, 251);
        doc.setDrawColor(214, 226, 239);
        doc.roundedRect(left, top, width, 18, 2, 2, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.2);
        doc.setTextColor(18, 42, 64);
        doc.text('Filtros aplicados', left + 4, top + 5.8);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.1);
        doc.setTextColor(71, 85, 105);
        const lines = doc.splitTextToSize(filters || '-', width - 8);
        doc.text(lines.slice(0, 2), left + 4, top + 10.5);
    };

    const exportPdf = async () => {
        const payload = getTablePayload();
        if (!payload.body.length) {
            toast('No hay registros visibles para exportar.', 'warning');
            return;
        }

        if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
            toast('No se pudo cargar el generador PDF.', 'danger');
            return;
        }

        try {
            const filters = Array.isArray(report.filters) ? report.filters.filter(Boolean).join(' | ') : '';
            const doc = await window.N360PDF.createDocument({
                orientation: 'landscape',
                title: report.title || 'HISTORIAL DE COMBUSTIBLE',
                secondTitle: report.subtitle || 'Movimientos operativos',
                description: 'Reporte de movimientos de combustible con columnas equivalentes al sistema de escritorio.',
                docCode: report.docCode || 'COM_RPT_HIST_COMB',
                userName: report.generatedBy || '',
                dni: report.dni || '',
                logoLeft: report.logoLeft,
                logoRight: report.logoRight,
                useCover: false,
                content: function (doc) {
                    const left = 12.7;
                    const right = 12.7;
                    const width = doc.internal.pageSize.getWidth() - left - right;
                    const y = 34;

                    drawFilters(doc, left, y, width, `Periodo: ${report.period || '-'} | ${filters || '-'}`);

                    doc.autoTable({
                        head: [payload.head],
                        body: getPdfRows(payload.body),
                        startY: y + 25,
                        margin: { left, right, top: 32, bottom: 22 },
                        tableWidth: 'wrap',
                        rowPageBreak: 'avoid',
                        styles: {
                            fontSize: 6.25,
                            cellPadding: 1.05,
                            overflow: 'linebreak',
                            valign: 'middle',
                            lineColor: [0, 0, 0],
                            lineWidth: 0.08
                        },
                        headStyles: {
                            fillColor: [44, 62, 80],
                            textColor: 255,
                            fontStyle: 'bold',
                            halign: 'center'
                        },
                        bodyStyles: {
                            textColor: [17, 24, 39]
                        },
                        alternateRowStyles: { fillColor: [249, 251, 253] },
                        columnStyles: {
                            0: { cellWidth: 12 },
                            1: { cellWidth: 27 },
                            2: { cellWidth: 17 },
                            3: { cellWidth: 22 },
                            4: { cellWidth: 35 },
                            5: { cellWidth: 42 },
                            6: { cellWidth: 10 },
                            7: { cellWidth: 24 },
                            8: { cellWidth: 17, halign: 'right' },
                            9: { cellWidth: 16, halign: 'right' },
                            10: { cellWidth: 19, halign: 'right' },
                            11: { cellWidth: 30 }
                        },
                        didParseCell: function (data) {
                            if (data.section !== 'body') return;
                            if ([8, 9, 10].includes(data.column.index)) data.cell.styles.halign = 'right';
                            if (data.column.index !== 2) return;
                            const value = String(data.cell.raw || '').toLowerCase();
                            if (value.includes('entrada')) data.cell.styles.textColor = [8, 118, 79];
                            if (value.includes('salida')) data.cell.styles.textColor = [184, 50, 39];
                            if (value.includes('inventariado')) data.cell.styles.textColor = [17, 108, 164];
                            data.cell.styles.fontStyle = 'bold';
                        }
                    });
                }
            });

            doc.save(`${report.fileBase || 'Movimientos_Combustible'}.pdf`);
        } catch (error) {
            console.error(error);
            toast('No se pudo generar el PDF.', 'danger');
        }
    };

    const exportExcel = () => {
        const payload = getTablePayload();
        if (!payload.body.length) {
            toast('No hay registros visibles para exportar.', 'warning');
            return;
        }

        if (!window.XLSX) {
            toast('No se pudo cargar el exportador Excel.', 'danger');
            return;
        }

        const sheetRows = [
            [report.title || 'HISTORIAL DE COMBUSTIBLE'],
            [`Periodo: ${report.period || '-'}`],
            Array.isArray(report.filters) ? [`Filtros: ${report.filters.filter(Boolean).join(' | ')}`] : ['Filtros: -'],
            [],
            payload.head,
            ...payload.body
        ];
        const ws = window.XLSX.utils.aoa_to_sheet(sheetRows);
        ws['!cols'] = payload.head.map((head) => ({ wch: Math.max(12, Math.min(32, head.length + 8)) }));
        const wb = window.XLSX.utils.book_new();
        window.XLSX.utils.book_append_sheet(wb, ws, 'Historial combustible');
        window.XLSX.writeFile(wb, `${report.fileBase || 'Movimientos_Combustible'}.xlsx`);
    };

    if (searchInput) {
        searchInput.addEventListener('input', applySearch);
        applySearch();
    } else {
        updateCount();
    }

    const formatPrice4 = (value) => {
        const number = Number(value);
        if (!Number.isFinite(number)) return '-';
        return `S/ ${number.toLocaleString('en-US', {
            minimumFractionDigits: 4,
            maximumFractionDigits: 4
        })}`;
    };

    const updatePriceModal = (modal) => {
        const filter = modal.querySelector('[data-comb-modal-filter]');
        const selected = filter ? filter.value : '__ALL__';
        const rowsModal = Array.from(modal.querySelectorAll('[data-comb-price-row]'));
        const visible = [];

        rowsModal.forEach((row) => {
            const ok = selected === '__ALL__' || row.dataset.product === selected;
            row.style.display = ok ? '' : 'none';
            if (ok) visible.push(row);
        });

        const values = visible
            .map((row) => Number(row.dataset.metricValue))
            .filter((value) => Number.isFinite(value));

        const count = visible.length;
        const min = values.length ? Math.min(...values) : null;
        const max = values.length ? Math.max(...values) : null;
        const avg = values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;
        const last = values.length ? values[0] : null;

        const setKpi = (key, value) => {
            const node = modal.querySelector(`[data-modal-kpi="${key}"]`);
            if (!node) return;
            node.textContent = key === 'count'
                ? new Intl.NumberFormat('es-PE').format(value || 0)
                : formatPrice4(value);
        };

        setKpi('count', count);
        setKpi('min', min);
        setKpi('max', max);
        setKpi('avg', avg);
        setKpi('last', last);
    };

    const modalTablePayload = (modal) => {
        const tableModal = modal.querySelector('.comb-modal-table');
        if (!tableModal) return { head: [], body: [] };
        const head = Array.from(tableModal.querySelectorAll('thead th')).map((th) => clean(th.textContent));
        const body = Array.from(tableModal.querySelectorAll('[data-comb-price-row]'))
            .filter((row) => row.style.display !== 'none')
            .map((row) => Array.from(row.children).map((td) => clean(td.textContent)));
        return { head, body };
    };

    const exportModalPdf = async (button) => {
        const modal = document.getElementById(button.dataset.modalId || '');
        if (!modal) return;

        const payload = modalTablePayload(modal);
        if (!payload.body.length) {
            toast('No hay registros visibles para exportar.', 'warning');
            return;
        }

        if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
            toast('No se pudo cargar el generador PDF.', 'danger');
            return;
        }

        const title = button.dataset.reportTitle || 'HISTORIAL DE COMBUSTIBLE';
        const docCode = button.dataset.docCode || 'COMB_HIST_AUX';
        const filter = modal.querySelector('[data-comb-modal-filter]');
        const filterText = filter && filter.value !== '__ALL__' ? filter.value : 'Todos los productos';
        const landscape = payload.head.length > 6;

        try {
            const doc = await window.N360PDF.createDocument({
                orientation: landscape ? 'landscape' : 'portrait',
                title,
                secondTitle: 'Combustible',
                description: 'Consulta auxiliar de combustible generada desde el historial operativo.',
                docCode,
                userName: report.generatedBy || '',
                dni: report.dni || '',
                logoLeft: report.logoLeft,
                logoRight: report.logoRight,
                useCover: false,
                content: function (doc) {
                    const left = 12.7;
                    const right = 12.7;
                    const width = doc.internal.pageSize.getWidth() - left - right;
                    const y = 34;

                    drawFilters(doc, left, y, width, `Filtro de producto: ${filterText}`);

                    doc.autoTable({
                        head: [payload.head],
                        body: payload.body,
                        startY: y + 24,
                        margin: { left, right, top: 32, bottom: 22 },
                        styles: {
                            fontSize: landscape ? 6.2 : 7.4,
                            cellPadding: 1.3,
                            overflow: 'linebreak',
                            valign: 'middle',
                            lineColor: [214, 226, 239],
                            lineWidth: 0.12
                        },
                        headStyles: {
                            fillColor: [18, 42, 64],
                            textColor: 255,
                            fontStyle: 'bold',
                            halign: 'center'
                        },
                        alternateRowStyles: { fillColor: [249, 251, 253] },
                        didParseCell: function (data) {
                            if (data.section !== 'body') return;
                            if (data.column.index >= payload.head.length - 3) {
                                data.cell.styles.halign = 'right';
                            }
                        }
                    });
                }
            });

            doc.save(`${docCode}_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.pdf`);
        } catch (error) {
            console.error(error);
            toast('No se pudo generar el PDF del modal.', 'danger');
        }
    };

    let activePriceModal = null;

    const removeModalBackdrop = () => {
        document.querySelectorAll('.comb-js-backdrop').forEach((node) => node.remove());
        if (!document.querySelector('.comb-price-modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    };

    const closePriceModal = (modal = activePriceModal) => {
        if (!modal) return;
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        modal.removeAttribute('role');
        if (activePriceModal === modal) activePriceModal = null;
        removeModalBackdrop();
    };

    const openPriceModal = (modal) => {
        if (!modal) return;
        if (activePriceModal && activePriceModal !== modal) closePriceModal(activePriceModal);
        activePriceModal = modal;
        updatePriceModal(modal);
        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('role', 'dialog');
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
        if (!document.querySelector('.comb-js-backdrop')) {
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show comb-js-backdrop';
            document.body.appendChild(backdrop);
        }
        modal.classList.add('show');
        const focusTarget = modal.querySelector('[data-comb-modal-filter], button, [href], input, select, textarea');
        if (focusTarget) focusTarget.focus({ preventScroll: true });
    };

    const initPriceModals = () => {
        document.querySelectorAll('.comb-price-modal').forEach((modal) => {
            modal.querySelector('[data-comb-modal-filter]')?.addEventListener('change', () => updatePriceModal(modal));
            modal.querySelectorAll('[data-comb-modal-close]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    closePriceModal(modal);
                });
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closePriceModal(modal);
            });
            updatePriceModal(modal);
        });

        document.querySelectorAll('[data-comb-open-modal]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                openPriceModal(document.getElementById(button.dataset.combOpenModal || ''));
            });
        });

        document.querySelectorAll('[data-comb-modal-export-pdf]').forEach((button) => {
            button.addEventListener('click', () => exportModalPdf(button));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && activePriceModal) closePriceModal(activePriceModal);
        });
    };

    document.querySelector('[data-combustible-export-pdf]')?.addEventListener('click', exportPdf);
    document.querySelector('[data-combustible-export-excel]')?.addEventListener('click', exportExcel);
    initPriceModals();
})();