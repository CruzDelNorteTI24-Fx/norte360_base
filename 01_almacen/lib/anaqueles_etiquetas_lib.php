<?php
if (!defined('N360_ALM_ANAQUELES')) {
    exit('Acceso no permitido.');
}

function alm_ana_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function alm_ana_is_admin(): bool {
    return (($_SESSION['web_rol'] ?? '') === 'Admin');
}

function alm_ana_can_access(): bool {
    if (empty($_SESSION['usuario']) && empty($_SESSION['id_usuario'])) {
        return false;
    }

    if (alm_ana_is_admin()) {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') {
        return true;
    }

    $permisos = array_map('intval', (array)$permisos);
    return in_array(3, $permisos, true);
}

function alm_ana_require_access(string $vista = 'Anaqueles y etiquetas'): void {
    if (empty($_SESSION['usuario']) && empty($_SESSION['id_usuario'])) {
        header('Location: ../login/login.php');
        exit;
    }

    if (!alm_ana_can_access()) {
        header('Location: ../login/none_permisos.php?vista=' . urlencode($vista));
        exit;
    }
}

function alm_ana_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function alm_ana_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }

    alm_ana_bind_params($stmt, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error ?: 'No se pudo ejecutar la consulta.');
    }

    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function alm_ana_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = alm_ana_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function alm_ana_fmt_num($value): string {
    if ($value === null || $value === '') {
        return '0';
    }

    $number = (float)str_replace(',', '.', (string)$value);
    $text = number_format($number, 3, '.', '');
    return rtrim(rtrim($text, '0'), '.') ?: '0';
}

function alm_ana_safe_filename(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'ANAQUEL';
    }

    $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'ANAQUEL';
    return trim($value, '_') ?: 'ANAQUEL';
}

function alm_ana_public_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = rtrim($dir, '/');

    return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
}

function alm_ana_qr_url(array $anaquel): string {
    $codigo = trim((string)($anaquel['codigo'] ?? ''));
    $id = (int)($anaquel['id'] ?? 0);
    $params = $codigo !== '' ? ['codigo' => $codigo] : ['id' => $id];

    return alm_ana_public_base_url() . '/anaquel_contenido.php?' . http_build_query($params);
}

