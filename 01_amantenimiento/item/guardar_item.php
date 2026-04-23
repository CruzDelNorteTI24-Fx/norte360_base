<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

if (isset($_POST['item_nombre']) && isset($_POST['categoria_id']) && isset($_POST['item_tipo'])) {
    $nombre = trim($_POST['item_nombre']);
    $categoria_id = $_POST['categoria_id'];
    $tipo = $_POST['item_tipo'];
    
    if ($nombre != "" && in_array($tipo, ['R','E','Q','H','T','O','N','F','D'])) {
        $stmt = $conn->prepare("INSERT INTO tb_items_checklist (clm_item_id_categoria, clm_item_nombre, clm_items_tipo) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $categoria_id, $nombre, $tipo);
        $stmt->execute();
        $stmt->close();
    }
}


header("Location: ../categorias_items.php");
exit();
?>