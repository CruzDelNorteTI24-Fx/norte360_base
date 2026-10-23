<?php
session_start();
date_default_timezone_set('America/Lima');
define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/flota_contratos.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function n360_contract_response(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (empty($_SESSION['usuario']) || !n360_puede_modulo(10)) n360_contract_response(false, [], 'Acceso no permitido.', 403);
$edit = n360_puede_vista('f-proghor') || n360_puede_vista('f-consalbus') || n360_puede_vista('f-proghist');
$read = $edit || n360_puede_vista('f-consalbus-ver') || n360_puede_vista('f-consalbus-aprobar');
if (!$read) n360_contract_response(false, [], 'Acceso no permitido.', 403);
define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
mysqli_report(MYSQLI_REPORT_OFF);
$transaction = false;
try {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
    if ($action === 'list' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        n360_contract_response(true, ['ready' => n360_contract_ready($conn), 'rows' => n360_contract_rows($conn)]);
    }
    if (!$edit || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('No tienes permiso de edicion.', 403);
    $uid = n360_contract_actor();
    $conn->begin_transaction();
    $transaction = true;
    n360_contract_change($conn, $action, $_POST, n360_is_admin(), $uid);
    $conn->commit();
    $transaction = false;
    n360_contract_response(true, ['rows' => n360_contract_rows($conn)], $action === 'release' ? 'Reserva liberada. No se creo ningun viaje.' : 'Retorno previsto actualizado.');
} catch (Throwable $e) {
    if ($transaction) $conn->rollback();
    $status = in_array($e->getCode(), [403,404,409,422,429], true) ? $e->getCode() : 500;
    n360_contract_response(false, [], $status === 500 ? 'No se pudo guardar el contrato. No se aplicaron cambios.' : $e->getMessage(), $status);
}
