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

function fcc_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fcc_uid(): int {
    if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
        return (int)$_SESSION['id_usuario'];
    }
    if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
        return (int)$_SESSION['web_id_usuario'];
    }
    return 1;
}

function fcc_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function fcc_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }
    $refs = [$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fcc_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }
    fcc_bind($stmt, $types, $params);
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

function fcc_table_exists(mysqli $conn, string $table): bool {
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

function fcc_column_exists(mysqli $conn, string $table, string $column): bool {
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

function fcc_norm_col(string $value): string {
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && $ascii !== '') {
        $value = $ascii;
    }
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
}

function fcc_ident(string $column): string {
    return '`' . str_replace('`', '``', $column) . '`';
}

function fcc_columns(mysqli $conn, string $table): array {
    $result = $conn->query('SHOW COLUMNS FROM ' . fcc_ident($table));
    if (!$result) {
        throw new RuntimeException('No se pudo leer la estructura de ' . $table . '.');
    }
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $name = (string)($row['Field'] ?? '');
        if ($name === '') {
            continue;
        }
        $columns[$name] = [
            'name' => $name,
            'norm' => fcc_norm_col($name),
            'type' => strtolower((string)($row['Type'] ?? '')),
        ];
    }
    return $columns;
}

function fcc_pick_col(array $columns, array $exact = [], array $fragments = []): ?string {
    $exactNorm = array_map('fcc_norm_col', $exact);
    foreach ($columns as $column => $meta) {
        if (in_array($meta['norm'], $exactNorm, true)) {
            return $column;
        }
    }
    $fragmentNorm = array_map('fcc_norm_col', $fragments);
    foreach ($columns as $column => $meta) {
        foreach ($fragmentNorm as $fragment) {
            if ($fragment !== '' && strpos($meta['norm'], $fragment) !== false) {
                return $column;
            }
        }
    }
    return null;
}

function fcc_placa_map(array $columns): array {
    return [
        'id' => fcc_pick_col($columns, ['clm_placas_id', 'id'], ['PLACASID']),
        'placa' => fcc_pick_col($columns, ['clm_placas_PLACA', 'clm_placas_placa', 'placa'], ['PLACA']),
        'bus' => fcc_pick_col($columns, ['clm_placas_BUS', 'clm_placas_bus', 'bus', 'nombre'], ['PLACASBUS', 'BUS']),
        'dueno' => fcc_pick_col($columns, ['clm_placas_dueno', 'dueno', 'propietario'], ['DUEN', 'OWNER', 'PROPIETARIO']),
        'tipo' => fcc_pick_col($columns, ['clm_placas_tipo_vehiculo', 'tipo_vehiculo'], ['TIPOVEH', 'VEHICULO', 'TIPO']),
        'estado' => fcc_pick_col($columns, ['clm_placas_ESTADO', 'clm_placas_estado', 'estado'], ['ESTADO']),
        'servicio' => fcc_pick_col($columns, ['clm_placas_servicio', 'servicio'], ['SERVICIO']),
    ];
}

function fcc_select_expr(?string $column, string $alias, string $fallback = ''): string {
    if ($column !== null && $column !== '') {
        return fcc_ident($column) . ' AS ' . fcc_ident($alias);
    }
    return "'" . str_replace("'", "''", $fallback) . "' AS " . fcc_ident($alias);
}

function fcc_status_key($value): string {
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['0', 'INACTIVO', 'INACTIVA', 'BAJA', 'RETIRADO', 'RETIRADA', 'NO'], true) ? 'inactiva' : 'activa';
}

function fcc_origin_key(array $row): string {
    $tipo = strtoupper((string)($row['tipo'] ?? ''));
    $servicio = strtoupper((string)($row['servicio'] ?? ''));
    $bus = trim((string)($row['bus'] ?? ''));
    if (strpos($tipo, 'EXTERN') !== false || strpos($servicio, 'COMBUST') !== false) {
        return 'externa';
    }
    if ($bus !== '' && !preg_match('/^\d+$/', $bus)) {
        return 'externa';
    }
    return 'flota';
}

