<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');
require_once __DIR__ . '/encomiendas_queries.php';
require_once __DIR__ . '/encomiendas_kupos_csv.php';
require_once __DIR__ . '/encomiendas_inventario_fields.php';

function enc_inventory_ready(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_id($conn);
    if (isset($cache[$key])) return $cache[$key];
    $row = enc_fetch_one($conn, "SELECT COUNT(*) total,
        MAX(CASE WHEN TABLE_NAME='tb_enc_manifiesto_revision_items' THEN TABLE_COMMENT END) marca FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
        ('tb_enc_manifiesto_revision_items','view_enc_inventario','tb_hist_enc_inventario','tb_enc_importaciones','tb_enc_importacion_detalle','tb_enc_oficinas_origen')");
    return $cache[$key] = (int)($row['total'] ?? 0) === 6 && ($row['marca'] ?? '')==='N360_ENC_INVENTARIO_ITEMS_V2';
}

function enc_inventory_now(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
}

function enc_kupos_dates_ready(mysqli $conn): bool {
    static $cache=[];$key=spl_object_id($conn);
    if (isset($cache[$key])) return $cache[$key];
    $row=enc_fetch_one($conn,"SELECT COUNT(*) total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND ((TABLE_NAME='tb_enc_manifiesto_revision_items' AND COLUMN_NAME IN ('clm_encrevitem_fecha_creacion_externa','clm_encrevitem_fecha_cobro_externa'))
          OR (TABLE_NAME='view_enc_inventario' AND COLUMN_NAME IN ('fecha_creacion_externa','fecha_cobro_externa')))");
    return $cache[$key]=(int)($row['total'] ?? 0)===4;
}

function enc_inventory_money($value): string {
    if ($value === null || $value === '') return '-';
    $value = enc_inventory_decimal($value);
    list($integer, $fraction) = explode('.', $value);
    $fraction = rtrim($fraction, '0');
    $fraction = str_pad($fraction, 2, '0');
    return 'S/ ' . preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer) . '.' . $fraction;
}

function enc_inventory_data(array $row): array {
    $data = [];
    foreach (enc_kupos_columns() as $field => $label) {
        $value = $row[$field] ?? null;
        if ($field === 'total') $value = enc_inventory_decimal($value);
        elseif ($field === 'articulos') $value = $value !== null ? (int)$value : null;
        elseif ($value !== null) $value = (string)$value;
        $data[$field] = $value;
    }
    $data['sucursal'] = $row['sucursal'] ?? null;
    return $data;
}

function enc_inventory_snapshot(array $row): array {
    $data = enc_inventory_data($row);
    foreach (['tipo_origen','revision_estado','revision_observacion','referencia_envio','guia_remision'] as $field) {
        $data[$field] = $row[$field] ?? null;
    }
    $data['peso'] = enc_inventory_decimal($row['peso'] ?? null);
    $data['importe_cobrado'] = enc_inventory_decimal($row['importe_cobrado'] ?? null);
    $data['activo'] = (int)($row['activo'] ?? 1);
    $data['item_activo'] = (int)($row['item_activo'] ?? $row['activo'] ?? 1);
    $data['legacy_documento_id'] = isset($row['legacy_documento_id']) ? (int)$row['legacy_documento_id'] : null;
    $data['legacy_guia_id'] = isset($row['legacy_guia_id']) ? (int)$row['legacy_guia_id'] : null;
    $data['legacy_hoja'] = isset($row['legacy_hoja']) ? (int)$row['legacy_hoja'] : null;
    $data['legacy_orden'] = isset($row['legacy_orden']) ? (int)$row['legacy_orden'] : null;
    return $data;
}

function enc_inventory_changes(array $before, array $after): array {
    $changes = [];
    foreach (array_unique(array_merge(array_keys($before),array_keys($after))) as $field) {
        if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
            $changes[$field] = ['antes'=>$before[$field] ?? null,'despues'=>$after[$field] ?? null];
        }
    }
    return $changes;
}

