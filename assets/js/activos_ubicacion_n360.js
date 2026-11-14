(function () {
  'use strict';
  const text = (value) => String(value ?? '').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g, '').trim();
  const detail = (value) => text(value).replace(/\s+/g, ' ');
  const location = (sede, value) => {
    const extra = detail(value);
    return text(sede) + (text(sede) && extra ? ' - ' : '') + extra;
  };
  const observation = (value, sede, extra) => {
    const base = text(value);
    return base + (detail(extra) ? (base ? ' | ' : '') + 'Ubicación: ' + location(sede, extra) : '');
  };
  const spacePersonnel = (select, id = select?.value) => {
    const option = Array.from(select?.options || []).find(item => item.value === String(id));
    try { return JSON.parse(option?.dataset.personal || 'null'); } catch { return null; }
  };
  const personnelNames = (personal, turn) => personal === null || personal === undefined ? 'Personal no disponible'
    : (personal[turn] || []).map(text).filter(Boolean).join(', ') || 'Sin personal asignado';
  const renderPersonnel = (container, personal, selected = true) => {
    if (!container) return;
    container.replaceChildren(); container.hidden = !selected;
    if (!selected) return;
    const title = document.createElement('span');
    title.textContent = container.dataset.title || 'Personal vigente del espacio';
    const turns = document.createElement('div'); turns.className = 'n360-space-personnel__turns';
    for (const turn of [1, 2]) {
      const row = document.createElement('p'), label = document.createElement('strong');
      label.textContent = 'Turno ' + turn;
      row.append(label, document.createTextNode(personnelNames(personal, turn))); turns.append(row);
    }
    container.append(title, turns);
  };
  const bindSuggestions = (input, list, sede) => {
    if (!input || !list || !sede) return () => {};
    const previous = Array.from(list.options).map((option) => ({ value: option.value, sede: option.dataset.sede }));
    const refresh = () => {
      const seen = new Set();
      const options = [];
      previous.forEach((item) => {
        if (item.sede !== String(sede.value) || seen.has(item.value)) return;
        seen.add(item.value);
        const option = document.createElement('option');
        option.value = item.value;
        options.push(option);
      });
      list.replaceChildren(...options);
    };
    sede.addEventListener('change', refresh);
    sede.form?.addEventListener('reset', () => queueMicrotask(refresh));
    refresh();
    return refresh;
  };
  const bindSpaces = (select, sede) => {
    const noop = () => {};
    noop.setDisabled = () => {};
    const area = select ? document.getElementById(select.id + 'Area') : null;
    if (!select || !sede || !area) return noop;
    const options = Array.from(select.options).filter(option => option.value).map(option => option.cloneNode(true));
    const enabled = select.dataset.spacesEnabled === '1';
    const help = document.getElementById(select.id + 'Help');
    const personnel = document.getElementById(select.id + 'Personal');
    const key = value => detail(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE');
    let retainedId = '';
    let locked = !enabled;
    const available = () => options.filter(option => option.dataset.sede === String(sede.value)
      && (option.dataset.active === '1' || option.value === retainedId));
    const areaKey = option => key(option.dataset.area);
    const updatePersonnel = () => renderPersonnel(personnel, spacePersonnel(select), Boolean(select.value));

    const syncDisabled = () => {
      area.disabled = locked || !sede.value || area.options.length < 2;
      select.disabled = locked || !sede.value || !area.value;
      select.required = !select.disabled && Boolean(area.value);
      if (!help || !enabled) return;
      help.textContent = !sede.value ? 'Selecciona una sede para elegir el área.'
        : area.options.length < 2 ? 'Esta sede aún no tiene áreas o espacios disponibles.'
        : !area.value ? 'Elige un área para ver sus espacios.'
        : 'Selecciona el espacio dentro del área elegida.';
    };

    const updateSpaces = (selected = '') => {
      const entries = available().filter(option => areaKey(option) === area.value);
      const counts = new Map();
      entries.forEach(option => counts.set(key(option.dataset.space), (counts.get(key(option.dataset.space)) || 0) + 1));
      select.replaceChildren(new Option(area.value ? 'Selecciona un espacio...' : 'Selecciona primero un área', ''),
        ...entries.map(option => {
          const clone = option.cloneNode(true);
          if (counts.get(key(option.dataset.space)) > 1) {
            const people = spacePersonnel({options: [option]}, option.value);
            const names = [1, 2].flatMap(turn => people?.[turn] || []).map(text).filter(Boolean).join(', ');
            clone.textContent += ' - ' + (names ? names + ' · ' : '') + 'Ubicación ' + option.value;
          }
          return clone;
        }));
      // Una fila de "solo área" ya tiene un ID real; no se inventa una asignación.
      const areaOnly = entries.filter(option => !detail(option.dataset.space));
      select.value = entries.some(option => option.value === String(selected)) ? String(selected) : areaOnly.length === 1 ? areaOnly[0].value : '';
      syncDisabled();
      updatePersonnel();
    };

    const rebuild = (selected = '', selectedArea = '') => {
      const entries = available();
      const groups = new Map();
      entries.forEach(option => {
        const group = areaKey(option);
        if (!groups.has(group)) groups.set(group, {name: option.dataset.area, active: false});
        if (option.dataset.active === '1') groups.get(group).active = true;
      });
      const current = entries.find(option => option.value === String(selected));
      const placeholder = !sede.value ? 'Selecciona primero una sede' : groups.size ? 'Sin área específica' : 'Sin áreas disponibles';
      area.replaceChildren(new Option(placeholder, ''), ...Array.from(groups, ([value, group]) =>
        new Option(group.name + (group.active ? '' : ' (inactivo)'), value)));
      const desiredArea = current ? areaKey(current) : selectedArea;
      area.value = groups.has(desiredArea) ? desiredArea : '';
      updateSpaces(selected);
    };

    const refresh = (selected = select.value) => {
      retainedId = String(selected || '');
      rebuild(retainedId);
    };
    refresh.setDisabled = value => { locked = Boolean(value) || !enabled; syncDisabled(); };
    sede.addEventListener('change', () => {
      rebuild();
      select.dispatchEvent(new Event('change'));
    });
    area.addEventListener('change', () => {
      updateSpaces();
      select.dispatchEvent(new Event('change'));
    });
    select.addEventListener('change', updatePersonnel);
    (select.form || sede.form)?.addEventListener('reset', () => queueMicrotask(() => {
      refresh('');
      select.dispatchEvent(new Event('change'));
    }));
    refresh();
    return refresh;
  };
  const selectedSpace = select => select && !select.disabled && select.value ? select.selectedOptions[0]?.dataset.name || '' : '';
  const description = (space, extra) => [text(space), detail(extra)].filter(Boolean).join(' - ');
  window.N360AssetLocation = Object.freeze({ text, detail, location, observation, bindSuggestions, bindSpaces, selectedSpace, description,
    spacePersonnel, personnelNames, renderPersonnel, length: (value) => Array.from(value).length });
})();
