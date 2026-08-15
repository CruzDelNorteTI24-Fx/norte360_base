<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

if (!n360_puede_modulo(10) || (!n360_puede_vista('f-consalbus') && !n360_puede_vista('f-proghist'))) {
    header("Location: ../login/none_permisos.php");
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

mysqli_report(MYSQLI_REPORT_OFF);

function csb_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csb_uid(): int {
    if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
        return (int)$_SESSION['id_usuario'];
    }
    if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
        return (int)$_SESSION['web_id_usuario'];
    }
    return 1;
}

function csb_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e): void {
        csb_json(false, [], 'No se pudo completar la accion: ' . $e->getMessage(), 500);
    });
}

function csb_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function csb_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function csb_column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function csb_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }
    csb_bind($stmt, $types, $params);
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

function csb_valid_date($value, string $fallback): string {
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

function csb_date_label(?string $value, string $format = 'd/m/Y'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : $value;
}

function csb_hora_label(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('H:i', $time) : substr($value, 0, 5);
}

function csb_estado_class(string $estado): string {
    $estado = strtoupper(trim($estado));
    if ($estado === 'VALIDADO') {
        return 'csb-status--ok';
    }
    if ($estado === 'OBSERVADO') {
        return 'csb-status--warn';
    }
    if ($estado === 'CORREGIDO') {
        return 'csb-status--info';
    }
    if ($estado === 'ANULADO') {
        return 'csb-status--danger';
    }
    if ($estado === 'MANUAL') {
        return 'csb-status--manual';
    }
    if ($estado === 'TRANSBORDADO') {
        return 'csb-status--transbordado';
    }
    if ($estado === 'TRANSBORDO') {
        return 'csb-status--transbordo';
    }
    return 'csb-status--pending';
}

function csb_normalize_time(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ':00';
    }
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $value, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ':' . $m[3];
    }
    return null;
}

function csb_hora_orden(?string $value): int {
    $time = csb_normalize_time($value);
    if ($time === null) {
        return 9999;
    }
    [$h, $m] = array_map('intval', explode(':', $time));
    return ($h * 60) + $m;
}

function csb_sede_label(array $row): string {
    $abr = trim((string)($row['abr'] ?? ''));
    $nombre = trim((string)($row['nombre'] ?? ''));
    if ($abr !== '' && $nombre !== '' && strcasecmp($abr, $nombre) !== 0) {
        return $abr;
    }
    return $nombre !== '' ? $nombre : $abr;
}

function csb_fetch_sedes_by_ids(mysqli $conn, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if (!$ids) {
        return [];
    }

    $abrSelect = csb_column_exists($conn, 'tb_sedes', 'clm_sedes_abr') ? "IFNULL(s.clm_sedes_abr, '') AS abr" : "'' AS abr";
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = csb_fetch_all(
        $conn,
        "SELECT s.clm_sedes_id AS id, {$abrSelect}, IFNULL(s.clm_sedes_name, '') AS nombre FROM tb_sedes s WHERE s.clm_sedes_id IN ({$placeholders})",
        str_repeat('i', count($ids)),
        $ids
    );

    $map = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = csb_sede_label($row);
        }
    }
    return $map;
}

function csb_fetch_manual_catalog(mysqli $conn): array {
    $servicioSelect = csb_column_exists($conn, 'tb_placas', 'clm_placas_servicio') ? "IFNULL(p.clm_placas_servicio, '') AS servicio" : "'' AS servicio";
    $placas = csb_fetch_all(
        $conn,
        "SELECT p.clm_placas_id AS id,
                IFNULL(p.clm_placas_BUS, '') AS bus,
                IFNULL(p.clm_placas_PLACA, '') AS placa,
                {$servicioSelect}
           FROM tb_placas p
          WHERE UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          ORDER BY CAST(IFNULL(p.clm_placas_BUS, '999999') AS UNSIGNED), p.clm_placas_BUS, p.clm_placas_PLACA"
    );

    $abrSelect = csb_column_exists($conn, 'tb_sedes', 'clm_sedes_abr') ? "IFNULL(s.clm_sedes_abr, '') AS abr" : "'' AS abr";
    $estadoWhere = csb_column_exists($conn, 'tb_sedes', 'clm_sedes_estado') ? "WHERE IFNULL(s.clm_sedes_estado, 1) = 1" : "";
    $sedes = csb_fetch_all(
        $conn,
        "SELECT s.clm_sedes_id AS id, {$abrSelect}, IFNULL(s.clm_sedes_name, '') AS nombre FROM tb_sedes s {$estadoWhere} ORDER BY nombre ASC"
    );

    foreach ($sedes as &$sede) {
        $sede['label'] = csb_sede_label($sede);
    }
    unset($sede);

    return [
        'placas' => $placas,
        'sedes' => $sedes,
    ];
}

function csb_norm(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = str_replace(['ÃƒÂ', 'Ãƒâ€°', 'ÃƒÂ', 'Ãƒâ€œ', 'ÃƒÅ¡', 'Ãƒâ€˜', 'ÃƒÂ¡', 'ÃƒÂ©', 'ÃƒÂ­', 'ÃƒÂ³', 'ÃƒÂº', 'ÃƒÂ±'], ['A', 'E', 'I', 'O', 'U', 'N', 'a', 'e', 'i', 'o', 'u', 'n'], $value);
    $value = strtolower($value);
    return preg_replace('/\s+/', ' ', $value) ?: '';
}

