<?php
define('ACCESS_GRANTED', true);

require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

require_once __DIR__ . '/live_lib.php';

function n360_live_api_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data], $flags);
    exit();
}

if (empty($_SESSION['usuario'])) {
    n360_live_api_json(false, [], 'No autorizado.', 401);
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'snapshot'));
$needsDb = $action === 'snapshot' || empty($_SESSION['n360_live_perm_checked']);
$conn = null;

if ($needsDb) {
    require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
    mysqli_report(MYSQLI_REPORT_OFF);
}

try {
    $usePermissionCache = $action !== 'snapshot';
    if (!n360_live_can_access($conn, $usePermissionCache)) {
        n360_live_api_json(false, [], 'No tienes permiso para visualizar Norte360 Live.', 403);
    }

    if ($action === 'leave') {
        n360_live_api_json(true, [
            'viewers' => n360_live_leave_presence(),
        ]);
    }

    if ($action === 'heartbeat') {
        n360_live_api_json(true, [
            'viewers' => n360_live_touch_presence(),
            'server_time' => n360_live_now()->format(DateTimeInterface::ATOM),
        ]);
    }

    if ($action !== 'snapshot') {
        n360_live_api_json(false, [], 'Accion no valida.', 400);
    }

    $force = (string)($_GET['refresh'] ?? $_POST['refresh'] ?? '') === '1';
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('No se pudo conectar a la base de datos.');
    }

    $snapshot = n360_live_fetch_snapshot($conn, $force);
    $viewers = n360_live_touch_presence();

    n360_live_api_json(true, [
        'snapshot' => $snapshot,
        'viewers' => $viewers,
        'server_time' => n360_live_now()->format(DateTimeInterface::ATOM),
        'user' => [
            'usuario' => (string)($_SESSION['usuario'] ?? ''),
            'nombre' => (string)($_SESSION['nombre'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    n360_live_api_json(false, [], $e->getMessage() ?: 'No se pudo cargar Norte360 Live.', 500);
}
