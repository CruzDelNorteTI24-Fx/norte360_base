(function () {
    const modal = document.getElementById('n360QuickScanModal');
    if (!modal || window.N360QuickScanReady) return;

    window.N360QuickScanReady = true;

    const form = document.getElementById('n360QuickScanForm');
    const input = document.getElementById('n360QuickScanInput');
    const status = document.getElementById('n360QuickScanStatus');
    const result = document.getElementById('n360QuickScanResult');
    const endpoint = modal.dataset.endpoint || '';
    const imageEndpoint = modal.dataset.imageEndpoint || '';
    const barcodeLogo = modal.dataset.barcodeLogo || '';
    let currentRequest = null;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const setStatus = (message, type) => {
        status.className = 'n360-quick-scan__status';
        if (type) status.classList.add(`is-${type}`);
        status.textContent = message;
    };

    const openModal = () => {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('n360-quick-scan-open');
        window.setTimeout(() => {
            input.focus();
            input.select();
        }, 40);
    };

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('n360-quick-scan-open');

        if (currentRequest) {
            currentRequest.abort();
            currentRequest = null;
        }
    };

    const renderEmpty = (message) => {
        result.innerHTML = `<div class="n360-qs-empty">${escapeHtml(message)}</div>`;
    };

    const renderTrace = (trace) => {
        if (!trace) return '';

        const items = [
            ['Sede actual', trace.sede],
            ['Anaquel', trace.anaquel],
            ['Fecha de ingreso', trace.fecha_ingreso],
            ['Fecha de etiquetado', trace.fecha_etiquetado],
            ['Estado etiqueta', trace.estado_etiqueta],
            ['Cantidad movimiento', trace.cantidad_movimiento],
            ['Observacion', trace.observacion],
        ];

        return `
            <section class="n360-qs-trace">
                <h3>Trazabilidad de etiqueta</h3>
                <div class="n360-qs-trace-grid">
                    ${items.map(([label, value]) => `
                        <div class="n360-qs-trace-item">
                            <span>${escapeHtml(label)}</span>
                            <strong>${escapeHtml(value || '-')}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    };

    const renderBarcodePreview = (payload, product, variant) => {
        const code = payload.mode === 'etiqueta'
            ? (payload.code || product.codigo || '-')
            : (product.codigo || payload.code || '-');
        const kind = payload.mode === 'etiqueta' ? 'etiqueta' : 'producto';
        const variantClass = variant === 'mini' ? ' n360-barcode-card--mini' : ' n360-barcode-card--compact';

        return `
            <section class="n360-qs-barcode">
                <div class="n360-barcode-card n360-barcode-card--flat${variantClass}"
                     data-n360-barcode
                     data-barcode-code="${escapeHtml(code)}"
                     data-barcode-name="${escapeHtml(product.nombre || 'Producto sin nombre')}"
                     data-barcode-category="${escapeHtml(product.categoria || 'Sin categoria')}"
                     data-barcode-kind="${escapeHtml(kind)}"
                     data-barcode-logo="${escapeHtml(barcodeLogo)}">
                    <div class="n360-barcode-card__head">
                        <div class="n360-barcode-card__title">
                            <i class="bi bi-upc"></i>
                            <span>Previsualizacion Code128</span>
                        </div>
                        <span class="n360-barcode-card__meta">PNG</span>
                    </div>
                    <div data-barcode-stage></div>
                    <div class="n360-barcode-actions">
                        <span class="n360-barcode-code">${escapeHtml(code)}</span>
                        <button class="n360-barcode-download" type="button" data-barcode-download>
                            <i class="bi bi-download"></i>
                            <span>Descargar PNG</span>
                        </button>
                    </div>
                </div>
            </section>
        `;
    };

    const renderProduct = (payload) => {
        const product = payload.product || {};
        const modeLabel = payload.mode === 'etiqueta' ? 'Etiqueta especifica' : 'Producto general';
        const unit = product.unidad ? ` - ${product.unidad}` : '';
        const imageUrl = product.id ? `${imageEndpoint}${encodeURIComponent(product.id)}` : '';
        const stockLevel = product.stock_level || 'neutral';

        result.innerHTML = `
            <article class="n360-qs-product">
                <div class="n360-qs-side">
                    <div class="n360-qs-photo">
                        ${imageUrl ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(product.nombre || 'Producto')}">` : '<i class="bi bi-box-seam"></i>'}
                    </div>
                    ${renderBarcodePreview(payload, product, 'mini')}
                </div>
                <div class="n360-qs-body">
                    <div class="n360-qs-badges">
                        <span class="n360-qs-badge">${escapeHtml(modeLabel)}</span>
                        <span class="n360-qs-badge">${escapeHtml(product.codigo || payload.code || '-')}</span>
                    </div>
                    <h3 class="n360-qs-title">${escapeHtml(product.nombre || 'Producto sin nombre')}${escapeHtml(unit)}</h3>
                    <p class="n360-qs-meta">${escapeHtml(product.categoria || 'Sin categoria')}</p>

                    <div class="n360-qs-kpis">
                        <div class="n360-qs-kpi n360-qs-kpi--${escapeHtml(stockLevel)}">
                            <span>Stock actual</span>
                            <strong>${escapeHtml(product.stock || '-')}</strong>
                        </div>
                        <div class="n360-qs-kpi">
                            <span>Estado</span>
                            <strong>${escapeHtml(product.estado || '-')}</strong>
                        </div>
                        <div class="n360-qs-kpi">
                            <span>Unidad</span>
                            <strong>${escapeHtml(product.unidad || '-')}</strong>
                        </div>
                    </div>

                    ${renderTrace(payload.trace)}
                </div>
            </article>
        `;

        if (window.N360Barcode) {
            window.N360Barcode.renderAll(result);
        }
    };

    const renderSuggestions = (suggestions) => {
        if (!Array.isArray(suggestions) || suggestions.length === 0) {
            renderEmpty('No se encontraron coincidencias.');
            return;
        }

        result.innerHTML = `
            <section class="n360-qs-suggestions">
                <h3>Coincidencias cercanas</h3>
                <div class="n360-qs-suggestion-list">
                    ${suggestions.map((item) => `
                        <button class="n360-qs-suggestion" type="button" data-n360-qs-code="${escapeHtml(item.codigo)}">
                            <span>
                                <strong>${escapeHtml(item.codigo)} · ${escapeHtml(item.nombre)}</strong>
                                <span>${escapeHtml(item.categoria)} · Stock ${escapeHtml(item.stock)} ${escapeHtml(item.unidad || '')}</span>
                            </span>
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    `).join('')}
                </div>
            </section>
        `;
    };

    const searchProduct = async (code) => {
        const query = String(code || '').trim();
        if (!query) {
            setStatus('Ingresa o escanea un codigo.', 'error');
            input.focus();
            return;
        }

        if (!endpoint) {
            setStatus('No se encontro el endpoint del lector.', 'error');
            return;
        }

        if (currentRequest) currentRequest.abort();
        currentRequest = new AbortController();

        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        setStatus('Consultando inventario...', 'loading');
        renderEmpty('Buscando informacion del producto.');

        try {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('q', query);

            const response = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                signal: currentRequest.signal,
                headers: {
                    'Accept': 'application/json',
                },
            });

            const payload = await response.json();

            if (!response.ok || !payload.ok) {
                setStatus(payload.message || 'No se encontro informacion.', 'error');
                renderSuggestions(payload.suggestions || []);
                return;
            }

            setStatus(payload.mode === 'etiqueta' ? 'Etiqueta encontrada.' : 'Producto encontrado.', 'ok');
            renderProduct(payload);
        } catch (error) {
            if (error.name !== 'AbortError') {
                setStatus('No se pudo consultar el producto. Revisa la conexion.', 'error');
                renderEmpty('La consulta no pudo completarse.');
            }
        } finally {
            submit.disabled = false;
            currentRequest = null;
            input.focus();
            input.select();
        }
    };

    document.addEventListener('keydown', (event) => {
        if (event.key === 'F2') {
            event.preventDefault();
            openModal();
        }

        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-n360-qs-close]')) {
            closeModal();
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        searchProduct(input.value);
    });

    result.addEventListener('click', (event) => {
        const suggestion = event.target.closest('[data-n360-qs-code]');
        if (!suggestion) return;

        input.value = suggestion.dataset.n360QsCode || '';
        searchProduct(input.value);
    });

    renderEmpty('Presiona F2 cuando necesites consultar un producto sin salir de la vista.');
})();
