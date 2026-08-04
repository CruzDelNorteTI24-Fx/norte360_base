<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_helpers.php';
require_once __DIR__ . '/includes/encomiendas_queries.php';

$conn = enc_start_page('enc-tracking', 'Tracking de Control Encomiendas');
$filters = enc_current_filters();
$pageError = '';
$schemaReady = false;
$sedes = [];
$placas = [];
$rows = [];
$totalRows = 0;
$kpis = [
    'total' => 0,
    'activas' => 0,
    'transito' => 0,
    'finalizadas' => 0,
    'observadas' => 0,
    'anuladas' => 0,
    'con_manifiestos' => 0,
];

try {
    $sedes = enc_fetch_sedes($conn);
    $placas = enc_fetch_placas($conn);
    $schemaReady = enc_schema_has_guias_norte($conn);
    if ($schemaReady) {
        $totalRows = enc_count_tracking($conn, $filters);
        $rows = enc_fetch_tracking($conn, $filters);
        $kpis = array_merge($kpis, enc_fetch_kpis($conn, $filters));
    }
} catch (Throwable $e) {
    enc_log($e);
    $pageError = enc_db_message($e);
}

$totalPages = max(1, (int)ceil($totalRows / max(1, $filters['per_page'])));
$currentPage = min($filters['page'], $totalPages);
$canAnular = enc_can_view('enc-anular');

if (!defined('N360_LAYOUT')) define('N360_LAYOUT', true);
if (!defined('N360_BASE_URL')) define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function enc_tracking_url(array $changes = []): string {
    $params = array_merge($_GET, $changes);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]);
    }
    return 'tracking.php' . ($params ? '?' . http_build_query($params) : '');
}

function enc_unit_label(array $placa): string {
    $bus = trim((string)($placa['bus'] ?? ''));
    $plate = trim((string)($placa['placa'] ?? ''));
    if ($bus !== '' && $plate !== '') return $bus . ' - ' . $plate;
    if ($bus !== '') return $bus;
    if ($plate !== '') return $plate;
    return 'Unidad ' . (int)($placa['id'] ?? 0);
}

