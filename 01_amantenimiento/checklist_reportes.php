<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

$checklistId = isset($_GET['checklist_id']) ? (int)$_GET['checklist_id'] : 0;
$singleMode = $checklistId > 0;

if (($_SESSION['web_rol'] ?? '') !== 'Admin' && !$singleMode) {
    header('Location: ../login/none_permisos.php');
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function crp_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$desde = date('Y-m-d', strtotime('-180 days'));
$userName = trim((string)($_SESSION['usuario'] ?? 'Usuario'));
$dni = trim((string)($_SESSION['DNI'] ?? 'No registrado'));
$autoDownload = isset($_GET['download']) || isset($_GET['descargar']);
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes de checklist | Norte360</title>
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
<?php n360_render_header(['title' => 'Reportes de checklist', 'subtitle' => 'Calidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner check-report-shell">
        <?php n360_render_content_separator('top'); ?>

        <?php if ($singleMode): ?>
            <section class="check-report-hero">
                <div>
                    <span class="check-report-kicker"><i class="bi bi-file-earmark-pdf-fill" aria-hidden="true"></i> Checklist unitario</span>
                    <h1>Descarga de checklist</h1>
                    <p>El reporte se genera con el formato PDF estandar de Norte 360. Puedes volver al listado cuando termine la descarga.</p>
                </div>
                <div class="check-report-hero__actions">
                    <a class="check-report-btn check-report-btn--ghost" href="lista_cheklist.php"><i class="bi bi-arrow-left"></i> Ver checklist</a>
                </div>
            </section>

            <section class="check-report-panel" style="margin-top:22px;">
                <div class="check-report-panel__head">
                    <h2>Estado de generacion</h2>
                </div>
                <div class="check-report-panel__body">
                    <div class="check-report-item" id="singleStatus">
                        <span>Preparando</span>
                        <strong>Generando PDF del checklist #<?= crp_h($checklistId) ?></strong>
                    </div>
                </div>
            </section>
        <?php else: ?>
            <section class="check-report-hero">
                <div>
                    <span class="check-report-kicker"><i class="bi bi-shield-check" aria-hidden="true"></i> Solo administradores</span>
                    <h1>Checklist por unidad</h1>
                    <p>Busca una unidad para revisar sus checklists por fecha, ultima fumigacion, conductores y rutas vigentes. Desde aqui puedes descargar reportes individuales o el consolidado de la unidad.</p>
                </div>
                <div class="check-report-hero__actions">
                    <button type="button" class="check-report-btn check-report-btn--primary" id="btnUnitPdf" disabled><i class="bi bi-file-earmark-pdf"></i> PDF consolidado</button>
                    <a class="check-report-btn check-report-btn--ghost" href="<?= crp_h(n360_base_url('index.php')) ?>"><i class="bi bi-arrow-left"></i> Panel</a>
                </div>
            </section>

            <section class="check-report-toolbar" aria-label="Filtros de reporte">
                <label class="check-report-field check-report-suggest">
                    <span>Unidad</span>
                    <input type="search" id="unitSearch" class="check-report-input" autocomplete="off" placeholder="Buscar por bus o placa...">
                    <div id="unitResults" class="check-report-results check-report-hidden"></div>
                </label>
                <label class="check-report-field">
                    <span>Desde</span>
                    <input type="date" id="unitDesde" value="<?= crp_h($desde) ?>">
                </label>
                <label class="check-report-field">
                    <span>Hasta</span>
                    <input type="date" id="unitHasta" value="<?= crp_h($today) ?>">
                </label>
                <button type="button" class="check-report-btn check-report-btn--primary" id="btnUnitLoad" disabled><i class="bi bi-search"></i> Consultar</button>
            </section>

            <section class="check-report-summary" id="unitSummary">
                <div class="check-report-metric"><span>Checklists</span><strong>0</strong></div>
                <div class="check-report-metric"><span>Completos</span><strong>0</strong></div>
                <div class="check-report-metric"><span>Incompletos</span><strong>0</strong></div>
                <div class="check-report-metric"><span>Unidades</span><strong>0</strong></div>
            </section>

            <section class="check-report-grid">
                <aside class="check-report-panel">
                    <div class="check-report-panel__head">
                        <h2>Unidad</h2>
                    </div>
                    <div class="check-report-panel__body">
                        <div id="unitAside" class="check-report-empty">Selecciona una unidad para ver su resumen.</div>
                    </div>
                </aside>

                <section class="check-report-panel">
                    <div class="check-report-panel__head">
                        <h2>Operacion vigente</h2>
                    </div>
                    <div class="check-report-panel__body">
                        <div id="unitProgramming" class="check-report-empty">Conductores y rutas se cargaran con la unidad.</div>
                    </div>
                </section>
            </section>

            <section class="check-report-grid" style="margin-top:16px;">
                <section class="check-report-panel">
                    <div class="check-report-panel__head">
                        <h2>Ultimos por tipo</h2>
                    </div>
                    <div class="check-report-panel__body">
                        <div id="unitLatest" class="check-report-list">
                            <div class="check-report-empty">Sin unidad seleccionada.</div>
                        </div>
                    </div>
                </section>

                <section class="check-report-panel">
                    <div class="check-report-panel__head">
                        <h2>Historial de checklists</h2>
                    </div>
                    <div class="check-report-panel__body">
                        <div class="check-report-table-wrap">
                            <table class="check-report-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Checklist</th>
                                        <th>Estado</th>
                                        <th>KPI</th>
                                        <th>Responsable</th>
                                        <th>Observaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="unitChecklistBody">
                                    <tr><td colspan="7">Selecciona una unidad.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </section>
        <?php endif; ?>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/pdf_n360.js') ?>"></script>
<script>
window.N360_CHECK_REPORT = {
    mode: <?= json_encode($singleMode ? 'single' : 'unit') ?>,
    checklistId: <?= json_encode($checklistId) ?>,
    autoDownload: <?= $autoDownload ? 'true' : 'false' ?>,
    apiUrl: <?= json_encode(n360_base_url('01_amantenimiento/api_checklist_reportes.php'), JSON_UNESCAPED_SLASHES) ?>,
    userName: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    dni: <?= json_encode($dni, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    logoLeft: <?= json_encode(n360_base_url('img/icon.png'), JSON_UNESCAPED_SLASHES) ?>,
    logoRight: <?= json_encode(n360_base_url('img/norte360_black.png'), JSON_UNESCAPED_SLASHES) ?>,
    coverImage: <?= json_encode(n360_base_url('img/caratula_historial_flota.png'), JSON_UNESCAPED_SLASHES) ?>,
    checklistViewUrl: <?= json_encode(n360_base_url('01_amantenimiento/ver_checklist.php'), JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= n360_asset('assets/js/checklist_reportes_n360.js') ?>"></script>
</body>
</html>
