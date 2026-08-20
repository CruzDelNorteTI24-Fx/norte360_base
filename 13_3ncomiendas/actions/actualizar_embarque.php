<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_validations.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-embarque');
enc_verify_action_csrf();

if (!enc_schema_has_guias_norte($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de Control Encomiendas antes de actualizar el embarque.', [], 409);
}

$id = max(0, (int)($_POST['id'] ?? 0));
$estado = strtoupper(trim((string)($_POST['estado'] ?? '')));
$obs = enc_nullable_string($_POST['observacion'] ?? '');
$userId = enc_user_id();

if ($id <= 0 || !enc_validate_estado_embarque($estado)) {
    enc_json(false, 'Datos de embarque invalidos.', [], 422);
}
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}

try {
    $guia = enc_fetch_guia($conn, $id);
    if (!$guia) enc_json(false, 'La Control Encomienda no existe.', [], 404);
    if ((int)$guia['clm_enc_activo'] === 0) enc_json(false, 'La Control Encomienda esta anulada.', [], 409);
    if ($estado === 'EMBARCADO' && empty($guia['clm_enc_idplaca_embarque'])) {
        enc_json(false, 'Para confirmar el embarque debes seleccionar una unidad en la Control Encomienda.', [], 422);
    }

    $newObs = $obs ?: ($guia['clm_enc_observacion'] ?? null);
    $conn->begin_transaction();
    enc_execute($conn, "
        UPDATE tb_enc_guias
           SET clm_enc_estado_embarque = ?,
               clm_enc_idusuario_embarque = ?,
               clm_enc_idusuario_actualiza = ?,
               clm_enc_tipo_comprobante = NULL,
               clm_enc_numero_comprobante = NULL,
               clm_enc_fecha_comprobante = NULL,
               clm_enc_fecha_embarque = CASE
                   WHEN ? IN ('EMBARCADO','OBSERVADO') AND clm_enc_fecha_embarque IS NULL THEN NOW()
                   ELSE clm_enc_fecha_embarque
               END,
               clm_enc_observacion = ?
         WHERE clm_enc_id = ?
    ", 'siissi', [$estado, $userId, $userId, $estado, $newObs, $id]);
    $conn->commit();
    enc_json(true, 'Embarque actualizado correctamente.', ['id' => $id]);
} catch (Throwable $e) {
    $conn->rollback();
    enc_log($e);
    $status = 500;
    if ((int)$e->getCode() === 1644 || (method_exists($e, 'getSqlState') && $e->getSqlState() === '45000')) {
        $status = 422;
    }
    enc_json(false, enc_db_message($e), [], $status);
}
