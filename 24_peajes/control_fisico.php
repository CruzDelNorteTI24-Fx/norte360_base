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
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Control fisico de peajes'));
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

$defaultRange = pje_default_control_range($conn);
$filters = pje_control_filters($defaultRange['desde'], $defaultRange['hasta']);
$pageError = '';
$nombres = [];
$estados = [];
$controlSummary = [
    'available' => false,
    'message' => '',
    'totals' => ['registros' => 0, 'cantidad' => 0, 'al_dia' => 0, 'atrasados' => 0, 'actualiza' => 0],
    'by_name' => [],
    'groups' => [],
];

try {
    $nombres = pje_fetch_control_options($conn, 'nombre');
    $estados = pje_fetch_control_options($conn, 'estado');
    $controlSummary = pje_fetch_control_summary($conn, $filters);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$totals = $controlSummary['totals'] ?? [];
$byName = $controlSummary['by_name'] ?? [];
$groups = $controlSummary['groups'] ?? [];
$isAvailable = (bool)($controlSummary['available'] ?? false);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control fisico | Norte360</title>
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
<?php n360_render_header(['title' => 'Peajes', 'subtitle' => 'Control fisico']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <div class="n360-main__inner n360-stock-page pje-page">
        <section class="stock-hero pje-hero pje-hero--control">
            <div class="pje-hero__main">
                <span class="pje-hero__icon" aria-hidden="true">
                    <i class="bi bi-clipboard2-check-fill"></i>
                </span>
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-signpost-split-fill"></i> Peajes - control fisico</span>
                    <h1>Control por grupos</h1>
                    <p>Visualiza los grupos cargados en tb_control, sus estados, cantidades, codigos y concesionarias.</p>
                </div>
            </div>
            <div class="stock-hero-actions pje-hero__actions">
                <a class="stock-btn stock-btn--soft" href="index.php">
                    <i class="bi bi-arrow-left"></i> Vista general
                </a>
                <a class="stock-btn stock-btn--soft" href="resumen_placas.php">
                    <i class="bi bi-bus-front-fill"></i> Resumen por placa
                </a>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert stock-alert--danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= pje_h($pageError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$isAvailable): ?>
            <section class="stock-table-card pje-control-card">
                <div class="pje-control-empty">
                    <i class="bi bi-info-circle-fill"></i>
                    <?= pje_h($controlSummary['message'] ?? 'No se pudo leer tb_control.') ?>
                </div>
            </section>
        <?php else: ?>
            <section class="stock-kpis pje-kpis pje-kpis--control">
                <article class="stock-kpi stock-kpi--blue">
                    <span>Registros</span>
                    <strong><?= pje_h(pje_num($totals['registros'] ?? 0)) ?></strong>
                </article>
                <article class="stock-kpi">
                    <span>Cantidad total</span>
                    <strong><?= pje_h(pje_num($totals['cantidad'] ?? 0)) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--green">
                    <span>Al dia</span>
                    <strong><?= pje_h(pje_num($totals['al_dia'] ?? 0)) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--amber">
                    <span>Atrasados</span>
                    <strong><?= pje_h(pje_num($totals['atrasados'] ?? 0)) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--blue">
                    <span>Actualiza</span>
                    <strong><?= pje_h(pje_num($totals['actualiza'] ?? 0)) ?></strong>
                </article>
            </section>

            <form class="stock-filters pje-filters pje-filters--control" method="get" action="control_fisico.php">
                <label class="stock-field">
                    <span>Desde</span>
                    <input type="date" name="desde" value="<?= pje_h($filters['desde']) ?>">
                </label>
                <label class="stock-field">
                    <span>Hasta</span>
                    <input type="date" name="hasta" value="<?= pje_h($filters['hasta']) ?>">
                </label>
                <label class="stock-field">
                    <span>Concesionaria</span>
                    <select name="nombre">
                        <option value="TODOS">Todas</option>
                        <?php foreach ($nombres as $item): ?>
                            <?php $value = (string)($item['value'] ?? ''); ?>
                            <option value="<?= pje_h($value) ?>" <?= $filters['nombre'] === $value ? 'selected' : '' ?>>
                                <?= pje_h($value) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="stock-field">
                    <span>Estado</span>
                    <select name="estado">
                        <option value="TODOS">Todos</option>
                        <?php foreach ($estados as $item): ?>
                            <?php $value = (string)($item['value'] ?? ''); ?>
                            <option value="<?= pje_h($value) ?>" <?= $filters['estado'] === $value ? 'selected' : '' ?>>
                                <?= pje_h($value) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="stock-field stock-field--search pje-search">
                    <span>Buscar</span>
                    <i class="bi bi-search"></i>
                    <input type="text" name="buscar" value="<?= pje_h($filters['buscar']) ?>" placeholder="Grupo, codigo, placa, detalle...">
                </label>
                <div class="stock-filter-actions pje-filter-actions">
                    <button class="stock-btn stock-btn--primary" type="submit">
                        <i class="bi bi-funnel"></i> Filtrar
                    </button>
                    <a class="stock-btn stock-btn--soft" href="control_fisico.php">
                        <i class="bi bi-x-circle"></i> Limpiar
                    </a>
                </div>
            </form>

            <section class="stock-table-card pje-control-card">
                <div class="pje-table-card__head">
                    <div>
                        <h2>Concesionarias</h2>
                        <p>Resumen de cantidades por nombre y estado dentro del rango filtrado.</p>
                    </div>
                    <span class="stock-table-count"><?= pje_h(pje_num(count($byName))) ?> grupos visibles</span>
                </div>

                <?php if (!$byName): ?>
                    <div class="pje-control-empty">
                        <i class="bi bi-search"></i>
                        No hay concesionarias para los filtros actuales.
                    </div>
                <?php else: ?>
                    <div class="pje-control-name-grid">
                        <?php foreach ($byName as $item): ?>
                            <article class="pje-control-name-card">
                                <div class="pje-control-name-card__head">
                                    <strong><?= pje_h($item['nombre'] ?? 'SIN NOMBRE') ?></strong>
                                    <span><?= pje_h(pje_num($item['registros'] ?? 0)) ?> reg.</span>
                                </div>
                                <div class="pje-control-name-card__metrics">
                                    <span><small>Total</small><b><?= pje_h(pje_num($item['cantidad'] ?? 0)) ?></b></span>
                                    <span><small>Al dia</small><b><?= pje_h(pje_num($item['al_dia'] ?? 0)) ?></b></span>
                                    <span><small>Atras.</small><b><?= pje_h(pje_num($item['atrasados'] ?? 0)) ?></b></span>
                                    <span><small>Cod.</small><b><?= pje_h(pje_num($item['codigos'] ?? 0)) ?></b></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="stock-table-card pje-table-card">
                <div class="pje-table-card__head">
                    <div>
                        <h2>Grupos de control</h2>
                        <p>Lectura agrupada por grupo, concesionaria y estado. Se muestran hasta 160 agrupaciones por filtro.</p>
                    </div>
                    <span class="stock-table-count"><?= pje_h(pje_num(count($groups))) ?> filas</span>
                </div>

                <div class="stock-table-wrap">
                    <table class="stock-table pje-table pje-control-table--groups">
                        <thead>
                        <tr>
                            <th>Grupo / codigos</th>
                            <th>Concesionaria</th>
                            <th>Estado</th>
                            <th>Cantidad</th>
                            <th>Placas</th>
                            <th>Rango fechas</th>
                            <th>Detalle</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$groups): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">No hay grupos para los filtros actuales.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($groups as $row): ?>
                            <?php $stateClass = pje_control_state_class($row['estado'] ?? ''); ?>
                            <tr>
                                <td>
                                    <strong><?= pje_h(pje_text($row['grupo'] ?? '')) ?></strong>
                                    <small><?= pje_h(pje_text($row['codigos'] ?? 'Sin codigos')) ?></small>
                                </td>
                                <td><?= pje_h(pje_text($row['nombre'] ?? '')) ?></td>
                                <td>
                                    <span class="pje-control-pill pje-control-pill--<?= pje_h($stateClass) ?>">
                                        <?= pje_h(pje_text($row['estado'] ?? '')) ?>
                                    </span>
                                </td>
                                <td><strong><?= pje_h(pje_num($row['cantidad'] ?? 0)) ?></strong></td>
                                <td><?= pje_h(pje_num($row['placas'] ?? 0)) ?></td>
                                <td>
                                    <?= pje_h(pje_date($row['primera_fecha'] ?? null)) ?>
                                    <small>hasta <?= pje_h(pje_date($row['ultima_fecha'] ?? null)) ?></small>
                                </td>
                                <td><?= pje_h(pje_text($row['detalle'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php n360_render_footer(); ?>
<script src="<?= pje_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= pje_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
</body>
</html>
