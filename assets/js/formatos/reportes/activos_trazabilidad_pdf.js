(function () {
  'use strict';
  const text = value => value == null || String(value).trim() === '' ? '-' : String(value);
  const date = value => {
    const match = String(value || '').match(/^([1-9]\d{3})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    return match ? `${match[3]}/${match[2]}/${match[1]}${match[4] ? ` ${match[4]}:${match[5]}` : ''}` : '-';
  };

  async function exportPdf(report) {
    if (!window.N360PDF || !window.jspdf?.jsPDF || typeof window.jspdf.jsPDF.API.autoTable !== 'function') {
      throw new Error('No se cargo el generador de PDF. Actualiza la pagina e intenta nuevamente.');
    }
    if (!report.rows?.length) throw new Error('No hay filas mostradas para exportar.');
    const filters = Object.entries(report.filters || {}).filter(([key, value]) => {
      const content = String(value ?? '').trim();
      return content !== '' && (key === 'Estado' || !['Todos', 'Todas'].includes(content));
    });
    const now = new Date();
    const doc = await window.N360PDF.createDocument({
      ...(report.pdf || {}),
      orientation: 'portrait',
      useCover: false,
      title: 'TRAZABILIDAD DE ACTIVOS',
      docCode: 'N360-CONT-ACTIVOS',
      dateText: now.toLocaleString('es-PE', {timeZone: 'America/Lima'}),
      content(pdf) {
        const margin = {top: 32, right: 12.7, bottom: 20, left: 12.7};
        const width = pdf.internal.pageSize.getWidth() - margin.left - margin.right;
        const styles = {font: 'helvetica', fontSize: 8, cellPadding: 2, overflow: 'linebreak', valign: 'top', lineWidth: .15, lineColor: [210, 222, 234], textColor: [15, 35, 52]};
        pdf.autoTable({
          startY: 32, margin, theme: 'grid', styles,
          head: [['Filtros aplicados', 'Valor']],
          body: [
            ['Registros del reporte', `${report.rows.length} mostrados de ${Number(report.total || 0)} encontrados`],
            ...filters.map(([key, value]) => [key, text(value)])
          ],
          headStyles: {fillColor: [19, 43, 67], textColor: [255, 255, 255]},
          columnStyles: {0: {cellWidth: 53, fontStyle: 'bold'}, 1: {cellWidth: width - 53}},
          rowPageBreak: 'avoid'
        });
        pdf.autoTable({
          startY: pdf.lastAutoTable.finalY + 6, margin, theme: 'grid', styles,
          head: [['Etiqueta / creacion', 'Producto / categoria', 'Ubicacion actual', 'Movimiento de origen', 'Estado']],
          body: report.rows.map(row => [
            `${text(row.etiqueta_codigo)}\n${date(row.etiqueta_fecha)}`,
            `(${text(row.producto_codigo)}) ${text(row.producto_nombre)}\nUnidad: ${text(row.producto_unidad)}\n${[row.categoria_codigo, row.categoria_nombre].filter(Boolean).join(' ')}`,
            `${row.sede_nombre || 'Sin ubicacion'}\n${text(row.ubicacion_detalle)}`,
            `MOV-${row.movimiento_id} - ${text(row.movimiento_tipo)}\n${row.nota_codigo || `MOV-${row.movimiento_id}`}\n${date(row.movimiento_fecha)}\n${text(row.usuario_nombre)}`,
            text(row.etiqueta_estado)
          ]),
          columnStyles: {0: {cellWidth: 27}, 1: {cellWidth: 49}, 2: {cellWidth: 39}, 3: {cellWidth: 47}, 4: {cellWidth: width - 162}},
          headStyles: {fillColor: [19, 43, 67], textColor: [255, 255, 255]},
          alternateRowStyles: {fillColor: [245, 249, 253]},
          showHead: 'everyPage', rowPageBreak: 'avoid'
        });
      }
    });
    const stamp = new Intl.DateTimeFormat('sv-SE', {timeZone: 'America/Lima'}).format(now);
    doc.save(`trazabilidad_activos_${stamp}.pdf`);
  }
  window.N360ActivosPDF = Object.freeze({exportPdf});
})();
