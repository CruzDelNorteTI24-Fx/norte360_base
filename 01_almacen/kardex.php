<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function kardex_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function kardex_can_admin_almacen(bool $isAdmin): bool {
    if (!$isAdmin) {
        return false;
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

function kardex_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
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

function kardex_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta.');
    }

    kardex_bind_params($stmt, $types, $params);

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

function kardex_valid_date($value, string $fallback): string {
    $value = trim((string)$value);
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : $fallback;
}

function kardex_fmt3($value): string {
    return number_format((float)($value ?? 0), 3, '.', '');
}

function kardex_fmt2($value): string {
    return number_format((float)($value ?? 0), 2, '.', '');
}

function kardex_date_display(string $date): string {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt ? $dt->format('d/m/Y') : $date) . ' 00:00:00';
}

function kardex_datetime_display($value): string {
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i:s', $ts) : '-';
}

function kardex_search_text(array $parts): string {
    return mb_strtolower(implode(' ', array_map(static fn($v) => (string)$v, $parts)), 'UTF-8');
}

$isAdmin = (($_SESSION['web_rol'] ?? '') === 'Admin');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

if (!kardex_can_admin_almacen($isAdmin)) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Kardex'));
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
$usarRango = !isset($_GET['usar_rango']) || (string)$_GET['usar_rango'] === '1';
$desde = kardex_valid_date($_GET['desde'] ?? '', $today);
$hasta = kardex_valid_date($_GET['hasta'] ?? '', $today);

if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$pageError = '';
$rows = [];
$productosMostrados = 0;
$movimientosMostrados = 0;
$totalEntradas = 0.0;
$totalSalidas = 0.0;
$saldoGeneral = 0.0;

