<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$capacitacion = trim($_POST['capacitacion'] ?? '');
$estado       = $_POST['estado'] ?? '';
$fechainicio  = $_POST['fechainicio'] ?? '';
$observacion  = trim($_POST['observacion'] ?? '');
$dur_horas    = (float)($_POST['duracion'] ?? 0);
$dur_min      = max(0, (int)round($dur_horas * 60)); // guardamos en minutos

// Normaliza fechainicio: "YYYY-MM-DDTHH:MM" -> "YYYY-MM-DD HH:MM:SS"
$fechainicio = str_replace('T', ' ', trim($fechainicio));
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $fechainicio)) {
  $fechainicio .= ':00';
}

if ($capacitacion === '' || $fechainicio === '' || $estado === '' || $dur_min <= 0) {
  echo json_encode(['ok'=>false,'error'=>'Faltan datos obligatorios']); exit;
}

// Al crear, no enviamos fecha fin (se establecerá al finalizar)
$fechafin = null;

// Documento (opcional)
$docData = null;
if (isset($_FILES['documento']) && $_FILES['documento']['error'] !== UPLOAD_ERR_NO_FILE) {
  $err = $_FILES['documento']['error'];
  if ($err !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'Error al subir el archivo (código '.$err.').']); exit;
  }
  // Límite 10MB
  if ($_FILES['documento']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'error'=>'Archivo demasiado grande (máx. 10 MB).']); exit;
  }
  $docData = @file_get_contents($_FILES['documento']['tmp_name']);
  if ($docData === false) {
    echo json_encode(['ok'=>false,'error'=>'No se pudo leer el archivo.']); exit;
  }
}

$stmt = $conn->prepare("INSERT INTO tb_capacitaciones 
  (clm_cap_capacitacion, clm_cap_estado, clm_cap_fecharegistro, clm_cap_fechainicio, clm_cap_fechafin, clm_cap_duracion_minutos, clm_cap_observacion, clm_cap_documento)
  VALUES (?,?,?,?,?,?,?,?)");

$ahora = date('Y-m-d H:i:s');

/*
 * Opción simple: trata el BLOB como string.
 * (Cambia el último tipo de 'b' a 's'.)
 */
$stmt->bind_param(
  'sssssiss',
  $capacitacion,
  $estado,
  $ahora,
  $fechainicio,
  $fechafin,      // se envía como NULL
  $dur_min,
  $observacion,
  $docData        // puede ser NULL o string binario
);

$ok = $stmt->execute();

if ($ok) {
  if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') === false) {
    header('Location: ../ncapacitaciones.php'); exit;
  }
  echo json_encode(['ok'=>true, 'id'=>$conn->insert_id]);
} else {
  echo json_encode(['ok'=>false,'error'=>$stmt->error ?: $conn->error]);
}
