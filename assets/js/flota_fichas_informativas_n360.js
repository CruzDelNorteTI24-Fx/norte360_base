(() => {
    'use strict';

    const cfg = window.N360_FICHAS_INFORMATIVAS || {};
    const buses = Array.isArray(cfg.buses) ? cfg.buses : [];
    const drivers = Array.isArray(cfg.drivers) ? cfg.drivers : [];

    const byId = (id) => document.getElementById(id);

    const els = {
        bus: byId('fiBusSelect'),
        driverOne: byId('fiDriverOneSelect'),
        driverTwo: byId('fiDriverTwoSelect'),
        preview: byId('fiPreviewStage'),
        status: byId('fiStatus'),
        previewBtn: byId('fiPreviewBtn'),
        downloadBtn: byId('fiDownloadBtn'),
        summaryBus: byId('fiSummaryBus'),
        summaryCapacity: byId('fiSummaryCapacity'),
        summaryDriverOne: byId('fiSummaryDriverOne'),
        summaryDriverTwo: byId('fiSummaryDriverTwo')
    };

    const findById = (items, value) => items.find((item) => String(item.id) === String(value)) || null;

    function setStatus(message, type = 'info') {
        if (!els.status) return;
        els.status.className = 'fi-status';
        if (type === 'ok') els.status.classList.add('fi-status--ok');
        if (type === 'warn') els.status.classList.add('fi-status--warn');
        if (type === 'danger') els.status.classList.add('fi-status--danger');
        const icon = type === 'ok' ? 'bi-check-circle' : type === 'danger' ? 'bi-exclamation-triangle' : type === 'warn' ? 'bi-exclamation-circle' : 'bi-info-circle';
        els.status.innerHTML = `<i class="bi ${icon}"></i><span>${escapeHtml(message)}</span>`;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[char]));
    }

    function selectedBus() {
        return findById(buses, els.bus?.value);
    }

    function selectedDriver(select) {
        return findById(drivers, select?.value);
    }

    function driverLabel(driver) {
        if (!driver) return 'Sin seleccionar';
        const dni = driver.dni ? ` (${driver.dni})` : '';
        return `${driver.conductor}${dni}`;
    }

    function updateSummary() {
        const bus = selectedBus();
        const driverOne = selectedDriver(els.driverOne);
        const driverTwo = selectedDriver(els.driverTwo);

        if (els.summaryBus) {
            els.summaryBus.textContent = bus ? `${bus.nombre} - ${bus.placa}` : 'Sin seleccionar';
        }
        if (els.summaryCapacity) {
            els.summaryCapacity.textContent = bus && bus.capacidad_total ? `${bus.capacidad_total} pasajeros` : '-';
        }
        if (els.summaryDriverOne) els.summaryDriverOne.textContent = driverLabel(driverOne);
        if (els.summaryDriverTwo) els.summaryDriverTwo.textContent = driverLabel(driverTwo);
    }

    function validateSelection() {
        const bus = selectedBus();
        const driverOne = selectedDriver(els.driverOne);
        const driverTwo = selectedDriver(els.driverTwo);

        if (!window.N360BusLookupImage) {
            setStatus('No se encontro el generador de imagenes de Norte360.', 'danger');
            return null;
        }
        if (!bus) {
            setStatus('Selecciona una unidad para generar la ficha.', 'warn');
            els.bus?.focus();
            return null;
        }
        if (!driverOne) {
            setStatus('Selecciona el primer conductor.', 'warn');
            els.driverOne?.focus();
            return null;
        }
        if (!driverTwo) {
            setStatus('Selecciona el segundo conductor.', 'warn');
            els.driverTwo?.focus();
            return null;
        }
        if (String(driverOne.id) === String(driverTwo.id)) {
            setStatus('Selecciona dos conductores diferentes para la ficha.', 'warn');
            els.driverTwo?.focus();
            return null;
        }

        return { bus, driverOne, driverTwo };
    }

    function buildPayload(selection) {
        const { bus, driverOne, driverTwo } = selection;
        return {
            bus: {
                id: bus.id,
                nombre: bus.nombre,
                placa: bus.placa,
                servicio: bus.servicio,
                dueno: bus.dueno,
                tipo: bus.tipo
            },
            patrimonio: {
                capacidad_total: bus.capacidad_total || 0,
                marca: bus.marca || '',
                modelo: bus.modelo || ''
            },
            resumen: {
                capacidad_total: bus.capacidad_total || 0
            },
            programacion: {
                conductores: [
                    {
                        conductor: driverOne.conductor,
                        licencia: driverOne.licencia || '-'
                    },
                    {
                        conductor: driverTwo.conductor,
                        licencia: driverTwo.licencia || '-'
                    }
                ]
            },
            generado_en: new Date().toISOString()
        };
    }

    function setBusy(isBusy) {
        [els.previewBtn, els.downloadBtn].forEach((button) => {
            if (button) button.disabled = isBusy;
        });
    }

    async function renderPreview() {
        const selection = validateSelection();
        if (!selection) return;

        setBusy(true);
        setStatus('Generando previsualizacion...', 'info');
        try {
            const payload = buildPayload(selection);
            const canvas = await window.N360BusLookupImage.buildCanvasWithAssets(payload, {
                logoUrl: cfg.logoUrl,
                busUrl: cfg.busUrl,
                pixelRatio: 1
            });

            if (els.preview) {
                els.preview.innerHTML = '';
                els.preview.appendChild(canvas);
            }
            setStatus('Ficha lista. Puedes descargarla en PNG.', 'ok');
        } catch (error) {
            console.error(error);
            setStatus(error?.message || 'No se pudo generar la ficha.', 'danger');
        } finally {
            setBusy(false);
        }
    }

    async function downloadImage() {
        const selection = validateSelection();
        if (!selection) return;

        setBusy(true);
        setStatus('Preparando descarga...', 'info');
        try {
            const payload = buildPayload(selection);
            await window.N360BusLookupImage.download(payload, {
                logoUrl: cfg.logoUrl,
                busUrl: cfg.busUrl,
                pixelRatio: 2,
                notify: (message, type) => setStatus(message, type === 'error' ? 'danger' : type || 'info')
            });
            setStatus('Descarga iniciada correctamente.', 'ok');
        } catch (error) {
            console.error(error);
            setStatus(error?.message || 'No se pudo descargar la ficha.', 'danger');
        } finally {
            setBusy(false);
        }
    }

    function bindEvents() {
        [els.bus, els.driverOne, els.driverTwo].forEach((select) => {
            select?.addEventListener('change', () => {
                updateSummary();
                setStatus('Cambios listos para previsualizar.', 'info');
            });
        });
        els.previewBtn?.addEventListener('click', renderPreview);
        els.downloadBtn?.addEventListener('click', downloadImage);
    }

    updateSummary();
    bindEvents();

    if (!buses.length || !drivers.length) {
        setStatus('Faltan unidades o conductores activos para generar fichas.', 'warn');
    } else {
        renderPreview();
    }
})();