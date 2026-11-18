<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');
require_once __DIR__ . '/encomiendas_inventario.php';

function enc_inventory_filters(): array {
    $scalar = static function (string $key): string { return is_scalar($_GET[$key] ?? '') ? trim((string)($_GET[$key] ?? '')) : ''; };
    $from=enc_nullable_date($scalar('desde')); $to=enc_nullable_date($scalar('hasta'));
    if ($from && $to && $from>$to) { $swap=$from; $from=$to; $to=$swap; }
    $source=$scalar('origen_registro');
    if (!in_array($source,['KUPOS','QUATROBUS','MANUAL'],true)) $source='';
    $revision=$scalar('revision') ?: $scalar('estado');
    if (!in_array($revision,['PENDIENTE','OK','REZAGADO','OBSERVADO'],true)) $revision='';
    return ['buscar'=>substr($scalar('buscar'),0,180),'origen_registro'=>$source,
        'revision'=>$revision,'seguimiento'=>substr($scalar('seguimiento'),0,80),
        'estatus'=>substr($scalar('estatus'),0,50),'pago'=>substr($scalar('pago'),0,50),
        'sucursal'=>substr($scalar('sucursal'),0,160),'sede'=>max(0,(int)$scalar('sede')),
        'desde'=>$from ?? '','hasta'=>$to ?? '','archivados'=>$scalar('archivados')==='1',
        'pagina'=>max(1,(int)$scalar('pagina')),'lote'=>max(0,(int)$scalar('lote')),
        'por_pagina'=>in_array((int)$scalar('por_pagina'),[25,50,100],true)?(int)$scalar('por_pagina'):25];
}

function enc_inventory_query(mysqli $conn,array $f): array {
    $where=['1=1']; $types=''; $params=[];
    if (!$f['archivados']) $where[]='i.activo=1';
    foreach (['origen_registro'=>'tipo_origen','revision'=>'revision_estado','seguimiento'=>'seguimiento',
        'estatus'=>'estatus','pago'=>'estado_pago','sucursal'=>'sucursal'] as $filter=>$column) {
        if ($f[$filter] !== '') { $where[]='i.'.$column.'=?'; $types.='s'; $params[]=$f[$filter]; }
    }
    foreach (['desde'=>'>=','hasta'=>'<='] as $key=>$op) {
        if ($f[$key] !== '') { $where[]='i.fecha'.$op.'?';$types.='s';$params[]=$f[$key]; }
    }
    if ($f['sede']) {
        $where[]='(ao.sede_id=? OR ad.sede_id=? OR i.sede_origen_id=? OR i.sede_destino_id=?)';
        $types.='iiii'; foreach (range(1,4) as $unused) $params[]=$f['sede'];
    }
    if ($f['lote']) {
        $where[]='EXISTS(SELECT 1 FROM tb_enc_importacion_detalle ld WHERE ld.inventario_id=i.id AND ld.lote_id=?)';
        $types.='i'; $params[]=$f['lote'];
    }
    if ($f['buscar'] !== '') {
        $search=[];
        foreach (['codigo_envio','doc_fiscal','destinatario','remitente','referencia_envio','origen','destino','oficina_destino','tipo_origen'] as $field) {
            $search[]='i.'.$field.' LIKE ?';$types.='s';$params[]='%'.$f['buscar'].'%';
        }
        $search[]='i.codigo_control LIKE ?';
        $types.='s';$params[]='%'.$f['buscar'].'%';
        $where[]='('.implode(' OR ',$search).')';
    }
    $joins=" FROM view_enc_inventario i
        LEFT JOIN tb_enc_oficinas_origen ao ON ao.tipo_origen=i.tipo_origen AND ao.clave=i.sucursal_clave
        LEFT JOIN tb_enc_oficinas_origen ad ON ad.tipo_origen=i.tipo_origen AND ad.clave=i.destino_clave
        WHERE ".implode(' AND ',$where);
    $summary=enc_fetch_one($conn,"SELECT COUNT(*) total,COALESCE(SUM(i.articulos),0) articulos,
        COALESCE(SUM(i.tipo_origen='KUPOS'),0) kupos,COALESCE(SUM(i.revision_estado='REZAGADO'),0) rezagados".$joins,$types,$params);
    $perPage=$f['por_pagina'] ?? 25;
    $page=min($f['pagina'],max(1,(int)ceil((int)$summary['total']/$perPage)));
    $rows=enc_fetch_all($conn,'SELECT i.*'
        .$joins.' ORDER BY i.fecha DESC,i.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage),$types,$params);
    return ['rows'=>$rows,'summary'=>$summary,'page'=>$page,'pages'=>max(1,(int)ceil((int)$summary['total']/$perPage))];
}

function enc_inventory_options(mysqli $conn): array {
    $result=['seguimiento'=>[],'estatus'=>[],'pago'=>[],'sucursal'=>[]];
    // Una consulta para todos los filtros, nunca una por fila de pantalla.
    $rows=enc_fetch_all($conn,"SELECT DISTINCT seguimiento,estatus,estado_pago,sucursal FROM view_enc_inventario WHERE activo=1");
    foreach ($rows as $row) foreach (['seguimiento'=>'seguimiento','estatus'=>'estatus','pago'=>'estado_pago','sucursal'=>'sucursal'] as $key=>$field) {
        if ($row[$field] !== null && $row[$field] !== '') $result[$key][$row[$field]]=true;
    }
    foreach ($result as &$values) { $values=array_keys($values);sort($values,SORT_STRING); }
    unset($values);
    return $result;
}