// Diferenciar datos no informados de un cambio explícito del proveedor.
function enc_kupos_effective_data(array $reported,?array $old): array {
    $data=enc_inventory_data($reported);
    if ($old) {
        foreach (enc_kupos_date_columns() as $field) {
            if (!array_key_exists($field,$reported)) $data[$field]=$old[$field] ?? null;
        }
        if (($reported['sucursal'] ?? null)===null) $data['sucursal']=$old['sucursal'] ?? null;
    }
    return $data;
}

function enc_inventory_insert_many(mysqli $conn, string $table, array $rows, string $suffix = ''): void {
    if (!$rows) return;
    if (!in_array($table, ['tb_enc_manifiesto_revision_items','tb_hist_enc_inventario','tb_enc_importaciones','tb_enc_importacion_detalle','tb_enc_oficinas_origen'],true)) {
        throw new LogicException('Tabla no permitida.');
    }
    $physical=$table==='tb_enc_manifiesto_revision_items';
    $fields = array_keys($physical?enc_inventory_physical_values($rows[0]):$rows[0]);
    foreach ($fields as $field) if (!preg_match('/^[a-z_]+$/D',$field)) throw new LogicException('Campo no permitido.');
    $pattern = '(' . implode(',',array_fill(0,count($fields),'?')) . ')';
    for ($offset=0,$length=count($rows);$offset<$length;$offset+=100) {
        $chunk=array_slice($rows,$offset,100);
        if ($physical) $chunk=array_map('enc_inventory_physical_values',$chunk);
        $params = [];
        foreach ($chunk as $row) {
            if (array_keys($row) !== $fields) throw new LogicException('Columnas inconsistentes.');
            foreach ($row as $value) $params[] = $value;
        }
        $stmt = enc_execute($conn, 'INSERT INTO ' . $table . ' (`' . implode('`,`',$fields) . '`) VALUES '
            . implode(',',array_fill(0,count($chunk),$pattern)) . $suffix, str_repeat('s',count($params)), $params);
        $stmt->close();
    }
}

function enc_inventory_event(mysqli $conn, int $id, string $action, ?array $before, array $after, int $userId, ?int $loteId = null): void {
    enc_inventory_insert_many($conn,'tb_hist_enc_inventario',[[
        'inventario_id'=>$id,'accion'=>$action,'usuario_id'=>$userId ?: null,'lote_id'=>$loteId,
        'fecha'=>enc_inventory_now(),'datos_antes'=>$before !== null ? enc_kupos_json($before) : null,
        'datos_despues'=>enc_kupos_json($after),
    ]]);
}

function enc_inventory_lookup(mysqli $conn, array $codes, bool $lock = false): array {
    $result = [];
    sort($codes,SORT_STRING);
    foreach (array_chunk($codes,200) as $chunk) {
        if (!$chunk) continue;
        $rows = enc_fetch_all($conn, "SELECT * FROM view_enc_inventario WHERE tipo_origen='KUPOS' AND codigo_envio IN ("
            . implode(',',array_fill(0,count($chunk),'?')) . ') ORDER BY codigo_envio' . ($lock ? ' FOR UPDATE' : ''),
            str_repeat('s',count($chunk)),$chunk);
        foreach ($rows as $row) $result[$row['codigo_envio']] = $row;
    }
    return $result;
}

function enc_kupos_cutoff(string $text, array $parsed): string {
    $text = str_replace('T',' ',trim($text));
    if (strlen($text) === 16) $text .= ':00';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text,new DateTimeZone('America/Lima'));
    if (!$date || $date->format('Y-m-d H:i:s') !== $text) throw new InvalidArgumentException('Indica la fecha y hora de corte del reporte.');
    if ($text > enc_inventory_now()) throw new InvalidArgumentException('La fecha de corte no puede estar en el futuro.');
    if (($parsed['observed_date_max'] ?? $parsed['fecha_hasta'] ?? '') > substr($text,0,10)) throw new InvalidArgumentException('La fecha de corte es anterior a una fecha de envío, creación o cobro del CSV.');
    return $text;
}

