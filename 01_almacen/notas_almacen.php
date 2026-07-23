<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function notas_json_response(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function notas_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function notas_can_almacen(bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];

    if ($permisos === 'all') {
        return true;
    }

    if (!is_array($permisos)) {
        return false;
    }

    return in_array(3, array_map('intval', $permisos), true);
}

$isAjax = (($_GET['ajax'] ?? '') === 'detalle');

if (!isset($_SESSION['usuario'])) {
    if ($isAjax) {
        notas_json_response(['ok' => false, 'message' => 'Sesion no iniciada.'], 401);
    }

    header('Location: ../login/login.php');
    exit;
}

$isAdmin = (($_SESSION['web_rol'] ?? '') === 'Admin');

if (!notas_can_almacen($isAdmin)) {
    if ($isAjax) {
        notas_json_response(['ok' => false, 'message' => 'No tienes permiso para consultar notas de almacén.'], 403);
    }

    header('Location: ../login/none_permisos.php');
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    if ($isAjax) {
        notas_json_response(['ok' => false, 'message' => 'No se pudo establecer conexion con la base de datos.'], 500);
    }

    $pageError = 'No se pudo establecer conexion con la base de datos.';
} else {
    $pageError = '';
    $conn->set_charset('utf8mb4');
}

function notas_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $typeRef = $types;
    $bind = [&$typeRef];

    foreach ($params as &$param) {
        $bind[] = &$param;
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function notas_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }

    notas_bind_params($stmt, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'No se pudo ejecutar la consulta.';
        $stmt->close();
        throw new RuntimeException($error);
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function notas_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = notas_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function notas_date_or_default(?string $value, string $default): string {
    $value = trim((string)$value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return $default;
}

function notas_fmt_datetime($value): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y H:i', $ts);
}

function notas_fmt_date_for_input(DateTime $date): string {
    return $date->format('Y-m-d');
}

function notas_fmt_qty($value): string {
    $number = (float)$value;
    $text = number_format($number, 4, '.', '');
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '' ? '0' : $text;
}

function notas_modulo_sql_expr(string $alias = 'ns'): string {
    return "CONVERT(COALESCE(NULLIF(TRIM(CAST({$alias}.clm_nota_modulo AS CHAR)), ''), 'Sin modulo') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function notas_is_almacen_modulo($value): bool {
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    $upper = function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);

    return strncmp($upper, 'ALMAC', 5) === 0;
}

