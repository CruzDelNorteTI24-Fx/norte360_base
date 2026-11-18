(function () {
    const normalize = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    const report = window.N360_COMBUSTIBLE_REPORT || {};
    const rows = Array.from(document.querySelectorAll('[data-combustible-row]'));
    const searchInput = document.querySelector('[data-combustible-search]');
    const countNode = document.querySelector('[data-combustible-visible-count]');
    const pillNode = document.querySelector('[data-combustible-visible-pill]');
    const table = document.querySelector('[data-combustible-table]');
    const hideAdditionalPrice = document.querySelector('[data-comb-hide-additional-price]');
    const showOwners = document.querySelector('[data-comb-show-owners]');
    const showClassification = document.querySelector('[data-comb-show-classification]');

    const visibleRows = () => rows.filter((row) => row.style.display !== 'none');
    const clean = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const limit = (value, size) => {
        const text = clean(value);
        return text.length > size ? `${text.slice(0, size - 1)}...` : text;
    };

    const updateCount = () => {
        const count = visibleRows().length;
        const formatted = new Intl.NumberFormat('es-PE').format(count);
        if (countNode) countNode.textContent = formatted;
        if (pillNode) pillNode.textContent = `${formatted} registros`;
    };

    const applySearch = () => {
        const needle = normalize(searchInput ? searchInput.value : '');
        rows.forEach((row) => {
            const blob = normalize(row.dataset.search || row.textContent || '');
            row.style.display = !needle || blob.includes(needle) ? '' : 'none';
        });
        updateCount();
    };

    const applyAdditionalPriceVisibility = () => {
        table?.querySelectorAll('[data-comb-additional-price]').forEach((part) => {
            part.hidden = Boolean(hideAdditionalPrice?.checked);
        });
    };

    const applyOptionalColumnsVisibility = () => {
        const show = Boolean(showOwners?.checked);
        table?.querySelectorAll('[data-comb-owner-column]').forEach((cell) => {
            cell.hidden = !show;
        });
        table?.classList.toggle('comb-table--owners', show);
        const classificationVisible = Boolean(showClassification?.checked);
        table?.querySelectorAll('[data-comb-classification-column]').forEach((cell) => {
            cell.hidden = !classificationVisible;
        });
        table?.classList.toggle('comb-table--classification', classificationVisible);
        const emptyCell = table?.querySelector('[data-combustible-empty] td');
        if (emptyCell) emptyCell.colSpan = table.querySelectorAll('thead th:not([hidden])').length;
    };

    const getCellExportText = (cell) => {
        if (cell.dataset.combExportText) return clean(cell.dataset.combExportText);
        if (!cell.querySelector('[data-comb-additional-price][hidden]')) return clean(cell.textContent);
        const copy = cell.cloneNode(true);
        copy.querySelectorAll('[data-comb-additional-price][hidden]').forEach((part) => part.remove());
        return clean(copy.textContent) || '-';
    };

    const getTablePayload = () => {
        if (!table) return { head: [], body: [], columns: [] };
        const headers = Array.from(table.querySelectorAll('thead th'));
        const columns = headers.flatMap((th, index) => th.hidden ? [] : [index]);
        const head = columns.map((index) => clean(headers[index].textContent));
        const body = visibleRows().map((row) => columns.map((index) => getCellExportText(row.children[index])));
        return { head, body, columns };
    };

    const getPdfRows = (payload) => payload.body.map((row) => row.map((cell, index) => {
        const column = payload.columns[index];
        if (column === 4) return limit(cell, 86);
        if (column === 5) return limit(cell, 110);
        if (column === 13) return limit(cell, 160);
        return cell || '-';
    }));

    const toast = (message, type = 'info') => {
        if (window.N360Loader && typeof window.N360Loader.toast === 'function') {
            window.N360Loader.toast(message, type);
            return;
        }
        console[type === 'danger' ? 'error' : 'log'](message);
    };

    const initEmissionEditor = () => {
        const dialog = document.querySelector('[data-comb-emision-dialog]');
        if (!dialog) return;
        const form = dialog.querySelector('[data-comb-emision-form]');
        const dateInput = form.elements.namedItem('fecha_emision');
        const idInput = form.elements.namedItem('id_mov');
        const movement = dialog.querySelector('[data-comb-emision-movement]');
        const message = dialog.querySelector('[data-comb-emision-message]');
        const buttons = Array.from(form.querySelectorAll('button'));
        let sourceButton = null;
        let saving = false;

        document.querySelectorAll('[data-comb-emision-add]').forEach((button) => {
            button.addEventListener('click', () => {
                if (saving || dialog.open) return;
                sourceButton = button;
                idInput.value = button.dataset.movementId;
                dateInput.value = '';
                movement.textContent = 'Movimiento #' + idInput.value;
                message.textContent = '';
                dialog.showModal();
                dateInput.focus();
            });
        });
        dialog.querySelectorAll('[data-comb-emision-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!saving) dialog.close();
            });
        });
        dialog.addEventListener('cancel', (event) => {
            if (saving) event.preventDefault();
        });
        dialog.addEventListener('close', () => {
            if (sourceButton?.isConnected) sourceButton.focus({ preventScroll: true });
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving || !form.reportValidity()) return;
            const payload = new FormData(form);
            saving = true;
            message.textContent = '';
            form.setAttribute('aria-busy', 'true');
            buttons.forEach((button) => { button.disabled = true; });
            dateInput.disabled = true;
            try {
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: payload
                });
                const data = await response.json();
                if (!response.ok || !data?.ok) {
                    throw new Error(data?.message || 'No se pudo guardar la fecha de emisión.');
                }
                if (String(data.id_mov) !== idInput.value || !/^\d{2}\/\d{2}\/\d{4}$/.test(data.fecha_display)
                    || !/^\d{4}-\d{2}-\d{2}$/.test(data.fecha_emision)) {
                    throw new Error('No se pudo confirmar la fecha guardada. Actualiza el historial.');
                }
                const cell = sourceButton.closest('td');
                const row = sourceButton.closest('[data-combustible-row]');
                cell.textContent = data.fecha_display;
                cell.dataset.combExportText = data.fecha_display;
                row.dataset.fechaEmision = data.fecha_emision;
                row.dataset.search = (row.dataset.search || '') + ' ' + data.fecha_emision + ' ' + data.fecha_display;
                applySearch();
                dialog.close();
                cell.tabIndex = -1;
                cell.focus({ preventScroll: true });
                toast('Fecha de emisión guardada.', 'success');
            } catch (error) {
                message.textContent = error instanceof SyntaxError
                    ? 'No se pudo confirmar el guardado. Actualiza el historial antes de reintentar.'
                    : (error.message || 'No se pudo guardar la fecha de emisión.');
            } finally {
                saving = false;
                form.removeAttribute('aria-busy');
                buttons.forEach((button) => { button.disabled = false; });
                dateInput.disabled = false;
            }
        });
    };

    const drawFilters = (doc, left, top, width, filters) => {
        doc.setFillColor(245, 248, 251);
        doc.setDrawColor(214, 226, 239);
        doc.roundedRect(left, top, width, 18, 2, 2, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.2);
        doc.setTextColor(18, 42, 64);
        doc.text('Filtros aplicados', left + 4, top + 5.8);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.1);
        doc.setTextColor(71, 85, 105);
        const lines = doc.splitTextToSize(filters || '-', width - 8);
        doc.text(lines.slice(0, 2), left + 4, top + 10.5);
    };

    const tipoResumen = { ENTRADA: 'Entrada', SALIDA: 'Salida' };
    const formatoCantidad = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    const formatoImporte = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
    const formatoMes = new Intl.DateTimeFormat('es-PE', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    const fechaResumen = (iso) => iso ? iso.split('-').reverse().join('/') : 'Sin emisión';
    const totalesResumen = (grupo) => [
        formatoCantidad.format(grupo.cantidad / 1000),
        formatoImporte.format(grupo.monto / 10000)
    ];

    const movimientosResumen = () => Array.from(table?.querySelectorAll('tbody tr[data-combustible-row]') || [])
        .filter((row) => Object.hasOwn(tipoResumen, row.dataset.tipo)
            && !row.hidden
            && row.getClientRects().length > 0
            && window.getComputedStyle(row).visibility === 'visible')
        .map((row) => {
            const { tipo, fechaEmision, productoId, cantidad, monto } = row.dataset;
            const cells = row.children;
            return {
                id: Number(cells[0].textContent),
                tipo,
                fechaEmision,
                productoId,
                producto: clean(cells[5].textContent),
                unidad: clean(cells[6].textContent),
                nota: getCellExportText(cells[3]),
                grifo: clean(cells[4].textContent),
                placa: clean(cells[7].textContent),
                observacion: getCellExportText(cells[13]),
                // Los enteros conservan los decimales de las columnas MySQL al sumar.
                cantidad: Math.round(Number(cantidad) * 1000),
                monto: Math.round(Number(monto) * 10000)
            };
        });

    const compararProductoTipo = (a, b) => a.producto.localeCompare(b.producto, 'es-PE')
        || Number(a.productoId) - Number(b.productoId) || a.tipo.localeCompare(b.tipo);

    const agruparResumen = (movimientos) => {
        const grupos = new Map();
        movimientos.forEach((mov) => {
            const key = JSON.stringify([mov.fechaEmision, mov.productoId, mov.tipo]);
            if (!grupos.has(key)) grupos.set(key, { ...mov, cantidad: 0, monto: 0, movimientos: [] });
            const grupo = grupos.get(key);
            grupo.cantidad += mov.cantidad;
            grupo.monto += mov.monto;
            grupo.movimientos.push(mov);
        });
        return Array.from(grupos.values()).sort((a, b) =>
            (a.fechaEmision || '9999-99-99').localeCompare(b.fechaEmision || '9999-99-99')
            || compararProductoTipo(a, b));
    };

    const sumarPorProductoTipo = (grupos) => {
        const totales = new Map();
        for (const grupo of grupos) {
            const { productoId, producto, unidad, tipo, cantidad, monto } = grupo;
            const clave = JSON.stringify([productoId, unidad, tipo]);
            if (!totales.has(clave)) {
                totales.set(clave, { productoId, producto, unidad, tipo, cantidad: 0, monto: 0 });
            }
            const total = totales.get(clave);
            total.cantidad += cantidad;
            total.monto += monto;
        }
        return Array.from(totales.values()).sort(compararProductoTipo);
    };

    const prepararResumenMensual = (grupos) => {
        const meses = new Map();
        const sinEmision = [];
        for (const grupo of grupos) {
            if (!grupo.fechaEmision) {
                sinEmision.push(grupo);
                continue;
            }
            const mes = grupo.fechaEmision.slice(0, 7);
            if (!meses.has(mes)) meses.set(mes, []);
            meses.get(mes).push(grupo);
        }
        const secciones = Array.from(meses, ([mes, diarios]) => {
            const nombre = formatoMes.format(new Date(`${mes}-01T00:00:00Z`));
            return {
                titulo: nombre[0].toUpperCase() + nombre.slice(1),
                diarios,
                totales: sumarPorProductoTipo(diarios)
            };
        });
        if (sinEmision.length) secciones.push({ titulo: 'Sin emisión', diarios: sinEmision, totales: [] });
        if (meses.size > 1) {
            secciones.push({
                titulo: 'Total de los meses mostrados',
                diarios: [],
                totales: sumarPorProductoTipo(Array.from(meses.values()).flat()),
                acumulado: true
            });
        }
        return secciones;
    };

    const filasResumenMensual = (secciones, tipo) => secciones.flatMap(({ titulo, diarios, totales, acumulado }) => {
        const delTipo = (grupo) => !tipo || grupo.tipo === tipo;
        const filas = diarios.filter(delTipo).map((grupo) => ({
            texto: fechaResumen(grupo.fechaEmision), clase: 'diario', grupo
        }));
        const sumas = totales.filter(delTipo).map((grupo) => ({
            texto: acumulado ? 'Total' : 'Subtotal mensual', clase: acumulado ? 'total' : 'subtotal', grupo
        }));
        if (!filas.length && !sumas.length) return [];
        return [{ texto: titulo, clase: acumulado ? 'acumulado' : 'mes' }, ...filas, ...sumas];
    });

    const exportResumenPdf = async (grupos, secciones, contexto) => {
        const doc = await window.N360PDF.createDocument({
            orientation: 'landscape',
            title: 'COMBUSTIBLE POR FECHA DE EMISIÓN',
            docCode: report.docCode || 'COM_RPT_HIST_COMB',
            userName: report.generatedBy || '',
            dni: report.dni || '',
            logoLeft: report.logoLeft,
            logoRight: report.logoRight,
            useCover: false,
            content: function (doc) {
                const left = 12.7;
                const right = 12.7;
                const width = doc.internal.pageSize.getWidth() - left - right;
                const layout = {
                    margin: { left, right, top: 32, bottom: 22 },
                    rowPageBreak: 'avoid',
                    styles: { fontSize: 8, cellPadding: 1.4, valign: 'middle', overflow: 'linebreak' },
                    headStyles: { fillColor: [18, 42, 64], textColor: 255 },
                    alternateRowStyles: { fillColor: [245, 248, 252] }
                };
                const titulo = (texto, y) => {
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(12);
                    doc.setTextColor(18, 42, 64);
                    doc.text(texto, left, y);
                };
                titulo('Resumen por fecha de emisión', 36);
                drawFilters(doc, left, 40, width, contexto.filtros);
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(8);
                doc.text(contexto.alcance, left, 63);
                const filasResumen = filasResumenMensual(secciones);
                const estilosResumen = {
                    mes: { fillColor: [224, 235, 245], fontStyle: 'bold' },
                    subtotal: { fillColor: [237, 246, 242], fontStyle: 'bold' },
                    acumulado: { fillColor: [30, 65, 90], textColor: 255, fontStyle: 'bold' },
                    total: { fillColor: [223, 239, 231], fontStyle: 'bold' }
                };
                doc.autoTable({
                    ...layout,
                    startY: 70,
                    head: [['Fecha de emisión', 'Tipo', 'Producto', 'Und.', 'Cantidad', 'Importe (S/)']],
                    body: filasResumen.map(({ texto, grupo }) => grupo
                        ? [texto, tipoResumen[grupo.tipo], grupo.producto, grupo.unidad, ...totalesResumen(grupo)]
                        : [{ content: texto, colSpan: 6 }]),
                    columnStyles: { 4: { halign: 'right' }, 5: { halign: 'right' } },
                    didParseCell: (data) => {
                        if (data.section !== 'body') return;
                        const estilo = estilosResumen[filasResumen[data.row.index].clase];
                        if (estilo) Object.assign(data.cell.styles, estilo);
                    }
                });

                const detalle = [];
                grupos.forEach((grupo) => {
                    const fecha = fechaResumen(grupo.fechaEmision);
                    const tipo = tipoResumen[grupo.tipo];
                    grupo.movimientos.slice().sort((a, b) => a.id - b.id).forEach((mov) => {
                        detalle.push([
                            fecha, mov.id, tipo, mov.producto, mov.unidad, mov.nota,
                            mov.grifo, mov.placa, mov.observacion,
                            ...totalesResumen(mov)
                        ]);
                    });
                    detalle.push([
                        {
                            content: `Subtotal · ${fecha} · ${tipo} · ${grupo.producto} ${grupo.unidad}`,
                            colSpan: 9
                        },
                        ...totalesResumen(grupo)
                    ]);
                });
                doc.addPage();
                titulo('Detalle de movimientos por fecha de emisión', 36);
                doc.autoTable({
                    ...layout,
                    startY: 41,
                    head: [['Fecha de emisión', 'Mov.', 'Tipo', 'Producto', 'Und.', 'Nota', 'Grifo', 'Placa',
                        'Observación', 'Cantidad', 'Importe (S/)']],
                    body: detalle,
                    styles: { ...layout.styles, fontSize: 7, cellPadding: 1.1 },
                    columnStyles: Object.fromEntries([25, 13, 17, 39, 11, 21, 26, 24, 51, 21, 23]
                        .map((cellWidth, column) => [column, { cellWidth, ...(column >= 9 ? { halign: 'right' } : {}) }])),
                    didParseCell: (data) => {
                        if (data.section === 'body' && data.row.raw[0].colSpan === 9) {
                            data.cell.styles.fillColor = [221, 235, 248];
                            data.cell.styles.fontStyle = 'bold';
                        }
                    }
                });
            }
        });
        const sufijo = (report.fileBase || '').replace(/^Movimientos_Combustible_/, '');
        doc.save(`Combustible_por_emision${sufijo ? '_' + sufijo : ''}.pdf`);
    };

    const initResumenEmision = () => {
        const dialog = document.querySelector('[data-comb-resumen]');
        const open = document.querySelector('[data-comb-resumen-open]');
        if (!dialog || !open) return;
        const tipo = dialog.querySelector('[data-comb-resumen-tipo]');
        const producto = dialog.querySelector('[data-comb-resumen-producto]');
        const pdf = dialog.querySelector('[data-comb-resumen-pdf]');
        const status = dialog.querySelector('[data-comb-resumen-status]');
        const error = dialog.querySelector('[data-comb-resumen-error]');
        const paneles = Array.from(dialog.querySelectorAll('[data-comb-resumen-panel]'));
        let movimientos = [];
        let grupos = [];
        let secciones = [];
        let exportando = false;

        const render = () => {
            grupos = agruparResumen(movimientos.filter((mov) =>
                (tipo.value === 'TODOS' || mov.tipo === tipo.value)
                && (producto.value === 'TODOS' || mov.productoId === producto.value)));
            secciones = prepararResumenMensual(grupos);
            for (const panel of paneles) {
                const tipoPanel = panel.dataset.combResumenPanel;
                panel.hidden = tipo.value !== 'TODOS' && tipo.value !== tipoPanel;
                const tbody = panel.querySelector('tbody');
                tbody.replaceChildren();
                if (panel.hidden) continue;
                filasResumenMensual(secciones, tipoPanel).forEach(({ texto, clase, grupo }) => {
                    const fila = tbody.insertRow();
                    fila.className = `comb-resumen-fila--${clase}`;
                    if (!grupo) {
                        const cell = fila.insertCell();
                        cell.colSpan = 5;
                        cell.textContent = texto;
                        return;
                    }
                    [texto, grupo.producto, grupo.unidad, ...totalesResumen(grupo)]
                        .forEach((texto, index) => {
                            const cell = fila.insertCell();
                            cell.textContent = texto;
                            if (index >= 3) cell.className = 'stock-num';
                        });
                });
                if (!tbody.rows.length) {
                    const cell = tbody.insertRow().insertCell();
                    cell.colSpan = 5;
                    cell.textContent = 'Sin movimientos para estos filtros.';
                }
            }
            const cantidad = grupos.reduce((total, grupo) => total + grupo.movimientos.length, 0);
            status.textContent = `${cantidad} ${cantidad === 1 ? 'movimiento' : 'movimientos'}`;
            pdf.disabled = exportando || !grupos.length;
            error.hidden = true;
        };

        open.addEventListener('click', () => {
            movimientos = movimientosResumen();
            const productos = new Map(movimientos.map((mov) => [mov.productoId, mov.producto]));
            producto.replaceChildren(new Option('Todos los productos', 'TODOS'));
            Array.from(productos).sort((a, b) => a[1].localeCompare(b[1], 'es-PE'))
                .forEach(([id, label]) => producto.add(new Option(label, id)));
            const tiposVisibles = new Set(movimientos.map((mov) => mov.tipo));
            tipo.value = tiposVisibles.size === 1 ? movimientos[0].tipo : 'TODOS';
            producto.value = productos.size === 1 ? movimientos[0].productoId : 'TODOS';
            render();
            dialog.showModal();
            tipo.focus();
        });
        const close = () => {
            dialog.close();
            open.focus({ preventScroll: true });
        };
        dialog.querySelector('[data-comb-resumen-close]').addEventListener('click', close);
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            close();
        });
        tipo.addEventListener('change', render);
        producto.addEventListener('change', render);
        pdf.addEventListener('click', async () => {
            if (!grupos.length || exportando) return;
            error.hidden = true;
            if (!window.N360PDF || !window.jspdf?.jsPDF) {
                error.textContent = 'No se pudo cargar el generador PDF.';
                error.hidden = false;
                return;
            }
            const filtros = [
                tipo.selectedOptions[0].text, producto.selectedOptions[0].text,
                report.period, ...(report.filters || []), searchInput?.value.trim()
            ].filter(Boolean).join(' | ');
            const alcance = dialog.querySelector('[data-comb-resumen-limit]')
                ? 'Movimientos visibles del historial · Límite de 3 000 filas alcanzado.'
                : 'Movimientos visibles del historial';
            exportando = true;
            pdf.disabled = true;
            try {
                await exportResumenPdf(grupos, secciones, { filtros, alcance });
            } catch (exception) {
                console.error(exception);
                error.textContent = 'No se pudo generar el PDF del resumen.';
                error.hidden = false;
            } finally {
                exportando = false;
                pdf.disabled = !grupos.length;
            }
        });
    };

    const exportPdf = async () => {
        const payload = getTablePayload();
        if (!payload.body.length) {
            toast('No hay registros visibles para exportar.', 'warning');
            return;
        }

        if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
            toast('No se pudo cargar el generador PDF.', 'danger');
            return;
        }

        try {
            const filters = Array.isArray(report.filters) ? report.filters.filter(Boolean).join(' | ') : '';
            const doc = await window.N360PDF.createDocument({
                orientation: 'landscape',
                title: report.title || 'HISTORIAL DE COMBUSTIBLE',
                secondTitle: report.subtitle || 'Movimientos operativos',
                description: 'Reporte de movimientos de combustible con columnas equivalentes al sistema de escritorio.',
                docCode: report.docCode || 'COM_RPT_HIST_COMB',
                userName: report.generatedBy || '',
                dni: report.dni || '',
                logoLeft: report.logoLeft,
                logoRight: report.logoRight,
                useCover: false,
                content: function (doc) {
                    const left = 12.7;
                    const right = 12.7;
                    const width = doc.internal.pageSize.getWidth() - left - right;
                    const y = 34;

                    drawFilters(doc, left, y, width, `Periodo: ${report.period || '-'} | ${filters || '-'}`);

                    const ownersVisible = payload.columns.includes(8);
                    const classificationVisible = payload.columns.includes(14);
                    const columnWidths = classificationVisible
                        ? (ownersVisible
                            ? [8, 20, 14, 16, 22, 27, 7, 20, 22, 15, 14, 16, 21, 25, 24]
                            : [9, 21, 15, 18, 24, 31, 8, 21, 0, 16, 15, 17, 22, 28, 26])
                        : (ownersVisible
                            ? [9, 21, 15, 18, 24, 31, 8, 21, 24, 16, 15, 17, 22, 30, 0]
                            : [10, 22, 16, 20, 28, 38, 9, 22, 0, 17, 16, 18, 23, 32, 0]);
                    const columnStyles = Object.fromEntries(payload.columns.map((column, index) => [index, {
                        cellWidth: columnWidths[column],
                        ...([9, 10, 11].includes(column) ? { halign: 'right' } : {})
                    }]));

                    doc.autoTable({
                        head: [payload.head],
                        body: getPdfRows(payload),
                        startY: y + 25,
                        margin: { left, right, top: 32, bottom: 22 },
                        tableWidth: 'wrap',
                        rowPageBreak: 'avoid',
                        styles: {
                            fontSize: 6.25,
                            cellPadding: 1.05,
                            overflow: 'linebreak',
                            valign: 'middle',
                            lineColor: [0, 0, 0],
                            lineWidth: 0.08
                        },
                        headStyles: {
                            fillColor: [44, 62, 80],
                            textColor: 255,
                            fontStyle: 'bold',
                            halign: 'center'
                        },
                        bodyStyles: {
                            textColor: [17, 24, 39]
                        },
                        alternateRowStyles: { fillColor: [249, 251, 253] },
                        columnStyles,
                        didParseCell: function (data) {
                            if (data.section !== 'body') return;
                            const column = payload.columns[data.column.index];
                            if ([9, 10, 11].includes(column)) data.cell.styles.halign = 'right';
                            if (column !== 2) return;
                            const value = String(data.cell.raw || '').toLowerCase();
                            if (value.includes('entrada')) data.cell.styles.textColor = [8, 118, 79];
                            if (value.includes('salida')) data.cell.styles.textColor = [184, 50, 39];
                            if (value.includes('inventariado')) data.cell.styles.textColor = [17, 108, 164];
                            data.cell.styles.fontStyle = 'bold';
                        }
                    });
                }
            });

            doc.save(`${report.fileBase || 'Movimientos_Combustible'}.pdf`);
        } catch (error) {
            console.error(error);
            toast('No se pudo generar el PDF.', 'danger');
        }
    };

    const exportExcel = () => {
        const payload = getTablePayload();
        if (!payload.body.length) {
            toast('No hay registros visibles para exportar.', 'warning');
            return;
        }

        if (!window.XLSX) {
            toast('No se pudo cargar el exportador Excel.', 'danger');
            return;
        }

        const sheetRows = [
            [report.title || 'HISTORIAL DE COMBUSTIBLE'],
            [`Periodo: ${report.period || '-'}`],
            Array.isArray(report.filters) ? [`Filtros: ${report.filters.filter(Boolean).join(' | ')}`] : ['Filtros: -'],
            [],
            payload.head,
            ...payload.body
        ];
        const ws = window.XLSX.utils.aoa_to_sheet(sheetRows);
        ws['!cols'] = payload.head.map((head) => ({ wch: Math.max(12, Math.min(32, head.length + 8)) }));
        const wb = window.XLSX.utils.book_new();
        window.XLSX.utils.book_append_sheet(wb, ws, 'Historial combustible');
        window.XLSX.writeFile(wb, `${report.fileBase || 'Movimientos_Combustible'}.xlsx`);
    };

    if (searchInput) {
        searchInput.addEventListener('input', applySearch);
        applySearch();
    } else {
        updateCount();
    }

    const formatPrice4 = (value) => {
        const number = Number(value);
        if (!Number.isFinite(number)) return '-';
        return `S/ ${number.toLocaleString('en-US', {
            minimumFractionDigits: 4,
            maximumFractionDigits: 4
        })}`;
    };

    const updatePriceModal = (modal) => {
        const filter = modal.querySelector('[data-comb-modal-filter]');
        const selected = filter ? filter.value : '__ALL__';
        const rowsModal = Array.from(modal.querySelectorAll('[data-comb-price-row]'));
        const visible = [];

        rowsModal.forEach((row) => {
            const ok = selected === '__ALL__' || row.dataset.product === selected;
            row.style.display = ok ? '' : 'none';
            if (ok) visible.push(row);
        });

        const values = visible
            .map((row) => Number(row.dataset.metricValue))
            .filter((value) => Number.isFinite(value));

        const count = visible.length;
        const min = values.length ? Math.min(...values) : null;
        const max = values.length ? Math.max(...values) : null;
        const avg = values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;
        const last = values.length ? values[0] : null;

        const setKpi = (key, value) => {
            const node = modal.querySelector(`[data-modal-kpi="${key}"]`);
            if (!node) return;
            node.textContent = key === 'count'
                ? new Intl.NumberFormat('es-PE').format(value || 0)
                : formatPrice4(value);
        };

        setKpi('count', count);
        setKpi('min', min);
        setKpi('max', max);
        setKpi('avg', avg);
        setKpi('last', last);
    };

    const modalTablePayload = (modal) => {
        const tableModal = modal.querySelector('.comb-modal-table');
        if (!tableModal) return { head: [], body: [] };
        const head = Array.from(tableModal.querySelectorAll('thead th')).map((th) => clean(th.textContent));
        const body = Array.from(tableModal.querySelectorAll('[data-comb-price-row]'))
            .filter((row) => row.style.display !== 'none')
            .map((row) => Array.from(row.children).map((td) => clean(td.textContent)));
        return { head, body };
    };

    const exportModalPdf = async (button) => {
        const modal = document.getElementById(button.dataset.modalId || '');
        if (!modal) return;

        const payload = modalTablePayload(modal);
        if (!payload.body.length) {
            toast('No hay registros visibles para exportar.', 'warning');
            return;
        }

        if (!window.N360PDF || !window.jspdf || !window.jspdf.jsPDF) {
            toast('No se pudo cargar el generador PDF.', 'danger');
            return;
        }

        const title = button.dataset.reportTitle || 'HISTORIAL DE COMBUSTIBLE';
        const docCode = button.dataset.docCode || 'COMB_HIST_AUX';
        const filter = modal.querySelector('[data-comb-modal-filter]');
        const filterText = filter && filter.value !== '__ALL__' ? filter.value : 'Todos los productos';
        const landscape = payload.head.length > 6;

        try {
            const doc = await window.N360PDF.createDocument({
                orientation: landscape ? 'landscape' : 'portrait',
                title,
                secondTitle: 'Combustible',
                description: 'Consulta auxiliar de combustible generada desde el historial operativo.',
                docCode,
                userName: report.generatedBy || '',
                dni: report.dni || '',
                logoLeft: report.logoLeft,
                logoRight: report.logoRight,
                useCover: false,
                content: function (doc) {
                    const left = 12.7;
                    const right = 12.7;
                    const width = doc.internal.pageSize.getWidth() - left - right;
                    const y = 34;

                    drawFilters(doc, left, y, width, `Filtro de producto: ${filterText}`);

                    doc.autoTable({
                        head: [payload.head],
                        body: payload.body,
                        startY: y + 24,
                        margin: { left, right, top: 32, bottom: 22 },
                        styles: {
                            fontSize: landscape ? 6.2 : 7.4,
                            cellPadding: 1.3,
                            overflow: 'linebreak',
                            valign: 'middle',
                            lineColor: [214, 226, 239],
                            lineWidth: 0.12
                        },
                        headStyles: {
                            fillColor: [18, 42, 64],
                            textColor: 255,
                            fontStyle: 'bold',
                            halign: 'center'
                        },
                        alternateRowStyles: { fillColor: [249, 251, 253] },
                        didParseCell: function (data) {
                            if (data.section !== 'body') return;
                            if (data.column.index >= payload.head.length - 3) {
                                data.cell.styles.halign = 'right';
                            }
                        }
                    });
                }
            });

            doc.save(`${docCode}_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.pdf`);
        } catch (error) {
            console.error(error);
            toast('No se pudo generar el PDF del modal.', 'danger');
        }
    };

    let activePriceModal = null;

    const removeModalBackdrop = () => {
        document.querySelectorAll('.comb-js-backdrop').forEach((node) => node.remove());
        if (!document.querySelector('.comb-price-modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    };

    const closePriceModal = (modal = activePriceModal) => {
        if (!modal) return;
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        modal.removeAttribute('role');
        if (activePriceModal === modal) activePriceModal = null;
        removeModalBackdrop();
    };

    const openPriceModal = (modal) => {
        if (!modal) return;
        if (activePriceModal && activePriceModal !== modal) closePriceModal(activePriceModal);
        activePriceModal = modal;
        updatePriceModal(modal);
        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('role', 'dialog');
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
        if (!document.querySelector('.comb-js-backdrop')) {
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show comb-js-backdrop';
            document.body.appendChild(backdrop);
        }
        modal.classList.add('show');
        const focusTarget = modal.querySelector('[data-comb-modal-filter], button, [href], input, select, textarea');
        if (focusTarget) focusTarget.focus({ preventScroll: true });
    };

    const initPriceModals = () => {
        document.querySelectorAll('.comb-price-modal').forEach((modal) => {
            modal.querySelector('[data-comb-modal-filter]')?.addEventListener('change', () => updatePriceModal(modal));
            modal.querySelectorAll('[data-comb-modal-close]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    closePriceModal(modal);
                });
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closePriceModal(modal);
            });
            updatePriceModal(modal);
        });

        document.querySelectorAll('[data-comb-open-modal]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                openPriceModal(document.getElementById(button.dataset.combOpenModal || ''));
            });
        });

        document.querySelectorAll('[data-comb-modal-export-pdf]').forEach((button) => {
            button.addEventListener('click', () => exportModalPdf(button));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && activePriceModal) closePriceModal(activePriceModal);
        });
    };

    document.querySelector('[data-combustible-export-pdf]')?.addEventListener('click', exportPdf);
    document.querySelector('[data-combustible-export-excel]')?.addEventListener('click', exportExcel);
    hideAdditionalPrice?.addEventListener('change', applyAdditionalPriceVisibility);
    applyAdditionalPriceVisibility();
    showOwners?.addEventListener('change', applyOptionalColumnsVisibility);
    showClassification?.addEventListener('change', applyOptionalColumnsVisibility);
    applyOptionalColumnsVisibility();
    initPriceModals();
    initEmissionEditor();
    initResumenEmision();
})();