function csb_hojaruta_key(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function csb_find_hojaruta_duplicate(mysqli $conn, string $hojaRuta, int $excludeId = 0): ?array {
    $hojaRuta = trim($hojaRuta);
    if ($hojaRuta === '') {
        return null;
    }

    $rows = csb_fetch_all($conn, "
        SELECT
            clm_salprog_id,
            clm_salprog_fecha_operativa,
            clm_salprog_horasalida,
            clm_salprog_bus,
            clm_salprog_placa,
            clm_salprog_origen,
            clm_salprog_destino,
            clm_salprog_hojaruta
        FROM tb_progbuses_salida_consolidado
        WHERE clm_salprog_id <> ?
          AND clm_salprog_hojaruta IS NOT NULL
          AND TRIM(clm_salprog_hojaruta) <> ''
          AND LOWER(TRIM(clm_salprog_hojaruta)) = LOWER(TRIM(?))
        ORDER BY clm_salprog_fecha_operativa DESC, clm_salprog_horasalida DESC, clm_salprog_id DESC
        LIMIT 1
    ", 'is', [$excludeId, $hojaRuta]);

    return $rows[0] ?? null;
}

function csb_conductores_lineas(?string $value): array {
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/\s+\|\s+|\r\n|\r|\n/', $value) ?: [];
    $parts = array_map('trim', $parts);
    return array_values(array_filter($parts, static function ($part) {
        return $part !== '';
    }));
}

function csb_array_is_list_compat(array $array): bool {
    $expected = 0;
    foreach (array_keys($array) as $key) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function csb_active_driver_where(mysqli $conn, string $alias = 't'): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $where = [
        "UPPER(TRIM(IFNULL({$prefix}clm_tra_tipo_trabajador, ''))) = 'CONDUCTOR'",
    ];

    if (csb_column_exists($conn, 'tb_trabajador', 'clm_tra_contrato')) {
        $where[] = "UPPER(TRIM(IFNULL({$prefix}clm_tra_contrato, ''))) = 'ACTIVO'";
    }

    return implode(' AND ', $where);
}

function csb_driver_label(array $row): string {
    $nombre = trim((string)($row['conductor'] ?? $row['clm_tra_nombres'] ?? ''));
    $dni = trim((string)($row['dni'] ?? $row['clm_tra_dni'] ?? ''));
    if ($nombre === '') {
        $nombre = 'Conductor sin nombre';
    }
    return $dni !== '' ? $nombre . ' (' . $dni . ')' : $nombre;
}

function csb_fetch_conductores_activos(mysqli $conn): array {
    if (!csb_table_exists($conn, 'tb_trabajador')) {
        return [];
    }

    $licenseSelect = csb_column_exists($conn, 'tb_trabajador', 'clm_tra_nlicenciaconducir')
        ? "IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia"
        : "'' AS licencia";

    $rows = csb_fetch_all($conn, "
        SELECT
            t.clm_tra_id AS id,
            IFNULL(t.clm_tra_nombres, '') AS conductor,
            IFNULL(t.clm_tra_dni, '') AS dni,
            {$licenseSelect}
        FROM tb_trabajador t
        WHERE " . csb_active_driver_where($conn, 't') . "
        ORDER BY t.clm_tra_nombres ASC
    ");

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['label'] = csb_driver_label($row);
    }
    unset($row);

    return $rows;
}

function csb_fetch_conductor_activo(mysqli $conn, int $id): ?array {
    if ($id <= 0 || !csb_table_exists($conn, 'tb_trabajador')) {
        return null;
    }

    $licenseSelect = csb_column_exists($conn, 'tb_trabajador', 'clm_tra_nlicenciaconducir')
        ? "IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia"
        : "'' AS licencia";

    $rows = csb_fetch_all($conn, "
        SELECT
            t.clm_tra_id AS id,
            IFNULL(t.clm_tra_nombres, '') AS conductor,
            IFNULL(t.clm_tra_dni, '') AS dni,
            {$licenseSelect}
        FROM tb_trabajador t
        WHERE t.clm_tra_id = ?
          AND " . csb_active_driver_where($conn, 't') . "
        LIMIT 1
    ", 'i', [$id]);

    if (!$rows) {
        return null;
    }
    $rows[0]['id'] = (int)($rows[0]['id'] ?? 0);
    $rows[0]['label'] = csb_driver_label($rows[0]);
    return $rows[0];
}

function csb_build_driver_history(?string $rawJson, string $oldText, string $newText, int $driverIndex, array $driver): string {
    $decoded = json_decode((string)$rawJson, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if (csb_array_is_list_compat($decoded)) {
        $payload = [
            'captura_original' => $decoded,
            'historial_ediciones' => [],
        ];
    } else {
        $payload = $decoded;
        if (!isset($payload['historial_ediciones']) || !is_array($payload['historial_ediciones'])) {
            $payload['historial_ediciones'] = [];
        }
    }

    $payload['historial_ediciones'][] = [
        'fecha' => date('Y-m-d H:i:s'),
        'usuario_id' => csb_uid(),
        'usuario' => (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? ''),
        'conductor_index' => $driverIndex,
        'conductor_id' => (int)($driver['id'] ?? 0),
        'conductor_label' => (string)($driver['label'] ?? ''),
        'texto_anterior' => $oldText,
        'texto_nuevo' => $newText,
        'accion' => 'edicion_conductor_consolidado',
    ];

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function csb_row_groups(array $row, array $sedeGroups): array {
    if (!$sedeGroups) {
        return [];
    }

    $origen = csb_norm($row['clm_salprog_origen'] ?? '');

    $groups = [];
    foreach ($sedeGroups as $label => $group) {
        if ($label === '') {
            continue;
        }
        $isDirect = $label === $origen;
        $isOriginHit = strlen($label) > 2 && $origen !== '' && strpos($origen, $label) !== false;
        if ($isDirect || $isOriginHit) {
            $groups[$group] = true;
        }
    }

    return array_keys($groups);
}

if (empty($_SESSION['csb_token'])) {
    $_SESSION['csb_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csb_token'];
$isAdmin = n360_is_admin();
$tableReady = isset($conn) && $conn instanceof mysqli && csb_table_exists(
    $conn,
    'tb_progbuses_salida_consolidado'
);

/* Rango de fechas operativas disponible antes de procesar GET/POST.
   Se conserva fecha_operativa como compatibilidad con enlaces antiguos. */
$defaultDate = date('Y-m-d', strtotime('-1 day'));
$legacyFechaOperativa = $_GET['fecha_operativa'] ?? '';

$fechaInicio = csb_valid_date(
    $_GET['fecha_inicio'] ?? $legacyFechaOperativa,
    $defaultDate
);
$fechaFin = csb_valid_date(
    $_GET['fecha_fin'] ?? $legacyFechaOperativa,
    $fechaInicio
);

if ($fechaFin < $fechaInicio) {
    [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
}

/* Se mantiene como fecha por defecto para formularios de un solo viaje. */
$fechaOperativa = $fechaInicio;
$esRangoFechas = $fechaInicio !== $fechaFin;
$periodoOperativoLabel = $esRangoFechas
    ? csb_date_label($fechaInicio) . ' - ' . csb_date_label($fechaFin)
    : csb_date_label($fechaInicio);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        csb_json(false, [], 'Primero crea la tabla del consolidado.', 400);
    }
    if (!hash_equals($csrfToken, (string)($_POST['csrf'] ?? ''))) {
        csb_json(false, [], 'Sesion invalida. Actualiza la pagina.', 419);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'calendar_counts') {
        if (!$isAdmin) {
            csb_json(false, [], 'Solo administradores pueden consultar el calendario.', 403);
        }

        $month = trim((string)($_POST['month'] ?? date('Y-m')));
        $monthDate = DateTime::createFromFormat('Y-m-d', $month . '-01');
        if (!$monthDate || $monthDate->format('Y-m') !== $month) {
            csb_json(false, [], 'Mes invalido.', 422);
        }

        $start = $monthDate->format('Y-m-01');
        $end = $monthDate->format('Y-m-t');
        $calendarRows = csb_fetch_all($conn, "
            SELECT clm_salprog_fecha_operativa AS fecha, COUNT(*) AS total
            FROM tb_progbuses_salida_consolidado
            WHERE clm_salprog_fecha_operativa BETWEEN ? AND ?
            GROUP BY clm_salprog_fecha_operativa
            ORDER BY clm_salprog_fecha_operativa ASC
        ", 'ss', [$start, $end]);

        $counts = [];
        foreach ($calendarRows as $calendarRow) {
            $counts[(string)$calendarRow['fecha']] = (int)$calendarRow['total'];
        }

        csb_json(true, [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'counts' => $counts,
        ]);
    }

    if ($action === 'update_driver') {
        $id = (int)($_POST['id'] ?? 0);
        $driverIndex = (int)($_POST['driver_index'] ?? -1);
        $driverId = (int)($_POST['driver_id'] ?? 0);

        if ($id <= 0 || $driverIndex < 0 || $driverId <= 0) {
            csb_json(false, [], 'Selecciona un conductor valido.', 422);
        }

        $driver = csb_fetch_conductor_activo($conn, $driverId);
        if (!$driver) {
            csb_json(false, [], 'El conductor seleccionado no esta activo o no existe.', 422);
        }

        $conn->begin_transaction();
        try {
            $currentRows = csb_fetch_all($conn, "
                SELECT
                    clm_salprog_revision_estado,
                    clm_salprog_conductores_texto,
                    clm_salprog_conductores_json
                FROM tb_progbuses_salida_consolidado
                WHERE clm_salprog_id = ?
                LIMIT 1
                FOR UPDATE
            ", 'i', [$id]);

            if (!$currentRows) {
                throw new RuntimeException('No se encontro el registro del consolidado.');
            }

            $current = $currentRows[0];
            $estadoActual = strtoupper(trim((string)($current['clm_salprog_revision_estado'] ?? 'PENDIENTE')));
            if ($estadoActual !== 'OBSERVADO') {
                throw new RuntimeException('Solo puedes editar conductores cuando el registro esta OBSERVADO.');
            }

            $oldText = trim((string)($current['clm_salprog_conductores_texto'] ?? ''));
            $driverLines = csb_conductores_lineas($oldText);
            if (!isset($driverLines[$driverIndex])) {
                throw new RuntimeException('No se encontro ese conductor dentro de la fila.');
            }

            $driverLines[$driverIndex] = (string)$driver['label'];
            $newText = implode(' | ', $driverLines);
            $newJson = csb_build_driver_history($current['clm_salprog_conductores_json'] ?? '', $oldText, $newText, $driverIndex, $driver);

            $stmt = $conn->prepare("
                UPDATE tb_progbuses_salida_consolidado
                   SET clm_salprog_conductores_texto = ?,
                       clm_salprog_conductores_json = ?
                 WHERE clm_salprog_id = ?
                 LIMIT 1
            ");
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'No se pudo preparar el guardado.');
            }
            $stmt->bind_param('ssi', $newText, $newJson, $id);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error ?: 'No se pudo guardar el conductor.');
            }
            $stmt->close();
            $conn->commit();

            csb_json(true, [
                'id' => $id,
                'driver_index' => $driverIndex,
                'driver_id' => $driverId,
                'driver_label' => $driver['label'],
                'conductores_texto' => $newText,
                'conductores_lineas' => $driverLines,
            ], 'Conductor actualizado y guardado en historial.');
        } catch (Throwable $e) {
            $conn->rollback();
            csb_json(false, [], $e->getMessage() ?: 'No se pudo actualizar el conductor.', 400);
        }
    }

    if ($action === 'check_hojaruta') {
        $id = (int)($_POST['id'] ?? 0);
        $hojaRuta = trim((string)($_POST['hojaruta'] ?? ''));

        if ($id <= 0) {
            csb_json(false, [], 'Registro invalido para validar la hoja de ruta.', 422);
        }

        if ($hojaRuta === '') {
            csb_json(true, [
                'duplicada' => false,
                'hojaruta' => '',
            ]);
        }

        $duplicate = csb_find_hojaruta_duplicate($conn, $hojaRuta, $id);
        csb_json(true, [
            'duplicada' => $duplicate !== null,
            'hojaruta' => $hojaRuta,
            'duplicado' => $duplicate ? [
                'id' => (int)($duplicate['clm_salprog_id'] ?? 0),
                'fecha' => csb_date_label($duplicate['clm_salprog_fecha_operativa'] ?? ''),
                'hora' => csb_hora_label($duplicate['clm_salprog_horasalida'] ?? ''),
                'bus' => trim((string)($duplicate['clm_salprog_bus'] ?? '')),
                'placa' => trim((string)($duplicate['clm_salprog_placa'] ?? '')),
                'origen' => trim((string)($duplicate['clm_salprog_origen'] ?? '')),
                'destino' => trim((string)($duplicate['clm_salprog_destino'] ?? '')),
            ] : null,
        ], $duplicate ? 'Hoja de ruta duplicada.' : 'Hoja de ruta disponible.');
    }

    if ($action === 'create_transfer_trip') {
        if (!$isAdmin) {
            csb_json(false, [], 'Solo administradores pueden gestionar transbordos.', 403);
        }

        $sourceId = (int)($_POST['source_id'] ?? 0);
        $sourceRole = strtoupper(trim((string)($_POST['source_role'] ?? '')));
        $horaSalida = csb_normalize_time((string)($_POST['hora_salida'] ?? ''));
        $idPlaca = (int)($_POST['idplaca'] ?? 0);
        $idOrigen = (int)($_POST['idorigen'] ?? 0);
        $idDestino = (int)($_POST['iddestino'] ?? 0);
        $rutaIdsRaw = $_POST['ruta_ids'] ?? [];
        $comentarioTransfer = trim((string)($_POST['comentario'] ?? ''));
        $rolesPermitidos = ['TRANSBORDADO', 'TRANSBORDO'];

        if (!is_array($rutaIdsRaw)) {
            $rutaIdsRaw = [$rutaIdsRaw];
        }
        $rutaIds = array_values(array_unique(array_filter(array_map('intval', $rutaIdsRaw), static fn($id) => $id > 0)));

        if ($sourceId <= 0 || !in_array($sourceRole, $rolesPermitidos, true)) {
            csb_json(false, [], 'Selecciona el viaje y define si la unidad fue TRANSBORDADA o realizo el TRANSBORDO.', 422);
        }
        if (!$horaSalida || $idPlaca <= 0 || $idOrigen <= 0 || $idDestino <= 0) {
            csb_json(false, [], 'Completa hora, unidad, origen y destino del viaje relacionado.', 422);
        }
        if ($idOrigen === $idDestino) {
            csb_json(false, [], 'El origen y destino del viaje relacionado no pueden ser iguales.', 422);
        }
        if (in_array($idOrigen, $rutaIds, true) || in_array($idDestino, $rutaIds, true)) {
            csb_json(false, [], 'Las rutas intermedias no deben repetir el origen ni el destino.', 422);
        }

        $counterpartRole = $sourceRole === 'TRANSBORDADO' ? 'TRANSBORDO' : 'TRANSBORDADO';
        $uid = csb_uid();
        $conn->begin_transaction();

        try {
            // La apertura del modal NO hace una consulta AJAX: usa los datos ya renderizados en la fila.
            // En el guardado sí revalidamos el registro en servidor para evitar manipulación del formulario.
            $sourceRows = csb_fetch_all($conn, "
                SELECT *
                FROM tb_progbuses_salida_consolidado
                WHERE clm_salprog_id = ?
                LIMIT 1
                FOR UPDATE
            ", 'i', [$sourceId]);

            if (!$sourceRows) {
                throw new RuntimeException('No se encontro el viaje seleccionado en el consolidado.');
            }
            $source = $sourceRows[0];
            $sourceEstado = strtoupper(trim((string)($source['clm_salprog_revision_estado'] ?? 'PENDIENTE')));
            if (in_array($sourceEstado, $rolesPermitidos, true)) {
                throw new RuntimeException('Este viaje ya esta marcado como ' . $sourceEstado . '.');
            }

            $fechaOperativaTransfer = csb_valid_date((string)($source['clm_salprog_fecha_operativa'] ?? ''), '');
            $sourceIdPlaca = (int)($source['clm_salprog_idplaca'] ?? 0);
            if ($fechaOperativaTransfer === '') {
                throw new RuntimeException('El viaje seleccionado no tiene una fecha operativa valida.');
            }
            if ($sourceIdPlaca > 0 && $sourceIdPlaca === $idPlaca) {
                throw new RuntimeException('La unidad relacionada debe ser diferente a la unidad del viaje seleccionado.');
            }

            $servicioSelect = csb_column_exists($conn, 'tb_placas', 'clm_placas_servicio') ? "IFNULL(p.clm_placas_servicio, '') AS servicio" : "'' AS servicio";
            $placaRows = csb_fetch_all(
                $conn,
                "SELECT p.clm_placas_id AS id,
                        IFNULL(p.clm_placas_BUS, '') AS bus,
                        IFNULL(p.clm_placas_PLACA, '') AS placa,
                        {$servicioSelect}
                   FROM tb_placas p
                  WHERE p.clm_placas_id = ?
                    AND UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
                  LIMIT 1",
                'i',
                [$idPlaca]
            );
            if (!$placaRows) {
                throw new RuntimeException('La unidad relacionada no existe o no esta activa.');
            }
            $placa = $placaRows[0];

            $sedeIds = array_merge([$idOrigen, $idDestino], $rutaIds);
            $sedesMap = csb_fetch_sedes_by_ids($conn, $sedeIds);
            if (!isset($sedesMap[$idOrigen], $sedesMap[$idDestino])) {
                throw new RuntimeException('No se encontro el origen o destino seleccionado.');
            }
            foreach ($rutaIds as $rutaId) {
                if (!isset($sedesMap[$rutaId])) {
                    throw new RuntimeException('Una ruta intermedia seleccionada no existe.');
                }
            }

            $duplicados = csb_fetch_all(
                $conn,
                "SELECT clm_salprog_id
                   FROM tb_progbuses_salida_consolidado
                  WHERE clm_salprog_fecha_operativa = ?
                    AND clm_salprog_idplaca = ?
                    AND clm_salprog_horasalida = ?
                  LIMIT 1",
                'sis',
                [$fechaOperativaTransfer, $idPlaca, $horaSalida]
            );
            if ($duplicados) {
                throw new RuntimeException('Ya existe un registro para esa unidad, fecha y hora de salida.');
            }

            $rutaLabels = [];
            foreach ($rutaIds as $rutaId) {
                $rutaLabels[] = $sedesMap[$rutaId];
            }
            $rutaIdsText = implode(',', $rutaIds);
            $rutaTexto = implode(' -> ', $rutaLabels);
            $horaOrden = csb_hora_orden($horaSalida);
            $bus = trim((string)($placa['bus'] ?? ''));
            $placaTexto = trim((string)($placa['placa'] ?? ''));
            $servicio = trim((string)($placa['servicio'] ?? ''));
            $revisionComentario = $counterpartRole . ' relacionado con el registro #' . $sourceId . '.';

            $stmt = $conn->prepare("
                INSERT INTO tb_progbuses_salida_consolidado (
                    clm_salprog_cierre_id,
                    clm_salprog_fecha_operativa,
                    clm_salprog_fecha_ejecucion,
                    clm_salprog_run_id,
                    clm_salprog_progid,
                    clm_salprog_idplaca,
                    clm_salprog_bus,
                    clm_salprog_placa,
                    clm_salprog_servicio,
                    clm_salprog_idorigen,
                    clm_salprog_origen,
                    clm_salprog_iddestino,
                    clm_salprog_destino,
                    clm_salprog_ruta_ids,
                    clm_salprog_ruta_texto,
                    clm_salprog_horasalida,
                    clm_salprog_hora_orden,
                    clm_salprog_conductores_texto,
                    clm_salprog_conductores_json,
                    clm_salprog_comentario_horario,
                    clm_salprog_fecha_programacion,
                    clm_salprog_revision_estado,
                    clm_salprog_comentario_revision,
                    clm_salprog_usuario_revision,
                    clm_salprog_datetime_revision
                ) VALUES (
                    0,
                    ?,
                    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00'),
                    0,
                    0,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    '',
                    '[]',
                    ?,
                    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00'),
                    ?,
                    ?,
                    ?,
                    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00')
                )
            ");
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'No se pudo preparar el registro del transbordo.');
            }

            $paramsTransfer = [
                $fechaOperativaTransfer,
                $idPlaca,
                $bus,
                $placaTexto,
                $servicio,
                $idOrigen,
                $sedesMap[$idOrigen],
                $idDestino,
                $sedesMap[$idDestino],
                $rutaIdsText,
                $rutaTexto,
                $horaSalida,
                $horaOrden,
                $comentarioTransfer,
                $counterpartRole,
                $revisionComentario,
                $uid,
            ];
            csb_bind($stmt, 'sisssisissssisssi', $paramsTransfer);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error ?: 'No se pudo crear el viaje relacionado.');
            }
            $nuevoId = (int)$stmt->insert_id;
            $stmt->close();

            $sourceComentarioPrevio = trim((string)($source['clm_salprog_comentario_revision'] ?? ''));
            $sourceNota = $sourceRole . ' relacionado con el registro #' . $nuevoId . '.';
            $sourceComentarioNuevo = $sourceComentarioPrevio !== ''
                ? $sourceComentarioPrevio . "\n" . $sourceNota
                : $sourceNota;

            $stmt = $conn->prepare("
                UPDATE tb_progbuses_salida_consolidado
                   SET clm_salprog_revision_estado = ?,
                       clm_salprog_comentario_revision = ?,
                       clm_salprog_usuario_revision = ?,
                       clm_salprog_datetime_revision = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00')
                 WHERE clm_salprog_id = ?
                 LIMIT 1
            ");
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'No se pudo actualizar el viaje original.');
            }
            $stmt->bind_param('ssii', $sourceRole, $sourceComentarioNuevo, $uid, $sourceId);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error ?: 'No se pudo marcar el viaje original.');
            }
            $stmt->close();

            $conn->commit();

            csb_json(true, [
                'source_id' => $sourceId,
                'source_role' => $sourceRole,
                'related_id' => $nuevoId,
                'related_role' => $counterpartRole,
                'fecha_operativa' => $fechaOperativaTransfer,
                'redirect' => 'consolidado_salidas_buses.php?fecha_inicio=' . rawurlencode($fechaOperativaTransfer) . '&fecha_fin=' . rawurlencode($fechaOperativaTransfer),
            ], 'Transbordo registrado: ' . $sourceRole . ' / ' . $counterpartRole . '.');
        } catch (Throwable $e) {
            $conn->rollback();
            csb_json(false, [], 'No se pudo registrar el transbordo: ' . $e->getMessage(), 400);
        }
    }

    if ($action === 'create_manual_trip') {
        if (!$isAdmin) {
            csb_json(false, [], 'Solo administradores pueden agregar viajes manuales.', 403);
        }

        $fechaManual = csb_valid_date((string)($_POST['fecha_operativa'] ?? ''), $fechaOperativa);
        $horaSalida = csb_normalize_time((string)($_POST['hora_salida'] ?? ''));
        $idPlaca = (int)($_POST['idplaca'] ?? 0);
        $idOrigen = (int)($_POST['idorigen'] ?? 0);
        $idDestino = (int)($_POST['iddestino'] ?? 0);
        $rutaIdsRaw = $_POST['ruta_ids'] ?? [];
        $comentarioManual = trim((string)($_POST['comentario'] ?? ''));

        if (!is_array($rutaIdsRaw)) {
            $rutaIdsRaw = [$rutaIdsRaw];
        }
        $rutaIds = array_values(array_unique(array_filter(array_map('intval', $rutaIdsRaw), static fn($id) => $id > 0)));

        if (!$fechaManual || !$horaSalida || $idPlaca <= 0 || $idOrigen <= 0 || $idDestino <= 0) {
            csb_json(false, [], 'Completa fecha, hora, unidad, origen y destino para registrar el viaje manual.', 422);
        }
        if ($idOrigen === $idDestino) {
            csb_json(false, [], 'El origen y destino no pueden ser la misma sede.', 422);
        }
        if (in_array($idOrigen, $rutaIds, true) || in_array($idDestino, $rutaIds, true)) {
            csb_json(false, [], 'Las rutas intermedias no deben repetir el origen ni el destino.', 422);
        }

        $servicioSelect = csb_column_exists($conn, 'tb_placas', 'clm_placas_servicio') ? "IFNULL(p.clm_placas_servicio, '') AS servicio" : "'' AS servicio";
        $placaRows = csb_fetch_all(
            $conn,
            "SELECT p.clm_placas_id AS id,
                    IFNULL(p.clm_placas_BUS, '') AS bus,
                    IFNULL(p.clm_placas_PLACA, '') AS placa,
                    {$servicioSelect}
               FROM tb_placas p
              WHERE p.clm_placas_id = ?
                AND UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
              LIMIT 1",
            'i',
            [$idPlaca]
        );
        if (!$placaRows) {
            csb_json(false, [], 'La unidad seleccionada no existe o no esta activa.', 422);
        }
        $placa = $placaRows[0];

        $sedeIds = array_merge([$idOrigen, $idDestino], $rutaIds);
        $sedesMap = csb_fetch_sedes_by_ids($conn, $sedeIds);
        if (!isset($sedesMap[$idOrigen], $sedesMap[$idDestino])) {
            csb_json(false, [], 'No se encontro el origen o destino seleccionado.', 422);
        }
        foreach ($rutaIds as $rutaId) {
            if (!isset($sedesMap[$rutaId])) {
                csb_json(false, [], 'Una ruta intermedia seleccionada no existe.', 422);
            }
        }

        $rutaLabels = [];
        foreach ($rutaIds as $rutaId) {
            $rutaLabels[] = $sedesMap[$rutaId];
        }
        $rutaIdsText = implode(',', $rutaIds);
        $rutaTexto = implode(' -> ', $rutaLabels);
        $horaOrden = csb_hora_orden($horaSalida);
        $bus = trim((string)($placa['bus'] ?? ''));
        $placaTexto = trim((string)($placa['placa'] ?? ''));
        $servicio = trim((string)($placa['servicio'] ?? ''));

        $duplicados = csb_fetch_all(
            $conn,
            "SELECT clm_salprog_id
               FROM tb_progbuses_salida_consolidado
              WHERE clm_salprog_fecha_operativa = ?
                AND clm_salprog_idplaca = ?
                AND clm_salprog_horasalida = ?
              LIMIT 1",
            'sis',
            [$fechaManual, $idPlaca, $horaSalida]
        );
        if ($duplicados) {
            csb_json(false, [], 'Ya existe un registro para esa unidad, fecha y hora de salida.', 409);
        }

        $uid = csb_uid();
        $revisionComentario = 'Registro manual agregado desde consolidado.';
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO tb_progbuses_salida_consolidado (
                    clm_salprog_cierre_id,
                    clm_salprog_fecha_operativa,
                    clm_salprog_fecha_ejecucion,
                    clm_salprog_run_id,
                    clm_salprog_progid,
                    clm_salprog_idplaca,
                    clm_salprog_bus,
                    clm_salprog_placa,
                    clm_salprog_servicio,
                    clm_salprog_idorigen,
                    clm_salprog_origen,
                    clm_salprog_iddestino,
                    clm_salprog_destino,
                    clm_salprog_ruta_ids,
                    clm_salprog_ruta_texto,
                    clm_salprog_horasalida,
                    clm_salprog_hora_orden,
                    clm_salprog_conductores_texto,
                    clm_salprog_conductores_json,
                    clm_salprog_comentario_horario,
                    clm_salprog_fecha_programacion,
                    clm_salprog_revision_estado,
                    clm_salprog_comentario_revision,
                    clm_salprog_usuario_revision,
                    clm_salprog_datetime_revision
                ) VALUES (
                    0,
                    ?,
                    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00'),
                    0,
                    0,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    '',
                    '[]',
                    ?,
                    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00'),
                    'MANUAL',
                    ?,
                    ?,
                    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00')
                )
            ");
            if (!$stmt) {
                throw new RuntimeException('No se pudo preparar el registro manual.');
            }
            $paramsManual = [
                $fechaManual,
                $idPlaca,
                $bus,
                $placaTexto,
                $servicio,
                $idOrigen,
                $sedesMap[$idOrigen],
                $idDestino,
                $sedesMap[$idDestino],
                $rutaIdsText,
                $rutaTexto,
                $horaSalida,
                $horaOrden,
                $comentarioManual,
                $revisionComentario,
                $uid,
            ];

            csb_bind(
                $stmt,
                'sisssisissssissi',
                $paramsManual
            );

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();

                throw new RuntimeException(
                    $error ?: 'No se pudo registrar el viaje manual.'
                );
            }

            $nuevoId = (int)$stmt->insert_id;
            $stmt->close();
            $conn->commit();

            csb_json(true, [
                'id' => $nuevoId,
                'estado' => 'MANUAL',
                'redirect' => 'consolidado_salidas_buses.php?fecha_inicio=' . rawurlencode($fechaManual) . '&fecha_fin=' . rawurlencode($fechaManual) . '&revision=MANUAL',
            ], 'Viaje manual registrado correctamente.');
        } catch (Throwable $e) {
            $conn->rollback();
            csb_json(false, [], 'No se pudo registrar el viaje manual: ' . $e->getMessage(), 500);
        }
    }

    if ($action !== 'update_revision') {
        csb_json(false, [], 'Accion no reconocida.', 400);
    }

    $id = (int)($_POST['id'] ?? 0);
    $estado = strtoupper(trim((string)($_POST['estado'] ?? 'PENDIENTE')));
    $comentario = trim((string)($_POST['comentario'] ?? ''));
    $correccion = trim((string)($_POST['correccion'] ?? ''));
    $hojaRuta = trim((string)($_POST['hojaruta'] ?? ''));
    $permitidos = ['PENDIENTE', 'VALIDADO', 'OBSERVADO', 'CORREGIDO', 'ANULADO', 'MANUAL', 'TRANSBORDADO', 'TRANSBORDO'];

    if ($id <= 0 || !in_array($estado, $permitidos, true)) {
        csb_json(false, [], 'Datos incompletos para guardar.', 422);
    }

    if ($hojaRuta !== '') {
        $duplicate = csb_find_hojaruta_duplicate($conn, $hojaRuta, $id);
        if ($duplicate) {
            $refBus = trim((string)($duplicate['clm_salprog_bus'] ?? ''));
            $refPlaca = trim((string)($duplicate['clm_salprog_placa'] ?? ''));
            $refUnidad = $refBus !== '' && $refPlaca !== '' ? $refBus . ' (' . $refPlaca . ')' : ($refBus ?: ($refPlaca ?: 'otra unidad'));
            $refFecha = csb_date_label($duplicate['clm_salprog_fecha_operativa'] ?? '');
            $refHora = csb_hora_label($duplicate['clm_salprog_horasalida'] ?? '');
            csb_json(false, [
                'duplicada' => true,
                'duplicado_id' => (int)($duplicate['clm_salprog_id'] ?? 0),
            ], "La Hoja de Ruta ya esta registrada en {$refUnidad}, {$refFecha} {$refHora}. No se guardo el duplicado.", 409);
        }
    }

    $uid = csb_uid();
    $stmt = $conn->prepare("
        UPDATE tb_progbuses_salida_consolidado
           SET clm_salprog_revision_estado = ?,
               clm_salprog_comentario_revision = ?,
               clm_salprog_correccion = ?,
               clm_salprog_hojaruta = NULLIF(?, ''),
               clm_salprog_usuario_revision = ?,
               clm_salprog_datetime_revision = NOW()
         WHERE clm_salprog_id = ?
         LIMIT 1
    ");
    if (!$stmt) {
        csb_json(false, [], $conn->error ?: 'No se pudo preparar la actualizacion.', 500);
    }
    $stmt->bind_param('ssssii', $estado, $comentario, $correccion, $hojaRuta, $uid, $id);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        csb_json(false, [], $error ?: 'No se pudo guardar.', 500);
    }

    csb_json(true, [
        'estado' => $estado,
        'clase' => csb_estado_class($estado),
        'actualizado' => date('d/m/Y H:i'),
        'hojaruta' => $hojaRuta,
        'tiene_hojaruta' => $hojaRuta !== '',
    ], 'Cambios guardados.');
}

