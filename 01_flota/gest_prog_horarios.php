<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas   = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);

if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 10;
    $vista_actuales = ["f-proghist"];

    if (!in_array($modulo_actual, $_SESSION['permisos']) || empty(array_intersect($vista_actuales, $_SESSION['vistas']))) {
        header("Location: ../../login/none_permisos.php");
        exit();
    }
}

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
require_once("../trash/copidb_secure.php");

mysqli_report(MYSQLI_REPORT_OFF);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hist_json(bool $ok, array $payload = [], string $message = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => $ok,
        'message' => $message,
        'data'    => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function hist_db_error(mysqli $conn, string $fallback = 'Error de base de datos.'): string {
    $msg = trim((string)$conn->error);
    return $msg !== '' ? $msg : $fallback;
}

function hist_uid(): int {
    if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
        return (int) $_SESSION['id_usuario'];
    }
    if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
        return (int) $_SESSION['web_id_usuario'];
    }
    return 1;
}

function hist_build_filters(mysqli $conn, array $input): array {
    $where = [];
    $types = '';
    $params = [];

    $fechaIni = trim((string)($input['fecha_ini'] ?? ''));
    $fechaFin = trim((string)($input['fecha_fin'] ?? ''));
    $bus      = trim((string)($input['bus'] ?? ''));
    $accion   = strtoupper(trim((string)($input['accion'] ?? '')));
    $servicio = trim((string)($input['servicio'] ?? ''));
    $origen   = trim((string)($input['origen'] ?? ''));
    $destino  = trim((string)($input['destino'] ?? ''));

    if ($fechaIni !== '') {
        $where[] = "DATE(h.clm_hist_progbuses_fechaevento) >= ?";
        $types .= 's';
        $params[] = $fechaIni;
    }

    if ($fechaFin !== '') {
        $where[] = "DATE(h.clm_hist_progbuses_fechaevento) <= ?";
        $types .= 's';
        $params[] = $fechaFin;
    }

    if ($bus !== '') {
        $where[] = "(p.clm_placas_BUS LIKE ? OR p.clm_placas_PLACA LIKE ?)";
        $types .= 'ss';
        $params[] = "%{$bus}%";
        $params[] = "%{$bus}%";
    }

    if ($accion !== '' && in_array($accion, ['INSERT','UPDATE','DELETE'], true)) {
        $where[] = "UPPER(h.clm_hist_progbuses_accion) = ?";
        $types .= 's';
        $params[] = $accion;
    }

    if ($servicio !== '') {
        $where[] = "IFNULL(p.clm_placas_servicio,'') = ?";
        $types .= 's';
        $params[] = $servicio;
    }

    if ($origen !== '') {
        $where[] = "IFNULL(o1.clm_sedes_abr,'') = ?";
        $types .= 's';
        $params[] = $origen;
    }

    if ($destino !== '') {
        $where[] = "IFNULL(o2.clm_sedes_abr,'') = ?";
        $types .= 's';
        $params[] = $destino;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    return [
        'where'  => $sqlWhere,
        'types'  => $types,
        'params' => $params,
    ];
}

function hist_base_from(mysqli $conn, array $filters): array {
    $sql = "
        FROM tb_hist_progbuses h
        LEFT JOIN tb_placas p ON p.clm_placas_id = h.clm_progbuses_idplaca
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = h.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = h.clm_progbuses_idoficina_destino
        {$filters['where']}
    ";
    return [$sql, $filters['types'], $filters['params']];
}

function hist_stmt_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(hist_db_error($conn));
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error ?: hist_db_error($conn);
        $stmt->close();
        throw new RuntimeException($err);
    }

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function hist_stmt_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $rows = hist_stmt_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function hist_fetch_filters_options(mysqli $conn): array {
    $servicios = [];
    $origenes  = [];
    $destinos  = [];

    $res = $conn->query("SELECT DISTINCT IFNULL(clm_placas_servicio,'') AS servicio FROM tb_placas WHERE IFNULL(clm_placas_servicio,'') <> '' ORDER BY clm_placas_servicio ASC");
    if ($res) {
        $servicios = $res->fetch_all(MYSQLI_ASSOC);
    }

    $res = $conn->query("SELECT DISTINCT IFNULL(clm_sedes_abr,'') AS oficina FROM tb_sedes WHERE IFNULL(clm_sedes_abr,'') <> '' ORDER BY clm_sedes_abr ASC");
    if ($res) {
        $origenes = $res->fetch_all(MYSQLI_ASSOC);
        $destinos = $origenes;
    }

    return [
        'servicios' => $servicios,
        'origenes'  => $origenes,
        'destinos'  => $destinos,
    ];
}
function actual_build_filters(array $input): array {
    $where = [];
    $types = '';
    $params = [];

    $bus      = trim((string)($input['bus'] ?? ''));
    $servicio = trim((string)($input['servicio'] ?? ''));
    $origen   = trim((string)($input['origen'] ?? ''));
    $destino  = trim((string)($input['destino'] ?? ''));
    $estado   = trim((string)($input['estado_actual'] ?? ''));

    if ($bus !== '') {
        $where[] = "(p.clm_placas_BUS LIKE ? OR p.clm_placas_PLACA LIKE ?)";
        $types .= 'ss';
        $params[] = "%{$bus}%";
        $params[] = "%{$bus}%";
    }

    if ($servicio !== '') {
        $where[] = "IFNULL(p.clm_placas_servicio,'') = ?";
        $types .= 's';
        $params[] = $servicio;
    }

    if ($origen !== '') {
        $where[] = "IFNULL(o1.clm_sedes_abr,'') = ?";
        $types .= 's';
        $params[] = $origen;
    }

    if ($destino !== '') {
        $where[] = "IFNULL(o2.clm_sedes_abr,'') = ?";
        $types .= 's';
        $params[] = $destino;
    }

    if ($estado !== '' && in_array($estado, ['1', '2'], true)) {
        $where[] = "pb.clm_progbuses_estado = ?";
        $types .= 'i';
        $params[] = (int)$estado;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    return [
        'where'  => $sqlWhere,
        'types'  => $types,
        'params' => $params,
    ];
}
function hist_fetch_dashboard(mysqli $conn, array $input): array {
    $filters = hist_build_filters($conn, $input);
    [$fromSql, $types, $params] = hist_base_from($conn, $filters);

    $kpis = hist_stmt_fetch_one($conn, "
        SELECT
            COUNT(*) AS total_movimientos,
            SUM(CASE WHEN UPPER(h.clm_hist_progbuses_accion) = 'INSERT' THEN 1 ELSE 0 END) AS total_creaciones,
            SUM(CASE WHEN UPPER(h.clm_hist_progbuses_accion) = 'UPDATE' THEN 1 ELSE 0 END) AS total_actualizaciones,
            SUM(CASE WHEN UPPER(h.clm_hist_progbuses_accion) = 'DELETE' THEN 1 ELSE 0 END) AS total_eliminaciones,
            SUM(CASE WHEN UPPER(IFNULL(h.clm_progbuses_motivo,'')) LIKE '%TALLER%' THEN 1 ELSE 0 END) AS total_taller,
            COUNT(DISTINCT CASE WHEN h.clm_progbuses_idplaca IS NOT NULL THEN h.clm_progbuses_idplaca END) AS buses_involucrados,
            COUNT(DISTINCT CONCAT(
                IFNULL(h.clm_progbuses_idoficina_origen,0), '-',
                IFNULL(h.clm_progbuses_idoficina_destino,0), '-',
                IFNULL(h.clm_progbuses_horasalida,'00:00:00')
            )) AS horarios_distintos
        {$fromSql}
    ", $types, $params);

    $rankingBuses = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(p.clm_placas_BUS, 'SIN BUS') AS bus,
            IFNULL(p.clm_placas_PLACA, '—') AS placa,
            IFNULL(p.clm_placas_servicio, '—') AS servicio,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY h.clm_progbuses_idplaca, p.clm_placas_BUS, p.clm_placas_PLACA, p.clm_placas_servicio
        HAVING h.clm_progbuses_idplaca IS NOT NULL
        ORDER BY total DESC, p.clm_placas_BUS ASC
        LIMIT 10
    ", $types, $params);

    $rankingTaller = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(p.clm_placas_BUS, 'SIN BUS') AS bus,
            IFNULL(p.clm_placas_PLACA, '—') AS placa,
            COUNT(*) AS total_taller
        {$fromSql}
        " . ($filters['where'] ? " AND " : " WHERE ") . " UPPER(IFNULL(h.clm_progbuses_motivo,'')) LIKE '%TALLER%'
        GROUP BY h.clm_progbuses_idplaca, p.clm_placas_BUS, p.clm_placas_PLACA
        HAVING h.clm_progbuses_idplaca IS NOT NULL
        ORDER BY total_taller DESC, p.clm_placas_BUS ASC
        LIMIT 10
    ", $types, $params);

    $rankingHorarios = hist_stmt_fetch_all($conn, "
        SELECT
            TIME_FORMAT(h.clm_progbuses_horasalida, '%H:%i') AS hora,
            IFNULL(o1.clm_sedes_abr, '—') AS origen,
            IFNULL(o2.clm_sedes_abr, '—') AS destino,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY h.clm_progbuses_horasalida, o1.clm_sedes_abr, o2.clm_sedes_abr
        ORDER BY total DESC, h.clm_progbuses_horasalida ASC
        LIMIT 10
    ", $types, $params);

    $rankingServicios = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(p.clm_placas_servicio, 'SIN SERVICIO') AS servicio,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY p.clm_placas_servicio
        ORDER BY total DESC, servicio ASC
        LIMIT 10
    ", $types, $params);

    $movPorAccion = hist_stmt_fetch_all($conn, "
        SELECT
            UPPER(h.clm_hist_progbuses_accion) AS accion,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY UPPER(h.clm_hist_progbuses_accion)
        ORDER BY total DESC
    ", $types, $params);

    $movPorOrigen = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(o1.clm_sedes_abr, 'SIN ORIGEN') AS origen,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY o1.clm_sedes_abr
        ORDER BY total DESC, origen ASC
        LIMIT 10
    ", $types, $params);



$movPorDia = hist_stmt_fetch_all($conn, "
    SELECT
        DATE(h.clm_hist_progbuses_fechaevento) AS fecha_sql,
        DATE_FORMAT(MIN(h.clm_hist_progbuses_fechaevento), '%d/%m') AS fecha,
        COUNT(*) AS total
    {$fromSql}
    GROUP BY DATE(h.clm_hist_progbuses_fechaevento)
    ORDER BY fecha_sql ASC
    LIMIT 31
", $types, $params);

    $tabla = hist_stmt_fetch_all($conn, "
        SELECT
            h.clm_hist_progbuses_id AS hist_id,
            UPPER(h.clm_hist_progbuses_accion) AS accion,
            DATE_FORMAT(h.clm_hist_progbuses_fechaevento, '%d/%m/%Y %H:%i') AS fechaevento,
            h.clm_progbuses_progid AS progid,
            TIME_FORMAT(h.clm_progbuses_horasalida, '%H:%i') AS hora,
            IFNULL(p.clm_placas_BUS, 'SIN BUS') AS bus,
            IFNULL(p.clm_placas_PLACA, '—') AS placa,
            IFNULL(p.clm_placas_servicio, '—') AS servicio,
            IFNULL(o1.clm_sedes_abr, '—') AS origen,
            IFNULL(o2.clm_sedes_abr, '—') AS destino,
            IFNULL(h.clm_progbuses_motivo, '') AS motivo,
            IFNULL(u.usuario, '—') AS usuario
        FROM tb_hist_progbuses h
        LEFT JOIN tb_placas p ON p.clm_placas_id = h.clm_progbuses_idplaca
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = h.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = h.clm_progbuses_idoficina_destino
        LEFT JOIN tb_usuarios u ON u.id_usuario = h.clm_progbuses_idusuario
        {$filters['where']}
        ORDER BY h.clm_hist_progbuses_fechaevento DESC, h.clm_hist_progbuses_id DESC
        LIMIT 300
    ", $types, $params);

    return [
        'modo' => 'historico',
        'kpis' => [
            'total_movimientos'   => (int)($kpis['total_movimientos'] ?? 0),
            'total_creaciones'    => (int)($kpis['total_creaciones'] ?? 0),
            'total_actualizaciones'=> (int)($kpis['total_actualizaciones'] ?? 0),
            'total_eliminaciones' => (int)($kpis['total_eliminaciones'] ?? 0),
            'total_taller'        => (int)($kpis['total_taller'] ?? 0),
            'buses_involucrados'  => (int)($kpis['buses_involucrados'] ?? 0),
            'horarios_distintos'  => (int)($kpis['horarios_distintos'] ?? 0),
        ],
        'ranking_buses'     => $rankingBuses,
        'ranking_taller'    => $rankingTaller,
        'ranking_horarios'  => $rankingHorarios,
        'ranking_servicios' => $rankingServicios,
        'mov_accion'        => $movPorAccion,
        'mov_origen'        => $movPorOrigen,
        'mov_dia'           => $movPorDia,
        'tabla'             => $tabla,
        'filtros'           => hist_fetch_filters_options($conn),
    ];
}
function actual_fetch_dashboard(mysqli $conn, array $input): array {
    $filters = actual_build_filters($input);

    $fromSql = "
        FROM tb_progbuses pb
        LEFT JOIN tb_placas p ON p.clm_placas_id = pb.clm_progbuses_idplaca
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
        {$filters['where']}
    ";

    $types = $filters['types'];
    $params = $filters['params'];

    $kpis = hist_stmt_fetch_one($conn, "
        SELECT
            COUNT(*) AS total_registros,
            SUM(CASE WHEN pb.clm_progbuses_estado = 1 THEN 1 ELSE 0 END) AS activos,
            SUM(CASE WHEN pb.clm_progbuses_estado = 2 THEN 1 ELSE 0 END) AS inactivos,
            SUM(CASE WHEN pb.clm_progbuses_idplaca IS NOT NULL THEN 1 ELSE 0 END) AS con_bus,
            SUM(CASE WHEN pb.clm_progbuses_idplaca IS NULL THEN 1 ELSE 0 END) AS sin_bus,
            COUNT(DISTINCT CASE WHEN pb.clm_progbuses_idplaca IS NOT NULL THEN pb.clm_progbuses_idplaca END) AS buses_distintos
        {$fromSql}
    ", $types, $params);

    $rankingBuses = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(p.clm_placas_BUS, 'SIN BUS') AS bus,
            IFNULL(p.clm_placas_PLACA, '—') AS placa,
            IFNULL(p.clm_placas_servicio, '—') AS servicio,
            COUNT(*) AS total
        {$fromSql}
        " . ($filters['where'] ? " AND " : " WHERE ") . " pb.clm_progbuses_idplaca IS NOT NULL
        GROUP BY pb.clm_progbuses_idplaca, p.clm_placas_BUS, p.clm_placas_PLACA, p.clm_placas_servicio
        ORDER BY total DESC, p.clm_placas_BUS ASC
        LIMIT 10
    ", $types, $params);

    $rankingHorarios = hist_stmt_fetch_all($conn, "
        SELECT
            TIME_FORMAT(pb.clm_progbuses_horasalida, '%H:%i') AS hora,
            IFNULL(o1.clm_sedes_abr, '—') AS origen,
            IFNULL(o2.clm_sedes_abr, '—') AS destino,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY pb.clm_progbuses_horasalida, o1.clm_sedes_abr, o2.clm_sedes_abr
        ORDER BY total DESC, pb.clm_progbuses_horasalida ASC
        LIMIT 10
    ", $types, $params);

    $rankingServicios = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(p.clm_placas_servicio, 'SIN SERVICIO') AS servicio,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY p.clm_placas_servicio
        ORDER BY total DESC, servicio ASC
        LIMIT 10
    ", $types, $params);

    $movPorOrigen = hist_stmt_fetch_all($conn, "
        SELECT
            IFNULL(o1.clm_sedes_abr, 'SIN ORIGEN') AS origen,
            COUNT(*) AS total
        {$fromSql}
        GROUP BY o1.clm_sedes_abr
        ORDER BY total DESC, origen ASC
        LIMIT 10
    ", $types, $params);

    $tabla = hist_stmt_fetch_all($conn, "
        SELECT
            pb.clm_progbuses_progid AS progid,

            DATE_FORMAT(
                CASE
                    WHEN TIME(pb.clm_progbuses_horasalida) BETWEEN '00:00:00' AND '04:59:59'
                        THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    ELSE CURDATE()
                END,
                '%d/%m/%Y'
            ) AS fecha_operativa,

            DATE_FORMAT(
                COALESCE(pb.clm_progbuses_datetimeupdated, pb.clm_progbuses_fechacreated),
                '%d/%m/%Y %H:%i'
            ) AS fecha_gestion,

            TIME_FORMAT(pb.clm_progbuses_horasalida, '%H:%i') AS hora,
            IFNULL(p.clm_placas_BUS, 'SIN BUS') AS bus,
            IFNULL(p.clm_placas_PLACA, '—') AS placa,
            IFNULL(p.clm_placas_servicio, '—') AS servicio,
            IFNULL(o1.clm_sedes_abr, '—') AS origen,
            IFNULL(o2.clm_sedes_abr, '—') AS destino,
            IFNULL(pb.clm_progbuses_motivo, '') AS motivo,
            IFNULL(u.usuario, '—') AS usuario,
            pb.clm_progbuses_estado AS estado
        FROM tb_progbuses pb
        LEFT JOIN tb_placas p ON p.clm_placas_id = pb.clm_progbuses_idplaca
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
        LEFT JOIN tb_usuarios u ON u.id_usuario = pb.clm_progbuses_idusuario
        {$filters['where']}
        ORDER BY 
            CASE
                WHEN TIME(pb.clm_progbuses_horasalida) BETWEEN '00:00:00' AND '04:59:59' THEN 1
                ELSE 0
            END ASC,
            pb.clm_progbuses_horasalida ASC,
            o1.clm_sedes_abr ASC
        LIMIT 300
    ", $types, $params);

    return [
        'modo' => 'actual',
        'kpis' => [
            'total_registros' => (int)($kpis['total_registros'] ?? 0),
            'activos'         => (int)($kpis['activos'] ?? 0),
            'inactivos'       => (int)($kpis['inactivos'] ?? 0),
            'con_bus'         => (int)($kpis['con_bus'] ?? 0),
            'sin_bus'         => (int)($kpis['sin_bus'] ?? 0),
            'buses_distintos' => (int)($kpis['buses_distintos'] ?? 0),
        ],
        'ranking_buses'     => $rankingBuses,
        'ranking_horarios'  => $rankingHorarios,
        'ranking_servicios' => $rankingServicios,
        'mov_origen'        => $movPorOrigen,
        'tabla'             => $tabla,
        'filtros'           => hist_fetch_filters_options($conn),
    ];
}
if (isset($_GET['ajax'])) {
    try {
        $ajax = trim((string)($_GET['ajax'] ?? ''));

        if ($ajax === 'dashboard') {
            $modo = strtolower(trim((string)($_GET['modo'] ?? 'historico')));

            if ($modo === 'actual') {
                hist_json(true, actual_fetch_dashboard($conn, $_GET));
            }

            hist_json(true, hist_fetch_dashboard($conn, $_GET));
        }

        throw new RuntimeException('Acción AJAX no reconocida.');
    } catch (Throwable $e) {
        hist_json(false, [], $e->getMessage());
    }
}

$initialData = [];
$initialError = '';

try {
    $modoInicial = strtolower(trim((string)($_GET['modo'] ?? 'historico')));
    $initialData = ($modoInicial === 'actual')
        ? actual_fetch_dashboard($conn, $_GET)
        : hist_fetch_dashboard($conn, $_GET);
} catch (Throwable $e) {
    $initialError = $e->getMessage();
    $initialData = [
        'modo' => $modoInicial === 'actual' ? 'actual' : 'historico',
        'kpis' => [
            'total_movimientos'=>0,
            'total_creaciones'=>0,
            'total_actualizaciones'=>0,
            'total_eliminaciones'=>0,
            'total_taller'=>0,
            'buses_involucrados'=>0,
            'horarios_distintos'=>0,
        ],
        'ranking_buses'     => [],
        'ranking_taller'    => [],
        'ranking_horarios'  => [],
        'ranking_servicios' => [],
        'mov_accion'        => [],
        'mov_origen'        => [],
        'mov_dia'           => [],
        'tabla'             => [],
        'filtros'           => ['servicios'=>[], 'origenes'=>[], 'destinos'=>[]],
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Programación | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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


        .main-content{
            margin-left:240px;
            padding:30px;
            transition:margin-left .3s ease;
        }

        .hist-page{
            max-width:1700px;
        }

        .hist-hero{
            background:linear-gradient(135deg,#243447 0%, #30475e 55%, #3b556d 100%);
            border-radius:24px;
            color:white;
            padding:30px;
            box-shadow:0 18px 40px rgba(36,52,71,.18);
            margin-bottom:20px;
        }

        .hist-hero__title{
            font-size:2rem;
            font-weight:900;
            margin-bottom:8px;
        }

        .hist-hero__sub{
            color:#d9e6f1;
            max-width:950px;
            font-size:.98rem;
            line-height:1.7;
        }

        .hist-card{
            background:white;
            border:1px solid #e9eef5;
            border-radius:20px;
            box-shadow:0 10px 28px rgba(15,23,42,.05);
            height:100%;
        }

        .hist-card__head{
            padding:16px 18px;
            border-bottom:1px solid #eef2f7;
            font-weight:800;
            color:#243447;
            font-size:1rem;
        }

        .hist-card__body{
            padding:18px;
        }

        .kpi-box{
            background:white;
            border:1px solid #e8edf5;
            border-radius:18px;
            padding:18px;
            display:flex;
            align-items:center;
            gap:14px;
            box-shadow:0 10px 24px rgba(15,23,42,.05);
            height:100%;
        }

        .kpi-icon{
            width:54px;
            height:54px;
            border-radius:16px;
            display:grid;
            place-items:center;
            font-size:1.25rem;
            flex:0 0 auto;
        }

        .kpi-label{
            font-size:.78rem;
            text-transform:uppercase;
            color:#6b7c8f;
            font-weight:800;
            letter-spacing:.05em;
            margin-bottom:4px;
        }

        .kpi-value{
            font-size:1.65rem;
            line-height:1;
            font-weight:900;
            color:#22313f;
        }

        .filters-box{
            background:white;
            border:1px solid #e8edf5;
            border-radius:22px;
            padding:18px;
            box-shadow:0 10px 24px rgba(15,23,42,.05);
            margin-bottom:20px;
        }

        .filters-box .form-control,
        .filters-box .form-select{
            min-height:46px;
            border-radius:12px;
            border:1px solid #d8e2ec;
        }

        .chart-wrap{
            position:relative;
            min-height:320px;
        }

        .mini-ranking{
            display:flex;
            flex-direction:column;
            gap:10px;
        }

        .mini-ranking-item{
            border:1px solid #e8eef5;
            border-radius:14px;
            padding:12px 14px;
            background:#fbfdff;
        }

        .mini-ranking-top{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
        }

        .mini-ranking-title{
            font-weight:800;
            color:#243447;
        }

        .mini-ranking-meta{
            font-size:.85rem;
            color:#708193;
            margin-top:4px;
        }

        .badge-soft{
            background:#eaf3ff;
            color:#1f6fb2;
            border-radius:999px;
            padding:6px 10px;
            font-size:.75rem;
            font-weight:800;
        }

        .table-shell{
            background:white;
            border:1px solid #e8edf5;
            border-radius:22px;
            overflow:hidden;
            box-shadow:0 10px 24px rgba(15,23,42,.05);
        }

        .table-shell .table{
            margin-bottom:0;
        }

        .table-shell thead th{
            background:#243447;
            color:white;
            font-size:.86rem;
            white-space:nowrap;
            vertical-align:middle;
        }

        .table-shell tbody td{
            vertical-align:middle;
            font-size:.9rem;
        }

        .chip-action{
            border-radius:999px;
            padding:5px 10px;
            font-size:.75rem;
            font-weight:800;
            display:inline-block;
        }

        .chip-insert{ background:#e8f7ee; color:#157347; }
        .chip-update{ background:#eef4ff; color:#3558b5; }
        .chip-delete{ background:#fdecec; color:#b42318; }

        .empty-box{
            border:1px dashed #d8e0ea;
            background:#fafcff;
            color:#7a8b9a;
            border-radius:16px;
            text-align:center;
            padding:26px 18px;
            font-style:italic;
        }

        @media (max-width: 991px){
            .main-content{ margin-left:0 !important; }
        }
        .view-mode-box{
            background:linear-gradient(135deg,#ffffff 0%, #f8fbff 100%);
            border:1px solid #dfe9f3;
            border-radius:20px;
            padding:16px 18px;
            box-shadow:0 10px 24px rgba(15,23,42,.05);
            max-width:420px;
            margin-bottom:18px;
            position:relative;
            overflow:hidden;
        }

        .view-mode-box::before{
            content:'';
            position:absolute;
            top:0;
            left:0;
            width:100%;
            height:4px;
            background:linear-gradient(90deg,#1f6fb2 0%, #4f8ecf 55%, #7fb3e6 100%);
        }

        .view-mode-box__label{
            font-size:.82rem;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.05em;
            color:#4d6479;
            margin-bottom:12px;
            display:flex;
            align-items:center;
        }

        .view-mode-toggle{
            display:flex;
            gap:10px;
            background:#eef4fb;
            border:1px solid #d8e4f0;
            padding:6px;
            border-radius:16px;
        }

        .view-mode-btn{
            flex:1;
            border:none;
            background:transparent;
            color:#5f7286;
            font-weight:800;
            font-size:.95rem;
            border-radius:12px;
            min-height:46px;
            transition:all .25s ease;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
        }

        .view-mode-btn:hover{
            background:rgba(255,255,255,.7);
            color:#243447;
        }

        .view-mode-btn.active{
            background:linear-gradient(135deg,#243447 0%, #32506e 100%);
            color:#ffffff;
            box-shadow:0 8px 18px rgba(36,52,71,.20);
        }

        .view-mode-box__hint{
            margin-top:10px;
            font-size:.82rem;
            color:#6f8295;
            line-height:1.45;
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
  <a href="../01_amantenimiento\lista_cheklist.php">📝 CheckList</a>
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
    <li><a href="programacion_horarios_historial.php" class="active"><i class="bi bi-bar-chart-line-fill"></i> Historial Gerencial</a></li>
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

    <div class="container mt-4 mb-5 hist-page">
        <div id="alertZone">
            <?php if ($initialError !== ''): ?>
                <div class="alert alert-danger"><?= h($initialError) ?></div>
            <?php endif; ?>
        </div>

        <section class="hist-hero">
            <div class="hist-hero__title">
                <i class="bi bi-bar-chart-line-fill me-2"></i>Historial de Programación
            </div>
            <div class="hist-hero__sub">
                Vista de la trazabilidad histórica de la programación de horarios. Aquí podrás identificar qué buses aparecen más en programación, qué unidades registran más incidencias relacionadas a taller, qué horarios y rutas son más recurrentes.
            </div>
        </section>
        <div class="view-mode-box mb-3">
            <div class="view-mode-box__label">
                <i class="bi bi-layout-text-window-reverse me-2"></i>Modo de vista
            </div>

            <div class="view-mode-toggle" id="fModoGroup">
                <button type="button" class="view-mode-btn active" data-mode="historico" id="btnModoHistorico">
                    <i class="bi bi-clock-history me-2"></i>Histórico
                </button>

                <button type="button" class="view-mode-btn" data-mode="actual" id="btnModoActual">
                    <i class="bi bi-broadcast-pin me-2"></i>Actual
                </button>
            </div>

            <input type="hidden" id="fModo" value="historico">

            <div class="view-mode-box__hint" id="modoHint">
                Alterna entre la vista histórica y la programación actual vigente.
            </div>
        </div>
        <section class="filters-box">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Desde</label>
                    <input type="date" id="fFechaIni" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Hasta</label>
                    <input type="date" id="fFechaFin" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Bus / Placa</label>
                    <input type="text" id="fBus" class="form-control" placeholder="Ej. BUS 45 / ABC-123">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Acción</label>
                    <select id="fAccion" class="form-select">
                        <option value="">Todas</option>
                        <option value="INSERT">INSERT</option>
                        <option value="UPDATE">UPDATE</option>
                        <option value="DELETE">DELETE</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Servicio</label>
                    <select id="fServicio" class="form-select"></select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary" id="btnAplicarFiltros">
                        <i class="bi bi-funnel-fill me-2"></i>Aplicar filtros
                    </button>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Origen</label>
                    <select id="fOrigen" class="form-select"></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Destino</label>
                    <select id="fDestino" class="form-select"></select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-outline-secondary w-100" id="btnHoy">Hoy</button>
                    <button class="btn btn-outline-secondary w-100" id="btn7Dias">Últimos 7 días</button>
                    <button class="btn btn-outline-secondary w-100" id="btn30Dias">Últimos 30 días</button>
                </div>
                <div class="col-md-4 d-grid">
                    <button class="btn btn-outline-dark" id="btnLimpiarFiltros">
                        <i class="bi bi-eraser-fill me-2"></i>Limpiar filtros
                    </button>
                </div>
            </div>
        </section>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#eaf3ff;color:#1f6fb2;"><i class="bi bi-activity"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi1">Movimientos históricos</div>
                        <div class="kpi-value" id="kpiTotalMov">0</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#e8f7ee;color:#157347;"><i class="bi bi-plus-circle-fill"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi2">Creaciones</div>
                        <div class="kpi-value" id="kpiCreaciones">0</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#eef4ff;color:#3558b5;"><i class="bi bi-pencil-square"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi3">Actualizaciones</div>
                        <div class="kpi-value" id="kpiUpdates">0</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#fdecec;color:#b42318;"><i class="bi bi-tools"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi4">Eventos a taller</div>
                        <div class="kpi-value" id="kpiTaller">0</div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#f7f1ff;color:#7c3aed;"><i class="bi bi-bus-front-fill"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi5">Buses involucrados</div>
                        <div class="kpi-value" id="kpiBuses">0</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#fff6e8;color:#b86900;"><i class="bi bi-clock-fill"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi6">Horarios distintos</div>
                        <div class="kpi-value" id="kpiHorarios">0</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#f0f9ff;color:#0284c7;"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi7">Eliminaciones</div>
                        <div class="kpi-value" id="kpiDelete">0</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="kpi-box">
                    <div class="kpi-icon" style="background:#f3f4f6;color:#374151;"><i class="bi bi-diagram-3-fill"></i></div>
                    <div>
                        <div class="kpi-label" id="lblKpi8">Estado analítico</div>
                        <div class="kpi-value" id="kpiResumenTxt" style="font-size:1rem;">Operativo</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-graph-up-arrow me-2"></i>Movimientos por día</div>
                    <div class="hist-card__body">
                        <div class="chart-wrap">
                            <canvas id="chartMovDia"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-pie-chart-fill me-2"></i>Distribución por acción</div>
                    <div class="hist-card__body">
                        <div class="chart-wrap">
                            <canvas id="chartAccion"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-bus-front me-2"></i>Buses con más participación histórica</div>
                    <div class="hist-card__body">
                        <div id="rankingBusesWrap" class="mini-ranking"></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-tools me-2"></i>Buses con más entradas relacionadas a taller</div>
                    <div class="hist-card__body">
                        <div id="rankingTallerWrap" class="mini-ranking"></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-alarm-fill me-2"></i>Horarios más recurrentes</div>
                    <div class="hist-card__body">
                        <div class="chart-wrap">
                            <canvas id="chartHorarios"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-diagram-2-fill me-2"></i>Servicios con mayor movimiento</div>
                    <div class="hist-card__body">
                        <div class="chart-wrap">
                            <canvas id="chartServicios"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-12">
                <div class="hist-card">
                    <div class="hist-card__head"><i class="bi bi-geo-alt-fill me-2"></i>Oficinas de origen con más actividad</div>
                    <div class="hist-card__body">
                        <div class="chart-wrap">
                            <canvas id="chartOrigen"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section class="table-shell">
            <div class="hist-card__head"><i class="bi bi-table me-2"></i>Programación Actual Vigente</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th id="thCol1">Acción</th>
                            <th id="thCol2">Fecha</th>
                            <th id="thCol3">Horario</th>
                            <th id="thCol4">Bus</th>
                            <th id="thCol5">Placa</th>
                            <th id="thCol6">Servicio</th>
                            <th id="thCol7">Ruta</th>
                            <th id="thCol8">Usuario</th>
                            <th id="thCol9">Motivo</th>
                        </tr>
                    </thead>
                    <tbody id="tablaHistorialBody"></tbody>
                </table>
            </div>
        </section>
    </div>

    <hr>
</div>

<script>
window.histInitialData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.histInitialError = <?= json_encode($initialError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
(function(){
    const state = {
        data: window.histInitialData || {},
        charts: {}
    };

    const $ = (id) => document.getElementById(id);

    const els = {
      modoHint: $('modoHint'),
      btnModoHistorico: $('btnModoHistorico'),
      btnModoActual: $('btnModoActual'),
      thCol1: $('thCol1'),
      thCol2: $('thCol2'),
      thCol3: $('thCol3'),
      thCol4: $('thCol4'),
      thCol5: $('thCol5'),
      thCol6: $('thCol6'),
      thCol7: $('thCol7'),
      thCol8: $('thCol8'),
      thCol9: $('thCol9'),
              lblKpi1: $('lblKpi1'),
              lblKpi2: $('lblKpi2'),
              lblKpi3: $('lblKpi3'),
              lblKpi4: $('lblKpi4'),
              lblKpi5: $('lblKpi5'),
              lblKpi6: $('lblKpi6'),
              lblKpi7: $('lblKpi7'),
              lblKpi8: $('lblKpi8'),
        fModo: $('fModo'),
        alertZone: $('alertZone'),
        fFechaIni: $('fFechaIni'),
        fFechaFin: $('fFechaFin'),
        fBus: $('fBus'),
        fAccion: $('fAccion'),
        fServicio: $('fServicio'),
        fOrigen: $('fOrigen'),
        fDestino: $('fDestino'),
        btnAplicarFiltros: $('btnAplicarFiltros'),
        btnLimpiarFiltros: $('btnLimpiarFiltros'),
        btnHoy: $('btnHoy'),
        btn7Dias: $('btn7Dias'),
        btn30Dias: $('btn30Dias'),

        kpiTotalMov: $('kpiTotalMov'),
        kpiCreaciones: $('kpiCreaciones'),
        kpiUpdates: $('kpiUpdates'),
        kpiDelete: $('kpiDelete'),
        kpiTaller: $('kpiTaller'),
        kpiBuses: $('kpiBuses'),
        kpiHorarios: $('kpiHorarios'),
        kpiResumenTxt: $('kpiResumenTxt'),

        rankingBusesWrap: $('rankingBusesWrap'),
        rankingTallerWrap: $('rankingTallerWrap'),
        tablaHistorialBody: $('tablaHistorialBody'),

        chartMovDia: $('chartMovDia'),
        chartAccion: $('chartAccion'),
        chartHorarios: $('chartHorarios'),
        chartServicios: $('chartServicios'),
        chartOrigen: $('chartOrigen'),
    };

    const esc = (v) => String(v ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');

    function showAlert(type, message){
        els.alertZone.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">${esc(message)}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    }

    function setSelectOptions(select, rows, field, placeholder){
        const opts = [`<option value="">${placeholder}</option>`];
        (rows || []).forEach(r => {
            const value = r[field] || r.oficina || '';
            opts.push(`<option value="${esc(value)}">${esc(value)}</option>`);
        });
        select.innerHTML = opts.join('');
    }

    function renderFilters(){
        const filtros = state.data.filtros || {};
        setSelectOptions(els.fServicio, filtros.servicios || [], 'servicio', 'Todos');
        setSelectOptions(els.fOrigen, filtros.origenes || [], 'oficina', 'Todos');
        setSelectOptions(els.fDestino, filtros.destinos || [], 'oficina', 'Todos');

        if (els.fModo && state.data.modo) {
            els.fModo.value = state.data.modo;
        }
    }

function renderKpis(){
    const k = state.data.kpis || {};
    const modo = state.data.modo || 'historico';

    if (modo === 'actual') {
        els.kpiTotalMov.textContent = k.total_registros || 0;
        els.kpiCreaciones.textContent = k.activos || 0;
        els.kpiUpdates.textContent = k.inactivos || 0;
        els.kpiDelete.textContent = k.sin_bus || 0;
        els.kpiTaller.textContent = k.con_bus || 0;
        els.kpiBuses.textContent = k.buses_distintos || 0;
        els.kpiHorarios.textContent = k.total_registros || 0;

        let resumen = 'Programación vigente';
        if ((k.sin_bus || 0) > 0) resumen = 'Hay horarios sin unidad';
        if ((k.total_registros || 0) === 0) resumen = 'Sin registros actuales';

        els.kpiResumenTxt.textContent = resumen;
        return;
    }

    els.kpiTotalMov.textContent = k.total_movimientos || 0;
    els.kpiCreaciones.textContent = k.total_creaciones || 0;
    els.kpiUpdates.textContent = k.total_actualizaciones || 0;
    els.kpiDelete.textContent = k.total_eliminaciones || 0;
    els.kpiTaller.textContent = k.total_taller || 0;
    els.kpiBuses.textContent = k.buses_involucrados || 0;
    els.kpiHorarios.textContent = k.horarios_distintos || 0;

    let resumen = 'Operativo';
    if ((k.total_taller || 0) > 0 && (k.total_actualizaciones || 0) > 0) resumen = 'Alto dinamismo';
    if ((k.total_movimientos || 0) === 0) resumen = 'Sin datos';
    els.kpiResumenTxt.textContent = resumen;
}

    function renderMiniRanking(container, rows, formatter){
        if (!(rows || []).length) {
            container.innerHTML = `<div class="empty-box">No hay información para mostrar con los filtros actuales.</div>`;
            return;
        }

        container.innerHTML = rows.map((row, i) => formatter(row, i)).join('');
    }

    function renderRankings(){
        renderMiniRanking(els.rankingBusesWrap, state.data.ranking_buses || [], (r, i) => `
            <div class="mini-ranking-item">
                <div class="mini-ranking-top">
                    <div>
                        <div class="mini-ranking-title">#${i + 1} · ${esc(r.bus || 'SIN BUS')}</div>
                        <div class="mini-ranking-meta">Placa: ${esc(r.placa || '—')} · Servicio: ${esc(r.servicio || '—')}</div>
                    </div>
                    <span class="badge-soft">${esc(r.total || 0)} mov.</span>
                </div>
            </div>
        `);

        renderMiniRanking(els.rankingTallerWrap, state.data.ranking_taller || [], (r, i) => `
            <div class="mini-ranking-item">
                <div class="mini-ranking-top">
                    <div>
                        <div class="mini-ranking-title">#${i + 1} · ${esc(r.bus || 'SIN BUS')}</div>
                        <div class="mini-ranking-meta">Placa: ${esc(r.placa || '—')}</div>
                    </div>
                    <span class="badge-soft">${esc(r.total_taller || 0)} eventos</span>
                </div>
            </div>
        `);
    }

function renderTabla(){
    const rows = state.data.tabla || [];
    const modo = state.data.modo || 'historico';

    if (!rows.length) {
        els.tablaHistorialBody.innerHTML = `
            <tr>
                <td colspan="9">
                    <div class="empty-box my-2">No se encontraron registros con los filtros aplicados.</div>
                </td>
            </tr>
        `;
        return;
    }

if (modo === 'actual') {
    els.tablaHistorialBody.innerHTML = rows.map(r => {
        const estadoTxt = Number(r.estado) === 1 ? 'ACTIVO' : 'INACTIVO';
        const estadoClass = Number(r.estado) === 1 ? 'chip-insert' : 'chip-delete';

        return `
            <tr>
                <td><span class="chip-action ${estadoClass}">${esc(estadoTxt)}</span></td>
                <td>${esc(r.fecha_operativa || '—')}</td>
                <td><strong>${esc(r.hora || '—')}</strong></td>
                <td>${esc(r.bus || 'SIN BUS')}</td>
                <td>${esc(r.placa || '—')}</td>
                <td>${esc(r.servicio || '—')}</td>
                <td>${esc((r.origen || '—') + ' → ' + (r.destino || '—'))}</td>
                <td>${esc(r.fecha_gestion || '—')}</td>
                <td>
                    <div>${esc(r.usuario || '—')}</div>
                    <div class="text-secondary small mt-1">${esc(r.motivo || '—')}</div>
                </td>
            </tr>
        `;
    }).join('');
    return;
}

    els.tablaHistorialBody.innerHTML = rows.map(r => {
        let chipClass = 'chip-update';
        if ((r.accion || '').toUpperCase() === 'INSERT') chipClass = 'chip-insert';
        if ((r.accion || '').toUpperCase() === 'DELETE') chipClass = 'chip-delete';

        return `
            <tr>
                <td><span class="chip-action ${chipClass}">${esc(r.accion || '—')}</span></td>
                <td>${esc(r.fechaevento || '—')}</td>
                <td><strong>${esc(r.hora || '—')}</strong></td>
                <td>${esc(r.bus || 'SIN BUS')}</td>
                <td>${esc(r.placa || '—')}</td>
                <td>${esc(r.servicio || '—')}</td>
                <td>${esc((r.origen || '—') + ' → ' + (r.destino || '—'))}</td>
                <td>${esc(r.usuario || '—')}</td>
                <td>${esc(r.motivo || '—')}</td>
            </tr>
        `;
    }).join('');
}

    function destroyChart(name){
        if (state.charts[name]) {
            state.charts[name].destroy();
            state.charts[name] = null;
        }
    }

    function buildChart(name, canvas, type, labels, data, label){
        destroyChart(name);
        state.charts[name] = new Chart(canvas, {
            type,
            data: {
                labels,
                datasets: [{
                    label,
                    data,
                    borderWidth: 2,
                    borderRadius: 8,
                    tension: 0.3,
                    fill: type === 'line'
                }]
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: { display: type !== 'bar' ? true : false }
                },
                scales: type === 'doughnut' ? {} : {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

function renderCharts(){
    const modo = state.data.modo || 'historico';

    if (modo === 'actual') {
        destroyChart('movDia');
        destroyChart('accion');
        destroyChart('horarios');
        destroyChart('servicios');
        destroyChart('origen');

        const horarios = state.data.ranking_horarios || [];
        buildChart(
            'horarios',
            els.chartHorarios,
            'bar',
            horarios.map(x => `${x.hora} · ${x.origen}→${x.destino}`),
            horarios.map(x => Number(x.total || 0)),
            'Frecuencia actual'
        );

        const servicios = state.data.ranking_servicios || [];
        buildChart(
            'servicios',
            els.chartServicios,
            'bar',
            servicios.map(x => x.servicio),
            servicios.map(x => Number(x.total || 0)),
            'Servicios actuales'
        );

        const origenes = state.data.mov_origen || [];
        buildChart(
            'origen',
            els.chartOrigen,
            'bar',
            origenes.map(x => x.origen),
            origenes.map(x => Number(x.total || 0)),
            'Origen actual'
        );

        // gráfico de buses actuales
        const buses = state.data.ranking_buses || [];
        buildChart(
            'movDia',
            els.chartMovDia,
            'bar',
            buses.map(x => x.bus),
            buses.map(x => Number(x.total || 0)),
            'Buses programados'
        );

        // donut de estados actuales
        const k = state.data.kpis || {};
        buildChart(
            'accion',
            els.chartAccion,
            'doughnut',
            ['Activos', 'Inactivos', 'Sin bus', 'Con bus'],
            [
                Number(k.activos || 0),
                Number(k.inactivos || 0),
                Number(k.sin_bus || 0),
                Number(k.con_bus || 0)
            ],
            'Estado actual'
        );

        return;
    }

    const movDia = state.data.mov_dia || [];
    buildChart(
        'movDia',
        els.chartMovDia,
        'line',
        movDia.map(x => x.fecha),
        movDia.map(x => Number(x.total || 0)),
        'Movimientos'
    );

    const movAccion = state.data.mov_accion || [];
    buildChart(
        'accion',
        els.chartAccion,
        'doughnut',
        movAccion.map(x => x.accion),
        movAccion.map(x => Number(x.total || 0)),
        'Acciones'
    );

    const horarios = state.data.ranking_horarios || [];
    buildChart(
        'horarios',
        els.chartHorarios,
        'bar',
        horarios.map(x => `${x.hora} · ${x.origen}→${x.destino}`),
        horarios.map(x => Number(x.total || 0)),
        'Frecuencia'
    );

    const servicios = state.data.ranking_servicios || [];
    buildChart(
        'servicios',
        els.chartServicios,
        'bar',
        servicios.map(x => x.servicio),
        servicios.map(x => Number(x.total || 0)),
        'Movimientos'
    );

    const origenes = state.data.mov_origen || [];
    buildChart(
        'origen',
        els.chartOrigen,
        'bar',
        origenes.map(x => x.origen),
        origenes.map(x => Number(x.total || 0)),
        'Movimientos'
    );
}
function renderHero(){
    const modo = state.data.modo || 'historico';
    const title = document.querySelector('.hist-hero__title');
    const sub = document.querySelector('.hist-hero__sub');

    if (modo === 'actual') {
        title.innerHTML = `<i class="bi bi-broadcast-pin me-2"></i>Programación Actual de Horarios`;
        sub.textContent = 'Vista ejecutiva de la programación vigente registrada actualmente en la pizarra. Aquí podrás ver qué horarios están activos, cuáles no tienen unidad asignada y cómo está distribuida la operación actual.';
        return;
    }

    title.innerHTML = `<i class="bi bi-bar-chart-line-fill me-2"></i>Historial de Programación`;
    sub.textContent = 'Vista de la trazabilidad histórica de la programación de horarios. Aquí podrás identificar qué buses aparecen más en programación, qué unidades registran más incidencias relacionadas a taller, qué horarios y rutas son más recurrentes.';
}

function renderKpiLabels(){
    const modo = state.data.modo || 'historico';

    if (modo === 'actual') {
        els.lblKpi1.textContent = 'Registros actuales';
        els.lblKpi2.textContent = 'Horarios activos';
        els.lblKpi3.textContent = 'Horarios inactivos';
        els.lblKpi4.textContent = 'Horarios con bus';
        els.lblKpi5.textContent = 'Buses distintos';
        els.lblKpi6.textContent = 'Total horarios';
        els.lblKpi7.textContent = 'Sin bus';
        els.lblKpi8.textContent = 'Estado actual';
        return;
    }

    els.lblKpi1.textContent = 'Movimientos históricos';
    els.lblKpi2.textContent = 'Creaciones';
    els.lblKpi3.textContent = 'Actualizaciones';
    els.lblKpi4.textContent = 'Eventos a taller';
    els.lblKpi5.textContent = 'Buses involucrados';
    els.lblKpi6.textContent = 'Horarios distintos';
    els.lblKpi7.textContent = 'Eliminaciones';
    els.lblKpi8.textContent = 'Estado analítico';
}
function renderModeControls(){
    const modo = state.data.modo || 'historico';
    const esActual = modo === 'actual';

    els.fFechaIni.disabled = esActual;
    els.fFechaFin.disabled = esActual;
    els.fAccion.disabled = esActual;
    els.btnHoy.disabled = esActual;
    els.btn7Dias.disabled = esActual;
    els.btn30Dias.disabled = esActual;
}

function renderTableHeaders(){
    const modo = state.data.modo || 'historico';

    if (modo === 'actual') {
        els.thCol1.textContent = 'Estado';
        els.thCol2.textContent = 'Fecha operativa';
        els.thCol3.textContent = 'Horario';
        els.thCol4.textContent = 'Bus';
        els.thCol5.textContent = 'Placa';
        els.thCol6.textContent = 'Servicio';
        els.thCol7.textContent = 'Ruta';
        els.thCol8.textContent = 'Fecha gestión';
        els.thCol9.textContent = 'Usuario / Motivo';
        return;
    }

    els.thCol1.textContent = 'Acción';
    els.thCol2.textContent = 'Fecha';
    els.thCol3.textContent = 'Horario';
    els.thCol4.textContent = 'Bus';
    els.thCol5.textContent = 'Placa';
    els.thCol6.textContent = 'Servicio';
    els.thCol7.textContent = 'Ruta';
    els.thCol8.textContent = 'Usuario';
    els.thCol9.textContent = 'Motivo';
}
function renderModeHint(){
    const modo = state.data.modo || 'historico';

    if (modo === 'actual') {
        els.modoHint.textContent = 'Estás viendo la programación vigente registrada actualmente en la pizarra operativa del sistema.';
        return;
    }

    els.modoHint.textContent = 'Estás viendo la trazabilidad histórica de movimientos y cambios de programación.';
}
function renderModeButtons(){
    const modo = state.data.modo || 'historico';

    els.btnModoHistorico.classList.toggle('active', modo === 'historico');
    els.btnModoActual.classList.toggle('active', modo === 'actual');

    if (els.fModo) {
        els.fModo.value = modo;
    }
}
function renderAll(){
    renderFilters();
    renderModeButtons();
    renderHero();
    renderModeControls();
    renderModeHint();
    renderKpiLabels();
    renderTableHeaders();
    renderKpis();
    renderRankings();
    renderTabla();
    renderCharts();
}
    function getQueryString(){
        const qs = new URLSearchParams();
        if (els.fModo.value) qs.append('modo', els.fModo.value);
        if (els.fFechaIni.value) qs.append('fecha_ini', els.fFechaIni.value);
        if (els.fFechaFin.value) qs.append('fecha_fin', els.fFechaFin.value);
        if (els.fBus.value.trim()) qs.append('bus', els.fBus.value.trim());
        if (els.fAccion.value) qs.append('accion', els.fAccion.value);
        if (els.fServicio.value) qs.append('servicio', els.fServicio.value);
        if (els.fOrigen.value) qs.append('origen', els.fOrigen.value);
        if (els.fDestino.value) qs.append('destino', els.fDestino.value);
        return qs.toString();
    }

    async function fetchDashboard(){
        const query = getQueryString();
        const resp = await fetch(`${window.location.pathname}?ajax=dashboard&${query}`, { credentials:'same-origin' });
        const json = await resp.json();
        if (!json.ok) throw new Error(json.message || 'No se pudo cargar el dashboard.');
        state.data = json.data || {};
        renderAll();
    }

    function setRange(days){
        const today = new Date();
        const end = today.toISOString().split('T')[0];
        const start = new Date();
        start.setDate(today.getDate() - days);
        els.fFechaIni.value = start.toISOString().split('T')[0];
        els.fFechaFin.value = end;
    }

    els.btnModoHistorico.addEventListener('click', () => {
        if (els.fModo.value === 'historico') return;
        els.fModo.value = 'historico';
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo cambiar el modo de vista.'));
    });

    els.btnModoActual.addEventListener('click', () => {
        if (els.fModo.value === 'actual') return;
        els.fModo.value = 'actual';
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo cambiar el modo de vista.'));
    });

    els.btnAplicarFiltros.addEventListener('click', () => {
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo aplicar filtros.'));
    });

    els.btnLimpiarFiltros.addEventListener('click', () => {
        els.fFechaIni.value = '';
        els.fFechaFin.value = '';
        els.fBus.value = '';
        els.fAccion.value = '';
        els.fServicio.value = '';
        els.fOrigen.value = '';
        els.fDestino.value = '';
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo limpiar filtros.'));
    });

    els.btnHoy.addEventListener('click', () => {
        setRange(0);
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo aplicar rango.'));
    });

    els.btn7Dias.addEventListener('click', () => {
        setRange(7);
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo aplicar rango.'));
    });

    els.btn30Dias.addEventListener('click', () => {
        setRange(30);
        fetchDashboard().catch(err => showAlert('danger', err.message || 'No se pudo aplicar rango.'));
    });

    renderAll();
})();
</script>



<!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
<a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
</a>




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