function alm_ana_sedes(mysqli $conn): array {
    return alm_ana_fetch_all($conn, "
        SELECT clm_sedes_id AS id, clm_sedes_name AS nombre
        FROM tb_sedes
        ORDER BY clm_sedes_name ASC
    ");
}

function alm_ana_resumen(mysqli $conn, string $sede = 'TODAS'): array {
    $types = '';
    $params = [];
    $sedeWhere = '';
    if ($sede !== '' && $sede !== 'TODAS') {
        $sedeWhere = ' AND a.clm_alm_anaquel_idSEDE = ? ';
        $types .= 'i';
        $params[] = (int)$sede;
    }

    $row = alm_ana_fetch_one($conn, "
        SELECT
            COUNT(DISTINCT a.clm_alm_anaquel_id) AS anaqueles,
            COUNT(DISTINCT a.clm_alm_anaquel_idSEDE) AS sedes,
            COALESCE(SUM(x.etiquetas), 0) AS etiquetas,
            COALESCE(SUM(x.productos), 0) AS productos
        FROM tb_alm_anaquel a
        LEFT JOIN (
            SELECT
                e.clm_alm_etiquetado_anaquel AS anaquel_id,
                COUNT(*) AS etiquetas,
                COUNT(DISTINCT e.clm_alm_etiquetado_idPRODUCTO) AS productos
            FROM tb_alm_etiquetado e
            JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
            WHERE e.clm_alm_etiquetado_anaquel IS NOT NULL
              AND UPPER(COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'GENERADO')) <> 'CONSUMIDO'
              AND (m.clm_alm_mov_orgn IS NULL OR m.clm_alm_mov_orgn = 1)
            GROUP BY e.clm_alm_etiquetado_anaquel
        ) x ON x.anaquel_id = a.clm_alm_anaquel_id
        WHERE COALESCE(a.clm_alm_anaquel_estado, 1) = 1
        {$sedeWhere}
    ", $types, $params);

    return [
        'anaqueles' => (int)($row['anaqueles'] ?? 0),
        'sedes' => (int)($row['sedes'] ?? 0),
        'etiquetas' => (int)($row['etiquetas'] ?? 0),
        'productos' => (int)($row['productos'] ?? 0),
    ];
}

function alm_ana_listar_anaqueles(mysqli $conn, array $filters = []): array {
    $buscar = trim((string)($filters['buscar'] ?? ''));
    $sede = trim((string)($filters['sede'] ?? 'TODAS'));
    $where = ['COALESCE(a.clm_alm_anaquel_estado, 1) = 1'];
    $types = '';
    $params = [];

    if ($sede !== '' && $sede !== 'TODAS') {
        $where[] = 'a.clm_alm_anaquel_idSEDE = ?';
        $types .= 'i';
        $params[] = (int)$sede;
    }

    if ($buscar !== '') {
        $where[] = "(
            CAST(a.clm_alm_anaquel_id AS CHAR) LIKE CONCAT('%', ?, '%')
            OR a.clm_alm_anaquel_nombre LIKE CONCAT('%', ?, '%')
            OR a.clm_alm_anaquel_codigo LIKE CONCAT('%', ?, '%')
            OR s.clm_sedes_name LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'ssss';
        $params[] = $buscar;
        $params[] = $buscar;
        $params[] = $buscar;
        $params[] = $buscar;
    }

    return alm_ana_fetch_all($conn, "
        SELECT
            a.clm_alm_anaquel_id AS id,
            a.clm_alm_anaquel_nombre AS nombre,
            a.clm_alm_anaquel_codigo AS codigo,
            a.clm_alm_anaquel_idSEDE AS sede_id,
            COALESCE(NULLIF(TRIM(s.clm_sedes_name), ''), CONCAT('Sede ', a.clm_alm_anaquel_idSEDE)) AS sede,
            COALESCE(x.etiquetas, 0) AS etiquetas,
            COALESCE(x.productos, 0) AS productos,
            x.ultima_fecha
        FROM tb_alm_anaquel a
        LEFT JOIN tb_sedes s ON s.clm_sedes_id = a.clm_alm_anaquel_idSEDE
        LEFT JOIN (
            SELECT
                e.clm_alm_etiquetado_anaquel AS anaquel_id,
                COUNT(*) AS etiquetas,
                COUNT(DISTINCT e.clm_alm_etiquetado_idPRODUCTO) AS productos,
                MAX(e.clm_alm_etiquetado_FECHA) AS ultima_fecha
            FROM tb_alm_etiquetado e
            JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
            WHERE e.clm_alm_etiquetado_anaquel IS NOT NULL
              AND UPPER(COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'GENERADO')) <> 'CONSUMIDO'
              AND (m.clm_alm_mov_orgn IS NULL OR m.clm_alm_mov_orgn = 1)
            GROUP BY e.clm_alm_etiquetado_anaquel
        ) x ON x.anaquel_id = a.clm_alm_anaquel_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.clm_sedes_name ASC, a.clm_alm_anaquel_nombre ASC
    ", $types, $params);
}

function alm_ana_buscar_anaquel(mysqli $conn, array $input): ?array {
    $id = (int)($input['id'] ?? 0);
    $codigo = trim((string)($input['codigo'] ?? ''));

    if ($id <= 0 && $codigo === '') {
        return null;
    }

    $where = [];
    $types = '';
    $params = [];

    if ($id > 0) {
        $where[] = 'a.clm_alm_anaquel_id = ?';
        $types .= 'i';
        $params[] = $id;
    }

    if ($codigo !== '') {
        $where[] = 'a.clm_alm_anaquel_codigo = ?';
        $types .= 's';
        $params[] = $codigo;
    }

    return alm_ana_fetch_one($conn, "
        SELECT
            a.clm_alm_anaquel_id AS id,
            a.clm_alm_anaquel_nombre AS nombre,
            a.clm_alm_anaquel_codigo AS codigo,
            a.clm_alm_anaquel_idSEDE AS sede_id,
            COALESCE(NULLIF(TRIM(s.clm_sedes_name), ''), CONCAT('Sede ', a.clm_alm_anaquel_idSEDE)) AS sede
        FROM tb_alm_anaquel a
        LEFT JOIN tb_sedes s ON s.clm_sedes_id = a.clm_alm_anaquel_idSEDE
        WHERE COALESCE(a.clm_alm_anaquel_estado, 1) = 1
          AND (" . implode(' OR ', $where) . ")
        LIMIT 1
    ", $types, $params);
}

function alm_ana_contenido_productos(mysqli $conn, int $anaquelId): array {
    return alm_ana_fetch_all($conn, "
        SELECT
            p.clm_alm_producto_id AS id_producto,
            p.clm_alm_producto_codigo AS codigo_producto,
            p.clm_alm_producto_NOMBRE AS producto,
            p.clm_alm_producto_unidad AS unidad,
            p.clm_alm_producto_IMG AS producto_img,
            c.clm_alm_categoria_NOMBRE AS categoria_codigo,
            c.clm_alm_categoria_DESCRIPCION AS categoria,
            COALESCE(v.Stock_Actual, 0) AS stock_actual,
            COALESCE(v.Estado, '') AS estado_stock,
            COUNT(*) AS unidades,
            MAX(e.clm_alm_etiquetado_FECHA) AS ultima_fecha,
            GROUP_CONCAT(COALESCE(NULLIF(TRIM(e.clm_etiquetado_CODIGO), ''), CONCAT('ETQ-', e.clm_alm_etiquetado_id)) ORDER BY e.clm_alm_etiquetado_id ASC SEPARATOR ', ') AS etiquetas
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = e.clm_alm_etiquetado_idPRODUCTO
        LEFT JOIN tb_alm_categoria c ON c.clm_alm_categoria_id = p.clm_alm_producto_idCATEGORIA
        LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
        JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
        WHERE e.clm_alm_etiquetado_anaquel = ?
          AND UPPER(COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'GENERADO')) <> 'CONSUMIDO'
          AND (m.clm_alm_mov_orgn IS NULL OR m.clm_alm_mov_orgn = 1)
        GROUP BY
            p.clm_alm_producto_id,
            p.clm_alm_producto_codigo,
            p.clm_alm_producto_NOMBRE,
            p.clm_alm_producto_unidad,
            p.clm_alm_producto_IMG,
            c.clm_alm_categoria_NOMBRE,
            c.clm_alm_categoria_DESCRIPCION,
            v.Stock_Actual,
            v.Estado
        ORDER BY c.clm_alm_categoria_NOMBRE ASC, p.clm_alm_producto_NOMBRE ASC
    ", 'i', [$anaquelId]);
}

function alm_ana_contenido_etiquetas(mysqli $conn, int $anaquelId, int $limit = 500): array {
    return alm_ana_fetch_all($conn, "
        SELECT
            e.clm_alm_etiquetado_id AS id,
            COALESCE(NULLIF(TRIM(e.clm_etiquetado_CODIGO), ''), CONCAT('ETQ-', e.clm_alm_etiquetado_id)) AS codigo,
            e.clm_alm_etiquetado_FECHA AS fecha,
            COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'GENERADO') AS estado,
            p.clm_alm_producto_codigo AS codigo_producto,
            p.clm_alm_producto_NOMBRE AS producto,
            p.clm_alm_producto_unidad AS unidad,
            m.clm_alm_mov_TIPO AS tipo_movimiento,
            m.clm_alm_mov_OBSERVACION AS observacion,
            COALESCE(ns.clm_nota_sco, '') AS nota
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = e.clm_alm_etiquetado_idPRODUCTO
        JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
        LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
        WHERE e.clm_alm_etiquetado_anaquel = ?
          AND UPPER(COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'GENERADO')) <> 'CONSUMIDO'
          AND (m.clm_alm_mov_orgn IS NULL OR m.clm_alm_mov_orgn = 1)
        ORDER BY e.clm_alm_etiquetado_id DESC
        LIMIT ?
    ", 'ii', [$anaquelId, $limit]);
}
