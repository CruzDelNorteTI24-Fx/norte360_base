<?php
define('N360_ENCOMIENDAS',true);
require_once __DIR__.'/../13_3ncomiendas/includes/encomiendas_inventario.php';
n360_start_secure_session();
if (!isset($_SESSION['usuario'])) { header('Location: ../login/login.php');exit; }
if (!enc_is_admin()) { http_response_code(403);exit('Acceso reservado a Administración.'); }
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','HEAD','POST'],true)) { http_response_code(405);exit('Método no permitido.'); }
if ($method==='POST' && !n360_verify_csrf((string)($_POST['csrf_token'] ?? ''),'enc_oficinas')) { http_response_code(419);exit('Token de seguridad no válido.'); }
define('N360_ADMIN_CATALOG',true);
require_once __DIR__.'/_admin_catalogos.php';
$conn->set_charset('utf8mb4');
$error='';$rows=[];$sedes=[];$ready=false;
$query=is_scalar($_GET['buscar'] ?? '') ? trim((string)($_GET['buscar'] ?? '')) : '';
try {
    $ready=enc_inventory_ready($conn);
    if ($ready && $method==='POST') {
        $id=max(0,(int)($_POST['id'] ?? 0));$sede=max(0,(int)($_POST['sede_id'] ?? 0));
        if ($sede && !enc_fetch_one($conn,'SELECT clm_sedes_id FROM tb_sedes WHERE clm_sedes_id=?','i',[$sede])) throw new InvalidArgumentException('La sede seleccionada no existe.');
        $conn->begin_transaction();
        try {
            $current=enc_fetch_one($conn,"SELECT * FROM tb_enc_oficinas_origen WHERE id=? AND tipo_origen='KUPOS' FOR UPDATE",'i',[$id]);
            if (!$current) throw new InvalidArgumentException('La oficina de origen no existe.');
            if ((int)($current['sede_id'] ?? 0)!==(int)($_POST['sede_esperada'] ?? -1)) throw new InvalidArgumentException('La equivalencia cambió. Recarga la página antes de guardar.');
            enc_execute($conn,'UPDATE tb_enc_oficinas_origen SET sede_id=?,actualizado_por=?,actualizado_en=? WHERE id=?','iisi',[$sede ?: null,enc_user_id(),enc_inventory_now(),$id])->close();
            $conn->commit();header('Location: encomiendas_oficinas.php?guardado=1');exit;
        } catch (Throwable $e) {$conn->rollback();throw $e;}
    }
    if ($ready) {
        $sedes=enc_fetch_sedes($conn);
        $rows=enc_fetch_all($conn,"SELECT a.*,s.clm_sedes_name sede_nombre FROM tb_enc_oficinas_origen a
            LEFT JOIN tb_sedes s ON s.clm_sedes_id=a.sede_id WHERE a.tipo_origen='KUPOS' AND a.nombre LIKE ?
            ORDER BY a.sede_id IS NOT NULL,a.nombre LIMIT 500",'s',['%'.substr($query,0,160).'%']);
    }
} catch (InvalidArgumentException $e) {http_response_code(409);$error=$e->getMessage();}
catch (Throwable $e) {enc_log($e);$error='No se pudieron consultar o guardar las equivalencias.';}
n360_admin_render_head('Oficinas de Encomiendas');
n360_render_header(['title'=>'Oficinas de Encomiendas','subtitle'=>'Administración']);n360_render_sidebar();
?>
<main class="main-content n360-main n360-main--module n360-main--compact-access"><div class="n360-main__inner admin-cat-shell">
<?php n360_render_content_separator('top'); ?>
<section class="admin-cat-hero"><div><span class="admin-cat-kicker">Administración - Encomiendas</span><h1>Oficinas de origen Kupos</h1><p>Relaciona los nombres del reporte con las sedes de Norte360. Los textos originales de los envíos se conservan.</p></div></section>
<?php if (!$ready): ?><div class="alert alert-warning">La actualización del inventario está pendiente de instalación.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= enc_h($error) ?></div><?php endif; ?>
<?php if (isset($_GET['guardado'])): ?><div class="alert alert-success">Equivalencia guardada.</div><?php endif; ?>
<section class="admin-cat-panel"><div class="admin-cat-panel__head"><div><h2>Equivalencias de oficinas</h2><p>Sin vincular conserva el nombre de Kupos sin asignar una sede por aproximación.</p></div></div>
<form method="get" class="p-3 d-flex gap-2"><input class="form-control" name="buscar" value="<?= enc_h($query) ?>" placeholder="Buscar oficina..."><button class="btn btn-primary" type="submit">Buscar</button></form>
<div class="admin-cat-table-wrap"><table class="admin-cat-table"><thead><tr><th>Oficina en Kupos</th><th>Sede Norte360</th><th>Vincular</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= enc_h($row['nombre']) ?></td><td><?= enc_h($row['sede_nombre'] ?: 'Sin vincular') ?></td><td>
<form method="post" class="d-flex flex-wrap gap-2"><input type="hidden" name="csrf_token" value="<?= enc_h(n360_csrf_token('enc_oficinas')) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="sede_esperada" value="<?= (int)($row['sede_id'] ?? 0) ?>">
<select class="form-select form-select-sm w-auto" name="sede_id" aria-label="Sede para <?= enc_h($row['nombre']) ?>"><option value="0">Sin vincular</option><?php foreach ($sedes as $sede): ?><option value="<?= (int)$sede['id'] ?>" <?= (int)$row['sede_id']===(int)$sede['id']?'selected':'' ?>><?= enc_h($sede['nombre']) ?></option><?php endforeach; ?></select>
<button class="btn btn-primary btn-sm" type="submit">Guardar</button></form></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3" class="admin-cat-empty">Las oficinas aparecen al confirmar la primera importación. Se muestran hasta 500 coincidencias; utiliza la búsqueda para acotar.</td></tr><?php endif; ?>
</tbody></table></div></section></div></main>
<?php n360_admin_render_close(); ?>
