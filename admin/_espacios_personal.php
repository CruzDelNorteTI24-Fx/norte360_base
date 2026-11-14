<?php
require_once __DIR__ . '/../01_almacen/movimiento_backend.php';

function n360_espper_schema(mysqli $conn): array {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (isset($cache[$key])) return $cache[$key];
    $tables = [];
    foreach (alm_fetch_all($conn, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tb_sedes_espacios_personal', 'tb_trabajador')") as $column) {
        $tables[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
    }
    $required = ['clm_espper_id', 'clm_espper_idespacio', 'clm_espper_turno', 'clm_espper_idtrabajador',
        'clm_espper_nombre', 'clm_espper_desde', 'clm_espper_hasta', 'clm_espper_creado_por', 'clm_espper_retirado_por'];
    $workers = $tables['tb_trabajador'] ?? [];
    return $cache[$key] = ['ready' => !array_diff($required, $tables['tb_sedes_espacios_personal'] ?? []),
        'rrhh' => !array_diff(['clm_tra_id', 'clm_tra_nombres'], $workers), 'worker_columns' => $workers];
}

function n360_espper_nombre_sql(mysqli $conn): string {
    $fields = [];
    $columns = n360_espper_schema($conn)['worker_columns'];
    foreach (['clm_tra_namesistem', 'clm_tra_nomandapellidos', 'clm_tra_nombres'] as $name) {
        if (in_array($name, $columns, true)) $fields[] = "NULLIF(TRIM(t.$name), '')";
    }
    $fields[] = "CONCAT('Trabajador #', t.clm_tra_id)";
    return 'COALESCE(' . implode(', ', $fields) . ')';
}

function n360_espper_texto($value, int $max): string {
    if (!is_string($value)) throw new InvalidArgumentException('Revisa el nombre de la persona.');
    $text = preg_replace('/[\s\x{FEFF}]+/u', ' ', alm_activos_texto($value));
    if ($text === null || $text === '' || alm_activos_longitud($text) > $max) {
        throw new InvalidArgumentException("Indica un nombre de hasta $max caracteres.");
    }
    return $text;
}

function n360_espper_espacio(mysqli $conn, int $id, bool $lock = false): array {
    if ($lock) {
        $parent = alm_fetch_one($conn, 'SELECT clm_sedeesp_idsede AS sede_id FROM tb_sedes_espacios WHERE clm_sedeesp_id = ?', 'i', [$id]);
        if (!$parent) throw new RuntimeException('El espacio seleccionado ya no existe.', 404);
        // Mismo orden de bloqueo que altas, pabellones y carga inicial: sede -> espacio.
        alm_fetch_one($conn, 'SELECT clm_sedes_id FROM tb_sedes WHERE clm_sedes_id = ? FOR UPDATE', 'i', [(int)$parent['sede_id']]);
    }
    $name = alm_espacios_nombre_sql($conn, 'e', true);
    $row = alm_fetch_one($conn, "SELECT e.clm_sedeesp_id AS id, e.clm_sedeesp_estado AS activo,
        CONCAT_WS(' - ', s.clm_sedes_name, $name) AS nombre
        FROM tb_sedes_espacios e JOIN tb_sedes s ON s.clm_sedes_id = e.clm_sedeesp_idsede
        WHERE e.clm_sedeesp_id = ?" . ($lock ? ' FOR UPDATE' : ''), 'i', [$id]);
    if (!$row) throw new RuntimeException('El espacio seleccionado ya no existe.', 404);
    $row['id'] = (int)$row['id']; $row['activo'] = (int)$row['activo'] === 1;
    return $row;
}

function n360_espper_catalogo_owner(): string {
    return hash('sha256', serialize([$_SESSION['usuario'] ?? null, $_SESSION['id_usuario'] ?? null,
        $_SESSION['web_id_usuario'] ?? null, $_SESSION['usuario_id'] ?? null,
        $_SESSION['web_rol'] ?? null, $_SESSION['sede_espacios_csrf'] ?? null]));
}

function n360_espper_catalogo_en_cache(): bool {
    $cache = $_SESSION['sede_espacios_personal_catalogo'] ?? null;
    return is_array($cache) && ($cache['version'] ?? null) === 1
        && ($cache['owner'] ?? '') === n360_espper_catalogo_owner();
}

function n360_espper_busqueda_normalizar(string $value): string {
    $value = strtr($value, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c']);
    $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function n360_espper_catalogo_cargar(mysqli $conn): void {
    // Solo se renueva al entrar, actualizar o guardar. El DNI permanece en la sesión del servidor.
    unset($_SESSION['sede_espacios_personal_catalogo']);
    $schema = n360_espper_schema($conn);
    $cache = ['version' => 1, 'owner' => n360_espper_catalogo_owner(), 'rrhh' => $schema['rrhh'], 'rows' => []];
    if ($schema['rrhh']) {
        $columns = array_values(array_intersect(['clm_tra_namesistem', 'clm_tra_nomandapellidos', 'clm_tra_nombres', 'clm_tra_dni'], $schema['worker_columns']));
        $fields = implode(', ', array_map(static fn(string $column): string => "t.$column", $columns));
        $rows = alm_fetch_all($conn, 'SELECT t.clm_tra_id AS id, ' . n360_espper_nombre_sql($conn) . " AS nombre,
            COALESCE(NULLIF(TRIM(t.clm_tra_nombres), ''), '') AS nombre_completo, $fields
            FROM tb_trabajador t ORDER BY nombre, t.clm_tra_id");
        foreach ($rows as $row) {
            $search = [];
            foreach ($columns as $column) $search[] = n360_espper_busqueda_normalizar((string)($row[$column] ?? ''));
            $cache['rows'][] = ['id' => (int)$row['id'], 'nombre' => $row['nombre'],
                'nombre_completo' => $row['nombre_completo'], 'busqueda' => $search];
        }
    }
    $_SESSION['sede_espacios_personal_catalogo'] = $cache;
}

function n360_espper_workers(string $query): array {
    // Esta ruta no recibe conexión ni consulta la BD, tampoco cuando falta la caché.
    if (!n360_espper_catalogo_en_cache()) throw new RuntimeException('Actualiza Personal o recarga Espacios para cargar el catálogo de trabajadores.', 409);
    $cache = $_SESSION['sede_espacios_personal_catalogo'];
    if (!$cache['rrhh']) throw new RuntimeException('El catálogo de trabajadores aún no está disponible. Puedes usar un nombre libre.', 409);
    if (alm_activos_longitud($query) < 2) return ['rows' => [], 'more' => false];
    $needle = n360_espper_busqueda_normalizar($query);
    if ($needle === '') return ['rows' => [], 'more' => false];
    $rows = [];
    foreach ($cache['rows'] as $row) {
        foreach ($row['busqueda'] as $field) {
            if (strpos($field, $needle) === false) continue;
            $rows[] = ['id' => $row['id'], 'nombre' => $row['nombre'], 'nombre_completo' => $row['nombre_completo']];
            break;
        }
        if (count($rows) > 20) break;
    }
    return ['rows' => array_slice($rows, 0, 20), 'more' => count($rows) > 20];
}

function n360_espper_listar(mysqli $conn, int $id): array {
    $space = n360_espper_espacio($conn, $id);
    $rrhh = n360_espper_schema($conn)['rrhh'];
    $name = $rrhh ? "COALESCE(" . n360_espper_nombre_sql($conn) . ", p.clm_espper_nombre)" : 'p.clm_espper_nombre';
    $workerJoin = $rrhh ? 'LEFT JOIN tb_trabajador t ON t.clm_tra_id = p.clm_espper_idtrabajador' : '';
    $active = alm_fetch_all($conn, "SELECT p.clm_espper_id AS id, p.clm_espper_turno AS turno,
        p.clm_espper_idtrabajador AS trabajador_id, CASE WHEN p.clm_espper_idtrabajador IS NULL THEN p.clm_espper_nombre ELSE $name END AS nombre,
        p.clm_espper_desde AS desde
        FROM tb_sedes_espacios_personal p $workerJoin
        WHERE p.clm_espper_idespacio = ? AND p.clm_espper_hasta IS NULL ORDER BY p.clm_espper_turno, p.clm_espper_id", 'i', [$id]);
    foreach ($active as &$row) { $row['id'] = (int)$row['id']; $row['turno'] = (int)$row['turno']; $row['trabajador_id'] = $row['trabajador_id'] === null ? null : (int)$row['trabajador_id']; }
    $history = alm_fetch_all($conn, "SELECT p.clm_espper_id AS id, p.clm_espper_turno AS turno,
        p.clm_espper_nombre AS nombre, p.clm_espper_idtrabajador AS trabajador_id,
        p.clm_espper_desde AS desde, p.clm_espper_hasta AS hasta,
        COALESCE(NULLIF(TRIM(a.nombre), ''), a.usuario, 'Usuario anterior') AS asignado_por,
        COALESCE(NULLIF(TRIM(r.nombre), ''), r.usuario, 'Usuario anterior') AS retirado_por
        FROM tb_sedes_espacios_personal p
        LEFT JOIN tb_usuarios a ON a.id_usuario = p.clm_espper_creado_por
        LEFT JOIN tb_usuarios r ON r.id_usuario = p.clm_espper_retirado_por
        WHERE p.clm_espper_idespacio = ? AND p.clm_espper_hasta IS NOT NULL
        ORDER BY p.clm_espper_hasta DESC, p.clm_espper_id DESC LIMIT 50", 'i', [$id]);
    return ['space' => $space, 'rrhh' => $rrhh, 'rows' => $active, 'history' => $history];
}

function n360_espper_resumen(mysqli $conn, array $ids): array {
    if (!$ids || !n360_espper_schema($conn)['ready']) return [];
    $ids = implode(',', array_map('intval', $ids));
    $rows = alm_fetch_all($conn, "SELECT clm_espper_idespacio AS espacio_id, clm_espper_turno AS turno, COUNT(*) AS total
        FROM tb_sedes_espacios_personal WHERE clm_espper_idespacio IN ($ids) AND clm_espper_hasta IS NULL
        GROUP BY clm_espper_idespacio, clm_espper_turno");
    $summary = [];
    foreach ($rows as $row) $summary[(int)$row['espacio_id']][(int)$row['turno']] = (int)$row['total'];
    return $summary;
}

function n360_espper_guardar(mysqli $conn, array $payload, int $user): int {
    $spaceId = alm_activos_espacio_id($payload['espacio_id'] ?? null);
    if (!$spaceId || !in_array($payload['action'] ?? '', ['assign', 'retire'], true)) throw new InvalidArgumentException('Selecciona el espacio y la operación.');
    $conn->begin_transaction();
    try {
        $space = n360_espper_espacio($conn, $spaceId, true);
        $now = date('Y-m-d H:i:s');
        if ($payload['action'] === 'retire') {
            $id = alm_activos_espacio_id($payload['id'] ?? null);
            if (!$id) throw new InvalidArgumentException('Selecciona la asignación que deseas retirar.');
            $row = alm_fetch_one($conn, 'SELECT clm_espper_hasta AS hasta FROM tb_sedes_espacios_personal WHERE clm_espper_id = ? AND clm_espper_idespacio = ? FOR UPDATE', 'ii', [$id, $spaceId]);
            if (!$row) throw new RuntimeException('La asignación no pertenece a este espacio.', 404);
            if ($row['hasta'] !== null) throw new RuntimeException('Esta asignación ya fue retirada. Actualiza la lista.', 409);
            $stmt = $conn->prepare('UPDATE tb_sedes_espacios_personal SET clm_espper_hasta = ?, clm_espper_retirado_por = ? WHERE clm_espper_id = ?');
            $params = [$now, $user, $id]; alm_bind($stmt, 'sii', $params);
        } else {
            if (!$space['activo']) throw new RuntimeException('Este espacio está inactivo. Puedes consultar o retirar su personal, pero no agregar asignaciones.', 409);
            $turn = filter_var($payload['turno'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($turn, [1, 2], true)) throw new InvalidArgumentException('Selecciona Turno 1 o Turno 2.');
            $source = $payload['origen'] ?? '';
            $worker = null;
            if ($source === 'RRHH') {
                if (!n360_espper_schema($conn)['rrhh']) throw new RuntimeException('El catálogo de trabajadores aún no está disponible.', 409);
                $worker = alm_activos_espacio_id($payload['trabajador_id'] ?? null);
                if (!$worker || !is_string($payload['nombre'] ?? '') || trim($payload['nombre'] ?? '') !== '') throw new InvalidArgumentException('Selecciona un trabajador de RRHH.');
                $row = alm_fetch_one($conn, 'SELECT ' . n360_espper_nombre_sql($conn) . ' AS nombre FROM tb_trabajador t WHERE t.clm_tra_id = ? FOR UPDATE', 'i', [$worker]);
                if (!$row) throw new RuntimeException('El trabajador seleccionado ya no existe.', 404);
                $name = n360_espper_texto($row['nombre'], 255);
                $duplicate = alm_fetch_one($conn, 'SELECT clm_espper_id FROM tb_sedes_espacios_personal WHERE clm_espper_idespacio = ? AND clm_espper_turno = ? AND clm_espper_idtrabajador = ? AND clm_espper_hasta IS NULL LIMIT 1 FOR UPDATE', 'iii', [$spaceId, $turn, $worker]);
            } elseif ($source === 'LIBRE') {
                if (alm_activos_espacio_id($payload['trabajador_id'] ?? null) !== null) throw new InvalidArgumentException('Usa nombre libre o trabajador de RRHH en cada asignación.');
                $name = n360_espper_texto($payload['nombre'] ?? '', 100);
                $duplicate = alm_fetch_one($conn, 'SELECT clm_espper_id FROM tb_sedes_espacios_personal WHERE clm_espper_idespacio = ? AND clm_espper_turno = ? AND clm_espper_idtrabajador IS NULL AND clm_espper_nombre = ? AND clm_espper_hasta IS NULL LIMIT 1 FOR UPDATE', 'iis', [$spaceId, $turn, $name]);
            } else throw new InvalidArgumentException('Selecciona RRHH o nombre libre.');
            if ($duplicate) throw new RuntimeException('Esta persona ya está asignada a ese turno del espacio.', 409);
            $stmt = $conn->prepare('INSERT INTO tb_sedes_espacios_personal (clm_espper_idespacio, clm_espper_turno, clm_espper_idtrabajador, clm_espper_nombre, clm_espper_desde, clm_espper_creado_por) VALUES (?, ?, ?, ?, ?, ?)');
            $params = [$spaceId, $turn, $worker, $name, $now, $user]; alm_bind($stmt, 'iiissi', $params);
        }
        if (!$stmt->execute()) throw new RuntimeException('No se pudo guardar la asignación.');
        if ($payload['action'] === 'assign') $id = (int)$conn->insert_id;
        $stmt->close(); $conn->commit();
        return $id;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
