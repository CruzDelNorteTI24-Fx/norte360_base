<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = $_POST['id'] ?? null; // CORREGIDO: Obtener por POST
$nombre = $_POST['categoria_nombre'] ?? null;

if ($id && $nombre !== null && trim($nombre) !== "") {
    $stmt = $conn->prepare("UPDATE tb_categorias_checklist SET clm_categoria_nombre = ? WHERE clm_categoria_id = ?");
    $stmt->bind_param("si", $nombre, $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: ../categorias_items.php");
exit();
