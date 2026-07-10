(function (window) {
    'use strict';

    function common() {
        if (!window.N360NotasCommon) {
            throw new Error('N360NotasCommon no esta cargado.');
        }
        return window.N360NotasCommon;
    }

    function normalize(cfg, data) {
        const C = common();
        return C.baseNote(cfg, data, {
            kind: 'CM',
            series: 'CM',
            correlativo: '',
            module: 'Combustible',
            pointLabel: 'Punto de Despacho',
            space: '',
            unitText: '',
            actorLabel: 'Conductor',
            actor: '',
            reason: '',
            total: '',
            withAmounts: true,
            fileName: 'norte360_formato_CM_tanqueada_combustible.pdf'
        });
    }

    function drawTable(doc, note, y) {
        const C = common();
        const x0 = 5;
        const widths = [10, 8, 32, 10, 12];
        let x = x0;

        ['Cant.', 'Un.', 'Descripci\u00f3n del Producto', 'P.Unit', 'Importe'].forEach((header, index) => {
            C.drawCell(doc, x, y, widths[index], 5, header, {
                fill: C.HEADER_FILL,
                bold: true,
                size: 6.1,
                align: 'center',
                middle: true
            });
            x += widths[index];
        });
        y += 5;

        (note.products || []).forEach(product => {
            const rowH = Math.max(3.5, doc.splitTextToSize(C.safe(product.description), 30).length * 3.5);
            x = x0;
            C.drawCell(doc, x, y, widths[0], rowH, product.qty, {align: 'center', middle: true, wrap: false}); x += widths[0];
            C.drawCell(doc, x, y, widths[1], rowH, product.unit, {align: 'center', middle: true, wrap: false}); x += widths[1];
            C.drawCell(doc, x, y, widths[2], rowH, product.description); x += widths[2];
            C.drawCell(doc, x, y, widths[3], rowH, product.unitPrice, {align: 'center', middle: true, wrap: false}); x += widths[3];
            C.drawCell(doc, x, y, widths[4], rowH, product.amount, {align: 'center', middle: true, wrap: false});
            y += rowH;
        });

        return y + C.AFTER_TABLE_GAP;
    }

    function draw(doc, note, cfg) {
        const C = common();
        C.drawLogo(doc, cfg.assets && cfg.assets.logo, 5);

        let y = 22;
        y = C.leftLine(doc, `RUC: ${note.ruc}`, y, {bold: true, size: 8, lineHeight: 5});
        y = C.leftLine(doc, `Fecha de Emisi\u00f3n: ${note.fecha} ${note.hora}`, y, {bold: true, size: 8, lineHeight: 5});
        y += 5;

        y = C.centerLine(doc, 'ORDENES DE SUMINISTRO DE COMBUSTIBLE', y, 8, true) + 5;
        y = C.drawBanner(doc, y, 'VALE DE COMBUSTIBLE - SALIDA') + 5;

        y = C.leftLine(doc, `${note.series} - ${C.pad4(note.correlativo)}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `\u00c1rea: ${note.module}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `${note.pointLabel}: ${note.space}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `Unidad con Placa: ${note.unitText}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `${note.actorLabel}: ${note.actor}`, y, {size: 8, lineHeight: 4});
        y = C.leftLine(doc, `Motivo: ${note.reason}`, y, {size: 8, width: 60, lineHeight: 4}) + 6;

        y = drawTable(doc, note, y);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        C.text(doc, `TOTAL: ${note.total || ''}`, 70, y + 1.5, {align: 'right'});
        y += 8;

        y = C.leftLine(doc, `Responsable de Despacho: ${note.responsible}`, y, {size: 8, lineHeight: 4});
        y = C.leftLine(doc, `DNI: ${note.dni}`, y, {size: 8, lineHeight: 4});

        doc.setDrawColor(90, 90, 90);
        doc.setLineWidth(0.3);
        doc.line(10, y + C.RULE_AFTER_INFO_GAP, 70, y + C.RULE_AFTER_INFO_GAP);
        C.drawFooter(doc, note, cfg);
    }

    async function generate(options) {
        const C = common();
        const cfg = await C.withLogo(options || {});
        const note = normalize(cfg, cfg.noteData || {});
        const doc = C.createDocument(note, C.estimateSalidaHeight);
        draw(doc, note, cfg);
        if (cfg.save !== false) {
            doc.save(note.fileName);
        }
        return doc;
    }

    async function generateDemo(options) {
        if (!window.N360NotasDemoData) {
            throw new Error('N360NotasDemoData no esta cargado.');
        }

        const cfg = Object.assign({}, options || {}, {
            noteData: window.N360NotasDemoData.get('CM', options || {})
        });
        return generate(cfg);
    }

    window.N360NotaTanqueada = {
        generate,
        generateDemo
    };
})(window);