$revision = strtoupper(trim((string)($_GET['revision'] ?? 'TODOS')));
$buscar = trim((string)($_GET['buscar'] ?? ''));
$revisionPermitidas = ['TODOS', 'PENDIENTE', 'VALIDADO', 'OBSERVADO', 'CORREGIDO', 'ANULADO', 'MANUAL', 'TRANSBORDADO', 'TRANSBORDO'];
if (!in_array($revision, $revisionPermitidas, true)) {
    $revision = 'TODOS';
}

$rows = [];
$duplicateHojaRutaKeys = [];
$pageError = '';
$ultimoCierre = null;
$cierresEnRango = 0;
$retiradosEnRango = 0;
$rowsForDateTotal = 0;
$sedeGroups = [];
$rowGroupsById = [];
$groupCounters = [];
$conductoresActivos = [];
$manualCatalog = ['placas' => [], 'sedes' => []];
$kpis = [
    'registros' => 0,
    'unidades' => 0,
    'conductores' => 0,
    'pendientes' => 0,
    'observados' => 0,
    'validados' => 0,
    'corregidos' => 0,
    'anulados' => 0,
    'manuales' => 0,
    'transbordados' => 0,
    'transbordos' => 0,
];

if ($tableReady) {
    try {
        $conductoresActivos = csb_fetch_conductores_activos($conn);
        if ($isAdmin) {
            $manualCatalog = csb_fetch_manual_catalog($conn);
        }

        if (csb_column_exists($conn, 'tb_sedes', 'clm_sedes_grupo_pizarra')) {
            $estadoWhere = csb_column_exists($conn, 'tb_sedes', 'clm_sedes_estado')
                ? "WHERE IFNULL(clm_sedes_estado, 1) = 1"
                : "";
            $sedeRows = csb_fetch_all($conn, "
                SELECT
                    COALESCE(NULLIF(TRIM(clm_sedes_abr), ''), '') AS abr,
                    COALESCE(NULLIF(TRIM(clm_sedes_name), ''), '') AS nombre,
                    COALESCE(NULLIF(TRIM(clm_sedes_grupo_pizarra), ''), 'SIN GRUPO') AS grupo
                FROM tb_sedes
                {$estadoWhere}
            ");
            foreach ($sedeRows as $sedeRow) {
                $grupo = trim((string)($sedeRow['grupo'] ?? 'SIN GRUPO'));
                $grupo = $grupo !== '' ? $grupo : 'SIN GRUPO';
                foreach (['abr', 'nombre'] as $field) {
                    $label = csb_norm($sedeRow[$field] ?? '');
                    if ($label !== '') {
                        $sedeGroups[$label] = $grupo;
                    }
                }
            }
        }

        $dateTotalRows = csb_fetch_all($conn, "
            SELECT COUNT(*) AS total
            FROM tb_progbuses_salida_consolidado
            WHERE clm_salprog_fecha_operativa BETWEEN ? AND ?
        ", 'ss', [$fechaInicio, $fechaFin]);
        $rowsForDateTotal = (int)($dateTotalRows[0]['total'] ?? 0);

        $where = ["clm_salprog_fecha_operativa BETWEEN ? AND ?"];
        $types = 'ss';
        $params = [$fechaInicio, $fechaFin];

        if ($revision !== 'TODOS') {
            $where[] = "clm_salprog_revision_estado = ?";
            $types .= 's';
            $params[] = $revision;
        }

        if ($buscar !== '') {
            $like = '%' . $buscar . '%';
            $where[] = "(
                clm_salprog_bus LIKE ?
                OR clm_salprog_placa LIKE ?
                OR clm_salprog_servicio LIKE ?
                OR clm_salprog_origen LIKE ?
                OR clm_salprog_destino LIKE ?
                OR clm_salprog_ruta_texto LIKE ?
                OR clm_salprog_conductores_texto LIKE ?
                OR clm_salprog_comentario_horario LIKE ?
                OR clm_salprog_comentario_revision LIKE ?
                OR clm_salprog_correccion LIKE ?
                OR clm_salprog_hojaruta LIKE ?
            )";
            $types .= 'sssssssssss';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $rows = csb_fetch_all($conn, "
            SELECT *
            FROM tb_progbuses_salida_consolidado
            WHERE " . implode(' AND ', $where) . "
            ORDER BY clm_salprog_fecha_operativa ASC, clm_salprog_hora_orden ASC, clm_salprog_horasalida ASC, clm_salprog_bus ASC, clm_salprog_id ASC
        ", $types, $params);

        $duplicateRows = csb_fetch_all($conn, "
            SELECT LOWER(TRIM(clm_salprog_hojaruta)) AS hoja_key, COUNT(*) AS total
            FROM tb_progbuses_salida_consolidado
            WHERE clm_salprog_hojaruta IS NOT NULL
              AND TRIM(clm_salprog_hojaruta) <> ''
            GROUP BY LOWER(TRIM(clm_salprog_hojaruta))
            HAVING COUNT(*) > 1
        ");
        foreach ($duplicateRows as $duplicateRow) {
            $key = csb_hojaruta_key($duplicateRow['hoja_key'] ?? '');
            if ($key !== '') {
                $duplicateHojaRutaKeys[$key] = true;
            }
        }

        if (csb_table_exists($conn, 'tb_progbuses_cierre_operativo')) {
            $cierreRows = csb_fetch_all($conn, "
                SELECT *
                FROM tb_progbuses_cierre_operativo
                WHERE clm_cierre_fecha BETWEEN ? AND ?
                ORDER BY clm_cierre_fecha DESC, clm_cierre_id DESC
            ", 'ss', [$fechaInicio, $fechaFin]);

            $ultimoCierre = $cierreRows[0] ?? null;
            $cierresEnRango = count($cierreRows);
            foreach ($cierreRows as $cierreRow) {
                $retiradosEnRango += (int)($cierreRow['clm_cierre_total_retirados'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$placas = [];
$conductoresSet = [];
foreach ($rows as $row) {
    $kpis['registros']++;
    $rowId = (int)($row['clm_salprog_id'] ?? 0);
    $groups = csb_row_groups($row, $sedeGroups);
    if (!$groups && $sedeGroups) {
        $groups = ['SIN GRUPO'];
    }
    $rowGroupsById[$rowId] = $groups;
    foreach ($groups as $group) {
        $groupCounters[$group] = ($groupCounters[$group] ?? 0) + 1;
    }

    $placaId = (int)($row['clm_salprog_idplaca'] ?? 0);
    if ($placaId > 0) {
        $placas[$placaId] = true;
    }

    $estadoRow = strtoupper((string)($row['clm_salprog_revision_estado'] ?? 'PENDIENTE'));
    if ($estadoRow === 'VALIDADO') $kpis['validados']++;
    elseif ($estadoRow === 'OBSERVADO') $kpis['observados']++;
    elseif ($estadoRow === 'CORREGIDO') $kpis['corregidos']++;
    elseif ($estadoRow === 'ANULADO') $kpis['anulados']++;
    elseif ($estadoRow === 'MANUAL') $kpis['manuales']++;
    elseif ($estadoRow === 'TRANSBORDADO') $kpis['transbordados']++;
    elseif ($estadoRow === 'TRANSBORDO') $kpis['transbordos']++;
    else $kpis['pendientes']++;

    $hasConductor = false;
    $conductoresJson = json_decode((string)($row['clm_salprog_conductores_json'] ?? '[]'), true);
    if (is_array($conductoresJson)) {
        foreach ($conductoresJson as $conductor) {
            $key = (int)($conductor['idconductor'] ?? 0);
            if ($key > 0) {
                $conductoresSet[$key] = true;
                $hasConductor = true;
            }
        }
    }

    if (!$hasConductor) {
        $conductoresTexto = trim((string)($row['clm_salprog_conductores_texto'] ?? ''));
        if ($conductoresTexto !== '') {
            foreach (preg_split('/\s+\|\s+/', $conductoresTexto) as $conductorTexto) {
                $conductorTexto = trim((string)$conductorTexto);
                if ($conductorTexto !== '') {
                    $conductoresSet['txt:' . strtolower($conductorTexto)] = true;
                }
            }
        }
    }
}
$kpis['unidades'] = count($placas);
$kpis['conductores'] = count($conductoresSet);
ksort($groupCounters, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flota | Consolidado de salidas</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/flota_consolidado_salidas_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Flota', 'subtitle' => 'Consolidado operativo']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content csb-page">
        <section class="csb-hero">
            <div>
                <span class="csb-eyebrow"><i class="bi bi-bus-front-fill"></i> Consolidado de salidas de Buses</span>
                <h1>Buses con Programación Cerrada</h1>
            </div>
            <div class="csb-hero-meta">
                <span><i class="bi bi-calendar2-check"></i> <?= csb_h($periodoOperativoLabel) ?></span>
                <span><i class="bi bi-clock-history"></i> Cierre (Pe) 04:59am</span>
                <button type="button" class="csb-btn csb-btn--hero" data-csb-export-pdf><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                <?php if ($isAdmin): ?>
                    <button type="button" class="csb-btn csb-btn--hero-soft" data-csb-manual-open><i class="bi bi-plus-circle"></i> Viaje manual</button>
                    <button type="button" class="csb-btn csb-btn--hero-soft" data-csb-calendar-open><i class="bi bi-calendar3"></i> Calendario</button>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!$tableReady): ?>
            <div class="csb-alert csb-alert--warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Crea primero la tabla con el script <strong>database/tb_progbuses_salida_consolidado.sql</strong> y luego actualiza la rutina del cierre operativo.
            </div>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <div class="csb-alert csb-alert--danger">
                <i class="bi bi-x-octagon-fill"></i>
                <?= csb_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="csb-summary">
            <article class="csb-kpi csb-kpi--registros">
                <span>Registros</span>
                <strong><?= number_format($kpis['registros']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--unidades">
                <span>Unidades</span>
                <strong><?= number_format($kpis['unidades']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--conductores">
                <span>Conductores</span>
                <strong><?= number_format($kpis['conductores']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--pendientes">
                <span>Pendientes</span>
                <strong><?= number_format($kpis['pendientes']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--observados">
                <span>Observados</span>
                <strong><?= number_format($kpis['observados']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--validados">
                <span>Validados</span>
                <strong><?= number_format($kpis['validados']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--corregidos">
                <span>Corregidos</span>
                <strong><?= number_format($kpis['corregidos']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--manual">
                <span>Manuales</span>
                <strong><?= number_format($kpis['manuales']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--transbordado">
                <span>Transbordados</span>
                <strong><?= number_format($kpis['transbordados']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--transbordo">
                <span>Transbordos</span>
                <strong><?= number_format($kpis['transbordos']) ?></strong>
            </article>

            <article class="csb-kpi csb-kpi--danger">
                <span>Anulados</span>
                <strong><?= number_format($kpis['anulados']) ?></strong>
            </article>
        </section>

        <section class="csb-filter">
            <form method="get" class="csb-filter-grid" autocomplete="off">
                <label>
                    <span>Fecha operativa inicial</span>
                    <input type="date" name="fecha_inicio" value="<?= csb_h($fechaInicio) ?>">
                </label>
                <label>
                    <span>Fecha operativa final</span>
                    <input type="date" name="fecha_fin" value="<?= csb_h($fechaFin) ?>">
                </label>
                <label>
                    <span>Revision</span>
                    <select name="revision">
                        <?php foreach ($revisionPermitidas as $opcion): ?>
                            <option value="<?= csb_h($opcion) ?>" <?= $revision === $opcion ? 'selected' : '' ?>><?= csb_h($opcion) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="csb-filter-search">
                    <span>Buscar</span>
                    <input type="search" name="buscar" value="<?= csb_h($buscar) ?>" placeholder="Bus, placa, conductor, ruta, comentario...">
                </label>
                <div class="csb-filter-actions">
                    <button type="submit" class="csb-btn csb-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button>
                    <a class="csb-btn csb-btn--soft" href="consolidado_salidas_buses.php"><i class="bi bi-x-circle"></i> Limpiar</a>
                </div>
            </form>
        </section>

        <section class="csb-close-info">
            <div>
                <span>Periodo operativo</span>
                <strong><?= csb_h($periodoOperativoLabel) ?></strong>
            </div>
            <div>
                <span>Ultima ejecucion</span>
                <strong><?= $ultimoCierre ? csb_h(csb_date_label($ultimoCierre['clm_cierre_datetime'] ?? '', 'd/m/Y H:i')) : '-' ?></strong>
            </div>
            <div>
                <span>Cierres / retirados</span>
                <strong><?= number_format($cierresEnRango) ?> / <?= number_format($retiradosEnRango) ?></strong>
            </div>
            <div>
                <span>Estado ultimo cierre</span>
                <strong><?= $ultimoCierre ? csb_h($ultimoCierre['clm_cierre_estado'] ?? '-') : '-' ?></strong>
            </div>
        </section>

        <?php if ($groupCounters): ?>
            <section class="csb-group-filter" data-csb-group-filter>
                <button type="button" class="csb-group-btn is-active" data-csb-group="__ALL__">
                    Todos <span><?= number_format(count($rows)) ?></span>
                </button>
                <?php foreach ($groupCounters as $group => $total): ?>
                    <button type="button" class="csb-group-btn" data-csb-group="<?= csb_h($group) ?>">
                        <?= csb_h($group) ?> <span><?= number_format($total) ?></span>
                    </button>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="csb-card">
            <div class="csb-card-head">
                <div>
                    <h2>Consolidado de salidas de buses programados</h2>
                    <p>Datos capturados antes de limpiar la pizarra; los comentarios se guardan en esta tabla auxiliar.</p>
                </div>
                <div class="csb-card-head-actions">
                    <button
                        type="button"
                        class="csb-btn csb-btn--route-list"
                        data-csb-hojarutas-open
                        title="Ver Hojas de Ruta de los viajes visibles"
                    >
                        <i class="bi bi-card-list"></i>
                        <span>Ver Hojas de Ruta</span>
                    </button>
                    <button
                        type="button"
                        class="csb-btn csb-btn--sort-route"
                        data-csb-sort-hojaruta
                        aria-pressed="false"
                        title="Ordenar visualmente por Hoja de Ruta"
                    >
                        <i class="bi bi-sort-numeric-down"></i>
                        <span data-csb-sort-hojaruta-label>Ordenar por Hoja de Ruta</span>
                    </button>
                    <span class="csb-visible-pill" data-csb-visible-pill><?= number_format(count($rows)) ?> registros</span>
                </div>
            </div>

            <div class="csb-table-wrap">
                <table class="csb-table" data-csb-table>
                    <thead>
                        <tr>
                            <th>Fecha operativa</th>
                            <th>Hora</th>
                            <th>Unidad</th>
                            <th>Programacion</th>
                            <th>Hoja de ruta</th>
                            <th>Conductores</th>
                            <th>Revision</th>
                            <th>Comentario / Correccion</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <?php
                                $emptyMessage = $rowsForDateTotal <= 0
                                    ? 'No hay consolidado para esta fecha operativa. Si es una fecha anterior a la implementacion, el cron aun no capturaba estos respaldos; si es posterior, probablemente no hubo unidades programadas al cierre.'
                                    : 'No hay registros para los filtros seleccionados.';
                            ?>
                            <tr>
                                <td colspan="9" class="csb-empty"><?= csb_h($emptyMessage) ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $id = (int)($row['clm_salprog_id'] ?? 0);
                                $estado = strtoupper((string)($row['clm_salprog_revision_estado'] ?? 'PENDIENTE'));
                                $busLabel = trim((string)($row['clm_salprog_bus'] ?? ''));
                                $placaLabel = trim((string)($row['clm_salprog_placa'] ?? ''));
                                $unidadLabel = $busLabel !== '' && $placaLabel !== '' ? "{$busLabel} ({$placaLabel})" : ($busLabel ?: ($placaLabel ?: '-'));
                                $rowGroups = $rowGroupsById[$id] ?? [];
                                $conductoresLineas = csb_conductores_lineas($row['clm_salprog_conductores_texto'] ?? '');
                                $hojaRuta = trim((string)($row['clm_salprog_hojaruta'] ?? ''));
                                $tieneHojaRuta = $hojaRuta !== '';
                                $hojaRutaDuplicada = $tieneHojaRuta && isset($duplicateHojaRutaKeys[csb_hojaruta_key($hojaRuta)]);
                                $rowClasses = [];
                                if ($tieneHojaRuta) $rowClasses[] = 'csb-row--hojaruta';
                                if ($hojaRutaDuplicada) $rowClasses[] = 'csb-row--hojaruta-duplicate';
                            ?>
                            <tr
                                class="<?= csb_h(implode(' ', $rowClasses)) ?>"
                                data-csb-row="<?= $id ?>"
                                data-csb-groups="<?= csb_h(implode('|', $rowGroups)) ?>"
                                data-csb-db-revision="<?= csb_h($estado) ?>"
                                data-csb-has-hojaruta="<?= $tieneHojaRuta ? '1' : '0' ?>"
                                data-csb-hojaruta-duplicate="<?= $hojaRutaDuplicada ? '1' : '0' ?>"
                                data-csb-transfer-date="<?= csb_h($row['clm_salprog_fecha_operativa'] ?? $fechaOperativa) ?>"
                                data-csb-transfer-hour="<?= csb_h(csb_hora_label($row['clm_salprog_horasalida'] ?? '')) ?>"
                                data-csb-transfer-idplaca="<?= (int)($row['clm_salprog_idplaca'] ?? 0) ?>"
                                data-csb-transfer-unit="<?= csb_h($unidadLabel) ?>"
                                data-csb-transfer-service="<?= csb_h($row['clm_salprog_servicio'] ?? '') ?>"
                                data-csb-transfer-idorigen="<?= (int)($row['clm_salprog_idorigen'] ?? 0) ?>"
                                data-csb-transfer-origin="<?= csb_h($row['clm_salprog_origen'] ?? '') ?>"
                                data-csb-transfer-iddestino="<?= (int)($row['clm_salprog_iddestino'] ?? 0) ?>"
                                data-csb-transfer-destination="<?= csb_h($row['clm_salprog_destino'] ?? '') ?>"
                                data-csb-transfer-ruta-ids="<?= csb_h($row['clm_salprog_ruta_ids'] ?? '') ?>"
                                data-csb-transfer-route="<?= csb_h($row['clm_salprog_ruta_texto'] ?? '') ?>"
                            >
                                <td class="csb-date-cell">
                                    <strong><?= csb_h(csb_date_label($row['clm_salprog_fecha_operativa'] ?? '')) ?></strong>
                                    <small>Dia operativo</small>
                                </td>
                                <td>
                                    <strong><?= csb_h(csb_hora_label($row['clm_salprog_horasalida'] ?? '')) ?></strong>
                                    <small>#<?= (int)($row['clm_salprog_progid'] ?? 0) ?></small>
                                </td>
                                <td>
                                    <strong><?= csb_h($unidadLabel) ?></strong>
                                    <small><?= csb_h($row['clm_salprog_servicio'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <strong><?= csb_h(($row['clm_salprog_origen'] ?? '-') . ' -> ' . ($row['clm_salprog_destino'] ?? '-')) ?></strong>
                                    <small><?= csb_h($row['clm_salprog_ruta_texto'] ?: 'Sin ruta adicional') ?></small>
                                    <?php if (trim((string)($row['clm_salprog_comentario_horario'] ?? '')) !== ''): ?>
                                        <em><?= csb_h($row['clm_salprog_comentario_horario']) ?></em>
                                    <?php endif; ?>
                                </td>
                                <td class="csb-hojaruta-cell">
                                    <textarea
                                        data-csb-field="hojaruta"
                                        rows="3"
                                        placeholder="Digita la hoja de ruta anexa"
                                        aria-label="Hoja de ruta anexa"
                                    ><?= csb_h($hojaRuta) ?></textarea>
                                    <small class="csb-hojaruta-state" data-csb-hojaruta-state>
                                        <i class="bi <?= $hojaRutaDuplicada ? 'bi-exclamation-triangle-fill' : ($tieneHojaRuta ? 'bi-check-circle-fill' : 'bi-circle') ?>"></i>
                                        <span><?= $hojaRutaDuplicada ? 'Duplicada: revisar antes de continuar' : ($tieneHojaRuta ? 'Hoja de ruta registrada Ã‚Â· sin duplicados' : 'Pendiente de revisión') ?></span>
                                    </small>
                                </td>
                                <td>
                                    <div class="csb-drivers" data-csb-drivers>
                                        <?php if (!$conductoresLineas): ?>
                                            <span class="csb-driver-line csb-driver-line--empty">
                                                <span data-csb-driver-text>Sin conductor asignado</span>
                                            </span>
                                        <?php endif; ?>
                                        <?php foreach ($conductoresLineas as $indexConductor => $conductor): ?>
                                            <span class="csb-driver-line" data-csb-driver-line data-csb-driver-index="<?= (int)$indexConductor ?>">
                                                <span data-csb-driver-text><?= csb_h($conductor) ?></span>
                                                <button
                                                    type="button"
                                                    class="csb-driver-edit"
                                                    data-csb-driver-edit
                                                    data-csb-driver-index="<?= (int)$indexConductor ?>"
                                                    title="Editar conductor"
                                                    aria-label="Editar conductor"
                                                    <?= $estado === 'OBSERVADO' ? '' : 'hidden' ?>
                                                >
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <small>Asignacion capturada del modulo Conductores</small>
                                </td>
                                <td>
                                    <div class="csb-revision-cell">
                                        <input type="hidden" data-csb-field="estado" value="<?= csb_h($estado) ?>">
                                        <span class="csb-status <?= csb_h(csb_estado_class($estado)) ?>" data-csb-status><?= csb_h($estado) ?></span>
                                        <small data-csb-saved>
                                            <?= !empty($row['clm_salprog_datetime_revision']) ? csb_h(csb_date_label($row['clm_salprog_datetime_revision'], 'd/m/Y H:i')) : 'Sin revision registrada' ?>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <textarea data-csb-field="comentario" rows="2" placeholder="Comentario de revision"><?= csb_h($row['clm_salprog_comentario_revision'] ?? '') ?></textarea>
                                    <textarea data-csb-field="correccion" rows="2" placeholder="Correccion aplicada o pendiente"><?= csb_h($row['clm_salprog_correccion'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <div class="csb-action-panel">
                                        <div class="csb-state-buttons" aria-label="Cambiar revision">
                                            <?php foreach (['VALIDADO' => 'Validar', 'OBSERVADO' => 'Observar', 'CORREGIDO' => 'Corregir', 'ANULADO' => 'Anular', 'PENDIENTE' => 'Pend.'] as $opcion => $label): ?>
                                                <button
                                                    type="button"
                                                    class="csb-state-btn csb-state-btn--<?= strtolower($opcion) ?> <?= $estado === $opcion ? 'is-active' : '' ?>"
                                                    data-csb-state-option="<?= $opcion ?>"
                                                    title="Marcar como <?= csb_h($opcion) ?>"
                                                >
                                                    <?= csb_h($label) ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($isAdmin): ?>
                                            <button type="button" class="csb-transfer-btn" data-csb-transfer-open title="Gestionar transbordo">
                                                <i class="bi bi-arrow-left-right"></i> Trans.
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="csb-icon-btn csb-icon-btn--save" data-csb-save="<?= $id ?>" title="Guardar revision" aria-label="Guardar revision">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<div class="modal fade csb-hojaruta-list-modal" id="csbHojaRutaListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="csb-modal-head">
                <div>
                    <span><i class="bi bi-card-list"></i> Vista de pantalla</span>
                    <h2>Hojas de Ruta de los viajes visibles</h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="csb-route-list-summary">
                    <div>
                        <span>Viajes visibles</span>
                        <strong data-csb-hojarutas-total>0</strong>
                    </div>
                    <div>
                        <span>Con Hoja de Ruta</span>
                        <strong data-csb-hojarutas-completas>0</strong>
                    </div>
                    <div>
                        <span>Pendientes</span>
                        <strong data-csb-hojarutas-pendientes>0</strong>
                    </div>
                </div>
                <p class="csb-route-list-help">
                    Este listado se genera unicamente con las filas actualmente cargadas y visibles en la pantalla. No realiza una nueva consulta a la base de datos.
                </p>
                <div class="csb-route-list" data-csb-hojarutas-list></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="csb-btn csb-btn--soft" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade csb-driver-modal" id="csbDriverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="csb-modal-head">
                <div>
                    <span><i class="bi bi-pencil-square"></i> Registro observado</span>
                    <h2>Editar conductor del consolidado</h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="csb-driver-current">
                    <span>Conductor actual</span>
                    <strong data-csb-driver-current>Sin seleccionar</strong>
                </div>
                <label class="csb-driver-search">
                    <span>Buscar conductor activo</span>
                    <input type="search" data-csb-driver-search placeholder="Nombre, DNI o licencia..." autocomplete="off">
                </label>
                <div class="csb-driver-list" data-csb-driver-list></div>
                <p class="csb-driver-help">
                    El texto del consolidado se actualizara y el JSON conservara el historial de cambios.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="csb-btn csb-btn--soft" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="csb-btn csb-btn--primary" data-csb-driver-save disabled>
                    <i class="bi bi-check2"></i> Guardar conductor
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade csb-transfer-modal" id="csbTransferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <form data-csb-transfer-form autocomplete="off">
                <input type="hidden" name="source_id" value="">
                <div class="csb-modal-head">
                    <div>
                        <span><i class="bi bi-arrow-left-right"></i> Gestión operativa</span>
                        <h2>Registrar transbordo</h2>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <section class="csb-transfer-source">
                        <div class="csb-transfer-source-head">
                            <div>
                                <span>Viaje seleccionado</span>
                                <strong data-csb-transfer-source-unit>Sin seleccionar</strong>
                            </div>
                            <span class="csb-transfer-source-id" data-csb-transfer-source-id>#0</span>
                        </div>
                        <div class="csb-transfer-source-grid">
                            <div><span>Fecha / hora</span><strong data-csb-transfer-source-datetime>-</strong></div>
                            <div><span>Servicio</span><strong data-csb-transfer-source-service>-</strong></div>
                            <div><span>Ruta</span><strong data-csb-transfer-source-route>-</strong></div>
                        </div>
                    </section>

                    <section class="csb-transfer-role-section">
                        <span class="csb-transfer-section-title">¿Qué ocurrió con la unidad seleccionada?</span>
                        <div class="csb-transfer-role-grid">
                            <label class="csb-transfer-role-card">
                                <input type="radio" name="source_role" value="TRANSBORDADO" checked>
                                <span class="csb-transfer-role-icon"><i class="bi bi-bus-front"></i></span>
                                <span>
                                    <strong>Fue TRANSBORDADA</strong>
                                    <small>Esta unidad no continuó el servicio y sus pasajeros pasaron a otra unidad.</small>
                                </span>
                            </label>
                            <label class="csb-transfer-role-card">
                                <input type="radio" name="source_role" value="TRANSBORDO">
                                <span class="csb-transfer-role-icon"><i class="bi bi-arrow-right-circle"></i></span>
                                <span>
                                    <strong>Realizó el TRANSBORDO</strong>
                                    <small>Esta unidad recibió pasajeros de otra unidad y continuó el servicio.</small>
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="csb-transfer-related">
                        <div class="csb-transfer-related-head">
                            <div>
                                <span>Viaje relacionado a generar</span>
                                <h3 data-csb-transfer-counterpart-title>Unidad que realizó el TRANSBORDO</h3>
                            </div>
                            <span class="csb-transfer-role-pill" data-csb-transfer-counterpart-role>TRANSBORDO</span>
                        </div>

                        <div class="csb-manual-grid">
                            <label>
                                <span>Hora salida</span>
                                <input type="time" name="hora_salida" required>
                            </label>
                            <label>
                                <span>Unidad relacionada</span>
                                <select name="idplaca" required>
                                    <option value="">Seleccionar unidad...</option>
                                    <?php foreach ($manualCatalog['placas'] as $placa): ?>
                                        <?php
                                        $busLabel = trim((string)($placa['bus'] ?? ''));
                                        $placaLabel = trim((string)($placa['placa'] ?? ''));
                                        $servicioLabel = trim((string)($placa['servicio'] ?? ''));
                                        $optionText = trim($busLabel . ($placaLabel !== '' ? ' - ' . $placaLabel : '') . ($servicioLabel !== '' ? ' | ' . $servicioLabel : ''));
                                        ?>
                                        <option value="<?= (int)$placa['id'] ?>"><?= csb_h($optionText !== '' ? $optionText : ('Unidad #' . (int)$placa['id'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Origen</span>
                                <select name="idorigen" required>
                                    <option value="">Seleccionar origen...</option>
                                    <?php foreach ($manualCatalog['sedes'] as $sede): ?>
                                        <option value="<?= (int)$sede['id'] ?>"><?= csb_h($sede['label'] ?? $sede['nombre'] ?? ('Sede #' . (int)$sede['id'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Destino</span>
                                <select name="iddestino" required>
                                    <option value="">Seleccionar destino...</option>
                                    <?php foreach ($manualCatalog['sedes'] as $sede): ?>
                                        <option value="<?= (int)$sede['id'] ?>"><?= csb_h($sede['label'] ?? $sede['nombre'] ?? ('Sede #' . (int)$sede['id'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="csb-manual-wide">
                                <span>Rutas intermedias</span>
                                <select name="ruta_ids[]" multiple size="5" class="csb-manual-routes">
                                    <?php foreach ($manualCatalog['sedes'] as $sede): ?>
                                        <option value="<?= (int)$sede['id'] ?>"><?= csb_h($sede['label'] ?? $sede['nombre'] ?? ('Sede #' . (int)$sede['id'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Registra la ruta real de la unidad relacionada. No repitas origen ni destino.</small>
                            </label>
                            <label class="csb-manual-wide">
                                <span>Comentario</span>
                                <textarea name="comentario" rows="3" placeholder="Motivo del transbordo, punto de incidencia o referencia operativa"></textarea>
                            </label>
                        </div>
                        <p class="csb-manual-help">La fecha operativa se hereda del viaje seleccionado. La nueva fila se crea con cierre 0 y programación 0; ambos registros quedan marcados como TRANSBORDADO / TRANSBORDO.</p>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="csb-btn csb-btn--soft" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="csb-btn csb-btn--primary" data-csb-transfer-save>
                        <i class="bi bi-arrow-left-right"></i> Registrar transbordo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade csb-manual-modal" id="csbManualTripModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form data-csb-manual-form autocomplete="off">
                <div class="csb-modal-head">
                    <div>
                        <span><i class="bi bi-plus-circle"></i> Registro manual</span>
                        <h2>Agregar viaje al consolidado</h2>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="csb-manual-grid">
                        <label>
                            <span>Fecha operativa</span>
                            <input type="date" name="fecha_operativa" value="<?= csb_h($fechaOperativa) ?>" required>
                        </label>
                        <label>
                            <span>Hora salida</span>
                            <input type="time" name="hora_salida" required>
                        </label>
                        <label class="csb-manual-wide">
                            <span>Unidad</span>
                            <select name="idplaca" required>
                                <option value="">Seleccionar unidad...</option>
                                <?php foreach ($manualCatalog['placas'] as $placa): ?>
                                    <?php
                                    $busLabel = trim((string)($placa['bus'] ?? ''));
                                    $placaLabel = trim((string)($placa['placa'] ?? ''));
                                    $servicioLabel = trim((string)($placa['servicio'] ?? ''));
                                    $optionText = trim($busLabel . ($placaLabel !== '' ? ' - ' . $placaLabel : '') . ($servicioLabel !== '' ? ' | ' . $servicioLabel : ''));
                                    ?>
                                    <option value="<?= (int)$placa['id'] ?>"><?= csb_h($optionText !== '' ? $optionText : ('Unidad #' . (int)$placa['id'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Origen</span>
                            <select name="idorigen" required>
                                <option value="">Seleccionar origen...</option>
                                <?php foreach ($manualCatalog['sedes'] as $sede): ?>
                                    <option value="<?= (int)$sede['id'] ?>"><?= csb_h($sede['label'] ?? $sede['nombre'] ?? ('Sede #' . (int)$sede['id'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Destino</span>
                            <select name="iddestino" required>
                                <option value="">Seleccionar destino...</option>
                                <?php foreach ($manualCatalog['sedes'] as $sede): ?>
                                    <option value="<?= (int)$sede['id'] ?>"><?= csb_h($sede['label'] ?? $sede['nombre'] ?? ('Sede #' . (int)$sede['id'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="csb-manual-wide">
                            <span>Rutas intermedias</span>
                            <select name="ruta_ids[]" multiple size="5" class="csb-manual-routes">
                                <?php foreach ($manualCatalog['sedes'] as $sede): ?>
                                    <option value="<?= (int)$sede['id'] ?>"><?= csb_h($sede['label'] ?? $sede['nombre'] ?? ('Sede #' . (int)$sede['id'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Selecciona solo paradas intermedias; no repitas origen ni destino.</small>
                        </label>
                        <label class="csb-manual-wide">
                            <span>Comentario</span>
                            <textarea name="comentario" rows="3" placeholder="Motivo o referencia del viaje manual"></textarea>
                        </label>
                    </div>
                    <p class="csb-manual-help">El viaje se guardara con cierre 0, programacion 0 y estado MANUAL.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="csb-btn csb-btn--soft" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="csb-btn csb-btn--primary" data-csb-manual-save>
                        <i class="bi bi-save"></i> Guardar viaje manual
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade csb-calendar-modal" id="csbCalendarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="csb-modal-head">
                <div>
                    <span><i class="bi bi-calendar3"></i> Solo administradores</span>
                    <h2>Calendario de programaciones cerradas</h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="csb-calendar-toolbar">
                    <label>
                        <span>Mes operativo</span>
                        <input type="month" value="<?= csb_h(substr($fechaOperativa, 0, 7)) ?>" data-csb-calendar-month>
                    </label>
                    <p>Cantidad de buses capturados por dia operativo en el consolidado.</p>
                </div>
                <div class="csb-calendar-grid" data-csb-calendar-grid>
                    <div class="csb-calendar-loading">Selecciona un mes para cargar el calendario.</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
window.N360_CSB = {
    csrf: <?= json_encode($csrfToken) ?>,
    endpoint: 'consolidado_salidas_buses.php',
    fechaOperativa: <?= json_encode($fechaOperativa) ?>,
    fechaInicio: <?= json_encode($fechaInicio) ?>,
    fechaFin: <?= json_encode($fechaFin) ?>,
    conductores: <?= json_encode($conductoresActivos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    report: {
        title: 'CONSOLIDADO DE SALIDAS DE BUSES',
        subtitle: 'Buses con programacion cerrada',
        docCode: 'FLOTA_CONS_SALIDAS',
        period: <?= json_encode($periodoOperativoLabel) ?>,
        revision: <?= json_encode($revision) ?>,
        buscar: <?= json_encode($buscar) ?>,
        generatedBy: <?= json_encode($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? '') ?>,
        dni: <?= json_encode($_SESSION['DNI'] ?? 'No registrado') ?>,
        logoLeft: <?= json_encode(n360_asset('img/icon.png')) ?>,
        logoRight: <?= json_encode(n360_asset('img/norte360_black.png')) ?>,
        fileBase: <?= json_encode('consolidado_salidas_buses_' . str_replace('-', '', $fechaInicio) . ($fechaInicio !== $fechaFin ? '_' . str_replace('-', '', $fechaFin) : '')) ?>
    }
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/flota_consolidado_salidas_n360.js') ?>"></script>
<?php n360_render_footer(); ?>
</body>
</html>


