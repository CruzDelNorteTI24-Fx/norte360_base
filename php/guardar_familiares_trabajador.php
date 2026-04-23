<?php

require_once("../.c0nn3ct/db_securebd2.php");
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_trabajador = $_POST['trabajador_id'] ?? null;
    $parentescos   = $_POST['parentesco'] ?? [];
    $nombres       = $_POST['nombre_familiar'] ?? [];
    $dnis          = $_POST['dni_familiar'] ?? [];
    $contactos          = $_POST['contacto_familiar'] ?? [];

    if ($id_trabajador && is_numeric($id_trabajador) && count($parentescos) > 0) {
        $stmt = $conn->prepare("INSERT INTO tb_trabajador_familiares (clm_famtra_trabid, clm_famtra_relacion, clm_famtra_nombres, clm_famtra_dni, clm_famtra_contacto ) VALUES (?, ?, ?, ?, ?)");

        for ($i = 0; $i < count($parentescos); $i++) {
            $par = trim($parentescos[$i]);
            $nom = trim($nombres[$i]);
            $dni = trim($dnis[$i]);
            $contacto = trim($contactos[$i]);

            if (!empty($par) && !empty($nom) && !empty($dni) && !empty($contacto)) {
                $stmt->bind_param("issss", $id_trabajador, $par, $nom, $dni, $contacto);
                $stmt->execute();
            }
        }

        $stmt->close();
    }
    // 🔶 GUARDAR CONTACTO DE EMERGENCIA
    $emerg_parentesco = $_POST['emerg_parentesco'] ?? '';
    $emerg_nombre     = $_POST['emerg_nombre'] ?? '';
    $emerg_celular    = $_POST['emerg_celular'] ?? '';
    $emerg_dni    = $_POST['emerg_dni'] ?? '';

    if (!empty($emerg_parentesco) && !empty($emerg_nombre) && !empty($emerg_celular) && !empty($emerg_dni)) {
        $stmt_emerg = $conn->prepare("INSERT INTO tb_trabajador_emergencia (clm_emerg_trabid, clm_emerg_parentesco, clm_emerg_nombre, clm_emerg_celular, clm_emerg_dni) VALUES (?, ?, ?, ?, ?)");
        $stmt_emerg->bind_param("issss", $id_trabajador, $emerg_parentesco, $emerg_nombre, $emerg_celular, $emerg_dni);
        $stmt_emerg->execute();
        $stmt_emerg->close();
    }
    if (!defined('ACCESS_GRANTED')) {
        // Llamado directo: cerramos y redirigimos
        $conn->close();
        header("Location: ../01_contratos/nregrcdn_h.php?familiar=ok");
        exit();
    }
    // Llamado desde guardar_trabajador.php: NO cerrar conexión ni redirigir.
    // Devolvemos el control al script principal para que termine (planilla, exito, etc.)

} else {
    http_response_code(405);
    echo "Método no permitido.";
}
?>
