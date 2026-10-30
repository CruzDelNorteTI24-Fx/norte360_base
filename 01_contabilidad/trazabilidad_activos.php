<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
require_once __DIR__ . '/../layout/quick_scan_n360.php';
require_once __DIR__ . '/../layout/bus_lookup_n360.php';

$canTraceContabilidad = n360_is_admin() || ($_SESSION['permisos'] ?? null) === 'all' || n360_puede_modulo(12);
if (!$canTraceContabilidad) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Trazabilidad de activos'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/../01_almacen/movimiento_backend.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

function trace_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function trace_fmt_dt($value): string {
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return '-';
    }

    $ts = strtotime($raw);
    return $ts ? date('d/m/Y H:i', $ts) : $raw;
}

function trace_status_class($estado): string {
    $estado = strtoupper(trim((string)$estado));
    if ($estado === 'CONSUMIDO') {
        return 'is-consumed';
    }
    if ($estado === 'GENERADO') {
        return 'is-active';
    }
    return 'is-pending';
}

function trace_safe_file($value): string {
    $text = preg_replace('/[^\w.\-]+/u', '_', trim((string)$value));
    $text = trim((string)$text, '_');
    return $text !== '' ? substr($text, 0, 120) : 'etiqueta';
}

function trace_filter_value(string $key): string {
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
}

if (empty($_SESSION['conta_trace_csrf'])) {
    $_SESSION['conta_trace_csrf'] = bin2hex(random_bytes(24));
}

$buscar = trace_filter_value('buscar');
$estado = strtoupper(trace_filter_value('estado') ?: 'ACTIVOS');
$sede = max(-1, (int)trace_filter_value('sede'));
$productoFiltro = max(0, (int)trace_filter_value('producto'));
$categoriaFiltro = max(0, (int)trace_filter_value('categoria'));
$usuarioFiltro = max(0, (int)trace_filter_value('usuario'));
$detalleFiltro = trace_filter_value('detalle');
$notaFiltro = trace_filter_value('nota');
$dates = [];
foreach (['etiqueta_desde', 'etiqueta_hasta', 'movimiento_desde', 'movimiento_hasta'] as $key) {
    $dates[$key] = trace_filter_value($key);
}
$moreFilters = $productoFiltro || $categoriaFiltro || $usuarioFiltro || $notaFiltro !== '' || implode('', $dates) !== '';
$allowedEstados = ['ACTIVOS', 'TODOS', 'GENERADO', 'PENDIENTE', 'CONSUMIDO'];
if (!in_array($estado, $allowedEstados, true)) {
    $estado = 'ACTIVOS';
}

$labels = [];
$sedes = [];
$productos = [];
$categorias = [];
$usuarios = [];
$stats = [
    'total' => 0,
    'activos' => 0,
    'consumidos' => 0,
    'sedes' => 0,
];
$pageError = '';
$locationDetailEnabled = alm_activos_detalle_disponible($conn);
$locationDetailSql = $locationDetailEnabled ? 'e.clm_alm_etiquetado_ubicacion_detalle' : 'NULL';
$locationSuggestions = [];

