<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-tracking');
enc_verify_action_csrf();

if (!enc_schema_has_guias_norte($conn) || !enc_schema_has_manifest_review_pages($conn)) {
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

function enc_rezagado_manual_pdf_blob(): string {
    $stream = 'BT /F1 12 Tf 72 760 Td (Manifiesto de Encomiendas - Registro manual de rezagados) Tj ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $idx => $object) {
        $number = $idx + 1;
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($idx = 1; $idx <= count($objects); $idx++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$idx]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF";

    return $pdf;
}

function enc_rezagado_manual_fetch_review(mysqli $conn, int $revisionId): ?array {
    if ($revisionId <= 0) {
        return null;
    }

    return enc_fetch_one($conn, "
        SELECT r.clm_encrev_id,
               r.clm_encrev_iddocumento,
               r.clm_encrev_idguia,
               g.clm_enc_guia
        FROM tb_enc_manifiesto_revisiones r
        INNER JOIN tb_enc_documentos d
                ON d.clm_encdoc_id = r.clm_encrev_iddocumento
               AND d.clm_encdoc_estado = 1
        INNER JOIN tb_enc_guias g
                ON g.clm_enc_id = r.clm_encrev_idguia
        WHERE r.clm_encrev_id = ?
          AND r.clm_encrev_activo = 1
        LIMIT 1
    ", 'i', [$revisionId]);
}

function enc_rezagado_manual_ensure_review(mysqli $conn, int $userId): array {
    $code = 'MANUAL-' . date('Ymd');
    $docName = 'rezagados_manual_' . date('Ymd') . '.pdf';

    $review = enc_fetch_one($conn, "
        SELECT r.clm_encrev_id,
               r.clm_encrev_iddocumento,
               r.clm_encrev_idguia,
               g.clm_enc_guia
        FROM tb_enc_manifiesto_revisiones r
        INNER JOIN tb_enc_documentos d
                ON d.clm_encdoc_id = r.clm_encrev_iddocumento
               AND d.clm_encdoc_nombre = ?
               AND d.clm_encdoc_estado = 1
        INNER JOIN tb_enc_guias g
                ON g.clm_enc_id = r.clm_encrev_idguia
        WHERE r.clm_encrev_codigo_manifiesto = ?
          AND r.clm_encrev_activo = 1
        ORDER BY r.clm_encrev_id DESC
        LIMIT 1
    ", 'ss', [$docName, $code]);

    if ($review) {
        return $review;
    }

    $sedes = enc_fetch_all($conn, "
        SELECT clm_sedes_id
        FROM tb_sedes
        ORDER BY clm_sedes_id ASC
        LIMIT 2
    ");
    if (count($sedes) < 2) {
        throw new InvalidArgumentException('No hay sedes suficientes para crear el registro manual.');
    }

    $origen = (int)$sedes[0]['clm_sedes_id'];
    $destino = (int)$sedes[1]['clm_sedes_id'];
    $obs = 'Registro automatico para rezagados manuales ' . date('Y-m-d');

    enc_execute($conn, "
        INSERT INTO tb_enc_guias (
            clm_enc_guia,
            clm_enc_serie,
            clm_enc_fecha_guia,
            clm_enc_idusuario_registra,
            clm_enc_fechacreated,
            clm_enc_idsede_embarque,
            clm_enc_idsede_desembarque,
            clm_enc_estado_embarque,
            clm_enc_estado_desembarque,
            clm_enc_observacion,
            clm_enc_activo
        ) VALUES ('', 'CE', ?, ?, NOW(), ?, ?, 'PENDIENTE', 'PENDIENTE', ?, 1)
    ", 'siiis', [date('Y-m-d'), $userId, $origen, $destino, $obs]);

    $guideId = (int)$conn->insert_id;
    $guide = enc_fetch_one($conn, "
        SELECT clm_enc_guia
        FROM tb_enc_guias
        WHERE clm_enc_id = ?
        LIMIT 1
    ", 'i', [$guideId]);
    $guideCode = (string)($guide['clm_enc_guia'] ?? ('CE-' . str_pad((string)$guideId, 6, '0', STR_PAD_LEFT)));

    enc_execute($conn, "
        INSERT INTO tb_enc_guia_puntos (
            clm_encpunto_idguia,
            clm_encpunto_orden,
            clm_encpunto_idsede,
            clm_encpunto_tipo,
            clm_encpunto_manifiesto_obligatorio,
            clm_encpunto_estado,
            clm_encpunto_fecha_evento,
            clm_encpunto_idusuario_evento,
            clm_encpunto_observacion,
            clm_encpunto_activo
        ) VALUES (?, 1, ?, 'ORIGEN', 0, 'RECIBIDO', NOW(), ?, ?, 1)
    ", 'iiis', [$guideId, $origen, $userId, $obs]);

    $pointId = (int)$conn->insert_id;
    $pdf = enc_rezagado_manual_pdf_blob();
    $mime = 'application/pdf';
    $size = strlen($pdf);
    $sha = hash('sha256', $pdf);

    enc_execute($conn, "
        INSERT INTO tb_enc_documentos (
            clm_encdoc_idguia,
            clm_encdoc_idpunto,
            clm_encdoc_tipo,
            clm_encdoc_tipo_comprobante,
            clm_encdoc_observacion,
            clm_encdoc_nombre,
            clm_encdoc_mime,
            clm_encdoc_size,
            clm_encdoc_sha256,
            clm_encdoc_archivo,
            clm_encdoc_idusuario_carga,
            clm_encdoc_fechacarga,
            clm_encdoc_estado
        ) VALUES (?, ?, 'MANIFIESTO_ENCOMIENDAS', 'SIN_COMPROBANTE', ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
    ", 'iisssissi', [$guideId, $pointId, $obs, $docName, $mime, $size, $sha, $pdf, $userId]);

    $documentId = (int)$conn->insert_id;
    enc_execute($conn, "
        INSERT INTO tb_enc_manifiesto_revisiones (
            clm_encrev_iddocumento,
            clm_encrev_idguia,
            clm_encrev_idpunto,
            clm_encrev_orden_hoja,
            clm_encrev_codigo_manifiesto,
            clm_encrev_origen,
            clm_encrev_destino,
            clm_encrev_fecha_viaje,
            clm_encrev_total_items,
            clm_encrev_total_rezagados,
            clm_encrev_estado,
            clm_encrev_observacion,
            clm_encrev_idusuario_crea
        ) VALUES (?, ?, ?, 1, ?, 'Registro manual', 'Rezagados', NOW(), 0, 0, 'GENERADO', ?, ?)
    ", 'iiissi', [$documentId, $guideId, $pointId, $code, $obs, $userId]);

    return [
        'clm_encrev_id' => (int)$conn->insert_id,
        'clm_encrev_iddocumento' => $documentId,
        'clm_encrev_idguia' => $guideId,
        'clm_enc_guia' => $guideCode,
    ];
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
    $conn->begin_transaction();

    $isAutoManualReview = $revisionId <= 0;
    $review = $revisionId > 0
        ? enc_rezagado_manual_fetch_review($conn, $revisionId)
        : enc_rezagado_manual_ensure_review($conn, $userId);

    if (!$review) {
        throw new InvalidArgumentException('La revision seleccionada ya no esta disponible.');
    }
    $revisionId = (int)$review['clm_encrev_id'];

    $next = enc_fetch_one($conn, "
        SELECT COALESCE(MAX(clm_encrevitem_orden), 0) + 1 AS siguiente
        FROM tb_enc_manifiesto_revision_items
        WHERE clm_encrevitem_idrevision = ?
    ", 'i', [$revisionId]);
    $orden = max(1, (int)($next['siguiente'] ?? 1));

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
        'redirect' => 'rezagados.php?estado=' . rawurlencode($estado) . '&buscar=' . rawurlencode($isAutoManualReview ? 'manual' : (string)$review['clm_enc_guia']),
    ]);
} catch (Throwable $e) {
    @$conn->rollback();
    enc_log($e);
    if ($e instanceof InvalidArgumentException) {
        enc_json(false, $e->getMessage(), [], 422);
    }
    enc_json(false, enc_db_message($e), [], 500);
}
