<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

define('N360_PEAJES', true);
require_once __DIR__ . '/lib/peajes_helpers.php';

if (!pje_can_modulo(4)) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Peajes'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

$defaultRange = pje_default_range($conn);
$filters = pje_current_filters($defaultRange['desde'], $defaultRange['hasta']);
$pageError = '';
$rows = [];
$kpis = [
    'registros' => 0,
    'facturas' => 0,
    'placas' => 0,
    'base' => 0,
    'importe' => 0,
    'igv' => 0,
    'tsd' => 0,
    'total' => 0,
    'detraccion' => 0,
];
$estaciones = [];
$usuarios = [];
$importaciones = [];
$totalRows = 0;

$perPageOptions = [50, 100, 200, 500];
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 100;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

try {
    $estaciones = pje_fetch_options($conn, 'estacion');
    $usuarios = pje_fetch_options($conn, 'usuario');
    $importaciones = pje_fetch_options($conn, 'importacion');
    $kpis = array_merge($kpis, pje_fetch_kpis($conn, $filters));
    $totalRows = pje_count_rows($conn, $filters);
    $maxPage = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $maxPage) {
        $page = $maxPage;
        $offset = ($page - 1) * $perPage;
    }
    $rows = pje_fetch_rows($conn, $filters, $perPage, $offset);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
    $maxPage = 1;
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Peajes | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= pje_h(n360_asset('img/norte360.png')) ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/header_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/sidebar_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/main_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/footer_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/content_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/loader_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/inventario_stock_n360.css')) ?>">
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/peajes_n360.css')) ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Peajes', 'subtitle' => 'Control operativo']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <div class="n360-main__inner n360-stock-page pje-page">
        <section class="stock-hero pje-hero">
            <div class="pje-hero__main">
                <span class="pje-hero__icon" aria-hidden="true">
                    <i class="bi bi-signpost-2-fill"></i>
                </span>
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-receipt-cutoff"></i> Modulo peajes</span>
                    <h1>Peajes - vista general</h1>
                    <p>Lectura filtrada de comprobantes, detracciones, placas y procesos de peaje sin cargar todo el historico a la vez.</p>
                </div>
            </div>
            <div class="stock-hero-actions pje-hero__actions">
                <a class="stock-btn stock-btn--soft" href="<?= pje_h('resumen_placas.php?' . pje_query(['page' => null])) ?>">
                    <i class="bi bi-bus-front-fill"></i> Resumen por placa
                </a>
                <a class="stock-btn stock-btn--soft" href="control_fisico.php">
                    <i class="bi bi-clipboard2-check-fill"></i> Control fisico
                </a>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert stock-alert--danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= pje_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="stock-kpis pje-kpis">
            <article class="stock-kpi stock-kpi--blue">
                <span>Registros</span>
                <strong><?= pje_h(pje_num($kpis['registros'] ?? 0)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Facturas</span>
                <strong><?= pje_h(pje_num($kpis['facturas'] ?? 0)) ?></strong>
            </article>
            <article class="stock-kpi">
                <span>Placas</span>
                <strong><?= pje_h(pje_num($kpis['placas'] ?? 0)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--amber">
                <span>Total</span>
                <strong><?= pje_h(pje_money($kpis['total'] ?? 0)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--red">
                <span>Detraccion</span>
                <strong><?= pje_h(pje_money($kpis['detraccion'] ?? 0)) ?></strong>
            </article>
        </section>

        <form class="stock-filters pje-filters" method="get" action="index.php">
            <label class="stock-field">
                <span>Desde</span>
                <input type="date" name="desde" value="<?= pje_h($filters['desde']) ?>">
            </label>
            <label class="stock-field">
                <span>Hasta</span>
                <input type="date" name="hasta" value="<?= pje_h($filters['hasta']) ?>">
            </label>
            <label class="stock-field">
                <span>Estacion / proceso</span>
                <select name="estacion">
                    <option value="TODOS">Todos</option>
                    <?php foreach ($estaciones as $item): ?>
                        <?php $value = (string)($item['value'] ?? ''); ?>
                        <option value="<?= pje_h($value) ?>" <?= $filters['estacion'] === $value ? 'selected' : '' ?>>
                            <?= pje_h($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Usuario</span>
                <select name="usuario">
                    <option value="TODOS">Todos</option>
                    <?php foreach ($usuarios as $item): ?>
                        <?php $value = (string)($item['value'] ?? ''); ?>
                        <option value="<?= pje_h($value) ?>" <?= $filters['usuario'] === $value ? 'selected' : '' ?>>
                            <?= pje_h($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Importacion</span>
                <select name="importacion">
                    <option value="TODOS">Todos</option>
                    <?php foreach ($importaciones as $item): ?>
                        <?php $value = (string)($item['value'] ?? ''); ?>
                        <option value="<?= pje_h($value) ?>" <?= $filters['importacion'] === $value ? 'selected' : '' ?>>
                            <?= pje_h($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Placa</span>
                <input type="text" name="placa" value="<?= pje_h($filters['placa']) ?>" placeholder="ABC-123">
            </label>
            <label class="stock-field">
                <span>Factura</span>
                <input type="text" name="factura" value="<?= pje_h($filters['factura']) ?>" placeholder="Factura">
            </label>
            <label class="stock-field stock-field--search pje-search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input type="text" name="buscar" value="<?= pje_h($filters['buscar']) ?>" placeholder="Glosa, placa, factura, ruta...">
            </label>
            <label class="stock-field pje-per-page">
                <span>Filas</span>
                <select name="per_page">
                    <?php foreach ($perPageOptions as $option): ?>
                        <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="stock-filter-actions pje-filter-actions">
                <button class="stock-btn stock-btn--primary" type="submit">
                    <i class="bi bi-funnel"></i> Filtrar
                </button>
                <a class="stock-btn stock-btn--soft" href="index.php">
                    <i class="bi bi-x-circle"></i> Limpiar
                </a>
            </div>
        </form>

        <section class="stock-table-card pje-table-card">
            <div class="stock-table-card__head pje-table-card__head">
                <div>
                    <h2>Bitacora de peajes</h2>
                    <p>Mostrando <?= pje_h(pje_num($fromRow)) ?> - <?= pje_h(pje_num($toRow)) ?> de <?= pje_h(pje_num($totalRows)) ?> registros filtrados.</p>
                </div>
                <span class="stock-table-count"><?= pje_h(pje_num($totalRows)) ?> registros</span>
            </div>

            <div class="stock-table-wrap">
                <table class="stock-table pje-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Factura</th>
                        <th>Placa / bus</th>
                        <th>Dueno</th>
                        <th>Proceso</th>
                        <th>Glosa</th>
                        <th>Base</th>
                        <th>IGV</th>
                        <th>Total</th>
                        <th>Detraccion</th>
                        <th>Importacion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="12" class="text-center py-4">No hay peajes para los filtros actuales.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><span class="pje-id">#<?= pje_h($row['clm_pje_id'] ?? '') ?></span></td>
                            <td><?= pje_h(pje_date($row['fecha'] ?? null)) ?></td>
                            <td>
                                <strong><?= pje_h(pje_text($row['factura'] ?? '')) ?></strong>
                                <small><?= pje_h(trim((string)($row['serie'] ?? '') . '-' . (string)($row['numero'] ?? ''), '-')) ?></small>
                            </td>
                            <td>
                                <strong><?= pje_h(pje_vehicle_label($row['bus'] ?? '', $row['placa'] ?? '')) ?></strong>
                                <small><?= pje_h(pje_text($row['tipo_vehiculo'] ?? 'Sin tipo')) ?></small>
                            </td>
                            <td><?= pje_h(pje_text($row['dueno'] ?? 'No coincide')) ?></td>
                            <td><span class="pje-chip"><?= pje_h(pje_text($row['estacion'] ?? '')) ?></span></td>
                            <td class="pje-glosa"><?= pje_h(pje_text($row['glosa'] ?? '')) ?></td>
                            <td><?= pje_h(pje_money($row['base'] ?? 0)) ?></td>
                            <td><?= pje_h(pje_money($row['igv'] ?? 0)) ?></td>
                            <td><strong><?= pje_h(pje_money($row['total'] ?? 0)) ?></strong></td>
                            <td><?= pje_h(pje_money($row['detraccion'] ?? 0)) ?></td>
                            <td>
                                <?= pje_h(pje_date($row['fecha_importacion'] ?? null)) ?>
                                <small><?= pje_h(pje_text($row['cod_importacion'] ?? '')) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pje-pagination">
                <a class="stock-btn stock-btn--soft <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : pje_h('index.php?' . pje_query(['page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i> Anterior
                </a>
                <span>Pagina <?= pje_h(pje_num($page)) ?> de <?= pje_h(pje_num($maxPage)) ?></span>
                <a class="stock-btn stock-btn--soft <?= $page >= $maxPage ? 'disabled' : '' ?>"
                   href="<?= $page >= $maxPage ? '#' : pje_h('index.php?' . pje_query(['page' => $page + 1])) ?>">
                    Siguiente <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </section>
    </div>

    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php n360_render_footer(); ?>
<script src="<?= pje_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= pje_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
</body>
</html>