function notas_nota_sql_select(): string {
    return "
        SELECT
            ns.clm_nota_id,
            ns.clm_nota_serie,
            ns.clm_nota_corr,
            COALESCE(
                NULLIF(TRIM(CAST(ns.clm_nota_sco AS CHAR)), ''),
                CONCAT(COALESCE(ns.clm_nota_serie, ''), '-', LPAD(COALESCE(ns.clm_nota_corr, 0), 4, '0'))
            ) AS nota_codigo,
            ns.clm_nota_fecha,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_modulo AS CHAR)), ''), 'Sin modulo') AS modulo,
            CASE
                WHEN p.clm_placas_id IS NOT NULL
                     AND TRIM(COALESCE(p.clm_placas_BUS, '')) <> ''
                     AND TRIM(COALESCE(p.clm_placas_PLACA, '')) <> ''
                THEN CONCAT(TRIM(p.clm_placas_BUS), ' (', TRIM(p.clm_placas_PLACA), ')')
                WHEN p.clm_placas_id IS NOT NULL
                     AND TRIM(COALESCE(p.clm_placas_BUS, '')) <> ''
                THEN TRIM(p.clm_placas_BUS)
                WHEN p.clm_placas_id IS NOT NULL
                     AND TRIM(COALESCE(p.clm_placas_PLACA, '')) <> ''
                THEN TRIM(p.clm_placas_PLACA)
                ELSE 'Sin unidad vinculada'
            END AS unidad_label,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_proveedor AS CHAR)), ''), '-') AS entregado_a,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_espacio AS CHAR)), ''), '-') AS espacio,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_responsable AS CHAR)), ''), '-') AS responsable,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_DNI AS CHAR)), ''), '-') AS dni,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_motivo AS CHAR)), ''), '-') AS motivo,
            COALESCE(prod.nombres, '') AS componentes,
            COALESCE(prod.items_count, 0) AS items_count,
            COALESCE(prod.cantidad_total, 0) AS cantidad_total
        FROM tb_notas_salida ns
        LEFT JOIN tb_placas p
            ON ns.clm_nota_placa = p.clm_placas_id
        LEFT JOIN (
            SELECT
                m.clm_alm_mov_idNOTA AS idnota,
                GROUP_CONCAT(
                    DISTINCT CONCAT(
                        '(',
                        COALESCE(NULLIF(TRIM(pr.clm_alm_producto_codigo), ''), 'S/C'),
                        ') ',
                        COALESCE(NULLIF(TRIM(pr.clm_alm_producto_NOMBRE), ''), 'Producto sin nombre')
                    )
                    ORDER BY pr.clm_alm_producto_NOMBRE
                    SEPARATOR ' | '
                ) AS nombres,
                COUNT(*) AS items_count,
                SUM(COALESCE(m.clm_alm_mov_cantidad, 0)) AS cantidad_total
            FROM tb_alm_movimientos m
            JOIN tb_alm_producto pr
                ON pr.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
            WHERE m.clm_alm_mov_idNOTA IS NOT NULL
            GROUP BY m.clm_alm_mov_idNOTA
        ) prod
            ON prod.idnota = ns.clm_nota_id
    ";
}

