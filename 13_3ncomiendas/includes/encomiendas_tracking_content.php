<?php if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.'); ?>
<?php enc_inventory_navigation('tracking'); ?>
<?php if (!$inventoryReady): ?><div class="stock-alert stock-alert--warning">Los controles Kupos requieren instalar la actualización de inventario.</div><?php endif; ?>
<section class="enc-inventory-summary enc-inventory-summary--three">
<?php foreach (['total'=>'Controles encontrados','viajes'=>'Quatrobus / manual','kupos'=>'Kupos'] as $key=>$label): ?><article><span><?= enc_h($label) ?></span><strong><?= number_format($tracking[$key]) ?></strong></article><?php endforeach; ?>
</section>
<section class="enc-panel enc-filter-panel">
<div class="enc-source-bar"><span>Tipo de control</span><nav aria-label="Filtrar controles por origen">
<?php foreach ([''=>'Todos','VIAJE'=>'Quatrobus / manual','KUPOS'=>'Kupos'] as $value=>$label): ?>
<a class="enc-source-tab <?= $filters['origen_registro']===$value?'is-active':'' ?>" href="<?= enc_h(enc_ui_url('tracking.php',$_GET,['origen_registro'=>$value,'page'=>1])) ?>" <?= $filters['origen_registro']===$value?'aria-current="true"':'' ?>><?= enc_h($label) ?></a>
<?php endforeach; ?></nav></div>
<details class="enc-filter-accordion" <?= $hasAdvancedFilters?'open':'' ?>>
<summary><span><i class="bi bi-sliders"></i> Buscar y filtrar <small>Control, fechas, documentos y datos del viaje</small></span><i class="bi bi-chevron-down enc-filter-chevron"></i></summary>
<form method="get" action="tracking.php" class="enc-inventory-filters" data-enc-filter-form>
<input type="hidden" name="origen_registro" value="<?= enc_h($filters['origen_registro']) ?>">
<label class="stock-field"><span>Control Encomienda</span><input type="search" name="guia" value="<?= enc_h($filters['guia']) ?>" placeholder="CE-000015"></label>
<label class="stock-field"><span>Documento fiscal</span><input type="search" name="documento" value="<?= enc_h($filters['documento']) ?>" placeholder="Factura, boleta o recibo"></label>
<label class="stock-field enc-filter-wide"><span>Buscar</span><input type="search" name="buscar" value="<?= enc_h($filters['buscar']) ?>" placeholder="Control, reporte, ruta o unidad..."></label>
<?php foreach (['desde'=>'Desde','hasta'=>'Hasta','fecha_guia'=>'Fecha exacta del control'] as $key=>$label): ?><label class="stock-field"><span><?= enc_h($label) ?></span><input type="date" name="<?= enc_h($key) ?>" value="<?= enc_h($filters[$key]) ?>"></label><?php endforeach; ?>
<label class="stock-field"><span>Resultados por página</span><select name="por_pagina"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $filters['per_page']===$size?'selected':'' ?>><?= $size ?> controles</option><?php endforeach; ?></select></label>
<?php foreach (['idsede_embarque'=>'Oficina de origen','idsede_desembarque'=>'Oficina de destino'] as $key=>$label): ?>
<label class="stock-field"><span><?= enc_h($label) ?></span><select name="<?= enc_h($key) ?>"><option value="0">Todas</option><?php foreach ($sedes as $sede): ?><option value="<?= (int)$sede['id'] ?>" <?= $filters[$key]==$sede['id']?'selected':'' ?>><?= enc_h($sede['nombre']) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<label class="stock-field"><span>Unidad (viajes)</span><select name="idplaca"><option value="0">Todas</option><?php foreach ($placas as $placa): ?><option value="<?= (int)$placa['id'] ?>" <?= $filters['idplaca']==$placa['id']?'selected':'' ?>><?= enc_h(enc_unit_label($placa)) ?></option><?php endforeach; ?></select></label>
<?php foreach (['estado_general'=>['Estado general (viajes)',['REGISTRADA','EN_TRANSITO','FINALIZADA','OBSERVADA','ANULADA']],'estado_embarque'=>['Embarque (viajes)',['PENDIENTE','EMBARCADO','OBSERVADO']],'estado_desembarque'=>['Desembarque (viajes)',['PENDIENTE','RECIBIDO','INCOMPLETO','OBSERVADO']],'estado_vida'=>['Vista (viajes)',['ACTIVO','FINALIZADO','OBSERVADO','ANULADO']]] as $key=>$field): ?>
<label class="stock-field"><span><?= enc_h($field[0]) ?></span><select name="<?= enc_h($key) ?>"><option value="TODOS">Todos</option><?php foreach ($field[1] as $value): ?><option value="<?= $value ?>" <?= $filters[$key]===$value?'selected':'' ?>><?= enc_h($value) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<div class="enc-filter-actions"><button type="submit" class="stock-btn stock-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button><a class="stock-btn stock-btn--soft" href="tracking.php">Limpiar</a><small>En Kupos, las fechas corresponden a la importación. Unidad y estados operativos filtran solo viajes.</small></div>
</form></details></section>
<section class="enc-panel">
<div class="enc-panel__head"><div><span>Seguimiento de controles</span><h2>Controles Encomienda registrados</h2></div><span><?= number_format($totalRows) ?> controles</span></div>
<?php enc_ui_pagination($totalRows,$currentPage,$filters['per_page'],$_GET,'tracking.php','page',true); ?>
<div class="enc-inventory-table-wrap" tabindex="0" aria-label="Controles Encomienda registrados"><table class="stock-table enc-controls-table"><thead><tr>
<th>Control / origen</th><th>Fecha / corte</th><th>Viaje o reporte</th><th>Estado</th><th>Documentos / resultado</th><th>Última actividad</th><th>Acciones</th>
</tr></thead><tbody>
<?php foreach ($rows as $row): $isKupos=$row['tipo_control']==='KUPOS';$isAnulada=!(int)$row['activo'] || $row['estado_general']==='ANULADA'; ?>
<tr data-control-origin="<?= enc_h($row['tipo_control']) ?>">
<td><strong><a href="<?= $isKupos?'importaciones.php':'detalle.php' ?>?id=<?= (int)$row['id'] ?>"><?= enc_h($row['codigo_control']) ?></a></strong><small><span class="enc-inventory-source"><?= $isKupos?'KUPOS':'QUATROBUS / MANUAL' ?></span></small></td>
<td><strong><?= enc_h(enc_fmt_date($row['fecha_control'])) ?></strong><small><?= $isKupos?'Corte: '.enc_h(enc_fmt_datetime($row['fecha_corte'])):enc_h(trim(($row['horario'] ?: 'Sin horario').' '.substr((string)$row['hora'],0,5))) ?></small></td>
<td><?php if ($isKupos): ?><strong class="enc-report-name"><?= enc_h($row['archivo']) ?></strong><small><?= (int)$row['sucursales'] ?> sucursales · <?= number_format((int)$row['envios']) ?> envíos</small>
<?php else: ?><strong><?= enc_h($row['origen']) ?></strong><small>Hacia <?= enc_h($row['destino']) ?></small><small><?= enc_h(trim(($row['bus'] ?? '').' - '.($row['placa'] ?? ''),' -') ?: 'Sin unidad') ?></small><?php endif; ?></td>
<td><?php if ($isKupos): ?><span class="enc-state enc-state--success">IMPORTADO</span><small>Inventario actualizado</small><?php else: ?><?= enc_state_badge($row['estado_general']) ?><small>Embarque: <?= enc_h($row['embarque']) ?></small><small>Desembarque: <?= enc_h($row['desembarque']) ?></small><?php endif; ?></td>
<td><?php if ($isKupos): ?><strong><?= (int)$row['nuevos'] ?> nuevos · <?= (int)$row['modificados'] ?> modificados</strong><small><?= (int)$row['sin_cambios'] ?> sin cambios</small>
<?php else: ?><strong><?= (int)$row['manifiestos_ok'] ?>/<?= (int)$row['manifiestos_req'] ?> manifiestos</strong><small><?= (int)$row['transportista'] ?> guías de transportista</small><?php endif; ?></td>
<td><?= enc_h(enc_fmt_datetime($row['actualizado_en'])) ?></td>
<td><div class="enc-inventory-actions">
<?php if ($isKupos): ?>
<a class="stock-btn stock-btn--soft stock-btn--sm" href="importaciones.php?id=<?= (int)$row['id'] ?>"><i class="bi bi-eye"></i> Ver control</a>
<a class="stock-btn stock-btn--primary stock-btn--sm" href="inventario.php?lote=<?= (int)$row['id'] ?>&amp;origen_registro=KUPOS"><i class="bi bi-box-seam"></i> Encomiendas</a>
<?php else: ?>
<button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-detail="<?= (int)$row['id'] ?>"><i class="bi bi-eye"></i> Ver</button>
<button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-detail-section="timeline" data-guide-id="<?= (int)$row['id'] ?>"><i class="bi bi-diagram-3"></i> Seguimiento</button>
<button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-detail-section="history" data-guide-id="<?= (int)$row['id'] ?>"><i class="bi bi-clock-history"></i> Historial</button>
<button class="stock-btn stock-btn--primary stock-btn--sm" type="button" data-enc-pdf-guide="<?= (int)$row['id'] ?>"><i class="bi bi-filetype-pdf"></i> PDF</button>
<?php if ($canAnular && !$isAnulada): ?><button class="stock-btn stock-btn--danger stock-btn--sm" type="button" data-enc-annul-open data-guide-id="<?= (int)$row['id'] ?>" data-guide-code="<?= enc_h($row['codigo_control']) ?>"><i class="bi bi-x-octagon"></i> Anular</button><?php endif; ?>
<?php endif; ?></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="enc-inventory-empty">No hay controles para los filtros seleccionados.</td></tr><?php endif; ?>
</tbody></table></div>
<?php enc_ui_pagination($totalRows,$currentPage,$filters['per_page'],$_GET,'tracking.php','page'); ?>
</section>
