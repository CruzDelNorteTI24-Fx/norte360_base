<?php

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

function alm_activos_detalles_sugeridos(mysqli $conn): array {
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
          AND (m.clm_alm_mov_orgn = 12
            OR UPPER(TRIM(COALESCE(p.clm_alm_producto_area_control, ''))) = 'ACTIVOS'
            OR UPPER(TRIM(COALESCE(p.clm_alm_producto_tipo_control, ''))) = 'ACTIVO_FIJO')
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
    return $sede . ($sede !== '' && $detail !== '' ? ' — ' : '') . $detail;
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
