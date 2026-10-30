<?php

function alm_producto_movimientos(mysqli $conn, int $productId): array {
    $stmt = $conn->prepare("
        SELECT
            m.clm_alm_mov_id AS id_movimiento,
            m.clm_alm_mov_fecha_registro AS fecha_registro_MOV,
            m.clm_alm_mov_TIPO AS tipo_MOV,
            m.clm_alm_mov_cantidad AS cantidad_MOV,
            m.clm_alm_mov_idNOTA AS id_nota,
            UPPER(TRIM(COALESCE(ns.clm_nota_serie, ''))) AS nota_serie,
            COALESCE(CAST(ns.clm_nota_sco AS CHAR), CAST(m.clm_alm_mov_idNOTA AS CHAR)) AS nota_label,
            m.clm_alm_mov_observacion AS observacion_MOV
        FROM tb_alm_movimientos m
        LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
        WHERE m.clm_alm_mov_idPRODUCTO = ?
        ORDER BY m.clm_alm_mov_fecha_registro DESC, m.clm_alm_mov_id DESC
    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
