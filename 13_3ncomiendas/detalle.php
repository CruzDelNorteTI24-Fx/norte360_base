<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_helpers.php';
require_once __DIR__ . '/includes/encomiendas_queries.php';

$partial = isset($_GET['partial']) && $_GET['partial'] === '1';
$conn = enc_start_page('enc-tracking', 'Detalle de Control Encomienda');
$guideId = max(0, (int)($_GET['id'] ?? 0));

$guia = null;
$points = [];
$documents = [];
$history = [];
$pageError = '';
$schemaReady = false;

try {
    $schemaReady = enc_schema_has_guias_norte($conn);
    if (!$schemaReady) {
        throw new RuntimeException('Pendiente ejecutar la migracion de Control Encomiendas antes de abrir el detalle.');
    }
    if ($guideId <= 0) {
        throw new RuntimeException('Control Encomienda no identificada.');
    }
    $guia = enc_fetch_guia($conn, $guideId);
    if (!$guia) {
        throw new RuntimeException('No se encontro la Control Encomienda solicitada.');
    }
    $points = enc_fetch_route_points($conn, $guideId);
    $documents = enc_fetch_documents($conn, $guideId);
    $history = enc_fetch_history($conn, $guideId);
} catch (Throwable $e) {
    enc_log($e);
    $pageError = enc_db_message($e);
}

$csrf = enc_csrf_token();

function enc_route_type_label(?string $type): string {
    $type = strtoupper(trim((string)$type));
    if ($type === 'ORIGEN') return 'Origen';
    if ($type === 'DESTINO') return 'Destino';
    return 'Ruta';
}

function enc_route_label(array $point): string {
    $prefix = '#' . (int)($point['clm_encpunto_orden'] ?? 0) . ' ' . enc_route_type_label($point['clm_encpunto_tipo'] ?? 'RUTA');
    return $prefix . ' - ' . (string)($point['sede_nombre'] ?? 'Sede');
}

