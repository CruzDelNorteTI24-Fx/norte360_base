<?php
if (!defined('N360_COMB_REG')) {
    exit('Acceso no permitido.');
}

function comb_reg_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function comb_reg_json(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function comb_reg_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function comb_reg_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }

    comb_reg_bind($stmt, $types, $params);
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

function comb_reg_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = comb_reg_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function comb_reg_clean_text($value, int $max = 800): string {
    $value = trim((string)($value ?? ''));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function comb_reg_float($value): float {
    $value = trim((string)($value ?? ''));
    $value = str_replace(['S/.', 'S/', ' ', ','], ['', '', '', ''], $value);
    return is_numeric($value) ? (float)$value : 0.0;
}

function comb_reg_fmt_qty($value): string {
    $formatted = number_format((float)($value ?? 0), 4, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function comb_reg_fmt_money($value): string {
    return 'S/ ' . number_format((float)($value ?? 0), 2, '.', ',');
}

function comb_reg_fmt_pu($value): string {
    return number_format((float)($value ?? 0), 4, '.', ',');
}

function comb_reg_is_admin(): bool {
    return ($_SESSION['web_rol'] ?? '') === 'Admin';
}

function comb_reg_can_modulo(int $moduloId): bool {
    if (comb_reg_is_admin()) {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') {
        return true;
    }
    if (!is_array($permisos)) {
        return false;
    }

    return in_array($moduloId, array_map('intval', $permisos), true);
}

function comb_reg_user_id(mysqli $conn): int {
    if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
        return (int)$_SESSION['id_usuario'];
    }

    $usuario = trim((string)($_SESSION['usuario'] ?? ''));
    if ($usuario === '') {
        return 0;
    }

    $row = comb_reg_fetch_one(
        $conn,
        'SELECT id_usuario FROM tb_usuarios WHERE usuario = ? LIMIT 1',
        's',
        [$usuario]
    );

    return $row && is_numeric($row['id_usuario'] ?? null) ? (int)$row['id_usuario'] : 0;
}

function comb_reg_validate_csrf(array $payload): void {
    $expected = (string)($_SESSION['comb_reg_csrf'] ?? '');
    $received = (string)($payload['csrf'] ?? ($_SERVER['HTTP_X_N360_CSRF'] ?? ''));

    if ($expected === '' || !hash_equals($expected, $received)) {
        comb_reg_json(['ok' => false, 'message' => 'Token de seguridad invalido. Actualiza la pagina e intenta de nuevo.'], 419);
    }
}

function comb_reg_validate_config_password(mysqli $conn, string $configKey, string $password, string $contextLabel): void {
    $password = trim($password);
    if ($password === '') {
        comb_reg_json(['ok' => false, 'message' => 'Ingresa la contrasena de seguridad para confirmar ' . $contextLabel . '.'], 422);
    }

    $row = comb_reg_fetch_one(
        $conn,
        "SELECT valor FROM tb_config_sistema WHERE clave = ? AND estado = 1 LIMIT 1",
        's',
        [$configKey]
    );
    $stored = trim((string)($row['valor'] ?? ''));

    if ($stored === '') {
        comb_reg_json(['ok' => false, 'message' => 'No se encontro la contrasena de seguridad configurada. Contacta al administrador.'], 500);
    }

    $matchesPlain = hash_equals($stored, $password);
    $matchesSha256 = (bool)preg_match('/^[a-f0-9]{64}$/i', $stored) && hash_equals(strtolower($stored), hash('sha256', $password));

    if (!$matchesPlain && !$matchesSha256) {
        comb_reg_json(['ok' => false, 'message' => 'Contrasena de seguridad incorrecta. El movimiento no fue registrado.'], 403);
    }
}

function comb_reg_productos(mysqli $conn): array {
    try {
        return comb_reg_fetch_all(
            $conn,
            "SELECT
                clm_alm_producto_id AS id,
                COALESCE(NULLIF(TRIM(clm_alm_producto_codigo), ''), CONCAT('COMB', clm_alm_producto_id)) AS codigo,
                COALESCE(NULLIF(TRIM(clm_alm_producto_NOMBRE), ''), CONCAT('Combustible ', clm_alm_producto_id)) AS nombre,
                COALESCE(NULLIF(TRIM(clm_alm_producto_unidad), ''), 'GLN') AS unidad,
                COALESCE(clm_alm_producto_prec_unit, 0) AS precio_unitario
             FROM view_listprod_combustibles
             ORDER BY clm_alm_producto_NOMBRE ASC"
        );
    } catch (Throwable $e) {
        return comb_reg_fetch_all(
            $conn,
            "SELECT
                clm_alm_producto_id AS id,
                COALESCE(NULLIF(TRIM(clm_alm_producto_codigo), ''), CONCAT('COMB', clm_alm_producto_id)) AS codigo,
                COALESCE(NULLIF(TRIM(clm_alm_producto_NOMBRE), ''), CONCAT('Combustible ', clm_alm_producto_id)) AS nombre,
                COALESCE(NULLIF(TRIM(clm_alm_producto_unidad), ''), 'GLN') AS unidad,
                COALESCE(clm_alm_producto_prec_unit, 0) AS precio_unitario
             FROM tb_alm_producto
             WHERE clm_alm_producto_idCATEGORIA = 11
             ORDER BY clm_alm_producto_NOMBRE ASC"
        );
    }
}

function comb_reg_grifos(mysqli $conn): array {
    try {
        return comb_reg_fetch_all(
            $conn,
            "SELECT
                clm_esp_id AS id,
                COALESCE(NULLIF(TRIM(clm_esp_nombre), ''), CONCAT('GF', clm_esp_id)) AS codigo,
                COALESCE(NULLIF(TRIM(clm_esp_desc), ''), 'Grifo') AS nombre,
                CONCAT('(', COALESCE(NULLIF(TRIM(clm_esp_nombre), ''), CONCAT('GF', clm_esp_id)), ') ', COALESCE(NULLIF(TRIM(clm_esp_desc), ''), 'Grifo')) AS label
             FROM view_listespacios_combustibles
             ORDER BY clm_esp_nombre ASC, clm_esp_desc ASC"
        );
    } catch (Throwable $e) {
        return comb_reg_fetch_all(
            $conn,
            "SELECT
                clm_esp_id AS id,
                COALESCE(NULLIF(TRIM(clm_esp_nombre), ''), CONCAT('GF', clm_esp_id)) AS codigo,
                COALESCE(NULLIF(TRIM(clm_esp_desc), ''), 'Grifo') AS nombre,
                CONCAT('(', COALESCE(NULLIF(TRIM(clm_esp_nombre), ''), CONCAT('GF', clm_esp_id)), ') ', COALESCE(NULLIF(TRIM(clm_esp_desc), ''), 'Grifo')) AS label
             FROM tb_espacio
             WHERE clm_esp_obs = 2
             ORDER BY clm_esp_nombre ASC, clm_esp_desc ASC"
        );
    }
}

function comb_reg_producto(mysqli $conn, int $productId): ?array {
    return comb_reg_fetch_one(
        $conn,
        "SELECT
            clm_alm_producto_id AS id,
            COALESCE(NULLIF(TRIM(clm_alm_producto_codigo), ''), CONCAT('COMB', clm_alm_producto_id)) AS codigo,
            COALESCE(NULLIF(TRIM(clm_alm_producto_NOMBRE), ''), CONCAT('Combustible ', clm_alm_producto_id)) AS nombre,
            COALESCE(NULLIF(TRIM(clm_alm_producto_unidad), ''), 'GLN') AS unidad,
            COALESCE(clm_alm_producto_prec_unit, 0) AS precio_unitario
         FROM tb_alm_producto
         WHERE clm_alm_producto_id = ?
           AND clm_alm_producto_idCATEGORIA = 11
         LIMIT 1",
        'i',
        [$productId]
    );
}

function comb_reg_grifo_label(mysqli $conn, int $grifoId): string {
    $row = comb_reg_fetch_one(
        $conn,
        "SELECT
            COALESCE(NULLIF(TRIM(clm_esp_nombre), ''), CONCAT('GF', clm_esp_id)) AS codigo,
            COALESCE(NULLIF(TRIM(clm_esp_desc), ''), 'Grifo') AS nombre
         FROM tb_espacio
         WHERE clm_esp_id = ?
         LIMIT 1",
        'i',
        [$grifoId]
    );

    if (!$row) {
        return '';
    }

    return '(' . $row['codigo'] . ') ' . $row['nombre'];
}

function comb_reg_stock_producto_grifo(mysqli $conn, int $productId, int $grifoId): float {
    try {
        $row = comb_reg_fetch_one(
            $conn,
            "SELECT COALESCE(cant_neta, 0) AS stock
             FROM view_combustibles_por_grifo
             WHERE id_producto = ? AND id_grifo = ?
             LIMIT 1",
            'ii',
            [$productId, $grifoId]
        );
        return (float)($row['stock'] ?? 0);
    } catch (Throwable $e) {
        $row = comb_reg_fetch_one(
            $conn,
            "SELECT
                COALESCE(SUM(CASE
                    WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN COALESCE(m.clm_alm_mov_cantidad, 0)
                    WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN -COALESCE(m.clm_alm_mov_cantidad, 0)
                    ELSE 0
                END), 0) AS stock
             FROM tb_alm_movimientos m
             JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
             WHERE p.clm_alm_producto_idCATEGORIA = 11
               AND m.clm_alm_mov_idPRODUCTO = ?
               AND m.clm_alm_mov_orgn = ?",
            'ii',
            [$productId, $grifoId]
        );
        return (float)($row['stock'] ?? 0);
    }
}

function comb_reg_stocks_by_grifo(mysqli $conn, int $grifoId): array {
    if ($grifoId <= 0) {
        return [];
    }

    try {
        $rows = comb_reg_fetch_all(
            $conn,
            "SELECT
                id_producto AS producto_id,
                COALESCE(cant_neta, 0) AS stock
             FROM view_combustibles_por_grifo
             WHERE id_grifo = ?",
            'i',
            [$grifoId]
        );
    } catch (Throwable $e) {
        $rows = comb_reg_fetch_all(
            $conn,
            "SELECT
                m.clm_alm_mov_idPRODUCTO AS producto_id,
                COALESCE(SUM(CASE
                    WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN COALESCE(m.clm_alm_mov_cantidad, 0)
                    WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN -COALESCE(m.clm_alm_mov_cantidad, 0)
                    ELSE 0
                END), 0) AS stock
             FROM tb_alm_movimientos m
             JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
             WHERE p.clm_alm_producto_idCATEGORIA = 11
               AND m.clm_alm_mov_orgn = ?
             GROUP BY m.clm_alm_mov_idPRODUCTO",
            'i',
            [$grifoId]
        );
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['producto_id']] = (float)($row['stock'] ?? 0);
    }

    return $map;
}

function comb_reg_stock_grifo(mysqli $conn, int $grifoId): float {
    try {
        $row = comb_reg_fetch_one(
            $conn,
            "SELECT COALESCE(consumo_actual, 0) AS stock
             FROM view_consumo_actual_grifos
             WHERE id_grifo = ?
             LIMIT 1",
            'i',
            [$grifoId]
        );
        return (float)($row['stock'] ?? 0);
    } catch (Throwable $e) {
        $row = comb_reg_fetch_one(
            $conn,
            "SELECT
                COALESCE(SUM(CASE
                    WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN COALESCE(m.clm_alm_mov_cantidad, 0)
                    WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN -COALESCE(m.clm_alm_mov_cantidad, 0)
                    ELSE 0
                END), 0) AS stock
             FROM tb_alm_movimientos m
             JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
             WHERE p.clm_alm_producto_idCATEGORIA = 11
               AND m.clm_alm_mov_orgn = ?",
            'i',
            [$grifoId]
        );
        return (float)($row['stock'] ?? 0);
    }
}

function comb_reg_pu_salida_ref(mysqli $conn, int $productId, int $grifoId): ?float {
    try {
        $row = comb_reg_fetch_one(
            $conn,
            "SELECT pu_entrada_anterior, pu_promedio_para_salida
             FROM view_precios_salida_grifos
             WHERE id_producto = ? AND id_grifo = ?
             LIMIT 1",
            'ii',
            [$productId, $grifoId]
        );
        if (!$row) {
            return null;
        }

        if ($row['pu_entrada_anterior'] !== null && $row['pu_entrada_anterior'] !== '') {
            return (float)$row['pu_entrada_anterior'];
        }

        return ($row['pu_promedio_para_salida'] !== null && $row['pu_promedio_para_salida'] !== '')
            ? (float)$row['pu_promedio_para_salida']
            : null;
    } catch (Throwable $e) {
        $row = comb_reg_fetch_one(
            $conn,
            "SELECT COALESCE(
                m.clm_alm_mov_preciounitario,
                CASE
                    WHEN COALESCE(m.clm_alm_mov_cantidad, 0) <> 0
                    THEN m.clm_alm_mov_monto / m.clm_alm_mov_cantidad
                    ELSE NULL
                END
             ) AS pu
             FROM tb_alm_movimientos m
             WHERE m.clm_alm_mov_idPRODUCTO = ?
               AND m.clm_alm_mov_orgn = ?
               AND m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO')
             ORDER BY m.clm_alm_mov_fecha_registro DESC, m.clm_alm_mov_id DESC
             LIMIT 1",
            'ii',
            [$productId, $grifoId]
        );

        return ($row && $row['pu'] !== null && $row['pu'] !== '') ? (float)$row['pu'] : null;
    }
}

