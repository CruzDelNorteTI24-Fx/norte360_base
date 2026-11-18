<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_helpers.php';
require_once __DIR__ . '/includes/encomiendas_queries.php';
require_once __DIR__ . '/includes/encomiendas_inventory_layout.php';

require_once __DIR__.'/includes/encomiendas_tracking_queries.php';
$conn=enc_start_page('enc-tracking','Tracking de controles');
$filters=enc_control_tracking_filters();
$pageError='';$schemaReady=false;$inventoryReady=false;$sedes=[];$placas=[];$rows=[];$totalRows=0;
$tracking=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'kupos'=>0,'viajes'=>0];
try {
    $schemaReady=enc_schema_has_guias_norte($conn);$inventoryReady=enc_inventory_ready($conn);
    $sedes=enc_fetch_sedes($conn);$placas=enc_fetch_placas($conn);
    if ($schemaReady) {
        $tracking=enc_control_tracking_query($conn,$filters,$inventoryReady);
        $rows=$tracking['rows'];$totalRows=$tracking['total'];
    }
} catch (Throwable $e) {enc_log($e);$pageError=enc_db_message($e);}
$currentPage=$tracking['page'];$totalPages=$tracking['pages'];$canAnular=enc_can_view('enc-anular');
$hasAdvancedFilters=$filters['guia']!=='' || $filters['documento']!=='' || $filters['buscar']!==''
    || $filters['fecha_guia']!=='' || $filters['desde']!==date('Y-m-01') || $filters['hasta']!==date('Y-m-d')
    || $filters['idsede_embarque']>0 || $filters['idsede_desembarque']>0 || $filters['idplaca']>0
    || $filters['estado_embarque']!=='TODOS' || $filters['estado_desembarque']!=='TODOS'
    || $filters['estado_general']!=='TODOS' || $filters['estado_vida']!=='TODOS';

if (!defined('N360_LAYOUT')) define('N360_LAYOUT', true);
if (!defined('N360_BASE_URL')) define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function enc_unit_label(array $placa): string {
    $bus = trim((string)($placa['bus'] ?? ''));
    $plate = trim((string)($placa['placa'] ?? ''));
    if ($bus !== '' && $plate !== '') return $bus . ' - ' . $plate;
    if ($bus !== '') return $bus;
    if ($plate !== '') return $plate;
    return 'Unidad ' . (int)($placa['id'] ?? 0);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tracking de Control Encomiendas | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= enc_h(n360_asset('img/norte360.png')) ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/header_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/sidebar_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/main_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/footer_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/content_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/loader_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/dialog_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/inventario_stock_n360.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('13_3ncomiendas/assets/css/encomiendas.css')) ?>">
    <link rel="stylesheet" href="<?= enc_h(n360_asset('13_3ncomiendas/assets/css/kupos_inventario.css')) ?>?v=3">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Encomiendas', 'subtitle' => 'Control Encomiendas']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content enc-content">
        <div class="n360-main__inner enc-page enc-tracking-page" data-enc-tracking data-csrf="<?= enc_h(enc_csrf_token()) ?>" data-report-user="<?= enc_h(enc_user_name()) ?>" data-report-dni="<?= enc_h($_SESSION['DNI'] ?? $_SESSION['dni'] ?? 'No registrado') ?>">
            <section class="stock-hero enc-hero enc-hero--tracking">
                <div class="enc-hero__icon"><i class="bi bi-signpost-split-fill"></i></div>
                <div class="enc-hero__text">
                    <span class="stock-eyebrow"><i class="bi bi-radar"></i> Encomiendas - Control Encomiendas</span>
                    <h1>Tracking de controles</h1><p>Controles de viaje y reportes Kupos, con acceso a sus encomiendas.</p>
                </div>
                <div class="stock-hero-actions enc-hero__actions">
                    <?php if (enc_can_view('enc-register')): ?>
                        <a class="stock-btn stock-btn--primary" href="registro.php"><i class="bi bi-plus-circle"></i> Registrar control</a>
                    <?php endif; ?>
                    <a class="stock-btn stock-btn--soft" href="rezagados.php"><i class="bi bi-list-check"></i> Rezagados</a>
                    <?php if ($filters['origen_registro']!=='KUPOS'): ?><button class="stock-btn stock-btn--soft" type="button" data-enc-pdf-tracking><i class="bi bi-filetype-pdf"></i> PDF de viajes</button><?php endif; ?>
                </div>
            </section>

            <?php if (!$schemaReady): ?>
                <div class="stock-alert stock-alert--warning enc-schema-warning">
                    <i class="bi bi-database-exclamation"></i>
                    La instalación de Control Encomiendas está incompleta. Solicita revisar la estructura existente antes de instalar una actualización; los documentos y controles guardados deben conservarse.
                </div>
            <?php endif; ?>

            <?php if ($pageError !== ''): ?>
                <div class="stock-alert stock-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><?= enc_h($pageError) ?></div>
            <?php endif; ?>
