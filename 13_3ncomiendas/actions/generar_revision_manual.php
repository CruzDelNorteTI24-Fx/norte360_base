<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-docs');
enc_verify_action_csrf();

if (!enc_schema_has_guias_norte($conn) || !enc_schema_has_manifest_review_pages($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de revisiones de manifiestos por hoja.', [], 409);
}

function enc_manual_manifest_text($value, int $limit = 500): string {
    $value = enc_pdf_clean_token((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
    return substr($value, 0, $limit);
}

function enc_manual_manifest_pdf_literal(string $value): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function enc_manual_manifest_pdf_blob(array $lines): string {
    $safeLines = [];
    foreach ($lines as $line) {
        $line = enc_manual_manifest_text($line, 180);
        if ($line !== '') {
            $safeLines[] = $line;
        }
    }
    if (!$safeLines) {
        $safeLines[] = 'Manifiesto de Encomiendas';
    }

    $stream = "BT\n/F1 11 Tf\n72 780 Td\n";
    foreach ($safeLines as $idx => $line) {
        if ($idx > 0) {
            $stream .= "0 -16 Td\n";
        }
        $stream .= '(' . enc_manual_manifest_pdf_literal($line) . ") Tj\n";
    }
    $stream .= "ET";

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

function enc_manual_manifest_slug(string $value): string {
    $plain = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if ($plain === false || $plain === '') {
        $plain = $value;
    }
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $plain) ?? '';
    $slug = trim($slug, '_-');
    return $slug !== '' ? substr($slug, 0, 60) : 'manual';
}

$guideId = max(0, (int)($_POST['id'] ?? 0));
$pointId = max(0, (int)($_POST['idpunto'] ?? 0));
$userId = enc_user_id();

if ($guideId <= 0 || $pointId <= 0) {
    enc_json(false, 'No se pudo identificar la ruta para generar la revision manual.', [], 422);
}
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}

try {
    $guia = enc_fetch_guia($conn, $guideId);
    if (!$guia) {
        enc_json(false, 'La Control Encomienda no existe.', [], 404);
    }
    if ((int)$guia['clm_enc_activo'] === 0) {
        enc_json(false, 'La Control Encomienda esta anulada.', [], 409);
    }

    $point = enc_fetch_one($conn, "
        SELECT p.clm_encpunto_id,
               p.clm_encpunto_orden,
               p.clm_encpunto_tipo,
               s.clm_sedes_name AS sede_nombre
        FROM tb_enc_guia_puntos p
        INNER JOIN tb_sedes s ON s.clm_sedes_id = p.clm_encpunto_idsede
        WHERE p.clm_encpunto_id = ?
          AND p.clm_encpunto_idguia = ?
          AND p.clm_encpunto_activo = 1
        LIMIT 1
    ", 'ii', [$pointId, $guideId]);
    if (!$point) {
        enc_json(false, 'El punto de ruta no pertenece a esta Control Encomienda.', [], 422);
    }

    $existing = enc_fetch_one($conn, "
        SELECT clm_encdoc_id
        FROM tb_enc_documentos
        WHERE clm_encdoc_idguia = ?
          AND clm_encdoc_idpunto = ?
          AND clm_encdoc_tipo = 'MANIFIESTO_ENCOMIENDAS'
          AND clm_encdoc_estado = 1
        LIMIT 1
    ", 'ii', [$guideId, $pointId]);
    if ($existing) {
        $documentId = (int)$existing['clm_encdoc_id'];
        enc_json(true, 'La ruta ya tiene una revision disponible.', [
            'id' => $guideId,
            'redirect' => 'revision_manifiesto.php?documento=' . $documentId,
        ]);
    }

    $guideCode = (string)($guia['clm_enc_guia'] ?? ('CE-' . str_pad((string)$guideId, 6, '0', STR_PAD_LEFT)));
    $pointOrder = max(1, (int)($point['clm_encpunto_orden'] ?? 1));
    $pointName = enc_manual_manifest_text($point['sede_nombre'] ?? 'Ruta', 160);
    $unit = trim((string)($guia['placa_bus'] ?? '')) ?: 'Sin unidad';
    $plate = trim((string)($guia['placa_placa'] ?? ''));
    $dateTime = trim((string)($guia['clm_enc_fecha_guia'] ?? date('Y-m-d'))) . ' ' . (trim((string)($guia['clm_enc_hora_embarque_programada'] ?? '')) ?: '00:00:00');
    $code = substr('MANUAL-' . $guideCode . '-P' . str_pad((string)$pointOrder, 2, '0', STR_PAD_LEFT), 0, 80);
    $obs = '[REVISION_MANUAL] Revision generada sin PDF externo para ' . $pointName . '.';
    $fileName = 'revision_manual_' . enc_manual_manifest_slug($guideCode) . '_p' . str_pad((string)$pointOrder, 2, '0', STR_PAD_LEFT) . '_' . date('Ymd_His') . '.pdf';
    $pdf = enc_manual_manifest_pdf_blob([
        'Manifiesto de Encomiendas',
        'Origen',
        (string)($guia['sede_embarque'] ?? ''),
        'Destino',
        (string)($guia['sede_desembarque'] ?? ''),
        'Ciudad - Oficina de Destino',
        $pointName,
        'Bus Nro',
        $unit,
        'Placa',
        $plate,
        'Fecha de viaje',
        $dateTime,
        'Documento interno para revision manual sin archivo externo',
    ]);
    $mime = 'application/pdf';
    $size = strlen($pdf);
    $sha = hash('sha256', $pdf);

    $conn->begin_transaction();

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
    ", 'iisssissi', [$guideId, $pointId, $obs, $fileName, $mime, $size, $sha, $pdf, $userId]);

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
            clm_encrev_bus,
            clm_encrev_placa,
            clm_encrev_fecha_viaje,
            clm_encrev_total_items,
            clm_encrev_total_rezagados,
            clm_encrev_estado,
            clm_encrev_observacion,
            clm_encrev_idusuario_crea
        ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, 0, 0, 'GENERADO', ?, ?)
    ", 'iiisssssssi', [
        $documentId,
        $guideId,
        $pointId,
        $code,
        enc_manual_manifest_text($guia['sede_embarque'] ?? '', 160),
        enc_manual_manifest_text($guia['sede_desembarque'] ?? '', 160),
        enc_manual_manifest_text($unit, 80),
        enc_manual_manifest_text($plate, 80),
        $dateTime,
        $obs,
        $userId,
    ]);

    enc_execute($conn, "
        UPDATE tb_enc_guia_puntos
           SET clm_encpunto_estado = 'INCOMPLETO',
               clm_encpunto_fecha_evento = COALESCE(clm_encpunto_fecha_evento, NOW()),
               clm_encpunto_idusuario_evento = COALESCE(clm_encpunto_idusuario_evento, ?)
         WHERE clm_encpunto_id = ?
           AND clm_encpunto_idguia = ?
           AND clm_encpunto_activo = 1
    ", 'iii', [$userId, $pointId, $guideId]);

    enc_execute($conn, "UPDATE tb_enc_guias SET clm_enc_idusuario_actualiza = ? WHERE clm_enc_id = ?", 'ii', [$userId, $guideId]);

    $conn->commit();

    enc_json(true, 'Revision manual generada correctamente.', [
        'id' => $guideId,
        'redirect' => 'revision_manifiesto.php?documento=' . $documentId . '&manual=1',
    ]);
} catch (Throwable $e) {
    @$conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}
