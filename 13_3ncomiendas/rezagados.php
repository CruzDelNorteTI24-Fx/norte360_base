<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_queries.php';

$conn = enc_start_page('enc-tracking', 'Rezagados de Encomienda');
$filters = enc_current_rezagados_filters();
$schemaReady = false;
$pageError = '';
$rows = [];

try {
    $schemaReady = enc_schema_has_rezagados_view_pages($conn);
    if ($schemaReady) {
        $rows = enc_fetch_rezagados_encomienda($conn, $filters);
    }
} catch (Throwable $e) {
    enc_log($e);
    $pageError = enc_db_message($e);
}

if (!defined('N360_LAYOUT')) define('N360_LAYOUT', true);
if (!defined('N360_BASE_URL')) define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function enc_rezagado_estado_badge(string $state): string {
    $state = strtoupper($state);
    $map = [
        'PENDIENTE' => ['Pendiente', 'warning', 'bi-hourglass-split'],
        'OK' => ['OK', 'success', 'bi-check2-circle'],
        'REZAGADO' => ['Rezagado', 'danger', 'bi-exclamation-octagon'],
        'OBSERVADO' => ['Observado', 'warning', 'bi-exclamation-triangle'],
    ];
    [$label, $variant, $icon] = $map[$state] ?? [$state, 'muted', 'bi-circle'];
    return '<span class="enc-state enc-state--' . enc_h($variant) . '"><i class="bi ' . enc_h($icon) . '"></i>' . enc_h($label) . '</span>';
}

