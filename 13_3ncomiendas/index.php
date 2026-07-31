<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_helpers.php';

n360_start_secure_session();
if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

if (!enc_can_module()) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Encomiendas'));
    exit;
}

if (enc_can_view('enc-tracking')) {
    header('Location: tracking.php');
    exit;
}

if (enc_can_view('enc-register')) {
    header('Location: registro.php');
    exit;
}

header('Location: ../login/none_permisos.php?vista=' . urlencode('Encomiendas'));
exit;