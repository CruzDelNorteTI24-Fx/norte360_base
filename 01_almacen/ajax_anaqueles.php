<?php
require_once("../.c0nn3ct/db_securebd2.php");
header('Content-Type: application/json; charset=utf-8');

$sede_id = (int)($_GET['sede_id'] ?? 0);
$data = [];

if ($sede_id > 0) {
    $stmt = $conn->prepare("
        SELECT clm_alm_anaquel_id, clm_alm_anaquel_nombre, IFNULL(clm_alm_anaquel_codigo,'') AS codigo
        FROM tb_alm_anaquel
        WHERE clm_alm_anaquel_idSEDE = ?
          AND clm_alm_anaquel_estado = 1
        ORDER BY clm_alm_anaquel_nombre
    ");
    $stmt->bind_param("i", $sede_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while($r = $res->fetch_assoc()){
        $data[] = [
            'id' => (int)$r['clm_alm_anaquel_id'],
            'nombre' => $r['clm_alm_anaquel_nombre'],
            'codigo' => $r['codigo']
        ];
    }
}

echo json_encode($data);