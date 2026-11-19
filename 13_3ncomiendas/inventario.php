<?php
ob_start();
define('N360_ENCOMIENDAS',true);
require_once __DIR__.'/includes/encomiendas_inventario_queries.php';
require_once __DIR__.'/includes/encomiendas_inventory_layout.php';
$conn=enc_start_page('enc-tracking','Inventario de encomiendas');
$f=enc_inventory_filters(); $ready=false; $error=''; $rows=[]; $sedes=[];
try {
    $ready=enc_inventory_ready($conn);
    if ($ready) { $rows=enc_inventory_query($conn,$f);$sedes=enc_fetch_sedes($conn); }
} catch (Throwable $e) { enc_log($e);$rows=[];$error='No se pudo consultar el inventario. Inténtalo nuevamente.'; }
$csrf=enc_csrf_token();
$searchParams=array_intersect_key($f,array_flip(['buscar','desde','hasta','origen_registro','lote']));
enc_inventory_page_start('Inventario de encomiendas','Inventario');
?>
<div data-enc-inventory data-enc-rezagados data-csrf="<?= enc_h($csrf) ?>" data-initial-page="<?= $f['pagina'] ?>">
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
<article><span><?= enc_h($label) ?></span><strong data-inventory-kpi="<?= enc_h($key) ?>">…</strong></article>
<?php endforeach; ?></section>
<section class="enc-panel enc-filter-panel">
<div class="enc-source-bar"><span>Origen del registro</span><nav aria-label="Filtrar por origen del registro">
<?php foreach ([''=>'Todos','KUPOS'=>'Kupos','QUATROBUS'=>'Quatrobus','MANUAL'=>'Manual / excepcional'] as $v=>$label): ?>
<a class="enc-source-tab <?= $f['origen_registro']===$v?'is-active':'' ?>" data-inventory-source="<?= enc_h($v) ?>" href="<?= enc_h(enc_ui_url('inventario.php',$searchParams,['origen_registro'=>$v])) ?>" <?= $f['origen_registro']===$v?'aria-current="true"':'' ?>><?= enc_h($label) ?></a>
<?php endforeach; ?></nav></div>
<?php if ($f['lote']): ?><div class="enc-context-note"><i class="bi bi-file-earmark-spreadsheet"></i> Inventario del control seleccionado <a href="importaciones.php?id=<?= $f['lote'] ?>">Ver control</a><a href="<?= enc_h(enc_ui_url('inventario.php',$_GET,['lote'=>'','pagina'=>1])) ?>">Ver todo el inventario</a></div><?php endif; ?>
<form method="get" class="enc-inventory-search" data-inventory-search aria-label="Buscar encomiendas">
<label class="stock-field enc-filter-wide"><span>Buscar</span><input type="search" name="buscar" value="<?= enc_h($f['buscar']) ?>" placeholder="Código, documento, control, remitente o destinatario..."></label>
<input type="hidden" name="origen_registro" value="<?= enc_h($f['origen_registro']) ?>">
<label class="stock-field"><span>Fecha desde</span><input type="date" name="desde" value="<?= enc_h($f['desde']) ?>"></label>
<label class="stock-field"><span>Fecha hasta</span><input type="date" name="hasta" value="<?= enc_h($f['hasta']) ?>"></label>
<div class="enc-search-actions"><button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button><a class="stock-btn stock-btn--soft" data-inventory-clear-search title="Limpiar búsqueda y fechas" href="<?= enc_h(enc_ui_url('inventario.php',$searchParams,['buscar'=>'','desde'=>'','hasta'=>''])) ?>">Limpiar</a></div>
<?php if ($f['lote']): ?><input type="hidden" name="lote" value="<?= $f['lote'] ?>"><?php endif; ?>
<div data-inventory-search-state hidden></div></form></section>
<section class="enc-panel enc-local-filter-panel"><details class="enc-filter-accordion">
<summary><span><i class="bi bi-sliders" aria-hidden="true"></i> Filtrar resultados <small data-inventory-filter-count aria-live="polite">Todos los resultados cargados</small></span><i class="bi bi-chevron-down enc-filter-chevron" aria-hidden="true"></i></summary>
<form data-inventory-filters>
<div class="enc-local-filter-groups">
<fieldset><legend>Envío y pago</legend>
<label class="stock-field"><span>Modalidad del envío</span><select name="tipo_envio"><option value="">Todas</option><option value="CE">Contra entrega (CE)</option><option value="CP">Pagado al enviar (CP)</option><option value="OTRO">Otros / sin código CE o CP</option></select></label>
<label class="stock-field"><span>Estado de pago</span><select name="pago"><option value="">Todos</option></select></label>
<label class="stock-field"><span>Forma de pago</span><select name="tipo_pago"><option value="">Todas</option></select></label>
</fieldset><fieldset><legend>Seguimiento y revisión</legend>
<label class="stock-field"><span>Seguimiento del envío</span><select name="seguimiento"><option value="">Todos</option></select></label>
<label class="stock-field"><span>Estado del envío en Kupos</span><select name="estatus"><option value="">Todos</option></select></label>
<label class="stock-field"><span>Revisión Norte360</span><select name="revision"><option value="">Todas</option><option value="PENDIENTE">Pendiente</option><option value="OK">OK / revisado</option><option value="REZAGADO">Rezagado</option><option value="OBSERVADO">Observado</option></select></label>
</fieldset><fieldset><legend>Oficinas y texto</legend>
<?php foreach (['sucursal'=>'Sucursal de origen informada','oficina_destino'=>'Oficina de destino'] as $field=>$label): ?>
<div class="stock-field enc-multi-picker" data-inventory-multi="<?= enc_h($field) ?>">
<span id="encMultiLabel_<?= enc_h($field) ?>"><?= enc_h($label) ?></span>
<button type="button" class="enc-multi-trigger" data-multi-toggle aria-expanded="false" aria-controls="encMultiPanel_<?= enc_h($field) ?>" aria-labelledby="encMultiLabel_<?= enc_h($field) ?> encMultiCount_<?= enc_h($field) ?>"><strong id="encMultiCount_<?= enc_h($field) ?>" data-multi-count>Todas</strong><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
<div class="enc-multi-box" id="encMultiPanel_<?= enc_h($field) ?>" data-multi-panel role="group" aria-labelledby="encMultiLabel_<?= enc_h($field) ?>" hidden>
<input type="search" data-multi-search placeholder="Buscar opciones..." aria-label="Buscar en <?= enc_h($label) ?>" autocomplete="off">
<div class="enc-multi-actions"><button type="button" data-multi-visible>Seleccionar visibles</button><button type="button" data-multi-clear>Limpiar selección</button></div>
<div class="enc-multi-list" data-multi-list></div><p data-multi-empty hidden>No hay opciones para ese texto.</p>
</div></div>
<?php endforeach; ?>
<label class="stock-field"><span>Texto en pantalla</span><input type="search" name="texto_pantalla" placeholder="Código, documento, nombres, ruta..." autocomplete="off"></label>
</fieldset></div>
<div class="enc-local-filter-footer"><label><input type="checkbox" name="archivados" value="1"> Incluir retirados de manifiestos</label><button type="button" class="stock-btn stock-btn--soft" data-inventory-clear-filters><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Limpiar filtros</button></div>
</form></details></section>
<section class="enc-panel" data-inventory-results><div class="enc-panel__head"><div><span>Consulta de envíos</span><h2>Encomiendas registradas</h2></div><div class="enc-inventory-result-actions"><span data-inventory-count aria-live="polite">Cargando resultados…</span><button type="button" class="stock-btn stock-btn--soft" data-inventory-excel disabled><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Exportar Excel</button></div></div>
<p data-inventory-export-status class="enc-export-status" role="status" aria-live="polite"></p>
<noscript><p class="stock-alert stock-alert--warning">Activa JavaScript para mostrar, filtrar y paginar los resultados de la búsqueda y exportar a Excel.</p></noscript>
<div class="enc-pager enc-inventory-pager" data-inventory-pager></div>
<div class="enc-inventory-table-wrap" tabindex="0" aria-label="Inventario de encomiendas"><table class="stock-table enc-inventory-table"><thead><tr>
<th>Origen / control</th><th>Envío / documento</th><th>Remitente / destinatario</th><th>Ruta / oficina</th><th>Seguimiento</th><th>Pago / importe</th><th>Revisión</th><th>Acciones</th>
</tr></thead><tbody>
<tr><td colspan="8" class="enc-inventory-empty">Cargando encomiendas…</td></tr>
</tbody></table></div>
<div class="enc-pager enc-inventory-pager" data-inventory-pager></div></section>
<div class="modal fade enc-inventory-modal" id="encInventoryModal" tabindex="-1" aria-labelledby="encInventoryModalTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header"><div><span class="stock-eyebrow" data-inventory-modal-kicker>Información de encomienda</span><h2 class="modal-title" id="encInventoryModalTitle">Encomienda</h2><p class="enc-modal-subtitle" data-inventory-modal-subtitle></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
<div class="enc-modal-nav" role="group" aria-label="Vista de la encomienda"><button type="button" data-inventory-mode="info" aria-pressed="true"><i class="bi bi-info-circle" aria-hidden="true"></i> Información</button><button type="button" data-inventory-mode="history" aria-pressed="false"><i class="bi bi-clock-history" aria-hidden="true"></i> Historial</button></div>
<div class="modal-body" data-inventory-modal-body></div><div class="modal-footer"><button type="button" class="stock-btn stock-btn--soft" data-bs-dismiss="modal">Cerrar</button></div></div></div></div>
<?php $schemaReady=true; require __DIR__.'/includes/encomiendas_manual_modal.php'; ?>
<script type="application/json" id="encInventoryRows"><?php
$loadError=$error!=='';$sent=0;echo '{';
try {
    foreach ($rows as $row) {
        if ($sent) echo ',';
        echo json_encode((string)$row['id']).':'.json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $sent++;
        // Vaciar el buffer de salida no abre consultas ni conexiones por fila.
        if ($sent%200===0) { if (ob_get_level()>0) ob_flush();flush(); }
    }
} catch (Throwable $e) { enc_log($e);$loadError=true; }
echo '}';
?></script>
<?php if ($loadError && !$error): ?><p class="stock-alert stock-alert--danger" role="alert">No se pudo completar la búsqueda. Vuelve a buscar para obtener resultados completos.</p><?php endif; ?>
<script type="application/json" id="encInventoryConfig"><?= json_encode(['filters'=>$f,'sedes'=>$sedes,'search'=>$searchParams,'load_error'=>$loadError],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script type="application/json" id="encInventoryColumns"><?= json_encode(enc_kupos_columns()+['importe_cobrado'=>'Importe cobrado del manifiesto','sucursal'=>'Sucursal del reporte','tipo_origen'=>'Origen del registro','revision_estado'=>'Revisión Norte360','revision_observacion'=>'Observación Norte360','referencia_envio'=>'Referencia','peso'=>'Peso','guia_remision'=>'Guía de remisión','activo'=>'Vigente','legacy_hoja'=>'Hoja del manifiesto','legacy_orden'=>'Orden del ítem'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?></div>
<?php if ($ready): ?><script src="<?= enc_inventory_asset_url('assets/js/vendor/xlsx.full.min.js') ?>"></script><?php endif; ?>
<?php enc_inventory_page_end(['rezagados_encomienda.js','inventario_encomiendas.js']);$conn->close(); ?>