try {
    foreach ($dates as $value) {
        if ($value !== '' && (!preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]))) {
            throw new InvalidArgumentException('Selecciona fechas validas para los filtros.');
        }
    }
    foreach (['etiqueta', 'movimiento'] as $prefix) {
        if ($dates[$prefix . '_desde'] !== '' && $dates[$prefix . '_hasta'] !== ''
            && $dates[$prefix . '_desde'] > $dates[$prefix . '_hasta']) {
            [$dates[$prefix . '_desde'], $dates[$prefix . '_hasta']] = [$dates[$prefix . '_hasta'], $dates[$prefix . '_desde']];
        }
    }
    $locationSuggestions = alm_activos_detalles_sugeridos($conn);
    $sedes = alm_fetch_all($conn, "
        SELECT clm_sedes_id AS id, clm_sedes_name AS nombre
        FROM tb_sedes
        ORDER BY clm_sedes_name ASC
    ");

    $stateExpr = "UPPER(COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'PENDIENTE'))";
    $scopeExpr = "(
        m.clm_alm_mov_orgn = 12
        OR UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), '')) = 'ACTIVOS'
        OR UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), '')) = 'ACTIVO_FIJO'
    )";

    $where = [$scopeExpr];
    $productos = alm_fetch_all($conn, "
        SELECT DISTINCT p.clm_alm_producto_id AS id, p.clm_alm_producto_codigo AS codigo,
            p.clm_alm_producto_NOMBRE AS nombre, c.clm_alm_categoria_id AS categoria_id,
            CONCAT_WS(' ', c.clm_alm_categoria_NOMBRE, c.clm_alm_categoria_DESCRIPCION) AS categoria
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = e.clm_alm_etiquetado_idPRODUCTO
        JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
        JOIN tb_alm_categoria c ON c.clm_alm_categoria_id = p.clm_alm_producto_idCATEGORIA
        WHERE $scopeExpr
        ORDER BY p.clm_alm_producto_NOMBRE, p.clm_alm_producto_id
    ");
    foreach ($productos as $productOption) {
        $categorias[(int)$productOption['categoria_id']] = $productOption['categoria'];
    }
    asort($categorias);
    $usuarios = alm_fetch_all($conn, "
        SELECT DISTINCT m.clm_alm_mov_iduser AS id,
            COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u.usuario), ''), CONCAT('Usuario ', m.clm_alm_mov_iduser)) AS nombre
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = e.clm_alm_etiquetado_idPRODUCTO
        JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
        LEFT JOIN tb_usuarios u ON u.id_usuario = m.clm_alm_mov_iduser
        WHERE $scopeExpr
        ORDER BY nombre
    ");
    $types = '';
    $params = [];

    if ($estado === 'ACTIVOS') {
        $where[] = "$stateExpr <> 'CONSUMIDO'";
    } elseif ($estado !== 'TODOS') {
        $where[] = "$stateExpr = ?";
        $types .= 's';
        $params[] = $estado;
    }

    if ($sede > 0) {
        $where[] = 'e.clm_alm_etiquetado_oficina_destino = ?';
        $types .= 'i';
        $params[] = $sede;
    } elseif ($sede === -1) {
        $where[] = '(e.clm_alm_etiquetado_oficina_destino IS NULL OR e.clm_alm_etiquetado_oficina_destino = 0)';
    }
    foreach (['p.clm_alm_producto_id' => $productoFiltro, 'p.clm_alm_producto_idCATEGORIA' => $categoriaFiltro,
        'm.clm_alm_mov_iduser' => $usuarioFiltro] as $column => $value) {
        if ($value > 0) {
            $where[] = "$column = ?";
            $types .= 'i';
            $params[] = $value;
        }
    }
    foreach ([$locationDetailSql => $detalleFiltro, "COALESCE(NULLIF(TRIM(n.clm_nota_sco), ''), CONCAT('MOV-', m.clm_alm_mov_id))" => $notaFiltro] as $column => $value) {
        if ($value !== '') {
            $where[] = "$column LIKE CONCAT('%', ?, '%')";
            $types .= 's';
            $params[] = $value;
        }
    }
    foreach (['etiqueta' => 'e.clm_alm_etiquetado_FECHA', 'movimiento' => 'm.clm_alm_mov_fecha_registro'] as $prefix => $column) {
        if ($dates[$prefix . '_desde'] !== '') {
            $where[] = "$column >= ?";
            $types .= 's';
            $params[] = $dates[$prefix . '_desde'] . ' 00:00:00';
        }
        if ($dates[$prefix . '_hasta'] !== '') {
            $where[] = "$column <= ?";
            $types .= 's';
            $params[] = $dates[$prefix . '_hasta'] . ' 23:59:59.999999';
        }
    }

    if ($buscar !== '') {
        $where[] = "(
            e.clm_etiquetado_CODIGO LIKE CONCAT('%', ?, '%')
            OR p.clm_alm_producto_codigo LIKE CONCAT('%', ?, '%')
            OR p.clm_alm_producto_NOMBRE LIKE CONCAT('%', ?, '%')
            OR COALESCE(n.clm_nota_sco, '') LIKE CONCAT('%', ?, '%')
            OR COALESCE(s.clm_sedes_name, '') LIKE CONCAT('%', ?, '%')
            OR $locationDetailSql LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'ssssss';
        array_push($params, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar);
    }

    $whereSql = implode(' AND ', $where);

    $stats = array_merge($stats, alm_fetch_one($conn, "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN $stateExpr <> 'CONSUMIDO' THEN 1 ELSE 0 END) AS activos,
            SUM(CASE WHEN $stateExpr = 'CONSUMIDO' THEN 1 ELSE 0 END) AS consumidos,
            COUNT(DISTINCT CASE WHEN e.clm_alm_etiquetado_oficina_destino IS NOT NULL THEN e.clm_alm_etiquetado_oficina_destino END) AS sedes
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
        JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
        JOIN tb_alm_movimientos m ON e.clm_alm_etiquetado_idMOVIMIENTO = m.clm_alm_mov_id
        LEFT JOIN tb_notas_salida n ON m.clm_alm_mov_idNOTA = n.clm_nota_id
        LEFT JOIN tb_sedes s ON e.clm_alm_etiquetado_oficina_destino = s.clm_sedes_id
        WHERE $whereSql
    ", $types, $params) ?: []);

    $listParams = $params;
    $listTypes = $types . 'i';
    $limit = 600;
    $listParams[] = $limit;

    $labels = alm_fetch_all($conn, "
        SELECT
            e.clm_alm_etiquetado_id AS etiqueta_id,
            COALESCE(NULLIF(TRIM(e.clm_etiquetado_CODIGO), ''), CONCAT('ETQ-', e.clm_alm_etiquetado_id)) AS etiqueta_codigo,
            COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'PENDIENTE') AS etiqueta_estado,
            e.clm_alm_etiquetado_FECHA AS etiqueta_fecha,
            YEAR(e.clm_alm_etiquetado_FECHA) AS etiqueta_anio,
            e.clm_alm_etiquetado_oficina_destino AS sede_id,
            $locationDetailSql AS ubicacion_detalle,
            p.clm_alm_producto_id AS producto_id,
            p.clm_alm_producto_codigo AS producto_codigo,
            p.clm_alm_producto_NOMBRE AS producto_nombre,
            p.clm_alm_producto_unidad AS producto_unidad,
            c.clm_alm_categoria_NOMBRE AS categoria_codigo,
            c.clm_alm_categoria_DESCRIPCION AS categoria_nombre,
            m.clm_alm_mov_id AS movimiento_id,
            m.clm_alm_mov_fecha_registro AS movimiento_fecha,
            m.clm_alm_mov_TIPO AS movimiento_tipo,
            COALESCE(NULLIF(TRIM(n.clm_nota_sco), ''), CONCAT('MOV-', m.clm_alm_mov_id)) AS nota_codigo,
            s.clm_sedes_name AS sede_nombre,
            COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u.usuario), ''), CONCAT('Usuario ', m.clm_alm_mov_iduser)) AS usuario_nombre
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
        JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
        JOIN tb_alm_movimientos m ON e.clm_alm_etiquetado_idMOVIMIENTO = m.clm_alm_mov_id
        LEFT JOIN tb_notas_salida n ON m.clm_alm_mov_idNOTA = n.clm_nota_id
        LEFT JOIN tb_sedes s ON e.clm_alm_etiquetado_oficina_destino = s.clm_sedes_id
        LEFT JOIN tb_usuarios u ON m.clm_alm_mov_iduser = u.id_usuario
        WHERE $whereSql
        ORDER BY e.clm_alm_etiquetado_id DESC
        LIMIT ?
    ", $listTypes, $listParams);
} catch (Throwable $e) {
    $pageError = $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo consultar la trazabilidad. Intenta nuevamente.';
}
$exportFilters = ['Buscar' => $buscar, 'Estado' => $estado, 'Sede' => $sede === -1 ? 'Sin ubicacion' : 'Todas',
    'Detalle de ubicacion' => $detalleFiltro, 'Nota de origen' => $notaFiltro,
    'Categoria' => $categoriaFiltro ? ($categorias[$categoriaFiltro] ?? (string)$categoriaFiltro) : 'Todas',
    'Producto' => 'Todos', 'Usuario del movimiento' => 'Todos'];
