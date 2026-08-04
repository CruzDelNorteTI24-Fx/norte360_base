<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_validations.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-desembarque');
enc_verify_action_csrf();

if (!enc_schema_has_guias_norte($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de Control Encomiendas antes de actualizar el desembarque.', [], 409);
}

$id = max(0, (int)($_POST['id'] ?? 0));
$estado = strtoupper(trim((string)($_POST['estado'] ?? '')));
$obs = enc_nullable_string($_POST['observacion'] ?? '');
$userId = enc_user_id();

if ($id <= 0 || !enc_validate_estado_desembarque($estado)) {
    enc_json(false, 'Datos de desembarque invalidos.', [], 422);
}
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}

try {
    $guia = enc_fetch_guia($conn, $id);
    if (!$guia) enc_json(false, 'La Control Encomienda no existe.', [], 404);
    if ((int)$guia['clm_enc_activo'] === 0) enc_json(false, 'La Control Encomienda esta anulada.', [], 409);
    if ($guia['clm_enc_estado_embarque'] !== 'EMBARCADO') {
        enc_json(false, 'No puedes desembarcar una Control Encomienda que aun no fue embarcada.', [], 422);
    }

    if ($estado === 'RECIBIDO') {
        $missing = enc_missing_required_manifests($conn, $id);
        if ($missing) {
            $labels = array_map(static function (array $point): string {
                return '#' . (int)$point['clm_encpunto_orden'] . ' ' . (string)$point['sede_nombre'];
            }, array_slice($missing, 0, 4));
            $suffix = count($missing) > 4 ? ' y ' . (count($missing) - 4) . ' mas' : '';
            enc_json(false, 'Faltan manifiestos obligatorios para: ' . implode(', ', $labels) . $suffix . '.', [], 422);
        }
    }

    $newObs = $obs ?: ($guia['clm_enc_observacion'] ?? null);
    $conn->begin_transaction();
    enc_execute($conn, "
        UPDATE tb_enc_guias
           SET clm_enc_estado_desembarque = ?,
               clm_enc_idusuario_desembarque = ?,
               clm_enc_idusuario_actualiza = ?,
               clm_enc_fecha_desembarque = CASE
                   WHEN ? IN ('RECIBIDO','INCOMPLETO','OBSERVADO') AND clm_enc_fecha_desembarque IS NULL THEN NOW()
                   ELSE clm_enc_fecha_desembarque
               END,
               clm_enc_observacion = ?
         WHERE clm_enc_id = ?
    ", 'siissi', [$estado, $userId, $userId, $estado, $newObs, $id]);

    enc_execute($conn, "
        UPDATE tb_enc_guia_puntos
           SET clm_encpunto_estado = CASE
                   WHEN ? = 'RECIBIDO' THEN 'RECIBIDO'
                   WHEN ? = 'INCOMPLETO' THEN 'INCOMPLETO'
                   WHEN ? = 'OBSERVADO' THEN 'OBSERVADO'
                   ELSE clm_encpunto_estado
               END,
               clm_encpunto_fecha_evento = CASE
                   WHEN clm_encpunto_fecha_evento IS NULL THEN NOW()
                   ELSE clm_encpunto_fecha_evento
               END,
               clm_encpunto_idusuario_evento = COALESCE(clm_encpunto_idusuario_evento, ?)
         WHERE clm_encpunto_idguia = ?
           AND clm_encpunto_activo = 1
    ", 'sssii', [$estado, $estado, $estado, $userId, $id]);

    $conn->commit();
    enc_json(true, 'Desembarque actualizado correctamente.', ['id' => $id]);
} catch (Throwable $e) {
    $conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}