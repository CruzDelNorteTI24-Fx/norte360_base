(function () {
  'use strict';
  const text = (value) => value == null || String(value).trim() === '' ? '-' : String(value);
  const date = (value) => {
    const match = String(value || '').match(/^([1-9]\d{3})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    return match ? `${match[3]}/${match[2]}/${match[1]}${match[4] ? ` ${match[4]}:${match[5]}` : ''}` : '-';
  };
  function exportExcel(report) {
    if (!window.XLSX?.utils) throw new Error('No se cargo el generador de Excel. Actualiza la pagina e intenta nuevamente.');
    if (!report.rows?.length) throw new Error('No hay filas mostradas para exportar.');
    const workbook = window.XLSX.utils.book_new();
    workbook.Props = {Title: 'Trazabilidad de activos', Author: 'Norte360', Company: 'Cruz del Norte'};
    const header = ['Etiqueta', 'Creacion de etiqueta', 'Codigo producto', 'Producto', 'Unidad', 'Categoria', 'Sede actual', 'Detalle de ubicacion', 'Movimiento de origen', 'Tipo de movimiento', 'Nota de origen', 'Fecha del movimiento', 'Usuario del movimiento', 'Estado'];
    const rows = report.rows.map(row => [text(row.etiqueta_codigo), date(row.etiqueta_fecha), text(row.producto_codigo),
      text(row.producto_nombre), text(row.producto_unidad), [row.categoria_codigo, row.categoria_nombre].filter(Boolean).join(' '),
      row.sede_nombre || 'Sin ubicacion', text(row.ubicacion_detalle), Number(row.movimiento_id), text(row.movimiento_tipo),
      row.nota_codigo || `MOV-${row.movimiento_id}`, date(row.movimiento_fecha), text(row.usuario_nombre), text(row.etiqueta_estado)]);
    const sheet = window.XLSX.utils.aoa_to_sheet([header, ...rows]);
    sheet['!cols'] = [20, 22, 18, 45, 10, 38, 30, 50, 23, 22, 22, 23, 30, 18].map(wch => ({wch}));
    sheet['!autofilter'] = {ref: window.XLSX.utils.encode_range({s: {r: 0, c: 0}, e: {r: rows.length, c: header.length - 1}})};
    window.XLSX.utils.book_append_sheet(workbook, sheet, 'Etiquetas mostradas');
    const now = new Date().toLocaleString('es-PE', {timeZone: 'America/Lima'});
    const summary = window.XLSX.utils.aoa_to_sheet([
      ['TRAZABILIDAD DE ACTIVOS', 'Norte360'], ['Exportado en Peru', now],
      ['Filas exportadas', rows.length], ['Coincidencias de los filtros', Number(report.total || 0)],
      ['Alcance', 'Filas mostradas en pantalla; maximo 600'], [], ['Filtro aplicado', 'Valor'],
      ...Object.entries(report.filters || {}).map(([key, value]) => [key, text(value)])
    ]);
    summary['!cols'] = [{wch: 36}, {wch: 85}];
    window.XLSX.utils.book_append_sheet(workbook, summary, 'Filtros');
    const stamp = new Intl.DateTimeFormat('sv-SE', {timeZone: 'America/Lima'}).format(new Date());
    window.XLSX.writeFile(workbook, `trazabilidad_activos_${stamp}.xlsx`, {compression: true});
  }
  window.N360ActivosExcel = Object.freeze({exportExcel});
})();
