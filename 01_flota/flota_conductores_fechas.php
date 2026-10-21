<?php

function condt_fechas_ready(mysqli $db): bool {
    $result = $db->query("SELECT
        (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_progconductores_fechas') AS tabla,
        (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_hist_progconductores'
            AND COLUMN_NAME IN ('clm_hist_progconductores_tipo_evento','clm_hist_progconductores_fechas_antes','clm_hist_progconductores_fechas_despues')) AS campos");
    $row = $result->fetch_assoc();
    return (int)$row['tabla'] === 1 && (int)$row['campos'] === 3;
}

function condt_fechas_date($value): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        throw new InvalidArgumentException('Selecciona una fecha valida.', 422);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Selecciona una fecha valida.', 422);
    return $value;
}

function condt_fechas_normalize(array $data): array {
    $from = condt_fechas_date($data['trabajo_desde'] ?? null);
    $to = condt_fechas_date($data['trabajo_hasta'] ?? null);
    if (($from === null) !== ($to === null) || ($from !== null && $from > $to)) {
        throw new InvalidArgumentException('Completa el inicio y fin del trabajo en orden cronologico.', 422);
    }
    $periods = $data['descansos'] ?? [];
    if (!is_array($periods) || array_values($periods) !== $periods || count($periods) > 100) {
        throw new InvalidArgumentException('Puedes registrar hasta 100 periodos de descanso.', 422);
    }
    $valid = [];
    foreach ($periods as $period) {
        if (!is_array($period)) throw new InvalidArgumentException('El descanso no es valido.', 422);
        $start = condt_fechas_date($period['desde'] ?? null);
        $end = condt_fechas_date($period['hasta'] ?? null);
        if (!$start || !$end || $start > $end) throw new InvalidArgumentException('Completa cada descanso con fechas validas y ordenadas.', 422);
        $valid[] = ['desde' => $start, 'hasta' => $end];
    }
    usort($valid, static fn($a, $b) => [$a['desde'], $a['hasta']] <=> [$b['desde'], $b['hasta']]);
    $merged = [];
    foreach ($valid as $period) {
        $last = count($merged) - 1;
        // Treat the rest list as a union of calendar days, not overlapping permissions.
        $limit = $last >= 0 ? $merged[$last]['hasta'] : '';
        if ($last >= 0 && $limit !== '9999-12-31') $limit = (new DateTimeImmutable($limit, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        if ($last >= 0 && $period['desde'] <= $limit) {
            $merged[$last]['hasta'] = max($merged[$last]['hasta'], $period['hasta']);
        } else {
            $merged[] = $period;
        }
    }
    return ['trabajo_desde' => $from, 'trabajo_hasta' => $to, 'descansos' => $merged];
}

function condt_fechas_view(?array $row = null): array {
    $periods = json_decode((string)($row['clm_pcf_descansos_json'] ?? '[]'), true);
    if (!is_array($periods)) throw new RuntimeException('Las fechas guardadas no son validas. Revisa el historial antes de modificarlas.', 409);
    $data = condt_fechas_normalize([
        'trabajo_desde' => $row['clm_pcf_trabajo_desde'] ?? null,
        'trabajo_hasta' => $row['clm_pcf_trabajo_hasta'] ?? null,
        'descansos' => $periods,
    ]);
    $days = 0;
    foreach ($data['descansos'] as $period) {
        $start = new DateTimeImmutable($period['desde'], new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($period['hasta'], new DateTimeZone('UTC'));
        $days += (int)$start->diff($end)->days + 1;
    }
    return $data + ['dias_descanso' => $days, 'version' => (string)($row['clm_pcf_version'] ?? '0')];
}

function condt_fechas_map(mysqli $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if (!$ids) return [];
    $stmt = $db->prepare('SELECT * FROM tb_progconductores_fechas WHERE clm_pcf_idconductor IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $map = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $map[(int)$row['clm_pcf_idconductor']] = condt_fechas_view($row);
    $stmt->close();
    return $map;
}

function condt_fechas_snapshot(array $data): array {
    return array_intersect_key($data, array_flip(['trabajo_desde', 'trabajo_hasta', 'descansos']));
}

function condt_fechas_save(mysqli $db, int $id, array $input, string $expected, int $uid): array {
    if ($id <= 0 || $uid <= 0) throw new InvalidArgumentException('Conductor o usuario invalido.', 422);
    if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $expected)) throw new RuntimeException('Actualiza las fechas antes de guardar.', 409);
    if (!condt_fechas_ready($db)) throw new RuntimeException('Ejecuta la query de fechas de trabajo y descanso antes de guardar.', 409);
    $next = condt_fechas_normalize($input);
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("SELECT pc.* FROM tb_progconductores pc INNER JOIN tb_trabajador t ON t.clm_tra_id=pc.clm_progconductores_idconductor
            WHERE pc.clm_progconductores_idconductor=? AND pc.clm_progconductores_estado=1
              AND UPPER(TRIM(t.clm_tra_tipo_trabajador))='CONDUCTOR'
            ORDER BY pc.clm_progconductores_progid LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $id); $stmt->execute(); $context = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$context) throw new RuntimeException('El conductor ya no figura en la relacion. Actualiza la pagina.', 409);
        $stmt = $db->prepare("INSERT INTO tb_progconductores_fechas (clm_pcf_idconductor,clm_pcf_descansos_json) VALUES (?,'[]')
            ON DUPLICATE KEY UPDATE clm_pcf_idconductor=VALUES(clm_pcf_idconductor)");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        $stmt = $db->prepare('SELECT * FROM tb_progconductores_fechas WHERE clm_pcf_idconductor=? FOR UPDATE');
        $stmt->bind_param('i', $id); $stmt->execute(); $current = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $old = condt_fechas_view($current);
        if ($old['version'] !== $expected) throw new RuntimeException('Otro usuario modifico estas fechas. Cierra y actualiza la relacion antes de guardar.', 409);
        if (condt_fechas_snapshot($old) === $next) {
            $db->rollback();
            return $old + ['sin_cambios' => true];
        }
        $from = $next['trabajo_desde']; $to = $next['trabajo_hasta'];
        $json = json_encode($next['descansos'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare("UPDATE tb_progconductores_fechas SET clm_pcf_trabajo_desde=?,clm_pcf_trabajo_hasta=?,clm_pcf_descansos_json=?,
            clm_pcf_version=clm_pcf_version+1,clm_pcf_idusuario=?,clm_pcf_actualizado=CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00') WHERE clm_pcf_idconductor=?");
        $stmt->bind_param('sssii', $from, $to, $json, $uid, $id); $stmt->execute(); $stmt->close();
        $before = json_encode(condt_fechas_snapshot($old), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $after = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $progid = (int)$context['clm_progconductores_progid'];
        $created = $context['clm_progconductores_fechacreated'];
        $plate = $context['clm_progconductores_idplaca'];
        $type = (int)$context['clm_progconductores_tipoprog'];
        $state = (int)$context['clm_progconductores_estado'];
        $updated = $context['clm_progconductores_datetimeupdated'];
        $reason = 'Actualizacion informativa de fechas de trabajo y descanso.';
        $stmt = $db->prepare("INSERT INTO tb_hist_progconductores (
            clm_hist_progconductores_accion,clm_hist_progconductores_fechaevento,clm_progconductores_progid,clm_progconductores_fechacreated,
            clm_progconductores_idplaca,clm_progconductores_idconductor,clm_progconductores_tipoprog,clm_progconductores_estado,
            clm_progconductores_idusuario,clm_progconductores_motivo,clm_progconductores_datetimeupdated,
            clm_hist_progconductores_tipo_evento,clm_hist_progconductores_fechas_antes,clm_hist_progconductores_fechas_despues
            ) VALUES ('UPDATE',CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00'),?,?,?,?,?,?,?,?,?,'FECHAS_TRABAJO_DESCANSO',?,?)");
        $stmt->bind_param('isiiiiissss', $progid, $created, $plate, $id, $type, $state, $uid, $reason, $updated, $before, $after);
        $stmt->execute(); $stmt->close();
        $db->commit();
        return condt_fechas_view(['clm_pcf_trabajo_desde' => $from, 'clm_pcf_trabajo_hasta' => $to,
            'clm_pcf_descansos_json' => $json, 'clm_pcf_version' => (string)((int)$expected + 1)]);
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
