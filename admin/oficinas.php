<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';

function n360_office_search_text(array $parts): string {
    $text = trim(implode(' ', array_filter(array_map('strval', $parts), static fn($value) => trim($value) !== '')));
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

$sedes = n360_admin_query_all($conn, "
    SELECT clm_sedes_id, clm_sedes_name
    FROM tb_sedes
    ORDER BY clm_sedes_name ASC
");

$usuarios = n360_admin_query_all($conn, "
    SELECT id_usuario, usuario, nombre, DNI, web_rol, clm_usuarios_sede
    FROM tb_usuarios
    ORDER BY nombre ASC, usuario ASC
");

$anaqueles = n360_admin_query_all($conn, "
    SELECT clm_alm_anaquel_id, clm_alm_anaquel_nombre, clm_alm_anaquel_codigo, clm_alm_anaquel_idSEDE
    FROM tb_alm_anaquel
    ORDER BY clm_alm_anaquel_nombre ASC
");

$tieneSedeEspacio = n360_admin_has_column($conn, 'tb_espacio', 'clm_esp_idsede');
$espacios = n360_admin_query_all($conn, $tieneSedeEspacio ? "
    SELECT clm_esp_id, clm_esp_nombre, clm_esp_desc, clm_esp_obs, clm_esp_idsede
    FROM tb_espacio
    ORDER BY clm_esp_nombre ASC
" : "
    SELECT clm_esp_id, clm_esp_nombre, clm_esp_desc, clm_esp_obs
    FROM tb_espacio
    ORDER BY clm_esp_nombre ASC
");

$usuariosPorSede = [];
foreach ($usuarios as $usuario) {
    $usuariosPorSede[(int)($usuario['clm_usuarios_sede'] ?? 0)][] = $usuario;
}

$anaquelesPorSede = [];
foreach ($anaqueles as $anaquel) {
    $anaquelesPorSede[(int)($anaquel['clm_alm_anaquel_idSEDE'] ?? 0)][] = $anaquel;
}

$espaciosPorSede = [];
$espaciosSinSede = [];
foreach ($espacios as $espacio) {
    if ($tieneSedeEspacio) {
        $espaciosPorSede[(int)($espacio['clm_esp_idsede'] ?? 0)][] = $espacio;
    } else {
        $espaciosSinSede[] = $espacio;
    }
}

$sedesConContenido = 0;
foreach ($sedes as $sede) {
    $sedeId = (int)($sede['clm_sedes_id'] ?? 0);
    if (!empty($usuariosPorSede[$sedeId]) || !empty($anaquelesPorSede[$sedeId]) || !empty($espaciosPorSede[$sedeId])) {
        $sedesConContenido++;
    }
}

n360_admin_render_head('Oficinas');
?>
<?php n360_render_header(['title' => 'Oficinas', 'subtitle' => 'Administracion']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-cat-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-cat-hero admin-cat-hero--office">
            <div>
                <span class="admin-cat-kicker"><i class="bi bi-building" aria-hidden="true"></i> Administracion - Maestros</span>
                <h1>Oficinas</h1>
                <p>Mapa operativo por sede con usuarios, anaqueles y espacios vinculados en una sola lectura.</p>
            </div>
            <div class="admin-cat-badge">
                <span>Activas</span>
                <strong><?= $sedesConContenido ?></strong>
            </div>
        </section>

        <section class="admin-cat-kpis">
            <article class="admin-cat-kpi"><span>Sedes</span><strong><?= count($sedes) ?></strong></article>
            <article class="admin-cat-kpi"><span>Usuarios</span><strong><?= count($usuarios) ?></strong></article>
            <article class="admin-cat-kpi"><span>Anaqueles</span><strong><?= count($anaqueles) ?></strong></article>
            <article class="admin-cat-kpi"><span>Espacios</span><strong><?= count($espacios) ?></strong></article>
        </section>

        <section class="admin-cat-office-toolbar" aria-label="Filtros de oficinas">
            <label class="admin-cat-searchbox" for="adminOfficeSearch">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="adminOfficeSearch" type="search" placeholder="Buscar sede, usuario, anaquel, codigo o espacio..." autocomplete="off">
            </label>
            <div class="admin-cat-filter-actions">
                <select id="adminOfficeType">
                    <option value="all">Todo el contenido</option>
                    <option value="users">Con usuarios</option>
                    <option value="shelves">Con anaqueles</option>
                    <option value="spaces">Con espacios</option>
                    <option value="empty">Sin asignaciones</option>
                </select>
                <button type="button" id="adminOfficeClear" class="admin-cat-soft-btn">
                    <i class="bi bi-x-circle" aria-hidden="true"></i>
                    <span>Limpiar</span>
                </button>
            </div>
            <span id="adminOfficeResult" class="admin-cat-filter-result">Mostrando <?= count($sedes) ?> sedes</span>
        </section>

        <?php if (!$tieneSedeEspacio): ?>
            <div class="admin-cat-note">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span>Los espacios se muestran como globales hasta que exista <strong>clm_esp_idsede</strong> en tb_espacio.</span>
            </div>
        <?php endif; ?>

        <section class="admin-cat-office-grid" id="adminOfficeGrid">
            <?php if (!$sedes): ?>
                <div class="admin-cat-empty">No se encontraron sedes.</div>
            <?php endif; ?>
            <?php foreach ($sedes as $sede): ?>
                <?php
                $sedeId = (int)($sede['clm_sedes_id'] ?? 0);
                $sedeNombre = trim((string)($sede['clm_sedes_name'] ?? ('Sede ' . $sedeId)));
                $sedeUsuarios = $usuariosPorSede[$sedeId] ?? [];
                $sedeAnaqueles = $anaquelesPorSede[$sedeId] ?? [];
                $sedeEspacios = $espaciosPorSede[$sedeId] ?? [];
                $totalSede = count($sedeUsuarios) + count($sedeAnaqueles) + count($sedeEspacios);
                $meterValue = min(100, max(8, $totalSede * 12));
                $searchParts = [$sedeNombre, $sedeId];
                foreach ($sedeUsuarios as $usuario) {
                    $searchParts[] = $usuario['nombre'] ?? '';
                    $searchParts[] = $usuario['usuario'] ?? '';
                    $searchParts[] = $usuario['DNI'] ?? '';
                    $searchParts[] = $usuario['web_rol'] ?? '';
                }
                foreach ($sedeAnaqueles as $anaquel) {
                    $searchParts[] = $anaquel['clm_alm_anaquel_nombre'] ?? '';
                    $searchParts[] = $anaquel['clm_alm_anaquel_codigo'] ?? '';
                }
                foreach ($sedeEspacios as $espacio) {
                    $searchParts[] = $espacio['clm_esp_nombre'] ?? '';
                    $searchParts[] = $espacio['clm_esp_desc'] ?? '';
                    $searchParts[] = n360_admin_space_module($espacio['clm_esp_obs'] ?? 0);
                }
                ?>
                <article
                    class="admin-cat-office admin-cat-office--pro"
                    data-office-card
                    data-search="<?= n360_admin_h(n360_office_search_text($searchParts)) ?>"
                    data-users-count="<?= count($sedeUsuarios) ?>"
                    data-shelves-count="<?= count($sedeAnaqueles) ?>"
                    data-spaces-count="<?= count($sedeEspacios) ?>"
                >
                    <div class="admin-cat-office__head">
                        <div class="admin-cat-office-title">
                            <span class="admin-cat-office-icon"><i class="bi bi-buildings-fill" aria-hidden="true"></i></span>
                            <div>
                                <h3><?= n360_admin_h($sedeNombre) ?></h3>
                                <span>ID <?= $sedeId ?> · <?= $totalSede ?> elementos vinculados</span>
                            </div>
                        </div>
                        <div class="admin-cat-office-actions">
                            <div class="admin-cat-chip-list">
                                <span class="admin-cat-chip"><i class="bi bi-people-fill" aria-hidden="true"></i><?= count($sedeUsuarios) ?></span>
                                <span class="admin-cat-chip"><i class="bi bi-boxes" aria-hidden="true"></i><?= count($sedeAnaqueles) ?></span>
                                <span class="admin-cat-chip"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i><?= count($sedeEspacios) ?></span>
                            </div>
                            <button type="button" class="admin-cat-icon-btn" data-office-toggle aria-expanded="true" title="Contraer sede">
                                <i class="bi bi-chevron-up" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="admin-cat-office-meter" style="--office-meter: <?= $meterValue ?>%;"><span></span></div>
                    <div class="admin-cat-office__body">
                        <div class="admin-cat-office-column">
                            <h4><i class="bi bi-people-fill" aria-hidden="true"></i> Usuarios</h4>
                            <div class="admin-cat-mini-list">
                                <?php if (!$sedeUsuarios): ?><span class="admin-cat-mini-item admin-cat-mini-item--empty">Sin usuarios asignados</span><?php endif; ?>
                                <?php foreach ($sedeUsuarios as $usuario): ?>
                                    <span class="admin-cat-mini-item">
                                        <?= n360_admin_h($usuario['nombre'] ?: $usuario['usuario']) ?>
                                        <small><?= n360_admin_h($usuario['web_rol'] ?? 'Usuario') ?> · <?= n360_admin_h($usuario['DNI'] ?: 'Sin DNI') ?></small>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="admin-cat-office-column">
                            <h4><i class="bi bi-boxes" aria-hidden="true"></i> Anaqueles</h4>
                            <div class="admin-cat-mini-list">
                                <?php if (!$sedeAnaqueles): ?><span class="admin-cat-mini-item admin-cat-mini-item--empty">Sin anaqueles registrados</span><?php endif; ?>
                                <?php foreach ($sedeAnaqueles as $anaquel): ?>
                                    <span class="admin-cat-mini-item">
                                        <?= n360_admin_h($anaquel['clm_alm_anaquel_nombre'] ?? '') ?>
                                        <small><?= n360_admin_h($anaquel['clm_alm_anaquel_codigo'] ?: 'Sin codigo') ?></small>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="admin-cat-office-column">
                            <h4><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i> Espacios</h4>
                            <div class="admin-cat-mini-list">
                                <?php if (!$sedeEspacios): ?><span class="admin-cat-mini-item admin-cat-mini-item--empty">Sin espacios vinculados</span><?php endif; ?>
                                <?php foreach ($sedeEspacios as $espacio): ?>
                                    <span class="admin-cat-mini-item">
                                        <?= n360_admin_h($espacio['clm_esp_nombre'] ?? '') ?> - <?= n360_admin_h($espacio['clm_esp_desc'] ?? '') ?>
                                        <small><?= n360_admin_h(n360_admin_space_module($espacio['clm_esp_obs'] ?? 0)) ?></small>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="admin-cat-no-results" id="adminOfficeNoResults" hidden>
                <i class="bi bi-search" aria-hidden="true"></i>
                <strong>Sin coincidencias</strong>
                <span>Ajusta el filtro o limpia la busqueda.</span>
            </div>
        </section>

        <?php if ($espaciosSinSede): ?>
            <section class="admin-cat-panel" id="adminOfficeGlobalSpaces">
                <div class="admin-cat-panel__head">
                    <div>
                        <h2>Espacios globales</h2>
                        <p>Se listan aparte porque aun no tienen campo de sede.</p>
                    </div>
                    <span class="admin-cat-chip"><?= count($espaciosSinSede) ?> espacios</span>
                </div>
                <div class="admin-cat-grid admin-cat-grid--inside">
                    <?php foreach ($espaciosSinSede as $espacio): ?>
                        <?php
                        $globalSearch = n360_office_search_text([
                            $espacio['clm_esp_nombre'] ?? '',
                            $espacio['clm_esp_desc'] ?? '',
                            n360_admin_space_module($espacio['clm_esp_obs'] ?? 0),
                            $espacio['clm_esp_id'] ?? '',
                        ]);
                        ?>
                        <article class="admin-cat-card admin-cat-global-space" data-global-space data-search="<?= n360_admin_h($globalSearch) ?>">
                            <h3><?= n360_admin_h($espacio['clm_esp_nombre'] ?? '') ?></h3>
                            <p><?= n360_admin_h($espacio['clm_esp_desc'] ?? '') ?></p>
                            <div class="admin-cat-card__meta">
                                <span class="admin-cat-chip"><?= n360_admin_h(n360_admin_space_module($espacio['clm_esp_obs'] ?? 0)) ?></span>
                                <span class="admin-cat-chip admin-cat-chip--muted">ID <?= n360_admin_h($espacio['clm_esp_id'] ?? '') ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>
<script>
(function () {
    const input = document.getElementById('adminOfficeSearch');
    const type = document.getElementById('adminOfficeType');
    const clear = document.getElementById('adminOfficeClear');
    const result = document.getElementById('adminOfficeResult');
    const noResults = document.getElementById('adminOfficeNoResults');
    const cards = Array.from(document.querySelectorAll('[data-office-card]'));
    const globalSpaces = Array.from(document.querySelectorAll('[data-global-space]'));
    const globalPanel = document.getElementById('adminOfficeGlobalSpaces');

    if (!input || !type || !result) return;

    function matchesType(card, selected) {
        const users = Number(card.dataset.usersCount || 0);
        const shelves = Number(card.dataset.shelvesCount || 0);
        const spaces = Number(card.dataset.spacesCount || 0);
        if (selected === 'users') return users > 0;
        if (selected === 'shelves') return shelves > 0;
        if (selected === 'spaces') return spaces > 0;
        if (selected === 'empty') return users + shelves + spaces === 0;
        return true;
    }

    function applyFilters() {
        const query = input.value.trim().toLocaleLowerCase('es-PE');
        const selected = type.value;
        let visible = 0;

        cards.forEach((card) => {
            const matchesSearch = query === '' || (card.dataset.search || '').includes(query);
            const show = matchesSearch && matchesType(card, selected);
            card.hidden = !show;
            if (show) visible++;
        });

        let visibleGlobal = 0;
        globalSpaces.forEach((space) => {
            const canShowByType = selected === 'all' || selected === 'spaces';
            const matchesSearch = query === '' || (space.dataset.search || '').includes(query);
            const show = canShowByType && matchesSearch;
            space.hidden = !show;
            if (show) visibleGlobal++;
        });

        if (globalPanel) {
            globalPanel.hidden = visibleGlobal === 0 && (query !== '' || selected !== 'all');
        }

        noResults.hidden = visible > 0 || visibleGlobal > 0;
        result.textContent = `Mostrando ${visible} sedes${visibleGlobal ? ` y ${visibleGlobal} espacios globales` : ''}`;
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-office-toggle]');
        if (!button) return;

        const card = button.closest('[data-office-card]');
        const isCollapsed = card.classList.toggle('is-collapsed');
        button.setAttribute('aria-expanded', String(!isCollapsed));
        button.title = isCollapsed ? 'Expandir sede' : 'Contraer sede';
        const icon = button.querySelector('i');
        if (icon) {
            icon.className = isCollapsed ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
        }
    });

    input.addEventListener('input', applyFilters);
    type.addEventListener('change', applyFilters);
    clear?.addEventListener('click', () => {
        input.value = '';
        type.value = 'all';
        input.focus();
        applyFilters();
    });
})();
</script>
<?php n360_admin_render_close(); ?>
<?php $conn->close(); ?>
