<?php
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit("ID inválido.");
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = intval($_GET['id']);

$sql = "SELECT clm_doc_archivo, clm_doc_nombre_archivo FROM tb_documento_trabajador WHERE clm_doc_iddocumento = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    exit("Archivo no encontrado.");
}

$stmt->bind_result($blob, $nombre_original);
$stmt->fetch();
$stmt->close();
$conn->close();

$finfo = finfo_open();
$tipo_mime = finfo_buffer($finfo, $blob, FILEINFO_MIME_TYPE);
finfo_close($finfo); // ✅ Solución aplicada

header("Content-Type: $tipo_mime");
header("Content-Disposition: inline; filename=\"$nombre_original\"");
header("Content-Transfer-Encoding: binary");
header("Content-Length: " . strlen($blob));

echo $blob;
exit;
?>