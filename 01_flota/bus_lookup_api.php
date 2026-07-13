<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    bl_json(false, [], 'No autorizado.', 401);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

mysqli_report(MYSQLI_REPORT_OFF);

function bl_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data], $flags);
    exit();
}

function bl_text($value): string {
    return trim((string)($value ?? ''));
}

function bl_db_error(mysqli $conn): string {
    $msg = trim((string)$conn->error);
    return $msg !== '' ? $msg : 'Error de base de datos.';
}

function bl_fetch_all(mysqli_stmt $stmt): array {
    if (!$stmt->execute()) {
        $err = $stmt->error ?: 'No se pudo ejecutar la consulta.';
        $stmt->close();
        throw new RuntimeException($err);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function bl_fetch_one(mysqli_stmt $stmt): ?array {
    $rows = bl_fetch_all($stmt);
    return $rows[0] ?? null;
}

function bl_session_has_module(int $module): bool {
    if (($_SESSION['web_rol'] ?? '') === 'Admin') {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') {
        return true;
    }

    return in_array($module, array_map('intval', (array)$permisos), true);
}

function bl_can_lookup_bus(): bool {
    return ($_SESSION['web_rol'] ?? '') === 'Admin'
        || bl_session_has_module(10)
        || bl_session_has_module(5);
}

function bl_column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function bl_sede_map(mysqli $conn): array {
    $map = [];
    $res = $conn->query("SELECT clm_sedes_id, COALESCE(NULLIF(clm_sedes_abr, ''), clm_sedes_name, CONCAT('Sede ', clm_sedes_id)) AS nombre FROM tb_sedes");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int)$row['clm_sedes_id']] = bl_text($row['nombre']);
        }
    }
    return $map;
}

function bl_route_names(?string $ruta, array $sedeMap): string {
    $ruta = bl_text($ruta);
    if ($ruta === '') {
        return '';
    }

    $names = [];
    foreach (explode(',', $ruta) as $idRaw) {
        $id = (int)trim($idRaw);
        if ($id > 0) {
            $names[] = $sedeMap[$id] ?? ('Sede ' . $id);
        }
    }

    return implode(' -> ', $names);
}

function bl_format_bus(array $row): array {
    return [
        'id' => (int)($row['id_bus'] ?? $row['id'] ?? 0),
        'id_bus' => (int)($row['id_bus'] ?? $row['id'] ?? 0),
        'nombre' => bl_text($row['bus'] ?? $row['nombre'] ?? ''),
        'bus' => bl_text($row['bus'] ?? $row['nombre'] ?? ''),
        'placa' => bl_text($row['placa'] ?? ''),
        'dueno' => bl_text($row['dueno'] ?? ''),
        'tipo' => bl_text($row['tipo'] ?? ''),
        'servicio' => bl_text($row['servicio'] ?? ''),
        'estado' => bl_text($row['estado'] ?? ''),
        'kilometraje' => bl_text($row['kilometraje'] ?? ''),
    ];
}

