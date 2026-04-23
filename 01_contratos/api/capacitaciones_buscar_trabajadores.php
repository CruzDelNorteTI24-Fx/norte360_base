<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$q = trim($_GET['q'] ?? '');
$capid = intval($_GET['capid'] ?? 0);

if ($q === '' || $capid <= 0) { echo json_encode([]); exit; }

$like = "%$q%";
$sql = "
  SELECT 
    tra.clm_tra_id          AS id,
    tra.clm_tra_nombres     AS nombres,
    tra.clm_tra_dni         AS dni,
    tra.clm_tra_cargo       AS cargo,
    CASE WHEN t.clm_trcap_id IS NULL THEN 0 ELSE 1 END AS inscrito
  FROM tb_trabajador tra
  LEFT JOIN tb_trabincapacitaciones t 
    ON t.clm_trcap_trabid = tra.clm_tra_id
   AND t.clm_trcap_capid  = ?
  WHERE tra.clm_tra_nombres LIKE ? OR tra.clm_tra_dni LIKE ?
  ORDER BY tra.clm_tra_nombres
  LIMIT 50
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iss', $capid, $like, $like);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
  $out[] = [
    'id'       => (int)$r['id'],
    'nombres'  => $r['nombres'],
    'dni'      => $r['dni'],
    'cargo'    => $r['cargo'],
    'inscrito' => (bool)$r['inscrito']
  ];
}
echo json_encode($out);
