<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';

$anaqueles = n360_admin_query_all($conn, "
    SELECT
        a.clm_alm_anaquel_id,
        a.clm_alm_anaquel_nombre,
        a.clm_alm_anaquel_idSEDE,
        a.clm_alm_anaquel_codigo,
        s.clm_sedes_name AS sede_nombre
    FROM tb_alm_anaquel a
    LEFT JOIN tb_sedes s ON s.clm_sedes_id = a.clm_alm_anaquel_idSEDE
    ORDER BY s.clm_sedes_name ASC, a.clm_alm_anaquel_nombre ASC
");

$sedesAnaquel = [];
foreach ($anaqueles as $anaquel) {
    $key = (string)($anaquel['sede_nombre'] ?: $anaquel['clm_alm_anaquel_idSEDE'] ?: 'Sin sede');
    $sedesAnaquel[$key] = true;
}

n360_admin_render_head('Anaqueles');
?>
<?php n360_render_header(['title' => 'Anaqueles', 'subtitle' => 'Administracion']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-cat-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-cat-hero">
            <div>
                <span class="admin-cat-kicker"><i class="bi bi-boxes" aria-hidden="true"></i> Administracion - Maestros</span>
                <h1>Anaqueles</h1>
                <p>Consulta de anaqueles disponibles, codigo operativo y sede asignada.</p>
            </div>
        </section>

        <section class="admin-cat-kpis">
            <article class="admin-cat-kpi"><span>Anaqueles</span><strong><?= count($anaqueles) ?></strong></article>
            <article class="admin-cat-kpi"><span>Sedes</span><strong><?= count($sedesAnaquel) ?></strong></article>
            <article class="admin-cat-kpi"><span>Con codigo</span><strong><?= count(array_filter($anaqueles, static fn($a) => trim((string)($a['clm_alm_anaquel_codigo'] ?? '')) !== '')) ?></strong></article>
            <article class="admin-cat-kpi"><span>Sin sede</span><strong><?= count(array_filter($anaqueles, static fn($a) => trim((string)($a['clm_alm_anaquel_idSEDE'] ?? '')) === '')) ?></strong></article>
        </section>

        <section class="admin-cat-panel">
            <div class="admin-cat-panel__head">
                <div>
                    <h2>Anaqueles existentes</h2>
                </div>
            </div>
            <div class="admin-cat-table-wrap">
                <table class="admin-cat-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Anaquel</th>
                            <th>Codigo</th>
                            <th>Sede</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$anaqueles): ?>
                        <tr><td colspan="4" class="admin-cat-empty">No se encontraron anaqueles.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($anaqueles as $anaquel): ?>
                        <tr>
                            <td><?= n360_admin_h($anaquel['clm_alm_anaquel_id'] ?? '') ?></td>
                            <td><div class="admin-cat-main"><strong><?= n360_admin_h($anaquel['clm_alm_anaquel_nombre'] ?? '') ?></strong></div></td>
                            <td><span class="admin-cat-chip"><?= n360_admin_h($anaquel['clm_alm_anaquel_codigo'] ?: 'Sin codigo') ?></span></td>
                            <td><?= n360_admin_h($anaquel['sede_nombre'] ?: ($anaquel['clm_alm_anaquel_idSEDE'] ?? 'Sin sede')) ?></td>
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
