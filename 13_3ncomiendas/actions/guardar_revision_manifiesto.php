<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_action('enc-tracking');
enc_verify_action_csrf();

if (!enc_schema_has_manifest_review_pages($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de revisiones de manifiestos por hoja.', [], 409);
}

function enc_manifest_review_datetime($value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $formats = ['d/m/Y h:i A', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, strtoupper($value));
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function enc_manifest_review_decimal($value): ?float {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : null;
}

function enc_manifest_review_text($value, int $limit = 500): ?string {
    $value = enc_pdf_clean_token((string)$value);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
    return substr($value, 0, $limit);
}

function enc_manifest_review_normalize_item(array $raw, int $order): ?array {
    $documento = enc_manifest_review_text($raw['documento'] ?? '', 100);
    $consignado = enc_manifest_review_text($raw['consignado'] ?? '', 255);
    $referencia = enc_manifest_review_text($raw['referencia_envio'] ?? '', 3000);
    $guiaRemision = enc_manifest_review_text($raw['guia_remision'] ?? '', 100);
    $tipoPago = enc_manifest_review_text($raw['tipo_pago'] ?? '', 80);
    $manual = filter_var($raw['manual'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $estado = strtoupper(trim((string)($raw['estado'] ?? 'PENDIENTE')));
    if (!in_array($estado, ['PENDIENTE', 'OK', 'REZAGADO', 'OBSERVADO'], true)) {
        $estado = 'PENDIENTE';
    }

    if ($documento === null && $consignado === null && $referencia === null && $guiaRemision === null) {
        return null;
    }

    return [
        'orden' => $order,
        'manual' => $manual,
        'documento' => $documento,
        'consignado' => $consignado,
        'referencia_envio' => $referencia,
        'peso' => enc_manifest_review_decimal($raw['peso'] ?? ''),
        'tipo_pago' => $tipoPago,
        'importe_cobrado' => enc_manifest_review_decimal($raw['importe_cobrado'] ?? ''),
        'guia_remision' => $guiaRemision,
        'estado' => $estado,
        'observacion' => enc_manifest_review_text($raw['observacion'] ?? '', 2000),
    ];
}

$documentId = max(0, (int)($_POST['documento_id'] ?? 0));
$postedSheets = $_POST['sheets'] ?? [];
$legacyItems = $_POST['items'] ?? [];
$userId = enc_user_id();

if ((!is_array($postedSheets) || !$postedSheets) && is_array($legacyItems) && $legacyItems) {
    $postedSheets = [[
        'orden_hoja' => 1,
        'idpunto' => $_POST['idpunto'] ?? null,
        'codigo_manifiesto' => $_POST['codigo_manifiesto'] ?? '',
        'origen' => $_POST['origen'] ?? '',
        'destino' => $_POST['destino'] ?? '',
        'bus' => $_POST['bus'] ?? '',
        'placa' => $_POST['placa'] ?? '',
        'fecha_viaje' => $_POST['fecha_viaje'] ?? '',
        'observacion_revision' => $_POST['observacion_revision'] ?? '',
        'items' => $legacyItems,
    ]];
}

if ($documentId <= 0 || !is_array($postedSheets)) {
    enc_json(false, 'No se pudo identificar el manifiesto a revisar.', [], 422);
}
if ($userId <= 0) {
    enc_json(false, 'No se pudo identificar al usuario de la sesion.', [], 401);
}

try {
    $doc = enc_fetch_manifest_document($conn, $documentId);
    if (!$doc) {
        enc_json(false, 'El manifiesto no existe o ya no esta disponible.', [], 404);
    }
    $parsedManifest = enc_parse_manifest_pdf((string)$doc['clm_encdoc_archivo']);
    if (!$parsedManifest['title_ok']) {
        enc_json(false, 'El PDF guardado no contiene "Manifiesto de Encomiendas".', [], 422);
    }
    $expectedDetailsBySheet = [];
    $parsedItemsBySheet = [];
    foreach (($parsedManifest['pages'] ?? []) as $idx => $page) {
        $order = (int)($page['orden_hoja'] ?? $idx + 1);
        if (($page['detalles_pdf'] ?? null) !== null) {
            $expectedDetailsBySheet[$order] = (int)$page['detalles_pdf'];
        }
        $parsedItemsBySheet[$order] = count($page['items'] ?? []);
    }

    $routePoints = enc_fetch_route_points($conn, (int)$doc['clm_encdoc_idguia']);
    $validPointIds = [];
    foreach ($routePoints as $point) {
        $validPointIds[(int)$point['clm_encpunto_id']] = true;
    }

    $sheets = [];
    foreach ($postedSheets as $sheetIdx => $rawSheet) {
        if (!is_array($rawSheet)) {
            continue;
        }
        $sheetOrder = max(1, (int)($rawSheet['orden_hoja'] ?? $sheetIdx + 1));
        $rawItems = $rawSheet['items'] ?? [];
        if (!is_array($rawItems)) {
            continue;
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $item = enc_manifest_review_normalize_item($rawItem, count($items) + 1);
            if ($item) {
                $items[] = $item;
            }
        }
        if (!$items) {
            continue;
        }
        $pdfItemsCount = 0;
        foreach ($items as $item) {
            if (!$item['manual']) {
                $pdfItemsCount++;
            }
        }
        $expectedDetails = $expectedDetailsBySheet[$sheetOrder] ?? null;
        if ($expectedDetails !== null && count($items) < $expectedDetails) {
            enc_json(false, 'La hoja ' . str_pad((string)$sheetOrder, 2, '0', STR_PAD_LEFT) . ' tiene ' . count($items) . ' items registrados, pero el PDF indica ' . $expectedDetails . ' detalles.', [], 422);
        }
        $parsedItems = $parsedItemsBySheet[$sheetOrder] ?? null;
        if ($parsedItems !== null && $pdfItemsCount !== $parsedItems) {
            enc_json(false, 'La hoja ' . str_pad((string)$sheetOrder, 2, '0', STR_PAD_LEFT) . ' no coincide con la lectura actual del PDF.', [], 422);
        }

        $pointId = enc_id_or_null($rawSheet['idpunto'] ?? null);
        if ($pointId && !isset($validPointIds[$pointId])) {
            $pointId = null;
        }

        $totalRezagados = 0;
        $hasOpenItems = false;
        foreach ($items as $item) {
            if ($item['estado'] === 'REZAGADO') {
                $totalRezagados++;
            }
            if (in_array($item['estado'], ['PENDIENTE', 'OBSERVADO'], true)) {
                $hasOpenItems = true;
            }
        }

        $sheets[] = [
            'orden_hoja' => $sheetOrder,
            'idpunto' => $pointId,
            'codigo_manifiesto' => enc_manifest_review_text($rawSheet['codigo_manifiesto'] ?? '', 80),
            'origen' => enc_manifest_review_text($rawSheet['origen'] ?? '', 160),
            'destino' => enc_manifest_review_text($rawSheet['destino'] ?? '', 160),
            'bus' => enc_manifest_review_text($rawSheet['bus'] ?? '', 80),
            'placa' => enc_manifest_review_text($rawSheet['placa'] ?? '', 80),
            'fecha_viaje' => enc_manifest_review_datetime($rawSheet['fecha_viaje'] ?? ''),
            'total_items' => count($items),
            'total_rezagados' => $totalRezagados,
            'estado' => $hasOpenItems ? 'EN_REVISION' : 'CERRADO',
            'observacion' => enc_manifest_review_text($rawSheet['observacion_revision'] ?? '', 3000),
            'items' => $items,
        ];
    }

    if (!$sheets) {
        enc_json(false, 'No hay items del manifiesto para guardar.', [], 422);
    }

    $conn->begin_transaction();
    enc_execute($conn, "DELETE FROM tb_enc_manifiesto_revisiones WHERE clm_encrev_iddocumento = ?", 'i', [$documentId]);

    $reviewIds = [];
    $pointIdsToMark = [];
    foreach ($sheets as $sheet) {
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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", 'iiii' . 'ssssss' . 'ii' . 'ss' . 'i', [
            $documentId,
            (int)$doc['clm_encdoc_idguia'],
            $sheet['idpunto'],
            $sheet['orden_hoja'],
            $sheet['codigo_manifiesto'],
            $sheet['origen'],
            $sheet['destino'],
            $sheet['bus'],
            $sheet['placa'],
            $sheet['fecha_viaje'],
            $sheet['total_items'],
            $sheet['total_rezagados'],
            $sheet['estado'],
            $sheet['observacion'],
            $userId,
        ]);
        $reviewId = (int)$conn->insert_id;
        $reviewIds[] = $reviewId;
        if ($sheet['idpunto']) {
            $pointIdsToMark[(int)$sheet['idpunto']] = true;
        }

        foreach ($sheet['items'] as $item) {
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
                $reviewId,
                $item['orden'],
                $item['documento'],
                $item['consignado'],
                $item['referencia_envio'],
                $item['peso'],
                $item['tipo_pago'],
                $item['importe_cobrado'],
                $item['guia_remision'],
                $item['estado'],
                $item['observacion'],
            ]);
        }
    }

    foreach (array_keys($pointIdsToMark) as $pointId) {
        enc_execute($conn, "
            UPDATE tb_enc_guia_puntos
               SET clm_encpunto_estado = 'RECIBIDO',
                   clm_encpunto_fecha_evento = COALESCE(clm_encpunto_fecha_evento, NOW()),
                   clm_encpunto_idusuario_evento = COALESCE(clm_encpunto_idusuario_evento, ?)
             WHERE clm_encpunto_id = ?
               AND clm_encpunto_idguia = ?
               AND clm_encpunto_activo = 1
        ", 'iii', [$userId, $pointId, (int)$doc['clm_encdoc_idguia']]);
    }

    enc_execute($conn, "UPDATE tb_enc_guias SET clm_enc_idusuario_actualiza = ? WHERE clm_enc_id = ?", 'ii', [$userId, (int)$doc['clm_encdoc_idguia']]);
    $conn->commit();

    enc_json(true, 'Revision de manifiesto guardada correctamente.', [
        'ids' => $reviewIds,
        'redirect' => 'revision_manifiesto.php?documento=' . $documentId . '&guardado=1',
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    enc_log($e);
    enc_json(false, enc_db_message($e), [], 500);
}