function fcc_fetch_company_plates(mysqli $conn): array {
    $columns = fcc_columns($conn, 'tb_placas');
    $map = fcc_placa_map($columns);
    if (!$map['id'] || !$map['placa']) {
        throw new RuntimeException('tb_placas no tiene id o placa disponible.');
    }

    $select = [
        fcc_select_expr($map['id'], 'id', '0'),
        fcc_select_expr($map['placa'], 'placa'),
        fcc_select_expr($map['bus'], 'bus'),
        fcc_select_expr($map['dueno'], 'dueno'),
        fcc_select_expr($map['tipo'], 'tipo'),
        fcc_select_expr($map['estado'], 'estado', 'Activo'),
        fcc_select_expr($map['servicio'], 'servicio'),
    ];

    $order = [];
    if ($map['bus']) {
        $bus = fcc_ident($map['bus']);
        $order[] = "CASE WHEN CAST(COALESCE($bus, '') AS CHAR) REGEXP '^[0-9]+$' THEN CAST($bus AS UNSIGNED) ELSE 999999 END ASC";
        $order[] = "$bus ASC";
    }
    $order[] = fcc_ident($map['placa']) . ' ASC';

    $rows = fcc_fetch_all($conn, 'SELECT ' . implode(', ', $select) . ' FROM tb_placas ORDER BY ' . implode(', ', $order));
    $plates = [];
    foreach ($rows as $row) {
        if (fcc_status_key($row['estado'] ?? '') !== 'activa') {
            continue;
        }
        if (fcc_origin_key($row) !== 'flota') {
            continue;
        }
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $bus = trim((string)($row['bus'] ?? ''));
        $placa = trim((string)($row['placa'] ?? ''));
        $row['label'] = trim(($bus !== '' ? $bus : 'Unidad') . ($placa !== '' ? ' (' . $placa . ')' : ''));
        $plates[$id] = $row;
    }
    return $plates;
}

function fcc_valid_month($value): string {
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m', $value);
    return ($date && $date->format('Y-m') === $value) ? $value : date('Y-m');
}

function fcc_date_label(?string $value, string $format = 'd/m/Y'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : $value;
}

function fcc_month_days(string $month): array {
    $start = new DateTimeImmutable($month . '-01');
    $days = [];
    $total = (int)$start->format('t');
    for ($i = 0; $i < $total; $i++) {
        $date = $start->modify('+' . $i . ' day');
        $days[] = [
            'date' => $date->format('Y-m-d'),
            'day' => $date->format('d'),
            'label' => $date->format('d/m'),
            'weekday' => ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'][(int)$date->format('w')],
        ];
    }
    return $days;
}

function fcc_month_label(string $monthStart): string {
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    $date = new DateTimeImmutable($monthStart);
    $monthNumber = (int)$date->format('n');
    return ($months[$monthNumber] ?? $date->format('m')) . ' ' . $date->format('Y');
}

function fcc_estado_revision_label($value): string {
    $estado = strtoupper(trim((string)$value));
    return $estado !== '' ? $estado : 'PENDIENTE';
}

function fcc_estado_revision_class(string $estado): string {
    $estado = strtoupper(trim($estado));
    if ($estado === 'VALIDADO') {
        return 'fcc-status--ok';
    }
    if ($estado === 'OBSERVADO') {
        return 'fcc-status--warn';
    }
    if ($estado === 'CORREGIDO') {
        return 'fcc-status--info';
    }
    if ($estado === 'SIN SALIDA') {
        return 'fcc-status--muted';
    }
    return 'fcc-status--pending';
}

function fcc_conductor_estado($value, bool $hasConductor): string {
    $estado = strtoupper(trim((string)$value));
    if ($estado === 'PAGADO') {
        return 'PAGADO';
    }
    if ($estado === 'PENDIENTE') {
        return 'PENDIENTE';
    }
    return $hasConductor ? 'PENDIENTE' : '';
}

function fcc_conductor_class(string $estado): string {
    return $estado === 'PAGADO' ? 'fcc-pay--ok' : ($estado === 'PENDIENTE' ? 'fcc-pay--pending' : 'fcc-pay--empty');
}

function fcc_conductores($texto): array {
    $texto = trim((string)$texto);
    if ($texto === '') {
        return [];
    }
    $parts = preg_split('/\s+\|\s+|\r\n|\r|\n|;/', $texto) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim(preg_replace('/\s+/', ' ', (string)$part));
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return array_slice($out, 0, 2);
}

