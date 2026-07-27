<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

$sessionPermisos = $_SESSION['permisos'] ?? [];
$sessionVistas = $_SESSION['vistas'] ?? [];
$isAdmin = ($_SESSION['web_rol'] ?? '') === 'Admin';
$hasAll = $sessionPermisos === 'all';
$permisos = $hasAll ? [] : array_map('intval', (array)$sessionPermisos);
$vistas = $hasAll ? [] : array_map('strval', (array)$sessionVistas);
$canAccess = $isAdmin || $hasAll || (in_array(10, $permisos, true) && (in_array('f-placas', $vistas, true) || in_array('f-flotas', $vistas, true)));

if (!$canAccess) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Gestion de placas'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');
try {
    $conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) {
    // La vista puede operar con el charset de conexion aunque el servidor no acepte esta collation.
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function gplac_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function gplac_clean($value, int $max = 160): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
}

function gplac_norm_col(string $value): string {
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    $value = strtr($value, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
        'Ã' => 'A', 'Ã‰' => 'E', 'Ã' => 'I', 'Ã“' => 'O', 'Ãš' => 'U',
        'Ã‘' => 'N', 'Ãœ' => 'U',
    ]);

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && $ascii !== '') {
        $value = $ascii;
    }

    return preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
}

function gplac_ident(string $column): string {
    return '`' . str_replace('`', '``', $column) . '`';
}

function gplac_columns(mysqli $conn): array {
    $result = $conn->query('SHOW COLUMNS FROM tb_placas');
    if (!$result) {
        throw new RuntimeException('No se pudo leer la estructura de tb_placas.');
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $name = (string)($row['Field'] ?? '');
        if ($name === '') {
            continue;
        }

        $columns[$name] = [
            'name' => $name,
            'norm' => gplac_norm_col($name),
            'type' => strtolower((string)($row['Type'] ?? '')),
        ];
    }

    return $columns;
}

function gplac_pick_col(array $columns, array $exact = [], array $fragments = []): ?string {
    $exactNorm = array_map('gplac_norm_col', $exact);
    foreach ($columns as $column => $meta) {
        if (in_array($meta['norm'], $exactNorm, true)) {
            return $column;
        }
    }

    $fragmentNorm = array_map('gplac_norm_col', $fragments);
    foreach ($columns as $column => $meta) {
        foreach ($fragmentNorm as $fragment) {
            if ($fragment !== '' && strpos($meta['norm'], $fragment) !== false) {
                return $column;
            }
        }
    }

    return null;
}

function gplac_column_map(array $columns): array {
    return [
        'id' => gplac_pick_col($columns, ['clm_placas_id', 'id'], ['PLACASID']),
        'placa' => gplac_pick_col($columns, ['clm_placas_PLACA', 'clm_placas_placa', 'placa'], ['PLACA']),
        'dueno' => gplac_pick_col($columns, ['clm_placas_dueno', 'dueno', 'propietario'], ['DUEN', 'OWNER', 'PROPIETARIO']),
        'bus' => gplac_pick_col($columns, ['clm_placas_BUS', 'clm_placas_bus', 'bus', 'nombre'], ['PLACASBUS', 'BUS']),
        'tipo' => gplac_pick_col($columns, ['clm_placas_tipo_vehiculo', 'tipo_vehiculo'], ['TIPOVEH', 'VEHICULO', 'TIPO']),
        'estado' => gplac_pick_col($columns, ['clm_placas_ESTADO', 'clm_placas_estado', 'estado'], ['ESTADO']),
        'kilometraje' => gplac_pick_col($columns, ['clm_placas_KILOMETRAJE', 'clm_placas_kilometraje', 'kilometraje'], ['KILOMETRAJE', 'KM']),
        'servicio' => gplac_pick_col($columns, ['clm_placas_servicio', 'servicio'], ['SERVICIO']),
        'fecha_inicio' => gplac_pick_col($columns, ['clm_placas_fecha_inicio', 'fecha_inicio'], ['FECHAINICIO']),
    ];
}

