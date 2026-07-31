<?php
if (!defined('N360_ENCOMIENDAS')) {
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/encomiendas_helpers.php';

function enc_fetch_sedes(mysqli $conn): array {
    return enc_fetch_all($conn, "
        SELECT clm_sedes_id AS id,
               clm_sedes_name AS nombre
        FROM tb_sedes
        ORDER BY clm_sedes_name ASC, clm_sedes_id ASC
    ");
}

function enc_fetch_placas(mysqli $conn): array {
    return enc_fetch_all($conn, "
        SELECT clm_placas_id AS id,
               clm_placas_BUS AS bus,
               clm_placas_PLACA AS placa
        FROM tb_placas
        ORDER BY CAST(clm_placas_BUS AS UNSIGNED) ASC, clm_placas_BUS ASC, clm_placas_PLACA ASC
    ");
}

function enc_fetch_active_programaciones(mysqli $conn): array {
    return enc_fetch_all($conn, "
        SELECT pb.clm_progbuses_progid AS id,
               pb.clm_progbuses_idplaca AS idplaca,
               pb.clm_progbuses_idoficina_origen AS idsede_origen,
               pb.clm_progbuses_idoficina_destino AS idsede_destino,
               pb.clm_progbuses_ruta AS ruta_raw,
               TIME_FORMAT(pb.clm_progbuses_horasalida, '%H:%i') AS hora,
               COALESCE(NULLIF(TRIM(so.clm_sedes_name), ''), CONCAT('Sede ', so.clm_sedes_id)) AS origen,
               COALESCE(NULLIF(TRIM(sd.clm_sedes_name), ''), CONCAT('Sede ', sd.clm_sedes_id)) AS destino,
               p.clm_placas_BUS AS bus,
               p.clm_placas_PLACA AS placa
        FROM tb_progbuses pb
        LEFT JOIN tb_sedes so ON so.clm_sedes_id = pb.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes sd ON sd.clm_sedes_id = pb.clm_progbuses_idoficina_destino
        LEFT JOIN tb_placas p ON p.clm_placas_id = pb.clm_progbuses_idplaca
        WHERE pb.clm_progbuses_estado = 1
        ORDER BY pb.clm_progbuses_horasalida ASC, so.clm_sedes_name ASC, sd.clm_sedes_name ASC, pb.clm_progbuses_progid ASC
        LIMIT 300
    ");
}

function enc_schema_has_guias_norte(mysqli $conn): bool {
    try {
        $columns = enc_fetch_one($conn, "
            SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_enc_guias'
              AND COLUMN_NAME IN ('clm_enc_serie', 'clm_enc_correlativo', 'clm_enc_horario_operativo', 'clm_enc_idprogbus', 'clm_enc_hora_embarque_programada')
        ");
        $docColumns = enc_fetch_one($conn, "
            SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_enc_documentos'
              AND COLUMN_NAME IN ('clm_encdoc_idpunto', 'clm_encdoc_tipo_comprobante', 'clm_encdoc_numero_comprobante', 'clm_encdoc_fecha_comprobante', 'clm_encdoc_observacion')
        ");
        $points = enc_fetch_one($conn, "
            SELECT COUNT(*) AS total
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_enc_guia_puntos'
        ");
        return (int)($columns['total'] ?? 0) === 5
            && (int)($docColumns['total'] ?? 0) === 5
            && (int)($points['total'] ?? 0) === 1;
    } catch (Throwable $e) {
        enc_log($e);
        return false;
    }
}

function enc_current_filters(): array {
    $today = date('Y-m-d');
    $first = date('Y-m-01');
    $perPage = (int)($_GET['per_page'] ?? 25);
    if (!in_array($perPage, [15, 25, 50, 100], true)) $perPage = 25;

    return [
        'guia' => trim((string)($_GET['guia'] ?? '')),
        'documento' => trim((string)($_GET['documento'] ?? '')),
        'fecha_guia' => enc_nullable_date($_GET['fecha_guia'] ?? '') ?? '',
        'desde' => enc_nullable_date($_GET['desde'] ?? '') ?? $first,
        'hasta' => enc_nullable_date($_GET['hasta'] ?? '') ?? $today,
        'idsede_embarque' => (int)($_GET['idsede_embarque'] ?? 0),
        'idsede_desembarque' => (int)($_GET['idsede_desembarque'] ?? 0),
        'idplaca' => (int)($_GET['idplaca'] ?? 0),
        'estado_embarque' => trim((string)($_GET['estado_embarque'] ?? 'TODOS')),
        'estado_desembarque' => trim((string)($_GET['estado_desembarque'] ?? 'TODOS')),
        'estado_general' => trim((string)($_GET['estado_general'] ?? 'TODOS')),
        'estado_vida' => trim((string)($_GET['estado_vida'] ?? 'TODOS')),
        'buscar' => trim((string)($_GET['buscar'] ?? '')),
        'page' => max(1, (int)($_GET['page'] ?? 1)),
        'per_page' => $perPage,
    ];
}

function enc_build_tracking_where(array $filters): array {
    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($filters['guia'] !== '') {
        $where[] = enc_like_expr('g.clm_enc_guia');
        $types .= 's';
        $params[] = $filters['guia'];
    }

    if ($filters['documento'] !== '') {
        $where[] = "EXISTS (
            SELECT 1
            FROM tb_enc_documentos dx
            WHERE dx.clm_encdoc_idguia = g.clm_enc_id
              AND dx.clm_encdoc_estado = 1
              AND (" . enc_like_expr('dx.clm_encdoc_numero_comprobante') . " OR " . enc_like_expr('dx.clm_encdoc_nombre') . ")
        )";
        $types .= 'ss';
        $params[] = $filters['documento'];
        $params[] = $filters['documento'];
    }

    if ($filters['fecha_guia'] !== '') {
        $where[] = 'g.clm_enc_fecha_guia = ?';
        $types .= 's';
        $params[] = $filters['fecha_guia'];
    } else {
        if ($filters['desde'] !== '') {
            $where[] = 'g.clm_enc_fecha_guia >= ?';
            $types .= 's';
            $params[] = $filters['desde'];
        }
        if ($filters['hasta'] !== '') {
            $where[] = 'g.clm_enc_fecha_guia <= ?';
            $types .= 's';
            $params[] = $filters['hasta'];
        }
    }

    if ($filters['idsede_embarque'] > 0) {
        $where[] = 'g.clm_enc_idsede_embarque = ?';
        $types .= 'i';
        $params[] = $filters['idsede_embarque'];
    }
    if ($filters['idsede_desembarque'] > 0) {
        $where[] = 'g.clm_enc_idsede_desembarque = ?';
        $types .= 'i';
        $params[] = $filters['idsede_desembarque'];
    }
    if ($filters['idplaca'] > 0) {
        $where[] = 'g.clm_enc_idplaca_embarque = ?';
        $types .= 'i';
        $params[] = $filters['idplaca'];
    }
    if (in_array($filters['estado_embarque'], ['PENDIENTE', 'EMBARCADO', 'OBSERVADO'], true)) {
        $where[] = 'g.clm_enc_estado_embarque = ?';
        $types .= 's';
        $params[] = $filters['estado_embarque'];
    }
    if (in_array($filters['estado_desembarque'], ['PENDIENTE', 'RECIBIDO', 'INCOMPLETO', 'OBSERVADO'], true)) {
        $where[] = 'g.clm_enc_estado_desembarque = ?';
        $types .= 's';
        $params[] = $filters['estado_desembarque'];
    }
    if (in_array($filters['estado_general'], ['REGISTRADA', 'EN_TRANSITO', 'FINALIZADA', 'OBSERVADA', 'ANULADA'], true)) {
        $where[] = 'g.clm_enc_estado_general = ?';
        $types .= 's';
        $params[] = $filters['estado_general'];
    }

    if ($filters['estado_vida'] === 'ACTIVO') {
        $where[] = 'g.clm_enc_activo = 1';
    } elseif ($filters['estado_vida'] === 'FINALIZADO') {
        $where[] = "g.clm_enc_estado_general = 'FINALIZADA'";
    } elseif ($filters['estado_vida'] === 'OBSERVADO') {
        $where[] = "g.clm_enc_estado_general = 'OBSERVADA'";
    } elseif ($filters['estado_vida'] === 'ANULADO') {
        $where[] = 'g.clm_enc_activo = 0';
    }

    if ($filters['buscar'] !== '') {
        $searchExpr = implode(' OR ', [
            enc_like_expr('g.clm_enc_guia'),
            enc_like_expr('g.clm_enc_horario_operativo'),
            enc_like_expr('g.clm_enc_observacion'),
            enc_like_expr('se.clm_sedes_name'),
            enc_like_expr('sd.clm_sedes_name'),
            enc_like_expr("CONCAT_WS(' ', p.clm_placas_BUS, p.clm_placas_PLACA)"),
            "EXISTS (
                SELECT 1
                FROM tb_enc_documentos ds
                WHERE ds.clm_encdoc_idguia = g.clm_enc_id
                  AND ds.clm_encdoc_estado = 1
                  AND (" . enc_like_expr('ds.clm_encdoc_numero_comprobante') . " OR " . enc_like_expr('ds.clm_encdoc_nombre') . ")
            )",
        ]);
        $where[] = '(' . $searchExpr . ')';
        $types .= 'ssssssss';
        for ($i = 0; $i < 8; $i++) $params[] = $filters['buscar'];
    }

    return ['where' => implode(' AND ', $where), 'types' => $types, 'params' => $params];
}

function enc_select_tracking_base(): string {
    return "
        FROM tb_enc_guias g
        INNER JOIN tb_sedes se ON se.clm_sedes_id = g.clm_enc_idsede_embarque
        INNER JOIN tb_sedes sd ON sd.clm_sedes_id = g.clm_enc_idsede_desembarque
        LEFT JOIN tb_placas p ON p.clm_placas_id = g.clm_enc_idplaca_embarque
        LEFT JOIN tb_usuarios ur ON ur.id_usuario = g.clm_enc_idusuario_registra
        LEFT JOIN tb_usuarios ua ON ua.id_usuario = g.clm_enc_idusuario_actualiza
        LEFT JOIN (
            SELECT clm_encpunto_idguia,
                   COUNT(*) AS puntos_total,
                   SUM(clm_encpunto_manifiesto_obligatorio = 1) AS manifiestos_req
            FROM tb_enc_guia_puntos
            WHERE clm_encpunto_activo = 1
            GROUP BY clm_encpunto_idguia
        ) pts ON pts.clm_encpunto_idguia = g.clm_enc_id
        LEFT JOIN (
            SELECT clm_encdoc_idguia,
                   COUNT(DISTINCT CASE WHEN clm_encdoc_tipo = 'MANIFIESTO_ENCOMIENDAS' THEN clm_encdoc_idpunto END) AS manifiestos_ok,
                   COUNT(CASE WHEN clm_encdoc_tipo = 'GUIA_TRANSPORTISTA' THEN clm_encdoc_id END) AS guias_transportista_total
            FROM tb_enc_documentos
            WHERE clm_encdoc_estado = 1
            GROUP BY clm_encdoc_idguia
        ) docs ON docs.clm_encdoc_idguia = g.clm_enc_id
    ";
}

function enc_count_tracking(mysqli $conn, array $filters): int {
    $build = enc_build_tracking_where($filters);
    $row = enc_fetch_one($conn, 'SELECT COUNT(*) AS total ' . enc_select_tracking_base() . ' WHERE ' . $build['where'], $build['types'], $build['params']);
    return (int)($row['total'] ?? 0);
}

function enc_fetch_tracking(mysqli $conn, array $filters): array {
    $build = enc_build_tracking_where($filters);
    $limit = (int)$filters['per_page'];
    $offset = max(0, ((int)$filters['page'] - 1) * $limit);
    $types = $build['types'] . 'ii';
    $params = $build['params'];
    $params[] = $offset;
    $params[] = $limit;

    return enc_fetch_all($conn, "
        SELECT g.*,
               se.clm_sedes_name AS sede_embarque,
               sd.clm_sedes_name AS sede_desembarque,
               p.clm_placas_BUS AS placa_bus,
               p.clm_placas_PLACA AS placa_placa,
               COALESCE(NULLIF(TRIM(ur.nombre), ''), ur.usuario, CONCAT('Usuario ', ur.id_usuario)) AS usuario_registra,
               COALESCE(NULLIF(TRIM(ua.nombre), ''), ua.usuario, CONCAT('Usuario ', ua.id_usuario)) AS usuario_actualiza,
               COALESCE(pts.puntos_total, 0) AS puntos_total,
               COALESCE(pts.manifiestos_req, 0) AS manifiestos_req,
               COALESCE(docs.manifiestos_ok, 0) AS manifiestos_ok,
               COALESCE(docs.guias_transportista_total, 0) AS guias_transportista_total
        " . enc_select_tracking_base() . "
        WHERE {$build['where']}
        ORDER BY COALESCE(g.clm_enc_datetimeupdated, g.clm_enc_fechacreated) DESC, g.clm_enc_id DESC
        LIMIT ?, ?
    ", $types, $params);
}

function enc_fetch_kpis(mysqli $conn, array $filters): array {
    $build = enc_build_tracking_where($filters);
    $row = enc_fetch_one($conn, "
        SELECT COUNT(*) AS total,
               SUM(g.clm_enc_activo = 1) AS activas,
               SUM(g.clm_enc_estado_general = 'EN_TRANSITO') AS transito,
               SUM(g.clm_enc_estado_general = 'FINALIZADA') AS finalizadas,
               SUM(g.clm_enc_estado_general = 'OBSERVADA') AS observadas,
               SUM(g.clm_enc_activo = 0) AS anuladas,
               SUM(COALESCE(docs.manifiestos_ok, 0) >= COALESCE(pts.manifiestos_req, 0) AND COALESCE(pts.manifiestos_req, 0) > 0) AS con_manifiestos
        " . enc_select_tracking_base() . "
        WHERE {$build['where']}
    ", $build['types'], $build['params']);
    return array_map('intval', $row ?: []);
}

function enc_fetch_guia(mysqli $conn, int $id): ?array {
    return enc_fetch_one($conn, "
        SELECT g.*,
               se.clm_sedes_name AS sede_embarque,
               sd.clm_sedes_name AS sede_desembarque,
               p.clm_placas_BUS AS placa_bus,
               p.clm_placas_PLACA AS placa_placa,
               COALESCE(NULLIF(TRIM(ur.nombre), ''), ur.usuario, CONCAT('Usuario ', ur.id_usuario)) AS usuario_registra,
               COALESCE(NULLIF(TRIM(ua.nombre), ''), ua.usuario, CONCAT('Usuario ', ua.id_usuario)) AS usuario_actualiza,
               COALESCE(NULLIF(TRIM(ue.nombre), ''), ue.usuario, CONCAT('Usuario ', ue.id_usuario)) AS usuario_embarque,
               COALESCE(NULLIF(TRIM(ud.nombre), ''), ud.usuario, CONCAT('Usuario ', ud.id_usuario)) AS usuario_desembarque,
               COALESCE(NULLIF(TRIM(uan.nombre), ''), uan.usuario, CONCAT('Usuario ', uan.id_usuario)) AS usuario_anula
        FROM tb_enc_guias g
        INNER JOIN tb_sedes se ON se.clm_sedes_id = g.clm_enc_idsede_embarque
        INNER JOIN tb_sedes sd ON sd.clm_sedes_id = g.clm_enc_idsede_desembarque
        LEFT JOIN tb_placas p ON p.clm_placas_id = g.clm_enc_idplaca_embarque
        LEFT JOIN tb_usuarios ur ON ur.id_usuario = g.clm_enc_idusuario_registra
        LEFT JOIN tb_usuarios ua ON ua.id_usuario = g.clm_enc_idusuario_actualiza
        LEFT JOIN tb_usuarios ue ON ue.id_usuario = g.clm_enc_idusuario_embarque
        LEFT JOIN tb_usuarios ud ON ud.id_usuario = g.clm_enc_idusuario_desembarque
        LEFT JOIN tb_usuarios uan ON uan.id_usuario = g.clm_enc_idusuario_anula
        WHERE g.clm_enc_id = ?
        LIMIT 1
    ", 'i', [$id]);
}

function enc_fetch_route_points(mysqli $conn, int $guideId): array {
    return enc_fetch_all($conn, "
        SELECT p.*,
               s.clm_sedes_name AS sede_nombre,
               COALESCE(NULLIF(TRIM(u.nombre), ''), u.usuario, CONCAT('Usuario ', u.id_usuario)) AS usuario_evento
        FROM tb_enc_guia_puntos p
        INNER JOIN tb_sedes s ON s.clm_sedes_id = p.clm_encpunto_idsede
        LEFT JOIN tb_usuarios u ON u.id_usuario = p.clm_encpunto_idusuario_evento
        WHERE p.clm_encpunto_idguia = ?
          AND p.clm_encpunto_activo = 1
        ORDER BY p.clm_encpunto_orden ASC, p.clm_encpunto_id ASC
    ", 'i', [$guideId]);
}

function enc_fetch_documents(mysqli $conn, int $guideId): array {
    return enc_fetch_all($conn, "
        SELECT d.clm_encdoc_id,
               d.clm_encdoc_idguia,
               d.clm_encdoc_idpunto,
               d.clm_encdoc_tipo,
               d.clm_encdoc_tipo_comprobante,
               d.clm_encdoc_numero_comprobante,
               d.clm_encdoc_fecha_comprobante,
               d.clm_encdoc_observacion,
               d.clm_encdoc_nombre,
               d.clm_encdoc_mime,
               d.clm_encdoc_size,
               d.clm_encdoc_sha256,
               d.clm_encdoc_idusuario_carga,
               d.clm_encdoc_fechacarga,
               d.clm_encdoc_idusuario_actualiza,
               d.clm_encdoc_fechaactualiza,
               d.clm_encdoc_estado,
               p.clm_encpunto_orden,
               p.clm_encpunto_tipo,
               s.clm_sedes_name AS punto_sede,
               COALESCE(NULLIF(TRIM(uc.nombre), ''), uc.usuario, CONCAT('Usuario ', uc.id_usuario)) AS usuario_carga,
               COALESCE(NULLIF(TRIM(ua.nombre), ''), ua.usuario, CONCAT('Usuario ', ua.id_usuario)) AS usuario_actualiza
        FROM tb_enc_documentos d
        LEFT JOIN tb_enc_guia_puntos p ON p.clm_encpunto_id = d.clm_encdoc_idpunto
        LEFT JOIN tb_sedes s ON s.clm_sedes_id = p.clm_encpunto_idsede
        LEFT JOIN tb_usuarios uc ON uc.id_usuario = d.clm_encdoc_idusuario_carga
        LEFT JOIN tb_usuarios ua ON ua.id_usuario = d.clm_encdoc_idusuario_actualiza
        WHERE d.clm_encdoc_idguia = ?
          AND d.clm_encdoc_estado = 1
        ORDER BY CASE d.clm_encdoc_tipo WHEN 'MANIFIESTO_ENCOMIENDAS' THEN 1 ELSE 2 END,
                 COALESCE(p.clm_encpunto_orden, 999),
                 d.clm_encdoc_fechacarga DESC,
                 d.clm_encdoc_id DESC
    ", 'i', [$guideId]);
}

function enc_fetch_document_blob(mysqli $conn, int $docId): ?array {
    return enc_fetch_one($conn, "
        SELECT d.clm_encdoc_id,
               d.clm_encdoc_idguia,
               d.clm_encdoc_tipo,
               d.clm_encdoc_nombre,
               d.clm_encdoc_mime,
               d.clm_encdoc_size,
               d.clm_encdoc_archivo,
               g.clm_enc_guia
        FROM tb_enc_documentos d
        INNER JOIN tb_enc_guias g ON g.clm_enc_id = d.clm_encdoc_idguia
        WHERE d.clm_encdoc_id = ?
          AND d.clm_encdoc_estado = 1
        LIMIT 1
    ", 'i', [$docId]);
}

function enc_fetch_history(mysqli $conn, int $guideId): array {
    return enc_fetch_all($conn, "
        SELECT h.*,
               COALESCE(NULLIF(TRIM(u.nombre), ''), u.usuario, CONCAT('Usuario ', u.id_usuario)) AS usuario_evento
        FROM tb_hist_enc_guias h
        LEFT JOIN tb_usuarios u ON u.id_usuario = h.clm_enchist_idusuario
        WHERE h.clm_enchist_idguia = ?
        ORDER BY h.clm_enchist_fechaevento DESC, h.clm_enchist_id DESC
    ", 'i', [$guideId]);
}

function enc_missing_required_manifests(mysqli $conn, int $guideId): array {
    return enc_fetch_all($conn, "
        SELECT p.clm_encpunto_id,
               p.clm_encpunto_orden,
               p.clm_encpunto_tipo,
               s.clm_sedes_name AS sede_nombre
        FROM tb_enc_guia_puntos p
        INNER JOIN tb_sedes s ON s.clm_sedes_id = p.clm_encpunto_idsede
        WHERE p.clm_encpunto_idguia = ?
          AND p.clm_encpunto_activo = 1
          AND p.clm_encpunto_manifiesto_obligatorio = 1
          AND NOT EXISTS (
              SELECT 1
              FROM tb_enc_documentos d
              WHERE d.clm_encdoc_idguia = p.clm_encpunto_idguia
                AND d.clm_encdoc_idpunto = p.clm_encpunto_id
                AND d.clm_encdoc_tipo = 'MANIFIESTO_ENCOMIENDAS'
                AND d.clm_encdoc_estado = 1
          )
        ORDER BY p.clm_encpunto_orden ASC
    ", 'i', [$guideId]);
}
function enc_fetch_tracking_report(mysqli $conn, array $filters, int $limit = 1500): array {
    $build = enc_build_tracking_where($filters);
    $limit = max(1, min(3000, $limit));
    $types = $build['types'] . 'i';
    $params = $build['params'];
    $params[] = $limit;

    return enc_fetch_all($conn, "
        SELECT g.*,
               se.clm_sedes_name AS sede_embarque,
               sd.clm_sedes_name AS sede_desembarque,
               p.clm_placas_BUS AS placa_bus,
               p.clm_placas_PLACA AS placa_placa,
               COALESCE(NULLIF(TRIM(ur.nombre), ''), ur.usuario, CONCAT('Usuario ', ur.id_usuario)) AS usuario_registra,
               COALESCE(NULLIF(TRIM(ua.nombre), ''), ua.usuario, CONCAT('Usuario ', ua.id_usuario)) AS usuario_actualiza,
               COALESCE(pts.puntos_total, 0) AS puntos_total,
               COALESCE(pts.manifiestos_req, 0) AS manifiestos_req,
               COALESCE(docs.manifiestos_ok, 0) AS manifiestos_ok,
               COALESCE(docs.guias_transportista_total, 0) AS guias_transportista_total
        " . enc_select_tracking_base() . "
        WHERE {$build['where']}
        ORDER BY COALESCE(g.clm_enc_datetimeupdated, g.clm_enc_fechacreated) DESC, g.clm_enc_id DESC
        LIMIT ?
    ", $types, $params);
}
