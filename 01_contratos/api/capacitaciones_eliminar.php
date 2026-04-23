<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

$stmt = $conn->prepare("UPDATE tb_capacitaciones SET clm_cap_estado = 3, clm_cap_fechafin = NOW() WHERE clm_cap_id = ?");
$stmt->bind_param('i', $id);
$ok = $stmt->execute();

echo json_encode([
  'ok'    => (bool)$ok,
  'error' => $ok ? null : $conn->error
]);
