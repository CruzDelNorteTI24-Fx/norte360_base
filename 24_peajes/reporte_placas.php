<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

define('N360_PEAJES', true);
require_once __DIR__ . '/lib/peajes_helpers.php';
require_once __DIR__ . '/lib/peajes_reporte_placas.php';

function pje_report_response(int $status, array $payload): void {
    http_response_code($status);
    ob_clean();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['usuario'])) {
    pje_report_response(401, ['ok' => false, 'message' => 'Tu sesion vencio. Inicia sesion nuevamente.']);
}
if (!pje_can_modulo(4)) {
    pje_report_response(403, ['ok' => false, 'message' => 'No tienes acceso al modulo Peajes.']);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    pje_report_response(405, ['ok' => false, 'message' => 'Metodo no permitido.']);
}
try {
    $body = [];
    if ($method === 'POST') {
        if (!pje_is_admin()) {
            pje_report_response(403, ['ok' => false, 'message' => 'Solo un administrador puede gestionar las placas.']);
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 100000) {
            pje_report_response(413, ['ok' => false, 'message' => 'La solicitud es demasiado grande.']);
        }
        $body = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body) || !is_string($body['csrf'] ?? null)
            || empty($_SESSION['pje_report_csrf'])
            || !hash_equals($_SESSION['pje_report_csrf'], $body['csrf'])) {
            pje_report_response(419, ['ok' => false, 'message' => 'La sesion de gestion vencio. Recarga la pagina.']);
        }
    }
    $action = $method === 'POST' ? 'save' : (string)($_GET['action'] ?? 'report');
    if (!in_array($action, ['report', 'config', 'suppliers', 'save'], true)) {
        throw new InvalidArgumentException('Accion no valida.');
    }
    if ($action === 'config' && !pje_is_admin()) {
        pje_report_response(403, ['ok' => false, 'message' => 'Solo un administrador puede gestionar las placas.']);
    }
    $id = filter_var($body['proveedor'] ?? $_GET['proveedor'] ?? 0, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]);
    if ($action !== 'suppliers' && $id === false) {
        throw new InvalidArgumentException('Selecciona un proveedor.');
    }
    define('ACCESS_GRANTED', true);
    require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        throw new RuntimeException('No se pudo conectar a la base de datos.');
    }
    $conn->set_charset('utf8mb4');
    if ($action === 'save') {
        if (!is_int($body['habilitado'] ?? null) || !is_array($body['placas'] ?? null)
            || !is_string($body['version'] ?? null)) {
            throw new InvalidArgumentException('La configuracion enviada no es valida.');
        }
        pje_report_save($conn, $id, $body['habilitado'], $body['placas'], $body['version']);
        $data = ['proveedores' => pje_report_suppliers($conn), 'message' => 'Seleccion de placas guardada.'];
    } elseif ($action === 'suppliers') {
        $data = ['proveedores' => pje_report_suppliers($conn, !pje_is_admin())];
    } else {
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
        try {
            $data = $action === 'config' ? pje_report_configuration($conn, $id)
                : pje_report_data($conn, pje_current_filters(), $id);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    pje_report_response(200, ['ok' => true, 'data' => $data]);
} catch (JsonException | InvalidArgumentException $e) {
    pje_report_response(422, ['ok' => false, 'message' => $e instanceof JsonException
        ? 'La solicitud no contiene un JSON valido.' : $e->getMessage()]);
} catch (Throwable $e) {
    if ($e->getCode() === 409) {
        pje_report_response(409, ['ok' => false, 'message' => $e->getMessage()]);
    }
    error_log('Peajes reporte por proveedor: ' . $e->getMessage());
    pje_report_response(500, ['ok' => false, 'message' => 'No se pudo procesar el reporte. Verifica la configuracion de Peajes.']);
}
