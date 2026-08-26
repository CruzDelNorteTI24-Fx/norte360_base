<?php

if (!function_exists('n360_cv_table_exists')) {
    function n360_cv_table_exists(mysqli $conn, string $table): bool {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) return $cache[$key];

        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) return $cache[$key] = false;
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            $stmt->close();
            return $cache[$key] = false;
        }
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$key] = $exists;
    }
}

if (!function_exists('n360_cv_column_exists')) {
    function n360_cv_column_exists(mysqli $conn, string $table, string $column): bool {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) return $cache[$key];

        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) return $cache[$key] = false;
        $stmt->bind_param('ss', $table, $column);
        if (!$stmt->execute()) {
            $stmt->close();
            return $cache[$key] = false;
        }
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$key] = $exists;
    }
}

if (!function_exists('n360_cv_item_version_ready')) {
    function n360_cv_item_version_ready(mysqli $conn): bool {
        return n360_cv_table_exists($conn, 'tb_checklist_versiones')
            && n360_cv_column_exists($conn, 'tb_items_checklist', 'clm_item_idversion');
    }
}

if (!function_exists('n360_cv_checklist_version_ready')) {
    function n360_cv_checklist_version_ready(mysqli $conn): bool {
        return n360_cv_column_exists($conn, 'tb_checklist_limpieza', 'clm_checklist_idversion');
    }
}

if (!function_exists('n360_cv_valid_date')) {
    function n360_cv_valid_date(string $date): bool {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}

if (!function_exists('n360_cv_bind_params')) {
    function n360_cv_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
        if ($types === '') return;
        $bind = [$types];
        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}

if (!function_exists('n360_cv_current_version_id')) {
    function n360_cv_current_version_id(mysqli $conn, int $tipoId, string $fecha = ''): ?int {
        if ($tipoId <= 0 || !n360_cv_table_exists($conn, 'tb_checklist_versiones')) return null;
        $fecha = n360_cv_valid_date($fecha) ? $fecha : date('Y-m-d');

        $stmt = $conn->prepare("
            SELECT clm_checkver_id
            FROM tb_checklist_versiones
            WHERE clm_checkver_idtipo = ?
              AND clm_checkver_estado = 'activo'
              AND (clm_checkver_desde IS NULL OR clm_checkver_desde <= ?)
              AND (clm_checkver_hasta IS NULL OR clm_checkver_hasta >= ?)
            ORDER BY clm_checkver_numero DESC, clm_checkver_id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('iss', $tipoId, $fecha, $fecha);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && (int)$row['clm_checkver_id'] > 0) return (int)$row['clm_checkver_id'];
            } else {
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("
            SELECT clm_checkver_id
            FROM tb_checklist_versiones
            WHERE clm_checkver_idtipo = ?
              AND clm_checkver_estado = 'activo'
            ORDER BY clm_checkver_numero DESC, clm_checkver_id DESC
            LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $tipoId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && (int)$row['clm_checkver_id'] > 0 ? (int)$row['clm_checkver_id'] : null;
    }
}

if (!function_exists('n360_cv_effective_version_id')) {
    function n360_cv_effective_version_id(mysqli $conn, int $tipoId, string $fecha = ''): ?int {
        if ($tipoId <= 0 || !n360_cv_table_exists($conn, 'tb_checklist_versiones')) return null;
        if (!n360_cv_valid_date($fecha)) return n360_cv_current_version_id($conn, $tipoId, $fecha);

        $stmt = $conn->prepare("
            SELECT clm_checkver_id
            FROM tb_checklist_versiones
            WHERE clm_checkver_idtipo = ?
              AND clm_checkver_estado <> 'borrador'
              AND (clm_checkver_desde IS NULL OR clm_checkver_desde <= ?)
              AND (clm_checkver_hasta IS NULL OR clm_checkver_hasta >= ?)
            ORDER BY clm_checkver_desde DESC, clm_checkver_numero DESC, clm_checkver_id DESC
            LIMIT 1
        ");
        if (!$stmt) return n360_cv_current_version_id($conn, $tipoId, $fecha);
        $stmt->bind_param('iss', $tipoId, $fecha, $fecha);
        if (!$stmt->execute()) {
            $stmt->close();
            return n360_cv_current_version_id($conn, $tipoId, $fecha);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int)$row['clm_checkver_id'] > 0
            ? (int)$row['clm_checkver_id']
            : n360_cv_current_version_id($conn, $tipoId, $fecha);
    }
}

if (!function_exists('n360_cv_checklist_version_id')) {
    function n360_cv_checklist_version_id(mysqli $conn, int $checklistId, int $tipoId, string $fecha = ''): ?int {
        $storedVersion = null;

        if ($checklistId > 0 && (n360_cv_checklist_version_ready($conn) || !n360_cv_valid_date($fecha))) {
            $hasVersionColumn = n360_cv_checklist_version_ready($conn);
            $versionSelect = $hasVersionColumn ? 'clm_checklist_idversion,' : '';
            $stmt = $conn->prepare("
                SELECT {$versionSelect} clm_checklist_fecha
                FROM tb_checklist_limpieza
                WHERE clm_checklist_id = ?
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $checklistId);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        if ($hasVersionColumn && (int)($row['clm_checklist_idversion'] ?? 0) > 0) {
                            $storedVersion = (int)$row['clm_checklist_idversion'];
                        }
                        if (!n360_cv_valid_date($fecha)) {
                            $fecha = (string)($row['clm_checklist_fecha'] ?? '');
                        }
                    }
                }
                $stmt->close();
            }
        }

        if ($storedVersion !== null) return $storedVersion;
        return n360_cv_effective_version_id($conn, $tipoId, $fecha);
    }
}

