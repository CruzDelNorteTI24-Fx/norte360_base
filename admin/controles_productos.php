<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';
require_once __DIR__ . '/../layout/admin_catalogo_productos_n360.php';
require_once __DIR__ . '/_controles_productos.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Esta vista permite únicamente consultar los controles de productos.');
}
$conn->set_charset('utf8mb4');

$tabs = [
    'mapa' => ['nombre' => 'Mapa por módulo', 'icono' => 'bi-diagram-3',
        'ayuda' => 'Módulo, área y tipo', 'titulo' => 'Gestión de productos por módulo', 'detalle' => 'Relación entre el módulo de gestión, el área y su tipo de control.'],
    'areas' => ['nombre' => 'Áreas de control', 'icono' => 'bi-building-gear',
        'ayuda' => 'Ámbito de gestión', 'titulo' => 'Áreas de control', 'detalle' => 'Códigos de área y su relación de referencia con los módulos del sistema.'],
    'tipos' => ['nombre' => 'Tipos de control', 'icono' => 'bi-sliders',
        'ayuda' => 'Comportamiento del producto', 'titulo' => 'Tipos de control', 'detalle' => 'Los tipos actuales de los productos y su descripción de uso.'],
];
$view = is_string($_GET['vista'] ?? null) && isset($tabs[$_GET['vista']]) ? $_GET['vista'] : 'mapa';
$areas = []; $types = []; $modulesByType = [];
$areaReady = false; $typeReady = false; $mapReady = false; $error = '';

