(function () {
    const page = document.querySelector('.req24-page');
    if (!page) return;

    const apiUrl = page.dataset.api || 'requerimientos_cotizaciones_api.php';
    const csrf = page.dataset.csrf || '';
    const logoUrl = page.dataset.logo || '';
    const pdfUser = page.dataset.pdfUser || '';
    const pdfDni = page.dataset.pdfDni || '';
    const companyRuc = page.dataset.ruc || '';
    const isAdmin = page.dataset.admin === '1';
    const quoteModalEl = document.getElementById('req24QuoteModal');
    const requirementModalEl = document.getElementById('req24RequirementModal');
    const historyModalEl = document.getElementById('req24HistoryModal');
    const detailModalEl = document.getElementById('req24DetailModal');
    const quoteModal = quoteModalEl ? new bootstrap.Modal(quoteModalEl) : null;
    const requirementModal = requirementModalEl ? new bootstrap.Modal(requirementModalEl) : null;
    const historyModal = historyModalEl ? new bootstrap.Modal(historyModalEl) : null;
    const detailModal = detailModalEl ? new bootstrap.Modal(detailModalEl) : null;
    const quoteForm = document.getElementById('req24QuoteForm');
    const requirementForm = document.getElementById('req24RequirementForm');
    const detailPdfButton = document.getElementById('req24DetailPdf');
    let currentDetailRow = null;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const parseRow = (button) => {
        try {
            return JSON.parse(button.dataset.row || '{}');
        } catch (error) {
            return {};
        }
    };

    const setValue = (form, name, value) => {
        const field = form ? form.elements[name] : null;
        if (field) field.value = value ?? '';
    };

    const setCodePreview = (form, value) => {
        const preview = form ? form.querySelector('[data-req24-code-preview]') : null;
        if (preview) preview.textContent = value || 'Se generara al guardar';
    };

    const valueOrDash = (value) => escapeHtml(value || '-');

    const money = (value) => {
        const number = Number(value || 0);
        return Number.isFinite(number) ? `S/ ${number.toFixed(2)}` : 'S/ 0.00';
    };

    const areaLabel = (value) => {
        const labels = {
            ADMINISTRACION: 'Administracion',
            ALMACEN: 'Almacen',
            CONTABILIDAD: 'Contabilidad',
            FINANZAS: 'Finanzas',
            COMBUSTIBLE: 'Combustible',
            CALIDAD: 'Calidad',
            FLOTA: 'Flota',
            MANTENIMIENTO: 'Mantenimiento',
            OPERACIONES: 'Operaciones',
            PEAJES: 'Peajes',
            ENCOMIENDAS: 'Encomiendas',
            'RECURSOS HUMANOS': 'Recursos Humanos',
            RECURSOS_HUMANOS: 'Recursos Humanos',
            SISTEMAS: 'Sistemas',
            GERENCIA: 'Gerencia',
        };
        return labels[String(value || '').toUpperCase()] || valueOrDash(value);
    };

    const detailLabel = (icon, label) => `
        <span class="req24-detail-label">
            <i class="bi ${icon}"></i>
            ${escapeHtml(label)}
        </span>
    `;

    const normalizeMessage = (message) => {
        if (typeof message === 'string') {
            return message;
        }
        if (message && typeof message === 'object') {
            if (typeof message.message === 'string') {
                return message.message;
            }
            if (typeof message.error === 'string') {
                return message.error;
            }
            try {
                return JSON.stringify(message);
            } catch (error) {
                return 'No se pudo completar la operacion.';
            }
        }
        return String(message ?? '');
    };

    const showDialog = async (title, message, type) => {
        const cleanMessage = normalizeMessage(message);
        if (window.N360Dialog && typeof window.N360Dialog.alert === 'function') {
            await window.N360Dialog.alert(cleanMessage, { title, type, variant: type });
            return;
        }
        console[type === 'error' ? 'error' : 'log'](`${title}: ${cleanMessage}`);
    };

    const postForm = async (form, action, successTitle) => {
        const fd = new FormData(form);
        fd.append('action', action);
        fd.append('csrf_token', csrf);

        try {
            if (window.N360Loader) window.N360Loader.show('Guardando informacion...');
            const response = await fetch(apiUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('La respuesta del servidor no es valida. Revisa si PHP mostro un error interno.');
            }
            if (!response.ok || !data.ok) {
                throw new Error(normalizeMessage(data.message || 'No se pudo completar la operacion.'));
            }
            await showDialog(successTitle, data.message || 'Operacion completada.', 'success');
            window.location.reload();
        } catch (error) {
            await showDialog('Norte360', error.message || 'No se pudo completar la operacion.', 'error');
        } finally {
            if (window.N360Loader) window.N360Loader.hide();
        }
    };

    const cleanText = (value) => String(value ?? '').trim();
    const valueOrPlainDash = (value) => cleanText(value) || '-';
    const safeFilename = (value) => valueOrPlainDash(value)
        .replace(/[^a-z0-9_-]+/gi, '_')
        .replace(/^_+|_+$/g, '') || 'cotizacion';

    const downloadDetailNotePdf = async (row) => {
        if (window.N360Req24NotaPdf && typeof window.N360Req24NotaPdf.download === 'function') {
            await window.N360Req24NotaPdf.download(row, {
                showDialog,
                logoUrl,
                pdfUser,
                pdfDni,
                ruc: companyRuc
            });
            return;
        }

        await showDialog('PDF nota', 'No se encontro el formato de nota PDF. Actualiza la pagina e intenta nuevamente.', 'error');
    };
    document.querySelectorAll('.req24-new-quote').forEach((button) => {
        button.addEventListener('click', () => {
            if (!quoteForm || !quoteModal) return;
            quoteForm.reset();
            setValue(quoteForm, 'id', '');
            setValue(quoteForm, 'estado', 'PENDIENTE');
            setCodePreview(quoteForm, '');
            quoteModal.show();
        });
    });

    document.querySelectorAll('.req24-edit-quote').forEach((button) => {
        button.addEventListener('click', () => {
            if (!isAdmin || !quoteForm || !quoteModal) return;
            const row = parseRow(button);
            quoteForm.reset();
            setValue(quoteForm, 'id', row.id);
            setValue(quoteForm, 'codigo_interno', row.codigo_interno);
            setCodePreview(quoteForm, row.codigo_interno);
            setValue(quoteForm, 'cotizacion', row.cotizacion);
            setValue(quoteForm, 'solicitante', row.solicitante);
            setValue(quoteForm, 'cargo', row.cargo);
            setValue(quoteForm, 'area', row.area);
            setValue(quoteForm, 'estado', row.estado || 'PENDIENTE');
            setValue(quoteForm, 'comentario', row.comentario);
            quoteModal.show();
        });
    });

    document.querySelectorAll('.req24-edit-req').forEach((button) => {
        button.addEventListener('click', () => {
            if (!requirementForm || !requirementModal) return;
            const row = parseRow(button);
            requirementForm.reset();
            setValue(requirementForm, 'id', row.id);
            setValue(requirementForm, 'requerimiento_codigo', row.req_codigo);
            setValue(requirementForm, 'requerimiento_name', row.req_name);
            setValue(requirementForm, 'requerimiento_monto', row.req_monto);
            setValue(requirementForm, 'estado', row.estado || 'PENDIENTE');
            setValue(requirementForm, 'requerimiento_comentario', row.req_comentario);
            requirementModal.show();
        });
    });

    document.querySelectorAll('.req24-view').forEach((button) => {
        button.addEventListener('click', () => {
            const row = parseRow(button);
            currentDetailRow = row;
            const body = document.getElementById('req24DetailBody');
            if (!body) {
                return;
            }

            body.innerHTML = `
                <section class="req24-detail-head req24-detail-head--with-icons">
                    <div>
                        ${detailLabel('bi-upc-scan', 'Codigo interno')}
                        <strong>${valueOrDash(row.codigo_interno)}</strong>
                    </div>
                    <div>
                        ${detailLabel('bi-shield-check', 'Estado')}
                        <strong>${valueOrDash(row.estado)}</strong>
                    </div>
                    <div>
                        ${detailLabel('bi-diagram-3', 'Area')}
                        <strong>${areaLabel(row.area)}</strong>
                    </div>
                </section>
                <section class="req24-detail-grid">
                    <article>
                        ${detailLabel('bi-receipt', 'Requerimiento')}
                        <strong>${valueOrDash(row.cotizacion)}</strong>
                    </article>
                    <article>
                        ${detailLabel('bi-person-badge', 'Solicitante')}
                        <strong>${valueOrDash(row.solicitante)}</strong>
                    </article>
                    <article>
                        ${detailLabel('bi-briefcase', 'Cargo')}
                        <strong>${valueOrDash(row.cargo)}</strong>
                    </article>
                    <article>
                        ${detailLabel('bi-clock-history', 'Registro')}
                        <strong>${valueOrDash(row.fecha_registro)}</strong>
                        <small>${valueOrDash(row.usuario_registro)}</small>
                    </article>
                    <article class="req24-detail-wide req24-detail-note">
                        ${detailLabel('bi-chat-left-text', 'Comentario de requerimiento')}
                        <p>${valueOrDash(row.comentario)}</p>
                    </article>
                </section>
                <section class="req24-detail-section">
                    <div class="req24-detail-title">
                        <div class="req24-detail-title-main">
                            <span class="req24-section-icon"><i class="bi bi-journal-check"></i></span>
                            <div>
                                <span>Cotización</span>
                                <small>Datos completados posteriormente</small>
                            </div>
                        </div>
                        <strong>${valueOrDash(row.req_codigo || row.req_name)}</strong>
                    </div>
                    <div class="req24-detail-grid req24-detail-grid--compact">
                        <article>
                            ${detailLabel('bi-hash', 'Codigo cot.')}
                            <strong>${valueOrDash(row.req_codigo)}</strong>
                        </article>
                        <article>
                            ${detailLabel('bi-card-text', 'Nombre')}
                            <strong>${valueOrDash(row.req_name)}</strong>
                        </article>
                        <article>
                            ${detailLabel('bi-cash-stack', 'Monto')}
                            <strong>${money(row.req_monto)}</strong>
                        </article>
                        <article>
                            ${detailLabel('bi-arrow-repeat', 'Ultima actualizacion')}
                            <strong>${valueOrDash(row.fecha_update)}</strong>
                            <small>${valueOrDash(row.usuario_update)}</small>
                        </article>
                        <article class="req24-detail-wide req24-detail-note">
                            ${detailLabel('bi-chat-square-dots', 'Comentario de la cotización')}
                            <p>${valueOrDash(row.req_comentario)}</p>
                        </article>
                    </div>
                </section>
            `;
            detailModal?.show();
        });
    });
    if (detailPdfButton) {
        detailPdfButton.addEventListener('click', () => {
            downloadDetailNotePdf(currentDetailRow);
        });
    }
    document.querySelectorAll('.req24-history').forEach((button) => {
        button.addEventListener('click', () => {
            if (!historyModal) return;
            const row = parseRow(button);
            const target = document.getElementById('req24HistoryList');
            let history = [];
            try {
                history = JSON.parse(row.histor || '[]');
            } catch (error) {
                history = [];
            }

            if (!Array.isArray(history) || history.length === 0) {
                target.innerHTML = '<div class="req24-empty">Sin historial registrado todavia.</div>';
            } else {
                target.innerHTML = history.slice().reverse().map((item) => `
                    <article class="req24-history-item">
                        <strong>${escapeHtml(item.accion || 'Movimiento')}</strong>
                        <small>${escapeHtml(item.fecha || '')} | ${escapeHtml(item.usuario || 'Usuario')}</small>
                        <p class="mb-0">Estado: ${escapeHtml(item.estado_anterior || '-')} -> ${escapeHtml(item.estado_nuevo || '-')}</p>
                    </article>
                `).join('');
            }
            historyModal.show();
        });
    });

    if (quoteForm) {
        quoteForm.addEventListener('submit', (event) => {
            event.preventDefault();
            postForm(quoteForm, 'save_quote', 'Cotizacion guardada');
        });
    }

    if (requirementForm) {
        requirementForm.addEventListener('submit', (event) => {
            event.preventDefault();
            postForm(requirementForm, 'save_requirement', 'Requerimiento guardado');
        });
    }
})();
