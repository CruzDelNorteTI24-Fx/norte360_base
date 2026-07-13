<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';

$tieneSede = n360_admin_has_column($conn, 'tb_espacio', 'clm_esp_idsede');
$sqlSede = $tieneSede
    ? ", e.clm_esp_idsede, s.clm_sedes_name AS sede_nombre"
    : ", NULL AS clm_esp_idsede, NULL AS sede_nombre";
$joinSede = $tieneSede ? "LEFT JOIN tb_sedes s ON s.clm_sedes_id = e.clm_esp_idsede" : "";

$espacios = n360_admin_query_all($conn, "
    SELECT
        e.clm_esp_id,
        e.clm_esp_nombre,
        e.clm_esp_desc,
        e.clm_esp_obs
        $sqlSede
    FROM tb_espacio e
    $joinSede
    ORDER BY e.clm_esp_obs ASC, e.clm_esp_nombre ASC
");

$porModulo = ['Almacen' => 0, 'Combustible' => 0, 'Oficina' => 0];
foreach ($espacios as $espacio) {
    $modulo = n360_admin_space_module($espacio['clm_esp_obs'] ?? 0);
    $porModulo[$modulo] = ($porModulo[$modulo] ?? 0) + 1;
}

n360_admin_render_head('Espacios');
?>
<?php n360_render_header(['title' => 'Espacios', 'subtitle' => 'Administracion']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-cat-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-cat-hero">
            <div>
                <span class="admin-cat-kicker"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i> Administracion - Maestros</span>
                <h1>Espacios</h1>
                <p>Mapa de espacios operativos por abreviatura, nombre, tipo de modulo y sede cuando este dato exista en la base.</p>
            </div>
        </section>

        <section class="admin-cat-kpis">
            <article class="admin-cat-kpi"><span>Espacios</span><strong><?= count($espacios) ?></strong></article>
            <article class="admin-cat-kpi"><span>Almacen</span><strong><?= (int)($porModulo['Almacen'] ?? 0) ?></strong></article>
            <article class="admin-cat-kpi"><span>Combustible</span><strong><?= (int)($porModulo['Combustible'] ?? 0) ?></strong></article>
            <article class="admin-cat-kpi"><span>Oficina</span><strong><?= (int)($porModulo['Oficina'] ?? 0) ?></strong></article>
        </section>

        <?php if (!$tieneSede): ?>
            <div class="admin-cat-note">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span>La columna <strong>clm_esp_idsede</strong> aun no existe; cuando la agregues, esta vista mostrara automaticamente la sede de cada espacio.</span>
            </div>
        <?php endif; ?>

        <section class="admin-cat-panel">
            <div class="admin-cat-panel__head">
                <div>
                    <h2>Espacios existentes</h2>
                </div>
            </div>
            <div class="admin-cat-table-wrap">
                <table class="admin-cat-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Abreviatura</th>
                            <th>Nombre</th>
                            <th>Modulo</th>
                            <th>Sede</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$espacios): ?>
                        <tr><td colspan="5" class="admin-cat-empty">No se encontraron espacios.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($espacios as $espacio): ?>
                        <tr>
                            <td><?= n360_admin_h($espacio['clm_esp_id'] ?? '') ?></td>
                            <td><span class="admin-cat-chip"><?= n360_admin_h($espacio['clm_esp_nombre'] ?? '') ?></span></td>
                            <td><div class="admin-cat-main"><strong><?= n360_admin_h($espacio['clm_esp_desc'] ?? '') ?></strong></div></td>
                            <td><?= n360_admin_h(n360_admin_space_module($espacio['clm_esp_obs'] ?? 0)) ?></td>
                            <td><?= n360_admin_h($espacio['sede_nombre'] ?: ($espacio['clm_esp_idsede'] ?? 'Pendiente')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>
<?php n360_admin_render_close(); ?>
<?php $conn->close(); ?>
