(function () {
  const searchInput = document.querySelector('[data-gplac-search-input]');
  const rows = Array.from(document.querySelectorAll('[data-gplac-row]'));
  const tabs = Array.from(document.querySelectorAll('[data-gplac-filter]'));
  const countPill = document.querySelector('[data-gplac-visible-count]');
  let activeFilter = 'all';

  function normalize(value) {
    return String(value || '').toLowerCase().trim();
  }

  function rowMatches(row) {
    const query = normalize(searchInput ? searchInput.value : '');
    const origin = row.dataset.origin || '';
    const state = row.dataset.state || '';
    const text = normalize(row.dataset.search || row.textContent);

    if (query && !text.includes(query)) return false;
    if (activeFilter === 'fleet' && origin !== 'flota') return false;
    if (activeFilter === 'external' && origin !== 'externa') return false;
    if (activeFilter === 'inactive' && state !== 'inactiva') return false;
    if (activeFilter !== 'inactive' && state === 'inactiva') return false;

    return true;
  }

  function applyFilters() {
    let visible = 0;
    rows.forEach((row) => {
      const show = rowMatches(row);
      row.hidden = !show;
      if (show) visible += 1;
    });

    if (countPill) {
      countPill.textContent = `${visible} visibles`;
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
  }

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      activeFilter = tab.dataset.gplacFilter || 'all';
      tabs.forEach((item) => item.classList.toggle('is-active', item === tab));
      applyFilters();
    });
  });

  function setOriginDefaults(form, origin) {
    if (!form) return;

    const tipo = form.querySelector('[name="tipo"]');
    const servicio = form.querySelector('[name="servicio"]');
    const bus = form.querySelector('[name="bus_nombre"]');

    if (origin === 'externa') {
      if (tipo && (!tipo.value || tipo.value.toUpperCase() === 'BUS')) tipo.value = 'EXTERNO';
      if (servicio && (!servicio.value || servicio.value.toUpperCase() === 'TRANSPORTE' || servicio.value.toUpperCase() === 'REGULAR')) servicio.value = 'COMBUSTIBLE';
      if (bus) bus.placeholder = 'Nombre visible o referencia externa';
      return;
    }

    if (tipo && (!tipo.value || tipo.value.toUpperCase() === 'EXTERNO')) tipo.value = 'BUS';
    if (servicio && (!servicio.value || servicio.value.toUpperCase() === 'COMBUSTIBLE')) servicio.value = 'TRANSPORTE';
    if (bus) bus.placeholder = 'Numero de bus. Ej. 158';
  }

  document.querySelectorAll('[data-gplac-origin-select]').forEach((select) => {
    select.addEventListener('change', () => setOriginDefaults(select.closest('form'), select.value));
    setOriginDefaults(select.closest('form'), select.value);
  });

  document.querySelectorAll('[data-gplac-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      const modal = document.getElementById('gplacEditModal');
      const form = modal ? modal.querySelector('form') : null;
      if (!form) return;

      let data = {};
      try {
        data = JSON.parse(button.dataset.gplacEdit || '{}');
      } catch (error) {
        data = {};
      }

      Object.entries({
        placa_id: data.id,
        placa: data.placa,
        bus_nombre: data.bus,
        dueno: data.dueno,
        tipo: data.tipo,
        servicio: data.servicio,
        kilometraje: data.kilometraje,
        estado: data.estado_key,
        origen_registro: data.origin
      }).forEach(([name, value]) => {
        const field = form.querySelector(`[name="${name}"]`);
        if (field) field.value = value || '';
      });

      const origin = form.querySelector('[name="origen_registro"]');
      setOriginDefaults(form, origin ? origin.value : 'flota');
    });
  });

  document.querySelectorAll('[data-gplac-uppercase]').forEach((input) => {
    input.addEventListener('input', () => {
      const start = input.selectionStart;
      const end = input.selectionEnd;
      input.value = input.value.toUpperCase();
      try {
        input.setSelectionRange(start, end);
      } catch (error) {
        // Some mobile keyboards do not expose selection ranges.
      }
    });
  });

  applyFilters();
})();
