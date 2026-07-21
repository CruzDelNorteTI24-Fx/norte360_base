(function (window) {
    'use strict';

    function pdfApi() {
        if (!window.N360PDF) {
            throw new Error('N360PDF no esta cargado.');
        }
        return window.N360PDF;
    }

    function cfg(options) {
        return Object.assign({}, window.N360_RRHH_ACTA_PDF_CONFIG || {}, options || {});
    }

    function safe(value) {
        return String(value === undefined || value === null ? '' : value).trim();
    }

    function money(value) {
        const n = Number(String(value || 0).replace(',', '.'));
        return 'S/ ' + (Number.isFinite(n) ? n : 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function check(doc, x, y, checked) {
        doc.rect(x, y - 3.2, 3.4, 3.4);
        if (checked) {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(7);
            doc.text('X', x + 0.7, y - 0.55);
        }
    }

    function line(doc, value, x, y, width, opts) {
        const o = opts || {};
        doc.setFont('helvetica', o.bold ? 'bold' : 'normal');
        doc.setFontSize(o.size || 8);
        doc.setTextColor(0, 0, 0);
        const lines = doc.splitTextToSize(safe(value), width || 170);
        doc.text(lines, x, y);
        return y + lines.length * (o.lineHeight || 4);
    }

    function cell(doc, x, y, w, h, value, opts) {
        const o = opts || {};
        doc.setDrawColor(30, 41, 59);
        doc.setLineWidth(0.18);
        if (o.fill) {
            doc.setFillColor(o.fill[0], o.fill[1], o.fill[2]);
            doc.rect(x, y, w, h, 'FD');
        } else {
            doc.rect(x, y, w, h);
        }
        doc.setFont('helvetica', o.bold ? 'bold' : 'normal');
        doc.setFontSize(o.size || 7);
        const lines = doc.splitTextToSize(safe(value), Math.max(2, w - 3)).slice(0, Math.max(1, Math.floor(h / 3.5)));
        const tx = o.align === 'center' ? x + w / 2 : x + 1.4;
        const ty = y + (o.middle ? h / 2 + 1.1 : 3.8);
        doc.text(lines, tx, ty, o.align === 'center' ? { align: 'center' } : {});
    }

    function sectionHeader(doc, x, y, w, label) {
        cell(doc, x, y, w, 6, label, { fill: [226, 232, 240], bold: true, size: 7.2 });
        return y + 6;
    }

    function drawOptions(doc, data, x, y) {
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7);
        doc.text('AREA:', x + 1.5, y);
        const areas = [
            ['COUNTER H.', 'COUNTER_H'],
            ['COUNTER M.', 'COUNTER_M'],
            ['CONDUCTOR', 'CONDUCTOR'],
            ['OFICINA', 'OFICINA']
        ];
        let cx = x + 18;
        areas.forEach(([label, value]) => {
            doc.text(label, cx, y);
            check(doc, cx + doc.getTextWidth(label) + 2, y, data.area === value);
            cx += value === 'CONDUCTOR' ? 32 : 35;
        });
        return y + 10;
    }

    function drawMotivos(doc, data, x, y) {
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7);
        doc.text('Motivo de la Entrega:', x + 1.5, y);
        const opts = [
            ['Inicio de Contrato/Cortesia', 'INICIO_CONTRATO_CORTESIA'],
            ['Reposicion/desgaste', 'REPOSICION_DESGASTE'],
            ['Perdida/Robo', 'PERDIDA_ROBO'],
            ['Compra', 'COMPRA']
        ];
        let cx = x + 38;
        opts.forEach(([label, value]) => {
            doc.text(label, cx, y);
            check(doc, cx + doc.getTextWidth(label) + 2, y, data.motivoActa === value);
            cx += value === 'INICIO_CONTRATO_CORTESIA' ? 48 : 39;
        });
        return y + 8;
    }

    function drawContent(doc, config) {
        const data = config.actaData || {};
        const actaCodigo = safe(data.actaCodigo || (data.id ? `RA-${String(data.id).padStart(4, '0')}` : ''));
        const notaCodigo = safe(data.notaCodigo);
        const x = 13;
        const w = 184;
        let y = 35;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.text('ACTA DE ENTREGA DE UNIFORMES', 105, y, { align: 'center' });
        doc.line(70, y + 1.5, 140, y + 1.5);
        doc.setFontSize(7);
        doc.text(`Acta: ${actaCodigo || '-'}${notaCodigo ? ' | Nota referenciada: ' + notaCodigo : ''}`, 105, y + 6, { align: 'center' });
        y += 16;

        y = line(doc, `Se deja constancia que la Empresa TRANSPORTE CRUZ DEL NORTE SAC, con RUC N. 20403002101 hace entrega a: ${safe(data.trabajadorNombre).toUpperCase()}, con DNI N. ${safe(data.trabajadorDni)}, los siguientes UNIFORMES que seran utilizados unica y exclusivamente para el desarrollo de operaciones.`, x, y, w, { size: 7.3, lineHeight: 3.6 });
        y += 4;

        y = sectionHeader(doc, x, y, w, '1. DATOS DEL PERSONAL');
        cell(doc, x, y, 112, 7, `Nombre y Apellido: ${safe(data.trabajadorNombre).toUpperCase()}`, { bold: true, size: 6.8 });
        cell(doc, x + 112, y, 72, 7, `DNI/CE: ${safe(data.trabajadorDni)}`, { bold: true, size: 6.8 });
        y += 7;
        cell(doc, x, y, w, 10, '', {});
        y = drawOptions(doc, data, x, y + 6);
        cell(doc, x, y, 82, 8, 'POSICION: _______________________', { bold: true, size: 6.8 });
        cell(doc, x + 82, y, 51, 8, 'Part Time', { bold: true, size: 6.8 });
        check(doc, x + 111, y + 4.6, data.posicion === 'PART_TIME');
        cell(doc, x + 133, y, 51, 8, 'Full Time', { bold: true, size: 6.8 });
        check(doc, x + 162, y + 4.6, data.posicion === 'FULL_TIME');
        y += 8;
        cell(doc, x, y, w, 9, '', {});
        y = drawMotivos(doc, data, x, y + 6);
        cell(doc, x, y, w, 7, `Fecha de entrega: ${safe(data.fechaEntregaLabel)}    Acta: ${actaCodigo || '-'}    Nota referenciada: ${notaCodigo || '-'}`, { bold: true, size: 6.8 });
        y += 16;

        const cols = [16, 24, 72, 18, 24, 30];
        const headers = ['ITEM', 'CANTIDAD', 'DETALLE', 'TALLA', 'VALOR', 'OBSERVACIONES'];
        let cx = x;
        headers.forEach((h, i) => {
            cell(doc, cx, y, cols[i], 8, h, { fill: [226, 232, 240], bold: true, align: 'center', middle: true, size: 6.7 });
            cx += cols[i];
        });
        y += 8;
        const rows = (data.items || []).length ? data.items : [{ item: 1, cantidad: '', detalle: '', talla: '', valor: '', observaciones: '' }];
        rows.slice(0, 12).forEach((item, idx) => {
            const rh = Math.max(7, doc.splitTextToSize(safe(item.detalle), cols[2] - 3).length * 3.8);
            cx = x;
            [idx + 1, item.cantidad, item.detalle, item.talla || '', item.valor, item.observaciones || ''].forEach((v, i) => {
                cell(doc, cx, y, cols[i], rh, i === 4 && v !== '' ? money(v) : v, { align: i === 2 || i === 5 ? 'left' : 'center', middle: i !== 2 && i !== 5, size: 6.6 });
                cx += cols[i];
            });
            y += rh;
        });

        cell(doc, x, y, w, 8, '', {});
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7);
        doc.text(`Total, ${money(data.total)}  se descontara`, x + 2, y + 5.2);
        check(doc, x + 68, y + 5.2, Number(data.descuenta || 0) === 1);
        doc.text(`${safe(data.cuotas || 1)} cuota(s) a partir del ${safe(data.fechaDescuento || '__ / __ / ____')}`, x + 75, y + 5.2);
        y += 10;

        y = sectionHeader(doc, x, y, w, 'OBSERVACIONES');
        cell(doc, x, y, w, 15, safe(data.observaciones), { size: 6.8 });
        y += 15;

        cell(doc, x, y, w / 2, 7, 'RECIBE', { fill: [226, 232, 240], bold: true, align: 'center', middle: true, size: 6.8 });
        cell(doc, x + w / 2, y, w / 2, 7, 'ENTREGA', { fill: [226, 232, 240], bold: true, align: 'center', middle: true, size: 6.8 });
        y += 7;
        cell(doc, x, y, w / 2, 12, `Nombres: ${safe(data.recibe && data.recibe.nombre).toUpperCase()}`, { size: 6.7 });
        cell(doc, x + w / 2, y, w / 2, 12, `Nombre: ${safe(data.entrega && data.entrega.nombre).toUpperCase()}`, { size: 6.7 });
        y += 12;
        cell(doc, x, y, w / 2, 12, 'Firma y Huella', { bold: true, size: 6.7 });
        cell(doc, x + w / 2, y, w / 2, 12, 'Firma:', { bold: true, size: 6.7 });
        y += 12;
        cell(doc, x, y, 55, 8, `Cargo: ${safe(data.recibe && data.recibe.cargo).toUpperCase()}`, { bold: true, size: 6.5 });
        cell(doc, x + 55, y, 37, 8, `DNI: ${safe(data.recibe && data.recibe.dni)}`, { bold: true, size: 6.5 });
        cell(doc, x + w / 2, y, 55, 8, `Cargo: ${safe(data.entrega && data.entrega.cargo).toUpperCase()}`, { bold: true, size: 6.5 });
        cell(doc, x + w / 2 + 55, y, 37, 8, `DNI: ${safe(data.entrega && data.entrega.dni)}`, { bold: true, size: 6.5 });
        y += 12;
        line(doc, 'El uniforme sera entregado a la empresa el dia que finalice su vinculo laboral.', x, y, w, { size: 7, lineHeight: 4 });
    }

    async function generate(options) {
        const config = cfg(options);
        if (!config.actaData) {
            throw new Error('No se recibio data del acta.');
        }
        const doc = await pdfApi().createDocument(Object.assign({
            orientation: 'portrait',
            useCover: false,
            title: 'ACTA DE ENTREGA DE UNIFORMES',
            secondTitle: '',
            docCode: 'RRHH-ACTA-UNI',
            content: drawContent
        }, config));
        if (config.save !== false) {
            doc.save(config.actaData.fileName || 'acta_entrega_uniformes.pdf');
        }
        return doc;
    }

    async function fetchActa(idActa) {
        const config = cfg();
        const endpoint = config.endpoint || 'php/rrhh_acta_uniforme_data.php';
        const res = await fetch(`${endpoint}?id_acta=${encodeURIComponent(idActa)}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.message || 'No se pudo obtener el acta.');
        }
        return data.actaData;
    }

    async function downloadByActaId(idActa) {
        const actaData = await fetchActa(idActa);
        return generate({ actaData });
    }

    window.N360ActaUniformes = {
        generate,
        fetchActa,
        downloadByActaId
    };
})(window);
