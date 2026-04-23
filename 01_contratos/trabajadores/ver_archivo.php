<?php
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = intval($_GET['id']);
$campo = $_GET['campo'];

$sql = "SELECT $campo FROM tb_trabajador WHERE clm_tra_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();

$stmt->bind_result($data);
$stmt->fetch();

if ($data) {
    // Detecta tipo MIME básico (opcionalmente guárdalo en BD para más precisión)
    $finfo = finfo_open();
    $mime = finfo_buffer($finfo, $data, FILEINFO_MIME_TYPE);
    finfo_close($finfo);

    header("Content-Type: $mime");
        header("Content-Length: " . strlen($data));
    echo $data;
} else {
    echo "Archivo no encontrado.";
}

$stmt->close();
$conn->close();
?>
