<?php
session_start();

$QR_PROG_TOKEN = 'KfQxLmNvRpTsYzAaBcDeFgHiJkLmNoPqRsTuVwXyZaBcEfGh';
$modo_qr_programacion = (
    isset($_GET['qr']) &&
    $_GET['qr'] === 'programacion' &&
    hash_equals($QR_PROG_TOKEN, (string)($_GET['t'] ?? ''))
);

if (!$modo_qr_programacion && !isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

$session_permisos_raw = $_SESSION['permisos'] ?? [];
$session_vistas_raw   = $_SESSION['vistas'] ?? [];
$web_rol              = $_SESSION['web_rol'] ?? '';
$usuario_session      = $_SESSION['usuario'] ?? 'QR Operativo';
$dni_session          = $_SESSION['DNI'] ?? '—';

$HORARIO_CIERRE_URL   = 'https://dimgrey-cat-911574.hostingersite.com/ht/01_flota/cron_cierre_operativo_horarios.php?token=NORTE360_CIERRE_2026_FABIO_SEGURIDAD';

$permisos = ($session_permisos_raw === 'all') ? [] : (array)$session_permisos_raw;
$vistas   = ($session_permisos_raw === 'all') ? [] : (array)$session_vistas_raw;

if (!$modo_qr_programacion && $web_rol !== 'Admin') {
    $modulo_actual = 10;
    $vista_actuales = ["f-proghor"];

    if (!in_array($modulo_actual, $permisos) || empty(array_intersect($vista_actuales, $vistas))) {
        header("Location: ../login/none_permisos.php");
        exit();
    }
}

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
require_once("../trash/copidb_secure.php");
$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);

mysqli_report(MYSQLI_REPORT_OFF);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function horario_uid(): int {
    if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
        return (int) $_SESSION['id_usuario'];
    }
    if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
        return (int) $_SESSION['web_id_usuario'];
    }
    return 1;
}

function horario_now_peru_sql(): string {
    return "CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00')";
}

function horario_json(bool $ok, array $payload = [], string $message = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
        'data' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function horario_mysqli_error(mysqli $conn, string $fallback = 'Error de base de datos.'): string {
    $msg = trim((string) $conn->error);
    return $msg !== '' ? $msg : $fallback;
}

function horario_column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function horario_has_comentario_column(mysqli $conn): bool {
    static $hasColumn = null;
    if ($hasColumn === null) {
        $hasColumn = horario_column_exists($conn, 'tb_progbuses', 'clm_progbuses_comentario');
    }
    return $hasColumn;
}

function horario_set_hist_motivo(mysqli $conn, ?string $motivo): void {
    if ($motivo === null || trim($motivo) === '') {
        $conn->query("SET @motivo_hist_progbuses = NULL");
        return;
    }
    $safe = $conn->real_escape_string($motivo);
    $conn->query("SET @motivo_hist_progbuses = '{$safe}'");
}

function horario_clear_hist_motivo(mysqli $conn): void {
    $conn->query("SET @motivo_hist_progbuses = NULL");
}

function horario_time_to_sql(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        $hh = (int) $m[1];
        $mm = (int) $m[2];
        $ss = isset($m[3]) ? (int) $m[3] : 0;
        if ($hh >= 0 && $hh <= 23 && $mm >= 0 && $mm <= 59 && $ss >= 0 && $ss <= 59) {
            return sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
        }
    }
    return null;
}

function horario_format_hora(?string $value): string {
    $sql = horario_time_to_sql($value ?? '');
    return $sql ? substr($sql, 0, 5) : '';
}
function horario_normalizar_ruta(?string $value): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $partes = array_filter(array_map('trim', explode(',', $value)), fn($x) => $x !== '');
    $ids = [];

    foreach ($partes as $p) {
        if (!ctype_digit($p)) {
            throw new RuntimeException('La ruta contiene una sede inválida.');
        }

        $id = (int)$p;

        if ($id <= 0) {
            throw new RuntimeException('La ruta contiene una sede inválida.');
        }

        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return count($ids) ? implode(',', $ids) : null;
}

function horario_ruta_to_nombres(?string $ruta, array $mapSedes): string {
    $ruta = trim((string)$ruta);

    if ($ruta === '') {
        return '';
    }

    $nombres = [];

    foreach (explode(',', $ruta) as $idRaw) {
        $id = (int)trim($idRaw);
        if ($id > 0 && isset($mapSedes[$id])) {
            $nombres[] = $mapSedes[$id];
        }
    }

    return implode(' → ', $nombres);
}
function horario_operational_dates(): array {
    $tz = new DateTimeZone('America/Lima');
    $base = new DateTime('now', $tz);
    $sig = (clone $base)->modify('+1 day');
    return [
        'fecha_base' => $base->format('d/m/Y'),
        'fecha_sig' => $sig->format('d/m/Y'),
        'fecha_sig_corta' => $sig->format('d/m/y'),
        'ahora_iso' => $base->format(DateTimeInterface::ATOM),
    ];
}

function horario_inicializar_estado_buses(mysqli $conn, int $uid): void {
    $sql = "
        INSERT INTO tb_progbuses_estado_actual (
            clm_pgbestado_idplaca,
            clm_pgbestado_estado,
            clm_pgbestado_progid,
            clm_pgbestado_motivo,
            clm_pgbestado_datetimeupdated,
            clm_pgbestado_idusuario
        )
        SELECT
            p.clm_placas_id,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM tb_progbuses pb
                    WHERE pb.clm_progbuses_idplaca = p.clm_placas_id
                      AND pb.clm_progbuses_estado = 1
                ) THEN 'ASIGNADO'
                ELSE 'SIN_HORARIO'
            END,
            (
                SELECT pb2.clm_progbuses_progid
                FROM tb_progbuses pb2
                WHERE pb2.clm_progbuses_idplaca = p.clm_placas_id
                  AND pb2.clm_progbuses_estado = 1
                LIMIT 1
            ),
            NULL,
            " . horario_now_peru_sql() . ",
            ?
        FROM tb_placas p
        WHERE UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND UPPER(TRIM(IFNULL(p.clm_placas_TIPO_VEHÍCULO, ''))) IN ('BUS', 'CARGUERO')
          AND NOT EXISTS (
              SELECT 1
              FROM tb_progbuses_estado_actual ea
              WHERE ea.clm_pgbestado_idplaca = p.clm_placas_id
          )
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(horario_mysqli_error($conn));
    }
    $stmt->bind_param('i', $uid);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: horario_mysqli_error($conn);
        $stmt->close();
        throw new RuntimeException($err);
    }
    $stmt->close();
}

function horario_upsert_estado_bus(mysqli $conn, int $uid, int $idplaca, string $estado, ?int $progid = null, ?string $motivo = null): void {
    $sql = "
        INSERT INTO tb_progbuses_estado_actual (
            clm_pgbestado_idplaca,
            clm_pgbestado_estado,
            clm_pgbestado_progid,
            clm_pgbestado_motivo,
            clm_pgbestado_datetimeupdated,
            clm_pgbestado_idusuario
        ) VALUES (
            ?, ?, ?, ?, " . horario_now_peru_sql() . ", ?
        )
        ON DUPLICATE KEY UPDATE
            clm_pgbestado_estado = VALUES(clm_pgbestado_estado),
            clm_pgbestado_progid = VALUES(clm_pgbestado_progid),
            clm_pgbestado_motivo = VALUES(clm_pgbestado_motivo),
            clm_pgbestado_datetimeupdated = VALUES(clm_pgbestado_datetimeupdated),
            clm_pgbestado_idusuario = VALUES(clm_pgbestado_idusuario)
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(horario_mysqli_error($conn));
    }
    $stmt->bind_param('isisi', $idplaca, $estado, $progid, $motivo, $uid);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: horario_mysqli_error($conn);
        $stmt->close();
        throw new RuntimeException($err);
    }
    $stmt->close();
}

function horario_sync_estado_actual_bus(mysqli $conn, int $uid, ?int $idplaca, ?string $motivo = null, bool $preferirTaller = false): void {
    if (!$idplaca) {
        return;
    }
    $sql = "
        SELECT COUNT(*) AS cantidad, MIN(clm_progbuses_progid) AS progid_ref
        FROM tb_progbuses
        WHERE clm_progbuses_idplaca = ?
          AND clm_progbuses_estado = 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(horario_mysqli_error($conn));
    }
    $stmt->bind_param('i', $idplaca);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: horario_mysqli_error($conn);
        $stmt->close();
        throw new RuntimeException($err);
    }
    $row = $stmt->get_result()->fetch_assoc() ?: ['cantidad' => 0, 'progid_ref' => null];
    $stmt->close();
    $cantidad = (int)($row['cantidad'] ?? 0);
    $progidRef = isset($row['progid_ref']) ? (int)$row['progid_ref'] : null;


    if ($preferirTaller && stripos((string)$motivo, 'TALLER') !== false) {
        horario_upsert_estado_bus($conn, $uid, $idplaca, 'TALLER', $progidRef, $motivo);
        return;
    }

    if ($cantidad > 0) {
        horario_upsert_estado_bus($conn, $uid, $idplaca, 'ASIGNADO', $progidRef, "Asignado en {$cantidad} horario(s) activo(s)");
        return;
    }

    horario_upsert_estado_bus($conn, $uid, $idplaca, 'SIN_HORARIO', null, $motivo);

}

function horario_fetch_oficinas(mysqli $conn, string $tipo = 'TODAS'): array {
$sql = "
    SELECT 
        clm_sedes_id,
        IFNULL(clm_sedes_abr, '') AS oficina,
        IFNULL(clm_sedes_origendestino, '') AS origendestino,
        IFNULL(clm_sedes_grupo_pizarra, 'SIN GRUPO') AS grupo_pizarra,
        IFNULL(NULLIF(TRIM(clm_sedes_tipo_imagen_grupo), ''), 'PIZARRA') AS tipo_imagen_grupo,
        clm_sedes_orden_pizarra
    FROM tb_sedes
    WHERE IFNULL(clm_sedes_estado, 0) = 1
";
    if ($tipo === 'ORIGEN') {
        $sql .= " AND UPPER(TRIM(IFNULL(clm_sedes_origendestino, ''))) = 'ORIGEN' ";
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN clm_sedes_orden_pizarra IS NULL THEN 999999
                ELSE clm_sedes_orden_pizarra
            END ASC,
            clm_sedes_abr ASC
    ";

    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    return $res->fetch_all(MYSQLI_ASSOC);
}

function horario_fetch_panel_horarios(mysqli $conn, int $estado = 1): array {
    $comentarioSelect = horario_has_comentario_column($conn)
        ? "IFNULL(pb.clm_progbuses_comentario, '') AS clm_progbuses_comentario,"
        : "'' AS clm_progbuses_comentario,";
$sql = "
    SELECT
        pb.clm_progbuses_progid,
        pb.clm_progbuses_fechacreated,
        pb.clm_progbuses_idplaca,
        pb.clm_progbuses_idoficina_origen,
        pb.clm_progbuses_idoficina_destino,
        pb.clm_progbuses_ruta,
        pb.clm_progbuses_horasalida,
        pb.clm_progbuses_estado,
        pb.clm_progbuses_idusuario,
        pb.clm_progbuses_datetimeupdated,
        pb.clm_progbuses_motivo,
        {$comentarioSelect}
        IFNULL(p.clm_placas_BUS, '') AS bus,
        IFNULL(p.clm_placas_PLACA, '') AS placa,
        IFNULL(p.clm_placas_TIPO_VEHÍCULO, '') AS tipo_vehiculo,
        IFNULL(p.clm_placas_servicio, '') AS servicio_unidad,   
        IFNULL(ea.clm_pgbestado_estado, '') AS estado_actual_unidad,
        IFNULL(ea.clm_pgbestado_motivo, '') AS motivo_actual_unidad,     
        IFNULL(o1.clm_sedes_abr, '') AS oficina_origen,
        IFNULL(o2.clm_sedes_abr, '') AS oficina_destino,
        IFNULL(o1.clm_sedes_grupo_pizarra, 'SIN GRUPO') AS grupo_pizarra_origen,
        IFNULL(NULLIF(TRIM(o1.clm_sedes_tipo_imagen_grupo), ''), 'PIZARRA') AS tipo_imagen_grupo_origen,
        o1.clm_sedes_orden_pizarra AS orden_pizarra_origen
    FROM tb_progbuses pb
    LEFT JOIN tb_placas p ON p.clm_placas_id = pb.clm_progbuses_idplaca
    LEFT JOIN tb_progbuses_estado_actual ea ON ea.clm_pgbestado_idplaca = p.clm_placas_id
    LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
    LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
    WHERE pb.clm_progbuses_estado = ?
    ORDER BY 
        IFNULL(o1.clm_sedes_grupo_pizarra, 'SIN GRUPO') ASC,
        CASE
            WHEN o1.clm_sedes_orden_pizarra IS NULL THEN 999999
            ELSE o1.clm_sedes_orden_pizarra
        END ASC,
        o1.clm_sedes_abr ASC,
        pb.clm_progbuses_horasalida ASC,
        o2.clm_sedes_abr ASC,
        pb.clm_progbuses_progid ASC
";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
    $stmt->bind_param('i', $estado);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: horario_mysqli_error($conn);
        $stmt->close();
        throw new RuntimeException($err);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['hora_fmt'] = horario_format_hora($row['clm_progbuses_horasalida'] ?? '');
        $row['estado_fmt'] = ((int)($row['clm_progbuses_estado'] ?? 0) === 1) ? 'ACTIVO' : 'INACTIVO';
    }
    unset($row);
    return $rows;
}

function horario_fetch_buses_sin_horario(mysqli $conn): array {
    $sql = "
        SELECT p.clm_placas_id, IFNULL(p.clm_placas_BUS, '') AS bus, IFNULL(p.clm_placas_PLACA, '') AS placa, IFNULL(p.clm_placas_TIPO_VEHÍCULO, '') AS tipo_vehiculo
        FROM tb_progbuses_estado_actual ea
        INNER JOIN tb_placas p ON p.clm_placas_id = ea.clm_pgbestado_idplaca
        WHERE ea.clm_pgbestado_estado = 'SIN_HORARIO'
          AND UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND UPPER(TRIM(IFNULL(p.clm_placas_TIPO_VEHÍCULO, ''))) IN ('BUS', 'CARGUERO')
        ORDER BY p.clm_placas_BUS ASC, p.clm_placas_PLACA ASC
    ";
    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    return $res->fetch_all(MYSQLI_ASSOC);
}

function horario_fetch_buses_taller(mysqli $conn): array {
    $sql = "
        SELECT p.clm_placas_id, IFNULL(p.clm_placas_BUS, '') AS bus, IFNULL(p.clm_placas_PLACA, '') AS placa, IFNULL(p.clm_placas_TIPO_VEHÍCULO, '') AS tipo_vehiculo, IFNULL(ea.clm_pgbestado_motivo, '') AS motivo
        FROM tb_progbuses_estado_actual ea
        INNER JOIN tb_placas p ON p.clm_placas_id = ea.clm_pgbestado_idplaca
        WHERE ea.clm_pgbestado_estado = 'TALLER'
          AND UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND UPPER(TRIM(IFNULL(p.clm_placas_TIPO_VEHÍCULO, ''))) IN ('BUS', 'CARGUERO')
        ORDER BY p.clm_placas_BUS ASC, p.clm_placas_PLACA ASC
    ";
    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    return $res->fetch_all(MYSQLI_ASSOC);
}

function horario_fetch_buses_disponibles(mysqli $conn): array {
    $sql = "
        SELECT
            p.clm_placas_id,
            IFNULL(p.clm_placas_BUS, '') AS bus,
            IFNULL(p.clm_placas_PLACA, '') AS placa,
            IFNULL(p.clm_placas_TIPO_VEHÍCULO, '') AS tipo_vehiculo,
            IFNULL(a.cantidad_asignaciones, 0) AS cantidad_asignaciones,
            CASE
                WHEN IFNULL(a.cantidad_asignaciones, 0) > 0 THEN 'ASIGNADO'
                WHEN IFNULL(ea.clm_pgbestado_estado, 'SIN_HORARIO') = 'TALLER' THEN 'TALLER'
                ELSE 'SIN_HORARIO'
            END AS estado_programacion,
            IFNULL(ea.clm_pgbestado_motivo, '') AS motivo
        FROM tb_placas p
        LEFT JOIN (
            SELECT clm_progbuses_idplaca, COUNT(*) AS cantidad_asignaciones
            FROM tb_progbuses
            WHERE clm_progbuses_estado = 1
              AND clm_progbuses_idplaca IS NOT NULL
            GROUP BY clm_progbuses_idplaca
        ) a ON a.clm_progbuses_idplaca = p.clm_placas_id
        LEFT JOIN tb_progbuses_estado_actual ea ON ea.clm_pgbestado_idplaca = p.clm_placas_id
        WHERE UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND UPPER(TRIM(IFNULL(p.clm_placas_TIPO_VEHÍCULO, ''))) IN ('BUS', 'CARGUERO')
        ORDER BY IFNULL(a.cantidad_asignaciones, 0) ASC, p.clm_placas_BUS ASC, p.clm_placas_PLACA ASC
    ";
    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    return $res->fetch_all(MYSQLI_ASSOC);
}

function horario_fetch_historial(mysqli $conn, int $limit = 300): array {
    $limit = max(1, min(1000, $limit));
    $sql = "
        SELECT
            h.clm_hist_progbuses_id AS hist_id,
            UPPER(h.clm_hist_progbuses_accion) AS accion,
            h.clm_hist_progbuses_fechaevento AS fechaevento_raw,
            DATE_FORMAT(h.clm_hist_progbuses_fechaevento, '%d/%m/%Y %H:%i') AS fechaevento,
            h.clm_progbuses_progid AS progid,
            h.clm_progbuses_idplaca,
            h.clm_progbuses_idoficina_origen,
            h.clm_progbuses_idoficina_destino,
            h.clm_progbuses_ruta,
            h.clm_progbuses_horasalida,
            h.clm_progbuses_estado,
            h.clm_progbuses_idusuario,
            IFNULL(u.usuario, CONCAT('Usuario #', h.clm_progbuses_idusuario)) AS usuario_realizo,
            h.clm_progbuses_datetimeupdated,
            IFNULL(h.clm_progbuses_motivo, '') AS motivo,
            IFNULL(p.clm_placas_BUS, '') AS bus,
            IFNULL(p.clm_placas_PLACA, '') AS placa,
            IFNULL(o1.clm_sedes_abr, '') AS oficina_origen,
            IFNULL(o2.clm_sedes_abr, '') AS oficina_destino
        FROM tb_hist_progbuses h
        LEFT JOIN tb_placas p ON p.clm_placas_id = h.clm_progbuses_idplaca
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = h.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = h.clm_progbuses_idoficina_destino
        LEFT JOIN tb_usuarios u ON u.id_usuario = h.clm_progbuses_idusuario
        ORDER BY h.clm_hist_progbuses_fechaevento DESC, h.clm_hist_progbuses_id DESC
        LIMIT {$limit}
    ";
    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['hora_fmt'] = horario_format_hora($row['clm_progbuses_horasalida'] ?? '');
    }
    unset($row);
    return $rows;
}

function horario_fetch_summary(mysqli $conn): array {
    $sql = "
        SELECT COUNT(*) AS total_activos,
               SUM(CASE WHEN pb.clm_progbuses_idplaca IS NULL THEN 1 ELSE 0 END) AS total_sin_bus,
               SUM(CASE WHEN pb.clm_progbuses_idplaca IS NOT NULL THEN 1 ELSE 0 END) AS total_asignados
        FROM tb_progbuses pb
        WHERE pb.clm_progbuses_estado = 1
    ";
    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    $row = $res->fetch_assoc() ?: [];
    return [
        'total_activos' => (int)($row['total_activos'] ?? 0),
        'total_sin_bus' => (int)($row['total_sin_bus'] ?? 0),
        'total_asignados' => (int)($row['total_asignados'] ?? 0),
    ];
}

function horario_build_snapshot(mysqli $conn): array {
    $panel = horario_fetch_panel_horarios($conn, 1);
    $sinHorario = horario_fetch_buses_sin_horario($conn);
    $taller = horario_fetch_buses_taller($conn);
    $summary = horario_fetch_summary($conn);
    $summary['buses_sin_horario'] = count($sinHorario);
    $summary['buses_taller'] = count($taller);
    return [
        'fechas' => horario_operational_dates(),
        'summary' => $summary,
        'horarios' => $panel,
        'buses_sin_horario' => $sinHorario,
        'buses_taller' => $taller,
        'oficinas_origen' => horario_fetch_oficinas($conn, 'ORIGEN'),
        'oficinas_destino' => horario_fetch_oficinas($conn, 'TODAS'),
    ];
}

$horario_uid = horario_uid();

