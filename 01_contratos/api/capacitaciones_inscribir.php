<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$payload = json_decode(file_get_contents('php://input'), true);
$capid = (int)($payload['capid'] ?? 0);
$trabIds = $payload['trab_ids'] ?? [];

if ($capid <= 0 || !is_array($trabIds) || empty($trabIds)) {
  echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit;
}

// 1) Verificar estado de la capacitación
$stmt = $conn->prepare("SELECT clm_cap_estado FROM tb_capacitaciones WHERE clm_cap_id=?");
$stmt->bind_param('i', $capid);
$stmt->execute();
$stmt->bind_result($estado);
if (!$stmt->fetch()) { echo json_encode(['ok'=>false,'error'=>'Capacitación no existe']); exit; }
$stmt->close();

if (in_array((int)$estado, [2,3], true)) { // 2: FINALIZADA, 3: CANCELADA
  http_response_code(409);
  echo json_encode(['ok'=>false,'error'=>'La capacitación no admite nuevas inscripciones.']);
  exit;
}

// 2) Insertar (evitando duplicados)
$conn->begin_transaction();
try {
  $ins = $conn->prepare("INSERT IGNORE INTO tb_trabincapacitaciones (clm_trcap_capid, clm_trcap_trabid, clm_trcap_estado)
                         VALUES (?, ?, 1)");
  foreach ($trabIds as $tid) {
    $tid = (int)$tid;
    if ($tid > 0) {
      $ins->bind_param('ii', $capid, $tid);
      $ins->execute();
    }
  }
  $conn->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error al guardar']);
}
exit;
