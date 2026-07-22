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

if (empty($_SESSION['conta_trace_csrf'])) {
    $_SESSION['conta_trace_csrf'] = bin2hex(random_bytes(24));
}

$buscar = trim((string)($_GET['buscar'] ?? ''));
$estado = strtoupper(trim((string)($_GET['estado'] ?? 'ACTIVOS')));
$sede = (int)($_GET['sede'] ?? 0);
$allowedEstados = ['ACTIVOS', 'TODOS', 'GENERADO', 'PENDIENTE', 'CONSUMIDO'];
if (!in_array($estado, $allowedEstados, true)) {
    $estado = 'ACTIVOS';
}

$labels = [];
$sedes = [];
$stats = [
    'total' => 0,
    'activos' => 0,
    'consumidos' => 0,
    'sedes' => 0,
];
$pageError = '';

try {
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
    }

    if ($buscar !== '') {
        $where[] = "(
            e.clm_etiquetado_CODIGO LIKE CONCAT('%', ?, '%')
            OR p.clm_alm_producto_codigo LIKE CONCAT('%', ?, '%')
            OR p.clm_alm_producto_NOMBRE LIKE CONCAT('%', ?, '%')
            OR COALESCE(n.clm_nota_sco, '') LIKE CONCAT('%', ?, '%')
            OR COALESCE(s.clm_sedes_name, '') LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'sssss';
        array_push($params, $buscar, $buscar, $buscar, $buscar, $buscar);
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
            e.clm_alm_etiquetado_oficina_destino AS sede_id,
            p.clm_alm_producto_id AS producto_id,
            p.clm_alm_producto_codigo AS producto_codigo,
            p.clm_alm_producto_NOMBRE AS producto_nombre,
            p.clm_alm_producto_unidad AS producto_unidad,
            c.clm_alm_categoria_NOMBRE AS categoria_codigo,
            c.clm_alm_categoria_DESCRIPCION AS categoria_nombre,
            m.clm_alm_mov_id AS movimiento_id,
            m.clm_alm_mov_fecha_registro AS movimiento_fecha,
            m.clm_alm_mov_TIPO AS movimiento_tipo,
            n.clm_nota_sco AS nota_codigo,
            s.clm_sedes_name AS sede_nombre,
            COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u.usuario), ''), CONCAT('Usuario ', e.clm_alm_etiquetado_USUARIO)) AS usuario_nombre
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
        JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
        JOIN tb_alm_movimientos m ON e.clm_alm_etiquetado_idMOVIMIENTO = m.clm_alm_mov_id
        LEFT JOIN tb_notas_salida n ON m.clm_alm_mov_idNOTA = n.clm_nota_id
        LEFT JOIN tb_sedes s ON e.clm_alm_etiquetado_oficina_destino = s.clm_sedes_id
        LEFT JOIN tb_usuarios u ON e.clm_alm_etiquetado_USUARIO = u.id_usuario
        WHERE $whereSql
        ORDER BY e.clm_alm_etiquetado_id DESC
        LIMIT ?
    ", $listTypes, $listParams);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
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
    <link rel="stylesheet" href="<?= n360_asset('assets/css/barcode_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/contabilidad_trazabilidad_n360.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Trazabilidad de activos', 'subtitle' => 'Contabilidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner trace-shell" id="contaTracePage"
         data-api="<?= trace_h(n360_base_url('01_contabilidad/trazabilidad_activos_api.php')) ?>"
         data-csrf="<?= trace_h($_SESSION['conta_trace_csrf']) ?>">
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

        <form class="trace-filters" method="get" autocomplete="off">
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
                    <?php foreach ($sedes as $sedeRow): ?>
                        <?php $sedeId = (int)($sedeRow['id'] ?? 0); ?>
                        <option value="<?= $sedeId ?>" <?= $sede === $sedeId ? 'selected' : '' ?>><?= trace_h($sedeRow['nombre'] ?? ('Sede ' . $sedeId)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
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
                <span><?= count($labels) ?> registros</span>
            </div>

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
                        $producto = trim((string)($row['producto_nombre'] ?? 'Producto'));
                        $productoLine = trim('(' . ($row['producto_codigo'] ?? '-') . ') ' . $producto . ' - ' . ($row['producto_unidad'] ?? ''));
                        $categoriaLine = trim(($row['categoria_codigo'] ?? '') . ' ' . ($row['categoria_nombre'] ?? ''));
                        $etiquetaCodigo = (string)($row['etiqueta_codigo'] ?? ('ETQ-' . $etiquetaId));
                        $canMove = $estadoRow !== 'CONSUMIDO';
                        ?>
                        <tr>
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
                            </td>
                            <td>
                                <strong><?= trace_h($row['nota_codigo'] ?? ('MOV-' . ($row['movimiento_id'] ?? ''))) ?></strong>
                                <small><?= trace_h(trace_fmt_dt($row['movimiento_fecha'] ?? '')) ?> · <?= trace_h($row['usuario_nombre'] ?? '') ?></small>
                            </td>
                            <td><span class="trace-status <?= trace_h(trace_status_class($estadoRow)) ?>"><?= trace_h($estadoRow) ?></span></td>
                            <td>
                                <div class="trace-actions">
                                    <button type="button"
                                            class="trace-row-btn"
                                            data-trace-label
                                            data-code="<?= trace_h($etiquetaCodigo) ?>"
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
                    <div class="trace-modal__actions">
                        <button type="button" class="trace-btn trace-btn--soft" data-trace-close>Cancelar</button>
                        <button type="submit" class="trace-btn trace-btn--primary" id="traceMoveSubmit"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Guardar traslado</span></button>
                    </div>
                </form>
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
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/barcode_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/contabilidad_trazabilidad_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
