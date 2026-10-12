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
require_once __DIR__ . '/lib/peajes_reporte_placas.php';

if (!pje_can_modulo(4)) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Resumen de peajes por placa'));
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
    'total' => 0,
    'detraccion' => 0,
];
$estaciones = [];
$usuarios = [];
$importaciones = [];
$placasNoCoinciden = 0;

try {
    $estaciones = pje_fetch_options($conn, 'estacion');
    $usuarios = pje_fetch_options($conn, 'usuario');
    $importaciones = pje_fetch_options($conn, 'importacion');
    $kpis = array_merge($kpis, pje_fetch_kpis($conn, $filters));
    $rows = pje_fetch_plate_summary($conn, $filters);

    foreach ($rows as $row) {
        if ((int)($row['placa_id'] ?? 0) <= 0) {
            $placasNoCoinciden++;
        }
    }
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}
$reportSuppliers = [];
$reportError = '';
try {
    $reportSuppliers = pje_report_suppliers($conn);
} catch (Throwable $e) {
    error_log('Peajes configuracion de proveedores: ' . $e->getMessage());
    $reportError = 'No se pudo cargar la configuracion del reporte por proveedor.';
}
$canManageReports = pje_is_admin();
if ($canManageReports && empty($_SESSION['pje_report_csrf'])) {
    $_SESSION['pje_report_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen por placa | Norte360</title>
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
    <link rel="stylesheet" href="<?= pje_h(n360_asset('assets/css/peajes_n360.css')) ?>&amp;pje=report-excel-2">
</head>
<body>
<?php n360_render_header(['title' => 'Peajes', 'subtitle' => 'Resumen por placa']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <div class="n360-main__inner n360-stock-page pje-page">
        <section class="stock-hero pje-hero pje-hero--summary">
            <div class="pje-hero__main">
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-signpost-split-fill"></i> Peajes - control por unidad</span>
                    <h1>Resumen por placa</h1>
                </div>
            </div>
            <div class="stock-hero-actions pje-hero__actions">
                <button class="stock-btn stock-btn--primary" type="button" data-pje-summary-pdf>
                    <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
                </button>
                <button class="stock-btn pje-btn--excel" type="button" data-pje-summary-excel>
                    <i class="bi bi-file-earmark-excel"></i> Descargar Excel
                </button>
                <a class="stock-btn stock-btn--soft" href="<?= pje_h('index.php?' . pje_query(['page' => null])) ?>">
                    <i class="bi bi-arrow-left"></i> Vista general
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
                <span>Placas filtradas</span>
                <strong><?= pje_h(pje_num(count($rows))) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--red">
                <span>No coinciden</span>
                <strong><?= pje_h(pje_num($placasNoCoinciden)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--amber">
                <span>Total</span>
                <strong><?= pje_h(pje_money($kpis['total'] ?? 0)) ?></strong>
            </article>
            <article class="stock-kpi">
                <span>Detraccion</span>
                <strong><?= pje_h(pje_money($kpis['detraccion'] ?? 0)) ?></strong>
            </article>
        </section>

        <form class="stock-filters pje-filters pje-filters--summary" method="get" action="resumen_placas.php">
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
            <label class="stock-field stock-field--search pje-search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input type="text" name="buscar" value="<?= pje_h($filters['buscar']) ?>" placeholder="Glosa, factura, ruta...">
            </label>
            <div class="stock-filter-actions pje-filter-actions">
                <button class="stock-btn stock-btn--primary" type="submit">
                    <i class="bi bi-funnel"></i> Filtrar
                </button>
                <a class="stock-btn stock-btn--soft" href="resumen_placas.php">
                    <i class="bi bi-x-circle"></i> Limpiar
                </a>
            </div>
        </form>

        <section class="stock-table-card pje-table-card">
            <div class="stock-table-card__head pje-table-card__head">
                <div>
                    <h2>Facturas y detracciones por placa</h2>
                </div>
                <span class="stock-table-count"><?= pje_h(pje_num(count($rows))) ?> placas</span>
            </div>

            <div class="pje-supplier-report" aria-label="Reporte por proveedor">
                <div class="pje-supplier-report__heading">
                    <i class="bi bi-building" aria-hidden="true"></i>
                    <div>
                        <strong>Reporte por proveedor</strong>
                        <span data-pje-report-count></span>
                    </div>
                </div>
                <label class="stock-field pje-supplier-report__field">
                    <span class="visually-hidden">Proveedor del reporte</span>
                    <select data-pje-report-supplier <?= !$reportSuppliers ? 'disabled' : '' ?>>
                        <?php if (!$reportSuppliers): ?>
                            <option value="">Sin proveedores habilitados</option>
                        <?php endif; ?>
                        <?php foreach ($reportSuppliers as $supplier): ?>
                            <option value="<?= (int)$supplier['id'] ?>">
                                <?= pje_h($supplier['nombre']) ?> - <?= pje_h($supplier['ruc']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="pje-supplier-report__actions">
                    <button class="stock-btn stock-btn--soft" type="button" data-pje-supplier-pdf <?= !$reportSuppliers ? 'disabled' : '' ?>>
                        <i class="bi bi-file-earmark-pdf"></i> PDF proveedor
                    </button>
                    <button class="stock-btn pje-btn--excel" type="button" data-pje-supplier-excel <?= !$reportSuppliers ? 'disabled' : '' ?>>
                        <i class="bi bi-file-earmark-excel"></i> Excel proveedor
                    </button>
                    <?php if ($canManageReports): ?>
                        <button class="stock-btn stock-btn--soft pje-report-manage-btn" type="button" data-pje-report-manage <?= $reportError !== '' ? 'disabled' : '' ?>
                            title="Gestionar placas del proveedor" aria-label="Gestionar placas del proveedor" data-bs-toggle="tooltip">
                            <i class="bi bi-sliders" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="pje-report-notice <?= $reportError === '' ? 'd-none' : '' ?>" data-pje-report-notice role="status">
                    <?= pje_h($reportError) ?>
                </div>
            </div>

            <div class="stock-table-wrap">
                <table class="stock-table pje-table pje-table--summary">
                    <thead>
                    <tr>
                        <th>Estado</th>
                        <th>Placa / bus</th>
                        <th>Tipo</th>
                        <th>Dueno</th>
                        <th>Registros</th>
                        <th>Facturas</th>
                        <th>Total</th>
                        <th>Detraccion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">No hay resumen para los filtros actuales.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $coincide = (int)($row['placa_id'] ?? 0) > 0; ?>
                        <tr class="<?= $coincide ? '' : 'pje-row-warning' ?>">
                            <td>
                                <span class="pje-status <?= $coincide ? 'pje-status--ok' : 'pje-status--warn' ?>">
                                    <?= $coincide ? 'Coincide' : 'No coincide' ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= pje_h(pje_vehicle_label($row['bus'] ?? '', $row['placa'] ?? '')) ?></strong>
                                <small><?= pje_h(pje_text($row['placa'] ?? '')) ?></small>
                            </td>
                            <td><?= pje_h(pje_text($row['tipo_vehiculo'] ?? 'Sin tipo')) ?></td>
                            <td><?= pje_h(pje_text($row['dueno'] ?? 'No identificado')) ?></td>
                            <td><strong><?= pje_h(pje_num($row['registros'] ?? 0)) ?></strong></td>
                            <td><?= pje_h(pje_num($row['facturas'] ?? 0)) ?></td>
                            <td><strong><?= pje_h(pje_money($row['total'] ?? 0)) ?></strong></td>
                            <td><?= pje_h(pje_money($row['detraccion'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php n360_render_footer(); ?>
<?php if ($canManageReports): ?>
<div class="modal fade pje-report-modal" id="pje-report-modal" tabindex="-1" aria-labelledby="pje-report-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header pje-report-modal__head">
                <div class="pje-report-modal__title">
                    <i class="bi bi-bus-front" aria-hidden="true"></i>
                    <div>
                        <span>Peajes / Proveedores</span>
                        <h2 class="modal-title" id="pje-report-modal-title">Placas del reporte</h2>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form data-pje-report-form>
                <div class="modal-body">
                    <fieldset data-pje-report-fields>
                        <div class="pje-report-provider-settings">
                            <label class="stock-field">
                                <span>Proveedor</span>
                                <select data-pje-manage-supplier required></select>
                            </label>
                            <div class="form-check form-switch pje-report-enabled">
                                <input class="form-check-input" type="checkbox" role="switch" id="pje-report-enabled" data-pje-report-enabled>
                                <label class="form-check-label" for="pje-report-enabled">Reporte habilitado</label>
                            </div>
                        </div>
                        <div class="pje-report-plate-toolbar">
                            <label class="stock-field pje-report-search">
                                <span class="visually-hidden">Buscar placa o unidad</span>
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" data-pje-plate-search placeholder="Buscar placa o unidad">
                            </label>
                            <label class="pje-report-selected-only">
                                <input type="checkbox" data-pje-selected-only> Seleccionadas
                            </label>
                            <strong class="pje-report-selection-count" data-pje-selected-count>0 placas</strong>
                        </div>
                        <div class="pje-report-plates-wrap">
                            <table class="pje-report-plates-table">
                                <thead><tr>
                                    <th scope="col"><span class="visually-hidden">Seleccion</span><i class="bi bi-check2-square" aria-hidden="true"></i></th>
                                    <th scope="col">Placa</th>
                                    <th scope="col">Unidad</th>
                                    <th scope="col">Estado</th>
                                </tr></thead>
                                <tbody data-pje-report-plates></tbody>
                            </table>
                        </div>
                    </fieldset>
                    <div class="pje-report-notice d-none" data-pje-manage-notice role="status"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="stock-btn stock-btn--soft" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="submit" class="stock-btn stock-btn--primary" data-pje-report-save disabled>
                        <i class="bi bi-floppy"></i> Guardar seleccion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<script>
window.N360_PEAJES_RESUMEN = {
    rows: <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    kpis: <?= json_encode($kpis, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    filters: <?= json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    pdf: {
        title: 'RESUMEN DE PEAJES POR PLACA',
        secondTitle: 'Facturas y detraccion por unidad',
        docCode: 'PEA-RPT-RES-PLACAS',
        userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        logoLeft: '<?= pje_h(n360_base_url('img/icon.png')) ?>',
        logoRight: '<?= pje_h(n360_base_url('img/norte360_black.png')) ?>',
        useCover: false,
        fileName: 'peajes_resumen_placas_<?= date('Ymd_His') ?>.pdf'
    }
};
window.N360_PEAJES_PROVEEDOR = {
    endpoint: 'reporte_placas.php',
    proveedores: <?= json_encode($reportSuppliers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    csrf: <?= json_encode($canManageReports ? $_SESSION['pje_report_csrf'] : '') ?>
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="<?= pje_h(n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js')) ?>"></script>
<script src="<?= pje_h(n360_asset('assets/js/formatos/reportes/peajes_resumen_placas_pdf.js')) ?>&amp;pje=pdf-solid-3"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="<?= pje_h(n360_asset('assets/js/formatos/reportes/peajes_resumen_placas_excel.js')) ?>&amp;pje=report-excel-2"></script>
<script src="<?= pje_h(n360_asset('assets/js/peajes_reporte_placas.js')) ?>&amp;pje=report-excel-2"></script>
<script src="<?= pje_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= pje_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
</body>
</html>
