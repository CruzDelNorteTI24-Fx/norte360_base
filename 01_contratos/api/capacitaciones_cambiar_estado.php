<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit;
}

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");
// evita que notices rompan el JSON:
ini_set('display_errors', 0);
error_reporting(0);

// limpia cualquier salida previa en buffer
if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }
$payload = json_decode(file_get_contents('php://input'), true);
$capid   = (int)($payload['capid']   ?? 0);
$trabid  = (int)($payload['trab_id'] ?? 0);
$estado  = (int)($payload['estado']  ?? -1);

/* Estados permitidos para el detalle del trabajador:
   0=PENDIENTE, 1=INSCRITO, 2=APROBADO, 3=REPROBADO, 4=ASISTIÓ, 5=NO ASISTIÓ */
$validos = [0,1,2,3,4,5];

if ($capid<=0 || $trabid<=0 || !in_array($estado,$validos,true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Parámetros inválidos']); exit;
}

// (Opcional) bloquear edición si la capacitación está CANCELADA
$capStmt = $conn->prepare("SELECT clm_cap_estado FROM tb_capacitaciones WHERE clm_cap_id=?");
$capStmt->bind_param('i', $capid);
$capStmt->execute();
$capStmt->bind_result($capEstado);
if (!$capStmt->fetch()) {
  echo json_encode(['ok'=>false,'error'=>'Capacitación no existe']); exit;
}
$capStmt->close();

if ((int)$capEstado === 3) { // CANCELADA
  http_response_code(409);
  echo json_encode(['ok'=>false,'error'=>'Capacitación cancelada: no se pueden modificar estados.']); exit;
}

// Verificar que exista la fila trabajador-capacitación
$chk = $conn->prepare("SELECT clm_trcap_id FROM tb_trabincapacitaciones WHERE clm_trcap_capid=? AND clm_trcap_trabid=?");
$chk->bind_param('ii', $capid, $trabid);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
  echo json_encode(['ok'=>false,'error'=>'El trabajador no está inscrito en esta capacitación.']); exit;
}
$chk->free_result();
$chk->close();

// Actualizar estado
$upd = $conn->prepare("
  UPDATE tb_trabincapacitaciones
     SET clm_trcap_estado=?
   WHERE clm_trcap_capid=? AND clm_trcap_trabid=?
  LIMIT 1
");
$upd->bind_param('iii', $estado, $capid, $trabid);

if (!$upd->execute()) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'No se pudo actualizar']); exit;
}
$upd->close();

// Devolver meta (por si la UI quiere usarla)
$MAP = [
  0 => ['txt'=>'PENDIENTE',  'badge'=>'secondary'],
  1 => ['txt'=>'INSCRITO',   'badge'=>'info'],
  2 => ['txt'=>'APROBADO',   'badge'=>'success'],
  3 => ['txt'=>'REPROBADO',  'badge'=>'danger'],
  4 => ['txt'=>'ASISTIÓ',    'badge'=>'primary'],
  5 => ['txt'=>'NO ASISTIÓ', 'badge'=>'warning'],
];

echo json_encode([
  'ok' => true,
  'estado_texto' => $MAP[$estado]['txt'],
  'estado_badge' => $MAP[$estado]['badge']
]); exit;  // <-- MUY IMPORTANTE