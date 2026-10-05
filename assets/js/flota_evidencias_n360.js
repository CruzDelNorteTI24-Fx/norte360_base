(() => {
  'use strict';
  const cfg = window.N360_FLOTA_EVIDENCE;
  const modalEl = document.getElementById('n360FlotaEvidenceModal');
  if (!cfg || !modalEl || !window.bootstrap) return;

  document.body.append(modalEl);
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const dateInput = modalEl.querySelector('input[type="date"]');
  const slotsEl = modalEl.querySelector('[data-fe-slots]');
  const countEl = modalEl.querySelector('[data-fe-count]');
  const noticeEl = modalEl.querySelector('[data-fe-notice]');
  const band = document.querySelector('[data-fe-band]');
  let loading = false;
  let uploading = false;
  let dayRequest = 0;
  let pageRequest = 0;
  let page = 0;
  const pageSize = 14;
  const dayMs = 86400000;
  const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[char]);
  const label = (date) => /^(\d{4})-(\d{2})-(\d{2})$/.test(date)
    ? date.split('-').reverse().join('/') : date;
  const url = (action, params = {}) => {
    const result = new URL(cfg.endpoint, window.location.href);
    result.search = new URLSearchParams({ action, ...params }).toString();
    return result.href;
  };
  const fileUrl = (item, download = false) => url(download ? 'download' : 'view', {
    id: item.id, revision: item.revision
  });

  async function request(target, options = {}) {
    const response = await fetch(target, { credentials: 'same-origin', ...options });
    let result;
    try { result = await response.json(); } catch {
      throw new Error(response.status === 413
        ? 'El servidor rechazo el archivo por su tamano.' : 'No se pudo leer la respuesta del servidor.');
    }
    if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo completar la operacion.');
    return result.data;
  }

  function notice(message = '', error = false) {
    noticeEl.textContent = message;
    noticeEl.hidden = !message;
    noticeEl.classList.toggle('is-error', error);
  }

  function updateControls() {
    dateInput.disabled = uploading;
    modalEl.querySelector('[data-fe-refresh]').disabled = uploading || loading;
    modalEl.querySelectorAll('[data-bs-dismiss]').forEach((button) => { button.disabled = uploading; });
    slotsEl.querySelectorAll('input, button').forEach((control) => {
      const empty = control.matches('button[type="submit"]')
        && !control.closest('form').querySelector('input[type="file"]').files.length;
      control.disabled = uploading || loading || empty;
    });
  }

  function updateCount(date, rows) {
    document.querySelectorAll('[data-fe-open]').forEach((button) => {
      if (button.dataset.feDate !== date) return;
      const counter = button.querySelector('[data-fe-current-count]');
      if (counter) counter.textContent = `${rows.length}/2`;
    });
  }

  function renderSlots(rows) {
    countEl.textContent = `${rows.length}/2`;
    countEl.classList.toggle('is-complete', rows.length === 2);
    for (const slot of [1, 2]) {
      const item = rows.find((row) => Number(row.cupo) === slot);
      const revision = Number(item?.revision || 0);
      const previous = slotsEl.querySelector(`[data-fe-slot="${slot}"]`);
      // Conservar una seleccion pendiente si el otro espacio acaba de guardarse.
      if (previous && Number(previous.dataset.feRevision) === revision) continue;
      const card = document.createElement('article');
      card.className = 'n360-fe-slot';
      card.dataset.feSlot = slot;
      card.dataset.feRevision = revision;
      const preview = item
        ? (item.mime === 'application/pdf'
          ? `<a class="n360-fe-preview n360-fe-preview--pdf" href="${escape(fileUrl(item))}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i><span>PDF</span></a>`
          : `<a class="n360-fe-preview" href="${escape(fileUrl(item))}" target="_blank" rel="noopener"><img src="${escape(fileUrl(item))}" alt="Evidencia ${slot} del ${escape(label(dateInput.value))}" loading="lazy"></a>`)
        : '<div class="n360-fe-preview n360-fe-preview--empty"><i class="bi bi-image" aria-hidden="true"></i><span>Sin archivo</span></div>';
      card.innerHTML = `<div class="n360-fe-slot-head"><strong>Evidencia ${slot}</strong><span>${item ? 'Adjuntada' : 'Disponible'}</span></div>
        ${preview}
        <div class="n360-fe-file">${item
          ? `<strong title="${escape(item.nombre)}">${escape(item.nombre)}</strong><small>${(Number(item.size) / 1048576).toFixed(2)} MB</small><small>${escape(item.usuario)} · ${escape(item.fechacarga)}</small>
             <div class="n360-fe-file-actions"><a class="btn btn-outline-secondary btn-sm" href="${escape(fileUrl(item))}" target="_blank" rel="noopener"><i class="bi bi-eye" aria-hidden="true"></i> Ver</a><a class="btn btn-outline-secondary btn-sm" href="${escape(fileUrl(item, true))}"><i class="bi bi-download" aria-hidden="true"></i> Descargar</a></div>`
          : '<strong>Sin evidencia</strong>'}</div>
        <form class="n360-fe-upload" data-fe-form="${slot}">
          <label for="n360FeFile${slot}">${item ? 'Reemplazar archivo' : 'Adjuntar archivo'}</label>
          <input type="file" class="form-control form-control-sm" id="n360FeFile${slot}" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required>
          <small>JPG, PNG, WEBP o PDF · Hasta 8 MB</small>
          <button type="submit" class="btn btn-primary btn-sm" disabled><i class="bi bi-upload" aria-hidden="true"></i> ${item ? 'Reemplazar' : 'Adjuntar'}</button>
        </form>`;
      if (previous) previous.replaceWith(card); else slotsEl.append(card);
      card.querySelector('input[type="file"]').addEventListener('change', (event) => {
        const input = event.target;
        const file = input.files[0];
        input.setCustomValidity(file && (file.size === 0 || file.size > Number(cfg.maxBytes))
          ? 'El archivo debe tener contenido y pesar hasta 8 MB.' : '');
        if (file) input.reportValidity();
        updateControls();
      });
      card.querySelector('form').addEventListener('submit', (event) => save(event, slot, revision));
    }
    updateCount(dateInput.value, rows);
    updateControls();
  }

  async function loadDay(clear = true) {
    const date = dateInput.value;
    if (uploading) return;
    if (!date || !dateInput.checkValidity()) {
      dayRequest++;
      loading = false;
      slotsEl.replaceChildren();
      countEl.textContent = '-/2';
      notice('Selecciona una fecha operativa valida.', true);
      updateControls();
      return;
    }
    const current = ++dayRequest;
    loading = true;
    if (clear) slotsEl.replaceChildren();
    countEl.textContent = '-/2';
    notice('Cargando evidencias...');
    updateControls();
    try {
      const result = await request(url('list', { inicio: date, fin: date }));
      if (current !== dayRequest || date !== dateInput.value) return;
      loading = false;
      renderSlots(result.evidencias || []);
      notice();
    } catch (error) {
      if (current !== dayRequest) return;
      notice(error.message, true);
    } finally {
      if (current === dayRequest) { loading = false; updateControls(); }
    }
  }

  async function save(event, slot, revision) {
    event.preventDefault();
    const form = event.currentTarget;
    if (uploading || loading || !form.reportValidity()) return;
    const file = form.querySelector('input[type="file"]').files[0];
    if (!file) return;
    if (revision > 0 && !window.confirm(`Reemplazar la evidencia ${slot} del ${label(dateInput.value)}?`)) return;
    uploading = true;
    const date = dateInput.value;
    updateControls();
    notice('Guardando evidencia...');
    const data = new FormData();
    data.append('action', 'upload');
    data.append('csrf', cfg.csrf);
    data.append('fecha', date);
    data.append('cupo', slot);
    data.append('revision', revision);
    data.append('evidencia', file);
    try {
      const result = await request(cfg.endpoint, { method: 'POST', body: data });
      renderSlots(result.evidencias || []);
      notice('Evidencia guardada.');
      if (band) await loadPage();
    } catch (error) {
      notice(error.message, true);
    } finally {
      uploading = false;
      updateControls();
    }
  }

  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-fe-open]');
    if (!opener || uploading) return;
    dateInput.value = opener.dataset.feDate || dateInput.value;
    const date = dateInput.value;
    const slot = Number(opener.dataset.feFocusSlot || 0);
    modal.show();
    loadDay().then(() => {
      if (dateInput.value !== date || ![1, 2].includes(slot)) return;
      const input = slotsEl.querySelector(`#n360FeFile${slot}`);
      if (input) { input.scrollIntoView({block: 'nearest'}); input.focus({preventScroll: true}); }
    });
  });
  dateInput.addEventListener('change', () => loadDay());
  modalEl.querySelector('[data-fe-refresh]').addEventListener('click', () => loadDay(false));
  modalEl.addEventListener('hide.bs.modal', (event) => { if (uploading) event.preventDefault(); });

  function pageDates() {
    const start = Date.parse(`${band.dataset.feStart}T00:00:00Z`);
    const end = Date.parse(`${band.dataset.feEnd}T00:00:00Z`);
    const total = Math.max(1, Math.round((end - start) / dayMs) + 1);
    const dates = [];
    for (let i = page * pageSize; i < Math.min(total, (page + 1) * pageSize); i++) {
      dates.push(new Date(start + i * dayMs).toISOString().slice(0, 10));
    }
    return { dates, total };
  }

  async function loadPage() {
    const { dates, total } = pageDates();
    const current = ++pageRequest;
    const daysEl = band.querySelector('[data-fe-days]');
    const pagination = band.querySelector('[data-fe-pagination]');
    pagination.hidden = total <= pageSize;
    band.querySelector('[data-fe-prev]').disabled = page === 0;
    band.querySelector('[data-fe-next]').disabled = (page + 1) * pageSize >= total;
    band.querySelector('[data-fe-page-label]').textContent = `${label(dates[0])} - ${label(dates[dates.length - 1])}`;
    daysEl.textContent = 'Cargando evidencias...';
    try {
      const result = await request(url('list', { inicio: dates[0], fin: dates[dates.length - 1] }));
      if (current !== pageRequest) return;
      const rows = result.evidencias || [];
      daysEl.innerHTML = dates.map((date) => {
        const files = rows.filter((item) => item.fecha === date);
        const attachments = [1, 2].map((slot) => {
          const item = files.find((file) => Number(file.cupo) === slot);
          const title = item ? item.nombre : `Adjuntar evidencia ${slot}`;
          const icon = item ? (item.mime === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image') : 'bi-cloud-arrow-up';
          const detail = item ? `${item.mime === 'application/pdf' ? 'PDF' : 'Imagen'} - ${(Number(item.size) / 1048576).toFixed(2)} MB` : 'Imagen o PDF';
          const content = `<i class="bi ${icon} n360-fe-attachment-icon" aria-hidden="true"></i><span class="n360-fe-attachment-text"><strong>${escape(title)}</strong><small>${escape(detail)}</small></span><i class="bi ${item ? 'bi-box-arrow-up-right' : 'bi-plus-lg'} n360-fe-attachment-action" aria-hidden="true"></i>`;
          return item
            ? `<a class="n360-fe-attachment is-attached" href="${escape(fileUrl(item))}" target="_blank" rel="noopener" title="Ver ${escape(item.nombre)}">${content}</a>`
            : `<button type="button" class="n360-fe-attachment" data-fe-open data-fe-date="${escape(date)}" data-fe-focus-slot="${slot}" title="Adjuntar evidencia ${slot} del ${escape(label(date))}">${content}</button>`;
        }).join('');
        return `<div class="n360-fe-day"><div class="n360-fe-day-title"><span class="n360-fe-date-label">D\u00eda operativo</span><div class="n360-fe-date-heading"><strong>${escape(label(date))}</strong><button type="button" class="btn btn-outline-secondary n360-fe-manage" data-fe-open data-fe-date="${escape(date)}" title="Ver o reemplazar las evidencias del ${escape(label(date))}" aria-label="Ver o reemplazar las evidencias del ${escape(label(date))}"><i class="bi bi-folder2-open" aria-hidden="true"></i></button></div><span class="n360-fe-count ${files.length === 2 ? 'is-complete' : ''}">${files.length}/2 archivos</span></div>
          <div class="n360-fe-day-files">${attachments}</div></div>`;
      }).join('');
    } catch (error) {
      if (current !== pageRequest) return;
      daysEl.textContent = error.message;
      const retry = document.createElement('button');
      retry.className = 'btn btn-outline-secondary btn-sm';
      retry.type = 'button';
      retry.textContent = 'Reintentar';
      retry.addEventListener('click', loadPage);
      daysEl.append(retry);
    }
  }

  function refreshCurrentDay(opener) {
    const date = opener.dataset.feDate;
    const counter = opener.querySelector('[data-fe-current-count]');
    if (counter) counter.textContent = '-/2';
    request(url('list', { inicio: date, fin: date }))
      .then((result) => { if (opener.dataset.feDate === date) updateCount(date, result.evidencias || []); })
      .catch(() => {});
  }

  document.addEventListener('n360:flota-operational-date', (event) => {
    const opener = document.querySelector('[data-fe-current-day]');
    const date = event.detail?.fecha;
    if (!opener || !/^\d{4}-\d{2}-\d{2}$/.test(date || '') || opener.dataset.feDate === date) return;
    opener.dataset.feDate = date;
    refreshCurrentDay(opener);
  });

  if (band) {
    band.querySelector('[data-fe-prev]').addEventListener('click', () => { page--; loadPage(); });
    band.querySelector('[data-fe-next]').addEventListener('click', () => { page++; loadPage(); });
    loadPage();
  } else {
    const opener = document.querySelector('[data-fe-open][data-fe-date]');
    if (opener) refreshCurrentDay(opener);
  }
})();
