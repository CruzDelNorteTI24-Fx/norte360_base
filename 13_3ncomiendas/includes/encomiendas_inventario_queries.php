<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');
require_once __DIR__ . '/encomiendas_inventario.php';

function enc_inventory_filters(): array {
    $scalar = static function (string $key): string { return is_scalar($_GET[$key] ?? '') ? trim((string)($_GET[$key] ?? '')) : ''; };
    $multiple = static function (string $key): array {
        $raw=$_GET[$key] ?? [];if (!is_array($raw)) $raw=[$raw];$values=[];
        foreach ($raw as $value) {
            if (!is_scalar($value)) continue;
            $value=trim((string)$value);
            $value=function_exists('mb_substr')?mb_substr($value,0,160,'UTF-8'):substr($value,0,160);
            if ($value!=='') $values[]=$value;
        }
        return array_values(array_unique($values));
    };
    $from=enc_nullable_date($scalar('desde')); $to=enc_nullable_date($scalar('hasta'));
    if ($from && $to && $from>$to) { $swap=$from; $from=$to; $to=$swap; }
    $source=$scalar('origen_registro');
    if (!in_array($source,['KUPOS','QUATROBUS','MANUAL'],true)) $source='';
    $revision=$scalar('revision') ?: $scalar('estado');
    if (!in_array($revision,['PENDIENTE','OK','REZAGADO','OBSERVADO'],true)) $revision='';
    $shipment=strtoupper($scalar('tipo_envio'));
    if (!in_array($shipment,['CE','CP','OTRO'],true)) $shipment='';
    // Solo texto, fechas, origen y contexto de control se aplican a la consulta SQL.
    // Los demás valores inicializan filtros locales del conjunto completo cargado.
    return ['buscar'=>substr($scalar('buscar'),0,180),'origen_registro'=>$source,
        'revision'=>$revision,'seguimiento'=>substr($scalar('seguimiento'),0,80),
        'estatus'=>substr($scalar('estatus'),0,50),'pago'=>substr($scalar('pago'),0,50),
        'sucursal'=>$multiple('sucursal'),
        'tipo_envio'=>$shipment,'tipo_pago'=>substr($scalar('tipo_pago'),0,80),
        'oficina_destino'=>$multiple('oficina_destino'),'texto_pantalla'=>$scalar('texto_pantalla'),
        'desde'=>$from ?? '','hasta'=>$to ?? '','archivados'=>$scalar('archivados')==='1',
        'pagina'=>max(1,(int)$scalar('pagina')),'lote'=>max(0,(int)$scalar('lote')),
        'por_pagina'=>500];
}

function enc_inventory_query(mysqli $conn,array $f): Generator {
    $where=['1=1']; $types=''; $params=[];
    if ($f['origen_registro']!=='') { $where[]='i.tipo_origen=?';$types.='s';$params[]=$f['origen_registro']; }
    foreach (['desde'=>'>=','hasta'=>'<='] as $key=>$op) {
        if ($f[$key] !== '') { $where[]='i.fecha'.$op.'?';$types.='s';$params[]=$f[$key]; }
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
    // Una consulta sin LIMIT. Se transmite fila a fila, sin retener otra copia completa en PHP.
    // bind_result evita que get_result almacene el resultado completo antes de leerlo.
    $stmt=enc_execute($conn,'SELECT i.*,ao.sede_id sede_sucursal_id,ad.sede_id sede_oficina_destino_id'
        .$joins.' ORDER BY i.fecha DESC,i.id DESC',$types,$params);
    try {
        $metadata=$stmt->result_metadata();$values=[];$bindings=[];
        foreach ($metadata->fetch_fields() as $field) { $values[$field->name]=null;$bindings[]=&$values[$field->name]; }
        $metadata->free();$stmt->bind_result(...$bindings);
        while ($stmt->fetch()) {
            $row=[];foreach ($values as $key=>$value) $row[$key]=$value;
            yield $row;
        }
    } finally { $stmt->free_result();$stmt->close(); }
}
