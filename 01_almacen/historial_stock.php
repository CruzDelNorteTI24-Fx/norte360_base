<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function hist_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hist_can_almacen(bool $isAdmin): bool {
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

function hist_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function hist_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta.');
    }

    hist_bind_params($stmt, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error ?: 'No se pudo ejecutar la consulta.');
    }

    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function hist_valid_date($value, string $fallback): string {
    $value = trim((string)$value);
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : $fallback;
}

function hist_fmt_num($value): string {
    $number = (float)str_replace(',', '.', (string)($value ?? 0));
    $text = number_format($number, 3, '.', '');
    return rtrim(rtrim($text, '0'), '.') ?: '0';
}

$isAdmin = (($_SESSION['web_rol'] ?? '') === 'Admin');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

if (!hist_can_almacen($isAdmin)) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Historial de stocks'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

$today = date('Y-m-d');
$desde = hist_valid_date($_GET['desde'] ?? '', $today);
$hasta = hist_valid_date($_GET['hasta'] ?? '', $today);

if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$categoria = trim((string)($_GET['categoria'] ?? 'TODOS'));
$buscar = trim((string)($_GET['buscar'] ?? ''));
$pageError = '';

try {
    $categorias = hist_fetch_all($conn, "
        SELECT clm_alm_categoria_NOMBRE AS codigo, clm_alm_categoria_DESCRIPCION AS descripcion
        FROM tb_alm_categoria
        ORDER BY clm_alm_categoria_NOMBRE ASC
    ");

    $types = 'sssssss';
    $params = [$desde, $desde, $hasta, $desde, $hasta, $desde, $hasta];
    $where = [
        'p.clm_alm_producto_idCATEGORIA <> 11',
        "EXISTS (
            SELECT 1
            FROM tb_alm_movimientos movx
            WHERE movx.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
              AND DATE(movx.clm_alm_mov_fecha_registro) BETWEEN ? AND ?
        )"
    ];

    if ($categoria !== '' && $categoria !== 'TODOS') {
        $where[] = 'cat.clm_alm_categoria_NOMBRE = ?';
        $types .= 's';
        $params[] = $categoria;
    }

    if ($buscar !== '') {
        $where[] = "(p.clm_alm_producto_codigo LIKE CONCAT('%', ?, '%') OR p.clm_alm_producto_NOMBRE LIKE CONCAT('%', ?, '%') OR cat.clm_alm_categoria_DESCRIPCION LIKE CONCAT('%', ?, '%') OR cod.clm_alm_codigo_DESCRIPCION LIKE CONCAT('%', ?, '%'))";
        $types .= 'ssss';
        array_push($params, $buscar, $buscar, $buscar, $buscar);
    }

    $sql = "
        SELECT
            p.clm_alm_producto_id AS id_producto,
            CONCAT('(', cod.clm_alm_codigo_NOMBRE, ') ', cod.clm_alm_codigo_DESCRIPCION) AS grupo,
            CONCAT('(', cat.clm_alm_categoria_NOMBRE, ') ', cat.clm_alm_categoria_DESCRIPCION) AS categoria,
            p.clm_alm_producto_codigo AS codigo_producto,
            p.clm_alm_producto_NOMBRE AS producto,
            p.clm_alm_producto_unidad AS unidad,
            IFNULL((
                SELECT SUM(CASE
                    WHEN mov.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN mov.clm_alm_mov_cantidad
                    WHEN mov.clm_alm_mov_TIPO = 'SALIDA' THEN -mov.clm_alm_mov_cantidad
                    ELSE 0
                END)
                FROM tb_alm_movimientos mov
                WHERE mov.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
                  AND DATE(mov.clm_alm_mov_fecha_registro) < ?
            ), 0) AS saldo_inicial,
            IFNULL((
                SELECT SUM(mov.clm_alm_mov_cantidad)
                FROM tb_alm_movimientos mov
                WHERE mov.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
                  AND mov.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO')
                  AND DATE(mov.clm_alm_mov_fecha_registro) BETWEEN ? AND ?
            ), 0) AS entradas,
            IFNULL((
                SELECT SUM(mov.clm_alm_mov_cantidad)
                FROM tb_alm_movimientos mov
                WHERE mov.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
                  AND mov.clm_alm_mov_TIPO = 'SALIDA'
                  AND DATE(mov.clm_alm_mov_fecha_registro) BETWEEN ? AND ?
            ), 0) AS salidas
        FROM tb_alm_producto p
        JOIN tb_alm_categoria cat ON p.clm_alm_producto_idCATEGORIA = cat.clm_alm_categoria_id
        JOIN tb_alm_codigo cod ON cat.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.clm_alm_producto_NOMBRE ASC
        LIMIT 3000
    ";

    $rows = hist_fetch_all($conn, $sql, $types, $params);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
    $categorias = [];
    $rows = [];
}

