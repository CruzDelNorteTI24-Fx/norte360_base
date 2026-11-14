<?php
// Propuesta revisable. No contiene IDs de trabajadores ni asigna etiquetas existentes.
function n360_espacios_propuesta(): array {
    $rows = [];
    $add = static function (int $sede, string $area, ?string $space, ?string $pavilion, string $classification = 'BIE', int $active = 1) use (&$rows): void {
        $rows[] = ['sede_id' => $sede, 'area' => $area, 'espacio' => $space, 'pabellon' => $pavilion, 'clasificacion' => $classification, 'estado' => $active];
    };
    $offices = 'Pabellón de oficinas'; $warehouse = 'Pabellón de almacén';
    $add(24, 'ALMACÉN PRINCIPAL', 'ALMACÉN PRINCIPAL', $warehouse, 'ALM');
    $add(24, 'ESTACIÓN 01', null, null, 'GF1'); $add(24, 'ESTACIÓN 02', null, null, 'GF2');
    for ($n = 1; $n <= 3; $n++) $add(24, 'ÁREA DE VENTAS', 'ESCRITORIO ' . $n, $offices);
    $add(24, 'ÁREA DE VENTAS', 'SALA DE VENTAS', $offices);
    $add(24, 'ÁREA DE ENCOMIENDAS', null, 'Pabellón de encomiendas');
    for ($n = 1; $n <= 4; $n++) $add(24, 'OFICINA MONITOREO', 'ESCRITORIO ' . $n, null);
    $add(24, 'OFICINA ENCOMIENDAS', 'ESCRITORIO 1', $offices);
    for ($n = 1; $n <= 5; $n++) $add(24, 'OFICINA RR.HH.', 'ESCRITORIO ' . $n, $offices);
    for ($n = 1; $n <= 9; $n++) $add(24, 'OFICINA CONTABILIDAD', 'ESCRITORIO ' . $n, $offices);
    $add(24, 'SALA DE REUNIONES', null, $offices);
    $add(24, 'ALMACÉN PRINCIPAL', 'ESCRITORIO 1', $warehouse);
    for ($n = 1; $n <= 6; $n++) $add(24, 'OFICINA ADMINISTRACIÓN', 'ESCRITORIO ' . $n, $offices);
    $add(26, 'OFICINA CALL CENTER', 'ESCRITORIO 1', null);
    foreach (['OFICINA MARKETING', 'OFICINA SRITA CAMILA', 'OFICINA TERCER PISO'] as $area) $add(24, $area, null, null, 'BIE', 0);
    return $rows;
}

function n360_espacios_key($value): string {
    return strtr(mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string)$value)), 'UTF-8'),
        ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
}

function n360_espacios_catalogo_estado(mysqli $conn, bool $lock = false): array {
    $sedes = alm_fetch_all($conn, 'SELECT clm_sedes_id AS id, clm_sedes_name AS nombre FROM tb_sedes
        WHERE clm_sedes_id IN (24, 26) ORDER BY clm_sedes_id' . ($lock ? ' FOR UPDATE' : ''));
    $names = array_column($sedes, 'nombre', 'id');
    foreach ([24 => 'VILLAMARIA', 26 => 'BRUCES'] as $id => $name) {
        if (!isset($names[$id]) || strpos(str_replace(' ', '', n360_espacios_key($names[$id])), $name) === false) {
            throw new RuntimeException('La sede esperada de Villamaría o Bruces no coincide. Revisa las sedes antes de cargar el catálogo.', 409);
        }
    }
    $classReady = alm_espacios_clasificacion_disponible($conn);
    $existing = alm_fetch_all($conn, 'SELECT clm_sedeesp_id AS id, clm_sedeesp_idsede AS sede_id,
        clm_sedeesp_area AS area, clm_sedeesp_espacio AS espacio, clm_sedeesp_pabellon AS pabellon,
        clm_sedeesp_estado AS estado, ' . ($classReady ? 'clm_sedeesp_idespacio' : 'NULL') . ' AS clasificacion_id
        FROM tb_sedes_espacios WHERE clm_sedeesp_idsede IN (24, 26) ORDER BY clm_sedeesp_id' . ($lock ? ' FOR UPDATE' : ''));
    $classes = [];
    foreach (alm_fetch_all($conn, 'SELECT clm_esp_id AS id, clm_esp_nombre AS codigo FROM tb_espacio ORDER BY clm_esp_id') as $row) {
        $classes[n360_espacios_key($row['codigo'])][] = (int)$row['id'];
    }
    $plan = [];
    foreach (n360_espacios_propuesta() as $row) {
        $matches = array_values(array_filter($existing, static fn($e) => (int)$e['sede_id'] === $row['sede_id']
            && n360_espacios_key($e['area']) === n360_espacios_key($row['area'])
            && n360_espacios_key($e['espacio']) === n360_espacios_key($row['espacio'])));
        $row['existente'] = count($matches) === 1 ? (int)$matches[0]['id'] : null;
        $row['resultado'] = !$matches ? 'Nuevo' : (count($matches) === 1 ? 'Conservar existente' : 'Revisar coincidencias');
        $row['sede_nombre'] = $names[$row['sede_id']];
        $ids = $classes[n360_espacios_key($row['clasificacion'])] ?? [];
        $row['clasificacion_id'] = $classReady && count($ids) === 1 ? $ids[0] : null;
        $plan[] = $row;
    }
    return ['rows' => $plan, 'token' => hash('sha256', json_encode([$sedes, $existing, $classes, n360_espacios_propuesta()]))];
}

function n360_espacios_catalogo_pendientes(array $plan): int {
    return count(array_filter($plan['rows'], static fn($row) => $row['resultado'] === 'Nuevo'));
}

function n360_espacios_catalogo_cargar(mysqli $conn, string $expected): array {
    if (!alm_espacios_pabellon_disponible($conn)) throw new RuntimeException('Primero habilita los pabellones para cargar el catálogo.', 409);
    $conn->begin_transaction();
    $plan = n360_espacios_catalogo_estado($conn, true);
    if (n360_espacios_catalogo_pendientes($plan) === 0) {
        throw new RuntimeException('El catálogo inicial ya está registrado. Para agregar otras ubicaciones, utiliza Nuevo espacio.', 409);
    }
    if (!hash_equals($plan['token'], $expected)) throw new RuntimeException('El catálogo cambió desde la revisión. Vuelve a revisar la propuesta antes de cargarla.', 409);
    $classReady = alm_espacios_clasificacion_disponible($conn);
    $created = 0; $kept = 0;
    foreach ($plan['rows'] as $row) {
        if ($row['resultado'] !== 'Nuevo') { $kept++; continue; }
        $params = [$row['sede_id'], $row['area'], $row['espacio'], $row['pabellon'], $row['estado']];
        if ($classReady) $params[] = $row['clasificacion_id'];
        $stmt = $conn->prepare('INSERT INTO tb_sedes_espacios
            (clm_sedeesp_idsede, clm_sedeesp_area, clm_sedeesp_espacio, clm_sedeesp_pabellon, clm_sedeesp_estado'
            . ($classReady ? ', clm_sedeesp_idespacio' : '') . ') VALUES (?, ?, ?, ?, ?' . ($classReady ? ', ?' : '') . ')');
        alm_bind($stmt, 'isssi' . ($classReady ? 'i' : ''), $params);
        if (!$stmt->execute()) throw new RuntimeException('No se pudo cargar el catálogo.');
        $stmt->close(); $created++;
    }
    $conn->commit();
    return [$created, $kept];
}