function enc_detail_stage(string $title, ?string $state, ?string $date, ?string $user, ?string $office, ?string $unit, ?string $obs, bool $done): string {
    [$label, $variant, $icon] = enc_state_meta($state ?: ($done ? 'FINALIZADA' : 'PENDIENTE'));
    $class = $done ? 'is-done' : 'is-pending';
    if (in_array(strtoupper((string)$state), ['OBSERVADO', 'OBSERVADA', 'INCOMPLETO'], true)) $class = 'is-observed';

    ob_start();
    ?>
    <article class="enc-stage <?= enc_h($class) ?>">
        <div class="enc-stage__dot"><i class="bi <?= enc_h($icon) ?>"></i></div>
        <div class="enc-stage__body">
            <div class="enc-stage__top">
                <strong><?= enc_h($title) ?></strong>
                <span class="enc-state enc-state--<?= enc_h($variant) ?>"><i class="bi <?= enc_h($icon) ?>"></i><?= enc_h($label) ?></span>
            </div>
            <dl>
                <div><dt>Fecha</dt><dd><?= enc_h(enc_fmt_datetime($date)) ?></dd></div>
                <div><dt>Usuario</dt><dd><?= enc_h($user ?: '-') ?></dd></div>
                <div><dt>Oficina</dt><dd><?= enc_h($office ?: '-') ?></dd></div>
                <div><dt>Unidad</dt><dd><?= enc_h($unit ?: '-') ?></dd></div>
            </dl>
            <?php if (trim((string)$obs) !== ''): ?><p><?= enc_h($obs) ?></p><?php endif; ?>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function enc_history_changes(?string $oldJson, ?string $newJson): string {
    $old = $oldJson ? json_decode($oldJson, true) : [];
    $new = $newJson ? json_decode($newJson, true) : [];
    if (!is_array($old)) $old = [];
    if (!is_array($new)) $new = [];
    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    $changes = [];
    foreach ($keys as $key) {
        $a = $old[$key] ?? null;
        $b = $new[$key] ?? null;
        if ($a !== $b) {
            $changes[] = $key . ': ' . ($a === null ? '-' : (string)$a) . ' -> ' . ($b === null ? '-' : (string)$b);
        }
    }
    return implode("\n", array_slice($changes, 0, 8));
}

function enc_doc_action_links(array $doc): string {
    $id = (int)$doc['clm_encdoc_id'];
    return '<div class="enc-doc-actions">'
        . '<a class="stock-btn stock-btn--soft stock-btn--sm" href="actions/documento.php?id=' . enc_h($id) . '&mode=view" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Ver</a>'
        . '<a class="stock-btn stock-btn--primary stock-btn--sm" href="actions/documento.php?id=' . enc_h($id) . '&mode=download"><i class="bi bi-download"></i> Descargar</a>'
        . '</div>';
}

function enc_render_detail_content(?array $guia, array $points, array $documents, array $history, string $pageError, string $csrf): void {
    if ($pageError !== '' || !$guia) {
        echo '<div class="stock-alert stock-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i>' . enc_h($pageError ?: 'No se pudo cargar el detalle.') . '</div>';
        return;
    }

    $manifestDocsByPoint = [];
    $transportDocs = [];
    foreach ($documents as $doc) {
        if (($doc['clm_encdoc_tipo'] ?? '') === 'MANIFIESTO_ENCOMIENDAS') {
            $manifestDocsByPoint[(int)($doc['clm_encdoc_idpunto'] ?? 0)] = $doc;
        } elseif (($doc['clm_encdoc_tipo'] ?? '') === 'GUIA_TRANSPORTISTA') {
            $transportDocs[] = $doc;
        }
    }

    $unit = trim((string)($guia['placa_bus'] ?? '')) . (trim((string)($guia['placa_placa'] ?? '')) !== '' ? ' - ' . trim((string)$guia['placa_placa']) : '');
    $unit = trim($unit) !== '' ? trim($unit) : 'Sin unidad';
    $routeNames = array_map(static fn($p) => (string)($p['sede_nombre'] ?? 'Sede'), $points);
    $routeText = $routeNames ? implode(' -> ', $routeNames) : ((string)$guia['sede_embarque'] . ' -> ' . (string)$guia['sede_desembarque']);
    $requiredManifests = 0;
    foreach ($points as $point) {
        if ((int)($point['clm_encpunto_manifiesto_obligatorio'] ?? 1) === 1) $requiredManifests++;
    }
    $readyManifests = count($manifestDocsByPoint);
    $manifestDone = $requiredManifests > 0 && $readyManifests >= $requiredManifests;
    $canDocs = enc_can_view('enc-docs');
    $canEmbarque = enc_can_view('enc-embarque');
    $canDesembarque = enc_can_view('enc-desembarque');
    $canAnular = enc_can_view('enc-anular');
    $isAnulada = (int)$guia['clm_enc_activo'] === 0 || $guia['clm_enc_estado_general'] === 'ANULADA';
    $horario = trim((string)($guia['clm_enc_horario_operativo'] ?? ''));
    $horaProgramada = trim((string)($guia['clm_enc_hora_embarque_programada'] ?? ''));
    $horarioDetalle = $horario !== '' ? $horario : 'Sin horario';
    if ($horaProgramada !== '') $horarioDetalle .= ' | ' . substr($horaProgramada, 0, 5);
    ?>
    <div class="enc-detail" data-guide-id="<?= enc_h($guia['clm_enc_id']) ?>">
        <section class="enc-detail-hero">
            <div>
                <span class="stock-eyebrow"><i class="bi bi-receipt-cutoff"></i> Control Encomienda</span>
                <h2><?= enc_h($guia['clm_enc_guia']) ?></h2>
                <p><?= enc_h($routeText) ?></p>
                <section class="enc-detail-grid" aria-label="Resumen operativo de la Control Encomienda">
                    <div class="enc-detail-stat"><span><i class="bi bi-calendar3"></i> Fecha guia</span><strong><?= enc_h(enc_fmt_date($guia['clm_enc_fecha_guia'])) ?></strong></div>
                    <div class="enc-detail-stat"><span><i class="bi bi-clock-history"></i> Horario operativo</span><strong><?= enc_h($horarioDetalle) ?></strong></div>
                    <div class="enc-detail-stat"><span><i class="bi bi-person-badge"></i> Registra</span><strong><?= enc_h($guia['usuario_registra']) ?></strong></div>
                    <div class="enc-detail-stat"><span><i class="bi bi-bus-front"></i> Unidad</span><strong><?= enc_h($unit) ?></strong></div>
                    <div class="enc-detail-stat"><span><i class="bi bi-file-earmark-check"></i> Manifiestos</span><strong><?= enc_h($readyManifests . ' de ' . $requiredManifests) ?></strong></div>
                    <div class="enc-detail-stat"><span><i class="bi bi-files"></i> Guias transportista</span><strong><?= enc_h(count($transportDocs)) ?></strong></div>
                </section>
            </div>
            <div class="enc-detail-hero__states">
                <?= enc_state_badge($guia['clm_enc_estado_general']) ?>
                <?= enc_state_badge($guia['clm_enc_estado_embarque']) ?>
                <?= enc_state_badge($guia['clm_enc_estado_desembarque']) ?>
                <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-pdf-guide="<?= enc_h($guia['clm_enc_id']) ?>"><i class="bi bi-filetype-pdf"></i> PDF Control Encomienda</button>
            </div>
        </section>

        <section class="enc-detail-actions" aria-label="Acciones de trazabilidad">
            <button class="enc-detail-action" type="button" data-enc-detail-toggle="timeline" aria-expanded="false">
                <i class="bi bi-diagram-3"></i>
                <span><strong>Linea de seguimiento</strong><small>Ver avance operativo por etapa</small></span>
            </button>
            <button class="enc-detail-action" type="button" data-enc-detail-toggle="history" aria-expanded="false">
                <i class="bi bi-clock-history"></i>
                <span><strong>Historial</strong><small>Eventos y cambios registrados</small></span>
            </button>
        </section>

        <section class="enc-detail-columns">
            <article class="enc-section">
                <div class="enc-section__head"><h3>Embarque</h3><span><?= enc_h($guia['sede_embarque']) ?></span></div>
                <dl class="enc-definition-list">
                    <div><dt>Unidad</dt><dd><?= enc_h($unit) ?></dd></div>
                    <div><dt>Estado</dt><dd><?= enc_state_badge($guia['clm_enc_estado_embarque']) ?></dd></div>
                    <div><dt>Fecha</dt><dd><?= enc_h(enc_fmt_datetime($guia['clm_enc_fecha_embarque'])) ?></dd></div>
                    <div><dt>Usuario</dt><dd><?= enc_h($guia['usuario_embarque'] ?: '-') ?></dd></div>
                </dl>
                <?php if (!$isAnulada && $canEmbarque && $guia['clm_enc_estado_embarque'] !== 'EMBARCADO'): ?>
                    <form class="enc-inline-form enc-ajax-form" action="actions/actualizar_embarque.php" method="post" data-confirm="Confirmar cambio de estado de embarque.">
                        <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= enc_h($guia['clm_enc_id']) ?>">
                        <label><span>Estado</span><select name="estado"><option value="EMBARCADO">Embarcada</option><option value="OBSERVADO">Observada</option></select></label>
                        <label><span>Observacion</span><input type="text" name="observacion" maxlength="500" autocomplete="off"></label>
                        <button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-box-arrow-up-right"></i> Actualizar embarque</button>
                    </form>
                <?php endif; ?>
            </article>

            <article class="enc-section">
                <div class="enc-section__head"><h3>Desembarque</h3><span><?= enc_h($guia['sede_desembarque']) ?></span></div>
                <dl class="enc-definition-list">
                    <div><dt>Estado</dt><dd><?= enc_state_badge($guia['clm_enc_estado_desembarque']) ?></dd></div>
                    <div><dt>Fecha</dt><dd><?= enc_h(enc_fmt_datetime($guia['clm_enc_fecha_desembarque'])) ?></dd></div>
                    <div><dt>Usuario</dt><dd><?= enc_h($guia['usuario_desembarque'] ?: '-') ?></dd></div>
                </dl>
                <?php if (!$isAnulada && $canDesembarque && $guia['clm_enc_estado_embarque'] === 'EMBARCADO' && $guia['clm_enc_estado_desembarque'] !== 'RECIBIDO'): ?>
                    <form class="enc-inline-form enc-ajax-form" action="actions/actualizar_desembarque.php" method="post" data-confirm="Confirmar desembarque. Para recibir/finalizar deben estar cargados los manifiestos obligatorios de la ruta.">
                        <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= enc_h($guia['clm_enc_id']) ?>">
                        <label><span>Estado</span><select name="estado"><option value="RECIBIDO">Recibida</option><option value="INCOMPLETO">Incompleta</option><option value="OBSERVADO">Observada</option></select></label>
                        <label><span>Observacion</span><input type="text" name="observacion" maxlength="500" autocomplete="off"></label>
                        <button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-check2-circle"></i> Actualizar desembarque</button>
                    </form>
                <?php endif; ?>
            </article>
        </section>

        <section class="enc-section">
            <div class="enc-section__head"><h3>Ruta y manifiestos</h3><span><?= enc_h($readyManifests . '/' . $requiredManifests) ?> manifiestos</span></div>
            <div class="enc-route-doc-grid">
                <?php if (!$points): ?>
                    <div class="stock-empty">No hay puntos de ruta registrados.</div>
                <?php else: ?>
                    <?php foreach ($points as $point): ?>
                        <?php $doc = $manifestDocsByPoint[(int)$point['clm_encpunto_id']] ?? null; ?>
                        <article class="enc-point-card <?= $doc ? 'has-doc' : '' ?>">
                            <div class="enc-point-card__head">
                                <span class="enc-mini-chip"><i class="bi bi-geo-alt"></i><?= enc_h(enc_route_type_label($point['clm_encpunto_tipo'] ?? 'RUTA')) ?></span>
                                <?= $doc ? '<span class="enc-manifest-pill enc-manifest-pill--ok"><i class="bi bi-check2-circle"></i>PDF listo</span>' : '<span class="enc-manifest-pill enc-manifest-pill--pending"><i class="bi bi-hourglass-split"></i>Pendiente</span>' ?>
                            </div>
                            <h4><?= enc_h($point['sede_nombre']) ?></h4>
                            <p>Orden <?= enc_h((int)$point['clm_encpunto_orden']) ?> dentro de la Control Encomienda.</p>
                            <?php if ($doc): ?>
                                <dl class="enc-doc-meta-grid">
                                    <div><dt>Archivo</dt><dd><?= enc_h($doc['clm_encdoc_nombre']) ?></dd></div>
                                    <div><dt>Peso</dt><dd><?= enc_h(enc_file_size($doc['clm_encdoc_size'])) ?></dd></div>
                                    <div><dt>Cargado</dt><dd><?= enc_h(enc_fmt_datetime($doc['clm_encdoc_fechacarga'])) ?></dd></div>
                                    <div><dt>Usuario</dt><dd><?= enc_h($doc['usuario_carga']) ?></dd></div>
                                </dl>
                                <?= enc_doc_action_links($doc) ?>
                            <?php endif; ?>
                            <?php if (!$isAnulada && $canDocs): ?>
                                <form class="enc-upload-form enc-ajax-form" action="actions/subir_documento.php" method="post" enctype="multipart/form-data" data-confirm="<?= $doc ? 'Reemplazar manifiesto de este punto.' : 'Subir manifiesto de este punto.' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= enc_h($guia['clm_enc_id']) ?>">
                                    <input type="hidden" name="tipo" value="MANIFIESTO_ENCOMIENDAS">
                                    <input type="hidden" name="idpunto" value="<?= enc_h($point['clm_encpunto_id']) ?>">
                                    <input type="file" name="documento" accept="application/pdf,.pdf" required>
                                    <button class="stock-btn stock-btn--soft stock-btn--sm" type="submit"><i class="bi bi-cloud-arrow-up"></i> <?= $doc ? 'Reemplazar' : 'Subir manifiesto' ?></button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="enc-section">
            <div class="enc-section__head enc-section__head--actions">
                <div><h3>Guias de transportista</h3><span>Opcionales por factura, boleta o recibo</span></div>
                <?php if (!$isAnulada && $canDocs): ?>
                    <button class="stock-btn stock-btn--primary stock-btn--sm" type="button" data-enc-transport-open data-guide-id="<?= enc_h($guia['clm_enc_id']) ?>"><i class="bi bi-plus-circle"></i> Agregar guia</button>
                <?php endif; ?>
            </div>
            <div class="enc-doc-guidance"><i class="bi bi-info-circle"></i> Estos documentos son opcionales y pueden cargarse cuando gerencia o facturacion los entregue.</div>

            <div class="stock-table-wrap enc-transport-docs">
                <table class="stock-table enc-doc-table">
                    <thead><tr><th>Documento</th><th>Tipo</th><th>Numero</th><th>Fecha</th><th>Archivo</th><th>Carga</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php if (!$transportDocs): ?>
                        <tr><td colspan="7" class="stock-empty">No se han cargado guias de transportista. Son opcionales.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transportDocs as $doc): ?>
                            <tr>
                                <td><strong><?= enc_h(enc_doc_label($doc['clm_encdoc_tipo'])) ?></strong><small><?= enc_h($doc['clm_encdoc_observacion'] ?: '') ?></small></td>
                                <td><?= enc_h($doc['clm_encdoc_tipo_comprobante'] ?: '-') ?></td>
                                <td><?= enc_h($doc['clm_encdoc_numero_comprobante'] ?: '-') ?></td>
                                <td><?= enc_h(enc_fmt_date($doc['clm_encdoc_fecha_comprobante'])) ?></td>
                                <td><?= enc_h($doc['clm_encdoc_nombre']) ?><br><small><?= enc_h(enc_file_size($doc['clm_encdoc_size'])) ?></small></td>
                                <td><?= enc_h(enc_fmt_datetime($doc['clm_encdoc_fechacarga'])) ?><br><small><?= enc_h($doc['usuario_carga']) ?></small></td>
                                <td>
                                    <?= enc_doc_action_links($doc) ?>
                                    <?php if (!$isAnulada && $canDocs): ?>
                                        <form class="enc-upload-form enc-ajax-form enc-replace-mini" action="actions/subir_documento.php" method="post" enctype="multipart/form-data" data-confirm="Reemplazar PDF de esta guia transportista.">
                                            <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
                                            <input type="hidden" name="id" value="<?= enc_h($guia['clm_enc_id']) ?>">
                                            <input type="hidden" name="tipo" value="GUIA_TRANSPORTISTA">
                                            <input type="hidden" name="documento_id" value="<?= enc_h($doc['clm_encdoc_id']) ?>">
                                            <input type="hidden" name="tipo_comprobante" value="<?= enc_h($doc['clm_encdoc_tipo_comprobante']) ?>">
                                            <input type="hidden" name="numero_comprobante" value="<?= enc_h($doc['clm_encdoc_numero_comprobante']) ?>">
                                            <input type="hidden" name="fecha_comprobante" value="<?= enc_h($doc['clm_encdoc_fecha_comprobante']) ?>">
                                            <input type="hidden" name="doc_observacion" value="<?= enc_h($doc['clm_encdoc_observacion']) ?>">
                                            <input type="file" name="documento" accept="application/pdf,.pdf" required>
                                            <button class="stock-btn stock-btn--soft stock-btn--sm" type="submit"><i class="bi bi-arrow-repeat"></i> Reemplazar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="enc-section enc-detail-panel" data-enc-detail-panel="timeline" hidden>
            <div class="enc-section__head"><h3>Linea de seguimiento</h3><span>Proceso operativo</span></div>
            <div class="enc-timeline">
                <?= enc_detail_stage('Control Encomienda registrada', 'REGISTRADA', $guia['clm_enc_fechacreated'], $guia['usuario_registra'], $guia['sede_embarque'], $unit, $guia['clm_enc_observacion'], true) ?>
                <?= enc_detail_stage('Embarque pendiente', 'PENDIENTE', null, null, $guia['sede_embarque'], $unit, '', $guia['clm_enc_estado_embarque'] === 'PENDIENTE') ?>
                <?= enc_detail_stage('Guia embarcada', $guia['clm_enc_estado_embarque'], $guia['clm_enc_fecha_embarque'], $guia['usuario_embarque'], $guia['sede_embarque'], $unit, '', in_array($guia['clm_enc_estado_embarque'], ['EMBARCADO','OBSERVADO'], true)) ?>
                <?= enc_detail_stage('Manifiestos por ruta', $manifestDone ? 'RECIBIDO' : 'PENDIENTE', null, null, $guia['sede_desembarque'], $unit, $manifestDone ? 'Manifiestos cargados por cada punto obligatorio.' : 'Faltan manifiestos obligatorios de la ruta.', $manifestDone) ?>
                <?= enc_detail_stage('Desembarque registrado', $guia['clm_enc_estado_desembarque'], $guia['clm_enc_fecha_desembarque'], $guia['usuario_desembarque'], $guia['sede_desembarque'], $unit, '', $guia['clm_enc_estado_desembarque'] !== 'PENDIENTE') ?>
                <?= enc_detail_stage('Proceso finalizado', $guia['clm_enc_estado_general'], $guia['clm_enc_datetimeupdated'], $guia['usuario_actualiza'], $guia['sede_desembarque'], $unit, '', $guia['clm_enc_estado_general'] === 'FINALIZADA') ?>
            </div>
        </section>

        <section class="enc-section enc-detail-panel" data-enc-detail-panel="history" hidden>
            <div class="enc-section__head"><h3>Historial</h3><span>Solo lectura</span></div>
            <div class="stock-table-wrap">
                <table class="stock-table enc-history-table">
                    <thead><tr><th>Accion</th><th>Fecha</th><th>Usuario</th><th>Cambios relevantes</th></tr></thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="4" class="stock-empty">No hay eventos registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($history as $event): ?>
                            <tr>
                                <td><?= enc_h($event['clm_enchist_accion']) ?></td>
                                <td><?= enc_h(enc_fmt_datetime($event['clm_enchist_fechaevento'])) ?></td>
                                <td><?= enc_h($event['usuario_evento'] ?: '-') ?></td>
                                <td><pre><?= enc_h(enc_history_changes($event['clm_enchist_datos_anteriores'], $event['clm_enchist_datos_nuevos'])) ?></pre></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>


    </div>
    <?php
}

