<?php
session_start();

function n360_units_api_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data], $flags);
    exit();
}

if (!isset($_SESSION['usuario'])) {
    n360_units_api_json(false, [], 'No autorizado.', 401);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/unidades_lib.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!n360_units_can_access($conn)) {
    n360_units_api_json(false, [], 'No tienes permiso para visualizar unidades activas.', 403);
}

try {
    $units = n360_units_fetch_active($conn);
    $counts = [];

    foreach ($units as $unit) {
        $group = (string)($unit['grupo'] ?? 'SIN SERVICIO');
        $counts[$group] = ($counts[$group] ?? 0) + 1;
    }

    n360_units_api_json(true, [
        'units' => $units,
        'counts' => $counts,
        'total' => count($units),
        'generated_at' => date('d/m/Y H:i'),
    ]);
} catch (Throwable $e) {
    n360_units_api_json(false, [], $e->getMessage() ?: 'No se pudo cargar la relacion de unidades.', 500);
}
