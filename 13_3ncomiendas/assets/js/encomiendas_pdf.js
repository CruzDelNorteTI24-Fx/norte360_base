(function () {
  const root = document.querySelector('[data-enc-tracking]') || document.querySelector('[data-enc-detail-page]');
  if (!root) return;

  const reportEndpoint = 'actions/report_data.php';
  const stateLabels = {
    REGISTRADA: 'Registrada',
    PENDIENTE: 'Pendiente',
    EMBARCADO: 'Embarcada',
    EN_TRANSITO: 'En transito',
    RECIBIDO: 'Recibida',
    FINALIZADA: 'Finalizada',
    OBSERVADA: 'Observada',
    OBSERVADO: 'Observada',
    INCOMPLETO: 'Incompleta',
    ANULADA: 'Anulada',
    ORIGEN: 'Origen',
    RUTA: 'Ruta',
    DESTINO: 'Destino'
  };

  const docTypeLabels = {
    MANIFIESTO_ENCOMIENDAS: 'Manifiesto',
    GUIA_TRANSPORTISTA: 'Guia transportista'
  };

  const safe = (value, fallback) => {
    const text = String(value ?? '').trim();
    return text !== '' ? text : (fallback || '-');
  };

  const stateText = (value) => stateLabels[String(value || '').toUpperCase()] || safe(value);

  const formatDate = (value) => {
    if (!value) return '-';
    const parts = String(value).slice(0, 10).split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : String(value);
  };

  const formatDateTime = (value) => {
    if (!value) return '-';
    const text = String(value).replace('T', ' ');
    return `${formatDate(text.slice(0, 10))} ${text.slice(11, 16)}`.trim();
  };

  const nowStamp = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}`;
  };

  const unitLabel = (row) => {
    const bus = safe(row.placa_bus || row.clm_placas_BUS, '');
    const plate = safe(row.placa_placa || row.clm_placas_PLACA, '');
    if (!bus && !plate) return 'Sin unidad';
    if (bus && plate) return `${bus} (${plate})`;
    return bus || plate;
  };

  const routeLabel = (guide) => `${safe(guide.sede_embarque)} -> ${safe(guide.sede_desembarque)}`;

  const parseJson = async (response) => {
    const text = await response.text();
    try {
      return JSON.parse(text.replace(/^\uFEFF/, ''));
    } catch (error) {
      throw new Error('El servidor devolvio una respuesta no valida para el reporte.');
    }
  };

  const showDialog = (message, variant, title) => {
    if (window.N360Dialog && typeof window.N360Dialog.alert === 'function') {
      return window.N360Dialog.alert(message, { variant: variant || 'info', title: title || 'Encomiendas' });
    }
    window.alert(message);
    return Promise.resolve();
  };

  const fetchReport = async (params) => {
    const url = new URL(reportEndpoint, window.location.href);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value) !== '') url.searchParams.set(key, value);
    });
    const response = await fetch(url.toString(), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await parseJson(response);
    if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo generar el reporte.');
    return data;
  };

  const withButton = async (button, task, title, detail) => {
    try {
      if (window.N360Loader && typeof window.N360Loader.during === 'function') {
        return await window.N360Loader.during(task(), { button, title, detail });
      }
      if (button) button.disabled = true;
      return await task();
    } finally {
      if (button) button.disabled = false;
    }
  };

  const assertPdfDeps = () => {
    if (!window.N360PDF || typeof window.N360PDF.createDocument !== 'function') {
      throw new Error('La plantilla PDF estandar no esta cargada.');
    }
    if (!window.jspdf || !window.jspdf.jsPDF) {
      throw new Error('jsPDF no esta cargado.');
    }
  };

  const drawSummaryBox = (doc, x, y, w, rows) => {
    if (window.N360PDF && typeof window.N360PDF.drawReportSummary === 'function') {
      return window.N360PDF.drawReportSummary(doc, {
        x,
        y,
        width: w,
        title: 'Resumen operativo',
        rows,
        columns: w > 230 ? 4 : 3,
        bottomGap: 8
      });
    }

    const rowH = 7.2;
    const h = 10 + rows.length * rowH;
    doc.setDrawColor(34, 147, 220);
    doc.setLineWidth(0.55);
    doc.line(x, y, x + w, y);
    doc.setTextColor(88, 110, 135);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7.6);
    doc.text('RESUMEN OPERATIVO', x, y + 7.2);
    doc.setFontSize(7.2);
    rows.forEach((row, idx) => {
      const yy = y + 13 + idx * rowH;
      doc.setTextColor(82, 103, 127);
      doc.setFont('helvetica', 'bold');
      doc.text(row[0], x, yy);
      doc.setTextColor(8, 36, 61);
      doc.setFont('helvetica', 'normal');
      doc.text(String(row[1] || '-'), x + 42, yy);
    });
    doc.setDrawColor(210, 226, 241);
    doc.setLineWidth(0.12);
    doc.line(x, y + h, x + w, y + h);
    return y + h + 8;
  };

  const simpleTable = (doc, options) => {
    if (typeof doc.autoTable === 'function') {
      doc.autoTable(options);
      return doc.lastAutoTable ? doc.lastAutoTable.finalY : (options.startY || 36);
    }
    throw new Error('El plugin autoTable no esta cargado.');
  };

  const buildGuidePdf = async (payload) => {
    const guide = payload.guia || {};
    const points = payload.points || [];
    const docs = payload.documents || [];
    const history = (payload.history || []).slice(0, 8);
    const user = payload.user || {};
    const doc = await window.N360PDF.createDocument({
      orientation: 'portrait',
      useCover: false,
      title: 'GUIA NORTE DE ENCOMIENDAS',
      secondTitle: safe(guide.clm_enc_guia, 'Control Encomienda'),
      docCode: safe(guide.clm_enc_guia, 'ENC-CE'),
      userName: safe(user.name || root.dataset.reportUser, 'Usuario'),
      dni: safe(user.dni || root.dataset.reportDni, 'No registrado'),
      logoLeft: '../img/icon.png',
      logoRight: '../img/norte360.png',
      content(document) {
        const W = document.internal.pageSize.getWidth();
        const left = 13;
        const contentW = W - left * 2;
        let y = 36;

        y = drawSummaryBox(document, left, y, contentW, [
          ['Control Encomienda', safe(guide.clm_enc_guia)],
          ['Fecha guia', formatDate(guide.clm_enc_fecha_guia)],
          ['Ruta', routeLabel(guide)],
          ['Unidad', unitLabel(guide)],
          ['Estado general', stateText(guide.clm_enc_estado_general)],
          ['Horario', [safe(guide.clm_enc_horario_operativo, ''), safe(guide.clm_enc_hora_embarque_programada, '')].filter(Boolean).join(' | ') || 'Sin horario'],
          ['Registra', safe(guide.usuario_registra)]
        ]);

        document.setTextColor(8, 36, 61);
        document.setFont('helvetica', 'bold');
        document.setFontSize(10.5);
        document.text('Ruta y manifiestos', left, y);
        y += 4;
        y = simpleTable(document, {
          startY: y,
          margin: { left, right: left, top: 30, bottom: 24 },
          head: [['Orden', 'Punto', 'Oficina', 'Estado', 'Manifiesto']],
          body: points.map((point) => [
            safe(point.clm_encpunto_orden),
            stateText(point.clm_encpunto_tipo),
            safe(point.sede_nombre),
            stateText(point.clm_encpunto_estado),
            Number(point.manifiesto_ok || 0) > 0 ? 'Cargado' : 'Pendiente'
          ]),
          theme: 'grid',
          styles: { fontSize: 7.4, cellPadding: 2.1, overflow: 'linebreak', textColor: [15, 35, 55], lineColor: [218, 230, 241] },
          headStyles: { fillColor: [18, 42, 64], textColor: [255, 255, 255], fontStyle: 'bold' },
          alternateRowStyles: { fillColor: [248, 251, 254] }
        }) + 8;

        document.setFont('helvetica', 'bold');
        document.setFontSize(10.5);
        document.setTextColor(8, 36, 61);
        document.text('Documentos asociados', left, y);
        y += 4;
        y = simpleTable(document, {
          startY: y,
          margin: { left, right: left, top: 30, bottom: 24 },
          head: [['Tipo', 'Punto', 'Comprobante', 'Archivo', 'Carga']],
          body: docs.length ? docs.map((item) => [
            docTypeLabels[item.clm_encdoc_tipo] || safe(item.clm_encdoc_tipo),
            safe(item.punto_sede, 'General'),
            [safe(item.clm_encdoc_tipo_comprobante, ''), safe(item.clm_encdoc_numero_comprobante, '')].filter(Boolean).join(' ') || '-',
            safe(item.clm_encdoc_nombre),
            `${formatDateTime(item.clm_encdoc_fechacarga)}\n${safe(item.usuario_carga)}`
          ]) : [['-', '-', '-', 'Sin documentos cargados', '-']],
          theme: 'grid',
          styles: { fontSize: 7.1, cellPadding: 2.0, overflow: 'linebreak', textColor: [15, 35, 55], lineColor: [218, 230, 241] },
          headStyles: { fillColor: [18, 42, 64], textColor: [255, 255, 255], fontStyle: 'bold' },
          alternateRowStyles: { fillColor: [248, 251, 254] },
          columnStyles: { 3: { cellWidth: 48 }, 4: { cellWidth: 34 } }
        }) + 8;

        document.setFont('helvetica', 'bold');
        document.setFontSize(10.5);
        document.setTextColor(8, 36, 61);
        document.text('Ultimos eventos', left, y);
        y += 4;
        simpleTable(document, {
          startY: y,
          margin: { left, right: left, top: 30, bottom: 24 },
          head: [['Fecha', 'Accion', 'Usuario', 'Estado']],
          body: history.length ? history.map((item) => [
            formatDateTime(item.clm_enchist_fechaevento),
            safe(item.clm_enchist_accion),
            safe(item.usuario_evento),
            `${stateText(item.clm_enchist_estado_anterior)} -> ${stateText(item.clm_enchist_estado_nuevo)}`
          ]) : [['-', 'Sin historial registrado', '-', '-']],
          theme: 'grid',
          styles: { fontSize: 7.1, cellPadding: 2.0, overflow: 'linebreak', textColor: [15, 35, 55], lineColor: [218, 230, 241] },
          headStyles: { fillColor: [18, 42, 64], textColor: [255, 255, 255], fontStyle: 'bold' },
          alternateRowStyles: { fillColor: [248, 251, 254] }
        });
      }
    });
    doc.save(`guia_norte_${safe(guide.clm_enc_guia, guide.clm_enc_id).replace(/[^A-Za-z0-9_-]+/g, '_')}.pdf`);
  };

  const buildTrackingPdf = async (payload) => {
    const rows = payload.rows || [];
    const filters = payload.filters || {};
    const kpis = payload.kpis || {};
    const user = payload.user || {};
    const doc = await window.N360PDF.createDocument({
      orientation: 'portrait',
      useCover: false,
      title: 'CONSOLIDADO DE CONTROL ENCOMIENDAS',
      secondTitle: 'Tracking de encomiendas',
      docCode: 'ENC-CONS',
      userName: safe(user.name || root.dataset.reportUser, 'Usuario'),
      dni: safe(user.dni || root.dataset.reportDni, 'No registrado'),
      logoLeft: '../img/icon.png',
      logoRight: '../img/norte360.png',
      content(document) {
        const W = document.internal.pageSize.getWidth();
        const H = document.internal.pageSize.getHeight();
        const left = 13;
        const contentW = W - left * 2;
        let y = 36;
        const period = filters.fecha_guia
          ? formatDate(filters.fecha_guia)
          : `${formatDate(filters.desde)} al ${formatDate(filters.hasta)}`;

        y = drawSummaryBox(document, left, y, contentW, [
          ['Periodo', period],
          ['Registros', safe(kpis.total, '0')],
          ['Activas', safe(kpis.activas, '0')],
          ['En transito', safe(kpis.transito, '0')],
          ['Finalizadas', safe(kpis.finalizadas, '0')],
          ['Observadas', safe(kpis.observadas, '0')]
        ]);

        simpleTable(document, {
          startY: y,
          margin: { left, right: left, top: 30, bottom: 24 },
          head: [['Guia', 'Fecha', 'Ruta', 'Unidad', 'Estados', 'Docs']],
          body: rows.length ? rows.map((row) => [
            safe(row.clm_enc_guia),
            formatDate(row.clm_enc_fecha_guia),
            `${safe(row.sede_embarque)} -> ${safe(row.sede_desembarque)}`,
            unitLabel(row),
            `General: ${stateText(row.clm_enc_estado_general)}\nEmb.: ${stateText(row.clm_enc_estado_embarque)}\nDes.: ${stateText(row.clm_enc_estado_desembarque)}`,
            `Manif.: ${Number(row.manifiestos_ok || 0)}/${Number(row.manifiestos_req || 0)}\nG. transp.: ${Number(row.guias_transportista_total || 0)}`
          ]) : [['-', '-', '-', '-', 'Sin resultados', '-']],
          theme: 'grid',
          styles: { fontSize: 6.9, cellPadding: 1.9, overflow: 'linebreak', textColor: [15, 35, 55], lineColor: [218, 230, 241] },
          headStyles: { fillColor: [18, 42, 64], textColor: [255, 255, 255], fontStyle: 'bold' },
          alternateRowStyles: { fillColor: [248, 251, 254] },
          columnStyles: {
            0: { cellWidth: 22 },
            1: { cellWidth: 19 },
            2: { cellWidth: 47 },
            3: { cellWidth: 34 },
            4: { cellWidth: 39 },
            5: { cellWidth: 23 }
          }
        });

        if (Number(payload.totalRows || 0) > rows.length) {
          document.setTextColor(122, 89, 0);
          document.setFont('helvetica', 'bold');
          document.setFontSize(7.4);
          document.text(`Nota: se imprimieron ${rows.length} de ${payload.totalRows} registros por limite operativo del reporte.`, left, H - 18);
        }
      }
    });
    doc.save(`consolidado_guias_norte_${nowStamp()}.pdf`);
  };
  document.addEventListener('click', async (event) => {
    const guideButton = event.target.closest('[data-enc-pdf-guide]');
    if (guideButton) {
      event.preventDefault();
      try {
        assertPdfDeps();
        const id = guideButton.dataset.encPdfGuide;
        const payload = await withButton(guideButton, () => fetchReport({ type: 'guia', id }), 'Generando PDF', 'Preparando Control Encomienda...');
        await buildGuidePdf(payload);
      } catch (error) {
        await showDialog(error.message || 'No se pudo generar el PDF.', 'danger', 'PDF no generado');
      }
      return;
    }

    const trackingButton = event.target.closest('[data-enc-pdf-tracking]');
    if (!trackingButton) return;
    event.preventDefault();
    try {
      assertPdfDeps();
      const params = Object.fromEntries(new URLSearchParams(window.location.search).entries());
      params.type = 'tracking';
      params.limit = 1500;
      const payload = await withButton(trackingButton, () => fetchReport(params), 'Generando consolidado', 'Leyendo Control Encomiendas filtradas...');
      await buildTrackingPdf(payload);
    } catch (error) {
      await showDialog(error.message || 'No se pudo generar el consolidado.', 'danger', 'PDF no generado');
    }
  });
})();
