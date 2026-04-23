<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('ACCESS_GRANTED', true);
    require '../../.c0nn3ct/db_securebd2.php'; // <- Asegúrate que apunta correctamente a tu archivo de conexión

    $clm_doc_idtrabajador = intval($_POST['idtrabajador']);
    $clm_doc_idtipo_documento = intval($_POST['idtipo_documento']);
    $clm_doc_observaciones = trim($_POST['observaciones']);
    $usuario = $_SESSION['usuario'];

    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === 0) {
        $clm_doc_nombre_archivo = $_FILES['archivo']['name'];
        $clm_doc_tipo_mime = $_FILES['archivo']['type'];
        $contenido = file_get_contents($_FILES['archivo']['tmp_name']);

        $stmt = $conn->prepare("INSERT INTO tb_documento_trabajador 
            (clm_doc_idtrabajador, clm_doc_idtipo_documento, clm_doc_nombre_archivo, clm_doc_archivo, clm_doc_tipo_mime, clm_doc_observaciones, clm_doc_usuario_registro)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param("iisssss", 
            $clm_doc_idtrabajador, 
            $clm_doc_idtipo_documento, 
            $clm_doc_nombre_archivo, 
            $contenido, 
            $clm_doc_tipo_mime, 
            $clm_doc_observaciones, 
            $usuario
        );

        if ($stmt->execute()) {
            $_SESSION['exito'] = true;
            header("Location: ../dorrhcdn.php");
            exit();
        } else {
            echo "<p class='invalid'>Error al guardar el documento: " . $stmt->error . "</p>";
        }

        $stmt->close();
    } else {
        echo "<p class='invalid'>Error al subir el archivo. Asegúrese de seleccionar un archivo válido.</p>";
    }

    $conn->close();
} else {
    echo "<p class='invalid'>Acceso no permitido.</p>";
}
?>
