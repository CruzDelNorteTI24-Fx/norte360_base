<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');

require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
require_once __DIR__ . '/../layout/quick_scan_n360.php';
require_once __DIR__ . '/../layout/bus_lookup_n360.php';

if (!function_exists('n360_puede_modulo') || !n360_puede_modulo(12)) {
    header('Location: ../login/none_permisos.php?vista=Cotizaciones%20y%20requerimientos');
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

$isAdminReq24 = function_exists('n360_is_admin') && n360_is_admin();
if (empty($_SESSION['requersen24_csrf'])) {
    $_SESSION['requersen24_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['requersen24_csrf'];

// Datos del usuario que GENERA el PDF (no del usuario que registró el requerimiento).
$pdfUserData = function_exists('n360_header_user_data')
    ? n360_header_user_data()
    : [
        'display_name' => (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario'),
        'dni' => (string)($_SESSION['DNI'] ?? ''),
    ];
$pdfUserName = trim((string)($pdfUserData['display_name'] ?? $_SESSION['usuario'] ?? 'Usuario'));
$pdfUserDni = trim((string)($pdfUserData['dni'] ?? $_SESSION['DNI'] ?? ''));
$rucEmpresa = '20403002101';

function req24_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function req24_date_filter(?string $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function req24_money($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return 'S/ ' . number_format((float)$value, 2);
}

function req24_state_meta(string $state): array
{
    switch ($state) {
        case 'REVISADO':
            return ['label' => 'Revisado', 'class' => 'is-info', 'icon' => 'bi-check2-circle'];
        case 'CORREGIDO':
            return ['label' => 'Corregido', 'class' => 'is-warn', 'icon' => 'bi-pencil-square'];
        case 'APROBADO':
            return ['label' => 'Aprobado', 'class' => 'is-ok', 'icon' => 'bi-patch-check'];
        case 'ANULADO':
            return ['label' => 'Anulado', 'class' => 'is-danger', 'icon' => 'bi-x-octagon'];
        default:
            return ['label' => 'Pendiente', 'class' => 'is-pending', 'icon' => 'bi-hourglass-split'];
    }
}

function req24_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function req24_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    req24_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

$filters = [
    'buscar' => trim((string)($_GET['buscar'] ?? '')),
    'estado' => trim((string)($_GET['estado'] ?? 'TODOS')),
    'desde' => req24_date_filter($_GET['desde'] ?? ''),
    'hasta' => req24_date_filter($_GET['hasta'] ?? ''),
];

$allowedStates = ['PENDIENTE', 'REVISADO', 'CORREGIDO', 'APROBADO', 'ANULADO'];
$where = [];
$types = '';
$params = [];

if ($filters['buscar'] !== '') {
    $like = '%' . $filters['buscar'] . '%';
    $where[] = "(
        COALESCE(r.clm_requersen24_CODIGO_INTERNO, '') COLLATE utf8mb4_unicode_ci LIKE CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci OR
        COALESCE(r.clm_requersen24_COTIZACION, '') COLLATE utf8mb4_unicode_ci LIKE CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci OR
        COALESCE(r.clm_requersen24_SOLICITANTE, '') COLLATE utf8mb4_unicode_ci LIKE CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci OR
        COALESCE(r.clm_requersen24_AREA, '') COLLATE utf8mb4_unicode_ci LIKE CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci OR
        COALESCE(r.clm_requersen24_requerimiento_codigo, '') COLLATE utf8mb4_unicode_ci LIKE CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci OR
        COALESCE(r.clm_requersen24_requerimiento_name, '') COLLATE utf8mb4_unicode_ci LIKE CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci
    )";
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if (in_array($filters['estado'], $allowedStates, true)) {
    $where[] = 'r.clm_requersen24_estado = ?';
    $types .= 's';
    $params[] = $filters['estado'];
}

if ($filters['desde'] !== '') {
    $where[] = 'DATE(r.clm_requersen24_fechahora_registro) >= ?';
    $types .= 's';
    $params[] = $filters['desde'];
}

if ($filters['hasta'] !== '') {
    $where[] = 'DATE(r.clm_requersen24_fechahora_registro) <= ?';
    $types .= 's';
    $params[] = $filters['hasta'];
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$rows = [];
$pageError = '';

try {
    $rows = req24_fetch_all(
        $conn,
        "SELECT
            r.*,
            COALESCE(ur.nombre, ur.usuario, CONCAT('Usuario ', r.clm_requersen24_user_registro)) AS usuario_registro_nombre,
            COALESCE(uu.nombre, uu.usuario, CONCAT('Usuario ', r.clm_requersen24_user_update)) AS usuario_update_nombre
        FROM tb_requersen24 r
        LEFT JOIN tb_usuarios ur ON ur.id_usuario = r.clm_requersen24_user_registro
        LEFT JOIN tb_usuarios uu ON uu.id_usuario = r.clm_requersen24_user_update
        $whereSql
        ORDER BY r.clm_requersen24_fechahora_registro DESC, r.clm_requersen24_id DESC
        LIMIT 500",
        $types,
        $params
    );
} catch (Throwable $e) {
    $pageError = 'No se pudo cargar la informacion de cotizaciones y requerimientos.';
    error_log('[requersen24] ' . $e->getMessage());
}

$stats = [
    'total' => count($rows),
    'pendientes' => 0,
    'con_requerimiento' => 0,
    'aprobados' => 0,
    'monto' => 0.0,
];

foreach ($rows as $row) {
    if (($row['clm_requersen24_estado'] ?? '') === 'PENDIENTE') {
        $stats['pendientes']++;
    }
    if (trim((string)($row['clm_requersen24_requerimiento_codigo'] ?? '')) !== '' || trim((string)($row['clm_requersen24_requerimiento_name'] ?? '')) !== '') {
        $stats['con_requerimiento']++;
    }
    if (($row['clm_requersen24_estado'] ?? '') === 'APROBADO') {
        $stats['aprobados']++;
    }
    $stats['monto'] += (float)($row['clm_requersen24_requerimiento_monto'] ?? 0);
}

$estadoOptions = [
    'PENDIENTE' => 'Pendiente',
    'REVISADO' => 'Revisado',
    'CORREGIDO' => 'Corregido',
    'APROBADO' => 'Aprobado',
    'ANULADO' => 'Anulado',
];

$areaOptions = [
    'ADMINISTRACION' => 'Administracion',
    'CONTABILIDAD' => 'Contabilidad',
    'FINANZAS' => 'Finanzas',
    'OPERACIONES' => 'Operaciones',
    'FLOTA' => 'Flota',
    'MANTENIMIENTO' => 'Mantenimiento',
    'ALMACEN' => 'Almacen',
    'COMBUSTIBLE' => 'Combustible',
    'RECURSOS_HUMANOS' => 'Recursos Humanos',
    'CALIDAD' => 'Calidad',
    'PEAJES' => 'Peajes',
    'ENCOMIENDAS' => 'Encomiendas',
    'SISTEMAS' => 'Sistemas',
    'GERENCIA' => 'Gerencia',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotizaciones y requerimientos | Norte360</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/loader_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/dialog_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/barcode_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/contabilidad_requersen24_n360.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Cotizaciones y requerimientos', 'subtitle' => 'Contabilidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner req24-page"
         data-api="<?php echo req24_h(n360_base_url('01_contabilidad/requerimientos_cotizaciones_api.php')); ?>"
         data-logo="<?php echo req24_h(n360_asset('img/completo.png')); ?>"
         data-pdf-user="<?php echo req24_h($pdfUserName); ?>"
         data-pdf-dni="<?php echo req24_h($pdfUserDni); ?>"
         data-ruc="<?php echo req24_h($rucEmpresa); ?>"
         data-csrf="<?php echo req24_h($csrfToken); ?>"
         data-admin="<?php echo $isAdminReq24 ? '1' : '0'; ?>">
        <?php n360_render_content_separator('top'); ?>

    <section class="req24-hero">
        <div>
            <p class="req24-eyebrow"><i class="bi bi-calculator"></i> Contabilidad - seguimiento operativo</p>
            <h1>Cotizaciones y requerimientos</h1>
        </div>
        <div class="req24-hero-actions">
            <?php if ($isAdminReq24): ?>
                <button type="button" class="btn btn-light req24-new-quote">
                    <i class="bi bi-plus-lg"></i> Nueva cotizacion
                </button>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($pageError !== ''): ?>
        <div class="alert alert-danger req24-alert"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo req24_h($pageError); ?></div>
    <?php endif; ?>

    <section class="req24-kpis">
        <article><span>Registros</span><strong><?php echo (int)$stats['total']; ?></strong></article>
        <article><span>Pendientes</span><strong><?php echo (int)$stats['pendientes']; ?></strong></article>
        <article><span>Con requerimiento</span><strong><?php echo (int)$stats['con_requerimiento']; ?></strong></article>
        <article><span>Aprobados</span><strong><?php echo (int)$stats['aprobados']; ?></strong></article>
    </section>

    <form class="req24-filters" method="get">
        <label>
            <span>Buscar</span>
            <div class="req24-input-icon">
                <i class="bi bi-search"></i>
                <input type="text" name="buscar" value="<?php echo req24_h($filters['buscar']); ?>" placeholder="Codigo interno, cotizacion, solicitante o requerimiento...">
            </div>
        </label>
        <label>
            <span>Estado</span>
            <select name="estado">
                <option value="TODOS">Todos</option>
                <?php foreach ($estadoOptions as $value => $label): ?>
                    <option value="<?php echo req24_h($value); ?>" <?php echo $filters['estado'] === $value ? 'selected' : ''; ?>><?php echo req24_h($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Desde</span>
            <input type="date" name="desde" value="<?php echo req24_h($filters['desde']); ?>">
        </label>
        <label>
            <span>Hasta</span>
            <input type="date" name="hasta" value="<?php echo req24_h($filters['hasta']); ?>">
        </label>
        <div class="req24-filter-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
            <a class="btn btn-outline-primary" href="requerimientos_cotizaciones.php"><i class="bi bi-x-circle"></i> Limpiar</a>
        </div>
    </form>

    <section class="req24-table-card">
        <div class="req24-card-head">
            <div>
                <h2>Cotizaciones</h2>
            </div>
            <span><?php echo (int)$stats['total']; ?> registros</span>
        </div>
        <div class="table-responsive">
            <table class="table req24-table align-middle">
                <thead>
                <tr>
                    <th>Cotizacion</th>
                    <th>Solicitante</th>
                    <th>Estado</th>
                    <th>Requerimiento</th>
                    <th>Actualizacion</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="req24-empty">No hay registros para los filtros actuales.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $meta = req24_state_meta((string)$row['clm_requersen24_estado']);
                    $payload = [
                        'id' => (int)$row['clm_requersen24_id'],
                        'codigo_interno' => (string)$row['clm_requersen24_CODIGO_INTERNO'],
                        'cotizacion' => (string)($row['clm_requersen24_COTIZACION'] ?? ''),
                        'solicitante' => (string)($row['clm_requersen24_SOLICITANTE'] ?? ''),
                        'cargo' => (string)($row['clm_requersen24_CARGO'] ?? ''),
                        'area' => (string)($row['clm_requersen24_AREA'] ?? ''),
                        'comentario' => (string)($row['clm_requersen24_comentario'] ?? ''),
                        'estado' => (string)$row['clm_requersen24_estado'],
                        'req_codigo' => (string)($row['clm_requersen24_requerimiento_codigo'] ?? ''),
                        'req_name' => (string)($row['clm_requersen24_requerimiento_name'] ?? ''),
                        'req_monto' => (string)($row['clm_requersen24_requerimiento_monto'] ?? ''),
                        'req_comentario' => (string)($row['clm_requersen24_requerimiento_comentario'] ?? ''),
            'fecha_registro' => (string)($row['clm_requersen24_fechahora_registro'] ?? ''),
            'fecha_update' => (string)($row['clm_requersen24_datetime_update'] ?? ''),
            'usuario_registro' => (string)($row['usuario_registro_nombre'] ?? ''),
            'usuario_update' => (string)($row['usuario_update_nombre'] ?? ''),
                        'histor' => (string)($row['clm_requersen24_histor'] ?? ''),
                    ];
                    $hasRequirement = trim((string)($row['clm_requersen24_requerimiento_codigo'] ?? '')) !== ''
                        || trim((string)($row['clm_requersen24_requerimiento_name'] ?? '')) !== '';
                    $reqButtonClass = $hasRequirement ? 'btn-outline-warning req24-req-btn req24-req-btn--edit' : 'btn-primary req24-req-btn';
                    $reqButtonIcon = $hasRequirement ? 'bi-pencil-square' : 'bi-journal-plus';
                    $reqButtonLabel = $hasRequirement ? 'Editar req.' : 'Requerimiento';
                    $rowJson = req24_h(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <tr>
                        <td>
                            <strong class="req24-code"><?php echo req24_h($row['clm_requersen24_CODIGO_INTERNO']); ?></strong>
                            <small><?php echo req24_h($row['clm_requersen24_COTIZACION'] ?: 'Sin cotizacion'); ?></small>
                        </td>
                        <td>
                            <strong><?php echo req24_h($row['clm_requersen24_SOLICITANTE'] ?: '-'); ?></strong>
                            <small><?php echo req24_h(trim(($row['clm_requersen24_CARGO'] ?? '') . ' / ' . ($row['clm_requersen24_AREA'] ?? ''), ' /')); ?></small>
                        </td>
                        <td>
                            <span class="req24-state <?php echo req24_h($meta['class']); ?>">
                                <i class="bi <?php echo req24_h($meta['icon']); ?>"></i> <?php echo req24_h($meta['label']); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo req24_h($row['clm_requersen24_requerimiento_codigo'] ?: 'Pendiente'); ?></strong>
                            <small><?php echo req24_h($row['clm_requersen24_requerimiento_name'] ?: 'Sin detalle de requerimiento'); ?></small>
                            <span class="req24-money"><?php echo req24_h(req24_money($row['clm_requersen24_requerimiento_monto'] ?? null)); ?></span>
                        </td>
                        <td>
                            <strong><?php echo req24_h($row['usuario_update_nombre'] ?: $row['usuario_registro_nombre']); ?></strong>
                            <small><?php echo req24_h($row['clm_requersen24_datetime_update'] ?: $row['clm_requersen24_fechahora_registro']); ?></small>
                        </td>
                        <td>
                            <div class="req24-actions">
                                <button type="button" class="btn btn-sm btn-outline-primary req24-view" data-row="<?php echo $rowJson; ?>">
                                    <i class="bi bi-eye"></i> Ver
                                </button>
                                <button type="button" class="btn btn-sm <?php echo req24_h($reqButtonClass); ?> req24-edit-req" data-row="<?php echo $rowJson; ?>">
                                    <i class="bi <?php echo req24_h($reqButtonIcon); ?>"></i> <?php echo req24_h($reqButtonLabel); ?>
                                </button>
                                <?php if ($isAdminReq24): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary req24-edit-quote" data-row="<?php echo $rowJson; ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary req24-history" data-row="<?php echo $rowJson; ?>">
                                    <i class="bi bi-clock-history"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<div class="modal fade" id="req24QuoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content req24-modal" id="req24QuoteForm" autocomplete="off">
            <input type="hidden" name="id">
            <div class="modal-header">
                <div>
                    <span>Solo administradores</span>
                    <h5 class="modal-title">Cotizacion</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body req24-form-grid">
                    <input type="hidden" name="codigo_interno">
                    <input type="hidden" name="estado" value="PENDIENTE">
                    <label><span>Cotizacion</span><input name="cotizacion" maxlength="255" placeholder="Ej. COT-034 / proveedor / servicio"></label>
                    <label><span>Solicitante</span><input name="solicitante" maxlength="255" placeholder="Nombre del solicitante"></label>
                    <label><span>Cargo</span><input name="cargo" maxlength="255" placeholder="Cargo o puesto"></label>
                    <label><span>Area</span><select name="area"><option value="">Seleccionar area operativa</option><?php foreach ($areaOptions as $value => $label): ?><option value="<?php echo req24_h($value); ?>"><?php echo req24_h($label); ?></option><?php endforeach; ?></select></label>
                    <label class="req24-span-2"><span>Comentario</span><textarea name="comentario" rows="3" placeholder="Contexto breve de la cotizacion o servicio solicitado"></textarea></label>
                </div>
                <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar cotizacion</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="req24RequirementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content req24-modal" id="req24RequirementForm" autocomplete="off">
            <input type="hidden" name="id">
            <div class="modal-header">
                <div>
                    <span>Gestion operativa</span>
                    <h5 class="modal-title">Requerimiento</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body req24-form-grid">
                <label><span>Codigo de requerimiento</span><input name="requerimiento_codigo" maxlength="255" placeholder="Ej. REQ-OC-001 / OC-2026-..."></label>
                <label><span>Nombre / detalle</span><input name="requerimiento_name" maxlength="255" placeholder="Descripcion clara del requerimiento"></label>
                <label><span>Monto</span><input name="requerimiento_monto" type="number" min="0" step="0.0001" placeholder="0.00"></label>
                <label><span>Estado</span><select name="estado"><?php foreach ($estadoOptions as $value => $label): ?><option value="<?php echo req24_h($value); ?>"><?php echo req24_h($label); ?></option><?php endforeach; ?></select></label>
                <label class="req24-span-2"><span>Comentario del requerimiento</span><textarea name="requerimiento_comentario" rows="4" placeholder="Detalle, correcciones o sustento del requerimiento"></textarea></label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save2"></i> Guardar requerimiento</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="req24DetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content req24-modal">
            <div class="modal-header req24-detail-header">
                <div class="req24-modal-titleblock">
                    <span><i class="bi bi-search"></i> Consulta</span>
                    <h5 class="modal-title">Detalle de cotizacion</h5>
                </div>
                <div class="req24-detail-modal-actions">
                    <button type="button" class="btn btn-light btn-sm req24-detail-pdf-btn" id="req24DetailPdf">
                        <i class="bi bi-file-earmark-pdf"></i>
                        PDF nota
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
            </div>
            <div class="modal-body">
                <div id="req24DetailBody"></div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="req24HistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content req24-modal">
            <div class="modal-header">
                <div>
                    <span>Auditoria</span>
                    <h5 class="modal-title">Historial del registro</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="req24-history-list" id="req24HistoryList"></div>
            </div>
        </div>
    </div>
</div>

<?php n360_render_footer(); ?>
<?php n360_render_quick_scan(); ?>
<?php n360_render_bus_lookup(); ?>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/barcode_n360.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="<?= n360_asset('assets/js/contabilidad_requersen24_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/contabilidad_requersen24_nota_pdf_n360.js') ?>"></script>
</body>
</html>

