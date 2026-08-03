(function () {
  const modal = document.querySelector('[data-patr-modal]');
  const matrixModal = document.querySelector('[data-patr-matrix-modal]');
  const form = document.querySelector('[data-patr-form]');
  const actionInput = document.querySelector('[data-patr-action]');
  const title = document.querySelector('[data-patr-title]');
  const submit = document.querySelector('[data-patr-submit]');
  const searchInput = document.querySelector('[data-patr-search]');
  const statusInput = document.querySelector('[data-patr-status]');
  const result = document.querySelector('[data-patr-result]');
  const rows = Array.from(document.querySelectorAll('[data-patr-row]'));

  if (!modal || !form) {
    return;
  }

  const today = new Date().toISOString().slice(0, 10);
  const defaults = {
    patr_id: '',
    placa_id: '',
    placa: '',
    bus: '',
    dueno: '',
    tipo: 'BUS',
    servicio: 'PREMIUM-EXCLUSIVO',
    kilometraje: '0',
    estado: 'Activo',
    fecha_alta: today,
    fecha_baja: '',
    motivo: '',
    compania: 'CRUZ DEL NORTE',
    marca: '',
    modelo: '',
    capacidad_pasajeros: '',
    capacidad_asientos_terr: '',
    capacidad_total: '',
    soat: '',
    revision_tecnica: '',
    seguro: '',
    gata: '',
    llave_ruedas: '',
    juego_llaves: '',
    palanca: '',
  };

  function field(name) {
    return form.querySelector(`[data-field="${name}"]`);
  }

  function setField(name, value) {
    const input = field(name);
    if (!input) return;
    input.value = value == null ? '' : String(value);
  }

  function fill(values) {
    Object.keys(defaults).forEach((key) => {
      setField(key, values[key] ?? defaults[key]);
    });
  }

  function openModal(mode, values) {
    const editing = mode === 'edit';
    form.reset();
    fill(editing ? values : defaults);
    actionInput.value = editing ? 'update' : 'create';
    title.textContent = editing ? 'Editar patrimonio' : 'Nuevo patrimonio';
    submit.querySelector('span').textContent = editing ? 'Guardar cambios' : 'Guardar patrimonio';
    submit.disabled = false;
    modal.hidden = false;
    document.documentElement.classList.add('patr-modal-open');
    window.setTimeout(() => field('placa')?.focus(), 40);
  }

  function closeModal() {
    modal.hidden = true;
    document.documentElement.classList.remove('patr-modal-open');
    form.reset();
  }

  function openMatrixModal() {
    if (!matrixModal) return;
    matrixModal.hidden = false;
    document.documentElement.classList.add('patr-modal-open');
  }

  function closeMatrixModal() {
    if (!matrixModal) return;
    matrixModal.hidden = true;
    if (modal.hidden) {
      document.documentElement.classList.remove('patr-modal-open');
    }
  }

  function normalize(value) {
    return String(value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  function applyFilters() {
    const needle = normalize(searchInput?.value);
    const status = statusInput?.value || 'all';
    let visible = 0;

    rows.forEach((row) => {
      const matchesText = !needle || normalize(row.dataset.search).includes(needle);
      const matchesStatus = status === 'all' || row.dataset.status === status;
      const show = matchesText && matchesStatus;
      row.hidden = !show;
      if (show) visible += 1;
    });

    if (result) {
      result.textContent = `Mostrando ${visible} ${visible === 1 ? 'unidad' : 'unidades'}`;
    }
  }

  document.addEventListener('click', (event) => {
    const matrixBtn = event.target.closest('[data-patr-matrix-open]');
    if (matrixBtn) {
      openMatrixModal();
      return;
    }

    if (event.target.closest('[data-patr-matrix-close]')) {
      closeMatrixModal();
      return;
    }

    const createBtn = event.target.closest('[data-patr-create]');
    if (createBtn) {
      openModal('create', {});
      return;
    }

    const editBtn = event.target.closest('[data-patr-edit]');
    if (editBtn) {
      try {
        openModal('edit', JSON.parse(editBtn.dataset.patrEdit || '{}'));
      } catch (error) {
        console.error('No se pudo leer el patrimonio seleccionado.', error);
      }
      return;
    }

    if (event.target.closest('[data-patr-close]')) {
      closeModal();
      return;
    }

    if (event.target.closest('[data-patr-clear]')) {
      if (searchInput) searchInput.value = '';
      if (statusInput) statusInput.value = 'all';
      applyFilters();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }

    if (matrixModal && !matrixModal.hidden) {
      closeMatrixModal();
      return;
    }

    if (!modal.hidden) {
      closeModal();
    }
  });

  document.querySelectorAll('[data-patr-state-form]').forEach((stateForm) => {
    stateForm.addEventListener('submit', (event) => {
      const state = stateForm.querySelector('input[name="target_state"]')?.value || '';
      const message = state.toLowerCase() === 'inactivo'
        ? 'Al inactivar esta unidad tambien se pondra Inactivo en placas y se liberara de programacion/conductores. Continuar?'
        : 'La placa volvera a Activo y reaparecera en programacion como SIN_HORARIO si corresponde. Continuar?';

      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  form.addEventListener('submit', () => {
    submit.disabled = true;
    submit.querySelector('span').textContent = 'Guardando...';
  });

  form.querySelector('[data-field="placa"]')?.addEventListener('input', (event) => {
    const start = event.target.selectionStart;
    event.target.value = event.target.value.toUpperCase();
    event.target.setSelectionRange(start, start);
  });

  searchInput?.addEventListener('input', applyFilters);
  statusInput?.addEventListener('change', applyFilters);
  applyFilters();
})();
