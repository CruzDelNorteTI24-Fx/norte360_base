<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    exit('No autorizado');
}

if ($_SESSION['web_rol'] !== 'Admin') {
    exit('No autorizado');
}

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$valor = isset($_POST['valor']) ? trim($_POST['valor']) : '';

if ($id <= 0) {
    exit('Error: ID inválido');
}

$permitidos = ['Activo', 'Inactivo'];
if (!in_array($valor, $permitidos, true)) {
    exit('Error: Estado no permitido');
}

$stmt = $conn->prepare("UPDATE tb_trabajador SET clm_tra_contrato = ? WHERE clm_tra_id = ?");
if (!$stmt) {
    exit('Error al preparar consulta');
}

$stmt->bind_param("si", $valor, $id);

if ($stmt->execute()) {
    echo "OK";
} else {
    echo "Error al actualizar";
}

$stmt->close();
$conn->close();
?>