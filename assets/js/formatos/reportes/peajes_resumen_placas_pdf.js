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
        const bus = clean(row.bus, '');
        const placa = clean(row.placa, '');
        if (bus && placa) return `${bus} (${placa})`;
        return bus || placa || '-';
    }

    function setBusy(isBusy) {
        if (!button) return;
        if (isBusy) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Generando';
            return;
        }
        button.disabled = false;
        if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
    }

    function notify(type, message) {
        if (window.N360Loader && typeof window.N360Loader.toast === 'function') {
            window.N360Loader.toast(type, message);
            return;
        }
        if (type === 'danger') {
            alert(message);
        }
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

    function infoText() {
        const filters = config.filters || {};
        const pieces = [
            `Periodo: ${clean(filters.desde)} al ${clean(filters.hasta)}`,
            `Estacion: ${clean(filters.estacion, 'TODOS')}`,
            `Usuario: ${clean(filters.usuario, 'TODOS')}`,
            `Importacion: ${clean(filters.importacion, 'TODOS')}`
        ];
        if (filters.placa) pieces.push(`Placa: ${filters.placa}`);
        if (filters.buscar) pieces.push(`Busqueda: ${filters.buscar}`);
        return pieces;
    }

    function drawSummaryBox(doc, y, width) {
        const kpis = config.kpis || {};
        const rows = config.rows || [];
        const noMatch = nonMatchingRows(rows).length;
        const left = 12.7;

        doc.setFillColor(245, 248, 251);
        doc.setDrawColor(214, 226, 239);
        doc.roundedRect(left, y, width, 26, 2, 2, 'FD');

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9);
        doc.setTextColor(18, 42, 64);
        doc.text('Resumen del reporte', left + 5, y + 7);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.2);
        doc.setTextColor(71, 85, 105);
        const filtersLines = doc.splitTextToSize(infoText().join('  |  '), width - 10);
        doc.text(filtersLines, left + 5, y + 12.3);

        doc.setFont('helvetica', 'bold');
        doc.setTextColor(15, 23, 42);
        const kpiLines = doc.splitTextToSize(`Registros: ${num(kpis.registros)}  |  Placas: ${num(rows.length)}  |  No coinciden: ${num(noMatch)}  |  Total: ${money(kpis.total)}  |  Detraccion: ${money(kpis.detraccion)}`, width - 10);
        doc.text(kpiLines, left + 5, y + 19.5);
    }

    function autoTable(doc, title, head, body, startY, options) {
        const left = 12.7;
        const width = doc.internal.pageSize.getWidth() - 25.4;
        const opts = options || {};
        const baseStyles = {
            font: 'helvetica',
            fontSize: 6.6,
            cellPadding: 1.7,
            lineColor: [214, 226, 239],
            lineWidth: 0.08,
            overflow: 'linebreak',
            valign: 'middle'
        };
        const baseHeadStyles = {
            fillColor: [18, 42, 64],
            textColor: [255, 255, 255],
            fontStyle: 'bold',
            halign: 'center'
        };
        const tableOptions = Object.assign({
            startY: startY + 4,
            margin: {left: left, right: left, top: 32, bottom: 23},
            tableWidth: width,
            head: [head],
            body: body.length ? body : [head.map(() => '-')],
            theme: 'grid',
            styles: baseStyles,
            headStyles: baseHeadStyles,
            alternateRowStyles: {fillColor: [248, 251, 255]},
            didDrawPage: function () {}
        }, opts);
        tableOptions.styles = Object.assign({}, baseStyles, opts.styles || {});
        tableOptions.headStyles = Object.assign({}, baseHeadStyles, opts.headStyles || {});
        tableOptions.alternateRowStyles = Object.assign({}, {fillColor: [248, 251, 255]}, opts.alternateRowStyles || {});

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10.5);
        doc.setTextColor(7, 27, 49);
        doc.text(title, left, startY);

        doc.autoTable(tableOptions);

        return doc.lastAutoTable ? doc.lastAutoTable.finalY + 8 : startY + 16;
    }

    function rowFillByOwner(data) {
        const owner = data.row.raw && data.row.raw._owner;
        const color = ownerColors[owner];
        if (!color || data.section !== 'body') return;
        data.cell.styles.fillColor = color;
    }

    async function exportPdf() {
        if (!button || button.disabled) return;

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

            setBusy(true);
            const pdfConfig = Object.assign({}, config.pdf || {}, {
                orientation: 'portrait',
                useCover: false,
                title: (config.pdf && config.pdf.title) || 'RESUMEN DE PEAJES POR PLACA',
                secondTitle: (config.pdf && config.pdf.secondTitle) || 'Facturas y detraccion por unidad'
            });

            const doc = await window.N360PDF.createDocument(Object.assign({}, pdfConfig, {
                content: function (pdf) {
                    const W = pdf.internal.pageSize.getWidth();
                    let y = 34;
                    drawSummaryBox(pdf, y, W - 25.4);
                    y += 36;

                    const mainBody = rows.map(row => ({
                        _owner: clean(row.dueno, 'No identificado'),
                        0: Number(row.placa_id || 0) > 0 ? 'Coincide' : 'No coincide',
                        1: vehicle(row),
                        2: clean(row.tipo_vehiculo, 'Sin tipo'),
                        3: clean(row.dueno, 'No identificado'),
                        4: num(row.registros),
                        5: num(row.facturas),
                        6: money(row.total),
                        7: money(row.detraccion)
                    }));

                    y = autoTable(pdf, 'Facturas y detracciones por placa', [
                        'Estado', 'Placa / bus', 'Tipo', 'Dueno', 'Reg.', 'Fact.', 'Total', 'Detr.'
                    ], mainBody, y, {
                        styles: {fontSize: 5.8, cellPadding: 1.1},
                        columns: [
                            {dataKey: 0}, {dataKey: 1}, {dataKey: 2}, {dataKey: 3},
                            {dataKey: 4}, {dataKey: 5}, {dataKey: 6}, {dataKey: 7}
                        ],
                        columnStyles: {
                            0: {cellWidth: 18},
                            1: {cellWidth: 34},
                            2: {cellWidth: 25},
                            3: {cellWidth: 30},
                            4: {cellWidth: 12, halign: 'right'},
                            5: {cellWidth: 12, halign: 'right'},
                            6: {cellWidth: 26, halign: 'right'},
                            7: {cellWidth: 24, halign: 'right'}
                        },
                        didParseCell: rowFillByOwner
                    });

                    y = autoTable(pdf, 'Resumen por dueno', [
                        'Dueno', 'Placas', 'Registros', 'Facturas', 'Total', 'Detraccion'
                    ], aggregateByOwner(rows).map(row => [
                        row.dueno,
                        num(row.placas),
                        num(row.registros),
                        num(row.facturas),
                        money(row.total),
                        money(row.detraccion)
                    ]), y, {
                        columnStyles: {
                            1: {halign: 'right'},
                            2: {halign: 'right'},
                            3: {halign: 'right'},
                            4: {halign: 'right'},
                            5: {halign: 'right'}
                        }
                    });

                    y = autoTable(pdf, 'Resumen por grupo operativo', [
                        'Grupo', 'Placas', 'Registros', 'Facturas', 'Total', 'Detraccion'
                    ], aggregateByGroup(rows).map(row => [
                        row.grupo,
                        num(row.placas),
                        num(row.registros),
                        num(row.facturas),
                        money(row.total),
                        money(row.detraccion)
                    ]), y, {
                        columnStyles: {
                            1: {halign: 'right'},
                            2: {halign: 'right'},
                            3: {halign: 'right'},
                            4: {halign: 'right'},
                            5: {halign: 'right'}
                        }
                    });

                    const missing = nonMatchingRows(rows);
                    if (missing.length) {
                        y = autoTable(pdf, 'Placas no coincidentes con maestro de flota', [
                            'Placa', 'Registros', 'Facturas', 'Total', 'Detraccion'
                        ], missing.map(row => [
                            clean(row.placa),
                            num(row.registros),
                            num(row.facturas),
                            money(row.total),
                            money(row.detraccion)
                        ]), y);
                    }
                }
            }));

            doc.save(pdfConfig.fileName || `peajes_resumen_placas_${Date.now()}.pdf`);
        } catch (error) {
            notify('danger', error.message || 'No se pudo generar el PDF.');
        } finally {
            setBusy(false);
        }
    }

    if (button) {
        button.addEventListener('click', exportPdf);
    }
})(window, document);
