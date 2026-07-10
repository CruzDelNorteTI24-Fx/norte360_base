(function (window, document) {
    'use strict';

    const DEFAULTS = {
        companyName: 'EMPRESA DE TRANSPORTES CRUZ DEL NORTE S.A.C.',
        ruc: '20403002101',
        systemName: 'Norte 360° - ERP Operativo de Transporte',
        docCode: 'N360-DOC-DEMO',
        title: 'FORMATO DOCUMENTARIO',
        secondTitle: 'Plantilla estandar A4',
        description: 'Vista de prueba para validar caratula, encabezado, contenido y pie de pagina.',
        userName: 'Usuario',
        dni: 'No registrado',
        logoLeft: '../img/icon.png',
        logoRight: '../img/norte360_black.png',
        coverImage: '../img/caratula_historial_flota.png'
    };

    const imageCache = new Map();

    function mergeConfig(config) {
        return Object.assign({}, DEFAULTS, config || {});
    }

    function jsPDFCtor() {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            throw new Error('jsPDF no esta cargado. Revisa el script CDN de jsPDF.');
        }
        return window.jspdf.jsPDF;
    }

    function imageType(src) {
        const path = String(src || '').split('?')[0].toLowerCase();
        if (path.endsWith('.jpg') || path.endsWith('.jpeg')) return 'JPEG';
        if (path.endsWith('.webp')) return 'WEBP';
        return 'PNG';
    }

    function loadImage(src) {
        if (!src) return Promise.resolve(null);
        if (imageCache.has(src)) return imageCache.get(src);

        const task = new Promise(resolve => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.naturalWidth || img.width;
                    canvas.height = img.naturalHeight || img.height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    const type = imageType(src);
                    const mime = type === 'JPEG' ? 'image/jpeg' : 'image/png';
                    resolve({
                        data: canvas.toDataURL(mime),
                        type: type === 'WEBP' ? 'PNG' : type,
                        width: canvas.width,
                        height: canvas.height
                    });
                } catch (err) {
                    resolve(null);
                }
            };
            img.onerror = function () {
                resolve(null);
            };
            img.src = src;
        });

        imageCache.set(src, task);
        return task;
    }

    function nowText() {
        const date = new Date();
        const pad = n => String(n).padStart(2, '0');
        return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    }

    function text(doc, value, x, y, options) {
        doc.text(String(value || ''), x, y, options || {});
    }

    function fitText(doc, value, maxWidth) {
        return doc.splitTextToSize(String(value || ''), maxWidth);
    }

    function roundedChip(doc, label, x, y, w, h) {
        doc.setFillColor(49, 113, 183);
        doc.setDrawColor(49, 113, 183);
        doc.roundedRect(x, y, w, h, 1.4, 1.4, 'FD');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11.5);
        text(doc, label, x + w / 2, y + h - 3.4, {align: 'center'});
    }

    function addCover(doc, config) {
        const cfg = mergeConfig(config);
        const W = doc.internal.pageSize.getWidth();
        const H = doc.internal.pageSize.getHeight();
        const cover = cfg.assets && cfg.assets.cover;

        doc.setFillColor(255, 255, 255);
        doc.rect(0, 0, W, H, 'F');

        if (cover) {
            const scale = Math.min(W / cover.width, H / cover.height, 1);
            const drawW = cover.width * scale;
            const drawH = cover.height * scale;
            doc.addImage(cover.data, cover.type, (W - drawW) / 2, (H - drawH) / 2, drawW, drawH, undefined, 'FAST');
        } else {
            doc.setFillColor(247, 250, 252);
            doc.rect(0, 0, W, H, 'F');
            doc.setFillColor(18, 42, 64);
            doc.rect(0, 0, W, 54, 'F');
            doc.setFillColor(35, 137, 201);
            doc.rect(0, H - 26, W, 26, 'F');
        }

        const offsetY = cfg.coverOffsetY || (H > W ? 98 : 62);
        const centerX = W / 2;
        const chipW = cfg.coverChipWidth || 86;

        roundedChip(doc, cfg.coverTag || cfg.systemName, centerX - chipW / 2, offsetY, chipW, 8);

        doc.setTextColor(15, 23, 42);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(20);
        text(doc, cfg.coverTitle || cfg.title, centerX, offsetY + 20, {align: 'center'});

        if (cfg.secondTitle) {
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(15);
            doc.setTextColor(55, 65, 81);
            text(doc, cfg.secondTitle, centerX, offsetY + 31, {align: 'center'});
        }

        if (cfg.coverMain) {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(18);
            doc.setTextColor(17, 24, 39);
            text(doc, cfg.coverMain, centerX, offsetY + 44, {align: 'center'});
        }

        if (cfg.coverSecond) {
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(14);
            doc.setTextColor(55, 65, 81);
            text(doc, cfg.coverSecond, centerX, offsetY + 54, {align: 'center'});
        }

        if (cfg.description) {
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(12);
            doc.setTextColor(75, 85, 99);
            const lines = fitText(doc, cfg.description, Math.min(W - 54, 142));
            doc.text(lines, centerX, offsetY + 67, {align: 'center'});
        }

        if (cfg.showCoverDate !== false) {
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(11);
            doc.setTextColor(107, 114, 128);
            text(doc, `Emitido el ${cfg.dateText || nowText()}`, centerX, offsetY + 86, {align: 'center'});
        }
    }

    function addHeaderFooter(doc, config) {
        const cfg = mergeConfig(config);
        const pageCount = doc.internal.getNumberOfPages();
        const leftMargin = cfg.leftMargin || 12.7;
        const rightMargin = cfg.rightMargin || 12.7;
        const headerH = 24.8;
        const logoLeft = cfg.assets && cfg.assets.logoLeft;
        const logoRight = cfg.assets && cfg.assets.logoRight;

        const startPage = cfg.cover ? 2 : 1;
        const totalVisiblePages = Math.max(pageCount - startPage + 1, 1);
        for (let page = startPage; page <= pageCount; page += 1) {
            doc.setPage(page);
            const visiblePage = page - startPage + 1;
            const W = doc.internal.pageSize.getWidth();
            const H = doc.internal.pageSize.getHeight();
            const yBar = 0;

            doc.setFillColor(255, 255, 255);
            doc.rect(0, yBar, W, headerH, 'F');

            if (logoLeft) {
                try { doc.addImage(logoLeft.data, logoLeft.type, leftMargin, 7, 10.6, 8.5, undefined, 'FAST'); } catch (err) {}
            }

            if (logoRight) {
                try { doc.addImage(logoRight.data, logoRight.type, W - rightMargin - 12.4, 5.5, 12.4, 12.4, undefined, 'FAST'); } catch (err) {}
            }

            doc.setTextColor(0, 0, 0);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(9);
            text(doc, cfg.companyName, W / 2, 9.8, {align: 'center'});

            doc.setFontSize(7);
            text(doc, `RUC: ${cfg.ruc}`, W / 2, 13.1, {align: 'center'});

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(13);
            text(doc, cfg.title, W / 2, 20.8, {align: 'center'});

            doc.setDrawColor(0, 0, 0);
            doc.setLineWidth(0.18);
            doc.setLineDashPattern([0.9, 0.9], 0);
            doc.line(leftMargin, 25.2, W - rightMargin, 25.2);
            doc.setLineDashPattern([], 0);

            const fy = H - 11.4;
            doc.setDrawColor(203, 213, 225);
            doc.setLineWidth(0.15);
            doc.line(leftMargin, fy - 3.6, W - rightMargin, fy - 3.6);

            doc.setTextColor(100, 116, 139);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7);
            text(doc, cfg.systemName, leftMargin, fy, {align: 'left'});

            doc.setFont('helvetica', 'bold');
            text(doc, cfg.docCode, W / 2, fy, {align: 'center'});

            doc.setFont('helvetica', 'normal');
            text(doc, `P\u00e1gina ${visiblePage} de ${totalVisiblePages}`, W - rightMargin, fy, {align: 'right'});

            doc.setFontSize(6);
            doc.setTextColor(148, 163, 184);
            text(doc, `Fecha y hora de impresion: ${cfg.dateText || nowText()}`, leftMargin, fy + 3.2, {align: 'left'});
            text(doc, `Usuario: ${cfg.userName || 'Usuario'}  |  DNI: ${cfg.dni || 'No registrado'}`, W - rightMargin, fy + 3.2, {align: 'right'});
        }
    }

    function addDemoContent(doc, config) {
        const cfg = mergeConfig(config);
        const W = doc.internal.pageSize.getWidth();
        const H = doc.internal.pageSize.getHeight();
        const left = cfg.leftMargin || 12.7;
        const top = 36;
        const contentW = W - left * 2;

        doc.setFillColor(245, 248, 251);
        doc.roundedRect(left, top, contentW, 22, 2, 2, 'F');
        doc.setTextColor(18, 42, 64);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        text(doc, cfg.secondTitle || 'Resumen del documento', left + 5, top + 8);

        doc.setTextColor(71, 85, 105);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.text(fitText(doc, cfg.description, contentW - 10), left + 5, top + 14);

        const tableY = top + 33;
        const cols = cfg.orientation === 'landscape'
            ? [24, 58, 74, 40, 54]
            : [20, 42, 50, 32, 38];
        const headers = ['Item', 'Seccion', 'Descripcion', 'Estado', 'Observacion'];
        const rows = [
            ['01', 'Caratula', 'Papeleria corporativa con chip, titulo, descripcion y fecha.', 'OK', 'Disponible cuando se requiera.'],
            ['02', 'Encabezado', 'Logos, razon social, RUC y titulo central.', 'OK', 'Mismo criterio que app.py.'],
            ['03', 'Contenido', 'Area util con margenes para tablas, paneles o reportes.', 'OK', 'A4 vertical u horizontal.'],
            ['04', 'Pie', 'Sistema, codigo documental, pagina, fecha, usuario y DNI.', 'OK', 'Listo para reutilizar.']
        ];

        let x = left;
        doc.setFillColor(18, 42, 64);
        doc.rect(left, tableY, contentW, 9, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7.5);
        headers.forEach((h, i) => {
            text(doc, h, x + 2, tableY + 5.8);
            x += cols[i];
        });

        let y = tableY + 9;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.2);
        rows.forEach((row, r) => {
            const rowH = 14;
            doc.setFillColor(r % 2 ? 255 : 249, r % 2 ? 255 : 251, r % 2 ? 255 : 253);
            doc.rect(left, y, contentW, rowH, 'F');
            doc.setDrawColor(226, 232, 240);
            doc.line(left, y + rowH, left + contentW, y + rowH);
            doc.setTextColor(15, 23, 42);
            x = left;
            row.forEach((cell, i) => {
                const lines = fitText(doc, cell, cols[i] - 4);
                doc.text(lines.slice(0, 2), x + 2, y + 5);
                x += cols[i];
            });
            y += rowH;
        });

        doc.setFillColor(240, 249, 255);
        doc.setDrawColor(186, 230, 253);
        doc.roundedRect(left, H - 38, contentW, 14, 2, 2, 'FD');
        doc.setTextColor(12, 74, 110);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.2);
        text(doc, 'Uso futuro', left + 5, H - 32);
        doc.setFont('helvetica', 'normal');
        text(doc, 'Llama N360PDF.generateDemo({ orientation }) o reutiliza N360PDF.createDocument(...) para reportes reales.', left + 5, H - 27.5);
    }

    async function prepareAssets(config) {
        const cfg = mergeConfig(config);
        cfg.assets = {
            logoLeft: await loadImage(cfg.logoLeft),
            logoRight: await loadImage(cfg.logoRight),
            cover: cfg.useCover === false ? null : await loadImage(cfg.coverImage)
        };
        return cfg;
    }

    async function createDocument(config) {
        const cfg = await prepareAssets(config);
        const jsPDF = jsPDFCtor();
        const doc = new jsPDF({
            orientation: cfg.orientation || 'portrait',
            unit: 'mm',
            format: 'a4',
            compress: true
        });

        if (cfg.cover) {
            addCover(doc, cfg);
            doc.addPage('a4', cfg.orientation || 'portrait');
        }

        if (typeof cfg.content === 'function') {
            cfg.content(doc, cfg);
        } else {
            addDemoContent(doc, cfg);
        }

        addHeaderFooter(doc, cfg);
        return doc;
    }

    async function generateDemo(options) {
        const cfg = mergeConfig(options);
        const orientationLabel = cfg.orientation === 'landscape' ? 'horizontal' : 'vertical';
        const doc = await createDocument(Object.assign({
            title: cfg.orientation === 'landscape' ? 'FORMATO A4 HORIZONTAL' : 'FORMATO A4 VERTICAL',
            secondTitle: 'Prueba de estandar documental',
            docCode: cfg.orientation === 'landscape' ? 'N360-DOC-A4-H' : 'N360-DOC-A4-V',
            coverTitle: cfg.orientation === 'landscape' ? 'FORMATO HORIZONTAL' : 'FORMATO VERTICAL',
            coverMain: 'Plantilla Norte 360',
            coverSecond: 'A4 - ' + orientationLabel.toUpperCase(),
            description: 'Documento de prueba sin consultas a base de datos. Sirve para validar estetica, margenes, caratula, encabezado y pie de pagina.'
        }, cfg));

        doc.save(`norte360_formato_a4_${orientationLabel}.pdf`);
    }

    window.N360PDF = {
        createDocument,
        generateDemo,
        addCover,
        addHeaderFooter,
        addDemoContent,
        loadImage
    };
})(window, document);
