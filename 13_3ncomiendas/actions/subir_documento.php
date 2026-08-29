<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_validations.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-docs');
enc_verify_action_csrf();

if (!enc_schema_has_guias_norte($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de Control Encomiendas antes de cargar documentos.', [], 409);
}

$id = max(0, (int)($_POST['id'] ?? 0));
$tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
$docId = max(0, (int)($_POST['documento_id'] ?? 0));
$pointId = enc_id_or_null($_POST['idpunto'] ?? null);
$tipoComprobante = strtoupper(trim((string)($_POST['tipo_comprobante'] ?? '')));
$numeroComprobante = enc_nullable_string($_POST['numero_comprobante'] ?? '');
$fechaComprobante = enc_nullable_date($_POST['fecha_comprobante'] ?? '');
$docObs = enc_nullable_string($_POST['doc_observacion'] ?? '');
$userId = enc_user_id();

if ($id <= 0 || !enc_validate_doc_type($tipo)) {
    enc_json(false, 'Datos del documento invalidos.', [], 422);
}
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}
if ($tipo === 'MANIFIESTO_ENCOMIENDAS' && !$pointId) {
    enc_json(false, 'Selecciona el punto de ruta al que pertenece el manifiesto.', [], 422);
}
if ($tipo === 'GUIA_TRANSPORTISTA') {
    if (!enc_validate_doc_comprobante_type($tipoComprobante)) {
        enc_json(false, 'Tipo de comprobante del documento no valido.', [], 422);
    }
    if ($tipoComprobante === '') {
        $tipoComprobante = null;
    } elseif ($tipoComprobante === 'SIN_COMPROBANTE') {
        $numeroComprobante = null;
        $fechaComprobante = null;
    }
} else {
    $tipoComprobante = null;
    $numeroComprobante = null;
    $fechaComprobante = null;
}

if (!isset($_FILES['documento']) || !is_array($_FILES['documento'])) {
    enc_json(false, 'Selecciona un PDF para cargar.', [], 422);
}

[$valid, $error, $pdf] = enc_validate_pdf_upload($_FILES['documento']);
if (!$valid || !$pdf) {
    enc_json(false, $error, [], 422);
}
if ($tipo === 'MANIFIESTO_ENCOMIENDAS' && !enc_pdf_contains_manifest_title($pdf['content'])) {
    enc_json(false, 'El PDF no parece ser un manifiesto de encomiendas. Debe contener "Manifiesto de Encomiendas".', [], 422);
}

try {
    $guia = enc_fetch_guia($conn, $id);
    if (!$guia) enc_json(false, 'La Control Encomienda no existe.', [], 404);
    if ((int)$guia['clm_enc_activo'] === 0) enc_json(false, 'La Control Encomienda esta anulada.', [], 409);

    if ($tipo === 'MANIFIESTO_ENCOMIENDAS') {
        $point = enc_fetch_one($conn, "
            SELECT clm_encpunto_id
            FROM tb_enc_guia_puntos
            WHERE clm_encpunto_id = ?
              AND clm_encpunto_idguia = ?
              AND clm_encpunto_activo = 1
            LIMIT 1
        ", 'ii', [$pointId, $id]);
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
        ", 'ii', [$id, $pointId]);
    } else {
        $pointId = null;
        $existing = null;
        if ($docId > 0) {
            $existing = enc_fetch_one($conn, "
                SELECT clm_encdoc_id
                FROM tb_enc_documentos
                WHERE clm_encdoc_id = ?
                  AND clm_encdoc_idguia = ?
                  AND clm_encdoc_tipo = 'GUIA_TRANSPORTISTA'
                  AND clm_encdoc_estado = 1
                LIMIT 1
            ", 'ii', [$docId, $id]);
            if (!$existing) {
                enc_json(false, 'El documento a reemplazar no pertenece a esta Control Encomienda.', [], 422);
            }
        }
    }

    $conn->begin_transaction();
    $storedDocId = 0;
    if ($existing) {
        $storedDocId = (int)$existing['clm_encdoc_id'];
        enc_execute($conn, "
            UPDATE tb_enc_documentos
               SET clm_encdoc_nombre = ?,
                   clm_encdoc_mime = ?,
                   clm_encdoc_size = ?,
                   clm_encdoc_sha256 = ?,
                   clm_encdoc_archivo = ?,
                   clm_encdoc_tipo_comprobante = ?,
                   clm_encdoc_numero_comprobante = ?,
                   clm_encdoc_fecha_comprobante = ?,
                   clm_encdoc_observacion = ?,
                   clm_encdoc_idusuario_actualiza = ?,
                   clm_encdoc_estado = 1
             WHERE clm_encdoc_id = ?
        ", 'ssissssssii', [
            $pdf['name'],
            $pdf['mime'],
            $pdf['size'],
            $pdf['sha256'],
            $pdf['content'],
            $tipoComprobante,
            $numeroComprobante,
            $fechaComprobante,
            $docObs,
            $userId,
            $storedDocId,
        ]);
    } else {
        enc_execute($conn, "
            INSERT INTO tb_enc_documentos (
                clm_encdoc_idguia,
                clm_encdoc_idpunto,
                clm_encdoc_tipo,
                clm_encdoc_tipo_comprobante,
                clm_encdoc_numero_comprobante,
                clm_encdoc_fecha_comprobante,
                clm_encdoc_observacion,
                clm_encdoc_nombre,
                clm_encdoc_mime,
                clm_encdoc_size,
                clm_encdoc_sha256,
                clm_encdoc_archivo,
                clm_encdoc_idusuario_carga,
                clm_encdoc_fechacarga,
                clm_encdoc_estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
        ", 'iisssssssissi', [
            $id,
            $pointId,
            $tipo,
            $tipoComprobante,
            $numeroComprobante,
            $fechaComprobante,
            $docObs,
            $pdf['name'],
            $pdf['mime'],
            $pdf['size'],
            $pdf['sha256'],
            $pdf['content'],
            $userId,
        ]);
        $storedDocId = (int)$conn->insert_id;
    }

    if ($existing && $tipo === 'MANIFIESTO_ENCOMIENDAS' && $storedDocId > 0 && enc_schema_has_manifest_reviews($conn)) {
        enc_execute($conn, "DELETE FROM tb_enc_manifiesto_revisiones WHERE clm_encrev_iddocumento = ?", 'i', [$storedDocId]);
    }

    if ($tipo === 'MANIFIESTO_ENCOMIENDAS' && $pointId) {
        enc_execute($conn, "
            UPDATE tb_enc_guia_puntos
               SET clm_encpunto_estado = 'RECIBIDO',
                   clm_encpunto_fecha_evento = NOW(),
                   clm_encpunto_idusuario_evento = ?
             WHERE clm_encpunto_id = ?
               AND clm_encpunto_idguia = ?
        ", 'iii', [$userId, $pointId, $id]);
    }

    enc_execute($conn, "UPDATE tb_enc_guias SET clm_enc_idusuario_actualiza = ? WHERE clm_enc_id = ?", 'ii', [$userId, $id]);
    $conn->commit();

    enc_json(true, $tipo === 'MANIFIESTO_ENCOMIENDAS' ? 'Manifiesto cargado correctamente.' : 'Guia de transportista cargada correctamente.', ['id' => $id]);
} catch (Throwable $e) {
    $conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}
