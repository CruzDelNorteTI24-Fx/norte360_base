(function (window, document) {
    'use strict';
    const general = window.N360_PEAJES_RESUMEN || {};
    const button = document.querySelector('[data-pje-summary-excel]');
    const moneyFormat = '"S/ "#,##0.00';
    const text = (value, fallback) => String(value == null ? '' : value).trim() || fallback || '-';
    const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;

    function addSheet(workbook, name, rows, widths, moneyColumns) {
        const sheet = window.XLSX.utils.aoa_to_sheet(rows);
        sheet['!cols'] = widths.map(wch => ({wch}));
        sheet['!autofilter'] = {ref: window.XLSX.utils.encode_range({s: {r: 0, c: 0},
            e: {r: Math.max(0, rows.length - 1), c: widths.length - 1}})};
        for (let r = 1; r < rows.length; r++) {
            moneyColumns.forEach(c => {
                const cell = sheet[window.XLSX.utils.encode_cell({r, c})];
                if (cell) cell.z = moneyFormat;
            });
        }
        window.XLSX.utils.book_append_sheet(workbook, sheet, name);
        return sheet;
    }

    function exportExcel(report) {
        if (!window.XLSX || !window.N360PeajesPDF) {
            throw new Error('No se cargo el generador de Excel. Recarga la pagina.');
        }
        const rows = Array.isArray(report.rows) ? report.rows : [];
        if (!rows.length) throw new Error('No hay datos para exportar con los filtros actuales.');
        const filters = report.filters || {};
        const kpis = report.kpis || {};
        const supplier = report.proveedor;
        const workbook = window.XLSX.utils.book_new();
        workbook.Props = {Title: 'Resumen de peajes por placa', Author: 'Norte360', Company: 'Cruz del Norte'};
        const summaryRows = [
            ['RESUMEN DE PEAJES POR PLACA', 'NORTE360'],
            ['Reporte', supplier ? 'Por proveedor' : 'General'],
            ['Proveedor', supplier ? text(supplier.nombre) : 'Todos'],
            ['RUC del proveedor', supplier ? String(supplier.ruc) : '-'],
            ['Desde', text(filters.desde)], ['Hasta', text(filters.hasta)],
            ['Estacion / proceso', text(filters.estacion, 'TODOS')],
            ['Usuario', text(filters.usuario, 'TODOS')],
            ['Importacion', text(filters.importacion, 'TODOS')],
            ['Filtro de placa', text(filters.placa)], ['Factura', text(filters.factura)],
            ['Busqueda', text(filters.buscar)], [],
            ['Registros', number(kpis.registros)], ['Facturas distintas', number(kpis.facturas)],
            ['Placas del reporte', rows.length],
            ['No coinciden', rows.filter(row => number(row.placa_id) <= 0).length],
            ['Total', number(kpis.total)], ['Detraccion', number(kpis.detraccion)]
        ];
        const summary = window.XLSX.utils.aoa_to_sheet(summaryRows);
        summary['!cols'] = [{wch: 30}, {wch: 85}];
        summary.B18.z = moneyFormat;
        summary.B19.z = moneyFormat;
        window.XLSX.utils.book_append_sheet(workbook, summary, 'Resumen');
        addSheet(workbook, 'Por placa', [
            ['Estado', 'Placa', 'Unidad', 'Tipo', 'Dueno', 'Registros', 'Facturas', 'Total', 'Detraccion'],
            ...rows.map(row => [number(row.placa_id) > 0 ? 'Coincide' : 'No coincide',
                text(row.placa), text(row.bus), text(row.tipo_vehiculo, 'Sin tipo'),
                text(row.dueno, 'No identificado'), number(row.registros), number(row.facturas),
                number(row.total), number(row.detraccion)])
        ], [16, 15, 28, 24, 24, 12, 12, 18, 18], [7, 8]);
        const summaries = window.N360PeajesPDF.summaries(rows);
        [['Por dueno', summaries.duenos, 'dueno', 'Dueno'], ['Por grupo', summaries.grupos, 'grupo', 'Grupo']]
            .forEach(([name, items, key, label]) => addSheet(workbook, name, [
                [label, 'Placas', 'Registros', 'Facturas por placa', 'Total', 'Detraccion'],
                ...items.map(item => [text(item[key]), number(item.placas), number(item.registros),
                    number(item.facturas), number(item.total), number(item.detraccion)])
            ], [28, 12, 12, 20, 18, 18], [4, 5]));
        const range = `${text(filters.desde)}_${text(filters.hasta)}`.replace(/[^0-9_-]/g, '');
        const scope = supplier ? String(supplier.ruc).replace(/[^0-9]/g, '') : 'general';
        window.XLSX.writeFile(workbook, `peajes_placas_${scope}_${range}.xlsx`, {compression: true});
    }

    if (button) button.addEventListener('click', function () {
        if (button.disabled) return;
        button.disabled = true;
        try {
            exportExcel(general);
        } catch (error) {
            if (window.N360Loader && typeof window.N360Loader.toast === 'function') {
                window.N360Loader.toast('danger', error.message);
            } else window.alert(error.message);
        } finally { button.disabled = false; }
    });
    window.N360PeajesExcel = {export: exportExcel};
})(window, document);
