<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function comb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function comb_can_modulo(int $moduloId): bool
{
    if (($_SESSION['web_rol'] ?? '') === 'Admin') {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') {
        return true;
    }
    if (!is_array($permisos)) {
        return false;
    }

    return in_array($moduloId, array_map('intval', $permisos), true);
}

function comb_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function comb_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }

    comb_bind_params($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error ?: 'No se pudo ejecutar la consulta.');
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function comb_valid_date($value, string $fallback): string
{
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return ($date && $date->format('Y-m-d') === (string)$value) ? (string)$value : $fallback;
}

function comb_fmt_qty($value): string
{
    $formatted = number_format((float)($value ?? 0), 4, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function comb_fmt_money($value): string
{
    return 'S/ ' . number_format((float)($value ?? 0), 2, '.', ',');
}

function comb_fmt_pu($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, 4, '.', ',');
}

function comb_tipo_label(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    if ($tipo === 'ENTRADA') {
        return 'Entrada';
    }
    if ($tipo === 'SALIDA') {
        return 'Salida';
    }
    if ($tipo === 'INVENTARIADO') {
        return 'Inventariado';
    }
    return $tipo !== '' ? ucfirst(strtolower($tipo)) : '-';
}

function comb_tipo_class(string $tipo): string
{
    $tipo = strtolower(trim($tipo));
    if ($tipo === 'entrada') {
        return 'entrada';
    }
    if ($tipo === 'salida') {
        return 'salida';
    }
    if ($tipo === 'inventariado') {
        return 'inventariado';
    }
    return 'neutro';
}

function comb_fecha_display($value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime((string)$value);
    return $time ? date('d/m/Y H:i', $time) : (string)$value;
}

function comb_text($value, string $fallback = '-'): string
{
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function comb_vehicle_label($bus, $placa): string
{
    $bus = trim((string)($bus ?? ''));
    $placa = trim((string)($placa ?? ''));

    if ($bus !== '' && $placa !== '') {
        return $bus . ' (' . $placa . ')';
    }
    if ($bus !== '') {
        return $bus;
    }
    if ($placa !== '') {
        return $placa;
    }
    return '-';
}

function comb_price_display($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return 'S/ ' . number_format((float)$value, 4, '.', ',');
}

function comb_price_metric($value): string
{
    return ($value === null || $value === '') ? '' : (string)(float)$value;
}

function comb_hist_metric(array $rows, string $key): array
{
    $values = [];
    foreach ($rows as $row) {
        if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
            $values[] = (float)$row[$key];
        }
    }

    $count = count($values);
    return [
        'count' => count($rows),
        'min' => $count ? min($values) : null,
        'max' => $count ? max($values) : null,
        'avg' => $count ? array_sum($values) / $count : null,
        'last' => ($count && isset($rows[0][$key])) ? (float)$rows[0][$key] : null,
    ];
}

function comb_hist_products(array $rows): array
{
    $products = [];
    foreach ($rows as $row) {
        $product = trim((string)($row['producto'] ?? ''));
        if ($product !== '') {
            $products[$product] = $product;
        }
    }
    natcasesort($products);
    return array_values($products);
}

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

if (!comb_can_modulo(9)) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Historial de combustible'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

$today = date('Y-m-d');
$defaultDesde = date('Y-01-01');
$desde = comb_valid_date($_GET['desde'] ?? $defaultDesde, $defaultDesde);
$hasta = comb_valid_date($_GET['hasta'] ?? $today, $today);
if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$tipo = strtoupper(trim((string)($_GET['tipo'] ?? 'TODOS')));
$tiposValidos = ['TODOS', 'ENTRADA', 'SALIDA'];
if (!in_array($tipo, $tiposValidos, true)) {
    $tipo = 'TODOS';
}

$grifo = trim((string)($_GET['grifo'] ?? 'TODOS'));
if ($grifo !== 'TODOS' && !ctype_digit($grifo)) {
    $grifo = 'TODOS';
}

$producto = trim((string)($_GET['producto'] ?? 'TODOS'));
if ($producto !== 'TODOS' && !ctype_digit($producto)) {
    $producto = 'TODOS';
}

$gruposValidos = ['TODOS', 'CLIENTES CDN', 'CHIMBOTE EXPRESS'];
$grupo = strtoupper(trim((string)($_GET['grupo'] ?? 'TODOS')));
if (!in_array($grupo, $gruposValidos, true)) {
    $grupo = 'TODOS';
}

$placa = trim((string)($_GET['placa'] ?? ''));
$buscar = trim((string)($_GET['buscar'] ?? ''));

$rows = [];
$grifos = [];
$productos = [];
$histPrecios = [];
$histExtras = [];
$histPromedios = [];
$pageError = '';
$kpis = [
    'movimientos' => 0,
    'entradas' => 0.0,
    'salidas' => 0.0,
    'balance' => 0.0,
    'monto_entradas' => 0.0,
    'unidades' => 0,
];

try {
    $grifos = comb_fetch_all(
        $conn,
        "SELECT clm_esp_id, clm_esp_nombre, clm_esp_desc,
                CONCAT('(', clm_esp_nombre, ') ', COALESCE(clm_esp_desc, '')) AS nombre
         FROM view_listespacios_combustibles
         ORDER BY clm_esp_nombre ASC, clm_esp_desc ASC"
    );

    $productos = comb_fetch_all(
        $conn,
        "SELECT clm_alm_producto_id, clm_alm_producto_codigo, clm_alm_producto_NOMBRE, clm_alm_producto_unidad
         FROM tb_alm_producto
         WHERE clm_alm_producto_idCATEGORIA = 11
         ORDER BY clm_alm_producto_NOMBRE ASC"
    );


    try {
        $histPrecios = comb_fetch_all(
            $conn,
            "SELECT
                CONCAT(h.clm_alm_preccomb_fecha, ' ', h.clm_alm_preccomb_hora) AS fechahora,
                p.clm_alm_producto_NOMBRE AS producto,
                IFNULL(p.clm_alm_producto_unidad, '') AS unidad,
                h.clm_alm_preccomb_preciounitario AS precio,
                h.clm_alm_preccomb_id AS id_hist
             FROM tb_pasadoprecio_combustibles h
             JOIN tb_alm_producto p ON p.clm_alm_producto_id = h.clm_alm_preccomb_idproducto
             ORDER BY h.clm_alm_preccomb_fecha DESC,
                      h.clm_alm_preccomb_hora DESC,
                      h.clm_alm_preccomb_id DESC
             LIMIT 3000"
        );
    } catch (Throwable $e) {
        $histPrecios = [];
    }

    try {
        $histExtras = comb_fetch_all(
            $conn,
            "SELECT
                CONCAT(h.clm_alm_extrapreccomb_fecha, ' ', h.clm_alm_extrapreccomb_hora) AS fechahora,
                p.clm_alm_producto_NOMBRE AS producto,
                IFNULL(p.clm_alm_producto_unidad, '') AS unidad,
                h.clm_alm_extrapreccomb_preciounitario AS extra,
                h.clm_alm_extrapreccomb_id AS id_hist
             FROM tb_pasadoextraprecio_combustibles h
             JOIN tb_alm_producto p ON p.clm_alm_producto_id = h.clm_alm_extrapreccomb_idproducto
             ORDER BY h.clm_alm_extrapreccomb_fecha DESC,
                      h.clm_alm_extrapreccomb_hora DESC,
                      h.clm_alm_extrapreccomb_id DESC
             LIMIT 3000"
        );
    } catch (Throwable $e) {
        $histExtras = [];
    }

    try {
        $histPromedios = comb_fetch_all(
            $conn,
            "SELECT
                h.fechahora,
                p.clm_alm_producto_NOMBRE AS producto,
                IFNULL(p.clm_alm_producto_unidad, '') AS unidad,
                IFNULL(CONCAT('(', e.clm_esp_nombre, ') ', e.clm_esp_desc), '-') AS grifo,
                h.pu_base,
                h.pu_entrada,
                h.pu_promedio,
                h.id_mov,
                h.fuente,
                h.id_hist
             FROM tb_hist_pu_promedio_combustible h
             JOIN tb_alm_producto p ON p.clm_alm_producto_id = h.id_producto
             LEFT JOIN view_listespacios_combustibles e ON e.clm_esp_id = h.id_grifo
             ORDER BY h.fechahora DESC, h.id_hist DESC
             LIMIT 3000"
        );
    } catch (Throwable $e) {
        $histPromedios = [];
    }
    $where = [
        'p.clm_alm_producto_idCATEGORIA = 11',
        'm.clm_alm_mov_fecha_registro >= ?',
        'm.clm_alm_mov_fecha_registro <= ?',
    ];
    $types = 'ss';
    $params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

    if ($tipo !== 'TODOS') {
        $where[] = "UPPER(TRIM(CAST(m.clm_alm_mov_TIPO AS CHAR))) = ?";
        $types .= 's';
        $params[] = $tipo;
    }

    if ($producto !== 'TODOS') {
        $where[] = 'm.clm_alm_mov_idPRODUCTO = ?';
        $types .= 'i';
        $params[] = (int)$producto;
    }

    if ($grifo !== 'TODOS') {
        $where[] = 'm.clm_alm_mov_orgn = ?';
        $types .= 'i';
        $params[] = (int)$grifo;
    }

    $grupoCase = "CASE
        WHEN pl.`clm_placas_DUEÑO` IS NOT NULL AND pl.`clm_placas_ESTADO` IS NOT NULL THEN 'CLIENTES CDN'
        WHEN pl.`clm_placas_DUEÑO` IS NULL AND pl.`clm_placas_ESTADO` IS NULL THEN 'CHIMBOTE EXPRESS'
        ELSE 'CHIMBOTE EXPRESS'
    END";

    if ($grupo !== 'TODOS') {
        $where[] = "($grupoCase) = ?";
        $types .= 's';
        $params[] = $grupo;
    }

    if ($placa !== '') {
        $likePlaca = '%' . $placa . '%';
        $where[] = "(
            CONVERT(pl.clm_placas_BUS USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(pl.clm_placas_PLACA USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(m.clm_alm_mov_placa USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        )";
        $types .= 'sss';
        array_push($params, $likePlaca, $likePlaca, $likePlaca);
    }

    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $where[] = "(
            CONVERT(p.clm_alm_producto_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(p.clm_alm_producto_NOMBRE USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(e.clm_esp_nombre USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(e.clm_esp_desc USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(ns.clm_nota_sco USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(m.clm_alm_mov_OBSERVACION USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(pl.clm_placas_BUS USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci OR
            CONVERT(pl.clm_placas_PLACA USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        )";
        $types .= 'ssssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql = "SELECT
            m.clm_alm_mov_id AS id_mov,
            m.clm_alm_mov_fecha_registro AS fecha,
            UPPER(TRIM(CAST(m.clm_alm_mov_TIPO AS CHAR))) AS tipo,
            COALESCE(NULLIF(ns.clm_nota_sco, ''), '-') AS nota,
            CONCAT('(', COALESCE(e.clm_esp_nombre, '-'), ') ', COALESCE(e.clm_esp_desc, '')) AS grifo,
            m.clm_alm_mov_orgn AS id_grifo,
            CONCAT('(', TRIM(COALESCE(p.clm_alm_producto_codigo, '')), ') ', TRIM(p.clm_alm_producto_NOMBRE)) AS producto,
            COALESCE(p.clm_alm_producto_unidad, '') AS unidad,
            p.clm_alm_producto_id AS id_producto,
            COALESCE(NULLIF(TRIM(pl.clm_placas_BUS), ''), '') AS bus,
            COALESCE(NULLIF(TRIM(pl.clm_placas_PLACA), ''), NULLIF(TRIM(m.clm_alm_mov_placa), ''), '') AS placa,
            COALESCE(m.clm_alm_mov_cantidad, 0) AS cantidad,
            CASE
                WHEN COALESCE(m.clm_alm_mov_cantidad, 0) > 0 THEN COALESCE(m.clm_alm_mov_monto, 0) / m.clm_alm_mov_cantidad
                ELSE NULL
            END AS precio_unitario,
            COALESCE(m.clm_alm_mov_monto, 0) AS monto,
            COALESCE(NULLIF(TRIM(m.clm_alm_mov_OBSERVACION), ''), '-') AS observacion,
            $grupoCase AS grupo_placa
        FROM tb_alm_movimientos m
        INNER JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
        LEFT JOIN view_listespacios_combustibles e ON e.clm_esp_id = m.clm_alm_mov_orgn
        LEFT JOIN tb_placas pl ON CAST(pl.clm_placas_id AS CHAR) = CAST(m.clm_alm_mov_placa AS CHAR)
        LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.clm_alm_mov_fecha_registro DESC, m.clm_alm_mov_id DESC
        LIMIT 3000";

    $rows = comb_fetch_all($conn, $sql, $types, $params);

    $unidadSet = [];
    foreach ($rows as $row) {
        $tipoRow = strtoupper((string)($row['tipo'] ?? ''));
        $cantidad = (float)($row['cantidad'] ?? 0);
        $monto = (float)($row['monto'] ?? 0);

        $kpis['movimientos']++;
        if ($tipoRow === 'ENTRADA') {
            $kpis['entradas'] += $cantidad;
            $kpis['balance'] += $cantidad;
            $kpis['monto_entradas'] += $monto;
        } elseif ($tipoRow === 'SALIDA') {
            $kpis['salidas'] += $cantidad;
            $kpis['balance'] -= $cantidad;
            $unidadKey = comb_vehicle_label($row['bus'] ?? '', $row['placa'] ?? '');
            if ($unidadKey !== '-') {
                $unidadSet[$unidadKey] = true;
            }
        }
    }
    $kpis['unidades'] = count($unidadSet);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
    $rows = [];
}

$histPrecioKpis = comb_hist_metric($histPrecios, 'precio');
$histExtraKpis = comb_hist_metric($histExtras, 'extra');
$histPromedioKpis = comb_hist_metric($histPromedios, 'pu_promedio');
$histPrecioProductos = comb_hist_products($histPrecios);
$histExtraProductos = comb_hist_products($histExtras);
$histPromedioProductos = comb_hist_products($histPromedios);

$nombreUsuario = trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? ''));
if ($nombreUsuario === '') {
    $nombreUsuario = $_SESSION['usuario'] ?? 'Usuario';
}
$dniUsuario = $_SESSION['dni'] ?? '';

$selectedGrifoLabel = 'Todos los grifos';
foreach ($grifos as $g) {
    if ($grifo !== 'TODOS' && (int)$g['clm_esp_id'] === (int)$grifo) {
        $selectedGrifoLabel = $g['nombre'];
        break;
    }
}

$selectedProductoLabel = 'Todos los combustibles';
foreach ($productos as $p) {
    if ($producto !== 'TODOS' && (int)$p['clm_alm_producto_id'] === (int)$producto) {
        $selectedProductoLabel = trim(($p['clm_alm_producto_codigo'] ?? '') . ' - ' . ($p['clm_alm_producto_NOMBRE'] ?? ''));
        break;
    }
}

$tipoLabel = $tipo === 'TODOS' ? 'Todos los tipos' : comb_tipo_label($tipo);
$grupoLabel = $grupo === 'TODOS' ? 'Todos los grupos' : $grupo;
$placaLabel = $placa === '' ? 'Todas las placas' : $placa;
$periodoLabel = date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));

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
    <title>Combustible | Historial Norte360</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/inventario_stock_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/combustible_historial_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Combustible', 'subtitle' => 'Historial operativo']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content n360-stock-page n360-comb-page">
        <section class="stock-hero comb-hero">
            <div class="comb-hero-main">
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-fuel-pump-fill"></i> Combustible - Historial operativo</span>
                    <h1>Historial de combustible</h1>
                    <p>Lectura operativa de entradas AB, salidas CM y movimientos por grifo, producto y unidad.</p>
                </div>
            </div>
            <div class="stock-hero-actions comb-hero-actions">
                <button type="button" class="stock-btn stock-btn--primary" data-combustible-export-pdf>
                    <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
                </button>
                <button type="button" class="stock-btn stock-btn--soft" data-combustible-export-excel>
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </button>
            </div>
        </section>

        <section class="comb-history-tools" aria-label="Historiales de precios de combustible">
            <div>
                <span>Consultas auxiliares</span>
                <strong>Precios, extras y PU promedio</strong>
            </div>
            <div class="comb-history-tools__actions">
                <button type="button" class="stock-btn stock-btn--soft" data-comb-open-modal="modalHistPrecio">
                    <i class="bi bi-clock-history"></i> Hist. precios
                </button>
                <button type="button" class="stock-btn stock-btn--soft" data-comb-open-modal="modalHistExtra">
                    <i class="bi bi-plus-circle"></i> Precio extra
                </button>
                <button type="button" class="stock-btn stock-btn--soft" data-comb-open-modal="modalHistPromedio">
                    <i class="bi bi-graph-up-arrow"></i> PU promedio
                </button>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert stock-alert--danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= comb_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="stock-kpis comb-kpis" aria-label="Resumen de combustible">
            <article class="stock-kpi">
                <span>Movimientos</span>
                <strong data-combustible-visible-count><?= number_format($kpis['movimientos']) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Entradas (cantidad)</span>
                <strong><?= comb_fmt_qty($kpis['entradas']) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--red">
                <span>Salidas (cantidad)</span>
                <strong><?= comb_fmt_qty($kpis['salidas']) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--blue">
                <span>Saldo neto (cant)</span>
                <strong><?= comb_fmt_qty($kpis['balance']) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--amber">
                <span>Entradas (S/.)</span>
                <strong><?= comb_fmt_money($kpis['monto_entradas']) ?></strong>
            </article>
        </section>

        <form class="stock-filters stock-filters--history comb-filters" method="GET" autocomplete="off">
            <label class="stock-field">
                <span>Desde</span>
                <input type="date" name="desde" value="<?= comb_h($desde) ?>">
            </label>
            <label class="stock-field">
                <span>Hasta</span>
                <input type="date" name="hasta" value="<?= comb_h($hasta) ?>">
            </label>
            <label class="stock-field">
                <span>Tipo</span>
                <select name="tipo">
                    <?php foreach ($tiposValidos as $tipoOption): ?>
                        <option value="<?= comb_h($tipoOption) ?>" <?= $tipo === $tipoOption ? 'selected' : '' ?>>
                            <?= comb_h($tipoOption === 'TODOS' ? 'TODOS' : comb_tipo_label($tipoOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Producto</span>
                <select name="producto">
                    <option value="TODOS">TODOS</option>
                    <?php foreach ($productos as $p): ?>
                        <option value="<?= (int)$p['clm_alm_producto_id'] ?>" <?= $producto !== 'TODOS' && (int)$producto === (int)$p['clm_alm_producto_id'] ? 'selected' : '' ?>>
                            <?= comb_h(($p['clm_alm_producto_codigo'] ?? '') . ' - ' . ($p['clm_alm_producto_NOMBRE'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Grifo</span>
                <select name="grifo">
                    <option value="TODOS">TODOS</option>
                    <?php foreach ($grifos as $g): ?>
                        <option value="<?= (int)$g['clm_esp_id'] ?>" <?= $grifo !== 'TODOS' && (int)$grifo === (int)$g['clm_esp_id'] ? 'selected' : '' ?>>
                            <?= comb_h($g['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Grupo</span>
                <select name="grupo">
                    <?php foreach ($gruposValidos as $grupoOption): ?>
                        <option value="<?= comb_h($grupoOption) ?>" <?= $grupo === $grupoOption ? 'selected' : '' ?>>
                            <?= comb_h($grupoOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Placa contiene</span>
                <input type="text" name="placa" value="<?= comb_h($placa) ?>" placeholder="ABC-321 o 158" autocomplete="off">
            </label>
            <label class="stock-field stock-field--search comb-filter-search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input type="search" name="buscar" value="<?= comb_h($buscar) ?>" placeholder="Nota, producto, observacion..." data-combustible-search autocomplete="off">
            </label>
            <div class="stock-filter-actions">
                <button type="submit" class="stock-btn stock-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button>
                <a class="stock-btn stock-btn--soft" href="historial_combustible.php"><i class="bi bi-x-circle"></i> Limpiar</a>
            </div>
        </form>

        <section class="stock-table-card comb-table-card">
            <header class="stock-table-head">
                <div>
                    <h2>Bitacora de movimientos</h2>
                    <p>Columnas operativas equivalentes al historial de combustible del sistema de escritorio.</p>
                </div>
                <span class="stock-pill" data-combustible-visible-pill><?= number_format($kpis['movimientos']) ?> registros</span>
            </header>

            <div class="stock-table-wrap">
                <table class="stock-table comb-table" data-combustible-table>
                    <thead>
                        <tr>
                            <th>Mov.</th>
                            <th>Fecha/Hora</th>
                            <th>Tipo</th>
                            <th>Correlativo Nota</th>
                            <th>Grifo</th>
                            <th>Producto</th>
                            <th>Und</th>
                            <th>Placa</th>
                            <th class="stock-num">Cantidad</th>
                            <th class="stock-num">PU (calc)</th>
                            <th class="stock-num">Monto</th>
                            <th>Observacion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr data-combustible-empty>
                                <td colspan="12" class="stock-empty">No hay movimientos para los filtros actuales.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row):
                                $tipoRow = strtoupper((string)($row['tipo'] ?? ''));
                                $searchBlob = implode(' ', [
                                    $row['id_mov'] ?? '',
                                    $row['fecha'] ?? '',
                                    $row['tipo'] ?? '',
                                    $row['nota'] ?? '',
                                    $row['grifo'] ?? '',
                                    $row['producto'] ?? '',
                                    $row['unidad'] ?? '',
                                    $row['bus'] ?? '',
                                    $row['placa'] ?? '',
                                    $row['observacion'] ?? '',
                                ]);
                                $unidadTxt = comb_vehicle_label($row['bus'] ?? '', $row['placa'] ?? '');
                            ?>
                                <tr data-combustible-row
                                    data-search="<?= comb_h(strtolower($searchBlob)) ?>"
                                    data-tipo="<?= comb_h($tipoRow) ?>"
                                    data-cantidad="<?= comb_h((string)($row['cantidad'] ?? 0)) ?>"
                                    data-monto="<?= comb_h((string)($row['monto'] ?? 0)) ?>">
                                    <td><span class="stock-code"><?= comb_h($row['id_mov'] ?? '-') ?></span></td>
                                    <td><?= comb_h(comb_fecha_display($row['fecha'] ?? '')) ?></td>
                                    <td><span class="comb-type comb-type--<?= comb_h(comb_tipo_class($tipoRow)) ?>"><?= comb_h(comb_tipo_label($tipoRow)) ?></span></td>
                                    <td><span class="stock-code"><?= comb_h(comb_text($row['nota'] ?? '')) ?></span></td>
                                    <td><?= comb_h(comb_text($row['grifo'] ?? '')) ?></td>
                                    <td><div class="comb-product"><strong><?= comb_h(comb_text($row['producto'] ?? '')) ?></strong></div></td>
                                    <td><?= comb_h(comb_text($row['unidad'] ?? '')) ?></td>
                                    <td><span class="comb-unit"><?= comb_h($unidadTxt) ?></span></td>
                                    <td class="stock-num"><?= comb_h(comb_fmt_qty($row['cantidad'] ?? 0)) ?></td>
                                    <td class="stock-num"><?= comb_h(comb_fmt_pu($row['precio_unitario'] ?? null)) ?></td>
                                    <td class="stock-num"><?= comb_h(comb_fmt_money($row['monto'] ?? 0)) ?></td>
                                    <td><?= comb_h(comb_text($row['observacion'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>


<div class="modal fade comb-price-modal" id="modalHistPrecio" tabindex="-1" aria-labelledby="modalHistPrecioLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header comb-price-modal__header">
                <div>
                    <span><i class="bi bi-clock-history"></i> Historial operativo</span>
                    <h2 class="modal-title" id="modalHistPrecioLabel">Historial de precios de combustible</h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-comb-modal-close aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="comb-price-modal__toolbar">
                    <label>
                        <span>Producto</span>
                        <select data-comb-modal-filter>
                            <option value="__ALL__">Todos los productos</option>
                            <?php foreach ($histPrecioProductos as $prod): ?>
                                <option value="<?= comb_h($prod) ?>"><?= comb_h($prod) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="stock-btn stock-btn--primary" data-comb-modal-export-pdf data-modal-id="modalHistPrecio" data-report-title="HISTORIAL DE PRECIOS DE COMBUSTIBLE" data-doc-code="COM_RPT_HIST_PRECIOS_COMB">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </button>
                </div>
                <div class="comb-price-kpis">
                    <article><span>Cambios</span><strong data-modal-kpi="count"><?= number_format($histPrecioKpis['count']) ?></strong></article>
                    <article><span>Minimo</span><strong data-modal-kpi="min"><?= comb_price_display($histPrecioKpis['min']) ?></strong></article>
                    <article><span>Maximo</span><strong data-modal-kpi="max"><?= comb_price_display($histPrecioKpis['max']) ?></strong></article>
                    <article><span>Promedio</span><strong data-modal-kpi="avg"><?= comb_price_display($histPrecioKpis['avg']) ?></strong></article>
                    <article><span>Ultimo</span><strong data-modal-kpi="last"><?= comb_price_display($histPrecioKpis['last']) ?></strong></article>
                </div>
                <div class="stock-table-wrap comb-modal-table-wrap">
                    <table class="stock-table comb-modal-table">
                        <thead>
                            <tr>
                                <th>Fecha/Hora</th>
                                <th>Producto</th>
                                <th>Und</th>
                                <th class="stock-num">Precio Unit.</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$histPrecios): ?>
                                <tr><td colspan="5" class="stock-empty">No hay historial de precios registrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($histPrecios as $row): ?>
                                    <tr data-comb-price-row data-product="<?= comb_h($row['producto'] ?? '') ?>" data-metric-value="<?= comb_h(comb_price_metric($row['precio'] ?? null)) ?>">
                                        <td><?= comb_h(comb_fecha_display($row['fechahora'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['producto'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['unidad'] ?? '')) ?></td>
                                        <td class="stock-num"><?= comb_h(comb_price_display($row['precio'] ?? null)) ?></td>
                                        <td><span class="stock-code"><?= comb_h($row['id_hist'] ?? '-') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade comb-price-modal" id="modalHistExtra" tabindex="-1" aria-labelledby="modalHistExtraLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header comb-price-modal__header">
                <div>
                    <span><i class="bi bi-plus-circle"></i> Historial operativo</span>
                    <h2 class="modal-title" id="modalHistExtraLabel">Historial de precios extra</h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-comb-modal-close aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="comb-price-modal__toolbar">
                    <label>
                        <span>Producto</span>
                        <select data-comb-modal-filter>
                            <option value="__ALL__">Todos los productos</option>
                            <?php foreach ($histExtraProductos as $prod): ?>
                                <option value="<?= comb_h($prod) ?>"><?= comb_h($prod) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="stock-btn stock-btn--primary" data-comb-modal-export-pdf data-modal-id="modalHistExtra" data-report-title="HISTORIAL DE EXTRAS DE COMBUSTIBLE" data-doc-code="COM_RPT_HIST_EXTRAS_COMB">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </button>
                </div>
                <div class="comb-price-kpis">
                    <article><span>Extras</span><strong data-modal-kpi="count"><?= number_format($histExtraKpis['count']) ?></strong></article>
                    <article><span>Minimo</span><strong data-modal-kpi="min"><?= comb_price_display($histExtraKpis['min']) ?></strong></article>
                    <article><span>Maximo</span><strong data-modal-kpi="max"><?= comb_price_display($histExtraKpis['max']) ?></strong></article>
                    <article><span>Promedio</span><strong data-modal-kpi="avg"><?= comb_price_display($histExtraKpis['avg']) ?></strong></article>
                    <article><span>Ultimo</span><strong data-modal-kpi="last"><?= comb_price_display($histExtraKpis['last']) ?></strong></article>
                </div>
                <div class="stock-table-wrap comb-modal-table-wrap">
                    <table class="stock-table comb-modal-table">
                        <thead>
                            <tr>
                                <th>Fecha/Hora</th>
                                <th>Producto</th>
                                <th>Und</th>
                                <th class="stock-num">Extra Unit.</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$histExtras): ?>
                                <tr><td colspan="5" class="stock-empty">No hay historial de extras registrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($histExtras as $row): ?>
                                    <tr data-comb-price-row data-product="<?= comb_h($row['producto'] ?? '') ?>" data-metric-value="<?= comb_h(comb_price_metric($row['extra'] ?? null)) ?>">
                                        <td><?= comb_h(comb_fecha_display($row['fechahora'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['producto'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['unidad'] ?? '')) ?></td>
                                        <td class="stock-num"><?= comb_h(comb_price_display($row['extra'] ?? null)) ?></td>
                                        <td><span class="stock-code"><?= comb_h($row['id_hist'] ?? '-') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade comb-price-modal comb-price-modal--wide" id="modalHistPromedio" tabindex="-1" aria-labelledby="modalHistPromedioLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header comb-price-modal__header">
                <div>
                    <span><i class="bi bi-graph-up-arrow"></i> Historial operativo</span>
                    <h2 class="modal-title" id="modalHistPromedioLabel">Historial de PU promedio</h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-comb-modal-close aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="comb-price-note">
                    <strong>Que es el PU promedio?</strong>
                    <p>Se calcula con la regla operativa del sistema: PU promedio = (PU base + PU entrada) / 2. El resultado queda como nueva referencia para salidas posteriores.</p>
                </div>
                <div class="comb-price-modal__toolbar">
                    <label>
                        <span>Producto</span>
                        <select data-comb-modal-filter>
                            <option value="__ALL__">Todos los productos</option>
                            <?php foreach ($histPromedioProductos as $prod): ?>
                                <option value="<?= comb_h($prod) ?>"><?= comb_h($prod) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="stock-btn stock-btn--primary" data-comb-modal-export-pdf data-modal-id="modalHistPromedio" data-report-title="HISTORIAL DE PU PROMEDIO DE COMBUSTIBLE" data-doc-code="COM_RPT_HIST_PREC_PROM_COMB">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </button>
                </div>
                <div class="comb-price-kpis">
                    <article><span>Registros</span><strong data-modal-kpi="count"><?= number_format($histPromedioKpis['count']) ?></strong></article>
                    <article><span>Minimo</span><strong data-modal-kpi="min"><?= comb_price_display($histPromedioKpis['min']) ?></strong></article>
                    <article><span>Maximo</span><strong data-modal-kpi="max"><?= comb_price_display($histPromedioKpis['max']) ?></strong></article>
                    <article><span>Promedio global</span><strong data-modal-kpi="avg"><?= comb_price_display($histPromedioKpis['avg']) ?></strong></article>
                    <article><span>Ultimo</span><strong data-modal-kpi="last"><?= comb_price_display($histPromedioKpis['last']) ?></strong></article>
                </div>
                <div class="stock-table-wrap comb-modal-table-wrap">
                    <table class="stock-table comb-modal-table comb-modal-table--wide">
                        <thead>
                            <tr>
                                <th>Fecha/Hora</th>
                                <th>Producto</th>
                                <th>Und</th>
                                <th>Grifo</th>
                                <th class="stock-num">PU base</th>
                                <th class="stock-num">PU entrada</th>
                                <th class="stock-num">PU promedio</th>
                                <th>Mov.</th>
                                <th>Fuente</th>
                                <th>ID hist.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$histPromedios): ?>
                                <tr><td colspan="10" class="stock-empty">No hay historial de PU promedio registrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($histPromedios as $row): ?>
                                    <tr data-comb-price-row data-product="<?= comb_h($row['producto'] ?? '') ?>" data-metric-value="<?= comb_h(comb_price_metric($row['pu_promedio'] ?? null)) ?>">
                                        <td><?= comb_h(comb_fecha_display($row['fechahora'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['producto'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['unidad'] ?? '')) ?></td>
                                        <td><?= comb_h(comb_text($row['grifo'] ?? '')) ?></td>
                                        <td class="stock-num"><?= comb_h(comb_price_display($row['pu_base'] ?? null)) ?></td>
                                        <td class="stock-num"><?= comb_h(comb_price_display($row['pu_entrada'] ?? null)) ?></td>
                                        <td class="stock-num"><?= comb_h(comb_price_display($row['pu_promedio'] ?? null)) ?></td>
                                        <td><span class="stock-code"><?= comb_h($row['id_mov'] ?? '-') ?></span></td>
                                        <td><?= comb_h(comb_text($row['fuente'] ?? '')) ?></td>
                                        <td><span class="stock-code"><?= comb_h($row['id_hist'] ?? '-') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php n360_render_footer(); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.4/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script>
window.N360_COMBUSTIBLE_REPORT = {
    title: 'HISTORIAL DE COMBUSTIBLE',
    subtitle: 'Movimientos operativos por grifo',
    docCode: 'COM_RPT_HIST_COMB',
    period: <?= json_encode($periodoLabel, JSON_UNESCAPED_UNICODE) ?>,
    filters: <?= json_encode([$tipoLabel, $selectedProductoLabel, $selectedGrifoLabel, $grupoLabel, $placaLabel], JSON_UNESCAPED_UNICODE) ?>,
    generatedBy: <?= json_encode($nombreUsuario, JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)$dniUsuario, JSON_UNESCAPED_UNICODE) ?>,
    fileBase: <?= json_encode('Movimientos_Combustible_' . date('Ymd_His'), JSON_UNESCAPED_UNICODE) ?>,
    logoLeft: <?= json_encode(n360_asset('img/icon.png'), JSON_UNESCAPED_SLASHES) ?>,
    logoRight: <?= json_encode(n360_asset('img/norte360_black.png'), JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= n360_asset('assets/js/combustible_historial_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
</body>
</html>