(function (window, document) {
    'use strict';
    const config = window.N360_PEAJES_PROVEEDOR;
    if (!config) return;
    const select = document.querySelector('[data-pje-report-supplier]');
    const pdfButton = document.querySelector('[data-pje-supplier-pdf]');
    const excelButton = document.querySelector('[data-pje-supplier-excel]');
    const notice = document.querySelector('[data-pje-report-notice]');
    const count = document.querySelector('[data-pje-report-count]');
    let suppliers = config.proveedores || [];
    let generating = false;

    function message(element, text, success) {
        element.textContent = text || '';
        element.classList.toggle('d-none', !text);
        element.classList.toggle('pje-report-notice--success', !!success);
    }
    async function request(params, body) {
        const url = new URL(config.endpoint, window.location.href);
        Object.entries(params || {}).forEach(([key, value]) => url.searchParams.set(key, value));
        const options = {credentials: 'same-origin', cache: 'no-store'};
        if (body) {
            options.method = 'POST';
            options.headers = {'Content-Type': 'application/json'};
            options.body = JSON.stringify(body);
        }
        const response = await fetch(url, options);
        let result;
        try { result = await response.json(); } catch (_) {
            throw new Error('No se pudo leer la respuesta del servidor.');
        }
        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo completar la solicitud.');
        return result.data;
    }
    function updateReportControls() {
        const supplier = suppliers.find(item => String(item.id) === select.value);
        count.textContent = supplier ? `${Number(supplier.placas)} placas seleccionadas` : '';
        pdfButton.disabled = generating || !supplier || Number(supplier.placas) === 0;
        excelButton.disabled = pdfButton.disabled;
        pdfButton.title = supplier ? `PDF ${supplier.nombre} - RUC ${supplier.ruc}` : 'PDF proveedor';
        excelButton.title = supplier ? `Excel ${supplier.nombre} - RUC ${supplier.ruc}` : 'Excel proveedor';
        select.disabled = generating || !suppliers.length;
    }
    function refreshSuppliers(list, preferred) {
        const old = preferred || select.value;
        suppliers = list.filter(item => Number(item.habilitado) === 1);
        select.replaceChildren();
        suppliers.forEach(supplier => select.add(new Option(`${supplier.nombre} - ${supplier.ruc}`, supplier.id)));
        if (!suppliers.length) select.add(new Option('Sin proveedores habilitados', ''));
        if (suppliers.some(item => String(item.id) === String(old))) select.value = String(old);
        updateReportControls();
    }
    select.addEventListener('change', function () {
        message(notice, '');
        updateReportControls();
    });
    updateReportControls();
    async function exportSupplier(format, button) {
        if (button.disabled) return;
        const original = button.innerHTML;
        const originalWidth = button.style.width;
        button.style.width = `${button.offsetWidth}px`;
        generating = true;
        updateReportControls();
        message(notice, '');
        button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Generando';
        try {
            const data = await request(Object.assign({}, window.N360_PEAJES_RESUMEN.filters, {
                action: 'report', proveedor: select.value
            }));
            if (!data.rows.length) {
                message(notice, 'No hay comprobantes para este proveedor, sus placas y los filtros actuales.');
                return;
            }
            if (format === 'excel') {
                if (!window.N360PeajesExcel) throw new Error('No se cargo el generador de Excel.');
                window.N360PeajesExcel.export(data);
                message(notice, 'Excel del proveedor generado.', true);
            } else {
                if (!window.N360PeajesPDF) throw new Error('No se cargo la plantilla del reporte PDF.');
                const pdf = Object.assign({}, window.N360_PEAJES_RESUMEN.pdf, {
                    secondTitle: `${data.proveedor.nombre} - RUC ${data.proveedor.ruc}`,
                    docCode: 'PEA-RPT-RES-PROVEEDOR',
                    fileName: `peajes_placas_${data.proveedor.ruc}_${data.filters.desde}_${data.filters.hasta}.pdf`
                });
                await window.N360PeajesPDF.export(Object.assign({}, data, {pdf}), button);
            }
        } catch (error) {
            message(notice, error.message || 'No se pudo generar el reporte.');
        } finally {
            generating = false;
            button.innerHTML = original;
            button.style.width = originalWidth;
            updateReportControls();
        }
    }
    pdfButton.addEventListener('click', function () { exportSupplier('pdf', pdfButton); });
    excelButton.addEventListener('click', function () { exportSupplier('excel', excelButton); });

    const manageButton = document.querySelector('[data-pje-report-manage]');
    const modalElement = document.getElementById('pje-report-modal');
    if (!manageButton || !modalElement) return;
    window.bootstrap.Tooltip.getOrCreateInstance(manageButton);
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    const form = modalElement.querySelector('[data-pje-report-form]');
    const fields = form.querySelector('[data-pje-report-fields]');
    const manageSelect = form.querySelector('[data-pje-manage-supplier]');
    const enabled = form.querySelector('[data-pje-report-enabled]');
    const search = form.querySelector('[data-pje-plate-search]');
    const selectedOnly = form.querySelector('[data-pje-selected-only]');
    const plateList = form.querySelector('[data-pje-report-plates]');
    const selectedCount = form.querySelector('[data-pje-selected-count]');
    const saveButton = form.querySelector('[data-pje-report-save]');
    const manageNotice = form.querySelector('[data-pje-manage-notice]');
    let state = null;
    let selected = new Set();
    let dirty = false;
    let saving = false;
    let modalReady = false;
    let sequence = 0;
    const normalize = value => String(value || '').toUpperCase().replace(/[-\s]/g, '');

    function renderPlates() {
        const focusedPlate = plateList.contains(document.activeElement) ? document.activeElement.value : null;
        const scrollTop = plateList.parentElement.parentElement.scrollTop;
        plateList.replaceChildren();
        const query = normalize(search.value);
        const plates = state ? state.placas.filter(plate => {
            return (!selectedOnly.checked || selected.has(Number(plate.id)))
                && (!query || normalize(`${plate.placa} ${plate.bus}`).includes(query));
        }) : [];
        plates.forEach(plate => {
            const row = document.createElement('tr');
            row.className = 'pje-report-plate';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = plate.id;
            checkbox.id = `pje-report-plate-${plate.id}`;
            checkbox.checked = selected.has(Number(plate.id));
            const choice = document.createElement('td');
            choice.append(checkbox);
            const plateCell = document.createElement('td');
            const title = document.createElement('label');
            title.htmlFor = checkbox.id;
            title.textContent = plate.placa;
            plateCell.append(title);
            const unitCell = document.createElement('td');
            unitCell.textContent = plate.bus || '-';
            const statusCell = document.createElement('td');
            const status = document.createElement('span');
            const statusKey = normalize(plate.estado);
            status.className = 'pje-report-plate__status' + (statusKey === 'ACTIVO' ? ' is-active' : '');
            status.textContent = statusKey === 'ACTIVO' ? 'Activo' : (statusKey === 'INACTIVO' ? 'Inactivo' : plate.estado || '-');
            statusCell.append(status);
            row.append(choice, plateCell, unitCell, statusCell);
            plateList.append(row);
        });
        if (!plates.length) {
            const empty = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.className = 'pje-report-plates__empty';
            cell.textContent = state ? 'No hay placas para esta seleccion.' : 'Cargando placas...';
            empty.append(cell);
            plateList.append(empty);
        }
        selectedCount.textContent = `${selected.size} placas`;
        saveButton.disabled = !state || saving || !dirty || !modalReady;
        if (focusedPlate) {
            const input = Array.from(plateList.querySelectorAll('input')).find(item => item.value === focusedPlate);
            if (input) input.focus({preventScroll: true});
            plateList.parentElement.parentElement.scrollTop = scrollTop;
        }
    }
    async function loadSupplier(id) {
        const current = ++sequence;
        state = null;
        selected = new Set();
        dirty = false;
        enabled.checked = false;
        fields.disabled = true;
        message(manageNotice, '');
        renderPlates();
        try {
            const data = await request({action: 'config', proveedor: id});
            if (current !== sequence) return;
            state = data;
            selected = new Set(data.seleccionadas.map(Number));
            enabled.checked = Number(data.proveedor.habilitado) === 1;
            search.value = '';
            selectedOnly.checked = false;
        } catch (error) {
            if (current !== sequence) return;
            message(manageNotice, error.message);
        } finally {
            if (current === sequence) {
                fields.disabled = false;
                renderPlates();
            }
        }
    }
    manageButton.addEventListener('click', async function () {
        manageButton.disabled = true;
        message(notice, '');
        try {
            const data = await request({action: 'suppliers'});
            refreshSuppliers(data.proveedores);
            manageSelect.replaceChildren();
            data.proveedores.forEach(supplier => manageSelect.add(new Option(`${supplier.nombre} - ${supplier.ruc}`, supplier.id)));
            if (!data.proveedores.length) throw new Error('No hay proveedores con un RUC valido en el catalogo.');
            manageSelect.value = select.value || String(data.proveedores[0].id);
            modal.show();
            await loadSupplier(manageSelect.value);
        } catch (error) {
            message(notice, error.message);
        } finally {
            manageButton.disabled = false;
        }
    });
    manageSelect.addEventListener('change', function () {
        if (dirty && !window.confirm('Descartar los cambios sin guardar de este proveedor?')) {
            manageSelect.value = state.proveedor.id;
            return;
        }
        loadSupplier(manageSelect.value);
    });
    plateList.addEventListener('change', function (event) {
        if (!state || event.target.type !== 'checkbox') return;
        const id = Number(event.target.value);
        if (event.target.checked) selected.add(id); else selected.delete(id);
        dirty = true;
        renderPlates();
    });
    enabled.addEventListener('change', function () { dirty = true; renderPlates(); });
    search.addEventListener('input', renderPlates);
    selectedOnly.addEventListener('change', renderPlates);
    modalElement.addEventListener('shown.bs.modal', function () {
        modalReady = true;
        renderPlates();
    });
    modalElement.addEventListener('hide.bs.modal', function (event) {
        if (saving || (dirty && !window.confirm('Descartar los cambios sin guardar?'))) event.preventDefault();
    });
    modalElement.addEventListener('hidden.bs.modal', function () {
        sequence++;
        modalReady = false;
        dirty = false;
        state = null;
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty || saving) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!state || !dirty || saving) return;
        saving = true;
        fields.disabled = true;
        saveButton.disabled = true;
        const original = saveButton.innerHTML;
        const originalWidth = saveButton.style.width;
        saveButton.style.width = `${saveButton.offsetWidth}px`;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando';
        message(manageNotice, '');
        try {
            const data = await request({}, {csrf: config.csrf, proveedor: Number(state.proveedor.id),
                habilitado: enabled.checked ? 1 : 0, placas: Array.from(selected), version: state.version});
            refreshSuppliers(data.proveedores, state.proveedor.id);
            dirty = false;
            saving = false;
            modal.hide();
            message(notice, data.message, true);
        } catch (error) {
            message(manageNotice, error.message);
        } finally {
            saving = false;
            fields.disabled = false;
            saveButton.innerHTML = original;
            saveButton.style.width = originalWidth;
            renderPlates();
        }
    });
})(window, document);
