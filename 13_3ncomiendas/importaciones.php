<?php
ob_start();
define('N360_ENCOMIENDAS',true);
require_once __DIR__.'/includes/encomiendas_inventario.php';
require_once __DIR__.'/includes/encomiendas_inventory_layout.php';
n360_start_secure_session();
$canTrack=enc_can_view('enc-tracking');
$conn=enc_start_page($canTrack ? 'enc-tracking' : 'enc-register','Controles Kupos');
$id=max(0,(int)($_GET['id'] ?? 0)); $page=max(1,(int)($_GET['pagina'] ?? 1));
$ready=false;$error='';$lote=null;$rows=[];$count=0;$pages=1;
try {
    $ready=enc_inventory_ready($conn);
    if ($ready) {
        $owner=$canTrack ? '' : ' AND l.creado_por=?';$ownerTypes=$canTrack?'':'i';$ownerParams=$canTrack?[]:[enc_user_id()];
        if ($id) {
            $lote=enc_fetch_one($conn,"SELECT l.*,COALESCE(NULLIF(u.usuario,''),u.nombre) usuario FROM tb_enc_importaciones l
                LEFT JOIN tb_usuarios u ON u.id_usuario=l.creado_por WHERE l.id=?".$owner,'i'.$ownerTypes,array_merge([$id],$ownerParams));
            if (!$lote) { http_response_code(404);$error='El control no existe o no está disponible para tu usuario.'; }
            else {
                $count=(int)$lote['total_envios'];$pages=max(1,(int)ceil($count/50));$page=min($page,$pages);
                $rows=enc_fetch_all($conn,'SELECT inventario_id,accion,sucursal,datos_json FROM tb_enc_importacion_detalle WHERE lote_id=? ORDER BY id LIMIT 50 OFFSET '.(($page-1)*50),'i',[$id]);
            }
        } else {
            $total=enc_fetch_one($conn,'SELECT COUNT(*) total FROM tb_enc_importaciones l WHERE 1=1'.$owner,$ownerTypes,$ownerParams);
            $count=(int)$total['total'];$pages=max(1,(int)ceil($count/25));$page=min($page,$pages);
            $rows=enc_fetch_all($conn,'SELECT l.* FROM tb_enc_importaciones l WHERE 1=1'.$owner.' ORDER BY l.id DESC LIMIT 25 OFFSET '.(($page-1)*25),$ownerTypes,$ownerParams);
        }
    }
} catch (Throwable $e) {enc_log($e);$error='No se pudieron consultar los controles de importación.';}
enc_inventory_page_start('Controles Kupos','Importaciones');
?>
<section class="stock-hero enc-hero"><div class="enc-hero__text"><span class="stock-eyebrow">Encomiendas - Kupos</span>
<h1><?= enc_h($lote ? $lote['codigo_control'] : 'Controles Kupos') ?></h1>
<p><?= $lote ? 'Encomiendas e importes tal como se registraron en este reporte.' : 'Cada reporte confirmado conserva su control y el resultado de la importación.' ?></p></div>
<div class="stock-hero-actions"><?php if ($canTrack): ?><a class="stock-btn stock-btn--soft" href="tracking.php?origen_registro=KUPOS"><i class="bi bi-arrow-left"></i> Volver a Tracking</a><?php endif; ?>
<?php if (enc_can_view('enc-register')): ?><a class="stock-btn stock-btn--soft" href="registro.php?modo=kupos"><i class="bi bi-cloud-arrow-up"></i> Importar reporte</a><?php endif; ?>
<?php if ($lote && $canTrack): ?><a class="stock-btn stock-btn--primary" href="inventario.php?lote=<?= $id ?>&amp;origen_registro=KUPOS"><i class="bi bi-box-seam"></i> Encomiendas del control</a><?php endif; ?></div></section>
<?php if ($canTrack) enc_inventory_navigation('kupos'); ?>
<?php if (!$ready): ?><div class="stock-alert stock-alert--warning">La actualización de importaciones está pendiente de instalación.</div><?php endif; ?>
<?php if ($error): ?><div class="stock-alert stock-alert--danger"><?= enc_h($error) ?></div><?php endif; ?>
<?php if ($lote): ?>
<section class="enc-panel"><div class="enc-panel__head"><div><span>Control Encomienda · Kupos</span><h2>Datos de la importación</h2></div><span class="enc-state enc-state--success">Importado</span></div><dl class="enc-inventory-detail-grid">
<?php foreach (['archivo_nombre'=>'Archivo','usuario'=>'Importado por','creado_en'=>'Fecha de importación','fecha_corte'=>'Corte del reporte'] as $field=>$label): ?><div><dt><?= enc_h($label) ?></dt><dd><?= enc_h($lote[$field]) ?></dd></div><?php endforeach; ?>
</dl></section><section class="enc-inventory-summary">
<?php foreach (['total_envios'=>'Envíos','nuevos'=>'Nuevos','modificados'=>'Modificados','sin_cambios'=>'Sin cambios'] as $field=>$label): ?><article><span><?= enc_h($label) ?></span><strong><?= (int)$lote[$field] ?></strong></article><?php endforeach; ?></section>
<?php endif; ?>
<?php if ($ready && (!$id || $lote)): ?>
<section class="enc-panel"><div class="enc-panel__head"><div><span><?= $lote?'Fotografía del reporte':'Reportes confirmados' ?></span><h2><?= $lote?'Encomiendas de este control':'Controles registrados' ?></h2></div></div>
<?php enc_ui_pagination($count,$page,$lote?50:25,$_GET,'importaciones.php','pagina',true); ?>
<div class="enc-inventory-table-wrap" tabindex="0" aria-label="Datos del reporte Kupos"><table class="stock-table <?= $lote ? 'enc-kupos-table' : '' ?>"><thead><tr>
<?php if ($lote): ?><th>Resultado</th><th>Sucursal</th><?php foreach (enc_kupos_columns() as $label): ?><th><?= enc_h($label) ?></th><?php endforeach; ?>
<?php else: ?><th>Control</th><th>Reporte</th><th>Corte</th><th>Envíos</th><th>Nuevos</th><th>Modificados</th><th>Sin cambios</th><th>Acción</th><?php endif; ?>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<?php if ($lote): $data=json_decode($row['datos_json'],true) ?: []; ?>
<td><?= enc_h($row['accion']) ?></td><td><?= enc_h($row['sucursal']) ?></td>
<?php foreach (enc_kupos_columns() as $field=>$label): ?><td><?= enc_h($field==='total' ? enc_inventory_money($data[$field] ?? null) : ($data[$field] ?? '-')) ?></td><?php endforeach; ?>
<?php else: ?><td><strong><?= enc_h($row['codigo_control']) ?></strong><small class="d-block"><?= enc_h(enc_fmt_datetime($row['creado_en'])) ?></small></td><td><?= enc_h($row['archivo_nombre']) ?></td><td><?= enc_h(enc_fmt_datetime($row['fecha_corte'])) ?></td>
<td><?= (int)$row['total_envios'] ?></td><td><?= (int)$row['nuevos'] ?></td><td><?= (int)$row['modificados'] ?></td><td><?= (int)$row['sin_cambios'] ?></td>
<td><a class="stock-btn stock-btn--soft stock-btn--sm" href="importaciones.php?id=<?= (int)$row['id'] ?>">Ver control</a></td><?php endif; ?>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="21" class="enc-inventory-empty">No hay importaciones registradas.</td></tr><?php endif; ?>
</tbody></table></div><?php enc_ui_pagination($count,$page,$lote?50:25,$_GET,'importaciones.php'); ?></section><?php endif; ?>
<?php enc_inventory_page_end(); ?>
