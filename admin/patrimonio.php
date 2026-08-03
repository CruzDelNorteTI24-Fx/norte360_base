<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';

function patr_h($value): string {
    return n360_admin_h((string)($value ?? ''));
}

function patr_norm_col(string $name): string {
    $name = strtolower($name);
    $from = ['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'];
    $to = ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'];
    $name = str_replace($from, $to, $name);
    return preg_replace('/[^a-z0-9]+/', '', $name) ?: '';
}

function patr_pick_col(array $columns, array $needles, ?string $fallback = null): ?string {
    $normalized = [];
    foreach ($columns as $column) {
        $normalized[$column] = patr_norm_col((string)$column);
    }

    foreach ($needles as $needle) {
        $needleNorm = patr_norm_col((string)$needle);
        foreach ($normalized as $column => $norm) {
            if ($norm === $needleNorm) {
                return $column;
            }
        }
    }

    foreach ($needles as $needle) {
        $needleNorm = patr_norm_col((string)$needle);
        foreach ($normalized as $column => $norm) {
            if ($needleNorm !== '' && str_contains($norm, $needleNorm)) {
                return $column;
            }
        }
    }

    return $fallback;
}

function patr_qcol(string $column): string {
    return '`' . str_replace('`', '``', $column) . '`';
}

function patr_bind_and_exec(mysqli $conn, string $sql, string $types = '', array $values = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta.');
    }

    if ($types !== '') {
        $refs = [];
        foreach ($values as $key => $value) {
            $refs[$key] = &$values[$key];
        }
        $stmt->bind_param($types, ...$refs);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new RuntimeException($error ?: 'No se pudo ejecutar la consulta.');
    }

    return $stmt;
}