function notas_build_where(string $desde, string $hasta, string $modulo, string $buscar, string &$types, array &$params, bool $soloAlmacen = false): string {
    $where = " WHERE 1=1 ";

    if ($desde !== '') {
        $where .= " AND DATE(ns.clm_nota_fecha) >= ? ";
        $types .= 's';
        $params[] = $desde;
    }

    if ($hasta !== '') {
        $where .= " AND DATE(ns.clm_nota_fecha) <= ? ";
        $types .= 's';
        $params[] = $hasta;
    }

    if ($soloAlmacen) {
        $where .= " AND " . notas_modulo_sql_expr('ns') . " = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci ";
        $types .= 's';
        $params[] = 'Almacen';
    } elseif ($modulo !== '' && $modulo !== '(Todos)') {
        $where .= " AND " . notas_modulo_sql_expr('ns') . " = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci ";
        $types .= 's';
        $params[] = $modulo;
    }

    if ($buscar !== '') {
        $where .= " AND (
            CAST(ns.clm_nota_id AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(ns.clm_nota_sco AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CONCAT(COALESCE(ns.clm_nota_serie, ''), '-', LPAD(COALESCE(ns.clm_nota_corr, 0), 4, '0')) LIKE CONCAT('%', ?, '%')
            OR CAST(ns.clm_nota_proveedor AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(ns.clm_nota_motivo AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(ns.clm_nota_espacio AS CHAR) LIKE CONCAT('%', ?, '%')
            OR p.clm_placas_PLACA LIKE CONCAT('%', ?, '%')
            OR p.clm_placas_BUS LIKE CONCAT('%', ?, '%')
            OR prod.nombres LIKE CONCAT('%', ?, '%')
        ) ";
        $types .= 'sssssssss';
        array_push($params, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar);
    }

    return $where;
}

function notas_detail_payload(mysqli $conn, int $idNota, bool $soloAlmacen = false): array {
    $sqlNota = notas_nota_sql_select() . " WHERE ns.clm_nota_id = ? LIMIT 1";
    $nota = notas_fetch_one($conn, $sqlNota, 'i', [$idNota]);

    if (!$nota) {
        throw new RuntimeException('No se encontro la nota solicitada.');
    }

    if ($soloAlmacen && !notas_is_almacen_modulo($nota['modulo'] ?? '')) {
        throw new RuntimeException('No tienes permiso para consultar esta nota.');
    }

    $productos = notas_fetch_all($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(CAST(m.clm_alm_mov_itmtable AS CHAR)), ''), '-') AS orden,
            COALESCE(NULLIF(TRIM(pr.clm_alm_producto_codigo), ''), 'S/C') AS codigo,
            COALESCE(NULLIF(TRIM(pr.clm_alm_producto_NOMBRE), ''), 'Producto sin nombre') AS producto,
            COALESCE(NULLIF(TRIM(CAST(m.clm_alm_mov_cantidad AS CHAR)), ''), '0') AS cantidad,
            COALESCE(NULLIF(TRIM(CAST(m.clm_alm_mov_OBSERVACION AS CHAR)), ''), '-') AS observacion,
            COALESCE(NULLIF(TRIM(CAST(m.clm_alm_mov_TIPO AS CHAR)), ''), '-') AS tipo_movimiento
        FROM tb_alm_movimientos m
        JOIN tb_alm_producto pr
            ON pr.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
        WHERE m.clm_alm_mov_idNOTA = ?
        ORDER BY
            CAST(NULLIF(m.clm_alm_mov_itmtable, '') AS UNSIGNED) ASC,
            m.clm_alm_mov_id ASC
    ", 'i', [$idNota]);

    $cantidadTotal = 0.0;

    foreach ($productos as &$producto) {
        $cantidadTotal += (float)str_replace(',', '.', (string)$producto['cantidad']);
        $producto['cantidad_label'] = notas_fmt_qty($producto['cantidad']);
    }
    unset($producto);

    $nota['fecha_label'] = notas_fmt_datetime($nota['clm_nota_fecha'] ?? '');
    $nota['cantidad_total_label'] = notas_fmt_qty($cantidadTotal);

    return [
        'ok' => true,
        'nota' => $nota,
        'productos' => $productos,
        'kpis' => [
            'items' => count($productos),
            'cantidad_total' => notas_fmt_qty($cantidadTotal),
            'modulo' => $nota['modulo'] ?? '-',
            'serie' => $nota['clm_nota_serie'] ?? '-',
        ],
    ];
}

if ($isAjax) {
    try {
        if ($pageError !== '') {
            throw new RuntimeException($pageError);
        }

        $idNota = (int)($_GET['id_nota'] ?? 0);

        if ($idNota <= 0) {
            throw new InvalidArgumentException('ID de nota invalido.');
        }

        notas_json_response(notas_detail_payload($conn, $idNota, !$isAdmin));
    } catch (Throwable $e) {
        notas_json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

$today = new DateTime('today');
$firstDay = new DateTime('first day of this month');
$desde = notas_date_or_default($_GET['desde'] ?? null, notas_fmt_date_for_input($firstDay));
$hasta = notas_date_or_default($_GET['hasta'] ?? null, notas_fmt_date_for_input($today));

if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$buscar = trim((string)($_GET['buscar'] ?? ''));
$soloAlmacen = !$isAdmin;
$modulo = $soloAlmacen ? 'Almacen' : trim((string)($_GET['modulo'] ?? 'Almacén'));

if ($modulo === '') {
    $modulo = '(Todos)';
}

$notas = [];
$modulosDisponibles = [];

try {
    if ($pageError !== '') {
        throw new RuntimeException($pageError);
    }

    $modulosDisponibles = $soloAlmacen
        ? [['modulo' => 'Almacen']]
        : notas_fetch_all($conn, "
            SELECT DISTINCT COALESCE(NULLIF(TRIM(CAST(clm_nota_modulo AS CHAR)), ''), 'Sin modulo') AS modulo
            FROM tb_notas_salida
            ORDER BY modulo ASC
        ");

    $types = '';
    $params = [];
    $where = notas_build_where($desde, $hasta, $modulo, $buscar, $types, $params, $soloAlmacen);
    $sql = notas_nota_sql_select() . $where . "
        ORDER BY ns.clm_nota_fecha DESC, ns.clm_nota_id DESC
        LIMIT 1200
    ";
    $notas = notas_fetch_all($conn, $sql, $types, $params);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
    $notas = [];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=notas_almacen_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Nota', 'Fecha', 'Modulo', 'Bus / Placa', 'Entregado a', 'Espacio', 'Responsable', 'DNI', 'Motivo', 'Items', 'Cantidad total', 'Componentes']);

    foreach ($notas as $nota) {
        fputcsv($out, [
            $nota['clm_nota_id'] ?? '',
            $nota['nota_codigo'] ?? '',
            $nota['clm_nota_fecha'] ?? '',
            $nota['modulo'] ?? '',
            $nota['unidad_label'] ?? '',
            $nota['entregado_a'] ?? '',
            $nota['espacio'] ?? '',
            $nota['responsable'] ?? '',
            $nota['dni'] ?? '',
            $nota['motivo'] ?? '',
            $nota['items_count'] ?? 0,
            $nota['cantidad_total'] ?? 0,
            $nota['componentes'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

$totalNotas = count($notas);
$totalItems = 0;
$totalCantidad = 0.0;
$notasConUnidad = 0;

foreach ($notas as $nota) {
    $totalItems += (int)($nota['items_count'] ?? 0);
    $totalCantidad += (float)($nota['cantidad_total'] ?? 0);

    if (($nota['unidad_label'] ?? '') !== 'Sin unidad vinculada') {
        $notasConUnidad++;
    }
}

$exportQuery = $_GET;
$exportQuery['modulo'] = $soloAlmacen ? 'Almacen' : $modulo;
$exportQuery['export'] = 'csv';
$exportUrl = 'notas_almacen.php?' . http_build_query($exportQuery);

$moduloOptions = $soloAlmacen ? ['Almacen'] : ['(Todos)'];

foreach ($modulosDisponibles as $rowModulo) {
    $value = trim((string)($rowModulo['modulo'] ?? ''));
    if ($value !== '' && !in_array($value, $moduloOptions, true)) {
        $moduloOptions[] = $value;
    }
}

if (!in_array($modulo, $moduloOptions, true)) {
    $moduloOptions[] = $modulo;
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notas de almacén | Norte360</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/notas_almacen_n360.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Notas de almacén', 'subtitle' => 'Historial operativo']); ?>
<?php n360_render_sidebar(); ?>

<div class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <main class="notas-page" data-notas-page>
        <section class="notas-hero">
            <div>
                <span class="notas-eyebrow"><i class="bi bi-receipt-cutoff"></i> Almacén en tiempo real</span>
                <h1>Notas de almacén</h1>
                <p>Consulta de notas, unidades, responsables y productos asociados segun el historial de la aplicacion de escritorio.</p>
            </div>
            <div class="notas-hero-actions">
                <a href="<?= notas_h($exportUrl) ?>" class="notas-btn notas-btn--ghost">
                    <i class="bi bi-filetype-csv"></i>
                    Exportar CSV
                </a>
                <button type="button" class="notas-btn notas-btn--primary" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise"></i>
                    Actualizar
                </button>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="notas-alert" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= notas_h($pageError) ?></span>
            </div>
        <?php endif; ?>

        <section class="notas-kpis" aria-label="Resumen de notas">
            <article class="notas-kpi">
                <span>Notas visibles</span>
                <strong><?= notas_h($totalNotas) ?></strong>
            </article>
            <article class="notas-kpi notas-kpi--green">
                <span>Items asociados</span>
                <strong><?= notas_h($totalItems) ?></strong>
            </article>
            <article class="notas-kpi notas-kpi--amber">
                <span>Cantidad total</span>
                <strong><?= notas_h(notas_fmt_qty($totalCantidad)) ?></strong>
            </article>
            <article class="notas-kpi notas-kpi--blue">
                <span>Con unidad</span>
                <strong><?= notas_h($notasConUnidad) ?></strong>
            </article>
        </section>

        <form class="notas-filters" method="get" action="notas_almacen.php" autocomplete="off">
            <label class="notas-field notas-field--search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    name="buscar"
                    value="<?= notas_h($buscar) ?>"
                    placeholder="Nota, unidad, responsable, motivo o producto..."
                    autocomplete="off"
                    data-notas-live-filter
                >
            </label>
            <label class="notas-field">
                <span>Desde</span>
                <input type="date" name="desde" value="<?= notas_h($desde) ?>">
            </label>
            <label class="notas-field">
                <span>Hasta</span>
                <input type="date" name="hasta" value="<?= notas_h($hasta) ?>">
            </label>
            <?php if ($isAdmin): ?>
                <label class="notas-field">
                    <span>Modulo</span>
                    <select name="modulo">
                        <?php foreach ($moduloOptions as $option): ?>
                            <option value="<?= notas_h($option) ?>" <?= $option === $modulo ? 'selected' : '' ?>>
                                <?= notas_h($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <label class="notas-field">
                    <span>Modulo</span>
                    <input type="text" value="Almacen" readonly>
                    <input type="hidden" name="modulo" value="Almacen">
                </label>
            <?php endif; ?>
            <div class="notas-filter-actions">
                <button type="submit" class="notas-btn notas-btn--primary">
                    <i class="bi bi-funnel"></i>
                    Filtrar
                </button>
                <a href="notas_almacen.php" class="notas-btn notas-btn--soft">
                    <i class="bi bi-x-circle"></i>
                    Limpiar
                </a>
            </div>
        </form>

        <section class="notas-table-card">
            <div class="notas-table-head">
                <div>
                    <h2>Historial de notas</h2>
                    <p>Doble click o Ver detalle para abrir los productos asociados.</p>
                </div>
                <span data-notas-visible-count><?= notas_h($totalNotas) ?> notas</span>
            </div>

            <div class="notas-table-wrap">
                <table class="notas-table" id="notasTable">
                    <thead>
                        <tr>
                            <th>Nota</th>
                            <th>Fecha</th>
                            <th>Modulo</th>
                            <th>Bus / Placa</th>
                            <th>Entregado a</th>
                            <th>Motivo</th>
                            <th>Productos</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$notas): ?>
                        <tr class="notas-empty-row">
                            <td colspan="8">
                                <i class="bi bi-inboxes"></i>
                                No hay notas para los filtros actuales.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($notas as $nota): ?>
                        <?php
                            $searchText = implode(' ', [
                                $nota['clm_nota_id'] ?? '',
                                $nota['nota_codigo'] ?? '',
                                $nota['clm_nota_fecha'] ?? '',
                                $nota['modulo'] ?? '',
                                $nota['unidad_label'] ?? '',
                                $nota['entregado_a'] ?? '',
                                $nota['motivo'] ?? '',
                                $nota['componentes'] ?? '',
                            ]);
                        ?>
                        <tr data-notas-row data-search="<?= notas_h(mb_strtolower($searchText, 'UTF-8')) ?>">
                            <td>
                                <div class="notas-note-code">
                                    <strong><?= notas_h($nota['nota_codigo'] ?? '-') ?></strong>
                                    <span>ID <?= notas_h($nota['clm_nota_id'] ?? '-') ?></span>
                                </div>
                            </td>
                            <td><?= notas_h(notas_fmt_datetime($nota['clm_nota_fecha'] ?? '')) ?></td>
                            <td><span class="notas-pill"><?= notas_h($nota['modulo'] ?? '-') ?></span></td>
                            <td><?= notas_h($nota['unidad_label'] ?? '-') ?></td>
                            <td><?= notas_h($nota['entregado_a'] ?? '-') ?></td>
                            <td class="notas-motive"><?= notas_h($nota['motivo'] ?? '-') ?></td>
                            <td>
                                <button
                                    type="button"
                                    class="notas-products-preview"
                                    data-nota-id="<?= (int)($nota['clm_nota_id'] ?? 0) ?>"
                                >
                                    <span><?= notas_h((int)($nota['items_count'] ?? 0)) ?> items</span>
                                    <small><?= notas_h(notas_fmt_qty($nota['cantidad_total'] ?? 0)) ?> und.</small>
                                </button>
                            </td>
                            <td>
                                <div class="notas-row-actions">
                                    <button
                                        type="button"
                                        class="notas-action"
                                        data-nota-id="<?= (int)($nota['clm_nota_id'] ?? 0) ?>"
                                    >
                                        <i class="bi bi-eye"></i>
                                        Ver detalle
                                    </button>
                                    <button
                                        type="button"
                                        class="notas-action notas-action--pdf"
                                        data-n360-note-download
                                        data-note-id="<?= (int)($nota['clm_nota_id'] ?? 0) ?>"
                                        data-note-serie="<?= notas_h($nota['clm_nota_serie'] ?? '') ?>"
                                    >
                                        <i class="bi bi-file-earmark-pdf"></i>
                                        PDF
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
    <?php n360_render_footer(); ?>
</div>

<div class="modal fade notas-detail-modal" id="notaDetalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header notas-detail-head">
                <div>
                    <span class="notas-eyebrow"><i class="bi bi-receipt"></i> Detalle de nota</span>
                    <h2 id="notaDetalleTitle">Nota</h2>
                    <p id="notaDetalleMeta">Cargando informacion...</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="notas-detail-status" id="notaDetalleStatus">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    Cargando detalle de nota...
                </div>

                <div class="notas-detail-content" id="notaDetalleContent" hidden>
                    <section class="notas-detail-kpis" id="notaDetalleKpis"></section>
                    <section class="notas-detail-grid" id="notaDetalleGrid"></section>

                    <div class="notas-products-head">
                        <div>
                            <h3>Productos asociados</h3>
                            <p id="notaDetalleProductosResumen">0 items</p>
                        </div>
                        <label class="notas-field notas-field--modal-search">
                            <span>Buscar producto</span>
                            <i class="bi bi-search"></i>
                            <input type="search" id="notaProductoSearch" placeholder="Codigo, producto u observacion..." autocomplete="off">
                        </label>
                    </div>

                    <div class="notas-detail-table-wrap">
                        <table class="notas-detail-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Codigo</th>
                                    <th>Producto</th>
                                    <th>Cantidad</th>
                                    <th>Tipo</th>
                                    <th>Observacion</th>
                                </tr>
                            </thead>
                            <tbody id="notaDetalleProductos"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button
                    type="button"
                    class="notas-btn notas-btn--primary"
                    id="notaDetallePdfBtn"
                    data-n360-note-download
                    hidden
                >
                    <i class="bi bi-file-earmark-pdf"></i>
                    Descargar PDF
                </button>
                <button type="button" class="notas-btn notas-btn--soft" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i>
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.N360_NOTAS_ALMACEN = {
    endpoint: 'notas_almacen.php'
};
window.N360_NOTA_PDF_CONFIG = {
    endpoint: '<?= notas_h(n360_base_url('php/nota_pdf_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoTicket: '<?= notas_h(n360_base_url('img/completo.png')) ?>',
    footerLabel: 'NORTE 360'
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_notas_common.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_entrada_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_entrada_bienes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_bienes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_tanqueada.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_abastecimiento.js') ?>"></script>
<script src="<?= n360_asset('assets/js/nota_pdf_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/notas_almacen_n360.js') ?>"></script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
