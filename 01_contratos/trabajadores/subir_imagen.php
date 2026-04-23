<?php
session_start();
if ($_SESSION['web_rol'] !== 'Admin') exit('No autorizado');
define('ACCESS_GRANTED', true);

require_once("../../.c0nn3ct/db_securebd2.php");

$id    = intval($_POST['id']);
$campo = $_POST['campo'];

if (!in_array($campo, ['clm_tra_dni_foto1','clm_tra_dni_foto2','clm_tra_lic_foto1','clm_tra_lic_foto2'])) {
  exit('Campo no permitido');
}

if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['imagen'];
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    $data = file_get_contents($f['tmp_name']);

    $stmt = $conn->prepare("UPDATE tb_trabajador SET $campo = ? WHERE clm_tra_id = ?");
    $null = NULL;
    $stmt->bind_param("bi", $null, $id);
    $stmt->send_long_data(0, $data);
    $stmt->execute();
}

header("Location: detalle_trabajador.php?id=$id");
