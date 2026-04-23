<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");

// 🔐 Iniciar sesión para acceder al token guardado
session_start();

// 🛡 Validación CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    die("CSRF detectado. Solicitud rechazada.");
}

unset($_SESSION['csrf_token']);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = $_POST['nombre'] ?? '';
    $fecha = $_POST['fecha'] ?? '';
    $hora = $_POST['hora'] ?? '';
    $puesto = $_POST['puesto'] ?? '';
    $referencia = $_POST['referencia'] ?? '';
    $observaciones = $_POST['observaciones'] ?? '';
    $dni = $_POST['dni'] ?? '';
    $sexo = $_POST['sexo'] ?? '';
    $edad = $_POST['edad'] ?? '';
    $celular = $_POST['celular'] ?? '';
    $usuarioreg  = $_SESSION['id_usuario'];
    $sede  = $_POST['sede'];

    $cv_binario = null;
    if (isset($_FILES['cv_pdf']) && $_FILES['cv_pdf']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['cv_pdf']['type'] != 'application/pdf') {
            die("Error: solo se aceptan PDFs.");
        }

        if (filesize($_FILES['cv_pdf']['tmp_name']) == 0) {
            die("Error: archivo vacío.");
        }

        $cv_binario = file_get_contents($_FILES['cv_pdf']['tmp_name']);
    }


    if (!empty($nombre) && !empty($fecha) && !empty($hora)) {
        $stmt = $conn->prepare("INSERT INTO entrevistas (nombre, fecha, hora, puesto, observaciones, dni, sexo, contacto, edad, clm_subircv, clm_usuarioreg, clm_sede, clm_referencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssibiss",$nombre, $fecha, $hora, $puesto, $observaciones, $dni, $sexo, $celular, $edad, $cv_binario, $usuarioreg, $sede, $referencia);
        $stmt->send_long_data(9, $cv_binario);
        if (!$stmt->execute()) {
            die("Error al guardar entrevista: " . $stmt->error);
        }
        $stmt->close();
        $conn->close();
        
        $_SESSION['exito'] = true;
        header("Location: ../01_entrevistas/reentrev.php");
        exit();

    } else {
        // Faltan datos, opcionalmente puedes redirigir con error
        header("Location: ../01_entrevistas/reentrev.php?success=0");
        exit();
    }
}
