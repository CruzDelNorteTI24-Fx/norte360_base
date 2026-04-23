<?php
// ../php/actualizar_observaciones.php
declare(strict_types=1);
session_start();

header('Content-Type: text/plain; charset=utf-8');

// 1) Sesión y permisos
if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    exit("No autorizado");
}
if (!($_SESSION['web_rol'] === 'Admin' || in_array(6, $_SESSION['permisos'] ?? []))) {
    http_response_code(403);
    exit("Sin permisos");
}

// 2) CSRF
if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
    http_response_code(403);
    exit("CSRF inválido");
}

// 3) Datos
$id = isset($_POST['id_entrevista']) ? (int) $_POST['id_entrevista'] : 0;
$obs = isset($_POST['observaciones']) ? trim((string)$_POST['observaciones']) : '';

if ($id <= 0) {
    http_response_code(400);
    exit("ID inválido");
}
// (Opcional) límite de tamaño
if (mb_strlen($obs) > 2000) {
    http_response_code(400);
    exit("Observaciones demasiado largas (máx. 2000)");
}

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php"); // usa el mismo conector que el listado

// 4) Update seguro
$stmt = $conn->prepare("UPDATE entrevistas SET observaciones = ? WHERE id_entrevista = ?");
if (!$stmt) {
    http_response_code(500);
    exit("Error de preparación");
}
$stmt->bind_param("si", $obs, $id);
$ok = $stmt->execute();

if ($ok) {
    echo "OK";
} else {
    http_response_code(500);
    echo "Error al actualizar";
}

$stmt->close();
$conn->close();
