<?php
// 01_contratos/api/planillas_historial.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit();
}

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");
require_once(__DIR__ . "/../lib/planillas_maps.php");

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit(); }

$sql = "SELECT 
            clm_pl_trabid,
            clm_pl_fechaingrespl,
            clm_pl_tra_estado,
            clm_pl_fechasalida,
            clm_pl_doc,
            clm_pl_tipo,
            clm_pl_com
        FROM tb_tpln
        WHERE clm_pl_trabid = ?
        ORDER BY clm_pl_fechregistro DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'Error de base de datos']); exit(); }
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
$hasVigente = false;

while ($r = $res->fetch_assoc()) {
    $estadoLabel = pl_estado_label($r['clm_pl_tra_estado']);
    $tipoLabel   = pl_tipo_label($r['clm_pl_tipo']);
    $isVig       = pl_is_vigente_row($estadoLabel, $r['clm_pl_fechasalida']);

    if ($isVig) $hasVigente = true;

    $data[] = [
        'clm_pl_fechaingrespl' => $r['clm_pl_fechaingrespl'],
        'clm_pl_tra_estado'   => $estadoLabel,
        'clm_pl_fechasalida'  => $r['clm_pl_fechasalida'],
        'clm_pl_doc'          => $r['clm_pl_doc'],
        'clm_pl_tipo'         => $tipoLabel,
        'clm_pl_com'          => $r['clm_pl_com'],
        'is_vigente'          => $isVig,                       // para pintar badge "VIGENTE"
        'tabla_estado'        => $isVig ? 'ACTIVO' : 'INACTIVO'// para columna Estado en modal
    ];
}
$stmt->close();
$conn->close();

echo json_encode(['ok'=>true,'has_vigente'=>$hasVigente,'data'=>$data], JSON_UNESCAPED_UNICODE);