function enc_kupos_compare(mysqli $conn, array $parsed, string $cutoff, bool $lock = false): array {
    $codes = array_column(array_column($parsed['rows'],'data'),'codigo_envio');
    $existing = enc_inventory_lookup($conn,$codes,$lock);
    $known = enc_fetch_all($conn,"SELECT clave,sede_id FROM tb_enc_oficinas_origen WHERE tipo_origen='KUPOS'");
    $aliases = [];
    foreach ($known as $alias) $aliases[$alias['clave']] = $alias['sede_id'];
    $unmapped = []; $entries = []; $baseline = []; $errors = $parsed['errors'];
    $counts = ['NUEVO'=>0,'MODIFICADO'=>0,'SIN_CAMBIOS'=>0];
    foreach ($parsed['rows'] as $record) {
        $data = $record['data']; $code = $data['codigo_envio']; $old = $existing[$code] ?? null;
        $oldData = $old ? enc_inventory_data($old) : null;
        $changes = $oldData ? enc_inventory_changes($oldData,enc_kupos_effective_data($data,$old)) : [];
        $state = !$old ? 'NUEVO' : ($changes ? 'MODIFICADO' : 'SIN_CAMBIOS');
        $counts[$state]++;
        $baseline[$code] = $oldData ? enc_kupos_hash($oldData) : null;
        if ($changes && !empty($old['observado_hasta']) && $cutoff < $old['observado_hasta']) {
            $errors[] = ['fila'=>$record['fila'],'mensaje'=>'El reporte es anterior a la última observación de ' . $code . '.'];
        }
        foreach (['sucursal','oficina_destino'] as $field) {
            if ($data[$field] !== null) {
                $aliasKey = enc_kupos_key($data[$field]);
                if (empty($aliases[$aliasKey])) $unmapped[$aliasKey] = $data[$field];
            }
        }
        $entries[] = ['fila'=>$record['fila'],'data'=>$data,'estado'=>$state,'cambios'=>$changes];
    }
    $processed = enc_fetch_one($conn,'SELECT id,codigo_control,creado_en FROM tb_enc_importaciones WHERE contenido_hash=?','s',[$parsed['hash']]);
    return ['entries'=>$entries,'counts'=>$counts,'errors'=>array_slice($errors,0,50),
        'baseline'=>$baseline,'existing'=>$existing,'unmapped'=>array_values($unmapped),'processed'=>$processed];
}

function enc_kupos_preview_result(array $stage, array $comparison): array {
    return ['token'=>$stage['token'],'archivo'=>$stage['archivo'],'fecha_corte'=>$stage['fecha_corte'],
        'fecha_desde'=>$stage['parsed']['fecha_desde'],'fecha_hasta'=>$stage['parsed']['fecha_hasta'],
        'sucursales'=>$stage['parsed']['branches'],'sin_sucursal'=>$stage['parsed']['unassigned'] ?? 0,
        'sucursales_reportadas'=>$stage['parsed']['reported_branch_count'] ?? count($stage['parsed']['branches']),
        'warnings'=>$stage['parsed']['warnings'],
        'rows'=>$comparison['entries'],'counts'=>$comparison['counts'],'errors'=>$comparison['errors'],
        'sin_equivalencia'=>$comparison['unmapped'],'procesado'=>$comparison['processed']];
}

