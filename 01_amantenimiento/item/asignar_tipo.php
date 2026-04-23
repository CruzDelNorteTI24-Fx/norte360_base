<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $item_id = $_POST['item_id'];
    $tipo_id = $_POST['tipo_id'];

    $stmt = $conn->prepare("UPDATE tb_items_checklist SET clm_item_idtipocheck = ? WHERE clm_item_id = ?");
    $stmt->bind_param("ii", $tipo_id, $item_id);
    if ($stmt->execute()) {
        $_SESSION['exito'] = true;
    } else {
        $_SESSION['exito'] = false;
    }
    $stmt->close();
}

header("Location: ../categorias_items.php");
exit();
?>
