<?php
require_once __DIR__ . '/movimiento_backend.php';

function alm_select_sedes(mysqli $conn): array {
    return alm_fetch_all($conn, "
        SELECT clm_sedes_id AS id, clm_sedes_name AS nombre
        FROM tb_sedes
        ORDER BY clm_sedes_name ASC
    ");
}

function alm_select_espacio(mysqli $conn, int $id): ?array {
    if ($id <= 0) {
        return null;
    }

    return alm_fetch_one($conn, "
        SELECT
            clm_esp_id AS id,
            COALESCE(NULLIF(TRIM(clm_esp_nombre), ''), CONCAT('ESP', clm_esp_id)) AS nombre,
            COALESCE(NULLIF(TRIM(clm_esp_desc), ''), 'Sin descripcion') AS descripcion,
            clm_esp_obs AS tipo
        FROM tb_espacio
        WHERE clm_esp_id = ?
        LIMIT 1
    ", 'i', [$id]);
}

function alm_select_anaqueles(mysqli $conn): array {
    return alm_fetch_all($conn, "
        SELECT
            clm_alm_anaquel_id AS id,
            clm_alm_anaquel_nombre AS nombre,
            clm_alm_anaquel_idSEDE AS sede_id,
            COALESCE(NULLIF(TRIM(clm_alm_anaquel_codigo), ''), CONCAT('AN', LPAD(clm_alm_anaquel_id, 2, '0'))) AS codigo
        FROM tb_alm_anaquel
        WHERE COALESCE(clm_alm_anaquel_estado, 1) = 1
        ORDER BY clm_alm_anaquel_nombre ASC
    ");
}

function alm_select_stats(mysqli $conn, int $originId = 1): array {
    $originId = in_array($originId, [1, 4, 12], true) ? $originId : 1;
    $row = alm_fetch_one($conn, "
        SELECT
            COUNT(*) AS hoy,
            SUM(CASE WHEN m.clm_alm_mov_TIPO = 'ENTRADA' THEN m.clm_alm_mov_cantidad ELSE 0 END) AS entradas,
            SUM(CASE WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN m.clm_alm_mov_cantidad ELSE 0 END) AS salidas,
            SUM(CASE WHEN m.clm_alm_mov_TIPO = 'INVENTARIADO' THEN m.clm_alm_mov_cantidad ELSE 0 END) AS inventariado
        FROM tb_alm_movimientos m
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
        WHERE DATE(m.clm_alm_mov_fecha_registro) = CURDATE()
          AND p.clm_alm_producto_idCATEGORIA <> 11
          AND COALESCE(m.clm_alm_mov_orgn, 1) = ?
    ", 'i', [$originId]);

    return $row ?: [];
}

function alm_select_recent_movements(mysqli $conn, int $originId = 1, int $limit = 12): array {
    $originId = in_array($originId, [1, 4, 12], true) ? $originId : 1;
    $limit = max(1, min(50, $limit));
    return alm_fetch_all($conn, "
        SELECT
            m.clm_alm_mov_id AS id,
            m.clm_alm_mov_fecha_registro AS fecha,
            m.clm_alm_mov_TIPO AS tipo,
            m.clm_alm_mov_cantidad AS cantidad,
            m.clm_alm_mov_monto AS monto,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_codigo), ''), p.clm_alm_producto_id) AS codigo,
            p.clm_alm_producto_NOMBRE AS producto,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_unidad), ''), '-') AS unidad,
            COALESCE(NULLIF(TRIM(ns.clm_nota_sco), ''), '-') AS nota,
            COALESCE(NULLIF(TRIM(u.usuario), ''), CAST(m.clm_alm_mov_iduser AS CHAR)) AS usuario,
            COALESCE(NULLIF(TRIM(m.clm_alm_mov_ubicacion), ''), '-') AS ubicacion
        FROM tb_alm_movimientos m
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
        LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
        LEFT JOIN tb_usuarios u ON u.id_usuario = m.clm_alm_mov_iduser
        WHERE p.clm_alm_producto_idCATEGORIA <> 11
          AND COALESCE(m.clm_alm_mov_orgn, 1) = ?
        ORDER BY m.clm_alm_mov_id DESC
        LIMIT {$limit}
    ", 'i', [$originId]);
}