function enc_kupos_commit(mysqli $conn, array $stage, int $userId): array {
    if ($stage['parsed']['errors']) throw new InvalidArgumentException('Corrige los errores del CSV antes de importar.');
    $conn->begin_transaction();
    try {
        // Compartir el bloqueo/correlativo CE con los controles manuales, sin reiniciarlo.
        enc_execute($conn,"INSERT INTO tb_enc_correlativos(clm_enccorr_serie,clm_enccorr_ultimo) VALUES('CE',0)
            ON DUPLICATE KEY UPDATE clm_enccorr_serie=clm_enccorr_serie")->close();
        $counter = enc_fetch_one($conn,"SELECT clm_enccorr_ultimo FROM tb_enc_correlativos WHERE clm_enccorr_serie='CE' FOR UPDATE");
        $comparison = enc_kupos_compare($conn,$stage['parsed'],$stage['fecha_corte'],true);
        if ($comparison['processed']) {
            $conn->rollback();
            return ['duplicate'=>true,'lote'=>$comparison['processed'],'comparison'=>$comparison];
        }
        if ($comparison['errors']) { $conn->rollback(); return ['conflict'=>true,'comparison'=>$comparison]; }
        if (($stage['baseline'] ?? []) !== $comparison['baseline']) {
            $conn->rollback();
            return ['conflict'=>true,'comparison'=>$comparison];
        }
        $maximum = enc_fetch_one($conn,"SELECT COALESCE(MAX(clm_enc_correlativo),0) ultimo FROM tb_enc_guias WHERE clm_enc_serie='CE'");
        $number = max((int)$counter['clm_enccorr_ultimo'],(int)$maximum['ultimo']) + 1;
        enc_execute($conn,"UPDATE tb_enc_correlativos SET clm_enccorr_ultimo=? WHERE clm_enccorr_serie='CE'",'i',[$number])->close();
        $control = 'CE-' . str_pad((string)$number,6,'0',STR_PAD_LEFT);
        $now = enc_inventory_now(); $counts = $comparison['counts'];
        enc_inventory_insert_many($conn,'tb_enc_importaciones',[[
            'codigo_control'=>$control,'contenido_hash'=>$stage['parsed']['hash'],'archivo_nombre'=>$stage['archivo'],
            'fecha_corte'=>$stage['fecha_corte'],'fecha_desde'=>$stage['parsed']['fecha_desde'],
            'fecha_hasta'=>$stage['parsed']['fecha_hasta'],'sucursales'=>$stage['parsed']['reported_branch_count'] ?? count($stage['parsed']['branches']),
            'total_envios'=>count($comparison['entries']),'nuevos'=>$counts['NUEVO'],'modificados'=>$counts['MODIFICADO'],
            'sin_cambios'=>$counts['SIN_CAMBIOS'],'creado_por'=>$userId,'creado_en'=>$now,
        ]]);
        $loteId = (int)$conn->insert_id;
        $writes = []; $officeNames = []; $unchanged = [];
        $owned = array_merge(array_keys(enc_kupos_columns()),['sucursal','sucursal_clave','destino_clave','ultimo_lote_id','observado_hasta','actualizado_por','actualizado_en']);
        $assignments = array_map(static function ($f) { $c=enc_inventory_column($f);return '`'.$c.'`=VALUES(`'.$c.'`)'; },$owned);
        $upsert=' ON DUPLICATE KEY UPDATE '.implode(',',$assignments).',clm_encrevitem_version=clm_encrevitem_version+1';
        foreach ($comparison['entries'] as $entry) {
            $data = $entry['data'];
            foreach (['sucursal','oficina_destino'] as $field) {
                if ($data[$field] !== null) $officeNames[enc_kupos_key($data[$field])] = $data[$field];
            }
            if ($entry['estado'] === 'SIN_CAMBIOS') { $unchanged[] = (int)$comparison['existing'][$data['codigo_envio']]['id']; continue; }
            $data=enc_kupos_effective_data($data,$comparison['existing'][$data['codigo_envio']] ?? null);
            $writes[] = $data + ['tipo_origen'=>'KUPOS',
                'sucursal_clave'=>$data['sucursal'] !== null ? enc_kupos_key($data['sucursal']) : null,
                'destino_clave'=>$data['oficina_destino'] !== null ? enc_kupos_key($data['oficina_destino']) : null,
                'ultimo_lote_id'=>$loteId,'observado_hasta'=>$stage['fecha_corte'],
                'creado_por'=>$userId,'actualizado_por'=>$userId,'creado_en'=>$now,'actualizado_en'=>$now,
                'idrevision'=>null,'orden'=>1];
            if (count($writes)===100) {
                enc_inventory_insert_many($conn,'tb_enc_manifiesto_revision_items',$writes,$upsert);$writes=[];
            }
        }
        enc_inventory_insert_many($conn,'tb_enc_manifiesto_revision_items',$writes,$upsert);
        unset($writes);
        foreach (array_chunk($unchanged,200) as $ids) {
            $params = array_merge([$loteId,$stage['fecha_corte'],$stage['fecha_corte']],$ids);
            enc_execute($conn,'UPDATE tb_enc_manifiesto_revision_items SET clm_encrevitem_ultimo_lote_id=?,clm_encrevitem_observado_hasta=GREATEST(COALESCE(clm_encrevitem_observado_hasta,?),?) WHERE clm_encrevitem_id IN ('
                . implode(',',array_fill(0,count($ids),'?')) . ')','iss'.str_repeat('i',count($ids)),$params)->close();
        }
        // Acotar fotografías e historial en memoria; el lote sigue siendo atómico.
        for ($offset=0,$length=count($comparison['entries']);$offset<$length;$offset+=200) {
            $entries=array_slice($comparison['entries'],$offset,200);
            $current = enc_inventory_lookup($conn,array_column(array_column($entries,'data'),'codigo_envio'));
            $events = []; $details = [];
            foreach ($entries as $entry) {
                $code = $entry['data']['codigo_envio']; $row = $current[$code];
                if ($entry['estado'] !== 'SIN_CAMBIOS') {
                    $before = isset($comparison['existing'][$code]) ? enc_inventory_snapshot($comparison['existing'][$code]) : null;
                    $events[] = ['inventario_id'=>$row['id'],'accion'=>$before ? 'CAMBIO' : 'ALTA',
                        'usuario_id'=>$userId,'lote_id'=>$loteId,'fecha'=>$now,
                        'datos_antes'=>$before ? enc_kupos_json($before) : null,
                        'datos_despues'=>enc_kupos_json(enc_inventory_snapshot($row))];
                }
                $details[] = ['lote_id'=>$loteId,'inventario_id'=>$row['id'],'fila'=>$entry['fila'],
                    'accion'=>$entry['estado'],'sucursal'=>$entry['data']['sucursal'] ?? '',
                    'datos_json'=>enc_kupos_json($entry['data'])];
            }
            enc_inventory_insert_many($conn,'tb_hist_enc_inventario',$events);
            enc_inventory_insert_many($conn,'tb_enc_importacion_detalle',$details);
        }
        $aliases = [];
        foreach ($officeNames as $key => $name) $aliases[] = ['tipo_origen'=>'KUPOS','clave'=>$key,'nombre'=>$name,'actualizado_en'=>$now];
        enc_inventory_insert_many($conn,'tb_enc_oficinas_origen',$aliases,' ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)');
        $conn->commit();
        return ['lote'=>['id'=>$loteId,'codigo_control'=>$control],'counts'=>$counts];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function enc_inventory_require_api(string $view, bool $write = true): void {
    n360_start_secure_session(); n360_send_security_headers();
    date_default_timezone_set('America/Lima');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!isset($_SESSION['usuario']) || enc_user_id() <= 0) enc_json(false,'Sesión expirada.',[],401);
    if (!enc_can_module() || !enc_can_view($view)) enc_json(false,'No tienes permiso para esta operación.',[],403);
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method !== ($write ? 'POST' : 'GET')) enc_json(false,'Método no permitido.',[],405);
    if ($write) enc_verify_action_csrf();
}

function enc_kupos_stage(): array {
    $stage = $_SESSION['enc_kupos_stage'] ?? null;
    $token = (string)($_POST['token'] ?? '');
    if (!is_array($stage) || ($stage['format_version'] ?? 0)!==ENC_KUPOS_STAGE_VERSION || ($stage['user'] ?? 0) !== enc_user_id()
        || ($stage['expires'] ?? 0) < time() || !hash_equals((string)($stage['token'] ?? ''),$token)) {
        unset($_SESSION['enc_kupos_stage']);
        enc_json(false,'La previsualización venció. Selecciona nuevamente el archivo.',[],409);
    }
    return $stage;
}

// Catálogo temporal del CSV de esta sesión; leerlo no escribe en la BD.
function enc_kupos_preview_offices(): array {
    $stage=$_SESSION['enc_kupos_stage'] ?? null;
    if (!is_array($stage) || ($stage['format_version'] ?? 0)!==ENC_KUPOS_STAGE_VERSION
        || ($stage['user'] ?? 0)!==enc_user_id() || ($stage['expires'] ?? 0)<time()) return [];
    $names=[];
    foreach (($stage['parsed']['rows'] ?? []) as $record) {
        foreach (['sucursal','oficina_destino'] as $field) {
            $name=$record['data'][$field] ?? null;
            if (is_string($name) && $name!=='') $names[enc_kupos_key($name)]=$name;
        }
    }
    return $names;
}