function gplac_plate_key(string $plate): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($plate))) ?? '';
}

function gplac_plate_store(string $plate): string {
    $plain = gplac_plate_key($plate);
    if (strlen($plain) > 3) {
        return substr($plain, 0, 3) . '-' . substr($plain, 3);
    }

    return $plain;
}

function gplac_validate_plate(string $plate): string {
    $stored = gplac_plate_store($plate);
    $plain = gplac_plate_key($stored);

    if (strlen($plain) < 5 || strlen($plain) > 10) {
        throw new RuntimeException('Ingresa una placa valida. Ejemplo: ABC-123.');
    }

    return $stored;
}

function gplac_status_key($value): string {
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['0', 'INACTIVO', 'INACTIVA', 'BAJA', 'RETIRADO', 'RETIRADA', 'NO'], true) ? 'inactiva' : 'activa';
}

function gplac_status_label($value): string {
    return gplac_status_key($value) === 'inactiva' ? 'Inactiva' : 'Activa';
}

function gplac_status_db_value(array $columns, ?string $column, string $state) {
    $state = $state === 'inactiva' ? 'inactiva' : 'activa';
    $type = $column && isset($columns[$column]) ? (string)$columns[$column]['type'] : '';

    if (strpos($type, 'int') !== false || strpos($type, 'tinyint') !== false || strpos($type, 'bool') !== false) {
        return $state === 'activa' ? 1 : 0;
    }

    return $state === 'activa' ? 'Activo' : 'Inactivo';
}

function gplac_origin_key(array $row): string {
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

function gplac_origin_label(array $row): string {
    return gplac_origin_key($row) === 'externa' ? 'Externa / combustible' : 'Flota';
}

function gplac_select_expr(?string $column, string $alias, string $fallback = ''): string {
    if ($column !== null && $column !== '') {
        return gplac_ident($column) . ' AS ' . gplac_ident($alias);
    }

    return "'" . str_replace("'", "''", $fallback) . "' AS " . gplac_ident($alias);
}

function gplac_fetch_rows(mysqli $conn, array $map): array {
    $idCol = $map['id'];
    $plateCol = $map['placa'];
    if (!$idCol || !$plateCol) {
        throw new RuntimeException('La tabla tb_placas no tiene las columnas minimas esperadas.');
    }

    $select = [
        gplac_select_expr($idCol, 'id', '0'),
        gplac_select_expr($plateCol, 'placa'),
        gplac_select_expr($map['dueno'] ?? null, 'dueno'),
        gplac_select_expr($map['bus'] ?? null, 'bus'),
        gplac_select_expr($map['tipo'] ?? null, 'tipo'),
        gplac_select_expr($map['estado'] ?? null, 'estado', 'Activo'),
        gplac_select_expr($map['kilometraje'] ?? null, 'kilometraje', '0'),
        gplac_select_expr($map['servicio'] ?? null, 'servicio'),
    ];

    $orderParts = [];
    if (!empty($map['estado'])) {
        $estado = gplac_ident($map['estado']);
        $orderParts[] = "CASE WHEN UPPER(CAST(COALESCE($estado, '') AS CHAR)) IN ('0','INACTIVO','INACTIVA','BAJA','RETIRADO','RETIRADA') THEN 1 ELSE 0 END ASC";
    }
    if (!empty($map['bus'])) {
        $bus = gplac_ident($map['bus']);
        $orderParts[] = "CASE WHEN CAST(COALESCE($bus, '') AS CHAR) REGEXP '^[0-9]+$' THEN CAST($bus AS UNSIGNED) ELSE 999999 END ASC";
    }
    $orderParts[] = gplac_ident($idCol) . ' DESC';

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM tb_placas ORDER BY ' . implode(', ', $orderParts) . ' LIMIT 2500';
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('No se pudo consultar tb_placas.');
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function gplac_duplicate_id(mysqli $conn, array $map, string $plate, int $excludeId = 0): int {
    $idCol = $map['id'];
    $plateCol = $map['placa'];
    $key = gplac_plate_key($plate);

    $plateExpr = "REPLACE(REPLACE(UPPER(TRIM(COALESCE(" . gplac_ident($plateCol) . ", ''))), '-', ''), ' ', '')";
    $sql = 'SELECT ' . gplac_ident($idCol) . " FROM tb_placas WHERE $plateExpr = ?";
    $types = 's';
    $params = [$key];

    if ($excludeId > 0) {
        $sql .= ' AND ' . gplac_ident($idCol) . ' <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la validacion de placa.');
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($foundId);

    return $stmt->fetch() ? (int)$foundId : 0;
}

function gplac_execute(mysqli $conn, string $sql, string $types, array $params): void {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la operacion.');
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error ?: 'No se pudo completar la operacion.');
    }
}

