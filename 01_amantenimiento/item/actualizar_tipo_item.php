<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");


if (isset($_POST['item_id']) && isset($_POST['nuevo_tipo'])) {
    $item_id = $_POST['item_id'];
    $nuevo_tipo = $_POST['nuevo_tipo'];

    if (in_array($nuevo_tipo, ['R','E','Q','H','T','O','N','F','D'])) {
        $stmt = $conn->prepare("UPDATE tb_items_checklist SET clm_items_tipo = ? WHERE clm_item_id = ?");
        $stmt->bind_param("si", $nuevo_tipo, $item_id);
        $stmt->execute();
        $stmt->close();
    }
}

header("Location: ../categorias_items.php");
exit();
?>
