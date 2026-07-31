<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_helpers.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

n360_start_secure_session();
if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    exit('Sesion expirada.');
}
if (!enc_can_module() || !enc_can_any_view(['enc-tracking', 'enc-docs'])) {
    http_response_code(403);
    exit('No autorizado.');
}

define('ACCESS_GRANTED', true);
require __DIR__ . '/../../.c0nn3ct/db_securebd2.php';
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    http_response_code(500);
    exit('No se pudo conectar.');
}
$conn->set_charset('utf8mb4');
@$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

$docId = max(0, (int)($_GET['id'] ?? 0));
$mode = (string)($_GET['mode'] ?? 'view');
if ($docId <= 0) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

try {
    $doc = enc_fetch_document_blob($conn, $docId);
    if (!$doc) {
        http_response_code(404);
        exit('Documento no encontrado.');
    }

    while (ob_get_level() > 0) ob_end_clean();
    $filename = enc_safe_pdf_name((string)$doc['clm_encdoc_nombre'], 'encomienda_' . (string)$doc['clm_enc_guia']);
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($doc['clm_encdoc_archivo']));
    header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $filename) . '"');
    echo $doc['clm_encdoc_archivo'];
    exit;
} catch (Throwable $e) {
    enc_log($e);
    http_response_code(500);
    exit('No se pudo entregar el documento.');
}