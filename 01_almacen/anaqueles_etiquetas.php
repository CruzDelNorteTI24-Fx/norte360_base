<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

define('N360_ALM_ANAQUELES', true);
require_once __DIR__ . '/lib/anaqueles_etiquetas_lib.php';

alm_ana_require_access('Anaqueles y etiquetas');

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

$buscar = trim((string)($_GET['buscar'] ?? ''));
$sede = trim((string)($_GET['sede'] ?? 'TODAS'));
$pageError = '';
$sedes = [];
$anaqueles = [];
$resumen = [
    'anaqueles' => 0,
    'sedes' => 0,
    'etiquetas' => 0,
    'productos' => 0,
];

try {
    $sedes = alm_ana_sedes($conn);
    $resumen = alm_ana_resumen($conn, $sede);
    $anaqueles = alm_ana_listar_anaqueles($conn, [
        'buscar' => $buscar,
        'sede' => $sede,
    ]);

    foreach ($anaqueles as &$anaquel) {
        $anaquel['qr_url'] = alm_ana_qr_url($anaquel);
        $anaquel['qr_file'] = 'QR_ANAQUEL_' . alm_ana_safe_filename((string)($anaquel['codigo'] ?: $anaquel['id'])) . '.png';
    }
    unset($anaquel);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$sedeLabel = 'Todas las sedes';
foreach ($sedes as $itemSede) {
    if ((string)$itemSede['id'] === (string)$sede) {
        $sedeLabel = (string)$itemSede['nombre'];
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
    <title>Anaqueles y etiquetas | Norte360</title>
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
<?php n360_render_header(['title' => 'Anaqueles y etiquetas', 'subtitle' => 'Almacen operativo']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content n360-stock-page ana-page">
        <section class="stock-hero ana-hero">
            <div>
                <span class="stock-eyebrow"><i class="bi bi-grid-3x3-gap-fill"></i> Almacen - ubicaciones</span>
                <h1>Anaqueles y etiquetas</h1>
                <p>Visualiza el contenido operativo por anaquel y descarga un QR protegido para consultar su contenido desde cualquier equipo con sesion autorizada.</p>
                <div class="ana-hero-tags">
                    <span><i class="bi bi-shield-lock"></i> Requiere login</span>
                    <span><i class="bi bi-box-seam"></i> Permiso modulo Almacen</span>
                    <span><i class="bi bi-slash-circle"></i> Sin gestion de activos fijos</span>
                </div>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= alm_ana_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="stock-kpis ana-kpis">
            <article class="stock-kpi stock-kpi--blue">
                <span>Anaqueles</span>
                <strong><?= alm_ana_h($resumen['anaqueles']) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--green">
                <span>Etiquetas vigentes</span>
                <strong><?= alm_ana_h($resumen['etiquetas']) ?></strong>
            </article>
            <article class="stock-kpi stock-kpi--amber">
                <span>Productos ubicados</span>
                <strong><?= alm_ana_h($resumen['productos']) ?></strong>
            </article>
            <article class="stock-kpi">
                <span>Sedes con anaqueles</span>
                <strong><?= alm_ana_h($resumen['sedes']) ?></strong>
            </article>
        </section>

        <form class="stock-filters ana-filters" method="get" action="anaqueles_etiquetas.php">
            <label class="stock-field stock-field--search">
                <span>Buscar</span>
                <i class="bi bi-search"></i>
                <input type="text" name="buscar" value="<?= alm_ana_h($buscar) ?>" placeholder="Codigo, anaquel o sede...">
            </label>
            <label class="stock-field">
                <span>Sede</span>
                <select name="sede">
                    <option value="TODAS" <?= $sede === 'TODAS' ? 'selected' : '' ?>>TODAS</option>
                    <?php foreach ($sedes as $itemSede): ?>
                        <option value="<?= alm_ana_h($itemSede['id']) ?>" <?= (string)$sede === (string)$itemSede['id'] ? 'selected' : '' ?>>
                            <?= alm_ana_h($itemSede['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="stock-btn stock-btn--primary">
                <i class="bi bi-funnel"></i>
                Filtrar
            </button>
            <a class="stock-btn stock-btn--soft" href="anaqueles_etiquetas.php">
                <i class="bi bi-x-circle"></i>
                Limpiar
            </a>
        </form>

        <section class="ana-scan-card ana-scan-card--list">
            <div class="alerta ok">
                <i class="bi bi-check-circle"></i>
                <div>
                    <strong>Consulta de anaqueles activa</strong>
                    <span>Selecciona un anaquel para revisar su contenido o generar su QR protegido.</span>
                </div>
            </div>
            <div class="hint-box">
                <div class="hint-title"><i class="bi bi-info-circle"></i> Uso operativo</div>
                <ul class="hint-list">
                    <li><strong>Ver</strong>: abre el contenido agrupado por producto como en el escaner.</li>
                    <li><strong>QR</strong>: previsualiza y descarga la imagen para pegarla en el anaquel.</li>
                    <li><strong>Permisos</strong>: el QR exige sesion activa y acceso al modulo de Almacen.</li>
                </ul>
            </div>
        </section>

        <section class="ana-table-card ana-table-card--scanner">
            <div class="ana-table-card__head">
                <div>
                    <h2>Anaqueles operativos</h2>
                    <p><?= alm_ana_h($sedeLabel) ?> · <?= count($anaqueles) ?> resultado(s)</p>
                </div>
                <span class="ana-pill"><i class="bi bi-qr-code"></i> QR por anaquel</span>
            </div>

            <div class="ana-table-wrap">
                <table class="ana-table">
                    <thead>
                        <tr>
                            <th>Anaquel</th>
                            <th>Sede</th>
                            <th>Contenido</th>
                            <th>Ultima etiqueta</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$anaqueles): ?>
                        <tr>
                            <td colspan="5" class="ana-empty">No hay anaqueles para los filtros actuales.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($anaqueles as $anaquel): ?>
                        <?php
                        $codigo = trim((string)($anaquel['codigo'] ?? ''));
                        $codigoMostrar = $codigo !== '' ? $codigo : ('ANA-' . (int)$anaquel['id']);
                        ?>
                        <tr>
                            <td>
                                <div class="ana-main-cell">
                                    <span class="ana-code"><?= alm_ana_h($codigoMostrar) ?></span>
                                    <strong><?= alm_ana_h($anaquel['nombre'] ?? '-') ?></strong>
                                    <small>ID <?= alm_ana_h($anaquel['id'] ?? '') ?></small>
                                </div>
                            </td>
                            <td><?= alm_ana_h($anaquel['sede'] ?? 'Sin sede') ?></td>
                            <td>
                                <div class="ana-content-count">
                                    <strong><?= alm_ana_h($anaquel['etiquetas'] ?? 0) ?></strong>
                                    <span>etiquetas</span>
                                    <strong><?= alm_ana_h($anaquel['productos'] ?? 0) ?></strong>
                                    <span>productos</span>
                                </div>
                            </td>
                            <td><?= alm_ana_h($anaquel['ultima_fecha'] ? date('d/m/Y H:i', strtotime((string)$anaquel['ultima_fecha'])) : '-') ?></td>
                            <td>
                                <div class="ana-actions">
                                    <a class="stock-mini-btn" href="anaquel_contenido.php?<?= $codigo !== '' ? 'codigo=' . urlencode($codigo) : 'id=' . urlencode((string)$anaquel['id']) ?>">
                                        <i class="bi bi-eye"></i>
                                        Ver
                                    </a>
                                    <button
                                        type="button"
                                        class="stock-mini-btn ana-qr-button"
                                        data-ana-qr
                                        data-qr-url="<?= alm_ana_h($anaquel['qr_url']) ?>"
                                        data-ana-code="<?= alm_ana_h($codigoMostrar) ?>"
                                        data-ana-name="<?= alm_ana_h($anaquel['nombre'] ?? '') ?>"
                                        data-ana-file="<?= alm_ana_h($anaquel['qr_file']) ?>">
                                        <i class="bi bi-qr-code"></i>
                                        QR
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
