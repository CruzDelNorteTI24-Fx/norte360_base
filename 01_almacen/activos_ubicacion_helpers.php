<?php
require_once __DIR__ . '/trazabilidad_contexto.php';

function alm_espacios_pabellon_disponible(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (!array_key_exists($key, $cache)) {
        $row = alm_fetch_one($conn, "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_sedes_espacios'
            AND COLUMN_NAME = 'clm_sedeesp_pabellon'");
        $cache[$key] = (int)$row['total'] === 1;
    }
    return $cache[$key];
}

function alm_espacios_nombre_sql(mysqli $conn, string $alias, bool $conPabellon = false): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Alias no válido.');
    $pabellon = $conPabellon && alm_espacios_pabellon_disponible($conn) ? "NULLIF(TRIM($alias.clm_sedeesp_pabellon), ''), " : '';
    return "CONCAT_WS(' - ', $pabellon NULLIF(TRIM($alias.clm_sedeesp_area), ''), NULLIF(TRIM($alias.clm_sedeesp_espacio), ''))";
}

function alm_espacios_clasificacion_disponible(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $row = alm_fetch_one($conn, "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_sedes_espacios'
          AND COLUMN_NAME = 'clm_sedeesp_idespacio'");
    return $cache[$key] = (int)($row['total'] ?? 0) === 1;
}

function alm_espacios_clasificaciones(mysqli $conn): array {
    return alm_fetch_all($conn, "SELECT clm_esp_id AS id,
        CONCAT_WS(' - ', NULLIF(TRIM(clm_esp_nombre), ''), NULLIF(TRIM(clm_esp_desc), '')) AS nombre
        FROM tb_espacio ORDER BY clm_esp_nombre, clm_esp_id");
}

function alm_espacios_validar_clasificacion(mysqli $conn, ?int $id): void {
    if ($id === null) return;
    if (!alm_espacios_clasificacion_disponible($conn)) throw new RuntimeException('La clasificación de espacios aún no está habilitada.', 409);
    if (!alm_fetch_one($conn, 'SELECT clm_esp_id FROM tb_espacio WHERE clm_esp_id = ?', 'i', [$id])) {
        throw new InvalidArgumentException('La clasificación seleccionada no existe.');
    }
}

function alm_activos_espacios_disponibles(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $row = alm_fetch_one($conn, "
        SELECT COUNT(*) AS total FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND (
            (TABLE_NAME = 'tb_sedes_espacios' AND COLUMN_NAME IN
                ('clm_sedeesp_id', 'clm_sedeesp_idsede', 'clm_sedeesp_area', 'clm_sedeesp_espacio',
                 'clm_sedeesp_referencia_id', 'clm_sedeesp_referencia_texto', 'clm_sedeesp_estado'))
            OR (TABLE_NAME = 'tb_alm_etiquetado' AND COLUMN_NAME = 'clm_alm_etiquetado_espacio_id')
            OR (TABLE_NAME = 'tb_alm_etiquetadoofi' AND COLUMN_NAME IN
                ('clm_alm_etiquetadoofi_espacio_id_antes', 'clm_alm_etiquetadoofi_espacio_id_despues'))
        )
    ");
    return $cache[$key] = (int)($row['total'] ?? 0) === 10;
}

function alm_activos_espacio_nombre(array $row): string {
    return implode(' - ', array_filter([
        trim((string)($row['area'] ?? '')), trim((string)($row['espacio'] ?? '')),
    ], static function ($value) { return $value !== ''; }));
}

function alm_espacios_personal(mysqli $conn, array $ids): array {
    static $cache = [];
    $key = spl_object_hash($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    if (!isset($cache[$key])) {
        $columns = [];
        foreach (alm_fetch_all($conn, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tb_sedes_espacios_personal', 'tb_trabajador')") as $column) {
            $columns[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
        }
        $required = ['clm_espper_id', 'clm_espper_idespacio', 'clm_espper_turno', 'clm_espper_idtrabajador', 'clm_espper_nombre', 'clm_espper_hasta'];
        $cache[$key] = ['ready' => !array_diff($required, $columns['tb_sedes_espacios_personal'] ?? []),
            'workers' => $columns['tb_trabajador'] ?? [], 'rows' => []];
    }
    if (!$cache[$key]['ready']) return array_fill_keys($ids, null);
    $missing = array_values(array_diff($ids, array_keys($cache[$key]['rows'])));
    if ($missing) {
        $names = [];
        if (in_array('clm_tra_id', $cache[$key]['workers'], true)) {
            foreach (['clm_tra_namesistem', 'clm_tra_nomandapellidos', 'clm_tra_nombres'] as $field) {
                if (in_array($field, $cache[$key]['workers'], true)) $names[] = "NULLIF(TRIM(t.$field), '')";
            }
        }
        $join = $names ? 'LEFT JOIN tb_trabajador t ON t.clm_tra_id = p.clm_espper_idtrabajador' : '';
        $names[] = "NULLIF(TRIM(p.clm_espper_nombre), '')";
        $name = 'COALESCE(' . implode(', ', $names) . ", 'Persona asignada')";
        // Una sola lectura por conjunto; solo nombres de asignaciones vigentes, sin catálogo ni DNI.
        $rows = alm_fetch_all($conn, "SELECT p.clm_espper_idespacio AS espacio_id, p.clm_espper_turno AS turno,
            $name AS nombre FROM tb_sedes_espacios_personal p $join
            WHERE p.clm_espper_idespacio IN (" . implode(',', $missing) . ")
              AND p.clm_espper_hasta IS NULL AND p.clm_espper_turno IN (1, 2)
            ORDER BY p.clm_espper_idespacio, p.clm_espper_turno, p.clm_espper_id");
        foreach ($missing as $id) $cache[$key]['rows'][$id] = [1 => [], 2 => []];
        foreach ($rows as $row) $cache[$key]['rows'][(int)$row['espacio_id']][(int)$row['turno']][] = (string)$row['nombre'];
    }
    return array_intersect_key($cache[$key]['rows'], array_flip($ids));
}

function alm_activos_espacios(mysqli $conn, bool $soloActivos = true): array {
    if (!alm_activos_espacios_disponibles($conn)) return [];
    $where = $soloActivos ? 'WHERE clm_sedeesp_estado = 1' : '';
    $classId = alm_espacios_clasificacion_disponible($conn) ? 'clm_sedeesp_idespacio' : 'NULL';
    $pabellon = alm_espacios_pabellon_disponible($conn) ? 'clm_sedeesp_pabellon' : 'NULL';
    $rows = alm_fetch_all($conn, "
        SELECT clm_sedeesp_id AS id, clm_sedeesp_idsede AS sede_id,
            clm_sedeesp_area AS area, clm_sedeesp_espacio AS espacio,
            clm_sedeesp_referencia_id AS referencia_id,
            clm_sedeesp_referencia_texto AS referencia_texto, clm_sedeesp_estado AS estado
            , $classId AS clasificacion_id, $pabellon AS pabellon
        FROM tb_sedes_espacios $where
        ORDER BY pabellon, clm_sedeesp_area, clm_sedeesp_espacio, clm_sedeesp_id
    ");
    $personnel = alm_espacios_personal($conn, array_column($rows, 'id'));
    foreach ($rows as &$row) $row['personal'] = $personnel[(int)$row['id']] ?? null;
    unset($row);
    return $rows;
}

function alm_activos_espacio_id($value): ?int {
    if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) throw new InvalidArgumentException('Selecciona un espacio válido.');
    return $id;
}

function alm_activos_validar_espacio(mysqli $conn, ?int $id, int $sedeId, ?int $actual = null, bool $bloquear = false): ?array {
    if ($id === null) return null;
    if (!alm_activos_espacios_disponibles($conn)) {
        throw new RuntimeException('Los espacios por sede aún no están habilitados. Contacta al administrador.', 409);
    }
    $lock = $bloquear ? ' FOR UPDATE' : '';
    $pavilion = alm_espacios_pabellon_disponible($conn) ? 'clm_sedeesp_pabellon' : 'NULL';
    $row = alm_fetch_one($conn, "
        SELECT clm_sedeesp_id AS id, clm_sedeesp_idsede AS sede_id,
            clm_sedeesp_area AS area, clm_sedeesp_espacio AS espacio, $pavilion AS pabellon, clm_sedeesp_estado AS estado
        FROM tb_sedes_espacios WHERE clm_sedeesp_id = ? LIMIT 1$lock
    ", 'i', [$id]);
    if (!$row || (int)$row['sede_id'] !== $sedeId) {
        throw new InvalidArgumentException('El espacio seleccionado no pertenece a la sede indicada.');
    }
    if ((int)$row['estado'] !== 1 && $id !== $actual) {
        throw new RuntimeException('El espacio seleccionado está inactivo. Selecciona otro espacio.', 409);
    }
    return $row;
}

// SQL interno compartido: mantiene las consultas anteriores sin la migración completa.
function alm_activos_espacio_sql(mysqli $conn, string $etiqueta = 'e', string $espacio = 'ue'): array {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $etiqueta) || !preg_match('/^[a-z][a-z0-9_]*$/D', $espacio)) {
        throw new InvalidArgumentException('Alias de ubicación inválido.');
    }
    if (!alm_activos_espacios_disponibles($conn)) return ['id' => 'NULL', 'nombre' => "''", 'nombre_corto' => "''", 'join' => '', 'clasificacion_id' => 'NULL', 'clasificacion_nombre' => "''"];
    $hasClass = alm_espacios_clasificacion_disponible($conn);
    $classAlias = $espacio . '_clas';
    return [
        'id' => "$etiqueta.clm_alm_etiquetado_espacio_id",
        'nombre' => alm_espacios_nombre_sql($conn, $espacio),
        'nombre_corto' => alm_espacios_nombre_sql($conn, $espacio, false),
        'join' => "LEFT JOIN tb_sedes_espacios $espacio ON $espacio.clm_sedeesp_id = $etiqueta.clm_alm_etiquetado_espacio_id"
            . ($hasClass ? " LEFT JOIN tb_espacio $classAlias ON $classAlias.clm_esp_id = $espacio.clm_sedeesp_idespacio" : ''),
        'clasificacion_id' => $hasClass ? "$espacio.clm_sedeesp_idespacio" : 'NULL',
        'clasificacion_nombre' => $hasClass ? "CONCAT_WS(' - ', NULLIF(TRIM($classAlias.clm_esp_nombre), ''), NULLIF(TRIM($classAlias.clm_esp_desc), ''))" : "''",
    ];
}

function alm_activos_detalle_disponible(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $row = alm_fetch_one($conn, "
            SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND ((TABLE_NAME = 'tb_alm_etiquetado' AND COLUMN_NAME = 'clm_alm_etiquetado_ubicacion_detalle')
                OR (TABLE_NAME = 'tb_alm_etiquetadoofi' AND COLUMN_NAME IN
                    ('clm_alm_etiquetadoofi_detalle_antes', 'clm_alm_etiquetadoofi_detalle_despues')))
        ");
        return $cache[$key] = (int)($row['total'] ?? 0) === 3;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function alm_activos_motivo_disponible(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (!array_key_exists($key, $cache)) {
        $row = alm_fetch_one($conn, "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_alm_etiquetadoofi'
              AND COLUMN_NAME = 'clm_alm_etiquetadoofi_motivo'");
        $cache[$key] = (int)($row['total'] ?? 0) === 1;
    }
    return $cache[$key];
}

function alm_activos_detalles_sugeridos(mysqli $conn, string $context = 'contabilidad'): array {
    if (!alm_activos_detalle_disponible($conn)) {
        return [];
    }
    return alm_fetch_all($conn, "
        SELECT DISTINCT d.sede_id, TRIM(d.detalle) AS detalle
        FROM (
            SELECT e.clm_alm_etiquetado_id AS etiqueta_id,
                   e.clm_alm_etiquetado_oficina_destino AS sede_id,
                   CONVERT(e.clm_alm_etiquetado_ubicacion_detalle USING utf8mb4) COLLATE utf8mb4_unicode_ci AS detalle
            FROM tb_alm_etiquetado e
            UNION ALL
            SELECT h.clm_alm_etiquetadoofi_etiref,
                   h.clm_alm_etiquetadoofi_sedeIDantes,
                   CONVERT(h.clm_alm_etiquetadoofi_detalle_antes USING utf8mb4) COLLATE utf8mb4_unicode_ci
            FROM tb_alm_etiquetadoofi h
            UNION ALL
            SELECT h.clm_alm_etiquetadoofi_etiref,
                   h.clm_alm_etiquetadoofi_sedeIDdespues,
                   CONVERT(h.clm_alm_etiquetadoofi_detalle_despues USING utf8mb4) COLLATE utf8mb4_unicode_ci
            FROM tb_alm_etiquetadoofi h
        ) d
        JOIN tb_alm_etiquetado e ON e.clm_alm_etiquetado_id = d.etiqueta_id
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = e.clm_alm_etiquetado_idPRODUCTO
        JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
        WHERE d.sede_id > 0 AND TRIM(COALESCE(d.detalle, '')) <> ''
          AND " . n360_trace_scope_sql($context) . "
        ORDER BY detalle ASC, d.sede_id ASC
    ");
}

function alm_activos_texto($value): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException('Ingresa un texto válido.');
    }
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if ($clean === null) {
        throw new InvalidArgumentException('El texto contiene caracteres no válidos.');
    }
    return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $clean);
}

function alm_activos_longitud(string $value): int {
    return preg_match_all('/./us', $value);
}

function alm_activos_detalle($value): string {
    $detail = preg_replace('/[\s\x{FEFF}]+/u', ' ', alm_activos_texto($value));
    if (alm_activos_longitud($detail) > 220) {
        throw new InvalidArgumentException('El detalle de ubicación admite hasta 220 caracteres.');
    }
    return $detail;
}

function alm_activos_ubicacion(string $sede, string $detail): string {
    return $sede . ($sede !== '' && $detail !== '' ? ' - ' : '') . $detail;
}

function alm_activos_observacion($observacion, string $sede, string $detail): string {
    $text = alm_activos_texto($observacion);
    if ($detail !== '') {
        $text .= ($text !== '' ? ' | ' : '') . 'Ubicación: ' . alm_activos_ubicacion($sede, $detail);
    }
    if (alm_activos_longitud($text) > 900) {
        throw new InvalidArgumentException('La observación final, incluido el detalle de ubicación, admite hasta 900 caracteres.');
    }
    return $text;
}
