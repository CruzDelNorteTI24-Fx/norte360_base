<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);

if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 10;
    $vista_actuales = ["f-proghor"];

    if (!in_array($modulo_actual, $_SESSION['permisos']) || empty(array_intersect($vista_actuales, $_SESSION['vistas']))) {
        header("Location: ../../login/none_permisos.php");
        exit();
    }
}

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
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

    if ($cantidad > 0) {
        horario_upsert_estado_bus($conn, $uid, $idplaca, 'ASIGNADO', $progidRef, "Asignado en {$cantidad} horario(s) activo(s)");
        return;
    }
    if ($preferirTaller && stripos((string)$motivo, 'TALLER') !== false) {
        horario_upsert_estado_bus($conn, $uid, $idplaca, 'TALLER', null, $motivo);
    } else {
        horario_upsert_estado_bus($conn, $uid, $idplaca, 'SIN_HORARIO', null, $motivo);
    }
}

function horario_fetch_oficinas(mysqli $conn, string $tipo = 'TODAS'): array {
$sql = "
    SELECT 
        clm_sedes_id,
        IFNULL(clm_sedes_abr, '') AS oficina,
        IFNULL(clm_sedes_origendestino, '') AS origendestino,
        IFNULL(clm_sedes_grupo_pizarra, 'SIN GRUPO') AS grupo_pizarra,
        IFNULL(NULLIF(TRIM(clm_sedes_tipo_imagen_grupo), ''), 'PIZARRA') AS tipo_imagen_grupo
    FROM tb_sedes
    WHERE IFNULL(clm_sedes_estado, 0) = 1
";
    if ($tipo === 'ORIGEN') {
        $sql .= " AND UPPER(TRIM(IFNULL(clm_sedes_origendestino, ''))) = 'ORIGEN' ";
    }
    $sql .= " ORDER BY clm_sedes_abr ASC ";
    $res = $conn->query($sql);
    if (!$res) throw new RuntimeException(horario_mysqli_error($conn));
    return $res->fetch_all(MYSQLI_ASSOC);
}

