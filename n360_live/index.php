<?php
define('ACCESS_GRANTED', true);

require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

if (empty($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/live_lib.php';

if (!n360_live_can_access($conn, false)) {
    header('Location: ../login/none_permisos.php');
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Norte360 Live | Programacion</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('n360_live/n360_live.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Norte360 Live', 'subtitle' => 'Programacion en vivo']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <section
        class="n360-live-page"
        data-n360-live-app
        data-live-endpoint="<?= htmlspecialchars(n360_base_url('n360_live/api.php'), ENT_QUOTES, 'UTF-8') ?>"
    >
        <header class="n360-live-hero">
            <div>
                <p class="n360-live-eyebrow">
                    <i class="bi bi-broadcast-pin"></i>
                    Terminal Norte360 Live
                </p>
                <h1>Salidas operativas</h1>
                <span>Monitor tipo aeropuerto para buses programados, proximos y en ruta.</span>
            </div>
            <div class="n360-live-clock">
                <small>Hora actual</small>
                <strong data-live-clock>--:--:--</strong>
                <span>America/Lima</span>
            </div>
        </header>

        <section class="n360-live-toolbar">
            <div>
                <strong data-live-summary>Sincronizando terminal...</strong>
                <span data-live-cache>Sin datos cargados.</span>
            </div>
            <button class="n360-live-btn" type="button" data-live-refresh>
                <i class="bi bi-arrow-clockwise"></i>
                <span>Actualizar ahora</span>
            </button>
        </section>

        <section class="n360-live-grid">
            <article class="n360-live-next" data-live-next>
                <div class="n360-live-loading">
                    <i class="bi bi-hourglass-split"></i>
                    <span>Cargando proxima salida...</span>
                </div>
            </article>

            <aside class="n360-live-side">
                <section class="n360-live-kpis">
                    <article>
                        <span>Programados</span>
                        <strong data-live-kpi="programados">0</strong>
                    </article>
                    <article>
                        <span>Proximos</span>
                        <strong data-live-kpi="proximos">0</strong>
                    </article>
                    <article>
                        <span>En ruta</span>
                        <strong data-live-kpi="ruta">0</strong>
                    </article>
                </section>

                <section class="n360-live-viewers">
                    <div class="n360-live-section-head">
                        <h2>Visualizando</h2>
                        <span data-live-viewer-count>0</span>
                    </div>
                    <div class="n360-live-viewer-list" data-live-viewers>
                        <p class="n360-live-empty">Sin visualizadores activos.</p>
                    </div>
                </section>
            </aside>
        </section>

        <section class="n360-live-board">
            <div class="n360-live-section-head">
                <h2>Tablero de salidas</h2>
                <span data-live-total>0 horarios</span>
            </div>
            <div class="n360-live-departure-head" aria-hidden="true">
                <span>Hora</span>
                <span>Unidad</span>
                <span>Recorrido</span>
                <span>Estado</span>
            </div>
            <div class="n360-live-board-list" data-live-list>
                <div class="n360-live-empty">No hay datos cargados.</div>
            </div>
        </section>
    </section>

    <?php n360_render_content_separator('bottom'); ?>
</main>
<?php n360_render_footer(); ?>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('n360_live/n360_live.js') ?>"></script>
</body>
</html>
