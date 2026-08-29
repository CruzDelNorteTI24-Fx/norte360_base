<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_queries.php';

$conn = enc_start_page('enc-tracking', 'Revision de Manifiesto');
$documentId = max(0, (int)($_GET['documento'] ?? 0));
$pageError = '';
$schemaReady = false;
$doc = null;
$sheets = [];
$reviews = [];

function enc_review_status_options(string $selected): string {
    $options = [
        'PENDIENTE' => 'Pendiente',
        'OK' => 'OK',
        'REZAGADO' => 'Rezagado',
        'OBSERVADO' => 'Observado',
    ];
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . enc_h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . enc_h($label) . '</option>';
    }
    return $html;
}

function enc_review_number($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
}

function enc_review_counts(array $sheets): array {
    $counts = ['PENDIENTE' => 0, 'OK' => 0, 'REZAGADO' => 0, 'OBSERVADO' => 0, 'TOTAL' => 0];
    foreach ($sheets as $sheet) {
        foreach (($sheet['items'] ?? []) as $item) {
            $state = strtoupper((string)($item['estado'] ?? 'PENDIENTE'));
            if (!isset($counts[$state])) {
                $state = 'PENDIENTE';
            }
            $counts[$state]++;
            $counts['TOTAL']++;
        }
    }
    return $counts;
}

function enc_review_sheet_label(int $order): string {
    return 'Hoja ' . str_pad((string)$order, 2, '0', STR_PAD_LEFT);
}

function enc_review_item_from_row(array $row): array {
    return [
        'orden' => (int)$row['clm_encrevitem_orden'],
        'documento' => $row['clm_encrevitem_documento'],
        'consignado' => $row['clm_encrevitem_consignado'],
        'referencia_envio' => $row['clm_encrevitem_referencia_envio'],
        'peso' => $row['clm_encrevitem_peso'],
        'tipo_pago' => $row['clm_encrevitem_tipo_pago'],
        'importe_cobrado' => $row['clm_encrevitem_importe_cobrado'],
        'guia_remision' => $row['clm_encrevitem_guia_remision'],
        'estado' => $row['clm_encrevitem_estado'],
        'observacion' => $row['clm_encrevitem_observacion'],
    ];
}

