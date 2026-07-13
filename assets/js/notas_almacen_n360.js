(function () {
    const config = window.N360_NOTAS_ALMACEN || {};
    const endpoint = config.endpoint || 'notas_almacen.php';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setLoading(isLoading, message) {
        const status = $('#notaDetalleStatus');
        const content = $('#notaDetalleContent');

        if (!status || !content) return;

        if (isLoading) {
            status.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' + escapeHtml(message || 'Cargando detalle de nota...');
            status.hidden = false;
            content.hidden = true;
            return;
        }

        status.hidden = true;
        content.hidden = false;
    }

    function setError(message) {
        const status = $('#notaDetalleStatus');
        const content = $('#notaDetalleContent');

        if (!status || !content) return;

        status.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i>' + escapeHtml(message || 'No se pudo cargar la nota.');
        status.hidden = false;
        content.hidden = true;
    }

    function detailInfo(title, value) {
        return `
            <article class="notas-info">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(value || '-')}</strong>
            </article>
        `;
    }

    function detailKpi(title, value, modifier) {
        const className = modifier ? ` notas-kpi--${modifier}` : '';
        return `
            <article class="notas-kpi${className}">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(value || '0')}</strong>
            </article>
        `;
    }

    function renderProducts(products) {
        const tbody = $('#notaDetalleProductos');
        const resumen = $('#notaDetalleProductosResumen');

        if (!tbody || !resumen) return;

        if (!products.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted fw-bold">No hay productos vinculados a esta nota.</td>
                </tr>
            `;
            resumen.textContent = '0 items';
            return;
        }

        tbody.innerHTML = products.map((product, index) => {
            const searchText = [
                product.orden,
                product.codigo,
                product.producto,
                product.cantidad_label,
                product.tipo_movimiento,
                product.observacion,
            ].join(' ').toLowerCase();

            return `
                <tr data-nota-product-row data-search="${escapeHtml(searchText)}">
                    <td>${escapeHtml(product.orden || String(index + 1))}</td>
                    <td><span class="notas-pill">${escapeHtml(product.codigo || 'S/C')}</span></td>
                    <td>${escapeHtml(product.producto || 'Producto sin nombre')}</td>
                    <td><strong>${escapeHtml(product.cantidad_label || product.cantidad || '0')}</strong></td>
                    <td>${escapeHtml(product.tipo_movimiento || '-')}</td>
                    <td>${escapeHtml(product.observacion || '-')}</td>
                </tr>
            `;
        }).join('');

        resumen.textContent = `${products.length} item${products.length === 1 ? '' : 's'}`;
    }

    function renderDetail(payload) {
        const nota = payload.nota || {};
        const products = payload.productos || [];
        const kpis = payload.kpis || {};

        const title = $('#notaDetalleTitle');
        const meta = $('#notaDetalleMeta');
        const grid = $('#notaDetalleGrid');
        const kpiWrap = $('#notaDetalleKpis');

        if (title) title.textContent = nota.nota_codigo || `Nota #${nota.clm_nota_id || ''}`;
        if (meta) meta.textContent = `${nota.fecha_label || '-'} | ${nota.modulo || '-'} | ID ${nota.clm_nota_id || '-'}`;

        if (kpiWrap) {
            kpiWrap.innerHTML = [
                detailKpi('Items', kpis.items, 'green'),
                detailKpi('Cantidad total', kpis.cantidad_total, 'amber'),
                detailKpi('Modulo', kpis.modulo, 'blue'),
                detailKpi('Serie', kpis.serie),
            ].join('');
        }

        if (grid) {
            grid.innerHTML = [
                detailInfo('Nota', nota.nota_codigo),
                detailInfo('Fecha / hora', nota.fecha_label),
                detailInfo('Bus / placa', nota.unidad_label),
                detailInfo('Entregado a', nota.entregado_a),
                detailInfo('Espacio', nota.espacio),
                detailInfo('Responsable', nota.responsable),
                detailInfo('DNI', nota.dni),
                detailInfo('Motivo', nota.motivo),
            ].join('');
        }

        renderProducts(products);
        setLoading(false);
    }

    async function openDetail(idNota) {
        const modalEl = $('#notaDetalleModal');

        if (!modalEl || !window.bootstrap) return;

        const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        setLoading(true);

        try {
            const url = `${endpoint}?ajax=detalle&id_nota=${encodeURIComponent(idNota)}`;
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
            const payload = await response.json();

            if (!payload.ok) {
                throw new Error(payload.message || 'No se pudo cargar la nota.');
            }

            renderDetail(payload);
        } catch (error) {
            setError(error.message || 'No se pudo cargar la nota.');
        }
    }

    function initMainTable() {
        const input = $('[data-notas-live-filter]');
        const rows = $$('[data-notas-row]');
        const count = $('[data-notas-visible-count]');

        if (!input || !rows.length) return;

        const applyFilter = () => {
            const term = input.value.trim().toLowerCase();
            let visible = 0;

            rows.forEach((row) => {
                const haystack = row.dataset.search || '';
                const show = term === '' || haystack.includes(term);
                row.hidden = !show;
                if (show) visible += 1;
            });

            if (count) {
                count.textContent = `${visible} nota${visible === 1 ? '' : 's'}`;
            }
        };

        input.addEventListener('input', applyFilter);
    }

    function initProductFilter() {
        const input = $('#notaProductoSearch');

        if (!input) return;

        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            $$('[data-nota-product-row]').forEach((row) => {
                const haystack = row.dataset.search || '';
                row.hidden = term !== '' && !haystack.includes(term);
            });
        });

        $('#notaDetalleModal')?.addEventListener('shown.bs.modal', () => {
            input.value = '';
        });
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-nota-id]');

        if (!trigger) return;

        const idNota = trigger.getAttribute('data-nota-id');

        if (!idNota || idNota === '0') return;

        openDetail(idNota);
    });

    document.addEventListener('dblclick', (event) => {
        const row = event.target.closest('[data-notas-row]');

        if (!row) return;

        const trigger = row.querySelector('[data-nota-id]');
        const idNota = trigger?.getAttribute('data-nota-id');

        if (!idNota || idNota === '0') return;

        openDetail(idNota);
    });

    document.addEventListener('DOMContentLoaded', () => {
        initMainTable();
        initProductFilter();
    });
})();
