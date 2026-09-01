<?php

if (!function_exists('n360_units_uid')) {
    function n360_units_uid(): int {
        if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
            return (int)$_SESSION['id_usuario'];
        }

        if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
            return (int)$_SESSION['web_id_usuario'];
        }

        return 0;
    }
}

if (!function_exists('n360_units_table_columns')) {
    function n360_units_table_columns(mysqli $conn, string $table): array {
        static $cache = [];
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);

        if ($safeTable === '') {
            return [];
        }

        if (isset($cache[$safeTable])) {
            return $cache[$safeTable];
        }

        $columns = [];

        try {
            $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $field = (string)($row['Field'] ?? '');
                    if ($field !== '') {
                        $columns[$field] = $field;
                    }
                }
            }
        } catch (Throwable $e) {
            $columns = [];
        }

        $cache[$safeTable] = $columns;
        return $columns;
    }
}

if (!function_exists('n360_units_norm_col')) {
    function n360_units_norm_col(string $value): string {
        $value = trim($value);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
    }
}

if (!function_exists('n360_units_pick_col')) {
    function n360_units_pick_col(array $columns, array $exact, array $fragments = []): ?string {
        $lowerMap = [];

        foreach ($columns as $column) {
            $lowerMap[strtolower($column)] = $column;
        }

        foreach ($exact as $candidate) {
            $key = strtolower($candidate);
            if (isset($lowerMap[$key])) {
                return $lowerMap[$key];
            }
        }

        $fragmentNorm = array_map('n360_units_norm_col', $fragments);
        foreach ($columns as $column) {
            $columnNorm = n360_units_norm_col($column);
            foreach ($fragmentNorm as $fragment) {
                if ($fragment !== '' && strpos($columnNorm, $fragment) !== false) {
                    return $column;
                }
            }
        }

        return null;
    }
}

if (!function_exists('n360_units_ident')) {
    function n360_units_ident(string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('n360_units_expr')) {
    function n360_units_expr(?string $column, string $fallback = ''): string {
        if ($column !== null && $column !== '') {
            return 'COALESCE(CAST(' . n360_units_ident($column) . " AS CHAR), '" . str_replace("'", "''", $fallback) . "')";
        }

        return "'" . str_replace("'", "''", $fallback) . "'";
    }
}

if (!function_exists('n360_units_value')) {
    function n360_units_value($value, string $fallback = ''): string {
        $value = trim((string)($value ?? ''));
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('n360_units_group')) {
    function n360_units_group(string $servicio, string $tipo): string {
        $serviceKey = n360_units_norm_col($servicio);
        $typeKey = n360_units_norm_col($tipo);

        if (in_array($serviceKey, ['ENCOMIENDA', 'ENCOMIENDAS'], true) || in_array($typeKey, ['ENCOMIENDA', 'ENCOMIENDAS'], true)) {
            return 'ENCOMIENDAS';
        }

        if ($serviceKey === 'BUS' || $typeKey === 'BUS') {
            return 'BUS';
        }

        return n360_units_value($servicio, n360_units_value($tipo, 'SIN SERVICIO'));
    }
}

if (!function_exists('n360_units_can_access')) {
    function n360_units_can_access(?mysqli $conn = null, bool $useSessionCache = true): bool {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['usuario'])) {
            return false;
        }

        if ($useSessionCache && isset($_SESSION['n360_units_perm_checked'])) {
            return !empty($_SESSION['n360_units_perm']);
        }

        if (!$conn instanceof mysqli) {
            return false;
        }

        $uid = n360_units_uid();
        if ($uid <= 0) {
            return false;
        }

        if (!in_array('prmso3nunidades', n360_units_table_columns($conn, 'tb_usuarios'), true)) {
            $_SESSION['n360_units_perm'] = 0;
            $_SESSION['n360_units_perm_checked'] = true;
            return false;
        }

        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(prmso3nunidades, 0) AS permitido
                FROM tb_usuarios
                WHERE id_usuario = ?
                LIMIT 1
            ");
        } catch (Throwable $e) {
            $stmt = false;
        }

        if (!$stmt) {
            $_SESSION['n360_units_perm'] = 0;
            $_SESSION['n360_units_perm_checked'] = true;
            return false;
        }

        $stmt->bind_param('i', $uid);
        if (!$stmt->execute()) {
            $stmt->close();
            $_SESSION['n360_units_perm'] = 0;
            $_SESSION['n360_units_perm_checked'] = true;
            return false;
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $allowed = (int)($row['permitido'] ?? 0) === 1;
        $_SESSION['prmso3nunidades'] = $allowed ? 1 : 0;
        $_SESSION['n360_units_perm'] = $allowed ? 1 : 0;
        $_SESSION['n360_units_perm_checked'] = true;

        return $allowed;
    }
}

