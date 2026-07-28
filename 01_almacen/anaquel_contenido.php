<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

define('N360_ALM_ANAQUELES', true);
require_once __DIR__ . '/lib/anaqueles_etiquetas_lib.php';

alm_ana_require_access('Contenido de anaquel');

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

$anaquel = null;
$productos = [];
$etiquetas = [];
$pageError = '';

try {
    $anaquel = alm_ana_buscar_anaquel($conn, [
        'id' => $_GET['id'] ?? 0,
        'codigo' => $_GET['codigo'] ?? '',
    ]);

    if ($anaquel) {
        $productos = alm_ana_contenido_productos($conn, (int)$anaquel['id']);
        $etiquetas = alm_ana_contenido_etiquetas($conn, (int)$anaquel['id']);
        $anaquel['qr_url'] = alm_ana_qr_url($anaquel);
        $anaquel['qr_file'] = 'QR_ANAQUEL_' . alm_ana_safe_filename((string)($anaquel['codigo'] ?: $anaquel['id'])) . '.png';
    }
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$codigoMostrar = $anaquel ? trim((string)($anaquel['codigo'] ?: 'ANA-' . $anaquel['id'])) : '-';

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
    <title>Contenido de anaquel | Norte360</title>
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
    <link rel="stylesheet" href="<?= n360_asset('assets/css/anaqueles_etiquetas_n360.css?anaqr=2') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Contenido de anaquel', 'subtitle' => 'Consulta protegida']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content n360-stock-page ana-page">
        <section class="stock-hero ana-hero ana-hero--detail">
            <div>
                <span class="stock-eyebrow"><i class="bi bi-shield-check"></i> Consulta protegida</span>
                <h1><?= alm_ana_h($anaquel ? ($anaquel['nombre'] ?: 'Anaquel') : 'Anaquel no encontrado') ?></h1>
                <p>
                    <?php if ($anaquel): ?>
                        Codigo <?= alm_ana_h($codigoMostrar) ?> &middot; <?= alm_ana_h($anaquel['sede'] ?? 'Sin sede') ?>.
                    <?php else: ?>
                        El QR o enlace no coincide con un anaquel activo.
                    <?php endif; ?>
                </p>
            </div>
            <div class="ana-hero-actions">
                <a class="stock-btn stock-btn--soft" href="anaqueles_etiquetas.php">
                    <i class="bi bi-arrow-left"></i>
                    Anaqueles
                </a>
                <?php if ($anaquel): ?>
                    <button
                        type="button"
                        class="stock-btn stock-btn--primary"
                        data-ana-qr
                        data-qr-url="<?= alm_ana_h($anaquel['qr_url']) ?>"
                        data-ana-code="<?= alm_ana_h($codigoMostrar) ?>"
                        data-ana-name="<?= alm_ana_h($anaquel['nombre'] ?? '') ?>"
                        data-ana-file="<?= alm_ana_h($anaquel['qr_file']) ?>">
                        <i class="bi bi-qr-code"></i>
                        QR
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= alm_ana_h($pageError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$anaquel): ?>
            <section class="ana-table-card">
                <div class="ana-empty">No se encontro el anaquel solicitado. Vuelve al listado y genera nuevamente el QR.</div>
            </section>
        <?php else: ?>
            <section class="ana-scan-card">
                <div class="alerta ok">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Anaquel encontrado</strong>
                        <span>Contenido operativo disponible para usuarios con permiso de Almacen.</span>
                    </div>
                </div>

                <section class="ana-scan-section">
                    <h3><i class="bi bi-grid-3x3-gap"></i> Anaquel</h3>
                    <ul class="ana-scan-list">
                        <li><strong>Codigo:</strong> <?= alm_ana_h($codigoMostrar) ?></li>
                        <li><strong>Nombre:</strong> <?= alm_ana_h($anaquel['nombre'] ?: '-') ?></li>
                        <li><strong>Sede:</strong> <?= alm_ana_h($anaquel['sede'] ?: '-') ?></li>
                    </ul>
                </section>

                <div class="hint-box">
                    <div class="hint-title"><i class="bi bi-info-circle"></i> Lectura del anaquel</div>
                    <ul class="hint-list">
                        <li><strong>Stock total</strong>: cantidad actual registrada por movimientos.</li>
                        <li><strong>En este anaquel</strong>: unidades ubicadas aqui segun etiquetas vigentes.</li>
                        <li><strong>En otros</strong>: diferencia entre stock total y lo ubicado en este anaquel.</li>
                    </ul>
                </div>
            </section>

            <section class="stock-kpis ana-kpis">
                <article class="stock-kpi stock-kpi--blue">
                    <span>Productos</span>
                    <strong><?= count($productos) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--green">
                    <span>Etiquetas vigentes</span>
                    <strong><?= count($etiquetas) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--amber">
                    <span>Codigo</span>
                    <strong class="ana-kpi-code"><?= alm_ana_h($codigoMostrar) ?></strong>
                </article>
                <article class="stock-kpi">
                    <span>Sede</span>
                    <strong class="ana-kpi-text"><?= alm_ana_h($anaquel['sede'] ?? '-') ?></strong>
                </article>
            </section>

            <section class="ana-table-card ana-table-card--scanner">
                <div class="ana-table-card__head">
                    <div>
                        <h2>Contenido por producto</h2>
                        <p>Resumen agrupado de etiquetas activas ubicadas en este anaquel.</p>
                    </div>
                    <span class="ana-pill"><?= count($productos) ?> producto(s)</span>
                </div>
                <div class="tabla-wrap">
                    <table class="tabla-pro">
                        <thead>
                            <tr>
                                <th>Img</th>
                                <th>Producto</th>
                                <th>Categoria</th>
                                <th>Stock total</th>
                                <th>En este anaquel</th>
                                <th>En otros</th>
                                <th>Etiquetas</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$productos): ?>
                            <tr><td colspan="7" class="ana-empty">Este anaquel no tiene etiquetas vigentes de Almacen.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($productos as $producto): ?>
                            <?php
                                $stockTotal = (float)($producto['stock_actual'] ?? 0);
                                $enAnaquel = (float)($producto['unidades'] ?? 0);
                                $enOtros = max(0, $stockTotal - $enAnaquel);
                                $pct = $stockTotal > 0 ? min(100, max(0, ($enAnaquel / $stockTotal) * 100)) : 0;
                                $estadoStock = trim((string)($producto['estado_stock'] ?? ''));
                                $estadoClass = $estadoStock === '' ? 'badge-soft' : (stripos($estadoStock, 'ok') !== false ? 'badge-ok' : 'badge-soft');
                            ?>
                            <tr>
                                <td>
                                    <img class="mini-img" src="scanner.php?img_prod=<?= urlencode((string)($producto['id_producto'] ?? 0)) ?>" alt="Producto">
                                </td>
                                <td>
                                    <div class="ana-main-cell">
                                        <strong>(<?= alm_ana_h($producto['codigo_producto'] ?? '-') ?>) <?= alm_ana_h($producto['producto'] ?? '-') ?></strong>
                                        <small><?= alm_ana_h($producto['unidad'] ?? '') ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="ana-code"><?= alm_ana_h($producto['categoria_codigo'] ?? '-') ?></span>
                                    <div><?= alm_ana_h($producto['categoria'] ?? 'Sin categoria') ?></div>
                                </td>
                                <td>
                                    <div class="qty-cell">
                                        <strong><?= alm_ana_h(alm_ana_fmt_num($stockTotal)) ?></strong>
                                        <span class="<?= $estadoClass ?>"><?= alm_ana_h($estadoStock !== '' ? $estadoStock : 'Sin estado') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="qty-cell">
                                        <strong><?= alm_ana_h(alm_ana_fmt_num($enAnaquel)) ?></strong>
                                        <span class="distbar"><span style="width: <?= alm_ana_h(number_format($pct, 2, '.', '')) ?>%"></span></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="qty-cell">
                                        <strong><?= alm_ana_h(alm_ana_fmt_num($enOtros)) ?></strong>
                                        <span class="badge-soft">Otros / sin ubicacion</span>
                                    </div>
                                </td>
                                <td><span class="ana-tag-list"><?= alm_ana_h($producto['etiquetas'] ?? '-') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ana-table-card">
                <div class="ana-table-card__head">
                    <div>
                        <h2>Etiquetas vigentes</h2>
                        <p>Detalle operativo del anaquel. No incluye salidas consumidas ni trazabilidad de activos fijos.</p>
                    </div>
                    <span class="ana-pill"><?= count($etiquetas) ?> etiqueta(s)</span>
                </div>
                <div class="ana-table-wrap">
                    <table class="ana-table ana-table--compact">
                        <thead>
                            <tr>
                                <th>Etiqueta</th>
                                <th>Producto</th>
                                <th>Estado</th>
                                <th>Movimiento</th>
                                <th>Nota</th>
                                <th>Observacion</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$etiquetas): ?>
                            <tr><td colspan="6" class="ana-empty">Sin etiquetas vigentes.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($etiquetas as $etiqueta): ?>
                            <tr>
                                <td><span class="ana-code"><?= alm_ana_h($etiqueta['codigo'] ?? '-') ?></span></td>
                                <td>
                                    <strong>(<?= alm_ana_h($etiqueta['codigo_producto'] ?? '-') ?>) <?= alm_ana_h($etiqueta['producto'] ?? '-') ?></strong>
                                    <div class="ana-muted"><?= alm_ana_h($etiqueta['unidad'] ?? '') ?></div>
                                </td>
                                <td><span class="ana-status"><?= alm_ana_h($etiqueta['estado'] ?? '-') ?></span></td>
                                <td><?= alm_ana_h($etiqueta['tipo_movimiento'] ?? '-') ?><br><span class="ana-muted"><?= alm_ana_h($etiqueta['fecha'] ? date('d/m/Y H:i', strtotime((string)$etiqueta['fecha'])) : '-') ?></span></td>
                                <td><?= alm_ana_h($etiqueta['nota'] ?: '-') ?></td>
                                <td><?= alm_ana_h($etiqueta['observacion'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>

<div class="ana-qr-modal" id="anaQrModal" hidden>
    <div class="ana-qr-modal__backdrop" data-ana-qr-close></div>
    <section class="ana-qr-modal__panel" role="dialog" aria-modal="true" aria-labelledby="anaQrTitle">
        <button type="button" class="ana-qr-modal__close" data-ana-qr-close aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
        <span class="stock-eyebrow"><i class="bi bi-qr-code"></i> QR protegido</span>
        <h2 id="anaQrTitle">Anaquel</h2>
        <p id="anaQrText">Escanear este QR abre el contenido del anaquel con validacion de sesion y permiso de Almacen.</p>
        <div class="ana-qr-preview">
            <canvas id="anaQrCanvas" width="260" height="260" aria-label="QR del anaquel"></canvas>
        </div>
        <div class="ana-qr-modal__actions">
            <button type="button" class="stock-btn stock-btn--primary" data-ana-qr-download>
                <i class="bi bi-download"></i>
                Descargar PNG
            </button>
            <button type="button" class="stock-btn stock-btn--soft" data-ana-qr-close>
                Cerrar
            </button>
        </div>
    </section>
</div>

<script>
window.N360_ANA_QR = {
    logo: '<?= alm_ana_h(n360_base_url('img/completo.png')) ?>',
    fallbackLogo: '<?= alm_ana_h(n360_base_url('img/norte360.png')) ?>',
    brand: 'NORTE 360',
    footer: 'Almacen - acceso protegido'
};
</script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= n360_asset('assets/js/anaqueles_etiquetas_n360.js?anaqr=2') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