if (empty($_SESSION['fcc_token'])) {
    $_SESSION['fcc_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['fcc_token'];
$isAdmin = n360_is_admin();
$tableReady = isset($conn) && $conn instanceof mysqli && fcc_table_exists($conn, 'tb_progbuses_salida_consolidado');
$driverColumns = [
    'clm_salprog_cond1_estado',
    'clm_salprog_cond1_observacion',
    'clm_salprog_cond2_estado',
    'clm_salprog_cond2_observacion',
];
$driverColumnsReady = $tableReady && isset($conn) && $conn instanceof mysqli;
if ($driverColumnsReady) {
    foreach ($driverColumns as $driverColumn) {
        $driverColumnsReady = $driverColumnsReady && fcc_column_exists($conn, 'tb_progbuses_salida_consolidado', $driverColumn);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        fcc_json(false, [], 'La tabla del consolidado todavia no esta disponible.', 500);
    }
    if (!$driverColumnsReady) {
        fcc_json(false, [], 'Faltan las columnas de estado y observacion de conductores. Ejecuta la query ALTER.', 500);
    }
    if (!hash_equals($csrfToken, (string)($_POST['csrf'] ?? ''))) {
        fcc_json(false, [], 'Sesion invalida. Actualiza la pagina.', 419);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'update_driver_status') {
        fcc_json(false, [], 'Accion no reconocida.', 400);
    }

    $id = (int)($_POST['id'] ?? 0);
    $cond1Estado = fcc_conductor_estado($_POST['cond1_estado'] ?? '', true);
    $cond2Estado = fcc_conductor_estado($_POST['cond2_estado'] ?? '', true);
    $cond1Obs = trim((string)($_POST['cond1_observacion'] ?? ''));
    $cond2Obs = trim((string)($_POST['cond2_observacion'] ?? ''));
    $cond1Obs = function_exists('mb_substr') ? mb_substr($cond1Obs, 0, 1000, 'UTF-8') : substr($cond1Obs, 0, 1000);
    $cond2Obs = function_exists('mb_substr') ? mb_substr($cond2Obs, 0, 1000, 'UTF-8') : substr($cond2Obs, 0, 1000);

    if ($id <= 0) {
        fcc_json(false, [], 'Registro invalido.', 422);
    }

    $stmt = $conn->prepare('
        UPDATE tb_progbuses_salida_consolidado
           SET clm_salprog_cond1_estado = ?,
               clm_salprog_cond1_observacion = NULLIF(?, \'\'),
               clm_salprog_cond2_estado = ?,
               clm_salprog_cond2_observacion = NULLIF(?, \'\')
         WHERE clm_salprog_id = ?
         LIMIT 1
    ');
    if (!$stmt) {
        fcc_json(false, [], $conn->error ?: 'No se pudo preparar la actualizacion.', 500);
    }
    $stmt->bind_param('ssssi', $cond1Estado, $cond1Obs, $cond2Estado, $cond2Obs, $id);
    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'No se pudo guardar el estado del conductor.';
        $stmt->close();
        fcc_json(false, [], $error, 500);
    }
    $stmt->close();

    fcc_json(true, [
        'cond1_estado' => $cond1Estado,
        'cond2_estado' => $cond2Estado,
        'actualizado' => date('d/m/Y H:i'),
    ], 'Estado de conductores actualizado.');
}

$month = fcc_valid_month($_GET['mes'] ?? date('Y-m'));
$monthStart = $month . '-01';
$monthEnd = (new DateTimeImmutable($monthStart))->format('Y-m-t');
$days = fcc_month_days($month);
$pageError = '';
$allPlates = [];
$plates = [];
$rows = [];
$rowsByPlateDay = [];
$selectedUnit = trim((string)($_GET['unidad'] ?? 'TODOS'));

try {
    if (!$tableReady) {
        throw new RuntimeException('Crea primero la tabla tb_progbuses_salida_consolidado y actualiza la rutina del cierre operativo.');
    }

    $allPlates = fcc_fetch_company_plates($conn);
    $plates = $allPlates;
    $rows = fcc_fetch_all($conn, '
        SELECT *
        FROM tb_progbuses_salida_consolidado
        WHERE clm_salprog_fecha_operativa BETWEEN ? AND ?
        ORDER BY clm_salprog_fecha_operativa ASC, clm_salprog_bus ASC, clm_salprog_hora_orden ASC, clm_salprog_id ASC
    ', 'ss', [$monthStart, $monthEnd]);

    foreach ($rows as $row) {
        $plateId = (int)($row['clm_salprog_idplaca'] ?? 0);
        $date = (string)($row['clm_salprog_fecha_operativa'] ?? '');
        if ($plateId <= 0 || $date === '') {
            continue;
        }
        if (!isset($rowsByPlateDay[$plateId])) {
            $rowsByPlateDay[$plateId] = [];
        }
        if (!isset($rowsByPlateDay[$plateId][$date])) {
            $rowsByPlateDay[$plateId][$date] = [];
        }
        $rowsByPlateDay[$plateId][$date][] = $row;
    }
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

if ($selectedUnit !== 'TODOS' && ctype_digit($selectedUnit)) {
    $unitId = (int)$selectedUnit;
    $plates = isset($plates[$unitId]) ? [$unitId => $plates[$unitId]] : [];
}

$kpis = [
    'unidades' => count($plates),
    'dias' => count($days),
    'programaciones' => count($rows),
    'pagados' => 0,
    'pendientes' => 0,
];

$reportUnits = [];
foreach ($plates as $plateId => $plate) {
    $unitProgrammed = 0;
    $unitPaid = 0;
    $unitPending = 0;
    $unitRows = [];

    foreach ($days as $day) {
        $date = $day['date'];
        $matches = $rowsByPlateDay[$plateId][$date] ?? [];
        $row = $matches[0] ?? null;
        $conductores = $row ? fcc_conductores($row['clm_salprog_conductores_texto'] ?? '') : [];
        $cond1 = $conductores[0] ?? '';
        $cond2 = $conductores[1] ?? '';
        $cond1Estado = fcc_conductor_estado($row['clm_salprog_cond1_estado'] ?? '', $cond1 !== '');
        $cond2Estado = fcc_conductor_estado($row['clm_salprog_cond2_estado'] ?? '', $cond2 !== '');
        $revision = $row ? fcc_estado_revision_label($row['clm_salprog_revision_estado'] ?? '') : 'SIN SALIDA';
        $extra = count($matches) > 1 ? '+' . (count($matches) - 1) : '';

        if ($row) {
            $unitProgrammed++;
            if ($cond1 !== '') {
                if ($cond1Estado === 'PAGADO') {
                    $unitPaid++;
                    $kpis['pagados']++;
                } else {
                    $unitPending++;
                    $kpis['pendientes']++;
                }
            }
            if ($cond2 !== '') {
                if ($cond2Estado === 'PAGADO') {
                    $unitPaid++;
                    $kpis['pagados']++;
                } else {
                    $unitPending++;
                    $kpis['pendientes']++;
                }
            }
        }

        $unitRows[] = [
            'id' => $row ? (int)($row['clm_salprog_id'] ?? 0) : 0,
            'date' => $date,
            'day' => $day['day'],
            'weekday' => $day['weekday'],
            'revision' => $revision,
            'extra' => $extra,
            'cond1' => $cond1,
            'cond1_estado' => $cond1Estado,
            'cond1_observacion' => $row ? (string)($row['clm_salprog_cond1_observacion'] ?? '') : '',
            'cond2' => $cond2,
            'cond2_estado' => $cond2Estado,
            'cond2_observacion' => $row ? (string)($row['clm_salprog_cond2_observacion'] ?? '') : '',
        ];
    }

    $reportUnits[] = [
        'id' => (int)$plateId,
        'label' => (string)($plate['label'] ?? ''),
        'bus' => (string)($plate['bus'] ?? ''),
        'placa' => (string)($plate['placa'] ?? ''),
        'programmed' => $unitProgrammed,
        'paid' => $unitPaid,
        'pending' => $unitPending,
        'rows' => $unitRows,
    ];
}

$monthLabel = fcc_month_label($monthStart);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flota | Control de conductores</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/flota_control_conductores_salidas_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Flota', 'subtitle' => 'Control mensual']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content fcc-page">
        <section class="fcc-hero">
            <div>
                <span class="fcc-eyebrow"><i class="bi bi-person-vcard-fill"></i> Flota - conductores</span>
                <h1>Control mensual de salidas</h1>
            </div>
            <div class="fcc-hero-actions">
                <button type="button" class="fcc-btn fcc-btn--primary" data-fcc-export-all><i class="bi bi-file-earmark-pdf"></i> PDF consolidado</button>
                <a class="fcc-btn fcc-btn--soft" href="consolidado_salidas_buses.php"><i class="bi bi-arrow-left"></i> Consolidado</a>
            </div>
        </section>

        <?php if (!$driverColumnsReady): ?>
            <div class="fcc-alert fcc-alert--warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Ejecuta la query ALTER para habilitar estado y observacion por conductor. La vista puede consultarse, pero no guardara cambios hasta tener esas columnas.
            </div>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <div class="fcc-alert fcc-alert--danger">
                <i class="bi bi-x-octagon-fill"></i>
                <?= fcc_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="fcc-summary">
            <article><span>Unidades</span><strong><?= number_format($kpis['unidades']) ?></strong></article>
            <article><span>Dias del mes</span><strong><?= number_format($kpis['dias']) ?></strong></article>
            <article><span>Pendientes</span><strong><?= number_format($kpis['pendientes']) ?></strong></article>
            <article><span>Pagados</span><strong><?= number_format($kpis['pagados']) ?></strong></article>
        </section>

        <section class="fcc-filter">
            <form method="get" class="fcc-filter-grid" autocomplete="off">
                <label>
                    <span>Mes operativo</span>
                    <input type="month" name="mes" value="<?= fcc_h($month) ?>">
                </label>
                <label>
                    <span>Unidad</span>
                    <select name="unidad">
                        <option value="TODOS" <?= $selectedUnit === 'TODOS' ? 'selected' : '' ?>>Todas las unidades</option>
                        <?php foreach ($allPlates as $plateOptionId => $plateOption): ?>
                            <option value="<?= (int)$plateOptionId ?>" <?= $selectedUnit === (string)$plateOptionId ? 'selected' : '' ?>><?= fcc_h($plateOption['label'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="fcc-filter-search">
                    <span>Buscar en pantalla</span>
                    <input type="search" data-fcc-search value="" placeholder="Bus, placa o conductor...">
                </label>
                <div class="fcc-filter-actions">
                    <button type="submit" class="fcc-btn fcc-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button>
                    <a class="fcc-btn fcc-btn--soft" href="control_conductores_salidas.php"><i class="bi bi-x-circle"></i> Limpiar</a>
                </div>
            </form>
        </section>

        <section class="fcc-units" data-fcc-units>
            <?php if (!$reportUnits): ?>
                <div class="fcc-empty">
                    <i class="bi bi-calendar2-x"></i>
                    <strong>No hay unidades para mostrar.</strong>
                    <span>Revisa filtros, permisos o la configuracion de placas activas de flota.</span>
                </div>
            <?php endif; ?>

            <?php foreach ($reportUnits as $index => $unit): ?>
                <article class="fcc-unit-card" data-fcc-unit data-unit-id="<?= (int)$unit['id'] ?>" data-unit-search="<?= fcc_h(strtolower(($unit['label'] ?? '') . ' ' . json_encode($unit['rows'], JSON_UNESCAPED_UNICODE))) ?>">
                    <div class="fcc-unit-head">
                        <button class="fcc-unit-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#fcc-unit-<?= (int)$unit['id'] ?>" aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>">
                            <span class="fcc-unit-icon"><i class="bi bi-bus-front-fill"></i></span>
                            <span>
                                <strong><?= fcc_h($unit['label']) ?></strong>
                                <small><?= number_format($unit['programmed']) ?> dias con salida en <?= fcc_h($monthLabel) ?></small>
                            </span>
                        </button>
                        <div class="fcc-unit-stats">
                            <span class="fcc-mini fcc-mini--pending">Pend. <?= number_format($unit['pending']) ?></span>
                            <span class="fcc-mini fcc-mini--paid">Pag. <?= number_format($unit['paid']) ?></span>
                            <button type="button" class="fcc-btn fcc-btn--soft" data-fcc-export-unit><i class="bi bi-file-earmark-pdf"></i> PDF unidad</button>
                        </div>
                    </div>

                    <div id="fcc-unit-<?= (int)$unit['id'] ?>" class="collapse <?= $index === 0 ? 'show' : '' ?>">
                        <div class="fcc-table-wrap">
                            <table class="fcc-table">
                                <thead>
                                    <tr>
                                        <th>Dia</th>
                                        <th>Estado trabajo</th>
                                        <th>Cond. 1</th>
                                        <th>Estado cond. 1</th>
                                        <th>Obs. cond. 1</th>
                                        <th>Cond. 2</th>
                                        <th>Estado cond. 2</th>
                                        <th>Obs. cond. 2</th>
                                        <th>Accion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unit['rows'] as $unitRow): ?>
                                        <?php
                                            $hasSchedule = (int)$unitRow['id'] > 0;
                                            $cond1Enabled = $hasSchedule && $unitRow['cond1'] !== '' && $driverColumnsReady;
                                            $cond2Enabled = $hasSchedule && $unitRow['cond2'] !== '' && $driverColumnsReady;
                                        ?>
                                        <tr data-fcc-row="<?= (int)$unitRow['id'] ?>" class="<?= $hasSchedule ? '' : 'is-empty-day' ?>">
                                            <td data-fcc-col="dia"><strong><?= fcc_h($unitRow['day']) ?></strong><span><?= fcc_h($unitRow['weekday']) ?></span></td>
                                            <td data-fcc-col="revision"><span class="fcc-status <?= fcc_estado_revision_class($unitRow['revision']) ?>"><?= fcc_h($unitRow['revision']) ?></span><?php if ($unitRow['extra'] !== ''): ?><small><?= fcc_h($unitRow['extra']) ?> salida</small><?php endif; ?></td>
                                            <td data-fcc-col="cond1"><?= $unitRow['cond1'] !== '' ? fcc_h($unitRow['cond1']) : '<span class="fcc-muted">-</span>' ?></td>
                                            <td data-fcc-col="cond1_estado">
                                                <select data-fcc-field="cond1_estado" class="<?= fcc_conductor_class($unitRow['cond1_estado']) ?>" <?= $cond1Enabled ? '' : 'disabled' ?>>
                                                    <option value="PENDIENTE" <?= $unitRow['cond1_estado'] === 'PENDIENTE' ? 'selected' : '' ?>>PENDIENTE</option>
                                                    <option value="PAGADO" <?= $unitRow['cond1_estado'] === 'PAGADO' ? 'selected' : '' ?>>PAGADO</option>
                                                </select>
                                            </td>
                                            <td data-fcc-col="cond1_obs"><textarea data-fcc-field="cond1_observacion" rows="1" <?= $cond1Enabled ? '' : 'disabled' ?>><?= fcc_h($unitRow['cond1_observacion']) ?></textarea></td>
                                            <td data-fcc-col="cond2"><?= $unitRow['cond2'] !== '' ? fcc_h($unitRow['cond2']) : '<span class="fcc-muted">-</span>' ?></td>
                                            <td data-fcc-col="cond2_estado">
                                                <select data-fcc-field="cond2_estado" class="<?= fcc_conductor_class($unitRow['cond2_estado']) ?>" <?= $cond2Enabled ? '' : 'disabled' ?>>
                                                    <option value="PENDIENTE" <?= $unitRow['cond2_estado'] === 'PENDIENTE' ? 'selected' : '' ?>>PENDIENTE</option>
                                                    <option value="PAGADO" <?= $unitRow['cond2_estado'] === 'PAGADO' ? 'selected' : '' ?>>PAGADO</option>
                                                </select>
                                            </td>
                                            <td data-fcc-col="cond2_obs"><textarea data-fcc-field="cond2_observacion" rows="1" <?= $cond2Enabled ? '' : 'disabled' ?>><?= fcc_h($unitRow['cond2_observacion']) ?></textarea></td>
                                            <td class="fcc-actions">
                                                <?php if ($hasSchedule): ?>
                                                    <button type="button" class="fcc-icon-save" data-fcc-save <?= $driverColumnsReady ? '' : 'disabled' ?> title="Guardar estados"><i class="bi bi-save2"></i></button>
                                                <?php else: ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<script>
window.N360_FCC = {
    csrf: <?= json_encode($csrfToken) ?>,
    endpoint: 'control_conductores_salidas.php',
    month: <?= json_encode($month) ?>,
    monthLabel: <?= json_encode($monthLabel) ?>,
    units: <?= json_encode($reportUnits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    report: {
        title: 'CONTROL MENSUAL DE CONDUCTORES',
        subtitle: 'Consolidado de salidas por unidad',
        docCode: 'FLOTA_CONDUCTORES_MES',
        generatedBy: <?= json_encode($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? '') ?>,
        dni: <?= json_encode($_SESSION['DNI'] ?? 'No registrado') ?>,
        logoLeft: <?= json_encode(n360_asset('img/icon.png')) ?>,
        logoRight: <?= json_encode(n360_asset('img/norte360_black.png')) ?>,
        fileBase: <?= json_encode('control_conductores_' . str_replace('-', '', $month)) ?>
    }
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/flota_control_conductores_salidas_n360.js') ?>"></script>
<?php n360_render_footer(); ?>
</body>
</html>