<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

if (($_SESSION['web_rol'] ?? '') !== 'Admin') {
    header('Location: ../login/none_permisos.php');
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function cac_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$desde = date('Y-m-d', strtotime('-180 days'));
$userName = trim((string)($_SESSION['usuario'] ?? 'Usuario'));
$dni = trim((string)($_SESSION['DNI'] ?? 'No registrado'));
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Análisis CheckList | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/loader_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/checklist_reportes_n360.css') ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>
<?php n360_render_header(['title' => 'Análisis CheckList', 'subtitle' => 'Calidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner check-report-shell check-analysis-page">
        <?php n360_render_content_separator('top'); ?>

        <section class="check-report-hero">
            <div>
                <span class="check-report-kicker"><i class="bi bi-bar-chart-line-fill" aria-hidden="true"></i> Control de calidad</span>
                <h1>Análisis CheckList</h1>
                <p>Resultados por unidad, periodo, versión, zona e ítem evaluado.</p>
            </div>
            <div class="check-report-hero__actions">
                <a class="check-report-btn check-report-btn--ghost" href="<?= cac_h(n360_base_url('01_amantenimiento/checklist_reportes.php')) ?>"><i class="bi bi-file-earmark-pdf"></i> Reportes</a>
            </div>
        </section>

        <section class="check-analysis-scope" aria-labelledby="analysisScopeTitle">
            <div class="check-analysis-scope__head">
                <div>
                    <span class="check-report-section-kicker"><i class="bi bi-bus-front-fill" aria-hidden="true"></i> Alcance</span>
                    <h2 id="analysisScopeTitle">Unidades a evaluar</h2>
                </div>
                <span class="check-analysis-scope__total" id="activeUnitsCount">Cargando unidades...</span>
            </div>

            <div class="check-analysis-scope__body">
                <div class="check-analysis-mode" role="group" aria-label="Modo de selección">
                    <button type="button" class="is-active" data-analysis-scope="all" aria-pressed="true"><i class="bi bi-check2-all" aria-hidden="true"></i> Todas las activas</button>
                    <button type="button" data-analysis-scope="manual" aria-pressed="false"><i class="bi bi-ui-checks" aria-hidden="true"></i> Selección manual</button>
                </div>

                <div class="check-analysis-controls">
                    <div class="check-analysis-selection">
                        <div class="check-analysis-all-summary" id="allScopeSummary">
                            <i class="bi bi-bus-front" aria-hidden="true"></i>
                            <span><strong id="allScopeCount">0 unidades</strong><small>Se incluirán todas las unidades activas</small></span>
                        </div>

                        <div class="check-analysis-picker check-report-hidden" id="manualScopeControls">
                            <button type="button" class="check-analysis-picker__trigger" id="unitPickerToggle" aria-expanded="false" aria-controls="unitPickerPanel">
                                <span><i class="bi bi-bus-front" aria-hidden="true"></i><strong id="manualSelectionLabel">Seleccionar unidades</strong></span>
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                            <div class="check-analysis-picker__panel check-report-hidden" id="unitPickerPanel">
                                <label class="check-analysis-picker__search">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input type="search" id="unitPickerSearch" placeholder="Buscar bus, placa o servicio..." autocomplete="off">
                                </label>
                                <div class="check-analysis-picker__tools">
                                    <span id="visibleUnitsCount">0 disponibles</span>
                                    <div>
                                        <button type="button" id="btnSelectVisible">Marcar visibles</button>
                                        <button type="button" id="btnClearUnits">Limpiar</button>
                                    </div>
                                </div>
                                <div class="check-analysis-picker__list" id="unitPickerList"></div>
                            </div>
                        </div>
                    </div>

                    <label class="check-report-field">
                        <span>Desde</span>
                        <input type="date" id="unitDesde" value="<?= cac_h($desde) ?>">
                    </label>
                    <label class="check-report-field">
                        <span>Hasta</span>
                        <input type="date" id="unitHasta" value="<?= cac_h($today) ?>">
                    </label>
                    <button type="button" class="check-report-btn check-report-btn--primary" id="btnUnitLoad" disabled><i class="bi bi-bar-chart-line" aria-hidden="true"></i> Analizar</button>
                </div>

                <div class="check-analysis-selected check-report-hidden" id="unitSelectionSummary"></div>
            </div>
        </section>

        <section class="check-analysis-empty" id="analysisEmpty">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
            <div>
                <strong>Análisis listo para consultar</strong>
                <span class="check-analysis-empty__message">Selecciona el alcance y el periodo para cargar los resultados.</span>
            </div>
        </section>

        <section class="check-analysis-results check-report-hidden" id="unitQuality" aria-labelledby="unitQualityTitle">
            <section class="check-analysis-result-head">
                <div class="check-analysis-result-head__title">
                    <div>
                        <span class="check-report-section-kicker"><i class="bi bi-bar-chart-line" aria-hidden="true"></i> <span id="analysisUnitName">Alcance seleccionado</span></span>
                        <h2 id="unitQualityTitle">Resultado consolidado</h2>
                        <p id="unitQualityPeriod">Sin periodo consultado</p>
                    </div>
                    <div class="check-analysis-version" id="unitQualityVersion">Sin versión disponible</div>
                </div>

                <div class="check-report-analysis-filters">
                <label class="check-report-field">
                    <span>Checklist</span>
                    <select id="unitAnalysisType"><option value="">Seleccionar</option></select>
                </label>
                <label class="check-report-field">
                    <span>Versión</span>
                    <select id="unitAnalysisVersion"><option value="0">Todas</option></select>
                </label>
                <label class="check-report-field">
                    <span>Resultado</span>
                    <select id="unitAnalysisStatus">
                        <option value="">Todos</option>
                        <option value="ok">Excelente</option>
                        <option value="warn">Aceptable</option>
                        <option value="bad">Crítico</option>
                    </select>
                </label>
                <label class="check-report-field check-report-field--search">
                    <span>Buscar zona o ítem</span>
                    <input type="search" id="unitAnalysisSearch" class="check-report-input" placeholder="Primer piso, asientos, ventanas...">
                </label>
                </div>
            </section>

            <section class="check-analysis-kpis" id="unitQualityMetrics" aria-label="Indicadores del análisis">
                <article class="check-analysis-kpi is-units"><span class="check-analysis-kpi__icon"><i class="bi bi-bus-front"></i></span><div><span>Unidades</span><strong>0</strong><small>Sin consulta</small></div></article>
                <article class="check-analysis-kpi is-runs"><span class="check-analysis-kpi__icon"><i class="bi bi-clipboard2-check"></i></span><div><span>Ejecuciones</span><strong>0</strong><small>Sin consulta</small></div></article>
                <article class="check-analysis-kpi is-answers"><span class="check-analysis-kpi__icon"><i class="bi bi-ui-checks-grid"></i></span><div><span>Ítems respondidos</span><strong>0 / 0</strong><small>0% de completitud</small></div></article>
                <article class="check-analysis-kpi is-quality"><span class="check-analysis-kpi__icon"><i class="bi bi-shield-check"></i></span><div><span>Conformidad</span><strong>0%</strong><small>0 evaluables</small></div></article>
                <article class="check-analysis-kpi is-findings"><span class="check-analysis-kpi__icon"><i class="bi bi-exclamation-triangle"></i></span><div><span>Hallazgos</span><strong>0</strong><small>0 pendientes</small></div></article>
            </section>

            <section class="check-analysis-board">
                <div class="check-analysis-board__head">
                    <div>
                        <span class="check-report-section-kicker"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Detalle operativo</span>
                        <h2>Lectura del checklist</h2>
                    </div>
                    <span>Resultados consolidados del periodo</span>
                </div>

                <nav class="check-analysis-tabs" aria-label="Vistas del análisis">
                <button type="button" class="is-active" data-analysis-view="zones" aria-selected="true"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Zonas e ítems</button>
                <button type="button" data-analysis-view="units" aria-selected="false"><i class="bi bi-bus-front" aria-hidden="true"></i> Unidades <span id="analysisUnitsCount">0</span></button>
                <button type="button" data-analysis-view="history" aria-selected="false"><i class="bi bi-clock-history" aria-hidden="true"></i> Ejecuciones <span id="analysisHistoryCount">0</span></button>
                </nav>

                <div class="check-analysis-view" id="analysisViewZones" data-analysis-panel="zones">
                <div class="check-report-zone-grid" id="unitZoneGrid">
                    <div class="check-report-empty">No hay información cargada.</div>
                </div>
                </div>

                <div class="check-analysis-view check-report-hidden" id="analysisViewUnits" data-analysis-panel="units">
                <div class="check-report-table-wrap">
                    <table class="check-report-table check-analysis-table">
                        <thead><tr><th>Unidad</th><th>Servicio</th><th>Ejecuciones</th><th>Respondidos</th><th>Conformidad</th><th>Hallazgos</th></tr></thead>
                        <tbody id="analysisUnitsBody"><tr><td colspan="6">Sin unidades cargadas.</td></tr></tbody>
                    </table>
                </div>
                </div>

                <div class="check-analysis-view check-report-hidden" id="analysisViewHistory" data-analysis-panel="history">
                <div class="check-report-table-wrap">
                    <table class="check-report-table check-analysis-table">
                        <thead>
                            <tr>
                                <th>Unidad</th>
                                <th>Fecha</th>
                                <th>Checklist</th>
                                <th>Versión</th>
                                <th>Responsable</th>
                                <th>Ítems</th>
                            </tr>
                        </thead>
                        <tbody id="analysisChecklistBody">
                            <tr><td colspan="6">Sin registros cargados.</td></tr>
                        </tbody>
                    </table>
                </div>
                </div>
            </section>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>

<div class="check-report-modal" id="checklistDetailModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="checklistDetailTitle">
    <div class="check-report-modal__dialog">
        <header class="check-report-modal__head">
            <div>
                <span class="check-report-section-kicker"><i class="bi bi-clipboard2-check" aria-hidden="true"></i> Detalle de calidad</span>
                <h2 id="checklistDetailTitle">Ítems del checklist</h2>
            </div>
            <button type="button" class="check-report-icon-btn" id="btnCloseChecklistDetail" aria-label="Cerrar detalle" title="Cerrar">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="check-report-modal__body" id="checklistDetailBody">
            <div class="check-report-empty">Cargando detalle...</div>
        </div>
    </div>
</div>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script>
window.N360_CHECK_REPORT = {
    mode: 'analysis',
    apiUrl: <?= json_encode(n360_base_url('01_amantenimiento/api_checklist_reportes.php'), JSON_UNESCAPED_SLASHES) ?>,
    userName: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    dni: <?= json_encode($dni, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    logoLeft: <?= json_encode(n360_base_url('img/icon.png'), JSON_UNESCAPED_SLASHES) ?>,
    logoRight: <?= json_encode(n360_base_url('img/norte360_black.png'), JSON_UNESCAPED_SLASHES) ?>,
    coverImage: <?= json_encode(n360_base_url('img/caratula_historial_flota.png'), JSON_UNESCAPED_SLASHES) ?>,
    checklistViewUrl: <?= json_encode(n360_base_url('01_amantenimiento/ver_checklist.php'), JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= n360_asset('assets/js/formatos/reportes/checklist_reportes_n360.js') ?>"></script>
</body>
</html>