foreach ($sedes as $option) { if ((int)$option['id'] === $sede) $exportFilters['Sede'] = $option['nombre']; }
foreach ($productos as $option) { if ((int)$option['id'] === $productoFiltro) $exportFilters['Producto'] = $option['codigo'] . ' ' . $option['nombre']; }
foreach ($usuarios as $option) { if ((int)$option['id'] === $usuarioFiltro) $exportFilters['Usuario del movimiento'] = $option['nombre']; }
foreach (['etiqueta' => 'Etiqueta', 'movimiento' => 'Movimiento'] as $prefix => $name) {
    $exportFilters[$name . ' desde'] = $dates[$prefix . '_desde'];
    $exportFilters[$name . ' hasta'] = $dates[$prefix . '_hasta'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Trazabilidad de activos | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/loader_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/dialog_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/barcode_n360.css?etiqueta=1') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/contabilidad_trazabilidad_n360.css?ubicacion=1&consulta=1') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Trazabilidad de activos', 'subtitle' => 'Contabilidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner trace-shell" id="contaTracePage"
         data-api="<?= trace_h(n360_base_url('01_contabilidad/trazabilidad_activos_api.php')) ?>"
         data-csrf="<?= trace_h($_SESSION['conta_trace_csrf']) ?>"
         data-location-detail="<?= $locationDetailEnabled ? '1' : '0' ?>">
        <?php n360_render_content_separator('top'); ?>

        <section class="trace-hero">
            <div class="trace-hero__mark">
                <i class="bi bi-upc-scan" aria-hidden="true"></i>
            </div>
            <div class="trace-hero__copy">
                <span class="trace-kicker"><i class="bi bi-calculator-fill" aria-hidden="true"></i> Contabilidad - activos fijos</span>
                <h1>Trazabilidad de activos</h1>
                <p>Control por etiqueta, ubicacion vigente e historial de traslados sin alterar los movimientos de almacen.</p>
            </div>
            <a class="trace-hero__action" href="<?= trace_h(n360_base_url('01_contabilidad/registro_activos.php')) ?>">
                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                <span>Registrar activo</span>
            </a>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="trace-alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span><?= trace_h($pageError) ?></span>
            </div>
        <?php endif; ?>

        <section class="trace-kpis" aria-label="Resumen de etiquetas">
            <article><span>Etiquetas</span><strong><?= (int)($stats['total'] ?? 0) ?></strong></article>
            <article><span>Activos vigentes</span><strong><?= (int)($stats['activos'] ?? 0) ?></strong></article>
            <article><span>Consumidos</span><strong><?= (int)($stats['consumidos'] ?? 0) ?></strong></article>
            <article><span>Ubicaciones</span><strong><?= (int)($stats['sedes'] ?? 0) ?></strong></article>
        </section>

        <form class="trace-filters" id="traceFilters" method="get" autocomplete="off">
            <label>
                <span>Buscar</span>
                <div class="trace-input-icon">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" name="buscar" value="<?= trace_h($buscar) ?>" placeholder="Etiqueta, producto, nota o sede...">
                </div>
            </label>
            <label>
                <span>Estado</span>
                <select name="estado">
                    <?php foreach (['ACTIVOS' => 'Activos', 'TODOS' => 'Todos', 'GENERADO' => 'Generados', 'PENDIENTE' => 'Pendientes', 'CONSUMIDO' => 'Consumidos'] as $value => $label): ?>
                        <option value="<?= trace_h($value) ?>" <?= $estado === $value ? 'selected' : '' ?>><?= trace_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Ubicacion</span>
                <select name="sede">
                    <option value="0">Todas las sedes/oficinas</option>
                    <option value="-1" <?= $sede === -1 ? 'selected' : '' ?>>Sin ubicacion</option>
                    <?php foreach ($sedes as $sedeRow): ?>
                        <?php $sedeId = (int)($sedeRow['id'] ?? 0); ?>
                        <option value="<?= $sedeId ?>" <?= $sede === $sedeId ? 'selected' : '' ?>><?= trace_h($sedeRow['nombre'] ?? ('Sede ' . $sedeId)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Detalle de ubicacion</span>
                <input name="detalle" id="traceFilterDetalle" list="traceFilterDetalleList" value="<?= trace_h($detalleFiltro) ?>" placeholder="Piso, ambiente o puesto..." <?= !$locationDetailEnabled ? 'disabled' : '' ?>>
                <datalist id="traceFilterDetalleList">
                    <?php foreach ($locationSuggestions as $option): ?>
                        <option value="<?= trace_h($option['detalle']) ?>" data-sede="<?= (int)$option['sede_id'] ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <details class="trace-more-filters" <?= $moreFilters ? 'open' : '' ?>>
                <summary>Mas filtros: producto, origen y fechas</summary>
                <div class="trace-extra-grid">
                    <label><span>Producto</span><select name="producto">
                        <option value="0">Todos los productos</option>
                        <?php foreach ($productos as $option): ?>
                            <option value="<?= (int)$option['id'] ?>" <?= $productoFiltro === (int)$option['id'] ? 'selected' : '' ?>><?= trace_h($option['codigo'] . ' ' . $option['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>Categoria</span><select name="categoria">
                        <option value="0">Todas las categorias</option>
                        <?php foreach ($categorias as $id => $name): ?>
                            <option value="<?= (int)$id ?>" <?= $categoriaFiltro === (int)$id ? 'selected' : '' ?>><?= trace_h($name) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>Nota de origen</span><input name="nota" value="<?= trace_h($notaFiltro) ?>" placeholder="Nota o MOV-123..."></label>
                    <label><span>Usuario del movimiento</span><select name="usuario">
                        <option value="0">Todos los usuarios</option>
                        <?php foreach ($usuarios as $option): ?>
                            <option value="<?= (int)$option['id'] ?>" <?= $usuarioFiltro === (int)$option['id'] ? 'selected' : '' ?>><?= trace_h($option['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <?php foreach (['etiqueta_desde' => 'Etiqueta desde', 'etiqueta_hasta' => 'Etiqueta hasta', 'movimiento_desde' => 'Movimiento desde', 'movimiento_hasta' => 'Movimiento hasta'] as $key => $name): ?>
                        <label><span><?= trace_h($name) ?></span><input type="date" name="<?= trace_h($key) ?>" value="<?= trace_h($dates[$key]) ?>" min="1000-01-01" max="9999-12-31"></label>
                    <?php endforeach; ?>
                </div>
            </details>
            <div class="trace-filter-actions">
                <button type="submit" class="trace-btn trace-btn--primary"><i class="bi bi-funnel" aria-hidden="true"></i><span>Filtrar</span></button>
                <a class="trace-btn trace-btn--soft" href="<?= trace_h(n360_base_url('01_contabilidad/trazabilidad_activos.php')) ?>"><i class="bi bi-x-circle" aria-hidden="true"></i><span>Limpiar</span></a>
            </div>
        </form>

        <section class="trace-panel">
            <div class="trace-panel__head">
                <div>
                    <h2>Etiquetas y ubicacion</h2>
                    <p>Doble lectura: codigo estable de etiqueta y bitacora de sede/oficina.</p>
                </div>
                <div class="trace-panel-tools">
                    <span class="trace-count"><?= count($labels) ?> mostrados de <?= (int)$stats['total'] ?> encontrados</span>
                    <button type="button" class="trace-btn trace-btn--soft" id="traceExportExcel" <?= !$labels || $pageError !== '' ? 'disabled' : '' ?>><i class="bi bi-file-earmark-excel" aria-hidden="true"></i><span>Exportar Excel</span></button>
                </div>
            </div>
            <?php if ((int)$stats['total'] > count($labels)): ?><p class="trace-limit-note">Se muestran las 600 etiquetas mas recientes. Ajusta los filtros para consultar otras etiquetas; Excel incluye las filas mostradas.</p><?php endif; ?>

            <div class="trace-table-wrap">
                <table class="trace-table">
                    <thead>
                    <tr>
                        <th>Etiqueta</th>
                        <th>Producto</th>
                        <th>Ubicacion actual</th>
                        <th>Origen</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$labels): ?>
                        <tr><td colspan="6" class="trace-empty">No hay etiquetas para los filtros actuales.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($labels as $row): ?>
                        <?php
                        $etiquetaId = (int)($row['etiqueta_id'] ?? 0);
                        $estadoRow = strtoupper(trim((string)($row['etiqueta_estado'] ?? 'PENDIENTE')));
                        $sedeId = (int)($row['sede_id'] ?? 0);
                        $sedeNombre = trim((string)($row['sede_nombre'] ?? ''));
                        $ubicacionDetalle = (string)($row['ubicacion_detalle'] ?? '');
                        $producto = trim((string)($row['producto_nombre'] ?? 'Producto'));
                        $productoLine = trim('(' . ($row['producto_codigo'] ?? '-') . ') ' . $producto . ' - ' . ($row['producto_unidad'] ?? ''));
                        $categoriaLine = trim(($row['categoria_codigo'] ?? '') . ' ' . ($row['categoria_nombre'] ?? ''));
                        $etiquetaCodigo = (string)($row['etiqueta_codigo'] ?? ('ETQ-' . $etiquetaId));
                        $canMove = $estadoRow !== 'CONSUMIDO';
                        ?>
                        <tr data-trace-row data-label-id="<?= $etiquetaId ?>">
                            <td>
                                <strong class="trace-code"><?= trace_h($row['etiqueta_codigo'] ?? ('ETQ-' . $etiquetaId)) ?></strong>
                                <small>Creada <?= trace_h(trace_fmt_dt($row['etiqueta_fecha'] ?? '')) ?></small>
                            </td>
                            <td>
                                <strong><?= trace_h($productoLine) ?></strong>
                                <small><?= trace_h(trim(($row['categoria_codigo'] ?? '') . ' ' . ($row['categoria_nombre'] ?? ''))) ?></small>
                            </td>
                            <td>
                                <span class="trace-location"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i><?= trace_h($sedeNombre !== '' ? $sedeNombre : 'Sin ubicacion') ?></span>
                                <?php if ($ubicacionDetalle !== ''): ?><small class="trace-location-detail"><?= trace_h($ubicacionDetalle) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <strong><?= trace_h($row['nota_codigo'] ?? ('MOV-' . ($row['movimiento_id'] ?? ''))) ?></strong>
                                <small><?= trace_h(trace_fmt_dt($row['movimiento_fecha'] ?? '')) ?> · <?= trace_h($row['usuario_nombre'] ?? '') ?></small>
                                <small>MOV-<?= (int)$row['movimiento_id'] ?> · <?= trace_h($row['movimiento_tipo']) ?></small>
                            </td>
                            <td><span class="trace-status <?= trace_h(trace_status_class($estadoRow)) ?>"><?= trace_h($estadoRow) ?></span></td>
                            <td>
                                <div class="trace-actions">
                                    <button type="button" class="trace-row-btn" data-trace-info data-label-id="<?= $etiquetaId ?>" data-label-code="<?= trace_h($etiquetaCodigo) ?>">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i><span>Informacion</span>
                                    </button>
                                    <button type="button"
                                            class="trace-row-btn"
                                            data-trace-label
                                            data-code="<?= trace_h($etiquetaCodigo) ?>"
                                            data-year="<?= (int)($row['etiqueta_anio'] ?? 0) ?>"
                                            data-name="<?= trace_h($productoLine) ?>"
                                            data-category="<?= trace_h($categoriaLine) ?>"
                                            data-filename="<?= trace_h('ETQ_' . trace_safe_file($etiquetaCodigo)) ?>">
                                        <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                        <span>Etiqueta</span>
                                    </button>
                                    <button type="button"
                                            class="trace-row-btn"
                                            data-trace-history
                                            data-label-id="<?= $etiquetaId ?>"
                                            data-label-code="<?= trace_h($etiquetaCodigo) ?>">
                                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                                        <span>Historial</span>
                                    </button>
                                    <button type="button"
                                            class="trace-row-btn trace-row-btn--primary"
                                            data-trace-move
                                            data-label-id="<?= $etiquetaId ?>"
                                            data-label-code="<?= trace_h($etiquetaCodigo) ?>"
                                            data-product="<?= trace_h($productoLine) ?>"
                                            data-current-sede-id="<?= $sedeId ?>"
                                            data-current-sede="<?= trace_h($sedeNombre !== '' ? $sedeNombre : 'Sin ubicacion') ?>"
                                            data-current-detalle="<?= trace_h($ubicacionDetalle) ?>"
                                            <?= $canMove ? '' : 'disabled' ?>>
                                        <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                                        <span>Mover</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>

        <div class="trace-modal" id="traceMoveModal" hidden>
            <div class="trace-modal__backdrop" data-trace-close></div>
            <section class="trace-modal__panel" role="dialog" aria-modal="true" aria-labelledby="traceMoveTitle">
                <button type="button" class="trace-modal__close" data-trace-close aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                <span class="trace-kicker"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Movimiento de ubicacion</span>
                <h2 id="traceMoveTitle">Mover activo</h2>
                <p class="trace-modal__lead">No se crea movimiento de almacen. Solo cambia la ubicacion actual de la etiqueta y se registra en etiquetaofi.</p>
                <form id="traceMoveForm">
                    <input type="hidden" id="traceMoveLabelId">
                    <input type="hidden" id="traceMoveExpectedSede">
                    <input type="hidden" id="traceMoveExpectedDetalle">
                    <div class="trace-modal__summary">
                        <div><span>Etiqueta</span><strong id="traceMoveCode">-</strong></div>
                        <div><span>Producto</span><strong id="traceMoveProduct">-</strong></div>
                        <div><span>Ubicacion actual</span><strong id="traceMoveCurrent">-</strong></div>
                    </div>
                    <label class="trace-modal__field">
                        <span>Nueva ubicacion</span>
                        <select id="traceMoveSede" required>
                            <option value="">Seleccionar sede/oficina...</option>
                            <?php foreach ($sedes as $sedeRow): ?>
                                <?php $sedeId = (int)($sedeRow['id'] ?? 0); ?>
                                <option value="<?= $sedeId ?>"><?= trace_h($sedeRow['nombre'] ?? ('Sede ' . $sedeId)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="trace-modal__field">
                        <span>Detalle de nueva ubicación (opcional)</span>
                        <input type="text" id="traceMoveDetalle" maxlength="220" placeholder="Ej. Tercer piso, Contabilidad, puesto Mirella"
                               list="traceMoveDetalleSugerencias" autocomplete="off"
                               <?= $locationDetailEnabled ? '' : 'disabled' ?>>
                        <datalist id="traceMoveDetalleSugerencias">
                            <?php foreach ($locationSuggestions as $suggestion): ?>
                            <option value="<?= trace_h($suggestion['detalle']) ?>" data-sede="<?= (int)$suggestion['sede_id'] ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?php if ($locationDetailEnabled): ?><small>Elige un detalle anterior de la sede o escribe uno nuevo.</small><?php endif; ?>
                    </label>
                    <?php if (!$locationDetailEnabled): ?><p class="trace-modal__lead">El detalle de ubicación aún no está habilitado. Contacta al administrador.</p><?php endif; ?>
                    <div class="trace-location-preview" aria-live="polite">
                        <span>Nueva ubicación que se guardará</span>
                        <p id="traceMovePreview">Selecciona una sede/oficina.</p>
                    </div>
                    <div class="trace-modal__actions">
                        <button type="button" class="trace-btn trace-btn--soft" data-trace-close>Cancelar</button>
                        <button type="submit" class="trace-btn trace-btn--primary" id="traceMoveSubmit"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Guardar traslado</span></button>
                    </div>
                </form>
            </section>
        </div>

        <div class="trace-modal" id="traceInfoModal" hidden>
            <div class="trace-modal__backdrop" data-trace-close></div>
            <section class="trace-modal__panel trace-info-panel" role="dialog" aria-modal="true" aria-labelledby="traceInfoTitle">
                <header class="trace-info-head">
                    <button type="button" class="trace-modal__close" data-trace-close aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    <span class="trace-kicker">Ficha del activo</span><h2 id="traceInfoTitle">Informacion de etiqueta</h2>
                    <p id="traceInfoCode" class="trace-modal__lead"></p>
                </header>
                <div class="trace-info-body" id="traceInfoBody" aria-live="polite"></div>
                <footer class="trace-info-footer"><button type="button" class="trace-btn trace-btn--soft" data-trace-close>Cerrar</button></footer>
            </section>
        </div>

        <div class="trace-modal" id="traceHistoryModal" hidden>
            <div class="trace-modal__backdrop" data-trace-close></div>
            <section class="trace-modal__panel trace-modal__panel--wide" role="dialog" aria-modal="true" aria-labelledby="traceHistoryTitle">
                <button type="button" class="trace-modal__close" data-trace-close aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                <span class="trace-kicker"><i class="bi bi-clock-history" aria-hidden="true"></i> Historial de ubicacion</span>
                <h2 id="traceHistoryTitle">Bitacora de etiqueta</h2>
                <p class="trace-modal__lead" id="traceHistoryCode">-</p>
                <div id="traceHistoryBody" class="trace-history-list"></div>
            </section>
        </div>

        <div class="trace-modal" id="traceBarcodeModal" hidden>
            <div class="trace-modal__backdrop" data-trace-close></div>
            <section class="trace-modal__panel trace-modal__panel--barcode" role="dialog" aria-modal="true" aria-labelledby="traceBarcodeTitle">
                <button type="button" class="trace-modal__close" data-trace-close aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                <span class="trace-kicker"><i class="bi bi-upc-scan" aria-hidden="true"></i> Imagen de etiqueta</span>
                <h2 id="traceBarcodeTitle">Regenerar etiqueta</h2>
                <p class="trace-modal__lead">Previsualiza y descarga nuevamente el PNG de la etiqueta seleccionada.</p>
                <div class="trace-barcode-box">
                    <div class="n360-barcode-card n360-barcode-card--compact"
                         id="traceBarcodeCard"
                         data-n360-barcode
                         data-barcode-kind="etiqueta"
                         data-barcode-logo="<?= trace_h(n360_base_url('img/completo.png')) ?>"
                         data-barcode-code=""
                         data-barcode-name=""
                         data-barcode-category=""
                         data-barcode-filename="">
                        <div class="n360-barcode-card__head">
                            <div class="n360-barcode-card__title">
                                <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                <span>Previsualizacion CODE128</span>
                            </div>
                            <span class="n360-barcode-card__meta">PNG</span>
                        </div>
                        <div data-barcode-stage></div>
                        <div class="n360-barcode-actions">
                            <span class="n360-barcode-code" id="traceBarcodeCode">-</span>
                            <button class="n360-barcode-download" type="button" data-barcode-download>
                                <i class="bi bi-download" aria-hidden="true"></i>
                                <span>Descargar PNG</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="trace-modal__actions">
                    <button type="button" class="trace-btn trace-btn--soft" data-trace-barcode-rerender><i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span>Regenerar imagen</span></button>
                    <button type="button" class="trace-btn trace-btn--soft" data-trace-close>Cerrar</button>
                </div>
            </section>
        </div>
    </div>
</main>

<?php n360_render_footer(); ?>
<script type="application/json" id="traceExportData"><?= json_encode(['rows' => $pageError === '' ? $labels : [], 'filters' => $exportFilters, 'total' => (int)$stats['total']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/reportes/activos_trazabilidad_excel.js?consulta=1') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/barcode_n360.js?etiqueta=1') ?>"></script>
<script src="<?= n360_asset('assets/js/activos_ubicacion_n360.js?ubicacion=2') ?>"></script>
<script src="<?= n360_asset('assets/js/contabilidad_trazabilidad_n360.js?ubicacion=2&etiqueta=1&consulta=1') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
