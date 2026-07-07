function n360ToggleModule(id) {
    const module = document.querySelector(`.sidebar-module[data-module="${id}"]`);
    if (!module) return;

    const wasOpen = module.classList.contains('open');

    document.querySelectorAll('.sidebar-module').forEach(item => {
        item.classList.remove('open');
    });

    if (!wasOpen) {
        module.classList.add('open');
        localStorage.setItem('n360_sidebar_module', id);
    } else {
        localStorage.removeItem('n360_sidebar_module');
    }
}

function n360ToggleSidebarDesktop() {
    document.body.classList.toggle('sidebar-collapsed');

    const collapsed = document.body.classList.contains('sidebar-collapsed');
    localStorage.setItem('n360_sidebar_collapsed', collapsed ? '1' : '0');
}

function n360ToggleSidebarMobile() {
    const sidebar = document.getElementById('sidebarN360');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !overlay) return;

    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebarN360');
    if (!sidebar) return;

    document.body.classList.add('with-sidebar');

    const collapsed = localStorage.getItem('n360_sidebar_collapsed');

    if (collapsed === '1') {
        document.body.classList.add('sidebar-collapsed');
    }

    const activeLink = document.querySelector('.sidebar-link.active');

    if (activeLink) {
        const module = activeLink.closest('.sidebar-module');
        if (module) {
            module.classList.add('open');
            localStorage.setItem('n360_sidebar_module', module.dataset.module);
        }
    } else {
        const savedModule = localStorage.getItem('n360_sidebar_module');
        const module = savedModule
            ? document.querySelector(`.sidebar-module[data-module="${savedModule}"]`)
            : document.querySelector('.sidebar-module');

        if (module) module.classList.add('open');
    }

    const search = document.getElementById('sidebarSearch');

    if (search) {
        search.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();

            document.querySelectorAll('.sidebar-module').forEach(module => {
                let hasVisible = false;

                module.querySelectorAll('.sidebar-link').forEach(link => {
                    const text = link.textContent.toLowerCase();
                    const match = text.includes(q);

                    link.style.display = match ? 'flex' : 'none';

                    if (match) hasVisible = true;
                });

                module.style.display = hasVisible ? 'block' : 'none';

                if (q && hasVisible) {
                    module.classList.add('open');
                }
            });
        });
    }
});