function horario_fetch_panel_horarios(mysqli $conn, int $estado = 1): array {
$sql = "
    SELECT
        pb.clm_progbuses_progid,
        pb.clm_progbuses_fechacreated,
        pb.clm_progbuses_idplaca,
        pb.clm_progbuses_idoficina_origen,
        pb.clm_progbuses_idoficina_destino,
        pb.clm_progbuses_horasalida,
        pb.clm_progbuses_estado,
        pb.clm_progbuses_idusuario,
        pb.clm_progbuses_datetimeupdated,
        pb.clm_progbuses_motivo,
        IFNULL(p.clm_placas_BUS, '') AS bus,
        IFNULL(p.clm_placas_PLACA, '') AS placa,
        IFNULL(p.clm_placas_TIPO_VEHÍCULO, '') AS tipo_vehiculo,
        IFNULL(p.clm_placas_servicio, '') AS servicio_unidad,        
        IFNULL(o1.clm_sedes_abr, '') AS oficina_origen,
        IFNULL(o2.clm_sedes_abr, '') AS oficina_destino,
        IFNULL(o1.clm_sedes_grupo_pizarra, 'SIN GRUPO') AS grupo_pizarra_origen,
        IFNULL(NULLIF(TRIM(o1.clm_sedes_tipo_imagen_grupo), ''), 'PIZARRA') AS tipo_imagen_grupo_origen
    FROM tb_progbuses pb
    LEFT JOIN tb_placas p ON p.clm_placas_id = pb.clm_progbuses_idplaca
    LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
    LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
    WHERE pb.clm_progbuses_estado = ?
    ORDER BY 
        IFNULL(o1.clm_sedes_grupo_pizarra, 'SIN GRUPO') ASC,
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
            h.clm_progbuses_horasalida,
            h.clm_progbuses_estado,
            h.clm_progbuses_idusuario,
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

        if ($ajax === 'snapshot') horario_json(true, horario_build_snapshot($conn));
        if ($ajax === 'historial') horario_json(true, ['historial' => horario_fetch_historial($conn, (int)($_GET['limit'] ?? 300))]);
        if ($ajax === 'inhabilitados') horario_json(true, ['inhabilitados' => horario_fetch_panel_horarios($conn, 2)]);
        if ($ajax === 'buses_disponibles') horario_json(true, ['buses' => horario_fetch_buses_disponibles($conn)]);
        if ($ajax === 'oficinas') {
            $tipo = strtoupper(trim((string)($_GET['tipo'] ?? 'TODAS')));
            horario_json(true, ['oficinas' => horario_fetch_oficinas($conn, $tipo === 'ORIGEN' ? 'ORIGEN' : 'TODAS')]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') horario_json(false, [], 'Método no permitido.');

        $conn->begin_transaction();

        if ($ajax === 'create_horario') {
            $idOrigen = (int)($_POST['idof_origen'] ?? 0);
            $idDestino = (int)($_POST['idof_destino'] ?? 0);
            $hora = horario_time_to_sql($_POST['horasalida'] ?? '');
            if ($idOrigen <= 0 || $idDestino <= 0) throw new RuntimeException('Selecciona origen y destino válidos.');
            if ($idOrigen === $idDestino) throw new RuntimeException('Origen y destino no pueden ser iguales.');
            if (!$hora) throw new RuntimeException('La hora de salida es obligatoria.');
            $sql = "INSERT INTO tb_progbuses (clm_progbuses_fechacreated, clm_progbuses_idplaca, clm_progbuses_idoficina_origen, clm_progbuses_idoficina_destino, clm_progbuses_horasalida, clm_progbuses_estado, clm_progbuses_idusuario, clm_progbuses_motivo) VALUES (" . horario_now_peru_sql() . ", NULL, ?, ?, ?, 1, ?, 'Creación inicial del horario')";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
            $stmt->bind_param('iisi', $idOrigen, $idDestino, $hora, $horario_uid);
            if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
            $stmt->close();
            $conn->commit();
            horario_json(true, horario_build_snapshot($conn), 'Horario creado correctamente.');
        }

        if ($ajax === 'editar_hora') {
            $progid = (int)($_POST['progid'] ?? 0);
            $nuevaHora = horario_time_to_sql($_POST['horasalida'] ?? '');
            if ($progid <= 0 || !$nuevaHora) throw new RuntimeException('Datos incompletos para editar la hora.');
            $stmt = $conn->prepare("SELECT clm_progbuses_horasalida FROM tb_progbuses WHERE clm_progbuses_progid = ? LIMIT 1");
            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
            $stmt->bind_param('i', $progid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) throw new RuntimeException('No se encontró el horario.');
            $horaAnterior = horario_format_hora($row['clm_progbuses_horasalida'] ?? '');
            $horaNuevaFmt = horario_format_hora($nuevaHora);
            if ($horaAnterior === $horaNuevaFmt) throw new RuntimeException('La nueva hora es igual a la actual.');
            $motivoAuto = "Reprogramación de horario: de {$horaAnterior} a {$horaNuevaFmt}";
            horario_set_hist_motivo($conn, $motivoAuto);
            $stmt = $conn->prepare("UPDATE tb_progbuses SET clm_progbuses_horasalida = ?, clm_progbuses_idusuario = ?, clm_progbuses_motivo = NULL WHERE clm_progbuses_progid = ?");
            if (!$stmt) throw new RuntimeException(horario_mysqli_error($conn));
            $stmt->bind_param('sii', $nuevaHora, $horario_uid, $progid);
            if (!$stmt->execute()) { $err = $stmt->error ?: horario_mysqli_error($conn); $stmt->close(); throw new RuntimeException($err); }
            $stmt->close();
            horario_clear_hist_motivo($conn);
            $conn->commit();
            horario_json(true, horario_build_snapshot($conn), 'Hora actualizada correctamente.');
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
    </style>
</head>

<body>
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

<header class="main-header animated-border">
  <div class="header-content">
    <a href="../index.php"">
        <div class="logo-bloque">
            <img src="../img/norte360.png" alt="Logo Empresa" class="logo-header">
        </div>
    </a>

    <div class="separador-vertical"></div>
        <a href="javascript:location.reload()">
            <div class="logo-bloque">
            <img src="../img/completo.png" alt="Logo Sistema" class="logo-header2">
            </div>
        </a>


    <div class="usuario-contenedor" style="margin-left:auto; position: relative;">
      <div class="usuario-barra" onclick="toggleDropdown()">
        <span>Hola, <?= htmlspecialchars($_SESSION['usuario']) ?></span>
        <img src="../img/icons/user.png" alt="Usuario">
      </div>
      <div class="usuario-dropdown" id="usuarioDropdown">
        <p><strong>Nombre:</strong> <?= htmlspecialchars($_SESSION['usuario']) ?></p>
        <p><strong>DNI:</strong> <?= htmlspecialchars($_SESSION['DNI']) ?></p>
        <p><strong>Edad:</strong> <?= $edad ?> años</p>
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%); margin: 12px 0; border: none; border-top: 1px solid #eee;">
        <p><strong>Rol:</strong> <?= htmlspecialchars($_SESSION['web_rol']) ?></p>
        <a href="../login/logout.php" class="btn-logout-dropdown">Cerrar sesión</a>
      </div>
    </div>

    </div>
</header>

<nav id="nav-modulos" class="nav-bar-pro">
  <ul class="nav-list-pro">
  <?php
    if ($_SESSION['web_rol'] === 'Admin' || in_array(6, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-personal\')">👥 Recursos Humanos</a></li>';
    }
    if ($_SESSION['web_rol'] === 'Admin' || in_array(5, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-mantenimiento\')">🔧 Mantenimiento</a></li>';
    }
    if ($_SESSION['web_rol'] === 'Admin' || in_array(3, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-inventario\')">📦 Inventario</a></li>';
    }
    if ($_SESSION['web_rol'] === 'Admin' || in_array(10, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-flotayoperaciones\')">🚌 Flota y Operaciones</a></li>';
    }
  ?>
  </ul>
</nav>

<div id="modulo-personal" class="subnav" style="display: none;">
  <a href="../01_contratos/nregrcdn_h.php">➕ Nuevo Trabajador</a>
  <a href="../01_entrevistas/reentrev.php">➕ Nueva Entrevista</a>
  <a href="../01_contratos/documentacion/agregadocu.php">➕ Nueva Documentación</a>
  <a href="../01_contratos/nlaskdrcdn_h.php">👤 Personal</a>
  <a href="../01_entrevistas/bvisentrevisaf.php">📝 Entrevistas</a>
  <a href="../01_contratos/dorrhcdn.php">📁 Documentación</a>
</div>

<div id="modulo-inventario" class="subnav" style="display: none;">
  <a href="../01_almacen/scanner.php"> 🏷️ Código de Barra</a>
  <a href="../01_almacen/gen_np9823.php">📋 Catálogo Productos</a>
</div>

<div id="modulo-mantenimiento" class="subnav" style="display: none;">
  <a href="../01_amantenimiento/lista_cheklist.php">📝 CheckList</a>
</div>

<div id="modulo-flotayoperaciones" class="subnav" style="display: none;">
  <a href="../01_flota/programacion_horarios.php">📋 Programación Horarios</a>
  <a href="../01_flota/programacion_condt.php">👤 Conductores</a>
  <a href="../01_flota/gest_plac.php">📝 Gestión de Placas</a>
</div>

<button class="menu-toggle" id="btnMenuToggle" onclick="toggleMenu()" aria-label="Menú"><span></span><span></span><span></span></button>

<!-- SIDEBAR FIJO EN DESKTOP -->
<nav class="menu-lateral" id="menuLateral">
  <button class="sidebar-toggle-btn" id="btnHideSidebar" aria-label="Ocultar menú">
    <i class="bi bi-chevron-left"></i>
  </button>

  <div class="menu-logo">
    <img src="../img/norte360_black.png" alt="Logo" style="height:40px;">
    <span class="fw-bold ms-2" style="color:#2c3e50;">Norte 360°</span>
  </div>
  <ul class="menu-list">
    <h3>Programación</h3>
    <li><a href="programacion_condt.php"><i class="bi bi-person-plus-fill"></i> Programación de Conductores</a></li>
    <li><a href="programacion_horarios.php" class="active"><i class="bi bi-clock-history"></i> Programación de Horarios</a></li>
    <li><a href="gest_prog_horarios.php" class="active"><i class="bi bi-bar-chart-line-fill"></i> Historial Gerencial</a></li>
  </ul>
  <ul class="menu-list">
    <h3>Vehículos</h3>
    <li><a href="gest_plac.php"><i class="bi bi-bus-front"></i> Gestionar Placas</a></li>
  </ul>
</nav>

<button class="sidebar-show-btn" id="sidebarShowBtn" aria-label="Mostrar menú">
  <i class="bi bi-chevron-right"></i>
</button>





<div class="main-content">
 <hr>

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
                <input type="text" id="filtroHorarios" class="form-control" placeholder="Busca por origen, destino, bus, placa, tipo o hora...">
            </div>
            <div class="col-lg-7">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end mt-lg-4">
                    <button class="btn btn-primary" id="btnNuevoHorario"><i class="bi bi-plus-circle me-2"></i>Nuevo horario</button>
                    <button class="btn btn-outline-secondary" id="btnRefreshHorarios"><i class="bi bi-arrow-repeat me-2"></i>Actualizar</button>
                    <button class="btn btn-outline-info" id="btnExportImagen"><i class="bi bi-image me-2"></i>Generar imagen</button>
                    <button class="btn btn-outline-danger" id="btnInhabilitados"><i class="bi bi-ban me-2"></i>Inhabilitados</button>
                    <button class="btn btn-outline-dark" id="btnHistorial"><i class="bi bi-clock-history me-2"></i>Historial</button>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-4 align-items-start">
<div class="col-xl-9">
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

 <hr>

</div>

<div class="modal fade" id="modalCrearHorario" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Crear nuevo horario</h5><div class="small text-white-50">Origen: solo sedes ORIGEN activas · Destino: todas las sedes activas</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-light border rounded-4 mb-4"><div class="small text-secondary fw-bold mb-1">Vista previa</div><div class="fs-5 fw-bold text-dark" id="previewNuevoHorario">16:00 | Origen → Destino</div></div><div class="row g-3 mb-3"><div class="col-md-5"><label class="form-label fw-bold">Origen</label><select id="crearOrigen" class="form-select"></select></div><div class="col-md-2 d-flex align-items-end justify-content-center"><button type="button" class="btn btn-outline-secondary w-100" id="btnSwapOficinas"><i class="bi bi-arrow-left-right"></i></button></div><div class="col-md-5"><label class="form-label fw-bold">Destino</label><select id="crearDestino" class="form-select"></select></div></div><div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label fw-bold">Hora de salida</label><input type="time" id="crearHora" class="form-control" step="300" value="16:00"></div><div class="col-md-8"><label class="form-label fw-bold">Horarios rápidos</label><div class="quick-time" id="quickTimeWrap"></div></div></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnGuardarNuevoHorario">Guardar horario</button></div></div></div></div>

<div class="modal fade" id="modalEditarHora" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Cambiar hora del horario</h5><div class="small text-white-50" id="editarHoraSubtitulo">Horario</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-light border rounded-4 mb-4"><div class="small text-secondary fw-bold mb-1">Vista previa de reprogramación</div><div class="fs-5 fw-bold text-dark" id="previewEditarHora">00:00 → 00:00</div></div><div class="mb-3"><label class="form-label fw-bold">Nueva hora de salida</label><input type="time" id="editarHoraInput" class="form-control" step="300"></div><div class="quick-time" id="quickEditTimeWrap"></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnGuardarEditarHora">Actualizar hora</button></div></div></div></div>

<div class="modal fade" id="modalMotivo" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="motivoTitulo">Motivo requerido</h5><div class="small text-white-50">Selecciona un motivo rápido o escribe uno personalizado.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3 mb-3" id="motivoOptions"></div><div class="alert alert-light border rounded-4 mb-3"><div class="small fw-bold text-secondary mb-1">Así quedará guardado el motivo en el historial</div><div id="motivoPreview" class="fw-bold text-dark"></div></div><div><label class="form-label fw-bold">Motivo libre</label><textarea id="motivoLibre" class="form-control" rows="4" placeholder="Escribe el motivo si elegiste OTRO"></textarea></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnAceptarMotivo">Aceptar</button></div></div></div></div>

<div class="modal fade" id="modalBus" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="modalBusTitle">Asignar bus</h5><div class="small text-white-50" id="modalBusSubtitle"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3 mb-3 align-items-center"><div class="col-lg-8"><input type="text" id="busSearch" class="form-control" placeholder="Buscar por bus, placa o tipo..."></div><div class="col-lg-4 text-lg-end fw-bold text-secondary" id="busSelectedLabel">Seleccionado: ninguno</div></div><div class="bus-list" id="busList"></div></div><div class="modal-footer bg-white"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="btnConfirmBus">Confirmar</button></div></div></div></div>

<div class="modal fade" id="modalHistorial" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Historial de movimientos</h5><div class="small text-white-50">Trazabilidad de inserciones, cambios, retiros, inhabilitaciones y reactivaciones.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div id="historialContainer"></div></div></div></div></div>

<div class="modal fade" id="modalInhabilitados" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1">Horarios inhabilitados</h5><div class="small text-white-50">Desde aquí puedes reactivar horarios sin mostrarlos en la pizarra principal.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div id="inhabilitadosContainer"></div></div></div></div></div>


<!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
<a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
</a>


<!-- MODAL DE CARGA -->
<div id="modal-cargando" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
  <div style="background:white; padding:30px 50px; border-radius:12px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3); min-width:280px;">
    <i class="bi bi-arrow-repeat" style="font-size:30px; color:#2980b9; display:inline-block; animation: horarioSpin 1s linear infinite;"></i>
    <p style="margin-top:15px; font-size:18px; font-weight:bold; color:#2c3e50;">
      Procesando...<br>Por favor espere
    </p>
  </div>
</div>

<footer class="main-footer animated-border">
  <div class="footer-top">
    <img src="../img/norte360.png" alt="Logo Empresa" class="logo-header3">
    <div class="footer-info">
      <p class="footer-title">Contáctanos</p>
      <div class="footer-cajas">
        <div class="footer-box"><img src="../img/icons/facebook.png" alt="Función 1"></div>
        <div class="footer-box"><img src="../img/icons/social.png" alt="Función 2"></div>
      </div>
    </div>
  </div>
  <p class="footer-copy">© <?= date('Y') ?> Norte 360° (v1.0.6). Todos los derechos reservados.</p>
  <style>.footer-h2bd {position: absolute;bottom: 10px;right: 10px;opacity: 0;transition: opacity 0.4s ease;width: 80px;}.main-footer:hover .footer-h2bd {opacity: 0.6;}.footer-h2bd {filter: grayscale(40%);}</style>
  <div id="h2bd" style="display:none; position:fixed; bottom:10px; left:10px; z-index:9999; text-align:center;"><img src="<?= $h2bd_img ?>" alt="icong" style="width:80px; opacity:0.8; filter: grayscale(40%); display:block; margin:0 auto;"><p style="color:white; font-size:12px; margin:4px 0 0 0;"><?= $h2bd_name ?></p></div>
  <script>document.addEventListener('keydown', function(e) {if (e.ctrlKey && e.altKey && e.key === 'm') {const egg = document.getElementById('h2bd');egg.style.display = egg.style.display === 'none' ? 'block' : 'none';}});</script>

</footer>

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
    btnHistorial: $('btnHistorial'),
    btnInhabilitados: $('btnInhabilitados'),
    crearOrigen: $('crearOrigen'),
    crearDestino: $('crearDestino'),
    crearHora: $('crearHora'),
    previewNuevoHorario: $('previewNuevoHorario'),
    btnGuardarNuevoHorario: $('btnGuardarNuevoHorario'),
    btnSwapOficinas: $('btnSwapOficinas'),
    quickTimeWrap: $('quickTimeWrap'),
    editarHoraSubtitulo: $('editarHoraSubtitulo'),
    editarHoraInput: $('editarHoraInput'),
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
    inhabilitadosContainer: $('inhabilitadosContainer'),
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
        els.btnGuardarNuevoHorario,
        els.btnGuardarEditarHora,
        els.btnAceptarMotivo,
        els.btnConfirmBus
      ].forEach(btn => {
        if (btn) btn.disabled = flag;
      });

      document.querySelectorAll('[data-action], [data-reactivar]').forEach(el => {
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
  const modalHistorial = new bootstrap.Modal($('modalHistorial')); const modalInhabilitados = new bootstrap.Modal($('modalInhabilitados'));
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


  function getFilteredRows(){ const q=(els.filtro.value||'').trim().toLowerCase(); const rows=Array.isArray(state.snapshot.horarios)?state.snapshot.horarios:[]; if(!q) return rows; return rows.filter(r=>[r.clm_progbuses_progid,r.oficina_origen,r.oficina_destino,r.bus,r.placa,r.tipo_vehiculo,fmtHora(r.clm_progbuses_horasalida),(Number(r.clm_progbuses_estado)===1?'ACTIVO':'INACTIVO')].join(' | ').toLowerCase().includes(q)); }
  function renderDates(){ const f=state.snapshot.fechas||{}; els.datesWrap.innerHTML = `<span class="horario-date-chip"><i class="bi bi-calendar-date me-2"></i>Día Operativo: <strong>${esc(f.fecha_base||'—')}</strong></span><span class="horario-date-chip"><i class="bi bi-arrow-right-circle me-2"></i>Siguiente día operativo: <strong>${esc(f.fecha_sig||'—')}</strong></span><span class="horario-date-chip"><i class="bi bi-clock me-2"></i>Corte diario: 00:00 a 04:59</span>`; }
  function renderSummary(){ const s=state.snapshot.summary||{}; els.statTotalActivos.textContent=s.total_activos??0; els.statSinBus.textContent=s.total_sin_bus??0; els.statBusesSinHorario.textContent=s.buses_sin_horario??0; els.statBusesTaller.textContent=s.buses_taller??0; }
function renderSideList(container, rows, emptyText, includeMotivo = false) {
  const orderedRows = (rows || []).slice().sort(compareBusNatural);

  if (!orderedRows.length) {
    container.innerHTML = `<div class="empty-state">${esc(emptyText)}</div>`;
    return;
  }

  container.innerHTML = orderedRows.map(r => `
    <div class="side-list-item">
      <div class="side-list-item__title">${esc(r.bus || 'SIN NOMBRE')}</div>
      <div class="side-list-item__meta">
        Placa: ${esc(r.placa || '—')} · Tipo: ${esc(r.tipo_vehiculo || '—')}
      </div>
      ${includeMotivo && r.motivo ? `<div class="side-list-item__meta mt-1">Motivo: ${esc(r.motivo)}</div>` : ''}
    </div>
  `).join('');
}

  function renderScheduleCard(row, fechaSig){
  const activo = Number(row.clm_progbuses_estado) === 1;
  const tieneBus = !!row.clm_progbuses_idplaca;
  const hora = fmtHora(row.clm_progbuses_horasalida || row.hora_fmt);

  let estadoBadge = badge('INACTIVO', 'danger');
  if (activo && tieneBus) estadoBadge = badge('ASIGNADO', 'success');
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
          <i class="bi bi-dash-circle"></i> Retirar
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
        <i class="bi bi-clock"></i> Hora
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

  return `
    <article class="${tieneBus ? 'schedule-card' : 'schedule-card schedule-card--empty'}">
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
    function attachBoardActions(){ els.boardContainer.querySelectorAll('[data-action]').forEach(btn=>{ btn.addEventListener('click',()=>{ const row=(state.snapshot.horarios||[]).find(r=>String(r.clm_progbuses_progid)===String(btn.dataset.id)); if(!row) return; const action=btn.dataset.action; if(action==='asignar') openBusModal(row,'asignar'); else if(action==='cambiar') openBusModal(row,'cambiar'); else if(action==='editarhora') openEditTimeModal(row); else if(action==='remover'){ askMotivo({accion:'RETIRO',titulo:'Motivo del retiro de la unidad',permitirTaller:true}).then(m=>{ if(m) performAction('remover_bus',{progid:row.clm_progbuses_progid,motivo:m},'Bus removido del horario.'); }); } else if(action==='inactivar'){ askMotivo({accion:'INACTIVAR',titulo:'Motivo de inhabilitación del horario',permitirTaller:false}).then(m=>{ if(m) performAction('inactivar_horario',{progid:row.clm_progbuses_progid,motivo:m},'Horario inhabilitado correctamente.'); }); } }); }); }
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
    .sort((a,b) => a.localeCompare(b))
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

  function measureTextWidth(ctx, text, font = '14px Segoe UI') {
    ctx.save();
    ctx.font = font;
    const w = ctx.measureText(String(text ?? '')).width;
    ctx.restore();
    return w;
  }

  function fitText(ctx, text, maxWidth, font = '14px Segoe UI') {
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



async function generarImagenTablaGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller) {
  const filas = (filasGrupo || []).slice().sort((a, b) => {
    return cmpArr(sortKey(a), sortKey(b));
  });

  const margen = 32;
  const anchoTotal = 1080;
  const anchoInterno = anchoTotal - (margen * 2);

  const rowH = 46;              // más alto para que respire mejor
  const tableHeaderH = 48;
  const headerH = 176;          // más alto para separar mejor el chip del título
  const resumenH = 92;
  const tablaExtraPadding = 34;

  const altoTabla = tableHeaderH + (filas.length * rowH);
  const alto = 24 + headerH + 18 + altoTabla + tablaExtraPadding + 26 + resumenH + 34;

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
  const greenSoft = '#eef7ee';
  const greenText = '#167b4d';
  const redSoft = '#fdecec';
  const redText = '#b42318';

  const fBrand = '700 15px "Segoe UI"';
  const fTitle = '700 24px "Segoe UI"';
  const fGrupo = '900 31px "Segoe UI"';
  const fOperativaChip = '900 18px "Segoe UI"';
  const fMetaLabel = '700 11px "Segoe UI"';
  const fMetaValue = '700 16px "Segoe UI"';
  const fTableHead = '700 13px "Segoe UI"';
  const fCell = '14px "Segoe UI"';   // más grande
  const fCellStrong = '700 14px "Segoe UI"';

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

  // Chip bien centrado y más arriba
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

  // Textos del bloque izquierdo más abajo para que no se peguen al chip
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

  // Menos espacio para BUS y más balanceado
  const columnas = [
    { key: 'fecha_operativa', label: 'DÍA OPERATIVO', width: 150 },
    { key: 'hora',            label: 'HORA',            width: 90  },
    { key: 'busplaca',        label: 'BUS (PLACA)',     width: 210 },
    { key: 'servicio',        label: 'SERVICIO',        width: 160 },
    { key: 'origen',          label: 'ORIGEN',          width: 155 },
    { key: 'destino',         label: 'DESTINO',         width: 155 }
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

    const busPlaca = (() => {
      const bus = String(r.bus || '').trim();
      const placa = String(r.placa || '').trim();
      if (bus && placa) return `${bus} (${placa})`;
      if (bus) return bus;
      if (placa) return `(${placa})`;
      return '—';
    })();

    const fechaOperativaFila = esSigDia ? fechaSigTxt : fechaBaseTxt;

    const valores = [
      fechaOperativaFila,
      hora || '—',
      busPlaca,
      r.servicio_unidad || '—',
      r.oficina_origen || '—',
      r.oficina_destino || '—'
    ];

    let xCell = tablaX;

    valores.forEach((valor, i) => {
      const col = columnas[i];

      let colorCelda = textMain;
      let fontCelda = fCell;

      if (i === 0 && valor) {
        colorCelda = blue;
        fontCelda = fCellStrong;
      }

      if (i === 1) {
        fontCelda = fCellStrong;
      }

      drawText(
        ctx,
        fitText(ctx, String(valor), col.width - 18, fontCelda),
        xCell + 9,
        yRow + 14,
        { font: fontCelda, color: colorCelda }
      );

      xCell += col.width;
    });

    drawLine(ctx, tablaX, yRow + rowH, tablaX + tablaW, yRow + rowH, borderSoft, 1);
    yRow += rowH;
  });

  const resumenY = tablaCardY + tablaH + 46;
  const cardResumenW = 190;
  const cardResumenH = 68;
  const gapResumen = 16;
  const totalResumenW = (cardResumenW * 3) + (gapResumen * 2);
  const resumenX = (anchoTotal / 2) - (totalResumenW / 2);

  const resumenData = [
    { titulo: 'HORARIOS',    valor: filas.length,           color: blueSoft,  text: navy },
    { titulo: 'SIN HORARIO', valor: busesSinHorario.length, color: greenSoft, text: greenText },
    { titulo: 'EN TALLER',   valor: busesTaller.length,     color: redSoft,   text: redText }
  ];

  resumenData.forEach((item, i) => {
    const x = resumenX + (i * (cardResumenW + gapResumen));

    drawCard(ctx, x, resumenY, cardResumenW, cardResumenH, {
      radius: 18,
      fill: item.color,
      stroke: border,
      lineWidth: 1,
      shadow: false
    });

    drawText(ctx, item.titulo, x + 16, resumenY + 13, {
      font: fMetaLabel,
      color: slate
    });

    drawText(ctx, String(item.valor), x + 16, resumenY + 32, {
      font: '900 24px "Segoe UI"',
      color: item.text
    });
  });

  const fechaBaseArchivo = slugify((state.snapshot.fechas || {}).fecha_base || 'operativo');
  const nombreArchivo = `tabla_operativa_vertical_${slugify(nombreGrupo)}_${fechaBaseArchivo}.png`;
  descargarCanvas(canvas, nombreArchivo);
}







async function generarImagenPizarraGrupo(nombreGrupo, filasGrupo, busesSinHorario, busesTaller) {
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
  const cuerpoW = 1450;
  const anchoTotal = cuerpoW + panelDerechoW + (margen * 2);

  let alto = 220;
  Object.keys(grupos).forEach(origen => {
    const items = grupos[origen];
    const filasVisuales = items.length > 6 ? Math.max(1, Math.ceil(items.length / 2)) : items.length;
    alto += 90;
    alto += filasVisuales * 80;
    alto += 24;
  });

  const yPanelBase = 184;
  let altoPanelDerecho = 96;
  altoPanelDerecho += 22;
  altoPanelDerecho += busesSinHorario.length * 40;

  if (busesTaller.length) {
    altoPanelDerecho += 10;
    altoPanelDerecho += 22;
    altoPanelDerecho += busesTaller.length * 40;
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

  const fBrand = '700 16px "Segoe UI"';
  const fTitle = '700 26px "Segoe UI"';
  const fGrupo = '900 34px "Segoe UI"';
  const fOperativaChip = '900 24px "Segoe UI"';
  const fSub = '12px "Segoe UI"';
  const fMetaLabel = '700 12px "Segoe UI"';
  const fMetaValue = '700 18px "Segoe UI"';
  const fOrigen = '700 23px "Segoe UI"';
  const fCount = '11px "Segoe UI"';
  const fHora = '700 31px "Segoe UI"';
  const fBus = '700 24px "Segoe UI"';
  const fDest = '700 11px "Segoe UI"';
  const fSmall = '10px "Segoe UI"';
  const fPanelHead = '700 22px "Segoe UI"';
  const fPanelBus = '700 17px "Segoe UI"';
  const fPanelSmall = '11px "Segoe UI"';

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
  const opW = measureTextWidth(ctx, operTxt, fOperativaChip) + 56;
  const opH = 40;
  const opX = (anchoTotal / 2) - (opW / 2);
  const opY = 34;

  drawCard(ctx, opX, opY, opW, opH, {
    radius: 18,
    fill: blueSoft,
    stroke: '#c8d9eb',
    lineWidth: 2,
    shadow: false
  });

  drawText(ctx, operTxt, opX + 28, opY + 9, {
    font: fOperativaChip,
    color: navy
  });

  drawText(ctx, `Madrugada 00:00–04:59 corresponde a ${fechaSigTxt}`, textX, 128, { font: fSub, color: slate });

  const infoX = anchoTotal - margen - 220;
  const infoY = 48;
  drawCard(ctx, infoX, infoY, 202, 60, {
    radius: 16,
    fill: cardSoft,
    stroke: border,
    lineWidth: 1,
    shadow: false
  });

  drawText(ctx, 'FECHA IMPRESIÓN', infoX + 16, infoY + 10, { font: fMetaLabel, color: slate });
  drawText(ctx, impresion.fecha, infoX + 16, infoY + 26, { font: fMetaValue, color: textMain });
  drawText(ctx, 'HORA', infoX + 132, infoY + 10, { font: fMetaLabel, color: slate });
  drawText(ctx, impresion.hora, infoX + 132, infoY + 26, { font: fMetaValue, color: textMain });

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

  drawCard(ctx, xPanel, yPanel, panelW, 74, {
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

let ySide = yPanel + 96;

// PRIMERO: EN TALLER
if (busesTaller.length) {
  drawText(ctx, 'EN TALLER', xPanel + 22, ySide, {
    font: fMetaLabel,
    color: wine
  });
  ySide += 22;

  busesTaller.forEach(b => {
    const bus = safeUpper(b.bus || '—');
    drawCard(ctx, xPanel + 16, ySide, panelW - 32, 34, {
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
      ySide + 8,
      { font: fPanelBus, color: wine }
    );
    ySide += 40;
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
  drawCard(ctx, xPanel + 16, ySide, panelW - 32, 34, {
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
  ySide += 40;
});

  const x0 = margen;
  let yCursor = 184;
  const cuerpoUtilW = cuerpoW - 24;
  const gapBloquesY = 20;
  const bloquePadX = 18;
  const bloquePadY = 16;
  const tituloH = 46;
  const filaH = 68;
  const bloqueW = cuerpoUtilW;
  const maxFilasPorSubcol = 6;
  const subGap = 28;

  Object.keys(grupos).sort(compareTextNatural).forEach(origen => {
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

        drawCard(ctx, subX - 6, filaY - 2, subcolW - 2, 56, {
          radius: 12,
          fill: '#fbfdff',
          shadow: false
        });

        drawText(ctx, hora || '—', subX, filaY + 1, { font: fHora, color: textMain });

        if (esSigDia) {
          drawText(ctx, fechaSigCorta, subX + 3, filaY + 38, { font: fSmall, color: slate });
        }

        if (bus) {
          drawText(ctx, fitText(ctx, bus, subcolW - 132, fBus), subX + 116, filaY + 4, { font: fBus, color: blue });
        } else {
          drawLine(ctx, subX + 116, filaY + 24, subX + subcolW - 12, filaY + 24, lineEmpty, 3);
        }

        if (destinoServicio) {
          const colorDest = destino.includes('TALLER') ? wine : slate;
          drawText(
            ctx,
            fitText(ctx, destinoServicio, subcolW - 132, fDest),
            subX + 116,
            filaY + 34,
            { font: fDest, color: colorDest }
          );
        }

        filaY += filaH;
      });
    });

    yCursor += blockH + gapBloquesY;
  });

  const fechaBaseArchivo = slugify((state.snapshot.fechas || {}).fecha_base || 'operativo');
  const nombreArchivo = `pizarra_operativa_${slugify(nombreGrupo)}_${fechaBaseArchivo}.png`;
  descargarCanvas(canvas, nombreArchivo);
}


  function updateAll(){ renderDates(); renderSummary(); renderBoard(); renderSideList(els.sideTaller,state.snapshot.buses_taller||[],'No hay buses marcados en taller.',true); renderSideList(els.sideSinHorario,state.snapshot.buses_sin_horario||[],'Todos los buses activos ya tienen horario.'); els.sideTallerCount.textContent=(state.snapshot.buses_taller||[]).length; els.sideSinHorarioCount.textContent=(state.snapshot.buses_sin_horario||[]).length; }
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
  async function refreshSnapshot(showOk=false){ const data=await fetchJson('snapshot'); state.snapshot=data; updateAll(); if(showOk) showAlert('success','Programación actualizada correctamente.'); }
  function populateOfficeSelects(){ const origenes=state.snapshot.oficinas_origen||[]; const destinos=state.snapshot.oficinas_destino||[]; els.crearOrigen.innerHTML=`<option value="">Selecciona origen</option>`+origenes.map(o=>`<option value="${o.clm_sedes_id}">${esc(o.oficina||`Sede ${o.clm_sedes_id}`)}</option>`).join(''); els.crearDestino.innerHTML=`<option value="">Selecciona destino</option>`+destinos.map(o=>`<option value="${o.clm_sedes_id}">${esc(o.oficina||`Sede ${o.clm_sedes_id}`)}</option>`).join(''); }
  function updateCreatePreview(){ const origenTxt=els.crearOrigen.options[els.crearOrigen.selectedIndex]?.text||'Origen'; const destinoTxt=els.crearDestino.options[els.crearDestino.selectedIndex]?.text||'Destino'; const hora=els.crearHora.value||'16:00'; els.previewNuevoHorario.textContent=`${hora} | ${origenTxt} → ${destinoTxt}`; }
  function fillQuickTimes(input, wrap, onChange){ wrap.innerHTML=quickTimes.map(h=>`<button type="button" class="btn btn-outline-secondary btn-sm" data-time="${h}">${h}</button>`).join(''); wrap.querySelectorAll('[data-time]').forEach(btn=>btn.addEventListener('click',()=>{ input.value=btn.dataset.time; onChange&&onChange(); })); }
  function openCreateModal(){ populateOfficeSelects(); els.crearHora.value='16:00'; els.crearOrigen.value=''; els.crearDestino.value=''; updateCreatePreview(); modalCreate.show(); }
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

  const fd=new FormData();
  fd.append('idof_origen',els.crearOrigen.value);
  fd.append('idof_destino',els.crearDestino.value);
  fd.append('horasalida',els.crearHora.value);

  const data=await fetchJson('create_horario',{method:'POST',body:fd});
  state.snapshot=data;
  updateAll();
  modalCreate.hide();
  showAlert('success','Horario creado correctamente.');
}
  function openEditTimeModal(row){ if(Number(row.clm_progbuses_estado)!==1){ showAlert('warning','Solo puedes cambiar la hora de horarios activos.'); return; } state.editRow=row; const hora=fmtHora(row.clm_progbuses_horasalida||row.hora_fmt)||'16:00'; els.editarHoraInput.value=hora; els.editarHoraSubtitulo.textContent=`Horario #${row.clm_progbuses_progid} | ${row.oficina_origen||'—'} → ${row.oficina_destino||'—'}`; updateEditPreview(); modalEdit.show(); }
  function updateEditPreview(){ if(!state.editRow) return; const horaOriginal=fmtHora(state.editRow.clm_progbuses_horasalida||state.editRow.hora_fmt); const horaNueva=els.editarHoraInput.value||horaOriginal; els.previewEditarHora.textContent=`${horaOriginal} → ${horaNueva} | ${state.editRow.oficina_origen||'—'} → ${state.editRow.oficina_destino||'—'}`; }
async function saveEditedHora(){
  if (state.isBusy) return;
  if(!state.editRow) return;
  if(!els.editarHoraInput.value){
    showAlert('warning','Selecciona la nueva hora.');
    return;
  }

  const fd=new FormData();
  fd.append('progid',state.editRow.clm_progbuses_progid);
  fd.append('horasalida',els.editarHoraInput.value);

  const data=await fetchJson('editar_hora',{method:'POST',body:fd});
  state.snapshot=data;
  updateAll();
  modalEdit.hide();
  state.editRow=null;
  showAlert('success','Hora actualizada correctamente.');
}
  function getMotivoConfig(config){ const accion=config.accion||'CAMBIO'; if(accion==='CAMBIO') return { titulo:config.titulo||'Motivo del cambio de bus', options:[{key:'NORMAL',label:'Cambio normal del día',preview:'Cambio de unidad por programación normal del día'}, ...(config.permitirTaller===false?[]:[{key:'TALLER',label:'TALLER',preview:'Cambio de unidad por envío a taller'}]), {key:'OTRO',label:'OTRO MOTIVO',preview:'Cambio de unidad por: '}], build:(sel,libre)=>sel==='NORMAL'?'Cambio de unidad por programación normal del día':sel==='TALLER'?'Cambio de unidad por envío a taller':`Cambio de unidad por: ${libre}`}; if(accion==='RETIRO') return { titulo:config.titulo||'Motivo del retiro de la unidad', options:[{key:'NORMAL',label:'Retiro normal del día',preview:'Retiro de unidad por programación normal del día'}, ...(config.permitirTaller===false?[]:[{key:'TALLER',label:'TALLER',preview:'TALLER'}]), {key:'OTRO',label:'OTRO MOTIVO',preview:'Retiro de unidad por: '}], build:(sel,libre)=>sel==='NORMAL'?'Retiro de unidad por programación normal del día':sel==='TALLER'?'TALLER':`Retiro de unidad por: ${libre}`}; if(accion==='INACTIVAR') return { titulo:config.titulo||'Motivo de inhabilitación del horario', options:[{key:'NORMAL',label:'Inactivación operativa',preview:'Horario inactivado por decisión operativa'},{key:'OTRO',label:'OTRO MOTIVO',preview:'Horario inactivado por: '}], build:(sel,libre)=>sel==='NORMAL'?'Horario inactivado por decisión operativa':`Horario inactivado por: ${libre}`}; if(accion==='ACTIVAR') return { titulo:config.titulo||'Motivo de activación del horario', options:[{key:'NORMAL',label:'Reactivación operativa',preview:'Horario reactivado para programación'},{key:'OTRO',label:'OTRO MOTIVO',preview:'Horario reactivado por: '}], build:(sel,libre)=>sel==='NORMAL'?'Horario reactivado para programación':`Horario reactivado por: ${libre}`}; return config; }
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

  // CAMBIAR BUS: cerrar primero el modal anterior para evitar superposición
  modalBus.hide();
  await waitModalHidden($('modalBus'));

  const motivo = await askMotivo({
    accion: 'CAMBIO',
    titulo: 'Motivo del cambio de bus',
    permitirTaller: true
  });

  // Si cancelan el motivo, regresamos al modal de selección de unidad
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
  async function openHistorial(){ modalHistorial.show(); els.historialContainer.innerHTML=`<div class="empty-state">Cargando historial...</div>`; const data=await fetchJson('historial',{query:'limit=300'}); state.historialRows=data.historial||[]; if(!state.historialRows.length){ els.historialContainer.innerHTML=`<div class="empty-state">Sin historial disponible.</div>`; return; } els.historialContainer.innerHTML=state.historialRows.map(r=>{ let bdg=badge(r.accion||'—','warning'); if((r.accion||'').toUpperCase()==='INSERT') bdg=badge('INSERT','success'); if((r.accion||'').toUpperCase()==='DELETE') bdg=badge('DELETE','danger'); return `<div class="historial-card"><div class="historial-card__top"><div>${bdg}</div><div class="small text-secondary">${esc(r.fechaevento||'—')}</div></div><div class="fw-bold text-dark mb-1">${esc(fmtHora(r.clm_progbuses_horasalida||r.hora_fmt))} | ${esc(r.oficina_origen||'—')} → ${esc(r.oficina_destino||'—')}</div><div class="text-secondary mb-2">Bus: ${esc(r.bus||'SIN BUS')} · Placa: ${esc(r.placa||'—')}</div><div class="text-dark">Motivo: ${esc(r.motivo||'Sin motivo registrado')}</div></div>`; }).join(''); }
  async function openInhabilitados(){ modalInhabilitados.show(); els.inhabilitadosContainer.innerHTML=`<div class="empty-state">Cargando horarios inhabilitados...</div>`; const data=await fetchJson('inhabilitados'); state.disabledRows=data.inhabilitados||[]; renderInhabilitados(); }
  function renderInhabilitados(){ const rows=state.disabledRows||[]; if(!rows.length){ els.inhabilitadosContainer.innerHTML=`<div class="empty-state">No hay horarios inhabilitados.</div>`; return; } els.inhabilitadosContainer.innerHTML=rows.map(r=>`<div class="inh-card"><div class="inh-card__top"><div><div class="fw-bold text-dark">${esc(fmtHora(r.clm_progbuses_horasalida||r.hora_fmt))} | ${esc(r.oficina_origen||'—')} → ${esc(r.oficina_destino||'—')}</div><div class="text-secondary small mt-1">Bus anterior: ${esc(r.bus||'SIN BUS')} · Placa: ${esc(r.placa||'—')}</div></div>${badge('INHABILITADO','danger')}</div><div class="text-secondary small mb-3">Último motivo: ${esc(r.clm_progbuses_motivo||'—')}</div><div class="text-end"><button class="btn btn-success btn-sm" data-reactivar="${r.clm_progbuses_progid}"><i class="bi bi-check-circle me-1"></i>Activar horario</button></div></div>`).join(''); els.inhabilitadosContainer.querySelectorAll('[data-reactivar]').forEach(btn=>btn.addEventListener('click',async()=>{ const row=rows.find(r=>String(r.clm_progbuses_progid)===String(btn.dataset.reactivar)); if(!row) return; const motivo=await askMotivo({accion:'ACTIVAR',titulo:'Motivo de activación del horario',permitirTaller:false}); if(!motivo) return; await performAction('activar_horario',{progid:row.clm_progbuses_progid,motivo},'Horario activado correctamente.'); state.disabledRows=state.disabledRows.filter(r=>String(r.clm_progbuses_progid)!==String(row.clm_progbuses_progid)); renderInhabilitados(); })); }
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
els.btnGuardarNuevoHorario.addEventListener('click',()=>saveNewHorario().catch(err=>showAlert('danger',err.message||'No se pudo crear el horario.'))); els.crearOrigen.addEventListener('change',updateCreatePreview); els.crearDestino.addEventListener('change',updateCreatePreview); els.crearHora.addEventListener('input',updateCreatePreview); els.btnSwapOficinas.addEventListener('click',()=>{ const o=els.crearOrigen.value; const d=els.crearDestino.value; els.crearOrigen.value=d; els.crearDestino.value=o; updateCreatePreview(); }); els.btnHistorial.addEventListener('click',()=>openHistorial().catch(err=>showAlert('danger',err.message||'No se pudo cargar el historial.'))); els.btnInhabilitados.addEventListener('click',()=>openInhabilitados().catch(err=>showAlert('danger',err.message||'No se pudieron cargar los horarios inhabilitados.'))); els.editarHoraInput.addEventListener('input',updateEditPreview); els.btnGuardarEditarHora.addEventListener('click',()=>saveEditedHora().catch(err=>showAlert('danger',err.message||'No se pudo actualizar la hora.'))); els.btnAceptarMotivo.addEventListener('click',acceptMotivo); els.busSearch.addEventListener('input',renderBusList); els.btnConfirmBus.addEventListener('click',()=>confirmBusSelection().catch(err=>showAlert('danger',err.message||'No se pudo completar la operación con el bus.')));
  fillQuickTimes(els.crearHora,els.quickTimeWrap,updateCreatePreview); fillQuickTimes(els.editarHoraInput,els.quickEditTimeWrap,updateEditPreview); updateAll();
})();
</script>

<script>
function mostrarSubmenu(id) {
  const seleccionado = document.getElementById(id);
  const estaVisible = seleccionado && seleccionado.style.display === 'flex';

  document.querySelectorAll('.subnav').forEach(el => el.style.display = 'none');

  if (!estaVisible && seleccionado) {
    seleccionado.style.display = 'flex';
  }
}
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

<script>
function toggleMenu() {
  const menu = document.querySelector('.menu-lateral');
  menu.classList.toggle('active');
}
</script>

<script>
  (function () {
    const body = document.body;
    const hideBtn = document.getElementById('btnHideSidebar');
    const showBtn = document.getElementById('sidebarShowBtn');
    const STORAGE_KEY = 'sidebarCollapsed';

    function setSidebar(collapsed) {
      body.classList.toggle('sidebar-collapsed', collapsed);
      try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch(e) {}
    }

    // Estado inicial desde localStorage (solo aplica en escritorio)
    const prefersCollapsed = (localStorage.getItem(STORAGE_KEY) === '1');
    if (window.matchMedia('(min-width: 992px)').matches && prefersCollapsed) {
      setSidebar(true);
    }

    // Eventos
    if (hideBtn) hideBtn.addEventListener('click', () => setSidebar(true));
    if (showBtn) showBtn.addEventListener('click', () => setSidebar(false));

    // Si cambias de tamaño de ventana, respeta el estado en escritorio y limpia en móvil
    window.addEventListener('resize', () => {
      if (window.matchMedia('(min-width: 992px)').matches) {
        const collapsed = (localStorage.getItem(STORAGE_KEY) === '1');
        body.classList.toggle('sidebar-collapsed', collapsed);
      } else {
        body.classList.remove('sidebar-collapsed'); // en móvil usamos tu menú responsive existente
      }
    });
  })();
</script>



</body>


</html>