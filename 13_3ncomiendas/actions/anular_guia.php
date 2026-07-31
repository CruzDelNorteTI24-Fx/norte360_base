<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_helpers.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-anular');
enc_verify_action_csrf();

$id = max(0, (int)($_POST['id'] ?? 0));
$motivo = enc_nullable_string($_POST['motivo'] ?? '');
$userId = enc_user_id();

if ($id <= 0 || $motivo === null) {
    enc_json(false, 'El motivo de anulacion es obligatorio.', [], 422);
}

try {
    $guia = enc_fetch_guia($conn, $id);
    if (!$guia) enc_json(false, 'La guia no existe.', [], 404);
    if ((int)$guia['clm_enc_activo'] === 0) enc_json(false, 'La guia ya esta anulada.', [], 409);

    $conn->begin_transaction();
    enc_execute($conn, "
        UPDATE tb_enc_guias
           SET clm_enc_activo = 0,
               clm_enc_idusuario_anula = ?,
               clm_enc_idusuario_actualiza = ?,
               clm_enc_motivo_anulacion = ?
         WHERE clm_enc_id = ?
    ", 'iisi', [$userId, $userId, $motivo, $id]);
    $conn->commit();
    enc_json(true, 'Guia anulada correctamente.');
} catch (Throwable $e) {
    $conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}