<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

$sessionPermisos = $_SESSION['permisos'] ?? [];
$isAdmin = ($_SESSION['web_rol'] ?? '') === 'Admin';
$hasAll = $sessionPermisos === 'all';
$permisos = $hasAll ? [] : array_map('intval', (array)$sessionPermisos);
$canAccess = $isAdmin || $hasAll || in_array(10, $permisos, true);

if (!$canAccess) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Fichas informativas'));
    exit;
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');
try {
    $conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
} catch (Throwable $e) {
    // La vista puede continuar aunque el servidor no acepte el cambio de cotejamiento.
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function fi_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function fi_asset_v(string $path): string
{
    $path = ltrim($path, '/');
    $full = __DIR__ . '/../' . $path;
    $version = is_file($full) ? filemtime($full) : time();
    return n360_asset($path) . '?v=' . $version;
}

function fi_fetch_all(mysqli $conn, string $sql): array
{
    $res = $conn->query($sql);
    if (!$res) {
        throw new RuntimeException($conn->error);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

$pageError = '';
$buses = [];
$drivers = [];

try {
    $buses = fi_fetch_all($conn, "
        SELECT
            p.clm_placas_id AS id,
            COALESCE(NULLIF(TRIM(p.clm_placas_BUS), ''), CONCAT('Unidad ', p.clm_placas_id)) AS nombre,
            COALESCE(NULLIF(TRIM(p.clm_placas_PLACA), ''), 'SIN-PLACA') AS placa,
            COALESCE(NULLIF(TRIM(p.`clm_placas_DUEÑO`), ''), '-') AS dueno,
            COALESCE(NULLIF(TRIM(p.`clm_placas_TIPO_VEHÍCULO`), ''), '-') AS tipo,
            COALESCE(NULLIF(TRIM(p.clm_placas_servicio), ''), '-') AS servicio,
            COALESCE(NULLIF(TRIM(p.clm_placas_ESTADO), ''), 'ACTIVO') AS estado,
            COALESCE(pv.clm_patr_capacidad_total, pv.clm_patr_capacidad_pasajeros, 0) AS capacidad_total,
            COALESCE(NULLIF(TRIM(pv.clm_patr_marca), ''), '') AS marca,
            COALESCE(NULLIF(TRIM(pv.clm_patr_modelo), ''), '') AS modelo
        FROM tb_placas p
        LEFT JOIN tb_patrimonio_vehicular pv
               ON pv.clm_patr_id_placa = p.clm_placas_id
        WHERE UPPER(TRIM(COALESCE(p.clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND UPPER(TRIM(COALESCE(p.`clm_placas_TIPO_VEHÍCULO`, ''))) IN ('BUS', 'CARGUERO')
        ORDER BY CAST(NULLIF(p.clm_placas_BUS, '') AS UNSIGNED), p.clm_placas_BUS, p.clm_placas_PLACA
    ");

    $driverWhere = "
        UPPER(TRIM(IFNULL(t.clm_tra_tipo_trabajador, ''))) = 'CONDUCTOR'
        AND UPPER(TRIM(IFNULL(t.clm_tra_contrato, ''))) = 'ACTIVO'
    ";

    $drivers = fi_fetch_all($conn, "
        SELECT
            t.clm_tra_id AS id,
            COALESCE(NULLIF(TRIM(t.clm_tra_nombres), ''), CONCAT('Conductor ', t.clm_tra_id)) AS conductor,
            COALESCE(NULLIF(TRIM(t.clm_tra_dni), ''), '') AS dni,
            COALESCE(NULLIF(TRIM(t.clm_tra_nlicenciaconducir), ''), 'Sin licencia') AS licencia
        FROM tb_trabajador t
        WHERE $driverWhere
        ORDER BY t.clm_tra_nombres ASC
    ");
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$config = [
    'buses' => array_map(static function (array $bus): array {
        return [
            'id' => (int)$bus['id'],
            'nombre' => (string)$bus['nombre'],
            'placa' => (string)$bus['placa'],
            'dueno' => (string)$bus['dueno'],
            'tipo' => (string)$bus['tipo'],
            'servicio' => (string)$bus['servicio'],
            'estado' => (string)$bus['estado'],
            'capacidad_total' => (int)$bus['capacidad_total'],
            'marca' => (string)$bus['marca'],
            'modelo' => (string)$bus['modelo'],
        ];
    }, $buses),
    'drivers' => array_map(static function (array $driver): array {
        $licencia = trim((string)$driver['licencia']);
        return [
            'id' => (int)$driver['id'],
            'conductor' => (string)$driver['conductor'],
            'dni' => (string)$driver['dni'],
            'licencia' => $licencia !== 'Sin licencia' ? $licencia : $licencia,
        ];
    }, $drivers),
    'logoUrl' => n360_asset('img/infologo2.png'),
    'busUrl' => n360_asset('img/IMG_3004.png'),
];

$defaultBusId = $buses[0]['id'] ?? '';
$defaultDriver1 = $drivers[0]['id'] ?? '';
$defaultDriver2 = $drivers[1]['id'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <title>Fichas informativas | Norte360</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= fi_asset_v('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= fi_asset_v('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= fi_asset_v('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= fi_asset_v('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= fi_asset_v('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= fi_asset_v('assets/css/flota_fichas_informativas_n360.css') ?>">
</head>
<body class="n360-body">
<?php n360_render_header(['title' => 'Flota', 'subtitle' => 'Fichas informativas']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module fi-page">
    <?php n360_render_content_separator('top'); ?>
    <div class="n360-main__inner fi-inner">
        <section class="fi-hero">
            <div>
                <span class="fi-eyebrow"><i class="bi bi-card-image"></i> Flota - fichas informativas</span>
                <h1>Generador de fichas de unidad</h1>
            </div>
            <div class="fi-hero-actions">
                <button type="button" class="fi-btn fi-btn--ghost" id="fiPreviewBtn">
                    <i class="bi bi-eye"></i> Previsualizar
                </button>
                <button type="button" class="fi-btn fi-btn--primary" id="fiDownloadBtn">
                    <i class="bi bi-download"></i> Descargar PNG
                </button>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="alert alert-danger fi-alert" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                No se pudo cargar la informacion base: <?= fi_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="fi-shell">
            <article class="fi-panel fi-panel--form">
                <div class="fi-panel-title">
                    <div>
                        <span>Configuracion</span>
                        <h2>Datos de la ficha</h2>
                    </div>
                    <i class="bi bi-sliders"></i>
                </div>

                <div class="fi-form-grid">
                    <label class="fi-field fi-field--wide">
                        <span>Unidad / bus</span>
                        <select id="fiBusSelect" class="fi-select">
                            <option value="">Seleccionar unidad</option>
                            <?php foreach ($buses as $bus): ?>
                                <option value="<?= (int)$bus['id'] ?>" <?= (string)$defaultBusId === (string)$bus['id'] ? 'selected' : '' ?>>
                                    <?= fi_h($bus['nombre'] . ' - ' . $bus['placa'] . ' | ' . $bus['servicio']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="fi-field">
                        <span>Primer conductor</span>
                        <select id="fiDriverOneSelect" class="fi-select">
                            <option value="">Seleccionar conductor</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= (int)$driver['id'] ?>" <?= (string)$defaultDriver1 === (string)$driver['id'] ? 'selected' : '' ?>>
                                    <?= fi_h($driver['conductor'] . ($driver['dni'] !== '' ? ' (' . $driver['dni'] . ')' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="fi-field">
                        <span>Segundo conductor</span>
                        <select id="fiDriverTwoSelect" class="fi-select">
                            <option value="">Seleccionar conductor</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= (int)$driver['id'] ?>" <?= (string)$defaultDriver2 === (string)$driver['id'] ? 'selected' : '' ?>>
                                    <?= fi_h($driver['conductor'] . ($driver['dni'] !== '' ? ' (' . $driver['dni'] . ')' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="fi-summary-grid" id="fiSummaryGrid">
                    <div class="fi-summary-card">
                        <span>Unidad</span>
                        <strong id="fiSummaryBus">Sin seleccionar</strong>
                    </div>
                    <div class="fi-summary-card">
                        <span>Capacidad</span>
                        <strong id="fiSummaryCapacity">-</strong>
                    </div>
                    <div class="fi-summary-card">
                        <span>Conductor 1</span>
                        <strong id="fiSummaryDriverOne">Sin seleccionar</strong>
                    </div>
                    <div class="fi-summary-card">
                        <span>Conductor 2</span>
                        <strong id="fiSummaryDriverTwo">Sin seleccionar</strong>
                    </div>
                </div>

                <div class="fi-status" id="fiStatus" role="status" aria-live="polite">
                    <i class="bi bi-info-circle"></i>
                    <span>Selecciona unidad y conductores para generar la ficha.</span>
                </div>
            </article>

            <article class="fi-panel fi-panel--preview">
                <div class="fi-panel-title">
                    <div>
                        <span>Previsualizacion</span>
                        <h2>Imagen generada</h2>
                    </div>
                    <i class="bi bi-aspect-ratio"></i>
                </div>
                <div class="fi-preview-stage" id="fiPreviewStage">
                    <div class="fi-empty-preview">
                        <i class="bi bi-file-earmark-image"></i>
                        <strong>Ficha pendiente</strong>
                        <span>La previsualizacion aparecera aqui antes de descargar.</span>
                    </div>
                </div>
            </article>
        </section>
    </div>
    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php n360_render_footer(); ?>

<script>
window.N360_FICHAS_INFORMATIVAS = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= fi_asset_v('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= fi_asset_v('assets/js/bus_lookup_image_n360.js') ?>"></script>
<script src="<?= fi_asset_v('assets/js/flota_fichas_informativas_n360.js') ?>"></script>
</body>
</html>