try {
    $schema = n360_controles_schema($conn);
    $areaReady = $schema['areas']; $typeReady = $schema['tipos']; $mapReady = $schema['mapa'];
    if ($typeReady) {
        $types = n360_controles_rows($conn, 'SELECT clm_alm_tipo_control_id AS id,
            clm_alm_tipo_control_codigo AS codigo, clm_alm_tipo_control_nombre AS nombre,
            clm_alm_tipo_control_descripcion AS descripcion FROM tb_alm_tipo_control
            ORDER BY clm_alm_tipo_control_id');
    }
    if ($mapReady) {
        $areas = n360_controles_rows($conn, 'SELECT a.clm_alm_area_control_id AS id, a.clm_alm_area_control_codigo AS codigo,
            a.clm_alm_area_control_nombre AS nombre, a.clm_alm_area_control_descripcion AS descripcion,
            a.clm_alm_area_control_idmodulo AS modulo_id, m.nombre_modulo AS modulo_nombre,
            a.clm_alm_area_control_idtipo AS tipo_id, t.clm_alm_tipo_control_codigo AS tipo_codigo,
            t.clm_alm_tipo_control_nombre AS tipo_nombre FROM tb_alm_area_control a
            LEFT JOIN tb_modulos m ON m.id_modulo = a.clm_alm_area_control_idmodulo
            LEFT JOIN tb_alm_tipo_control t ON t.clm_alm_tipo_control_id = a.clm_alm_area_control_idtipo
            ORDER BY a.clm_alm_area_control_id');
        foreach ($areas as $area) {
            $modulesByType[(int)$area['tipo_id']][(int)$area['modulo_id']] = n360_controles_module_name($area['modulo_nombre']);
        }
    }
} catch (Throwable $e) {
    error_log('controles_productos.php: ' . $e->getMessage());
    $error = 'No se pudieron consultar los controles de productos. Inténtalo nuevamente.';
    $areaReady = $typeReady = $mapReady = false;
    $areas = $types = $modulesByType = [];
}
$moduleCount = count(array_unique(array_column($areas, 'modulo_id')));
$available = $view === 'tipos' ? $typeReady : $mapReady;
$rows = $view === 'tipos' ? $types : $areas;
$columnCount = $view === 'areas' ? 6 : 5;
n360_admin_render_head('Controles de productos');
?>
<link rel="stylesheet" href="<?= n360_asset('assets/css/admin_controles_productos_n360.css?controles=3') ?>">
<?php n360_render_header(['title' => 'Controles de productos', 'subtitle' => 'Administración']); ?>
<?php n360_render_sidebar(); ?>
<main class="main-content n360-main n360-main--module n360-main--compact-access controles-main" role="main">
<div class="n360-main__inner admin-cat-shell controles-page">
    <?php n360_render_content_separator('top'); ?>
    <section class="admin-cat-hero">
        <div><span class="admin-cat-kicker"><i class="bi bi-diagram-3-fill" aria-hidden="true"></i> Administración - Maestros</span>
            <h1>Controles de productos</h1><p>Consulta las áreas, los tipos de control y su relación con los módulos de Norte360.</p></div>
        <span class="controles-readonly"><i class="bi bi-eye" aria-hidden="true"></i> Solo consulta</span>
    </section>
    <section class="admin-cat-kpis controles-kpis" aria-label="Resumen de catálogos">
        <article class="admin-cat-kpi controles-kpi controles-kpi--areas"><div class="controles-kpi__icon" aria-hidden="true"><i class="bi bi-building-gear"></i></div><div><span>Áreas de control</span><strong><?= $mapReady ? count($areas) : '-' ?></strong><small>Ámbitos de gestión del producto</small></div></article>
        <article class="admin-cat-kpi controles-kpi controles-kpi--tipos"><div class="controles-kpi__icon" aria-hidden="true"><i class="bi bi-sliders"></i></div><div><span>Tipos de control</span><strong><?= $typeReady ? count($types) : '-' ?></strong><small>Formas de controlar los productos</small></div></article>
        <article class="admin-cat-kpi controles-kpi controles-kpi--modulos"><div class="controles-kpi__icon" aria-hidden="true"><i class="bi bi-grid"></i></div><div><span>Módulos</span><strong><?= $mapReady ? $moduleCount : '-' ?></strong><small>Gestiones vinculadas al catálogo</small></div></article>
    </section>
    <?php if ($error !== ''): ?><div class="admin-cat-note controles-error" role="alert"><?= n360_admin_h($error) ?></div>
    <?php elseif (!$mapReady): ?><div class="admin-cat-note" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Los catálogos de controles aún no están habilitados por completo. La consulta estará disponible al completar su instalación.</span></div><?php endif; ?>
    <section class="admin-cat-panel controles-panel">
        <nav class="controles-tabs" aria-label="Catálogos de controles">
            <?php foreach ($tabs as $key => $tab): ?><a href="?vista=<?= $key ?>" class="controles-tab <?= $view === $key ? 'is-current' : '' ?>" <?= $view === $key ? 'aria-current="page"' : '' ?>><span class="controles-tab__icon"><i class="bi <?= $tab['icono'] ?>" aria-hidden="true"></i></span><span class="controles-tab__copy"><strong><?= $tab['nombre'] ?></strong><small><?= $tab['ayuda'] ?></small></span><span class="controles-tab__count"><?= $key === 'tipos' ? ($typeReady ? count($types) : '-') : ($mapReady ? count($areas) : '-') ?></span></a><?php endforeach; ?>
        </nav>
        <div class="admin-cat-panel__head controles-panel__head"><div><h2><?= $tabs[$view]['titulo'] ?></h2><p><?= $tabs[$view]['detalle'] ?></p></div>
            <?php if ($available): ?><span class="controles-count"><?= count($rows) ?> <?= count($rows) === 1 ? 'registro' : 'registros' ?></span><?php endif; ?></div>
        <div class="admin-cat-table-wrap" tabindex="0" role="region" aria-label="<?= $tabs[$view]['titulo'] ?>">
            <table class="admin-cat-table controles-table controles-table--<?= $view ?>"><thead><tr>
                <?php if ($view === 'mapa'): ?><th scope="col">Gestión (módulo)</th><th scope="col">Área de control</th><th scope="col">Tipo de control</th><th scope="col">Descripción</th>
                <?php elseif ($view === 'areas'): ?><th scope="col">Código</th><th scope="col">Área</th><th scope="col">Módulo relacionado</th><th scope="col">Tipo asociado</th><th scope="col">Descripción</th>
                <?php else: ?><th scope="col">Código</th><th scope="col">Tipo de control</th><th scope="col">Descripción</th><th scope="col">Usado en</th><?php endif; ?>
                <th scope="col" class="controles-products-column">Productos</th>
            </tr></thead><tbody>
                <?php if (!$available || !$rows): ?><tr><td colspan="<?= $columnCount ?>" class="admin-cat-empty"><?= $error !== '' ? 'La consulta no está disponible temporalmente.' : (!$available ? 'Este catálogo está pendiente de habilitación.' : 'No hay registros en este catálogo.') ?></td></tr>
                <?php else: foreach ($rows as $row): ?><tr data-control-code="<?= n360_admin_h($row['codigo']) ?>">
                    <?php if ($view === 'mapa'): ?>
                        <td><span class="controles-module"><span class="controles-module__icon"><i class="bi bi-grid" aria-hidden="true"></i></span><?= n360_admin_h(n360_controles_module_name($row['modulo_nombre'])) ?></span></td>
                        <td><span class="controles-code"><?= n360_admin_h($row['codigo']) ?></span><small class="controles-caption"><?= n360_admin_h($row['nombre']) ?></small></td>
                        <td><span class="controles-code controles-code--soft"><?= n360_admin_h($row['tipo_codigo'] ?? 'Sin tipo relacionado') ?></span><small class="controles-caption"><?= n360_admin_h($row['tipo_nombre'] ?? '') ?></small></td>
                        <td class="controles-description"><?= n360_admin_h($row['descripcion']) ?></td>
                    <?php elseif ($view === 'areas'): ?>
                        <td><span class="controles-code"><?= n360_admin_h($row['codigo']) ?></span></td><td><strong><?= n360_admin_h($row['nombre']) ?></strong></td>
                        <td><?= n360_admin_h(n360_controles_module_name($row['modulo_nombre'])) ?></td><td><span class="controles-code controles-code--soft"><?= n360_admin_h($row['tipo_codigo'] ?? 'Sin tipo relacionado') ?></span></td><td class="controles-description"><?= n360_admin_h($row['descripcion']) ?></td>
                    <?php else: ?>
                        <td><span class="controles-code"><?= n360_admin_h($row['codigo']) ?></span></td><td><strong><?= n360_admin_h($row['nombre']) ?></strong></td><td class="controles-description"><?= n360_admin_h($row['descripcion']) ?></td>
                        <td><?php if (!$mapReady): ?><span class="controles-caption">Pendiente de relación</span><?php elseif (empty($modulesByType[(int)$row['id']])): ?><span class="controles-caption">Sin módulos relacionados</span><?php else: ?><div class="controles-uses"><?php foreach ($modulesByType[(int)$row['id']] as $module): ?><span><?= n360_admin_h($module) ?></span><?php endforeach; ?></div><?php endif; ?></td>
                    <?php endif; ?>
                    <td class="controles-products-column"><button type="button" class="controles-search-button" data-control-catalog data-control-scope="<?= $view ?>" data-control-id="<?= (int)$row['id'] ?>" data-control-label="<?= n360_admin_h($row['nombre']) ?>" title="Ver catálogo: <?= n360_admin_h($row['nombre']) ?>" aria-label="Ver catálogo de productos: <?= n360_admin_h($row['nombre']) ?>"><i class="bi bi-search" aria-hidden="true"></i><span>Ver catálogo</span></button></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table>
        </div>
        <p class="controles-panel-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Vista de consulta. Las combinaciones registradas no modifican por sí solas el comportamiento de los módulos.</p>
    </section>
    <aside class="controles-location-note"><i class="bi bi-geo-alt" aria-hidden="true"></i><div><strong>El control pertenece al producto</strong><p>El área y el tipo describen su gestión. Una ubicación física puede contener productos de distintas gestiones.</p></div></aside>
    <?php n360_render_content_separator('bottom'); ?>
</div></main>
<?php n360_render_admin_catalogo_productos(); ?>
<?php n360_render_footer(); n360_admin_render_close(); $conn->close(); ?>
