<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

mysqli_report(MYSQLI_REPORT_OFF);

function cr_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function cr_is_admin(): bool {
    return ($_SESSION['web_rol'] ?? '') === 'Admin';
}

function cr_session_has_module(int $module): bool {
    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') return true;
    return in_array($module, array_map('intval', (array)$permisos), true);
}

function cr_can_view_tipo(int $tipo): bool {
    if (cr_is_admin()) return true;
    if (!cr_session_has_module(5)) return false;

    $permisos = $_SESSION['permisos'] ?? [];
    $vistas = $permisos === 'all' ? ['c-limp', 'c-sab', 'c-lalu'] : (array)($_SESSION['vistas'] ?? []);
    $map = [
        1 => ['c-limp', 'c-lalu'],
        2 => ['c-sab'],
        3 => ['c-lalu'],
        4 => ['c-lalu'],
    ];

    foreach (($map[$tipo] ?? []) as $vista) {
        if (in_array($vista, $vistas, true)) return true;
    }
    return false;
}

function cr_require_admin(): void {
    if (!cr_is_admin()) {
        cr_json(false, [], 'Esta consulta es solo para administradores.', 403);
    }
}

function cr_db_error(mysqli $conn): string {
    $msg = trim((string)$conn->error);
    return $msg !== '' ? $msg : 'Error de base de datos.';
}

