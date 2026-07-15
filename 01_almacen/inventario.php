<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function inv_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function inv_can_almacen(bool $isAdmin): bool {
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

function inv_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
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

function inv_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta.');
    }

    inv_bind_params($stmt, $types, $params);

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

function inv_fmt_num($value): string {
    $number = (float)str_replace(',', '.', (string)($value ?? 0));
    $text = number_format($number, 3, '.', '');
    return rtrim(rtrim($text, '0'), '.') ?: '0';
}

function inv_state_class($estado): string {
    $estado = strtoupper(trim((string)$estado));
    return $estado === 'OK' ? 'ok' : 'warn';
}

$isAdmin = (($_SESSION['web_rol'] ?? '') === 'Admin');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

if (!inv_can_almacen($isAdmin)) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Inventario'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

$buscar = trim((string)($_GET['buscar'] ?? ''));
$categoria = trim((string)($_GET['categoria'] ?? 'TODOS'));
$estado = trim((string)($_GET['estado'] ?? 'TODOS'));
$soloMovimientos = isset($_GET['solo_movimientos']) && (string)$_GET['solo_movimientos'] === '1';
$pageError = '';

try {
    $categorias = inv_fetch_all($conn, "
        SELECT clm_alm_categoria_NOMBRE AS codigo, clm_alm_categoria_DESCRIPCION AS descripcion
        FROM tb_alm_categoria
        ORDER BY clm_alm_categoria_NOMBRE ASC
    ");

    $estados = inv_fetch_all($conn, "
        SELECT DISTINCT CAST(Estado AS CHAR) AS Estado
        FROM vw_control_inventario
        WHERE Estado IS NOT NULL
        ORDER BY Estado ASC
    ");

    $types = '';
    $params = [];
    $where = ['1=1'];

    if ($categoria !== '' && $categoria !== 'TODOS') {
        $where[] = "CAST(Categoria AS CHAR) LIKE CONCAT('(', ?, ') %')";
        $types .= 's';
        $params[] = $categoria;
    }

    if ($estado !== '' && $estado !== 'TODOS') {
        $where[] = 'CAST(Estado AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci';
        $types .= 's';
        $params[] = $estado;
    }

    if ($soloMovimientos) {
        $where[] = 'Tiene_Movimientos = 1';
    }

    if ($buscar !== '') {
        $where[] = "(CAST(CODIPRODUCTO AS CHAR) LIKE CONCAT('%', ?, '%') OR CAST(Producto AS CHAR) LIKE CONCAT('%', ?, '%') OR CAST(Categoria AS CHAR) LIKE CONCAT('%', ?, '%') OR CAST(Codigo AS CHAR) LIKE CONCAT('%', ?, '%'))";
        $types .= 'ssss';
        array_push($params, $buscar, $buscar, $buscar, $buscar);
    }

    $sql = "
        SELECT
            ID,
            CODIPRODUCTO,
            Producto,
            Codigo,
            Categoria,
            Unidad,
            Stock_Min,
            Stock_Actual,
            Diferencia,
            Estado,
            Tiene_Movimientos
        FROM vw_control_inventario
        WHERE " . implode(' AND ', $where) . "
        ORDER BY Tiene_Movimientos DESC, Producto ASC
        LIMIT 3000
    ";

    $rows = inv_fetch_all($conn, $sql, $types, $params);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
    $categorias = [];
    $estados = [];
    $rows = [];
}

$totalProductos = count($rows);
$conMovimientos = 0;
$enReposicion = 0;
$stockTotal = 0.0;

foreach ($rows as $row) {
    if ((int)($row['Tiene_Movimientos'] ?? 0) === 1) {
        $conMovimientos++;
    }
    if (strtoupper((string)($row['Estado'] ?? '')) !== 'OK') {
        $enReposicion++;
    }
    $stockTotal += (float)($row['Stock_Actual'] ?? 0);
}

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
    <title>Inventario | Norte360</title>
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
<?php n360_render_header(['title' => 'Inventario', 'subtitle' => 'Control operativo de stock']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content n360-stock-page">
        <section class="stock-hero">
            <div>
                <span class="stock-eyebrow"><i class="bi bi-box-seam"></i> Almacen - Reporte actual</span>
                <h1>Inventario de productos</h1>
                <p>Consulta filtrable del stock actual, estado de reposicion y productos con movimientos registrados.</p>
            </div>
            <button type="button" class="stock-btn stock-btn--primary" data-stock-export-pdf data-table-id="inventarioTable" data-report-kind="inventario">
                <i class="bi bi-file-earmark-pdf"></i>
                Descargar PDF
            </button>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= inv_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="stock-kpis">
            <article class="stock-kpi">
                <span>Productos</span>
                <strong data-stock-visible-count><?= inv_h($totalProductos) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Con movimientos</span>
                <strong><?= inv_h($conMovimientos) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--amber">
                <span>En reposicion</span>
                <strong><?= inv_h($enReposicion) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--blue">
                <span>Stock total</span>
                <strong><?= inv_h(inv_fmt_num($stockTotal)) ?></strong>
            </article>
        </section>

        <form class="stock-filters" method="get" action="inventario.php" autocomplete="off">
            <label class="stock-field stock-field--search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input type="search" name="buscar" value="<?= inv_h($buscar) ?>" placeholder="Codigo, producto, grupo o categoria..." autocomplete="off" data-stock-search>
            </label>
            <label class="stock-field">
                <span>Categoria</span>
                <select name="categoria">
                    <option value="TODOS">TODOS</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= inv_h($cat['codigo']) ?>" <?= (string)$cat['codigo'] === $categoria ? 'selected' : '' ?>>
                            <?= inv_h($cat['codigo'] . ' - ' . $cat['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-field">
                <span>Estado</span>
                <select name="estado">
                    <option value="TODOS">TODOS</option>
                    <?php foreach ($estados as $item): ?>
                        <?php $estadoValue = (string)($item['Estado'] ?? ''); ?>
                        <option value="<?= inv_h($estadoValue) ?>" <?= $estadoValue === $estado ? 'selected' : '' ?>>
                            <?= inv_h($estadoValue) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="stock-check">
                <input type="checkbox" name="solo_movimientos" value="1" <?= $soloMovimientos ? 'checked' : '' ?>>
                <span>Solo productos con movimientos</span>
            </label>
            <div class="stock-filter-actions">
                <button type="submit" class="stock-btn stock-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button>
                <a href="inventario.php" class="stock-btn stock-btn--soft"><i class="bi bi-x-circle"></i> Limpiar</a>
            </div>
        </form>

        <section class="stock-table-card">
            <div class="stock-table-head">
                <div>
                    <h2>Tabla de inventario</h2>
                    <p>Doble click sobre un producto para abrir su historial de movimientos.</p>
                </div>
                <span><?= inv_h($categoriaLabel) ?> · <?= inv_h($estado ?: 'TODOS') ?></span>
            </div>
            <div class="stock-table-wrap">
                <table class="stock-table" id="inventarioTable" data-report-title="Inventario de productos" data-report-subtitle="Categoria: <?= inv_h($categoriaLabel) ?> | Estado: <?= inv_h($estado ?: 'TODOS') ?> | Solo movimientos: <?= $soloMovimientos ? 'SI' : 'NO' ?>">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Codigo</th>
                            <th>Producto</th>
                            <th>Grupo</th>
                            <th>Categoria</th>
                            <th>Unidad</th>
                            <th>Stock Min</th>
                            <th>Stock Actual</th>
                            <th>Diferencia</th>
                            <th>Estado</th>
                            <th>Mov.</th>
                            <th data-pdf-skip>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr class="stock-empty-row">
                            <td colspan="12">No hay productos para los filtros actuales.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                        <?php
                            $id = (int)($row['ID'] ?? 0);
                            $searchText = mb_strtolower(implode(' ', [
                                $row['ID'] ?? '',
                                $row['CODIPRODUCTO'] ?? '',
                                $row['Producto'] ?? '',
                                $row['Codigo'] ?? '',
                                $row['Categoria'] ?? '',
                                $row['Estado'] ?? '',
                            ]), 'UTF-8');
                        ?>
                        <tr data-stock-row data-product-id="<?= $id ?>" data-search="<?= inv_h($searchText) ?>">
                            <td><?= inv_h($id) ?></td>
                            <td><span class="stock-code"><?= inv_h($row['CODIPRODUCTO'] ?? 'S/C') ?></span></td>
                            <td class="stock-product"><?= inv_h($row['Producto'] ?? '-') ?></td>
                            <td><?= inv_h($row['Codigo'] ?? '-') ?></td>
                            <td><?= inv_h($row['Categoria'] ?? '-') ?></td>
                            <td><?= inv_h($row['Unidad'] ?? '-') ?></td>
                            <td class="num"><?= inv_h(inv_fmt_num($row['Stock_Min'] ?? 0)) ?></td>
                            <td class="num strong"><?= inv_h(inv_fmt_num($row['Stock_Actual'] ?? 0)) ?></td>
                            <td class="num"><?= inv_h(inv_fmt_num($row['Diferencia'] ?? 0)) ?></td>
                            <td><span class="stock-state stock-state--<?= inv_h(inv_state_class($row['Estado'] ?? '')) ?>"><?= inv_h($row['Estado'] ?? '-') ?></span></td>
                            <td><?= ((int)($row['Tiene_Movimientos'] ?? 0) === 1) ? '<span class="stock-dot yes">Si</span>' : '<span class="stock-dot">No</span>' ?></td>
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
</div>

<?php n360_render_footer(); ?>

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
    historyEndpoint: '<?= inv_h(n360_base_url('php/ver_movimientos_producto.php')) ?>',
    noteEndpoint: '<?= inv_h(n360_base_url('php/ver_nota_salida.php')) ?>',
    pdf: {
        orientation: 'landscape',
        title: 'INVENTARIO DE PRODUCTOS',
        secondTitle: 'Control de inventario actual',
        docCode: 'ALM-RPT-INV-ACTUAL',
        description: 'Reporte generado desde la vista web de inventario.',
        userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        logoLeft: '<?= inv_h(n360_base_url('img/icon.png')) ?>',
        logoRight: '<?= inv_h(n360_base_url('img/norte360_black.png')) ?>',
        useCover: false,
        fileName: 'inventario_productos_<?= date('Ymd_His') ?>.pdf'
    }
};
window.N360_NOTA_PDF_CONFIG = {
    endpoint: '<?= inv_h(n360_base_url('php/nota_pdf_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoTicket: '<?= inv_h(n360_base_url('img/completo.png')) ?>',
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
