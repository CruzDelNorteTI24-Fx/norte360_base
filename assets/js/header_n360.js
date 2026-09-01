document.addEventListener('DOMContentLoaded', () => {
    const header = document.getElementById('n360Header');
    if (!header) return;

    document.body.classList.add('with-header-n360');

    const menu = header.querySelector('[data-n360-user-menu]');
    const toggle = header.querySelector('[data-n360-user-toggle]');

    function syncHeaderShadow() {
        header.classList.toggle('is-scrolled', window.scrollY > 6);
    }

    function closeMenu() {
        if (!menu || !toggle) return;

        menu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMenu(event) {
        event.preventDefault();
        event.stopPropagation();

        if (!menu || !toggle) return;

        const open = menu.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (toggle) {
        toggle.addEventListener('click', toggleMenu);
    }

    document.addEventListener('click', event => {
        if (!menu || menu.contains(event.target)) return;
        closeMenu();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeMenu();
            closeUnitsModal();
        }
    });

    window.addEventListener('scroll', syncHeaderShadow, { passive: true });
    syncHeaderShadow();

    const unitsOpen = header.querySelector('[data-n360-units-open]');
    const unitsModal = document.querySelector('[data-n360-units-modal]');
    const unitsContent = unitsModal ? unitsModal.querySelector('[data-n360-units-content]') : null;
    const unitsSummary = unitsModal ? unitsModal.querySelector('[data-n360-units-summary]') : null;
    const unitsSearch = unitsModal ? unitsModal.querySelector('[data-n360-units-search]') : null;
    let unitsLoaded = false;
    let unitsLoading = false;
    let unitsRows = [];

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeSearch(value) {
        return String(value ?? '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function setUnitsState(message, tone = '') {
        if (!unitsContent) return;
        unitsContent.innerHTML = `<div class="n360-units-state ${tone ? `n360-units-state--${tone}` : ''}">${escapeHtml(message)}</div>`;
    }

    function updateUnitsSummary(total, visible, counts = {}) {
        if (!unitsSummary) return;

        const parts = Object.entries(counts)
            .filter(([, amount]) => Number(amount) > 0)
            .map(([group, amount]) => `${escapeHtml(group)}: ${Number(amount)}`);

        unitsSummary.innerHTML = `
            <span>${visible === total ? 'Total' : 'Visible'}</span>
            <strong>${Number(visible || 0)}</strong>
            ${parts.length ? `<small>${parts.join(' | ')}</small>` : ''}
        `;
    }

    function renderUnits() {
        if (!unitsContent) return;

        const query = normalizeSearch(unitsSearch ? unitsSearch.value : '');
        const filtered = query
            ? unitsRows.filter(unit => normalizeSearch([
                unit.bus,
                unit.placa,
                unit.servicio,
                unit.tipo,
                unit.estado,
                unit.grupo,
            ].join(' ')).includes(query))
            : unitsRows.slice();

        if (!filtered.length) {
            updateUnitsSummary(unitsRows.length, 0);
            setUnitsState(query ? 'No hay unidades que coincidan con la busqueda.' : 'No hay unidades activas para mostrar.', 'empty');
            return;
        }

        const grouped = filtered.reduce((acc, unit) => {
            const group = unit.grupo || 'SIN SERVICIO';
            if (!acc[group]) acc[group] = [];
            acc[group].push(unit);
            return acc;
        }, {});

        const counts = Object.fromEntries(Object.entries(grouped).map(([group, rows]) => [group, rows.length]));
        updateUnitsSummary(unitsRows.length, filtered.length, counts);

        unitsContent.innerHTML = Object.entries(grouped).map(([group, rows]) => `
            <section class="n360-units-group">
                <div class="n360-units-group__head">
                    <strong>${escapeHtml(group)}</strong>
                    <span>${rows.length} unidades</span>
                </div>
                <div class="n360-units-grid">
                    ${rows.map(unit => `
                        <article class="n360-units-card">
                            <div class="n360-units-card__top">
                                <span class="n360-units-card__bus">${escapeHtml(unit.bus || 'SIN ASIGNAR')}</span>
                                <span class="n360-units-card__status">${escapeHtml(unit.estado || 'ACTIVO')}</span>
                            </div>
                            <div class="n360-units-card__plate">
                                <i class="bi bi-credit-card-2-front" aria-hidden="true"></i>
                                <span>${escapeHtml(unit.placa || '-')}</span>
                            </div>
                            <div class="n360-units-card__meta">
                                <span>${escapeHtml(unit.servicio || '-')}</span>
                                <span>${escapeHtml(unit.tipo || '-')}</span>
                            </div>
                        </article>
                    `).join('')}
                </div>
            </section>
        `).join('');
    }

    async function loadUnits() {
        if (!unitsModal || !unitsContent || unitsLoading) return;

        const endpoint = unitsModal.dataset.endpoint || '';
        if (!endpoint) {
            setUnitsState('No se encontro el endpoint de unidades.', 'error');
            return;
        }

        unitsLoading = true;
        setUnitsState('Cargando unidades...');

        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();

            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'No se pudo cargar la relacion de unidades.');
            }

            unitsRows = Array.isArray(payload.data?.units) ? payload.data.units : [];
            unitsLoaded = true;
            renderUnits();
        } catch (error) {
            unitsLoaded = false;
            setUnitsState(error.message || 'No se pudo cargar la relacion de unidades.', 'error');
            updateUnitsSummary(0, 0);
        } finally {
            unitsLoading = false;
        }
    }

    function openUnitsModal(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        if (!unitsModal) return;

        unitsModal.hidden = false;
        document.body.classList.add('n360-units-modal-open');
        closeMenu();

        if (unitsSearch) {
            unitsSearch.value = '';
            setTimeout(() => unitsSearch.focus(), 60);
        }

        if (!unitsLoaded) {
            loadUnits();
        } else {
            renderUnits();
        }
    }

    function closeUnitsModal() {
        if (!unitsModal || unitsModal.hidden) return;
        unitsModal.hidden = true;
        document.body.classList.remove('n360-units-modal-open');
    }

    if (unitsOpen) {
        unitsOpen.addEventListener('click', openUnitsModal);
    }

    if (unitsModal) {
        unitsModal.querySelectorAll('[data-n360-units-close]').forEach(button => {
            button.addEventListener('click', closeUnitsModal);
        });
    }

    if (unitsSearch) {
        unitsSearch.addEventListener('input', renderUnits);
    }
});
