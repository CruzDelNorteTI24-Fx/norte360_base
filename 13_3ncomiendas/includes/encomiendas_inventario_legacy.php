<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');
require_once __DIR__.'/encomiendas_inventario.php';

function enc_inventory_legacy_map(mysqli $conn,int $documentId,bool $lock=false): array {
    if (!enc_inventory_ready($conn)) return [];
    $rows=enc_fetch_all($conn,'SELECT * FROM view_enc_inventario WHERE legacy_documento_id=? ORDER BY id'.($lock?' FOR UPDATE':''),'i',[$documentId]);
    $map=[];
    foreach ($rows as $row) $map[(int)$row['id']]=$row;
    return $map;
}

function enc_inventory_legacy_fingerprint(array $map,string $pdfHash=''): string {
    $versions=[];
    foreach ($map as $row) $versions[(int)$row['id']]=[(int)$row['version'],enc_kupos_hash(enc_inventory_snapshot($row))];
    ksort($versions,SORT_NUMERIC);
    return enc_kupos_hash(['pdf'=>$pdfHash,'items'=>$versions]);
}

function enc_inventory_lock_legacy(mysqli $conn,int $documentId,array $sheets,string $fingerprint,string $expectedPdfHash=''): array {
    if (!enc_inventory_ready($conn)) throw new InvalidArgumentException('Instala la actualización de inventario antes de guardar revisiones.');
    $doc=enc_fetch_one($conn,'SELECT clm_encdoc_sha256 FROM tb_enc_documentos WHERE clm_encdoc_id=? FOR UPDATE','i',[$documentId]);
    if (!$doc) throw new InvalidArgumentException('El documento ya no está disponible.');
    if ($expectedPdfHash!=='' && !hash_equals((string)$doc['clm_encdoc_sha256'],$expectedPdfHash)) {
        throw new InvalidArgumentException('El PDF cambió mientras se validaba. Recarga la revisión.');
    }
    $map=enc_inventory_legacy_map($conn,$documentId,true);
    if (!hash_equals(enc_inventory_legacy_fingerprint($map,(string)$doc['clm_encdoc_sha256']),$fingerprint)) {
        throw new InvalidArgumentException('El documento o su inventario cambió. Recarga la revisión antes de guardar.');
    }
    $seen=[];
    foreach ($sheets as $sheet) {
        if ($sheet['orden_hoja']>65535 || count($sheet['items'])>65535) throw new InvalidArgumentException('La hoja excede el tamaño permitido.');
        foreach ($sheet['items'] as $item) {
            $id=(int)($item['inventory_id']??0);
            if (!$id) continue;
            if (!isset($map[$id]) || isset($seen[$id]) || (int)$map[$id]['version']!==(int)($item['inventory_version']??0)) {
                throw new InvalidArgumentException('La identidad de una encomienda no coincide con esta revisión. Recarga la página.');
            }
            $seen[$id]=true;
        }
    }
    return $map;
}