if (isset($_GET['ajax'])) {
    try {
        horario_inicializar_estado_buses($conn, $horario_uid);
        $ajax = trim((string)$_GET['ajax']);

        // MODO QR: SOLO LECTURA
        // No permite crear, editar, cambiar bus, remover, taller, inhabilitar, activar, etc.
        if ($modo_qr_programacion && $ajax !== 'snapshot') {
            horario_json(false, [], 'Acción no permitida en modo QR.');
        }

        if ($ajax === 'snapshot') horario_json(true, horario_build_snapshot($conn));
        if ($ajax === 'historial') horario_json(true, ['historial' => horario_fetch_historial($conn, (int)($_GET['limit'] ?? 300))]);
        if ($ajax === 'inhabilitados') horario_json(true, ['inhabilitados' => horario_fetch_panel_horarios($conn, 2)]);
        if ($ajax === 'buses_disponibles') horario_json(true, ['buses' => horario_fetch_buses_disponibles($conn)]);
        if ($ajax === 'oficinas') {
            $tipo = strtoupper(trim((string)($_GET['tipo'] ?? 'TODAS')));
            horario_json(true, ['oficinas' => horario_fetch_oficinas($conn, $tipo === 'ORIGEN' ? 'ORIGEN' : 'TODAS')]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') horario_json(false, [], 'Método no permitido.');

        if ($ajax === 'limpiar_pizarra') {
            if ($web_rol !== 'Admin') {
                horario_json(false, [], 'Solo administradores pueden limpiar la pizarra.');
            }
            horario_json(true, ['url' => $HORARIO_CIERRE_URL], 'Confirmacion recibida.');
        }

        $conn->begin_transaction();

        if ($ajax === 'create_horario') {
            $idOrigen = (int)($_POST['idof_origen'] ?? 0);
            $idDestino = (int)($_POST['idof_destino'] ?? 0);
            $ruta = horario_normalizar_ruta($_POST['ruta'] ?? '');
            $hora = horario_time_to_sql($_POST['horasalida'] ?? '');
            $comentario = trim((string)($_POST['comentario'] ?? ''));
            if (function_exists('mb_strlen') && mb_strlen($comentario, 'UTF-8') > 500) {
                $comentario = mb_substr($comentario, 0, 500, 'UTF-8');
            } elseif (!function_exists('mb_strlen') && strlen($comentario) > 500) {
                $comentario = substr($comentario, 0, 500);
            }
            $comentarioDb = $comentario !== '' ? $comentario : null;
            if ($idOrigen <= 0 || $idDestino <= 0) throw new RuntimeException('Selecciona origen y destino válidos.');
            if ($idOrigen === $idDestino) throw new RuntimeException('Origen y destino no pueden ser iguales.');
            if ($ruta !== null) {
                $rutaIds = array_map('intval', explode(',', $ruta));

                if (in_array($idOrigen, $rutaIds, true)) {
                    throw new RuntimeException('La ruta no puede incluir la oficina de origen.');
                }

                if (in_array($idDestino, $rutaIds, true)) {
                    throw new RuntimeException('La ruta no puede incluir la oficina de destino.');
                }
            }
            if (!$hora) throw new RuntimeException('La hora de salida es obligatoria.');

            if (horario_has_comentario_column($conn)) {
                $sql = "
                    INSERT INTO tb_progbuses (
                        clm_progbuses_fechacreated,
                        clm_progbuses_idplaca,
                        clm_progbuses_idoficina_origen,
                        clm_progbuses_idoficina_destino,
                        clm_progbuses_ruta,
                        clm_progbuses_horasalida,
                        clm_progbuses_estado,
                        clm_progbuses_idusuario,
                        clm_progbuses_motivo,
                        clm_progbuses_comentario
                    ) VALUES (
                        " . horario_now_peru_sql() . ",
                        NULL,
                        ?, ?, ?, ?, 1, ?, 'Creacion inicial del horario', ?
                    )
                ";

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
                $stmt->bind_param('iissis', $idOrigen, $idDestino, $ruta, $hora, $horario_uid, $comentarioDb);
            } else {
              $sql = "
                  INSERT INTO tb_progbuses (
                      clm_progbuses_fechacreated,
                      clm_progbuses_idplaca,
                      clm_progbuses_idoficina_origen,
                      clm_progbuses_idoficina_destino,
                      clm_progbuses_ruta,
                      clm_progbuses_horasalida,
                      clm_progbuses_estado,
                      clm_progbuses_idusuario,
                      clm_progbuses_motivo
                  ) VALUES (
                      " . horario_now_peru_sql() . ",
                      NULL,
                      ?, ?, ?, ?, 1, ?, 'Creacion inicial del horario'
                  )
              ";

              $stmt = $conn->prepare($sql);
              if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
              $stmt->bind_param('iissi', $idOrigen, $idDestino, $ruta, $hora, $horario_uid);
            }

            if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
            $stmt->close();
            $conn->commit();
            horario_json(true, horario_build_snapshot($conn), 'Horario creado correctamente.');
        }

        if ($ajax === 'editar_hora') {
            $progid = (int)($_POST['progid'] ?? 0);
            $nuevaHora = horario_time_to_sql($_POST['horasalida'] ?? '');
            $nuevoDestino = (int)($_POST['idof_destino'] ?? 0);
            $nuevaRuta = horario_normalizar_ruta($_POST['ruta'] ?? '');

            if ($progid <= 0 || !$nuevaHora || $nuevoDestino <= 0) {
                throw new RuntimeException('Datos incompletos para editar el horario.');
            }

            $stmt = $conn->prepare("
                SELECT 
                    pb.clm_progbuses_horasalida,
                    pb.clm_progbuses_idoficina_origen,
                    pb.clm_progbuses_idoficina_destino,
                    pb.clm_progbuses_ruta,
                    IFNULL(o1.clm_sedes_abr, '') AS origen,
                    IFNULL(o2.clm_sedes_abr, '') AS destino_actual
                FROM tb_progbuses pb
                LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
                LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
                WHERE pb.clm_progbuses_progid = ?
                LIMIT 1
            ");

            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));

            $stmt->bind_param('i', $progid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                throw new RuntimeException('No se encontró el horario.');
            }

            $idOrigenActual = (int)($row['clm_progbuses_idoficina_origen'] ?? 0);
            $idDestinoActual = (int)($row['clm_progbuses_idoficina_destino'] ?? 0);

            if ($idOrigenActual <= 0) {
                throw new RuntimeException('El horario no tiene origen válido.');
            }

            if ($idOrigenActual === $nuevoDestino) {
                throw new RuntimeException('El destino no puede ser igual al origen.');
            }
            if ($nuevaRuta !== null) {
                $rutaIds = array_map('intval', explode(',', $nuevaRuta));

                if (in_array($idOrigenActual, $rutaIds, true)) {
                    throw new RuntimeException('La ruta no puede incluir la oficina de origen.');
                }

                if (in_array($nuevoDestino, $rutaIds, true)) {
                    throw new RuntimeException('La ruta no puede incluir la oficina de destino.');
                }
            }
            $stmt = $conn->prepare("
                SELECT clm_sedes_abr 
                FROM tb_sedes 
                WHERE clm_sedes_id = ?
                  AND IFNULL(clm_sedes_estado, 0) = 1
                LIMIT 1
            ");

            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));

            $stmt->bind_param('i', $nuevoDestino);
            $stmt->execute();
            $destRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$destRow) {
                throw new RuntimeException('Selecciona un destino válido y activo.');
            }

            $horaAnterior = horario_format_hora($row['clm_progbuses_horasalida'] ?? '');
            $horaNuevaFmt = horario_format_hora($nuevaHora);

            $destinoAnteriorTxt = trim((string)($row['destino_actual'] ?? ''));
            $destinoNuevoTxt = trim((string)($destRow['clm_sedes_abr'] ?? ''));

            $cambioHora = ($horaAnterior !== $horaNuevaFmt);
            $cambioDestino = ($idDestinoActual !== $nuevoDestino);
            $rutaAnterior = trim((string)($row['clm_progbuses_ruta'] ?? ''));
            $rutaNueva = trim((string)($nuevaRuta ?? ''));

            $cambioRuta = ($rutaAnterior !== $rutaNueva);
            if (!$cambioHora && !$cambioDestino && !$cambioRuta) {
                throw new RuntimeException('La hora, el destino y la ruta son iguales a los actuales.');
            }

            $partesMotivo = [];

            if ($cambioHora) {
                $partesMotivo[] = "hora de {$horaAnterior} a {$horaNuevaFmt}";
            }

            if ($cambioDestino) {
                $partesMotivo[] = "destino de {$destinoAnteriorTxt} a {$destinoNuevoTxt}";
            }
            if ($cambioRuta) {
                $partesMotivo[] = "ruta actualizada";
            }
            $motivoAuto = "Reprogramación de horario: " . implode(' y ', $partesMotivo);

            horario_set_hist_motivo($conn, $motivoAuto);

            $stmt = $conn->prepare("
              UPDATE tb_progbuses 
              SET 
                  clm_progbuses_horasalida = ?,
                  clm_progbuses_idoficina_destino = ?,
                  clm_progbuses_ruta = ?,
                  clm_progbuses_idusuario = ?,
                  clm_progbuses_motivo = NULL
              WHERE clm_progbuses_progid = ?
            ");

            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));

            $stmt->bind_param('sisii', $nuevaHora, $nuevoDestino, $nuevaRuta, $horario_uid, $progid);

            if (!$stmt->execute()) {
                $err = $stmt->error ?: horario_mysqli_error($conn);
                $stmt->close();
                throw new RuntimeException($err);
            }

            $stmt->close();
            horario_clear_hist_motivo($conn);

            $conn->commit();
            horario_json(true, horario_build_snapshot($conn), 'Horario actualizado correctamente.');
        }

        if ($ajax === 'asignar_bus') {
            $progid = (int)($_POST['progid'] ?? 0);
            $idplaca = (int)($_POST['idplaca'] ?? 0);
            if ($progid <= 0 || $idplaca <= 0) throw new RuntimeException('Selecciona un horario y un bus válido.');
            $stmt = $conn->prepare("UPDATE tb_progbuses SET clm_progbuses_idplaca = ?, clm_progbuses_estado = 1, clm_progbuses_idusuario = ?, clm_progbuses_motivo = NULL WHERE clm_progbuses_progid = ?");
            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
            $stmt->bind_param('iii', $idplaca, $horario_uid, $progid);
            if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
            $stmt->close();
            horario_sync_estado_actual_bus($conn, $horario_uid, $idplaca);
            $conn->commit();
            horario_json(true, horario_build_snapshot($conn), 'Bus asignado correctamente.');
        }

        if ($ajax === 'marcar_bus_taller') {
            $idplaca = (int)($_POST['idplaca'] ?? 0);
            $motivo = trim((string)($_POST['motivo'] ?? ''));

            if ($idplaca <= 0) {
                throw new RuntimeException('Unidad inválida.');
            }

            if ($motivo === '') {
                $motivo = 'TALLER: unidad enviada a taller desde estado en espera / sin horario';
            }

            $stmt = $conn->prepare("
                SELECT
                    p.clm_placas_id,
                    IFNULL(p.clm_placas_BUS, '') AS bus,
                    IFNULL(p.clm_placas_PLACA, '') AS placa,
                    IFNULL(ea.clm_pgbestado_estado, 'SIN_HORARIO') AS estado_actual,
                    (
                        SELECT COUNT(*)
                        FROM tb_progbuses pb
                        WHERE pb.clm_progbuses_idplaca = p.clm_placas_id
                          AND pb.clm_progbuses_estado = 1
                    ) AS horarios_activos
                FROM tb_placas p
                LEFT JOIN tb_progbuses_estado_actual ea
                    ON ea.clm_pgbestado_idplaca = p.clm_placas_id
                WHERE p.clm_placas_id = ?
                  AND UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
                  AND UPPER(TRIM(IFNULL(p.clm_placas_TIPO_VEHÍCULO, ''))) IN ('BUS', 'CARGUERO')
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(horario_mysqli_error($conn));
            }

            $stmt->bind_param('i', $idplaca);
            $stmt->execute();
            $rowUnidad = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$rowUnidad) {
                throw new RuntimeException('No se encontró la unidad activa.');
            }

            if ((int)($rowUnidad['horarios_activos'] ?? 0) > 0) {
                throw new RuntimeException('Esta unidad tiene un horario activo. Usa la opción Retirar o Cambiar unidad.');
            }

            $estadoActual = strtoupper(trim((string)($rowUnidad['estado_actual'] ?? '')));

            if ($estadoActual !== 'SIN_HORARIO') {
                throw new RuntimeException('Solo puedes enviar a taller unidades que estén en espera / sin horario.');
            }

            horario_upsert_estado_bus(
                $conn,
                $horario_uid,
                $idplaca,
                'TALLER',
                null,
                $motivo
            );

            $conn->commit();

            horario_json(
                true,
                horario_build_snapshot($conn),
                'Unidad enviada a taller correctamente.'
            );
        }


        if ($ajax === 'marcar_bus_sin_horario') {
            $idplaca = (int)($_POST['idplaca'] ?? 0);
            $motivo = trim((string)($_POST['motivo'] ?? ''));

            if ($idplaca <= 0) {
                throw new RuntimeException('Unidad inválida.');
            }

            if ($motivo === '') {
                $motivo = 'Unidad liberada de taller y enviada a espera / sin horario';
            }

            $stmt = $conn->prepare("
                SELECT
                    p.clm_placas_id,
                    IFNULL(p.clm_placas_BUS, '') AS bus,
                    IFNULL(p.clm_placas_PLACA, '') AS placa,
                    IFNULL(ea.clm_pgbestado_estado, 'SIN_HORARIO') AS estado_actual,
                    (
                        SELECT COUNT(*)
                        FROM tb_progbuses pb
                        WHERE pb.clm_progbuses_idplaca = p.clm_placas_id
                          AND pb.clm_progbuses_estado = 1
                    ) AS horarios_activos
                FROM tb_placas p
                LEFT JOIN tb_progbuses_estado_actual ea
                    ON ea.clm_pgbestado_idplaca = p.clm_placas_id
                WHERE p.clm_placas_id = ?
                  AND UPPER(TRIM(IFNULL(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
                  AND UPPER(TRIM(IFNULL(p.clm_placas_TIPO_VEHÍCULO, ''))) IN ('BUS', 'CARGUERO')
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(horario_mysqli_error($conn));
            }

            $stmt->bind_param('i', $idplaca);
            $stmt->execute();
            $rowUnidad = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$rowUnidad) {
                throw new RuntimeException('No se encontró la unidad activa.');
            }

            if ((int)($rowUnidad['horarios_activos'] ?? 0) > 0) {
                throw new RuntimeException('Esta unidad aún tiene un horario activo. Primero retírala del horario o cambia la unidad.');
            }

            $estadoActual = strtoupper(trim((string)($rowUnidad['estado_actual'] ?? '')));

            if ($estadoActual !== 'TALLER') {
                throw new RuntimeException('Solo puedes liberar unidades que estén actualmente en taller.');
            }

            horario_upsert_estado_bus(
                $conn,
                $horario_uid,
                $idplaca,
                'SIN_HORARIO',
                null,
                $motivo
            );

            $conn->commit();

            horario_json(
                true,
                horario_build_snapshot($conn),
                'Unidad liberada de taller correctamente.'
            );
        }



        if (in_array($ajax, ['cambiar_bus', 'remover_bus', 'inactivar_horario', 'activar_horario'], true)) {
            $progid = (int)($_POST['progid'] ?? 0);
            if ($progid <= 0) throw new RuntimeException('Horario inválido.');
            $stmt = $conn->prepare("SELECT clm_progbuses_idplaca FROM tb_progbuses WHERE clm_progbuses_progid = ? LIMIT 1");
            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
            $stmt->bind_param('i', $progid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) throw new RuntimeException('No se encontró el horario.');
            $idplacaActual = isset($row['clm_progbuses_idplaca']) ? (int)$row['clm_progbuses_idplaca'] : null;
            $motivo = trim((string)($_POST['motivo'] ?? ''));

            if ($ajax === 'cambiar_bus') {
                $idplacaNueva = (int)($_POST['idplaca'] ?? 0);
                if ($idplacaNueva <= 0) throw new RuntimeException('Selecciona la nueva unidad.');
                if ($idplacaActual && $idplacaActual === $idplacaNueva) throw new RuntimeException('Has seleccionado la misma unidad que ya tiene el horario.');
                horario_set_hist_motivo($conn, $motivo);
                $stmt = $conn->prepare("UPDATE tb_progbuses SET clm_progbuses_idplaca = ?, clm_progbuses_estado = 1, clm_progbuses_idusuario = ?, clm_progbuses_motivo = NULL WHERE clm_progbuses_progid = ?");
                if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
                $stmt->bind_param('iii', $idplacaNueva, $horario_uid, $progid);
                if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
                $stmt->close();
                horario_clear_hist_motivo($conn);
                if ($idplacaActual && $idplacaActual !== $idplacaNueva) horario_sync_estado_actual_bus($conn, $horario_uid, $idplacaActual, $motivo, stripos($motivo, 'TALLER') !== false);
                horario_sync_estado_actual_bus($conn, $horario_uid, $idplacaNueva);
                $conn->commit();
                horario_json(true, horario_build_snapshot($conn), 'Bus cambiado correctamente.');
            }

            if ($ajax === 'remover_bus') {
                horario_set_hist_motivo($conn, $motivo);
                $stmt = $conn->prepare("UPDATE tb_progbuses SET clm_progbuses_idplaca = NULL, clm_progbuses_estado = 1, clm_progbuses_idusuario = ?, clm_progbuses_motivo = NULL WHERE clm_progbuses_progid = ?");
                if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
                $stmt->bind_param('ii', $horario_uid, $progid);
                if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
                $stmt->close();
                horario_clear_hist_motivo($conn);
                if ($idplacaActual) horario_sync_estado_actual_bus($conn, $horario_uid, $idplacaActual, $motivo, stripos($motivo, 'TALLER') !== false);
                $conn->commit();
                horario_json(true, horario_build_snapshot($conn), 'Bus removido del horario.');
            }

            if ($ajax === 'inactivar_horario') {
                horario_set_hist_motivo($conn, $motivo);
                $stmt = $conn->prepare("UPDATE tb_progbuses SET clm_progbuses_idplaca = NULL, clm_progbuses_estado = 2, clm_progbuses_idusuario = ?, clm_progbuses_motivo = NULL WHERE clm_progbuses_progid = ?");
                if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
                $stmt->bind_param('ii', $horario_uid, $progid);
                if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
                $stmt->close();
                horario_clear_hist_motivo($conn);
                if ($idplacaActual) horario_sync_estado_actual_bus($conn, $horario_uid, $idplacaActual, $motivo, false);
                $conn->commit();
                horario_json(true, horario_build_snapshot($conn), 'Horario inhabilitado correctamente.');
            }

            if ($ajax === 'activar_horario') {
                horario_set_hist_motivo($conn, $motivo);
                $stmt = $conn->prepare("UPDATE tb_progbuses SET clm_progbuses_idplaca = NULL, clm_progbuses_estado = 1, clm_progbuses_idusuario = ?, clm_progbuses_motivo = NULL WHERE clm_progbuses_progid = ?");
                if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
                $stmt->bind_param('ii', $horario_uid, $progid);
                if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
                $stmt->close();
                horario_clear_hist_motivo($conn);
                if ($idplacaActual) horario_upsert_estado_bus($conn, $horario_uid, $idplacaActual, 'SIN_HORARIO', null, $motivo);
                $conn->commit();
                horario_json(true, horario_build_snapshot($conn), 'Horario activado correctamente.');
            }
        }

        throw new RuntimeException('Acción AJAX no reconocida.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        try { horario_clear_hist_motivo($conn); } catch (Throwable $ignore) {}
        horario_json(false, [], $e->getMessage());
    }
}

$initialSnapshot = [];
$initialError = '';
try {
    horario_inicializar_estado_buses($conn, $horario_uid);
    $initialSnapshot = horario_build_snapshot($conn);
} catch (Throwable $e) {
    $initialError = $e->getMessage();
    $initialSnapshot = [
        'fechas' => horario_operational_dates(),
        'summary' => ['total_activos'=>0,'total_sin_bus'=>0,'total_asignados'=>0,'buses_sin_horario'=>0,'buses_taller'=>0],
        'horarios' => [],
        'buses_sin_horario' => [],
        'buses_taller' => [],
        'oficinas_origen' => [],
        'oficinas_destino' => [],
    ];
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Programación de Horarios | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">     
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>


    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }

        .card {
            background: #fff;
            max-width: 700px;
            margin: 40px auto 20px auto;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        h2 {
            text-align: center;
            color: #2c3e50;
        }

        form {
            margin-bottom: 25px;
        }

        input[type=text] {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            margin-bottom: 15px;
        }

        button {
            background: #2980b9;
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
        }

        button:hover {
            background: #1c5980;
        }

        .resultado {
            font-size: 16px;
            color: #34495e;
            line-height: 1.7;
        }

        section {
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        section h3 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 18px;
        }

        ul {
            list-style: none;
            padding-left: 0;
        }

        ul li {
            margin-bottom: 8px;
        }

        .img-block {
            text-align: center;
            margin-top: 15px;
        }

        .img-block img {
            max-width: 100%;
            height: auto;
            max-height: 180px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .img-block p {
            margin-bottom: 6px;
            font-weight: bold;
            color: #555;
        }

        .no-image {
            color: #aaa;
            font-style: italic;
        }

        .codigo {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 18px;
            text-align: center;
        }

        .valid { color: #27ae60; font-weight: bold; text-align: center; margin-bottom: 15px; }
        .invalid { color: #c0392b; font-weight: bold; text-align: center; margin-bottom: 15px; }

        .logo-inicio {
    display: block;
    margin: 0 auto 20px auto;
    max-width: 200px;
    width: 100%;
    height: auto;
}
.metodos-extra {
    background: #fff;
    border-radius: 12px;
    padding: 25px 20px;
    margin: 40px auto 20px auto;
    max-width: 750px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    text-align: center;
}

.metodos-extra h3 {
    font-size: 20px;
    margin-bottom: 25px;
    color: #2c3e50;
}

.opciones-validacion {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 20px;
}

.card-opcion {
    background: #3498db;
    color: white;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    font-size: 17px;
    font-weight: bold;
    width: 180px;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transition: background 0.3s, transform 0.3s;
}

.card-opcion:hover {
    background: #21618c;
    transform: scale(1.05);
}

hr {
    border: none;
    height: 2px;
    background: linear-gradient(to right, #3498db, yellow, #3498db);
    margin: 50px auto 30px auto;
    width: 80%;
    border-radius: 4px;
}
/* BOTÓN FLOTANTE DE SOPORTE */
.btn-flotante {
    position: fixed;
    bottom: 25px;
    right: 25px;
    background: #28a745;
    color: white;
    padding: 15px 20px;
    border-radius: 50px;
    font-size: 18px;
    text-decoration: none;
    box-shadow: 0 6px 12px rgba(0,0,0,0.2);
    transition: background 0.3s, transform 0.3s;
    z-index: 1000;
}

.btn-flotante:hover {
    background: #218838;
    transform: scale(1.1);
}
.main-header {
    background: #2c3e50;
    width: 100%;
    padding: 20px 30px;
    color: white;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    box-sizing: border-box;
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    width: 100%;
    max-width: none;
    padding: 0 30px;
    box-sizing: border-box;
    gap: 20px;
    flex-wrap: wrap;
}

.logo-bloque {
    display: flex;
    align-items: center;
}

.logo-header {
    max-width: 60px;
    height: auto;
    width: auto;
}
.logo-header2 {
    max-width: 60px;
    height: auto;
    max-width: 300px;
}
.logo-header3 {
    align-items: center;

    max-width: 150px;
    height: auto;
    width: auto;
}
.separador-vertical {
    width: 4px;
    height: 50px;
    background: #ecf0f1;
    margin: 0 10px;
}



.main-footer {
    background: #2c3e50;
    color: white;
    padding: 30px 20px;
    font-size: 14px;
    width: 100%;
    box-sizing: border-box;
}

.footer-top {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}


.footer-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.footer-title {
    font-weight: bold;
    font-size: 16px;
    margin: 0 0 10px 0;
}

.footer-cajas {
    display: flex;
    gap: 15px;
}

.footer-box {
    padding: 10px;
    border-radius: 8px;
    width: 40px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.footer-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.footer-copy {
    text-align: center;
    margin-top: 30px;
    font-size: 13px;
    color: #ccc;
}




@media (max-width: 600px) {
    .header-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 10px;
        padding: 10px 20px;
    }
    .separador-vertical {
        display: none;
    }
    
    .logo-header {
        display: none;

}
    
            .card, .metodos-extra {
                padding: 20px;
margin: 20px
            }

            h2 {
                font-size: 22px;
            }

            section h3 {
                font-size: 16px;
            }
        }


        @keyframes pulse {
    0% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7);
    }
    70% {
        transform: scale(1.08);
        box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
    }
    100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
    }
}

.btn-flotante {
    animation: pulse 6s infinite;
}
@keyframes shimmer {
    0% {
        background-position: -200% 0;
    }
    100% {
        background-position: 200% 0;
    }
}

.btn-validar {
    background: linear-gradient(120deg, #2980b9 30%, #3498db 50%, #2980b9 70%);
    background-size: 200% auto;
    color: white;
    padding: 12px 24px;
    font-size: 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    width: 100%;
    animation: shimmer 4s infinite linear;
    transition: transform 0.3s ease;
}

.btn-validar:hover {
    transform: scale(1.05);
}
@keyframes movingLine {
  0% {
    background-position: -200% 0;
  }
  100% {
    background-position: 200% 0;
  }
}

.animated-border {
  background: linear-gradient(
    110deg,
    #2c3e50 10%,
    #34495e 50%,
    #2c3e50 90%
  );
  background-size: 300% 100%;
  animation: movingLine 6s linear infinite;
}
.catalogo-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    padding-top: 20px;
}

.product-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: transform 0.2s;
}

.product-card:hover {
    transform: scale(1.02);
}

.product-card img {
    max-width: 100%;
    max-height: 150px;
    border-radius: 8px;
    object-fit: cover;
    margin-bottom: 12px;
}

.product-card h4 {
    color: #2c3e50;
    font-size: 16px;
    margin-bottom: 8px;
    text-align: center;
}

.product-card p {
    font-size: 14px;
    color: #555;
    margin: 2px 0;
    text-align: center;
}

.pagination {
    text-align: center;
    margin-top: 30px;
}

.pagination a {
    margin: 0 5px;
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 6px;
    background: #3498db;
    color: white;
    font-weight: bold;
    transition: background 0.3s;
}

.pagination a:hover {
    background: #21618c;
}

.pagination strong {
    margin: 0 5px;
    color: #2980b9;
}



.modal {
  display: none;
  position: fixed;
  z-index: 9999;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.5);
  overflow: auto;
}

.modal-content {
  background-color: #fff;
  margin: 5% auto;
  padding: 30px;
  border-radius: 12px;
  max-width: 900px;
  width: 90%;
  animation: fadeIn 0.3s ease;
  box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}

.cerrar {
  float: right;
  font-size: 24px;
  color: #aaa;
  font-weight: bold;
  cursor: pointer;
}

.cerrar:hover {
  color: #e74c3c;
}

/* Estilo tabla dentro del modal */
.modal-content table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 20px;
}

.modal-content th, .modal-content td {
  padding: 10px 14px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}

.modal-content th {
  background-color: #2c3e50;
  color: white;
}

.modal-content tr:hover {
  background-color: #f1f1f1;
}


#popup-exito {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    animation: fadeIn 0.4s ease forwards;
}

#popup-exito .mensaje {
    background: linear-gradient(to left, #2ecc71, #27ae60);
    padding: 20px 40px;
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
    font-size: 20px;
    font-weight: bold;
    color: white;
    text-align: center;
    animation: scaleIn 0.4s ease forwards;
    transform: scale(0.8);
    opacity: 0;
}

@keyframes fadeIn {
    to {
        opacity: 1;
    }
}

@keyframes scaleIn {
    to {
        transform: scale(1);
        opacity: 1;
    }
}

@keyframes fadeOut {
    to {
        opacity: 0;
        transform: scale(0.9);
    }
}


.check-icon {
  width: 80px;
  height: 80px;
  stroke: #fff;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  background: #2ecc71;
  border-radius: 50%;
  padding: 10px;
  box-shadow: 0 0 15px rgba(0, 0, 0, 0.3);
  margin: 0 auto 10px auto;
  display: block;
}

.check-circle {
  stroke-dasharray: 157;
  stroke-dashoffset: 157;
  animation: drawCircle 0.6s ease-out forwards;
}

.check-mark {
  stroke-dasharray: 36;
  stroke-dashoffset: 36;
  animation: drawCheck 0.4s ease-out 0.5s forwards;
}

.texto-popup {
  margin-top: 10px;
  font-size: 18px;
  color: white;
  font-weight: bold;
  animation: fadeInText 0.4s ease-in 0.8s forwards;
  opacity: 0;
}

@keyframes drawCircle {
  to {
    stroke-dashoffset: 0;
  }
}

@keyframes drawCheck {
  to {
    stroke-dashoffset: 0;
  }
}

@keyframes fadeInText {
  to {
    opacity: 1;
  }
}
.formulario-entrevista {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.campo-form {
    display: flex;
    flex-direction: column;
}

.campo-form label {
    font-weight: bold;
    color: #2c3e50;
    margin-bottom: 6px;
}

.campo-form input,
.campo-form textarea {
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 10px;
    font-size: 15px;
    transition: border 0.3s;
}

.campo-form input:focus,
.campo-form textarea:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
}