function gplac_param_type($value): string {
    if (is_int($value)) return 'i';
    if (is_float($value)) return 'd';
    return 's';
}

function gplac_payload(array $columns, array $map, array $post): array {
    $plate = gplac_validate_plate((string)($post['placa'] ?? ''));
    $origin = (string)($post['origen_registro'] ?? 'flota') === 'externa' ? 'externa' : 'flota';
    $bus = gplac_clean($post['bus_nombre'] ?? '', 80);
    $dueno = gplac_clean($post['dueno'] ?? '', 120);
    $tipo = strtoupper(gplac_clean($post['tipo'] ?? '', 60));
    $servicio = strtoupper(gplac_clean($post['servicio'] ?? '', 60));
    $state = (string)($post['estado'] ?? 'activa') === 'inactiva' ? 'inactiva' : 'activa';
    $kmRaw = trim((string)($post['kilometraje'] ?? '0'));
    $kmNormalized = str_replace(',', '.', $kmRaw);
    if ($kmRaw !== '' && !is_numeric($kmNormalized)) {
        throw new RuntimeException('El kilometraje debe ser numerico.');
    }
    $km = $kmRaw === '' ? 0 : (float)$kmNormalized;

    if ($km < 0) {
        throw new RuntimeException('El kilometraje no puede ser negativo.');
    }

    if ($origin === 'externa') {
        if ($bus === '') {
            $bus = str_replace('-', '', $plate);
        }
        if ($tipo === '' || $tipo === 'BUS') {
            $tipo = 'EXTERNO';
        }
        if ($servicio === '' || $servicio === 'TRANSPORTE' || $servicio === 'REGULAR') {
            $servicio = 'COMBUSTIBLE';
        }
    } else {
        if ($bus === '') {
            throw new RuntimeException('Para una placa de flota coloca el numero de bus.');
        }
        if ($tipo === '' || $tipo === 'EXTERNO') {
            $tipo = 'BUS';
        }
        if ($servicio === '' || $servicio === 'COMBUSTIBLE') {
            $servicio = 'TRANSPORTE';
        }
    }

    $values = [
        $map['placa'] => $plate,
    ];

    if (!empty($map['bus'])) $values[$map['bus']] = $bus;
    if (!empty($map['dueno'])) $values[$map['dueno']] = $dueno;
    if (!empty($map['tipo'])) $values[$map['tipo']] = $tipo;
    if (!empty($map['servicio'])) $values[$map['servicio']] = $servicio;
    if (!empty($map['estado'])) $values[$map['estado']] = gplac_status_db_value($columns, $map['estado'], $state);
    if (!empty($map['kilometraje'])) $values[$map['kilometraje']] = $km;
    if (!empty($map['fecha_inicio'])) $values[$map['fecha_inicio']] = date('Y-m-d');

    return [$plate, $values];
}

function gplac_insert_row(mysqli $conn, array $values): void {
    $fields = array_keys($values);
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO tb_placas (' . implode(', ', array_map('gplac_ident', $fields)) . ') VALUES (' . $placeholders . ')';
    $types = '';
    $params = [];

    foreach ($fields as $field) {
        $types .= gplac_param_type($values[$field]);
        $params[] = $values[$field];
    }

    gplac_execute($conn, $sql, $types, $params);
}

