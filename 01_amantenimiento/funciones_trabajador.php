<?php
if (!defined('ACCESS_GRANTED')) { http_response_code(403); exit('No direct access'); }

function obtenerConductores(mysqli $conn): array {
    $conductores = [];

    $sql = "SELECT clm_tra_id, clm_tra_nombres, clm_tra_dni
            FROM tb_trabajador
            WHERE clm_tra_tipo_trabajador = 'Conductor'
            ORDER BY clm_tra_nombres ASC";

    $res = $conn->query($sql);

    while ($row = $res->fetch_assoc()) {
        $conductores[] = [
            'id' => $row['clm_tra_id'],
            'nombres' => $row['clm_tra_nombres'],
            'dni' => $row['clm_tra_dni']
        ];
    }
    return $conductores;
}