function enc_manifest_status(array $row): string {
    $required = (int)($row['manifiestos_req'] ?? 0);
    $ready = (int)($row['manifiestos_ok'] ?? 0);
    $class = ($required > 0 && $ready >= $required) ? 'enc-manifest-pill--ok' : 'enc-manifest-pill--pending';
    $icon = ($required > 0 && $ready >= $required) ? 'bi-check2-circle' : 'bi-hourglass-split';
    $text = $required > 0 ? $ready . '/' . $required : '0/0';
    return '<span class="enc-manifest-pill ' . enc_h($class) . '"><i class="bi ' . enc_h($icon) . '"></i>' . enc_h($text) . '</span>';
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
    <link rel="stylesheet" href="assets/css/encomiendas.css?v=1.7.0">
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
                    <h1>Tracking de Control Encomiendas</h1>
                </div>
                <div class="stock-hero-actions enc-hero__actions">
                    <?php if (enc_can_view('enc-register')): ?>
                        <a class="stock-btn stock-btn--primary" href="registro.php"><i class="bi bi-plus-circle"></i> Nueva Control Encomienda</a>
                    <?php endif; ?>
                    <button class="stock-btn stock-btn--soft" type="button" data-enc-pdf-tracking><i class="bi bi-filetype-pdf"></i> PDF consolidado</button>
                </div>
            </section>

            <?php if (!$schemaReady): ?>
                <div class="stock-alert stock-alert--warning enc-schema-warning">
                    <i class="bi bi-database-exclamation"></i>
                    Esta vista ya esta preparada para Control Encomiendas. Ejecuta manualmente el SQL complementario <strong>querysnuevas_encomiendas_unificado.sql</strong> para activar correlativos, puntos de ruta y documentos por punto.
                </div>
            <?php endif; ?>

            <?php if ($pageError !== ''): ?>
                <div class="stock-alert stock-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><?= enc_h($pageError) ?></div>
            <?php endif; ?>
<!-- 
            <section class="stock-kpis enc-kpis">
                <article class="stock-kpi stock-kpi--blue"><span>Guias Norte</span><strong><?= enc_h($kpis['total'] ?? 0) ?></strong></article>
                <article class="stock-kpi stock-kpi--green"><span>Activas</span><strong><?= enc_h($kpis['activas'] ?? 0) ?></strong></article>
                <article class="stock-kpi"><span>En transito</span><strong><?= enc_h($kpis['transito'] ?? 0) ?></strong></article>
                <article class="stock-kpi stock-kpi--green"><span>Con manifiestos</span><strong><?= enc_h($kpis['con_manifiestos'] ?? 0) ?></strong></article>
                <article class="stock-kpi stock-kpi--amber"><span>Observadas</span><strong><?= enc_h($kpis['observadas'] ?? 0) ?></strong></article>
                <article class="stock-kpi stock-kpi--red"><span>Anuladas</span><strong><?= enc_h($kpis['anuladas'] ?? 0) ?></strong></article>
            </section> -->

                        <form class="stock-filters enc-filters enc-filters--segmented" method="get" action="tracking.php">
                <section class="enc-filter-group enc-filter-group--lookup">
                    <div class="enc-filter-group__head">
                        <i class="bi bi-search"></i>
                        <div><strong>Busqueda principal</strong><span>Control Encomienda, documento o texto libre.</span></div>
                    </div>
                    <div class="enc-filter-group__fields">
                        <label class="stock-field"><span>Control Encomienda</span><input type="text" name="guia" value="<?= enc_h($filters['guia']) ?>" placeholder="GN-000001" autocomplete="off"></label>
                        <label class="stock-field"><span>Documento legal</span><input type="text" name="documento" value="<?= enc_h($filters['documento']) ?>" placeholder="Factura, boleta, recibo o PDF" autocomplete="off"></label>
                        <label class="stock-field stock-field--search"><span>Buscar</span><i class="bi bi-search"></i><input type="text" name="buscar" value="<?= enc_h($filters['buscar']) ?>" placeholder="Guia, ruta, unidad u observacion..." autocomplete="off"></label>
                    </div>
                </section>

                <section class="enc-filter-group enc-filter-group--dates">
                    <div class="enc-filter-group__head">
                        <i class="bi bi-calendar-range"></i>
                        <div><strong>Fechas</strong><span>Dia de guia o periodo.</span></div>
                    </div>
                    <div class="enc-filter-group__fields">
                        <label class="stock-field"><span>Desde</span><input type="date" name="desde" value="<?= enc_h($filters['desde']) ?>"></label>
                        <label class="stock-field"><span>Hasta</span><input type="date" name="hasta" value="<?= enc_h($filters['hasta']) ?>"></label>
                        <label class="stock-field"><span>Fecha guia</span><input type="date" name="fecha_guia" value="<?= enc_h($filters['fecha_guia']) ?>"></label>
                    </div>
                </section>

                <section class="enc-filter-group enc-filter-group--route">
                    <div class="enc-filter-group__head">
                        <i class="bi bi-signpost-2"></i>
                        <div><strong>Ruta y unidad</strong><span>Origen, destino y bus asignado.</span></div>
                    </div>
                    <div class="enc-filter-group__fields">
                        <label class="stock-field"><span>Origen</span><select name="idsede_embarque"><option value="0">Todas</option><?php foreach ($sedes as $sede): ?><option value="<?= enc_h($sede['id']) ?>" <?= $filters['idsede_embarque']==$sede['id']?'selected':'' ?>><?= enc_h($sede['nombre']) ?></option><?php endforeach; ?></select></label>
                        <label class="stock-field"><span>Destino</span><select name="idsede_desembarque"><option value="0">Todas</option><?php foreach ($sedes as $sede): ?><option value="<?= enc_h($sede['id']) ?>" <?= $filters['idsede_desembarque']==$sede['id']?'selected':'' ?>><?= enc_h($sede['nombre']) ?></option><?php endforeach; ?></select></label>
                        <label class="stock-field"><span>Unidad</span><select name="idplaca"><option value="0">Todas</option><?php foreach ($placas as $placa): ?><option value="<?= enc_h($placa['id']) ?>" <?= $filters['idplaca']==$placa['id']?'selected':'' ?>><?= enc_h(enc_unit_label($placa)) ?></option><?php endforeach; ?></select></label>
                    </div>
                </section>

                <section class="enc-filter-group enc-filter-group--states">
                    <div class="enc-filter-group__head">
                        <i class="bi bi-toggles2"></i>
                        <div><strong>Estados</strong><span>Control operativo y cantidad de filas.</span></div>
                    </div>
                    <div class="enc-filter-group__fields">
                        <label class="stock-field"><span>Estado embarque</span><select name="estado_embarque"><option value="TODOS">Todos</option><?php foreach (['PENDIENTE','EMBARCADO','OBSERVADO'] as $e): ?><option value="<?= enc_h($e) ?>" <?= $filters['estado_embarque']===$e?'selected':'' ?>><?= enc_h($e) ?></option><?php endforeach; ?></select></label>
                        <label class="stock-field"><span>Estado desembarque</span><select name="estado_desembarque"><option value="TODOS">Todos</option><?php foreach (['PENDIENTE','RECIBIDO','INCOMPLETO','OBSERVADO'] as $e): ?><option value="<?= enc_h($e) ?>" <?= $filters['estado_desembarque']===$e?'selected':'' ?>><?= enc_h($e) ?></option><?php endforeach; ?></select></label>
                        <label class="stock-field"><span>Estado general</span><select name="estado_general"><option value="TODOS">Todos</option><?php foreach (['REGISTRADA','EN_TRANSITO','FINALIZADA','OBSERVADA','ANULADA'] as $e): ?><option value="<?= enc_h($e) ?>" <?= $filters['estado_general']===$e?'selected':'' ?>><?= enc_h($e) ?></option><?php endforeach; ?></select></label>
                        <label class="stock-field"><span>Vista</span><select name="estado_vida"><option value="TODOS">Todos</option><?php foreach (['ACTIVO'=>'Activas','FINALIZADO'=>'Finalizadas','OBSERVADO'=>'Observadas','ANULADO'=>'Anuladas'] as $value=>$label): ?><option value="<?= enc_h($value) ?>" <?= $filters['estado_vida']===$value?'selected':'' ?>><?= enc_h($label) ?></option><?php endforeach; ?></select></label>
                        <label class="stock-field"><span>Filas</span><select name="per_page"><?php foreach ([15,25,50,100] as $n): ?><option value="<?= $n ?>" <?= $filters['per_page']===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></label>
                    </div>
                </section>

                <div class="stock-filter-actions enc-filter-actions enc-filter-actions--panel">
                    <button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-funnel"></i> Buscar</button>
                    <a class="stock-btn stock-btn--soft" href="tracking.php"><i class="bi bi-x-circle"></i> Limpiar</a>
                </div>
            </form>

            <section class="stock-table-card enc-table-card">
                <div class="stock-table-card__head">
                    <div>
                        <h2>Control Encomiendas registradas</h2>
                    </div>
                    <span class="stock-table-count"><?= enc_h($totalRows) ?> registros</span>
                </div>
                <div class="stock-table-wrap">
                    <table class="stock-table enc-table enc-table--tracking">
                        <thead>
                            <tr>
                                <th>Control Encomienda</th>
                                <th>Fecha / horario</th>
                                <th>Ruta</th>
                                <th>Unidad</th>
                                <th>General</th>
                                <th>Embarque</th>
                                <th>Desembarque</th>
                                <th>Manifiestos</th>
                                <th>G. transp.</th>
                                <th>Ultima act.</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="11" class="stock-empty"><?= $schemaReady ? 'No existen Control Encomiendas para los filtros actuales.' : 'Pendiente ejecutar la migracion de Control Encomiendas.' ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $unit = trim((string)($row['placa_bus'] ?? '')) . (trim((string)($row['placa_placa'] ?? '')) !== '' ? ' - ' . trim((string)$row['placa_placa']) : '');
                                $scheduleText = trim((string)($row['clm_enc_horario_operativo'] ?? ''));
                                $scheduleHour = trim((string)($row['clm_enc_hora_embarque_programada'] ?? ''));
                                $hour = $scheduleText !== '' ? $scheduleText : 'Sin horario';
                                if ($scheduleHour !== '') $hour .= ' | ' . substr($scheduleHour, 0, 5);
                                ?>
                                <tr>
                                    <td><strong><?= enc_h($row['clm_enc_guia']) ?></strong></td>
                                    <td><span class="enc-table__main"><strong><?= enc_h(enc_fmt_date($row['clm_enc_fecha_guia'])) ?></strong><small><?= enc_h($hour) ?></small></span></td>
                                    <td><span class="enc-table__main"><strong><?= enc_h($row['sede_embarque']) ?></strong><small>hacia <?= enc_h($row['sede_desembarque']) ?></small></span></td>
                                    <td><?= enc_h(trim($unit) !== '' ? $unit : 'Sin unidad') ?></td>
                                    <td><?= enc_state_badge($row['clm_enc_estado_general']) ?></td>
                                    <td><?= enc_state_badge($row['clm_enc_estado_embarque']) ?></td>
                                    <td><?= enc_state_badge($row['clm_enc_estado_desembarque']) ?></td>
                                    <td><?= enc_manifest_status($row) ?></td>
                                    <td><span class="enc-mini-chip"><i class="bi bi-files"></i><?= enc_h((int)($row['guias_transportista_total'] ?? 0)) ?></span></td>
                                    <td><?= enc_h(enc_fmt_datetime($row['clm_enc_datetimeupdated'] ?: $row['clm_enc_fechacreated'])) ?></td>
                                    <td>
                                        <?php $isAnulada = ((int)($row['clm_enc_activo'] ?? 1) === 0) || strtoupper((string)($row['clm_enc_estado_general'] ?? '')) === 'ANULADA'; ?>
                                        <div class="enc-row-actions enc-row-actions--expanded">
                                            <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-detail="<?= enc_h($row['clm_enc_id']) ?>"><i class="bi bi-eye"></i> Ver</button>
                                            <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-detail-section="timeline" data-guide-id="<?= enc_h($row['clm_enc_id']) ?>"><i class="bi bi-diagram-3"></i> Seguimiento</button>
                                            <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-detail-section="history" data-guide-id="<?= enc_h($row['clm_enc_id']) ?>"><i class="bi bi-clock-history"></i> Historial</button>
                                            <button class="stock-btn stock-btn--primary stock-btn--sm" type="button" data-enc-pdf-guide="<?= enc_h($row['clm_enc_id']) ?>"><i class="bi bi-filetype-pdf"></i> PDF</button>
                                            <?php if ($canAnular && !$isAnulada): ?>
                                                <button class="stock-btn stock-btn--danger stock-btn--sm" type="button" data-enc-annul-open data-guide-id="<?= enc_h($row['clm_enc_id']) ?>" data-guide-code="<?= enc_h($row['clm_enc_guia']) ?>"><i class="bi bi-x-octagon"></i> Anular</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="enc-pagination">
                    <a class="stock-btn stock-btn--soft <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= enc_h(enc_tracking_url(['page' => max(1, $currentPage - 1)])) ?>"><i class="bi bi-chevron-left"></i> Anterior</a>
                    <span>Pagina <?= enc_h($currentPage) ?> de <?= enc_h($totalPages) ?></span>
                    <a class="stock-btn stock-btn--soft <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= enc_h(enc_tracking_url(['page' => min($totalPages, $currentPage + 1)])) ?>">Siguiente <i class="bi bi-chevron-right"></i></a>
                </div>
            </section>
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
                <label class="stock-field"><span>Tipo doc.</span><select name="tipo_comprobante"><option value="">Sin especificar</option><option value="FACTURA">Factura</option><option value="BOLETA">Boleta</option><option value="RECIBO">Recibo</option><option value="SIN_COMPROBANTE">Sin comprobante</option></select></label>
                <label class="stock-field"><span>Numero</span><input type="text" name="numero_comprobante" maxlength="80" autocomplete="off" placeholder="F001-000123"></label>
                <label class="stock-field"><span>Fecha doc.</span><input type="date" name="fecha_comprobante"></label>
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
<script src="assets/js/encomiendas_pdf.js?v=1.4.0"></script>
<script src="assets/js/tracking_encomiendas.js?v=1.6.0"></script>
</body>
</html>
