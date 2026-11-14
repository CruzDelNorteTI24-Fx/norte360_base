<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function n360_espper_json(array $data, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['usuario'])) n360_espper_json(['ok' => false, 'message' => 'Tu sesión terminó. Vuelve a iniciar sesión.'], 401);
if (($_SESSION['web_rol'] ?? '') !== 'Admin') n360_espper_json(['ok' => false, 'message' => 'No tienes permiso para gestionar personal de los espacios.'], 403);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    n360_espper_json(['ok' => false, 'message' => 'Método no permitido.'], 405);
}
$payload = [];
if ($method === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;
    $expected = $_SESSION['sede_espacios_csrf'] ?? '';
    if (!is_string($payload['csrf'] ?? null) || $expected === '' || !hash_equals($expected, $payload['csrf'])) {
        n360_espper_json(['ok' => false, 'message' => 'La sesión del formulario venció. Actualiza la página.'], 419);
    }
}
define('ACCESS_GRANTED', true);
require_once __DIR__ . '/_espacios_personal.php';
try {
    $action = $method === 'POST' ? '' : ($_GET['action'] ?? 'list');
    if ($action === 'workers') {
        $query = $_GET['q'] ?? '';
        if (!is_string($query) || alm_activos_longitud($query) > 100) throw new InvalidArgumentException('La búsqueda admite hasta 100 caracteres.');
        n360_espper_json(['ok' => true] + n360_espper_workers(trim($query)));
    }
    $spaceId = null;
    $refresh = false;
    if ($method !== 'POST') {
        if ($action !== 'list') throw new InvalidArgumentException('Consulta inválida.');
        $spaceId = alm_activos_espacio_id($_GET['espacio_id'] ?? null);
        if (!$spaceId) throw new InvalidArgumentException('Selecciona un espacio válido.');
        if (isset($_GET['refresh']) && $_GET['refresh'] !== '1') throw new InvalidArgumentException('Actualización inválida.');
        $refresh = isset($_GET['refresh']);
    }
    require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) throw new RuntimeException('Conexión no disponible.');
    $conn->set_charset('utf8mb4');
    if (!n360_espper_schema($conn)['ready']) throw new RuntimeException('El personal por espacio aún está pendiente de habilitación.', 409);
    if ($method === 'POST') {
        $user = alm_user_id($conn);
        if ($user <= 0) throw new InvalidArgumentException('No se pudo identificar al usuario de la sesión.');
        $id = n360_espper_guardar($conn, $payload, $user);
        $response = ['ok' => true, 'id' => $id, 'message' => $payload['action'] === 'retire'
            ? 'Persona retirada del turno. Su historial se conserva.' : 'Persona asignada al turno.', 'personnel' => null];
        try {
            n360_espper_catalogo_cargar($conn);
            $response['personnel'] = n360_espper_listar($conn, (int)$payload['espacio_id']);
        } catch (Throwable $e) {
            // El commit ya terminó: un fallo al releer nunca se presenta como una escritura fallida.
            unset($_SESSION['sede_espacios_personal_catalogo']);
            error_log('espacios_personal_api.php actualización posterior al guardado: ' . $e->getMessage());
            $response['refresh_error'] = 'El cambio se guardó, pero no se pudo actualizar la lista. Pulsa Actualizar antes de continuar.';
        }
        n360_espper_json($response);
    }
    if ($refresh || !n360_espper_catalogo_en_cache()) n360_espper_catalogo_cargar($conn);
    n360_espper_json(['ok' => true] + n360_espper_listar($conn, $spaceId));
} catch (Throwable $e) {
    $status = $e instanceof InvalidArgumentException ? 422 : (in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 500);
    if ($status === 500) error_log('espacios_personal_api.php: ' . $e->getMessage());
    n360_espper_json(['ok' => false, 'message' => $status === 500 ? 'No se pudo procesar el personal del espacio. Inténtalo nuevamente.' : $e->getMessage()], $status);
}
