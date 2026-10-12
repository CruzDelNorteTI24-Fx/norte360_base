(function (window, document) {
    'use strict';

    const config = window.N360_PEAJES_RESUMEN || {};
    const button = document.querySelector('[data-pje-summary-pdf]');
    const ownerColors = {
        'Sr. Lucho': [255, 240, 193],
        'Dr. Wilbor': [255, 255, 255],
        'Sr. Eddy': [230, 235, 246],
        'Sra. Nichi': [249, 219, 238],
        'Sr. Beto': [239, 246, 234]
    };
    const ownerGroups = {
        'Sr. Eddy': ['Sr. Eddy'],
        'Hermanos': ['Sr. Lucho', 'Sr. Beto', 'Dr. Wilbor', 'Sra. Nichi']
    };

    function money(value) {
        return 'S/ ' + Number(value || 0).toLocaleString('es-PE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function num(value, decimals) {
        return Number(value || 0).toLocaleString('es-PE', {
            minimumFractionDigits: decimals || 0,
            maximumFractionDigits: decimals || 0
        });
    }

    function clean(value, fallback) {
        const text = String(value == null ? '' : value).trim();
        return text || fallback || '-';
    }

    function vehicle(row) {
        const bus = String(row.bus == null ? '' : row.bus).trim();
        const placa = String(row.placa == null ? '' : row.placa).trim();
        if (bus && placa) return `${bus} (${placa})`;
        return bus || placa || '-';
    }

    function setBusy(button, isBusy) {
        if (!button) return;
        if (isBusy) {
            button.dataset.originalHtml = button.innerHTML;
            button.dataset.originalWidth = button.style.width;
            button.style.width = `${button.offsetWidth}px`;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Generando';
            return;
        }
        button.disabled = false;
        if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
        button.style.width = button.dataset.originalWidth || '';
    }

    function notify(type, message) {
        if (window.N360Loader && typeof window.N360Loader.toast === 'function') {
            window.N360Loader.toast(type, message);
            return;
        }
        alert(message);
    }

    function aggregateByOwner(rows) {
        const map = new Map();
        rows.forEach(row => {
            const key = clean(row.dueno, 'No identificado');
            if (!map.has(key)) {
                map.set(key, {dueno: key, placas: 0, registros: 0, facturas: 0, total: 0, detraccion: 0});
            }
            const item = map.get(key);
            item.placas += 1;
            item.registros += Number(row.registros || 0);
            item.facturas += Number(row.facturas || 0);
            item.total += Number(row.total || 0);
            item.detraccion += Number(row.detraccion || 0);
        });
        return Array.from(map.values()).sort((a, b) => b.total - a.total);
    }

    function aggregateByGroup(rows) {
        return Object.entries(ownerGroups).map(([groupName, owners]) => {
            const filtered = rows.filter(row => owners.includes(clean(row.dueno, '')));
            return filtered.reduce((acc, row) => {
                acc.placas += 1;
                acc.registros += Number(row.registros || 0);
                acc.facturas += Number(row.facturas || 0);
                acc.total += Number(row.total || 0);
                acc.detraccion += Number(row.detraccion || 0);
                return acc;
            }, {grupo: groupName, placas: 0, registros: 0, facturas: 0, total: 0, detraccion: 0});
        }).filter(row => row.placas > 0);
    }

    function nonMatchingRows(rows) {
        return rows.filter(row => Number(row.placa_id || 0) <= 0);
    }

    function infoText(config) {
        const filters = config.filters || {};
        const pieces = [
            `Periodo: ${clean(filters.desde)} al ${clean(filters.hasta)}`
        ];
        [['estacion', 'Estacion'], ['usuario', 'Usuario'], ['importacion', 'Importacion']].forEach(([key, label]) => {
            if (filters[key] && filters[key] !== 'TODOS') pieces.push(`${label}: ${filters[key]}`);
        });
        if (filters.placa) pieces.push(`Placa: ${filters.placa}`);
        if (filters.buscar) pieces.push(`Busqueda: ${filters.buscar}`);
        if (filters.factura) pieces.push(`Factura: ${filters.factura}`);
        return pieces;
    }

    function drawReportContext(doc, y, width, config) {
        const left = 12.7;
        const center = left + width / 2;
        doc.setTextColor(0, 0, 0);
        if (config.proveedor) {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            const supplierLines = doc.splitTextToSize(`${clean(config.proveedor.nombre)} | RUC: ${clean(config.proveedor.ruc)}`, width);
            doc.text(supplierLines, center, y, {align: 'center'});
            y += supplierLines.length * 3.8;
        }
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.6);
        const filterLines = doc.splitTextToSize(infoText(config).join(' | '), width);
        doc.text(filterLines, center, y, {align: 'center'});
        return y + filterLines.length * 3.5 + 3;
    }

    function totals(rows) {
        return rows.reduce((sum, row) => {
            sum.placas += Number(row.placas == null ? 1 : row.placas);
            sum.registros += Number(row.registros || 0);
            sum.total += Number(row.total || 0);
            sum.detraccion += Number(row.detraccion || 0);
            return sum;
        }, {placas: 0, registros: 0, total: 0, detraccion: 0});
    }

    function autoTable(doc, title, head, body, startY, options) {
        const left = 12.7;
        const width = doc.internal.pageSize.getWidth() - 25.4;
        const opts = options || {};
        const baseStyles = {
            font: 'helvetica',
            fontSize: 7.2,
            cellPadding: 1.2,
            textColor: [0, 0, 0],
            lineColor: [0, 0, 0],
            lineWidth: 0.18,
            overflow: 'linebreak',
            valign: 'middle'
        };
        const baseHeadStyles = {
            fillColor: [35, 35, 35],
            textColor: [255, 255, 255],
            fontStyle: 'bold',
            halign: 'center'
        };
        const tableOptions = Object.assign({
            startY: startY + 6,
            margin: {left: left, right: left, top: 34, bottom: 23},
            tableWidth: width,
            head: [head],
            body: body.length ? body : [head.map(() => '-')],
            showHead: 'everyPage',
            showFoot: 'lastPage',
            pageBreak: body.length <= 8 ? 'avoid' : 'auto',
            rowPageBreak: 'avoid',
            theme: 'grid',
            styles: baseStyles,
            headStyles: baseHeadStyles,
            footStyles: {fillColor: [235, 237, 239], textColor: [0, 0, 0], fontStyle: 'bold', lineWidth: 0.25, halign: 'right'},
            alternateRowStyles: {fillColor: [246, 246, 246]},
            willDrawPage: function (data) {
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(10);
                doc.setTextColor(0, 0, 0);
                doc.text(title + (data.pageNumber > 1 ? ' (continuacion)' : ''), left, data.cursor.y - 4);
            }
        }, opts);
        tableOptions.styles = Object.assign({}, baseStyles, opts.styles || {});
        tableOptions.headStyles = Object.assign({}, baseHeadStyles, opts.headStyles || {});
        tableOptions.alternateRowStyles = Object.assign({}, {fillColor: [246, 246, 246]}, opts.alternateRowStyles || {});

        doc.autoTable(tableOptions);

        return doc.lastAutoTable ? doc.lastAutoTable.finalY + 8 : startY + 16;
    }

    function rowFillByOwner(data) {
        const owner = data.row.raw && data.row.raw._owner;
        const color = ownerColors[owner];
        if (!color || data.section !== 'body') return;
        data.cell.styles.fillColor = color;
    }

    async function exportPdf(config, button) {

        try {
            if (!window.N360PDF || typeof window.N360PDF.createDocument !== 'function') {
                throw new Error('La plantilla PDF estandar no esta cargada.');
            }
            if (!window.jspdf || !window.jspdf.jsPDF || typeof window.jspdf.jsPDF.API.autoTable !== 'function') {
                throw new Error('jsPDF AutoTable no esta cargado.');
            }

            const rows = Array.isArray(config.rows) ? config.rows : [];
            if (!rows.length) {
                notify('warning', 'No hay datos para exportar con los filtros actuales.');
                return;
            }

            setBusy(button, true);
            const pdfConfig = Object.assign({}, config.pdf || {}, {
                orientation: 'portrait',
                useCover: false,
                title: (config.pdf && config.pdf.title) || 'RESUMEN DE PEAJES POR PLACA',
                secondTitle: (config.pdf && config.pdf.secondTitle) || 'Facturas y detraccion por unidad'
            });

            const doc = await window.N360PDF.createDocument(Object.assign({}, pdfConfig, {
                content: function (pdf) {
                    const W = pdf.internal.pageSize.getWidth();
                    let y = drawReportContext(pdf, 30.5, W - 25.4, config);
                    const mainTotals = totals(rows);

                    const mainBody = rows.map(row => ({
                        _owner: clean(row.dueno, 'No identificado'),
                        0: vehicle(row),
                        1: clean(row.tipo_vehiculo, 'Sin tipo'),
                        2: clean(row.dueno, 'No identificado'),
                        3: num(row.registros),
                        4: money(row.total),
                        5: money(row.detraccion)
                    }));

                    y = autoTable(pdf, 'Facturas y detracciones por placa', [
                        'Placa / bus', 'Tipo', 'Dueno', 'Cant.', 'Total', 'Detraccion'
                    ], mainBody, y, {
                        columns: [
                            {dataKey: 0}, {dataKey: 1}, {dataKey: 2}, {dataKey: 3},
                            {dataKey: 4}, {dataKey: 5}
                        ],
                        columnStyles: {
                            0: {cellWidth: 54},
                            1: {cellWidth: 30},
                            2: {cellWidth: 33},
                            3: {cellWidth: 15, halign: 'right'},
                            4: {cellWidth: 27, halign: 'right'},
                            5: {cellWidth: W - 25.4 - 159, halign: 'right'}
                        },
                        foot: [[{content: 'TOTAL GENERAL', colSpan: 3, styles: {halign: 'left'}}, num(mainTotals.registros),
                            money(mainTotals.total), money(mainTotals.detraccion)]],
                        didParseCell: rowFillByOwner
                    });

                    const owners = aggregateByOwner(rows);
                    const ownerTotals = totals(owners);
                    y = autoTable(pdf, 'Resumen por dueno', [
                        'Dueno', 'Placas', 'Registros', 'Total', 'Detraccion'
                    ], owners.map(row => [
                        row.dueno,
                        num(row.placas),
                        num(row.registros),
                        money(row.total),
                        money(row.detraccion)
                    ]), y, {
                        columnStyles: {
                            1: {halign: 'right'},
                            2: {halign: 'right'},
                            3: {halign: 'right'},
                            4: {halign: 'right'}
                        },
                        foot: [[{content: 'TOTAL DUENOS', styles: {halign: 'left'}}, num(ownerTotals.placas), num(ownerTotals.registros),
                            money(ownerTotals.total), money(ownerTotals.detraccion)]]
                    });

                    const groups = aggregateByGroup(rows);
                    const groupTotals = totals(groups);
                    y = autoTable(pdf, 'Resumen por grupo operativo', [
                        'Grupo', 'Placas', 'Registros', 'Total', 'Detraccion'
                    ], groups.map(row => [
                        row.grupo,
                        num(row.placas),
                        num(row.registros),
                        money(row.total),
                        money(row.detraccion)
                    ]), y, {
                        columnStyles: {
                            1: {halign: 'right'},
                            2: {halign: 'right'},
                            3: {halign: 'right'},
                            4: {halign: 'right'}
                        },
                        foot: [[{content: 'TOTAL GRUPOS', styles: {halign: 'left'}}, num(groupTotals.placas), num(groupTotals.registros),
                            money(groupTotals.total), money(groupTotals.detraccion)]]
                    });

                    const missing = nonMatchingRows(rows);
                    if (missing.length) {
                        const missingTotals = totals(missing);
                        y = autoTable(pdf, 'Placas no coincidentes con maestro de flota', [
                            'Placa', 'Registros', 'Total', 'Detraccion'
                        ], missing.map(row => [
                            clean(row.placa),
                            num(row.registros),
                            money(row.total),
                            money(row.detraccion)
                        ]), y, {
                            columnStyles: {1: {halign: 'right'}, 2: {halign: 'right'}, 3: {halign: 'right'}},
                            foot: [[{content: 'TOTAL NO COINCIDENTES', styles: {halign: 'left'}}, num(missingTotals.registros),
                                money(missingTotals.total), money(missingTotals.detraccion)]]
                        });
                    }
                }
            }));

            doc.save(pdfConfig.fileName || `peajes_resumen_placas_${Date.now()}.pdf`);
        } catch (error) {
            notify('danger', error.message || 'No se pudo generar el PDF.');
        } finally {
            setBusy(button, false);
        }
    }

    if (button) {
        button.addEventListener('click', function () {
            if (!button.disabled) exportPdf(config, button);
        });
    }
    window.N360PeajesPDF = {export: exportPdf,
        summaries: function (rows) { return {duenos: aggregateByOwner(rows), grupos: aggregateByGroup(rows)}; }};
})(window, document);
