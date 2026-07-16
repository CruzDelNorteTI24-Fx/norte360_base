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
require_once __DIR__ . '/../layout/almacen_movimiento_n360.php';

if (!n360_is_admin() && (!n360_puede_modulo(3) || !n360_puede_vista('a-formulreg'))) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Registrar movimiento'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/movimiento_backend.php';
require_once __DIR__ . '/movimiento_selects.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');

function alm_page_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


function alm_page_fmt_qty($value): string {
    $number = (float)str_replace(',', '.', (string)($value ?? 0));
    $text = number_format($number, 3, '.', '');
    return rtrim(rtrim($text, '0'), '.') ?: '0';
}

if (empty($_SESSION['alm_mov_csrf'])) {
    $_SESSION['alm_mov_csrf'] = bin2hex(random_bytes(24));
}

$pageError = '';
$sedes = [];
$anaqueles = [];
$recentMovements = [];
$stats = [
    'hoy' => 0,
    'entradas' => 0,
    'salidas' => 0,
    'inventariado' => 0,
];

try {
    $sedes = alm_select_sedes($conn);
    $anaqueles = alm_select_anaqueles($conn);
    $stats = array_merge($stats, alm_select_stats($conn));
    $recentMovements = alm_select_recent_movements($conn);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$csrf = (string)$_SESSION['alm_mov_csrf'];
$isAdmin = n360_is_admin();
$canEditPrices = alm_can_edit_prices();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de movimientos | Norte360</title>
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
    <link rel="stylesheet" href="<?= n360_asset('assets/css/almacen_movimiento_n360.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Registro de movimientos', 'subtitle' => 'Almacen operativo']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <div class="n360-main__inner alm-mov-page" id="almMovPage" data-api="movimiento_api.php" data-csrf="<?= alm_page_h($csrf) ?>" data-can-edit-prices="<?= $canEditPrices ? '1' : '0' ?>">
        <section class="alm-mov-hero">
            <div class="alm-mov-hero__copy">
                <div>
                    <p class="alm-mov-eyebrow"><i class="bi bi-boxes"></i> Almacen - movimiento operativo</p>
                    <h1>Registro de movimientos</h1>
                </div>
            </div>
            <div class="alm-mov-hero__actions">
                <a class="alm-btn alm-btn--soft" href="movimientos_ofi.php">
                    <i class="bi bi-clock-history"></i>
                    <span>Movimientos</span>
                </a>
                <a class="alm-btn alm-btn--primary" href="notas_almacen.php">
                    <i class="bi bi-receipt-cutoff"></i>
                    <span>Notas</span>
                </a>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="alm-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= alm_page_h($pageError) ?>
            </div>
        <?php endif; ?>


        <?php if ($isAdmin): ?>
        <section class="alm-mov-kpis">
            <article class="alm-kpi alm-kpi--blue"><span>Movimientos hoy</span><strong><?= alm_page_h((int)($stats['hoy'] ?? 0)) ?></strong></article>
            <article class="alm-kpi alm-kpi--green"><span>Entradas hoy</span><strong><?= alm_page_h(alm_page_fmt_qty($stats['entradas'] ?? 0)) ?></strong></article>
            <article class="alm-kpi alm-kpi--red"><span>Salidas hoy</span><strong><?= alm_page_h(alm_page_fmt_qty($stats['salidas'] ?? 0)) ?></strong></article>
            <article class="alm-kpi alm-kpi--amber"><span>Inventariado hoy</span><strong><?= alm_page_h(alm_page_fmt_qty($stats['inventariado'] ?? 0)) ?></strong></article>
        </section>
        <?php endif; ?>

        <section class="alm-mov-layout">
            <article class="alm-card">


        <section class="alm-modebar" aria-label="Tipo de movimiento">
            <div class="alm-modebar__title">
                <strong>Formulario de Almacén</strong>
            </div>
            <div class="alm-modebar__buttons">
                <button type="button" class="is-active" id="almEntradaMode">
                    <i class="bi bi-box-arrow-in-down"></i>
                    Entrada
                </button>
                <button type="button" id="almOpenSalida">
                    <i class="bi bi-box-arrow-up"></i>
                    Salida
                </button>
            </div>
        </section>
                <form class="alm-form" id="almEntradaForm" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="producto_id" id="almEntradaProductoId">
                    <input type="hidden" name="ubicacion_raw" id="almEntradaUbicacionRaw">
                    <input type="hidden" name="ubicacion_label" id="almEntradaUbicacionLabel">

                    <div class="alm-form__grid">
                        <div class="alm-product-line">
                            <div class="alm-selected-product" id="almEntradaProductBox">
                                <span class="alm-selected-product__empty">Selecciona un producto desde el catalogo.</span>
                            </div>
                            <button class="alm-btn alm-btn--primary" type="button" data-alm-open-catalog data-target="entrada">
                                <i class="bi bi-search"></i>
                                <span>Buscar producto</span>
                            </button>
                        </div>
                        <div class="alm-field alm-field--span-3 alm-field--type">
                            <span>Tipo</span>
                            <input type="hidden" name="tipo" id="almEntradaTipo" value="">
                            <div class="alm-type-label" id="almEntradaTipoLabel" data-tipo="">
                                <i class="bi bi-magic"></i>
                                <strong>Pendiente</strong>
                            </div>
                            <small class="alm-help" id="almEntradaTipoHelp"> </small>
                        </div>
                        <label class="alm-field alm-field--span-3">
                            <span>Cantidad *</span>
                            <input name="cantidad" id="almEntradaCantidad" type="number" min="0.001" step="0.001" placeholder="0">
                        </label>
                        <label class="alm-field alm-field--span-3 alm-field--price <?= $canEditPrices ? '' : 'alm-hidden' ?>">
                            <span>Precio unitario</span>
                            <input name="precio_unitario" id="almEntradaPrecio" type="number" min="0" step="0.0001" placeholder="0.0000" <?= $canEditPrices ? '' : 'readonly tabindex="-1"' ?>>
                        </label>
                        <label class="alm-field alm-field--span-3 alm-field--price <?= $canEditPrices ? '' : 'alm-hidden' ?>">
                            <span>Monto</span>
                            <input name="monto" id="almEntradaMonto" type="number" min="0" step="0.0001" readonly placeholder="0.0000">
                        </label>

                        <div class="alm-options-row">
                            <label class="alm-toggle">
                                <input type="checkbox" id="almToggleRefs">
                                <span>Mostrar proveedor y doc. ref.</span>
                            </label>
                            <label class="alm-toggle alm-toggle--strong">
                                <input type="checkbox" name="auto_pdf" id="almEntradaAutoPdf" value="1">
                                <span>Generar PDF automaticamente al guardar</span>
                            </label>
                        </div>

                        <div class="alm-refs-group" id="almRefsGroup">
                            <label class="alm-field">
                                <span>Proveedor / RUC</span>
                                <input name="proveedor" id="almEntradaProveedor" type="text" placeholder="Proveedor o RUC de referencia">
                            </label>
                            <label class="alm-field">
                                <span>Documento ref.</span>
                                <input name="factura" id="almEntradaFactura" type="text" placeholder="Factura, boleta u otro documento">
                            </label>
                        </div>
                        <label class="alm-field alm-field--span-12">
                            <span>Archivo de respaldo</span>
                            <input name="documento" id="almEntradaDocumento" type="file">
                        </label>

                        <label class="alm-field alm-field--span-12">
                            <span>Observacion</span>
                            <textarea name="observacion" id="almEntradaObservacion" rows="3" placeholder="Detalle operativo del ingreso..."></textarea>
                        </label>

                        <div class="alm-form__actions">
                            <button class="alm-btn alm-btn--ghost" type="button" id="almEntradaReset">
                                <i class="bi bi-eraser"></i>
                                <span>Limpiar</span>
                            </button>
                            <button class="alm-btn alm-btn--primary" type="submit" id="almEntradaSubmit">
                                <i class="bi bi-save2"></i>
                                <span>Registrar entrada</span>
                            </button>
                        </div>
                    </div>
                </form>
            </article>

            <div class="alm-side-stack">
                <section class="alm-location alm-location--side">
                            <div class="alm-location__head">
                                <div>
                                    <p class="alm-location__label">Ubicacion y etiquetado</p>
                                    <p class="alm-help">Si seleccionas sede y anaquel, el movimiento queda preparado para generar etiquetas.</p>
                                </div>
                                <div class="alm-location__preview" id="almLocationPreview">
                                    <i class="bi bi-geo-alt"></i>
                                    <span>Sin ubicacion seleccionada</span>
                                </div>
                            </div>

                            <div class="alm-location__grid">
                                <label class="alm-field alm-field--span-3">
                                    <span>Sede</span>
                                    <select name="sede_id" id="almEntradaSede" form="almEntradaForm">
                                        <option value="">Sin sede</option>
                                        <?php foreach ($sedes as $sede): ?>
                                            <option value="<?= alm_page_h($sede['id'] ?? '') ?>"><?= alm_page_h($sede['nombre'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="alm-field alm-field--span-3">
                                    <span>Anaquel</span>
                                    <select name="anaquel_id" id="almEntradaAnaquel" form="almEntradaForm">
                                        <option value="">Sin anaquel</option>
                                        <?php foreach ($anaqueles as $anaquel): ?>
                                            <option value="<?= alm_page_h($anaquel['id'] ?? '') ?>"
                                                    data-sede="<?= alm_page_h($anaquel['sede_id'] ?? '') ?>"
                                                    data-code="<?= alm_page_h($anaquel['codigo'] ?? '') ?>">
                                                <?= alm_page_h(($anaquel['codigo'] ?? '') . ' - ' . ($anaquel['nombre'] ?? '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="alm-field alm-field--span-2">
                                    <span>BB</span>
                                    <input name="ubi_bloque" id="almEntradaBloque" form="almEntradaForm" type="text" value="00" maxlength="2">
                                </label>
                                <label class="alm-field alm-field--span-2">
                                    <span>NN</span>
                                    <input name="ubi_nivel" id="almEntradaNivel" form="almEntradaForm" type="text" value="00" maxlength="2">
                                </label>
                                <label class="alm-field alm-field--span-2">
                                    <span>SSSS</span>
                                    <input name="ubi_seccion" id="almEntradaSeccion" form="almEntradaForm" type="text" value="0000" maxlength="4">
                                </label>

                                <div class="alm-location__mode">
                                    <label class="alm-radio-pill"><input type="radio" name="ubicacion_modo" form="almEntradaForm" value="anaquel" checked> Solo anaquel</label>
                                    <label class="alm-radio-pill"><input type="radio" name="ubicacion_modo" form="almEntradaForm" value="bloque"> Bloque</label>
                                    <label class="alm-radio-pill"><input type="radio" name="ubicacion_modo" form="almEntradaForm" value="nivel"> Bloque + nivel</label>
                                    <label class="alm-radio-pill"><input type="radio" name="ubicacion_modo" form="almEntradaForm" value="completo"> Completo</label>
                                    <label class="alm-radio-pill"><input type="radio" name="ubicacion_modo" form="almEntradaForm" value="fila"> Fila completa</label>
                                </div>
                                <label class="alm-check">
                                    <input type="checkbox" name="gen_etq" id="almEntradaGenEtq" form="almEntradaForm" value="1">
                                    <span>Generar etiquetas si la cantidad es entera.</span>
                                </label>
                            </div>
                        </section>
                <details class="alm-card alm-accordion" id="almRecentAccordion">
                    <summary class="alm-card__head alm-accordion__summary">
                        <div class="alm-card__title" style="color: #000;" >
                            <strong>Ultimos movimientos</strong>
                            <span>Lectura rapida de lo registrado recientemente.</span>
                        </div>
                        <div class="alm-card__icon alm-accordion__chevron"><i class="bi bi-chevron-down"></i></div>
                    </summary>
                    <div class="alm-table-wrap alm-accordion__body">
                        <table class="alm-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Producto</th>
                                    <th>Cant.</th>
                                    <th>Nota</th>
                                    <th>Usuario</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recentMovements): ?>
                                    <tr><td colspan="7" class="alm-table__empty">No hay movimientos para mostrar.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentMovements as $movement): ?>
                                        <?php
                                        $tipo = strtoupper(trim((string)($movement['tipo'] ?? '')));
                                        $badge = $tipo === 'SALIDA' ? 'alm-badge--red' : ($tipo === 'INVENTARIADO' ? 'alm-badge--amber' : 'alm-badge--green');
                                        ?>
                                        <tr>
                                            <td><span class="alm-badge">#<?= alm_page_h($movement['id'] ?? '') ?></span></td>
                                            <td><?= alm_page_h($movement['fecha'] ?? '') ?></td>
                                            <td><span class="alm-badge <?= alm_page_h($badge) ?>"><?= alm_page_h($tipo) ?></span></td>
                                            <td><strong>(<?= alm_page_h($movement['codigo'] ?? '') ?>) <?= alm_page_h($movement['producto'] ?? '') ?></strong><br><small><?= alm_page_h($movement['unidad'] ?? '') ?> · <?= alm_page_h($movement['ubicacion'] ?? '') ?></small></td>
                                            <td><?= alm_page_h(alm_page_fmt_qty($movement['cantidad'] ?? 0)) ?></td>
                                            <td><?= alm_page_h($movement['nota'] ?? '-') ?></td>
                                            <td><?= alm_page_h($movement['usuario'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </section>
    </div>

    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php n360_render_almacen_product_catalog(); ?>
<?php n360_render_almacen_salida_modal(); ?>
<?php n360_render_footer(); ?>

<script>
window.N360_NOTA_PDF_CONFIG = {
    endpoint: '<?= alm_page_h(n360_base_url('php/nota_pdf_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoTicket: '<?= alm_page_h(n360_base_url('img/completo.png')) ?>',
    footerLabel: 'NORTE 360'
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_notas_common.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_entrada_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_tanqueada.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_abastecimiento.js') ?>"></script>
<script src="<?= n360_asset('assets/js/nota_pdf_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/almacen_movimiento_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