$totalProductos = count($rows);
$sumInicial = 0.0;
$sumEntradas = 0.0;
$sumSalidas = 0.0;
$sumFinal = 0.0;

foreach ($rows as &$row) {
    $inicial = (float)($row['saldo_inicial'] ?? 0);
    $entradas = (float)($row['entradas'] ?? 0);
    $salidas = (float)($row['salidas'] ?? 0);
    $final = $inicial + $entradas - $salidas;

    $row['saldo_final'] = $final;
    $sumInicial += $inicial;
    $sumEntradas += $entradas;
    $sumSalidas += $salidas;
    $sumFinal += $final;
}
unset($row);

$categoriaLabel = 'TODOS';
foreach ($categorias as $cat) {
    if ((string)$cat['codigo'] === $categoria) {
        $categoriaLabel = $cat['codigo'] . ' - ' . $cat['descripcion'];
        break;
    }
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
    <title>Historial de stocks | Norte360</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/inventario_stock_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Historial de stocks', 'subtitle' => 'Saldo inicial, entradas, salidas y final']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content n360-stock-page">
        <section class="stock-hero stock-hero--history">
            <div>
                <span class="stock-eyebrow"><i class="bi bi-clock-history"></i> Almacen - Corte operativo</span>
                <h1>Historial de stock</h1>
                <p>Productos con movimientos en el periodo seleccionado, mostrando saldo inicial, entradas, salidas y saldo final.</p>
            </div>
            <button type="button" class="stock-btn stock-btn--primary" data-stock-export-pdf data-table-id="historialStockTable" data-report-kind="historial">
                <i class="bi bi-file-earmark-pdf"></i>
                Descargar PDF
            </button>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= hist_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="stock-kpis">
            <article class="stock-kpi">
                <span>Productos movidos</span>
                <strong data-stock-visible-count><?= hist_h($totalProductos) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--blue">
                <span>Saldo inicial</span>
                <strong><?= hist_h(hist_fmt_num($sumInicial)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Entradas</span>
                <strong><?= hist_h(hist_fmt_num($sumEntradas)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--red">
                <span>Salidas</span>
                <strong><?= hist_h(hist_fmt_num($sumSalidas)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--amber">
                <span>Saldo final</span>
                <strong><?= hist_h(hist_fmt_num($sumFinal)) ?></strong>
            </article>
        </section>

        <form class="stock-filters stock-filters--history" method="get" action="historial_stock.php" autocomplete="off">
            <label class="stock-field">
                <span>Desde</span>
                <input type="date" name="desde" value="<?= hist_h($desde) ?>">
            </label>
            <label class="stock-field">
                <span>Hasta</span>
                <input type="date" name="hasta" value="<?= hist_h($hasta) ?>">
            </label>
            <label class="stock-field">
                <span>Categoria</span>
                <select name="categoria">
                    <option value="TODOS">TODOS</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= hist_h($cat['codigo']) ?>" <?= (string)$cat['codigo'] === $categoria ? 'selected' : '' ?>>
                            <?= hist_h($cat['codigo'] . ' - ' . $cat['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field stock-field--search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input type="search" name="buscar" value="<?= hist_h($buscar) ?>" placeholder="Codigo, producto, grupo o categoria..." autocomplete="off" data-stock-search>
            </label>
            <div class="stock-filter-actions">
                <button type="submit" class="stock-btn stock-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button>
                <a href="historial_stock.php" class="stock-btn stock-btn--soft"><i class="bi bi-x-circle"></i> Limpiar</a>
            </div>
        </form>

        <section class="stock-table-card">
            <div class="stock-table-head">
                <div>
                    <h2>Movimientos del periodo</h2>
                    <p>Doble click sobre un producto para abrir su historial completo.</p>
                </div>
                <span><?= hist_h($desde) ?> a <?= hist_h($hasta) ?> · <?= hist_h($categoriaLabel) ?></span>
            </div>
            <div class="stock-table-wrap">
                <table class="stock-table" id="historialStockTable" data-report-title="Historial de stocks" data-report-subtitle="Rango: <?= hist_h($desde) ?> a <?= hist_h($hasta) ?> | Categoria: <?= hist_h($categoriaLabel) ?>">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Grupo</th>
                            <th>Categoria</th>
                            <th>Codigo</th>
                            <th>Producto</th>
                            <th>Unidad</th>
                            <th>Saldo Inicial</th>
                            <th>Entradas</th>
                            <th>Salidas</th>
                            <th>Saldo Final</th>
                            <th data-pdf-skip>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr class="stock-empty-row">
                            <td colspan="11">No hay productos con movimientos para el periodo seleccionado.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                        <?php
                            $id = (int)($row['id_producto'] ?? 0);
                            $searchText = mb_strtolower(implode(' ', [
                                $row['grupo'] ?? '',
                                $row['categoria'] ?? '',
                                $row['codigo_producto'] ?? '',
                                $row['producto'] ?? '',
                            ]), 'UTF-8');
                        ?>
                        <tr data-stock-row data-product-id="<?= $id ?>" data-search="<?= hist_h($searchText) ?>">
                            <td><?= hist_h($id) ?></td>
                            <td><?= hist_h($row['grupo'] ?? '-') ?></td>
                            <td><?= hist_h($row['categoria'] ?? '-') ?></td>
                            <td><span class="stock-code"><?= hist_h($row['codigo_producto'] ?? 'S/C') ?></span></td>
                            <td class="stock-product"><?= hist_h($row['producto'] ?? '-') ?></td>
                            <td><?= hist_h($row['unidad'] ?? '-') ?></td>
                            <td class="num"><?= hist_h(hist_fmt_num($row['saldo_inicial'] ?? 0)) ?></td>
                            <td class="num in"><?= hist_h(hist_fmt_num($row['entradas'] ?? 0)) ?></td>
                            <td class="num out"><?= hist_h(hist_fmt_num($row['salidas'] ?? 0)) ?></td>
                            <td class="num strong"><?= hist_h(hist_fmt_num($row['saldo_final'] ?? 0)) ?></td>
                            <td data-pdf-skip>
                                <button type="button" class="stock-mini-btn" data-product-history="<?= $id ?>">
                                    <i class="bi bi-clock-history"></i>
                                    Historial
                                </button>
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

<div id="modal-movimientos" class="stock-drawer">
    <div class="stock-drawer-panel">
        <button type="button" class="stock-drawer-close" onclick="cerrarModal()" aria-label="Cerrar">×</button>
        <div id="contenido-movimientos">Cargando movimientos...</div>
    </div>
</div>

<div id="modal-nota" class="stock-note-modal">
    <div class="stock-note-content">
        <button type="button" class="stock-drawer-close" onclick="cerrarNotaModal()" aria-label="Cerrar">×</button>
        <div id="contenido-nota">Cargando nota...</div>
    </div>
</div>

<script>
window.N360_STOCK_REPORT = {
    historyEndpoint: '<?= hist_h(n360_base_url('php/ver_movimientos_producto.php')) ?>',
    noteEndpoint: '<?= hist_h(n360_base_url('php/ver_nota_salida.php')) ?>',
    pdf: {
        orientation: 'landscape',
        title: 'HISTORIAL DE STOCKS',
        secondTitle: 'Saldo inicial, entradas, salidas y saldo final',
        docCode: 'ALM-RPT-HIST-STOCK',
        description: 'Reporte generado desde la vista web de historial de stocks.',
        userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        logoLeft: '<?= hist_h(n360_base_url('img/icon.png')) ?>',
        logoRight: '<?= hist_h(n360_base_url('img/norte360_black.png')) ?>',
        useCover: false,
        fileName: 'historial_stocks_<?= date('Ymd_His') ?>.pdf'
    }
};
window.N360_NOTA_PDF_CONFIG = {
    endpoint: '<?= hist_h(n360_base_url('php/nota_pdf_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoTicket: '<?= hist_h(n360_base_url('img/completo.png')) ?>',
    footerLabel: 'NORTE 360'
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_notas_common.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_entrada_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_tanqueada.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_abastecimiento.js') ?>"></script>
<script src="<?= n360_asset('assets/js/nota_pdf_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/barcode_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/inventario_stock_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
