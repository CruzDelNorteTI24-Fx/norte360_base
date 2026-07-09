(function (window, document) {
    'use strict';

    const DEFAULT_TITLE = 'Procesando...';
    const DEFAULT_DETAIL = 'Por favor espere';
    let depth = 0;
    let root = null;
    let titleEl = null;
    let detailEl = null;
    const busyButtons = new WeakMap();

    function ensureLoader() {
        if (root) return root;

        root = document.createElement('div');
        root.className = 'n360-loader';
        root.id = 'n360Loader';
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');
        root.setAttribute('aria-hidden', 'true');
        root.innerHTML = [
            '<div class="n360-loader__card">',
            '  <div class="n360-loader__mark"><div class="n360-loader__spinner" aria-hidden="true"></div></div>',
            '  <p class="n360-loader__title">Procesando...</p>',
            '  <p class="n360-loader__detail">Por favor espere</p>',
            '  <div class="n360-loader__bar" aria-hidden="true"></div>',
            '</div>'
        ].join('');

        document.body.appendChild(root);
        titleEl = root.querySelector('.n360-loader__title');
        detailEl = root.querySelector('.n360-loader__detail');
        return root;
    }

    function resolveButton(button) {
        if (!button) return null;
        if (typeof button === 'string') return document.querySelector(button);
        return button;
    }

    function setButtonBusy(button, busy) {
        const target = resolveButton(button);
        if (!target) return;

        if (busy) {
            if (!busyButtons.has(target)) {
                busyButtons.set(target, {
                    disabled: target.disabled,
                    ariaBusy: target.getAttribute('aria-busy')
                });
            }
            target.disabled = true;
            target.setAttribute('aria-busy', 'true');
            target.classList.add('n360-is-loading');
            return;
        }

        const prev = busyButtons.get(target);
        target.classList.remove('n360-is-loading');
        if (prev) {
            target.disabled = prev.disabled;
            if (prev.ariaBusy === null) target.removeAttribute('aria-busy');
            else target.setAttribute('aria-busy', prev.ariaBusy);
            busyButtons.delete(target);
        } else {
            target.disabled = false;
            target.removeAttribute('aria-busy');
        }
    }

    function show(options) {
        const config = typeof options === 'string' ? {title: options} : (options || {});
        const loader = ensureLoader();

        titleEl.textContent = config.title || config.message || DEFAULT_TITLE;
        detailEl.textContent = config.detail || DEFAULT_DETAIL;

        depth += 1;
        loader.classList.add('is-active');
        loader.setAttribute('aria-hidden', 'false');
        document.body.classList.add('n360-loader-open');

        return Symbol('n360-loader-token');
    }

    function hide() {
        if (!root) return;

        depth = Math.max(depth - 1, 0);
        if (depth > 0) return;

        root.classList.remove('is-active');
        root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('n360-loader-open');
    }

    async function during(task, options) {
        const config = options || {};
        const button = resolveButton(config.button);

        setButtonBusy(button, true);
        show(config);

        try {
            return await (typeof task === 'function' ? task() : task);
        } finally {
            hide();
            setButtonBusy(button, false);
        }
    }

    window.N360Loader = {
        show,
        hide,
        during,
        setButtonBusy
    };
})(window, document);