function alm_action_categorias_producto(mysqli $conn): void {
    $originId = alm_origin_id_from_payload(['orgn_id' => (int)($_GET['origin_id'] ?? 0)]);
    $context = alm_context_config_from_origin($originId);

    $rows = alm_fetch_all($conn, "
        SELECT
            c.clm_alm_categoria_id AS id,
            COALESCE(NULLIF(TRIM(c.clm_alm_categoria_NOMBRE), ''), CONCAT('CAT', c.clm_alm_categoria_id)) AS nombre,
            COALESCE(NULLIF(TRIM(c.clm_alm_categoria_DESCRIPCION), ''), COALESCE(NULLIF(TRIM(c.clm_alm_categoria_NOMBRE), ''), 'Sin descripcion')) AS descripcion,
            COALESCE(NULLIF(TRIM(cod.clm_alm_codigo_DESCRIPCION), ''), NULLIF(TRIM(cod.clm_alm_codigo_NOMBRE), ''), 'Sin grupo') AS grupo,
            COALESCE(NULLIF(TRIM(cod.clm_alm_codigo_NOMBRE), ''), '') AS codigo_grupo
        FROM tb_alm_categoria c
        JOIN tb_alm_codigo cod ON cod.clm_alm_codigo_id = c.clm_alm_categoria_idCODIGO
        WHERE c.clm_alm_categoria_id <> 11
        ORDER BY c.clm_alm_categoria_DESCRIPCION ASC, c.clm_alm_categoria_NOMBRE ASC
    ");

    alm_json([
        'ok' => true,
        'rows' => $rows,
        'context' => [
            'origin_id' => $originId,
            'area_control' => $context['area_control'],
            'tipo_control' => $context['tipo_control'],
        ],
    ]);
}
function alm_action_catalogo_productos(mysqli $conn): void {
    $q = alm_clean_text($_GET['q'] ?? '', 80);
    $originId = alm_origin_id_from_payload(['orgn_id' => (int)($_GET['origin_id'] ?? 0)]);
    $context = alm_context_config_from_origin($originId);

    $where = [
        'p.clm_alm_producto_idCATEGORIA <> 11',
        "UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN')) = ?",
        "UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), 'CONSUMIBLE')) = ?"
    ];
    $types = 'ss';
    $params = [$context['area_control'], $context['tipo_control']];

    if ($q !== '') {
        $where[] = "(
            CONVERT(p.clm_alm_producto_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
            OR CONVERT(p.clm_alm_producto_NOMBRE USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
            OR CONVERT(p.clm_alm_producto_DESCRIPCION USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
            OR CONVERT(c.clm_alm_categoria_DESCRIPCION USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        )";
        $types .= 'ssss';
        array_push($params, $q, $q, $q, $q);
    }

    $rows = alm_fetch_all($conn, "
        SELECT
            p.clm_alm_producto_id AS id,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_codigo), ''), p.clm_alm_producto_id) AS codigo,
            p.clm_alm_producto_NOMBRE AS producto,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_DESCRIPCION), ''), '') AS descripcion,
            COALESCE(NULLIF(TRIM(c.clm_alm_categoria_DESCRIPCION), ''), 'Sin categoria') AS categoria,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_unidad), ''), '-') AS unidad,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN') AS area_control,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), 'CONSUMIBLE') AS tipo_control,
            COALESCE(p.clm_alm_producto_stock_minimo, 0) AS stock_min,
            COALESCE(v.Stock_Actual, 0) AS stock,
            COALESCE(p.clm_alm_producto_prec_unit, 0) AS precio,
            CASE
                WHEN COALESCE(v.Tiene_Movimientos, 0) = 1
                     OR EXISTS (
                        SELECT 1
                        FROM tb_alm_movimientos mx
                        WHERE mx.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
                        LIMIT 1
                     )
                THEN 1 ELSE 0
            END AS tiene_movimientos
        FROM tb_alm_producto p
        JOIN tb_alm_categoria c ON c.clm_alm_categoria_id = p.clm_alm_producto_idCATEGORIA
        LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.clm_alm_producto_id DESC
        LIMIT 80
    ", $types, $params);

    if (!alm_can_edit_prices()) {
        foreach ($rows as &$row) {
            $row['precio'] = 0;
        }
        unset($row);
    }

    alm_json([
        'ok' => true,
        'rows' => $rows,
        'context' => [
            'origin_id' => $originId,
            'area_control' => $context['area_control'],
            'tipo_control' => $context['tipo_control'],
            'serie_entrada' => $context['serie_entrada'],
            'serie_salida' => $context['serie_salida'],
            'nota_modulo' => $context['nota_modulo'],
        ],
    ]);
}

function alm_action_buses(mysqli $conn): void {
    $q = alm_clean_text($_GET['q'] ?? '', 80);
    if ($q === '') {
        alm_json(['ok' => true, 'rows' => []]);
    }

    $rows = alm_fetch_all($conn, "
        SELECT
            clm_placas_id AS id,
            COALESCE(NULLIF(TRIM(clm_placas_BUS), ''), CONCAT('Unidad ', clm_placas_id)) AS bus,
            COALESCE(NULLIF(TRIM(clm_placas_PLACA), ''), '-') AS placa,
            COALESCE(NULLIF(TRIM(`clm_placas_DUEÑO`), ''), '') AS dueno
        FROM tb_placas
        WHERE clm_placas_ESTADO = 'Activo'
          AND (
            CONVERT(clm_placas_BUS USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
            OR REPLACE(CONVERT(clm_placas_PLACA USING utf8mb4), '-', '') COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', REPLACE(? COLLATE utf8mb4_unicode_ci, '-', ''), '%')
            OR CONVERT(clm_placas_PLACA USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
          )
        ORDER BY clm_placas_BUS ASC
        LIMIT 12
    ", 'sss', [$q, $q, $q]);

    alm_json(['ok' => true, 'rows' => $rows]);
}

function alm_action_trabajadores(mysqli $conn): void {
    $q = alm_clean_text($_GET['q'] ?? '', 80);
    $types = '';
    $params = [];
    $where = '1=1';

    if ($q !== '') {
        $where = "(
            CONVERT(clm_tra_nombres USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
            OR CONVERT(clm_tra_dni USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
            OR CONVERT(clm_tra_cargo USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        )";
        $types = 'sss';
        $params = [$q, $q, $q];
    }

    $rows = alm_fetch_all($conn, "
        SELECT
            clm_tra_id AS id,
            COALESCE(NULLIF(TRIM(clm_tra_nombres), ''), CONCAT('Trabajador ', clm_tra_id)) AS nombre,
            COALESCE(NULLIF(TRIM(clm_tra_dni), ''), '-') AS dni,
            COALESCE(NULLIF(TRIM(clm_tra_cargo), ''), '') AS cargo,
            COALESCE(NULLIF(TRIM(clm_tra_tipo_trabajador), ''), '') AS tipo
        FROM tb_trabajador
        WHERE {$where}
        ORDER BY clm_tra_nombres ASC
        LIMIT 20
    ", $types, $params);

    alm_json(['ok' => true, 'rows' => $rows]);
}
