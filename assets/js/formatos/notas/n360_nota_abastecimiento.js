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
            kind: 'AB',
            series: 'AB',
            correlativo: '',
            module: 'Combustible',
            pointLabel: 'Punto de Recepci\u00f3n',
            space: '',
            providerLabel: 'Suministrador',
            provider: '',
            reason: '',
            withAmounts: true,
            fileName: 'norte360_formato_AB_abastecimiento_combustible.pdf'
        });
    }

    function drawTable(doc, note, y) {
        const C = common();
        const x0 = 5;
        const widths = [10, 36, 12, 12];
        const product = (note.products && note.products[0]) || {};
        let x = x0;

        ['Cant.', 'Descripci\u00f3n', 'P.Unit', 'Importe'].forEach((header, index) => {
            C.drawCell(doc, x, y, widths[index], 5, header, {
                fill: C.HEADER_FILL,
                bold: true,
                size: 7,
                align: 'center',
                middle: true
            });
            x += widths[index];
        });
        y += 5;

        const rowH = Math.max(3.5, doc.splitTextToSize(C.safe(product.description), 34).length * 3.5);
        x = x0;
        C.drawCell(doc, x, y, widths[0], rowH, product.qty, {align: 'center', middle: true, wrap: false}); x += widths[0];
        C.drawCell(doc, x, y, widths[1], rowH, product.description); x += widths[1];
        C.drawCell(doc, x, y, widths[2], rowH, product.unitPrice, {align: 'center', middle: true, wrap: false}); x += widths[2];
        C.drawCell(doc, x, y, widths[3], rowH, product.amount, {align: 'center', middle: true, wrap: false});

        return y + rowH + C.AFTER_TABLE_GAP;
    }

    function draw(doc, note, cfg) {
        const C = common();
        C.drawLogo(doc, cfg.assets && cfg.assets.logo, 4);

        let y = 25;
        y = C.leftLine(doc, `RUC: ${note.ruc}`, y, {bold: true, size: 8, lineHeight: 5});
        y = C.leftLine(doc, `Fecha de Emisi\u00f3n: ${note.fecha} ${note.hora}`, y, {bold: true, size: 8, lineHeight: 5});
        y += 3;

        y = C.centerLine(doc, 'ORDENES DE SUMINISTRO DE COMBUSTIBLE', y, 8, true) + 4;
        y = C.drawBanner(doc, y, 'ABASTECIMIENTO - ENTRADA') + 5;
        y = C.leftLine(doc, `${note.series}-${C.pad4(note.correlativo)}`, y, {size: 8, lineHeight: 4});

        if (note.pointLabel || note.space) {
            y = C.leftLine(doc, `${note.pointLabel}: ${note.space}`, y, {size: 8, lineHeight: 4});
        }
        if (note.providerLabel || note.provider) {
            y = C.leftLine(doc, `${note.providerLabel}: ${note.provider}`, y, {size: 8, lineHeight: 4});
        }

        y = C.leftLine(doc, `Motivo: ${note.reason}`, y, {size: 8, width: 60, lineHeight: 4}) + 6;
        y = drawTable(doc, note, y);
        y = C.leftLine(doc, `Responsable: ${note.responsible}`, y, {size: 7.4, lineHeight: 4});
        y = C.leftLine(doc, `DNI: ${note.dni}`, y, {size: 7.4, lineHeight: 4});

        doc.setDrawColor(90, 90, 90);
        doc.setLineWidth(0.3);
        doc.line(10, y + C.RULE_AFTER_INFO_GAP, 70, y + C.RULE_AFTER_INFO_GAP);
        C.drawFooter(doc, note, cfg);
    }

    async function generate(options) {
        const C = common();
        const cfg = await C.withLogo(options || {});
        const note = normalize(cfg, cfg.noteData || {});
        const doc = C.createDocument(note, C.estimateEntradaHeight);
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
            noteData: window.N360NotasDemoData.get('AB', options || {})
        });
        return generate(cfg);
    }

    window.N360NotaAbastecimiento = {
        generate,
        generateDemo
    };
})(window);
