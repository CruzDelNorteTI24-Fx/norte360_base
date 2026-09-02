<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

if (!n360_puede_modulo(10) || (!n360_puede_vista('f-consalbus') && !n360_puede_vista('f-proghist'))) {
    header('Location: ../login/none_permisos.php');
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

mysqli_report(MYSQLI_REPORT_OFF);

function fav_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fav_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fav_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }
    fav_bind($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error ?: 'No se pudo ejecutar la consulta.');
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function fav_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('
        SELECT COUNT(*) AS total
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function fav_column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare('
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function fav_valid_date($value, string $fallback): string {
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

function fav_date_label(?string $value, string $format = 'd/m/Y'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : $value;
}

function fav_time_label(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('H:i', $time) : substr($value, 0, 5);
}

function fav_money_number($value): float {
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace(['S/', ' ', ','], ['', '', ''], $value);
    return is_numeric($value) ? (float)$value : 0.0;
}

function fav_money_label($value): string {
    return 'S/ ' . number_format(fav_money_number($value), 2, '.', ',');
}

function fav_normal_state($value, string $fallback = 'PENDIENTE'): string {
    $value = strtoupper(trim((string)$value));
    return $value !== '' ? $value : $fallback;
}

function fav_revision_class(string $estado): string {
    $estado = fav_normal_state($estado);
    if ($estado === 'VALIDADO') return 'fav-pill--ok';
    if ($estado === 'OBSERVADO') return 'fav-pill--warn';
    if ($estado === 'CORREGIDO') return 'fav-pill--info';
    if ($estado === 'ANULADO') return 'fav-pill--danger';
    if ($estado === 'MANUAL') return 'fav-pill--manual';
    if ($estado === 'TRANSBORDADO' || $estado === 'TRANSBORDO') return 'fav-pill--route';
    return 'fav-pill--pending';
}

function fav_direction_class(string $estado): string {
    $estado = fav_normal_state($estado);
    if ($estado === 'IDA') return 'fav-pill--ida';
    if ($estado === 'RETORNO') return 'fav-pill--retorno';
    return 'fav-pill--pending';
}

function fav_pay_label($value): string {
    $estado = fav_normal_state($value, '');
    return $estado === 'PAGADO' ? 'OK' : ($estado !== '' ? $estado : '-');
}

function fav_driver_lines(?string $value): array {
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/\s+\|\s+/', $value) ?: [];
    $lines = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $lines[] = $part;
        }
    }
    return $lines;
}

function fav_fetch_active_units(mysqli $conn): array {
    if (!fav_table_exists($conn, 'tb_placas')) {
        return [];
    }

    $hasBus = fav_column_exists($conn, 'tb_placas', 'clm_placas_BUS');
    $hasPlaca = fav_column_exists($conn, 'tb_placas', 'clm_placas_PLACA');
    $busSelect = $hasBus ? "IFNULL(clm_placas_BUS, '') AS bus" : "'' AS bus";
    $placaSelect = $hasPlaca ? "IFNULL(clm_placas_PLACA, '') AS placa" : "'' AS placa";
    $servicioSelect = fav_column_exists($conn, 'tb_placas', 'clm_placas_servicio') ? "IFNULL(clm_placas_servicio, '') AS servicio" : "'' AS servicio";
    $estadoWhere = fav_column_exists($conn, 'tb_placas', 'clm_placas_ESTADO')
        ? "WHERE UPPER(TRIM(IFNULL(clm_placas_ESTADO, 'ACTIVO'))) NOT IN ('INACTIVO', 'INACTIVA', 'BAJA', 'RETIRADO', 'RETIRADA', '0')"
        : '';
    $order = ['clm_placas_id ASC'];
    if ($hasBus) {
        array_unshift(
            $order,
            "CASE WHEN CAST(COALESCE(clm_placas_BUS, '') AS CHAR) REGEXP '^[0-9]+$' THEN CAST(clm_placas_BUS AS UNSIGNED) ELSE 999999 END ASC",
            'clm_placas_BUS ASC'
        );
    }
    if ($hasPlaca) {
        $order[] = 'clm_placas_PLACA ASC';
    }

    $rows = fav_fetch_all($conn, "
        SELECT clm_placas_id AS id,
               {$busSelect},
               {$placaSelect},
               {$servicioSelect}
        FROM tb_placas
        {$estadoWhere}
        ORDER BY " . implode(', ', $order) . "
    ");

    $units = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $bus = trim((string)($row['bus'] ?? ''));
        $placa = trim((string)($row['placa'] ?? ''));
        if ($bus === '' && $placa === '') {
            continue;
        }
        $row['label'] = trim(($bus !== '' ? $bus : 'Unidad') . ($placa !== '' ? ' (' . $placa . ')' : ''));
        $units[$id] = $row;
    }
    return $units;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$fechaInicio = fav_valid_date($_GET['fecha_inicio'] ?? $monthStart, $monthStart);
$fechaFin = fav_valid_date($_GET['fecha_fin'] ?? $today, $today);
if ($fechaInicio > $fechaFin) {
    [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
}

$selectedUnitIds = $_GET['buses'] ?? [];
if (!is_array($selectedUnitIds)) {
    $selectedUnitIds = [$selectedUnitIds];
}
$selectedUnitIds = array_values(array_unique(array_filter(array_map('intval', $selectedUnitIds), static fn($id) => $id > 0)));

$pageError = '';
$tableReady = isset($conn) && $conn instanceof mysqli && fav_table_exists($conn, 'tb_progbuses_salida_consolidado');
$units = [];
$rows = [];

try {
    $units = fav_fetch_active_units($conn);

    if (!$tableReady) {
        throw new RuntimeException('La tabla tb_progbuses_salida_consolidado todavia no esta disponible.');
    }

    $where = ['clm_salprog_fecha_operativa BETWEEN ? AND ?'];
    $types = 'ss';
    $params = [$fechaInicio, $fechaFin];

    if ($selectedUnitIds) {
        $placeholders = implode(',', array_fill(0, count($selectedUnitIds), '?'));
        $where[] = "clm_salprog_idplaca IN ({$placeholders})";
        $types .= str_repeat('i', count($selectedUnitIds));
        array_push($params, ...$selectedUnitIds);
    }

    $rows = fav_fetch_all($conn, '
        SELECT *
        FROM tb_progbuses_salida_consolidado
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY clm_salprog_fecha_operativa ASC, clm_salprog_hora_orden ASC, clm_salprog_horasalida ASC, clm_salprog_bus ASC, clm_salprog_id ASC
    ', $types, $params);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$selectedLabel = $selectedUnitIds ? count($selectedUnitIds) . ' unidad(es)' : 'Todos los buses';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flota | Analisis de viajes</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/flota_analisis_viajes_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Flota', 'subtitle' => 'Analisis de viajes']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content fav-page" data-fav-page>
        <section class="fav-hero">
            <div class="fav-hero__copy">
                <span class="fav-eyebrow"><i class="bi bi-clipboard-data-fill"></i> Flota - analisis operativo</span>
                <h1>Analisis de viajes</h1>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="fav-alert fav-alert--danger">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <?= fav_h($pageError) ?>
            </div>
        <?php endif; ?>

        <form class="fav-query fav-panel" method="get" autocomplete="off">
            <div class="fav-query-head">
                <div>
                    <span><i class="bi bi-database-check"></i> Busqueda SQL</span>
                    <strong>Consulta base</strong>
                </div>
                <div class="fav-actions">
                    <button type="button" class="fav-btn fav-btn--ghost" data-fav-bus-clear>
                        <i class="bi bi-check2-all"></i> Todos los buses
                    </button>
                    <button type="submit" class="fav-btn fav-btn--primary">
                        <i class="bi bi-search"></i> Buscar viajes
                    </button>
                </div>
            </div>

            <div class="fav-query-grid">
                <label class="fav-field">
                    <span>Fecha operativa inicio</span>
                    <input type="date" name="fecha_inicio" value="<?= fav_h($fechaInicio) ?>" required>
                </label>
                <label class="fav-field">
                    <span>Fecha operativa fin</span>
                    <input type="date" name="fecha_fin" value="<?= fav_h($fechaFin) ?>" required>
                </label>
                <div class="fav-bus-picker">
                    <button type="button" class="fav-bus-trigger" data-fav-bus-toggle aria-expanded="false">
                        <div>
                            <span>Buses</span>
                            <strong data-fav-bus-count><?= fav_h($selectedLabel) ?></strong>
                        </div>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="fav-bus-box" data-fav-bus-panel>
                        <input type="search" class="fav-bus-search" placeholder="Filtrar bus o placa..." data-fav-bus-search>
                        <div class="fav-bus-list" data-fav-bus-list>
                            <?php if (!$units): ?>
                                <p class="fav-empty-mini">No se encontraron buses activos.</p>
                            <?php endif; ?>
                            <?php foreach ($units as $unitId => $unit): ?>
                                <?php $checked = in_array((int)$unitId, $selectedUnitIds, true); ?>
                                <label class="fav-bus-option" data-fav-bus-option>
                                    <input type="checkbox" name="buses[]" value="<?= (int)$unitId ?>" <?= $checked ? 'checked' : '' ?>>
                                    <span>
                                        <strong><?= fav_h($unit['bus'] !== '' ? $unit['bus'] : 'Unidad') ?></strong>
                                        <small><?= fav_h(trim(($unit['placa'] ?? '') . (($unit['servicio'] ?? '') !== '' ? ' - ' . $unit['servicio'] : ''))) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <section class="fav-kpis">
            <article class="fav-kpi fav-kpi--main"><i class="bi bi-signpost-split"></i><span>Viajes visibles</span><strong data-fav-kpi="viajes"><?= number_format(count($rows)) ?></strong></article>
            <article class="fav-kpi"><i class="bi bi-bus-front"></i><span>Unidades</span><strong data-fav-kpi="unidades">0</strong></article>
            <article class="fav-kpi"><i class="bi bi-shield-check"></i><span>Con hoja ruta</span><strong data-fav-kpi="hojas">0</strong></article>
            <article class="fav-kpi"><i class="bi bi-arrow-return-left"></i><span>Retornos</span><strong data-fav-kpi="retornos">0</strong></article>
            <article class="fav-kpi fav-kpi--danger"><i class="bi bi-slash-circle"></i><span>Anulados</span><strong data-fav-kpi="anulados">0</strong></article>
            <article class="fav-kpi fav-kpi--money"><i class="bi bi-cash-stack"></i><span>Total viaje</span><strong data-fav-kpi="total">S/ 0.00</strong></article>
            <article class="fav-kpi fav-kpi--money"><i class="bi bi-person-check"></i><span>Pago cond.</span><strong data-fav-kpi="conductores">S/ 0.00</strong></article>
            <article class="fav-kpi fav-kpi--diff"><i class="bi bi-calculator"></i><span>Diferencia</span><strong data-fav-kpi="diferencia">S/ 0.00</strong></article>
        </section>

        <section class="fav-screen fav-panel" data-fav-filter-panel>
            <div class="fav-screen-head">
                <div>
                    <span><i class="bi bi-funnel"></i> Filtros en pantalla</span>
                    <strong>Sobre <?= number_format(count($rows)) ?> viaje(s) cargados</strong>
                </div>
                <div class="fav-actions">
                    <button type="button" class="fav-btn fav-btn--soft" data-fav-toggle-filters aria-expanded="true">
                        <i class="bi bi-sliders"></i> <span data-fav-toggle-filters-label>Ocultar filtros</span>
                    </button>
                    <button type="button" class="fav-btn fav-btn--soft" data-fav-clear-filters>
                        <i class="bi bi-x-circle"></i> Limpiar filtros
                    </button>
                </div>
            </div>
            <div class="fav-filter-grid" data-fav-filter-body>
                <label class="fav-field fav-field--wide">
                    <span>Buscar en pantalla</span>
                    <input type="search" data-fav-filter-text placeholder="Bus, ruta, conductor, hoja de ruta, comentario...">
                </label>
                <label class="fav-field">
                    <span>Revision</span>
                    <select data-fav-filter="estado"><option value="">Todos</option></select>
                </label>
                <label class="fav-field">
                    <span>Ida / retorno</span>
                    <select data-fav-filter="ida"><option value="">Todos</option></select>
                </label>
                <label class="fav-field">
                    <span>Origen</span>
                    <select data-fav-filter="origen"><option value="">Todos</option></select>
                </label>
                <label class="fav-field">
                    <span>Destino</span>
                    <select data-fav-filter="destino"><option value="">Todos</option></select>
                </label>
                <label class="fav-field">
                    <span>Conductor</span>
                    <select data-fav-filter="conductor"><option value="">Todos</option></select>
                </label>
                <label class="fav-field">
                    <span>Hoja de ruta</span>
                    <select data-fav-filter="hoja">
                        <option value="">Todos</option>
                        <option value="con">Con hoja</option>
                        <option value="sin">Sin hoja</option>
                    </select>
                </label>
                <label class="fav-field">
                    <span>Importes</span>
                    <select data-fav-filter="balance">
                        <option value="">Todos</option>
                        <option value="ok">Cuadrados</option>
                        <option value="diff">Con diferencia</option>
                    </select>
                </label>
                <label class="fav-field">
                    <span>Pagos</span>
                    <select data-fav-filter="pagos">
                        <option value="">Todos</option>
                        <option value="ok">Conductores OK</option>
                        <option value="pendiente">Con pendiente</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="fav-visual fav-panel">
            <div class="fav-visual-head">
                <div>
                    <span><i class="bi bi-graph-up-arrow"></i> Tablero visual</span>
                    <strong>Lectura grafica</strong>
                </div>
                <div class="fav-visual-live">
                    <i class="bi bi-lightning-charge-fill"></i>
                    <span data-fav-visual-total><?= number_format(count($rows)) ?></span>
                    <small>visible(s)</small>
                </div>
            </div>

            <div class="fav-visual-grid">
                <article class="fav-chart-card fav-chart-card--wide">
                    <div class="fav-chart-title">
                        <span>Tendencia diaria</span>
                        <strong>Viajes por fecha operativa</strong>
                    </div>
                    <div class="fav-canvas-wrap">
                        <canvas data-fav-chart="daily"></canvas>
                    </div>
                </article>

                <article class="fav-chart-card">
                    <div class="fav-chart-title">
                        <span>Revision</span>
                        <strong>Estados</strong>
                    </div>
                    <div class="fav-canvas-wrap fav-canvas-wrap--donut">
                        <canvas data-fav-chart="states"></canvas>
                    </div>
                    <div class="fav-chart-legend" data-fav-legend="states"></div>
                </article>

                <article class="fav-chart-card">
                    <div class="fav-chart-title">
                        <span>Viaje</span>
                        <strong>Ida / retorno</strong>
                    </div>
                    <div class="fav-canvas-wrap fav-canvas-wrap--donut">
                        <canvas data-fav-chart="directions"></canvas>
                    </div>
                    <div class="fav-chart-legend" data-fav-legend="directions"></div>
                </article>

                <article class="fav-chart-card fav-chart-card--wide">
                    <div class="fav-chart-title">
                        <span>Movimiento</span>
                        <strong>Rutas principales</strong>
                    </div>
                    <div class="fav-canvas-wrap fav-canvas-wrap--bars">
                        <canvas data-fav-chart="routes"></canvas>
                    </div>
                </article>

                <article class="fav-chart-card">
                    <div class="fav-chart-title">
                        <span>Unidades</span>
                        <strong>Mayor actividad</strong>
                    </div>
                    <div class="fav-top-list" data-fav-top-units></div>
                </article>

                <article class="fav-chart-card">
                    <div class="fav-chart-title">
                        <span>Importes</span>
                        <strong>Balance visible</strong>
                    </div>
                    <div class="fav-money-bars" data-fav-money-bars></div>
                </article>
            </div>
        </section>

        <section class="fav-table-card">
            <div class="fav-table-head">
                <div>
                    <span>Viajes cargados</span>
                    <strong data-fav-visible-label><?= number_format(count($rows)) ?> visible(s)</strong>
                </div>
                <small>Fecha operativa <?= fav_h(fav_date_label($fechaInicio) . ' - ' . fav_date_label($fechaFin)) ?></small>
            </div>
            <div class="fav-table-wrap">
                <table class="fav-table" data-fav-table>
                    <thead>
                        <tr>
                            <th>Fecha / hora</th>
                            <th>Unidad</th>
                            <th>Ida/retorno</th>
                            <th>Ruta</th>
                            <th>Conductores</th>
                            <th>Revision</th>
                            <th>Hoja ruta</th>
                            <th>Importes</th>
                            <th>Comentarios</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr class="fav-empty-row">
                            <td colspan="9">No hay viajes para los criterios seleccionados.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $id = (int)($row['clm_salprog_id'] ?? 0);
                            $fecha = (string)($row['clm_salprog_fecha_operativa'] ?? '');
                            $hora = (string)($row['clm_salprog_horasalida'] ?? '');
                            $bus = trim((string)($row['clm_salprog_bus'] ?? ''));
                            $placa = trim((string)($row['clm_salprog_placa'] ?? ''));
                            $servicio = trim((string)($row['clm_salprog_servicio'] ?? ''));
                            $origen = trim((string)($row['clm_salprog_origen'] ?? ''));
                            $destino = trim((string)($row['clm_salprog_destino'] ?? ''));
                            $ruta = trim((string)($row['clm_salprog_ruta_texto'] ?? ''));
                            $revision = fav_normal_state($row['clm_salprog_revision_estado'] ?? 'PENDIENTE');
                            $idaVuelta = fav_normal_state($row['clm_salprog_estadoidavuelta'] ?? 'PENDIENTE');
                            if (!in_array($idaVuelta, ['PENDIENTE', 'IDA', 'RETORNO'], true)) {
                                $idaVuelta = 'PENDIENTE';
                            }
                            $hojaRuta = trim((string)($row['clm_salprog_hojaruta'] ?? ''));
                            $conductores = fav_driver_lines($row['clm_salprog_conductores_texto'] ?? '');
                            $cond1Estado = fav_normal_state($row['clm_salprog_cond1_estado'] ?? '', '');
                            $cond2Estado = fav_normal_state($row['clm_salprog_cond2_estado'] ?? '', '');
                            $totalViaje = fav_money_number($row['clm_salprog_imtotaldelviaje'] ?? '');
                            $totalCond1 = fav_money_number($row['clm_salprog_imtotalcond1'] ?? '');
                            $totalCond2 = fav_money_number($row['clm_salprog_imtotalcond2'] ?? '');
                            $totalConductores = $totalCond1 + $totalCond2;
                            $diferencia = round($totalViaje - $totalConductores, 4);
                            $hasDifference = abs($diferencia) > 0.009;
                            $comentarioHorario = trim((string)($row['clm_salprog_comentario_horario'] ?? ''));
                            $comentarioRevision = trim((string)($row['clm_salprog_comentario_revision'] ?? ''));
                            $correccion = trim((string)($row['clm_salprog_correccion'] ?? ''));
                            $comentarioControl = trim((string)($row['clm_salprog_comentariocontroldelviaje'] ?? ''));
                            $comentarios = array_filter([$comentarioHorario, $comentarioRevision, $correccion, $comentarioControl], static fn($item) => trim((string)$item) !== '');
                            $searchParts = [
                                $id,
                                $fecha,
                                fav_time_label($hora),
                                $bus,
                                $placa,
                                $servicio,
                                $origen,
                                $destino,
                                $ruta,
                                $revision,
                                $idaVuelta,
                                $hojaRuta,
                                implode(' ', $conductores),
                                implode(' ', $comentarios),
                            ];
                            $driverFilter = implode('|', $conductores);
                        ?>
                        <tr
                            data-fav-row
                            data-fav-id="<?= $id ?>"
                            data-fav-fecha="<?= fav_h($fecha) ?>"
                            data-fav-hora="<?= fav_h(fav_time_label($hora)) ?>"
                            data-fav-bus-id="<?= (int)($row['clm_salprog_idplaca'] ?? 0) ?>"
                            data-fav-bus="<?= fav_h($bus) ?>"
                            data-fav-placa="<?= fav_h($placa) ?>"
                            data-fav-origen="<?= fav_h($origen) ?>"
                            data-fav-destino="<?= fav_h($destino) ?>"
                            data-fav-ruta-label="<?= fav_h(($origen !== '' ? $origen : '-') . ' -> ' . ($destino !== '' ? $destino : '-')) ?>"
                            data-fav-estado="<?= fav_h($revision) ?>"
                            data-fav-ida="<?= fav_h($idaVuelta) ?>"
                            data-fav-conductores="<?= fav_h($driverFilter) ?>"
                            data-fav-hoja="<?= $hojaRuta !== '' ? '1' : '0' ?>"
                            data-fav-total="<?= fav_h((string)$totalViaje) ?>"
                            data-fav-cond-total="<?= fav_h((string)$totalConductores) ?>"
                            data-fav-diferencia="<?= fav_h((string)$diferencia) ?>"
                            data-fav-cond1-estado="<?= fav_h($cond1Estado) ?>"
                            data-fav-cond2-estado="<?= fav_h($cond2Estado) ?>"
                            data-fav-search="<?= fav_h(implode(' ', $searchParts)) ?>"
                        >
                            <td data-label="Fecha / hora">
                                <strong><?= fav_h(fav_date_label($fecha)) ?></strong>
                                <small><?= fav_h(fav_time_label($hora)) ?> - ID <?= $id ?></small>
                            </td>
                            <td data-label="Unidad">
                                <strong><?= fav_h($bus !== '' ? $bus : 'Sin bus') ?></strong>
                                <small><?= fav_h(trim(($placa !== '' ? $placa : 'Sin placa') . ($servicio !== '' ? ' - ' . $servicio : ''))) ?></small>
                            </td>
                            <td data-label="Ida/retorno">
                                <span class="fav-pill <?= fav_h(fav_direction_class($idaVuelta)) ?>"><?= fav_h($idaVuelta) ?></span>
                            </td>
                            <td data-label="Ruta" class="fav-route-cell">
                                <strong><?= fav_h(($origen !== '' ? $origen : '-') . ' -> ' . ($destino !== '' ? $destino : '-')) ?></strong>
                                <small><?= fav_h($ruta !== '' ? $ruta : 'Sin ruta intermedia') ?></small>
                            </td>
                            <td data-label="Conductores">
                                <?php if (!$conductores): ?>
                                    <span class="fav-muted">Sin conductor</span>
                                <?php endif; ?>
                                <?php foreach ($conductores as $idx => $conductor): ?>
                                    <?php $driverState = $idx === 0 ? $cond1Estado : ($idx === 1 ? $cond2Estado : ''); ?>
                                    <span class="fav-driver-line">
                                        <strong><?= fav_h($conductor) ?></strong>
                                        <small><?= fav_h(fav_pay_label($driverState)) ?></small>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="Revision">
                                <span class="fav-pill <?= fav_h(fav_revision_class($revision)) ?>"><?= fav_h($revision) ?></span>
                            </td>
                            <td data-label="Hoja ruta">
                                <?php if ($hojaRuta !== ''): ?>
                                    <span class="fav-hoja fav-hoja--ok"><i class="bi bi-shield-check"></i> <?= fav_h($hojaRuta) ?></span>
                                <?php else: ?>
                                    <span class="fav-hoja fav-hoja--empty"><i class="bi bi-shield"></i> Sin hoja</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Importes" class="<?= $hasDifference ? 'fav-money-cell is-diff' : 'fav-money-cell' ?>">
                                <span><b>Viaje</b> <?= fav_h(fav_money_label($totalViaje)) ?></span>
                                <span><b>Cond.</b> <?= fav_h(fav_money_label($totalConductores)) ?></span>
                                <small><?= $hasDifference ? 'Dif. ' . fav_h(fav_money_label($diferencia)) : 'Cuadrado' ?></small>
                            </td>
                            <td data-label="Comentarios" class="fav-comment-cell">
                                <?php if (!$comentarios): ?>
                                    <span class="fav-muted">Sin comentarios</span>
                                <?php else: ?>
                                    <?php foreach ($comentarios as $comentario): ?>
                                        <p><?= fav_h($comentario) ?></p>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

</div>
<?php n360_render_footer(); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/flota_analisis_viajes_n360.js') ?>"></script>
</body>
</html>
