<?php
// Contract reservations are independent of the daily bus assignment cleared by the cron.
function n360_contract_columns(): array {
    return [
        'clm_progbuses_escontrato', 'clm_progbuses_origen_libre', 'clm_progbuses_destino_libre',
        'clm_progbuses_contrato_salida', 'clm_progbuses_contrato_retorno',
        'clm_progbuses_contrato_idplaca', 'clm_progbuses_contrato_liberado',
        'clm_progbuses_contrato_liberado_fecha', 'clm_progbuses_contrato_liberado_usuario',
        'clm_progbuses_contrato_version',
    ];
}

function n360_contract_ready(mysqli $conn): bool {
    static $cache = [];
    $key = spl_object_id($conn);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $result = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
        ('tb_progbuses','tb_hist_progbuses','tb_progbuses_salida_consolidado')");
    if (!$result) return $cache[$key] = false;
    $found = [];
    $installed = false;
    while ($row = $result->fetch_assoc()) {
        $found[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
        if ($row['TABLE_NAME'] === 'tb_progbuses' && $row['COLUMN_NAME'] === 'clm_progbuses_contrato_version'
            && $row['COLUMN_COMMENT'] === 'n360_contracts_v1') $installed = true;
    }
    if (!$installed) return $cache[$key] = false;
    foreach (['tb_progbuses', 'tb_hist_progbuses'] as $table) {
        foreach (n360_contract_columns() as $column) if (empty($found[$table][$column])) return $cache[$key] = false;
    }
    if (empty($found['tb_progbuses']['clm_progbuses_contrato_reserva_key'])) return $cache[$key] = false;
    foreach (['clm_salprog_contrato_id', 'clm_salprog_contrato_tramo'] as $column) {
        if (empty($found['tb_progbuses_salida_consolidado'][$column])) return $cache[$key] = false;
    }
    return $cache[$key] = true;
}

function n360_contract_csrf(): string {
    if (empty($_SESSION['n360_contract_csrf'])) $_SESSION['n360_contract_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['n360_contract_csrf'];
}

function n360_contract_check_csrf(array $input): void {
    if (!hash_equals(n360_contract_csrf(), (string)($input['contrato_csrf'] ?? ''))) {
        throw new RuntimeException('La sesion del contrato vencio. Actualiza la pagina.', 403);
    }
}

function n360_contract_actor(): int {
    $id = (int)($_SESSION['id_usuario'] ?? $_SESSION['web_id_usuario'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Vuelve a iniciar sesion para gestionar contratos.', 403);
    return $id;
}

function n360_contract_query(mysqli $conn, string $sql, array $params = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo consultar el contrato.');
    if ($params) {
        $types = '';
        foreach ($params as $value) $types .= is_int($value) ? 'i' : 's';
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $duplicate = $stmt->errno === 1062;
        $stmt->close();
        throw new RuntimeException($duplicate
            ? 'La unidad ya tiene una reserva o este retorno ya fue registrado. Actualiza la pagina.'
            : 'No se pudo guardar el contrato.', $duplicate ? 409 : 500);
    }
    return $stmt;
}

function n360_contract_fetch(mysqli $conn, string $sql, array $params = []): array {
    $stmt = n360_contract_query($conn, $sql, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function n360_contract_datetime(string $value): string {
    $value = str_replace('T', ' ', trim($value));
    $format = strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('America/Lima'));
    if (!$date || $date->format($format) !== $value || (int)$date->format('Y') < 1000) {
        throw new RuntimeException('Indica una fecha y hora validas para el contrato.', 422);
    }
    return $date->format('Y-m-d H:i:s');
}

function n360_contract_operational_date(string $departure): string {
    $date = new DateTimeImmutable($departure, new DateTimeZone('America/Lima'));
    if ($date->format('H:i:s') < '05:00:00') $date = $date->modify('-1 day');
    return $date->format('Y-m-d');
}

function n360_contract_place(mysqli $conn, int $office, string $text): array {
    $text = trim($text);
    if ($office > 0 && $text !== '') throw new RuntimeException('Elige oficina o lugar personalizado, no ambos.', 422);
    if ($office > 0) {
        $rows = n360_contract_fetch($conn, 'SELECT COALESCE(NULLIF(clm_sedes_abr, \'\'), clm_sedes_name) AS label
            FROM tb_sedes WHERE clm_sedes_id = ? AND clm_sedes_estado = 1', [$office]);
        if (!$rows) throw new RuntimeException('La oficina seleccionada no existe o no esta activa.', 422);
        return ['id' => $office, 'text' => null, 'label' => $rows[0]['label']];
    }
    if (!preg_match('/^.{1,160}$/us', $text)) throw new RuntimeException('Escribe un lugar de 1 a 160 caracteres.', 422);
    return ['id' => null, 'text' => $text, 'label' => $text];
}

function n360_contract_route(array $origin, array $destination): void {
    $a = trim($origin['label']);
    $b = trim($destination['label']);
    $a = function_exists('mb_strtolower') ? mb_strtolower($a, 'UTF-8') : strtolower($a);
    $b = function_exists('mb_strtolower') ? mb_strtolower($b, 'UTF-8') : strtolower($b);
    if ($a === $b) throw new RuntimeException('El origen y destino no pueden ser iguales.', 422);
}

function n360_contract_lock_bus(mysqli $conn, int $bus): array {
    $rows = n360_contract_fetch($conn, "SELECT p.clm_placas_id, p.clm_placas_ESTADO,
        IFNULL(e.clm_pgbestado_estado,'SIN_HORARIO') AS estado
        FROM tb_placas p LEFT JOIN tb_progbuses_estado_actual e ON e.clm_pgbestado_idplaca=p.clm_placas_id
        WHERE p.clm_placas_id=? FOR UPDATE", [$bus]);
    if (!$rows || strtoupper(trim($rows[0]['clm_placas_ESTADO'])) !== 'ACTIVO') {
        throw new RuntimeException('La unidad no existe o no esta activa.', 422);
    }
    return $rows[0];
}

function n360_contract_guard_bus(mysqli $conn, int $bus, int $target, array $input, bool $newContract = false): string {
    if (!n360_contract_ready($conn)) return '';
    n360_contract_lock_bus($conn, $bus);
    $rows = n360_contract_fetch($conn, 'SELECT clm_progbuses_progid FROM tb_progbuses
        WHERE clm_progbuses_contrato_reserva_key=? FOR UPDATE', [$bus]);
    if (!$rows || (int)$rows[0]['clm_progbuses_progid'] === $target) return '';
    if ($newContract) throw new RuntimeException('La unidad ya esta reservada. Libera el contrato antes de crear otro.', 409);
    n360_contract_check_csrf($input);
    $attempts = $_SESSION['n360_contract_attempts'] ?? ['count' => 0, 'since' => time()];
    if (time() - $attempts['since'] > 300) $attempts = ['count' => 0, 'since' => time()];
    if ($attempts['count'] >= 5) throw new RuntimeException('Demasiados intentos. Espera cinco minutos.', 429);
    if (!password_verify((string)($input['contrato_autorizacion'] ?? ''), '$2y$10$qgGWoxj0D9xjXvdOSmPOP.C36DmK8O90ft.YIdU1qTwaNtCU7sAYm')) {
        $attempts['count']++;
        $_SESSION['n360_contract_attempts'] = $attempts;
        throw new RuntimeException('Unidad reservada por contrato. Se requiere autorizacion valida.', 409);
    }
    unset($_SESSION['n360_contract_attempts']);
    n360_contract_actor();
    $id = (int)$rows[0]['clm_progbuses_progid'];
    $reason = '[CONTRATO #' . $id . '] Reasignacion excepcional autorizada; reserva no liberada.';
    $stmt = n360_contract_query($conn, 'SET @motivo_hist_progbuses=?', [$reason]);
    $stmt->close();
    return $reason;
}

function n360_contract_regular_schedule(mysqli $conn, int $id): void {
    if (!n360_contract_ready($conn)) return;
    $rows = n360_contract_fetch($conn, 'SELECT clm_progbuses_escontrato FROM tb_progbuses WHERE clm_progbuses_progid=?', [$id]);
    if ($rows && (int)$rows[0]['clm_progbuses_escontrato'] === 1) {
        throw new RuntimeException('La salida por contrato no se reutiliza. Gestiona la reserva desde Contratos.', 409);
    }
}

function n360_contract_fields_sql(mysqli $conn, string $alias, bool $public = false): string {
    $columns = $public ? ['clm_progbuses_escontrato', 'clm_progbuses_contrato_salida', 'clm_progbuses_contrato_retorno'] : n360_contract_columns();
    return n360_contract_ready($conn) ? implode(', ', array_map(static fn($c) => "$alias.$c", $columns)) . ','
        : '0 AS clm_progbuses_escontrato,';
}

function n360_contract_place_sql(mysqli $conn, string $alias, string $office, string $side): string {
    $fallback = "IFNULL($office.clm_sedes_abr, '')";
    return n360_contract_ready($conn) ? "COALESCE(NULLIF($alias.clm_progbuses_{$side}_libre,''), NULLIF($office.clm_sedes_abr,''), '')" : $fallback;
}

function n360_contract_rows(mysqli $conn): array {
    if (!n360_contract_ready($conn)) return [];
    $origin = n360_contract_place_sql($conn, 'pb', 'o', 'origen');
    $destination = n360_contract_place_sql($conn, 'pb', 'd', 'destino');
    return n360_contract_fetch($conn, "SELECT pb.clm_progbuses_progid AS id,
        pb.clm_progbuses_contrato_idplaca AS bus_id, p.clm_placas_BUS AS bus, p.clm_placas_PLACA AS plate,
        p.clm_placas_ESTADO AS plate_state, $origin AS origin, $destination AS destination,
        pb.clm_progbuses_idoficina_origen AS origin_id, pb.clm_progbuses_idoficina_destino AS destination_id,
        pb.clm_progbuses_contrato_salida AS departure, pb.clm_progbuses_contrato_retorno AS return_expected,
        pb.clm_progbuses_contrato_liberado AS released, pb.clm_progbuses_contrato_liberado_fecha AS released_at,
        pb.clm_progbuses_contrato_version AS version, ida.clm_salprog_id AS outbound_id,
        vuelta.clm_salprog_id AS return_id,
        (pb.clm_progbuses_contrato_retorno < CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00')) AS expired
        FROM tb_progbuses pb
        LEFT JOIN tb_placas p ON p.clm_placas_id=pb.clm_progbuses_contrato_idplaca
        LEFT JOIN tb_sedes o ON o.clm_sedes_id=pb.clm_progbuses_idoficina_origen
        LEFT JOIN tb_sedes d ON d.clm_sedes_id=pb.clm_progbuses_idoficina_destino
        LEFT JOIN tb_progbuses_salida_consolidado ida ON ida.clm_salprog_contrato_id=pb.clm_progbuses_progid AND ida.clm_salprog_contrato_tramo='IDA'
        LEFT JOIN tb_progbuses_salida_consolidado vuelta ON vuelta.clm_salprog_contrato_id=pb.clm_progbuses_progid AND vuelta.clm_salprog_contrato_tramo='RETORNO'
        WHERE pb.clm_progbuses_escontrato=1 AND (pb.clm_progbuses_contrato_liberado=0 OR vuelta.clm_salprog_id IS NULL)
        ORDER BY pb.clm_progbuses_contrato_liberado, pb.clm_progbuses_contrato_salida DESC");
}

function n360_contract_create(mysqli $conn, array $input, int $uid): int {
    if (!n360_contract_ready($conn)) throw new RuntimeException('Instala primero la query de contratos de Flota.', 409);
    n360_contract_check_csrf($input);
    n360_contract_actor();
    $bus = (int)($input['contrato_bus'] ?? 0);
    $busRow = n360_contract_lock_bus($conn, $bus);
    if ($busRow['estado'] === 'TALLER') throw new RuntimeException('La unidad esta en taller.', 409);
    n360_contract_guard_bus($conn, $bus, 0, $input, true);
    $origin = n360_contract_place($conn, (int)($input['idof_origen'] ?? 0), (string)($input['origen_libre'] ?? ''));
    $destination = n360_contract_place($conn, (int)($input['idof_destino'] ?? 0), (string)($input['destino_libre'] ?? ''));
    n360_contract_route($origin, $destination);
    $departure = n360_contract_datetime((string)($input['contrato_salida'] ?? ''));
    $end = n360_contract_datetime((string)($input['contrato_retorno'] ?? ''));
    if ($end < $departure) throw new RuntimeException('El retorno previsto no puede ser anterior a la salida.', 422);
    $closed = n360_contract_fetch($conn, 'SELECT clm_cierre_id FROM tb_progbuses_cierre_operativo WHERE clm_cierre_fecha=?', [n360_contract_operational_date($departure)]);
    if ($closed) throw new RuntimeException('Ese dia operativo ya fue cerrado. No crees un contrato con salida en un cierre pasado.', 409);
    $route = horario_normalizar_ruta($input['ruta'] ?? '');
    $routeIds = $route ? array_map('intval', explode(',', $route)) : [];
    foreach ([$origin['id'], $destination['id']] as $office) {
        if ($office && in_array($office, $routeIds, true)) throw new RuntimeException('La ruta intermedia no puede repetir origen o destino.', 422);
    }
    $comment = trim((string)($input['comentario'] ?? ''));
    if (!preg_match('/^.{0,470}$/us', $comment)) throw new RuntimeException('El comentario admite hasta 470 caracteres.', 422);
    $comment = 'CONTRATO' . ($comment !== '' ? ' | ' . $comment : '');
    $stmt = n360_contract_query($conn, "INSERT INTO tb_progbuses
        (clm_progbuses_fechacreated,clm_progbuses_idplaca,clm_progbuses_idoficina_origen,clm_progbuses_idoficina_destino,
         clm_progbuses_ruta,clm_progbuses_horasalida,clm_progbuses_estado,clm_progbuses_idusuario,clm_progbuses_comentario,
         clm_progbuses_escontrato,clm_progbuses_origen_libre,clm_progbuses_destino_libre,clm_progbuses_contrato_salida,
         clm_progbuses_contrato_retorno,clm_progbuses_contrato_idplaca)
        VALUES (CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00'),?,?,?,?,?,1,?,?,1,?,?,?,?,?)",
        [$bus,$origin['id'],$destination['id'],$route,substr($departure,11),$uid,$comment,
         $origin['text'],$destination['text'],$departure,$end,$bus]);
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function n360_contract_change(mysqli $conn, string $action, array $input, bool $admin, int $uid): void {
    n360_contract_check_csrf($input);
    n360_contract_actor();
    if (!n360_contract_ready($conn)) throw new RuntimeException('Instala la query de contratos.', 409);
    $id = (int)($input['id'] ?? 0);
    $rows = n360_contract_fetch($conn, 'SELECT clm_progbuses_contrato_idplaca AS bus FROM tb_progbuses WHERE clm_progbuses_progid=? AND clm_progbuses_escontrato=1', [$id]);
    if (!$rows) throw new RuntimeException('El contrato no existe.', 404);
    // Serialize reservation changes with assignment and manual-return transactions.
    n360_contract_fetch($conn, 'SELECT clm_placas_id FROM tb_placas WHERE clm_placas_id=? FOR UPDATE', [(int)$rows[0]['bus']]);
    $row = n360_contract_fetch($conn, 'SELECT * FROM tb_progbuses WHERE clm_progbuses_progid=? FOR UPDATE', [$id])[0];
    if ((int)($input['version'] ?? -1) !== (int)$row['clm_progbuses_contrato_version']) throw new RuntimeException('Otro usuario modifico el contrato. Actualiza la lista.', 409);
    if ((int)$row['clm_progbuses_contrato_liberado'] === 1) throw new RuntimeException('El contrato ya esta liberado.', 409);
    if ($action === 'release') {
        $sql = "UPDATE tb_progbuses SET clm_progbuses_contrato_liberado=1,
            clm_progbuses_contrato_liberado_fecha=CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00'),
            clm_progbuses_contrato_liberado_usuario=?,clm_progbuses_idusuario=?,
            clm_progbuses_contrato_version=clm_progbuses_contrato_version+1,
            clm_progbuses_motivo='Liberacion manual de reserva por contrato' WHERE clm_progbuses_progid=?";
        $params = [$uid,$uid,$id];
    } elseif ($action === 'end') {
        if (!$admin) throw new RuntimeException('Solo administradores pueden modificar el retorno previsto.', 403);
        $end = n360_contract_datetime((string)($input['return_expected'] ?? ''));
        if ($end < $row['clm_progbuses_contrato_salida']) throw new RuntimeException('El retorno no puede ser anterior a la salida.', 422);
        if ($end === $row['clm_progbuses_contrato_retorno']) return;
        $sql = "UPDATE tb_progbuses SET clm_progbuses_contrato_retorno=?,clm_progbuses_idusuario=?,
            clm_progbuses_contrato_version=clm_progbuses_contrato_version+1,
            clm_progbuses_motivo=? WHERE clm_progbuses_progid=?";
        $reason = 'Retorno previsto del contrato: ' . $row['clm_progbuses_contrato_retorno'] . ' -> ' . $end;
        $params = [$end,$uid,$reason,$id];
    } else throw new RuntimeException('Accion de contrato invalida.', 422);
    $stmt = n360_contract_query($conn, $sql, $params);
    $stmt->close();
}

function n360_contract_return(mysqli $conn, int $id, int $bus, string $realDeparture): array {
    if (!n360_contract_ready($conn)) throw new RuntimeException('Instala la query de contratos.', 409);
    n360_contract_lock_bus($conn, $bus);
    $rows = n360_contract_fetch($conn, 'SELECT * FROM tb_progbuses WHERE clm_progbuses_progid=? AND clm_progbuses_escontrato=1 FOR UPDATE', [$id]);
    if (!$rows || (int)$rows[0]['clm_progbuses_contrato_idplaca'] !== $bus) throw new RuntimeException('El contrato no corresponde a esa unidad.', 422);
    $row = $rows[0];
    if (!(int)$row['clm_progbuses_contrato_liberado']) throw new RuntimeException('Libera primero el contrato para registrar su retorno.', 409);
    if ($realDeparture < $row['clm_progbuses_contrato_salida']) throw new RuntimeException('El retorno no puede salir antes que el viaje de contrato.', 422);
    $trips = n360_contract_fetch($conn, 'SELECT clm_salprog_contrato_tramo AS tramo FROM tb_progbuses_salida_consolidado WHERE clm_salprog_contrato_id=?', [$id]);
    $legs = array_column($trips, 'tramo');
    if (!in_array('IDA', $legs, true)) throw new RuntimeException('La salida del contrato aun no fue capturada por el cierre.', 409);
    if (in_array('RETORNO', $legs, true)) throw new RuntimeException('Este contrato ya tiene retorno registrado.', 409);
    return $row;
}
