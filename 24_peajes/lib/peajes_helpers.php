<?php
if (!defined('N360_PEAJES')) {
    exit('Acceso no permitido.');
}

function pje_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pje_is_admin(): bool {
    return (($_SESSION['web_rol'] ?? '') === 'Admin');
}

function pje_can_modulo(int $moduleId = 4): bool {
    if (pje_is_admin()) {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') {
        return true;
    }

    if (!is_array($permisos)) {
        return false;
    }

    return in_array($moduleId, array_map('intval', $permisos), true);
}

function pje_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($params as &$param) {
        $refs[] = &$param;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function pje_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }

    pje_bind_params($stmt, $types, $params);
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

function pje_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $rows = pje_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function pje_valid_date($value, string $fallback): string {
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return ($date && $date->format('Y-m-d') === (string)$value) ? (string)$value : $fallback;
}

function pje_default_range(mysqli $conn): array {
    try {
        $row = pje_fetch_one($conn, "
            SELECT MAX(clm_pje_FECHA) AS max_fecha
            FROM tb_peajes
            WHERE clm_pje_FECHA IS NOT NULL
        ");
    } catch (Throwable $e) {
        $row = [];
    }

    $hasta = pje_valid_date($row['max_fecha'] ?? '', date('Y-m-d'));

    return [
        'desde' => date('Y-m-01', strtotime($hasta)),
        'hasta' => $hasta,
    ];
}

function pje_current_filters(?string $defaultDesde = null, ?string $defaultHasta = null): array {
    $today = $defaultHasta ?: date('Y-m-d');
    $firstDay = $defaultDesde ?: date('Y-m-01', strtotime($today));

    $desde = pje_valid_date($_GET['desde'] ?? '', $firstDay);
    $hasta = pje_valid_date($_GET['hasta'] ?? '', $today);

    if (strtotime($hasta) < strtotime($desde)) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    return [
        'desde' => $desde,
        'hasta' => $hasta,
        'estacion' => trim((string)($_GET['estacion'] ?? 'TODOS')),
        'usuario' => trim((string)($_GET['usuario'] ?? 'TODOS')),
        'importacion' => trim((string)($_GET['importacion'] ?? 'TODOS')),
        'placa' => trim((string)($_GET['placa'] ?? '')),
        'factura' => trim((string)($_GET['factura'] ?? '')),
        'buscar' => trim((string)($_GET['buscar'] ?? '')),
    ];
}

function pje_where(array $filters): array {
    $where = ['p.clm_pje_FECHA >= ?', 'p.clm_pje_FECHA <= ?'];
    $types = 'ss';
    $params = [$filters['desde'], $filters['hasta']];

    if (($filters['estacion'] ?? 'TODOS') !== '' && ($filters['estacion'] ?? 'TODOS') !== 'TODOS') {
        $where[] = 'CAST(p.clm_pje_ESTACION_PEAJE AS CHAR) = ?';
        $types .= 's';
        $params[] = $filters['estacion'];
    }

    if (($filters['usuario'] ?? 'TODOS') !== '' && ($filters['usuario'] ?? 'TODOS') !== 'TODOS') {
        $where[] = 'CAST(p.clm_pje_USUARIO AS CHAR) = ?';
        $types .= 's';
        $params[] = $filters['usuario'];
    }

    if (($filters['importacion'] ?? 'TODOS') !== '' && ($filters['importacion'] ?? 'TODOS') !== 'TODOS') {
        $where[] = 'CAST(p.clm_pje_codimportacion AS CHAR) = ?';
        $types .= 's';
        $params[] = $filters['importacion'];
    }

    if (($filters['placa'] ?? '') !== '') {
        $where[] = 'CAST(p.clm_pje_NRO_PLACA AS CHAR) LIKE CONCAT("%", ?, "%")';
        $types .= 's';
        $params[] = $filters['placa'];
    }

    if (($filters['factura'] ?? '') !== '') {
        $where[] = 'CAST(p.clm_pje_NFACTURA AS CHAR) LIKE CONCAT("%", ?, "%")';
        $types .= 's';
        $params[] = $filters['factura'];
    }

    if (($filters['buscar'] ?? '') !== '') {
        $where[] = "(
            CAST(p.clm_pje_NFACTURA AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(p.clm_pje_NRO_PLACA AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(p.clm_pje_GLOSA AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(p.clm_pje_ESTACION_PEAJE AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(p.clm_pje_RUTADOC AS CHAR) LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'sssss';
        array_push($params, $filters['buscar'], $filters['buscar'], $filters['buscar'], $filters['buscar'], $filters['buscar']);
    }

    return [
        'sql' => implode(' AND ', $where),
        'types' => $types,
        'params' => $params,
    ];
}

function pje_join_placas_sql(): string {
    return "
        LEFT JOIN tb_placas pl
            ON TRIM(CAST(p.clm_pje_NRO_PLACA AS CHAR)) COLLATE utf8mb4_unicode_ci
             = TRIM(CAST(pl.clm_placas_PLACA AS CHAR)) COLLATE utf8mb4_unicode_ci
    ";
}

function pje_fetch_options(mysqli $conn, string $option): array {
    $columns = [
        'estacion' => 'clm_pje_ESTACION_PEAJE',
        'usuario' => 'clm_pje_USUARIO',
        'importacion' => 'clm_pje_codimportacion',
    ];

    if (!isset($columns[$option])) {
        return [];
    }

    $column = $columns[$option];
    return pje_fetch_all($conn, "
        SELECT DISTINCT CAST($column AS CHAR) AS value
        FROM tb_peajes
        WHERE $column IS NOT NULL
          AND TRIM(CAST($column AS CHAR)) <> ''
        ORDER BY value ASC
        LIMIT 300
    ");
}

function pje_fetch_kpis(mysqli $conn, array $filters): array {
    $where = pje_where($filters);
    return pje_fetch_one($conn, "
        SELECT
            COUNT(*) AS registros,
            COUNT(DISTINCT NULLIF(TRIM(CAST(p.clm_pje_NFACTURA AS CHAR)), '')) AS facturas,
            COUNT(DISTINCT NULLIF(TRIM(CAST(p.clm_pje_NRO_PLACA AS CHAR)), '')) AS placas,
            COALESCE(SUM(p.clm_pje_BASENOGRAVSOLES), 0) AS base,
            COALESCE(SUM(p.clm_pje_IMPORTE), 0) AS importe,
            COALESCE(SUM(p.clm_pje_IGV), 0) AS igv,
            COALESCE(SUM(p.clm_pje_TSD), 0) AS tsd,
            COALESCE(SUM(p.clm_pje_TOTAL), 0) AS total,
            COALESCE(SUM(p.clm_pje_DETRACCION), 0) AS detraccion
        FROM tb_peajes p
        WHERE {$where['sql']}
    ", $where['types'], $where['params']);
}

function pje_count_rows(mysqli $conn, array $filters): int {
    $where = pje_where($filters);
    $row = pje_fetch_one($conn, "
        SELECT COUNT(*) AS total
        FROM tb_peajes p
        WHERE {$where['sql']}
    ", $where['types'], $where['params']);

    return (int)($row['total'] ?? 0);
}

function pje_fetch_rows(mysqli $conn, array $filters, int $limit, int $offset): array {
    $where = pje_where($filters);
    $params = $where['params'];
    $types = $where['types'] . 'ii';
    $params[] = $limit;
    $params[] = $offset;

    return pje_fetch_all($conn, "
        SELECT
            p.clm_pje_id,
            p.`clm_pje_AÑO` AS anio,
            p.clm_pje_MES AS mes,
            p.clm_pje_FECHA AS fecha,
            p.clm_pje_NFACTURA AS factura,
            p.clm_pje_SERIE AS serie,
            p.clm_pje_NUMERO AS numero,
            p.clm_pje_NRO_PLACA AS placa,
            p.clm_pje_ESTACION_PEAJE AS estacion,
            p.clm_pje_GLOSA AS glosa,
            p.clm_pje_MONEDA AS moneda,
            p.clm_pje_BASENOGRAVSOLES AS base,
            p.clm_pje_IMPORTE AS importe,
            p.clm_pje_IGV AS igv,
            p.clm_pje_TSD AS tsd,
            p.clm_pje_TOTAL AS total,
            p.clm_pje_DETRACCION AS detraccion,
            p.clm_pje_CLASIFICACION AS clasificacion,
            p.clm_pje_GRUPO AS grupo,
            p.clm_pje_RUTADOC AS ruta_doc,
            p.clm_pje_USUARIO AS usuario,
            p.clm_pje_fechaimportacion AS fecha_importacion,
            p.clm_pje_codimportacion AS cod_importacion,
            pl.clm_placas_BUS AS bus,
            pl.`clm_placas_DUEÑO` AS dueno,
            pl.`clm_placas_TIPO_VEHÍCULO` AS tipo_vehiculo
        FROM tb_peajes p
        " . pje_join_placas_sql() . "
        WHERE {$where['sql']}
        ORDER BY p.clm_pje_FECHA DESC, p.clm_pje_id DESC
        LIMIT ? OFFSET ?
    ", $types, $params);
}

function pje_fetch_plate_summary(mysqli $conn, array $filters): array {
    $where = pje_where($filters);

    return pje_fetch_all($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(CAST(p.clm_pje_NRO_PLACA AS CHAR)), ''), 'SIN PLACA') AS placa,
            COUNT(*) AS registros,
            COUNT(DISTINCT NULLIF(TRIM(CAST(p.clm_pje_NFACTURA AS CHAR)), '')) AS facturas,
            COALESCE(SUM(p.clm_pje_TOTAL), 0) AS total,
            COALESCE(SUM(p.clm_pje_DETRACCION), 0) AS detraccion,
            MAX(pl.clm_placas_id) AS placa_id,
            MAX(pl.clm_placas_BUS) AS bus,
            MAX(pl.`clm_placas_DUEÑO`) AS dueno,
            MAX(pl.`clm_placas_TIPO_VEHÍCULO`) AS tipo_vehiculo
        FROM tb_peajes p
        " . pje_join_placas_sql() . "
        WHERE {$where['sql']}
        GROUP BY COALESCE(NULLIF(TRIM(CAST(p.clm_pje_NRO_PLACA AS CHAR)), ''), 'SIN PLACA')
        ORDER BY tipo_vehiculo ASC, registros DESC, total DESC, placa ASC
        LIMIT 800
    ", $where['types'], $where['params']);
}

function pje_table_columns(mysqli $conn, string $table): array {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $safeTable = '`' . str_replace('`', '``', $table) . '`';
    $result = $conn->query("SHOW COLUMNS FROM $safeTable");
    if (!$result) {
        throw new RuntimeException($conn->error ?: "No se pudo leer la estructura de $table.");
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = $field;
        }
    }
    $result->free();

    $cache[$table] = $columns;
    return $columns;
}

function pje_pick_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $columns[$candidate];
        }
    }

    $normalized = [];
    foreach ($columns as $column) {
        $key = strtoupper(strtr($column, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N',
        ]));
        $normalized[$key] = $column;
    }

    foreach ($candidates as $candidate) {
        $key = strtoupper(strtr($candidate, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N',
        ]));
        if (isset($normalized[$key])) {
            return $normalized[$key];
        }
    }

    return null;
}

function pje_sql_col(string $column): string {
    return '`' . str_replace('`', '``', $column) . '`';
}

function pje_control_state_key($value): string {
    $text = strtoupper(trim((string)$value));
    return strtr($text, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N',
    ]);
}

function pje_control_state_class($value): string {
    $key = pje_control_state_key($value);
    if (str_contains($key, 'AL DIA')) {
        return 'ok';
    }
    if (str_contains($key, 'ATRAS')) {
        return 'warn';
    }
    if (str_contains($key, 'ACTUALIZA')) {
        return 'info';
    }
    return 'neutral';
}

function pje_default_control_range(mysqli $conn): array {
    try {
        $columns = pje_table_columns($conn, 'tb_control');
        $colFecha = pje_pick_column($columns, ['clm_ctrl_FECHA_RECEPCION', 'clm_ctrl_FECHA']);
        if (!$colFecha) {
            throw new RuntimeException('tb_control no tiene columna de fecha.');
        }

        $fecha = pje_sql_col($colFecha);
        $row = pje_fetch_one($conn, "
            SELECT MAX(DATE($fecha)) AS max_fecha
            FROM tb_control
            WHERE $fecha IS NOT NULL
        ");
    } catch (Throwable $e) {
        $row = [];
    }

    $hasta = pje_valid_date($row['max_fecha'] ?? '', date('Y-m-d'));

    return [
        'desde' => date('Y-m-01', strtotime($hasta)),
        'hasta' => $hasta,
    ];
}

function pje_control_filters(?string $defaultDesde = null, ?string $defaultHasta = null): array {
    $today = $defaultHasta ?: date('Y-m-d');
    $firstDay = $defaultDesde ?: date('Y-m-01', strtotime($today));

    $desde = pje_valid_date($_GET['desde'] ?? '', $firstDay);
    $hasta = pje_valid_date($_GET['hasta'] ?? '', $today);

    if (strtotime($hasta) < strtotime($desde)) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    return [
        'desde' => $desde,
        'hasta' => $hasta,
        'nombre' => trim((string)($_GET['nombre'] ?? 'TODOS')),
        'estado' => trim((string)($_GET['estado'] ?? 'TODOS')),
        'buscar' => trim((string)($_GET['buscar'] ?? '')),
    ];
}

function pje_fetch_control_options(mysqli $conn, string $option): array {
    try {
        $columns = pje_table_columns($conn, 'tb_control');
    } catch (Throwable $e) {
        return [];
    }

    $candidates = [
        'nombre' => ['clm_ctrl_NOMBRE'],
        'estado' => ['clm_ctrl_ESTADO'],
    ];

    if (!isset($candidates[$option])) {
        return [];
    }

    $column = pje_pick_column($columns, $candidates[$option]);
    if (!$column) {
        return [];
    }

    $sqlCol = pje_sql_col($column);
    return pje_fetch_all($conn, "
        SELECT DISTINCT CAST($sqlCol AS CHAR) AS value
        FROM tb_control
        WHERE $sqlCol IS NOT NULL
          AND TRIM(CAST($sqlCol AS CHAR)) <> ''
        ORDER BY value ASC
        LIMIT 300
    ");
}

function pje_fetch_control_summary(mysqli $conn, array $filters): array {
    try {
        $columns = pje_table_columns($conn, 'tb_control');
    } catch (Throwable $e) {
        return [
            'available' => false,
            'message' => 'No se encontro tb_control para mostrar control fisico.',
            'totals' => ['registros' => 0, 'cantidad' => 0, 'al_dia' => 0, 'atrasados' => 0, 'actualiza' => 0],
            'by_name' => [],
            'groups' => [],
        ];
    }

    $colFecha = pje_pick_column($columns, ['clm_ctrl_FECHA_RECEPCION', 'clm_ctrl_FECHA']);
    $colNombre = pje_pick_column($columns, ['clm_ctrl_NOMBRE']);
    $colCantidad = pje_pick_column($columns, ['clm_ctrl_CANTIDAD']);
    $colPlaca = pje_pick_column($columns, ['clm_ctrl_PLACA']);
    $colDetalle = pje_pick_column($columns, ['clm_ctrl_DETALLE']);
    $colGrupo = pje_pick_column($columns, ['clm_ctrl_GRUPO']);
    $colCodigo = pje_pick_column($columns, ['clm_ctrl_CODIGO']);
    $colEstado = pje_pick_column($columns, ['clm_ctrl_ESTADO']);

    if (!$colFecha || !$colNombre || !$colCantidad || !$colGrupo || !$colCodigo || !$colEstado) {
        return [
            'available' => false,
            'message' => 'tb_control no tiene todas las columnas necesarias para el resumen fisico.',
            'totals' => ['registros' => 0, 'cantidad' => 0, 'al_dia' => 0, 'atrasados' => 0, 'actualiza' => 0],
            'by_name' => [],
            'groups' => [],
        ];
    }

    $fecha = pje_sql_col($colFecha);
    $nombre = pje_sql_col($colNombre);
    $cantidad = pje_sql_col($colCantidad);
    $placa = $colPlaca ? pje_sql_col($colPlaca) : "''";
    $detalle = $colDetalle ? pje_sql_col($colDetalle) : "''";
    $grupo = pje_sql_col($colGrupo);
    $codigo = pje_sql_col($colCodigo);
    $estado = pje_sql_col($colEstado);

    $desde = $filters['desde'] ?? date('Y-m-01');
    $hasta = $filters['hasta'] ?? date('Y-m-d');
    $where = ["DATE($fecha) >= ?", "DATE($fecha) <= ?"];
    $types = 'ss';
    $params = [$desde, $hasta];

    if (($filters['nombre'] ?? 'TODOS') !== '' && ($filters['nombre'] ?? 'TODOS') !== 'TODOS') {
        $where[] = "CAST($nombre AS CHAR) = ?";
        $types .= 's';
        $params[] = $filters['nombre'];
    }

    if (($filters['estado'] ?? 'TODOS') !== '' && ($filters['estado'] ?? 'TODOS') !== 'TODOS') {
        $where[] = "CAST($estado AS CHAR) = ?";
        $types .= 's';
        $params[] = $filters['estado'];
    }

    if (($filters['buscar'] ?? '') !== '') {
        $where[] = "(
            CAST($nombre AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST($grupo AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST($codigo AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST($estado AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST($placa AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST($detalle AS CHAR) LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'ssssss';
        array_push($params, $filters['buscar'], $filters['buscar'], $filters['buscar'], $filters['buscar'], $filters['buscar'], $filters['buscar']);
    }

    $whereSql = implode(' AND ', $where);

    $rawByName = pje_fetch_all($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(CAST($nombre AS CHAR)), ''), 'SIN NOMBRE') AS nombre,
            COALESCE(NULLIF(TRIM(CAST($estado AS CHAR)), ''), 'SIN ESTADO') AS estado,
            COUNT(*) AS registros,
            COALESCE(SUM(CAST($cantidad AS DECIMAL(18,4))), 0) AS cantidad,
            COUNT(DISTINCT NULLIF(TRIM(CAST($grupo AS CHAR)), '')) AS grupos,
            COUNT(DISTINCT NULLIF(TRIM(CAST($codigo AS CHAR)), '')) AS codigos
        FROM tb_control
        WHERE $whereSql
        GROUP BY nombre, estado
        ORDER BY nombre ASC, estado ASC
    ", $types, $params);

    $byName = [];
    $totals = [
        'registros' => 0,
        'cantidad' => 0.0,
        'al_dia' => 0.0,
        'atrasados' => 0.0,
        'actualiza' => 0.0,
    ];

    foreach ($rawByName as $row) {
        $name = (string)($row['nombre'] ?? 'SIN NOMBRE');
        if (!isset($byName[$name])) {
            $byName[$name] = [
                'nombre' => $name,
                'registros' => 0,
                'cantidad' => 0.0,
                'al_dia' => 0.0,
                'atrasados' => 0.0,
                'actualiza' => 0.0,
                'grupos' => 0,
                'codigos' => 0,
            ];
        }

        $qty = (float)($row['cantidad'] ?? 0);
        $registros = (int)($row['registros'] ?? 0);
        $stateKey = pje_control_state_key($row['estado'] ?? '');

        $byName[$name]['registros'] += $registros;
        $byName[$name]['cantidad'] += $qty;
        $byName[$name]['grupos'] += (int)($row['grupos'] ?? 0);
        $byName[$name]['codigos'] += (int)($row['codigos'] ?? 0);

        $totals['registros'] += $registros;
        $totals['cantidad'] += $qty;

        if (str_contains($stateKey, 'AL DIA')) {
            $byName[$name]['al_dia'] += $qty;
            $totals['al_dia'] += $qty;
        } elseif (str_contains($stateKey, 'ATRAS')) {
            $byName[$name]['atrasados'] += $qty;
            $totals['atrasados'] += $qty;
        } elseif (str_contains($stateKey, 'ACTUALIZA')) {
            $byName[$name]['actualiza'] += $qty;
            $totals['actualiza'] += $qty;
        }
    }

    $groups = pje_fetch_all($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(CAST($grupo AS CHAR)), ''), 'SIN GRUPO') AS grupo,
            COALESCE(NULLIF(TRIM(CAST($nombre AS CHAR)), ''), 'SIN NOMBRE') AS nombre,
            COALESCE(NULLIF(TRIM(CAST($estado AS CHAR)), ''), 'SIN ESTADO') AS estado,
            COUNT(*) AS registros,
            COALESCE(SUM(CAST($cantidad AS DECIMAL(18,4))), 0) AS cantidad,
            COUNT(DISTINCT NULLIF(TRIM(CAST($placa AS CHAR)), '')) AS placas,
            MIN(DATE($fecha)) AS primera_fecha,
            MAX(DATE($fecha)) AS ultima_fecha,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(CAST($codigo AS CHAR)), '') ORDER BY $codigo SEPARATOR ', ') AS codigos,
            SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(TRIM(CAST($detalle AS CHAR)), '') ORDER BY $detalle SEPARATOR ' | '), ' | ', 1) AS detalle
        FROM tb_control
        WHERE $whereSql
        GROUP BY grupo, nombre, estado
        ORDER BY ultima_fecha DESC, grupo ASC
        LIMIT 160
    ", $types, $params);

    return [
        'available' => true,
        'message' => '',
        'totals' => $totals,
        'by_name' => array_values($byName),
        'groups' => $groups,
    ];
}

function pje_money($value): string {
    return 'S/ ' . number_format((float)($value ?? 0), 2, '.', ',');
}

function pje_num($value, int $decimals = 0): string {
    return number_format((float)($value ?? 0), $decimals, '.', ',');
}

function pje_date($value): string {
    if (!$value) {
        return '-';
    }

    $time = strtotime((string)$value);
    return $time ? date('d/m/Y', $time) : (string)$value;
}

function pje_text($value, string $fallback = '-'): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function pje_vehicle_label($bus, $placa): string {
    $bus = trim((string)($bus ?? ''));
    $placa = trim((string)($placa ?? ''));

    if ($bus !== '' && $placa !== '') {
        return $bus . ' (' . $placa . ')';
    }
    if ($bus !== '') {
        return $bus;
    }
    if ($placa !== '') {
        return $placa;
    }
    return '-';
}

function pje_query(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}