if (!function_exists('n360_units_fetch_active')) {
    function n360_units_fetch_active(mysqli $conn): array {
        $columns = n360_units_table_columns($conn, 'tb_placas');
        $map = [
            'id' => n360_units_pick_col($columns, ['clm_placas_id', 'id'], ['PLACASID']),
            'bus' => n360_units_pick_col($columns, ['clm_placas_BUS', 'clm_placas_bus', 'bus'], ['PLACASBUS', 'BUS']),
            'placa' => n360_units_pick_col($columns, ['clm_placas_PLACA', 'clm_placas_placa', 'placa'], ['PLACA']),
            'estado' => n360_units_pick_col($columns, ['clm_placas_ESTADO', 'clm_placas_estado', 'estado'], ['ESTADO']),
            'servicio' => n360_units_pick_col($columns, ['clm_placas_servicio', 'clm_placas_SERVICIO', 'servicio'], ['SERVICIO']),
            'tipo' => n360_units_pick_col($columns, ['clm_placas_tipo_vehiculo', 'tipo_vehiculo'], ['TIPOVEHICULO', 'TIPOVEHCULO', 'VEHICULO', 'VEHCULO']),
        ];

        if (!$map['id'] || !$map['bus'] || !$map['placa']) {
            throw new RuntimeException('La tabla tb_placas no tiene las columnas minimas esperadas.');
        }

        $idExpr = n360_units_ident($map['id']);
        $busExpr = n360_units_expr($map['bus']);
        $placaExpr = n360_units_expr($map['placa']);
        $estadoExpr = 'UPPER(TRIM(' . n360_units_expr($map['estado'], 'ACTIVO') . '))';
        $servicioExpr = 'UPPER(TRIM(' . n360_units_expr($map['servicio']) . '))';
        $tipoExpr = 'UPPER(TRIM(' . n360_units_expr($map['tipo']) . '))';
        $targetExpr = "({$servicioExpr} IN ('BUS', 'ENCOMIENDA', 'ENCOMIENDAS') OR {$tipoExpr} IN ('BUS', 'ENCOMIENDA', 'ENCOMIENDAS'))";

        $sql = "
            SELECT
                {$idExpr} AS id,
                {$busExpr} AS bus,
                {$placaExpr} AS placa,
                " . n360_units_expr($map['servicio']) . " AS servicio,
                " . n360_units_expr($map['tipo']) . " AS tipo,
                " . n360_units_expr($map['estado'], 'ACTIVO') . " AS estado
            FROM tb_placas
            WHERE {$estadoExpr} IN ('ACTIVO', 'ACTIVA', '1', 'SI')
              AND {$targetExpr}
            ORDER BY
                CASE
                    WHEN {$servicioExpr} IN ('BUS') OR {$tipoExpr} IN ('BUS') THEN 1
                    WHEN {$servicioExpr} IN ('ENCOMIENDA', 'ENCOMIENDAS') OR {$tipoExpr} IN ('ENCOMIENDA', 'ENCOMIENDAS') THEN 2
                    ELSE 3
                END,
                CASE WHEN {$busExpr} REGEXP '^[0-9]+$' THEN CAST({$busExpr} AS UNSIGNED) ELSE 999999 END,
                {$busExpr} ASC,
                {$placaExpr} ASC
        ";

        $result = $conn->query($sql);
        if (!$result) {
            throw new RuntimeException($conn->error ?: 'No se pudo cargar la relacion de unidades.');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $servicio = n360_units_value($row['servicio'] ?? '');
            $tipo = n360_units_value($row['tipo'] ?? '');
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'bus' => n360_units_value($row['bus'] ?? '', 'SIN ASIGNAR'),
                'placa' => n360_units_value($row['placa'] ?? '', '-'),
                'servicio' => n360_units_value($servicio, '-'),
                'tipo' => n360_units_value($tipo, '-'),
                'estado' => n360_units_value($row['estado'] ?? '', 'ACTIVO'),
                'grupo' => n360_units_group($servicio, $tipo),
            ];
        }

        return $rows;
    }
}
