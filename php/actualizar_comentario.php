<?php
session_start();
if (!isset($_SESSION['usuario'])) { http_response_code(403); exit("No auth"); }
if (!defined('ACCESS_GRANTED')) define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php"); // <- cambia desde copidb_secure.php

$csrf = $_POST['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  http_response_code(400);
  exit("CSRF");
}

$id  = isset($_POST['id_entrevista']) ? (int)$_POST['id_entrevista'] : 0;
$campo = $_POST['campo'] ?? '';
$valor = $_POST['valor'] ?? '';

if ($id <= 0 || $campo === '') { http_response_code(400); exit("Parámetros inválidos"); }

// Whitelist para evitar inyección por nombre de columna
$permitidos = [
  'observaciones',
  'clm_comentario_entrevistapersonal',
  'clm_comentario_induccion',
  'clm_comentario_contratado',
  'clm_comentario_rechazado'
];

if (!in_array($campo, $permitidos, true)) {
  http_response_code(400);
  exit("Campo no permitido");
}

$sql = "UPDATE entrevistas SET $campo = ? WHERE id_entrevista = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); exit("Error prepare"); }
$stmt->bind_param("si", $valor, $id);

if ($stmt->execute()) {
  echo "OK";
} else {
  http_response_code(500);
  echo "Error al actualizar";
}
$stmt->close();
$conn->close();
