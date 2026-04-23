<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");

session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $_SESSION['prellenar_trabajador'] = [
        'nombre' => $_POST['nombre'] ?? '',
        'dni' => $_POST['dni'] ?? '',
        'sexo' => $_POST['sexo'] ?? '',
        'celular' => $_POST['celular'] ?? '',
        'cargo' => $_POST['cargo'] ?? '',
        'id_entrevista' => $_POST['id_entrevista'] ?? '',
        'puesto' => 'Personal' ?? ''
        
    ];

    header("Location: ../01_contratos/nregrcdn_h.php?desde=entrevista");
    exit();
} else {
    echo "❌ Método no permitido.";
}

?>