.grupo-flex {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.grupo-flex .campo-form {
    flex: 1;
}

    .filtros {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 20px;
      margin: 20px;
    }

    .filtros input, .filtros select {
      padding: 10px;
      border-radius: 8px;
      border: 1px solid #ccc;
      min-width: 180px;
    }
        .tabla-contenedor {
            overflow-x: auto;
            padding: 10px;
            display: flex;
            justify-content: center;
        padding: 10px;
        }

        table {
            width: 70%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            min-width: 600px;
            
        }

        th, td {
            padding: 14px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background-color: #2c3e50;
            color: white;
        }

        tr:hover {
            background-color: #f1f1f1;
        }

        .volver-btn {
            display: inline-block;
            margin: 20px auto;
            background: linear-gradient(120deg, #2980b9, #3498db, #2980b9);
            background-size: 200% auto;
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
            transition: transform 0.3s ease;
            animation: shimmer 3s infinite linear;
            text-align: center;
        }

        .volver-btn:hover {
            background: #1c5980;
        }


        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }

        @media (max-width: 600px) {
        .tabla-contenedor {
            overflow-x: auto;
            justify-content: flex-start;
        }
        table {
            min-width: 100%;
        }
        }
.input-evaluacion {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid #ccc;
  border-radius: 10px;
  font-size: 15px;
  transition: border 0.3s, box-shadow 0.3s;
  font-family: 'Segoe UI', sans-serif;
}

.input-evaluacion:focus {
  border-color: #3498db;
  box-shadow: 0 0 5px rgba(52, 152, 219, 0.4);
  outline: none;
}

#estadoSelect {
  appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg fill='%233498db' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 20px;
}
.btn-cv-profesional {
    display: inline-block;
    background: linear-gradient(90deg, #1abc9c, #16a085);
    color: white;
    padding: 12px 28px;
    font-size: 16px;
    font-weight: bold;
    border-radius: 30px;
    text-decoration: none;
    box-shadow: 0 6px 14px rgba(22, 160, 133, 0.4);
    transition: all 0.3s ease;
    position: relative;
}

.btn-cv-profesional:hover {
    background: linear-gradient(90deg, #16a085, #1abc9c);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(22, 160, 133, 0.5);
}

.icono-pdf {
    font-size: 20px;
    margin-right: 10px;
}
.nav-bar-pro {
    background: #34495e;
    box-shadow: inset 0 -2px 4px rgba(0,0,0,0.1);
    overflow-x: auto;
    white-space: nowrap;
}

.nav-list-pro {
    list-style: none;
    margin: 0;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 30px;
}

.nav-list-pro li a {
    color: white;
    font-weight: bold;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 30px;
    transition: background 0.3s, transform 0.3s;
    position: relative;
}

.nav-list-pro li a:hover {
    background: #2c3e50;
    transform: scale(1.05);
}

.nav-list-pro li a::after {
    content: '';
    position: absolute;
    height: 3px;
    background: #3498db;
    width: 0%;
    left: 50%;
    bottom: 4px;
    transition: all 0.3s ease-in-out;
    transform: translateX(-50%);
}

.nav-list-pro li a:hover::after {
    width: 60%;
}

@media (max-width: 768px) {
  .nav-list-pro {
    gap: 16px;
    padding: 10px;
  }

  .nav-list-pro li a {
    font-size: 14px;
    padding: 8px 12px;
  }
}
.subnav {
  display: flex;
  gap: 20px;
  padding: 12px 30px;
  background: #dff3f9;
  border-bottom: 3px solid #3498db;
  animation: fadeIn 0.3s ease;
}

.subnav a {
  color: #2c3e50;
  font-weight: 600;
  text-decoration: none;
  background: #ecf0f1;
  padding: 8px 16px;
  border-radius: 20px;
  transition: all 0.3s ease;
}

.subnav a:hover {
  background: #3498db;
  color: white;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.usuario-barra {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10px;
  color: white;
  font-weight: bold;
}
.usuario-barra img {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: white;
  padding: 2px;
}
.usuario-barra span {
  font-weight: bold;
  font-size: 15px;
  white-space: nowrap;
}
.usuario-dropdown {
  position: absolute;
  top: 100%;
  right: 30px;
  margin-top: 5px;
  background: white;
  color: #2c3e50;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  padding: 15px 20px;
  min-width: 220px;
  display: none;
  z-index: 999;
  font-size: 15px;
  animation: fadeIn 0.3s ease-in-out;
    transition: all 0.3s ease-in-out;
}

.usuario-dropdown p {
  margin: 8px 0;
}

.usuario-barra {
  cursor: pointer;
  position: relative;
}
.btn-logout-dropdown {
  display: block;
  background: #e74c3c;
  color: white;
  text-align: center;
  padding: 10px 0;
  border-radius: 6px;
  text-decoration: none;
  font-weight: bold;
  transition: background 0.3s, transform 0.2s;
}

.btn-logout-dropdown:hover {
  background: #c0392b;
  transform: scale(1.03);
}

.menu-lateral {
  position: fixed;
  top: 0; /* Se fija desde la parte superior de la pantalla */
  left: 0;
  width: 250px;
  height: 100%; /* Que ocupe toda la altura */
  background: #f7f9fb;
  color: #2d3436;
  padding: 30px 20px;
  box-shadow: 4px 0 12px rgba(0,0,0,0.06);
  box-sizing: border-box;
  z-index: 900;
  overflow-y: auto; /* Para que el menú lateral pueda hacer scroll interno si hay muchos elementos */
  transition: transform .3s ease;
}


.menu-lateral h3 {
  font-size: 17px;
  margin-bottom: 20px;
  color: #0984e3;
  border-bottom: 2px solid #0984e3;
  padding-bottom: 10px;
  font-weight: 600;
}

.menu-lateral ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.menu-lateral ul li {
  margin-bottom: 14px;
}

.menu-lateral ul li a {
  color: #2d3436;
  text-decoration: none;
  font-weight: 500;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s;
  padding: 8px 12px;
  border-radius: 6px;
}

.menu-lateral ul li a:hover {
  background: #dcdde1;
  color: #0984e3;
  transform: translateX(4px);
}

.menu-toggle {
  display: none;
  position: fixed;
  top: 100px;
  left: 20px;
  background: #0984e3;
  color: white;
  border: none;
  border-radius: 6px;
  padding: 10px 14px;
  font-size: 20px;
  z-index: 1001;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  cursor: pointer;
}

/* ---------- Escritorio ---------- */
@media (min-width: 992px) {
  /* Botón para ocultar (dentro del menú) */
  .sidebar-toggle-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    border: 0;
    background: #e9eef5;
    color: #2c3e50;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
        padding: 0px 0px;
  }
  .sidebar-toggle-btn:hover { background: #dbe7f6; }

  /* Botón para mostrar (fuera, flotante en el borde izquierdo) */
  .sidebar-show-btn {
    position: fixed;
    top: 160px;           /* ajústalo si tu header es más alto/bajo */
    left: 10px;
    border: 0;
    background: #e9eef5;
    color: #2c3e50;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(0,0,0,.12);
    z-index: 1002;
    opacity: 0;                 /* oculto por defecto */
    pointer-events: none;       /* no clickeable por defecto */
    transition: opacity .2s ease;
    padding: 0px 0px;
  }
  .sidebar-show-btn:hover { background: #dbe7f6; }

  /* Cuando el body tiene el colapso activado */
  body.sidebar-collapsed .menu-lateral {
    transform: translateX(-100%);   /* se sale de pantalla a la izquierda */
  }
  body.sidebar-collapsed .main-content {
    margin-left: 0 !important;      /* el contenido ocupa todo */
  }
  body.sidebar-collapsed #sidebarShowBtn {
    opacity: 1;
    pointer-events: auto;
  }
}

/* ---------- Móvil/Tablet: no mostrar botón flotante de escritorio ---------- */
@media (max-width: 991px) {
  .sidebar-toggle-btn,
  .sidebar-show-btn { display: none !important; }
}




/* Responsive en móviles */
@media (max-width: 768px) {
  .menu-lateral {
    position: fixed; /* Mejor experiencia móvil */
    top: 0;
    left: 0;
    width: 250px;
    height: 100%;
    background: #fff; /* O el color de tu menú */
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    z-index: 9;
  }

  .menu-lateral.active {
    transform: translateX(0);
  }

  .main-content {
    margin-left: 0 !important;
    transition: margin-left 0.3s ease;
  }

  .menu-toggle {
    position: fixed; /* Para que siempre sea visible */
    top: 15px;
    left: 15px;
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    width: 30px;
    height: 30px;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0;
    z-index: 10;
  }

  .menu-toggle span {
    width: 100%;
    height: 3px;
    background-color: #333; /* Cambia según tu paleta */
    border-radius: 2px;
    transition: all 0.3s ease-in-out;
    transform-origin: 1px;
  }

  /* ANIMACIÓN AL ACTIVAR (hamburger a X) */
  .menu-toggle.active span:nth-child(1) {
    transform: rotate(45deg) translate(5px, 5px);
  }

  .menu-toggle.active span:nth-child(2) {
    opacity: 0;
  }

  .menu-toggle.active span:nth-child(3) {
    transform: rotate(-45deg) translate(5px, -5px);
  }
}

.main-content {
    max-width: none;
    margin-left: 240px;
    padding: 30px;
    transition: margin-left .3s ease; 
}


.accordion-button.fw-bold:hover, 
.accordion-button.fw-bold:focus {
  background-color: #2c3e50 !important; /* Cambia este color por el que prefieras */
  color: white !important; /* Cambia el texto si quieres */
  transition: background 0.25s;
}

.btn-action {
  font-weight: bold;
  box-shadow: 0 3px 12px rgba(52,152,219,0.06);
  border-radius: 10px;
  padding-left: 18px;
  padding-right: 18px;
  transition: all 0.15s;
}
.btn-action:hover {
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 8px 16px rgba(52,152,219,0.18);
  opacity: 0.93;
}
.action-bar-pro {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 18px;
  margin-top: 10px;
  margin-bottom: 28px;
  background: rgba(255,255,255,0.44);
  border-radius: 18px;
  box-shadow: 0 6px 28px 0 rgba(41,128,185,0.07), 0 1.5px 10px rgba(44,62,80,0.03);
  padding: 16px 12px 10px 12px;
  backdrop-filter: blur(4px) saturate(1.2);
  border: 1.5px solid #eaf1fb;
  animation: fadeIn 0.5s;
}
.action-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 1.05rem;
  font-weight: 600;
  color: #24416c;
  text-decoration: none;
  padding: 13px 26px 13px 20px;
  border-radius: 14px;
  background: linear-gradient(120deg,rgba(240,245,255,0.82),rgba(232,243,255,0.88) 60%, #f2f8fd 100%);
  border: 1.5px solid #e2eafc;
  box-shadow: 0 2.5px 8px rgba(41,128,185,0.06);
  transition: 
    background 0.21s,
    box-shadow 0.21s,
    color 0.14s,
    transform 0.16s;
  position: relative;
  overflow: hidden;
}
.action-btn i {
  font-size: 1.45em;
  margin-right: 4px;
  vertical-align: middle;
  transition: color 0.18s;
}
.action-btn span {
  letter-spacing: .2px;
}
.action-btn:hover, .action-btn:focus {
  background: linear-gradient(120deg,rgba(52,152,219,0.12),rgba(149,232,255,0.23) 80%, #f2f8fd 100%);
  box-shadow: 0 8px 24px rgba(52,152,219,0.13);
  color: #17509c;
  transform: translateY(-2px) scale(1.03);
}
.action-btn:active {
  transform: scale(.98);
}
.action-btn:hover i, .action-btn:focus i {
  color: #2082da;
}

.action-btn.action-new     { border-left: 5px solid #4ade80; }
.action-btn.action-view    { border-left: 5px solid #60a5fa; }
.action-btn.action-license { border-left: 5px solid #fbbf24; }
.action-btn.action-job     { border-left: 5px solid #818cf8; }
.action-btn.action-alert   { border-left: 5px solid #f87171; }

@media (max-width: 650px) {
  .action-bar-pro {
    gap: 10px;
    padding: 10px 6px 6px 6px;
  }
  .action-btn {
    padding: 11px 13px 11px 13px;
    font-size: 0.97rem;
    border-radius: 10px;
  }
  .action-btn span {
    display: none;
  }
}
.offcanvas .nav-link,
aside .nav-link {
  color: #24416c;
  font-weight: 500;
  font-size: 1.05em;
  border-radius: 10px;
  padding: 10px 16px;
  margin-bottom: 2px;
  transition: background .16s, color .16s, padding .12s;
}
.offcanvas .nav-link:hover,
aside .nav-link:hover,
.offcanvas .nav-link.active,
aside .nav-link.active {
  background: linear-gradient(120deg, #e8f3fd 70%, #fff 100%);
  color: #166ab5;
  padding-left: 23px;
}
.offcanvas .nav-link i,
aside .nav-link i {
  font-size: 1.3em;
  color: #60a5fa;
}
.offcanvas-title img,
aside img {
  vertical-align: middle;
}
@media (max-width: 991px) {
  .main-content { margin-left: 0 !important; }
  aside { display: none !important; }
}
@media (max-width: 600px) {
  .row.g-3 > [class^="col-"] {
    flex: 0 0 100%;
    max-width: 100%;
  }
}
@media (max-width: 650px) {
  .action-btn span {
    display: none;
  }
}


/* =========================
   PROGRAMACIÓN CONDUCTORES
========================= */


.condt-shell .card {
    max-width: 100%;
    margin: 0;
    padding: 0;
}

.condt-header {
    background: linear-gradient(135deg, #243447, #30475e);
    color: #fff;
    border-radius: 18px;
    padding: 22px 24px;
    margin-bottom: 18px;
    box-shadow: 0 12px 25px rgba(44, 62, 80, 0.18);
}

.condt-header h2 {
    margin: 0;
    color: #fff;
    text-align: left;
    font-weight: 800;
    font-size: 2rem;
}

.condt-header p {
    margin: 8px 0 0 0;
    color: #dbe7f3;
    font-size: 14px;
}

.condt-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.condt-btn,
.condt-mini-btn {
    width: auto !important;
}

.condt-btn {
    border: none;
    border-radius: 12px;
    padding: 11px 16px;
    font-weight: 700;
    transition: .22s ease;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    box-shadow: 0 5px 12px rgba(0,0,0,.08);
}

.condt-btn:hover {
    transform: translateY(-1px);
}

.condt-btn-xs {
    padding: 8px 12px;
    font-size: 13px;
}

.condt-btn-primary { background: #2980b9; color: #fff; }
.condt-btn-success { background: #16a085; color: #fff; }
.condt-btn-warning { background: #e67e22; color: #fff; }
.condt-btn-danger  { background: #c0392b; color: #fff; }
.condt-btn-dark    { background: #64748b; color: #fff; }
.condt-btn-light   { background: #eef2f7; color: #243447; }

.condt-summary-card {
    border: none;
    border-radius: 18px;
    color: white;
    overflow: hidden;
    box-shadow: 0 12px 22px rgba(0,0,0,.08);
}

.condt-summary-card .card-body {
    padding: 20px;
}

.condt-summary-label {
    font-size: 13px;
    opacity: 0.92;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    font-weight: 700;
}

.condt-summary-value {
    font-size: 32px;
    font-weight: 800;
    line-height: 1;
}

.condt-panel-card {
    border: none;
    border-radius: 20px;
    box-shadow: 0 12px 26px rgba(44,62,80,.08);
    overflow: hidden;
}

.condt-panel-head {
    background: linear-gradient(135deg, #243447, #30475e);
    color: white;
    padding: 15px 18px;
    font-weight: 800;
    font-size: 15px;
}

.condt-panel-body {
    padding: 18px;
    background: #fff;
}

.condt-inline-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.condt-search-bar {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.condt-search-group {
    position: relative;
}

.condt-search-group i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 15px;
    pointer-events: none;
}

.condt-search-group .form-control {
    padding-left: 40px;
    border-radius: 12px;
    border: 1px solid #dbe4ee;
    min-height: 46px;
    box-shadow: none;
}

.condt-search-group .form-control:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 0.18rem rgba(52, 152, 219, 0.12);
}

.condt-search-hint {
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
}

.condt-unit-card {
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    margin-bottom: 14px;
    overflow: hidden;
    box-shadow: 0 7px 18px rgba(0,0,0,0.045);
    background: #fff;
}

.condt-unit-head {
    background: linear-gradient(135deg, #34495e, #3c5871);
    color: white;
    padding: 14px 16px;
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 12px;
    align-items: center;
}

.condt-unit-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.condt-unit-title {
    font-weight: 800;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.condt-unit-sub {
    font-size: 13px;
    color: #d6e2ee;
}

.condt-unit-metrics {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.condt-chip {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.16);
    color: #fff;
    border-radius: 999px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.condt-unit-toggle {
    width: 42px !important;
    height: 42px;
    border: none;
    border-radius: 12px;
    background: rgba(255,255,255,.14);
    color: white;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: .2s ease;
}

.condt-unit-toggle:hover {
    background: rgba(255,255,255,.22);
}

.condt-unit-toggle i {
    transition: transform .2s ease;
}

.condt-unit-toggle[aria-expanded="true"] i {
    transform: rotate(180deg);
}

.condt-unit-body {
    background: #f8fbfe;
    border-top: 1px solid #edf2f7;
}

.condt-slot-row {
    display: grid;
    grid-template-columns: 120px 1fr auto;
    gap: 12px;
    padding: 14px 16px;
    border-top: 1px solid #eef2f7;
    align-items: center;
    background: #fff;
}

.condt-slot-row:nth-child(even) {
    background: #fbfdff;
}

.condt-slot-badge {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    background: #eaf2f8;
    color: #1f2937;
    font-weight: 700;
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 13px;
}

.condt-driver-name {
    font-weight: 800;
    color: #243447;
    font-size: 15px;
}

.condt-driver-meta {
    color: #64748b;
    font-size: 13px;
    margin-top: 5px;
    line-height: 1.5;
}

.condt-empty {
    color: #94a3b8;
    font-style: italic;
    font-weight: 600;
}

.condt-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.condt-mini-btn {
    border: none;
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: .18s ease;
}

.condt-mini-btn:hover {
    transform: translateY(-1px);
}

.condt-mini-btn.detalle { background: #2980b9; color: #fff; }
.condt-mini-btn.asignar { background: #16a085; color: #fff; }
.condt-mini-btn.cambiar { background: #e67e22; color: #fff; }
.condt-mini-btn.liberar { background: #c0392b; color: #fff; }

.condt-reten-item,
.condt-pend-item,
.condt-hist-item {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 12px;
    background: #fff;
    box-shadow: 0 4px 10px rgba(0,0,0,.03);
}

.condt-clickable {
    color: #2980b9;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
}

.condt-clickable:hover {
    color: #1f6692;
    text-decoration: underline;
}

.condt-hist-chip {
    display: inline-block;
    color: white;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 8px;
}

.condt-muted {
    color: #64748b;
    font-size: 13px;
}

.condt-modal-label {
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 6px;
}

.condt-photo-wrap {
    width: 100%;
    min-height: 240px;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.condt-photo-wrap img {
    max-width: 100%;
    max-height: 260px;
    object-fit: contain;
}

.condt-no-results {
    border: 1px dashed #cbd5e1;
    border-radius: 16px;
    padding: 24px;
    text-align: center;
    background: #f8fafc;
    color: #64748b;
    font-weight: 700;
}

@media (max-width: 992px) {
    .condt-toolbar {
        justify-content: stretch;
    }

    .condt-toolbar .condt-btn {
        flex: 1;
    }

    .condt-unit-head {
        grid-template-columns: 1fr;
    }

    .condt-slot-row {
        grid-template-columns: 1fr;
    }

    .condt-actions {
        justify-content: flex-start;
    }

    .condt-inline-actions {
        width: 100%;
    }
}
.condt-btn-danger {
    background: #c0392b;
    color: #fff;
}
    
        /* ======================= PROGRAMACIÓN HORARIOS ======================= */
/* ======================= PROGRAMACIÓN HORARIOS · REDISEÑO ======================= */
.horarios-page{
    --hz-navy:#243447;
    --hz-navy-2:#2f4254;
    --hz-navy-3:#3b5168;
    --hz-bg:#f4f7fb;
    --hz-card:#ffffff;
    --hz-line:#d9e3ec;
    --hz-text:#22313f;
    --hz-muted:#6b7c8f;
    --hz-blue:#1f6fb2;
    --hz-blue-soft:#eaf3ff;
    --hz-success:#157347;
    --hz-success-soft:#e8f7ee;
    --hz-warning:#a86400;
    --hz-warning-soft:#fff3df;
    --hz-danger:#b42318;
    --hz-danger-soft:#fdecec;
    --hz-shadow:0 14px 32px rgba(15,23,42,.07);
    max-width: 1680px;
}

.horarios-page,
.horarios-page *{
    box-sizing:border-box;
}

.horarios-page .btn{
    width:auto;
    border-radius:12px;
    font-weight:700;
    min-height:44px;
    box-shadow:none;
}

.horarios-page .btn-sm{
    min-height:auto;
}

.horarios-page .row{
    --bs-gutter-x: 1rem;
    --bs-gutter-y: 1rem;
}

.horarios-hero{
    background: linear-gradient(135deg, var(--hz-navy) 0%, var(--hz-navy-2) 45%, var(--hz-navy-3) 100%);
    color:#fff;
    border-radius:24px;
    padding:30px 30px 24px;
    box-shadow:0 20px 40px rgba(36,52,71,.20);
    position:relative;
    overflow:hidden;
    margin-bottom:20px;
}

.horarios-hero::before{
    content:"";
    position:absolute;
    top:-70px;
    right:-70px;
    width:210px;
    height:210px;
    border-radius:50%;
    background:radial-gradient(circle, rgba(255,255,255,.16) 0%, rgba(255,255,255,0) 70%);
}

.horarios-hero__title{
    font-size:clamp(1.55rem, 2.2vw, 2.35rem);
    font-weight:900;
    margin-bottom:8px;
    letter-spacing:.01em;
}

.horarios-hero__sub{
    max-width:820px;
    color:#d9e4ef;
    font-size:.97rem;
    line-height:1.6;
    margin-bottom:18px;
}

.horarios-dates{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
}

.horario-date-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:9px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.18);
    color:#fff;
    font-size:.88rem;
    backdrop-filter:blur(6px);
}

.stat-card{
    background:var(--hz-card);
    border:1px solid #edf2f7;
    border-radius:20px;
    padding:18px 18px;
    height:100%;
    display:flex;
    align-items:center;
    gap:14px;
    box-shadow:var(--hz-shadow);
}

.stat-card__icon{
    width:52px;
    height:52px;
    border-radius:16px;
    display:grid;
    place-items:center;
    font-size:1.2rem;
    flex:0 0 auto;
}

.stat-card__label{
    font-size:.77rem;
    color:var(--hz-muted);
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:4px;
}

.stat-card__value{
    font-size:1.6rem;
    font-weight:900;
    color:var(--hz-text);
    line-height:1;
}

.horarios-toolbar{
    background:var(--hz-card);
    border:1px solid #edf2f7;
    border-radius:20px;
    padding:18px;
    box-shadow:var(--hz-shadow);
    margin-bottom:18px;
}

.horarios-toolbar .form-label{
    font-size:.92rem;
    color:#526476;
}

.horarios-toolbar .form-control{
    border-radius:14px;
    border:1px solid var(--hz-line);
    min-height:48px;
    padding-inline:16px;
    box-shadow:none;
}

.horarios-toolbar .form-control:focus{
    border-color:#8fc2f3;
    box-shadow:0 0 0 .18rem rgba(31,111,178,.10);
}

.board-shell,
.side-shell{
    background:var(--hz-card);
    border:1px solid #edf2f7;
    border-radius:24px;
    box-shadow:var(--hz-shadow);
    overflow:hidden;
}

.board-shell__head,
.side-shell__head{
    padding:16px 18px;
    background:var(--hz-navy);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.board-shell__head h3,
.side-shell__head h3{
    margin:0;
    color:#fff;
    font-size:1rem;
    font-weight:900;
    letter-spacing:.01em;
}

.board-shell__body{
    padding:18px;
    background:var(--hz-bg);
}

.origin-block{
    border:1px solid #e2e8f0;
    border-radius:20px;
    margin-bottom:14px;
    background:#fff;
    overflow:hidden;
    box-shadow:0 6px 16px rgba(15,23,42,.04);
}

.origin-block:last-child{
    margin-bottom:0;
}

.origin-block__head{
    width:100%;
    border:0;
    padding:14px 16px;
    background:linear-gradient(135deg, #30475e 0%, #39556c 100%);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    cursor:pointer;
    text-align:left;
}

.origin-block__head:hover{
    background:linear-gradient(135deg, #344d66 0%, #3f5e77 100%);
}

.origin-block__head-main{
    display:flex;
    flex-direction:column;
    gap:4px;
    min-width:0;
}

.origin-block__title{
    font-size:1rem;
    font-weight:900;
    color:#fff;
    line-height:1.1;
}

.origin-block__resume{
    font-size:.82rem;
    color:#d7e4ef;
    font-weight:600;
}

.origin-block__count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:38px;
    padding:8px 10px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.18);
    color:#fff;
    font-size:.78rem;
    font-weight:800;
    white-space:nowrap;
}

.origin-block__icon{
    font-size:1.15rem;
    transition:transform .22s ease;
}

.origin-block.is-collapsed .origin-block__icon{
    transform:rotate(-90deg);
}

.origin-block.is-collapsed .origin-block__body{
    display:none;
}

.origin-block__body{
    padding:16px;
    background:#f8fbfe;
    border-top:1px solid #edf2f7;
}

.origin-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(290px, 1fr));
    gap:14px;
}

.schedule-card{
    background:#fff;
    border:1px solid var(--hz-line);
    border-radius:18px;
    padding:14px;
    display:flex;
    flex-direction:column;
    gap:10px;
    min-height:210px;
    transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.schedule-card:hover{
    transform:translateY(-2px);
    box-shadow:0 12px 24px rgba(44,62,80,.09);
    border-color:#bfd2e5;
}

.schedule-card--empty{
    background:linear-gradient(180deg, #fffdfa 0%, #fff7eb 100%);
    border-style:dashed;
}

.schedule-card__top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
}

.schedule-card__time{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.schedule-card__hour{
    font-size:1.38rem;
    font-weight:900;
    color:var(--hz-text);
    line-height:1;
    letter-spacing:.01em;
}

.schedule-card__day{
    font-size:.72rem;
    color:#7c8a98;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.schedule-card__badges{
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:6px;
}

.mini-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    padding:5px 9px;
    font-size:.68rem;
    font-weight:900;
    letter-spacing:.02em;
}

.mini-badge--success{
    background:var(--hz-success-soft);
    color:var(--hz-success);
}

.mini-badge--warning{
    background:var(--hz-warning-soft);
    color:var(--hz-warning);
}

.mini-badge--danger{
    background:var(--hz-danger-soft);
    color:var(--hz-danger);
}

.mini-badge--next{
    background:#eef3ff;
    color:#3558b5;
}

.schedule-card__dest{
    font-size:1rem;
    font-weight:900;
    color:var(--hz-text);
    line-height:1.3;
}

.schedule-card__unit{
    font-size:.92rem;
    color:#405261;
    font-weight:700;
}

.schedule-card__meta{
    font-size:.82rem;
    color:var(--hz-muted);
    line-height:1.45;
}

.schedule-card__footer{
    margin-top:auto;
    display:flex;
    flex-direction:column;
    gap:10px;
}

.schedule-actions{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
}

.schedule-actions .btn-action-card{
    min-height:38px;
    border-radius:10px;
    font-size:.76rem;
    font-weight:800;
    padding:8px 10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid transparent;
    white-space:nowrap;
    background:#fff;
}

.schedule-actions .btn-action-card.full{
    grid-column:1 / -1;
}

.btn-action-card.primary{
    background:#1f6fb2;
    color:#fff;
}

.btn-action-card.primary:hover{
    background:#195c95;
    color:#fff;
}

.btn-action-card.light{
    background:#fff;
    color:#425466;
    border-color:#d6e1eb;
}

.btn-action-card.light:hover{
    background:#f5f9fd;
    color:#22313f;
}

.btn-action-card.warning{
    background:#fff8eb;
    color:#a86400;
    border-color:#f3d79a;
}

.btn-action-card.warning:hover{
    background:#fff1d9;
    color:#8c5600;
}

.btn-action-card.danger{
    background:#fff;
    color:#b42318;
    border-color:#efc2be;
}

.btn-action-card.danger:hover{
    background:#fdecec;
    color:#9a1f16;
}

.side-shell{
    margin-bottom:16px;
}

.side-shell__body{
    padding:14px;
    background:#f8fbfe;
    max-height:520px;
    overflow:auto;
}

.side-list-item{
    background:#fff;
    border:1px solid #dde6ee;
    border-radius:16px;
    padding:12px;
    margin-bottom:10px;
    box-shadow:0 4px 10px rgba(0,0,0,.03);
}

.side-list-item:last-child{
    margin-bottom:0;
}

.side-list-item__title{
    font-weight:900;
    color:#243447;
    margin-bottom:4px;
    font-size:.95rem;
}

.side-list-item__meta{
    color:#6f7f90;
    font-size:.82rem;
    line-height:1.4;
}

.empty-state{
    border:1px dashed #d4dde7;
    background:#fff;
    color:#6f7f90;
    border-radius:16px;
    padding:22px 18px;
    text-align:center;
    font-style:italic;
}

.modal-content{
    border:0;
    border-radius:22px;
    overflow:hidden;
}

.modal-header{
    background:#2c3e50;
    color:#fff;
    border-bottom:0;
}

.modal-header .btn-close{
    filter:invert(1);
}

.modal-body{
    background:#f8fafc;
}

.quick-time{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.quick-time .btn{
    border-radius:999px;
    font-weight:700;
    padding-inline:14px;
}

.historial-card,
.inh-card{
    background:#fff;
    border:1px solid #dde6ee;
    border-radius:16px;
    padding:15px;
    margin-bottom:12px;
}

.historial-card__top,
.inh-card__top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:8px;
    margin-bottom:8px;
}

.bus-list{
    max-height:430px;
    overflow:auto;
    padding-right:4px;
}

.bus-card{
    border:1px solid #dbe4ec;
    border-radius:16px;
    background:#fff;
    padding:14px;
    margin-bottom:10px;
    cursor:pointer;
    transition:.18s ease;
}

.bus-card:hover,
.bus-card.active{
    border-color:#2980b9;
    background:#eef7ff;
    box-shadow:0 0 0 3px rgba(41,128,185,.08);
}

.bus-card__top{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    margin-bottom:6px;
}

.bus-card__title{
    font-weight:800;
    color:#22313f;
}

.bus-card__meta{
    font-size:.84rem;
    color:#627488;
}

.motivo-option{
    cursor:pointer;
    border:1px solid #d7e0ea;
    border-radius:14px;
    padding:14px;
    background:#f8fbfe;
    transition:.18s ease;
}

.motivo-option.active{
    border-color:#2980b9;
    background:#edf6fe;
    box-shadow:0 0 0 3px rgba(41,128,185,.12);
}

.motivo-option small{
    color:#728293;
    display:block;
    margin-top:3px;
}

@media (min-width:1200px){
    .horarios-sidebar{
        position:sticky;
        top:110px;
    }
}

@media (max-width:991.98px){
    .origin-grid{
        grid-template-columns:1fr;
    }

    .schedule-actions{
        grid-template-columns:1fr;
    }

    .schedule-actions .btn-action-card.full{
        grid-column:auto;
    }
}

@media (max-width:575.98px){
    .horarios-hero,
    .horarios-toolbar,
    .board-shell__body,
    .side-shell__body{
        padding-left:14px;
        padding-right:14px;
    }

    .origin-block__head{
        padding:12px 14px;
    }

    .schedule-card{
        min-height:auto;
    }

    .schedule-card__hour{
        font-size:1.2rem;
    }

    .horarios-dates{
        gap:8px;
    }

    .horario-date-chip{
        width:100%;
        justify-content:flex-start;
    }
}
.board-direct{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.board-direct .origin-block{
    margin-bottom:0;
}
@keyframes horarioSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}


.modal-img-export {
  border: 0;
  border-radius: 26px;
  overflow: hidden;
  box-shadow: 0 30px 80px rgba(15, 23, 42, .28);
}

.modal-img-export__header {
  background: linear-gradient(135deg, #243447, #30475e);
  padding: 22px 24px;
}

.modal-img-export__eyebrow {
  font-size: 11px;
  font-weight: 900;
  letter-spacing: .09em;
  text-transform: uppercase;
  color: #8fd3ff;
  margin-bottom: 4px;
}

.modal-img-export__body {
  background: #f5f8fc;
  padding: 22px;
}

.export-mode-card {
  background: white;
  border: 1px solid #dbe6f0;
  border-radius: 20px;
  padding: 18px;
  display: flex;
  justify-content: space-between;
  gap: 18px;
  align-items: center;
}

.export-mode-title {
  font-weight: 900;
  color: #243447;
  margin-bottom: 4px;
}

.export-mode-desc {
  color: #64748b;
  font-size: 13px;
  line-height: 1.45;
}

.export-switch-box {
  background: #eef5fc;
  border: 1px solid #d6e6f5;
  border-radius: 999px;
  padding: 9px 12px;
  display: flex;
  gap: 10px;
  align-items: center;
  font-weight: 900;
  color: #243447;
  white-space: nowrap;
}

.export-option {
  width: 100%;
  min-height: 145px;
  border-radius: 22px;
  background: white;
  border: 2px solid #dbe6f0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 7px;
  transition: .2s ease;
  font-weight: 900;
}

.export-option i {
  font-size: 34px;
}

.export-option span {
  font-size: 15px;
}

.export-option small {
  font-size: 12px;
  color: #64748b;
}

.export-option--pizarra {
  color: #1f6fb2;
  border-color: #b8d8f4;
}

.export-option--tabla {
  color: #167b4d;
  border-color: #bde6ca;
}

.export-option:hover {
  transform: translateY(-3px);
  box-shadow: 0 16px 35px rgba(15, 23, 42, .12);
  background: #fbfdff;
}
.horarios-page .btn-img-auto,
.horarios-page .btn-img-export {
  width: auto;
  min-height: 44px;
  border-radius: 14px;
  font-weight: 900;
  padding: 10px 16px;
  border: 1.5px solid transparent;
  transition: .2s ease;
}

.horarios-page .btn-img-auto {
  color: #075985;
  background: linear-gradient(135deg, #e0f2fe, #f0f9ff);
  border-color: #7dd3fc;
}

.horarios-page .btn-img-export {
  color: #166534;
  background: linear-gradient(135deg, #dcfce7, #f0fdf4);
  border-color: #86efac;
}

.horarios-page .btn-img-auto:hover,
.horarios-page .btn-img-export:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 22px rgba(15, 23, 42, .12);
}

.horarios-page .btn-img-auto:hover {
  color: #fff;
  background: linear-gradient(135deg, #0284c7, #0369a1);
}

.horarios-page .btn-img-export:hover {
  color: #fff;
  background: linear-gradient(135deg, #16a34a, #15803d);
}
.schedule-card--taller {
  border-color: #f1b8b8;
  background: linear-gradient(180deg, #ffffff 0%, #fff7f7 100%);
}

.mini-badge--taller {
  background: #fdecec;
  color: #b42318;
}

.schedule-card__taller-note {
  background: #fff1f1;
  color: #9f1d16;
  border: 1px solid #f3c2bd;
  border-radius: 12px;
  padding: 8px 10px;
  font-size: .78rem;
  font-weight: 800;
}
.ruta-box {
  background: #ffffff;
  border: 1px solid #dbe6f0;
  border-radius: 16px;
  padding: 14px;
}

.ruta-box__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
}

.ruta-box__title {
  font-weight: 900;
  color: #243447;
  font-size: 14px;
}

.ruta-box__hint {
  font-size: 12px;
  color: #64748b;
}

.ruta-list {
  max-height: 190px;
  overflow: auto;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 8px;
  padding-right: 4px;
}

.ruta-item {
  border: 1px solid #dbe4ec;
  background: #f8fbfe;
  border-radius: 12px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  color: #334155;
  cursor: pointer;
  transition: .16s ease;
}

.ruta-item:hover {
  background: #edf6fe;
  border-color: #9ecbf2;
}

.ruta-item input {
  margin: 0;
}

.ruta-preview {
  margin-top: 10px;
  background: #eef5fc;
  border: 1px solid #d6e6f5;
  color: #243447;
  border-radius: 12px;
  padding: 9px 11px;
  font-size: 13px;
  font-weight: 800;
}

.schedule-card__ruta {
  background: #f3f8fd;
  border: 1px solid #dbe8f3;
  border-radius: 12px;
  padding: 8px 10px;
  font-size: .84rem;
  color: #425466;
  font-weight: 800;
  line-height: 1.35;
}

.schedule-card__comentario {
  background: #fff9ec;
  border: 1px solid #f6dfb4;
  border-radius: 12px;
  padding: 8px 10px;
  font-size: .78rem;
  color: #69470f;
  font-weight: 700;
  line-height: 1.35;
}

.export-pdf-divider {
  background: #fff;
  border: 1px dashed #d7e2ec;
  border-radius: 16px;
  padding: 12px 14px;
  color: #243447;
}

.export-pdf-divider strong {
  display: block;
  font-size: 14px;
  font-weight: 900;
}

.export-pdf-divider small {
  display: block;
  color: #64748b;
  font-size: 12px;
  margin-top: 2px;
}

.export-option--pdf-pizarra {
  color: #b42318;
  border-color: #f2b8b5;
  background: linear-gradient(180deg, #ffffff 0%, #fff7f7 100%);
}

.export-option--pdf-tabla {
  color: #8a4b00;
  border-color: #f3d29a;
  background: linear-gradient(180deg, #ffffff 0%, #fffaf0 100%);
}
.panel-qr-programacion {
    display: none;
    margin: 16px;
    padding: 18px;
    border-radius: 18px;
    background: #1f3a5f;
    color: white;
    box-shadow: 0 10px 25px rgba(0,0,0,.18);
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.panel-qr-programacion p {
    margin: 4px 0 0 0;
    color: #dbeafe;
}

.panel-qr-programacion button {
    border: 0;
    border-radius: 14px;
    padding: 12px 18px;
    background: #f2b149;
    color: #111827;
    font-weight: 800;
}

body.modo-qr-programacion .panel-qr-programacion {
    display: flex;
}


/* =========================
   MODO QR PROGRAMACIÓN
   Solo descarga PDF
========================= */

body.modo-qr-programacion {
    background: #0f172a;
    min-height: 100vh;
    overflow: hidden;
}

/* Ocultar navegación, header, footer, sidebar y soporte */
body.modo-qr-programacion .main-header,
body.modo-qr-programacion .nav-bar-pro,
body.modo-qr-programacion .subnav,
body.modo-qr-programacion .menu-lateral,
body.modo-qr-programacion .sidebar-show-btn,
body.modo-qr-programacion .menu-toggle,
body.modo-qr-programacion .main-footer,
body.modo-qr-programacion .btn-flotante {
    display: none !important;
}

/* La interfaz existe para JS, pero no se muestra al usuario */
body.modo-qr-programacion .main-content {
    position: absolute !important;
    left: -99999px !important;
    top: -99999px !important;
    width: 1px !important;
    height: 1px !important;
    overflow: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

/* Panel visible del QR */
body.modo-qr-programacion .panel-qr-programacion {
    display: flex !important;
    position: fixed;
    inset: 0;
    margin: 0;
    border-radius: 0;
    background:
        radial-gradient(circle at top right, rgba(47, 113, 183, .35), transparent 35%),
        linear-gradient(135deg, #0f172a, #1e293b);
    color: white;
    box-shadow: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
    padding: 28px;
    z-index: 999999;
}

body.modo-qr-programacion .panel-qr-programacion > div {
    max-width: 620px;
}

body.modo-qr-programacion .panel-qr-programacion strong {
    display: block;
    font-size: clamp(1.5rem, 4vw, 2.3rem);
    font-weight: 900;
    margin-bottom: 10px;
}

body.modo-qr-programacion .panel-qr-programacion p {
    color: #cbd5e1;
    font-size: 1rem;
    margin-bottom: 22px;
}

body.modo-qr-programacion .panel-qr-programacion button {
    width: auto;
    min-width: 280px;
    border: 0;
    border-radius: 16px;
    padding: 14px 24px;
    background: #f2b149;
    color: #111827;
    font-weight: 900;
    box-shadow: 0 14px 30px rgba(0,0,0,.28);
}

body.modo-qr-programacion .panel-qr-programacion button:hover {
    background: #ffc766;
}
@font-face {
  font-family: "N360Pizarra";
  src: url("../assets/fonts/static/Inter_24pt-Regular.ttf?v=1") format("truetype");
  font-weight: 400;
}

@font-face {
  font-family: "N360Pizarra";
  src: url("../assets/fonts/static/Inter_24pt-Bold.ttf?v=1") format("truetype");
  font-weight: 700;
}

@font-face {
  font-family: "N360Pizarra";
  src: url("../assets/fonts/static/Inter_24pt-ExtraBold.ttf?v=1") format("truetype");
  font-weight: 800;
}

@font-face {
  font-family: "N360Pizarra";
  src: url("../assets/fonts/static/Inter_24pt-Black.ttf?v=1") format("truetype");
  font-weight: 900;
}
    </style>
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/dialog_n360.css') ?>">
    <script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
</head>

<body class="<?= $modo_qr_programacion ? 'modo-qr-programacion' : '' ?>">
<?php
function calcularEdad($fechaNacimiento) {
    $hoy = new DateTime();
    $nac = new DateTime($fechaNacimiento);
    $edad = $hoy->diff($nac);
    return $edad->y;
}

$edad = calcularEdad("2000-04-12"); // ejemplo
?>
<?php if ($exito): ?>
    
<div id="popup-exito">
  <div class="mensaje">
    <svg class="check-icon" viewBox="0 0 52 52">
      <circle class="check-circle" cx="26" cy="26" r="25" fill="none" />
      <path class="check-mark" fill="none" d="M14 27 l8 8 l16 -16" />
    </svg>
    <p class="texto-popup">¡Trabajador registrado correctamente!</p>
  </div>
</div>

<?php endif; ?>

<?php if (!$modo_qr_programacion) { n360_render_header(['title' => 'Programacion de horarios', 'subtitle' => 'Flota y operaciones']); } ?>

<?php if (!$modo_qr_programacion) { n360_render_sidebar(); } ?>

<div class="main-content <?= $modo_qr_programacion ? '' : 'n360-main n360-main--module' ?>">
<?php if (!$modo_qr_programacion) { n360_render_content_separator('top'); } ?>
 <div class="container mt-4 mb-5 horarios-page">
    <div id="horariosAlertZone">
        <?php if ($initialError !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>No se pudo cargar la programación.</strong>
                <?= h($initialError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <section class="horarios-hero">
        <div class="horarios-hero__title"><i class="bi bi-clock-history me-2"></i>Programación de Horarios</div>
        <div class="horarios-dates" id="horariosDatesWrap"></div>
    </section>

    <div class="row g-3 mb-3" id="statsRow">
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-card__icon" style="background:#e9f3ff;color:#1f6fb2;"><i class="bi bi-calendar3-event"></i></div><div><div class="stat-card__label">Horarios activos</div><div class="stat-card__value" id="statTotalActivos">0</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-card__icon" style="background:#fff4e5;color:#b95c00;"><i class="bi bi-exclamation-circle"></i></div><div><div class="stat-card__label">Activos sin bus</div><div class="stat-card__value" id="statSinBus">0</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-card__icon" style="background:#eef7ee;color:#167b4d;"><i class="bi bi-bus-front"></i></div><div><div class="stat-card__label">Buses sin horario</div><div class="stat-card__value" id="statBusesSinHorario">0</div></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-card__icon" style="background:#fdecec;color:#b42318;"><i class="bi bi-tools"></i></div><div><div class="stat-card__label">Buses en taller</div><div class="stat-card__value" id="statBusesTaller">0</div></div></div></div>
    </div>

    <section class="horarios-toolbar">
        <div class="row g-3 align-items-center">
            <div class="col-lg-5">
                <label for="filtroHorarios" class="form-label fw-bold text-secondary mb-2">Filtrar pizarra</label>
                <input type="search" id="filtroHorarios" name="n360_busqueda_pizarra" class="form-control" placeholder="Busca por origen, destino, bus, placa, tipo o hora..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" readonly>
            </div>
            <div class="col-lg-7">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end mt-lg-4">
                    <button class="btn btn-primary" id="btnNuevoHorario"><i class="bi bi-plus-circle me-2"></i>Nuevo horario</button>
                    <button class="btn btn-outline-secondary" id="btnRefreshHorarios"><i class="bi bi-arrow-repeat me-2"></i>Actualizar</button>
                    <button class="btn btn-img-auto" id="btnExportImagen">
                        <i class="bi bi-magic me-2"></i>Generar imágenes
                    </button>

                    <button class="btn btn-img-export" id="btnAbrirModalImagen">
                        <i class="bi bi-sliders2 me-2"></i>Exportar imagen
                    </button>
                    <button class="btn btn-outline-danger" id="btnInhabilitados"><i class="bi bi-ban me-2"></i>Inhabilitados</button>
                    <button class="btn btn-outline-dark" id="btnHistorial"><i class="bi bi-clock-history me-2"></i>Historial</button>
                    <?php if ($web_rol === 'Admin'): ?>
                    <button type="button" class="btn btn-outline-danger" id="btnLimpiarPizarra"><i class="bi bi-trash3 me-2"></i>Limpiar pizarra</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-4 align-items-start">
<div class="col-xl-9">

    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="button" class="btn btn-sm btn-outline-success" id="btnExpandirAcordeones">
            <i class="bi bi-arrows-expand me-1"></i> Expandir todo
        </button>

        <button type="button" class="btn btn-sm btn-outline-warning" id="btnContraerAcordeones">
            <i class="bi bi-arrows-collapse me-1"></i> Contraer todo
        </button>
    </div>

    <div id="boardContainer" class="board-direct"></div>

</div>
        <div class="col-xl-3 horarios-sidebar">
            <section class="side-shell">
                <div class="side-shell__head" style="background:#c0392b;"><h3><i class="bi bi-tools me-2"></i>Buses en taller</h3><span class="small text-white-50" id="sideTallerCount">0</span></div>
                <div class="side-shell__body" id="sideTaller"></div>
            </section>
            <section class="side-shell">
                <div class="side-shell__head" style="background:#546e7a;"><h3><i class="bi bi-bus-front me-2"></i>Buses sin horario</h3><span class="small text-white-50" id="sideSinHorarioCount">0</span></div>
                <div class="side-shell__body" id="sideSinHorario"></div>
            </section>
        </div>
    </div>
 </div>

</div>

<div class="modal fade" id="modalCrearHorario" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Crear nuevo horario</h5><div class="small text-white-50">Origen: solo sedes ORIGEN activas · Destino: todas las sedes activas</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-light border rounded-4 mb-4"><div class="small text-secondary fw-bold mb-1">Vista previa</div><div class="fs-5 fw-bold text-dark" id="previewNuevoHorario">16:00 | Origen → Destino</div></div><div class="row g-3 mb-3"><div class="col-md-5"><label class="form-label fw-bold">Origen</label><select id="crearOrigen" class="form-select"></select></div><div class="col-md-2 d-flex align-items-end justify-content-center"><button type="button" class="btn btn-outline-secondary w-100" id="btnSwapOficinas"><i class="bi bi-arrow-left-right"></i></button></div><div class="col-md-5"><label class="form-label fw-bold">Destino</label><select id="crearDestino" class="form-select"></select></div></div><div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label fw-bold">Hora de salida</label><input type="time" id="crearHora" class="form-control" step="300" value="16:00"></div><div class="col-md-8"><label class="form-label fw-bold">Horarios rápidos</label><div class="quick-time" id="quickTimeWrap"></div></div></div>
<div class="ruta-box">
  <div class="ruta-box__head">
    <div>
      <div class="ruta-box__title">
        <i class="bi bi-signpost-split me-1"></i> Ruta intermedia
      </div>
      <div class="ruta-box__hint">
        Selecciona las sedes por donde pasará la unidad. No se mostrará origen ni destino.
      </div>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLimpiarRutaCrear">
      Limpiar
    </button>
  </div>

  <div class="ruta-list" id="crearRutaList"></div>

  <div class="ruta-preview" id="crearRutaPreview">
    Ruta: directa, sin sedes intermedias.
  </div>
</div>

<div class="mt-3"><label class="form-label fw-bold">Comentario</label><textarea id="crearComentario" class="form-control" rows="3" maxlength="500" placeholder="Comentario opcional para este horario"></textarea><div class="form-text">Se guarda junto al horario y queda visible en la tarjeta.</div></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnGuardarNuevoHorario">Guardar horario</button></div></div></div></div>

<div class="modal fade" id="modalEditarHora" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Cambiar hora del horario</h5><div class="small text-white-50" id="editarHoraSubtitulo">Horario</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-light border rounded-4 mb-4"><div class="small text-secondary fw-bold mb-1">Vista previa de reprogramación</div><div class="fs-5 fw-bold text-dark" id="previewEditarHora">00:00 → 00:00</div></div>
    <div class="mb-3">
      <label class="form-label fw-bold">Nuevo destino</label>
      <select id="editarDestino" class="form-select"></select>
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Nueva hora de salida</label>
      <input type="time" id="editarHoraInput" class="form-control" step="300">
    </div>

    <div class="quick-time" id="quickEditTimeWrap"></div>
<div class="ruta-box mt-3">
  <div class="ruta-box__head">
    <div>
      <div class="ruta-box__title">
        <i class="bi bi-signpost-split me-1"></i> Ruta intermedia
      </div>
      <div class="ruta-box__hint">
        Se excluye automáticamente el origen y el destino seleccionado.
      </div>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLimpiarRutaEditar">
      Limpiar
    </button>
  </div>

  <div class="ruta-list" id="editarRutaList"></div>

  <div class="ruta-preview" id="editarRutaPreview">
    Ruta: directa, sin sedes intermedias.
  </div>
</div>
      </div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnGuardarEditarHora">Actualizar hora</button></div></div></div></div>

<div class="modal fade" id="modalMotivo" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="motivoTitulo">Motivo requerido</h5><div class="small text-white-50">Selecciona un motivo rápido o escribe uno personalizado.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3 mb-3" id="motivoOptions"></div><div class="alert alert-light border rounded-4 mb-3"><div class="small fw-bold text-secondary mb-1">Así quedará guardado el motivo en el historial</div><div id="motivoPreview" class="fw-bold text-dark"></div></div><div><label class="form-label fw-bold">Motivo libre</label><textarea id="motivoLibre" class="form-control" rows="4" placeholder="Escribe el motivo si elegiste OTRO"></textarea></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnAceptarMotivo">Aceptar</button></div></div></div></div>

<div class="modal fade" id="modalBus" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="modalBusTitle">Asignar bus</h5><div class="small text-white-50" id="modalBusSubtitle"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3 mb-3 align-items-center"><div class="col-lg-8"><input type="text" id="busSearch" class="form-control" placeholder="Buscar por bus, placa o tipo..."></div><div class="col-lg-4 text-lg-end fw-bold text-secondary" id="busSelectedLabel">Seleccionado: ninguno</div></div><div class="bus-list" id="busList"></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnConfirmBus">Confirmar</button></div></div></div></div>

<div class="modal fade" id="modalHistorial" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1">Historial de movimientos</h5>
          <div class="small text-white-50">
            Trazabilidad de inserciones, cambios, retiros, inhabilitaciones y reactivaciones.
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold text-secondary mb-2">
            Buscar en historial
          </label>
          <input 
            type="text" 
            id="historialSearch" 
            class="form-control" 
            placeholder="Busca por usuario, bus, placa, origen, destino, motivo, hora o fecha..."
            autocomplete="off"
          >
          <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="button" class="btn btn-outline-warning btn-sm" id="btnFiltroTransbordoHistorial">
              <i class="bi bi-arrow-left-right me-1"></i>
              Solo transbordos
            </button>

            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnLimpiarFiltroHistorial">
              <i class="bi bi-x-circle me-1"></i>
              Limpiar filtros
            </button>
          </div>
          <div class="small text-secondary mt-2" id="historialSearchInfo">
            El filtro se aplica sobre el historial cargado, sin consultar nuevamente la base de datos.
          </div>
        </div>

        <div id="historialContainer"></div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalInhabilitados" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Horarios inhabilitados</h5><div class="small text-white-50">Desde aquí puedes reactivar horarios sin mostrarlos en la pizarra principal.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div id="inhabilitadosContainer"></div></div></div></div></div>

<div class="modal fade" id="modalExportImagen" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-img-export">

      <div class="modal-header modal-img-export__header">
        <div>
          <div class="modal-img-export__eyebrow">Programación operativa</div>
          <h5 class="modal-title mb-1">Exportar imagen de horarios</h5>
          <div class="small text-white-50">
            Selecciona cómo quieres generar la pizarra o tabla.
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body modal-img-export__body">

        <div class="export-mode-card">
          <div>
            <div class="export-mode-title">Modo de exportación</div>
            <div class="export-mode-desc" id="txtModoImagen">
              Predefinido: solo descarga los grupos que pertenecen al formato seleccionado.
            </div>
          </div>

          <div class="export-switch-box">
            <span id="lblModoImagen">Predefinido</span>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="switchModoImagen">
            </div>
          </div>
        </div>

        <div class="row g-3 mt-2">
          <div class="col-md-6">
            <button type="button" class="export-option export-option--pizarra" id="btnDescargarPizarra">
              <i class="bi bi-layout-text-window-reverse"></i>
              <span>Descargar Pizarra</span>
              <small>Formato visual por bloques</small>
            </button>
          </div>

          <div class="col-md-6">
            <button type="button" class="export-option export-option--tabla" id="btnDescargarTabla">
              <i class="bi bi-table"></i>
              <span>Descargar Tabla</span>
              <small>Formato listado operativo</small>
            </button>
          </div>
        </div>

        <div class="export-pdf-divider mt-3">
          <div>
            <strong>Versión PDF</strong>
            <small>Genera un solo archivo PDF con cada grupo como página.</small>
          </div>
        </div>

        <div class="row g-3 mt-2">
          <div class="col-md-6">
            <button type="button" class="export-option export-option--pdf-pizarra" id="btnDescargarPizarraPDF">
              <i class="bi bi-file-earmark-pdf"></i>
              <span>Pizarra PDF</span>
              <small>Un PDF con formato visual</small>
            </button>
          </div>

          <div class="col-md-6">
            <button type="button" class="export-option export-option--pdf-tabla" id="btnDescargarTablaPDF">
              <i class="bi bi-file-earmark-pdf-fill"></i>
              <span>Tabla PDF</span>
              <small>Un PDF con listado operativo</small>
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>



<?php if ($web_rol === 'Admin'): ?>
<div class="modal fade" id="modalLimpiarPizarra" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <div>
          <h5 class="modal-title mb-1"><i class="bi bi-exclamation-octagon me-2"></i>Limpiar pizarra operativa</h5>
          <div class="small text-white-50">Esta accion ejecuta el cierre operativo configurado.</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning border-0 mb-3">
          <strong>Advertencia:</strong> se limpiara la pizarra de programacion. Esta accion retirara las unidades activas segun el cierre operativo configurado.
        </div>
        <p class="mb-0 text-secondary">Confirma solo si ya revisaste que no hay cambios pendientes en la pizarra.</p>
      </div>
      <div class="modal-footer bg-white">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btnConfirmLimpiarPizarra"><i class="bi bi-trash3 me-2"></i>Limpiar Pizarra</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL DE CARGA -->
<div id="modal-cargando" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
  <div style="background:white; padding:30px 50px; border-radius:12px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3); min-width:280px;">
    <i class="bi bi-arrow-repeat" style="font-size:30px; color:#2980b9; display:inline-block; animation: horarioSpin 1s linear infinite;"></i>
    <p style="margin-top:15px; font-size:18px; font-weight:bold; color:#2c3e50;">
      Procesando...<br>Por favor espere
    </p>
  </div>
</div>
<?php if (!$modo_qr_programacion) { n360_render_content_separator('bottom'); n360_render_footer(); } ?>

<script>
window.horariosInitialData = <?= json_encode($initialSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.horariosInitialError = <?= json_encode($initialError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function () {
  const state = {
    snapshot: window.horariosInitialData || {summary:{},horarios:[],buses_sin_horario:[],buses_taller:[],oficinas_origen:[],oficinas_destino:[],fechas:{}},
    currentRow: null,
    busMode: 'asignar',
    busSelection: null,
    busList: [],
    disabledRows: [],
    historialRows: [],
    historialSoloTransbordo: false,
    pendingMotivoConfig: null,
    pendingMotivoResolver: null,
    editRow: null,
    collapsedOrigins: new Set(),
    isBusy: false,
    busyCount: 0
  };
  const $ = id => document.getElementById(id);
    const els = {
    alertZone: $('horariosAlertZone'),
    datesWrap: $('horariosDatesWrap'),
    statTotalActivos: $('statTotalActivos'),
    statSinBus: $('statSinBus'),
    statBusesSinHorario: $('statBusesSinHorario'),
    statBusesTaller: $('statBusesTaller'),
    boardCounter: $('boardCounterText'),
    boardContainer: $('boardContainer'),
    sideTaller: $('sideTaller'),
    sideSinHorario: $('sideSinHorario'),
    sideTallerCount: $('sideTallerCount'),
    sideSinHorarioCount: $('sideSinHorarioCount'),
    filtro: $('filtroHorarios'),
    btnRefresh: $('btnRefreshHorarios'),
    btnNuevoHorario: $('btnNuevoHorario'),
    btnExportImagen: $('btnExportImagen'),

btnAbrirModalImagen: $('btnAbrirModalImagen'),
btnDescargarPizarra: $('btnDescargarPizarra'),
btnDescargarTabla: $('btnDescargarTabla'),
btnDescargarPizarraPDF: $('btnDescargarPizarraPDF'),
btnDescargarTablaPDF: $('btnDescargarTablaPDF'),
switchModoImagen: $('switchModoImagen'),
lblModoImagen: $('lblModoImagen'),
txtModoImagen: $('txtModoImagen'),

    btnHistorial: $('btnHistorial'),
    btnInhabilitados: $('btnInhabilitados'),
    btnLimpiarPizarra: $('btnLimpiarPizarra'),
    btnConfirmLimpiarPizarra: $('btnConfirmLimpiarPizarra'),
    crearOrigen: $('crearOrigen'),
    crearDestino: $('crearDestino'),
    crearHora: $('crearHora'),
    crearComentario: $('crearComentario'),
    crearRutaList: $('crearRutaList'),
    crearRutaPreview: $('crearRutaPreview'),
    btnLimpiarRutaCrear: $('btnLimpiarRutaCrear'),
    previewNuevoHorario: $('previewNuevoHorario'),
    btnGuardarNuevoHorario: $('btnGuardarNuevoHorario'),
    btnSwapOficinas: $('btnSwapOficinas'),
    quickTimeWrap: $('quickTimeWrap'),
    editarHoraSubtitulo: $('editarHoraSubtitulo'),
    editarHoraInput: $('editarHoraInput'),

    editarDestino: $('editarDestino'),
    editarRutaList: $('editarRutaList'),
    editarRutaPreview: $('editarRutaPreview'),
    btnLimpiarRutaEditar: $('btnLimpiarRutaEditar'),
    previewEditarHora: $('previewEditarHora'),
    btnGuardarEditarHora: $('btnGuardarEditarHora'),

    quickEditTimeWrap: $('quickEditTimeWrap'),
    motivoTitulo: $('motivoTitulo'),
    motivoOptions: $('motivoOptions'),
    motivoPreview: $('motivoPreview'),
    motivoLibre: $('motivoLibre'),
    btnAceptarMotivo: $('btnAceptarMotivo'),
    modalBusTitle: $('modalBusTitle'),
    modalBusSubtitle: $('modalBusSubtitle'),
    busSearch: $('busSearch'),
    busSelectedLabel: $('busSelectedLabel'),
    busList: $('busList'),
    btnConfirmBus: $('btnConfirmBus'),
    historialContainer: $('historialContainer'),
    historialSearch: $('historialSearch'),
    historialSearchInfo: $('historialSearchInfo'),
    inhabilitadosContainer: $('inhabilitadosContainer'),
    btnFiltroTransbordoHistorial: $('btnFiltroTransbordoHistorial'),
    btnLimpiarFiltroHistorial: $('btnLimpiarFiltroHistorial'),
    };

    function horarioMostrarLoader() {
      const modal = document.getElementById('modal-cargando');
      if (modal) modal.style.display = 'flex';
    }

    function horarioOcultarLoader() {
      const modal = document.getElementById('modal-cargando');
      if (modal) modal.style.display = 'none';
    }

    function horarioSetBusy(flag) {
      state.isBusy = flag;

      [
        els.btnRefresh,
        els.btnNuevoHorario,
        els.btnExportImagen,
        els.btnHistorial,
        els.btnInhabilitados,
        els.btnLimpiarPizarra,
        els.btnConfirmLimpiarPizarra,
        els.btnGuardarNuevoHorario,
        els.btnGuardarEditarHora,
        els.btnAceptarMotivo,
        els.btnConfirmBus,
        els.btnDescargarPizarraPDF,
        els.btnDescargarTablaPDF
      ].forEach(btn => {
        if (btn) btn.disabled = flag;
      });

      document.querySelectorAll('[data-action], [data-reactivar], [data-marcar-taller], [data-marcar-sin-horario]').forEach(el => {
        if (flag) {
          el.style.pointerEvents = 'none';
          el.style.opacity = '0.65';
        } else {
          el.style.pointerEvents = '';
          el.style.opacity = '';
        }
      });
    }

    function horarioBeginLoading() {
      state.busyCount = (state.busyCount || 0) + 1;
      horarioSetBusy(true);
      horarioMostrarLoader();
    }

    function horarioEndLoading() {
      state.busyCount = Math.max((state.busyCount || 1) - 1, 0);

      if (state.busyCount === 0) {
        horarioSetBusy(false);
        horarioOcultarLoader();
      }
    }

  const modalCreate = new bootstrap.Modal($('modalCrearHorario')); const modalEdit = new bootstrap.Modal($('modalEditarHora'));
  const modalMotivo = new bootstrap.Modal($('modalMotivo')); const modalBus = new bootstrap.Modal($('modalBus'));
  // Enfocar automáticamente el buscador al abrir el modal de bus
$('modalBus').addEventListener('shown.bs.modal', () => {
  if (els.busSearch) {
    els.busSearch.focus({ preventScroll: true });
    els.busSearch.select();
  }
});
  const modalHistorial = new bootstrap.Modal($('modalHistorial')); const modalInhabilitados = new bootstrap.Modal($('modalInhabilitados'));
  const modalExportImagen = new bootstrap.Modal($('modalExportImagen'));
  const modalLimpiarPizarra = $('modalLimpiarPizarra') ? new bootstrap.Modal($('modalLimpiarPizarra')) : null;
  if (els.filtro) {
    setTimeout(() => els.filtro.removeAttribute('readonly'), 180);
    els.filtro.addEventListener('focus', () => els.filtro.removeAttribute('readonly'), { once: true });
  }

  const quickTimes = ['06:00','08:00','10:00','12:00','16:00','18:00','20:00','22:00'];
  const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  const showAlert = (type, message) => { els.alertZone.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${esc(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`; };
  const badge = (text, variant) => `<span class="mini-badge mini-badge--${variant}">${esc(text)}</span>`;
  const fmtHora = value => { const txt = String(value || '').trim(); if (!txt) return ''; const p = txt.split(':'); return p.length >= 2 ? `${String(p[0]).padStart(2,'0')}:${String(p[1]).padStart(2,'0')}` : txt.slice(0,5); };
  const isNext = value => { const hora = fmtHora(value); if (!hora) return false; const hh = parseInt(hora.split(':')[0],10); return hh >= 0 && hh <= 4; };
  const sortKey = row => {
    const hora = fmtHora(row.clm_progbuses_horasalida || row.hora_fmt || '99:99');
    const [hhRaw, mmRaw] = hora.split(':');

    const hh = parseInt(hhRaw, 10);
    const mm = parseInt(mmRaw, 10);

    return [
      isNext(hora) ? 1 : 0,
      Number.isNaN(hh) ? 99 : hh,
      Number.isNaN(mm) ? 99 : mm,
      String(row.oficina_destino || '').toUpperCase(),
      parseInt(row.clm_progbuses_progid || 0, 10)
    ];
  };
  const cmpArr = (a,b) => { for (let i=0;i<Math.max(a.length,b.length);i++){ if(a[i]<b[i]) return -1; if(a[i]>b[i]) return 1; } return 0; };
  function compareBusNatural(a, b) {
  const busA = String(a?.bus ?? '').trim();
  const busB = String(b?.bus ?? '').trim();

  const cmpBus = busA.localeCompare(busB, 'es', {
    numeric: true,
    sensitivity: 'base'
  });
  if (cmpBus !== 0) return cmpBus;

  const placaA = String(a?.placa ?? '').trim();
  const placaB = String(b?.placa ?? '').trim();

  const cmpPlaca = placaA.localeCompare(placaB, 'es', {
    numeric: true,
    sensitivity: 'base'
  });
  if (cmpPlaca !== 0) return cmpPlaca;

  return Number(a?.clm_placas_id ?? 0) - Number(b?.clm_placas_id ?? 0);
}
function waitModalHidden(modalElement) {
  return new Promise((resolve) => {
    if (!modalElement || !modalElement.classList.contains('show')) {
      resolve();
      return;
    }

    const handler = () => {
      modalElement.removeEventListener('hidden.bs.modal', handler);
      resolve();
    };

    modalElement.addEventListener('hidden.bs.modal', handler, { once: true });
  });
}
function getSedeNombreById(id) {
  const sedes = state.snapshot.oficinas_destino || [];
  const row = sedes.find(o => String(o.clm_sedes_id) === String(id));
  return row ? (row.oficina || `Sede ${id}`) : `Sede ${id}`;
}

function rutaToArray(ruta) {
  return String(ruta || '')
    .split(',')
    .map(x => x.trim())
    .filter(Boolean);
}

function getRutaSeleccionada(container) {
  if (!container) return [];

  return Array.from(container.querySelectorAll('input[type="checkbox"]:checked'))
    .map(chk => String(chk.value))
    .filter(Boolean);
}

function rutaIdsToTexto(ids) {
  const arr = Array.isArray(ids) ? ids : rutaToArray(ids);

  if (!arr.length) {
    return 'directa, sin sedes intermedias';
  }

  return arr.map(id => getSedeNombreById(id)).join(' → ');
}

function renderRutaSelector({
  container,
  preview,
  origenId,
  destinoId,
  selectedIds = []
}) {
  if (!container) return;

  const sedes = state.snapshot.oficinas_destino || [];
  const origen = String(origenId || '');
  const destino = String(destinoId || '');

  const selectedSet = new Set(
    (selectedIds || [])
      .map(String)
      .filter(id => id && id !== origen && id !== destino)
  );

  const disponibles = sedes.filter(o => {
    const id = String(o.clm_sedes_id || '');
    return id && id !== origen && id !== destino;
  });

  if (!disponibles.length) {
    container.innerHTML = `<div class="empty-state">No hay sedes disponibles para ruta intermedia.</div>`;
    if (preview) preview.textContent = 'Ruta: directa, sin sedes intermedias.';
    return;
  }

  container.innerHTML = disponibles.map(o => {
    const id = String(o.clm_sedes_id);
    const checked = selectedSet.has(id) ? 'checked' : '';

    return `
      <label class="ruta-item">
        <input type="checkbox" value="${esc(id)}" ${checked}>
        <span>${esc(o.oficina || `Sede ${id}`)}</span>
      </label>
    `;
  }).join('');

  const updatePreview = () => {
    const ids = getRutaSeleccionada(container);
    if (preview) preview.textContent = `Ruta: ${rutaIdsToTexto(ids)}`;
  };

  container.querySelectorAll('input[type="checkbox"]').forEach(chk => {
    chk.addEventListener('change', updatePreview);
  });

  updatePreview();
}

function refreshCrearRutaSelector() {
  const selected = getRutaSeleccionada(els.crearRutaList);

  renderRutaSelector({
    container: els.crearRutaList,
    preview: els.crearRutaPreview,
    origenId: els.crearOrigen.value,
    destinoId: els.crearDestino.value,
    selectedIds: selected
  });
}

function refreshEditarRutaSelector() {
  if (!state.editRow) return;

  const selected = getRutaSeleccionada(els.editarRutaList);

  renderRutaSelector({
    container: els.editarRutaList,
    preview: els.editarRutaPreview,
    origenId: state.editRow.clm_progbuses_idoficina_origen,
    destinoId: els.editarDestino.value,
    selectedIds: selected
  });
}

function getFilteredRows(){
  const q = (els.filtro.value || '').trim().toLowerCase();
  const rows = Array.isArray(state.snapshot.horarios) ? state.snapshot.horarios : [];

  if (!q) return rows;

  return rows.filter(r => {
    const rutaTexto = rutaIdsToTexto(r.clm_progbuses_ruta || '');

    return [
      r.clm_progbuses_progid,
      r.oficina_origen,
      r.oficina_destino,
      rutaTexto,
      r.bus,
      r.placa,
      r.tipo_vehiculo,
      fmtHora(r.clm_progbuses_horasalida),
      Number(r.clm_progbuses_estado) === 1 ? 'ACTIVO' : 'INACTIVO'
    ].join(' | ').toLowerCase().includes(q);
  });
}

  function renderDates(){ const f=state.snapshot.fechas||{}; els.datesWrap.innerHTML = `<span class="horario-date-chip"><i class="bi bi-calendar-date me-2"></i>Día Operativo: <strong>${esc(f.fecha_base||'—')}</strong></span><span class="horario-date-chip"><i class="bi bi-arrow-right-circle me-2"></i>Siguiente día operativo: <strong>${esc(f.fecha_sig||'—')}</strong></span><span class="horario-date-chip"><i class="bi bi-clock me-2"></i>Corte diario: 00:00 a 04:59</span>`; }
  function renderSummary(){ const s=state.snapshot.summary||{}; els.statTotalActivos.textContent=s.total_activos??0; els.statSinBus.textContent=s.total_sin_bus??0; els.statBusesSinHorario.textContent=s.buses_sin_horario??0; els.statBusesTaller.textContent=s.buses_taller??0; }
function renderSideList(container, rows, emptyText, includeMotivo = false, modo = '') {
  const orderedRows = (rows || []).slice().sort(compareBusNatural);

  if (!orderedRows.length) {
    container.innerHTML = `<div class="empty-state">${esc(emptyText)}</div>`;
    return;
  }

  container.innerHTML = orderedRows.map(r => {
    const btnTaller = modo === 'sin_horario'
      ? `
        <div class="side-list-actions mt-2">
          <button 
            type="button" 
            class="btn btn-sm btn-outline-danger btn-side-taller"
            data-marcar-taller="${esc(r.clm_placas_id)}"
            data-bus="${esc(r.bus || '')}"
            data-placa="${esc(r.placa || '')}">
            <i class="bi bi-tools me-1"></i>
            Pasar a taller
          </button>
        </div>
      `
      : '';

    const btnSinHorario = modo === 'taller'
      ? `
        <div class="side-list-actions mt-2">
          <button 
            type="button" 
            class="btn btn-sm btn-outline-success btn-side-sin-horario"
            data-marcar-sin-horario="${esc(r.clm_placas_id)}"
            data-bus="${esc(r.bus || '')}"
            data-placa="${esc(r.placa || '')}">
            <i class="bi bi-check-circle me-1"></i>
            Pasar a sin horario
          </button>
        </div>
      `
      : '';

    return `
      <div class="side-list-item">
        <div class="side-list-item__title">${esc(r.bus || 'SIN NOMBRE')}</div>
        <div class="side-list-item__meta">
          Placa: ${esc(r.placa || '—')} · Tipo: ${esc(r.tipo_vehiculo || '—')}
        </div>

        ${includeMotivo && r.motivo ? `
          <div class="side-list-item__meta mt-1">
            Motivo: ${esc(r.motivo)}
          </div>
        ` : ''}

        ${btnTaller}
        ${btnSinHorario}
      </div>
    `;
  }).join('');

  if (modo === 'sin_horario') {
    attachMarcarTallerActions(container);
  }

  if (modo === 'taller') {
    attachMarcarSinHorarioActions(container);
  }
}
function attachMarcarTallerActions(container) {
  container.querySelectorAll('[data-marcar-taller]').forEach(btn => {
    btn.addEventListener('click', () => {
      const idplaca = btn.dataset.marcarTaller;
      const bus = btn.dataset.bus || '';
      const placa = btn.dataset.placa || '';

      askMotivo({
        accion: 'MARCAR_TALLER',
        titulo: 'Motivo para enviar unidad a taller',
        options: [
          {
            key: 'NORMAL',
            label: 'Enviar a taller',
            preview: `TALLER: unidad ${bus} ${placa ? '(' + placa + ')' : ''} enviada a taller desde estado en espera / sin horario`
          },
          {
            key: 'OTRO',
            label: 'OTRO MOTIVO',
            preview: 'TALLER: '
          }
        ],
        build: (sel, libre) => {
          if (sel === 'NORMAL') {
            return `TALLER: unidad ${bus} ${placa ? '(' + placa + ')' : ''} enviada a taller desde estado en espera / sin horario`;
          }

          return `TALLER: ${libre}`;
        }
      }).then(motivo => {
        if (!motivo) return;

        performAction(
          'marcar_bus_taller',
          {
            idplaca,
            motivo
          },
          'Unidad enviada a taller correctamente.'
        );
      });
    });
  });
}

function attachMarcarSinHorarioActions(container) {
  container.querySelectorAll('[data-marcar-sin-horario]').forEach(btn => {
    btn.addEventListener('click', () => {
      const idplaca = btn.dataset.marcarSinHorario;
      const bus = btn.dataset.bus || '';
      const placa = btn.dataset.placa || '';

      askMotivo({
        accion: 'MARCAR_SIN_HORARIO',
        titulo: 'Motivo para liberar unidad de taller',
        options: [
          {
            key: 'NORMAL',
            label: 'Liberar de taller',
            preview: `Unidad ${bus} ${placa ? '(' + placa + ')' : ''} liberada de taller y enviada a espera / sin horario`
          },
          {
            key: 'OTRO',
            label: 'OTRO MOTIVO',
            preview: 'Unidad liberada de taller por: '
          }
        ],
        build: (sel, libre) => {
          if (sel === 'NORMAL') {
            return `Unidad ${bus} ${placa ? '(' + placa + ')' : ''} liberada de taller y enviada a espera / sin horario`;
          }

          return `Unidad liberada de taller por: ${libre}`;
        }
      }).then(motivo => {
        if (!motivo) return;

        performAction(
          'marcar_bus_sin_horario',
          {
            idplaca,
            motivo
          },
          'Unidad liberada de taller correctamente.'
        );
      });
    });
  });
}


  function renderScheduleCard(row, fechaSig){
  const activo = Number(row.clm_progbuses_estado) === 1;
  const tieneBus = !!row.clm_progbuses_idplaca;
  const estaEnTaller = tieneBus && String(row.estado_actual_unidad || '').toUpperCase() === 'TALLER';
  const hora = fmtHora(row.clm_progbuses_horasalida || row.hora_fmt);

  let estadoBadge = badge('INACTIVO', 'danger');
  if (activo && tieneBus) estadoBadge = estaEnTaller ? badge('EN TALLER', 'taller') : badge('ASIGNADO', 'success');
  if (activo && !tieneBus) estadoBadge = badge('SIN BUS', 'warning');

  const nextBadge = isNext(hora) ? `<span class="mini-badge mini-badge--next">${esc(fechaSig || 'SIG. DÍA')}</span>` : '';

  let actions = '';
  if (activo) {
    if (tieneBus) {
      actions += `
        <button class="btn-action-card primary full" data-action="cambiar" data-id="${row.clm_progbuses_progid}">
          <i class="bi bi-arrow-left-right"></i> Cambiar unidad
        </button>
        <button class="btn-action-card warning" data-action="remover" data-id="${row.clm_progbuses_progid}">
          <i class="bi bi-dash-circle"></i> ${estaEnTaller ? 'Quitar' : 'Retirar'}
        </button>
      `;
    } else {
      actions += `
        <button class="btn-action-card primary full" data-action="asignar" data-id="${row.clm_progbuses_progid}">
          <i class="bi bi-plus-circle"></i> Asignar unidad
        </button>
      `;
    }

    actions += `
      <button class="btn-action-card light" data-action="editarhora" data-id="${row.clm_progbuses_progid}">
        <i class="bi bi-clock"></i> Horario
      </button>
      <button class="btn-action-card danger" data-action="inactivar" data-id="${row.clm_progbuses_progid}">
        <i class="bi bi-ban"></i> Inhabilitar
      </button>
    `;
  }

  const unidadTexto = tieneBus
    ? `${row.bus || 'UNIDAD'} · ${row.placa || '—'}`
    : 'Sin unidad asignada';

  const metaTexto = tieneBus
    ? `Tipo: ${row.tipo_vehiculo || '—'} · Horario #${row.clm_progbuses_progid || '0'}`
    : `Pendiente de asignación · Horario #${row.clm_progbuses_progid || '0'}`;
  const rutaTexto = rutaIdsToTexto(row.clm_progbuses_ruta || '');
  const rutaHtml = row.clm_progbuses_ruta
    ? `<div class="schedule-card__ruta">
        <i class="bi bi-signpost-split me-1"></i>
        Ruta: ${esc(rutaTexto)}
      </div>`
    : '';
  const comentario = String(row.clm_progbuses_comentario || '').trim();
  const comentarioHtml = comentario
    ? `<div class="schedule-card__comentario">
        <i class="bi bi-chat-left-text me-1"></i>
        ${esc(comentario)}
      </div>`
    : '';
      const tallerNota = estaEnTaller
        ? `<div class="schedule-card__taller-note">
            <i class="bi bi-tools me-1"></i> Esta unidad está actualmente en taller.
          </div>`
        : '';

  return `
    <article class="${tieneBus ? `schedule-card ${estaEnTaller ? 'schedule-card--taller' : ''}` : 'schedule-card schedule-card--empty'}">
      <div class="schedule-card__top">
        <div class="schedule-card__time">
          <div class="schedule-card__hour">${esc(hora || '—')}</div>
          <div class="schedule-card__day">${isNext(hora) ? esc(fechaSig || 'SIGUIENTE DÍA') : 'MISMO DÍA'}</div>
        </div>
        <div class="schedule-card__badges">
          ${nextBadge}
          ${estadoBadge}
        </div>
      </div>

      <div class="schedule-card__dest">${
        esc(
          (row.oficina_destino || 'Destino no definido') +
          (row.servicio_unidad ? ` | ${row.servicio_unidad}` : '')
        )
      }</div>
      <div class="schedule-card__unit">${esc(unidadTexto)}</div>
      <div class="schedule-card__meta">${esc(metaTexto)}</div>
      ${rutaHtml}
      ${comentarioHtml}
      ${tallerNota}

      <div class="schedule-card__footer">
        <div class="schedule-actions">
          ${actions}
        </div>
      </div>
    </article>
  `;
}
  function attachOriginCollapse(){
  els.boardContainer.querySelectorAll('[data-origin-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.originToggle;
      if (!key) return;

      if (state.collapsedOrigins.has(key)) {
        state.collapsedOrigins.delete(key);
      } else {
        state.collapsedOrigins.add(key);
      }

      const block = btn.closest('.origin-block');
      if (block) block.classList.toggle('is-collapsed', state.collapsedOrigins.has(key));
    });
  });
}
    function attachBoardActions(){ els.boardContainer.querySelectorAll('[data-action]').forEach(btn=>{ btn.addEventListener('click',()=>{ const row=(state.snapshot.horarios||[]).find(r=>String(r.clm_progbuses_progid)===String(btn.dataset.id)); if(!row) return; const action=btn.dataset.action; if(action==='asignar') openBusModal(row,'asignar'); else if(action==='cambiar') openBusModal(row,'cambiar'); else if(action==='editarhora') openEditTimeModal(row); 

            else if(action === 'remover'){
              const estaEnTaller = String(row.estado_actual_unidad || '').toUpperCase() === 'TALLER';

              if (estaEnTaller) {
                const motivoAuto = `Retiro referencial: la unidad ${row.bus || ''} ${row.placa ? '(' + row.placa + ')' : ''} ya se encontraba en TALLER. Se remueve de este horario activo para sincerar la programación.`;

                performAction(
                  'remover_bus',
                  {
                    progid: row.clm_progbuses_progid,
                    motivo: motivoAuto
                  },
                  'Unidad quitada del horario. La unidad continúa registrada como taller.'
                );

                return;
              }

              askMotivo({accion:'RETIRO', titulo:'Motivo del retiro de la unidad', permitirTaller:true}).then(m => {
                if(m) performAction('remover_bus', {progid:row.clm_progbuses_progid, motivo:m}, 'Bus removido del horario.');
              });
            }

          else if(action==='inactivar'){ askMotivo({accion:'INACTIVAR',titulo:'Motivo de inhabilitación del horario',permitirTaller:false}).then(m=>{ if(m) performAction('inactivar_horario',{progid:row.clm_progbuses_progid,motivo:m},'Horario inhabilitado correctamente.'); }); } }); }); }

    function renderBoard(){
  const rows = getFilteredRows();

  if (els.boardCounter) {
    els.boardCounter.textContent = `${rows.length} horario(s)`;
  }

  if(!rows.length){
    els.boardContainer.innerHTML = `<div class="empty-state">No hay horarios para mostrar con el filtro actual.</div>`;
    return;
  }

  const groups = {};
  rows.forEach(r => {
    const key = r.oficina_origen || 'SIN ORIGEN';
    (groups[key] ||= []).push(r);
  });

  const fechaSig = (state.snapshot.fechas || {}).fecha_sig_corta || '';

els.boardContainer.innerHTML = Object.keys(groups)
    .sort((a, b) => {
      const ordenA = Math.min(
        ...groups[a].map(r => {
          const n = Number(r.orden_pizarra_origen);
          return Number.isFinite(n) && n > 0 ? n : 999999;
        })
      );

      const ordenB = Math.min(
        ...groups[b].map(r => {
          const n = Number(r.orden_pizarra_origen);
          return Number.isFinite(n) && n > 0 ? n : 999999;
        })
      );

      if (ordenA !== ordenB) return ordenA - ordenB;

      return String(a || '').localeCompare(String(b || ''), 'es', {
        numeric: true,
        sensitivity: 'base'
      });
    })
    .map(origen => {
      const grp = groups[origen].slice().sort((a,b) => cmpArr(sortKey(a), sortKey(b)));
      const total = grp.length;
      const totalAsignados = grp.filter(x => !!x.clm_progbuses_idplaca && Number(x.clm_progbuses_estado) === 1).length;
      const totalSinBus = grp.filter(x => !x.clm_progbuses_idplaca && Number(x.clm_progbuses_estado) === 1).length;
      const collapsed = state.collapsedOrigins.has(origen);

      return `
        <section class="origin-block ${collapsed ? 'is-collapsed' : ''}">
          <button type="button" class="origin-block__head" data-origin-toggle="${esc(origen)}">
            <div class="origin-block__head-main">
              <span class="origin-block__title">${esc(origen)}</span>
              <span class="origin-block__resume">${totalAsignados} asignados · ${totalSinBus} sin bus</span>
            </div>

            <div class="d-flex align-items-center gap-2">
              <span class="origin-block__count">${total}</span>
              <i class="bi bi-chevron-down origin-block__icon"></i>
            </div>
          </button>

          <div class="origin-block__body">
            <div class="origin-grid">
              ${grp.map(row => renderScheduleCard(row, fechaSig)).join('')}
            </div>
          </div>
        </section>
      `;
    }).join('');

  attachOriginCollapse();
  attachBoardActions();
}
    const loadImage = (src) => new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = `${src}${src.includes('?') ? '&' : '?'}v=${Date.now()}`;
  });

  function createHiDPICanvas(width, height, ratio = 2) {
    const canvas = document.createElement('canvas');
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.textBaseline = 'top';
    return { canvas, ctx };
  }

  function roundRectPath(ctx, x, y, w, h, r) {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
  }

  function drawCard(ctx, x, y, w, h, {
    radius = 18,
    fill = '#ffffff',
    stroke = null,
    lineWidth = 1,
    shadow = true,
    shadowColor = 'rgba(15,23,42,.06)',
    shadowBlur = 16,
    shadowOffsetY = 6
  } = {}) {
    ctx.save();
    if (shadow) {
      ctx.shadowColor = shadowColor;
      ctx.shadowBlur = shadowBlur;
      ctx.shadowOffsetY = shadowOffsetY;
    }
    roundRectPath(ctx, x, y, w, h, radius);
    ctx.fillStyle = fill;
    ctx.fill();
    ctx.restore();

    if (stroke) {
      ctx.save();
      roundRectPath(ctx, x, y, w, h, radius);
      ctx.strokeStyle = stroke;
      ctx.lineWidth = lineWidth;
      ctx.stroke();
      ctx.restore();
    }
  }

  function drawText(ctx, text, x, y, {
    font = '14px Segoe UI',
    color = '#111827',
    align = 'left'
  } = {}) {
    ctx.save();
    ctx.font = font;
    ctx.fillStyle = color;
    ctx.textAlign = align;
    ctx.fillText(String(text ?? ''), x, y);
    ctx.restore();
  }

const FONT_PIZARRA_NAME = 'N360Pizarra';

function fontPizarra(weight, sizePx) {
  return `${weight} ${sizePx}px "${FONT_PIZARRA_NAME}", Arial, sans-serif`;
}

async function asegurarFuentePizarra() {
  if (!document.fonts) return;

  try {
    await Promise.all([
      document.fonts.load(fontPizarra(400, 14)),
      document.fonts.load(fontPizarra(700, 18)),
      document.fonts.load(fontPizarra(800, 27)),
      document.fonts.load(fontPizarra(900, 42))
    ]);

    await document.fonts.ready;
  } catch (e) {
    console.warn('No se pudo cargar N360Pizarra, se usará fuente alternativa:', e);
  }
}

  function measureTextWidth(ctx, text, font = fontPizarra(400, 14)) {
    ctx.save();
    ctx.font = font;
    const w = ctx.measureText(String(text ?? '')).width;
    ctx.restore();
    return w;
  }

  function fitText(ctx, text, maxWidth, font = fontPizarra(400, 14)) {
    const raw = String(text ?? '');
    if (!raw) return '';
    ctx.save();
    ctx.font = font;
    if (ctx.measureText(raw).width <= maxWidth) {
      ctx.restore();
      return raw;
    }
    let result = raw;
    while (result.length > 0 && ctx.measureText(result + '…').width > maxWidth) {
      result = result.slice(0, -1);
    }
    ctx.restore();
    return result + '…';
  }

  function drawLine(ctx, x1, y1, x2, y2, color = '#dce4ee', width = 1) {
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.strokeStyle = color;
    ctx.lineWidth = width;
    ctx.stroke();
    ctx.restore();
  }

  function formatDateParts(now = new Date()) {
    const pad = n => String(n).padStart(2, '0');
    return {
      fecha: `${pad(now.getDate())}-${pad(now.getMonth() + 1)}-${now.getFullYear()}`,
      hora: `${pad(now.getHours())}:${pad(now.getMinutes())}`
    };
  }

  function safeUpper(v) {
    return String(v ?? '').trim().toUpperCase();
  }

  function slugify(v) {
    return String(v ?? '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '');
  }
function compareTextNatural(a, b) {
  return String(a || '').localeCompare(String(b || ''), 'es', {
    numeric: true,
    sensitivity: 'base'
  });
}

function agruparFilasPorGrupoPizarra(rows) {
  const groups = {};

  (rows || []).forEach(r => {
    const key = String(r.grupo_pizarra_origen || 'SIN GRUPO').trim() || 'SIN GRUPO';
    const tipoImagen = String(r.tipo_imagen_grupo_origen || 'PIZARRA').trim().toUpperCase() || 'PIZARRA';

    if (!groups[key]) {
      groups[key] = {
        nombreGrupo: key,
        tipoImagen,
        filas: []
      };
    }

    groups[key].filas.push(r);
  });

  return groups;
}

function descargarCanvas(canvas, nombreArchivo) {
  const a = document.createElement('a');
  a.href = canvas.toDataURL('image/png');
  a.download = nombreArchivo;
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function pausa(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}


function getModoImagen() {
  return els.switchModoImagen?.checked ? 'TODOS' : 'PREDEFINIDO';
}

function actualizarTextoModoImagen() {
  const modo = getModoImagen();

  if (modo === 'TODOS') {
    els.lblModoImagen.textContent = 'Todos';
    els.txtModoImagen.textContent = 'Todos: descarga todos los grupos en el formato seleccionado.';
  } else {
    els.lblModoImagen.textContent = 'Predefinido';
    els.txtModoImagen.textContent = 'Predefinido: solo descarga los grupos que pertenecen al formato seleccionado.';
  }
}

async function generarImagenPorFormato(formatoSeleccionado) {
  const formato = String(formatoSeleccionado || 'PIZARRA').toUpperCase();
  const modo = getModoImagen();

  const filas = getFilteredRows();
  const busesSinHorario = (state.snapshot.buses_sin_horario || []).slice().sort(compareBusNatural);
  const busesTaller = (state.snapshot.buses_taller || []).slice().sort(compareBusNatural);

  const gruposPizarra = agruparFilasPorGrupoPizarra(filas);
  const nombresGrupos = Object.keys(gruposPizarra).sort(compareTextNatural);

  if (!nombresGrupos.length) {
    showAlert('warning', 'No hay grupos disponibles para generar imagen.');
    return;
  }

  let total = 0;

  for (const nombreGrupo of nombresGrupos) {
    const grupoInfo = gruposPizarra[nombreGrupo];
    const filasGrupo = grupoInfo?.filas || [];
    const tipoPredefinido = String(grupoInfo?.tipoImagen || 'PIZARRA').toUpperCase();

    if (modo === 'PREDEFINIDO' && tipoPredefinido !== formato) {
      continue;
    }

    if (formato === 'TABLA') {
      await generarImagenTablaGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller);
    } else {
      await generarImagenPizarraGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller);
    }

    total++;
    await pausa(180);
  }

  if (total === 0) {
    showAlert(
      'warning',
      modo === 'PREDEFINIDO'
        ? `No hay grupos configurados como ${formato}.`
        : `No hay grupos para descargar como ${formato}.`
    );
    return;
  }

  showAlert(
    'success',
    modo === 'TODOS'
      ? `Se generaron ${total} imagen(es) en formato ${formato}.`
      : `Se generaron ${total} imagen(es) ${formato} según configuración personalizada.`
  );
}


async function generarCanvasPorFormato(formatoSeleccionado) {
  const formato = String(formatoSeleccionado || 'PIZARRA').toUpperCase();
  const modo = getModoImagen();

  const filas = getFilteredRows();
  const busesSinHorario = (state.snapshot.buses_sin_horario || []).slice().sort(compareBusNatural);
  const busesTaller = (state.snapshot.buses_taller || []).slice().sort(compareBusNatural);

  const gruposPizarra = agruparFilasPorGrupoPizarra(filas);
  const nombresGrupos = Object.keys(gruposPizarra).sort(compareTextNatural);

  if (!nombresGrupos.length) {
    throw new Error('No hay grupos disponibles para generar PDF.');
  }

  const items = [];

  for (const nombreGrupo of nombresGrupos) {
    const grupoInfo = gruposPizarra[nombreGrupo];
    const filasGrupo = grupoInfo?.filas || [];
    const tipoPredefinido = String(grupoInfo?.tipoImagen || 'PIZARRA').toUpperCase();

    if (modo === 'PREDEFINIDO' && tipoPredefinido !== formato) {
      continue;
    }

    let item = null;

    if (formato === 'TABLA') {
      item = await generarImagenTablaGrupo(
        nombreGrupo,
        filasGrupo,
        busesSinHorario,
        busesTaller,
        { salida: 'canvas' }
      );
    } else {
      item = await generarImagenPizarraGrupo(
        nombreGrupo,
        filasGrupo,
        busesSinHorario,
        busesTaller,
        { salida: 'canvas' }
      );
    }

    if (item?.canvas) {
      items.push(item);
    }

    await pausa(120);
  }

  if (!items.length) {
    throw new Error(
      modo === 'PREDEFINIDO'
        ? `No hay grupos configurados como ${formato} para PDF.`
        : `No hay grupos para generar PDF como ${formato}.`
    );
  }

  return { formato, modo, items };
}

function descargarPDFDesdeCanvas(items, nombrePDF) {
  if (!window.jspdf || !window.jspdf.jsPDF) {
    throw new Error('jsPDF no está cargado. Revisa el script CDN de jsPDF.');
  }

  const { jsPDF } = window.jspdf;

  let pdf = null;

  items.forEach((item, index) => {
    const canvas = item.canvas;

    // Tus canvas son HiDPI ratio 2, por eso se divide entre 2.
    const pageW = canvas.width / 2;
    const pageH = canvas.height / 2;
    const orientation = pageW > pageH ? 'landscape' : 'portrait';

    if (!pdf) {
      pdf = new jsPDF({
        orientation,
        unit: 'px',
        format: [pageW, pageH],
        compress: true
      });
    } else {
      pdf.addPage([pageW, pageH], orientation);
    }

    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 0, 0, pageW, pageH, undefined, 'FAST');
  });

  pdf.save(nombrePDF);
}

async function generarPDFQRDosHojas() {
  const filas = getFilteredRows();

  const busesSinHorario = (state.snapshot.buses_sin_horario || [])
    .slice()
    .sort(compareBusNatural);

  const busesTaller = (state.snapshot.buses_taller || [])
    .slice()
    .sort(compareBusNatural);

  if (!filas.length && !busesSinHorario.length && !busesTaller.length) {
    throw new Error('No hay información para generar el PDF de programación.');
  }

  const gruposPizarra = agruparFilasPorGrupoPizarra(filas);
  const nombresGrupos = Object.keys(gruposPizarra).sort(compareTextNatural);

  if (!nombresGrupos.length) {
    throw new Error('No hay grupos de pizarra configurados en las sedes de origen.');
  }

  const items = [];

  for (const nombreGrupo of nombresGrupos) {
    const grupoInfo = gruposPizarra[nombreGrupo];
    const filasGrupo = grupoInfo?.filas || [];

    // Esta es la misma lógica del botón "Generar imágenes"
    const tipoImagen = String(grupoInfo?.tipoImagen || 'PIZARRA')
      .trim()
      .toUpperCase();

    let hoja = null;

    if (tipoImagen === 'TABLA') {
      hoja = await generarImagenTablaGrupo(
        nombreGrupo,
        filasGrupo,
        busesSinHorario,
        busesTaller,
        { salida: 'canvas' }
      );
    } else {
      hoja = await generarImagenPizarraGrupo(
        nombreGrupo,
        filasGrupo,
        busesSinHorario,
        busesTaller,
        { salida: 'canvas' }
      );
    }

    if (hoja?.canvas) {
      items.push(hoja);
    }

    await pausa(120);
  }

  if (!items.length) {
    throw new Error('No se pudo generar ninguna hoja para el PDF.');
  }

  const fechaBaseArchivo = slugify((state.snapshot.fechas || {}).fecha_base || 'operativo');
  const nombrePDF = `programacion_qr_configurada_${fechaBaseArchivo}.pdf`;

  descargarPDFDesdeCanvas(items, nombrePDF);
}


async function generarPDFPorFormato(formatoSeleccionado) {
  const { formato, modo, items } = await generarCanvasPorFormato(formatoSeleccionado);

  const fechaBaseArchivo = slugify((state.snapshot.fechas || {}).fecha_base || 'operativo');
  const nombrePDF = `programacion_${formato.toLowerCase()}_${modo.toLowerCase()}_${fechaBaseArchivo}.pdf`;

  descargarPDFDesdeCanvas(items, nombrePDF);

  showAlert(
    'success',
    `Se generó 1 PDF con ${items.length} página(s) en formato ${formato}.`
  );
}


async function generarImagenPizarra() {
  const btn = els.btnExportImagen;
  const btnHtml = btn ? btn.innerHTML : '';

  try {
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando...';
    }

    const filas = getFilteredRows();
    const busesSinHorario = (state.snapshot.buses_sin_horario || []).slice().sort(compareBusNatural);
    const busesTaller = (state.snapshot.buses_taller || []).slice().sort(compareBusNatural);

    if (!filas.length && !busesSinHorario.length && !busesTaller.length) {
      showAlert('warning', 'No hay información para generar la imagen.');
      return;
    }

    const gruposPizarra = agruparFilasPorGrupoPizarra(filas);
    const nombresGrupos = Object.keys(gruposPizarra).sort(compareTextNatural);

    if (!nombresGrupos.length) {
      showAlert('warning', 'No hay grupos de pizarra configurados en las sedes de origen.');
      return;
    }

    let totalGeneradas = 0;

    for (const nombreGrupo of nombresGrupos) {
      const grupoInfo = gruposPizarra[nombreGrupo];
      const filasGrupo = grupoInfo?.filas || [];
      const tipoImagen = String(grupoInfo?.tipoImagen || 'PIZARRA').toUpperCase();

      if (tipoImagen === 'TABLA') {
        await generarImagenTablaGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller);
      } else {
        await generarImagenPizarraGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller);
      }

      totalGeneradas++;
      await pausa(180);
    }

    showAlert('success', `Se generaron ${totalGeneradas} imagen(es) de pizarra por grupo.`);
  } catch (err) {
    showAlert('danger', err.message || 'No se pudo generar la imagen de la pizarra.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = btnHtml;
    }
  }
}


async function generarImagenTablaGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller, options = {}) {
  await asegurarFuentePizarra();

  const filas = (filasGrupo || []).slice().sort((a, b) => {
    return cmpArr(sortKey(a), sortKey(b));
  });

  const margen = 32;
  const anchoTotal = 1080;
  const anchoInterno = anchoTotal - (margen * 2);

  const rowH = 64;
  const tableHeaderH = 48;
  const headerH = 176;
  const resumenH = 92;
  const tablaExtraPadding = 2;

  const altoTabla = tableHeaderH + (filas.length * rowH);
  const alto = 24 + headerH + 2 + altoTabla + tablaExtraPadding + 2 + resumenH + 2;

  const { canvas, ctx } = createHiDPICanvas(anchoTotal, alto, 2);

  const bgPage = '#f6f8fb';
  const card = '#ffffff';
  const cardSoft = '#f8fbff';
  const border = '#d9e2ec';
  const borderSoft = '#e9eef5';
  const navy = '#1f3a5f';
  const blue = '#2f5d8a';
  const blueSoft = '#eaf2fb';
  const slate = '#64748b';
  const textMain = '#1f2937';
  const white = '#ffffff';

  const fBrand = '700 15px "Segoe UI"';
  const fTitle = '700 24px "Segoe UI"';
  const fGrupo = '900 31px "Segoe UI"';
  const fOperativaChip = '900 18px "Segoe UI"';
  const fMetaLabel = '700 11px "Segoe UI"';
  const fMetaValue = '700 16px "Segoe UI"';
  const fTableHead = '800 16px "Segoe UI"';
  const fCell = '18px "Segoe UI"';
  const fCellStrong = '800 18px "Segoe UI"';
  const fBusCell = '900 24px "Segoe UI"';
  const fBadge = '700 11px "Segoe UI"';

  const fechas = state.snapshot.fechas || {};
  const fechaBaseTxt = fechas.fecha_base || '—';
  const fechaSigTxt = fechas.fecha_sig || '—';
  const impresion = formatDateParts(new Date());

  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, anchoTotal, alto);

  drawCard(ctx, 12, 12, anchoTotal - 24, alto - 24, {
    radius: 30,
    fill: bgPage,
    stroke: '#edf1f5',
    lineWidth: 1,
    shadow: false
  });

  const headerX = margen;
  const headerY = 22;
  const headerW = anchoInterno;

  drawCard(ctx, headerX, headerY, headerW, headerH, {
    radius: 24,
    fill: card,
    stroke: border,
    lineWidth: 2
  });

  const logoX = margen + 18;
  const logoY = 60;
  let textX = margen + 24;

  try {
    const logo = await loadImage('../img/norte360_black.png');
    ctx.drawImage(logo, logoX, logoY, 48, 48);
    textX = logoX + 64;
  } catch (_) {}

  const operTxt = `DÍA OPERATIVO · ${fechaBaseTxt}`;
  const opW = measureTextWidth(ctx, operTxt, fOperativaChip) + 54;
  const opH = 34;
  const opX = (anchoTotal / 2) - (opW / 2);
  const opY = 28;

  drawCard(ctx, opX, opY, opW, opH, {
    radius: 17,
    fill: blueSoft,
    stroke: '#c8d9eb',
    lineWidth: 2,
    shadow: false
  });

  drawText(ctx, operTxt, opX + (opW / 2), opY + 7, {
    font: fOperativaChip,
    color: navy,
    align: 'center'
  });

  drawText(ctx, 'TABLA OPERATIVA', textX, 46, { font: fBrand, color: blue });
  drawText(ctx, 'Programación de horarios y buses', textX, 68, {
    font: fTitle,
    color: textMain
  });
  drawText(ctx, fitText(ctx, safeUpper(nombreGrupo), 480, fGrupo), textX, 102, {
    font: fGrupo,
    color: navy
  });

  drawText(ctx, `00:00–04:59 corresponde a fecha cronológica ${fechaSigTxt}`, textX, 142, {
    font: '12px "Segoe UI"',
    color: slate
  });

  const infoW = 186;
  const infoX = anchoTotal - margen - infoW;
  const infoY = 60;

  drawCard(ctx, infoX, infoY, infoW, 58, {
    radius: 16,
    fill: cardSoft,
    stroke: border,
    lineWidth: 1,
    shadow: false
  });

  drawText(ctx, 'FECHA IMPRESIÓN', infoX + 14, infoY + 10, { font: fMetaLabel, color: slate });
  drawText(ctx, impresion.fecha, infoX + 14, infoY + 25, { font: fMetaValue, color: textMain });
  drawText(ctx, 'HORA', infoX + 118, infoY + 10, { font: fMetaLabel, color: slate });
  drawText(ctx, impresion.hora, infoX + 118, infoY + 25, { font: fMetaValue, color: textMain });

  // SIN columna DIA OPERATIVO y SIN placa
  const columnas = [
    { key: 'hora',     label: 'HORA',    width: 110 },
    { key: 'bus',      label: 'UNIDAD',  width: 120 },
    { key: 'servicio', label: 'SERVICIO', width: 250 },
    { key: 'origen',   label: 'ORIGEN',   width: 210 },
    { key: 'destino',  label: 'DESTINO',  width: 210 }
  ];

  const tablaW = columnas.reduce((acc, c) => acc + c.width, 0);
  const tablaCardX = margen;
  const tablaCardY = headerY + headerH + 18;
  const tablaCardW = anchoInterno;
  const tablaX = tablaCardX + ((tablaCardW - tablaW) / 2);
  const tablaY = tablaCardY + 16;
  const tablaH = tableHeaderH + (filas.length * rowH);

  drawCard(ctx, tablaCardX, tablaCardY, tablaCardW, tablaH + 32, {
    radius: 22,
    fill: card,
    stroke: border,
    lineWidth: 2
  });

  drawCard(ctx, tablaX, tablaY, tablaW, tableHeaderH, {
    radius: 14,
    fill: navy,
    shadow: false
  });

  let xCursor = tablaX;
  columnas.forEach(col => {
    drawText(ctx, col.label, xCursor + 10, tablaY + 14, {
      font: fTableHead,
      color: white
    });

    xCursor += col.width;

    if (xCursor < tablaX + tablaW) {
      drawLine(ctx, xCursor, tablaY, xCursor, tablaY + tableHeaderH + (filas.length * rowH), '#c7d6e6', 1);
    }
  });

  let yRow = tablaY + tableHeaderH;

  filas.forEach((r, idx) => {
    const bg = idx % 2 === 0 ? '#ffffff' : '#f8fbfe';

    drawCard(ctx, tablaX, yRow, tablaW, rowH, {
      radius: 0,
      fill: bg,
      shadow: false
    });

    const horaVal = r.clm_progbuses_horasalida || r.hora_fmt || '';
    const hora = fmtHora(horaVal);
    const esSigDia = isNext(horaVal);

    // SOLO nombre del bus / unidad, sin placa
    const busUnidad = (() => {
      const bus = String(r.bus || '').trim();
      return bus || '—';
    })();

    let xCell = tablaX;

    // HORA
    drawText(
      ctx,
      fitText(ctx, hora || '—', columnas[0].width - 18, fCellStrong),
      xCell + 9,
      yRow + (esSigDia ? 8 : 18),
      { font: fCellStrong, color: textMain }
    );

    // indicador discreto si corresponde a otro día operativo
    if (esSigDia) {
      const badgeTxt = 'SIG. DÍA';
      const badgeW = measureTextWidth(ctx, badgeTxt, fBadge) + 18;

      drawCard(ctx, xCell + 9, yRow + 34, badgeW, 18, {
        radius: 9,
        fill: '#eef2f7',
        stroke: '#d7dee7',
        lineWidth: 1,
        shadow: false
      });

      drawText(ctx, badgeTxt, xCell + 18, yRow + 37, {
        font: fBadge,
        color: slate
      });
    }

    xCell += columnas[0].width;

    // UNIDAD
    drawText(
      ctx,
      fitText(ctx, String(busUnidad), columnas[1].width - 18, fBusCell),
      xCell + 9,
      yRow + 12,
      { font: fBusCell, color: blue }
    );

    xCell += columnas[1].width;

    // SERVICIO
    drawText(
      ctx,
      fitText(ctx, String(r.servicio_unidad || '—'), columnas[2].width - 18, fCell),
      xCell + 9,
      yRow + 18,
      { font: fCell, color: textMain }
    );

    xCell += columnas[2].width;

    // ORIGEN
    drawText(
      ctx,
      fitText(ctx, String(r.oficina_origen || '—'), columnas[3].width - 18, fCell),
      xCell + 9,
      yRow + 18,
      { font: fCell, color: textMain }
    );

    xCell += columnas[3].width;

    // DESTINO
    drawText(
      ctx,
      fitText(ctx, String(r.oficina_destino || '—'), columnas[4].width - 18, fCell),
      xCell + 9,
      yRow + 18,
      { font: fCell, color: textMain }
    );

    drawLine(ctx, tablaX, yRow + rowH, tablaX + tablaW, yRow + rowH, borderSoft, 1);
    yRow += rowH;
  });

  const fechaBaseArchivo = slugify((state.snapshot.fechas || {}).fecha_base || 'operativo');
  const nombreArchivo = `tabla_op_${slugify(nombreGrupo)}_${fechaBaseArchivo}.png`;

  const resultado = {
    canvas,
    nombreArchivo,
    nombreGrupo,
    formato: 'TABLA'
  };

  if (options.salida === 'canvas') {
    return resultado;
  }

  descargarCanvas(canvas, nombreArchivo);
  return resultado;
}







async function generarImagenPizarraGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller, options = {}) {
  await asegurarFuentePizarra();

  const grupos = {};

  filasGrupo.forEach(r => {
    const origen = safeUpper(r.oficina_origen || 'SIN ORIGEN');
    (grupos[origen] ||= []).push(r);
  });

  Object.keys(grupos).forEach(origen => {
    grupos[origen].sort((a, b) => cmpArr(sortKey(a), sortKey(b)));
  });

  const margen = 34;
  const panelDerechoW = 320;
  const cuerpoW = 1760;
  const anchoTotal = cuerpoW + panelDerechoW + (margen * 2);

  let alto = 60;
  Object.keys(grupos).forEach(origen => {
    const items = grupos[origen];
    const filasVisuales = items.length > 6 ? Math.max(1, Math.ceil(items.length / 2)) : items.length;
    alto += 90;
    alto += filasVisuales * 108;
    alto += 24;
  });

  const yPanelBase = 184;
  let altoPanelDerecho = 96;
  altoPanelDerecho += 22;
  altoPanelDerecho += busesSinHorario.length * 48;

  if (busesTaller.length) {
    altoPanelDerecho += 10;
    altoPanelDerecho += 22;
    altoPanelDerecho += busesTaller.length * 48;
  }

  alto = Math.max(alto, yPanelBase + altoPanelDerecho + 40);
  alto = Math.max(alto, 980);

  const { canvas, ctx } = createHiDPICanvas(anchoTotal, alto, 2);

  const bgPage = '#f6f8fb';
  const card = '#ffffff';
  const cardSoft = '#f8fbff';
  const border = '#d9e2ec';
  const borderSoft = '#e9eef5';
  const navy = '#1f3a5f';
  const blue = '#2f5d8a';
  const blueSoft = '#eaf2fb';
  const slate = '#64748b';
  const textMain = '#1f2937';
  const lineSoft = '#dce4ee';
  const lineEmpty = '#aab7c4';
  const wine = '#9b5c62';
  const wineSoft = '#fdf2f4';
  const white = '#ffffff';

const fBrand = fontPizarra(700, 18);
const fTitle = fontPizarra(700, 30);
const fGrupo = fontPizarra(900, 40);
const fOperativaChip = fontPizarra(900, 34);

const fSub = fontPizarra(400, 14);
const fMetaLabel = fontPizarra(700, 14);
const fMetaValue = fontPizarra(800, 24);

const fOrigen = fontPizarra(800, 27);
const fCount = fontPizarra(400, 12);

const fHora = fontPizarra(900, 34);
const fBus = fontPizarra(900, 40);
const fDest = fontPizarra(900, 25);

const fSmall = fontPizarra(400, 13);
const fComentario = fontPizarra(600, 15);

const fPanelHead = fontPizarra(800, 25);
const fPanelBus = fontPizarra(900, 26);
const fPanelSmall = fontPizarra(500, 14);

  const fechas = state.snapshot.fechas || {};
  const fechaBaseTxt = fechas.fecha_base || '—';
  const fechaSigTxt = fechas.fecha_sig || '—';
  const fechaSigCorta = fechas.fecha_sig_corta || 'SIG. DÍA';
  const impresion = formatDateParts(new Date());

  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, anchoTotal, alto);

  drawCard(ctx, 12, 12, anchoTotal - 24, alto - 24, {
    radius: 30,
    fill: bgPage,
    stroke: '#edf1f5',
    lineWidth: 1,
    shadow: false
  });

  const headerX = margen;
  const headerY = 22;
  const headerW = anchoTotal - (margen * 2);
  const headerH = 154;

  drawCard(ctx, headerX, headerY, headerW, headerH, {
    radius: 24,
    fill: card,
    stroke: border,
    lineWidth: 2
  });

  const logoX = margen + 18;
  const logoY = 50;
  let textX = margen + 26;

  try {
    const logo = await loadImage('../img/norte360_black.png');
    ctx.drawImage(logo, logoX, logoY, 52, 52);
    textX = logoX + 68;
  } catch (_) {}

  drawText(ctx, 'PIZARRA OPERATIVA', textX, 38, { font: fBrand, color: blue });
  drawText(ctx, 'Programación de horarios y buses', textX, 60, { font: fTitle, color: textMain });
  drawText(ctx, fitText(ctx, safeUpper(nombreGrupo), 780, fGrupo), textX, 92, { font: fGrupo, color: navy });

  const operTxt = `DÍA OPERATIVO · ${fechaBaseTxt}`;
  const opW = measureTextWidth(ctx, operTxt, fOperativaChip) + 72;
  const opH = 52;
  const opX = (anchoTotal / 2) - (opW / 2);
  const opY = 28;

  drawCard(ctx, opX, opY, opW, opH, {
    radius: 22,
    fill: blueSoft,
    stroke: '#c8d9eb',
    lineWidth: 2,
    shadow: false
  });

  drawText(ctx, operTxt, opX + (opW / 2), opY + 12, {
    font: fOperativaChip,
    color: navy,
    align: 'center'
  });

  drawText(ctx, `Madrugada 00:00–04:59 corresponde a ${fechaSigTxt}`, textX, 128, { font: fSub, color: slate });

  const infoW = 270;
  const infoH = 76;
  const infoX = anchoTotal - margen - infoW - 12;
  const infoY = 42;

  drawCard(ctx, infoX, infoY, infoW, infoH, {
    radius: 18,
    fill: cardSoft,
    stroke: border,
    lineWidth: 1,
    shadow: false
  });

  drawText(ctx, 'FECHA IMPRESIÓN', infoX + 18, infoY + 12, {
    font: fMetaLabel,
    color: slate
  });
  drawText(ctx, impresion.fecha, infoX + 18, infoY + 34, {
    font: fMetaValue,
    color: textMain
  });

  drawText(ctx, 'HORA', infoX + 170, infoY + 12, {
    font: fMetaLabel,
    color: slate
  });
  drawText(ctx, impresion.hora, infoX + 170, infoY + 34, {
    font: fMetaValue,
    color: textMain
  });

  const xPanel = margen + cuerpoW + 18;
  const yPanel = 184;
  const panelW = panelDerechoW - 10;
  const panelH = alto - 36 - yPanel;

  drawCard(ctx, xPanel, yPanel, panelW, panelH, {
    radius: 24,
    fill: card,
    stroke: border,
    lineWidth: 2
  });

  drawCard(ctx, xPanel, yPanel, panelW, 82, {
    radius: 24,
    fill: navy,
    shadow: false
  });

drawText(ctx, 'ESTADO DE UNIDADES', xPanel + 24, yPanel + 18, {
  font: fPanelHead,
  color: white
});
drawText(ctx, 'En taller y sin horario', xPanel + 24, yPanel + 48, {
  font: fPanelSmall,
  color: '#dbe7f2'
});

let ySide = yPanel + 104;

// PRIMERO: EN TALLER
if (busesTaller.length) {
  drawText(ctx, 'EN TALLER', xPanel + 22, ySide, {
    font: fMetaLabel,
    color: wine
  });
  ySide += 22;

  busesTaller.forEach(b => {
    const bus = safeUpper(b.bus || '—');
    drawCard(ctx, xPanel + 16, ySide, panelW - 32, 46, {
      radius: 10,
      fill: wineSoft,
      stroke: '#f1d9de',
      lineWidth: 1,
      shadow: false
    });
    drawText(
      ctx,
      fitText(ctx, bus, panelW - 64, fPanelBus),
      xPanel + 28,
      ySide + 9,
      { font: fPanelBus, color: wine }
    );
    ySide += 54;
  });

  ySide += 10;
}

// DESPUÉS: SIN HORARIO
drawText(ctx, 'SIN HORARIO', xPanel + 22, ySide, {
  font: fMetaLabel,
  color: slate
});
ySide += 22;

busesSinHorario.forEach(b => {
  const bus = safeUpper(b.bus || '—');
  drawCard(ctx, xPanel + 16, ySide, panelW - 32, 42, {
    radius: 10,
    fill: blueSoft,
    stroke: borderSoft,
    lineWidth: 1,
    shadow: false
  });
  drawText(
    ctx,
    fitText(ctx, bus, panelW - 64, fPanelBus),
    xPanel + 28,
    ySide + 8,
    { font: fPanelBus, color: blue }
  );
  ySide += 48;
});

  const x0 = margen;
  let yCursor = 184;
  const cuerpoUtilW = cuerpoW - 24;
  const gapBloquesY = 20;
  const bloquePadX = 18;
  const bloquePadY = 16;
  const tituloH = 46;
  const filaH = 104;
  const bloqueW = cuerpoUtilW;
  const maxFilasPorSubcol = 6;
  const subGap = 28;

  Object.keys(grupos).sort((a, b) => {
  const ordenA = Math.min(
    ...grupos[a].map(r => {
      const n = Number(r.orden_pizarra_origen);
      return Number.isFinite(n) && n > 0 ? n : 999999;
    })
  );

  const ordenB = Math.min(
    ...grupos[b].map(r => {
      const n = Number(r.orden_pizarra_origen);
      return Number.isFinite(n) && n > 0 ? n : 999999;
    })
  );

  if (ordenA !== ordenB) return ordenA - ordenB;

  return compareTextNatural(a, b);
}).forEach(origen => {
    const items = grupos[origen];
    const usarDosCols = items.length > maxFilasPorSubcol;
    const subcols = usarDosCols ? 2 : 1;
    const subcolW = Math.floor((bloqueW - (bloquePadX * 2) - (subcols === 2 ? subGap : 0)) / subcols);

    const itemsCols = subcols === 2
      ? [items.slice(0, Math.ceil(items.length / 2)), items.slice(Math.ceil(items.length / 2))]
      : [items];

    const filasMax = Math.max(...itemsCols.map(col => col.length));
    const blockH = bloquePadY + tituloH + (filasMax * filaH) + 20;

    drawCard(ctx, x0, yCursor, bloqueW, blockH, {
      radius: 22,
      fill: card,
      stroke: border,
      lineWidth: 2
    });

    drawCard(ctx, x0, yCursor, bloqueW, 56, {
      radius: 22,
      fill: cardSoft,
      shadow: false
    });

    drawText(ctx, fitText(ctx, origen, bloqueW - 220, fOrigen), x0 + bloquePadX, yCursor + 14, { font: fOrigen, color: navy });

    const cantTxt = `${items.length} horarios`;
    const pillW = measureTextWidth(ctx, cantTxt, fCount) + 28;
    const pillX = x0 + bloqueW - pillW - 18;

    drawCard(ctx, pillX, yCursor + 16, pillW, 22, {
      radius: 11,
      fill: '#f4f8fc',
      stroke: '#b9cadb',
      lineWidth: 2,
      shadow: false
    });
    drawText(ctx, cantTxt, pillX + 14, yCursor + 21, { font: fCount, color: slate });

    drawLine(ctx, x0 + 16, yCursor + 56, x0 + bloqueW - 16, yCursor + 56, borderSoft, 1);

    const contenidoY = yCursor + 68;

    itemsCols.forEach((subItems, idxSubcol) => {
      const subX = x0 + bloquePadX + idxSubcol * (subcolW + subGap);

      if (subcols === 2 && idxSubcol === 1) {
        const lineaX = subX - (subGap / 2);
        drawLine(ctx, lineaX, contenidoY - 6, lineaX, yCursor + blockH - 16, lineSoft, 2);
      }

      let filaY = contenidoY;

      subItems.forEach(r => {
        const horaVal = r.clm_progbuses_horasalida || r.hora_fmt || '';
        const hora = fmtHora(horaVal);
        const esSigDia = isNext(horaVal);
        const bus = safeUpper(r.bus || '');
        const destino = safeUpper(r.oficina_destino || '');
        const servicio = safeUpper(r.servicio_unidad || '');
        const destinoServicio = destino
          ? `${destino}${servicio ? ' | ' + servicio : ''}`
          : (servicio || '');
        const comentario = String(r.clm_progbuses_comentario || '').trim();

        drawCard(ctx, subX - 6, filaY - 2, subcolW - 2, 84, {
          radius: 12,
          fill: '#fbfdff',
          shadow: false
        });

        drawText(ctx, hora || '—', subX, filaY + 7, { font: fHora, color: textMain });

        if (esSigDia) {
          drawText(ctx, fechaSigCorta, subX + 3, filaY + 38, { font: fSmall, color: slate });
        }

        const xHora = subX;
        const xBus = subX + 132;
        const xDestino = subX + 315;

        if (bus) {
            drawText(
              ctx,
              fitText(ctx, bus, 170, fBus),
              xBus,
              filaY + 3,
              { font: fBus, color: blue }
            );
        } else {
          drawLine(ctx, xBus, filaY + 27, xBus + 130, filaY + 27, lineEmpty, 3);
        }

        if (destinoServicio) {
          const colorDest = destino.includes('TALLER') ? wine : slate;

          drawText(
            ctx,
            fitText(ctx, destinoServicio, subcolW - 335, fDest),
            xDestino,
            filaY + 9,
            { font: fDest, color: colorDest }
          );
        }

        if (comentario) {
          drawText(
            ctx,
            fitText(ctx, `Comentario: ${comentario}`, subcolW - 335, fComentario),
            xDestino,
            filaY + 42,
            { font: fComentario, color: '#8a5a00' }
          );
        }

        filaY += filaH;
      });
    });

    yCursor += blockH + gapBloquesY;
  });

  const fechaBaseArchivo = slugify((state.snapshot.fechas || {}).fecha_base || 'operativo');
  const nombreArchivo = `pizarra_op_${slugify(nombreGrupo)}_${fechaBaseArchivo}.png`;

  const resultado = {
    canvas,
    nombreArchivo,
    nombreGrupo,
    formato: 'PIZARRA'
  };

  if (options.salida === 'canvas') {
    return resultado;
  }

  descargarCanvas(canvas, nombreArchivo);
  return resultado;
}


  function updateAll(){ renderDates(); renderSummary(); renderBoard(); 
  renderSideList(
    els.sideTaller,
    state.snapshot.buses_taller || [],
    'No hay buses marcados en taller.',
    true,
    'taller'
  );


  renderSideList(
    els.sideSinHorario,
    state.snapshot.buses_sin_horario || [],
    'Todos los buses activos ya tienen horario.',
    false,
    'sin_horario'
  );
  
  
  els.sideTallerCount.textContent=(state.snapshot.buses_taller||[]).length; els.sideSinHorarioCount.textContent=(state.snapshot.buses_sin_horario||[]).length; }
async function fetchJson(action, options = {}) {
  if (state.isBusy && options.skipBusy !== true) {
    throw new Error('Ya hay una operación en proceso. Espera un momento.');
  }

  const url = `${window.location.pathname}?ajax=${encodeURIComponent(action)}${options.query ? `&${options.query}` : ''}`;

  if (options.useLoader !== false) {
    horarioBeginLoading();
  }

  try {
    const resp = await fetch(url, {
      method: options.method || 'GET',
      body: options.body || null,
      credentials: 'same-origin'
    });

    const data = await resp.json();

    if (!data.ok) {
      throw new Error(data.message || 'No se pudo completar la acción.');
    }

    return data.data || {};
  } finally {
    if (options.useLoader !== false) {
      horarioEndLoading();
    }
  }
}
  async function confirmarLimpiarPizarra() {
    if (!els.btnConfirmLimpiarPizarra) return;

    const fd = new FormData();
    const data = await fetchJson('limpiar_pizarra', {
      method: 'POST',
      body: fd
    });

    if (!data.url) {
      throw new Error('No se recibio la URL de cierre operativo.');
    }

    modalLimpiarPizarra?.hide();
    showAlert('warning', 'Limpieza de pizarra lanzada. Revisa el resultado del cierre operativo.');
    const opened = window.open(data.url, '_blank', 'noopener');
    if (!opened) {
      window.location.href = data.url;
    }
  }

  async function refreshSnapshot(showOk=false){ const data=await fetchJson('snapshot'); state.snapshot=data; updateAll(); if(showOk) showAlert('success','Programación actualizada correctamente.'); }
  function populateOfficeSelects(){ const origenes=state.snapshot.oficinas_origen||[]; const destinos=state.snapshot.oficinas_destino||[]; els.crearOrigen.innerHTML=`<option value="">Selecciona origen</option>`+origenes.map(o=>`<option value="${o.clm_sedes_id}">${esc(o.oficina||`Sede ${o.clm_sedes_id}`)}</option>`).join(''); els.crearDestino.innerHTML=`<option value="">Selecciona destino</option>`+destinos.map(o=>`<option value="${o.clm_sedes_id}">${esc(o.oficina||`Sede ${o.clm_sedes_id}`)}</option>`).join(''); }
  function updateCreatePreview(){
    const origenTxt = els.crearOrigen.options[els.crearOrigen.selectedIndex]?.text || 'Origen';
    const destinoTxt = els.crearDestino.options[els.crearDestino.selectedIndex]?.text || 'Destino';
    const hora = els.crearHora.value || '16:00';
    const rutaIds = getRutaSeleccionada(els.crearRutaList);
    const rutaTxt = rutaIds.length ? ` | Ruta: ${rutaIdsToTexto(rutaIds)}` : '';

    els.previewNuevoHorario.textContent = `${hora} | ${origenTxt} → ${destinoTxt}${rutaTxt}`;
  }

  function fillQuickTimes(input, wrap, onChange){ wrap.innerHTML=quickTimes.map(h=>`<button type="button" class="btn btn-outline-secondary btn-sm" data-time="${h}">${h}</button>`).join(''); wrap.querySelectorAll('[data-time]').forEach(btn=>btn.addEventListener('click',()=>{ input.value=btn.dataset.time; onChange&&onChange(); })); }
  function openCreateModal(){
    populateOfficeSelects();

    els.crearHora.value = '16:00';
    els.crearOrigen.value = '';
    els.crearDestino.value = '';
    if (els.crearComentario) els.crearComentario.value = '';

    renderRutaSelector({
      container: els.crearRutaList,
      preview: els.crearRutaPreview,
      origenId: '',
      destinoId: '',
      selectedIds: []
    });

    updateCreatePreview();
    modalCreate.show();
  }
async function saveNewHorario(){
  if (state.isBusy) return;

  if(!els.crearOrigen.value||!els.crearDestino.value){
    showAlert('warning','Selecciona origen y destino.');
    return;
  }
  if(els.crearOrigen.value===els.crearDestino.value){
    showAlert('warning','Origen y destino no pueden ser iguales.');
    return;
  }
  if(!els.crearHora.value){
    showAlert('warning','Selecciona una hora de salida.');
    return;
  }

  const rutaIds = getRutaSeleccionada(els.crearRutaList);

  const fd=new FormData();
  fd.append('idof_origen',els.crearOrigen.value);
  fd.append('idof_destino',els.crearDestino.value);
  fd.append('horasalida',els.crearHora.value);
  fd.append('ruta', rutaIds.join(','));
  fd.append('comentario', (els.crearComentario?.value || '').trim());

  const data=await fetchJson('create_horario',{method:'POST',body:fd});
  state.snapshot=data;
  updateAll();
  modalCreate.hide();
  showAlert('success','Horario creado correctamente.');
}
  function openEditTimeModal(row){
    if(Number(row.clm_progbuses_estado)!==1){
      showAlert('warning','Solo puedes editar horarios activos.');
      return;
    }

    state.editRow = row;

    const hora = fmtHora(row.clm_progbuses_horasalida || row.hora_fmt) || '16:00';
    els.editarHoraInput.value = hora;

    const destinos = state.snapshot.oficinas_destino || [];

    els.editarDestino.innerHTML = `<option value="">Selecciona destino</option>` + destinos.map(o => {
      const selected = String(o.clm_sedes_id) === String(row.clm_progbuses_idoficina_destino) ? 'selected' : '';
      return `<option value="${o.clm_sedes_id}" ${selected}>${esc(o.oficina || `Sede ${o.clm_sedes_id}`)}</option>`;
    }).join('');

    renderRutaSelector({
      container: els.editarRutaList,
      preview: els.editarRutaPreview,
      origenId: row.clm_progbuses_idoficina_origen,
      destinoId: row.clm_progbuses_idoficina_destino,
      selectedIds: rutaToArray(row.clm_progbuses_ruta || '')
    });

    els.editarHoraSubtitulo.textContent = `Horario #${row.clm_progbuses_progid} | ${row.oficina_origen || '—'} → ${row.oficina_destino || '—'}`;

    updateEditPreview();
    modalEdit.show();
  }
  function updateEditPreview(){
    if(!state.editRow) return;

    const horaOriginal = fmtHora(state.editRow.clm_progbuses_horasalida || state.editRow.hora_fmt);
    const horaNueva = els.editarHoraInput.value || horaOriginal;

    const destinoOriginal = state.editRow.oficina_destino || '—';
    const destinoNuevo = els.editarDestino.options[els.editarDestino.selectedIndex]?.text || destinoOriginal;

    const rutaIds = getRutaSeleccionada(els.editarRutaList);
    const rutaTxt = rutaIds.length ? ` | Ruta: ${rutaIdsToTexto(rutaIds)}` : '';

    els.previewEditarHora.textContent =
      `${horaOriginal} → ${horaNueva} | ${state.editRow.oficina_origen || '—'} → ${destinoNuevo}${rutaTxt}`;
  }

async function saveEditedHora(){
  if (state.isBusy) return;
  if(!state.editRow) return;

  if(!els.editarHoraInput.value){
    showAlert('warning','Selecciona la nueva hora.');
    return;
  }

  if(!els.editarDestino.value){
    showAlert('warning','Selecciona el nuevo destino.');
    return;
  }

  if(String(els.editarDestino.value) === String(state.editRow.clm_progbuses_idoficina_origen)){
    showAlert('warning','El destino no puede ser igual al origen.');
    return;
  }

  const rutaIds = getRutaSeleccionada(els.editarRutaList);

  const fd = new FormData();
  fd.append('progid', state.editRow.clm_progbuses_progid);
  fd.append('horasalida', els.editarHoraInput.value);
  fd.append('idof_destino', els.editarDestino.value);
  fd.append('ruta', rutaIds.join(','));

  const data = await fetchJson('editar_hora', {method:'POST', body:fd});

  state.snapshot = data;
  updateAll();
  modalEdit.hide();
  state.editRow = null;
  showAlert('success','Horario actualizado correctamente.');
}

  function getMotivoConfig(config){ const accion=config.accion||'CAMBIO'; 
    if (accion === 'CAMBIO') {
      return {
        titulo: config.titulo || 'Motivo del cambio de bus',
        options: [
          {
            key: 'NORMAL',
            label: 'Cambio normal del día',
            preview: 'Cambio de unidad por programación normal del día'
          },
          ...(config.permitirTaller === false ? [] : [
            {
              key: 'TALLER',
              label: 'TALLER',
              preview: 'Cambio de unidad por envío a taller'
            }
          ]),
          {
            key: 'TRANSBORDO',
            label: 'TRANSBORDO',
            preview: 'Transbordo operativo: unidad de reemplazo asignada para continuar el viaje hasta su destino'
          },
          {
            key: 'OTRO',
            label: 'OTRO MOTIVO',
            preview: 'Cambio de unidad por: '
          }
        ],
        build: (sel, libre) =>
          sel === 'NORMAL'
            ? 'Cambio de unidad por programación normal del día'
            : sel === 'TALLER'
              ? 'Cambio de unidad por envío a taller'
              : sel === 'TRANSBORDO'
                ? 'Transbordo operativo: unidad de reemplazo asignada por incidente en ruta para continuar el viaje hasta su destino'
                : `Cambio de unidad por: ${libre}`
      };
    }

    if(accion==='RETIRO') return { titulo:config.titulo||'Motivo del retiro de la unidad', options:[{key:'NORMAL',label:'Retiro normal del día',preview:'Retiro de unidad por programación normal del día'}, ...(config.permitirTaller===false?[]:[{key:'TALLER',label:'TALLER',preview:'TALLER'}]), {key:'OTRO',label:'OTRO MOTIVO',preview:'Retiro de unidad por: '}], build:(sel,libre)=>sel==='NORMAL'?'Retiro de unidad por programación normal del día':sel==='TALLER'?'TALLER':`Retiro de unidad por: ${libre}`}; 
    
    if(accion==='INACTIVAR') return { titulo:config.titulo||'Motivo de inhabilitación del horario', options:[{key:'NORMAL',label:'Inactivación operativa',preview:'Horario inactivado por decisión operativa'},{key:'OTRO',label:'OTRO MOTIVO',preview:'Horario inactivado por: '}], build:(sel,libre)=>sel==='NORMAL'?'Horario inactivado por decisión operativa':`Horario inactivado por: ${libre}`}; 
    
    if(accion==='ACTIVAR') return { titulo:config.titulo||'Motivo de activación del horario', options:[{key:'NORMAL',label:'Reactivación operativa',preview:'Horario reactivado para programación'},{key:'OTRO',label:'OTRO MOTIVO',preview:'Horario reactivado por: '}], build:(sel,libre)=>sel==='NORMAL'?'Horario reactivado para programación':`Horario reactivado por: ${libre}`}; return config; }

  function renderMotivoOptions(){ const conf=state.pendingMotivoConfig; if(!conf) return; els.motivoOptions.innerHTML=conf.options.map(opt=>`<div class="col-md-${conf.options.length>=3?'4':'6'}"><div class="motivo-option ${conf.selected===opt.key?'active':''}" data-key="${opt.key}"><strong>${esc(opt.label)}</strong><small>${esc(opt.preview)}</small></div></div>`).join(''); els.motivoOptions.querySelectorAll('.motivo-option').forEach(el=>el.addEventListener('click',()=>{ conf.selected=el.dataset.key; renderMotivoOptions(); })); const opt=conf.options.find(o=>o.key===conf.selected)||conf.options[0]; els.motivoPreview.textContent=opt.preview; els.motivoLibre.disabled=conf.selected!=='OTRO'; if(conf.selected!=='OTRO') els.motivoLibre.value=''; }
  function askMotivo(config){ const conf=getMotivoConfig(config||{}); state.pendingMotivoConfig={...conf,selected:'NORMAL'}; els.motivoTitulo.textContent=conf.titulo; els.motivoLibre.value=''; renderMotivoOptions(); modalMotivo.show(); return new Promise(resolve=>{ state.pendingMotivoResolver=resolve; }); }
  function resolveMotivo(value){ const resolver=state.pendingMotivoResolver; state.pendingMotivoResolver=null; state.pendingMotivoConfig=null; if(typeof resolver==='function') resolver(value); }
  async function acceptMotivo(){ const conf=state.pendingMotivoConfig; if(!conf) return; const libre=(els.motivoLibre.value||'').trim(); if(conf.selected==='OTRO'&&!libre){ showAlert('warning','Escribe el motivo libre.'); return; } modalMotivo.hide(); resolveMotivo(conf.build(conf.selected, libre)); }
  $('modalMotivo').addEventListener('hidden.bs.modal',()=>{ if(state.pendingMotivoResolver) resolveMotivo(null); });
  async function loadBusList() {
  const data = await fetchJson('buses_disponibles');
  state.busList = (data.buses || []).slice().sort(compareBusNatural);
  renderBusList();
}

function renderBusList() {
  const q = (els.busSearch.value || '').trim().toLowerCase();

  const rows = (state.busList || [])
    .filter(b => !q || [b.bus, b.placa, b.tipo_vehiculo].join(' | ').toLowerCase().includes(q))
    .slice()
    .sort(compareBusNatural);

  if (!rows.length) {
    els.busList.innerHTML = `<div class="empty-state">No se encontraron buses disponibles.</div>`;
    els.busSelectedLabel.textContent = 'Seleccionado: ninguno';
    return;
  }

  els.busList.innerHTML = rows.map(b => {
    let bdg = badge('SIN_HORARIO', 'warning');
    if (b.estado_programacion === 'TALLER') bdg = badge('TALLER', 'danger');
    if (Number(b.cantidad_asignaciones) > 0) bdg = badge('ASIGNADO', 'success');

    const active = state.busSelection && String(state.busSelection.clm_placas_id) === String(b.clm_placas_id);

    return `
      <div class="bus-card ${active ? 'active' : ''}" data-id="${b.clm_placas_id}">
        <div class="bus-card__top">
          <div>
            <div class="bus-card__title">${esc(b.bus || 'SIN NOMBRE')}</div>
            <div class="bus-card__meta">Placa: ${esc(b.placa || '—')} · Tipo: ${esc(b.tipo_vehiculo || '—')}</div>
          </div>
          ${bdg}
        </div>
        <div class="bus-card__meta">
          Asignaciones activas: ${esc(b.cantidad_asignaciones || 0)}${b.motivo ? ` · Motivo: ${esc(b.motivo)}` : ''}
        </div>
      </div>
    `;
  }).join('');

  els.busList.querySelectorAll('.bus-card').forEach(card => {
    card.addEventListener('click', () => {
      state.busSelection = rows.find(r => String(r.clm_placas_id) === String(card.dataset.id)) || null;
      els.busSelectedLabel.textContent = state.busSelection
        ? `Seleccionado: ${state.busSelection.bus || 'SIN NOMBRE'} | ${state.busSelection.placa || '—'}`
        : 'Seleccionado: ninguno';
      renderBusList();
    });
  });

  els.busSelectedLabel.textContent = state.busSelection
    ? `Seleccionado: ${state.busSelection.bus || 'SIN NOMBRE'} | ${state.busSelection.placa || '—'}`
    : 'Seleccionado: ninguno';
}
  
  async function openBusModal(row,mode){ state.currentRow=row; state.busMode=mode; state.busSelection=null; els.busSearch.value=''; els.modalBusTitle.textContent=mode==='asignar'?'Asignar bus al horario':'Cambiar bus del horario'; els.modalBusSubtitle.textContent=`${fmtHora(row.clm_progbuses_horasalida)} | ${row.oficina_origen||'—'} → ${row.oficina_destino||'—'}`; modalBus.show(); await loadBusList(); }
function horaToMinutosOperativos(hora) {
  const h = fmtHora(hora);
  if (!h || !h.includes(':')) return null;

  const [hhRaw, mmRaw] = h.split(':');
  const hh = parseInt(hhRaw, 10);
  const mm = parseInt(mmRaw, 10);

  if (Number.isNaN(hh) || Number.isNaN(mm)) return null;

  // Regla operativa:
  // 00:00 a 04:59 pertenecen al siguiente día operativo
  let total = (hh * 60) + mm;
  if (hh >= 0 && hh <= 4) {
    total += 24 * 60;
  }

  return total;
}

function evaluarAlertaDescanso8Horas(idplacaNueva, rowDestino) {
  const nuevaHoraMin = horaToMinutosOperativos(
    rowDestino.clm_progbuses_horasalida || rowDestino.hora_fmt
  );

  if (nuevaHoraMin === null) return null;

  const horarios = Array.isArray(state.snapshot.horarios)
    ? state.snapshot.horarios
    : [];

  const conflictos = horarios
    .filter(r => {
      if (String(r.clm_progbuses_idplaca || '') !== String(idplacaNueva || '')) return false;
      if (String(r.clm_progbuses_progid || '') === String(rowDestino.clm_progbuses_progid || '')) return false;
      if (Number(r.clm_progbuses_estado || 0) !== 1) return false;
      return true;
    })
    .map(r => {
      const horaRefMin = horaToMinutosOperativos(r.clm_progbuses_horasalida || r.hora_fmt);
      if (horaRefMin === null) return null;

      const diferenciaMin = Math.abs(nuevaHoraMin - horaRefMin);
      const diferenciaHoras = diferenciaMin / 60;

      return {
        row: r,
        diferenciaMin,
        diferenciaHoras
      };
    })
    .filter(x => x && x.diferenciaMin < (8 * 60))
    .sort((a, b) => a.diferenciaMin - b.diferenciaMin);

  if (!conflictos.length) return null;

  const c = conflictos[0];
  const horas = Math.floor(c.diferenciaMin / 60);
  const minutos = c.diferenciaMin % 60;

  return {
    horarioRelacionado: c.row,
    diferenciaMin: c.diferenciaMin,
    diferenciaTexto: `${horas}h ${String(minutos).padStart(2, '0')}min`
  };
}

function confirmarDescanso8Horas(alerta, busSeleccionado, rowDestino) {
  return new Promise(resolve => {
    const modalId = 'modalAlertaDescanso8Horas';
    const anterior = document.getElementById(modalId);
    if (anterior) anterior.remove();

    const rel = alerta.horarioRelacionado || {};
    const horaActual = fmtHora(rowDestino.clm_progbuses_horasalida || rowDestino.hora_fmt);
    const horaRelacionada = fmtHora(rel.clm_progbuses_horasalida || rel.hora_fmt);

    const html = `
      <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content" style="border:0;border-radius:22px;overflow:hidden;">
            
            <div class="modal-header" style="background:#b45309;color:white;border:0;">
              <div>
                <h5 class="modal-title mb-1">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i>
                  Alerta de intervalo operativo
                </h5>
                <div class="small text-white-50">
                  Validación preventiva antes de programar la unidad.
                </div>
              </div>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" style="background:#fffaf0;">
              <div class="alert mb-3" style="background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:16px;">
                <div class="fw-bold mb-1">La unidad está por debajo del intervalo mínimo de 8 horas.</div>
                <div>
                  El sistema detectó que el vehículo tiene una programación cercana y aún no cumple el tiempo operativo recomendado.
                </div>
              </div>

              <div class="p-3 rounded-4 bg-white border mb-3">
                <div class="fw-bold text-dark mb-2">
                  ${esc(busSeleccionado.bus || 'UNIDAD')} · ${esc(busSeleccionado.placa || '—')}
                </div>

                <div class="small text-secondary">Horario relacionado</div>
                <div class="fw-bold text-dark mb-2">
                  ${esc(horaRelacionada || '—')} | ${esc(rel.oficina_origen || '—')} → ${esc(rel.oficina_destino || '—')}
                </div>

                <div class="small text-secondary">Nuevo horario a asignar</div>
                <div class="fw-bold text-dark mb-2">
                  ${esc(horaActual || '—')} | ${esc(rowDestino.oficina_origen || '—')} → ${esc(rowDestino.oficina_destino || '—')}
                </div>

                <div class="mt-2">
                  <span class="badge rounded-pill text-bg-warning">
                    Diferencia detectada: ${esc(alerta.diferenciaTexto)}
                  </span>
                </div>
              </div>

              <div class="p-3 rounded-4 border" style="background:#ffffff;">
                <label class="form-label fw-bold text-dark mb-2">
                  Contraseña de autorización
                </label>
                <input 
                  type="password" 
                  class="form-control" 
                  id="inputPasswordDescanso8h"
                  placeholder="Ingresa la contraseña para autorizar"
                  autocomplete="off"
                >
                <div class="small text-secondary mt-2">
                  Esta validación confirma que el movimiento fue revisado y autorizado.
                </div>
                <div class="small fw-bold text-danger mt-2 d-none" id="errorPasswordDescanso8h">
                  Contraseña incorrecta. No se realizará el movimiento.
                </div>
              </div>
            </div>

            <div class="modal-footer bg-white">
              <button type="button" class="btn btn-light" id="btnCancelarDescanso8h">
                Cancelar
              </button>
              <button type="button" class="btn btn-warning text-dark fw-bold" id="btnConfirmarDescanso8h">
                Sí, realizar movimiento
              </button>
            </div>

          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML('beforeend', html);

    const el = document.getElementById(modalId);
    const modal = new bootstrap.Modal(el, {
      backdrop: 'static',
      keyboard: false
    });

    const inputPass = document.getElementById('inputPasswordDescanso8h');
    const errorPass = document.getElementById('errorPasswordDescanso8h');

    const cerrar = (valor) => {
      resolve(valor);
      modal.hide();
    };

    document.getElementById('btnCancelarDescanso8h').addEventListener('click', () => {
      cerrar(false);
    });

    document.getElementById('btnConfirmarDescanso8h').addEventListener('click', () => {
      const pass = String(inputPass.value || '').trim();

      if (pass !== 'phcdn26') {
        errorPass.classList.remove('d-none');
        inputPass.classList.add('is-invalid');
        inputPass.focus();
        return;
      }

      cerrar(true);
    });

    inputPass.addEventListener('input', () => {
      errorPass.classList.add('d-none');
      inputPass.classList.remove('is-invalid');
    });

    inputPass.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        document.getElementById('btnConfirmarDescanso8h').click();
      }
    });

    el.addEventListener('shown.bs.modal', () => {
      inputPass.focus();
    });

    el.addEventListener('hidden.bs.modal', () => {
      el.remove();
    }, { once: true });

    modal.show();
  });
}

async function confirmBusSelection() {
  if (!state.currentRow || !state.busSelection) {
    showAlert('warning', 'Selecciona un bus disponible.');
    return;
  }

  if (
    state.busMode === 'cambiar' &&
    String(state.currentRow.clm_progbuses_idplaca || '') === String(state.busSelection.clm_placas_id || '')
  ) {
    showAlert('warning', 'Has seleccionado la misma unidad que ya tiene el horario.');
    return;
  }

  const alerta8h = evaluarAlertaDescanso8Horas(
    state.busSelection.clm_placas_id,
    state.currentRow
  );

  if (alerta8h) {
    modalBus.hide();
    await waitModalHidden($('modalBus'));

    const continuar = await confirmarDescanso8Horas(
      alerta8h,
      state.busSelection,
      state.currentRow
    );

    if (!continuar) {
      modalBus.show();
      setTimeout(() => renderBusList(), 150);
      return;
    }
  }

  if (state.busMode === 'asignar') {
    await performAction(
      'asignar_bus',
      {
        progid: state.currentRow.clm_progbuses_progid,
        idplaca: state.busSelection.clm_placas_id
      },
      'Bus asignado correctamente.'
    );

    modalBus.hide();
    return;
  }

  modalBus.hide();
  await waitModalHidden($('modalBus'));

  const motivo = await askMotivo({
    accion: 'CAMBIO',
    titulo: 'Motivo del cambio de bus',
    permitirTaller: true
  });

  if (!motivo) {
    modalBus.show();
    setTimeout(() => renderBusList(), 150);
    return;
  }

  await performAction(
    'cambiar_bus',
    {
      progid: state.currentRow.clm_progbuses_progid,
      idplaca: state.busSelection.clm_placas_id,
      motivo
    },
    'Bus cambiado correctamente.'
  );
}


  async function performAction(action,payload,successMessage=''){
  if (state.isBusy) return;

  const fd=new FormData();
  Object.entries(payload||{}).forEach(([k,v])=>fd.append(k,v));

  const data=await fetchJson(action,{method:'POST',body:fd});
  state.snapshot=data;
  updateAll();

  if(successMessage) showAlert('success',successMessage);
}
function historialTextoBuscable(r) {
  return [
    r.accion,
    r.fechaevento,
    r.progid,
    r.usuario_realizo,
    r.clm_progbuses_idusuario,
    fmtHora(r.clm_progbuses_horasalida || r.hora_fmt),
    r.oficina_origen,
    r.oficina_destino,
    rutaIdsToTexto(r.clm_progbuses_ruta || ''),
    r.clm_progbuses_ruta,
    r.bus,
    r.placa,
    r.motivo
  ].join(' | ').toLowerCase();
}

function renderHistorial() {
  const q = (els.historialSearch?.value || '').trim().toLowerCase();
  const rowsBase = Array.isArray(state.historialRows) ? state.historialRows : [];

  let rows = rowsBase;

  if (state.historialSoloTransbordo) {
    rows = rows.filter(r => {
      const motivo = String(r.motivo || '').toLowerCase();
      return motivo.includes('transbordo');
    });
  }

  if (q) {
    rows = rows.filter(r => historialTextoBuscable(r).includes(q));
  }

  if (els.btnFiltroTransbordoHistorial) {
    els.btnFiltroTransbordoHistorial.classList.toggle('btn-warning', state.historialSoloTransbordo);
    els.btnFiltroTransbordoHistorial.classList.toggle('btn-outline-warning', !state.historialSoloTransbordo);
    els.btnFiltroTransbordoHistorial.innerHTML = state.historialSoloTransbordo
      ? `<i class="bi bi-check-circle me-1"></i> Transbordos activos`
      : `<i class="bi bi-arrow-left-right me-1"></i> Solo transbordos`;
  }

  if (els.btnLimpiarFiltroHistorial) {
    const hayFiltros = !!q || state.historialSoloTransbordo;
    els.btnLimpiarFiltroHistorial.classList.toggle('d-none', !hayFiltros);
  }

  if (els.historialSearchInfo) {
    let textoFiltro = 'Mostrando historial cargado.';

    if (state.historialSoloTransbordo && q) {
      textoFiltro = `Mostrando ${rows.length} de ${rowsBase.length} movimiento(s) cargado(s), filtrado por transbordo y texto.`;
    } else if (state.historialSoloTransbordo) {
      textoFiltro = `Mostrando ${rows.length} transbordo(s) de ${rowsBase.length} movimiento(s) cargado(s).`;
    } else if (q) {
      textoFiltro = `Mostrando ${rows.length} de ${rowsBase.length} movimiento(s) cargado(s).`;
    } else {
      textoFiltro = `Mostrando ${rowsBase.length} movimiento(s) cargado(s). El filtro no realiza consultas adicionales.`;
    }

    els.historialSearchInfo.textContent = textoFiltro;
  }

  if (!rowsBase.length) {
    els.historialContainer.innerHTML = `<div class="empty-state">Sin historial disponible.</div>`;
    return;
  }

  if (!rows.length) {
    els.historialContainer.innerHTML = `<div class="empty-state">No se encontraron movimientos con los filtros aplicados.</div>`;
    return;
  }

  
els.historialContainer.innerHTML = rows.map(r => {
  let bdg = badge(r.accion || '—', 'warning');

  if ((r.accion || '').toUpperCase() === 'INSERT') {
    bdg = badge('INSERT', 'success');
  }

  if ((r.accion || '').toUpperCase() === 'DELETE') {
    bdg = badge('DELETE', 'danger');
  }

  const usuario = r.usuario_realizo || `Usuario #${r.clm_progbuses_idusuario || '—'}`;
  const motivo = String(r.motivo || '');
  const esTransbordo = motivo.toLowerCase().includes('transbordo');

  const rutaRaw = String(r.clm_progbuses_ruta || '').trim();
  const tieneRuta = rutaRaw !== '';
  const rutaTexto = tieneRuta ? rutaIdsToTexto(rutaRaw) : '';

  const esCambioRuta = motivo.toLowerCase().includes('ruta');

  const rutaHtml = tieneRuta
    ? `
      <div class="mt-2 mb-2 p-2 rounded-4 border" style="background:#f3f8fd;border-color:#dbe8f3 !important;">
        <div class="small fw-bold text-secondary mb-1">
          <i class="bi bi-signpost-split me-1"></i>
          Ruta registrada
          ${esCambioRuta ? `<span class="badge rounded-pill text-bg-info ms-1">RUTA EDITADA</span>` : ''}
        </div>
        <div class="fw-bold text-dark">
          ${esc(rutaTexto)}
        </div>
        <div class="small text-secondary mt-1">
          IDs: ${esc(rutaRaw)}
        </div>
      </div>
    `
    : `
      <div class="mt-2 mb-2 p-2 rounded-4 border" style="background:#fff;border-color:#edf2f7 !important;">
        <div class="small text-secondary">
          <i class="bi bi-signpost me-1"></i>
          Ruta directa, sin sedes intermedias.
        </div>
      </div>
    `;

  return `
    <div class="historial-card ${esTransbordo ? 'border-warning' : ''}">
      <div class="historial-card__top">
        <div class="d-flex flex-wrap gap-2 align-items-center">
          ${bdg}
          ${esTransbordo ? `<span class="badge rounded-pill text-bg-warning"><i class="bi bi-arrow-left-right me-1"></i> TRANSBORDO</span>` : ''}
          ${esCambioRuta ? `<span class="badge rounded-pill text-bg-info"><i class="bi bi-signpost-split me-1"></i> RUTA</span>` : ''}
        </div>
        <div class="small text-secondary">${esc(r.fechaevento || '—')}</div>
      </div>

      <div class="fw-bold text-dark mb-1">
        ${esc(fmtHora(r.clm_progbuses_horasalida || r.hora_fmt))} |
        ${esc(r.oficina_origen || '—')} → ${esc(r.oficina_destino || '—')}
      </div>

      ${rutaHtml}

      <div class="text-secondary mb-2">
        Bus: ${esc(r.bus || 'SIN BUS')} · Placa: ${esc(r.placa || '—')}
      </div>

      <div class="small mb-2">
        <span class="badge rounded-pill text-bg-light border text-dark">
          <i class="bi bi-person-circle me-1"></i>
          Realizado por: ${esc(usuario)}
        </span>
      </div>

      <div class="text-dark">
        <strong>Motivo:</strong> ${esc(r.motivo || 'Sin motivo registrado')}
      </div>
    </div>
  `;
}).join('');
}

async function openHistorial() {
  modalHistorial.show();

  if (els.historialSearch) {
    els.historialSearch.value = '';
  }

  state.historialSoloTransbordo = false;

  els.historialContainer.innerHTML = `<div class="empty-state">Cargando historial...</div>`;

  const data = await fetchJson('historial', { query: 'limit=300' });

  state.historialRows = data.historial || [];

  renderHistorial();

  setTimeout(() => {
    if (els.historialSearch) els.historialSearch.focus();
  }, 250);
}

  async function openInhabilitados(){ modalInhabilitados.show(); els.inhabilitadosContainer.innerHTML=`<div class="empty-state">Cargando horarios inhabilitados...</div>`; const data=await fetchJson('inhabilitados'); state.disabledRows=data.inhabilitados||[]; renderInhabilitados(); }
  function renderInhabilitados(){ const rows=state.disabledRows||[]; if(!rows.length){ els.inhabilitadosContainer.innerHTML=`<div class="empty-state">No hay horarios inhabilitados.</div>`; return; } els.inhabilitadosContainer.innerHTML=rows.map(r=>`<div class="inh-card"><div class="inh-card__top"><div><div class="fw-bold text-dark">${esc(fmtHora(r.clm_progbuses_horasalida||r.hora_fmt))} | ${esc(r.oficina_origen||'—')} → ${esc(r.oficina_destino||'—')}</div><div class="text-secondary small mt-1">Bus anterior: ${esc(r.bus||'SIN BUS')} · Placa: ${esc(r.placa||'—')}</div></div>${badge('INHABILITADO','danger')}</div><div class="text-secondary small mb-3">Último motivo: ${esc(r.clm_progbuses_motivo||'—')}</div><div class="text-end"><button class="btn btn-success btn-sm" data-reactivar="${r.clm_progbuses_progid}"><i class="bi bi-check-circle me-1"></i>Activar horario</button></div></div>`).join(''); els.inhabilitadosContainer.querySelectorAll('[data-reactivar]').forEach(btn=>btn.addEventListener('click',async()=>{ const row=rows.find(r=>String(r.clm_progbuses_progid)===String(btn.dataset.reactivar)); if(!row) return; const motivo=await askMotivo({accion:'ACTIVAR',titulo:'Motivo de activación del horario',permitirTaller:false}); if(!motivo) return; await performAction('activar_horario',{progid:row.clm_progbuses_progid,motivo},'Horario activado correctamente.'); state.disabledRows=state.disabledRows.filter(r=>String(r.clm_progbuses_progid)!==String(row.clm_progbuses_progid)); renderInhabilitados(); })); }
const btnExpandirAcordeones = document.getElementById('btnExpandirAcordeones');
const btnContraerAcordeones = document.getElementById('btnContraerAcordeones');

if (btnExpandirAcordeones) {
  btnExpandirAcordeones.addEventListener('click', () => {
    state.collapsedOrigins.clear();
    renderBoard();
  });
}

if (btnContraerAcordeones) {
  btnContraerAcordeones.addEventListener('click', () => {
    const rows = getFilteredRows();

    rows.forEach(r => {
      const origen = r.oficina_origen || 'SIN ORIGEN';
      state.collapsedOrigins.add(origen);
    });

    renderBoard();
  });
} 
  els.filtro.addEventListener('input',renderBoard); els.btnRefresh.addEventListener('click',()=>refreshSnapshot(true).catch(err=>showAlert('danger',err.message||'No se pudo actualizar la programación.'))); els.btnNuevoHorario.addEventListener('click',openCreateModal); els.btnExportImagen.addEventListener('click', async () => {
  if (state.isBusy) return;

  horarioBeginLoading();
  try {
    await generarImagenPizarra();
  } catch (err) {
    showAlert('danger', err.message || 'No se pudo generar la imagen.');
  } finally {
    horarioEndLoading();
  }
});

els.btnAbrirModalImagen.addEventListener('click', () => {
  modalExportImagen.show();
});

els.switchModoImagen.addEventListener('change', actualizarTextoModoImagen);

els.btnAbrirModalImagen.addEventListener('click', () => {
  if (els.switchModoImagen) els.switchModoImagen.checked = false;
  actualizarTextoModoImagen();
  modalExportImagen.show();
});

els.btnDescargarPizarra.addEventListener('click', async () => {
  if (state.isBusy) return;

  modalExportImagen.hide();
  horarioBeginLoading();

  try {
    await generarImagenPorFormato('PIZARRA');
  } catch (err) {
    showAlert('danger', err.message || 'No se pudo generar la pizarra.');
  } finally {
    horarioEndLoading();
  }
});

els.btnDescargarTabla.addEventListener('click', async () => {
  if (state.isBusy) return;

  modalExportImagen.hide();
  horarioBeginLoading();

  try {
    await generarImagenPorFormato('TABLA');
  } catch (err) {
    showAlert('danger', err.message || 'No se pudo generar la tabla.');
  } finally {
    horarioEndLoading();
  }
});

if (els.btnDescargarPizarraPDF) {
  els.btnDescargarPizarraPDF.addEventListener('click', async () => {
    if (state.isBusy) return;

    modalExportImagen.hide();
    horarioBeginLoading();

    try {
      await generarPDFPorFormato('PIZARRA');
    } catch (err) {
      showAlert('danger', err.message || 'No se pudo generar el PDF de pizarra.');
    } finally {
      horarioEndLoading();
    }
  });
}

if (els.btnDescargarTablaPDF) {
  els.btnDescargarTablaPDF.addEventListener('click', async () => {
    if (state.isBusy) return;

    modalExportImagen.hide();
    horarioBeginLoading();

    try {
      await generarPDFPorFormato('TABLA');
    } catch (err) {
      showAlert('danger', err.message || 'No se pudo generar el PDF de tabla.');
    } finally {
      horarioEndLoading();
    }
  });
}

els.btnGuardarNuevoHorario.addEventListener('click',()=>saveNewHorario().catch(err=>showAlert('danger',err.message||'No se pudo crear el horario.'))); 

els.crearOrigen.addEventListener('change', () => {
  refreshCrearRutaSelector();
  updateCreatePreview();
});

els.crearDestino.addEventListener('change', () => {
  refreshCrearRutaSelector();
  updateCreatePreview();
});

els.crearHora.addEventListener('input', updateCreatePreview);

els.btnSwapOficinas.addEventListener('click', () => {
  const o = els.crearOrigen.value;
  const d = els.crearDestino.value;

  els.crearOrigen.value = d;
  els.crearDestino.value = o;

  refreshCrearRutaSelector();
  updateCreatePreview();
});

els.btnHistorial.addEventListener('click',()=>openHistorial().catch(err=>showAlert('danger',err.message||'No se pudo cargar el historial.'))); els.btnInhabilitados.addEventListener('click',()=>openInhabilitados().catch(err=>showAlert('danger',err.message||'No se pudieron cargar los horarios inhabilitados.'))); 
if (els.btnLimpiarPizarra && modalLimpiarPizarra) {
  els.btnLimpiarPizarra.addEventListener('click', () => {
    modalLimpiarPizarra.show();
  });
}
if (els.btnConfirmLimpiarPizarra) {
  els.btnConfirmLimpiarPizarra.addEventListener('click', () => {
    confirmarLimpiarPizarra().catch(err => {
      showAlert('danger', err.message || 'No se pudo lanzar la limpieza.');
    });
  });
}
els.editarHoraInput.addEventListener('input',updateEditPreview);

els.editarDestino.addEventListener('change', updateEditPreview);
if (els.btnLimpiarRutaCrear) {
  els.btnLimpiarRutaCrear.addEventListener('click', () => {
    els.crearRutaList?.querySelectorAll('input[type="checkbox"]').forEach(chk => chk.checked = false);
    updateCreatePreview();

    if (els.crearRutaPreview) {
      els.crearRutaPreview.textContent = 'Ruta: directa, sin sedes intermedias.';
    }
  });
}

if (els.btnLimpiarRutaEditar) {
  els.btnLimpiarRutaEditar.addEventListener('click', () => {
    els.editarRutaList?.querySelectorAll('input[type="checkbox"]').forEach(chk => chk.checked = false);
    updateEditPreview();

    if (els.editarRutaPreview) {
      els.editarRutaPreview.textContent = 'Ruta: directa, sin sedes intermedias.';
    }
  });
}

if (els.crearRutaList) {
  els.crearRutaList.addEventListener('change', updateCreatePreview);
}

if (els.editarRutaList) {
  els.editarRutaList.addEventListener('change', updateEditPreview);
}
els.btnGuardarEditarHora.addEventListener('click',()=>saveEditedHora().catch(err=>showAlert('danger',err.message||'No se pudo actualizar la hora.'))); els.btnAceptarMotivo.addEventListener('click',acceptMotivo); els.busSearch.addEventListener('input',renderBusList); 

if (els.historialSearch) {
  els.historialSearch.addEventListener('input', renderHistorial);
}
if (els.btnFiltroTransbordoHistorial) {
  els.btnFiltroTransbordoHistorial.addEventListener('click', () => {
    state.historialSoloTransbordo = !state.historialSoloTransbordo;
    renderHistorial();
  });
}

if (els.btnLimpiarFiltroHistorial) {
  els.btnLimpiarFiltroHistorial.addEventListener('click', () => {
    state.historialSoloTransbordo = false;

    if (els.historialSearch) {
      els.historialSearch.value = '';
    }

    renderHistorial();
  });
}
els.btnConfirmBus.addEventListener('click',()=>confirmBusSelection().catch(err=>showAlert('danger',err.message||'No se pudo completar la operación con el bus.')));
fillQuickTimes(els.crearHora, els.quickTimeWrap, updateCreatePreview);
fillQuickTimes(els.editarHoraInput, els.quickEditTimeWrap, updateEditPreview);
updateAll();

const paramsQR = new URLSearchParams(window.location.search);
const esModoQRProgramacion = paramsQR.get('qr') === 'programacion';

if (esModoQRProgramacion) {
  document.body.classList.add('modo-qr-programacion');

  setTimeout(async () => {
    try {
      await generarPDFQRDosHojas();
    } catch (err) {
      N360Dialog.alert(err.message || 'No se pudo generar el PDF desde el QR.', {
        variant: 'danger',
        title: 'No se pudo generar el PDF'
      });
    }
  }, 1200);
}
})();
</script>

<script>
function toggleDropdown() {
  const dropdown = document.getElementById("usuarioDropdown");
  dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
}

// Cierra si haces clic fuera
document.addEventListener("click", function (e) {
  const barra = document.querySelector(".usuario-barra");
  const dropdown = document.getElementById("usuarioDropdown");

  if (!barra.contains(e.target) && !dropdown.contains(e.target)) {
    dropdown.style.display = "none";
  }
});
</script>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>


</html>
