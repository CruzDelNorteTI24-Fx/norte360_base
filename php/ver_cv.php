<?php
if (!isset($_GET['id'])) {
    die("ID no especificado.");
}

$id = intval($_GET['id']);
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$stmt = $conn->prepare("SELECT clm_subircv FROM entrevistas WHERE id_entrevista = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    die("No se encontró el archivo.");
}

$stmt->bind_result($pdf);
$stmt->fetch();
$stmt->close();
$conn->close();

header("Content-type: application/pdf");
header("Content-Disposition: inline; filename=cv_entrevista_$id.pdf");
echo $pdf;
exit;