function enc_rezagado_is_manual_row(array $row): bool {
    return stripos((string)($row['manifiesto_pdf'] ?? ''), 'rezagados_manual_') !== false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Rezagados de Encomienda | Norte360</title>
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
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Encomiendas', 'subtitle' => 'Rezagados']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content enc-content">
        <div class="n360-main__inner enc-page enc-rezagados-page" data-enc-rezagados>
            <section class="stock-hero enc-hero enc-hero--rezagados">
                <div class="enc-hero__icon"><i class="bi bi-list-check"></i></div>
                <div class="enc-hero__text">
                    <span class="stock-eyebrow"><i class="bi bi-box-seam-fill"></i> Encomiendas - Revisión</span>
                    <h1>Rezagados de Encomienda</h1>
                    <p>Items revisados desde los manifiestos cargados.</p>
                </div>
                <div class="stock-hero-actions enc-hero__actions">
                    <button class="stock-btn stock-btn--primary" type="button" data-bs-toggle="modal" data-bs-target="#encManualRezagadoModal" <?= !$schemaReady ? 'disabled' : '' ?>>
                        <i class="bi bi-plus-circle"></i> Agregar manual
                    </button>
                    <a class="stock-btn stock-btn--soft" href="tracking.php"><i class="bi bi-arrow-left"></i> Tracking</a>
                </div>
            </section>

            <?php if (!$schemaReady): ?>
                <div class="stock-alert stock-alert--warning"><i class="bi bi-database-exclamation"></i> Falta ejecutar la migracion de revisiones de manifiestos para usar esta vista.</div>
            <?php endif; ?>
            <?php if ($pageError !== ''): ?>
                <div class="stock-alert stock-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><?= enc_h($pageError) ?></div>
            <?php endif; ?>

            <form class="stock-filters enc-filters enc-rezagados-filter" method="get" action="rezagados.php">
                <label class="stock-field">
                    <span>Estado</span>
                    <select name="estado">
                        <?php foreach (['REZAGADO' => 'Rezagados', 'PENDIENTE' => 'Pendientes', 'OBSERVADO' => 'Observados', 'OK' => 'OK', 'TODOS' => 'Todos'] as $value => $label): ?>
                            <option value="<?= enc_h($value) ?>" <?= $filters['estado'] === $value ? 'selected' : '' ?>><?= enc_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="stock-field"><span>Desde</span><input type="date" name="desde" value="<?= enc_h($filters['desde']) ?>"></label>
                <label class="stock-field"><span>Hasta</span><input type="date" name="hasta" value="<?= enc_h($filters['hasta']) ?>"></label>
                <label class="stock-field stock-field--search"><span>Buscar</span><i class="bi bi-search"></i><input type="text" name="buscar" value="<?= enc_h($filters['buscar']) ?>" placeholder="Control, manual, consignado..." autocomplete="off"></label>
                <div class="stock-filter-actions">
                    <button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
                    <a class="stock-btn stock-btn--soft" href="rezagados.php"><i class="bi bi-x-circle"></i> Limpiar</a>
                </div>
            </form>

            <section class="stock-table-card enc-table-card">
                <div class="stock-table-card__head">
                    <div>
                        <span class="stock-eyebrow">Revision de manifiestos</span>
                        <h2>Items registrados</h2>
                    </div>
                    <span class="stock-table-count"><?= enc_h(count($rows)) ?> items</span>
                </div>
                <div class="stock-table-wrap">
                    <table class="stock-table enc-table enc-rezagados-table">
                        <thead>
                            <tr>
                                <th>Control</th>
                                <th>Hoja</th>
                                <th>Documento</th>
                                <th>Consignado</th>
                                <th>Referencia</th>
                                <th>Pago</th>
                                <th>Estado</th>
                                <th>Observacion</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="9" class="stock-empty"><?= $schemaReady ? 'No hay items para los filtros actuales.' : 'Migracion pendiente.' ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php $isManual = enc_rezagado_is_manual_row($row); ?>
                                <tr>
                                    <td>
                                        <strong><?= enc_h($isManual ? 'Manual' : $row['clm_enc_guia']) ?></strong>
                                        <small><?= enc_h(enc_fmt_datetime($row['clm_encrev_fechacreated'] ?? null)) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= enc_h($isManual ? 'Manual' : str_pad((string)(int)($row['clm_encrev_orden_hoja'] ?? 1), 2, '0', STR_PAD_LEFT)) ?></strong>
                                        <small><?= enc_h($isManual ? 'Registro directo' : ($row['manifiesto_pdf'] ?: '')) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= enc_h($row['clm_encrevitem_documento'] ?: '-') ?></strong>
                                        <small><?= enc_h($row['clm_encrevitem_guia_remision'] ?: '') ?></small>
                                    </td>
                                    <td><?= enc_h($row['clm_encrevitem_consignado'] ?: '-') ?></td>
                                    <td><?= enc_h($row['clm_encrevitem_referencia_envio'] ?: '-') ?></td>
                                    <td>
                                        <strong>S/ <?= enc_h($row['clm_encrevitem_importe_cobrado'] !== null ? number_format((float)$row['clm_encrevitem_importe_cobrado'], 2) : '0.00') ?></strong>
                                        <small><?= enc_h($row['clm_encrevitem_tipo_pago'] ?: '-') ?></small>
                                    </td>
                                    <td><?= enc_rezagado_estado_badge((string)$row['clm_encrevitem_estado']) ?></td>
                                    <td><?= enc_h($row['clm_encrevitem_observacion'] ?: '-') ?></td>
                                    <td><a class="stock-btn stock-btn--soft stock-btn--sm" href="revision_manifiesto.php?documento=<?= enc_h($row['clm_encrev_iddocumento']) ?>"><i class="bi bi-pencil-square"></i> Revisar</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="modal fade enc-manual-modal" id="encManualRezagadoModal" tabindex="-1" aria-labelledby="encManualRezagadoTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <form class="modal-content" action="actions/agregar_rezagado_manual.php" method="post" data-enc-manual-form data-confirm="Se agregara esta encomienda manual como rezagado.">
                        <input type="hidden" name="csrf_token" value="<?= enc_h(enc_csrf_token()) ?>">
                        <input type="hidden" name="estado" value="REZAGADO">
                        <div class="modal-header">
                            <div>
                                <span class="stock-eyebrow"><i class="bi bi-plus-square"></i> Registro manual</span>
                                <h2 class="modal-title" id="encManualRezagadoTitle">Agregar encomienda rezagada</h2>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body enc-manual-modal__body">
                            <label class="stock-field">
                                <span>Documento</span>
                                <input type="text" name="documento" maxlength="100" autocomplete="off" placeholder="Codigo o comprobante">
                            </label>
                            <label class="stock-field">
                                <span>Guia remision</span>
                                <input type="text" name="guia_remision" maxlength="100" autocomplete="off" placeholder="Opcional">
                            </label>
                            <label class="stock-field stock-field--wide">
                                <span>Consignado</span>
                                <input type="text" name="consignado" maxlength="255" autocomplete="off" placeholder="Cliente o destinatario">
                            </label>
                            <label class="stock-field stock-field--wide">
                                <span>Referencia</span>
                                <input type="text" name="referencia_envio" maxlength="1000" autocomplete="off" placeholder="Detalle de encomienda">
                            </label>
                            <label class="stock-field">
                                <span>Peso</span>
                                <input type="number" name="peso" step="0.0001" min="0" inputmode="decimal" placeholder="0.00">
                            </label>
                            <label class="stock-field">
                                <span>Tipo pago</span>
                                <input type="text" name="tipo_pago" maxlength="80" autocomplete="off" placeholder="Efectivo, Yape...">
                            </label>
                            <label class="stock-field">
                                <span>Importe</span>
                                <input type="number" name="importe_cobrado" step="0.0001" min="0" inputmode="decimal" placeholder="0.00">
                            </label>
                            <label class="stock-field stock-field--wide">
                                <span>Observacion</span>
                                <input type="text" name="observacion" maxlength="1000" autocomplete="off">
                            </label>
                        </div>
                        <div class="modal-footer">
                            <button class="stock-btn stock-btn--soft" type="button" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancelar</button>
                            <button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-save2"></i> Guardar manual</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>

<script src="<?= enc_h(n360_asset('assets/js/loader_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/dialog_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('13_3ncomiendas/assets/js/rezagados_encomienda.js')) ?>"></script>
</body>
</html>