function bl_search_buses(mysqli $conn, string $q): array {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT clm_placas_id AS id_bus,
               IFNULL(clm_placas_BUS, '') AS bus,
               IFNULL(clm_placas_PLACA, '') AS placa,
               IFNULL(`clm_placas_DUEÑO`, '') AS dueno,
               IFNULL(`clm_placas_TIPO_VEHÍCULO`, '') AS tipo,
               IFNULL(clm_placas_SERVICIO, '') AS servicio,
               IFNULL(clm_placas_ESTADO, '') AS estado,
               IFNULL(clm_placas_KILOMETRAJE, '') AS kilometraje
        FROM tb_placas
        WHERE UPPER(TRIM(IFNULL(clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND (clm_placas_BUS LIKE ? OR clm_placas_PLACA LIKE ? OR `clm_placas_DUEÑO` LIKE ?)
        ORDER BY CAST(clm_placas_BUS AS UNSIGNED) ASC, clm_placas_BUS ASC, clm_placas_PLACA ASC
        LIMIT 20
    ");
    if (!$stmt) {
        throw new RuntimeException(bl_db_error($conn));
    }

    $stmt->bind_param('sss', $like, $like, $like);
    return array_map('bl_format_bus', bl_fetch_all($stmt));
}

function bl_fetch_bus(mysqli $conn, int $busId): ?array {
    $stmt = $conn->prepare("
        SELECT clm_placas_id AS id_bus,
               IFNULL(clm_placas_BUS, '') AS bus,
               IFNULL(clm_placas_PLACA, '') AS placa,
               IFNULL(`clm_placas_DUEÑO`, '') AS dueno,
               IFNULL(`clm_placas_TIPO_VEHÍCULO`, '') AS tipo,
               IFNULL(clm_placas_SERVICIO, '') AS servicio,
               IFNULL(clm_placas_ESTADO, '') AS estado,
               IFNULL(clm_placas_KILOMETRAJE, '') AS kilometraje
        FROM tb_placas
        WHERE clm_placas_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException(bl_db_error($conn));
    }

    $stmt->bind_param('i', $busId);
    $row = bl_fetch_one($stmt);
    return $row ? bl_format_bus($row) : null;
}

function bl_fetch_conductores(mysqli $conn, int $busId): array {
    $stmt = $conn->prepare("
        SELECT pc.clm_progconductores_progid AS progid,
               pc.clm_progconductores_idconductor AS id_conductor,
               DATE_FORMAT(COALESCE(pc.clm_progconductores_datetimeupdated, pc.clm_progconductores_fechacreated), '%d/%m/%Y %H:%i') AS fecha_asignacion,
               IFNULL(t.clm_tra_nombres, '') AS conductor,
               IFNULL(t.clm_tra_dni, '') AS dni,
               IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia,
               IFNULL(t.clm_tra_celular, '') AS celular
        FROM tb_progconductores pc
        LEFT JOIN tb_trabajador t ON t.clm_tra_id = pc.clm_progconductores_idconductor
        WHERE pc.clm_progconductores_estado = 1
          AND pc.clm_progconductores_tipoprog = 1
          AND pc.clm_progconductores_idplaca = ?
        ORDER BY pc.clm_progconductores_progid ASC
    ");
    if (!$stmt) {
        throw new RuntimeException(bl_db_error($conn));
    }

    $stmt->bind_param('i', $busId);
    return bl_fetch_all($stmt);
}

function bl_fetch_horarios(mysqli $conn, int $busId): array {
    $sedeMap = bl_sede_map($conn);
    $comentarioExpr = bl_column_exists($conn, 'tb_progbuses', 'clm_progbuses_comentario')
        ? "IFNULL(pb.clm_progbuses_comentario, '')"
        : "''";

    $stmt = $conn->prepare("
        SELECT pb.clm_progbuses_progid AS progid,
               pb.clm_progbuses_idoficina_origen AS id_origen,
               pb.clm_progbuses_idoficina_destino AS id_destino,
               pb.clm_progbuses_ruta AS ruta_ids,
               pb.clm_progbuses_horasalida AS hora,
               DATE_FORMAT(COALESCE(pb.clm_progbuses_datetimeupdated, pb.clm_progbuses_fechacreated), '%d/%m/%Y %H:%i') AS fecha_programacion,
               DATE_FORMAT(
                   CASE
                       WHEN TIME(pb.clm_progbuses_horasalida) BETWEEN '00:00:00' AND '04:59:59'
                           THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                       ELSE CURDATE()
                   END,
                   '%d/%m/%Y'
               ) AS fecha_operativa,
               DATE_FORMAT(pb.clm_progbuses_datetimeupdated, '%d/%m/%Y %H:%i') AS fecha_actualizacion,
               IFNULL(o1.clm_sedes_abr, '') AS origen,
               IFNULL(o2.clm_sedes_abr, '') AS destino,
               {$comentarioExpr} AS comentario
        FROM tb_progbuses pb
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
        WHERE pb.clm_progbuses_estado = 1
          AND pb.clm_progbuses_idplaca = ?
        ORDER BY pb.clm_progbuses_horasalida ASC, pb.clm_progbuses_progid ASC
    ");
    if (!$stmt) {
        throw new RuntimeException(bl_db_error($conn));
    }

    $stmt->bind_param('i', $busId);
    $rows = bl_fetch_all($stmt);
    foreach ($rows as &$row) {
        $row['ruta_texto'] = bl_route_names($row['ruta_ids'] ?? '', $sedeMap);
    }
    unset($row);
    return $rows;
}

function bl_fetch_checklists(mysqli $conn, int $busId): array {
    $stmt = $conn->prepare("
        SELECT c.clm_checklist_id AS id,
               c.clm_checklist_corr AS corr,
               c.clm_checklist_idtipo AS tipo_id,
               IFNULL(t.clm_checktip_nombre, CONCAT('Tipo ', c.clm_checklist_idtipo)) AS tipo,
               c.clm_checklist_fecha AS fecha,
               c.clm_checklist_hora AS hora,
               c.clm_checklist_estado AS estado,
               c.clm_checklist_responsable AS responsable,
               c.clm_checklist_observaciones AS observaciones
        FROM tb_checklist_limpieza c
        LEFT JOIN tb_checklist_tipos t ON t.clm_checktip_id = c.clm_checklist_idtipo
        WHERE c.clm_checklist_id_bus = ?
        ORDER BY c.clm_checklist_fecha DESC, c.clm_checklist_hora DESC, c.clm_checklist_id DESC
        LIMIT 8
    ");
    if (!$stmt) {
        throw new RuntimeException(bl_db_error($conn));
    }

    $stmt->bind_param('i', $busId);
    return bl_fetch_all($stmt);
}

function bl_fetch_ultima_fumigacion(mysqli $conn, int $busId): ?array {
    $stmt = $conn->prepare("
        SELECT c.clm_checklist_id AS id,
               c.clm_checklist_corr AS corr,
               c.clm_checklist_fecha AS fecha,
               c.clm_checklist_hora AS hora,
               c.clm_checklist_estado AS estado,
               r.clm_resultado_dfecd AS fecha_fumigacion
        FROM tb_checklist_limpieza c
        LEFT JOIN tb_resultados_checklist r ON r.clm_resultado_id_checklist = c.clm_checklist_id
        LEFT JOIN tb_items_checklist i ON i.clm_item_id = r.clm_resultado_id_item AND i.clm_items_tipo = 'H'
        WHERE c.clm_checklist_idtipo = 4
          AND c.clm_checklist_id_bus = ?
        ORDER BY COALESCE(r.clm_resultado_dfecd, CONCAT(c.clm_checklist_fecha, ' ', c.clm_checklist_hora)) DESC,
                 c.clm_checklist_fecha DESC,
                 c.clm_checklist_hora DESC
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException(bl_db_error($conn));
    }

    $stmt->bind_param('i', $busId);
    $row = bl_fetch_one($stmt);
    if (!$row) {
        return null;
    }

    $fechaBase = bl_text($row['fecha_fumigacion']) ?: trim(bl_text($row['fecha']) . ' ' . bl_text($row['hora']));
    $dias = null;
    $vigencia = 'Sin fecha valida';

    try {
        $dt = new DateTime($fechaBase);
        $hoy = new DateTime('today');
        $dias = $dt->diff($hoy)->days;
        $vigencia = $dias <= 15 ? 'Vigente' : 'Vencida';
    } catch (Throwable $e) {
    }

    $row['fecha_fumigacion'] = $fechaBase;
    $row['dias'] = $dias;
    $row['vigencia'] = $vigencia;
    return $row;
}

function bl_detail(mysqli $conn, int $busId): array {
    $bus = bl_fetch_bus($conn, $busId);
    if (!$bus) {
        bl_json(false, [], 'Unidad no encontrada.', 404);
    }

    $conductores = bl_fetch_conductores($conn, $busId);
    $horarios = bl_fetch_horarios($conn, $busId);
    $checklists = bl_fetch_checklists($conn, $busId);

    return [
        'mode' => 'detail',
        'bus' => $bus,
        'programacion' => [
            'conductores' => $conductores,
            'horarios' => $horarios,
            'checklists' => $checklists,
            'ultima_fumigacion' => bl_fetch_ultima_fumigacion($conn, $busId),
        ],
        'resumen' => [
            'conductores' => count($conductores),
            'horarios' => count($horarios),
            'checklists' => count($checklists),
        ],
    ];
}

if (!bl_can_lookup_bus()) {
    bl_json(false, [], 'No tienes permiso para consultar unidades.', 403);
}

try {
    $busId = isset($_GET['id_bus']) ? (int)$_GET['id_bus'] : 0;
    if ($busId > 0) {
        bl_json(true, bl_detail($conn, $busId));
    }

    $q = bl_text($_GET['q'] ?? '');
    if (mb_strlen($q, 'UTF-8') < 2) {
        bl_json(false, [], 'Escribe al menos 2 caracteres para buscar.', 422);
    }

    $matches = bl_search_buses($conn, $q);
    if (!$matches) {
        bl_json(false, [], 'No se encontraron unidades.', 404);
    }

    if (count($matches) === 1) {
        bl_json(true, bl_detail($conn, (int)$matches[0]['id_bus']));
    }

    bl_json(true, [
        'mode' => 'suggestions',
        'sugerencias' => $matches,
    ]);
} catch (Throwable $e) {
    bl_json(false, [], $e->getMessage(), 500);
}