function gplac_update_row(mysqli $conn, array $map, int $id, array $values): void {
    $sets = [];
    $types = '';
    $params = [];

    foreach ($values as $field => $value) {
        if ($field === $map['id']) {
            continue;
        }
        $sets[] = gplac_ident($field) . ' = ?';
        $types .= gplac_param_type($value);
        $params[] = $value;
    }

    if (!$sets) {
        throw new RuntimeException('No hay campos para actualizar.');
    }

    $types .= 'i';
    $params[] = $id;
    $sql = 'UPDATE tb_placas SET ' . implode(', ', $sets) . ' WHERE ' . gplac_ident($map['id']) . ' = ? LIMIT 1';
    gplac_execute($conn, $sql, $types, $params);
}

function gplac_set_state(mysqli $conn, array $columns, array $map, int $id, string $state): void {
    if (empty($map['estado'])) {
        throw new RuntimeException('La tabla no tiene columna de estado.');
    }

    $value = gplac_status_db_value($columns, $map['estado'], $state);
    $sql = 'UPDATE tb_placas SET ' . gplac_ident($map['estado']) . ' = ? WHERE ' . gplac_ident($map['id']) . ' = ? LIMIT 1';
    gplac_execute($conn, $sql, gplac_param_type($value) . 'i', [$value, $id]);
}

function gplac_json_attr(array $data): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($data, $flags);
    return gplac_h($json !== false ? $json : '{}');
}

if (empty($_SESSION['gplac_csrf'])) {
    try {
        $_SESSION['gplac_csrf'] = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $_SESSION['gplac_csrf'] = sha1(uniqid('gplac', true));
    }
}

$csrf = (string)$_SESSION['gplac_csrf'];
$pageError = '';
$flash = $_SESSION['gplac_flash'] ?? null;
unset($_SESSION['gplac_flash']);

