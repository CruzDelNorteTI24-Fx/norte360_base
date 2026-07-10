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
            kind: 'NS',
            series: 'NS',
            correlativo: '',
            module: 'Almac\u00e9n',
            pointLabel: 'Punto de Recepci\u00f3n',
            space: '',
            unitText: '',
            actorLabel: 'Entregado a',
            actor: '',
            reason: '',
            withAmounts: false,
            fileName: 'norte360_formato_NS_nota_salida.pdf'
        });
    }

    function drawTable(doc, note, y) {
        const C = common();
        const x0 = 10;
        const widths = [8, 58];

        C.drawCell(doc, x0, y, widths[0], 5, 'Cant.', {
            fill: C.HEADER_FILL,
            bold: true,
            size: 6.4,
            align: 'center',
            middle: true
        });
        C.drawCell(doc, x0 + widths[0], y, widths[1], 5, 'Descripci\u00f3n del Producto', {
            fill: C.HEADER_FILL,
            bold: true,
            size: 6.4,
            align: 'center',
            middle: true
        });
        y += 5;

        (note.products || []).forEach(product => {
            const rowH = Math.max(3.5, doc.splitTextToSize(C.safe(product.description), 56).length * 3.5);
            C.drawCell(doc, x0, y, widths[0], rowH, product.qty, {align: 'center', middle: true, wrap: false});
            C.drawCell(doc, x0 + widths[0], y, widths[1], rowH, product.description);
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

        y = C.centerLine(doc, 'NOTA DE SALIDA DE MATERIALES AUXILIARES,', y, 8, true);
        y = C.centerLine(doc, 'SUMINISTROS Y REPUESTOS DE ALMACEN', y + 1.5, 8, true) + 6;

        y = C.leftLine(doc, `${note.series} - ${C.pad4(note.correlativo)}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `\u00c1rea: ${note.module}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `${note.pointLabel}: ${note.space}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `Unidad con Placa: ${note.unitText}`, y, {size: 8, lineHeight: 5});
        y = C.leftLine(doc, `${note.actorLabel}: ${note.actor}`, y, {size: 8, lineHeight: 4});
        y = C.leftLine(doc, `Motivo: ${note.reason}`, y, {size: 8, width: 60, lineHeight: 4}) + 6;

        y = drawTable(doc, note, y);
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
            noteData: window.N360NotasDemoData.get('NS', options || {})
        });
        return generate(cfg);
    }

    window.N360NotaSalidaAlmacen = {
        generate,
        generateDemo
    };
})(window);
