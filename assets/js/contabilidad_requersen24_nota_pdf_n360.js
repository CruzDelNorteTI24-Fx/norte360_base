(function () {
    const noteConfig = {
        width: 226.77,
        height: 640,
        margin: 18,
        navy: [18, 49, 76],
        blue: [34, 145, 213],
        softBlue: [236, 246, 255],
        border: [190, 211, 229],
        text: [9, 31, 52],
        muted: [88, 111, 135]
    };

    const plain = (value, fallback = '-') => {
        if (value === null || value === undefined) return fallback;
        const text = String(value).trim();
        return text === '' ? fallback : text;
    };

    const areaPlainLabel = (value) => {
        const raw = plain(value, '').toUpperCase().replace(/_/g, ' ');
        const labels = {
            ADMINISTRACION: 'Administracion',
            ALMACEN: 'Almacen',
            CONTABILIDAD: 'Contabilidad',
            COMBUSTIBLE: 'Combustible',
            FLOTA: 'Flota',
            MANTENIMIENTO: 'Mantenimiento',
            OPERACIONES: 'Operaciones',
            PEAJES: 'Peajes',
            'RECURSOS HUMANOS': 'Recursos Humanos',
            RECURSOS_HUMANOS: 'Recursos Humanos',
            CALIDAD: 'Calidad',
            ENCOMIENDAS: 'Encomiendas',
            SISTEMAS: 'Sistemas',
            GERENCIA: 'Gerencia',
            LOGISTICA: 'Logistica'
        };
        return labels[raw] || plain(value);
    };

    const money = (value) => {
        const number = Number(value || 0);
        if (!Number.isFinite(number) || number <= 0) return '-';
        return `S/ ${number.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

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

    const notify = async (showDialog, title, message, type = 'info') => {
        if (typeof showDialog === 'function') {
            await showDialog(title, message, type);
            return;
        }
        window.alert(`${title}: ${message}`);
    };

    const setText = (doc, size, style = 'normal', color = noteConfig.text) => {
        doc.setFont('helvetica', style);
        doc.setFontSize(size);
        doc.setTextColor(...color);
    };

    const box = (doc, x, y, w, h, fill = [248, 251, 254]) => {
        doc.setFillColor(...fill);
        doc.setDrawColor(...noteConfig.border);
        doc.roundedRect(x, y, w, h, 4, 4, 'FD');
    };

    const labelValue = (doc, label, value, x, y, w, h = 34) => {
        box(doc, x, y, w, h);
        setText(doc, 6.5, 'bold', noteConfig.muted);
        doc.text(String(label).toUpperCase(), x + 7, y + 11);
        setText(doc, 9.5, 'bold');
        const lines = doc.splitTextToSize(plain(value), w - 14);
        doc.text(lines.slice(0, 2), x + 7, y + 24);
    };

    const sectionTitle = (doc, title, x, y, w) => {
        doc.setFillColor(...noteConfig.navy);
        doc.roundedRect(x, y, w, 20, 4, 4, 'F');
        setText(doc, 8.5, 'bold', [255, 255, 255]);
        doc.text(String(title).toUpperCase(), x + 8, y + 13.5);
    };

    const writeParagraph = (doc, value, x, y, w, maxLines = 4) => {
        setText(doc, 8.2, 'normal');
        const lines = doc.splitTextToSize(plain(value), w);
        doc.text(lines.slice(0, maxLines), x, y);
        return y + Math.min(lines.length, maxLines) * 10;
    };

    const download = async (row, options = {}) => {
        const showDialog = options.showDialog;
        if (!row) {
            await notify(showDialog, 'PDF nota', 'Primero abre el detalle de una cotizacion.', 'warning');
            return;
        }

        const JsPDF = jsPdfCtor();
        if (!JsPDF) {
            await notify(showDialog, 'PDF nota', 'No se encontro jsPDF cargado en la pagina.', 'error');
            return;
        }

        const cfg = noteConfig;
        const doc = new JsPDF({ orientation: 'portrait', unit: 'pt', format: [cfg.width, cfg.height] });
        const x = cfg.margin;
        const w = cfg.width - cfg.margin * 2;
        let y = 18;

        doc.setFillColor(...cfg.navy);
        doc.roundedRect(x, y, w, 58, 7, 7, 'F');
        setText(doc, 7.5, 'bold', [190, 220, 245]);
        doc.text('NORTE 360', x + 10, y + 17);
        setText(doc, 13, 'bold', [255, 255, 255]);
        doc.text('Nota de cotizacion', x + 10, y + 34);
        setText(doc, 7, 'normal', [218, 232, 244]);
        doc.text('Seguimiento interno de requerimientos', x + 10, y + 47);
        setText(doc, 8, 'bold', [255, 255, 255]);
        doc.text(plain(row.codigo_interno), x + w - 10, y + 34, { align: 'right' });
        y += 74;

        labelValue(doc, 'Estado', row.estado, x, y, 58);
        labelValue(doc, 'Area', areaPlainLabel(row.area), x + 64, y, 70);
        labelValue(doc, 'Registro', row.fechahora_registro, x + 140, y, w - 140);
        y += 46;

        sectionTitle(doc, 'Datos de cotizacion', x, y, w);
        y += 31;
        labelValue(doc, 'Cotizacion', row.cotizacion, x, y, 78);
        labelValue(doc, 'Solicitante', row.solicitante, x + 84, y, w - 84);
        y += 45;
        labelValue(doc, 'Cargo', row.cargo, x, y, w, 32);
        y += 47;
        setText(doc, 6.5, 'bold', cfg.muted);
        doc.text('COMENTARIO DE COTIZACION', x, y);
        y += 12;
        y = writeParagraph(doc, row.comentario, x, y, w, 5) + 12;

        sectionTitle(doc, 'Requerimiento', x, y, w);
        y += 31;
        labelValue(doc, 'Codigo req.', row.requerimiento_codigo, x, y, 74);
        labelValue(doc, 'Monto', money(row.requerimiento_monto), x + 80, y, 62);
        labelValue(doc, 'Actualizado', row.datetime_update || '-', x + 148, y, w - 148);
        y += 45;
        labelValue(doc, 'Nombre', row.requerimiento_name, x, y, w, 36);
        y += 51;
        setText(doc, 6.5, 'bold', cfg.muted);
        doc.text('COMENTARIO DEL REQUERIMIENTO', x, y);
        y += 12;
        y = writeParagraph(doc, row.requerimiento_comentario, x, y, w, 6) + 8;

        const footerY = cfg.height - 44;
        doc.setDrawColor(...cfg.border);
        doc.line(x, footerY, x + w, footerY);
        setText(doc, 6.5, 'italic', cfg.muted);
        const generated = new Date().toLocaleString('es-PE');
        doc.text(`Generado: ${generated}`, x, footerY + 14);
        doc.text('Norte360 - ERP Operativo de Transporte', x + w, footerY + 14, { align: 'right' });
        doc.setFillColor(...cfg.navy);
        doc.roundedRect(x + 58, footerY + 23, 74, 14, 2, 2, 'F');
        setText(doc, 6.5, 'bold', [255, 255, 255]);
        doc.text('CONTROL INTERNO', x + 95, footerY + 32, { align: 'center' });

        doc.save(`nota_${safeFilename(row.codigo_interno)}.pdf`);
    };

    window.N360Req24NotaPdf = { download };
})();