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
    <title>Norte360 Live | Programación</title>
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
<?php n360_render_header(['title' => 'Norte360 Live', 'subtitle' => 'Programación en vivo']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <section
        class="n360-live-page"
        data-n360-live-app
        data-live-endpoint="<?= htmlspecialchars(n360_base_url('n360_live/api.php'), ENT_QUOTES, 'UTF-8') ?>"
    >
        <header class="n360-live-terminal">
            <div class="n360-live-terminal__left">
                <div class="n360-live-terminal__eyebrow">
                    <span class="n360-live-liveflag"><i></i> EN VIVO</span>
                </div>
                <div class="n360-live-terminal__title">
                    <div>
                        <h1>Programación operativa</h1>
                    </div>
                </div>
            </div>

            <div class="n360-live-clockbox">
                <span class="n360-live-clockbox__label">HORA LOCAL · LIMA</span>
                <strong data-live-clock>--:--:--</strong>
                <small data-live-date>--</small>
            </div>
        </header>

        <section class="n360-live-controlbar">
            <div class="n360-live-sync">
                <i class="bi bi-broadcast"></i>
                <div>
                    <strong data-live-summary>Sincronizando programación...</strong>
                    <span data-live-cache>Sin datos cargados.</span>
                </div>
            </div>

            <button class="n360-live-btn" type="button" data-live-refresh>
                <i class="bi bi-arrow-clockwise"></i>
                <span>Actualizar pizarra</span>
            </button>
        </section>

        <section class="n360-live-kpis" aria-label="Resumen operativo">
            <article class="is-programado">
                <div class="n360-live-kpi-icon"><i class="bi bi-calendar-check"></i></div>
                <div>
                    <span>Programados</span>
                    <strong data-live-kpi="programados">0</strong>
                </div>
            </article>
            <article class="is-proximo">
                <div class="n360-live-kpi-icon"><i class="bi bi-clock-history"></i></div>
                <div>
                    <span>Próximos</span>
                    <strong data-live-kpi="proximos">0</strong>
                </div>
            </article>
            <article class="is-ruta">
                <div class="n360-live-kpi-icon"><i class="bi bi-bus-front"></i></div>
                <div>
                    <span>En ruta</span>
                    <strong data-live-kpi="ruta">0</strong>
                </div>
            </article>
        </section>

        <article class="n360-live-next" data-live-next>
            <div class="n360-live-loading">
                <i class="bi bi-hourglass-split"></i>
                <span>Cargando próxima salida...</span>
            </div>
        </article>

        <section class="n360-live-board">
            <div class="n360-live-section-head">
                <div>
                    <span class="n360-live-section-kicker">RESUMEN DE LA PIZARRA OPERATIVA</span>
                    <h2>Salidas programadas</h2>
                </div>
                <span data-live-total>0 horarios</span>
            </div>

            <div class="n360-live-departure-head" aria-hidden="true">
                <span>HORA</span>
                <span>UNIDAD</span>
                <span>ORIGEN</span>
                <span>DESTINO</span>
                <span>RECORRIDO</span>
                <span>ESTADO</span>
            </div>

            <div class="n360-live-board-list" data-live-list>
                <div class="n360-live-empty">No hay datos cargados.</div>
            </div>
        </section>

        <section class="n360-live-viewers">
            <div class="n360-live-viewers__head">
                <div>
                    <span>SESIONES ACTIVAS</span>
                    <strong>Visualizando Norte360 Live</strong>
                </div>
                <span class="n360-live-viewer-count" data-live-viewer-count>0</span>
            </div>
            <div class="n360-live-viewer-list" data-live-viewers>
                <p class="n360-live-empty">Sin visualizadores activos.</p>
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
