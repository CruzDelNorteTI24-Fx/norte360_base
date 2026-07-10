(function (window) {
    'use strict';

    const BLUE = [52, 73, 94];
    const GRID = [52, 73, 94];
    const HEADER_FILL = [230, 230, 230];
    const AFTER_TABLE_GAP = 8.5;
    const RULE_AFTER_INFO_GAP = 8;

    function jsPDFCtor() {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            throw new Error('jsPDF no esta cargado. Revisa el script CDN de jsPDF.');
        }
        return window.jspdf.jsPDF;
    }

    function loadImage(src) {
        if (window.N360PDF && typeof window.N360PDF.loadImage === 'function') {
            return window.N360PDF.loadImage(src);
        }
        return Promise.resolve(null);
    }

    function safe(value) {
        return String(value === undefined || value === null ? '' : value).trim();
    }

    function pad4(value) {
        return String(value || '0').padStart(4, '0');
    }

    function nowParts() {
        const date = new Date();
        const pad = n => String(n).padStart(2, '0');
        return {
            fecha: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
            hora: `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`,
            impreso: `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
        };
    }

    function countLines(text, maxChars) {
        const value = safe(text);
        if (!value) return 1;
        return value.split(/\s+/).reduce((lines, word) => {
            const last = lines[lines.length - 1] || '';
            const test = `${last} ${word}`.trim();
            if (test.length <= maxChars) {
                lines[lines.length - 1] = test;
            } else {
                lines.push(word);
            }
            return lines;
        }, ['']).length;
    }

    function estimateHeight(note) {
        const descChars = note.withAmounts ? 24 : 42;
        const rowHeight = (note.products || []).reduce((sum, product) => {
            return sum + (countLines(product.description, descChars) * 3.5);
        }, 0);
        const baseTableHeight = 35;
        return 170 + Math.max(0, rowHeight - baseTableHeight) + 4;
    }

    function createDocument(note) {
        const jsPDF = jsPDFCtor();
        return new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: [80, estimateHeight(note)],
            compress: true
        });
    }

    function text(doc, value, x, y, options) {
        doc.text(String(value || ''), x, y, options || {});
    }

    function logoHeight(logo, width) {
        if (!logo || !logo.width || !logo.height) return 7;
        return Math.min(12, Math.max(7, width * (logo.height / logo.width)));
    }

    function drawLogo(doc, logo) {
        if (!logo) return;
        const w = 60;
        const h = logoHeight(logo, w);
        try {
            doc.addImage(logo.data, logo.type, 10, 5, w, h, undefined, 'FAST');
        } catch (err) {}
    }

    function centerLine(doc, value, y, size, bold) {
        doc.setFont('helvetica', bold ? 'bold' : 'normal');
        doc.setFontSize(size);
        const lines = doc.splitTextToSize(safe(value), 60);
        doc.text(lines, 40, y, {align: 'center'});
        return y + lines.length * 4.2;
    }

    function leftLine(doc, value, y, opts) {
        const config = opts || {};
        doc.setFont('helvetica', config.bold ? 'bold' : 'normal');
        doc.setFontSize(config.size || 8);
        const lines = doc.splitTextToSize(safe(value), config.width || 60);
        doc.text(lines, config.x || 10, y);
        return y + lines.length * (config.lineHeight || 4);
    }

    function drawBanner(doc, y, label) {
        const x = 10;
        const w = 60;
        const h = 8;
        doc.setFillColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.setDrawColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.rect(x, y, w, h, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.8);
        text(doc, label, 40, y + 5.4, {align: 'center'});
        doc.setTextColor(0, 0, 0);
        return y + h;
    }

    function drawCell(doc, x, y, w, h, value, opts) {
        const config = opts || {};
        doc.setDrawColor(GRID[0], GRID[1], GRID[2]);
        doc.setLineWidth(0.2);
        if (config.fill) {
            doc.setFillColor(config.fill[0], config.fill[1], config.fill[2]);
            doc.rect(x, y, w, h, 'FD');
        } else {
            doc.rect(x, y, w, h);
        }

        doc.setFont('helvetica', config.bold ? 'bold' : 'normal');
        doc.setFontSize(config.size || 6.4);
        const lines = config.wrap === false
            ? [safe(value)]
            : doc.splitTextToSize(safe(value), Math.max(2, w - 2));
        const shown = lines.slice(0, Math.max(1, Math.floor(h / (config.lineHeight || 3.5))));
        const tx = config.align === 'center' ? x + w / 2 : x + 1;
        const ty = y + (config.middle ? Math.max(3.2, h / 2 + 1.1) : 3.1);
        if (config.align === 'center') {
            doc.text(shown, tx, ty, {align: 'center'});
        } else {
            doc.text(shown, tx, ty);
        }
    }

    function drawFooter(doc, note, cfg) {
        const h = doc.internal.pageSize.getHeight();
        const yBase = h - 12;
        doc.setDrawColor(90, 90, 90);
        doc.setLineWidth(0.3);
        doc.line(10, yBase - 1.5, 70, yBase - 1.5);

        doc.setFont('helvetica', 'italic');
        doc.setFontSize(6);
        doc.setTextColor(0, 0, 0);
        text(
            doc,
            `Impresion: ${note.impreso || cfg.dateText || nowParts().impreso} | Usuario: ${cfg.userName || note.responsible || 'Usuario'} | DNI: ${cfg.dni || note.dni || 'No registrado'}`,
            40,
            yBase + 2.7,
            {align: 'center'}
        );

        const label = note.footerLabel || 'NORTE 360';
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(5);
        const tw = doc.getTextWidth(label) + 6;
        const x = (80 - tw) / 2;
        const y = yBase + 5.2;
        doc.setFillColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.setDrawColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.rect(x, y, tw, 3.5, 'F');
        doc.setTextColor(255, 255, 255);
        text(doc, label, 40, y + 2.45, {align: 'center'});
        doc.setTextColor(0, 0, 0);
    }

    function sample(kind, cfg) {
        const now = nowParts();
        const base = {
            kind,
            ruc: cfg.ruc || '20403002101',
            fecha: now.fecha,
            hora: now.hora,
            impreso: now.impreso,
            responsible: cfg.userName || 'admin',
            dni: cfg.dni || '72953637',
            footerLabel: 'NORTE 360'
        };

        if (kind === 'CM') {
            return Object.assign(base, {
                series: 'CM',
                correlativo: 24,
                module: 'Combustible',
                pointLabel: 'Punto de Despacho',
                space: 'GRIFO PRINCIPAL (DIESEL)',
                unitText: 'BUS 158 ABC-321',
                actorLabel: 'Conductor',
                actor: 'Juan Perez Gomez',
                reason: 'Tanqueo a unidad BUS 158 para salida operativa.',
                products: [
                    {qty: '45.0000', unit: 'GLN', description: 'DIESEL B5 S-50', unitPrice: '14.8500', amount: '668.2500'}
                ],
                total: 'S/. 668.2500',
                withAmounts: true,
                fileName: 'norte360_formato_CM_tanqueada_combustible.pdf'
            });
        }

        return Object.assign(base, {
            series: 'NS',
            correlativo: 18,
            module: 'Almac\u00e9n',
            pointLabel: 'Punto de Recepci\u00f3n',
            space: 'ALMACEN (ALM)',
            unitText: 'BUS 158 (ABC-321)',
            actorLabel: 'Entregado a',
            actor: 'Taller de mantenimiento',
            reason: 'Atencion de mantenimiento preventivo de unidad.',
            products: [
                {qty: '2', description: 'FILTRO DE ACEITE MOTOR 15W40 - UNIDAD'},
                {qty: '1', description: 'FAJA DE ALTERNADOR PARA BUS INTERPROVINCIAL'}
            ],
            withAmounts: false,
            fileName: 'norte360_formato_NS_nota_salida.pdf'
        });
    }

    function drawTableWithAmounts(doc, note, y) {
        const x0 = 5;
        const widths = [10, 8, 32, 10, 12];
        let x = x0;
        doc.setFont('helvetica', 'bold');
            ['Cant.', 'Un.', 'Descripci\u00f3n del Producto', 'P.Unit', 'Importe'].forEach((header, index) => {
            drawCell(doc, x, y, widths[index], 5, header, {
                fill: HEADER_FILL,
                bold: true,
                size: 6.1,
                align: 'center',
                middle: true
            });
            x += widths[index];
        });
        y += 5;

        (note.products || []).forEach(product => {
            const rowH = Math.max(3.5, doc.splitTextToSize(safe(product.description), 30).length * 3.5);
            x = x0;
            drawCell(doc, x, y, widths[0], rowH, product.qty, {align: 'center', middle: true, wrap: false}); x += widths[0];
            drawCell(doc, x, y, widths[1], rowH, product.unit, {align: 'center', middle: true, wrap: false}); x += widths[1];
            drawCell(doc, x, y, widths[2], rowH, product.description); x += widths[2];
            drawCell(doc, x, y, widths[3], rowH, product.unitPrice, {align: 'center', middle: true, wrap: false}); x += widths[3];
            drawCell(doc, x, y, widths[4], rowH, product.amount, {align: 'center', middle: true, wrap: false});
            y += rowH;
        });

        return y + AFTER_TABLE_GAP;
    }

    function drawTablePlain(doc, note, y) {
        const x0 = 10;
        const widths = [8, 58];
        drawCell(doc, x0, y, widths[0], 5, 'Cant.', {
            fill: HEADER_FILL,
            bold: true,
            size: 6.4,
            align: 'center',
            middle: true
        });
        drawCell(doc, x0 + widths[0], y, widths[1], 5, 'Descripci\u00f3n del Producto', {
            fill: HEADER_FILL,
            bold: true,
            size: 6.4,
            align: 'center',
            middle: true
        });
        y += 5;

        (note.products || []).forEach(product => {
            const rowH = Math.max(3.5, doc.splitTextToSize(safe(product.description), 56).length * 3.5);
            drawCell(doc, x0, y, widths[0], rowH, product.qty, {align: 'center', middle: true, wrap: false});
            drawCell(doc, x0 + widths[0], y, widths[1], rowH, product.description);
            y += rowH;
        });

        return y + AFTER_TABLE_GAP;
    }

    function draw(doc, note, cfg) {
        drawLogo(doc, cfg.assets && cfg.assets.logo);

        let y = 22;
        y = leftLine(doc, `RUC: ${note.ruc}`, y, {bold: true, size: 8, lineHeight: 5});
        y = leftLine(doc, `Fecha de Emisi\u00f3n: ${note.fecha} ${note.hora}`, y, {bold: true, size: 8, lineHeight: 5});
        y += 5;

        if (note.withAmounts) {
            y = centerLine(doc, 'ORDENES DE SUMINISTRO DE COMBUSTIBLE', y, 8, true) + 5;
            y = drawBanner(doc, y, 'VALE DE COMBUSTIBLE - SALIDA') + 5;
        } else {
            y = centerLine(doc, 'NOTA DE SALIDA DE MATERIALES AUXILIARES,', y, 8, true);
            y = centerLine(doc, 'SUMINISTROS Y REPUESTOS DE ALMACEN', y + 1.5, 8, true) + 6;
        }

        y = leftLine(doc, `${note.series} - ${pad4(note.correlativo)}`, y, {size: 8, lineHeight: 5});
        y = leftLine(doc, `\u00c1rea: ${note.module}`, y, {size: 8, lineHeight: 5});
        y = leftLine(doc, `${note.pointLabel}: ${note.space}`, y, {size: 8, lineHeight: 5});
        y = leftLine(doc, `Unidad con Placa: ${note.unitText}`, y, {size: 8, lineHeight: 5});
        y = leftLine(doc, `${note.actorLabel}: ${note.actor}`, y, {size: 8, lineHeight: 4});
        y = leftLine(doc, `Motivo: ${note.reason}`, y, {size: 8, width: 60, lineHeight: 4}) + 6;

        y = note.withAmounts ? drawTableWithAmounts(doc, note, y) : drawTablePlain(doc, note, y);

        if (note.withAmounts) {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            text(doc, `TOTAL: ${note.total || ''}`, 70, y + 1.5, {align: 'right'});
            y += 8;
        }

        y = leftLine(doc, `Responsable de Despacho: ${note.responsible}`, y, {size: 8, lineHeight: 4});
        y = leftLine(doc, `DNI: ${note.dni}`, y, {size: 8, lineHeight: 4});

        doc.setDrawColor(90, 90, 90);
        doc.setLineWidth(0.3);
        doc.line(10, y + RULE_AFTER_INFO_GAP, 70, y + RULE_AFTER_INFO_GAP);

        drawFooter(doc, note, cfg);
    }

    async function generate(kind, options) {
        const type = String(kind || 'NS').toUpperCase();
        const cfg = Object.assign({}, options || {});
        const note = Object.assign(sample(type, cfg), cfg.noteData || {});
        cfg.assets = Object.assign({}, cfg.assets || {}, {
            logo: await loadImage(cfg.logoTicket || cfg.logoWide || cfg.logoCompany || cfg.logoRight || cfg.logoLeft)
        });

        const doc = createDocument(note);
        draw(doc, note, cfg);
        doc.save(note.fileName || `norte360_formato_${type}.pdf`);
        return doc;
    }

    window.N360NotaSalida = {
        generate,
        generateDemo: generate
    };
})(window);