// Libera el orden de cada hoja dentro de la transaccion sin borrar identidades.
// Se conserva el documento y el contexto de los items que terminen archivados.
function enc_inventory_prepare_legacy(mysqli $conn,int $documentId): void {
    enc_execute($conn,"UPDATE tb_enc_manifiesto_revision_items i
        JOIN tb_enc_manifiesto_revisiones r ON r.clm_encrev_id=i.clm_encrevitem_idrevision
        JOIN tb_enc_documentos d ON d.clm_encdoc_id=r.clm_encrev_iddocumento
        JOIN tb_enc_guias g ON g.clm_enc_id=d.clm_encdoc_idguia
        LEFT JOIN tb_enc_guia_puntos p ON p.clm_encpunto_id=r.clm_encrev_idpunto
        LEFT JOIN tb_sedes so ON so.clm_sedes_id=g.clm_enc_idsede_embarque
        LEFT JOIN tb_sedes sd ON sd.clm_sedes_id=g.clm_enc_idsede_desembarque
        LEFT JOIN tb_sedes sp ON sp.clm_sedes_id=p.clm_encpunto_idsede
        SET i.clm_encrevitem_iddocumento=d.clm_encdoc_id,
            i.clm_encrevitem_hoja_origen=r.clm_encrev_orden_hoja,
            i.clm_encrevitem_fecha=COALESCE(i.clm_encrevitem_fecha,DATE(r.clm_encrev_fecha_viaje),g.clm_enc_fecha_guia),
            i.clm_encrevitem_origen=COALESCE(i.clm_encrevitem_origen,NULLIF(r.clm_encrev_origen,''),so.clm_sedes_name),
            i.clm_encrevitem_destino=COALESCE(i.clm_encrevitem_destino,NULLIF(r.clm_encrev_destino,''),sd.clm_sedes_name),
            i.clm_encrevitem_oficina_destino=COALESCE(i.clm_encrevitem_oficina_destino,sp.clm_sedes_name,sd.clm_sedes_name),
            i.clm_encrevitem_idrevision=NULL
        WHERE r.clm_encrev_iddocumento=?",'i',[$documentId])->close();
}

function enc_inventory_document_origin(array $doc,bool $manual=false): string {
    $name=strtolower((string)($doc['clm_encdoc_nombre']??''));
    return $manual || strpos($name,'revision_manual_')===0 || strpos($name,'rezagados_manual_')===0
        || stripos((string)($doc['clm_encdoc_observacion']??''),'[REVISION_MANUAL]')!==false ? 'MANUAL' : 'QUATROBUS';
}

function enc_inventory_store_legacy_item(mysqli $conn,array $doc,int $reviewId,int $sheetOrder,array $item,array $locked,int $userId): int {
    $id=(int)($item['inventory_id']??0);
    $before=$id?($locked[$id]??null):null;
    if ($id && !$before) throw new InvalidArgumentException('La encomienda no pertenece a este documento.');
    $values=['idrevision'=>$reviewId,'orden'=>$item['orden'],'documento_origen_id'=>(int)$doc['clm_encdoc_id'],
        'hoja_origen'=>$sheetOrder,'doc_fiscal'=>$item['documento'],'destinatario'=>$item['consignado'],
        'referencia_envio'=>$item['referencia_envio'],'peso'=>enc_inventory_decimal($item['peso']),
        'tipo_pago'=>$item['tipo_pago'],'importe_cobrado'=>enc_inventory_decimal($item['importe_cobrado']),
        'guia_remision'=>$item['guia_remision'],'revision_estado'=>$item['estado'],
        'revision_observacion'=>$item['observacion'],'activo'=>1,
        'tipo_origen'=>$before?$before['tipo_origen']:enc_inventory_document_origin($doc,!empty($item['manual'])),
        'fecha'=>null,'origen'=>null,'destino'=>null,'oficina_destino'=>null];
    $now=enc_inventory_now();
    if ($before) enc_inventory_update($conn,$id,$values,false);
    else {
        enc_inventory_insert_many($conn,'tb_enc_manifiesto_revision_items',[$values+
            ['creado_por'=>$userId,'actualizado_por'=>$userId,'creado_en'=>$now,'actualizado_en'=>$now]]);
        $id=(int)$conn->insert_id;
    }
    $after=enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=?','i',[$id]);
    if (!$after) throw new RuntimeException('No se pudo consultar la encomienda guardada.');
    $beforeSnapshot=$before?enc_inventory_snapshot($before):null;
    $afterSnapshot=enc_inventory_snapshot($after);
    if (!$before || enc_inventory_changes($beforeSnapshot,$afterSnapshot)) {
        if ($before) enc_inventory_update($conn,$id,['actualizado_por'=>$userId,'actualizado_en'=>$now]);
        enc_inventory_event($conn,$id,$before?'CAMBIO':'ALTA',$beforeSnapshot,$afterSnapshot,$userId);
    }
    return $id;
}

function enc_inventory_finish_legacy(mysqli $conn,int $documentId,array $locked,array $links,int $userId): void {
    $kept=[];
    foreach ($links as $link) $kept[(int)$link['inventory_id']]=true;
    foreach ($locked as $id=>$before) {
        if (isset($kept[$id]) || !(int)$before['item_activo']) continue;
        enc_inventory_update($conn,(int)$id,['activo'=>0,'idrevision'=>null,'actualizado_por'=>$userId,'actualizado_en'=>enc_inventory_now()]);
        $after=enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=?','i',[$id]);
        enc_inventory_event($conn,(int)$id,'ARCHIVADO',enc_inventory_snapshot($before),enc_inventory_snapshot($after),$userId);
    }
}

function enc_inventory_add_manual(mysqli $conn,int $documentId,int $itemId,int $userId): void {
    if (!enc_inventory_ready($conn)) return;
    enc_execute($conn,"UPDATE tb_enc_manifiesto_revision_items i
        JOIN tb_enc_manifiesto_revisiones r ON r.clm_encrev_id=i.clm_encrevitem_idrevision
        SET i.clm_encrevitem_iddocumento=r.clm_encrev_iddocumento,
            i.clm_encrevitem_hoja_origen=r.clm_encrev_orden_hoja,
            i.clm_encrevitem_origen_registro='MANUAL',
            i.clm_encrevitem_creado_por=?,i.clm_encrevitem_actualizado_por=?,
            i.clm_encrevitem_creado_en=?,i.clm_encrevitem_actualizado_en=?
        WHERE i.clm_encrevitem_id=? AND r.clm_encrev_iddocumento=?",
        'iissii',[$userId,$userId,enc_inventory_now(),enc_inventory_now(),$itemId,$documentId])->close();
    $row=enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=?','i',[$itemId]);
    if (!$row) throw new RuntimeException('No se pudo consultar la encomienda manual.');
    enc_inventory_event($conn,$itemId,'ALTA',null,enc_inventory_snapshot($row),$userId);
}

// Sustituir un PDF invalida su revision, pero no elimina revisiones ni items.
function enc_inventory_invalidate_pdf(mysqli $conn,int $documentId,int $userId,string $oldHash,string $newHash): void {
    if (hash_equals($oldHash,$newHash)) return;
    $rows=enc_inventory_legacy_map($conn,$documentId,true);
    foreach ($rows as $id=>$before) {
        if (!(int)$before['item_activo']) continue;
        enc_inventory_update($conn,(int)$id,['revision_estado'=>'PENDIENTE','actualizado_por'=>$userId,'actualizado_en'=>enc_inventory_now()]);
        $after=enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=?','i',[$id]);
        $a=enc_inventory_snapshot($before);$a['documento_sha256']=$oldHash;
        $b=enc_inventory_snapshot($after);$b['documento_sha256']=$newHash;
        enc_inventory_event($conn,(int)$id,'PDF_REEMPLAZADO',$a,$b,$userId);
    }
    enc_execute($conn,"UPDATE tb_enc_manifiesto_revisiones SET clm_encrev_estado='GENERADO',
        clm_encrev_idusuario_actualiza=?,clm_encrev_datetimeupdated=? WHERE clm_encrev_iddocumento=?",
        'isi',[$userId,enc_inventory_now(),$documentId])->close();
}
