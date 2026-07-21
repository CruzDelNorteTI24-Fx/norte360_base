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

if (!n360_is_admin() && !(n360_puede_modulo(6) && n360_puede_vista('rrhh-registeralm'))) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Actas de uniformes'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

function rrhh_actas_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rrhh_actas_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = [];
    foreach ($params as $k => &$v) $refs[$k] = &$v;
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function rrhh_actas_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    rrhh_actas_bind($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'No se pudo ejecutar la consulta.';
        $stmt->close();
        throw new RuntimeException($error);
    }
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function rrhh_actas_date($value): string {
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function rrhh_actas_money($value): string {
    return 'S/ ' . number_format((float)$value, 2, '.', ',');
}

function rrhh_actas_column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    return rrhh_actas_fetch_all($conn, $sql, 'ss', [$table, $column]) !== [];
}

function rrhh_actas_acta_codigo(array $row): string {
    $codigo = trim((string)($row['clm_rrhh_acta_codigo'] ?? ''));
    if ($codigo !== '') {
        return $codigo;
    }

    $serie = trim((string)($row['clm_rrhh_acta_serie'] ?? 'RA')) ?: 'RA';
    $corr = (int)($row['clm_rrhh_acta_corr'] ?? 0);
    if ($corr > 0) {
        return $serie . '-' . str_pad((string)$corr, 4, '0', STR_PAD_LEFT);
    }

    return 'RA-' . str_pad((string)(int)($row['clm_rrhh_acta_id'] ?? 0), 4, '0', STR_PAD_LEFT);
}

$hasActaCodigo = rrhh_actas_column_exists($conn, 'tb_rrhh_acta_uniformes', 'clm_rrhh_acta_codigo');

$desde = trim((string)($_GET['desde'] ?? date('Y-m-01')));
$hasta = trim((string)($_GET['hasta'] ?? date('Y-m-d')));
$buscar = trim((string)($_GET['buscar'] ?? ''));
$motivo = strtoupper(trim((string)($_GET['motivo'] ?? 'TODOS')));

$where = ['a.clm_rrhh_acta_estado = 1'];
$types = '';
$params = [];

if ($desde !== '') {
    $where[] = 'a.clm_rrhh_acta_fecha_entrega >= ?';
    $types .= 's';
    $params[] = $desde;
}
if ($hasta !== '') {
    $where[] = 'a.clm_rrhh_acta_fecha_entrega <= ?';
    $types .= 's';
    $params[] = $hasta;
}
if ($motivo !== '' && $motivo !== 'TODOS') {
    $where[] = 'a.clm_rrhh_acta_motivo = ?';
    $types .= 's';
    $params[] = $motivo;
}
if ($buscar !== '') {
    $searchParts = [
        "CONVERT(a.clm_rrhh_acta_trabajador_nombre USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, '%')",
        "CONVERT(a.clm_rrhh_acta_trabajador_dni USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, '%')",
        "CONVERT(ns.clm_nota_sco USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, '%')",
    ];
    $types .= 'sss';
    $params[] = $buscar;
    $params[] = $buscar;
    $params[] = $buscar;

    if ($hasActaCodigo) {
        $searchParts[] = "CONVERT(a.clm_rrhh_acta_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, '%')";
        $types .= 's';
        $params[] = $buscar;
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$rows = [];
$pageError = '';
try {
    $rows = rrhh_actas_fetch_all($conn, "
        SELECT
            a.*,
            ns.clm_nota_id,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_sco AS CHAR)), ''), CONCAT(ns.clm_nota_serie, '-', LPAD(ns.clm_nota_corr, 4, '0'))) AS nota_codigo,
            COUNT(m.clm_alm_mov_id) AS total_items,
            SUM(COALESCE(m.clm_alm_mov_cantidad, 0)) AS total_cantidad
        FROM tb_rrhh_acta_uniformes a
        JOIN tb_notas_salida ns ON ns.clm_nota_id = a.clm_rrhh_acta_idnota
        LEFT JOIN tb_alm_movimientos m ON m.clm_alm_mov_idNOTA = ns.clm_nota_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY a.clm_rrhh_acta_id
        ORDER BY a.clm_rrhh_acta_fecha_entrega DESC, a.clm_rrhh_acta_id DESC
        LIMIT 400
    ", $types, $params);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$totalActas = count($rows);
