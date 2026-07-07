document.addEventListener('DOMContentLoaded', () => {
    const header = document.getElementById('n360Header');
    if (!header) return;

    document.body.classList.add('with-header-n360');

    const menu = header.querySelector('[data-n360-user-menu]');
    const toggle = header.querySelector('[data-n360-user-toggle]');

    function syncHeaderShadow() {
        header.classList.toggle('is-scrolled', window.scrollY > 6);
    }

    function closeMenu() {
        if (!menu || !toggle) return;

        menu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMenu(event) {
        event.preventDefault();
        event.stopPropagation();

        if (!menu || !toggle) return;

        const open = menu.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (toggle) {
        toggle.addEventListener('click', toggleMenu);
    }

    document.addEventListener('click', event => {
        if (!menu || menu.contains(event.target)) return;
        closeMenu();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeMenu();
    });

    window.addEventListener('scroll', syncHeaderShadow, { passive: true });
    syncHeaderShadow();
});