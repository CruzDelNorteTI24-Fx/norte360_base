<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = $_GET['id'] ?? null;

if ($id) {
    $stmt = $conn->prepare("UPDATE tb_items_checklist SET clm_item_estado = 'oculto' WHERE clm_item_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: ../categorias_items.php");
exit();
?>
