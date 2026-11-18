<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_queries.php';
require_once __DIR__ . '/../includes/encomiendas_inventario_legacy.php';

$conn = enc_start_action('enc-tracking');
enc_verify_action_csrf();

if (!enc_schema_has_manifest_review_pages($conn)) {
    enc_json(false, 'Falta ejecutar la migracion SQL de revisiones de manifiestos por hoja.', [], 409);
}
if (!enc_inventory_ready($conn)) {
    enc_json(false,'Instala la actualización de inventario antes de guardar revisiones. Los registros anteriores se conservan.',[],409);
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
        'inventory_id' => max(0,(int)($raw['inventory_id'] ?? 0)),
        'inventory_version' => max(0,(int)($raw['inventory_version'] ?? 0)),
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

function enc_manifest_review_doc_is_manual(array $doc): bool {
    $name = strtolower((string)($doc['clm_encdoc_nombre'] ?? ''));
    $obs = strtoupper((string)($doc['clm_encdoc_observacion'] ?? ''));
    return strpos($name, 'revision_manual_') === 0
        || strpos($name, 'rezagados_manual_') === 0
        || strpos($obs, '[REVISION_MANUAL]') !== false;
}

function enc_manifest_review_item_is_manual_placeholder(array $item): bool {
    $documento = strtoupper(trim((string)($item['documento'] ?? '')));
    $consignado = strtoupper(trim((string)($item['consignado'] ?? '')));
    $referencia = strtoupper(trim((string)($item['referencia_envio'] ?? '')));

    return strpos($documento, 'MANUAL-') === 0
        && strpos($consignado, 'REVISION MANUAL') !== false
        && strpos($referencia, 'ORIGEN') !== false
        && strpos($referencia, 'DESTINO') !== false;
}

$documentId = max(0, (int)($_POST['documento_id'] ?? 0));
$postedSheets = $_POST['sheets'] ?? [];
if (isset($_POST['sheets_json'])) {
    $json = is_string($_POST['sheets_json']) ? $_POST['sheets_json'] : '';
    if (strlen($json)>5242880) enc_json(false,'La revisión excede el tamaño permitido.',[],422);
    $postedSheets = json_decode($json,true);
    if (!is_array($postedSheets)) enc_json(false,'El contenido de la revisión no es válido.',[],422);
}
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
    $manualDocument = enc_manifest_review_doc_is_manual($doc);
    $expectedDetailsBySheet = [];
    $parsedItemsBySheet = [];
    if (!$manualDocument) {
        foreach (($parsedManifest['pages'] ?? []) as $idx => $page) {
            $order = (int)($page['orden_hoja'] ?? $idx + 1);
            if (($page['detalles_pdf'] ?? null) !== null) {
                $expectedDetailsBySheet[$order] = (int)$page['detalles_pdf'];
            }
            $parsedItemsBySheet[$order] = count($page['items'] ?? []);
        }
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
                if ($manualDocument && enc_manifest_review_item_is_manual_placeholder($item)) {
                    continue;
                }
                $items[] = $item;
            }
        }
        $expectedDetails = $expectedDetailsBySheet[$sheetOrder] ?? null;
        $allowEmptyPdfSheet = !$manualDocument && $expectedDetails !== null && (int)$expectedDetails === 0;
        if (!$items && !$allowEmptyPdfSheet) {
            continue;
        }
        $pdfItemsCount = 0;
        foreach ($items as $item) {
            if (!$item['manual']) {
                $pdfItemsCount++;
            }
        }
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
    $inventoryLocked = enc_inventory_lock_legacy($conn,$documentId,$sheets,(string)($_POST['inventory_fingerprint'] ?? ''),(string)$doc['clm_encdoc_sha256']);
    $inventoryLinks = [];
    enc_inventory_prepare_legacy($conn,$documentId);

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
                clm_encrev_idusuario_crea,
                clm_encrev_pdf_sha256
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                clm_encrev_id=LAST_INSERT_ID(clm_encrev_id),
                clm_encrev_idguia=VALUES(clm_encrev_idguia),clm_encrev_idpunto=VALUES(clm_encrev_idpunto),
                clm_encrev_codigo_manifiesto=VALUES(clm_encrev_codigo_manifiesto),
                clm_encrev_origen=VALUES(clm_encrev_origen),clm_encrev_destino=VALUES(clm_encrev_destino),
                clm_encrev_bus=VALUES(clm_encrev_bus),clm_encrev_placa=VALUES(clm_encrev_placa),
                clm_encrev_fecha_viaje=VALUES(clm_encrev_fecha_viaje),
                clm_encrev_total_items=VALUES(clm_encrev_total_items),clm_encrev_total_rezagados=VALUES(clm_encrev_total_rezagados),
                clm_encrev_estado=VALUES(clm_encrev_estado),clm_encrev_observacion=VALUES(clm_encrev_observacion),
                clm_encrev_idusuario_actualiza=VALUES(clm_encrev_idusuario_crea),clm_encrev_datetimeupdated=NOW(),
                clm_encrev_pdf_sha256=VALUES(clm_encrev_pdf_sha256),clm_encrev_activo=1
        ", 'iiii' . 'ssssss' . 'ii' . 'ss' . 'is', [
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
            $doc['clm_encdoc_sha256'],
        ]);
        $reviewId = (int)$conn->insert_id;
        $reviewIds[] = $reviewId;
        if ($sheet['idpunto']) {
            $pointIdsToMark[(int)$sheet['idpunto']] = true;
        }

        foreach ($sheet['items'] as $item) {
            $savedItemId=enc_inventory_store_legacy_item($conn,$doc,$reviewId,$sheet['orden_hoja'],$item,$inventoryLocked,$userId);
            $inventoryLinks[]=['inventory_id'=>$savedItemId];
        }
    }

    // Las hojas retiradas se conservan inactivas; sus items ya no se borran por cascada.
    $reviewParams=array_merge([$userId,$documentId],$reviewIds);
    enc_execute($conn,'UPDATE tb_enc_manifiesto_revisiones SET clm_encrev_activo=0,
        clm_encrev_idusuario_actualiza=?,clm_encrev_datetimeupdated=NOW()
        WHERE clm_encrev_iddocumento=? AND clm_encrev_id NOT IN ('.implode(',',array_fill(0,count($reviewIds),'?')).')',
        str_repeat('i',count($reviewParams)),$reviewParams)->close();

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
    enc_inventory_finish_legacy($conn,$documentId,$inventoryLocked,$inventoryLinks,$userId);
    $conn->commit();

    enc_json(true, 'Revision de manifiesto guardada correctamente.', [
        'ids' => $reviewIds,
        'redirect' => 'revision_manifiesto.php?documento=' . $documentId . '&guardado=1',
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    enc_log($e);
    if ($e instanceof InvalidArgumentException) enc_json(false,$e->getMessage(),[],409);
    enc_json(false, enc_db_message($e), [], 500);
}
