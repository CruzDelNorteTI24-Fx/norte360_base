(function () {
    'use strict';

    // Formato ticket 80 mm, siguiendo la estructura visual de la Nota de Abastecimiento Norte360.
    const CFG = {
        width: 80,
        minHeight: 200,
        maxHeight: 400,
        marginX: 5,
        navy: [52, 73, 94],       // #34495e
        gray: [90, 90, 90],
        softGray: [230, 230, 230],
        border: [130, 130, 130],
        text: [0, 0, 0]
    };

    const plain = (value, fallback = '-') => {
        if (value === null || value === undefined) return fallback;
        const text = String(value).trim();
        return text === '' ? fallback : text;
    };

    const firstValue = (...values) => {
        for (const value of values) {
            if (value !== null && value !== undefined && String(value).trim() !== '') {
                return value;
            }
        }
        return '';
    };

    const normalizeRow = (row = {}) => ({
        id: row.id,
        codigo: firstValue(row.codigo_interno, row.clm_requersen24_CODIGO_INTERNO),
        cotizacion: firstValue(row.cotizacion, row.clm_requersen24_COTIZACION),
        solicitante: firstValue(row.solicitante, row.clm_requersen24_SOLICITANTE),
        cargo: firstValue(row.cargo, row.clm_requersen24_CARGO),
        area: firstValue(row.area, row.clm_requersen24_AREA),
        comentario: firstValue(row.comentario, row.clm_requersen24_comentario),
        estado: firstValue(row.estado, row.clm_requersen24_estado),

        // El payload de la vista usa req_*; también aceptamos los nombres largos por compatibilidad.
        reqCodigo: firstValue(row.req_codigo, row.requerimiento_codigo, row.clm_requersen24_requerimiento_codigo),
        reqNombre: firstValue(row.req_name, row.requerimiento_name, row.clm_requersen24_requerimiento_name),
        reqMonto: firstValue(row.req_monto, row.requerimiento_monto, row.clm_requersen24_requerimiento_monto),
        reqComentario: firstValue(row.req_comentario, row.requerimiento_comentario, row.clm_requersen24_requerimiento_comentario),

        fechaRegistro: firstValue(row.fecha_registro, row.fechahora_registro, row.clm_requersen24_fechahora_registro),
        fechaUpdate: firstValue(row.fecha_update, row.datetime_update, row.clm_requersen24_datetime_update),
        usuarioRegistro: firstValue(row.usuario_registro, row.usuario_registro_nombre),
        usuarioUpdate: firstValue(row.usuario_update, row.usuario_update_nombre)
    });

    const areaLabel = (value) => {
        const raw = plain(value, '').toUpperCase().replace(/_/g, ' ');
        const labels = {
            ADMINISTRACION: 'Administración',
            ALMACEN: 'Almacén',
            CONTABILIDAD: 'Contabilidad',
            FINANZAS: 'Finanzas',
            COMBUSTIBLE: 'Combustible',
            FLOTA: 'Flota',
            MANTENIMIENTO: 'Mantenimiento',
            OPERACIONES: 'Operaciones',
            PEAJES: 'Peajes',
            'RECURSOS HUMANOS': 'Recursos Humanos',
            CALIDAD: 'Calidad',
            ENCOMIENDAS: 'Encomiendas',
            SISTEMAS: 'Sistemas',
            GERENCIA: 'Gerencia',
            LOGISTICA: 'Logística'
        };
        return labels[raw] || plain(value);
    };

    const money = (value) => {
        if (value === null || value === undefined || String(value).trim() === '') return '-';
        const number = Number(value);
        if (!Number.isFinite(number)) return '-';
        return `S/ ${number.toLocaleString('es-PE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })}`;
    };

    const formatDateTime = (value) => {
        const raw = plain(value, '');
        if (!raw) return '-';

        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (match) {
            const [, year, month, day, hh = '00', mm = '00', ss = '00'] = match;
            return `${day}/${month}/${year} ${hh}:${mm}:${ss}`;
        }
        return raw;
    };

    const nowPeru = () => new Intl.DateTimeFormat('es-PE', {
        timeZone: 'America/Lima',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    }).format(new Date()).replace(',', '');

    const safeFilename = (value) => plain(value, 'cotizacion')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9_-]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 80) || 'cotizacion';

    const jsPdfCtor = () => {
        if (window.jspdf && window.jspdf.jsPDF) return window.jspdf.jsPDF;
        if (window.jsPDF) return window.jsPDF;
        return null;
    };

    const loadImageData = async (url) => {
        const src = plain(url, '');
        if (!src) return null;

        try {
            const response = await fetch(src, { credentials: 'same-origin' });
            if (!response.ok) return null;
            const blob = await response.blob();
            const dataUrl = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });

            const dimensions = await new Promise((resolve) => {
                const img = new Image();
                img.onload = () => resolve({ width: img.naturalWidth || 1, height: img.naturalHeight || 1 });
                img.onerror = () => resolve({ width: 5, height: 1 });
                img.src = dataUrl;
            });

            return { dataUrl, ...dimensions };
        } catch (error) {
            console.warn('[N360Req24NotaPdf] No se pudo cargar el logo:', error);
            return null;
        }
    };

    const notify = async (showDialog, title, message, type = 'info') => {
        if (typeof showDialog === 'function') {
            await showDialog(title, message, type);
            return;
        }
        window.alert(`${title}: ${message}`);
    };

    const setFont = (doc, size = 8, style = 'normal', color = CFG.text) => {
        doc.setFont('helvetica', style);
        doc.setFontSize(size);
        doc.setTextColor(...color);
    };

    const splitLines = (doc, value, width, maxLines = 8) => {
        const lines = doc.splitTextToSize(plain(value), width);
        return lines.slice(0, maxLines);
    };

    const leftField = (doc, label, value, y, options = {}) => {
        const x = options.x ?? CFG.marginX;
        const width = options.width ?? (CFG.width - CFG.marginX * 2);
        const labelWidth = options.labelWidth ?? 17;
        const fontSize = options.fontSize ?? 8;
        const lineHeight = options.lineHeight ?? 4;
        const maxLines = options.maxLines ?? 5;

        setFont(doc, fontSize, 'bold');
        doc.text(`${label}:`, x, y);
        setFont(doc, fontSize, 'normal');

        const valueX = x + labelWidth;
        const lines = splitLines(doc, value, Math.max(8, width - labelWidth), maxLines);
        doc.text(lines, valueX, y);
        return y + Math.max(1, lines.length) * lineHeight;
    };

    const paragraph = (doc, label, value, y, options = {}) => {
        const x = options.x ?? CFG.marginX;
        const width = options.width ?? (CFG.width - CFG.marginX * 2);
        const fontSize = options.fontSize ?? 7.5;
        const lineHeight = options.lineHeight ?? 3.7;
        const maxLines = options.maxLines ?? 10;

        setFont(doc, fontSize, 'bold');
        doc.text(`${label}:`, x, y);
        y += lineHeight;

        setFont(doc, fontSize, 'normal');
        const lines = splitLines(doc, value, width, maxLines);
        doc.text(lines, x, y);
        return y + Math.max(1, lines.length) * lineHeight;
    };

    const banner = (doc, title, y) => {
        const x = CFG.marginX;
        const w = CFG.width - CFG.marginX * 2;
        const h = 8;
        doc.setFillColor(...CFG.navy);
        doc.setDrawColor(...CFG.navy);
        doc.rect(x, y, w, h, 'F');
        setFont(doc, 9, 'bold', [255, 255, 255]);
        doc.text(String(title).toUpperCase(), CFG.width / 2, y + 5.35, { align: 'center' });
        setFont(doc, 8, 'normal');
        return y + h;
    };

    const outlinedTitle = (doc, title, y) => {
        const x = CFG.marginX;
        const w = CFG.width - CFG.marginX * 2;
        const h = 6;
        doc.setDrawColor(...CFG.navy);
        doc.setLineWidth(0.3);
        doc.rect(x, y, w, h, 'S');
        setFont(doc, 8, 'bold');
        doc.text(String(title).toUpperCase(), CFG.width / 2, y + 4.2, { align: 'center' });
        return y + h;
    };

    const drawReqTable = (doc, data, y) => {
        const x0 = CFG.marginX;
        const widths = [20, 50];
        const headerH = 5;
        let x = x0;

        ['CAMPO', 'DETALLE'].forEach((header, index) => {
            doc.setFillColor(...CFG.softGray);
            doc.setDrawColor(...CFG.border);
            doc.setLineWidth(0.25);
            doc.rect(x, y, widths[index], headerH, 'FD');
            setFont(doc, 6.8, 'bold');
            doc.text(header, x + widths[index] / 2, y + 3.5, { align: 'center' });
            x += widths[index];
        });
        y += headerH;

        const rows = [
            ['Código cot.', plain(data.reqCodigo)],
            ['Nombre', plain(data.reqNombre)],
            ['Monto', money(data.reqMonto)],
            ['Actualizado', formatDateTime(data.fechaUpdate)],
            ['Actualizó', plain(data.usuarioUpdate)]
        ];

        rows.forEach(([label, value]) => {
            setFont(doc, 6.8, 'normal');
            const lines = splitLines(doc, value, widths[1] - 2.5, 4);
            const rowH = Math.max(5.5, lines.length * 3.3 + 1.8);

            doc.setDrawColor(...CFG.border);
            doc.setLineWidth(0.25);
            doc.rect(x0, y, widths[0], rowH, 'S');
            doc.rect(x0 + widths[0], y, widths[1], rowH, 'S');

            setFont(doc, 6.7, 'bold');
            doc.text(label, x0 + 1.2, y + 3.7);
            setFont(doc, 6.7, 'normal');
            doc.text(lines, x0 + widths[0] + 1.2, y + 3.7);
            y += rowH;
        });

        return y;
    };

    const estimateHeight = (JsPDF, data) => {
        const measure = new JsPDF({ orientation: 'portrait', unit: 'mm', format: [CFG.width, 250] });
        setFont(measure, 7.5, 'normal');
        const fullWidth = CFG.width - CFG.marginX * 2;

        const commentLines = splitLines(measure, data.comentario, fullWidth, 10).length;
        const reqCommentLines = splitLines(measure, data.reqComentario, fullWidth, 12).length;
        const reqNameLines = splitLines(measure, data.reqNombre, 47.5, 4).length;
        const userUpdateLines = splitLines(measure, data.usuarioUpdate, 47.5, 4).length;

        // 120 mm es la altura base de la Nota de Abastecimiento. Solo crece cuando el contenido lo exige.
        const extra = Math.max(0, commentLines - 1) * 3.7
            + Math.max(0, reqCommentLines - 1) * 3.7
            + Math.max(0, reqNameLines - 1) * 3.3
            + Math.max(0, userUpdateLines - 1) * 3.3;

        return Math.min(CFG.maxHeight, Math.max(CFG.minHeight, 132 + extra));
    };

    const drawFooter = (doc, pageHeight, options = {}) => {
        const x = CFG.marginX;
        const w = CFG.width - CFG.marginX * 2;
        const footerY = pageHeight - 12;
        const ruleY = footerY - 1.5;

        doc.setDrawColor(...CFG.gray);
        doc.setLineWidth(0.3);
        doc.line(x, ruleY, x + w, ruleY);

        const usuario = plain(options.pdfUser || options.usuario || options.user, 'Usuario desconocido');
        const dni = plain(options.pdfDni || options.dni || options.userDni, 'DNI desconocido');
        const footerText = `Impresión: ${nowPeru()} | Usuario: ${usuario} | DNI: ${dni}`;

        let footerFont = 5.7;
        setFont(doc, footerFont, 'italic', CFG.gray);
        while (doc.getTextWidth(footerText) > w && footerFont > 4.4) {
            footerFont -= 0.2;
            setFont(doc, footerFont, 'italic', CFG.gray);
        }
        doc.text(footerText, CFG.width / 2, footerY + 2.2, { align: 'center' });

        const label = 'NORTE 360';
        setFont(doc, 5, 'bold', [255, 255, 255]);
        const chipW = doc.getTextWidth(label) + 6;
        const chipH = 3.5;
        const chipX = (CFG.width - chipW) / 2;
        const chipY = footerY + 4.6;

        doc.setFillColor(...CFG.navy);
        doc.setDrawColor(...CFG.navy);
        doc.rect(chipX, chipY, chipW, chipH, 'F');
        doc.text(label, CFG.width / 2, chipY + 2.55, { align: 'center' });
        setFont(doc, 8, 'normal');
    };

    const download = async (row, options = {}) => {
        const showDialog = options.showDialog;
        if (!row) {
            await notify(showDialog, 'PDF nota', 'Primero abre el detalle de una cotización.', 'warning');
            return;
        }

        const JsPDF = jsPdfCtor();
        if (!JsPDF) {
            await notify(showDialog, 'PDF nota', 'No se encontró jsPDF cargado en la página.', 'error');
            return;
        }

        const data = normalizeRow(row);
        const pageHeight = estimateHeight(JsPDF, data);
        const doc = new JsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: [CFG.width, pageHeight]
        });

        const logo = await loadImageData(options.logoUrl);
        if (logo) {
            try {
                const logoW = 60;
                const logoH = Math.min(17, Math.max(7, logoW * (logo.height / logo.width)));
                doc.addImage(logo.dataUrl, 'PNG', 10, 4, logoW, logoH, undefined, 'FAST');
            } catch (error) {
                console.warn('[N360Req24NotaPdf] No se pudo insertar el logo:', error);
            }
        }

        let y = 25;
        const ruc = plain(options.ruc, '');
        if (ruc) {
            setFont(doc, 8, 'bold');
            doc.text(`RUC: ${ruc}`, CFG.marginX, y);
            y += 5;
        }

        setFont(doc, 8, 'bold');
        doc.text(`Fecha de Emisión: ${formatDateTime(data.fechaRegistro)}`, CFG.marginX, y);
        y += 8;

        setFont(doc, 8, 'bold');
        doc.text('CONTROL INTERNO', CFG.width / 2, y, { align: 'center' });
        y += 4;

        y = banner(doc, 'NOTA DE REQUERIMIENTO Y COTIZACIÓN', y) + 5;

        setFont(doc, 8.3, 'bold');
        doc.text(plain(data.codigo), CFG.marginX, y);
        y += 4.5;

        y = leftField(doc, 'Área', areaLabel(data.area), y, { labelWidth: 15, lineHeight: 4 });
        y = leftField(doc, 'Estado', data.estado, y, { labelWidth: 15, lineHeight: 4 });
        y += 1.5;

        // Datos de la cotización: mismo enfoque simple y compacto de la Nota de Abastecimiento.
        y = leftField(doc, 'Requerimiento', data.cotizacion, y, { labelWidth: 21, lineHeight: 4 });
        y = leftField(doc, 'Solicitante', data.solicitante, y, { labelWidth: 21, lineHeight: 4 });
        y = leftField(doc, 'Cargo', data.cargo, y, { labelWidth: 21, lineHeight: 4 });
        y = paragraph(doc, 'Comentario', data.comentario, y + 1, { maxLines: 10 }) + 3;

        // Metadatos de creación, para que no se pierda quién registró originalmente la cotización.
        y = leftField(doc, 'Registrado', formatDateTime(data.fechaRegistro), y, { labelWidth: 21, fontSize: 7, lineHeight: 3.6 });
        y = leftField(doc, 'Registró', data.usuarioRegistro, y, { labelWidth: 21, fontSize: 7, lineHeight: 3.6 });
        y += 3;

        y = outlinedTitle(doc, 'COTIZACIÓN', y) + 4;
        y = drawReqTable(doc, data, y) + 3;
        y = paragraph(doc, 'Comentario de la cotización', data.reqComentario, y, { maxLines: 12 });

        // Separador igual al que precede al pie en la Nota de Abastecimiento.
        const footerTop = pageHeight - 15;
        if (y + 3 < footerTop) {
            doc.setDrawColor(...CFG.gray);
            doc.setLineWidth(0.3);
            doc.line(10, Math.min(y + 3, footerTop - 1), 70, Math.min(y + 3, footerTop - 1));
        }

        drawFooter(doc, pageHeight, options);
        doc.save(`nota_${safeFilename(data.codigo)}.pdf`);
    };

    window.N360Req24NotaPdf = { download };
})();
