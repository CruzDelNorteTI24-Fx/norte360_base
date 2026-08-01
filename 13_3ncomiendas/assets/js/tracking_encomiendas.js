(function () {
  const root = document.querySelector('[data-enc-tracking]') || document.querySelector('[data-enc-detail-page]');
  const drawer = document.querySelector('[data-enc-detail-drawer]');
  const backdrop = document.querySelector('[data-enc-drawer-backdrop]');
  const drawerBody = drawer?.querySelector('[data-enc-detail-body]');
  const detailPage = document.querySelector('[data-enc-detail-page]');
  const csrf = root?.dataset.csrf || '';
  const transportModalEl = document.getElementById('encTransportDocModal');
  const transportModal = transportModalEl && window.bootstrap ? new bootstrap.Modal(transportModalEl) : null;
  const transportGuideInput = transportModalEl?.querySelector('[data-enc-transport-guide-id]');
  const annulModalEl = document.getElementById('encAnnulModal');
  const annulModal = annulModalEl && window.bootstrap ? new bootstrap.Modal(annulModalEl) : null;
  const annulGuideInput = annulModalEl?.querySelector('[data-enc-annul-guide-id]');
  const annulGuideCode = annulModalEl?.querySelector('[data-enc-annul-guide-code]');
  let currentGuideId = detailPage?.dataset.guideId || null;

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

  const confirmDialog = (message, title) => {
    if (window.N360Dialog && typeof window.N360Dialog.confirm === 'function') {
      return window.N360Dialog.confirm(message, {
        variant: 'warning',
        title: title || 'Confirmar operacion',
        confirmText: 'Confirmar',
        cancelText: 'Cancelar'
      });
    }
    return Promise.resolve(window.confirm(message));
  };

  const setDrawerLoading = () => {
    if (!drawerBody) return;
    drawerBody.innerHTML = '<div class="enc-loading-inline"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Cargando Guia Norte...</div>';
  };

  const openDrawer = () => {
    if (!drawer) return;
    backdrop?.removeAttribute('hidden');
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('enc-drawer-open');
    drawer.querySelector('[data-enc-drawer-close]')?.focus({ preventScroll: true });
  };

  const closeDrawer = () => {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    backdrop?.setAttribute('hidden', 'hidden');
    document.body.classList.remove('enc-drawer-open');
  };

  const loadDetail = async (id, shouldOpen, preferredPanel) => {
    if (!id || !drawerBody) return;
    currentGuideId = id;
    if (shouldOpen) openDrawer();
    setDrawerLoading();

    const task = async () => {
      const response = await fetch(`detalle.php?id=${encodeURIComponent(id)}&partial=1`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const html = await response.text();
      if (!response.ok) throw new Error(html || 'No se pudo cargar el detalle de la Guia Norte.');
      return html;
    };

    try {
      const detailLabel = preferredPanel === 'timeline'
        ? 'Abriendo linea de seguimiento...'
        : preferredPanel === 'history'
          ? 'Abriendo historial operativo...'
          : 'Leyendo ruta, manifiestos e historial...';
      const html = window.N360Loader && window.N360Loader.during
        ? await window.N360Loader.during(task(), { title: 'Cargando Guia Norte', detail: detailLabel })
        : await task();
      drawerBody.innerHTML = html;

      if (preferredPanel) {
        const detail = drawerBody.querySelector('.enc-detail');
        const trigger = detail
          ? Array.from(detail.querySelectorAll('[data-enc-detail-toggle]')).find((button) => button.dataset.encDetailToggle === preferredPanel)
          : null;
        if (trigger) {
          toggleDetailPanel(trigger, { forceOpen: true, scroll: true });
        } else if (detail) {
          const panel = Array.from(detail.querySelectorAll('[data-enc-detail-panel]')).find((node) => node.dataset.encDetailPanel === preferredPanel);
          if (panel) {
            panel.hidden = false;
            panel.classList.add('is-open');
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      }
    } catch (error) {
      drawerBody.innerHTML = `<div class="enc-form-alert enc-form-alert--error">${escapeHtml(error.message || 'No se pudo cargar el detalle.')}</div>`;
    }
  };

  const resetTransportModal = () => {
    if (!transportModalEl) return;
    const form = transportModalEl.querySelector('form');
    form?.reset();
    if (transportGuideInput && currentGuideId) transportGuideInput.value = currentGuideId;
    transportModalEl.querySelectorAll('[data-enc-file-name]').forEach((node) => { node.textContent = 'Ningun PDF seleccionado'; });
  };

  const openTransportModal = (guideId) => {
    currentGuideId = guideId || currentGuideId;
    if (transportGuideInput && currentGuideId) transportGuideInput.value = currentGuideId;
    resetTransportModal();
    transportModal?.show();
  };

  const resetAnnulModal = () => {
    if (!annulModalEl) return;
    const form = annulModalEl.querySelector('form');
    form?.reset();
    if (annulGuideInput) annulGuideInput.value = '';
    if (annulGuideCode) annulGuideCode.textContent = 'Guia Norte';
  };

  const openAnnulModal = (trigger) => {
    if (!annulModalEl || !annulModal) return;
    resetAnnulModal();
    if (annulGuideInput) annulGuideInput.value = trigger.dataset.guideId || '';
    if (annulGuideCode) annulGuideCode.textContent = trigger.dataset.guideCode || 'Guia Norte';
    annulModal.show();
  };

  const toggleDetailPanel = (trigger, options) => {
    const opts = options || {};
    const detail = trigger.closest('.enc-detail') || document;
    const name = trigger.dataset.encDetailToggle || '';
    const buttons = Array.from(detail.querySelectorAll('[data-enc-detail-toggle]'));
    const panels = Array.from(detail.querySelectorAll('[data-enc-detail-panel]'));
    const target = panels.find((panel) => panel.dataset.encDetailPanel === name);
    if (!target) return;

    const shouldOpen = typeof opts.forceOpen === 'boolean'
      ? opts.forceOpen
      : (target.hidden || !trigger.classList.contains('is-active'));

    buttons.forEach((button) => {
      button.classList.remove('is-active');
      button.setAttribute('aria-expanded', 'false');
    });
    panels.forEach((panel) => {
      panel.hidden = true;
      panel.classList.remove('is-open');
    });

    if (shouldOpen) {
      trigger.classList.add('is-active');
      trigger.setAttribute('aria-expanded', 'true');
      target.hidden = false;
      target.classList.add('is-open');
      if (opts.scroll !== false) {
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }
  };
  document.addEventListener('click', (event) => {
    const detailPanelTrigger = event.target.closest('[data-enc-detail-toggle]');
    if (detailPanelTrigger) {
      event.preventDefault();
      toggleDetailPanel(detailPanelTrigger);
      return;
    }

    const sectionTrigger = event.target.closest('[data-enc-detail-section]');
    if (sectionTrigger) {
      event.preventDefault();
      loadDetail(sectionTrigger.dataset.guideId, true, sectionTrigger.dataset.encDetailSection);
      return;
    }

    const annulTrigger = event.target.closest('[data-enc-annul-open]');
    if (annulTrigger) {
      event.preventDefault();
      openAnnulModal(annulTrigger);
      return;
    }

    const detailTrigger = event.target.closest('[data-enc-detail]');
    if (detailTrigger) {
      event.preventDefault();
      loadDetail(detailTrigger.dataset.encDetail, true);
      return;
    }

    if (event.target.closest('[data-enc-drawer-close]') || event.target.closest('[data-enc-drawer-backdrop]')) {
      event.preventDefault();
      closeDrawer();
      return;
    }

    const transportTrigger = event.target.closest('[data-enc-transport-open]');
    if (transportTrigger) {
      event.preventDefault();
      openTransportModal(transportTrigger.dataset.guideId);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && drawer?.classList.contains('is-open')) closeDrawer();
  });

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.enc-ajax-form');
    if (!form) return;
    event.preventDefault();

    if (form.dataset.confirm) {
      const confirmed = await confirmDialog(form.dataset.confirm, form.dataset.confirmTitle || 'Confirmar');
      if (!confirmed) return;
    }

    const button = form.querySelector('[type="submit"]');
    const wasAnnulModal = !!annulModalEl?.classList.contains('show');
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
      if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo completar la operacion.');
      return data;
    };

    try {
      const data = window.N360Loader && window.N360Loader.during
        ? await window.N360Loader.during(task(), { button, title: 'Procesando Guia Norte', detail: 'Actualizando trazabilidad...' })
        : await task();
      await showDialog(data.message || 'Operacion completada.', 'success', 'Listo');

      const guideId = data.id || form.dataset.guideId || currentGuideId;
      if (transportModalEl?.classList.contains('show')) {
        transportModal?.hide();
      }
      if (wasAnnulModal) {
        annulModal?.hide();
        window.location.reload();
        return;
      }

      if (drawer?.classList.contains('is-open') && guideId) {
        await loadDetail(guideId, false);
      } else if (detailPage) {
        window.location.reload();
      } else if (guideId) {
        await loadDetail(guideId, true);
      } else {
        window.location.reload();
      }
    } catch (error) {
      await showDialog(error.message || 'No se pudo completar la operacion.', 'danger', 'Operacion no completada');
    }
  });

  document.addEventListener('change', (event) => {
    const input = event.target.closest('[data-enc-file-label]');
    if (!input) return;
    const target = input.dataset.encFileLabel || '[data-enc-file-name]';
    const label = document.querySelector(target);
    if (label) label.textContent = input.files?.[0]?.name || 'Ningun PDF seleccionado';
  });

  transportModalEl?.addEventListener('hidden.bs.modal', resetTransportModal);
  annulModalEl?.addEventListener('hidden.bs.modal', resetAnnulModal);
})();
