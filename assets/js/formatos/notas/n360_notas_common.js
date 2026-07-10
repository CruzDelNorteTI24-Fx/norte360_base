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

    function countLines(textValue, maxChars) {
        const value = safe(textValue);
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

    function estimateSalidaHeight(note) {
        const descChars = note.withAmounts ? 24 : 42;
        const rowHeight = (note.products || []).reduce((sum, product) => {
            return sum + countLines(product.description, descChars) * 3.5;
        }, 0);
        return 170 + Math.max(0, rowHeight - 35) + 4;
    }

    function estimateEntradaHeight(note) {
        const product = (note.products && note.products[0]) || {};
        const rowLines = countLines(product.description, note.withAmounts ? 28 : 46);
        const textLines = countLines(note.reason, 42) + countLines(note.provider, 42) + countLines(note.space, 42);
        return 120 + Math.max(0, rowLines - 3) * 3.5 + Math.max(0, textLines - 5) * 3.5 + 4;
    }

    function createDocument(note, heightResolver) {
        const jsPDF = jsPDFCtor();
        return new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: [80, heightResolver(note)],
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

    function drawLogo(doc, logo, y) {
        if (!logo) return;

        const width = 60;
        const height = logoHeight(logo, width);
        try {
            doc.addImage(logo.data, logo.type, 10, y || 5, width, height, undefined, 'FAST');
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
        doc.setFillColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.setDrawColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.rect(10, y, 60, 8, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.8);
        text(doc, label, 40, y + 5.4, {align: 'center'});
        doc.setTextColor(0, 0, 0);
        return y + 8;
    }

    function drawTitleBox(doc, y, label) {
        doc.setDrawColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.setLineWidth(0.3);
        doc.rect(10, y, 60, 6);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        text(doc, label, 40, y + 4.15, {align: 'center'});
        return y + 6;
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
        doc.setFontSize(config.size || 7);
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
        const yBase = doc.internal.pageSize.getHeight() - 12;
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
        const chipWidth = doc.getTextWidth(label) + 6;
        const chipX = (80 - chipWidth) / 2;
        const chipY = yBase + 5.2;

        doc.setFillColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.setDrawColor(BLUE[0], BLUE[1], BLUE[2]);
        doc.rect(chipX, chipY, chipWidth, 3.5, 'F');
        doc.setTextColor(255, 255, 255);
        text(doc, label, 40, chipY + 2.45, {align: 'center'});
        doc.setTextColor(0, 0, 0);
    }

    async function withLogo(options) {
        const cfg = Object.assign({}, options || {});
        cfg.assets = Object.assign({}, cfg.assets || {}, {
            logo: await loadImage(cfg.logoTicket || cfg.logoWide || cfg.logoCompany || cfg.logoRight || cfg.logoLeft)
        });
        return cfg;
    }

    function baseNote(cfg, data, defaults) {
        const now = nowParts();
        return Object.assign({
            ruc: cfg.ruc || '20403002101',
            fecha: now.fecha,
            hora: now.hora,
            impreso: now.impreso,
            responsible: cfg.userName || 'Usuario',
            dni: cfg.dni || 'No registrado',
            footerLabel: 'NORTE 360',
            products: []
        }, defaults || {}, data || {});
    }

    window.N360NotasCommon = {
        BLUE,
        GRID,
        HEADER_FILL,
        AFTER_TABLE_GAP,
        RULE_AFTER_INFO_GAP,
        safe,
        pad4,
        nowParts,
        countLines,
        estimateSalidaHeight,
        estimateEntradaHeight,
        createDocument,
        text,
        drawLogo,
        centerLine,
        leftLine,
        drawBanner,
        drawTitleBox,
        drawCell,
        drawFooter,
        withLogo,
        baseNote
    };
})(window);