function comb_reg_stats(mysqli $conn): array {
    $row = comb_reg_fetch_one(
        $conn,
        "SELECT
            COUNT(*) AS movimientos,
            COALESCE(SUM(CASE WHEN m.clm_alm_mov_TIPO = 'ENTRADA' THEN m.clm_alm_mov_cantidad ELSE 0 END), 0) AS entradas,
            COALESCE(SUM(CASE WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN m.clm_alm_mov_cantidad ELSE 0 END), 0) AS salidas,
            COALESCE(SUM(CASE WHEN m.clm_alm_mov_TIPO = 'ENTRADA' THEN m.clm_alm_mov_monto ELSE 0 END), 0) AS monto_entradas,
            COALESCE(SUM(CASE WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN m.clm_alm_mov_monto ELSE 0 END), 0) AS monto_salidas
         FROM tb_alm_movimientos m
         JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
         WHERE p.clm_alm_producto_idCATEGORIA = 11
           AND DATE(m.clm_alm_mov_fecha_registro) = CURDATE()"
    );

    return [
        'movimientos' => (int)($row['movimientos'] ?? 0),
        'entradas' => (float)($row['entradas'] ?? 0),
        'salidas' => (float)($row['salidas'] ?? 0),
        'balance' => (float)($row['entradas'] ?? 0) - (float)($row['salidas'] ?? 0),
        'monto_entradas' => (float)($row['monto_entradas'] ?? 0),
        'monto_salidas' => (float)($row['monto_salidas'] ?? 0),
    ];
}