function patr_insert_row(mysqli $conn, string $table, array $fields): int {
    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    foreach ($fields as $column => $meta) {
        $columns[] = patr_qcol((string)$column);
        $placeholders[] = '?';
        $types .= $meta['type'];
        $values[] = $meta['value'];
    }

    $sql = 'INSERT INTO ' . patr_qcol($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = patr_bind_and_exec($conn, $sql, $types, $values);
    $stmt->close();

    return (int)$conn->insert_id;
}

function patr_update_row(mysqli $conn, string $table, array $fields, string $whereColumn, int $whereId): void {
    $sets = [];
    $types = '';
    $values = [];

    foreach ($fields as $column => $meta) {
        $sets[] = patr_qcol((string)$column) . ' = ?';
        $types .= $meta['type'];
        $values[] = $meta['value'];
    }

    $types .= 'i';
    $values[] = $whereId;
    $sql = 'UPDATE ' . patr_qcol($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . patr_qcol($whereColumn) . ' = ?';
    $stmt = patr_bind_and_exec($conn, $sql, $types, $values);
    $stmt->close();
}

function patr_post_text(string $key, int $max = 255): string {
    $value = trim((string)($_POST[$key] ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function patr_post_nullable_text(string $key, int $max = 1000): ?string {
    $value = patr_post_text($key, $max);
    return $value === '' ? null : $value;
}

function patr_post_int_or_null(string $key): ?int {
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    return is_numeric($value) ? max(0, (int)$value) : null;
}

function patr_post_date_or_null(string $key): ?string {
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : null;
}

function patr_equipment_from_post(string $key): ?int {
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    return in_array($value, ['1', '2'], true) ? (int)$value : null;
}

function patr_clean_plate(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9-]/', '', $value) ?: '';
    return substr($value, 0, 20);
}

function patr_plate_state(string $patrState): string {
    return strtoupper(trim($patrState)) === 'ACTIVO' ? 'Activo' : 'Inactivo';
}

function patr_payload(): array {
    $states = ['Activo', 'Inactivo', 'Baja', 'Mantenimiento', 'Venta'];
    $types = ['BUS', 'CARGUERO', 'CAMIONETA', 'AUTO', 'OTRO'];
    $services = ['PREMIUM-EXCLUSIVO', 'PREMIUM-CLASE', 'PRIMERA-CLASE', 'ESTANDAR'];

    $estado = patr_post_text('estado', 30);
    if (!in_array($estado, $states, true)) {
        $estado = 'Activo';
    }

    $tipo = strtoupper(patr_post_text('tipo', 40));
    if (!in_array($tipo, $types, true)) {
        $tipo = 'BUS';
    }

    $servicio = strtoupper(patr_post_text('servicio', 40));
    if (!in_array($servicio, $services, true)) {
        $servicio = 'PREMIUM-EXCLUSIVO';
    }

    $fechaAlta = patr_post_date_or_null('fecha_alta') ?: date('Y-m-d');
    $fechaBaja = patr_post_date_or_null('fecha_baja');
    if ($estado !== 'Activo' && $fechaBaja === null) {
        $fechaBaja = date('Y-m-d');
    }
    if ($estado === 'Activo') {
        $fechaBaja = null;
    }

    return [
        'placa' => patr_clean_plate(patr_post_text('placa', 20)),
        'bus' => patr_post_text('bus', 80),
        'dueno' => patr_post_text('dueno', 120),
        'tipo' => $tipo,
        'servicio' => $servicio,
        'kilometraje' => patr_post_int_or_null('kilometraje') ?? 0,
        'estado' => $estado,
        'fecha_alta' => $fechaAlta,
        'fecha_baja' => $fechaBaja,
        'motivo' => patr_post_nullable_text('motivo', 1000),
        'compania' => patr_post_nullable_text('compania', 120),
        'marca' => patr_post_nullable_text('marca', 120),
        'modelo' => patr_post_nullable_text('modelo', 120),
        'capacidad_pasajeros' => patr_post_int_or_null('capacidad_pasajeros'),
        'capacidad_asientos_terr' => patr_post_int_or_null('capacidad_asientos_terr'),
        'capacidad_total' => patr_post_int_or_null('capacidad_total'),
        'soat' => patr_equipment_from_post('soat'),
        'revision_tecnica' => patr_equipment_from_post('revision_tecnica'),
        'seguro' => patr_equipment_from_post('seguro'),
        'gata' => patr_equipment_from_post('gata'),
        'llave_ruedas' => patr_equipment_from_post('llave_ruedas'),
        'juego_llaves' => patr_equipment_from_post('juego_llaves'),
        'palanca' => patr_equipment_from_post('palanca'),
    ];
}

function patr_validate_payload(array $payload): void {
    if ($payload['placa'] === '') {
        throw new InvalidArgumentException('Ingresa la placa de la unidad.');
    }
    if ($payload['bus'] === '') {
        throw new InvalidArgumentException('Ingresa el numero o nombre de la unidad.');
    }
    if ($payload['fecha_alta'] === null) {
        throw new InvalidArgumentException('La fecha de alta no es valida.');
    }
}

function patr_find_plate(mysqli $conn, array $map, string $plate, int $excludeId = 0): ?int {
    $idCol = $map['id'];
    $plateCol = $map['placa'];
    $sql = 'SELECT ' . patr_qcol($idCol) . ' AS placa_id FROM tb_placas WHERE REPLACE(UPPER(' . patr_qcol($plateCol) . "), '-', '') = REPLACE(UPPER(?), '-', '')";
    $types = 's';
    $values = [$plate];
    if ($excludeId > 0) {
        $sql .= ' AND ' . patr_qcol($idCol) . ' <> ?';
        $types .= 'i';
        $values[] = $excludeId;
    }
    $sql .= ' LIMIT 1';

    $stmt = patr_bind_and_exec($conn, $sql, $types, $values);
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int)$row['placa_id'] : null;
}

function patr_get_row(mysqli $conn, int $patrId): ?array {
    $stmt = patr_bind_and_exec($conn, 'SELECT * FROM tb_patrimonio_vehicular WHERE clm_patr_id = ? LIMIT 1', 'i', [$patrId]);
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function patr_plate_fields(array $payload, array $map): array {
    $fields = [
        $map['placa'] => ['type' => 's', 'value' => $payload['placa']],
        $map['dueno'] => ['type' => 's', 'value' => $payload['dueno']],
        $map['bus'] => ['type' => 's', 'value' => $payload['bus']],
        $map['tipo'] => ['type' => 's', 'value' => $payload['tipo']],
        $map['estado'] => ['type' => 's', 'value' => patr_plate_state($payload['estado'])],
    ];

    if (!empty($map['kilometraje'])) {
        $fields[$map['kilometraje']] = ['type' => 'i', 'value' => (int)$payload['kilometraje']];
    }
    if (!empty($map['servicio'])) {
        $fields[$map['servicio']] = ['type' => 's', 'value' => $payload['servicio']];
    }
    if (!empty($map['fecha_inicio'])) {
        $fields[$map['fecha_inicio']] = ['type' => 's', 'value' => $payload['fecha_alta']];
    }
    if (!empty($map['fecha_fin'])) {
        $fields[$map['fecha_fin']] = ['type' => 's', 'value' => $payload['estado'] === 'Activo' ? null : $payload['fecha_baja']];
    }

    return $fields;
}

function patr_patrimonio_fields(array $payload, ?int $placaId = null): array {
    $fields = [];
    if ($placaId !== null) {
        $fields['clm_patr_id_placa'] = ['type' => 'i', 'value' => $placaId];
    }

    $fields += [
        'clm_patr_estado' => ['type' => 's', 'value' => $payload['estado']],
        'clm_patr_fecha_alta' => ['type' => 's', 'value' => $payload['fecha_alta']],
        'clm_patr_fecha_baja' => ['type' => 's', 'value' => $payload['fecha_baja']],
        'clm_patr_motivo' => ['type' => 's', 'value' => $payload['motivo']],
        'clm_patr_compania' => ['type' => 's', 'value' => $payload['compania']],
        'clm_patr_marca' => ['type' => 's', 'value' => $payload['marca']],
        'clm_patr_modelo' => ['type' => 's', 'value' => $payload['modelo']],
        'clm_patr_capacidad_pasajeros' => ['type' => 'i', 'value' => $payload['capacidad_pasajeros']],
        'clm_patr_capacidad_asientos_terr' => ['type' => 'i', 'value' => $payload['capacidad_asientos_terr']],
        'clm_patr_capacidad_total' => ['type' => 'i', 'value' => $payload['capacidad_total']],
        'clm_patr_soat' => ['type' => 'i', 'value' => $payload['soat']],
        'clm_patr_revision_tecnica' => ['type' => 'i', 'value' => $payload['revision_tecnica']],
        'clm_patr_seguro' => ['type' => 'i', 'value' => $payload['seguro']],
        'clm_patr_gata' => ['type' => 'i', 'value' => $payload['gata']],
        'clm_patr_llave_ruedas' => ['type' => 'i', 'value' => $payload['llave_ruedas']],
        'clm_patr_juego_llaves' => ['type' => 'i', 'value' => $payload['juego_llaves']],
        'clm_patr_palanca' => ['type' => 'i', 'value' => $payload['palanca']],
    ];

    return $fields;
}

function patr_state_chip(string $state): string {
    $key = strtoupper(trim($state));
    return match ($key) {
        'ACTIVO' => 'patr-chip--ok',
        'MANTENIMIENTO' => 'patr-chip--warn',
        'BAJA', 'VENTA' => 'patr-chip--danger',
        default => 'patr-chip--muted',
    };
}

function patr_yes_no(?int $value): string {
    if ($value === 1) return 'Si';
    if ($value === 2) return 'No';
    return 'Pendiente';
}

function patr_yes_no_class(?int $value): string {
    if ($value === 1) return 'patr-eq--yes';
    if ($value === 2) return 'patr-eq--no';
    return 'patr-eq--empty';
}

function patr_json_attr(array $data): string {
    return htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
}

$placaColumns = n360_admin_columns($conn, 'tb_placas');
$placaMap = [
    'id' => patr_pick_col($placaColumns, ['clm_placas_id', 'placas_id']),
    'placa' => patr_pick_col($placaColumns, ['clm_placas_PLACA', 'placa']),
    'dueno' => patr_pick_col($placaColumns, ['clm_placas_DUENO', 'dueno']),
    'bus' => patr_pick_col($placaColumns, ['clm_placas_BUS', 'bus']),
    'tipo' => patr_pick_col($placaColumns, ['clm_placas_TIPO_VEHICULO', 'tipo_vehiculo']),
    'estado' => patr_pick_col($placaColumns, ['clm_placas_ESTADO', 'estado']),
    'kilometraje' => patr_pick_col($placaColumns, ['clm_placas_KILOMETRAJE', 'kilometraje']),
    'servicio' => patr_pick_col($placaColumns, ['clm_placas_servicio', 'servicio']),
    'fecha_inicio' => patr_pick_col($placaColumns, ['clm_placas_fecha_inicio', 'fecha_inicio']),
    'fecha_fin' => patr_pick_col($placaColumns, ['clm_placas_fecha_fin', 'fecha_fin']),
    'patrimonio_id' => patr_pick_col($placaColumns, ['clm_placas_patrimonio_id', 'patrimonio_id']),
];

foreach (['id', 'placa', 'dueno', 'bus', 'tipo', 'estado'] as $requiredCol) {
    if (empty($placaMap[$requiredCol])) {
        throw new RuntimeException('No se encontro una columna requerida de tb_placas: ' . $requiredCol);
    }
}

$equipment = [
    'soat' => ['label' => 'SOAT', 'column' => 'clm_patr_soat'],
    'revision_tecnica' => ['label' => 'Revision tecnica', 'column' => 'clm_patr_revision_tecnica'],
    'seguro' => ['label' => 'Seguro', 'column' => 'clm_patr_seguro'],
    'gata' => ['label' => 'Gata', 'column' => 'clm_patr_gata'],
    'llave_ruedas' => ['label' => 'Llave de ruedas', 'column' => 'clm_patr_llave_ruedas'],
    'juego_llaves' => ['label' => 'Juego de llaves', 'column' => 'clm_patr_juego_llaves'],
    'palanca' => ['label' => 'Palanca', 'column' => 'clm_patr_palanca'],
];

if (empty($_SESSION['patrimonio_csrf'])) {
    $_SESSION['patrimonio_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['patrimonio_csrf'];
$flash = $_SESSION['patrimonio_flash'] ?? null;
unset($_SESSION['patrimonio_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = patr_post_text('action', 40);
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('La sesion expiro. Actualiza la pagina e intenta nuevamente.');
        }

        if ($action === 'create') {
            $payload = patr_payload();
            patr_validate_payload($payload);
            if (patr_find_plate($conn, $placaMap, $payload['placa']) !== null) {
                throw new InvalidArgumentException('La placa ya existe. Revisa gestion de placas o edita el patrimonio vinculado.');
            }

            $conn->begin_transaction();
            $placaId = patr_insert_row($conn, 'tb_placas', patr_plate_fields($payload, $placaMap));
            $patrId = patr_insert_row($conn, 'tb_patrimonio_vehicular', patr_patrimonio_fields($payload, $placaId));
            if (!empty($placaMap['patrimonio_id'])) {
                patr_update_row($conn, 'tb_placas', [
                    $placaMap['patrimonio_id'] => ['type' => 'i', 'value' => $patrId],
                ], $placaMap['id'], $placaId);
            }
            $conn->commit();
            $_SESSION['patrimonio_flash'] = ['type' => 'ok', 'message' => 'Patrimonio registrado y placa creada correctamente.'];
        } elseif ($action === 'update') {
            $patrId = (int)($_POST['patr_id'] ?? 0);
            $current = $patrId > 0 ? patr_get_row($conn, $patrId) : null;
            if (!$current) {
                throw new InvalidArgumentException('No se encontro el patrimonio seleccionado.');
            }
            $placaId = (int)($current['clm_patr_id_placa'] ?? 0);
            if ($placaId <= 0) {
                throw new InvalidArgumentException('El patrimonio no tiene una placa vinculada.');
            }

            $payload = patr_payload();
            patr_validate_payload($payload);
            $duplicate = patr_find_plate($conn, $placaMap, $payload['placa'], $placaId);
            if ($duplicate !== null) {
                throw new InvalidArgumentException('La placa ingresada ya pertenece a otra unidad.');
            }

            $conn->begin_transaction();
            patr_update_row($conn, 'tb_patrimonio_vehicular', patr_patrimonio_fields($payload), 'clm_patr_id', $patrId);
            patr_update_row($conn, 'tb_placas', patr_plate_fields($payload, $placaMap), $placaMap['id'], $placaId);
            $conn->commit();
            $_SESSION['patrimonio_flash'] = ['type' => 'ok', 'message' => 'Patrimonio y placa sincronizados correctamente.'];
        } elseif ($action === 'set_state') {
            $patrId = (int)($_POST['patr_id'] ?? 0);
            $target = patr_post_text('target_state', 30) === 'Activo' ? 'Activo' : 'Inactivo';
            $current = $patrId > 0 ? patr_get_row($conn, $patrId) : null;
            if (!$current) {
                throw new InvalidArgumentException('No se encontro el patrimonio seleccionado.');
            }
            $placaId = (int)($current['clm_patr_id_placa'] ?? 0);
            if ($placaId <= 0) {
                throw new InvalidArgumentException('El patrimonio no tiene una placa vinculada.');
            }

            $conn->begin_transaction();
            patr_update_row($conn, 'tb_patrimonio_vehicular', [
                'clm_patr_estado' => ['type' => 's', 'value' => $target],
                'clm_patr_fecha_baja' => ['type' => 's', 'value' => $target === 'Activo' ? null : date('Y-m-d')],
                'clm_patr_motivo' => ['type' => 's', 'value' => $target === 'Activo' ? 'Reactivado desde patrimonio vehicular.' : 'Inactivado desde patrimonio vehicular.'],
            ], 'clm_patr_id', $patrId);
            $plateState = patr_plate_state($target);
            $plateFields = [
                $placaMap['estado'] => ['type' => 's', 'value' => $plateState],
            ];
            if (!empty($placaMap['fecha_fin'])) {
                $plateFields[$placaMap['fecha_fin']] = ['type' => 's', 'value' => $target === 'Activo' ? null : date('Y-m-d')];
            }
            patr_update_row($conn, 'tb_placas', $plateFields, $placaMap['id'], $placaId);
            $conn->commit();
            $_SESSION['patrimonio_flash'] = ['type' => 'ok', 'message' => $target === 'Activo' ? 'Patrimonio reactivado. La unidad vuelve a quedar disponible.' : 'Patrimonio inactivado. La placa tambien quedo inactiva.'];
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $_SESSION['patrimonio_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }

    header('Location: patrimonio.php');
    exit;
}

$select = [
    'p.*',
    'pl.' . patr_qcol($placaMap['id']) . ' AS placa_id',
    'pl.' . patr_qcol($placaMap['placa']) . ' AS placa_numero',
    'pl.' . patr_qcol($placaMap['dueno']) . ' AS placa_dueno',
    'pl.' . patr_qcol($placaMap['bus']) . ' AS placa_bus',
    'pl.' . patr_qcol($placaMap['tipo']) . ' AS placa_tipo',
    'pl.' . patr_qcol($placaMap['estado']) . ' AS placa_estado',
];
if (!empty($placaMap['kilometraje'])) $select[] = 'pl.' . patr_qcol($placaMap['kilometraje']) . ' AS placa_kilometraje';
if (!empty($placaMap['servicio'])) $select[] = 'pl.' . patr_qcol($placaMap['servicio']) . ' AS placa_servicio';

$rows = n360_admin_query_all($conn, '
    SELECT ' . implode(', ', $select) . '
    FROM tb_patrimonio_vehicular p
    LEFT JOIN tb_placas pl ON pl.' . patr_qcol($placaMap['id']) . ' = p.clm_patr_id_placa
    ORDER BY FIELD(p.clm_patr_estado, "Activo", "Mantenimiento", "Inactivo", "Baja", "Venta"), CAST(pl.' . patr_qcol($placaMap['bus']) . ' AS UNSIGNED), pl.' . patr_qcol($placaMap['bus']) . ', p.clm_patr_id ASC
');

$total = count($rows);
$activos = 0;
$inactivos = 0;
$equipados = 0;
$sinPlaca = 0;
foreach ($rows as $row) {
    $isActive = strtoupper(trim((string)($row['clm_patr_estado'] ?? ''))) === 'ACTIVO';
    $activos += $isActive ? 1 : 0;
    $inactivos += $isActive ? 0 : 1;
    $sinPlaca += empty($row['placa_id']) ? 1 : 0;
    $yesCount = 0;
    foreach ($equipment as $meta) {
        $yesCount += ((int)($row[$meta['column']] ?? 0) === 1) ? 1 : 0;
    }
    if ($yesCount === count($equipment)) {
        $equipados++;
    }
}

n360_admin_render_head('Patrimonio');
?>
<?php n360_render_header(['title' => 'Patrimonio', 'subtitle' => 'Administracion']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-cat-shell patr-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-cat-hero patr-hero">
            <div>
                <span class="admin-cat-kicker"><i class="bi bi-gem" aria-hidden="true"></i> Administracion - Patrimonio vehicular</span>
                <h1>Equipamiento de unidades</h1>
                <p>Alta, mantenimiento e inactivacion del patrimonio vinculado a cada placa de la empresa.</p>
            </div>
            <div class="patr-hero__actions">
                <button type="button" class="patr-secondary-btn" data-patr-matrix-open>
                    <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
                    <span>Matriz de equipamiento</span>
                </button>
                <button type="button" class="patr-primary-btn" data-patr-create>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>Nuevo patrimonio</span>
                </button>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="patr-alert patr-alert--<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>">
                <i class="bi <?= $flash['type'] === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                <span><?= patr_h($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>

        <section class="admin-cat-kpis">
            <article class="admin-cat-kpi"><span>Patrimonios</span><strong><?= $total ?></strong></article>
            <article class="admin-cat-kpi"><span>Activos</span><strong><?= $activos ?></strong></article>
            <article class="admin-cat-kpi"><span>Inactivos / baja</span><strong><?= $inactivos ?></strong></article>
            <article class="admin-cat-kpi"><span>Equipo completo</span><strong><?= $equipados ?></strong></article>
        </section>

        <section class="patr-toolbar" aria-label="Filtros de patrimonio">
            <label class="admin-cat-searchbox" for="patrSearch">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="patrSearch" type="search" placeholder="Buscar bus, placa, dueno, marca, modelo..." autocomplete="off" data-patr-search>
            </label>
            <div class="patr-toolbar__actions">
                <select data-patr-status aria-label="Filtrar por estado">
                    <option value="all">Todos los estados</option>
                    <option value="activo">Solo activos</option>
                    <option value="inactivo">Inactivos, baja o venta</option>
                    <option value="mantenimiento">Mantenimiento</option>
                </select>
                <button type="button" class="admin-cat-soft-btn" data-patr-clear>
                    <i class="bi bi-x-circle" aria-hidden="true"></i>
                    <span>Limpiar</span>
                </button>
                <span class="patr-result" data-patr-result>Mostrando <?= $total ?> unidades</span>
            </div>
        </section>

        <?php if ($sinPlaca > 0): ?>
            <div class="admin-cat-note">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span>Hay <?= $sinPlaca ?> patrimonio(s) sin placa vinculada. Revisa la relacion <strong>clm_patr_id_placa</strong>.</span>
            </div>
        <?php endif; ?>

        <section class="admin-cat-panel patr-panel">
            <div class="admin-cat-panel__head">
                <div>
                    <h2>Patrimonio registrado</h2>
                    <p>La accion de inactivar sincroniza tambien el estado de la placa relacionada.</p>
                </div>
            </div>
            <div class="admin-cat-table-wrap">
                <table class="admin-cat-table patr-table">
                    <thead>
                        <tr>
                            <th>Unidad</th>
                            <th>Estado</th>
                            <th>Ficha tecnica</th>
                            <th>Capacidad</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-patr-tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="admin-cat-empty">No se encontraron patrimonios vehiculares.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $patrId = (int)($row['clm_patr_id'] ?? 0);
                        $placaId = (int)($row['placa_id'] ?? ($row['clm_patr_id_placa'] ?? 0));
                        $estado = trim((string)($row['clm_patr_estado'] ?? 'Activo'));
                        $estadoKey = strtolower($estado);
                        $isActive = strtoupper($estado) === 'ACTIVO';
                        $plateText = trim((string)($row['placa_numero'] ?? 'Sin placa'));
                        $busText = trim((string)($row['placa_bus'] ?? 'Unidad sin nombre'));
                        $duenoText = trim((string)($row['placa_dueno'] ?? 'Sin dueno'));
                        $search = strtolower(trim(implode(' ', [
                            $plateText,
                            $busText,
                            $duenoText,
                            $row['placa_tipo'] ?? '',
                            $row['placa_servicio'] ?? '',
                            $row['clm_patr_compania'] ?? '',
                            $row['clm_patr_marca'] ?? '',
                            $row['clm_patr_modelo'] ?? '',
                            $estado,
                        ])));
                        $editData = [
                            'patr_id' => $patrId,
                            'placa_id' => $placaId,
                            'placa' => $plateText,
                            'bus' => $busText,
                            'dueno' => $duenoText,
                            'tipo' => $row['placa_tipo'] ?? 'BUS',
                            'servicio' => $row['placa_servicio'] ?? 'PREMIUM-EXCLUSIVO',
                            'kilometraje' => $row['placa_kilometraje'] ?? 0,
                            'estado' => $estado,
                            'fecha_alta' => $row['clm_patr_fecha_alta'] ?? '',
                            'fecha_baja' => $row['clm_patr_fecha_baja'] ?? '',
                            'motivo' => $row['clm_patr_motivo'] ?? '',
                            'compania' => $row['clm_patr_compania'] ?? '',
                            'marca' => $row['clm_patr_marca'] ?? '',
                            'modelo' => $row['clm_patr_modelo'] ?? '',
                            'capacidad_pasajeros' => $row['clm_patr_capacidad_pasajeros'] ?? '',
                            'capacidad_asientos_terr' => $row['clm_patr_capacidad_asientos_terr'] ?? '',
                            'capacidad_total' => $row['clm_patr_capacidad_total'] ?? '',
                        ];
                        foreach ($equipment as $key => $meta) {
                            $editData[$key] = $row[$meta['column']] ?? '';
                        }
                        ?>
                        <tr data-patr-row data-search="<?= patr_h($search) ?>" data-status="<?= patr_h($estadoKey) ?>">
                            <td>
                                <div class="patr-unit">
                                    <span class="patr-unit__icon"><i class="bi bi-bus-front-fill" aria-hidden="true"></i></span>
                                    <span>
                                        <strong><?= patr_h($busText) ?> <small><?= patr_h($plateText) ?></small></strong>
                                        <em><?= patr_h($duenoText) ?> - <?= patr_h($row['placa_tipo'] ?? 'Unidad') ?></em>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="patr-chip <?= patr_state_chip($estado) ?>"><?= patr_h($estado) ?></span>
                                <small class="patr-date">Alta: <?= patr_h($row['clm_patr_fecha_alta'] ?? '-') ?></small>
                                <?php if (!empty($row['clm_patr_fecha_baja'])): ?>
                                    <small class="patr-date">Baja: <?= patr_h($row['clm_patr_fecha_baja']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="patr-main">
                                    <strong><?= patr_h($row['clm_patr_marca'] ?: 'Marca pendiente') ?> - <?= patr_h($row['clm_patr_modelo'] ?: 'Modelo pendiente') ?></strong>
                                    <span><?= patr_h($row['clm_patr_compania'] ?: 'Compania no registrada') ?></span>
                                    <span><?= patr_h($row['placa_servicio'] ?: 'Servicio no definido') ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="patr-capacity">
                                    <span><b><?= patr_h($row['clm_patr_capacidad_pasajeros'] ?? '-') ?></b> pasajeros</span>
                                    <span><b><?= patr_h($row['clm_patr_capacidad_asientos_terr'] ?? '-') ?></b> terr.</span>
                                    <span><b><?= patr_h($row['clm_patr_capacidad_total'] ?? '-') ?></b> total</span>
                                </div>
                            </td>
                            <td>
                                <div class="patr-row-actions">
                                    <button type="button" class="admin-cat-soft-btn patr-mini-btn" data-patr-edit="<?= patr_json_attr($editData) ?>">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                        <span>Editar</span>
                                    </button>
                                    <form method="post" class="patr-inline-form" data-patr-state-form>
                                        <input type="hidden" name="csrf" value="<?= patr_h($csrf) ?>">
                                        <input type="hidden" name="action" value="set_state">
                                        <input type="hidden" name="patr_id" value="<?= $patrId ?>">
                                        <input type="hidden" name="target_state" value="<?= $isActive ? 'Inactivo' : 'Activo' ?>">
                                        <button type="submit" class="admin-cat-soft-btn patr-mini-btn <?= $isActive ? 'patr-mini-btn--danger' : 'patr-mini-btn--ok' ?>" data-confirm-state="<?= $isActive ? 'inactivar' : 'activar' ?>">
                                            <i class="bi <?= $isActive ? 'bi-lock-fill' : 'bi-unlock-fill' ?>" aria-hidden="true"></i>
                                            <span><?= $isActive ? 'Inactivar' : 'Activar' ?></span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<div class="patr-modal patr-modal--matrix" hidden data-patr-matrix-modal>
    <div class="patr-modal__backdrop" data-patr-matrix-close></div>
    <section class="patr-modal__dialog patr-matrix-dialog" role="dialog" aria-modal="true" aria-labelledby="patrMatrixTitle">
        <div class="patr-modal__head">
            <div>
                <span class="admin-cat-kicker"><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Control de equipamiento</span>
                <h2 id="patrMatrixTitle">Matriz de equipamiento</h2>
                <p>Comparativo rapido del equipamiento registrado por unidad.</p>
            </div>
            <button type="button" class="patr-modal__close" data-patr-matrix-close aria-label="Cerrar matriz">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="patr-matrix-body">
            <div class="patr-matrix-summary">
                <article><span>Unidades</span><strong><?= $total ?></strong></article>
                <article><span>Activas</span><strong><?= $activos ?></strong></article>
                <article><span>Equipo completo</span><strong><?= $equipados ?></strong></article>
                <article><span>Items evaluados</span><strong><?= count($equipment) ?></strong></article>
            </div>
            <div class="patr-matrix-wrap" role="region" aria-label="Matriz de equipamiento por unidad" tabindex="0">
                <table class="patr-matrix-table">
                    <thead>
                        <tr>
                            <th>Unidad</th>
                            <th>Estado</th>
                            <?php foreach ($equipment as $meta): ?>
                                <th><?= patr_h($meta['label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= count($equipment) + 2 ?>" class="admin-cat-empty">No hay patrimonio para construir la matriz.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $estado = trim((string)($row['clm_patr_estado'] ?? 'Activo'));
                        $plateText = trim((string)($row['placa_numero'] ?? 'Sin placa'));
                        $busText = trim((string)($row['placa_bus'] ?? 'Unidad sin nombre'));
                        $duenoText = trim((string)($row['placa_dueno'] ?? 'Sin dueno'));
                        ?>
                        <tr>
                            <td>
                                <div class="patr-unit patr-unit--compact">
                                    <span class="patr-unit__icon"><i class="bi bi-bus-front-fill" aria-hidden="true"></i></span>
                                    <span>
                                        <strong><?= patr_h($busText) ?> <small><?= patr_h($plateText) ?></small></strong>
                                        <em><?= patr_h($duenoText) ?></em>
                                    </span>
                                </div>
                            </td>
                            <td><span class="patr-chip <?= patr_state_chip($estado) ?>"><?= patr_h($estado) ?></span></td>
                            <?php foreach ($equipment as $meta): ?>
                                <?php
                                $eqValue = isset($row[$meta['column']]) ? (int)$row[$meta['column']] : null;
                                $eqIcon = $eqValue === 1 ? 'bi-check-lg' : ($eqValue === 2 ? 'bi-x-lg' : 'bi-dash-lg');
                                ?>
                                <td>
                                    <span class="patr-matrix-cell <?= patr_yes_no_class($eqValue) ?>">
                                        <i class="bi <?= $eqIcon ?>" aria-hidden="true"></i>
                                        <span><?= patr_yes_no($eqValue) ?></span>
                                    </span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<div class="patr-modal" hidden data-patr-modal>
    <div class="patr-modal__backdrop" data-patr-close></div>
    <section class="patr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="patrModalTitle">
        <form method="post" class="patr-form" data-patr-form autocomplete="off">
            <input type="hidden" name="csrf" value="<?= patr_h($csrf) ?>">
            <input type="hidden" name="action" value="create" data-patr-action>
            <input type="hidden" name="patr_id" value="" data-field="patr_id">
            <input type="hidden" name="placa_id" value="" data-field="placa_id">

            <div class="patr-modal__head">
                <div>
                    <span class="admin-cat-kicker"><i class="bi bi-gem" aria-hidden="true"></i> Patrimonio vehicular</span>
                    <h2 id="patrModalTitle" data-patr-title>Nuevo patrimonio</h2>
                    <p>Creara la placa y su ficha de equipamiento en una sola operacion.</p>
                </div>
                <button type="button" class="patr-modal__close" data-patr-close aria-label="Cerrar">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>

            <div class="patr-form__body">
                <fieldset class="patr-form__section patr-form__section--unit">
                    <legend>Unidad y placa</legend>
                    <div class="patr-form-grid patr-form-grid--4">
                        <label><span>Placa *</span><input type="text" name="placa" data-field="placa" required placeholder="ABC-123" autocomplete="off"></label>
                        <label><span>Unidad / bus *</span><input type="text" name="bus" data-field="bus" required placeholder="158 o BUS 158" autocomplete="off"></label>
                        <label><span>Dueno</span><input type="text" name="dueno" data-field="dueno" placeholder="Empresa o propietario"></label>
                        <label><span>Kilometraje</span><input type="number" name="kilometraje" data-field="kilometraje" min="0" step="1" value="0"></label>
                        <label><span>Tipo</span><select name="tipo" data-field="tipo"><option>BUS</option><option>CARGUERO</option><option>CAMIONETA</option><option>AUTO</option><option>OTRO</option></select></label>
                        <label><span>Servicio</span><select name="servicio" data-field="servicio"><option>PREMIUM-EXCLUSIVO</option><option>PREMIUM-CLASE</option><option>PRIMERA-CLASE</option><option>ESTANDAR</option></select></label>
                        <label><span>Estado patrimonio</span><select name="estado" data-field="estado"><option>Activo</option><option>Inactivo</option><option>Baja</option><option>Mantenimiento</option><option>Venta</option></select></label>
                        <label><span>Fecha alta *</span><input type="date" name="fecha_alta" data-field="fecha_alta" required value="<?= date('Y-m-d') ?>"></label>
                    </div>
                </fieldset>

                <fieldset class="patr-form__section">
                    <legend>Ficha tecnica</legend>
                    <div class="patr-form-grid patr-form-grid--3">
                        <label><span>Compania</span><input type="text" name="compania" data-field="compania" placeholder="CRUZ DEL NORTE"></label>
                        <label><span>Marca</span><input type="text" name="marca" data-field="marca" placeholder="MERCEDES"></label>
                        <label><span>Modelo</span><input type="text" name="modelo" data-field="modelo" placeholder="Modelo de la unidad"></label>
                        <label><span>Cap. pasajeros</span><input type="number" name="capacidad_pasajeros" data-field="capacidad_pasajeros" min="0" step="1"></label>
                        <label><span>Asientos terr.</span><input type="number" name="capacidad_asientos_terr" data-field="capacidad_asientos_terr" min="0" step="1"></label>
                        <label><span>Capacidad total</span><input type="number" name="capacidad_total" data-field="capacidad_total" min="0" step="1"></label>
                    </div>
                </fieldset>

                <fieldset class="patr-form__section">
                    <legend>Equipamiento</legend>
                    <div class="patr-equipment-grid">
                        <?php foreach ($equipment as $key => $meta): ?>
                            <label>
                                <span><?= patr_h($meta['label']) ?></span>
                                <select name="<?= patr_h($key) ?>" data-field="<?= patr_h($key) ?>">
                                    <option value="">Pendiente</option>
                                    <option value="1">Si</option>
                                    <option value="2">No</option>
                                </select>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="patr-form__section">
                    <legend>Baja o comentario operativo</legend>
                    <div class="patr-form-grid patr-form-grid--2">
                        <label><span>Fecha baja</span><input type="date" name="fecha_baja" data-field="fecha_baja"></label>
                        <label class="patr-form__wide"><span>Motivo / observacion</span><textarea name="motivo" data-field="motivo" rows="3" placeholder="Detalle de baja, mantenimiento o comentario relevante"></textarea></label>
                    </div>
                </fieldset>
            </div>

            <div class="patr-modal__foot">
                <button type="button" class="admin-cat-soft-btn" data-patr-close>Cancelar</button>
                <button type="submit" class="patr-primary-btn" data-patr-submit>
                    <i class="bi bi-save2" aria-hidden="true"></i>
                    <span>Guardar patrimonio</span>
                </button>
            </div>
        </form>
    </section>
</div>

<?php n360_render_footer(); ?>
<script src="<?= n360_asset('assets/js/admin_patrimonio_n360.js') ?>?v=20260803"></script>
<?php n360_admin_render_close(); ?>
<?php $conn->close(); ?>
