<?php
ob_start();
define('N360_ENCOMIENDAS',true);
require_once __DIR__.'/includes/encomiendas_inventario_queries.php';
require_once __DIR__.'/includes/encomiendas_inventory_layout.php';
$conn=enc_start_page('enc-tracking','Inventario de encomiendas');
$f=enc_inventory_filters(); $ready=false; $error=''; $rows=[]; $options=[]; $sedes=[];
$result=['summary'=>['total'=>0,'articulos'=>0,'rezagados'=>0,'kupos'=>0],'page'=>1,'pages'=>1];
try {
    $ready=enc_inventory_ready($conn);
    if ($ready) { $result=enc_inventory_query($conn,$f);$rows=$result['rows'];$options=enc_inventory_options($conn);$sedes=enc_fetch_sedes($conn); }
} catch (Throwable $e) { enc_log($e);$error='No se pudo consultar el inventario. Inténtalo nuevamente.'; }
$csrf=enc_csrf_token();
$activeFilters=0;
foreach (['buscar','revision','seguimiento','estatus','pago','sucursal','desde','hasta'] as $key) if ($f[$key]!=='') $activeFilters++;
if ($f['sede']) $activeFilters++;
if ($f['archivados']) $activeFilters++;
enc_inventory_page_start('Inventario de encomiendas','Inventario');
?>
<div data-enc-inventory data-enc-rezagados data-csrf="<?= enc_h($csrf) ?>">
<section class="stock-hero enc-hero"><div class="enc-hero__text"><span class="stock-eyebrow">Encomiendas - Inventario común</span>
<h1>Inventario de encomiendas</h1><p>Envíos, importes, seguimiento e historial en un solo lugar.</p></div>
<div class="stock-hero-actions"><?php if (enc_can_view('enc-register')): ?><a class="stock-btn stock-btn--soft" href="registro.php"><i class="bi bi-plus-circle"></i> Registrar control</a><?php endif; ?>
<?php if ($ready): ?><button class="stock-btn stock-btn--primary" type="button" data-bs-toggle="modal" data-bs-target="#encManualRezagadoModal"><i class="bi bi-pencil-square"></i> Agregar manual</button><?php endif; ?></div></section>
<?php enc_inventory_navigation('inventario'); ?>
<?php if (!$ready): ?><div class="stock-alert stock-alert--warning">La actualización del inventario está pendiente de instalación. Los controles y manifiestos anteriores se conservan. <a href="tracking.php">Consultar controles manuales</a></div><?php endif; ?>
<?php if ($error): ?><div class="stock-alert stock-alert--danger"><?= enc_h($error) ?></div><?php endif; ?>
<?php if ($ready): ?>
<section class="enc-inventory-summary">
<?php foreach (['total'=>'Envíos filtrados','articulos'=>'Artículos informados','rezagados'=>'Rezagados','kupos'=>'Envíos Kupos'] as $key=>$label): ?>
<article><span><?= enc_h($label) ?></span><strong><?= enc_h(number_format((int)($result['summary'][$key] ?? 0),0,'.',',')) ?></strong></article>
<?php endforeach; ?></section>
<section class="enc-panel enc-filter-panel">
<div class="enc-source-bar"><span>Origen del registro</span><nav aria-label="Filtrar por origen del registro">
<?php foreach ([''=>'Todos','KUPOS'=>'Kupos','QUATROBUS'=>'Quatrobus','MANUAL'=>'Manual / excepcional'] as $v=>$label): ?>
<a class="enc-source-tab <?= $f['origen_registro']===$v?'is-active':'' ?>" href="<?= enc_h(enc_ui_url('inventario.php',$_GET,['origen_registro'=>$v,'pagina'=>1])) ?>" <?= $f['origen_registro']===$v?'aria-current="true"':'' ?>><?= enc_h($label) ?></a>
<?php endforeach; ?></nav></div>
<?php if ($f['lote']): ?><div class="enc-context-note"><i class="bi bi-file-earmark-spreadsheet"></i> Inventario del control seleccionado <a href="importaciones.php?id=<?= $f['lote'] ?>">Ver control</a><a href="<?= enc_h(enc_ui_url('inventario.php',$_GET,['lote'=>'','pagina'=>1])) ?>">Ver todo el inventario</a></div><?php endif; ?>
<details class="enc-filter-accordion" <?= $activeFilters?'open':'' ?>>
<summary><span><i class="bi bi-sliders"></i> Buscar y filtrar <small><?= $activeFilters ? $activeFilters.' filtros aplicados' : 'Código, estado, oficina y fechas' ?></small></span><i class="bi bi-chevron-down enc-filter-chevron"></i></summary>
<form method="get" class="enc-inventory-filters">
<label class="stock-field enc-filter-wide"><span>Buscar</span><input type="search" name="buscar" value="<?= enc_h($f['buscar']) ?>" placeholder="Código, documento, control, remitente o destinatario..."></label>
<input type="hidden" name="origen_registro" value="<?= enc_h($f['origen_registro']) ?>">
<label class="stock-field"><span>Revisión Norte360</span><select name="revision"><option value="">Todas</option><?php foreach (['PENDIENTE','OK','REZAGADO','OBSERVADO'] as $v): ?><option <?= $f['revision']===$v?'selected':'' ?>><?= enc_h($v) ?></option><?php endforeach; ?></select></label>
<label class="stock-field"><span>Resultados por página</span><select name="por_pagina"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $f['por_pagina']===$size?'selected':'' ?>><?= $size ?> encomiendas</option><?php endforeach; ?></select></label>
<?php foreach (['seguimiento'=>'Seguimiento Kupos','estatus'=>'Estatus Kupos','pago'=>'Estado de pago','sucursal'=>'Sucursal del reporte'] as $key=>$label): ?>
<label class="stock-field"><span><?= enc_h($label) ?></span><select name="<?= enc_h($key) ?>"><option value="">Todos</option><?php foreach ($options[$key] as $v): ?><option value="<?= enc_h($v) ?>" <?= $f[$key]===$v?'selected':'' ?>><?= enc_h($v) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<label class="stock-field"><span>Sede vinculada</span><select name="sede"><option value="0">Todas</option><?php foreach ($sedes as $sede): ?><option value="<?= (int)$sede['id'] ?>" <?= $f['sede']===(int)$sede['id']?'selected':'' ?>><?= enc_h($sede['nombre']) ?></option><?php endforeach; ?></select></label>
<label class="stock-field"><span>Fecha desde</span><input type="date" name="desde" value="<?= enc_h($f['desde']) ?>"></label>
<label class="stock-field"><span>Fecha hasta</span><input type="date" name="hasta" value="<?= enc_h($f['hasta']) ?>"></label>
<div class="enc-filter-actions"><button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button><a class="stock-btn stock-btn--soft" href="inventario.php">Limpiar</a>
<label><input type="checkbox" name="archivados" value="1" <?= $f['archivados']?'checked':'' ?>> Incluir retirados de manifiestos</label>
<?php if ($f['lote']): ?><input type="hidden" name="lote" value="<?= $f['lote'] ?>"><?php endif; ?></div>
</form></details></section>
<section class="enc-panel"><div class="enc-panel__head"><div><span>Consulta de envíos</span><h2>Encomiendas registradas</h2></div><span><?= count($rows) ?> de <?= (int)$result['summary']['total'] ?></span></div>
<?php enc_ui_pagination((int)$result['summary']['total'],$result['page'],$f['por_pagina'],$_GET,'inventario.php','pagina',true); ?>
<div class="enc-inventory-table-wrap" tabindex="0" aria-label="Inventario de encomiendas"><table class="stock-table enc-inventory-table"><thead><tr>
<th>Origen / control</th><th>Envío / documento</th><th>Remitente / destinatario</th><th>Ruta / oficina</th><th>Seguimiento</th><th>Pago / importe</th><th>Revisión</th><th>Acciones</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><span class="enc-inventory-source"><?= enc_h($row['tipo_origen']) ?></span><strong>
<?php if ($row['tipo_origen']==='KUPOS' && $row['ultimo_lote_id']): ?><a href="importaciones.php?id=<?= (int)$row['ultimo_lote_id'] ?>"><?= enc_h($row['codigo_control'] ?: '-') ?></a>
<?php elseif ($row['legacy_guia_id']): ?><a href="detalle.php?id=<?= (int)$row['legacy_guia_id'] ?>"><?= enc_h($row['codigo_control'] ?: '-') ?></a>
<?php else: ?><?= enc_h($row['codigo_control'] ?: '-') ?><?php endif; ?></strong><small><?= enc_h(enc_fmt_date($row['fecha'])) ?></small></td>
<td><strong><?= enc_h($row['codigo_envio'] ?: $row['doc_fiscal'] ?: '-') ?></strong><small><?= enc_h($row['codigo_envio'] ? ($row['doc_fiscal'] ?: 'Sin documento fiscal') : ($row['guia_remision'] ?: '')) ?></small></td>
<td><?= enc_h($row['remitente'] ?: '-') ?><small>Para: <?= enc_h($row['destinatario'] ?: '-') ?></small><?php if ($row['articulos']!==null): ?><small><?= (int)$row['articulos'] ?> artículo(s)</small><?php endif; ?></td>
<td><?= enc_h(($row['origen'] ?: '-') . ' - ' . ($row['destino'] ?: '-')) ?><small><?= enc_h($row['oficina_destino'] ?: '-') ?></small><?php if ($row['sucursal']): ?><small>Sucursal: <?= enc_h($row['sucursal']) ?></small><?php endif; ?></td>
<td><?= enc_h($row['seguimiento'] ?: '-') ?><small><?= enc_h($row['estatus'] ?: '') ?></small><?php if (!(int)$row['activo']): ?><small>Retirado del manifiesto</small><?php endif; ?></td>
<td><small><?= $row['tipo_origen']==='KUPOS' ? 'Total' : 'Importe cobrado' ?></small><strong><?= enc_h(enc_inventory_money($row['tipo_origen']==='KUPOS'?$row['total']:$row['importe_cobrado'])) ?></strong><small><?= enc_h($row['estado_pago'] ?: '-') ?></small><small><?= enc_h($row['tipo_pago'] ?: '') ?></small></td>
<td><span class="enc-state <?= $row['revision_estado']==='REZAGADO'?'enc-state--danger':'enc-state--muted' ?>"><?= enc_h($row['revision_estado']) ?></span></td>
<td><div class="enc-inventory-actions"><button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-inventory-info="<?= (int)$row['id'] ?>"><i class="bi bi-info-circle"></i> Información</button>
<button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-inventory-history="<?= (int)$row['id'] ?>"><i class="bi bi-clock-history"></i> Historial</button>
<?php if ($row['legacy_documento_id']): ?><a class="stock-btn stock-btn--soft stock-btn--sm" href="revision_manifiesto.php?documento=<?= (int)$row['legacy_documento_id'] ?>"><i class="bi bi-pencil"></i> Revisar PDF</a><?php endif; ?></div></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="enc-inventory-empty">No hay encomiendas para los filtros seleccionados.</td></tr><?php endif; ?>
</tbody></table></div>
<?php enc_ui_pagination((int)$result['summary']['total'],$result['page'],$f['por_pagina'],$_GET,'inventario.php'); ?></section>
<div class="modal fade enc-inventory-modal" id="encInventoryModal" tabindex="-1" aria-labelledby="encInventoryModalTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header"><div><span class="stock-eyebrow">Inventario de encomiendas</span><h2 class="modal-title fs-5" id="encInventoryModalTitle">Encomienda</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
<div class="modal-body" data-inventory-modal-body></div></div></div></div>
<?php $schemaReady=true; require __DIR__.'/includes/encomiendas_manual_modal.php'; ?>
<script type="application/json" id="encInventoryRows"><?= json_encode(array_column($rows,null,'id'),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script type="application/json" id="encInventoryColumns"><?= json_encode(enc_kupos_columns()+['importe_cobrado'=>'Importe cobrado del manifiesto','sucursal'=>'Sucursal del reporte','tipo_origen'=>'Origen del registro','revision_estado'=>'Revisión Norte360','revision_observacion'=>'Observación Norte360','referencia_envio'=>'Referencia','peso'=>'Peso','guia_remision'=>'Guía de remisión','activo'=>'Vigente','legacy_hoja'=>'Hoja del manifiesto','legacy_orden'=>'Orden del ítem'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?></div>
<?php enc_inventory_page_end(['rezagados_encomienda.js','inventario_encomiendas.js']); ?>