try {
    if (!$usarRango) {
        $minRows = kardex_fetch_all($conn, "
            SELECT MIN(DATE(clm_alm_mov_fecha_registro)) AS fecha_min
            FROM tb_alm_movimientos
        ");
        $desde = (string)($minRows[0]['fecha_min'] ?? $today);
        if ($desde === '') {
            $desde = $today;
        }
        $hasta = $today;
    }

    $catalogo = kardex_fetch_all($conn, "
        SELECT
            p.clm_alm_producto_id AS id_producto,
            CONCAT(
                '(',
                CONVERT(COALESCE(p.clm_alm_producto_codigo, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                ') ',
                CONVERT(COALESCE(p.clm_alm_producto_NOMBRE, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci
            ) AS producto,
            CONVERT(COALESCE(p.clm_alm_producto_unidad, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS unidad,
            CONCAT(
                '(',
                CONVERT(COALESCE(c.clm_alm_categoria_NOMBRE, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                ') ',
                CONVERT(COALESCE(c.clm_alm_categoria_DESCRIPCION, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci
            ) AS categoria
        FROM tb_alm_producto p
        LEFT JOIN tb_alm_categoria c
            ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
        ORDER BY p.clm_alm_producto_id ASC
    ");

    $saldosRows = kardex_fetch_all($conn, "
        SELECT
            m.clm_alm_mov_idPRODUCTO AS id_producto,
            SUM(
                CASE
                    WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN m.clm_alm_mov_cantidad
                    ELSE -m.clm_alm_mov_cantidad
                END
            ) AS saldo_cantidad,
            SUM(
                CASE
                    WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN m.clm_alm_mov_monto
                    ELSE -m.clm_alm_mov_monto
                END
            ) AS saldo_valorizado
        FROM tb_alm_movimientos m
        WHERE DATE(m.clm_alm_mov_fecha_registro) < ?
        GROUP BY m.clm_alm_mov_idPRODUCTO
    ", 's', [$desde]);

    $movimientos = kardex_fetch_all($conn, "
        SELECT
            m.clm_alm_mov_id AS nro_mov,
            m.clm_alm_mov_idPRODUCTO AS id_producto,
            m.clm_alm_mov_fecha_registro AS fecha,
            COALESCE(
                NULLIF(TRIM(CONVERT(m.clm_alm_mov_OBSERVACION USING utf8mb4) COLLATE utf8mb4_unicode_ci), '' COLLATE utf8mb4_unicode_ci),
                '-'
            ) AS observacion,
            CASE
                WHEN m.clm_alm_mov_TIPO = 'ENTRADA' THEN '01-Entrada'
                WHEN m.clm_alm_mov_TIPO = 'INVENTARIADO' THEN '00-Inventariado'
                WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN '02-Salida'
                ELSE 'Otro'
            END AS tipo_descripcion,
            CASE
                WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN COALESCE(NULLIF(TRIM(CONVERT(ns.clm_nota_proveedor USING utf8mb4) COLLATE utf8mb4_unicode_ci), '' COLLATE utf8mb4_unicode_ci), '-')
                WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN COALESCE(NULLIF(TRIM(CONVERT(m.clm_mov_ruc USING utf8mb4) COLLATE utf8mb4_unicode_ci), '' COLLATE utf8mb4_unicode_ci), '-')
                ELSE ''
            END AS proveedor,
            COALESCE(
                CASE
                    WHEN m.clm_alm_mov_TIPO = 'SALIDA' THEN COALESCE(NULLIF(TRIM(CONVERT(ns.clm_nota_sco USING utf8mb4) COLLATE utf8mb4_unicode_ci), '' COLLATE utf8mb4_unicode_ci), '-')
                    WHEN m.clm_alm_mov_TIPO IN ('ENTRADA', 'INVENTARIADO') THEN COALESCE(NULLIF(TRIM(CONVERT(m.clm_mov_factura USING utf8mb4) COLLATE utf8mb4_unicode_ci), '' COLLATE utf8mb4_unicode_ci), '-')
                    ELSE ''
                END,
                ''
            ) AS documento,
            m.clm_alm_mov_cantidad AS cantidad,
            m.clm_alm_mov_monto AS monto
        FROM tb_alm_movimientos m
        LEFT JOIN tb_notas_salida ns
            ON m.clm_alm_mov_idNOTA = ns.clm_nota_id
        WHERE DATE(m.clm_alm_mov_fecha_registro) BETWEEN ? AND ?
        ORDER BY id_producto ASC, m.clm_alm_mov_fecha_registro ASC, m.clm_alm_mov_id ASC
    ", 'ss', [$desde, $hasta]);

    $saldosPrev = [];
    foreach ($saldosRows as $saldo) {
        $pid = (int)($saldo['id_producto'] ?? 0);
        $saldosPrev[$pid] = [
            'cant' => (float)($saldo['saldo_cantidad'] ?? 0),
            'val' => (float)($saldo['saldo_valorizado'] ?? 0),
        ];
    }

    $porProducto = [];
    foreach ($movimientos as $mov) {
        $pid = (int)($mov['id_producto'] ?? 0);
        $porProducto[$pid][] = $mov;
    }

    foreach ($catalogo as $meta) {
        $pid = (int)($meta['id_producto'] ?? 0);
        $saldoCant = (float)($saldosPrev[$pid]['cant'] ?? 0);
        $saldoVal = (float)($saldosPrev[$pid]['val'] ?? 0);
        $movsProducto = $porProducto[$pid] ?? [];
        $tieneMovs = count($movsProducto) > 0;

        if (!$tieneMovs && abs($saldoCant) < 0.000001 && abs($saldoVal) < 0.000001) {
            continue;
        }

        $productosMostrados++;
        $base = [
            'categoria' => (string)($meta['categoria'] ?? '-'),
            'producto' => (string)($meta['producto'] ?? '-'),
            'unidad' => (string)($meta['unidad'] ?? '-'),
        ];

        $rows[] = array_merge($base, [
            'row_type' => 'initial',
            'fecha' => kardex_date_display($desde),
            'nro_mov' => '-',
            'tipo_mov' => 'INVENTARIO INICIAL',
            'documento' => '-',
            'proveedor' => '-',
            'observaciones' => '-',
            'entrada' => '0.000',
            'salida' => '0.000',
            'saldo' => kardex_fmt3($saldoCant),
            'ingreso' => '',
            'gasto' => '',
            'saldo_mont' => kardex_fmt2($saldoVal),
            'costo_unitario' => '',
        ]);

        foreach ($movsProducto as $mov) {
            $descripcion = (string)($mov['tipo_descripcion'] ?? 'Otro');
            $cantidad = (float)($mov['cantidad'] ?? 0);
            $monto = (float)($mov['monto'] ?? 0);
            $entrada = '0.000';
            $salida = '0.000';
            $ingreso = '0.00';
            $gasto = '0.00';

            if ($descripcion === '01-Entrada' || $descripcion === '00-Inventariado') {
                $entrada = kardex_fmt3($cantidad);
                $ingreso = kardex_fmt2($monto);
                $saldoCant += $cantidad;
                $saldoVal += $monto;
                $totalEntradas += $cantidad;
            } elseif ($descripcion === '02-Salida') {
                $salida = kardex_fmt3($cantidad);
                $gasto = kardex_fmt2($monto);
                $saldoCant -= $cantidad;
                $saldoVal -= $monto;
                $totalSalidas += $cantidad;
            }

            $movimientosMostrados++;

            $rows[] = array_merge($base, [
                'row_type' => 'movement',
                'fecha' => kardex_datetime_display($mov['fecha'] ?? ''),
                'nro_mov' => (string)($mov['nro_mov'] ?? ''),
                'tipo_mov' => $descripcion,
                'documento' => (string)($mov['documento'] ?? ''),
                'proveedor' => (string)($mov['proveedor'] ?? ''),
                'observaciones' => (string)($mov['observacion'] ?? ''),
                'entrada' => $entrada,
                'salida' => $salida,
                'saldo' => kardex_fmt3($saldoCant),
                'ingreso' => $ingreso,
                'gasto' => $gasto,
                'saldo_mont' => kardex_fmt2($saldoVal),
                'costo_unitario' => $cantidad ? kardex_fmt2($monto / $cantidad) : '0.00',
            ]);
        }

        $saldoGeneral += $saldoCant;

        $rows[] = array_merge($base, [
            'row_type' => 'balance',
            'fecha' => '',
            'nro_mov' => '-',
            'tipo_mov' => 'SALDO',
            'documento' => '-',
            'proveedor' => '-',
            'observaciones' => '-',
            'entrada' => '',
            'salida' => '',
            'saldo' => kardex_fmt3($saldoCant),
            'ingreso' => '',
            'gasto' => '',
            'saldo_mont' => kardex_fmt2($saldoVal),
            'costo_unitario' => '',
        ]);
    }
} catch (Throwable $e) {
    $pageError = $e->getMessage();
    $rows = [];
}

$periodoLabel = $usarRango
    ? 'Desde ' . date('d/m/Y', strtotime($desde)) . ' hasta ' . date('d/m/Y', strtotime($hasta))
    : 'Historico hasta ' . date('d/m/Y', strtotime($hasta));

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
    <title>Kardex | Norte360</title>
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
    <link rel="stylesheet" href="<?= n360_asset('assets/css/kardex_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Kardex', 'subtitle' => 'Movimientos valorizados de almacen']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content n360-stock-page n360-kardex-page">
        <section class="stock-hero kardex-hero">
            <div>
                <span class="stock-eyebrow"><i class="bi bi-journal-text"></i> Almacen - Solo administradores</span>
                <h1>Kardex general</h1>
                <p>Inventario inicial, entradas, salidas y saldos acumulados por producto segun el rango operativo.</p>
            </div>
            <div class="kardex-actions">
                <button type="button" class="stock-btn stock-btn--light" data-kardex-export-excel data-table-id="kardexTable">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    Excel
                </button>
                <button type="button" class="stock-btn stock-btn--primary" data-kardex-export-pdf data-table-id="kardexTable">
                    <i class="bi bi-file-earmark-pdf"></i>
                    PDF
                </button>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= kardex_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="stock-kpis kardex-kpis">
            <article class="stock-kpi">
                <span>Productos</span>
                <strong data-kardex-visible-products><?= kardex_h($productosMostrados) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Movimientos</span>
                <strong data-kardex-visible-count><?= kardex_h($movimientosMostrados) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Entradas</span>
                <strong><?= kardex_h(kardex_fmt3($totalEntradas)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--orange">
                <span>Salidas</span>
                <strong><?= kardex_h(kardex_fmt3($totalSalidas)) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--blue">
                <span>Saldo general</span>
                <strong><?= kardex_h(kardex_fmt3($saldoGeneral)) ?></strong>
            </article>
        </section>

        <form class="stock-filters kardex-filters" method="get" autocomplete="off">
            <label class="stock-field">
                <span>Desde</span>
                <input type="date" name="desde" value="<?= kardex_h($desde) ?>" data-kardex-date>
            </label>
            <label class="stock-field">
                <span>Hasta</span>
                <input type="date" name="hasta" value="<?= kardex_h($hasta) ?>" data-kardex-date>
            </label>
            <label class="stock-check kardex-range-check">
                <input type="hidden" name="usar_rango" value="0">
                <input type="checkbox" name="usar_rango" value="1" <?= $usarRango ? 'checked' : '' ?> data-kardex-range-toggle>
                <span>Usar rango de fechas</span>
            </label>
            <label class="stock-field stock-field--grow">
                <span>Buscar en tabla</span>
                <input type="search" data-kardex-search placeholder="Producto, categoria, documento, proveedor...">
            </label>
            <button type="submit" class="stock-btn stock-btn--primary">
                <i class="bi bi-funnel"></i>
                Buscar
            </button>
            <a class="stock-btn stock-btn--ghost" href="kardex.php">
                <i class="bi bi-x-circle"></i>
                Limpiar
            </a>
        </form>

        <section class="stock-card kardex-card">
            <div class="stock-card-head">
                <div>
                    <h2>Kardex de productos</h2>
                    <p><?= kardex_h($periodoLabel) ?>. Las columnas monetarias se mantienen para calculo interno y se omiten del PDF/Excel como en escritorio.</p>
                </div>
                <span><?= kardex_h($periodoLabel) ?></span>
            </div>
            <div class="stock-table-wrap kardex-table-wrap">
                <table class="stock-table kardex-table" id="kardexTable" data-report-title="Kardex general" data-report-subtitle="<?= kardex_h($periodoLabel) ?>">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>N° Mov.</th>
                            <th>Tipo Mov.</th>
                            <th>Categoria</th>
                            <th>Producto</th>
                            <th>Un.</th>
                            <th>Documento</th>
                            <th>Proveedor</th>
                            <th>Observaciones</th>
                            <th>Entrada</th>
                            <th>Salida</th>
                            <th>Saldo</th>
                            <th class="kardex-hidden-col" data-export-hidden>Ingreso (s/.)</th>
                            <th class="kardex-hidden-col" data-export-hidden>Gasto (s/.)</th>
                            <th class="kardex-hidden-col" data-export-hidden>Saldo (MONT)</th>
                            <th class="kardex-hidden-col" data-export-hidden>Costo Unitario</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr class="kardex-empty-row">
                                <td colspan="12">No hay movimientos ni saldos para el rango seleccionado.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $search = kardex_h(kardex_search_text([
                                    $row['fecha'] ?? '',
                                    $row['nro_mov'] ?? '',
                                    $row['tipo_mov'] ?? '',
                                    $row['categoria'] ?? '',
                                    $row['producto'] ?? '',
                                    $row['documento'] ?? '',
                                    $row['proveedor'] ?? '',
                                    $row['observaciones'] ?? '',
                                ]));
                                $rowType = kardex_h($row['row_type'] ?? 'movement');
                            ?>
                            <tr class="kardex-row kardex-row--<?= $rowType ?>" data-kardex-row data-row-type="<?= $rowType ?>" data-search="<?= $search ?>">
                                <td><?= kardex_h($row['fecha'] ?? '') ?></td>
                                <td><?= kardex_h($row['nro_mov'] ?? '') ?></td>
                                <td><span class="kardex-type kardex-type--<?= $rowType ?>"><?= kardex_h($row['tipo_mov'] ?? '') ?></span></td>
                                <td><?= kardex_h($row['categoria'] ?? '') ?></td>
                                <td><?= kardex_h($row['producto'] ?? '') ?></td>
                                <td><?= kardex_h($row['unidad'] ?? '') ?></td>
                                <td><?= kardex_h($row['documento'] ?? '') ?></td>
                                <td><?= kardex_h($row['proveedor'] ?? '') ?></td>
                                <td><?= kardex_h($row['observaciones'] ?? '') ?></td>
                                <td class="num"><?= kardex_h($row['entrada'] ?? '') ?></td>
                                <td class="num"><?= kardex_h($row['salida'] ?? '') ?></td>
                                <td class="num strong"><?= kardex_h($row['saldo'] ?? '') ?></td>
                                <td class="kardex-hidden-col" data-export-hidden><?= kardex_h($row['ingreso'] ?? '') ?></td>
                                <td class="kardex-hidden-col" data-export-hidden><?= kardex_h($row['gasto'] ?? '') ?></td>
                                <td class="kardex-hidden-col" data-export-hidden><?= kardex_h($row['saldo_mont'] ?? '') ?></td>
                                <td class="kardex-hidden-col" data-export-hidden><?= kardex_h($row['costo_unitario'] ?? '') ?></td>
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

<script>
window.N360_KARDEX_REPORT = {
    pdf: {
        orientation: 'landscape',
        title: 'KARDEX GENERAL',
        secondTitle: 'Reporte general de Kardex',
        docCode: 'ALM-RPT-KARDEX-GEN',
        description: 'Kardex generado desde la vista web de almacen.',
        userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        logoLeft: '<?= kardex_h(n360_base_url('img/icon.png')) ?>',
        logoRight: '<?= kardex_h(n360_base_url('img/norte360_black.png')) ?>',
        useCover: false,
        fileName: 'kardex_<?= date('Ymd_His') ?>.pdf'
    },
    excelFileName: 'kardex_<?= date('Ymd_His') ?>.xlsx',
    period: <?= json_encode($periodoLabel, JSON_UNESCAPED_UNICODE) ?>,
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="<?= n360_asset('assets/js/kardex_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
