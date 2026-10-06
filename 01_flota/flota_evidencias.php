<?php
session_start();
require_once __DIR__ . '/flota_evidencias_lib.php';

function n360_flota_evidence_json(bool $ok, array $data = [], string $message = '', int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['ok' => $ok, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['usuario'])) {
    n360_flota_evidence_json(false, [], 'Tu sesion termino. Inicia sesion nuevamente.', 401);
}
if (!n360_flota_evidence_can_access($_SESSION)) {
    n360_flota_evidence_json(false, [], 'No tienes permiso para las evidencias de Flota.', 403);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && !n360_flota_evidence_can_upload($_SESSION)) {
    n360_flota_evidence_json(false, [], 'Tu permiso de evidencias es de solo lectura.', 403);
}
if ($method === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) {
    n360_flota_evidence_json(false, [], 'El servidor rechazo la carga por su limite de tamano. Usa un archivo menor o revisa el limite del hosting.', 413);
}
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'list'));
if (!in_array($method, ['GET', 'POST'], true)
    || ($method === 'GET' && !in_array($action, ['list', 'view', 'download'], true))
    || ($method === 'POST' && $action !== 'upload')) {
    n360_flota_evidence_json(false, [], 'Accion no permitida.', 405);
}
$userId = (int)($_SESSION['id_usuario'] ?? $_SESSION['web_id_usuario'] ?? 0);
$user = (string)($_SESSION['nombre'] ?? $_SESSION['usuario']);
if ($method === 'POST') {
    $token = (string)($_SESSION['flota_evidencias_token'] ?? '');
    if ($token === '' || !hash_equals($token, (string)($_POST['csrf'] ?? ''))) {
        n360_flota_evidence_json(false, [], 'La sesion de carga no es valida. Actualiza la pagina.', 419);
    }
    if ($userId <= 0) {
        n360_flota_evidence_json(false, [], 'No se pudo identificar al usuario de la carga.', 401);
    }
}
session_write_close();

try {
    define('ACCESS_GRANTED', true);
    require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!isset($conn) || !($conn instanceof mysqli) || !n360_flota_evidence_schema_ready($conn)) {
        n360_flota_evidence_json(false, [], 'Falta instalar database/tb_progbuses_evidencias_operativas.sql en la base de datos.', 503);
    }
    if ($action === 'list') {
        $start = n360_flota_evidence_date((string)($_GET['inicio'] ?? ''));
        $end = n360_flota_evidence_date((string)($_GET['fin'] ?? $start));
        if ($end < $start || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 30) {
            throw new InvalidArgumentException('Consulta las evidencias en periodos de hasta 31 dias.');
        }
        n360_flota_evidence_json(true, ['evidencias' => n360_flota_evidence_metadata($conn, $start, $end)]);
    }
    if ($action === 'upload') {
        $date = n360_flota_evidence_date((string)($_POST['fecha'] ?? ''));
        $slot = n360_flota_evidence_slot((int)($_POST['cupo'] ?? 0));
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($revision === false || $revision === null || $revision < 0) {
            throw new InvalidArgumentException('Actualiza las evidencias antes de guardar.');
        }
        $evidence = n360_flota_evidence_upload($_FILES);
        n360_flota_evidence_store($conn, $date, $slot, $revision, $userId, $user, $evidence);
        n360_flota_evidence_json(true, ['evidencias' => n360_flota_evidence_metadata($conn, $date, $date)], 'Evidencia guardada.');
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        n360_flota_evidence_json(false, [], 'Evidencia no encontrada.', 404);
    }
    $stmt = $conn->prepare('SELECT clm_eviop_archivo, clm_eviop_nombre, clm_eviop_mime
        FROM tb_progbuses_evidencias_operativas WHERE clm_eviop_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('No se pudo consultar el archivo.');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo consultar el archivo.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !in_array($row['clm_eviop_mime'], ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
        n360_flota_evidence_json(false, [], 'Evidencia no encontrada.', 404);
    }
    $name = str_replace(["\r", "\n", '"'], ['', '', "'"], $row['clm_eviop_nombre']);
    $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'evidencia';
    $disposition = $action === 'download' ? 'attachment' : 'inline';
    header('Content-Type: ' . $row['clm_eviop_mime']);
    header('Content-Length: ' . strlen($row['clm_eviop_archivo']));
    header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    echo $row['clm_eviop_archivo'];
} catch (InvalidArgumentException $e) {
    n360_flota_evidence_json(false, [], $e->getMessage(), 400);
} catch (Throwable $e) {
    if ($e->getCode() === 409) {
        n360_flota_evidence_json(false, [], $e->getMessage(), 409);
    }
    n360_flota_evidence_json(false, [], 'No se pudo completar la operacion con la evidencia.', 500);
}
