<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');
require_once __DIR__.'/encomiendas_inventario.php';

function enc_control_tracking_filters(): array {
    $f=enc_current_filters();
    $source=is_scalar($_GET['origen_registro'] ?? '')?(string)($_GET['origen_registro'] ?? ''):'';
    $f['origen_registro']=in_array($source,['KUPOS','VIAJE'],true)?$source:'';
    $perPage=is_scalar($_GET['por_pagina'] ?? '')?(int)($_GET['por_pagina'] ?? 25):25;
    $f['per_page']=in_array($perPage,[25,50,100],true)?$perPage:25;
    if ($f['desde']>$f['hasta']) [$f['desde'],$f['hasta']]=[$f['hasta'],$f['desde']];
    return $f;
}

function enc_control_tracking_query(mysqli $conn,array $f,bool $hasKupos): array {
    $legacy=enc_build_tracking_where($f);
    $text=static fn(string $expr): string => 'CONVERT('.$expr.' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    // Proyeccion comun, sin crear controles de viaje ficticios para los CSV.
    $travel=[
        'id'=>'g.clm_enc_id','tipo_control'=>"'VIAJE'",'codigo_control'=>'g.clm_enc_guia',
        'fecha_control'=>'g.clm_enc_fecha_guia','actualizado_en'=>'COALESCE(g.clm_enc_datetimeupdated,g.clm_enc_fechacreated)',
        'horario'=>'g.clm_enc_horario_operativo','hora'=>'g.clm_enc_hora_embarque_programada',
        'origen'=>'se.clm_sedes_name','destino'=>'sd.clm_sedes_name',
        'bus'=>'p.clm_placas_BUS','placa'=>'p.clm_placas_PLACA',
        'estado_general'=>'g.clm_enc_estado_general','embarque'=>'g.clm_enc_estado_embarque',
        'desembarque'=>'g.clm_enc_estado_desembarque','activo'=>'g.clm_enc_activo',
        'manifiestos_req'=>'COALESCE(pts.manifiestos_req,0)','manifiestos_ok'=>'COALESCE(docs.manifiestos_ok,0)',
        'transportista'=>'COALESCE(docs.guias_transportista_total,0)',
        'archivo'=>'NULL','sucursales'=>'NULL','envios'=>'NULL','nuevos'=>'NULL','modificados'=>'NULL',
        'sin_cambios'=>'NULL','fecha_corte'=>'NULL'
    ];
    $external=array_fill_keys(array_keys($travel),'NULL');
    $external=array_merge($external,[
        'id'=>'l.id','tipo_control'=>"'KUPOS'",'codigo_control'=>'l.codigo_control',
        'fecha_control'=>'DATE(l.creado_en)','actualizado_en'=>'l.creado_en','activo'=>'1',
        'archivo'=>'l.archivo_nombre','sucursales'=>'l.sucursales','envios'=>'l.total_envios',
        'nuevos'=>'l.nuevos','modificados'=>'l.modificados','sin_cambios'=>'l.sin_cambios','fecha_corte'=>'l.fecha_corte'
    ]);
    $textKeys=['tipo_control','codigo_control','horario','origen','destino','bus','placa','estado_general','embarque','desembarque','archivo'];
    $project=static function(array $values) use($text,$textKeys): string {
        $columns=[];
        foreach ($values as $key=>$expr) $columns[]=(in_array($key,$textKeys,true)?$text($expr):$expr).' AS '.$key;
        return implode(',',$columns);
    };
    $queries=[];$types='';$params=[];
    if ($f['origen_registro']!=='KUPOS') {
        $queries[]='SELECT '.$project($travel).enc_select_tracking_base($conn).' WHERE '.$legacy['where'];
        $types=$legacy['types'];$params=$legacy['params'];
    }
    if ($hasKupos && $f['origen_registro']!=='VIAJE') {
        $where=['1=1'];$kt='';$kp=[];
        if ($f['guia']!=='') {$where[]=enc_like_expr('l.codigo_control');$kt.='s';$kp[]=$f['guia'];}
        if ($f['fecha_guia']!=='') {$where[]='DATE(l.creado_en)=?';$kt.='s';$kp[]=$f['fecha_guia'];}
        else foreach (['desde'=>'>=','hasta'=>'<='] as $key=>$operator) {
            if ($f[$key]!=='') {$where[]='DATE(l.creado_en)'.$operator.'?';$kt.='s';$kp[]=$f[$key];}
        }
        if ($f['documento']!=='') {
            $where[]="EXISTS(SELECT 1 FROM tb_enc_importacion_detalle ld WHERE ld.lote_id=l.id AND (".
                enc_like_expr("JSON_UNQUOTE(JSON_EXTRACT(ld.datos_json,'$.doc_fiscal'))").' OR '.
                enc_like_expr("JSON_UNQUOTE(JSON_EXTRACT(ld.datos_json,'$.documento_fiscal'))").'))';
            $kt.='ss';$kp[]=$f['documento'];$kp[]=$f['documento'];
        }
        if ($f['buscar']!=='') {
            $where[]='('.enc_like_expr('l.codigo_control').' OR '.enc_like_expr('l.archivo_nombre').')';
            $kt.='ss';$kp[]=$f['buscar'];$kp[]=$f['buscar'];
        }
        // Un reporte puede abarcar varias oficinas: basta una coincidencia del lote.
        foreach (['idsede_embarque'=>'sucursal_clave','idsede_desembarque'=>'destino_clave'] as $key=>$column) {
            if ($f[$key]>0) {
                $where[]="EXISTS(SELECT 1 FROM tb_enc_importacion_detalle ld
                    JOIN view_enc_inventario vi ON vi.id=ld.inventario_id
                    JOIN tb_enc_oficinas_origen a ON a.tipo_origen='KUPOS' AND a.clave=vi.$column
                    WHERE ld.lote_id=l.id AND a.sede_id=?)";
                $kt.='i';$kp[]=$f[$key];
            }
        }
        // Embarque/unidad/estado operativo corresponden exclusivamente a viajes.
        if ($f['idplaca']>0 || $f['estado_embarque']!=='TODOS' || $f['estado_desembarque']!=='TODOS'
            || $f['estado_general']!=='TODOS' || !in_array($f['estado_vida'],['TODOS','ACTIVO'],true)) $where[]='1=0';
        $queries[]='SELECT '.$project($external).' FROM tb_enc_importaciones l WHERE '.implode(' AND ',$where);
        $types.=$kt;$params=array_merge($params,$kp);
    }
    if (!$queries) return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'kupos'=>0,'viajes'=>0];
    $union=implode(' UNION ALL ',$queries);
    $summary=enc_fetch_one($conn,"SELECT COUNT(*) total,COALESCE(SUM(tipo_control='KUPOS'),0) kupos,
        COALESCE(SUM(tipo_control='VIAJE'),0) viajes FROM ($union) controles",$types,$params);
    $total=(int)$summary['total'];$pages=max(1,(int)ceil($total/$f['per_page']));$page=min($f['page'],$pages);
    $rows=enc_fetch_all($conn,'SELECT * FROM ('.$union.') controles ORDER BY actualizado_en DESC,tipo_control,id DESC LIMIT ? OFFSET ?',
        $types.'ii',array_merge($params,[$f['per_page'],($page-1)*$f['per_page']]));
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'kupos'=>(int)$summary['kupos'],'viajes'=>(int)$summary['viajes']];
}
