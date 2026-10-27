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
