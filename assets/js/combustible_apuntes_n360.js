(function () {
    const sinMovimiento = 'sin-movimiento';

    const opcionesMovimiento = (select, movimientos, iniciales) => {
        const opciones = iniciales.map(([texto, valor]) => new Option(texto, valor));
        for (const [id, etiqueta] of movimientos) {
            opciones.push(new Option(etiqueta, String(id)));
        }
        select.replaceChildren(...opciones);
    };

    const init = (filasVisibles) => {
        const dialog = document.querySelector('[data-comb-apuntes]');
        const botonAbrir = document.querySelector('[data-comb-apuntes-open]');
        const configuracion = document.querySelector('[data-comb-apuntes-config]');
        if (!dialog || !botonAbrir || !configuracion) return;
        const { apuntes, error: errorInicial, csrf } = JSON.parse(configuracion.textContent);
        const porId = new Map(apuntes.map((apunte) => [apunte.id_apunte, apunte]));
        const filtro = dialog.querySelector('[data-comb-apuntes-filtro]');
        const nuevo = dialog.querySelector('[data-comb-apuntes-nuevo]');
        const cerrar = dialog.querySelector('[data-comb-apuntes-close]');
        const formulario = dialog.querySelector('[data-comb-apuntes-form]');
        const titulo = formulario.querySelector('[data-comb-apuntes-titulo]');
        const movimiento = formulario.elements.namedItem('id_movimiento');
        const nota = formulario.elements.namedItem('nota');
        const tbody = dialog.querySelector('tbody');
        const error = dialog.querySelector('[data-comb-apuntes-error]');
        const estado = dialog.querySelector('[data-comb-apuntes-status]');
        let movimientos = new Map();
        let editando = null;
        let reintento = null;
        let guardando = false;

        const renderizar = (mensaje = '') => {
            const seleccionado = filtro.value;
            const visibles = [...porId.values()]
                .filter((apunte) => apunte.id_movimiento === null
                    ? (!seleccionado || seleccionado === sinMovimiento)
                    : (movimientos.has(apunte.id_movimiento) && (!seleccionado || seleccionado === String(apunte.id_movimiento))))
                .sort((a, b) => b.id_apunte - a.id_apunte);
            tbody.replaceChildren();
            for (const apunte of visibles) {
                const fila = tbody.insertRow();
                fila.insertCell().textContent = apunte.id_movimiento === null ? 'Sin movimiento' : movimientos.get(apunte.id_movimiento);
                fila.insertCell().textContent = apunte.nota;
                fila.insertCell().textContent = apunte.autor;
                const fecha = fila.insertCell();
                fecha.textContent = apunte.creado_en;
                if (apunte.editado_en) {
                    const edicion = document.createElement('small');
                    edicion.textContent = `Editado ${apunte.editado_en}`;
                    fecha.append(edicion);
                }
                const acciones = fila.insertCell();
                if (csrf && apunte.editable) {
                    const grupo = document.createElement('div');
                    grupo.className = 'comb-apuntes-acciones';
                    for (const [accion, etiqueta] of [['editar', 'Editar'], ['archivar', 'Archivar']]) {
                        const boton = document.createElement('button');
                        boton.type = 'button';
                        boton.className = 'stock-btn stock-btn--soft';
                        boton.dataset.apunteAccion = accion;
                        boton.dataset.apunteId = String(apunte.id_apunte);
                        boton.textContent = etiqueta;
                        grupo.append(boton);
                    }
                    acciones.append(grupo);
                }
            }
            let resumen = '';
            if (!errorInicial) {
                if (!visibles.length) resumen = 'No hay apuntes para la selección actual.';
                else resumen = visibles.length === 1 ? '1 apunte' : `${visibles.length} apuntes`;
            }
            estado.textContent = [mensaje, resumen].filter(Boolean).join(' ');
        };

        const abrirEditor = (apunte = null) => {
            editando = apunte;
            reintento = null;
            error.textContent = '';
            titulo.textContent = apunte ? 'Editar apunte' : 'Nuevo apunte';
            nota.value = apunte?.nota || '';
            const id = apunte ? apunte.id_movimiento : filtro.value;
            movimiento.value = id === null || id === sinMovimiento ? '' : String(id);
            movimiento.disabled = Boolean(apunte);
            formulario.hidden = false;
            nota.focus();
        };

        const enviar = async (campos) => {
            guardando = true;
            error.textContent = '';
            dialog.setAttribute('aria-busy', 'true');
            const controles = [...dialog.querySelectorAll('button, select, textarea')].filter((control) => !control.disabled);
            controles.forEach((control) => { control.disabled = true; });
            let foco = null;
            try {
                const datos = new FormData();
                datos.set('csrf', csrf);
                Object.entries(campos).forEach(([nombre, valor]) => datos.set(nombre, valor === null ? '' : String(valor)));
                const response = await fetch(location.pathname, { method: 'POST', credentials: 'same-origin', body: datos });
                const respuesta = await response.json();
                if (!response.ok || !respuesta.ok) {
                    throw new Error(respuesta.message || 'No se pudo guardar el cambio.');
                }
                if (campos.action === 'archivar_apunte') {
                    if (respuesta.id_archivado !== campos.id_apunte) throw new Error('No se pudo confirmar que se archivó el apunte.');
                    porId.delete(respuesta.id_archivado);
                    if (editando?.id_apunte === respuesta.id_archivado) formulario.hidden = true;
                    renderizar('Apunte archivado.');
                    foco = formulario.hidden ? filtro : nota;
                } else {
                    const apunte = respuesta.apunte;
                    if (!apunte || apunte.id_movimiento !== campos.id_movimiento || !Number.isInteger(apunte.id_apunte)
                        || (campos.action === 'editar_apunte' && apunte.id_apunte !== campos.id_apunte)) {
                        throw new Error('No se pudo confirmar el guardado.');
                    }
                    porId.set(apunte.id_apunte, apunte);
                    if (filtro.value) {
                        filtro.value = apunte.id_movimiento === null ? sinMovimiento : String(apunte.id_movimiento);
                    }
                    formulario.hidden = true;
                    renderizar('Apunte guardado.');
                    foco = nuevo;
                }
            } catch (excepcion) {
                error.textContent = excepcion instanceof SyntaxError || excepcion instanceof TypeError
                    ? 'No se pudo confirmar el cambio. Puedes reintentar desde el modal.'
                    : (excepcion.message || 'No se pudo guardar el cambio.');
                foco = formulario.hidden ? filtro : nota;
            } finally {
                guardando = false;
                dialog.removeAttribute('aria-busy');
                controles.forEach((control) => { control.disabled = false; });
                foco?.focus({preventScroll: true});
            }
        };

        botonAbrir.addEventListener('click', () => {
            movimientos = new Map(filasVisibles().map((fila) => {
                const celdas = fila.cells;
                const id = Number(celdas[0].textContent);
                const etiqueta = [`#${id}`, celdas[5].textContent.trim(), celdas[1].textContent.trim()].join(' · ');
                return [id, etiqueta];
            }));
            opcionesMovimiento(filtro, movimientos, [['Todos los apuntes', ''], ['Sin movimiento', sinMovimiento]]);
            opcionesMovimiento(movimiento, movimientos, [['Sin movimiento', '']]);
            nuevo.hidden = !csrf;
            nuevo.disabled = Boolean(errorInicial);
            filtro.disabled = Boolean(errorInicial);
            formulario.hidden = true;
            error.textContent = errorInicial;
            renderizar();
            dialog.showModal();
            (filtro.disabled ? cerrar : filtro).focus();
        });

        const cerrarDialogo = () => {
            if (guardando) return;
            dialog.close();
            botonAbrir.focus({preventScroll: true});
        };
        cerrar.addEventListener('click', cerrarDialogo);
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            cerrarDialogo();
        });
        filtro.addEventListener('change', () => renderizar());
        nuevo.addEventListener('click', () => abrirEditor());
        formulario.querySelector('[data-comb-apuntes-cancelar]').addEventListener('click', () => {
            formulario.hidden = true;
            error.textContent = '';
            nuevo.focus();
        });

        tbody.addEventListener('click', (event) => {
            const boton = event.target.closest('[data-apunte-accion]');
            const apunte = porId.get(Number(boton?.dataset.apunteId));
            if (guardando || !apunte?.editable) return;
            if (boton.dataset.apunteAccion === 'editar') {
                abrirEditor(apunte);
                return;
            }
            const referencia = apunte.id_movimiento === null ? 'sin movimiento' : `del movimiento #${apunte.id_movimiento}`;
            if (!window.confirm(`¿Archivar este apunte ${referencia}?`)) return;
            enviar({
                action: 'archivar_apunte', id_apunte: apunte.id_apunte,
                id_movimiento: apunte.id_movimiento, version: apunte.version
            });
        });

        formulario.addEventListener('submit', (event) => {
            event.preventDefault();
            if (guardando || !csrf || !formulario.reportValidity()) return;
            const idMovimiento = movimiento.value === '' ? null : Number(movimiento.value);
            const texto = nota.value.trim();
            if (!texto || (idMovimiento !== null && !movimientos.has(idMovimiento))) {
                error.textContent = 'Escribe un apunte y verifica el movimiento asociado.';
                return;
            }
            const campos = { id_movimiento: idMovimiento, nota: texto };
            if (editando) {
                Object.assign(campos, { action: 'editar_apunte', id_apunte: editando.id_apunte, version: editando.version });
            } else {
                if (reintento?.id !== idMovimiento || reintento?.texto !== texto) {
                    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
                    const clave = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
                    reintento = { id: idMovimiento, texto, clave };
                }
                Object.assign(campos, { action: 'crear_apunte', clave_solicitud: reintento.clave });
            }
            enviar(campos);
        });
    };

    window.N360CombustibleApuntes = { init };
})();