function cr_column_exists(mysqli $conn, string $table, string $column): bool {
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

function cr_result_doc_expr(mysqli $conn, string $alias = 'r'): string {
    static $hasDoc = null;
    if ($hasDoc === null) {
        $hasDoc = cr_column_exists($conn, 'tb_resultados_checklist', 'clm_rescheck_doc');
    }
    return $hasDoc ? "{$alias}.clm_rescheck_doc" : "''";
}

function cr_fetch_all(mysqli_stmt $stmt): array {
    if (!$stmt->execute()) {
        $err = $stmt->error ?: 'No se pudo ejecutar la consulta.';
        $stmt->close();
        throw new RuntimeException($err);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cr_fetch_one(mysqli_stmt $stmt): ?array {
    $rows = cr_fetch_all($stmt);
    return $rows[0] ?? null;
}

function cr_date_or_default(?string $date, string $default): string {
    $date = trim((string)$date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $default;
}

function cr_text($value): string {
    return trim((string)($value ?? ''));
}

function cr_item_value(array $row): string {
    $tipo = cr_text($row['clm_items_tipo'] ?? '');
    if (in_array($tipo, ['R', 'E', 'Q'], true)) return cr_text($row['clm_resultado_estado'] ?? '');
    if ($tipo === 'H') return cr_text($row['clm_resultado_dfecd'] ?? '');
    if ($tipo === 'T' || $tipo === 'O') return cr_text($row['clm_rescheck_conductor1'] ?? '');
    if ($tipo === 'N') return cr_text($row['clm_rescheck_porcentaje1'] ?? '');
    if ($tipo === 'F') return !empty($row['clm_rescheck_imagen']) ? 'Foto registrada' : '';
    if ($tipo === 'D') return cr_text($row['clm_rescheck_doc'] ?? '');
    return '';
}

function cr_item_answered(array $row): bool {
    return cr_item_value($row) !== '';
}

function cr_item_metric(array $row): array {
    $tipo = strtoupper(cr_text($row['clm_items_tipo'] ?? ''));
    $valor = cr_item_value($row);
    $valorUpper = strtoupper($valor);

    if (in_array($tipo, ['R', 'E', 'Q'], true)) {
        if ($valorUpper === 'C') return ['label' => 'Conforme', 'value' => $valor, 'status' => 'ok'];
        if ($valorUpper === 'NC') return ['label' => 'No conforme', 'value' => $valor, 'status' => 'bad'];
        if ($valorUpper === 'NA') return ['label' => 'No aplica', 'value' => $valor, 'status' => 'neutral'];
        if ($valorUpper === 'P') return ['label' => 'Presente', 'value' => $valor, 'status' => 'ok'];
        if ($valorUpper !== '') return ['label' => 'Registrado', 'value' => $valor, 'status' => 'ok'];
        return ['label' => 'Pendiente', 'value' => 'Sin respuesta', 'status' => 'warn'];
    }

    if ($tipo === 'N') {
        if ($valor === '') return ['label' => 'Pendiente', 'value' => 'Sin valor', 'status' => 'warn'];
        $numero = (float)$valor;
        return [
            'label' => $numero > 0.5 ? 'No apto' : 'Apto',
            'value' => $valor,
            'status' => $numero > 0.5 ? 'bad' : 'ok',
        ];
    }

    if ($tipo === 'H') {
        return $valor !== ''
            ? ['label' => 'Fecha registrada', 'value' => $valor, 'status' => 'ok']
            : ['label' => 'Pendiente', 'value' => 'Sin fecha', 'status' => 'warn'];
    }

    if ($tipo === 'F') {
        return $valor !== ''
            ? ['label' => 'Evidencia registrada', 'value' => $valor, 'status' => 'ok']
            : ['label' => 'Sin evidencia', 'value' => 'Sin foto', 'status' => 'warn'];
    }

    if ($valor !== '') {
        return ['label' => 'Registrado', 'value' => $valor, 'status' => 'ok'];
    }

    return ['label' => 'Pendiente', 'value' => 'Sin respuesta', 'status' => 'warn'];
}

function cr_fetch_checklist_items_flat(mysqli $conn, int $checklistId, int $tipoId): array {
    $stmt = $conn->prepare("
        SELECT i.clm_item_id, i.clm_item_nombre, i.clm_items_tipo,
               r.clm_resultado_estado, r.clm_resultado_dfecd, r.clm_rescheck_conductor1,
               r.clm_rescheck_porcentaje1, r.clm_rescheck_imagen,
               " . cr_result_doc_expr($conn, 'r') . " AS clm_rescheck_doc
        FROM tb_items_checklist i
        INNER JOIN tb_categorias_checklist c
            ON c.clm_categoria_id = i.clm_item_id_categoria
        LEFT JOIN tb_resultados_checklist r
            ON r.clm_resultado_id_item = i.clm_item_id
           AND r.clm_resultado_id_checklist = ?
        WHERE i.clm_item_estado = 'activo'
          AND c.clm_categorias_estado = 'activo'
          AND i.clm_item_idtipocheck = ?
        ORDER BY i.clm_item_id ASC
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('ii', $checklistId, $tipoId);
    return cr_fetch_all($stmt);
}

function cr_completion_for_checklist(mysqli $conn, int $checklistId, int $tipoId): array {
    $rows = cr_fetch_checklist_items_flat($conn, $checklistId, $tipoId);

    $total = count($rows);
    $respondidos = 0;
    foreach ($rows as $row) {
        if (cr_item_answered($row)) $respondidos++;
    }
    $porcentaje = $total > 0 ? round(($respondidos / $total) * 100, 2) : 0;

    return [
        'total' => $total,
        'respondidos' => $respondidos,
        'porcentaje' => $porcentaje,
        'estado' => ($total > 0 && $respondidos >= $total) ? 'Completo' : 'Incompleto',
    ];
}

function cr_kpi_for_checklist(mysqli $conn, int $checklistId, int $tipoId, ?int $busId = null): array {
    $rows = cr_fetch_checklist_items_flat($conn, $checklistId, $tipoId);
    $totalItems = count($rows);
    $totalConforme = 0;

    foreach ($rows as $row) {
        if (strtoupper(cr_text($row['clm_resultado_estado'] ?? '')) === 'C') {
            $totalConforme++;
        }
    }

    if ($tipoId === 1 || $tipoId === 2) {
        $porcentaje = $totalItems > 0 ? round(($totalConforme / $totalItems) * 100, 2) : 0;
        $texto = $porcentaje > 70 ? 'EXCELENTE' : ($porcentaje >= 50 ? 'ACEPTABLE' : 'DEFICIENTE');
        return [
            'titulo' => $tipoId === 1 ? 'Estado de Limpieza de la Unidad' : 'Estado de Servicio a Bordo / Embarque',
            'texto' => $texto,
            'valor' => number_format($porcentaje, 2) . '%',
            'porcentaje' => $porcentaje,
            'estado' => $porcentaje > 70 ? 'ok' : ($porcentaje >= 50 ? 'warn' : 'bad'),
            'detalle' => [
                ['label' => 'Items conformes', 'value' => $totalConforme],
                ['label' => 'Items evaluados', 'value' => $totalItems],
            ],
        ];
    }

    if ($tipoId === 3) {
        $detalle = [];
        foreach ($rows as $row) {
            if (strtoupper(cr_text($row['clm_items_tipo'] ?? '')) !== 'N') continue;
            $valor = cr_text($row['clm_rescheck_porcentaje1'] ?? '');
            if ($valor === '') continue;
            $numero = (float)$valor;
            $detalle[] = [
                'label' => cr_text($row['clm_item_nombre'] ?? 'Alcoholimetro'),
                'value' => $valor,
                'estado' => $numero > 0.5 ? 'NO APTO' : 'APTO',
                'status' => $numero > 0.5 ? 'bad' : 'ok',
            ];
        }
        $noAptos = count(array_filter($detalle, fn($item) => ($item['status'] ?? '') === 'bad'));
        return [
            'titulo' => 'Prueba Alcoholimetria',
            'texto' => $noAptos > 0 ? 'OBSERVADO' : 'APTO',
            'valor' => count($detalle) . ' resultado(s)',
            'porcentaje' => null,
            'estado' => $noAptos > 0 ? 'bad' : 'ok',
            'detalle' => $detalle,
        ];
    }

    if ($tipoId === 4) {
        $fumigacion = $busId ? cr_fetch_ultima_fumigacion($conn, $busId) : null;
        return [
            'titulo' => 'Ultima Fumigacion Realizada',
            'texto' => $fumigacion ? $fumigacion['vigencia'] : 'Sin registro',
            'valor' => $fumigacion ? $fumigacion['fecha_fumigacion'] : 'Sin registros validos',
            'porcentaje' => null,
            'estado' => ($fumigacion && $fumigacion['vigencia'] === 'Vigente') ? 'ok' : 'bad',
            'detalle' => $fumigacion ? [
                ['label' => 'Dias transcurridos', 'value' => $fumigacion['dias']],
                ['label' => 'Checklist', 'value' => $fumigacion['corr']],
            ] : [],
        ];
    }

    $completion = cr_completion_for_checklist($conn, $checklistId, $tipoId);
    return [
        'titulo' => 'Completitud del Checklist',
        'texto' => $completion['estado'],
        'valor' => $completion['respondidos'] . ' / ' . $completion['total'],
        'porcentaje' => $completion['porcentaje'],
        'estado' => $completion['estado'] === 'Completo' ? 'ok' : 'warn',
        'detalle' => [],
    ];
}

function cr_fetch_bus(mysqli $conn, int $busId): ?array {
    $stmt = $conn->prepare("
        SELECT clm_placas_id AS id_bus,
               IFNULL(clm_placas_BUS, '') AS bus,
               IFNULL(clm_placas_PLACA, '') AS placa,
               IFNULL(clm_placas_SERVICIO, '') AS servicio,
               IFNULL(clm_placas_ESTADO, '') AS estado
        FROM tb_placas
        WHERE clm_placas_id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('i', $busId);
    return cr_fetch_one($stmt);
}

function cr_search_buses(mysqli $conn, string $q): array {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT clm_placas_id AS id_bus,
               IFNULL(clm_placas_BUS, '') AS bus,
               IFNULL(clm_placas_PLACA, '') AS placa,
               IFNULL(clm_placas_SERVICIO, '') AS servicio
        FROM tb_placas
        WHERE UPPER(TRIM(IFNULL(clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND (clm_placas_BUS LIKE ? OR clm_placas_PLACA LIKE ?)
        ORDER BY clm_placas_BUS ASC, clm_placas_PLACA ASC
        LIMIT 30
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('ss', $like, $like);
    return cr_fetch_all($stmt);
}

function cr_checklist_base_query(): string {
    return "
        SELECT c.clm_checklist_id, c.clm_checklist_corr, c.clm_checklist_idtipo,
               c.clm_checklist_id_bus, c.clm_checklist_fecha, c.clm_checklist_hora,
               c.clm_checklist_estado, c.clm_checklist_responsable,
               c.clm_checklist_observaciones, c.clm_checklist_idpersonaregistra,
               IFNULL(t.clm_checktip_nombre, CONCAT('Tipo ', c.clm_checklist_idtipo)) AS tipo_nombre,
               IFNULL(p.clm_placas_BUS, '') AS bus,
               IFNULL(p.clm_placas_PLACA, '') AS placa,
               IFNULL(p.clm_placas_SERVICIO, '') AS servicio,
               COALESCE(NULLIF(u.nombre, ''), u.usuario, c.clm_checklist_idpersonaregistra) AS usuario_registro
        FROM tb_checklist_limpieza c
        LEFT JOIN tb_checklist_tipos t ON t.clm_checktip_id = c.clm_checklist_idtipo
        LEFT JOIN tb_placas p ON p.clm_placas_id = c.clm_checklist_id_bus
        LEFT JOIN tb_usuarios u ON u.id_usuario = c.clm_checklist_idpersonaregistra
    ";
}

function cr_summary_from_row(mysqli $conn, array $row): array {
    $checklistId = (int)$row['clm_checklist_id'];
    $tipoId = (int)$row['clm_checklist_idtipo'];
    $completion = cr_completion_for_checklist($conn, $checklistId, $tipoId);
    $kpi = cr_kpi_for_checklist($conn, $checklistId, $tipoId, (int)$row['clm_checklist_id_bus']);

    return [
        'id' => $checklistId,
        'corr' => cr_text($row['clm_checklist_corr']),
        'tipo_id' => $tipoId,
        'tipo' => cr_text($row['tipo_nombre']),
        'fecha' => cr_text($row['clm_checklist_fecha']),
        'hora' => cr_text($row['clm_checklist_hora']),
        'estado' => cr_text($row['clm_checklist_estado']),
        'responsable' => cr_text($row['clm_checklist_responsable']),
        'observaciones' => cr_text($row['clm_checklist_observaciones']),
        'usuario_registro' => cr_text($row['usuario_registro']),
        'bus' => cr_text($row['bus']),
        'placa' => cr_text($row['placa']),
        'servicio' => cr_text($row['servicio']),
        'id_bus' => (int)$row['clm_checklist_id_bus'],
        'completion' => $completion,
        'kpi' => $kpi,
    ];
}

function cr_fetch_checklist_detail(mysqli $conn, int $checklistId): array {
    $stmt = $conn->prepare(cr_checklist_base_query() . " WHERE c.clm_checklist_id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('i', $checklistId);
    $row = cr_fetch_one($stmt);
    if (!$row) {
        cr_json(false, [], 'Checklist no encontrado.', 404);
    }
    if (!cr_can_view_tipo((int)$row['clm_checklist_idtipo'])) {
        cr_json(false, [], 'No tienes permiso para este tipo de checklist.', 403);
    }

    $stmtItems = $conn->prepare("
        SELECT cat.clm_categoria_id, cat.clm_categoria_nombre,
               i.clm_item_id, i.clm_item_nombre, i.clm_items_tipo,
               r.clm_resultado_estado, r.clm_resultado_dfecd, r.clm_rescheck_conductor1,
               r.clm_rescheck_porcentaje1, r.clm_rescheck_imagen,
               " . cr_result_doc_expr($conn, 'r') . " AS clm_rescheck_doc,
               r.clm_resultados_obs, r.clm_resultado_fecharegistro,
               COALESCE(NULLIF(ureg.nombre, ''), ureg.usuario, r.clm_resultados_id_user) AS usuario_respuesta
        FROM tb_items_checklist i
        INNER JOIN tb_categorias_checklist cat
            ON cat.clm_categoria_id = i.clm_item_id_categoria
        LEFT JOIN tb_resultados_checklist r
            ON r.clm_resultado_id_item = i.clm_item_id
           AND r.clm_resultado_id_checklist = ?
        LEFT JOIN tb_usuarios ureg
            ON ureg.id_usuario = r.clm_resultados_id_user
        WHERE i.clm_item_estado = 'activo'
          AND cat.clm_categorias_estado = 'activo'
          AND i.clm_item_idtipocheck = ?
        ORDER BY cat.clm_categoria_id ASC, i.clm_item_id ASC
    ");
    if (!$stmtItems) throw new RuntimeException(cr_db_error($conn));
    $tipoId = (int)$row['clm_checklist_idtipo'];
    $stmtItems->bind_param('ii', $checklistId, $tipoId);
    $itemRows = cr_fetch_all($stmtItems);

    $categories = [];
    $total = 0;
    $respondidos = 0;
    foreach ($itemRows as $item) {
        $catId = (int)$item['clm_categoria_id'];
        if (!isset($categories[$catId])) {
            $categories[$catId] = [
                'id' => $catId,
                'nombre' => cr_text($item['clm_categoria_nombre']),
                'items' => [],
            ];
        }
        $valor = cr_item_value($item);
        $answered = $valor !== '';
        $total++;
        if ($answered) $respondidos++;
        $categories[$catId]['items'][] = [
            'id' => (int)$item['clm_item_id'],
            'item' => cr_text($item['clm_item_nombre']),
            'tipo' => cr_text($item['clm_items_tipo']),
            'valor' => $valor,
            'kpi' => cr_item_metric($item),
            'observacion' => cr_text($item['clm_resultados_obs']),
            'usuario' => cr_text($item['usuario_respuesta']),
            'fecha_registro' => cr_text($item['clm_resultado_fecharegistro']),
            'respondido' => $answered,
        ];
    }

    $summary = cr_summary_from_row($conn, $row);
    $summary['completion'] = [
        'total' => $total,
        'respondidos' => $respondidos,
        'porcentaje' => $total > 0 ? round(($respondidos / $total) * 100, 2) : 0,
        'estado' => ($total > 0 && $respondidos >= $total) ? 'Completo' : 'Incompleto',
    ];

    return [
        'checklist' => $summary,
        'categorias' => array_values($categories),
    ];
}

function cr_sede_map(mysqli $conn): array {
    $map = [];
    $res = $conn->query("SELECT clm_sedes_id, COALESCE(NULLIF(clm_sedes_abr, ''), clm_sedes_name, CONCAT('Sede ', clm_sedes_id)) AS nombre FROM tb_sedes");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int)$row['clm_sedes_id']] = cr_text($row['nombre']);
        }
    }
    return $map;
}

function cr_route_names(?string $ruta, array $sedeMap): string {
    $ruta = trim((string)$ruta);
    if ($ruta === '') return '';
    $names = [];
    foreach (explode(',', $ruta) as $idRaw) {
        $id = (int)trim($idRaw);
        if ($id > 0) $names[] = $sedeMap[$id] ?? ('Sede ' . $id);
    }
    return implode(' -> ', $names);
}

function cr_fetch_conductores_by_bus(mysqli $conn, int $busId): array {
    $stmt = $conn->prepare("
        SELECT pc.clm_progconductores_progid AS progid,
               pc.clm_progconductores_idconductor AS id_conductor,
               COALESCE(pc.clm_progconductores_datetimeupdated, pc.clm_progconductores_fechacreated) AS fecha_programacion_raw,
               DATE_FORMAT(COALESCE(pc.clm_progconductores_datetimeupdated, pc.clm_progconductores_fechacreated), '%d/%m/%Y %H:%i') AS fecha_programacion,
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
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('i', $busId);
    return cr_fetch_all($stmt);
}

function cr_fetch_rutas_by_bus(mysqli $conn, int $busId): array {
    $sedeMap = cr_sede_map($conn);
    $stmt = $conn->prepare("
        SELECT pb.clm_progbuses_progid AS progid,
               pb.clm_progbuses_idoficina_origen AS id_origen,
               pb.clm_progbuses_idoficina_destino AS id_destino,
               pb.clm_progbuses_ruta AS ruta_ids,
               pb.clm_progbuses_horasalida AS hora,
               COALESCE(pb.clm_progbuses_datetimeupdated, pb.clm_progbuses_fechacreated) AS fecha_programacion_raw,
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
               IFNULL(o2.clm_sedes_abr, '') AS destino
        FROM tb_progbuses pb
        LEFT JOIN tb_sedes o1 ON o1.clm_sedes_id = pb.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes o2 ON o2.clm_sedes_id = pb.clm_progbuses_idoficina_destino
        WHERE pb.clm_progbuses_estado = 1
          AND pb.clm_progbuses_idplaca = ?
        ORDER BY pb.clm_progbuses_horasalida ASC, pb.clm_progbuses_progid ASC
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('i', $busId);
    $rows = cr_fetch_all($stmt);
    foreach ($rows as &$row) {
        $row['ruta_texto'] = cr_route_names($row['ruta_ids'] ?? '', $sedeMap);
    }
    unset($row);
    return $rows;
}

function cr_fetch_ultima_fumigacion(mysqli $conn, int $busId): ?array {
    $stmt = $conn->prepare("
        SELECT c.clm_checklist_id, c.clm_checklist_corr, c.clm_checklist_fecha, c.clm_checklist_hora,
               c.clm_checklist_estado, r.clm_resultado_dfecd
        FROM tb_checklist_limpieza c
        LEFT JOIN tb_resultados_checklist r
            ON r.clm_resultado_id_checklist = c.clm_checklist_id
        LEFT JOIN tb_items_checklist i
            ON i.clm_item_id = r.clm_resultado_id_item
           AND i.clm_items_tipo = 'H'
        WHERE c.clm_checklist_idtipo = 4
          AND c.clm_checklist_id_bus = ?
        ORDER BY COALESCE(r.clm_resultado_dfecd, CONCAT(c.clm_checklist_fecha, ' ', c.clm_checklist_hora)) DESC,
                 c.clm_checklist_fecha DESC, c.clm_checklist_hora DESC
        LIMIT 1
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('i', $busId);
    $row = cr_fetch_one($stmt);
    if (!$row) return null;

    $fechaBase = cr_text($row['clm_resultado_dfecd']) ?: trim(cr_text($row['clm_checklist_fecha']) . ' ' . cr_text($row['clm_checklist_hora']));
    $dias = null;
    $estadoVigencia = 'Sin fecha valida';
    try {
        $dt = new DateTime($fechaBase);
        $hoy = new DateTime('today');
        $dias = $dt->diff($hoy)->days;
        $estadoVigencia = $dias <= 15 ? 'Vigente' : 'Vencida';
    } catch (Throwable $e) {
    }

    return [
        'id' => (int)$row['clm_checklist_id'],
        'corr' => cr_text($row['clm_checklist_corr']),
        'fecha' => cr_text($row['clm_checklist_fecha']),
        'hora' => cr_text($row['clm_checklist_hora']),
        'fecha_fumigacion' => $fechaBase,
        'dias' => $dias,
        'vigencia' => $estadoVigencia,
        'estado' => cr_text($row['clm_checklist_estado']),
    ];
}

function cr_fetch_unit_report(mysqli $conn, int $busId, string $desde, string $hasta): array {
    $bus = cr_fetch_bus($conn, $busId);
    if (!$bus) cr_json(false, [], 'Unidad no encontrada.', 404);

    $stmt = $conn->prepare(cr_checklist_base_query() . "
        WHERE c.clm_checklist_id_bus = ?
          AND c.clm_checklist_fecha BETWEEN ? AND ?
        ORDER BY c.clm_checklist_fecha DESC, c.clm_checklist_hora DESC, c.clm_checklist_id DESC
        LIMIT 400
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('iss', $busId, $desde, $hasta);
    $rows = cr_fetch_all($stmt);

    $checklists = [];
    $ultimos = [];
    foreach ($rows as $row) {
        $summary = cr_summary_from_row($conn, $row);
        $checklists[] = $summary;
        $tipoId = (int)$summary['tipo_id'];
        if (!isset($ultimos[$tipoId])) $ultimos[$tipoId] = $summary;
    }

    $tipos = [];
    $resTipos = $conn->query("SELECT clm_checktip_id, clm_checktip_nombre FROM tb_checklist_tipos ORDER BY clm_checktip_id ASC");
    if ($resTipos) {
        while ($tipo = $resTipos->fetch_assoc()) {
            $id = (int)$tipo['clm_checktip_id'];
            if ($id >= 1 && $id <= 4) {
                $tipos[] = [
                    'tipo_id' => $id,
                    'tipo' => cr_text($tipo['clm_checktip_nombre']),
                    'ultimo' => $ultimos[$id] ?? null,
                ];
            }
        }
    }

    $completos = 0;
    foreach ($checklists as $chk) {
        if (($chk['completion']['estado'] ?? '') === 'Completo') $completos++;
    }

    return [
        'bus' => $bus,
        'filtros' => ['desde' => $desde, 'hasta' => $hasta],
        'resumen' => [
            'checklists' => count($checklists),
            'completos' => $completos,
            'incompletos' => max(count($checklists) - $completos, 0),
        ],
        'ultimos_por_tipo' => $tipos,
        'ultima_fumigacion' => cr_fetch_ultima_fumigacion($conn, $busId),
        'conductores' => cr_fetch_conductores_by_bus($conn, $busId),
        'rutas' => cr_fetch_rutas_by_bus($conn, $busId),
        'checklists' => $checklists,
    ];
}

function cr_fetch_fleet_report(mysqli $conn, string $desde, string $hasta): array {
    $stmt = $conn->prepare(cr_checklist_base_query() . "
        WHERE c.clm_checklist_fecha BETWEEN ? AND ?
        ORDER BY p.clm_placas_BUS ASC, c.clm_checklist_fecha DESC, c.clm_checklist_hora DESC
        LIMIT 1500
    ");
    if (!$stmt) throw new RuntimeException(cr_db_error($conn));
    $stmt->bind_param('ss', $desde, $hasta);
    $rows = cr_fetch_all($stmt);

    $checklists = [];
    $unidades = [];
    foreach ($rows as $row) {
        if (!cr_can_view_tipo((int)$row['clm_checklist_idtipo'])) {
            continue;
        }
        $summary = cr_summary_from_row($conn, $row);
        $checklists[] = $summary;
        $busId = (int)$summary['id_bus'];
        if (!isset($unidades[$busId])) {
            $unidades[$busId] = [
                'id_bus' => $busId,
                'bus' => $summary['bus'],
                'placa' => $summary['placa'],
                'servicio' => $summary['servicio'],
                'total' => 0,
                'completos' => 0,
                'incompletos' => 0,
                'por_tipo' => [],
                'kpis' => [],
            ];
        }
        $unidades[$busId]['total']++;
        if (($summary['completion']['estado'] ?? '') === 'Completo') {
            $unidades[$busId]['completos']++;
        } else {
            $unidades[$busId]['incompletos']++;
        }
        $tipoId = (int)$summary['tipo_id'];
        if (!isset($unidades[$busId]['por_tipo'][$tipoId])) {
            $unidades[$busId]['por_tipo'][$tipoId] = $summary;
        }
        $unidades[$busId]['kpis'][] = $summary['kpi'];
    }

    return [
        'filtros' => ['desde' => $desde, 'hasta' => $hasta],
        'resumen' => [
            'unidades' => count($unidades),
            'checklists' => count($checklists),
            'completos' => count(array_filter($checklists, fn($r) => ($r['completion']['estado'] ?? '') === 'Completo')),
            'incompletos' => count(array_filter($checklists, fn($r) => ($r['completion']['estado'] ?? '') !== 'Completo')),
        ],
        'unidades' => array_values($unidades),
        'checklists' => $checklists,
    ];
}

try {
    $action = trim((string)($_GET['action'] ?? ''));
    $today = date('Y-m-d');
    $defaultDesde = date('Y-m-d', strtotime('-180 days'));
    $desde = cr_date_or_default($_GET['desde'] ?? null, $defaultDesde);
    $hasta = cr_date_or_default($_GET['hasta'] ?? null, $today);

    if ($action === 'buscar_unidades') {
        cr_require_admin();
        $q = trim((string)($_GET['q'] ?? ''));
        cr_json(true, ['unidades' => cr_search_buses($conn, $q)]);
    }

    if ($action === 'unidad') {
        cr_require_admin();
        $busId = (int)($_GET['id_bus'] ?? 0);
        if ($busId <= 0) cr_json(false, [], 'Selecciona una unidad valida.', 422);
        cr_json(true, cr_fetch_unit_report($conn, $busId, $desde, $hasta));
    }

    if ($action === 'flota') {
        cr_json(true, cr_fetch_fleet_report($conn, $desde, $hasta));
    }

    if ($action === 'checklist') {
        $checklistId = (int)($_GET['id_checklist'] ?? 0);
        if ($checklistId <= 0) cr_json(false, [], 'Checklist no valido.', 422);
        cr_json(true, cr_fetch_checklist_detail($conn, $checklistId));
    }

    cr_json(false, [], 'Accion no reconocida.', 400);
} catch (Throwable $e) {
    cr_json(false, [], $e->getMessage(), 500);
}
