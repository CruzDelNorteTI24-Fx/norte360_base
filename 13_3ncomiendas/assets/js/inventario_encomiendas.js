(() => {
  'use strict';
  const root = document.querySelector('[data-enc-inventory]');
  const rowsScript = document.getElementById('encInventoryRows');
  if (!root || !rowsScript) return;
  const rows = JSON.parse(rowsScript.textContent);
  const labels = JSON.parse(document.getElementById('encInventoryColumns').textContent);
  const element = document.getElementById('encInventoryModal');
  const body = root.querySelector('[data-inventory-modal-body]');
  const title = document.getElementById('encInventoryModalTitle');
  const historyCache = new Map();
  const historyRequests = new Map();
  let activeId = 0;
  let activeMode = '';
  const node = (tag, text, cls) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (cls) result.className = cls;
    return result;
  };
  const valueText = (value, key) => {
    if (value === null || value === undefined || value === '') return '-';
    if (key === 'total' || key === 'importe_cobrado') return `S/ ${String(value)}`;
    return String(value);
  };
  const show = (id, heading, mode = 'info') => {
    activeId = id;
    activeMode = mode;
    title.textContent = heading;
    if (window.bootstrap?.Modal) bootstrap.Modal.getOrCreateInstance(element).show();
  };
  const information = id => {
    const row = rows[id];
    if (!row) return;
    show(id, row.codigo_envio || row.doc_fiscal || `Encomienda ${id}`);
    body.replaceChildren();
    const grid = node('dl', undefined, 'enc-inventory-detail-grid');
    Object.entries(labels).forEach(([field, label]) => {
      const entry = node('div');
      entry.append(node('dt', label), node('dd', valueText(row[field], field))); grid.append(entry);
    });
    body.append(grid);
    if (row.tipo_origen === 'KUPOS') {
      const form = node('form', undefined, 'enc-review-local');
      const stateLabel = node('label', undefined, 'stock-field');
      stateLabel.append(node('span', 'Revisión Norte360'));
      const select = node('select'); select.name = 'estado';
      ['PENDIENTE', 'OK', 'REZAGADO', 'OBSERVADO'].forEach(state => {
        const option = node('option', state); option.value = state; option.selected = row.revision_estado === state; select.append(option);
      });
      stateLabel.append(select);
      const observationLabel = node('label', undefined, 'stock-field');
      observationLabel.append(node('span', 'Observación Norte360'));
      const observation = node('textarea'); observation.name = 'observacion'; observation.maxLength = 2000; observation.rows = 3;
      observation.value = row.revision_observacion || ''; observationLabel.append(observation);
      const save = node('button', 'Guardar revisión', 'stock-btn stock-btn--primary'); save.type = 'submit';
      const status = node('p'); status.setAttribute('role', 'status');
      form.append(stateLabel, observationLabel, save, status);
      form.addEventListener('submit', async event => {
        event.preventDefault(); save.disabled = true;
        const data = new FormData(form);
        data.append('id', id); data.append('version', row.version); data.append('csrf_token', root.dataset.csrf);
        try {
          const response = await fetch('actions/inventario.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
          const result = await response.json();
          if (!result.ok) throw new Error(result.message || 'No se pudo guardar.');
          window.location.reload();
        } catch (error) { status.textContent = error.message; save.disabled = false; }
      });
      body.append(form);
    }
  };
  const renderHistory = result => {
    body.replaceChildren();
    if (!result.historial.length) { body.append(node('p', 'No hay eventos registrados.')); return; }
    result.historial.forEach(event => {
      const entry = node('article', undefined, 'enc-history-event');
      entry.append(node('strong', event.accion), node('p', `${event.fecha} - ${event.usuario}${event.codigo_control ? ` - ${event.codigo_control}` : ''}`));
      Object.entries(event.cambios).forEach(([field, change]) => {
        if (!labels[field]) return;
        const line = node('div', undefined, 'enc-history-change');
        line.append(node('strong', field === 'total' && event.importe_heredado ? 'Importe cobrado del manifiesto' : labels[field]));
        const before = node('div'); before.append(node('small', 'Antes'), node('span', valueText(change.antes, field)));
        const after = node('div'); after.append(node('small', 'Después'), node('span', valueText(change.despues, field)));
        line.append(before, after); entry.append(line);
      });
      body.append(entry);
    });
    if (result.hay_mas) {
      const more = node('button', 'Cargar eventos anteriores', 'stock-btn stock-btn--soft');
      more.type = 'button';
      more.addEventListener('click', async () => {
        const id = activeId; more.disabled = true;
        try {
          const response = await fetch(`actions/inventario.php?id=${id}&antes_de=${result.siguiente}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
          const older = await response.json();
          if (!older.ok) throw new Error(older.message || 'No se pudo consultar.');
          const combined = { ...older, historial: [...result.historial, ...older.historial] };
          historyCache.set(id, combined);
          if (activeId === id && activeMode === 'history') renderHistory(combined);
        } catch (error) { more.disabled = false; more.textContent = error.message; }
      });
      body.append(more);
    }
  };
  const history = async id => {
    show(id, `Historial - ${rows[id]?.codigo_envio || rows[id]?.doc_fiscal || id}`, 'history');
    body.replaceChildren(node('p', 'Consultando historial...'));
    if (historyCache.has(id)) { renderHistory(historyCache.get(id)); return; }
    let request = historyRequests.get(id);
    if (!request) {
      request = (async () => {
        const response = await fetch(`actions/inventario.php?id=${id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'No se pudo consultar.');
        historyCache.set(id, result);
        return result;
      })();
      historyRequests.set(id, request);
    }
    try {
      const result = await request;
      if (activeId === id && activeMode === 'history') renderHistory(result);
    } catch (error) { if (activeId === id && activeMode === 'history') body.replaceChildren(node('p', error.message)); }
    finally { if (historyRequests.get(id) === request) historyRequests.delete(id); }
  };
  root.addEventListener('click', event => {
    const info = event.target.closest('[data-inventory-info]');
    const historyButton = event.target.closest('[data-inventory-history]');
    if (info) information(Number(info.dataset.inventoryInfo));
    else if (historyButton) history(Number(historyButton.dataset.inventoryHistory));
  });
  element.addEventListener('hidden.bs.modal', () => { activeId = 0; activeMode = ''; });
})();
