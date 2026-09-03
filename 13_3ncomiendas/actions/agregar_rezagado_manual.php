<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-tracking');
enc_verify_action_csrf();

if (!enc_schema_has_manifest_review_pages($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de revisiones de manifiestos por hoja.', [], 409);
}

function enc_rezagado_manual_text($value, int $limit = 500): ?string {
    $value = enc_pdf_clean_token((string)$value);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
    return substr($value, 0, $limit);
}

function enc_rezagado_manual_decimal($value): ?float {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : null;
}

$revisionId = max(0, (int)($_POST['revision_id'] ?? 0));
$documento = enc_rezagado_manual_text($_POST['documento'] ?? '', 100);
$consignado = enc_rezagado_manual_text($_POST['consignado'] ?? '', 255);
$referencia = enc_rezagado_manual_text($_POST['referencia_envio'] ?? '', 3000);
$peso = enc_rezagado_manual_decimal($_POST['peso'] ?? '');
$tipoPago = enc_rezagado_manual_text($_POST['tipo_pago'] ?? '', 80);
$importe = enc_rezagado_manual_decimal($_POST['importe_cobrado'] ?? '');
$guiaRemision = enc_rezagado_manual_text($_POST['guia_remision'] ?? '', 100);
$estado = strtoupper(trim((string)($_POST['estado'] ?? 'REZAGADO')));
$observacion = enc_rezagado_manual_text($_POST['observacion'] ?? '', 2000);
$userId = enc_user_id();

if ($revisionId <= 0) {
    enc_json(false, 'Selecciona la revision u hoja donde se agregara la encomienda.', [], 422);
}
if ($documento === null && $consignado === null && $referencia === null && $guiaRemision === null) {
    enc_json(false, 'Registra al menos documento, consignado, referencia o guia de remision.', [], 422);
}
if (!in_array($estado, ['PENDIENTE', 'OK', 'REZAGADO', 'OBSERVADO'], true)) {
    $estado = 'REZAGADO';
}
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}

try {
    $review = enc_fetch_one($conn, "
        SELECT r.clm_encrev_id,
               r.clm_encrev_iddocumento,
               r.clm_encrev_idguia,
               g.clm_enc_guia
        FROM tb_enc_manifiesto_revisiones r
        INNER JOIN tb_enc_documentos d
                ON d.clm_encdoc_id = r.clm_encrev_iddocumento
               AND d.clm_encdoc_tipo = 'MANIFIESTO_ENCOMIENDAS'
               AND d.clm_encdoc_estado = 1
        INNER JOIN tb_enc_guias g
                ON g.clm_enc_id = r.clm_encrev_idguia
        WHERE r.clm_encrev_id = ?
          AND r.clm_encrev_activo = 1
        LIMIT 1
    ", 'i', [$revisionId]);

    if (!$review) {
        enc_json(false, 'La revision seleccionada ya no esta disponible.', [], 404);
    }

    $next = enc_fetch_one($conn, "
        SELECT COALESCE(MAX(clm_encrevitem_orden), 0) + 1 AS siguiente
        FROM tb_enc_manifiesto_revision_items
        WHERE clm_encrevitem_idrevision = ?
    ", 'i', [$revisionId]);
    $orden = max(1, (int)($next['siguiente'] ?? 1));

    $conn->begin_transaction();

    enc_execute($conn, "
        INSERT INTO tb_enc_manifiesto_revision_items (
            clm_encrevitem_idrevision,
            clm_encrevitem_orden,
            clm_encrevitem_documento,
            clm_encrevitem_consignado,
            clm_encrevitem_referencia_envio,
            clm_encrevitem_peso,
            clm_encrevitem_tipo_pago,
            clm_encrevitem_importe_cobrado,
            clm_encrevitem_guia_remision,
            clm_encrevitem_estado,
            clm_encrevitem_observacion,
            clm_encrevitem_activo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ", 'iisssdsdsss', [
        $revisionId,
        $orden,
        $documento,
        $consignado,
        $referencia,
        $peso,
        $tipoPago,
        $importe,
        $guiaRemision,
        $estado,
        $observacion,
    ]);

    enc_execute($conn, "
        UPDATE tb_enc_manifiesto_revisiones r
           SET r.clm_encrev_total_items = (
                   SELECT COUNT(*)
                   FROM tb_enc_manifiesto_revision_items i
                   WHERE i.clm_encrevitem_idrevision = r.clm_encrev_id
                     AND i.clm_encrevitem_activo = 1
               ),
               r.clm_encrev_total_rezagados = (
                   SELECT COUNT(*)
                   FROM tb_enc_manifiesto_revision_items i
                   WHERE i.clm_encrevitem_idrevision = r.clm_encrev_id
                     AND i.clm_encrevitem_activo = 1
                     AND i.clm_encrevitem_estado = 'REZAGADO'
               ),
               r.clm_encrev_estado = CASE
                   WHEN EXISTS (
                       SELECT 1
                       FROM tb_enc_manifiesto_revision_items i
                       WHERE i.clm_encrevitem_idrevision = r.clm_encrev_id
                         AND i.clm_encrevitem_activo = 1
                         AND i.clm_encrevitem_estado IN ('PENDIENTE', 'OBSERVADO')
                   ) THEN 'EN_REVISION'
                   ELSE 'CERRADO'
               END,
               r.clm_encrev_idusuario_actualiza = ?,
               r.clm_encrev_datetimeupdated = NOW()
         WHERE r.clm_encrev_id = ?
    ", 'ii', [$userId, $revisionId]);

    enc_execute($conn, "
        UPDATE tb_enc_guias
           SET clm_enc_idusuario_actualiza = ?
         WHERE clm_enc_id = ?
    ", 'ii', [$userId, (int)$review['clm_encrev_idguia']]);

    $conn->commit();

    enc_json(true, 'Encomienda manual agregada correctamente.', [
        'redirect' => 'rezagados.php?estado=' . rawurlencode($estado) . '&buscar=' . rawurlencode((string)$review['clm_enc_guia']),
    ]);
} catch (Throwable $e) {
    @$conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}
