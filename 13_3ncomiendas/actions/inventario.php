<?php
ob_start();
define('N360_ENCOMIENDAS',true);
require_once __DIR__ . '/../includes/encomiendas_inventario.php';
$write = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
enc_inventory_require_api('enc-tracking',$write);
$id = max(0,(int)($write ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0)));
if (!$id) enc_json(false,'Encomienda no válida.',[],422);
$conn = null; $status=200; $response=[];
try {
    $conn = enc_open_connection_json();
    if (!enc_inventory_ready($conn)) throw new InvalidArgumentException('La actualización del inventario está pendiente.');
    if (!$write) {
        $cursor=max(0,(int)($_GET['antes_de'] ?? 0));
        $row = enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=?','i',[$id]);
        if (!$row) { $status=404; $response=['ok'=>false,'message'=>'La encomienda no existe.']; }
        else {
            $events = enc_fetch_all($conn,"SELECT h.id,h.accion,h.fecha,h.datos_antes,h.datos_despues,
                COALESCE(NULLIF(u.usuario,''),NULLIF(u.nombre,''),'Usuario') usuario,l.codigo_control
                FROM tb_hist_enc_inventario h LEFT JOIN tb_usuarios u ON u.id_usuario=h.usuario_id
                LEFT JOIN tb_enc_importaciones l ON l.id=h.lote_id
                WHERE h.inventario_id=?".($cursor ? ' AND h.id<?' : '')." ORDER BY h.id DESC LIMIT 101",
                $cursor ? 'ii' : 'i',$cursor ? [$id,$cursor] : [$id]);
            $more = count($events)>100;
            $events = array_slice($events,0,100);
            foreach ($events as &$event) {
                $before = $event['datos_antes'] !== null ? json_decode($event['datos_antes'],true) : null;
                $after = json_decode($event['datos_despues'],true) ?: [];
                $event['importe_heredado']=($row['tipo_origen']!=='KUPOS' && !array_key_exists('importe_cobrado',$after));
                $event['cambios'] = enc_inventory_changes($before ?? [],$after);
                unset($event['datos_antes'],$event['datos_despues']);
            }
            unset($event);
            $response=['ok'=>true,'row'=>$row,'historial'=>$events,'hay_mas'=>$more,
                'siguiente'=>$more && $events ? (int)$events[count($events)-1]['id'] : null];
        }
    } else {
        $state = strtoupper(trim((string)($_POST['estado'] ?? '')));
        $observation = enc_kupos_text((string)($_POST['observacion'] ?? ''));
        if (!in_array($state,['PENDIENTE','OK','REZAGADO','OBSERVADO'],true) || enc_kupos_length($observation)>2000) {
            throw new InvalidArgumentException('Estado u observación no válidos.');
        }
        $conn->begin_transaction();
        try {
            $before = enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=? FOR UPDATE','i',[$id]);
            if (!$before) throw new InvalidArgumentException('La encomienda no existe.');
            if ($before['tipo_origen']!=='KUPOS') throw new InvalidArgumentException('Revisa esta encomienda desde su manifiesto para conservar la documentación vinculada.');
            if ((int)$before['version']!==(int)($_POST['version'] ?? 0)) {
                $conn->rollback(); $status=409; $response=['ok'=>false,'message'=>'La encomienda cambió. Actualiza la página antes de guardar.'];
            } else {
                $newObs = $observation !== '' ? $observation : null;
                if ($before['revision_estado']!==$state || $before['revision_observacion']!==$newObs) {
                    enc_inventory_update($conn,$id,['revision_estado'=>$state,'revision_observacion'=>$newObs,
                        'actualizado_por'=>enc_user_id(),'actualizado_en'=>enc_inventory_now()]);
                    $after = enc_fetch_one($conn,'SELECT * FROM view_enc_inventario WHERE id=?','i',[$id]);
                    enc_inventory_event($conn,$id,'REVISION',enc_inventory_snapshot($before),enc_inventory_snapshot($after),enc_user_id());
                }
                $conn->commit(); $response=['ok'=>true,'message'=>'Revisión guardada.'];
            }
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
} catch (InvalidArgumentException $e) { $status=422; $response=['ok'=>false,'message'=>$e->getMessage()]; }
catch (Throwable $e) { enc_log($e); $status=500; $response=['ok'=>false,'message'=>'No se pudo completar la operación del inventario.']; }
finally { if ($conn instanceof mysqli) $conn->close(); }
http_response_code($status);
echo json_encode($response,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
