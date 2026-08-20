(function () {
  const root = document.querySelector('[data-enc-registro]');
  if (!root) return;

  const form = root.querySelector('#encRegistroForm');
  const resultBox = root.querySelector('#encRegistroResult, [data-enc-result]');
  const csrf = root.dataset.csrf || '';
  const routeBuilder = root.querySelector('[data-enc-route-builder]');
  const routeBox = root.querySelector('[data-enc-route-points]');
  const routeTemplate = root.querySelector('#encRutaPointTemplate');
  const routePreview = root.querySelector('[data-enc-route-preview]');
  const addPointBtn = root.querySelector('[data-enc-add-point]');
  const scheduleToggle = root.querySelector('[data-enc-schedule-toggle]');
  const scheduleBox = root.querySelector('[data-enc-schedule-box]');
  const scheduleSelect = root.querySelector('[data-enc-schedule-select]');
  const scheduleIdInput = root.querySelector('[data-enc-schedule-id]');
  const scheduleTimeInput = root.querySelector('[data-enc-schedule-time]');
  const scheduleTextInput = root.querySelector('[data-enc-manual-schedule]');
  const scheduleStatus = root.querySelector('[data-enc-schedule-status]');
  const unitIdInput = root.querySelector('[data-enc-unit]');
  const unitSearchInput = root.querySelector('[data-enc-unit-search]');
  const unitSuggestBox = root.querySelector('[data-enc-unit-suggestions]');
  const sameOfficeMessage = 'La oficina de origen, ruta y destino no se deben repetir.';

  if (!form) return;

  const cssEscape = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
    return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  };

  const readJson = (id, fallback) => {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try {
      return JSON.parse((el.textContent || '').replace(/^\uFEFF/, ''));
    } catch (error) {
      return fallback;
    }
  };

  const programacionesData = readJson('encProgramacionesData', []);
  const sedesData = readJson('encSedesData', []);
  const placasData = readJson('encPlacasData', []);
  const programaciones = Array.isArray(programacionesData) ? programacionesData : [];
  const sedes = Array.isArray(sedesData) ? sedesData : [];
  const placas = Array.isArray(placasData) ? placasData : [];
  const sedesById = new Map(sedes.map((sede) => [String(sede.id), sede]));
  const placasById = new Map(placas.map((placa) => [String(placa.id), placa]));

  const escapeHtml = (value) => String(value || '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));

  const parseJson = async (response) => {
    const text = await response.text();
    try {
      return JSON.parse(text.replace(/^\uFEFF/, ''));
    } catch (error) {
      throw new Error('El servidor devolvio una respuesta no valida.');
    }
  };

  const showDialog = (message, variant, title) => {
    if (window.N360Dialog && typeof window.N360Dialog.alert === 'function') {
      return window.N360Dialog.alert(message, { variant: variant || 'info', title: title || 'Encomiendas' });
    }
    console.log(title || 'Encomiendas', message);
    return Promise.resolve();
  };

  const fieldByName = (name) => form.querySelector(`[name="${cssEscape(name)}"]`);

  const clearErrors = () => {
    form.querySelectorAll('.has-error').forEach((el) => el.classList.remove('has-error'));
    form.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; });
  };

  const setError = (name, message) => {
    const error = form.querySelector(`[data-error-for="${cssEscape(name)}"]`);
    if (error) error.textContent = message;
    const field = fieldByName(name);
    const wrapper = field ? field.closest('.stock-field') : error?.closest('.stock-field');
    if (wrapper) wrapper.classList.add('has-error');
  };

  const getSelectLabel = (select) => {
    if (!select || !select.value) return '';
    return select.options[select.selectedIndex]?.textContent?.trim() || '';
  };

  const routeSelects = () => Array.from(routeBox?.querySelectorAll('[data-enc-route-select]') || []);

  const today = () => {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  };

  const selectedEndpointValues = () => [
    form.elements.idsede_embarque?.value || '',
    form.elements.idsede_desembarque?.value || ''
  ].filter(Boolean);

  const updateRoutePreview = () => {
    if (!routePreview) return;
    const origin = form.elements.idsede_embarque;
    const destination = form.elements.idsede_desembarque;
    const labels = [];
    const originLabel = getSelectLabel(origin);
    const destinationLabel = getSelectLabel(destination);
    if (originLabel) labels.push({ icon: 'bi-geo-alt-fill', text: originLabel });
    routeSelects().forEach((select) => {
      const label = getSelectLabel(select);
      if (label) labels.push({ icon: 'bi-signpost-split', text: label });
    });
    if (destinationLabel) labels.push({ icon: 'bi-flag-fill', text: destinationLabel });

    routePreview.innerHTML = labels.length
      ? labels.map((item) => `<span><i class="bi ${item.icon}"></i>${escapeHtml(item.text)}</span>`).join('')
      : '<span><i class="bi bi-geo-alt-fill"></i> Origen</span><span><i class="bi bi-flag-fill"></i> Destino</span>';
  };

  const syncEndpointOptions = () => {
    const origin = form.elements.idsede_embarque;
    const destination = form.elements.idsede_desembarque;
    if (!origin || !destination) return;
    Array.from(origin.options).forEach((opt) => { opt.disabled = opt.value !== '' && opt.value === destination.value; });
    Array.from(destination.options).forEach((opt) => { opt.disabled = opt.value !== '' && opt.value === origin.value; });
    if (origin.value && origin.value === destination.value) destination.value = '';
  };

  const syncRouteOptions = () => {
    syncEndpointOptions();
    const blocked = new Set(selectedEndpointValues());
    const used = new Set();

    routeSelects().forEach((select) => {
      const current = select.value;
      Array.from(select.options).forEach((opt) => {
        if (!opt.value) {
          opt.disabled = false;
          return;
        }
        opt.disabled = blocked.has(opt.value) || (used.has(opt.value) && opt.value !== current);
      });

      if (current && blocked.has(current)) {
        select.value = '';
      }
      if (select.value) used.add(select.value);
    });

    updateRoutePreview();
  };

  const setSelectValue = (name, value) => {
    const select = fieldByName(name);
    if (!select) return false;
    const nextValue = value == null ? '' : String(value);
    if (!nextValue) {
      select.value = '';
      return true;
    }
    const option = Array.from(select.options).find((opt) => opt.value === nextValue);
    if (!option) return false;
    select.value = nextValue;
    return true;
  };

  const parseRouteIds = (raw) => {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw.map((item) => String(item.id || item.idsede || item)).filter(Boolean);
    const text = String(raw).trim();
    if (!text) return [];
    try {
      const parsed = JSON.parse(text);
      if (Array.isArray(parsed)) return parsed.map((item) => String(item.id || item.idsede || item)).filter(Boolean);
    } catch (error) {
      // Sigue con el formato simple usado en algunas pizarras.
    }
    return text.split(/[;,|>\/-]+/).map((part) => part.trim()).filter((part) => /^\d+$/.test(part));
  };

  const addRoutePoint = (value) => {
    if (!routeTemplate || !routeBox) return null;
    const fragment = routeTemplate.content.cloneNode(true);
    routeBox.appendChild(fragment);
    const select = routeBox.querySelector('.enc-route-row:last-child select');
    syncRouteOptions();
    if (select && value) select.value = String(value);
    syncRouteOptions();
    if (!value) select?.focus();
    return select;
  };

  const clearRoutePoints = () => {
    if (routeBox) routeBox.innerHTML = '';
    syncRouteOptions();
  };

  const getSchedule = (id) => programaciones.find((item) => String(item.id) === String(id));

  const scheduleLabel = (prog) => {
    if (!prog) return '';
    const hour = String(prog.hora || '').slice(0, 5);
    const origin = prog.origen || sedesById.get(String(prog.idsede_origen))?.nombre || 'Origen';
    const dest = prog.destino || sedesById.get(String(prog.idsede_destino))?.nombre || 'Destino';
    const unit = [prog.bus, prog.placa].map((v) => String(v || '').trim()).filter(Boolean).join(' - ');
    return [hour, `${origin} -> ${dest}`, unit].filter(Boolean).join(' | ').slice(0, 120);
  };

  const normalizeText = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const unitLabel = (unit) => {
    if (!unit) return '';
    const bus = String(unit.bus || '').trim();
    const plate = String(unit.placa || '').trim();
    const fallback = unit.id ? `Unidad ${unit.id}` : 'Unidad';
    return [bus || fallback, plate].filter(Boolean).join(' - ');
  };

  const unitSearchHaystack = (unit) => normalizeText([
    unitLabel(unit),
    unit?.bus,
    unit?.placa,
    unit?.id
  ].filter(Boolean).join(' '));

  const hideUnitSuggestions = () => {
    if (!unitSuggestBox) return;
    unitSuggestBox.hidden = true;
    unitSuggestBox.innerHTML = '';
  };

  const setUnitValue = (id) => {
    if (!unitIdInput || !unitSearchInput) return false;
    const nextId = id == null ? '' : String(id);
    if (!nextId) {
      unitIdInput.value = '';
      unitSearchInput.value = '';
      hideUnitSuggestions();
      return true;
    }
    const unit = placasById.get(nextId);
    if (!unit) return false;
    unitIdInput.value = nextId;
    unitSearchInput.value = unitLabel(unit);
    hideUnitSuggestions();
    return true;
  };

  const matchingUnits = (query) => {
    const normalized = normalizeText(query);
    const terms = normalized.split(/\s+/).filter(Boolean);
    if (!terms.length) return placas.slice(0, 8);
    return placas
      .filter((unit) => {
        const haystack = unitSearchHaystack(unit);
        return terms.every((term) => haystack.includes(term));
      })
      .slice(0, 8);
  };

  const renderUnitSuggestions = (query) => {
    if (!unitSuggestBox || !unitSearchInput || unitSearchInput.disabled) return;
    const matches = matchingUnits(query);
    if (!matches.length) {
      unitSuggestBox.innerHTML = '<div class="enc-unit-suggestions__empty">Sin coincidencias</div>';
      unitSuggestBox.hidden = false;
      return;
    }

    unitSuggestBox.innerHTML = matches.map((unit) => {
      const id = escapeHtml(unit.id);
      const bus = String(unit.bus || '').trim();
      const plate = String(unit.placa || '').trim();
      const meta = [bus ? `Bus ${bus}` : '', plate ? `Placa ${plate}` : ''].filter(Boolean).join(' | ');
      return `<button type="button" data-enc-unit-option="${id}">
        <strong>${escapeHtml(unitLabel(unit))}</strong>
        <small>${escapeHtml(meta || `Unidad ${unit.id || ''}`)}</small>
      </button>`;
    }).join('');
    unitSuggestBox.hidden = false;
  };

  const resolveTypedUnit = () => {
    if (!unitIdInput || !unitSearchInput) return true;
    const typed = normalizeText(unitSearchInput.value);
    if (!typed) {
      unitIdInput.value = '';
      return false;
    }

    const selected = placasById.get(String(unitIdInput.value || ''));
    if (selected && normalizeText(unitLabel(selected)) === typed) return true;

    const exact = placas.find((unit) => {
      const bus = String(unit.bus || '').trim();
      const plate = String(unit.placa || '').trim();
      const variants = [
        unitLabel(unit),
        bus,
        plate,
        [bus, plate].filter(Boolean).join(' - '),
        bus ? `bus ${bus}` : '',
        plate ? `placa ${plate}` : ''
      ].filter(Boolean).map(normalizeText);
      return variants.includes(typed);
    });

    if (exact) {
      setUnitValue(exact.id);
      return true;
    }
    unitIdInput.value = '';
    return false;
  };

  const setScheduleMode = (active) => {
    if (scheduleBox) scheduleBox.hidden = !active;
    if (scheduleSelect) scheduleSelect.disabled = !active || form.dataset.disabled === '1' || !programaciones.length;
    if (scheduleTextInput) {
      scheduleTextInput.readOnly = active;
      scheduleTextInput.classList.toggle('is-readonly', active);
      if (!active) scheduleTextInput.placeholder = 'Ej. Lima - Huancayo 20:30 / Servicio 158';
    }
    if (scheduleTimeInput) {
      scheduleTimeInput.readOnly = active;
      scheduleTimeInput.classList.toggle('is-readonly', active);
    }
    if (scheduleStatus) {
      scheduleStatus.innerHTML = active
        ? '<i class="bi bi-link-45deg"></i> Vinculado a pizarra'
        : '<i class="bi bi-pencil-square"></i> Modo manual';
    }
    if (!active) {
      if (scheduleIdInput) scheduleIdInput.value = '0';
      if (scheduleSelect) scheduleSelect.value = '';
      if (scheduleTextInput) scheduleTextInput.value = '';
      if (scheduleTimeInput) scheduleTimeInput.value = '';
    }
    syncRouteOptions();
  };

  const applySchedule = (prog) => {
    if (!prog) return;
    if (scheduleIdInput) scheduleIdInput.value = String(prog.id || 0);
    if (form.elements.fecha_guia && !form.elements.fecha_guia.value) form.elements.fecha_guia.value = today();
    if (scheduleTimeInput) scheduleTimeInput.value = String(prog.hora || '').slice(0, 5);
    if (scheduleTextInput) scheduleTextInput.value = scheduleLabel(prog);
    setSelectValue('idsede_embarque', prog.idsede_origen || '');
    setSelectValue('idsede_desembarque', prog.idsede_destino || '');
    setUnitValue(prog.idplaca || '');

    clearRoutePoints();
    const blocked = new Set([String(prog.idsede_origen || ''), String(prog.idsede_destino || '')].filter(Boolean));
    const added = new Set();
    parseRouteIds(prog.ruta_raw).forEach((id) => {
      if (!id || blocked.has(id) || added.has(id)) return;
      addRoutePoint(id);
      added.add(id);
    });
    syncRouteOptions();
  };

  const validate = () => {
    clearErrors();
    syncRouteOptions();
    let ok = true;

    if (!form.elements.fecha_guia?.value) {
      setError('fecha_guia', 'Selecciona la fecha de la Control Encomienda.');
      ok = false;
    }
    if (scheduleToggle?.checked && !scheduleIdInput?.value) {
      setError('idprogbus', 'Selecciona un horario activo de la pizarra.');
      ok = false;
    }
    if (!form.elements.idsede_embarque?.value) {
      setError('idsede_embarque', 'Selecciona la oficina de origen.');
      ok = false;
    }
    if (!form.elements.idsede_desembarque?.value) {
      setError('idsede_desembarque', 'Selecciona la oficina de destino.');
      ok = false;
    }
    if (!resolveTypedUnit() || !unitIdInput?.value) {
      setError('idplaca_embarque', 'Selecciona una unidad de transporte.');
      ok = false;
    }

    const values = [
      form.elements.idsede_embarque?.value || '',
      ...routeSelects().map((select) => select.value).filter(Boolean),
      form.elements.idsede_desembarque?.value || ''
    ].filter(Boolean);
    if (values.length !== new Set(values).size) {
      setError('puntos_ruta', sameOfficeMessage);
      routeBuilder?.classList.add('has-error');
      ok = false;
    } else {
      routeBuilder?.classList.remove('has-error');
    }

    return ok;
  };

  addPointBtn?.addEventListener('click', () => addRoutePoint());

  scheduleToggle?.addEventListener('change', () => {
    setScheduleMode(scheduleToggle.checked);
    if (scheduleToggle.checked && scheduleSelect?.value) applySchedule(getSchedule(scheduleSelect.value));
  });

  scheduleSelect?.addEventListener('change', () => {
    applySchedule(getSchedule(scheduleSelect.value));
  });

  unitSearchInput?.addEventListener('focus', () => {
    renderUnitSuggestions(unitSearchInput.value);
  });

  unitSearchInput?.addEventListener('input', () => {
    const selected = placasById.get(String(unitIdInput?.value || ''));
    if (selected && normalizeText(unitSearchInput.value) !== normalizeText(unitLabel(selected))) {
      unitIdInput.value = '';
    }
    renderUnitSuggestions(unitSearchInput.value);
  });

  unitSearchInput?.addEventListener('blur', () => {
    window.setTimeout(() => {
      resolveTypedUnit();
      hideUnitSuggestions();
    }, 120);
  });

  unitSuggestBox?.addEventListener('mousedown', (event) => {
    event.preventDefault();
  });

  unitSuggestBox?.addEventListener('click', (event) => {
    const option = event.target.closest('[data-enc-unit-option]');
    if (!option) return;
    setUnitValue(option.dataset.encUnitOption || '');
    unitSearchInput?.focus();
  });

  routeBox?.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-enc-remove-point]');
    if (!trigger) return;
    trigger.closest('[data-enc-route-row]')?.remove();
    syncRouteOptions();
  });

  form.addEventListener('change', (event) => {
    if (event.target.matches('select')) syncRouteOptions();
  });

  form.addEventListener('reset', () => {
    window.setTimeout(() => {
      clearRoutePoints();
      clearErrors();
      if (form.elements.fecha_guia) form.elements.fecha_guia.value = today();
      setUnitValue('');
      if (scheduleToggle) scheduleToggle.checked = false;
      setScheduleMode(false);
    }, 0);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (form.dataset.disabled === '1') {
      await showDialog('Primero ejecuta la migracion de Control Encomiendas en la base de datos.', 'warning', 'Migracion pendiente');
      return;
    }
    if (!validate()) {
      await showDialog('Revisa los campos marcados antes de registrar la Control Encomienda.', 'warning', 'Datos pendientes');
      return;
    }

    const submitButton = form.querySelector('[type="submit"]');
    const fd = new FormData(form);
    if (csrf && !fd.has('csrf_token')) fd.set('csrf_token', csrf);

    const task = async () => {
      const response = await fetch(form.action, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await parseJson(response);
      if (!response.ok || !data.ok) {
        if (data.errors && typeof data.errors === 'object') {
          Object.entries(data.errors).forEach(([name, message]) => setError(name, message));
        }
        throw new Error(data.message || 'No se pudo registrar la Control Encomienda.');
      }
      return data;
    };

    try {
      const data = window.N360Loader && window.N360Loader.during
        ? await window.N360Loader.during(task(), { button: submitButton, title: 'Registrando Control Encomienda', detail: 'Generando correlativo y ruta documentaria...' })
        : await task();

      if (resultBox) {
        resultBox.hidden = false;
        resultBox.classList.add('is-visible');
        resultBox.innerHTML = `
          <h3><i class="bi bi-check2-circle"></i> Control Encomienda registrada</h3>
          <p class="mb-3">Se inicio el seguimiento para <strong>${escapeHtml(data.guia)}</strong>.</p>
          <div class="enc-action-row">
            <a class="stock-btn stock-btn--primary" href="${escapeHtml(data.tracking_url || 'tracking.php')}"><i class="bi bi-signpost-split-fill"></i> Ir al tracking</a>
            <a class="stock-btn stock-btn--soft" href="${escapeHtml(data.detail_url || 'tracking.php')}"><i class="bi bi-eye"></i> Ver detalle</a>
            <button class="stock-btn stock-btn--soft" type="button" data-enc-new><i class="bi bi-plus-circle"></i> Nueva Control Encomienda</button>
          </div>`;
      }
      await showDialog(data.message || 'Control Encomienda registrada correctamente.', 'success', 'Registro listo');
    } catch (error) {
      await showDialog(error.message || 'No se pudo completar el registro.', 'danger', 'No se pudo registrar');
    }
  });

  root.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-enc-new]');
    if (!trigger) return;
    form.reset();
    clearErrors();
    if (resultBox) {
      resultBox.hidden = true;
      resultBox.classList.remove('is-visible');
      resultBox.innerHTML = '';
    }
    form.elements.fecha_guia?.focus();
  });

  setScheduleMode(false);
  setUnitValue(unitIdInput?.value || '');
  syncRouteOptions();
})();