$totalItems = array_sum(array_map(fn($r) => (int)($r['total_items'] ?? 0), $rows));
$totalMonto = array_sum(array_map(fn($r) => (float)($r['clm_rrhh_acta_total'] ?? 0), $rows));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actas de uniformes | Norte360</title>
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
    <link rel="stylesheet" href="<?= n360_asset('assets/css/rrhh_actas_uniformes.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Actas de uniformes', 'subtitle' => 'RRHH']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>
    <div class="n360-main__inner rrhh-actas" id="rrhhActasPage">
        <section class="rrhh-actas__hero">
            <div>
                <p><i class="bi bi-file-earmark-text"></i> RRHH - bienes controlados</p>
                <h1>Actas de entrega de uniformes</h1>
                <span>Consulta y descarga de actas con correlativo propio vinculadas a notas RS.</span>
            </div>
            <a class="rrhh-actas__btn rrhh-actas__btn--primary" href="<?= rrhh_actas_h(n360_base_url('01_contratos/registro_almacen.php')) ?>">
                <i class="bi bi-box-arrow-up"></i> Nueva entrega
            </a>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="rrhh-actas__alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= rrhh_actas_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="rrhh-actas__kpis">
            <article><span>Actas</span><strong><?= rrhh_actas_h($totalActas) ?></strong></article>
            <article><span>Items vinculados</span><strong><?= rrhh_actas_h($totalItems) ?></strong></article>
            <article><span>Monto referencial</span><strong><?= rrhh_actas_h(rrhh_actas_money($totalMonto)) ?></strong></article>
        </section>

        <form class="rrhh-actas__filters" method="get" autocomplete="off">
            <label><span>Desde</span><input type="date" name="desde" value="<?= rrhh_actas_h($desde) ?>"></label>
            <label><span>Hasta</span><input type="date" name="hasta" value="<?= rrhh_actas_h($hasta) ?>"></label>
            <label>
                <span>Motivo</span>
                <select name="motivo">
                    <?php
                    $motivos = [
                        'TODOS' => 'Todos',
                        'INICIO_CONTRATO_CORTESIA' => 'Inicio/Cortesia',
                        'REPOSICION_DESGASTE' => 'Reposicion/desgaste',
                        'PERDIDA_ROBO' => 'Perdida/Robo',
                        'COMPRA' => 'Compra',
                    ];
                    foreach ($motivos as $key => $label):
                    ?>
                    <option value="<?= rrhh_actas_h($key) ?>" <?= $motivo === $key ? 'selected' : '' ?>><?= rrhh_actas_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="rrhh-actas__search"><span>Buscar</span><input type="search" name="buscar" value="<?= rrhh_actas_h($buscar) ?>" placeholder="Acta, nota, trabajador o DNI..."></label>
            <button class="rrhh-actas__btn rrhh-actas__btn--primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
            <a class="rrhh-actas__btn" href="<?= rrhh_actas_h(n360_base_url('01_contratos/actas_uniformes.php')) ?>"><i class="bi bi-x-circle"></i> Limpiar</a>
        </form>

        <section class="rrhh-actas__table-card">
            <header>
                <div>
                    <h2>Historial de actas</h2>
                    <p>La data de productos se lee desde los movimientos vinculados a la nota.</p>
                </div>
                <span><?= rrhh_actas_h($totalActas) ?> registros</span>
            </header>
            <div class="rrhh-actas__table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Acta RRHH</th>
                            <th>Nota RS</th>
                            <th>Fecha</th>
                            <th>Trabajador</th>
                            <th>Motivo</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="rrhh-actas__empty">No hay actas para los filtros actuales.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><span class="rrhh-actas__badge"><?= rrhh_actas_h(rrhh_actas_acta_codigo($row)) ?></span></td>
                                <td><strong><?= rrhh_actas_h($row['nota_codigo'] ?? '-') ?></strong></td>
                                <td><?= rrhh_actas_h(rrhh_actas_date($row['clm_rrhh_acta_fecha_entrega'] ?? '')) ?></td>
                                <td>
                                    <strong><?= rrhh_actas_h($row['clm_rrhh_acta_trabajador_nombre'] ?? '-') ?></strong>
                                    <small>DNI <?= rrhh_actas_h($row['clm_rrhh_acta_trabajador_dni'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <span class="rrhh-actas__chip"><?= rrhh_actas_h(str_replace('_', ' ', $row['clm_rrhh_acta_motivo'] ?? '-')) ?></span>
                                </td>
                                <td><?= rrhh_actas_h((int)($row['total_items'] ?? 0)) ?></td>
                                <td><?= rrhh_actas_h(rrhh_actas_money($row['clm_rrhh_acta_total'] ?? 0)) ?></td>
                                <td>
                                    <div class="rrhh-actas__actions">
                                        <button type="button" data-acta-pdf="<?= rrhh_actas_h($row['clm_rrhh_acta_id'] ?? '') ?>"><i class="bi bi-filetype-pdf"></i> Acta</button>
                                        <button type="button" data-nota-pdf="<?= rrhh_actas_h($row['clm_nota_id'] ?? '') ?>"><i class="bi bi-receipt"></i> Nota</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php n360_render_footer(); ?>
<script>
window.N360_RRHH_ACTA_PDF_CONFIG = {
    endpoint: '<?= rrhh_actas_h(n360_base_url('php/rrhh_acta_uniforme_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoLeft: '<?= rrhh_actas_h(n360_base_url('img/icon.png')) ?>',
    logoRight: '<?= rrhh_actas_h(n360_base_url('img/norte360_black.png')) ?>'
};
window.N360_NOTA_PDF_CONFIG = {
    endpoint: '<?= rrhh_actas_h(n360_base_url('php/nota_pdf_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoTicket: '<?= rrhh_actas_h(n360_base_url('img/completo.png')) ?>',
    footerLabel: 'NORTE 360'
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_notas_common.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_bienes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/rrhh/n360_acta_uniformes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/nota_pdf_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/rrhh_actas_uniformes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
