(() => {
  'use strict';
  const root = document.querySelector('[data-enc-registro]');
  const form = root?.querySelector('[data-enc-kupos-form]');
  if (!root || !form) return;
  const columns = JSON.parse(document.getElementById('encKuposColumns').textContent);
  const labels = { NUEVO: 'Nuevo', MODIFICADO: 'Modificado', SIN_CAMBIOS: 'Sin cambios' };
  const file = form.elements.archivo;
  const cutoff = form.elements.fecha_corte;
  const preview = root.querySelector('[data-kupos-preview]');
  const message = root.querySelector('[data-kupos-message]');
  const validateButton = form.querySelector('[data-kupos-validate]');
  const importButton = form.querySelector('[data-kupos-import]');
  const stateFilter = root.querySelector('[data-kupos-state]');
  const branchFilter = root.querySelector('[data-kupos-branch]');
  const search = root.querySelector('[data-kupos-search]');
  let model = null;
  let token = '';
  let busy = false;
  let page = 1;
  let requestNumber = 0;

  const node = (tag, text, className) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (className) result.className = className;
    return result;
  };
  const notify = (text, error = false) => {
    message.replaceChildren(node('p', text));
    message.classList.toggle('is-error', error);
  };
  const buttons = () => {
    validateButton.disabled = busy || !token;
    importButton.disabled = busy || !token || !model || model.errors.length > 0 || !!model.procesado;
    file.disabled = busy;
    cutoff.disabled = busy;
    root.querySelectorAll('[data-enc-mode]').forEach(button => { button.disabled = busy; });
    form.setAttribute('aria-busy', String(busy));
  };
  const display = (value, field) => {
    if (value === null || value === undefined || value === '') return '-';
    if (field === 'total') return `S/ ${String(value)}`;
    if (['fecha', 'fecha_creacion_externa', 'fecha_cobro_externa'].includes(field) && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value.split('-').reverse().join('/');
    return String(value);
  };
  const renderRows = () => {
    if (!model) return;
    const term = search.value.trim().toLocaleLowerCase('es');
    const rows = model.rows.filter(row => (!stateFilter.value || row.estado === stateFilter.value)
      && (!branchFilter.value || (branchFilter.value === '__SIN_SUCURSAL__' ? !row.data.sucursal : row.data.sucursal === branchFilter.value))
      && (!term || Object.values(row.data).some(value => String(value ?? '').toLocaleLowerCase('es').includes(term))));
    const pages = Math.max(1, Math.ceil(rows.length / 25));
    page = Math.min(page, pages);
    const body = root.querySelector('[data-kupos-rows]');
    body.replaceChildren();
    rows.slice((page - 1) * 25, page * 25).forEach(row => {
      const tr = node('tr', undefined, `enc-kupos-row is-${row.estado.toLowerCase()}`);
      tr.append(node('td', labels[row.estado]), node('td', row.data.sucursal || '-'));
      Object.keys(columns).forEach(field => {
        const td = node('td');
        td.append(node('span', display(row.data[field], field)));
        if (row.cambios[field]) {
          td.classList.add('enc-kupos-cell-changed');
          td.append(node('small', `Antes: ${display(row.cambios[field].antes, field)}`));
        }
        tr.append(td);
      });
      body.append(tr);
    });
    if (!rows.length) {
      const td = node('td', 'No hay envíos para estos filtros.');
      td.colSpan = Object.keys(columns).length + 2;
      const tr = node('tr'); tr.append(td); body.append(tr);
    }
    root.querySelector('[data-kupos-page-info]').textContent = `${rows.length} envíos - Página ${page} de ${pages}`;
    root.querySelector('[data-kupos-prev]').disabled = page <= 1;
    root.querySelector('[data-kupos-next]').disabled = page >= pages;
  };
  const render = data => {
    model = data; token = data.token; page = 1; preview.hidden = false;
    const counts = root.querySelector('[data-kupos-counts]'); counts.replaceChildren();
    [['NUEVO', 'Nuevos'], ['MODIFICADO', 'Modificados'], ['SIN_CAMBIOS', 'Sin cambios']].forEach(([key, label]) => {
      const item = node('div', undefined, `enc-kupos-count is-${key.toLowerCase()}`);
      item.append(node('span', label), node('strong', String(data.counts[key]))); counts.append(item);
    });
    const errors = root.querySelector('[data-kupos-errors]'); errors.replaceChildren();
    errors.hidden = !data.errors.length;
    if (data.errors.length) {
      const list = node('ul');
      data.errors.forEach(error => list.append(node('li', `Fila ${error.fila}: ${error.mensaje}`)));
      errors.append(list);
    }
    const warnings = root.querySelector('[data-kupos-warnings]');
    const warningList = root.querySelector('[data-kupos-warning-list]'); warningList.replaceChildren();
    data.warnings.forEach(text => warningList.append(node('li', text)));
    data.sin_equivalencia.forEach(name => warningList.append(node('li', `${name}: sin equivalencia de sede.`)));
    warnings.hidden = !warningList.children.length;
    const branchNote = root.querySelector('[data-kupos-branch-note]');
    branchNote.hidden = !data.sin_sucursal;
    branchNote.textContent = data.sin_sucursal
      ? `${data.sin_sucursal} envíos sin sucursal de origen informada por Kupos. Se conservará la que ya tenga el inventario. Las ${data.sucursales_reportadas} sucursales del resumen no identifican el origen de cada envío.` : '';
    const currentBranch = branchFilter.value;
    branchFilter.replaceChildren(node('option', 'Todas las sucursales'));
    branchFilter.firstChild.value = '';
    if (data.sin_sucursal) {
      const option = node('option', `Sin sucursal informada (${data.sin_sucursal})`);
      option.value = '__SIN_SUCURSAL__'; branchFilter.append(option);
    }
    Object.keys(data.sucursales).sort().forEach(name => {
      const option = node('option', `${name} (${data.sucursales[name]})`); option.value = name; branchFilter.append(option);
    });
    if (Object.hasOwn(data.sucursales, currentBranch)) branchFilter.value = currentBranch;
    if (data.procesado) {
      notify('Este contenido ya fue importado. Se conserva el control existente.');
      const link = node('a', `Ver ${data.procesado.codigo_control}`);
      link.href = `importaciones.php?id=${Number(data.procesado.id)}`;
      message.append(link);
    }
    renderRows(); buttons();
  };
  const send = async operation => {
    if (busy || !file.files.length || !form.reportValidity()) return;
    const number = ++requestNumber;
    busy = true; buttons();
    notify(operation === 'importar' ? 'Validando y guardando la importación...' : 'Leyendo y validando el reporte...');
    const data = new FormData();
    data.append('csrf_token', form.elements.csrf_token.value);
    data.append('operacion', operation);
    data.append('fecha_corte', cutoff.value);
    if (operation === 'previsualizar' || operation === 'importar') data.append('archivo', file.files[0]);
    if (operation !== 'previsualizar') data.append('token', token);
    try {
      const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (number !== requestNumber) return;
      notify(result.message || 'Operación completada.', !result.ok);
      if (result.preview) render(result.preview);
      if (result.ok && result.redirect) { window.location.assign(result.redirect); return; }
      if (!result.ok && !result.preview) throw new Error(result.message || 'No se pudo completar la operación.');
    } catch (error) {
      notify(error.message || 'No se pudo comunicar con el servidor.', true);
    } finally {
      if (number === requestNumber) { busy = false; buttons(); }
    }
  };
  file.addEventListener('change', () => {
    model = null; token = ''; preview.hidden = true; buttons();
    const selected = file.files[0];
    if (!selected) return;
    if (!cutoff.value) {
      const date = new Date(selected.lastModified);
      const part = value => String(value).padStart(2, '0');
      cutoff.value = `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}T${part(date.getHours())}:${part(date.getMinutes())}`;
    }
    send('previsualizar');
  });
  validateButton.addEventListener('click', () => send('validar'));
  cutoff.addEventListener('change', () => {
    if (!token && file.files.length) send('previsualizar');
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (importButton.disabled) return;
    const text = `Se guardarán ${model.counts.NUEVO} nuevos y ${model.counts.MODIFICADO} modificados, con sus importes e historial.`;
    const approved = window.N360Dialog?.confirm
      ? await window.N360Dialog.confirm(text, { title: 'Importar encomiendas', variant: 'warning', confirmText: 'Importar', cancelText: 'Cancelar' })
      : window.confirm(text);
    if (approved) send('importar');
  });
  [stateFilter, branchFilter].forEach(filter => filter.addEventListener('change', () => { page = 1; renderRows(); }));
  search.addEventListener('input', () => { page = 1; renderRows(); });
  root.querySelector('[data-kupos-prev]').addEventListener('click', () => { page--; renderRows(); });
  root.querySelector('[data-kupos-next]').addEventListener('click', () => { page++; renderRows(); });
  const selectMode = mode => {
    root.querySelector('[data-enc-manual-panel]').hidden = mode !== 'manual';
    root.querySelector('#encKuposPanel').hidden = mode !== 'kupos';
    root.querySelector('[data-enc-kupos-panel]').hidden = mode !== 'kupos';
    root.querySelectorAll('[data-enc-mode]').forEach(button => {
      const active = button.dataset.encMode === mode;
      button.classList.toggle('is-active', active); button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
    });
  };
  const tabs = Array.from(root.querySelectorAll('[data-enc-mode]'));
  tabs.forEach((button, index) => {
    button.addEventListener('click', () => selectMode(button.dataset.encMode));
    button.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      tabs[next].focus(); selectMode(tabs[next].dataset.encMode);
    });
  });
  selectMode(new URLSearchParams(window.location.search).get('modo') === 'kupos' ? 'kupos' : 'manual');
})();
