<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    exit('Sesion no valida.');
}

function n360_evidence_can_access(): bool
{
    if (($_SESSION['web_rol'] ?? '') === 'Admin' || ($_SESSION['permisos'] ?? null) === 'all') {
        return true;
    }

    $modules = array_map('intval', (array)($_SESSION['permisos'] ?? []));
    $views = array_map('strval', (array)($_SESSION['vistas'] ?? []));
    $allowedViews = ['c-limp', 'c-sab', 'c-lalu', 'check-datafecha'];
    return in_array(5, $modules, true) && count(array_intersect($allowedViews, $views)) > 0;
}

if (!n360_evidence_can_access()) {
    http_response_code(403);
    exit('No tienes permisos para consultar esta evidencia.');
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/checklist_evidencia_lib.php';

$checklistId = (int)($_GET['id'] ?? 0);
$mode = (string)($_GET['modo'] ?? 'inline');
if ($checklistId <= 0 || !n360_checklist_evidence_schema_ready($conn)) {
    http_response_code(404);
    exit('Evidencia no encontrada.');
}

$stmt = $conn->prepare(
    'SELECT clm_checklist_evidencia,
            clm_checklist_evidencia_nombre,
            clm_checklist_evidencia_mime,
            clm_checklist_evidencia_size
     FROM tb_checklist_limpieza
     WHERE clm_checklist_id = ?
     LIMIT 1'
);
if (!$stmt) {
    http_response_code(500);
    exit('No se pudo consultar la evidencia.');
}
$stmt->bind_param('i', $checklistId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$bytes = $row['clm_checklist_evidencia'] ?? null;
$mime = strtolower(trim((string)($row['clm_checklist_evidencia_mime'] ?? '')));
$name = trim((string)($row['clm_checklist_evidencia_nombre'] ?? ''));
if (!is_array($row) || !is_string($bytes) || $bytes === '' || !in_array($mime, $allowedMimes, true)) {
    http_response_code(404);
    exit('Evidencia no encontrada.');
}

$name = str_replace(["\r", "\n", '"'], ['', '', "'"], $name);
if ($name === '') {
    $name = $mime === 'application/pdf' ? 'evidencia.pdf' : 'evidencia';
}
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
$asciiName = $asciiName !== '' ? $asciiName : 'evidencia';
$disposition = $mode === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo $bytes;
exit();