if (!function_exists('n360_cv_version_has_items')) {
    function n360_cv_version_has_items(mysqli $conn, int $versionId): bool {
        static $cache = [];
        if ($versionId <= 0 || !n360_cv_item_version_ready($conn)) return false;
        if (array_key_exists($versionId, $cache)) return $cache[$versionId];

        $stmt = $conn->prepare("
            SELECT 1
            FROM tb_items_checklist
            WHERE clm_item_idversion = ?
            LIMIT 1
        ");
        if (!$stmt) return $cache[$versionId] = false;
        $stmt->bind_param('i', $versionId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $cache[$versionId] = false;
        }
        $hasItems = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$versionId] = $hasItems;
    }
}

if (!function_exists('n360_cv_item_filter')) {
    function n360_cv_item_filter(
        mysqli $conn,
        int $checklistId,
        int $tipoId,
        string $itemAlias = 'i',
        ?string $catAlias = null,
        string $fecha = ''
    ): array {
        $versionId = n360_cv_checklist_version_id($conn, $checklistId, $tipoId, $fecha);
        if ($versionId !== null && n360_cv_version_has_items($conn, $versionId)) {
            return [
                'where' => "{$itemAlias}.clm_item_idversion = ?",
                'types' => 'i',
                'params' => [$versionId],
                'version_id' => $versionId,
                'mode' => 'version',
            ];
        }

        $where = [
            "{$itemAlias}.clm_item_estado = 'activo'",
            "{$itemAlias}.clm_item_idtipocheck = ?",
        ];
        if ($catAlias !== null) {
            $where[] = "{$catAlias}.clm_categorias_estado = 'activo'";
        }

        return [
            'where' => implode(' AND ', $where),
            'types' => 'i',
            'params' => [$tipoId],
            'version_id' => null,
            'mode' => 'legacy',
        ];
    }
}

if (!function_exists('n360_cv_version_label')) {
    function n360_cv_version_label(mysqli $conn, ?int $versionId): string {
        if (!$versionId || !n360_cv_table_exists($conn, 'tb_checklist_versiones')) return '';

        $stmt = $conn->prepare("
            SELECT clm_checkver_numero, clm_checkver_nombre
            FROM tb_checklist_versiones
            WHERE clm_checkver_id = ?
            LIMIT 1
        ");
        if (!$stmt) return '';
        $stmt->bind_param('i', $versionId);
        if (!$stmt->execute()) {
            $stmt->close();
            return '';
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return '';

        $nombre = trim((string)($row['clm_checkver_nombre'] ?? ''));
        if ($nombre !== '') return $nombre;
        return 'Version ' . (int)($row['clm_checkver_numero'] ?? 0);
    }
}

if (!function_exists('n360_cv_checklist_id_for_context')) {
    function n360_cv_checklist_id_for_context(mysqli $conn, int $busId, string $fecha, int $tipoId): int {
        if ($busId <= 0 || $tipoId <= 0 || !n360_cv_valid_date($fecha)) return 0;

        $stmt = $conn->prepare("
            SELECT clm_checklist_id
            FROM tb_checklist_limpieza
            WHERE clm_checklist_id_bus = ?
              AND clm_checklist_fecha = ?
              AND clm_checklist_idtipo = ?
            ORDER BY clm_checklist_fecha DESC, clm_checklist_hora DESC, clm_checklist_id DESC
            LIMIT 1
        ");
        if (!$stmt) return 0;
        $stmt->bind_param('isi', $busId, $fecha, $tipoId);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['clm_checklist_id'] : 0;
    }
}
