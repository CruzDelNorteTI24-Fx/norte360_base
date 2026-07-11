(function () {
    if (window.N360BarcodeReady) return;
    window.N360BarcodeReady = true;

    const PATTERNS = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'
    ];

    const START_B = 104;
    const STOP = 106;

    const esc = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const clean = (value, fallback) => {
        const text = String(value ?? '').trim();
        return text || fallback || '-';
    };

    const safeFile = (value) => clean(value, 'barcode')
        .replace(/[^\w.\- ]+/g, '')
        .replace(/\s+/g, '_')
        .slice(0, 120) || 'barcode';

    const nowStamp = () => {
        const d = new Date();
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
    };

    const getData = (el) => {
        const code = clean(el.dataset.barcodeCode, '-');
        const name = clean(el.dataset.barcodeName, 'Producto sin nombre');
        const category = clean(el.dataset.barcodeCategory, 'Sin categoria');
        const kind = clean(el.dataset.barcodeKind, 'producto').toLowerCase();
        const logo = clean(el.dataset.barcodeLogo, '');
        const filename = clean(el.dataset.barcodeFilename, '');

        return { code, name, category, kind, logo, filename };
    };

    const codeValues = (code) => {
        const text = clean(code, '-');
        const values = [START_B];

        for (const char of text) {
            const value = char.charCodeAt(0) - 32;
            values.push(value >= 0 && value <= 95 ? value : 13);
        }

        let checksum = START_B;
        for (let i = 1; i < values.length; i += 1) {
            checksum += values[i] * i;
        }

        values.push(checksum % 103);
        values.push(STOP);
        return values;
    };

    const moduleCount = (values) => values.reduce((total, value) => {
        const pattern = PATTERNS[value] || '';
        return total + pattern.split('').reduce((sum, part) => sum + Number(part || 0), 0);
    }, 20);

    const makeSvg = (code) => {
        const values = codeValues(code);
        const modules = moduleCount(values);
        let x = 10;
        const bars = [];

        values.forEach((value) => {
            const pattern = PATTERNS[value] || '';
            let drawBar = true;

            pattern.split('').forEach((part) => {
                const width = Number(part || 0);
                if (drawBar && width > 0) {
                    bars.push(`<rect x="${x}" y="5" width="${width}" height="72" fill="#000"></rect>`);
                }
                x += width;
                drawBar = !drawBar;
            });
        });

        return `
            <svg class="n360-barcode-svg" viewBox="0 0 ${modules} 100" preserveAspectRatio="none" role="img" aria-label="Codigo de barras ${esc(code)}" xmlns="http://www.w3.org/2000/svg">
                <rect x="0" y="0" width="${modules}" height="100" fill="#fff"></rect>
                ${bars.join('')}
                <text x="${modules / 2}" y="94" text-anchor="middle" font-family="N360Consola, Consolas, Courier New, monospace" font-size="16" font-weight="900" fill="#000">${esc(code)}</text>
            </svg>
        `;
    };

    const defaultFileName = (data) => {
        if (data.filename && data.filename !== '-') {
            return data.filename.endsWith('.png') ? data.filename : `${data.filename}.png`;
        }

        if (data.kind === 'etiqueta') {
            return `${safeFile(data.code)}_${nowStamp()}.png`;
        }

        return `BARCODE_${safeFile(`${data.code}_${data.name}`)}.png`;
    };

    const drawBarcodeCanvas = (ctx, code, x0, y0, width, height) => {
        const values = codeValues(code);
        const modules = moduleCount(values);
        const scale = width / modules;
        let x = x0 + (10 * scale);

        ctx.fillStyle = '#fff';
        ctx.fillRect(x0, y0, width, height);
        ctx.fillStyle = '#000';

        values.forEach((value) => {
            const pattern = PATTERNS[value] || '';
            let drawBar = true;

            pattern.split('').forEach((part) => {
                const moduleWidth = Number(part || 0);
                const drawWidth = moduleWidth * scale;
                if (drawBar && moduleWidth > 0) {
                    ctx.fillRect(x, y0 + 5, Math.max(drawWidth, 0.7), Math.max(58, height - 44));
                }
                x += drawWidth;
                drawBar = !drawBar;
            });
        });

        ctx.font = '900 25px N360Consola, Consolas, Courier New, monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.fillText(code, x0 + width / 2, y0 + height - 10);
    };

    const fitText = (ctx, text, maxWidth, maxSize, minSize) => {
        let output = clean(text, '-');
        let size = maxSize;

        for (; size >= minSize; size -= 1) {
            ctx.font = `700 ${size}px N360Consola, Consolas, Courier New, monospace`;
            if (ctx.measureText(output).width <= maxWidth) {
                return { text: output, size };
            }
        }

        ctx.font = `700 ${minSize}px N360Consola, Consolas, Courier New, monospace`;
        while (output.length > 3 && ctx.measureText(`${output}...`).width > maxWidth) {
            output = output.slice(0, -1);
        }

        return { text: `${output}...`, size: minSize };
    };

    const loadImage = (src) => new Promise((resolve) => {
        if (!src || src === '-') {
            resolve(null);
            return;
        }

        const img = new Image();
        img.crossOrigin = 'same-origin';
        img.onload = () => resolve(img);
        img.onerror = () => resolve(null);
        img.src = src;
    });

    const downloadPng = async (el) => {
        const data = getData(el);
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const margin = 10;
        const barcodeW = 350;
        const barcodeH = 120;
        const logoW = 350;
        const logoH = 50;
        const textGap1 = 6;
        const textGap2 = 4;
        const logoGap = 7;
        const maxTextW = barcodeW - 10;

        canvas.width = Math.max(barcodeW, logoW) + margin * 2;
        canvas.height = margin + barcodeH + textGap1 + 16 + textGap2 + 16 + logoGap + logoH + margin;

        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const xBarcode = Math.floor((canvas.width - barcodeW) / 2);
        let y = margin;
        drawBarcodeCanvas(ctx, data.code, xBarcode, y, barcodeW, barcodeH);
        y += barcodeH + textGap1;

        ctx.fillStyle = '#000';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';

        const cat = fitText(ctx, data.category, maxTextW, 14, 8);
        ctx.font = `700 ${cat.size}px N360Consola, Consolas, Courier New, monospace`;
        ctx.fillText(cat.text, xBarcode, y);
        y += 16 + textGap2;

        const name = fitText(ctx, data.name, maxTextW, 14, 8);
        ctx.font = `700 ${name.size}px N360Consola, Consolas, Courier New, monospace`;
        ctx.fillText(name.text, xBarcode, y);
        y += 16 + logoGap;

        const logo = await loadImage(data.logo);
        if (logo) {
            ctx.drawImage(logo, Math.floor((canvas.width - logoW) / 2), y, logoW, logoH);
        } else {
            ctx.fillStyle = '#143149';
            ctx.fillRect(Math.floor((canvas.width - 150) / 2), y + 10, 150, 30);
            ctx.fillStyle = '#fff';
            ctx.font = '900 13px N360Consola, Consolas, Courier New, monospace';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('NORTE 360', canvas.width / 2, y + 25);
        }

        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = defaultFileName(data);
        document.body.appendChild(link);
        link.click();
        link.remove();
    };

    const renderElement = (el) => {
        if (!el || el.dataset.barcodeRendered === '1') return;

        const data = getData(el);
        const stage = el.querySelector('[data-barcode-stage]') || el;

        try {
            stage.innerHTML = `
                <div class="n360-barcode-paper">
                    ${makeSvg(data.code)}
                    <div class="n360-barcode-text">
                        <span title="${esc(data.category)}">${esc(data.category)}</span>
                        <span title="${esc(data.name)}">${esc(data.name)}</span>
                    </div>
                    ${data.logo && data.logo !== '-' ? `<img class="n360-barcode-logo" src="${esc(data.logo)}" alt="Norte 360">` : '<div class="n360-barcode-logo" aria-hidden="true"></div>'}
                </div>
            `;
            el.dataset.barcodeRendered = '1';
        } catch (error) {
            stage.innerHTML = '<div class="n360-barcode-error">No se pudo generar la previsualizacion del codigo.</div>';
        }
    };

    const renderAll = (root) => {
        const scope = root || document;
        scope.querySelectorAll('[data-n360-barcode]').forEach(renderElement);
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-barcode-download]');
        if (!button) return;

        const card = button.closest('[data-n360-barcode]');
        if (!card) return;

        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="bi bi-hourglass-split"></i><span>Generando...</span>';

        downloadPng(card).finally(() => {
            button.disabled = false;
            button.innerHTML = originalText;
        });
    });

    document.addEventListener('DOMContentLoaded', () => renderAll(document));

    window.N360Barcode = {
        renderAll,
        renderElement,
        downloadPng,
    };
})();
