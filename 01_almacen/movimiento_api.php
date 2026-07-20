<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/movimiento_backend.php';

if (!isset($_SESSION['usuario'])) {
    alm_json(['ok' => false, 'message' => 'Sesion no iniciada.'], 401);
}

if (!alm_can_almacen()) {
    alm_json(['ok' => false, 'message' => 'No tienes permiso para usar almacen.'], 403);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/movimiento_selects.php';
require_once __DIR__ . '/movimiento_guardado.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    alm_json(['ok' => false, 'message' => 'No se pudo conectar a la base de datos.'], 500);
}

$conn->set_charset('utf8mb4');
$action = trim((string)($_GET['action'] ?? ''));

try {
    switch ($action) {
        case 'catalogo_productos':
            alm_action_catalogo_productos($conn);
            break;

        case 'categorias_producto':
            alm_action_categorias_producto($conn);
            break;

        case 'crear_producto':
            alm_action_crear_producto($conn);
            break;

        case 'buses':
            alm_action_buses($conn);
            break;

        case 'trabajadores':
            alm_action_trabajadores($conn);
            break;

        case 'debug_payload':
            alm_action_debug_payload($conn);
            break;

        case 'save_entrada':
            alm_action_save_entrada($conn);
            break;

        case 'save_salida':
            alm_action_save_salida($conn);
            break;

        default:
            alm_json(['ok' => false, 'message' => 'Accion no reconocida.'], 404);
    }
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log('movimiento_api.php: ' . $e->getMessage());
    alm_json(['ok' => false, 'message' => 'No se pudo completar la operacion: ' . $e->getMessage()], 500);
}
