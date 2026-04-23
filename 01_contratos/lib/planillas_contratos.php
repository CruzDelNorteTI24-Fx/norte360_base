<?php
// 01_contratos/lib/planillas_contratos.php
if (!defined('ACCESS_GRANTED')) { http_response_code(403); exit('No direct access'); }

/**
 * Regresa el estado de contrato por trabajador de forma masiva (mapa id => array estado)
 * Reglas:
 * - Sin registros en tb_tpln => "SIN_CONTRATO"
 * - Toma el último registro por trabajador (clm_pl_fechregistro más reciente)
 * - "VIGENTE" si:
 *      - clm_pl_tra_estado en ('ACTIVO','VIGENTE','EN PLANILLA') Y
 *      - clm_pl_fechasalida es NULL o >= CURDATE()
 * - "CESADO" si:
 *      - clm_pl_tra_estado en ('CESADO','BAJA','INACTIVO') o clm_pl_fechasalida < CURDATE()
 * Extra: puedes usar clm_pl_doc para marcar “FALTA_DOCUMENTO” si quieres.
 */
// 01_contratos/lib/planillas_contratos.php
if (!defined('ACCESS_GRANTED')) { http_response_code(403); exit('No direct access'); }

require_once(__DIR__ . '/planillas_maps.php');

/**
 * Regresa por trabajador:
 *  - has_vigente: bool (si existe algún contrato vigente a hoy)
 *  - tabla_estado: "ACTIVO" | "INACTIVO" (para pintar en la tabla)
 *  - raw_ultimo: último registro (por si lo necesitas)
 */
function contratos_estado_bulk(mysqli $conn, array $trabIds): array {
    $resultado = [];
    if (empty($trabIds)) return $resultado;

    $placeholders = implode(',', array_fill(0, count($trabIds), '?'));
    $types = str_repeat('i', count($trabIds));

    // Subconsulta: último registro por trabajador (para mostrar/depurar)
    // Subconsulta: ¿existe registro vigente (estado ACTIVO y fecha salida NULL o futura)?
    $sql = "
        SELECT
            u.clm_pl_trabid,
            u.clm_pl_tra_estado    AS ult_estado,
            u.clm_pl_fechasalida   AS ult_fsalida,
            u.clm_pl_fechregistro  AS ult_freg,
            IF(a.activo_max IS NULL, 0, 1) AS has_vigente
        FROM (
            SELECT t.*
            FROM tb_tpln t
            INNER JOIN (
                SELECT clm_pl_trabid, MAX(clm_pl_fechregistro) AS max_reg
                FROM tb_tpln
                WHERE clm_pl_trabid IN ($placeholders)
                GROUP BY clm_pl_trabid
            ) m ON t.clm_pl_trabid = m.clm_pl_trabid AND t.clm_pl_fechregistro = m.max_reg
        ) u
        LEFT JOIN (
            SELECT clm_pl_trabid, MAX(clm_pl_fechregistro) AS activo_max
            FROM tb_tpln
            WHERE clm_pl_trabid IN ($placeholders)
              AND (
                    (clm_pl_tra_estado IN (1,'ACTIVO','VIGENTE','EN PLANILLA'))
                  )
            AND (
                clm_pl_fechasalida IS NULL
            OR CAST(clm_pl_fechasalida AS CHAR) = '0000-00-00'
            OR clm_pl_fechasalida >= CURDATE()
            )

            GROUP BY clm_pl_trabid
        ) a ON u.clm_pl_trabid = a.clm_pl_trabid
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types . $types, ...$trabIds, ...$trabIds);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['clm_pl_trabid'];
        $estadoUlt = pl_estado_label($r['ult_estado']);
        $hasVigente = ((int)$r['has_vigente'] === 1);

        $resultado[$id] = [
            'has_vigente'  => $hasVigente,
            'tabla_estado' => $hasVigente ? 'ACTIVO' : 'INACTIVO',
            'raw_ultimo'   => [
                'estado'      => $estadoUlt,
                'fechasalida' => $r['ult_fsalida'],
                'fregistro'   => $r['ult_freg'],
            ],
        ];
    }
    $stmt->close();

    // Asegura todos los ids consultados
    foreach ($trabIds as $id) {
        $id = (int)$id;
        if (!isset($resultado[$id])) {
            $resultado[$id] = [
                'has_vigente'  => false,
                'tabla_estado' => 'INACTIVO',
                'raw_ultimo'   => null,
            ];
        }
    }

    return $resultado;
}

/** (opcional) deja el badge para evoluciones futuras */
function render_badge_contrato(string $tabla_estado): string {
    return $tabla_estado; // ya estandarizado: "ACTIVO" | "INACTIVO"
}