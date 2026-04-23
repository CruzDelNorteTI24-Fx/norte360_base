<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trabid    = intval($_POST['clm_nmna_trabid']);
    $fechaIng  = $_POST['clm_nmna_fechaingrespl'];          // 'YYYY-MM-DD'
    $coment    = $_POST['clm_nmna_com'];
    $docData   = file_get_contents($_FILES['clm_nmna_doc']['tmp_name']);

    // 1. Calculamos la fecha de registro en PHP
    $fechaReg = date('Y-m-d H:i:s'); // e.g. "2025-07-31 14:23:45"

    // 2. Preparamos el INSERT con placeholders para ambas fechas
    $sql = "
      INSERT INTO tb_nomina (
        clm_nmna_trabid,
        clm_nmna_tra_estado,
        clm_nmna_fechaingrespl,
        clm_nmna_fechregistro,
        clm_nmna_doc,
        clm_nmna_com
      ) VALUES (
        ?,    -- trabajador
        1,    -- estado = activo
        ?,    -- fecha ingreso
        ?,    -- fecha registro (PHP)
        ?,    -- documento BLOB
        ?     -- comentario
      )
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error en prepare(): " . $conn->error);
    }

    // 3. Ligamos parámetros:
    // i = integer, s = string/date, s = string/datetime, s = blob, s = string
    $null = NULL;
    $stmt->bind_param(
        "issss",
        $trabid,    // i
        $fechaIng,  // s (YYYY-MM-DD)
        $fechaReg,  // s (YYYY-MM-DD HH:MM:SS)
        $null,      // placeholder para BLOB
        $coment     // s
    );

    // 4. Enviamos el contenido BLOB al cuarto placeholder (index 3)
    $stmt->send_long_data(3, $docData);

    // 5. Ejecutamos y redirigimos
    if ($stmt->execute()) {
        $_SESSION['exito'] = true;
        header("Location: ver_listanomina.php");
        exit();
    } else {
        die("Error al guardar la nómina: " . $stmt->error);
    }
}