<?php require __DIR__.'/includes/encomiendas_tracking_content.php'; ?>
        </div>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>

<div class="enc-drawer-backdrop" data-enc-drawer-backdrop hidden></div>
<aside class="enc-drawer" data-enc-detail-drawer aria-hidden="true" aria-label="Detalle de Control Encomienda">
    <div class="enc-drawer__head">
        <div>
            <span class="stock-eyebrow"><i class="bi bi-box-seam-fill"></i> Encomiendas</span>
            <h2>Detalle de Control Encomienda</h2>
        </div>
        <button class="enc-drawer__close" type="button" data-enc-drawer-close aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="enc-drawer__body" data-enc-detail-body>
        <div class="enc-loading-inline"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Cargando detalle...</div>
    </div>
</aside>

<div class="modal fade enc-transport-modal" id="encTransportDocModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content enc-ajax-form" action="actions/subir_documento.php" method="post" enctype="multipart/form-data" data-confirm="Subir Guia de Transportista opcional.">
            <div class="modal-header">
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-file-earmark-pdf"></i> Documento opcional</span>
                    <h5 class="modal-title">Agregar Guia de Transportista</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body enc-transport-modal__body">
                <input type="hidden" name="csrf_token" value="<?= enc_h(enc_csrf_token()) ?>">
                <input type="hidden" name="id" value="" data-enc-transport-guide-id>
                <input type="hidden" name="tipo" value="GUIA_TRANSPORTISTA">
                <label class="stock-field"><span>Numero</span><input type="text" name="numero_comprobante" maxlength="80" autocomplete="off" placeholder="F001-000123"></label>
                <label class="stock-field"><span>Fecha doc.</span><input type="date" name="fecha_comprobante" value="<?= enc_h(enc_now_date()) ?>"></label>
                <label class="stock-field stock-field--wide"><span>Observacion</span><input type="text" name="doc_observacion" maxlength="500" autocomplete="off" placeholder="Ruta, cliente o referencia"></label>
                <label class="stock-field stock-field--wide"><span>PDF</span><input type="file" name="documento" accept="application/pdf,.pdf" required></label>
                <div class="enc-doc-helper"><i class="bi bi-shield-check"></i> El archivo se valida como PDF real antes de guardarse.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="stock-btn stock-btn--soft" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="submit" class="stock-btn stock-btn--primary"><i class="bi bi-cloud-arrow-up"></i> Subir guia</button>
            </div>
        </form>
    </div>
</div>
<div class="modal fade enc-transport-modal" id="encAnnulModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content enc-ajax-form" action="actions/anular_guia.php" method="post" data-confirm="La Control Encomienda sera anulada logicamente. Se conservara historial y documentos." data-confirm-title="Anular Control Encomienda">
            <div class="modal-header">
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-x-octagon"></i> Control operativo</span>
                    <h5 class="modal-title">Anular Control Encomienda</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body enc-transport-modal__body">
                <input type="hidden" name="csrf_token" value="<?= enc_h(enc_csrf_token()) ?>">
                <input type="hidden" name="id" value="" data-enc-annul-guide-id>
                <div class="enc-annul-note stock-field--wide">
                    <i class="bi bi-shield-exclamation"></i>
                    <span>Se anulara <strong data-enc-annul-guide-code>Control Encomienda</strong>. La accion es logica y conservara la trazabilidad.</span>
                </div>
                <label class="stock-field stock-field--wide"><span>Motivo obligatorio</span><textarea name="motivo" rows="4" maxlength="1000" required placeholder="Detalle el motivo de anulacion"></textarea></label>
            </div>
            <div class="modal-footer">
                <button type="button" class="stock-btn stock-btn--soft" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="submit" class="stock-btn stock-btn--danger"><i class="bi bi-x-octagon"></i> Anular Guia</button>
            </div>
        </form>
    </div>
</div><script src="<?= enc_h(n360_asset('assets/js/loader_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/dialog_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="<?= enc_h(n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('13_3ncomiendas/assets/js/encomiendas_pdf.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('13_3ncomiendas/assets/js/tracking_encomiendas.js')) ?>"></script>
</body>
</html>
