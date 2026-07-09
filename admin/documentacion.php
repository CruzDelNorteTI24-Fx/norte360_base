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

function n360_doc_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userName = trim((string)($_SESSION['usuario'] ?? $_SESSION['usuario'] ?? 'Usuario'));
$dni = trim((string)($_SESSION['DNI'] ?? 'No registrado'));
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Documentacion | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/loader_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/admin_documentacion_n360.css') ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>
<?php n360_render_header(['title' => 'Documentacion', 'subtitle' => 'Estandar PDF']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-doc-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-doc-hero">
            <div>
                <span class="admin-doc-kicker"><i class="bi bi-file-earmark-pdf-fill" aria-hidden="true"></i> Administracion - Sistema</span>
                <h1>Documentacion</h1>
                <p>Prueba visual del formato PDF Norte 360 para hoja A4 vertical y horizontal. Esta vista no consulta la base de datos; solo valida estetica, caratula, encabezado y pie.</p>
            </div>
            <div class="admin-doc-badge">
                <span>Estandar</span>
                <strong>A4 PDF</strong>
            </div>
        </section>

        <section class="admin-doc-grid">
            <article class="admin-doc-panel">
                <div class="admin-doc-panel__head">
                    <h2>Generadores de prueba</h2>
                    <span class="admin-doc-mini-badge"><span>Sin BD</span><strong>Demo</strong></span>
                </div>
                <div class="admin-doc-panel__body">
                    <div class="admin-doc-actions">
                        <button type="button" class="admin-doc-btn" id="btnPdfVertical">
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                            <span><strong>PDF A4 vertical</strong><span>Caratula + formato portrait</span></span>
                        </button>
                        <button type="button" class="admin-doc-btn" id="btnPdfHorizontal">
                            <i class="bi bi-file-earmark-ruled" aria-hidden="true"></i>
                            <span><strong>PDF A4 horizontal</strong><span>Caratula + formato landscape</span></span>
                        </button>
                    </div>

                    <div class="admin-doc-preview">
                        <div class="admin-doc-preview__paper" aria-hidden="true">
                            <div class="admin-doc-preview__header">EMPRESA DE TRANSPORTES CRUZ DEL NORTE S.A.C.<br>RUC: 20403002101</div>
                            <div class="admin-doc-preview__body"></div>
                            <div class="admin-doc-preview__footer"><span>Norte 360</span><span>N360-DOC-DEMO</span><span>Pagina 1</span></div>
                        </div>
                    </div>
                </div>
            </article>

            <aside class="admin-doc-panel">
                <div class="admin-doc-panel__head">
                    <h2>Formato migrado</h2>
                </div>
                <div class="admin-doc-panel__body">
                    <div class="admin-doc-list">
                        <div><span>Caratula</span><strong>Basada en _page_caratula y _caratula_generica</strong></div>
                        <div><span>Encabezado</span><strong>Logos, razon social, RUC y titulo central</strong></div>
                        <div><span>Pie de pagina</span><strong>Sistema, codigo, pagina, fecha, usuario y DNI</strong></div>
                        <div><span>Uso futuro</span><strong>N360PDF.createDocument(...) / N360PDF.generateDemo(...)</strong></div>
                    </div>
                </div>
            </aside>
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
const pdfConfig = {
    userName: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    dni: <?= json_encode($dni, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    logoLeft: <?= json_encode(n360_base_url('img/icon.png'), JSON_UNESCAPED_SLASHES) ?>,
    logoRight: <?= json_encode(n360_base_url('img/norte360_black.png'), JSON_UNESCAPED_SLASHES) ?>,
    coverImage: <?= json_encode(n360_base_url('img/caratula_historial_flota.png'), JSON_UNESCAPED_SLASHES) ?>
};

async function generarDemoPDF(orientation, button) {
    if (!window.N360PDF) {
        throw new Error('El estandar N360PDF no esta cargado.');
    }

    await window.N360PDF.generateDemo(Object.assign({}, pdfConfig, {
        orientation,
        cover: true
    }));
}

document.getElementById('btnPdfVertical').addEventListener('click', async function () {
    const button = this;
    try {
        await window.N360Loader.during(
            () => generarDemoPDF('portrait', button),
            {title: 'Generando PDF vertical...', detail: 'Preparando formato A4', button}
        );
    } catch (err) {
        alert(err.message || 'No se pudo generar el PDF vertical.');
    }
});

document.getElementById('btnPdfHorizontal').addEventListener('click', async function () {
    const button = this;
    try {
        await window.N360Loader.during(
            () => generarDemoPDF('landscape', button),
            {title: 'Generando PDF horizontal...', detail: 'Preparando formato A4', button}
        );
    } catch (err) {
        alert(err.message || 'No se pudo generar el PDF horizontal.');
    }
});
</script>
</body>
</html>
