<?php
define('N360_ENCOMIENDAS',true);
require_once __DIR__.'/../13_3ncomiendas/includes/encomiendas_inventario.php';
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
$jsonPost=$method==='POST' && stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''),'application/json')!==false;
function enc_offices_json(bool $ok,string $message,array $data=[],int $status=200): void {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok,'message'=>$message]+$data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
n360_start_secure_session();
if (!isset($_SESSION['usuario'])) { if ($jsonPost) enc_offices_json(false,'La sesión venció. Vuelve a iniciar sesión.',[],401);header('Location: ../login/login.php');exit; }
if (!enc_is_admin()) { if ($jsonPost) enc_offices_json(false,'Acceso reservado a Administración.',[],403);http_response_code(403);exit('Acceso reservado a Administración.'); }
if (!in_array($method,['GET','HEAD','POST'],true)) { http_response_code(405);exit('Método no permitido.'); }
if ($method==='POST' && (!is_string($_POST['csrf_token'] ?? null) || !n360_verify_csrf($_POST['csrf_token'],'enc_oficinas'))) { if ($jsonPost) enc_offices_json(false,'La sesión del formulario venció. Actualiza la página.',[],419);http_response_code(419);exit('Token de seguridad no válido.'); }
define('N360_ADMIN_CATALOG',true);
require_once __DIR__.'/_admin_catalogos.php';
$conn->set_charset('utf8mb4');
$error='';$errorStatus=409;$rows=[];$sedes=[];$ready=false;$previewCount=0;
$query=is_scalar($_GET['buscar'] ?? '') ? trim((string)($_GET['buscar'] ?? '')) : '';
$state=is_string($_GET['estado'] ?? null) && in_array($_GET['estado'],['pendientes','vinculadas'],true)?$_GET['estado']:'todas';
$page=is_scalar($_GET['pagina'] ?? '')?max(1,(int)($_GET['pagina'] ?? 1)):1;
$size=is_string($_GET['tamano'] ?? null) && in_array($_GET['tamano'],['10','25','50','todos'],true)?$_GET['tamano']:'10';
try {
    $ready=enc_inventory_ready($conn);
    if ($ready && $method==='POST') {
        $id=max(0,(int)($_POST['id'] ?? 0));$sede=max(0,(int)($_POST['sede_id'] ?? 0));
        $selectedSede=$sede?enc_fetch_one($conn,'SELECT clm_sedes_id,clm_sedes_name FROM tb_sedes WHERE clm_sedes_id=?','i',[$sede]):null;
        if ($sede && !$selectedSede) throw new InvalidArgumentException('La sede seleccionada no existe.');
        $conn->begin_transaction();
        try {
            if (!$id) {
                $key=is_string($_POST['clave_detectada'] ?? null)?$_POST['clave_detectada']:'';
                $detected=enc_kupos_preview_offices();
                if (!isset($detected[$key])) throw new InvalidArgumentException('La oficina ya no está en el CSV validado de tu sesión. Vuelve a validar el archivo y actualiza esta vista.');
                // Solo Guardar crea la equivalencia; un GET o Validar no la registra.
                enc_execute($conn,"INSERT INTO tb_enc_oficinas_origen(tipo_origen,clave,nombre,actualizado_por,actualizado_en)
                    VALUES('KUPOS',?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",'ssis',[$key,$detected[$key],enc_user_id(),enc_inventory_now()])->close();
                $id=(int)$conn->insert_id;
            }
            $current=enc_fetch_one($conn,"SELECT * FROM tb_enc_oficinas_origen WHERE id=? AND tipo_origen='KUPOS' FOR UPDATE",'i',[$id]);
            if (!$current) throw new InvalidArgumentException('La oficina de Kupos no existe.');
            if ((int)($current['sede_id'] ?? 0)!==(int)($_POST['sede_esperada'] ?? -1)) throw new InvalidArgumentException('La equivalencia cambió. Recarga la página antes de guardar.');
            if ((int)($current['sede_id'] ?? 0)!==$sede) enc_execute($conn,'UPDATE tb_enc_oficinas_origen SET sede_id=?,actualizado_por=?,actualizado_en=? WHERE id=?','iisi',[$sede ?: null,enc_user_id(),enc_inventory_now(),$id])->close();
            $conn->commit();
            if ($jsonPost) enc_offices_json(true,'Equivalencia guardada.',['office'=>['id'=>$id,'sede_id'=>$sede,'sede_nombre'=>$selectedSede['clm_sedes_name'] ?? '', 'nombre'=>$current['nombre']]]);
            header('Location: encomiendas_oficinas.php?'.http_build_query(['guardado'=>1,'buscar'=>$query,'estado'=>$state,'pagina'=>$page,'tamano'=>$size]),true,303);exit;
        } catch (Throwable $e) {$conn->rollback();throw $e;}
    }
    if ($ready) {
        $sedes=enc_fetch_sedes($conn);
        $rows=enc_fetch_all($conn,"SELECT a.*,s.clm_sedes_name sede_nombre FROM tb_enc_oficinas_origen a
            LEFT JOIN tb_sedes s ON s.clm_sedes_id=a.sede_id WHERE a.tipo_origen='KUPOS'
            ORDER BY a.sede_id IS NOT NULL,a.nombre,a.id");
        $known=[];foreach ($rows as $row) $known[$row['clave']]=true;
        foreach (enc_kupos_preview_offices() as $key=>$name) {
            if (isset($known[$key])) continue;
            $rows[]=['id'=>0,'clave'=>$key,'nombre'=>$name,'sede_id'=>null,'sede_nombre'=>null,'preview_only'=>true];$previewCount++;
        }
        usort($rows,static function($a,$b) {
            $linked=((int)($a['sede_id'] ?? 0)>0)<=>((int)($b['sede_id'] ?? 0)>0);
            return $linked ?: strnatcasecmp($a['nombre'],$b['nombre']);
        });
    }
} catch (InvalidArgumentException $e) {http_response_code(409);$error=$e->getMessage();}
catch (Throwable $e) {enc_log($e);$error='No se pudieron consultar o guardar las equivalencias.';$errorStatus=500;http_response_code(500);}
if ($jsonPost) enc_offices_json(false,$error ?: 'La actualización del inventario está pendiente de instalación.',[],$errorStatus);
$linked=count(array_filter($rows,static fn($row)=>(int)($row['sede_id'] ?? 0)>0));
n360_admin_render_head('Oficinas de Encomiendas');
?><link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/admin_encomiendas_oficinas_n360.css')) ?>?v=1"><?php
n360_render_header(['title'=>'Oficinas de Encomiendas','subtitle'=>'Administración']);n360_render_sidebar();
?>
<main class="main-content n360-main n360-main--module n360-main--compact-access"><div class="n360-main__inner admin-cat-shell enc-offices-page" data-enc-offices data-initial-page="<?= $page ?>">
<?php n360_render_content_separator('top'); ?>
<section class="admin-cat-hero"><div><span class="admin-cat-kicker"><i class="bi bi-buildings" aria-hidden="true"></i> Administración - Encomiendas</span><h1>Oficinas de Encomiendas</h1><p>Vincula las oficinas de Kupos con las sedes de Norte360 para consultar tus envíos por sede.</p></div><div class="enc-offices-hero-actions"><a href="../13_3ncomiendas/registro.php?modo=kupos"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Importar encomiendas</a><a href="encomiendas_oficinas.php" data-office-refresh><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar oficinas</a><a href="../13_3ncomiendas/inventario.php"><i class="bi bi-box-seam" aria-hidden="true"></i> Ver inventario</a></div></section>
<?php if (!$ready): ?><div class="enc-offices-notice enc-offices-notice--warning" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i> La actualización del inventario está pendiente de instalación.</div><?php endif; ?>
<?php if ($error): ?><div class="enc-offices-notice enc-offices-notice--error" role="alert"><?= enc_h($error) ?></div><?php endif; ?>
<div class="enc-offices-notice enc-offices-notice--success" data-offices-feedback role="status" aria-live="polite" <?= !isset($_GET['guardado'])?'hidden':'' ?>><i class="bi bi-check-circle" aria-hidden="true"></i><span data-offices-feedback-text>Equivalencia guardada.</span></div>
<section class="enc-offices-stats" aria-label="Resumen de equivalencias">
<?php foreach (['total'=>[count($rows),'Oficinas en Kupos','bi-buildings'],'pending'=>[count($rows)-$linked,'Pendientes de vincular','bi-link-45deg'],'linked'=>[$linked,'Vinculadas a una sede','bi-check2-circle']] as $key=>$stat): ?><article class="enc-offices-stat enc-offices-stat--<?= $key ?>"><i class="bi <?= $stat[2] ?>" aria-hidden="true"></i><div><span><?= enc_h($stat[1]) ?></span><strong data-office-count="<?= $key ?>"><?= $stat[0] ?></strong></div></article><?php endforeach; ?>
</section>
<?php if ($previewCount): ?><aside class="enc-offices-help" role="status"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i><div><strong><?= $previewCount ?> <?= $previewCount===1?'oficina nueva detectada':'oficinas nuevas detectadas' ?> en el CSV actual</strong><p>Puedes vincularlas antes de importar. La equivalencia se registra al pulsar Guardar; las encomiendas todavía no se importan.</p></div></aside><?php endif; ?>
<section class="admin-cat-panel"><div class="admin-cat-panel__head"><div><h2>Equivalencias de oficinas</h2><p>Selecciona la sede correspondiente y guarda cada oficina.</p></div><span class="enc-offices-panel-tag"><i class="bi bi-shield-check" aria-hidden="true"></i> Administración</span></div>
<div class="enc-offices-toolbar"><form method="get" class="enc-offices-search" role="search" data-office-search-form><label for="encOfficeQuery">Buscar oficina o sede</label><div><span class="enc-offices-search-input"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="encOfficeQuery" name="buscar" value="<?= enc_h($query) ?>" maxlength="160" placeholder="Ej. Villamaria, Bruces, Lima..." data-office-search></span><button type="submit">Buscar</button><button type="button" class="enc-offices-clear" data-office-clear>Limpiar</button></div></form>
<label class="enc-offices-size">Mostrar<select name="tamano" data-office-size><?php foreach (['10'=>'10 oficinas','25'=>'25 oficinas','50'=>'50 oficinas','todos'=>'Todas'] as $value=>$label): ?><option value="<?= $value ?>" <?= $size===(string)$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label></div>
<div class="enc-offices-filter-row"><nav class="enc-offices-filters" aria-label="Estado de vinculación"><?php foreach (['todas'=>'Todas','pendientes'=>'Sin vincular','vinculadas'=>'Vinculadas'] as $value=>$label): ?><button type="button" data-office-filter="<?= $value ?>" aria-pressed="<?= $state===$value?'true':'false' ?>"><?= $label ?><span data-office-filter-count="<?= $value ?>"><?= $value==='todas'?count($rows):($value==='pendientes'?count($rows)-$linked:$linked) ?></span></button><?php endforeach; ?></nav><p data-office-range aria-live="polite"><?= count($rows) ?> oficinas</p></div>
<div class="admin-cat-table-wrap enc-offices-table-wrap" tabindex="0" role="region" aria-label="Oficinas de Kupos y sus sedes vinculadas"><table class="admin-cat-table enc-offices-table"><thead><tr><th scope="col">Oficina en Kupos</th><th scope="col">Vinculación actual</th><th scope="col">Sede Norte360</th></tr></thead><tbody>
<?php foreach ($rows as $row): $isLinked=(int)($row['sede_id'] ?? 0)>0; ?><tr data-office-row data-office-name="<?= enc_h($row['nombre']) ?>" data-office-linked="<?= $isLinked?'1':'0' ?>" data-office-sede="<?= enc_h($row['sede_nombre'] ?: '') ?>">
<td><div class="enc-offices-name"><span class="enc-offices-name-icon"><i class="bi bi-building" aria-hidden="true"></i></span><div><strong><?= enc_h($row['nombre']) ?></strong><small data-office-source-note><?= !empty($row['preview_only'])?'Detectada en el CSV actual':'Nombre en Kupos' ?></small></div></div></td>
<td><span class="enc-offices-status <?= $isLinked?'is-linked':'is-pending' ?>" data-office-status><i class="bi <?= $isLinked?'bi-check2-circle':'bi-link-45deg' ?>" aria-hidden="true"></i><span><?= $isLinked?'Vinculada':'Sin vincular' ?></span></span><small class="enc-offices-current" data-office-current><?= enc_h($row['sede_nombre'] ?: 'Pendiente de asignación') ?></small></td>
<td><form method="post" data-office-form><input type="hidden" name="csrf_token" value="<?= enc_h(n360_csrf_token('enc_oficinas')) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="clave_detectada" value="<?= enc_h($row['clave']) ?>"><input type="hidden" name="sede_esperada" value="<?= (int)($row['sede_id'] ?? 0) ?>">
<div class="enc-offices-assignment"><select name="sede_id" aria-label="Sede de Norte360 para <?= enc_h($row['nombre']) ?>"><option value="0">Seleccionar sede / Sin vincular</option><?php foreach ($sedes as $sede): ?><option value="<?= (int)$sede['id'] ?>" <?= (int)$row['sede_id']===(int)$sede['id']?'selected':'' ?>><?= enc_h($sede['nombre']) ?></option><?php endforeach; ?></select><button type="submit"><i class="bi bi-check2" aria-hidden="true"></i><span>Guardar</span></button></div><p class="enc-offices-row-message" data-office-message role="status" aria-live="polite"></p></form></td></tr><?php endforeach; ?>
<tr data-office-empty <?= $rows?'hidden':'' ?>><td colspan="3" class="admin-cat-empty"><i class="bi bi-building" aria-hidden="true"></i><strong><?= $rows?'No hay oficinas para estos filtros.':'Aún no hay oficinas de Kupos registradas.' ?></strong><span><?= $rows?'Prueba otro nombre o cambia el estado de vinculación.':'Valida un CSV y pulsa Actualizar oficinas para vincular sus nombres antes de importar.' ?></span></td></tr>
</tbody></table></div><footer class="enc-offices-footer"><p>Los nombres originales del reporte se conservan.</p><nav data-office-pages aria-label="Páginas de oficinas"></nav></footer></section>
<aside class="enc-offices-help"><i class="bi bi-info-circle" aria-hidden="true"></i><div><strong>Una oficina de Kupos, una sede de Norte360</strong><p>La vinculación habilita los filtros por sede. Dejar una oficina sin vincular conserva su nombre y permite importar sus envíos. Después de validar otro CSV, pulsa Actualizar oficinas para ver sus nombres nuevos.</p></div></aside>
</div></main><script src="<?= enc_h(n360_asset('assets/js/admin_encomiendas_oficinas_n360.js')) ?>?v=2"></script>
<?php n360_admin_render_close(); ?>
