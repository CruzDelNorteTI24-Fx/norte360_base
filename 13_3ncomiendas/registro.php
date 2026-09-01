<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/includes/encomiendas_helpers.php';
require_once __DIR__ . '/includes/encomiendas_queries.php';

$conn = enc_start_page('enc-register', 'Registro de Control Encomienda');

$sedes = [];
$placas = [];
$programaciones = [];
$pageError = '';
$schemaReady = false;
try {
    $schemaReady = enc_schema_has_guias_norte($conn);
    $sedes = enc_fetch_sedes($conn);
    $placas = enc_fetch_placas($conn);
    $programaciones = enc_fetch_active_programaciones($conn);
} catch (Throwable $e) {
    enc_log($e);
    $pageError = enc_db_message($e);
}

$csrf = enc_csrf_token();

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
    <title>Registro de Control Encomienda | Norte360</title>
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
<?php n360_render_header(['title' => 'Encomiendas', 'subtitle' => 'Registro de Control Encomienda']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content enc-content">
    <div class="n360-main__inner enc-page enc-registro-page" data-enc-registro data-csrf="<?= enc_h($csrf) ?>">
        <section class="stock-hero enc-hero enc-hero--registro">
            <div class="enc-hero__icon"><i class="bi bi-signpost-2-fill"></i></div>
            <div class="enc-hero__text">
                <span class="stock-eyebrow"><i class="bi bi-send-check-fill"></i> Encomiendas - Control Encomienda</span>
                <h1>Registrar Control Encomienda</h1>
            </div>
            <div class="stock-hero-actions enc-hero__actions">
                <a class="stock-btn stock-btn--soft" href="tracking.php"><i class="bi bi-search"></i> Ir a tracking</a>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert stock-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><?= enc_h($pageError) ?></div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="stock-alert stock-alert--warning enc-schema-warning">
                <i class="bi bi-database-fill-gear"></i>
                Ejecuta manualmente <strong>C:\00_core_norte360\DOCS\sql\norte360\querysnuevas_encomiendas_unificado.sql</strong> para activar correlativos CE, puntos de ruta y la referencia opcional a pizarra.
            </div>
        <?php endif; ?>

        <section class="enc-result" id="encRegistroResult" hidden></section>

        <form class="enc-form" id="encRegistroForm" action="actions/guardar_guia.php" method="post" novalidate data-tracking-url="tracking.php" data-detail-url="detalle.php" <?= !$schemaReady ? 'data-disabled="1"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
            <input type="hidden" name="idprogbus" value="0" data-enc-schedule-id>

            <div class="enc-registro-layout">
                <div class="enc-registro-main">
                    <section class="enc-panel enc-guide-panel">
                        <div class="enc-panel__head">
                            <div>
                                <span>Control Encomienda</span>
                                <h2>Datos base del viaje</h2>
                            </div>
                            <span class="enc-auto-guide"><i class="bi bi-magic"></i> Correlativo automatico CE-000001</span>
                        </div>

                        <div class="enc-form-grid enc-form-grid--guide">
                            <label class="stock-field">
                                <span>Fecha de Control Encomienda *</span>
                                <input type="date" name="fecha_guia" value="<?= enc_h(enc_now_date()) ?>" required <?= !$schemaReady ? 'disabled' : '' ?>>
                                <small data-error-for="fecha_guia"></small>
                            </label>
                            <label class="stock-field">
                                <span>Hora de embarque</span>
                                <input type="time" name="hora_embarque_programada" data-enc-schedule-time <?= !$schemaReady ? 'disabled' : '' ?>>
                                <small data-error-for="hora_embarque_programada"></small>
                            </label>
                            <label class="stock-field stock-field--wide enc-schedule-switch">
                                <span>Horario operativo / viaje</span>
                                <span class="enc-switch-line">
                                    <input type="checkbox" data-enc-schedule-toggle <?= (!$schemaReady || !$programaciones) ? 'disabled' : '' ?>>
                                    <strong>Tomar horario actual de pizarra</strong>
                                    <small><?= $programaciones ? 'Autocompleta fecha, hora, oficinas, ruta y unidad.' : 'No hay horarios activos para vincular ahora.' ?></small>
                                </span>
                                <small data-error-for="idprogbus"></small>
                            </label>
                            <div class="enc-schedule-box" data-enc-schedule-box hidden>
                                <label class="stock-field stock-field--wide">
                                    <span>Horario activo</span>
                                    <select data-enc-schedule-select <?= (!$schemaReady || !$programaciones) ? 'disabled' : '' ?>>
                                        <option value="">Seleccionar horario activo</option>
                                        <?php foreach ($programaciones as $prog): ?>
                                            <?php
                                            $progHour = trim((string)($prog['hora'] ?? ''));
                                            $progUnit = trim((string)($prog['bus'] ?? ''));
                                            $progPlate = trim((string)($prog['placa'] ?? ''));
                                            $progLabel = trim(($progHour !== '' ? $progHour . ' | ' : '') . ($prog['origen'] ?? 'Origen') . ' -> ' . ($prog['destino'] ?? 'Destino') . ($progUnit !== '' ? ' | ' . $progUnit : '') . ($progPlate !== '' ? ' - ' . $progPlate : ''));
                                            ?>
                                            <option value="<?= enc_h($prog['id']) ?>"><?= enc_h($progLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <span class="enc-readonly-chip" data-enc-schedule-status><i class="bi bi-pencil-square"></i> Modo manual</span>
                            </div>
                            <input type="hidden" name="horario_operativo" data-enc-manual-schedule <?= !$schemaReady ? 'disabled' : '' ?>>
                            <label class="stock-field stock-field--wide">
                                <span>Observaciones internas</span>
                                <textarea name="observacion" rows="3" maxlength="2000" placeholder="Indicaciones de gerencia, incidencias o notas internas" <?= !$schemaReady ? 'disabled' : '' ?>></textarea>
                            </label>
                            <div class="enc-reg-user">
                                <span>Usuario que registra</span>
                                <strong><i class="bi bi-person-check-fill"></i> <?= enc_h(enc_user_name()) ?></strong>
                            </div>
                        </div>
                    </section>

                    <section class="enc-panel enc-route-builder" data-enc-route-builder>
                        <div class="enc-panel__head">
                            <div>
                                <span>Ruta documentaria</span>
                                <h2>Origen, puntos de manifiesto y destino</h2>
                            </div>
                            <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-enc-add-point <?= !$schemaReady ? 'disabled' : '' ?>><i class="bi bi-plus-circle"></i> Agregar parada</button>
                        </div>

                        <div class="enc-form-grid enc-form-grid--operation">
                            <label class="stock-field">
                                <span>Oficina de origen *</span>
                                <select name="idsede_embarque" required data-enc-origin <?= !$schemaReady ? 'disabled' : '' ?>>
                                    <option value="">Seleccionar origen</option>
                                    <?php foreach ($sedes as $sede): ?>
                                        <option value="<?= enc_h($sede['id']) ?>"><?= enc_h($sede['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small data-error-for="idsede_embarque"></small>
                            </label>
                            <label class="stock-field">
                                <span>Oficina de destino final *</span>
                                <select name="idsede_desembarque" required data-enc-destination <?= !$schemaReady ? 'disabled' : '' ?>>
                                    <option value="">Seleccionar destino</option>
                                    <?php foreach ($sedes as $sede): ?>
                                        <option value="<?= enc_h($sede['id']) ?>"><?= enc_h($sede['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small data-error-for="idsede_desembarque"></small>
                            </label>
                            <label class="stock-field stock-field--wide enc-unit-lookup" data-enc-unit-lookup>
                                <span>Unidad de transporte *</span>
                                <input type="hidden" name="idplaca_embarque" data-enc-unit <?= !$schemaReady ? 'disabled' : '' ?>>
                                <input type="search" data-enc-unit-search autocomplete="off" placeholder="Escribe bus o placa..." required <?= !$schemaReady ? 'disabled' : '' ?>>
                                <div class="enc-unit-suggestions" data-enc-unit-suggestions hidden></div>
                                <small data-error-for="idplaca_embarque"></small>
                            </label>
                        </div>

                        <div class="enc-route-points" data-enc-route-points></div>
                        <template id="encRutaPointTemplate">
                            <div class="enc-route-row" data-enc-route-row>
                                <span class="enc-route-row__handle"><i class="bi bi-signpost-split"></i></span>
                                <label class="stock-field">
                                    <span>Parada intermedia / manifiesto</span>
                                    <select name="puntos_ruta[]" data-enc-route-select <?= !$schemaReady ? 'disabled' : '' ?>>
                                        <option value="">Seleccionar parada</option>
                                        <?php foreach ($sedes as $sede): ?>
                                            <option value="<?= enc_h($sede['id']) ?>"><?= enc_h($sede['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="stock-btn stock-btn--soft stock-btn--icon" type="button" data-enc-remove-point aria-label="Quitar punto"><i class="bi bi-trash3"></i></button>
                            </div>
                        </template>

                        <div class="enc-route-preview" data-enc-route-preview>
                            <span><i class="bi bi-geo-alt-fill"></i> Origen</span>
                            <span><i class="bi bi-flag-fill"></i> Destino</span>
                        </div>
                        <small class="enc-route-hint" data-error-for="puntos_ruta">Origen, cada parada y destino tendran su manifiesto obligatorio.</small>
                    </section>
                </div>

                <aside class="enc-registro-side">
                    <section class="enc-panel enc-state-panel">
                        <div class="enc-panel__head">
                            <div>
                                <span>Embarque</span>
                                <h2>Estado inicial</h2>
                            </div>
                            <span class="enc-state enc-state--muted"><i class="bi bi-hourglass-split"></i>Pendiente</span>
                        </div>
                        <div class="enc-state-summary">
                            <strong>La guia nace pendiente de embarque.</strong>
                            <p>Luego se confirma embarque, desembarque, manifiestos y cierre desde tracking.</p>
                        </div>
                        <label class="stock-field stock-field--wide">
                            <span>Observacion operativa</span>
                            <textarea name="observacion_operativa" rows="6" maxlength="2000" placeholder="Notas para el embarque o recepcion en ruta" <?= !$schemaReady ? 'disabled' : '' ?>></textarea>
                        </label>
                    </section>
                </aside>
            </div>

            <div class="enc-form-actions">
                <button class="stock-btn stock-btn--soft" type="reset" <?= !$schemaReady ? 'disabled' : '' ?>><i class="bi bi-eraser"></i> Limpiar</button>
                <button class="stock-btn stock-btn--primary" type="submit" <?= !$schemaReady ? 'disabled' : '' ?>><i class="bi bi-save2"></i> Registrar Control Encomienda</button>
            </div>
        </form>
    </div>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>

<script type="application/json" id="encProgramacionesData"><?= json_encode($programaciones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="encSedesData"><?= json_encode($sedes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="encPlacasData"><?= json_encode($placas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= enc_h(n360_asset('assets/js/loader_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/dialog_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="<?= enc_h(n360_asset('13_3ncomiendas/assets/js/registro_encomiendas.js')) ?>"></script>
</body>
</html>
