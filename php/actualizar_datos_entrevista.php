<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function responder_json(bool $ok, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['usuario'])) {
    responder_json(false, 'No autorizado.', [], 401);
}

$rol = (string)($_SESSION['web_rol'] ?? '');
$permisos = $_SESSION['permisos'] ?? [];
if (!is_array($permisos)) {
    $permisos = [];
}

if (!($rol === 'Admin' || in_array(6, $permisos, true) || in_array('6', $permisos, true))) {
    responder_json(false, 'Sin permisos para actualizar entrevistas.', [], 403);
}

if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf'])) {
    responder_json(false, 'CSRF invalido.', [], 403);
}

$id = isset($_POST['id_entrevista']) ? (int)$_POST['id_entrevista'] : 0;
$sexo = trim((string)($_POST['sexo'] ?? ''));
$sede = trim((string)($_POST['sede'] ?? ''));
$referencia = trim((string)($_POST['referencia'] ?? ''));
$observaciones = trim((string)($_POST['observaciones'] ?? ''));

if ($id <= 0) {
    responder_json(false, 'ID de entrevista invalido.', [], 400);
}

if (!in_array($sexo, ['Femenino', 'Masculino'], true)) {
    responder_json(false, 'Selecciona un sexo valido.', [], 422);
}

if (mb_strlen($sede) > 120) {
    responder_json(false, 'La sede es demasiado larga.', [], 422);
}

if (mb_strlen($referencia) > 500) {
    responder_json(false, 'La referencia es demasiado larga.', [], 422);
}

if (mb_strlen($observaciones) > 2000) {
    responder_json(false, 'Las observaciones son demasiado largas.', [], 422);
}

try {
    define('ACCESS_GRANTED', true);
    require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        responder_json(false, 'No se pudo abrir la conexion.', [], 500);
    }

    $stmt = $conn->prepare(
        'UPDATE entrevistas
            SET sexo = ?, clm_sede = ?, clm_referencia = ?, observaciones = ?
          WHERE id_entrevista = ?'
    );

    if (!$stmt) {
        responder_json(false, 'No se pudo preparar la actualizacion.', [], 500);
    }

    $stmt->bind_param('ssssi', $sexo, $sede, $referencia, $observaciones, $id);
    $stmt->execute();
    $stmt->close();

    responder_json(true, 'Datos de entrevista actualizados.', [
        'data' => [
            'sexo' => $sexo,
            'sede' => $sede,
            'referencia' => $referencia,
            'observaciones' => $observaciones,
        ],
    ]);
} catch (Throwable $e) {
    responder_json(false, 'No se pudo actualizar la entrevista.', [], 500);
}