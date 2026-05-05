<?php
date_default_timezone_set('America/Lima');

define('ACCESS_GRANTED', true);
require_once(__DIR__ . '/../.c0nn3ct/db_securebd2.php');

header('Content-Type: text/plain; charset=utf-8');

$tokenPermitido = 'NORTE360_CIERRE_2026_FABIO_SEGURIDAD';
$tokenRecibido = $_GET['token'] ?? '';

if ($tokenRecibido !== $tokenPermitido) {
    http_response_code(403);
    echo "Acceso denegado.";
    exit;
}

try {
    if (!$conn) {
        throw new Exception("No existe conexión a la base de datos.");
    }

    if (!$conn->query("CALL sp_cierre_operativo_progbuses()")) {
        throw new Exception($conn->error);
    }

    echo "OK - Cierre operativo ejecutado: " . date('d/m/Y H:i:s');

} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR - " . $e->getMessage();
}