try {
    $schemaReady = enc_schema_has_guias_norte($conn) && enc_schema_has_manifest_review_pages($conn);
    if (!$schemaReady) {
        throw new RuntimeException('Pendiente ejecutar la migracion de revisiones de manifiestos por hoja.');
    }
    if ($documentId <= 0) {
        throw new RuntimeException('Manifiesto no identificado.');
    }

    $doc = enc_fetch_manifest_document($conn, $documentId);
    if (!$doc) {
        throw new RuntimeException('No se encontro el manifiesto solicitado.');
    }

    $routePoints = enc_fetch_route_points($conn, (int)$doc['clm_encdoc_idguia']);
    $routePointsById = [];
    foreach ($routePoints as $point) {
        $routePointsById[(int)$point['clm_encpunto_id']] = $point;
    }

    $reviews = enc_fetch_manifest_reviews($conn, $documentId);
    if ($reviews) {
        foreach ($reviews as $review) {
            $pointId = enc_id_or_null($review['clm_encrev_idpunto'] ?? null);
            $point = $pointId ? ($routePointsById[$pointId] ?? null) : null;
            $items = [];
            foreach (enc_fetch_manifest_review_items($conn, (int)$review['clm_encrev_id']) as $row) {
                $items[] = enc_review_item_from_row($row);
            }
            $sheets[] = [
                'review_id' => (int)$review['clm_encrev_id'],
                'orden_hoja' => (int)($review['clm_encrev_orden_hoja'] ?? count($sheets) + 1),
                'idpunto' => $pointId,
                'punto_sede' => $point['sede_nombre'] ?? $doc['punto_sede'] ?? null,
                'estado_revision' => $review['clm_encrev_estado'] ?? 'EN_REVISION',
                'observacion_revision' => $review['clm_encrev_observacion'] ?? '',
                'meta' => [
                    'codigo_manifiesto' => $review['clm_encrev_codigo_manifiesto'] ?? null,
                    'origen' => $review['clm_encrev_origen'] ?? null,
                    'destino' => $review['clm_encrev_destino'] ?? null,
                    'oficina_destino' => $point['sede_nombre'] ?? null,
                    'bus' => $review['clm_encrev_bus'] ?? null,
                    'placa' => $review['clm_encrev_placa'] ?? null,
                    'fecha_viaje' => $review['clm_encrev_fecha_viaje'] ?? null,
                ],
                'items' => $items,
            ];
        }
    } else {
        $parsed = enc_parse_manifest_pdf((string)$doc['clm_encdoc_archivo']);
        if (!$parsed['title_ok']) {
            throw new RuntimeException('El PDF guardado no contiene "Manifiesto de Encomiendas".');
        }
        $pages = $parsed['pages'] ?: [[
            'orden_hoja' => 1,
            'meta' => $parsed['meta'],
            'items' => $parsed['items'],
        ]];
        $multiPage = count($pages) > 1;
        foreach ($pages as $idx => $page) {
            $assignedPoint = null;
            if ($multiPage) {
                $assignedPoint = $routePoints[$idx] ?? null;
            } else {
                $pointId = enc_id_or_null($doc['clm_encdoc_idpunto'] ?? null);
                $assignedPoint = $pointId ? ($routePointsById[$pointId] ?? null) : null;
            }
            $sheets[] = [
                'review_id' => null,
                'orden_hoja' => (int)($page['orden_hoja'] ?? $idx + 1),
                'idpunto' => $assignedPoint ? (int)$assignedPoint['clm_encpunto_id'] : enc_id_or_null($doc['clm_encdoc_idpunto'] ?? null),
                'punto_sede' => $assignedPoint['sede_nombre'] ?? $doc['punto_sede'] ?? null,
                'estado_revision' => 'GENERADO',
                'observacion_revision' => '',
                'meta' => $page['meta'] ?? $parsed['meta'],
                'items' => $page['items'] ?? [],
            ];
        }
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

$counts = enc_review_counts($sheets);
$modeLabel = $reviews ? 'Continuar revision' : 'Generar revision';
$saved = isset($_GET['guardado']) && $_GET['guardado'] === '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Revision de Manifiesto | Norte360</title>
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
<?php n360_render_header(['title' => 'Encomiendas', 'subtitle' => 'Revision de manifiesto']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content enc-content">
        <div class="n360-main__inner enc-page enc-review-page" data-enc-review data-csrf="<?= enc_h(enc_csrf_token()) ?>">
            <section class="stock-hero enc-hero enc-hero--review">
                <div class="enc-hero__icon"><i class="bi bi-clipboard2-check-fill"></i></div>
                <div class="enc-hero__text">
                    <span class="stock-eyebrow"><i class="bi bi-box-seam-fill"></i> Encomiendas - Manifiesto</span>
                    <h1><?= enc_h($modeLabel) ?></h1>
                    <p><?= $doc ? enc_h(($doc['clm_enc_guia'] ?? 'Control Encomienda') . ' - ' . ($doc['clm_encdoc_nombre'] ?? 'manifiesto.pdf')) : 'Revision de manifiesto' ?></p>
                </div>
                <div class="stock-hero-actions enc-hero__actions">
                    <a class="stock-btn stock-btn--soft" href="tracking.php"><i class="bi bi-arrow-left"></i> Tracking</a>
                    <a class="stock-btn stock-btn--primary" href="rezagados.php"><i class="bi bi-list-check"></i> Rezagados</a>
                </div>
            </section>

            <?php if ($saved): ?>
                <div class="stock-alert stock-alert--success"><i class="bi bi-check2-circle"></i> Revision guardada correctamente.</div>
            <?php endif; ?>

            <?php if ($pageError !== '' || !$doc): ?>
                <div class="stock-alert stock-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><?= enc_h($pageError ?: 'No se pudo cargar el manifiesto.') ?></div>
            <?php else: ?>
                <section class="enc-review-summary">
                    <article><span>Control</span><strong><?= enc_h($doc['clm_enc_guia']) ?></strong></article>
                    <article><span>PDF</span><strong><?= enc_h($doc['clm_encdoc_nombre']) ?></strong></article>
                    <article><span>Hojas</span><strong><?= enc_h(count($sheets)) ?></strong></article>
                    <article><span>Items</span><strong data-enc-review-count-total><?= enc_h($counts['TOTAL']) ?></strong></article>
                    <article class="is-rezagado"><span>Rezagados</span><strong data-enc-review-count="REZAGADO"><?= enc_h($counts['REZAGADO']) ?></strong></article>
                </section>

                <form class="enc-review-form" action="actions/guardar_revision_manifiesto.php" method="post" data-enc-review-form data-confirm="Guardar la revision del manifiesto y actualizar rezagados.">
                    <input type="hidden" name="csrf_token" value="<?= enc_h(enc_csrf_token()) ?>">
                    <input type="hidden" name="documento_id" value="<?= enc_h($doc['clm_encdoc_id']) ?>">

                    <?php if (!$sheets): ?>
                        <section class="enc-section">
                            <div class="stock-empty">No se pudieron leer hojas del manifiesto. Verifica que el PDF mantenga la estructura original.</div>
                        </section>
                    <?php else: ?>
                        <div class="enc-review-sheets">
                            <?php foreach ($sheets as $sheetIdx => $sheet): ?>
                                <?php
                                $sheetOrder = (int)($sheet['orden_hoja'] ?? $sheetIdx + 1);
                                $sheetMeta = $sheet['meta'] ?? [];
                                $sheetItems = $sheet['items'] ?? [];
                                ?>
                                <section class="enc-section enc-review-sheet" data-enc-review-sheet>
                                    <div class="enc-section__head enc-review-sheet__head">
                                        <div>
                                            <h3><?= enc_h(enc_review_sheet_label($sheetOrder)) ?></h3>
                                            <span><?= enc_h(($sheetMeta['oficina_destino'] ?? '') ?: ($sheet['punto_sede'] ?? 'Ruta del manifiesto')) ?></span>
                                        </div>
                                        <span><?= enc_h(count($sheetItems)) ?> items</span>
                                    </div>

                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][orden_hoja]" value="<?= enc_h($sheetOrder) ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][idpunto]" value="<?= enc_h($sheet['idpunto'] ?? '') ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][codigo_manifiesto]" value="<?= enc_h($sheetMeta['codigo_manifiesto'] ?? '') ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][origen]" value="<?= enc_h($sheetMeta['origen'] ?? '') ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][destino]" value="<?= enc_h($sheetMeta['destino'] ?? '') ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][bus]" value="<?= enc_h($sheetMeta['bus'] ?? '') ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][placa]" value="<?= enc_h($sheetMeta['placa'] ?? '') ?>">
                                    <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][fecha_viaje]" value="<?= enc_h($sheetMeta['fecha_viaje'] ?? '') ?>">

                                    <div class="enc-review-meta__grid">
                                        <div><span>Origen</span><strong><?= enc_h(($sheetMeta['origen'] ?? '') ?: '-') ?></strong></div>
                                        <div><span>Destino</span><strong><?= enc_h(($sheetMeta['destino'] ?? '') ?: '-') ?></strong></div>
                                        <div><span>Punto asignado</span><strong><?= enc_h(($sheet['punto_sede'] ?? '') ?: '-') ?></strong></div>
                                        <div><span>Bus</span><strong><?= enc_h(($sheetMeta['bus'] ?? '') ?: '-') ?></strong></div>
                                        <div><span>Fecha viaje</span><strong><?= enc_h(($sheetMeta['fecha_viaje'] ?? '') ?: '-') ?></strong></div>
                                    </div>

                                    <?php if (!$sheetItems): ?>
                                        <div class="stock-empty">Esta hoja no tiene items leidos del PDF.</div>
                                    <?php else: ?>
                                        <div class="enc-review-table">
                                            <?php foreach ($sheetItems as $idx => $item): ?>
                                                <?php $estado = strtoupper((string)($item['estado'] ?? 'PENDIENTE')); ?>
                                                <article class="enc-review-row is-<?= enc_h(strtolower($estado)) ?>" data-enc-review-row>
                                                    <div class="enc-review-row__num"><?= enc_h(str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT)) ?></div>
                                                    <div class="enc-review-row__main">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][documento]" value="<?= enc_h($item['documento'] ?? '') ?>">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][consignado]" value="<?= enc_h($item['consignado'] ?? '') ?>">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][referencia_envio]" value="<?= enc_h($item['referencia_envio'] ?? '') ?>">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][peso]" value="<?= enc_h(enc_review_number($item['peso'] ?? null)) ?>">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][tipo_pago]" value="<?= enc_h($item['tipo_pago'] ?? '') ?>">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][importe_cobrado]" value="<?= enc_h(enc_review_number($item['importe_cobrado'] ?? null)) ?>">
                                                        <input type="hidden" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][guia_remision]" value="<?= enc_h($item['guia_remision'] ?? '') ?>">

                                                        <strong><?= enc_h(($item['documento'] ?? '') ?: 'Documento sin codigo') ?></strong>
                                                        <span><?= enc_h(($item['consignado'] ?? '') ?: 'Sin consignado') ?></span>
                                                        <p><?= enc_h(($item['referencia_envio'] ?? '') ?: '-') ?></p>
                                                    </div>
                                                    <div class="enc-review-row__money">
                                                        <span>Peso <?= enc_h(($item['peso'] ?? '') !== '' && ($item['peso'] ?? null) !== null ? number_format((float)$item['peso'], 2) : '-') ?></span>
                                                        <strong>S/ <?= enc_h(($item['importe_cobrado'] ?? '') !== '' && ($item['importe_cobrado'] ?? null) !== null ? number_format((float)$item['importe_cobrado'], 2) : '0.00') ?></strong>
                                                        <small><?= enc_h(($item['tipo_pago'] ?? '') ?: '-') ?></small>
                                                    </div>
                                                    <label class="stock-field enc-review-row__state">
                                                        <span>Estado</span>
                                                        <select name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][estado]" data-enc-review-state>
                                                            <?= enc_review_status_options($estado) ?>
                                                        </select>
                                                    </label>
                                                    <label class="stock-field enc-review-row__obs">
                                                        <span>Observacion</span>
                                                        <input type="text" name="sheets[<?= enc_h($sheetIdx) ?>][items][<?= enc_h($idx) ?>][observacion]" value="<?= enc_h($item['observacion'] ?? '') ?>" maxlength="1000" autocomplete="off">
                                                    </label>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <label class="stock-field stock-field--wide enc-review-sheet__obs">
                                        <span>Observacion de hoja</span>
                                        <textarea name="sheets[<?= enc_h($sheetIdx) ?>][observacion_revision]" rows="2" maxlength="2000"><?= enc_h($sheet['observacion_revision'] ?? '') ?></textarea>
                                    </label>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <section class="enc-review-footer">
                        <div>
                            <strong><span data-enc-review-count="OK"><?= enc_h($counts['OK']) ?></span> OK</strong>
                            <span><b data-enc-review-count="PENDIENTE"><?= enc_h($counts['PENDIENTE']) ?></b> pendientes / <b data-enc-review-count="OBSERVADO"><?= enc_h($counts['OBSERVADO']) ?></b> observados</span>
                        </div>
                        <button class="stock-btn stock-btn--primary" type="submit" <?= $counts['TOTAL'] <= 0 ? 'disabled' : '' ?>><i class="bi bi-save2"></i> Guardar cambios</button>
                    </section>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>

<script src="<?= enc_h(n360_asset('assets/js/loader_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/dialog_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('13_3ncomiendas/assets/js/revision_manifiesto.js')) ?>"></script>
</body>
</html>
