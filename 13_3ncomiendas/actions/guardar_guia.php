<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_validations.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-register');
enc_verify_action_csrf();

if (!enc_schema_has_guias_norte($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de Control Encomiendas antes de registrar encomiendas.', [], 409);
}

$errors = enc_validate_new_guia($_POST);
if ($errors) {
    enc_json(false, 'Revisa los campos marcados.', ['errors' => $errors], 422);
}

$userId = enc_user_id();
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}

$fechaGuia = enc_valid_date_required($_POST['fecha_guia'] ?? '');
$horario = enc_nullable_string($_POST['horario_operativo'] ?? '');
$idProgbusRaw = (int)($_POST['idprogbus'] ?? 0);
$idProgbus = $idProgbusRaw > 0 ? $idProgbusRaw : null;
$horaEmbarque = trim((string)($_POST['hora_embarque_programada'] ?? ''));
$horaEmbarque = $horaEmbarque !== '' ? substr($horaEmbarque, 0, 5) : null;
$placa = (int)($_POST['idplaca_embarque'] ?? 0);
$origen = (int)($_POST['idsede_embarque'] ?? 0);
$destino = (int)($_POST['idsede_desembarque'] ?? 0);
$obs = trim((string)($_POST['observacion'] ?? ''));
$obsOperativa = trim((string)($_POST['observacion_operativa'] ?? ''));
if ($obsOperativa !== '') {
    $obs = trim($obs . ($obs !== '' ? "\n" : '') . '[OPERACION] ' . $obsOperativa);
}
$obs = enc_nullable_string($obs);

$routePoints = $_POST['puntos_ruta'] ?? [];
if (!is_array($routePoints)) {
    $routePoints = [$routePoints];
}
$routeIds = [];
$seen = [$origen => true, $destino => true];
foreach ($routePoints as $rawPoint) {
    $pointId = (int)$rawPoint;
    if ($pointId <= 0 || isset($seen[$pointId])) {
        continue;
    }
    $routeIds[] = $pointId;
    $seen[$pointId] = true;
}

try {
    $placaRow = enc_fetch_one($conn, "
        SELECT clm_placas_id
        FROM tb_placas
        WHERE clm_placas_id = ?
        LIMIT 1
    ", 'i', [$placa]);
    if (!$placaRow) {
        enc_json(false, 'La unidad de transporte seleccionada no existe.', [
            'errors' => ['idplaca_embarque' => 'Selecciona una unidad de transporte valida.']
        ], 422);
    }

    $conn->begin_transaction();

    enc_execute($conn, "
        INSERT INTO tb_enc_guias (
            clm_enc_guia,
            clm_enc_serie,
            clm_enc_fecha_guia,
            clm_enc_horario_operativo,
            clm_enc_idprogbus,
            clm_enc_hora_embarque_programada,
            clm_enc_tipo_comprobante,
            clm_enc_numero_comprobante,
            clm_enc_fecha_comprobante,
            clm_enc_idusuario_registra,
            clm_enc_fechacreated,
            clm_enc_idplaca_embarque,
            clm_enc_idsede_embarque,
            clm_enc_idsede_desembarque,
            clm_enc_estado_embarque,
            clm_enc_estado_desembarque,
            clm_enc_observacion,
            clm_enc_activo
        ) VALUES ('', 'CE', ?, ?, ?, ?, NULL, NULL, NULL, ?, NOW(), ?, ?, ?, 'PENDIENTE', 'PENDIENTE', ?, 1)
    ", 'ssisiiiis', [
        $fechaGuia,
        $horario,
        $idProgbus,
        $horaEmbarque,
        $userId,
        $placa,
        $origen,
        $destino,
        $obs,
    ]);

    $id = (int)$conn->insert_id;
    $order = 1;
    $pointSql = "
        INSERT INTO tb_enc_guia_puntos (
            clm_encpunto_idguia,
            clm_encpunto_orden,
            clm_encpunto_idsede,
            clm_encpunto_tipo,
            clm_encpunto_manifiesto_obligatorio,
            clm_encpunto_estado,
            clm_encpunto_observacion
        ) VALUES (?, ?, ?, ?, 1, 'PENDIENTE', ?)
    ";

    enc_execute($conn, $pointSql, 'iiiss', [$id, $order++, $origen, 'ORIGEN', null]);
    foreach ($routeIds as $routeId) {
        enc_execute($conn, $pointSql, 'iiiss', [$id, $order++, $routeId, 'RUTA', null]);
    }
    enc_execute($conn, $pointSql, 'iiiss', [$id, $order, $destino, 'DESTINO', null]);

    $created = enc_fetch_one($conn, "
        SELECT clm_enc_guia, clm_enc_serie, clm_enc_correlativo
        FROM tb_enc_guias
        WHERE clm_enc_id = ?
        LIMIT 1
    ", 'i', [$id]);

    $conn->commit();

    $guideCode = (string)($created['clm_enc_guia'] ?? ('CE-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT)));
    enc_json(true, 'Control Encomienda registrada correctamente.', [
        'id' => $id,
        'guia' => $guideCode,
        'detail_url' => 'detalle.php?id=' . $id,
        'tracking_url' => 'tracking.php?guia=' . urlencode($guideCode),
    ]);
} catch (Throwable $e) {
    if ($conn->errno === 0) {
        // keep rollback guarded for engines that already closed the transaction after a fatal SQL error
    }
    $conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}
