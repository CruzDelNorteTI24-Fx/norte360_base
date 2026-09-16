(function () {
  const cfg = window.N360_SALPROG_HISTORY || {};
  const modalEl = document.getElementById('n360SalprogHistoryModal');
  const openButtons = Array.from(document.querySelectorAll('[data-salprog-history-open]'));

  if (!modalEl || !openButtons.length) return;

  const listEl = modalEl.querySelector('[data-salprog-history-list]');
  const totalEl = modalEl.querySelector('[data-salprog-history-total]');
  const searchEl = modalEl.querySelector('[data-salprog-history-search]');
  const actionEl = modalEl.querySelector('[data-salprog-history-action]');
  const periodEl = modalEl.querySelector('[data-salprog-history-period]');
  const modal = window.bootstrap && window.bootstrap.Modal
    ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
    : null;
  let rows = [];

  const fieldLabels = {
    clm_salprog_id: 'ID consolidado',
    clm_salprog_cierre_id: 'ID cierre',
    clm_salprog_fecha_operativa: 'Fecha operativa',
    clm_salprog_fecha_ejecucion: 'Fecha de ejecucion',
    clm_salprog_run_id: 'Run ID',
    clm_salprog_progid: 'ID programacion',
    clm_salprog_idplaca: 'ID placa',
    clm_salprog_bus: 'Bus',
    clm_salprog_placa: 'Placa',
    clm_salprog_servicio: 'Servicio',
    clm_salprog_idorigen: 'ID origen',
    clm_salprog_origen: 'Origen',
    clm_salprog_iddestino: 'ID destino',
    clm_salprog_destino: 'Destino',
    clm_salprog_ruta_ids: 'IDs de ruta',
    clm_salprog_ruta_texto: 'Ruta',
    clm_salprog_horasalida: 'Hora de salida',
    clm_salprog_hora_orden: 'Orden operativo',
    clm_salprog_fecha_programacion: 'Fecha de programacion',
    clm_salprog_comentario_horario: 'Comentario del horario',
    clm_salprog_conductores_json: 'Datos de conductores',
    clm_salprog_conductores_texto: 'Conductores',
    clm_salprog_cond1_estado: 'Estado conductor 1',
    clm_salprog_imtotalcond1: 'Pago conductor 1',
    clm_salprog_cond1_observacion: 'Observacion conductor 1',
    clm_salprog_cond2_estado: 'Estado conductor 2',
    clm_salprog_imtotalcond2: 'Pago conductor 2',
    clm_salprog_cond2_observacion: 'Observacion conductor 2',
    clm_salprog_revision_estado: 'Estado de revision',
    clm_salprog_comentario_revision: 'Comentario de revision',
    clm_salprog_correccion: 'Correccion',
    clm_salprog_hojaruta: 'Hoja de ruta',
    clm_salprog_usuario_revision: 'Usuario de revision',
    clm_salprog_datetime_revision: 'Fecha de revision',
    clm_salprog_usuario_creacion: 'Usuario de creacion',
    clm_salprog_fecha_creacion: 'Fecha de creacion',
    clm_salprog_estadoidavuelta: 'Ida / vuelta',
    clm_salprog_imtotaldelviaje: 'Importe total del viaje',
    clm_salprog_comentariocontroldelviaje: 'Comentario de control'
  };

  const compact = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));

  function formatDate(value, includeTime) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return compact(value) || '-';
    const date = `${match[3]}/${match[2]}/${match[1]}`;
    return includeTime && match[4] ? `${date} ${match[4]}:${match[5]}` : date;
  }

  function displayValue(value) {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);

    const raw = String(value);
    const trimmed = raw.trim();
    if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
      try {
        return JSON.stringify(JSON.parse(trimmed), null, 2);
      } catch (error) {
        return raw;
      }
    }
    return raw;
  }

  function sameValue(left, right) {
    return JSON.stringify(left ?? null) === JSON.stringify(right ?? null);
  }

  function changedFields(row) {
    const oldSnapshot = row.snapshot_old || {};
    const newSnapshot = row.snapshot_new || {};
    const recorded = Array.isArray(row.campos_modificados)
      ? row.campos_modificados.filter((field) => field && field !== 'registro_completo')
      : [];

    if (recorded.length) return recorded;

    return Array.from(new Set([...Object.keys(oldSnapshot), ...Object.keys(newSnapshot)]))
      .filter((field) => !sameValue(oldSnapshot[field], newSnapshot[field]));
  }

  function actionLabel(action) {
    if (action === 'INSERT') return 'Creacion';
    if (action === 'DELETE') return 'Eliminacion';
    return 'Actualizacion';
  }

  function originLabel(value) {
    const origin = compact(value);
    if (origin === 'sp_cierre_operativo_progbuses') return 'Cierre automatico';
    if (origin === 'db_directa') return 'Base de datos';
    if (origin.startsWith('consolidado_salidas_buses:')) {
      return `Consolidado | ${origin.split(':').slice(1).join(':').replace(/_/g, ' ')}`;
    }
    if (origin.startsWith('control_conductores_salidas:')) {
      return `Control | ${origin.split(':').slice(1).join(':').replace(/_/g, ' ')}`;
    }
    return origin || 'Origen no registrado';
  }

  function actorLabel(row) {
    const user = compact(row.usuario);
    if (user) return user;
    if (row.usuario_id) return `Usuario #${row.usuario_id}`;
    return compact(row.db_user) || 'Usuario no registrado';
  }

  function unitLabel(row) {
    const bus = compact(row.bus);
    const plate = compact(row.placa);
    if (bus && plate) return `${bus} (${plate})`;
    return bus || plate || 'Unidad sin identificar';
  }

  function renderChange(row, field) {
    const before = row.snapshot_old ? row.snapshot_old[field] : null;
    const after = row.snapshot_new ? row.snapshot_new[field] : null;
    return `
      <section class="n360-salprog-history-change">
        <h4>${escapeHtml(fieldLabels[field] || field)}</h4>
        <div>
          <article>
            <span>Antes</span>
            <pre>${escapeHtml(displayValue(before))}</pre>
          </article>
          <article>
            <span>Despues</span>
            <pre>${escapeHtml(displayValue(after))}</pre>
          </article>
        </div>
      </section>`;
  }

  function renderRow(row) {
    const action = compact(row.accion).toUpperCase() || 'UPDATE';
    const fields = changedFields(row);
    const changes = fields.length
      ? fields.map((field) => renderChange(row, field)).join('')
      : '<div class="n360-salprog-history-state">El evento no contiene diferencias para mostrar.</div>';
    const fieldCount = fields.length;

    return `
      <details class="n360-salprog-history-item n360-salprog-history-item--${escapeHtml(action.toLowerCase())}">
        <summary>
          <span class="n360-salprog-history-action">${escapeHtml(actionLabel(action))}</span>
          <span class="n360-salprog-history-unit">
            <strong>${escapeHtml(unitLabel(row))}</strong>
            <small>Registro #${escapeHtml(row.salprog_id || '-')} | ${escapeHtml(formatDate(row.fecha_operativa, false))} ${escapeHtml(compact(row.hora_salida).slice(0, 5))}</small>
          </span>
          <span class="n360-salprog-history-actor">
            <strong>${escapeHtml(actorLabel(row))}</strong>
            <small>${escapeHtml(formatDate(row.fecha_evento, true))}</small>
          </span>
          <span class="n360-salprog-history-count">${fieldCount}</span>
          <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="n360-salprog-history-body">
          <div class="n360-salprog-history-meta">
            <span><small>Origen</small><strong>${escapeHtml(originLabel(row.origen_evento))}</strong></span>
            <span><small>Usuario SQL</small><strong>${escapeHtml(compact(row.db_user) || '-')}</strong></span>
            <span><small>Estado</small><strong>${escapeHtml(compact(row.revision_estado) || '-')}</strong></span>
          </div>
          <div class="n360-salprog-history-changes">${changes}</div>
        </div>
      </details>`;
  }

  function rowSearchText(row) {
    return compact([
      row.accion,
      row.salprog_id,
      row.fecha_evento,
      row.fecha_operativa,
      row.bus,
      row.placa,
      row.revision_estado,
      row.usuario,
      row.usuario_id,
      row.db_user,
      row.origen_evento,
      ...(row.campos_modificados || []),
      JSON.stringify(row.snapshot_old || {}),
      JSON.stringify(row.snapshot_new || {})
    ].join(' ')).toLowerCase();
  }

  function render() {
    const query = compact(searchEl?.value).toLowerCase();
    const action = compact(actionEl?.value).toUpperCase() || 'TODOS';
    const visible = rows.filter((row) => {
      if (action !== 'TODOS' && compact(row.accion).toUpperCase() !== action) return false;
      return !query || rowSearchText(row).includes(query);
    });

    if (totalEl) totalEl.textContent = String(visible.length);
    if (!listEl) return;

    listEl.innerHTML = visible.length
      ? visible.map(renderRow).join('')
      : '<div class="n360-salprog-history-state">No hay movimientos que coincidan con los filtros.</div>';
  }

  function showModal() {
    if (modal) {
      modal.show();
      return;
    }
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
  }

  async function loadHistory(button) {
    rows = [];
    if (searchEl) searchEl.value = '';
    if (actionEl) actionEl.value = 'TODOS';
    if (totalEl) totalEl.textContent = '0';
    if (periodEl) {
      periodEl.textContent = `${formatDate(cfg.fechaInicio, false)} - ${formatDate(cfg.fechaFin, false)}`;
    }
    if (listEl) listEl.innerHTML = '<div class="n360-salprog-history-state">Cargando historial...</div>';
    showModal();

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    try {
      const body = new URLSearchParams({
        action: 'audit_history',
        csrf: cfg.csrf || '',
        fecha_inicio: cfg.fechaInicio || '',
        fecha_fin: cfg.fechaFin || ''
      });
      const response = await fetch(cfg.endpoint || window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        credentials: 'same-origin',
        body: body.toString()
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || !payload.ok) {
        throw new Error(payload?.message || 'No se pudo cargar el historial.');
      }

      rows = Array.isArray(payload.data?.rows) ? payload.data.rows : [];
      render();
    } catch (error) {
      if (listEl) {
        listEl.innerHTML = `<div class="n360-salprog-history-state n360-salprog-history-state--error">${escapeHtml(error.message || 'No se pudo cargar el historial.')}</div>`;
      }
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  }

  openButtons.forEach((button) => {
    button.addEventListener('click', () => loadHistory(button));
  });
  searchEl?.addEventListener('input', render);
  actionEl?.addEventListener('change', render);
})();
