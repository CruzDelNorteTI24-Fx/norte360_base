<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

if (isset($_POST['categoria_nombre'])) {
    $nombre = trim($_POST['categoria_nombre']);
    if ($nombre != "") {
        $stmt = $conn->prepare("INSERT INTO tb_categorias_checklist (clm_categoria_nombre) VALUES (?)");
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $stmt->close();
    }
}

header("Location: ../categorias_items.php");
exit();
?>