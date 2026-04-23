<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'No autorizado']);
  exit;
}

// Permisos (igual que en la vista)
$modulo_actual = 6;
if ($_SESSION['web_rol'] !== 'Admin') {
  $permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
  if (!in_array($modulo_actual, $permisos)) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Sin permisos']);
    exit;
  }
}

require_once("../.c0nn3ct/db_securebd2.php"); // <-- ajusta si tu path es distinto

// Lee y valida entradas
$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$tema = trim($_POST['capacitacion'] ?? '');
$ini  = trim($_POST['fechainicio'] ?? '');
$obs  = trim($_POST['observacion'] ?? '');

if ($id <= 0 || $tema === '' || $ini === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Datos incompletos']);
  exit;
}

// Normaliza fecha "YYYY-MM-DDTHH:MM" => "YYYY-MM-DD HH:MM:00"
$dt = DateTime::createFromFormat('Y-m-d\TH:i', $ini);
if (!$dt) { // por si viene como "YYYY-MM-DD HH:MM"
  $dt = DateTime::createFromFormat('Y-m-d H:i', $ini);
}
if (!$dt) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Fecha inválida']);
  exit;
}
$fechainicio = $dt->format('Y-m-d H:i:00');

// Consulta duración y estado actuales
$durMin = 0;
$estado = null;
$stmt = $conn->prepare("SELECT clm_cap_duracion_minutos, clm_cap_estado FROM tb_capacitaciones WHERE clm_cap_id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($durMin, $estado);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'Capacitación no encontrada']);
  exit;
}
$stmt->close();

// (Opcional) bloquear edición si FINALIZADA (2) o CANCELADA (3)
if (is_numeric($estado) && ((int)$estado === 2 || (int)$estado === 3)) {
  http_response_code(409);
  echo json_encode(['ok'=>false, 'error'=>'No se puede editar una capacitación finalizada o cancelada']);
  exit;
}

// Calcula fin (si hay duración)
$fechafin = null;
if (is_numeric($durMin) && (int)$durMin > 0) {
  $d2 = DateTime::createFromFormat('Y-m-d H:i:s', $fechainicio);
  $d2->modify('+' . (int)$durMin . ' minutes');
  $fechafin = $d2->format('Y-m-d H:i:00');
}

// Actualiza SOLO los 3 campos + fin recalculado
$sql = "
  UPDATE tb_capacitaciones
  SET clm_cap_capacitacion=?,
      clm_cap_fechainicio=?,
      clm_cap_fechafin=?,
      clm_cap_observacion=?
  WHERE clm_cap_id=?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssi", $tema, $fechainicio, $fechafin, $obs, $id);

if (!$stmt->execute()) {
  $err = $conn->error;
  $stmt->close();
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Error al actualizar: '.$err]);
  exit;
}
$stmt->close();

// Devuelve datos clave para refrescar la fila
echo json_encode([
  'ok' => true,
  'cap' => [
    'id'          => $id,
    'capacitacion'=> $tema,
    'fechainicio' => $fechainicio,
    'fechafin'    => $fechafin
  ]
]);