try {
    $columns = gplac_columns($conn);
    $map = gplac_column_map($columns);

    if (empty($map['id']) || empty($map['placa'])) {
        throw new RuntimeException('tb_placas no tiene id o placa disponible.');
    }
} catch (Throwable $e) {
    $columns = [];
    $map = [];
    $pageError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pageError === '') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Token de seguridad vencido. Actualiza la pagina e intenta nuevamente.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            [$plate, $values] = gplac_payload($columns, $map, $_POST);
            if (gplac_duplicate_id($conn, $map, $plate) > 0) {
                throw new RuntimeException('La placa ya existe en el sistema.');
            }

            gplac_insert_row($conn, $values);
            $_SESSION['gplac_flash'] = ['type' => 'ok', 'message' => 'Placa registrada correctamente.'];
            header('Location: gest_plac.php');
            exit;
        }

        if ($action === 'update') {
            $id = (int)($_POST['placa_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('No se recibio la placa a actualizar.');
            }

            [$plate, $values] = gplac_payload($columns, $map, $_POST);
            $duplicateId = gplac_duplicate_id($conn, $map, $plate, $id);
            if ($duplicateId > 0) {
                throw new RuntimeException('La placa ya existe en otro registro.');
            }

            gplac_update_row($conn, $map, $id, $values);
            $_SESSION['gplac_flash'] = ['type' => 'ok', 'message' => 'Placa actualizada correctamente.'];
            header('Location: gest_plac.php');
            exit;
        }

        if ($action === 'set_state') {
            $id = (int)($_POST['placa_id'] ?? 0);
            $state = (string)($_POST['target_state'] ?? 'activa') === 'inactiva' ? 'inactiva' : 'activa';
            if ($id <= 0) {
                throw new RuntimeException('No se recibio la placa a cambiar de estado.');
            }

            gplac_set_state($conn, $columns, $map, $id, $state);
            $_SESSION['gplac_flash'] = ['type' => 'ok', 'message' => $state === 'activa' ? 'Placa activada.' : 'Placa inactivada.'];
            header('Location: gest_plac.php');
            exit;
        }

        throw new RuntimeException('Accion no reconocida.');
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$placas = [];
if ($pageError === '') {
    try {
        $placas = gplac_fetch_rows($conn, $map);
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$metrics = ['total' => 0, 'activas' => 0, 'flota' => 0, 'externas' => 0];
foreach ($placas as $row) {
    $metrics['total']++;
    if (gplac_status_key($row['estado'] ?? '') !== 'inactiva') {
        $metrics['activas']++;
    }
    if (gplac_origin_key($row) === 'externa') {
        $metrics['externas']++;
    } else {
        $metrics['flota']++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestion de placas | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= gplac_h(n360_asset('img/norte360.png')) ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= gplac_h(n360_asset('assets/css/header_n360.css')) ?>">
    <link rel="stylesheet" href="<?= gplac_h(n360_asset('assets/css/sidebar_n360.css')) ?>">
    <link rel="stylesheet" href="<?= gplac_h(n360_asset('assets/css/main_n360.css')) ?>">
    <link rel="stylesheet" href="<?= gplac_h(n360_asset('assets/css/footer_n360.css')) ?>">
    <link rel="stylesheet" href="<?= gplac_h(n360_asset('assets/css/content_n360.css')) ?>">
    <link rel="stylesheet" href="<?= gplac_h(n360_asset('assets/css/flota_placas_n360.css')) ?>?v=20260725">
</head>
<body>
<?php n360_render_header(['title' => 'Flota', 'subtitle' => 'Gestion de placas']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <div class="n360-main__inner gplac-page">
        <section class="gplac-hero">
            <div>
                <span class="gplac-eyebrow"><i class="bi bi-bus-front-fill"></i> Flota - placas</span>
                <h1>Gestion de placas</h1>
                <p>Administra unidades propias y placas externas registradas desde combustible sin mezclarlas en la operacion de flota.</p>
            </div>
            <div class="gplac-hero-actions">
                <button type="button" class="gplac-btn gplac-btn--soft" data-bs-toggle="modal" data-bs-target="#gplacCreateModal">
                    <i class="bi bi-plus-lg"></i> Nueva placa
                </button>
                <a class="gplac-btn gplac-btn--soft" href="programacion_horarios.php">
                    <i class="bi bi-calendar2-week"></i> Horarios
                </a>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="gplac-alert gplac-alert--<?= gplac_h($flash['type'] ?? 'ok') ?>">
                <i class="bi bi-check-circle-fill"></i>
                <span><?= gplac_h($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <div class="gplac-alert gplac-alert--danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= gplac_h($pageError) ?></span>
            </div>
        <?php endif; ?>

        <section class="gplac-summary">
            <article>
                <span>Total placas</span>
                <strong><?= number_format($metrics['total']) ?></strong>
            </article>
            <article>
                <span>Activas</span>
                <strong><?= number_format($metrics['activas']) ?></strong>
            </article>
            <article>
                <span>Flota</span>
                <strong><?= number_format($metrics['flota']) ?></strong>
            </article>
            <article>
                <span>Externas combustible</span>
                <strong><?= number_format($metrics['externas']) ?></strong>
            </article>
        </section>

        <section class="gplac-filter">
            <div class="gplac-filter-grid">
                <label>
                    <span>Buscar</span>
                    <input type="search" data-gplac-search-input placeholder="Placa, bus, propietario, tipo o servicio...">
                </label>
                <div class="gplac-filter-tabs" aria-label="Filtros rapidos">
                    <button type="button" class="gplac-filter-tab is-active" data-gplac-filter="all">Activas</button>
                    <button type="button" class="gplac-filter-tab" data-gplac-filter="fleet">Flota</button>
                    <button type="button" class="gplac-filter-tab" data-gplac-filter="external">Externas</button>
                    <button type="button" class="gplac-filter-tab" data-gplac-filter="inactive">Inactivas</button>
                </div>
            </div>
        </section>

        <section class="gplac-card">
            <div class="gplac-card-head">
                <div>
                    <h2>Placas registradas</h2>
                    <p>Las externas quedan disponibles para combustible sin tratarlas como buses de la empresa.</p>
                </div>
                <span class="gplac-count-pill" data-gplac-visible-count><?= number_format($metrics['activas']) ?> visibles</span>
            </div>

            <div class="gplac-table-wrap">
                <table class="gplac-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Placa</th>
                        <th>Unidad / referencia</th>
                        <th>Propietario</th>
                        <th>Tipo</th>
                        <th>Servicio</th>
                        <th>Km</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$placas): ?>
                        <tr>
                            <td colspan="9" class="gplac-empty">No hay placas registradas.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($placas as $row): ?>
                        <?php
                        $id = (int)($row['id'] ?? 0);
                        $statusKey = gplac_status_key($row['estado'] ?? '');
                        $originKey = gplac_origin_key($row);
                        $editData = [
                            'id' => $id,
                            'placa' => $row['placa'] ?? '',
                            'bus' => $row['bus'] ?? '',
                            'dueno' => $row['dueno'] ?? '',
                            'tipo' => $row['tipo'] ?? '',
                            'servicio' => $row['servicio'] ?? '',
                            'kilometraje' => $row['kilometraje'] ?? '',
                            'estado_key' => $statusKey,
                            'origin' => $originKey,
                        ];
                        $searchBlob = implode(' ', [
                            $row['placa'] ?? '',
                            $row['bus'] ?? '',
                            $row['dueno'] ?? '',
                            $row['tipo'] ?? '',
                            $row['servicio'] ?? '',
                            gplac_origin_label($row),
                            gplac_status_label($row['estado'] ?? ''),
                        ]);
                        ?>
                        <tr
                            data-gplac-row
                            data-origin="<?= gplac_h($originKey) ?>"
                            data-state="<?= gplac_h($statusKey) ?>"
                            data-search="<?= gplac_h($searchBlob) ?>"
                        >
                            <td><span class="gplac-pill gplac-pill--soft">#<?= $id ?></span></td>
                            <td>
                                <strong><?= gplac_h($row['placa'] ?? '-') ?></strong>
                                <span class="gplac-pill <?= $originKey === 'externa' ? 'gplac-pill--external' : 'gplac-pill--fleet' ?>">
                                    <?= gplac_h(gplac_origin_label($row)) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= gplac_h($row['bus'] ?: '-') ?></strong>
                                <small><?= $originKey === 'externa' ? 'Referencia externa' : 'Unidad de flota' ?></small>
                            </td>
                            <td><?= gplac_h($row['dueno'] ?: '-') ?></td>
                            <td><?= gplac_h($row['tipo'] ?: '-') ?></td>
                            <td><?= gplac_h($row['servicio'] ?: '-') ?></td>
                            <td><?= gplac_h(number_format((float)($row['kilometraje'] ?? 0), 0)) ?></td>
                            <td>
                                <span class="gplac-pill <?= $statusKey === 'inactiva' ? 'gplac-pill--inactive' : 'gplac-pill--active' ?>">
                                    <?= gplac_h(gplac_status_label($row['estado'] ?? '')) ?>
                                </span>
                            </td>
                            <td>
                                <div class="gplac-row-actions">
                                    <button type="button" class="gplac-icon-btn" data-bs-toggle="modal" data-bs-target="#gplacEditModal" data-gplac-edit="<?= gplac_json_attr($editData) ?>" title="Editar">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf" value="<?= gplac_h($csrf) ?>">
                                        <input type="hidden" name="action" value="set_state">
                                        <input type="hidden" name="placa_id" value="<?= $id ?>">
                                        <input type="hidden" name="target_state" value="<?= $statusKey === 'inactiva' ? 'activa' : 'inactiva' ?>">
                                        <button type="submit" class="gplac-icon-btn" title="<?= $statusKey === 'inactiva' ? 'Activar' : 'Inactivar' ?>">
                                            <i class="bi <?= $statusKey === 'inactiva' ? 'bi-check2-circle' : 'bi-slash-circle' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
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

<div class="modal fade gplac-modal" id="gplacCreateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= gplac_h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="gplac-modal-head">
                    <div>
                        <span><i class="bi bi-plus-circle-fill"></i> Nuevo registro</span>
                        <h2>Nueva placa</h2>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="gplac-modal-body">
                    <label>
                        <span>Origen</span>
                        <select name="origen_registro" data-gplac-origin-select>
                            <option value="flota">Flota de la empresa</option>
                            <option value="externa">Externa / combustible</option>
                        </select>
                    </label>
                    <label>
                        <span>Placa</span>
                        <input type="text" name="placa" placeholder="ABC-123" required data-gplac-uppercase>
                    </label>
                    <label>
                        <span>Unidad / referencia</span>
                        <input type="text" name="bus_nombre" placeholder="Numero de bus. Ej. 158">
                    </label>
                    <label>
                        <span>Propietario</span>
                        <input type="text" name="dueno" placeholder="Empresa, proveedor o duenio">
                    </label>
                    <label>
                        <span>Tipo vehiculo</span>
                        <input type="text" name="tipo" value="BUS">
                    </label>
                    <label>
                        <span>Servicio</span>
                        <input type="text" name="servicio" value="TRANSPORTE">
                    </label>
                    <label>
                        <span>Kilometraje</span>
                        <input type="number" name="kilometraje" min="0" step="1" value="0">
                    </label>
                    <label>
                        <span>Estado</span>
                        <select name="estado">
                            <option value="activa">Activa</option>
                            <option value="inactiva">Inactiva</option>
                        </select>
                    </label>
                    <p class="gplac-help gplac-field--wide">
                        Usa "Externa / combustible" para placas registradas por abastecimientos/tanqueadas que no pertenecen a la flota operativa.
                    </p>
                </div>
                <div class="gplac-modal-foot">
                    <button type="button" class="gplac-btn" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="gplac-btn gplac-btn--primary">Guardar placa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade gplac-modal" id="gplacEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= gplac_h($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="placa_id" value="">
                <div class="gplac-modal-head">
                    <div>
                        <span><i class="bi bi-pencil-square"></i> Edicion</span>
                        <h2>Editar placa</h2>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="gplac-modal-body">
                    <label>
                        <span>Origen visual</span>
                        <select name="origen_registro" data-gplac-origin-select>
                            <option value="flota">Flota de la empresa</option>
                            <option value="externa">Externa / combustible</option>
                        </select>
                    </label>
                    <label>
                        <span>Placa</span>
                        <input type="text" name="placa" required data-gplac-uppercase>
                    </label>
                    <label>
                        <span>Unidad / referencia</span>
                        <input type="text" name="bus_nombre">
                    </label>
                    <label>
                        <span>Propietario</span>
                        <input type="text" name="dueno">
                    </label>
                    <label>
                        <span>Tipo vehiculo</span>
                        <input type="text" name="tipo">
                    </label>
                    <label>
                        <span>Servicio</span>
                        <input type="text" name="servicio">
                    </label>
                    <label>
                        <span>Kilometraje</span>
                        <input type="number" name="kilometraje" min="0" step="1">
                    </label>
                    <label>
                        <span>Estado</span>
                        <select name="estado">
                            <option value="activa">Activa</option>
                            <option value="inactiva">Inactiva</option>
                        </select>
                    </label>
                </div>
                <div class="gplac-modal-foot">
                    <button type="button" class="gplac-btn" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="gplac-btn gplac-btn--primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= gplac_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= gplac_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="<?= gplac_h(n360_asset('assets/js/flota_placas_n360.js')) ?>?v=20260725"></script>
</body>
</html>