function comb_reg_recent(mysqli $conn): array {
    return comb_reg_fetch_all(
        $conn,
        "SELECT
            m.clm_alm_mov_id AS id_mov,
            m.clm_alm_mov_fecha_registro AS fecha,
            UPPER(TRIM(m.clm_alm_mov_TIPO)) AS tipo,
            COALESCE(ns.clm_nota_sco, '-') AS nota,
            COALESCE(e.clm_esp_nombre, '-') AS grifo_codigo,
            COALESCE(e.clm_esp_desc, '-') AS grifo_nombre,
            COALESCE(p.clm_alm_producto_codigo, '') AS codigo,
            COALESCE(p.clm_alm_producto_NOMBRE, '') AS producto,
            COALESCE(p.clm_alm_producto_unidad, '') AS unidad,
            COALESCE(m.clm_alm_mov_cantidad, 0) AS cantidad,
            COALESCE(m.clm_alm_mov_monto, 0) AS monto
         FROM tb_alm_movimientos m
         JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
         LEFT JOIN tb_espacio e ON e.clm_esp_id = m.clm_alm_mov_orgn
         LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
         WHERE p.clm_alm_producto_idCATEGORIA = 11
         ORDER BY m.clm_alm_mov_fecha_registro DESC, m.clm_alm_mov_id DESC
         LIMIT 8"
    );
}

function comb_reg_nota_code(mysqli $conn, int $notaId): array {
    $row = comb_reg_fetch_one(
        $conn,
        "SELECT clm_nota_corr, clm_nota_sco
         FROM tb_notas_salida
         WHERE clm_nota_id = ?
         LIMIT 1",
        'i',
        [$notaId]
    );

    return [
        'corr' => (int)($row['clm_nota_corr'] ?? 0),
        'sco' => (string)($row['clm_nota_sco'] ?? ''),
    ];
}

function comb_reg_fecha_display($value): string {
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function comb_reg_bus_label(array $bus): string {
    $name = trim((string)($bus['bus'] ?? ''));
    $plate = trim((string)($bus['placa'] ?? ''));
    if ($name !== '' && $plate !== '') {
        return $name . ' (' . $plate . ')';
    }
    return $name !== '' ? $name : ($plate !== '' ? $plate : '-');
}
