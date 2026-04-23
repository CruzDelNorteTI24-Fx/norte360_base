<?php
// 01_contratos/lib/planillas_maps.php
if (!defined('ACCESS_GRANTED')) { http_response_code(403); exit('No direct access'); }

/**
 * Mapeos estandarizados
 * - clm_pl_tipo: 1=PLANILLA, 2=RECIBO POR HONORARIOS
 * - clm_pl_tra_estado: 1=ACTIVO, 2=CESADO
 */

const PL_TIPO_MAP = [
    1 => 'PLANILLA',
    2 => 'RECIBO POR HONORARIOS',
];

const PL_ESTADO_MAP = [
    1 => 'ACTIVO',
    2 => 'CESADO',
];

/** Normaliza tipo (int|string) a etiqueta canon ("PLANILLA" | "RECIBO POR HONORARIOS") */
function pl_tipo_label($val): string {
    if (is_numeric($val)) {
        return PL_TIPO_MAP[(int)$val] ?? 'DESCONOCIDO';
    }
    $t = strtoupper(trim((string)$val));
    // alias comunes
    if ($t === 'RPH' || $t === 'HONORARIO' || $t === 'RECIBO') return 'RECIBO POR HONORARIOS';
    return in_array($t, PL_TIPO_MAP, true) ? $t : 'DESCONOCIDO';
}

/** Normaliza estado (int|string) a etiqueta canon ("ACTIVO" | "CESADO") */
function pl_estado_label($val): string {
    if (is_numeric($val)) {
        return PL_ESTADO_MAP[(int)$val] ?? 'DESCONOCIDO';
    }
    $e = strtoupper(trim((string)$val));
    // alias antiguos
    if ($e === 'VIGENTE' || $e === 'EN PLANILLA') return 'ACTIVO';
    if ($e === 'BAJA' || $e === 'INACTIVO') return 'CESADO';
    return in_array($e, PL_ESTADO_MAP, true) ? $e : 'DESCONOCIDO';
}

/** Vigencia de un registro a hoy: estado=ACTIVO y (fechasalida NULL o >= hoy) */
function pl_is_vigente_row(?string $estadoLabel, ?string $fechaSalidaYmd): bool {
    $estadoLabel = pl_estado_label($estadoLabel ?? '');
    if ($estadoLabel !== 'ACTIVO') return false;
    if (empty($fechaSalidaYmd) || $fechaSalidaYmd === '0000-00-00') return true;
    try {
        $hoy = new DateTime('today');
        $fs  = new DateTime($fechaSalidaYmd);
        return $fs >= $hoy;
    } catch (\Throwable $e) {
        // Si la fecha es inválida, asumimos no vigente
        return false;
    }
}
