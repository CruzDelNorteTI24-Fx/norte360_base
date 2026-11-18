<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');

// Campos publicos de view_enc_inventario y sus columnas del inventario existente.
function enc_inventory_column_map(): array {
    return [
        'id'=>'clm_encrevitem_id',
        'idrevision'=>'clm_encrevitem_idrevision',
        'orden'=>'clm_encrevitem_orden',
        'tipo_origen'=>'clm_encrevitem_origen_registro',
        'doc_fiscal'=>'clm_encrevitem_documento',
        'destinatario'=>'clm_encrevitem_consignado',
        'tipo_pago'=>'clm_encrevitem_tipo_pago',
        'revision_estado'=>'clm_encrevitem_estado',
        'revision_observacion'=>'clm_encrevitem_observacion',
        'referencia_envio'=>'clm_encrevitem_referencia_envio',
        'peso'=>'clm_encrevitem_peso',
        'importe_cobrado'=>'clm_encrevitem_importe_cobrado',
        'guia_remision'=>'clm_encrevitem_guia_remision',
        'activo'=>'clm_encrevitem_activo',
        'codigo_envio'=>'clm_encrevitem_codigo_envio',
        'documento_fiscal'=>'clm_encrevitem_documento_fiscal',
        'nota_credito'=>'clm_encrevitem_nota_credito',
        'fecha'=>'clm_encrevitem_fecha',
        'remitente'=>'clm_encrevitem_remitente',
        'articulos'=>'clm_encrevitem_articulos',
        'estatus'=>'clm_encrevitem_estatus',
        'seguimiento'=>'clm_encrevitem_seguimiento',
        'estado_pago'=>'clm_encrevitem_estado_pago',
        'numero_operacion'=>'clm_encrevitem_numero_operacion',
        'origen'=>'clm_encrevitem_origen',
        'destino'=>'clm_encrevitem_destino',
        'oficina_destino'=>'clm_encrevitem_oficina_destino',
        'creado_por_externo'=>'clm_encrevitem_creado_por_externo',
        'cobrado_por_externo'=>'clm_encrevitem_cobrado_por_externo',
        'total'=>'clm_encrevitem_total',
        'sucursal'=>'clm_encrevitem_sucursal',
        'sucursal_clave'=>'clm_encrevitem_sucursal_clave',
        'destino_clave'=>'clm_encrevitem_destino_clave',
        'ultimo_lote_id'=>'clm_encrevitem_ultimo_lote_id',
        'observado_hasta'=>'clm_encrevitem_observado_hasta',
        'version'=>'clm_encrevitem_version',
        'creado_por'=>'clm_encrevitem_creado_por',
        'actualizado_por'=>'clm_encrevitem_actualizado_por',
        'creado_en'=>'clm_encrevitem_creado_en',
        'actualizado_en'=>'clm_encrevitem_actualizado_en',
        'documento_origen_id'=>'clm_encrevitem_iddocumento',
        'hoja_origen'=>'clm_encrevitem_hoja_origen'
    ];
}

function enc_inventory_column(string $field): string {
    $map=enc_inventory_column_map();
    if (!isset($map[$field])) throw new LogicException('Campo de inventario no permitido.');
    return $map[$field];
}

function enc_inventory_physical_values(array $values): array {
    $result=[];
    foreach ($values as $key=>$value) $result[enc_inventory_column($key)]=$value;
    return $result;
}

function enc_inventory_update(mysqli $conn,int $id,array $values,bool $increment=true): void {
    $physical=enc_inventory_physical_values($values);
    $sets=[];$params=[];
    foreach ($physical as $field=>$value) {$sets[]='`'.$field.'`=?';$params[]=$value;}
    if ($increment) $sets[]='clm_encrevitem_version=clm_encrevitem_version+1';
    if (!$sets) return;
    $params[]=$id;
    enc_execute($conn,'UPDATE tb_enc_manifiesto_revision_items SET '.implode(',',$sets).' WHERE clm_encrevitem_id=?',
        str_repeat('s',count($params)),$params)->close();
}
