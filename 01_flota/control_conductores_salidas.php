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

function fcc_time_label($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $match)) {
        return str_pad($match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2];
    }

    $time = strtotime($value);
    return $time ? date('H:i', $time) : $value;
}

function fcc_real_departure_datetime($operationalDate, $departureTime, string $cutoff = '05:00:00'): string {
    $operationalDate = trim((string)$operationalDate);
    $departureTime = trim((string)$departureTime);

    if ($operationalDate === '' || $departureTime === '') {
        return '';
    }

    $normalizedTime = fcc_time_label($departureTime);
    if (!preg_match('/^\d{2}:\d{2}$/', $normalizedTime)) {
        return '';
    }

    try {
        $realDate = new DateTimeImmutable($operationalDate);
        $compareTime = $normalizedTime . ':00';
        if ($compareTime < $cutoff) {
            $realDate = $realDate->modify('+1 day');
        }
        return $realDate->format('Y-m-d') . ' ' . $normalizedTime . ':00';
    } catch (Throwable $e) {
        return '';
    }
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

function fcc_revision_is_anulada(string $estado): bool {
    return strtoupper(trim($estado)) === 'ANULADO';
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
    if ($estado === 'ANULADO') {
        return 'fcc-status--void';
    }
    if ($estado === 'MANUAL') {
        return 'fcc-status--manual';
    }
    if ($estado === 'TRANSBORDADO' || $estado === 'TRANSBORDO') {
        return 'fcc-status--transfer';
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

function fcc_ida_vuelta($value): string {
    $estado = strtoupper(trim((string)$value));
    if (in_array($estado, ['PENDIENTE', 'IDA', 'RETORNO'], true)) {
        return $estado;
    }
    return 'PENDIENTE';
}

function fcc_hojaruta_key($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function fcc_importe_nullable($value, string $label): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = str_replace(',', '.', $value);
    if (!preg_match('/^\d{1,16}(?:\.\d{1,4})?$/', $value)) {
        throw new InvalidArgumentException($label . ' debe ser un importe positivo con hasta 4 decimales.');
    }

    $parts = array_pad(explode('.', $value, 2), 2, '');
    $entero = ltrim($parts[0], '0');
    $decimal = $parts[1];
    if ($entero === '') {
        $entero = '0';
    }

    return $decimal !== '' ? $entero . '.' . $decimal : $entero;
}

function fcc_importe_input($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $value = trim((string)$value);
    return preg_match('/^\d{1,16}(?:\.\d{1,4})?$/', $value) ? $value : '';
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

function fcc_driver_update_payload(array $data): array {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Registro invalido.');
    }

    $idaVuelta = fcc_ida_vuelta($data['ida_vuelta'] ?? 'PENDIENTE');
    $cond1Estado = fcc_conductor_estado($data['cond1_estado'] ?? '', true);
    $cond2Estado = fcc_conductor_estado($data['cond2_estado'] ?? '', true);
    $viajeImporte = fcc_importe_nullable($data['viaje_importe'] ?? '', 'Importe total del viaje');
    $viajeComentario = trim((string)($data['viaje_comentario'] ?? ''));
    $cond1Importe = fcc_importe_nullable($data['cond1_importe'] ?? '', 'Pago del conductor 1');
    $cond2Importe = fcc_importe_nullable($data['cond2_importe'] ?? '', 'Pago del conductor 2');
    $cond1Obs = trim((string)($data['cond1_observacion'] ?? ''));
    $cond2Obs = trim((string)($data['cond2_observacion'] ?? ''));
    $viajeComentario = function_exists('mb_substr') ? mb_substr($viajeComentario, 0, 1000, 'UTF-8') : substr($viajeComentario, 0, 1000);
    $cond1Obs = function_exists('mb_substr') ? mb_substr($cond1Obs, 0, 1000, 'UTF-8') : substr($cond1Obs, 0, 1000);
    $cond2Obs = function_exists('mb_substr') ? mb_substr($cond2Obs, 0, 1000, 'UTF-8') : substr($cond2Obs, 0, 1000);

    if ($idaVuelta === 'RETORNO') {
        $cond1Estado = '';
        $cond1Importe = '';
        $cond2Estado = '';
        $cond2Importe = '';
    }

    return [
        'id' => $id,
        'ida_vuelta' => $idaVuelta,
        'viaje_importe' => $viajeImporte,
        'viaje_comentario' => $viajeComentario,
        'cond1_estado' => $cond1Estado,
        'cond1_importe' => $cond1Importe,
        'cond1_observacion' => $cond1Obs,
        'cond2_estado' => $cond2Estado,
        'cond2_importe' => $cond2Importe,
        'cond2_observacion' => $cond2Obs,
    ];
}

function fcc_prepare_driver_update(mysqli $conn): mysqli_stmt {
    $stmt = $conn->prepare('
        UPDATE tb_progbuses_salida_consolidado
           SET clm_salprog_estadoidavuelta = ?,
               clm_salprog_imtotaldelviaje = NULLIF(?, \'\'),
               clm_salprog_comentariocontroldelviaje = NULLIF(?, \'\'),
               clm_salprog_cond1_estado = NULLIF(?, \'\'),
               clm_salprog_imtotalcond1 = NULLIF(?, \'\'),
               clm_salprog_cond1_observacion = NULLIF(?, \'\'),
               clm_salprog_cond2_estado = NULLIF(?, \'\'),
               clm_salprog_imtotalcond2 = NULLIF(?, \'\'),
               clm_salprog_cond2_observacion = NULLIF(?, \'\')
         WHERE clm_salprog_id = ?
         LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la actualizacion.');
    }
    return $stmt;
}

if (empty($_SESSION['fcc_token'])) {
    $_SESSION['fcc_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['fcc_token'];
$isAdmin = n360_is_admin();
$tableReady = isset($conn) && $conn instanceof mysqli && fcc_table_exists($conn, 'tb_progbuses_salida_consolidado');
$driverColumns = [
    'clm_salprog_estadoidavuelta',
    'clm_salprog_imtotaldelviaje',
    'clm_salprog_comentariocontroldelviaje',
    'clm_salprog_cond1_estado',
    'clm_salprog_cond1_observacion',
    'clm_salprog_imtotalcond1',
    'clm_salprog_cond2_estado',
    'clm_salprog_cond2_observacion',
    'clm_salprog_imtotalcond2',
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
        fcc_json(false, [], 'Faltan columnas de ida/vuelta, importe total, comentario, estado, observacion o importe de conductores. Ejecuta la query ALTER.', 500);
    }
    if (!hash_equals($csrfToken, (string)($_POST['csrf'] ?? ''))) {
        fcc_json(false, [], 'Sesion invalida. Actualiza la pagina.', 419);
    }

    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, ['update_driver_status', 'bulk_update_driver_status'], true)) {
        fcc_json(false, [], 'Accion no reconocida.', 400);
    }

    if ($action === 'bulk_update_driver_status') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || !$items) {
            fcc_json(false, [], 'No hay filas modificadas para guardar.', 422);
        }
        if (count($items) > 300) {
            fcc_json(false, [], 'Selecciona menos de 300 registros por guardado masivo.', 422);
        }

        $stmt = null;
        $transactionStarted = false;
        try {
            $payloads = array_map(static fn($item) => fcc_driver_update_payload(is_array($item) ? $item : []), $items);
            $conn->begin_transaction();
            $transactionStarted = true;

            $stmt = fcc_prepare_driver_update($conn);
            $idaVuelta = $viajeImporte = $viajeComentario = $cond1Estado = $cond1Importe = $cond1Obs = $cond2Estado = $cond2Importe = $cond2Obs = '';
            $id = 0;
            $stmt->bind_param('sssssssssi', $idaVuelta, $viajeImporte, $viajeComentario, $cond1Estado, $cond1Importe, $cond1Obs, $cond2Estado, $cond2Importe, $cond2Obs, $id);

            foreach ($payloads as $payload) {
                $idaVuelta = $payload['ida_vuelta'];
                $viajeImporte = $payload['viaje_importe'];
                $viajeComentario = $payload['viaje_comentario'];
                $cond1Estado = $payload['cond1_estado'];
                $cond1Importe = $payload['cond1_importe'];
                $cond1Obs = $payload['cond1_observacion'];
                $cond2Estado = $payload['cond2_estado'];
                $cond2Importe = $payload['cond2_importe'];
                $cond2Obs = $payload['cond2_observacion'];
                $id = $payload['id'];
                if (!$stmt->execute()) {
                    throw new RuntimeException($stmt->error ?: 'No se pudo guardar la gestion del conductor.');
                }
            }

            $stmt->close();
            $stmt = null;
            $conn->commit();
            $transactionStarted = false;

            fcc_json(true, [
                'actualizados' => count($payloads),
                'rows' => $payloads,
                'actualizado' => date('d/m/Y H:i'),
            ], count($payloads) . ' registro(s) actualizados correctamente.');
        } catch (InvalidArgumentException $e) {
            fcc_json(false, [], $e->getMessage(), 422);
        } catch (Throwable $e) {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            if ($transactionStarted) {
                $conn->rollback();
            }
            fcc_json(false, [], $e->getMessage(), 500);
        }
    }

    $stmt = null;
    try {
        $payload = fcc_driver_update_payload($_POST);
        $stmt = fcc_prepare_driver_update($conn);

        $idaVuelta = $payload['ida_vuelta'];
        $viajeImporte = $payload['viaje_importe'];
        $viajeComentario = $payload['viaje_comentario'];
        $cond1Estado = $payload['cond1_estado'];
        $cond1Importe = $payload['cond1_importe'];
        $cond1Obs = $payload['cond1_observacion'];
        $cond2Estado = $payload['cond2_estado'];
        $cond2Importe = $payload['cond2_importe'];
        $cond2Obs = $payload['cond2_observacion'];
        $id = $payload['id'];
        $stmt->bind_param('sssssssssi', $idaVuelta, $viajeImporte, $viajeComentario, $cond1Estado, $cond1Importe, $cond1Obs, $cond2Estado, $cond2Importe, $cond2Obs, $id);

        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error ?: 'No se pudo guardar la gestion del conductor.');
        }

        $stmt->close();
        $stmt = null;

        fcc_json(true, [
            'ida_vuelta' => $payload['ida_vuelta'],
            'viaje_importe' => $payload['viaje_importe'],
            'viaje_comentario' => $payload['viaje_comentario'],
            'cond1_estado' => $payload['cond1_estado'],
            'cond1_importe' => $payload['cond1_importe'],
            'cond2_estado' => $payload['cond2_estado'],
            'cond2_importe' => $payload['cond2_importe'],
            'actualizado' => date('d/m/Y H:i'),
        ], 'Estado, pago, comentario y observaciones actualizados.');
    } catch (InvalidArgumentException $e) {
        fcc_json(false, [], $e->getMessage(), 422);
    } catch (Throwable $e) {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        fcc_json(false, [], $e->getMessage(), 500);
    }
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
$hojaRutaCounts = [];
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
        ORDER BY clm_salprog_fecha_operativa ASC, clm_salprog_bus ASC, clm_salprog_hora_orden ASC, clm_salprog_horasalida ASC, clm_salprog_id ASC
    ', 'ss', [$monthStart, $monthEnd]);

    foreach ($rows as $row) {
        $hojaRutaKey = fcc_hojaruta_key($row['clm_salprog_hojaruta'] ?? '');
        if ($hojaRutaKey !== '') {
            $hojaRutaCounts[$hojaRutaKey] = ($hojaRutaCounts[$hojaRutaKey] ?? 0) + 1;
        }

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
    'programaciones' => 0,
    'anulados' => 0,
    'pagados' => 0,
    'pendientes' => 0,
];

$reportUnits = [];
foreach ($plates as $plateId => $plate) {
    $unitProgrammed = 0;
    $unitCanceled = 0;
    $unitPaid = 0;
    $unitPending = 0;
    $unitRows = [];

    foreach ($days as $day) {
        $date = $day['date'];
        $matches = $rowsByPlateDay[$plateId][$date] ?? [];
        $totalTripsDay = count($matches);

        if ($totalTripsDay === 0) {
            $unitRows[] = [
                'id' => 0,
                'date' => $date,
                'day' => $day['day'],
                'weekday' => $day['weekday'],
                'revision' => 'SIN SALIDA',
                'is_anulado' => false,
                'ida_vuelta' => '',
                'is_retorno' => false,
                'trip_index' => 0,
                'trips_day' => 0,
                'hora' => '',
                'fecha_salida_real' => '',
                'hora_orden' => null,
                'cierre_id' => null,
                'fecha_ejecucion' => '',
                'run_id' => '',
                'progid' => null,
                'idplaca' => (int)$plateId,
                'bus' => (string)($plate['bus'] ?? ''),
                'placa' => (string)($plate['placa'] ?? ''),
                'servicio' => '',
                'idorigen' => null,
                'origen' => '',
                'iddestino' => null,
                'destino' => '',
                'ruta_ids' => '',
                'ruta_texto' => '',
                'fecha_programacion' => '',
                'comentario_horario' => '',
                'conductores_texto' => '',
                'comentario_revision' => '',
                'correccion' => '',
                'hoja_ruta' => '',
                'hoja_ruta_validada' => false,
                'hoja_ruta_duplicada' => false,
                'usuario_revision' => null,
                'datetime_revision' => '',
                'usuario_creacion' => null,
                'fecha_creacion' => '',
                'viaje_importe' => '',
                'viaje_comentario' => '',
                'cond1' => '',
                'cond1_estado' => '',
                'cond1_importe' => '',
                'cond1_observacion' => '',
                'cond2' => '',
                'cond2_estado' => '',
                'cond2_importe' => '',
                'cond2_observacion' => '',
            ];
            continue;
        }

        foreach ($matches as $tripIndex => $row) {
            $conductores = fcc_conductores($row['clm_salprog_conductores_texto'] ?? '');
            $cond1 = $conductores[0] ?? '';
            $cond2 = $conductores[1] ?? '';
            $cond1Estado = fcc_conductor_estado($row['clm_salprog_cond1_estado'] ?? '', $cond1 !== '');
            $cond2Estado = fcc_conductor_estado($row['clm_salprog_cond2_estado'] ?? '', $cond2 !== '');
            $revision = fcc_estado_revision_label($row['clm_salprog_revision_estado'] ?? '');
            $isAnulado = fcc_revision_is_anulada($revision);
            $idaVuelta = fcc_ida_vuelta($row['clm_salprog_estadoidavuelta'] ?? 'PENDIENTE');
            $isRetorno = $idaVuelta === 'RETORNO';
            $hojaRuta = trim((string)($row['clm_salprog_hojaruta'] ?? ''));
            $hojaRutaKey = fcc_hojaruta_key($hojaRuta);
            $hojaRutaDuplicada = $hojaRutaKey !== '' && (int)($hojaRutaCounts[$hojaRutaKey] ?? 0) > 1;
            $hojaRutaValidada = $hojaRuta !== '' && !$hojaRutaDuplicada;

            if ($isAnulado) {
                $unitCanceled++;
                $kpis['anulados']++;
            } else {
                $unitProgrammed++;
                $kpis['programaciones']++;

                if (!$isRetorno && $cond1 !== '') {
                    if ($cond1Estado === 'PAGADO') {
                        $unitPaid++;
                        $kpis['pagados']++;
                    } else {
                        $unitPending++;
                        $kpis['pendientes']++;
                    }
                }

                if (!$isRetorno && $cond2 !== '') {
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
                'id' => (int)($row['clm_salprog_id'] ?? 0),
                'date' => $date,
                'day' => $day['day'],
                'weekday' => $day['weekday'],
                'revision' => $revision,
                'is_anulado' => $isAnulado,
                'ida_vuelta' => $idaVuelta,
                'is_retorno' => $isRetorno,
                'trip_index' => $tripIndex + 1,
                'trips_day' => $totalTripsDay,
                'hora' => fcc_time_label($row['clm_salprog_horasalida'] ?? ''),
                'fecha_salida_real' => fcc_real_departure_datetime($date, $row['clm_salprog_horasalida'] ?? ''),
                'hora_orden' => isset($row['clm_salprog_hora_orden']) ? (int)$row['clm_salprog_hora_orden'] : null,
                'cierre_id' => isset($row['clm_salprog_cierre_id']) ? (int)$row['clm_salprog_cierre_id'] : null,
                'fecha_ejecucion' => (string)($row['clm_salprog_fecha_ejecucion'] ?? ''),
                'run_id' => (string)($row['clm_salprog_run_id'] ?? ''),
                'progid' => isset($row['clm_salprog_progid']) ? (int)$row['clm_salprog_progid'] : null,
                'idplaca' => isset($row['clm_salprog_idplaca']) ? (int)$row['clm_salprog_idplaca'] : (int)$plateId,
                'bus' => (string)($row['clm_salprog_bus'] ?? $plate['bus'] ?? ''),
                'placa' => (string)($row['clm_salprog_placa'] ?? $plate['placa'] ?? ''),
                'servicio' => (string)($row['clm_salprog_servicio'] ?? ''),
                'idorigen' => isset($row['clm_salprog_idorigen']) ? (int)$row['clm_salprog_idorigen'] : null,
                'origen' => (string)($row['clm_salprog_origen'] ?? ''),
                'iddestino' => isset($row['clm_salprog_iddestino']) ? (int)$row['clm_salprog_iddestino'] : null,
                'destino' => (string)($row['clm_salprog_destino'] ?? ''),
                'ruta_ids' => (string)($row['clm_salprog_ruta_ids'] ?? ''),
                'ruta_texto' => (string)($row['clm_salprog_ruta_texto'] ?? ''),
                'fecha_programacion' => (string)($row['clm_salprog_fecha_programacion'] ?? ''),
                'comentario_horario' => (string)($row['clm_salprog_comentario_horario'] ?? ''),
                'conductores_texto' => (string)($row['clm_salprog_conductores_texto'] ?? ''),
                'comentario_revision' => (string)($row['clm_salprog_comentario_revision'] ?? ''),
                'correccion' => (string)($row['clm_salprog_correccion'] ?? ''),
                'hoja_ruta' => $hojaRuta,
                'hoja_ruta_validada' => $hojaRutaValidada,
                'hoja_ruta_duplicada' => $hojaRutaDuplicada,
                'usuario_revision' => isset($row['clm_salprog_usuario_revision']) ? (int)$row['clm_salprog_usuario_revision'] : null,
                'datetime_revision' => (string)($row['clm_salprog_datetime_revision'] ?? ''),
                'usuario_creacion' => isset($row['clm_salprog_usuario_creacion']) ? (int)$row['clm_salprog_usuario_creacion'] : null,
                'fecha_creacion' => (string)($row['clm_salprog_fecha_creacion'] ?? ''),
                'viaje_importe' => fcc_importe_input($row['clm_salprog_imtotaldelviaje'] ?? null),
                'viaje_comentario' => (string)($row['clm_salprog_comentariocontroldelviaje'] ?? ''),
                'cond1' => $cond1,
                'cond1_estado' => $isRetorno ? '' : $cond1Estado,
                'cond1_importe' => $isRetorno ? '' : fcc_importe_input($row['clm_salprog_imtotalcond1'] ?? null),
                'cond1_observacion' => (string)($row['clm_salprog_cond1_observacion'] ?? ''),
                'cond2' => $cond2,
                'cond2_estado' => $isRetorno ? '' : $cond2Estado,
                'cond2_importe' => $isRetorno ? '' : fcc_importe_input($row['clm_salprog_imtotalcond2'] ?? null),
                'cond2_observacion' => (string)($row['clm_salprog_cond2_observacion'] ?? ''),
            ];
        }
    }

    $reportUnits[] = [
        'id' => (int)$plateId,
        'label' => (string)($plate['label'] ?? ''),
        'bus' => (string)($plate['bus'] ?? ''),
        'placa' => (string)($plate['placa'] ?? ''),
        'programmed' => $unitProgrammed,
        'canceled' => $unitCanceled,
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
    <link rel="stylesheet" href="<?= htmlspecialchars(n360_asset_url('assets/css/flota_control_conductores_salidas_n360.css') . '&ctrl=comentario-viaje-1', ENT_QUOTES, 'UTF-8') ?>">
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
                <button type="button" class="fcc-btn fcc-btn--soft" data-fcc-driver-summary><i class="bi bi-people-fill"></i> Resumen conductores</button>
                <button type="button" class="fcc-btn fcc-btn--soft" data-fcc-canceled-summary><i class="bi bi-slash-circle"></i> Anulados <span data-fcc-canceled-count><?= number_format($kpis['anulados']) ?></span></button>
                <button type="button" class="fcc-btn fcc-btn--soft" data-fcc-export-payments-pdf><i class="bi bi-cash-coin"></i> PDF pagos</button>
                <button type="button" class="fcc-btn fcc-btn--soft" data-fcc-export-payments-excel><i class="bi bi-file-earmark-spreadsheet"></i> Excel pagos</button>
                <button type="button" class="fcc-btn fcc-btn--primary" data-fcc-export-all><i class="bi bi-file-earmark-pdf"></i> PDF consolidado</button>
                <a class="fcc-btn fcc-btn--soft" href="consolidado_salidas_buses.php"><i class="bi bi-arrow-left"></i> Consolidado</a>
            </div>
        </section>

        <?php if (!$driverColumnsReady): ?>
            <div class="fcc-alert fcc-alert--warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Ejecuta la query ALTER para habilitar estado, importe total, comentario y observacion por conductor. La vista puede consultarse, pero no guardara cambios hasta tener esas columnas.
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
            <article><span>Viajes</span><strong><?= number_format($kpis['programaciones']) ?></strong></article>
            <article><span>Anulados</span><strong><?= number_format($kpis['anulados']) ?></strong></article>
            <article><span>Pendientes</span><strong><?= number_format($kpis['pendientes']) ?></strong></article>
            <article><span>OK</span><strong><?= number_format($kpis['pagados']) ?></strong></article>
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

        <section class="fcc-bulk-panel <?= $driverColumnsReady ? 'is-active' : '' ?>" data-fcc-bulk-panel>
            <div class="fcc-bulk-panel__info">
                <span class="fcc-bulk-icon"><i class="bi bi-pencil-square"></i></span>
                <div>
                    <strong>Editar masivo</strong>
                    <small>Modo activo para modificar varias filas y guardar todo en una sola confirmacion.</small>
                </div>
            </div>
            <div class="fcc-bulk-panel__actions">
                <span class="fcc-bulk-count" data-fcc-bulk-count>0 filas modificadas</span>
                <button type="button" class="fcc-btn <?= $driverColumnsReady ? 'fcc-btn--primary' : 'fcc-btn--soft' ?>" data-fcc-bulk-toggle <?= $driverColumnsReady ? '' : 'disabled' ?>><i class="bi <?= $driverColumnsReady ? 'bi-toggles2' : 'bi-toggles' ?>"></i> <?= $driverColumnsReady ? 'Desactivar masivo' : 'Activar masivo' ?></button>
                <button type="button" class="fcc-btn fcc-btn--primary" data-fcc-bulk-save disabled><i class="bi bi-save2"></i> Guardar cambios</button>
                <button type="button" class="fcc-btn fcc-btn--soft" data-fcc-bulk-cancel disabled><i class="bi bi-x-circle"></i> Cancelar cambios</button>
            </div>
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
                                <small><?= number_format($unit['programmed']) ?> viajes contables<?= (int)$unit['canceled'] > 0 ? ' / ' . number_format($unit['canceled']) . ' anulados' : '' ?> en <?= fcc_h($monthLabel) ?></small>
                            </span>
                        </button>
                        <div class="fcc-unit-stats">
                            <?php if ((int)$unit['canceled'] > 0): ?>
                                <span class="fcc-mini fcc-mini--void">Anul. <?= number_format($unit['canceled']) ?></span>
                            <?php endif; ?>
                            <span class="fcc-mini fcc-mini--pending">Pend. <?= number_format($unit['pending']) ?></span>
                            <span class="fcc-mini fcc-mini--paid">OK <?= number_format($unit['paid']) ?></span>
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
                                        <th>Ida/Vuelta</th>
                                        <th>Ruta</th>
                                        <th>Importe viaje</th>
                                        <th>Comentario viaje</th>
                                        <th>Cond. 1</th>
                                        <th>Estado cond. 1</th>
                                        <th>Pago cond. 1</th>
                                        <th>Obs. cond. 1</th>
                                        <th>Cond. 2</th>
                                        <th>Estado cond. 2</th>
                                        <th>Pago cond. 2</th>
                                        <th>Obs. cond. 2</th>
                                        <th>Accion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $fccDateRowCounts = [];
                                        foreach ($unit['rows'] as $fccCountRow) {
                                            $fccDateKey = (string)($fccCountRow['date'] ?? '');
                                            if ($fccDateKey !== '') {
                                                $fccDateRowCounts[$fccDateKey] = ($fccDateRowCounts[$fccDateKey] ?? 0) + 1;
                                            }
                                        }
                                        $fccRenderedDates = [];
                                    ?>
                                    <?php foreach ($unit['rows'] as $unitRow): ?>
                                        <?php
                                            $hasSchedule = (int)$unitRow['id'] > 0;
                                            $isAnulado = !empty($unitRow['is_anulado']);
                                            $isRetorno = !empty($unitRow['is_retorno']);
                                            $cond1Enabled = $hasSchedule && !$isAnulado && !$isRetorno && $unitRow['cond1'] !== '' && $driverColumnsReady;
                                            $cond2Enabled = $hasSchedule && !$isAnulado && !$isRetorno && $unitRow['cond2'] !== '' && $driverColumnsReady;
                                            $cond1ObsEnabled = $hasSchedule && !$isAnulado && $unitRow['cond1'] !== '' && $driverColumnsReady;
                                            $cond2ObsEnabled = $hasSchedule && !$isAnulado && $unitRow['cond2'] !== '' && $driverColumnsReady;
                                            $viajeImporteEnabled = $hasSchedule && !$isAnulado && $driverColumnsReady;
                                            $viajeComentarioEnabled = $hasSchedule && !$isAnulado && $driverColumnsReady;

                                            $fccDateKey = (string)($unitRow['date'] ?? '');
                                            $fccShowDate = !isset($fccRenderedDates[$fccDateKey]);
                                            $fccDateRowspan = max(1, (int)($fccDateRowCounts[$fccDateKey] ?? 1));
                                            $fccIsGroupStart = $fccShowDate;
                                            $fccIsGroupEnd = ((int)$unitRow['trip_index'] >= (int)$unitRow['trips_day']) || !$hasSchedule;
                                            $fccRowOrigen = trim((string)($unitRow['origen'] ?? ''));
                                            $fccRowDestino = trim((string)($unitRow['destino'] ?? ''));
                                            $fccHasRoute = $hasSchedule && ($fccRowOrigen !== '' || $fccRowDestino !== '');
                                            $fccIdaVueltaClass = $unitRow['ida_vuelta'] === 'RETORNO'
                                                ? 'is-return'
                                                : ($unitRow['ida_vuelta'] === 'IDA' ? 'is-outbound' : 'is-pending');

                                            if ($fccShowDate) {
                                                $fccRenderedDates[$fccDateKey] = true;
                                            }

                                            $fccRowClasses = [];
                                            if (!$hasSchedule) {
                                                $fccRowClasses[] = 'is-empty-day';
                                            } else {
                                                if ($isAnulado) {
                                                    $fccRowClasses[] = 'is-anulado';
                                                }
                                                if ($isRetorno) {
                                                    $fccRowClasses[] = 'is-retorno';
                                                }
                                                if (!empty($unitRow['hoja_ruta_validada'])) {
                                                    $fccRowClasses[] = 'has-hojaruta';
                                                }
                                                if (!empty($unitRow['hoja_ruta_duplicada'])) {
                                                    $fccRowClasses[] = 'has-hojaruta-warning';
                                                }
                                                if ((int)$unitRow['trips_day'] > 1) {
                                                    $fccRowClasses[] = 'has-multiple-trips';
                                                }
                                            }
                                            if ($fccIsGroupStart) {
                                                $fccRowClasses[] = 'fcc-date-group-start';
                                            }
                                            if ($fccIsGroupEnd) {
                                                $fccRowClasses[] = 'fcc-date-group-end';
                                            }
                                        ?>
                                        <tr
                                            data-fcc-row="<?= (int)$unitRow['id'] ?>"
                                            data-fcc-date="<?= fcc_h($unitRow['date']) ?>"
                                            data-fcc-day="<?= fcc_h($unitRow['day']) ?>"
                                            data-fcc-weekday="<?= fcc_h($unitRow['weekday']) ?>"
                                            data-fcc-trip-index="<?= (int)$unitRow['trip_index'] ?>"
                                            data-fcc-trips-day="<?= (int)$unitRow['trips_day'] ?>"
                                            data-fcc-hora="<?= fcc_h($unitRow['hora']) ?>"
                                            data-fcc-revision="<?= fcc_h($unitRow['revision']) ?>"
                                            data-fcc-origen="<?= fcc_h($fccRowOrigen) ?>"
                                            data-fcc-destino="<?= fcc_h($fccRowDestino) ?>"
                                            data-fcc-hojaruta="<?= fcc_h($unitRow['hoja_ruta']) ?>"
                                            data-fcc-hojaruta-validada="<?= !empty($unitRow['hoja_ruta_validada']) ? '1' : '0' ?>"
                                            data-fcc-hojaruta-duplicada="<?= !empty($unitRow['hoja_ruta_duplicada']) ? '1' : '0' ?>"
                                            data-fcc-anulado="<?= $isAnulado ? '1' : '0' ?>"
                                            data-fcc-retorno="<?= $isRetorno ? '1' : '0' ?>"
                                            data-fcc-editable="<?= ($driverColumnsReady && $hasSchedule && !$isAnulado) ? '1' : '0' ?>"
                                            data-fcc-cond1="<?= $unitRow['cond1'] !== '' ? '1' : '0' ?>"
                                            data-fcc-cond2="<?= $unitRow['cond2'] !== '' ? '1' : '0' ?>"
                                            class="<?= fcc_h(implode(' ', $fccRowClasses)) ?>"
                                        >
                                            <?php if ($fccShowDate): ?>
                                                <td data-fcc-col="dia" rowspan="<?= $fccDateRowspan ?>" class="<?= $fccDateRowspan > 1 ? 'fcc-date-group-cell' : '' ?>">
                                                    <strong><?= fcc_h($unitRow['day']) ?></strong>
                                                    <span><?= fcc_h($unitRow['weekday']) ?></span>
                                                    <?php if ((int)$unitRow['trips_day'] > 1): ?>
                                                        <small class="fcc-date-trip-count"><?= (int)$unitRow['trips_day'] ?> viajes</small>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td data-fcc-col="revision">
                                                <span class="fcc-status <?= fcc_estado_revision_class($unitRow['revision']) ?>"><?= fcc_h($unitRow['revision']) ?></span>
                                                <?php if ($hasSchedule): ?>
                                                    <small class="fcc-trip-detail">
                                                        <span>Viaje <?= (int)$unitRow['trip_index'] ?><?= (int)$unitRow['trips_day'] > 1 ? ' de ' . (int)$unitRow['trips_day'] : '' ?></span>
                                                        <?php if ($unitRow['hora'] !== ''): ?>
                                                            <span><i class="bi bi-clock"></i> <?= fcc_h($unitRow['hora']) ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($unitRow['hoja_ruta_validada'])): ?>
                                                            <span class="fcc-hojaruta-shield" title="Hoja de ruta validada: <?= fcc_h($unitRow['hoja_ruta']) ?>" aria-label="Hoja de ruta validada">
                                                                <i class="bi bi-shield-check"></i> HR
                                                            </span>
                                                        <?php elseif (!empty($unitRow['hoja_ruta_duplicada'])): ?>
                                                            <span class="fcc-hojaruta-shield fcc-hojaruta-shield--warn" title="Hoja de ruta duplicada: <?= fcc_h($unitRow['hoja_ruta']) ?>" aria-label="Hoja de ruta duplicada">
                                                                <i class="bi bi-shield-exclamation"></i> HR
                                                            </span>
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="ida_vuelta">
                                                <?php if ($hasSchedule && !$isAnulado): ?>
                                                    <select data-fcc-field="ida_vuelta" class="fcc-roundtrip <?= fcc_h($fccIdaVueltaClass) ?>" <?= $driverColumnsReady ? '' : 'disabled' ?>>
                                                        <option value="PENDIENTE" <?= $unitRow['ida_vuelta'] === 'PENDIENTE' ? 'selected' : '' ?>>PENDIENTE</option>
                                                        <option value="IDA" <?= $unitRow['ida_vuelta'] === 'IDA' ? 'selected' : '' ?>>IDA</option>
                                                        <option value="RETORNO" <?= $unitRow['ida_vuelta'] === 'RETORNO' ? 'selected' : '' ?>>RETORNO</option>
                                                    </select>
                                                <?php elseif ($hasSchedule): ?>
                                                    <span class="fcc-roundtrip-badge"><?= fcc_h($unitRow['ida_vuelta'] ?: '-') ?></span>
                                                <?php else: ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="ruta">
                                                <?php if ($fccHasRoute): ?>
                                                    <span class="fcc-route-line">
                                                        <strong><?= fcc_h($fccRowOrigen !== '' ? $fccRowOrigen : '-') ?></strong>
                                                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                                        <strong><?= fcc_h($fccRowDestino !== '' ? $fccRowDestino : '-') ?></strong>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="viaje_importe" class="fcc-total-cell">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <label class="fcc-money-field fcc-total-field">
                                                        <span><strong>S/</strong><small>VIAJE</small></span>
                                                        <input type="number" min="0" max="9999999999999999.9999" step="0.0001" inputmode="decimal" aria-label="Importe total del viaje en soles" data-fcc-field="viaje_importe" value="<?= fcc_h($unitRow['viaje_importe']) ?>" placeholder="0.00" <?= $viajeImporteEnabled ? '' : 'disabled' ?>>
                                                    </label>
                                                    <small class="fcc-total-diff" data-fcc-total-diff>Esperando pagos</small>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="viaje_comentario" class="fcc-trip-comment-cell">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <textarea data-fcc-field="viaje_comentario" rows="1" maxlength="1000" placeholder="Comentario del viaje..." <?= $viajeComentarioEnabled ? '' : 'disabled' ?>><?= fcc_h($unitRow['viaje_comentario']) ?></textarea>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="cond1"><?= (!$isAnulado && $unitRow['cond1'] !== '') ? fcc_h($unitRow['cond1']) : '<span class="fcc-muted">-</span>' ?></td>
                                            <td data-fcc-col="cond1_estado">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <select data-fcc-field="cond1_estado" class="<?= fcc_conductor_class($unitRow['cond1_estado']) ?>" <?= $cond1Enabled ? '' : 'disabled' ?>>
                                                        <option value="" <?= $unitRow['cond1_estado'] === '' ? 'selected' : '' ?>>-</option>
                                                        <option value="PENDIENTE" <?= $unitRow['cond1_estado'] === 'PENDIENTE' ? 'selected' : '' ?>>PENDIENTE</option>
                                                        <option value="PAGADO" <?= $unitRow['cond1_estado'] === 'PAGADO' ? 'selected' : '' ?>>OK</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="cond1_pago">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <label class="fcc-money-field">
                                                        <span><strong>S/</strong><small>PEN</small></span>
                                                        <input type="number" min="0" max="9999999999999999.9999" step="0.0001" inputmode="decimal" aria-label="Pago conductor 1 en soles" data-fcc-field="cond1_importe" value="<?= fcc_h($unitRow['cond1_importe']) ?>" placeholder="0.00" <?= $cond1Enabled ? '' : 'disabled' ?>>
                                                    </label>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="cond1_obs">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <textarea data-fcc-field="cond1_observacion" rows="1" <?= $cond1ObsEnabled ? '' : 'disabled' ?>><?= fcc_h($unitRow['cond1_observacion']) ?></textarea>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="cond2"><?= (!$isAnulado && $unitRow['cond2'] !== '') ? fcc_h($unitRow['cond2']) : '<span class="fcc-muted">-</span>' ?></td>
                                            <td data-fcc-col="cond2_estado">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <select data-fcc-field="cond2_estado" class="<?= fcc_conductor_class($unitRow['cond2_estado']) ?>" <?= $cond2Enabled ? '' : 'disabled' ?>>
                                                        <option value="" <?= $unitRow['cond2_estado'] === '' ? 'selected' : '' ?>>-</option>
                                                        <option value="PENDIENTE" <?= $unitRow['cond2_estado'] === 'PENDIENTE' ? 'selected' : '' ?>>PENDIENTE</option>
                                                        <option value="PAGADO" <?= $unitRow['cond2_estado'] === 'PAGADO' ? 'selected' : '' ?>>OK</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                            <td data-fcc-col="cond2_pago">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <label class="fcc-money-field">
                                                        <span><strong>S/</strong><small>PEN</small></span>
                                                        <input type="number" min="0" max="9999999999999999.9999" step="0.0001" inputmode="decimal" aria-label="Pago conductor 2 en soles" data-fcc-field="cond2_importe" value="<?= fcc_h($unitRow['cond2_importe']) ?>" placeholder="0.00" <?= $cond2Enabled ? '' : 'disabled' ?>>
                                                    </label>
                                                <?php endif; ?>
                                            </td>                                            
                                            <td data-fcc-col="cond2_obs">
                                                <?php if ($isAnulado): ?>
                                                    <span class="fcc-muted">-</span>
                                                <?php else: ?>
                                                    <textarea data-fcc-field="cond2_observacion" rows="1" <?= $cond2ObsEnabled ? '' : 'disabled' ?>><?= fcc_h($unitRow['cond2_observacion']) ?></textarea>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fcc-actions">
                                                <?php if ($hasSchedule): ?>
                                                    <div class="fcc-action-buttons">
                                                        <button type="button" class="fcc-icon-detail" data-fcc-view-trip data-fcc-trip-id="<?= (int)$unitRow['id'] ?>" title="Ver detalle del viaje" aria-label="Ver detalle del viaje"><i class="bi bi-eye-fill"></i></button>
                                                        <?php if (!$isAnulado): ?>
                                                            <button type="button" class="fcc-icon-save" data-fcc-save <?= $driverColumnsReady ? '' : 'disabled' ?> title="Guardar estados, pagos y observaciones" aria-label="Guardar estados, pagos y observaciones"><i class="bi bi-save2"></i></button>
                                                        <?php endif; ?>
                                                    </div>
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

<div class="modal fade fcc-driver-modal" id="fccDriverSummaryModal" tabindex="-1" aria-labelledby="fccDriverSummaryTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="fcc-modal-eyebrow"><i class="bi bi-people-fill"></i> Conductores filtrados</span>
                    <h2 class="modal-title" id="fccDriverSummaryTitle">Resumen mensual de conductores</h2>
                    <p><?= fcc_h($monthLabel) ?> segun las unidades visibles en pantalla.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="fcc-driver-kpis">
                    <article><span>Conductores</span><strong data-fcc-driver-kpi="drivers">0</strong></article>
                    <article><span>Viajes</span><strong data-fcc-driver-kpi="trips">0</strong></article>
                    <article><span>Buses usados</span><strong data-fcc-driver-kpi="buses">0</strong></article>
                    <article><span>Pendientes</span><strong data-fcc-driver-kpi="pending">0</strong></article>
                    <article><span>OK</span><strong data-fcc-driver-kpi="paid">0</strong></article>
                </div>

                <label class="fcc-driver-search">
                    <span>Buscar conductor</span>
                    <input type="search" data-fcc-driver-search placeholder="Nombre, DNI o bus...">
                </label>

                <div class="fcc-driver-table-wrap">
                    <table class="fcc-driver-table">
                        <thead>
                            <tr>
                                <th>Conductor</th>
                                <th>Viajes</th>
                                <th>Buses</th>
                                <th>Pendientes</th>
                                <th>OK</th>
                                <th>Obs.</th>
                            </tr>
                        </thead>
                        <tbody data-fcc-driver-summary-body>
                            <tr>
                                <td colspan="6" class="fcc-driver-empty">Abre el resumen para calcular los conductores visibles.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal fade fcc-driver-modal fcc-canceled-modal" id="fccCanceledTripsModal" tabindex="-1" aria-labelledby="fccCanceledTripsTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="fcc-modal-eyebrow"><i class="bi bi-slash-circle-fill"></i> Viajes no contables</span>
                    <h2 class="modal-title" id="fccCanceledTripsTitle">Viajes anulados</h2>
                    <p><?= fcc_h($monthLabel) ?> segun las unidades visibles en pantalla.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="fcc-driver-kpis fcc-canceled-kpis">
                    <article><span>Anulados</span><strong data-fcc-canceled-kpi="trips">0</strong></article>
                    <article><span>Unidades</span><strong data-fcc-canceled-kpi="units">0</strong></article>
                    <article><span>Conductores</span><strong data-fcc-canceled-kpi="drivers">0</strong></article>
                </div>

                <label class="fcc-driver-search">
                    <span>Buscar anulado</span>
                    <input type="search" data-fcc-canceled-search placeholder="Unidad, conductor, ruta o fecha...">
                </label>

                <div class="fcc-driver-table-wrap">
                    <table class="fcc-driver-table fcc-canceled-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Unidad</th>
                                <th>Horario</th>
                                <th>Ruta</th>
                                <th>Conductores</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody data-fcc-canceled-body>
                            <tr>
                                <td colspan="6" class="fcc-driver-empty">Abre anulados para calcular los viajes visibles.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal fade fcc-driver-modal fcc-payment-range-modal" id="fccPaymentRangeModal" tabindex="-1" aria-labelledby="fccPaymentRangeTitle" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="fcc-modal-eyebrow"><i class="bi bi-calendar-range-fill"></i> Pagos visibles</span>
                    <h2 class="modal-title" id="fccPaymentRangeTitle">Exportar pagos</h2>
                    <p>Selecciona el rango que se aplicara sobre las unidades visibles en pantalla.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="fcc-payment-range-grid">
                    <label>
                        <span>Desde</span>
                        <input type="date" data-fcc-payment-from>
                    </label>
                    <label>
                        <span>Hasta</span>
                        <input type="date" data-fcc-payment-to>
                    </label>
                </div>
                <div class="fcc-payment-range-help">
                    <i class="bi bi-info-circle"></i>
                    <span>Solo se exportan pagos visibles, no anulados y que no sean retorno.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="fcc-btn fcc-btn--soft" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="button" class="fcc-btn fcc-btn--primary" data-fcc-payment-confirm><i class="bi bi-download"></i> Descargar</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade fcc-trip-modal" id="fccTripDetailModal" tabindex="-1" aria-labelledby="fccTripDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="fcc-modal-eyebrow"><i class="bi bi-signpost-split-fill"></i> Detalle operativo</span>
                    <h2 class="modal-title" id="fccTripDetailTitle" data-fcc-trip-title>Detalle del viaje</h2>
                    <p data-fcc-trip-subtitle>Consulta completa del registro consolidado.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div class="fcc-trip-highlight">
                    <div>
                        <span>Día operativo</span>
                        <strong data-fcc-trip-field="fecha_operativa">-</strong>
                    </div>
                    <div>
                        <span>Salida real</span>
                        <strong data-fcc-trip-field="fecha_salida_real">-</strong>
                    </div>
                    <div>
                        <span>Hora mostrada</span>
                        <strong data-fcc-trip-field="horasalida">-</strong>
                    </div>
                    <div>
                        <span>Estado de revisión</span>
                        <strong><span class="fcc-status fcc-status--pending" data-fcc-trip-status>PENDIENTE</span></strong>
                    </div>
                </div>

                <section class="fcc-trip-section">
                    <div class="fcc-trip-section-title">
                        <i class="bi bi-fingerprint"></i>
                        <div><strong>Identificación operativa</strong><small>Claves y trazabilidad del consolidado.</small></div>
                    </div>
                    <div class="fcc-trip-detail-grid">
                        <article><span>ID consolidado</span><strong data-fcc-trip-field="id">-</strong></article>
                        <article><span>ID cierre</span><strong data-fcc-trip-field="cierre_id">-</strong></article>
                        <article><span>ID programación</span><strong data-fcc-trip-field="progid">-</strong></article>
                        <article><span>Run ID</span><strong data-fcc-trip-field="run_id">-</strong></article>
                        <article><span>Fecha de ejecución</span><strong data-fcc-trip-field="fecha_ejecucion">-</strong></article>
                        <article><span>Orden operativo</span><strong data-fcc-trip-field="hora_orden">-</strong></article>
                    </div>
                </section>

                <section class="fcc-trip-section">
                    <div class="fcc-trip-section-title">
                        <i class="bi bi-bus-front-fill"></i>
                        <div><strong>Unidad, ruta y horario</strong><small>La hora visible corresponde a la hora de la programación en pizarra.</small></div>
                    </div>
                    <div class="fcc-trip-detail-grid">
                        <article><span>Bus</span><strong data-fcc-trip-field="bus">-</strong></article>
                        <article><span>Placa</span><strong data-fcc-trip-field="placa">-</strong></article>
                        <article><span>Servicio</span><strong data-fcc-trip-field="servicio">-</strong></article>
                        <article><span>Ida/Vuelta</span><strong data-fcc-trip-field="ida_vuelta">-</strong></article>
                        <article><span>Origen</span><strong data-fcc-trip-field="origen">-</strong></article>
                        <article><span>Destino</span><strong data-fcc-trip-field="destino">-</strong></article>
                        <article><span>Programado en pizarra</span><strong data-fcc-trip-field="fecha_programacion">-</strong></article>
                        <article><span>Hoja de ruta</span><strong data-fcc-trip-field="hoja_ruta">-</strong></article>
                        <article><span>Estado hoja ruta</span><strong data-fcc-trip-field="hoja_ruta_estado">-</strong></article>
                        <article class="fcc-trip-detail-wide"><span>Ruta consolidada</span><strong data-fcc-trip-field="ruta_texto">-</strong></article>
                        <article class="fcc-trip-detail-wide"><span>Comentario del horario</span><strong data-fcc-trip-field="comentario_horario">-</strong></article>
                    </div>
                </section>

                <section class="fcc-trip-section">
                    <div class="fcc-trip-section-title">
                        <i class="bi bi-people-fill"></i>
                        <div><strong>Conductores y pagos</strong><small>Gestión independiente correspondiente a este viaje.</small></div>
                    </div>
                    <div class="fcc-trip-drivers">
                        <article>
                            <div class="fcc-trip-driver-head"><span>Total del viaje</span><strong data-fcc-trip-field="viaje_importe">-</strong></div>
                            <div class="fcc-trip-driver-meta">
                                <span>Debe cuadrar con la suma de conductores</span>
                            </div>
                            <p data-fcc-trip-field="viaje_importe_estado">-</p>
                        </article>
                        <article>
                            <div class="fcc-trip-driver-head"><span>Comentario viaje</span><strong>Control</strong></div>
                            <p data-fcc-trip-field="viaje_comentario">-</p>
                        </article>
                        <article>
                            <div class="fcc-trip-driver-head"><span>Conductor 1</span><strong data-fcc-trip-field="cond1">-</strong></div>
                            <div class="fcc-trip-driver-meta">
                                <span>Estado: <strong data-fcc-trip-field="cond1_estado">-</strong></span>
                                <span>Pago: <strong data-fcc-trip-field="cond1_importe">-</strong></span>
                            </div>
                            <p data-fcc-trip-field="cond1_observacion">-</p>
                        </article>
                        <article>
                            <div class="fcc-trip-driver-head"><span>Conductor 2</span><strong data-fcc-trip-field="cond2">-</strong></div>
                            <div class="fcc-trip-driver-meta">
                                <span>Estado: <strong data-fcc-trip-field="cond2_estado">-</strong></span>
                                <span>Pago: <strong data-fcc-trip-field="cond2_importe">-</strong></span>
                            </div>
                            <p data-fcc-trip-field="cond2_observacion">-</p>
                        </article>
                    </div>
                </section>

                <section class="fcc-trip-section">
                    <div class="fcc-trip-section-title">
                        <i class="bi bi-clipboard2-check-fill"></i>
                        <div><strong>Revisión y auditoría</strong><small>Observaciones, correcciones y usuarios relacionados.</small></div>
                    </div>
                    <div class="fcc-trip-detail-grid">
                        <article class="fcc-trip-detail-wide"><span>Comentario de revisión</span><strong data-fcc-trip-field="comentario_revision">-</strong></article>
                        <article class="fcc-trip-detail-wide"><span>Corrección</span><strong data-fcc-trip-field="correccion">-</strong></article>
                        <article><span>Usuario de revisión</span><strong data-fcc-trip-field="usuario_revision">-</strong></article>
                        <article><span>Fecha de revisión</span><strong data-fcc-trip-field="datetime_revision">-</strong></article>
                        <article><span>Usuario de creación</span><strong data-fcc-trip-field="usuario_creacion">-</strong></article>
                        <article><span>Fecha de creación</span><strong data-fcc-trip-field="fecha_creacion">-</strong></article>
                    </div>
                </section>
            </div>
        </div>
    </div>
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
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= htmlspecialchars(n360_asset_url('assets/js/flota_control_conductores_salidas_n360.js') . '&ctrl=split-viaje-1', ENT_QUOTES, 'UTF-8') ?>"></script>
<?php n360_render_footer(); ?>
</body>
</html>