if ($partial) {
    enc_render_detail_content($guia, $points, $documents, $history, $pageError, $csrf);
    exit;
}

if (!defined('N360_LAYOUT')) define('N360_LAYOUT', true);
if (!defined('N360_BASE_URL')) define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle de Control Encomienda | Norte360</title>
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
<?php n360_render_header(['title' => 'Encomiendas', 'subtitle' => 'Detalle Control Encomienda']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content enc-content">
        <div class="n360-main__inner enc-page" data-enc-detail-page data-csrf="<?= enc_h($csrf) ?>" data-report-user="<?= enc_h(enc_user_name()) ?>" data-report-dni="<?= enc_h($_SESSION['DNI'] ?? $_SESSION['dni'] ?? 'No registrado') ?>">
            <div class="enc-backbar"><a class="stock-btn stock-btn--soft" href="tracking.php"><i class="bi bi-arrow-left"></i> Volver al tracking</a></div>
            <?php enc_render_detail_content($guia, $points, $documents, $history, $pageError, $csrf); ?>
        </div>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>
<div class="modal fade enc-transport-modal" id="encTransportDocModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content enc-ajax-form" action="actions/subir_documento.php" method="post" enctype="multipart/form-data" data-confirm="Subir Guia de Transportista opcional.">
            <div class="modal-header">
                <div>
                    <span style="color: #08243d;" class="stock-eyebrow"><i class="bi bi-file-earmark-pdf"></i> Documento opcional</span>
                    <h5 class="modal-title">Agregar Guia de Transportista</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body enc-transport-modal__body">
                <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
                <input type="hidden" name="id" value="<?= enc_h($guideId) ?>" data-enc-transport-guide-id>
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
<script src="<?= enc_h(n360_asset('assets/js/loader_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/dialog_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="<?= enc_h(n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js')) ?>"></script>
<script src="assets/js/encomiendas_pdf.js?v=1.0.0"></script>
<script src="assets/js/tracking_encomiendas.js?v=1.6.0"></script>
</body>
</html>
