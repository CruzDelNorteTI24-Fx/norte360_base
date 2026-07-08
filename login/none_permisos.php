<?php
session_start();

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');

require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function n360_np_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$isLogged = isset($_SESSION['usuario']) && trim((string)$_SESSION['usuario']) !== '';
$userName = trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario'));
$role = trim((string)($_SESSION['web_rol'] ?? 'Sin sesion'));
$requestedPath = 'No identificada';

if (!empty($_SERVER['HTTP_REFERER'])) {
    $parts = parse_url((string)$_SERVER['HTTP_REFERER']);
    if (is_array($parts)) {
        $refererHost = strtolower((string)($parts['host'] ?? ''));
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($refererHost === '' || $currentHost === '' || $refererHost === $currentHost) {
            $requestedPath = (string)($parts['path'] ?? 'No identificada');
            if (!empty($parts['query'])) {
                $requestedPath .= '?' . $parts['query'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso no permitido | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/none_permisos_n360.css') ?>">
</head>
<body class="<?= $isLogged ? 'with-sidebar' : 'n360-denied-guest' ?>">
<?php if ($isLogged): ?>
    <?php n360_render_header(); ?>
    <?php n360_render_sidebar(); ?>
<?php else: ?>
    <header class="n360-denied-guest-header">
        <a href="<?= n360_np_h(n360_base_url('login/login.php')) ?>" class="n360-denied-brand" aria-label="Ir al login">
            <img src="<?= n360_np_h(n360_base_url('img/norte360.png')) ?>" alt="Norte360">
            <span>
                <strong>Norte360</strong>
                <small>ERP Operativo de Transporte</small>
            </span>
        </a>
    </header>
<?php endif; ?>

<main class="<?= $isLogged ? 'main-content n360-main n360-main--module' : 'n360-denied-main n360-main' ?>" role="main">
    <div class="n360-main__inner n360-denied-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="n360-denied-card" aria-labelledby="deniedTitle">
            <div class="n360-denied-icon" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="n360-denied-copy">
                <span class="n360-denied-kicker">Control de acceso</span>
                <h1 id="deniedTitle">Acceso no permitido</h1>
                <p>Tu usuario no tiene permiso para ingresar a esta interfaz. Si necesitas acceso, solicita la habilitacion a un administrador.</p>
            </div>

            <dl class="n360-denied-meta">
                <div><dt>Usuario</dt><dd><?= n360_np_h($userName) ?></dd></div>
                <div><dt>Rol</dt><dd><?= n360_np_h($role) ?></dd></div>
                <div><dt>Vista solicitada</dt><dd><?= n360_np_h($requestedPath) ?></dd></div>
            </dl>

            <div class="n360-denied-actions">
                <a href="<?= n360_np_h(n360_base_url('index.php')) ?>" class="n360-denied-btn n360-denied-btn--primary"><i class="bi bi-house-door-fill" aria-hidden="true"></i><span>Ir al panel</span></a>
                <button type="button" class="n360-denied-btn n360-denied-btn--ghost" onclick="history.length > 1 ? history.back() : location.href='<?= n360_np_h(n360_base_url('index.php')) ?>'"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Volver</span></button>
                <?php if (!$isLogged): ?>
                    <a href="<?= n360_np_h(n360_base_url('login/login.php')) ?>" class="n360-denied-btn n360-denied-btn--ghost"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span>Iniciar sesion</span></a>
                <?php endif; ?>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>