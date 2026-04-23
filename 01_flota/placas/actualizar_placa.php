<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

// Validar que el formulario fue enviado por POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Preparar y sanitizar entradas
    $id = intval($_POST['clm_placas_id']);
    $placa = trim($_POST['clm_placas_PLACA']);
    $dueño = trim($_POST['clm_placas_DUEÑO']);
    $bus = trim($_POST['clm_placas_BUS']);
    $tipo = trim($_POST['clm_placas_TIPO_VEHÍCULO']);
    $estado = trim($_POST['clm_placas_ESTADO']);
    $kilometraje = trim($_POST['clm_placas_KILOMETRAJE']);
    $servicio = trim($_POST['clm_placas_servicio']);

    // Preparar la consulta UPDATE
    $stmt = $conn->prepare("UPDATE tb_placas SET clm_placas_PLACA=?, clm_placas_DUEÑO=?, clm_placas_BUS=?, clm_placas_TIPO_VEHÍCULO=?, clm_placas_ESTADO=?, clm_placas_KILOMETRAJE=?, clm_placas_servicio=? WHERE clm_placas_id=?");

    if ($stmt) {
        $stmt->bind_param("sssssssi", $placa, $dueño, $bus, $tipo, $estado, $kilometraje, $servicio, $id);

        if ($stmt->execute()) {
            $_SESSION['exito'] = true;
        } else {
            $_SESSION['exito'] = false;
            $_SESSION['error_sql'] = "Error al actualizar la placa.";
        }

        $stmt->close();
    } else {
        $_SESSION['exito'] = false;
        $_SESSION['error_sql'] = "Error en la preparación de la consulta.";
    }

    // Redirigir de nuevo a gest_plac.php
    header("Location: ../gest_plac.php");
    exit();
} else {
    // Si el acceso no es por POST, redirigir por seguridad
    header("Location: ../gest_plac.php");
    exit();
}
?>
