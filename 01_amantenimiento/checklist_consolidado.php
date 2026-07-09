<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

$permisos = ($_SESSION['permisos'] ?? []) === 'all' ? [] : (array)($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] ?? []) === 'all' ? [] : (array)($_SESSION['vistas'] ?? []);
if (($_SESSION['web_rol'] ?? '') !== 'Admin' && ($_SESSION['permisos'] ?? []) !== 'all') {
    $moduloActual = 5;
    $vistasActuales = ['c-limp', 'c-sab', 'c-lalu'];
    if (!in_array($moduloActual, array_map('intval', $permisos), true) || empty(array_intersect($vistasActuales, $vistas))) {
        header('Location: ../login/none_permisos.php');
        exit();
    }
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function fchk_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$desde = date('Y-m-d', strtotime('-30 days'));
$userName = trim((string)($_SESSION['usuario'] ?? 'Usuario'));
$dni = trim((string)($_SESSION['DNI'] ?? 'No registrado'));
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consolidado checklist | Norte360</title>
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
<?php n360_render_header(['title' => 'Consolidado checklist', 'subtitle' => 'Calidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner check-report-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="check-report-hero">
            <div>
                <span class="check-report-kicker"><i class="bi bi-bar-chart-line-fill" aria-hidden="true"></i> Calidad</span>
                <h1>Consolidado de checklist</h1>
                <p>Vista general para revisar checklists por unidad y sus metricas KPI dentro del periodo seleccionado.</p>
            </div>
            <div class="check-report-hero__actions">
                <button type="button" class="check-report-btn check-report-btn--primary" id="btnFleetPdf" disabled><i class="bi bi-file-earmark-pdf"></i> PDF consolidado</button>
                <a class="check-report-btn check-report-btn--ghost" href="<?= fchk_h(n360_base_url('index.php')) ?>"><i class="bi bi-arrow-left"></i> Panel</a>
            </div>
        </section>

        <section class="check-report-toolbar check-report-toolbar--fleet" aria-label="Filtros de consolidado">
            <label class="check-report-field">
                <span>Desde</span>
                <input type="date" id="fleetDesde" value="<?= fchk_h($desde) ?>">
            </label>
            <label class="check-report-field">
                <span>Hasta</span>
                <input type="date" id="fleetHasta" value="<?= fchk_h($today) ?>">
            </label>
            <button type="button" class="check-report-btn check-report-btn--primary" id="btnFleetLoad"><i class="bi bi-search"></i> Consultar</button>
        </section>

        <section class="check-report-filterbar check-report-hidden" id="fleetLocalFilters" aria-label="Filtros locales del consolidado">
            <label class="check-report-field">
                <span>Buscar en resultados</span>
                <input type="search" id="fleetLocalSearch" class="check-report-input" placeholder="Unidad, placa, checklist, responsable...">
            </label>
            <label class="check-report-field">
                <span>Checklist</span>
                <select id="fleetTipoFilter">
                    <option value="">Todos</option>
                </select>
            </label>
            <label class="check-report-field">
                <span>KPI</span>
                <select id="fleetKpiFilter">
                    <option value="">Todos</option>
                    <option value="ok">Positivos</option>
                    <option value="warn">En observacion</option>
                    <option value="bad">Criticos</option>
                </select>
            </label>
            <button type="button" class="check-report-btn check-report-btn--soft" id="btnFleetClearFilters"><i class="bi bi-x-circle"></i> Limpiar filtros</button>
        </section>

        <section class="check-report-summary" id="fleetSummary">
            <div class="check-report-metric"><span>Checklists</span><strong>0</strong></div>
            <div class="check-report-metric"><span>Completos</span><strong>0</strong></div>
            <div class="check-report-metric"><span>Incompletos</span><strong>0</strong></div>
            <div class="check-report-metric"><span>Unidades</span><strong>0</strong></div>
        </section>

        <section class="check-report-panel">
            <div class="check-report-panel__head">
                <h2>KPIs por checklist</h2>
                <span class="check-report-chip">Calidad</span>
            </div>
            <div class="check-report-panel__body">
                <div class="check-report-table-wrap">
                    <table class="check-report-table">
                        <thead>
                            <tr>
                                <th>Unidad</th>
                                <th>Fecha</th>
                                <th>Checklist</th>
                                <th>Estado</th>
                                <th>KPI</th>
                                <th>Responsable</th>
                            </tr>
                        </thead>
                        <tbody id="fleetBody">
                            <tr><td colspan="6">Pulsa Consultar para cargar el consolidado.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

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
    mode: 'fleet',
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
