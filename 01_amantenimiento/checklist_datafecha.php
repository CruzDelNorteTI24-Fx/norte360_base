<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

function cdf_tiene_acceso(): bool {
    if (($_SESSION['web_rol'] ?? '') === 'Admin' || ($_SESSION['permisos'] ?? null) === 'all') {
        return true;
    }

    $modulos = array_map('intval', (array)($_SESSION['permisos'] ?? []));
    $vistas = array_map('strval', (array)($_SESSION['vistas'] ?? []));

    return in_array(5, $modulos, true) && in_array('check-datafecha', $vistas, true);
}

if (!cdf_tiene_acceso()) {
    header('Location: ../login/none_permisos.php');
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

date_default_timezone_set('America/Lima');
mysqli_report(MYSQLI_REPORT_OFF);

function cdf_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function cdf_fecha_valida(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('America/Lima'));
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function cdf_fecha_ui($value): string {
    $value = trim((string)$value);
    if (!cdf_fecha_valida($value)) {
        return $value !== '' ? $value : '-';
    }

    [$year, $month, $day] = explode('-', $value);
    return $day . '/' . $month . '/' . $year;
}

function cdf_fecha_hora_ui($value): array {
    $value = trim((string)$value);
    if ($value === '') {
        return ['date' => 'Sin registro', 'time' => ''];
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return ['date' => $value, 'time' => ''];
    }

    return [
        'date' => date('d/m/Y', $timestamp),
        'time' => date('H:i:s', $timestamp),
    ];
}

function cdf_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function cdf_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    cdf_bind($stmt, $types, $params);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cdf_filter_query(array $filters, ?int $page = null): string {
    $params = [];
    if ($filters['q'] !== '') $params['q'] = $filters['q'];
    if ($filters['tipo'] > 0) $params['tipo'] = $filters['tipo'];
    if ($filters['desde'] !== '') $params['desde'] = $filters['desde'];
    if ($filters['hasta'] !== '') $params['hasta'] = $filters['hasta'];
    if ($page !== null && $page > 1) $params['page'] = $page;

    return $params ? '?' . http_build_query($params) : '';
}

function cdf_flash(string $type, string $message): void {
    $_SESSION['cdf_flash'] = ['type' => $type, 'message' => $message];
}

$request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$search = trim((string)($request['q'] ?? ''));
if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 80, 'UTF-8');
} else {
    $search = substr($search, 0, 80);
}

$filters = [
    'q' => $search,
    'tipo' => max(0, (int)($request['tipo'] ?? 0)),
    'desde' => cdf_fecha_valida((string)($request['desde'] ?? '')) ? (string)$request['desde'] : '',
    'hasta' => cdf_fecha_valida((string)($request['hasta'] ?? '')) ? (string)$request['hasta'] : '',
];
$page = max(1, (int)($request['page'] ?? 1));

if (empty($_SESSION['cdf_csrf'])) {
    $_SESSION['cdf_csrf'] = bin2hex(random_bytes(24));
}
$csrfToken = (string)$_SESSION['cdf_csrf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $receivedToken = (string)($_POST['csrf'] ?? '');
    if ($receivedToken === '' || !hash_equals($csrfToken, $receivedToken)) {
        cdf_flash('danger', 'No se pudo validar la solicitud. Actualiza la pagina e intenta nuevamente.');
        header('Location: checklist_datafecha.php' . cdf_filter_query($filters, $page));
        exit();
    }

    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $newDate = trim((string)($_POST['nueva_fecha'] ?? ''));

    try {
        if ((string)($_POST['action'] ?? '') !== 'cambiar_fecha') {
            throw new RuntimeException('Accion no permitida.');
        }
        if ($checklistId <= 0 || !cdf_fecha_valida($newDate)) {
            throw new RuntimeException('Selecciona una fecha valida.');
        }

        $stmtCurrent = $conn->prepare(
            'SELECT clm_checklist_corr, clm_checklist_fecha FROM tb_checklist_limpieza WHERE clm_checklist_id = ? LIMIT 1'
        );
        if (!$stmtCurrent) {
            throw new RuntimeException('No se pudo consultar el checklist.');
        }
        $stmtCurrent->bind_param('i', $checklistId);
        if (!$stmtCurrent->execute()) {
            $stmtCurrent->close();
            throw new RuntimeException('No se pudo consultar el checklist.');
        }
        $current = $stmtCurrent->get_result()->fetch_assoc();
        $stmtCurrent->close();

        if (!$current) {
            throw new RuntimeException('El checklist seleccionado no existe.');
        }

        $oldDate = (string)$current['clm_checklist_fecha'];
        $reference = trim((string)($current['clm_checklist_corr'] ?? ''));
        $reference = $reference !== '' ? $reference : '#' . $checklistId;

        if ($oldDate === $newDate) {
            cdf_flash('info', 'El checklist ' . $reference . ' ya tiene la fecha seleccionada.');
        } else {
            $stmtUpdate = $conn->prepare(
                'UPDATE tb_checklist_limpieza SET clm_checklist_fecha = ? WHERE clm_checklist_id = ?'
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('No se pudo preparar el cambio de fecha.');
            }
            $stmtUpdate->bind_param('si', $newDate, $checklistId);
            if (!$stmtUpdate->execute()) {
                $stmtUpdate->close();
                throw new RuntimeException('No se pudo cambiar la fecha del checklist.');
            }
            $updated = $stmtUpdate->affected_rows;
            $stmtUpdate->close();

            if ($updated !== 1) {
                throw new RuntimeException('No se encontro un registro para actualizar.');
            }

            cdf_flash('success', 'Fecha del checklist ' . $reference . ' actualizada de ' . cdf_fecha_ui($oldDate) . ' a ' . cdf_fecha_ui($newDate) . '.');
        }
    } catch (RuntimeException $error) {
        cdf_flash('danger', $error->getMessage());
    }

    header('Location: checklist_datafecha.php' . cdf_filter_query($filters, $page));
    exit();
}

$flash = $_SESSION['cdf_flash'] ?? null;
unset($_SESSION['cdf_flash']);

$types = cdf_fetch_all(
    $conn,
    'SELECT clm_checktip_id, clm_checktip_nombre FROM tb_checklist_tipos ORDER BY clm_checktip_nombre ASC'
) ?? [];

$selectedTypeName = 'Todos los tipos';
foreach ($types as $type) {
    if ((int)$type['clm_checktip_id'] === $filters['tipo']) {
        $selectedTypeName = (string)$type['clm_checktip_nombre'];
        break;
    }
}

$periodLabel = 'Todas las fechas';
if ($filters['desde'] !== '' && $filters['hasta'] !== '') {
    $periodLabel = cdf_fecha_ui($filters['desde']) . ' - ' . cdf_fecha_ui($filters['hasta']);
} elseif ($filters['desde'] !== '') {
    $periodLabel = 'Desde ' . cdf_fecha_ui($filters['desde']);
} elseif ($filters['hasta'] !== '') {
    $periodLabel = 'Hasta ' . cdf_fecha_ui($filters['hasta']);
}

$where = [];
$paramTypes = '';
$params = [];

if ($filters['q'] !== '') {
    $like = '%' . $filters['q'] . '%';
    $where[] = '(c.clm_checklist_corr LIKE ? OR p.clm_placas_placa LIKE ? OR p.clm_placas_BUS LIKE ? OR c.clm_checklist_responsable LIKE ? OR t.clm_checktip_nombre LIKE ?)';
    $paramTypes .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($filters['tipo'] > 0) {
    $where[] = 'c.clm_checklist_idtipo = ?';
    $paramTypes .= 'i';
    $params[] = $filters['tipo'];
}
if ($filters['desde'] !== '') {
    $where[] = 'c.clm_checklist_fecha >= ?';
    $paramTypes .= 's';
    $params[] = $filters['desde'];
}
if ($filters['hasta'] !== '') {
    $where[] = 'c.clm_checklist_fecha <= ?';
    $paramTypes .= 's';
    $params[] = $filters['hasta'];
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countRows = cdf_fetch_all(
    $conn,
    'SELECT COUNT(*) AS total
     FROM tb_checklist_limpieza c
     LEFT JOIN tb_placas p ON p.clm_placas_id = c.clm_checklist_id_bus
     LEFT JOIN tb_checklist_tipos t ON t.clm_checktip_id = c.clm_checklist_idtipo' . $whereSql,
    $paramTypes,
    $params
);

$pageError = '';
if ($countRows === null) {
    $pageError = 'No se pudo consultar la cantidad de checklists.';
    $totalRows = 0;
} else {
    $totalRows = (int)($countRows[0]['total'] ?? 0);
}

$perPage = 50;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rows = cdf_fetch_all(
    $conn,
    'SELECT c.clm_checklist_id,
            c.clm_checklist_corr,
            c.clm_checklist_idtipo,
            c.clm_checklist_fecha,
            c.clm_checklist_hora,
            c.clm_checklist_fechahoraregistro,
            c.clm_checklist_responsable,
            c.clm_checklist_estado,
            t.clm_checktip_nombre,
            p.clm_placas_placa,
            p.clm_placas_BUS,
            COALESCE(NULLIF(TRIM(u.nombre), \'\'), NULLIF(TRIM(u.usuario), \'\'), CONCAT(\'Usuario #\', c.clm_checklist_idpersonaregistra)) AS usuario_registra
     FROM tb_checklist_limpieza c
     LEFT JOIN tb_placas p ON p.clm_placas_id = c.clm_checklist_id_bus
     LEFT JOIN tb_checklist_tipos t ON t.clm_checktip_id = c.clm_checklist_idtipo
     LEFT JOIN tb_usuarios u ON u.id_usuario = c.clm_checklist_idpersonaregistra' . $whereSql . '
     ORDER BY c.clm_checklist_fecha DESC, c.clm_checklist_hora DESC, c.clm_checklist_id DESC
     LIMIT ' . $perPage . ' OFFSET ' . $offset,
    $paramTypes,
    $params
);

if ($rows === null) {
    $rows = [];
    $pageError = 'No se pudo cargar la relacion de checklists.';
}
$visibleRows = count($rows);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Fechas de checklist | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= cdf_h(n360_asset('img/norte360.png')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= cdf_h(n360_asset('assets/css/sidebar_n360.css')) ?>">
    <link rel="stylesheet" href="<?= cdf_h(n360_asset('assets/css/header_n360.css')) ?>">
    <link rel="stylesheet" href="<?= cdf_h(n360_asset('assets/css/main_n360.css')) ?>">
    <link rel="stylesheet" href="<?= cdf_h(n360_asset('assets/css/footer_n360.css')) ?>">
    <link rel="stylesheet" href="<?= cdf_h(n360_asset('assets/css/content_n360.css')) ?>">
    <link rel="stylesheet" href="<?= cdf_h(n360_asset('assets/css/checklist_datafecha_n360.css')) ?>?v=20260922-2">
</head>
<body>
<?php n360_render_header(['title' => 'Calidad', 'subtitle' => 'Fechas de checklist']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module" role="main">
    <div class="n360-main__inner cdf-page">
        <?php n360_render_content_separator('top'); ?>

        <header class="cdf-heading">
            <div class="cdf-heading__main">
                <span class="cdf-heading__icon" aria-hidden="true"><i class="bi bi-calendar2-week-fill"></i></span>
                <div>
                    <span class="cdf-heading__context">Calidad / Checklists</span>
                    <h1>Control de fechas</h1>
                </div>
            </div>
            <a href="lista_cheklist.php" class="cdf-btn cdf-btn--heading"><i class="bi bi-ui-checks-grid" aria-hidden="true"></i> Ver checklists</a>
        </header>

        <?php if (is_array($flash)): ?>
            <div class="cdf-alert cdf-alert--<?= cdf_h($flash['type'] ?? 'info') ?>" role="status">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span><?= cdf_h($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <div class="cdf-alert cdf-alert--danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span><?= cdf_h($pageError) ?></span>
            </div>
        <?php endif; ?>

        <section class="cdf-summary" aria-label="Resumen de la consulta">
            <div class="cdf-summary__item cdf-summary__item--total">
                <span class="cdf-summary__icon"><i class="bi bi-clipboard2-data-fill" aria-hidden="true"></i></span>
                <div><small>Resultados</small><strong><?= number_format($totalRows) ?></strong></div>
            </div>
            <div class="cdf-summary__item">
                <span class="cdf-summary__icon"><i class="bi bi-eye-fill" aria-hidden="true"></i></span>
                <div><small>En esta pagina</small><strong><?= number_format($visibleRows) ?></strong></div>
            </div>
            <div class="cdf-summary__item">
                <span class="cdf-summary__icon"><i class="bi bi-tags-fill" aria-hidden="true"></i></span>
                <div><small>Tipo</small><strong><?= cdf_h($selectedTypeName) ?></strong></div>
            </div>
            <div class="cdf-summary__item">
                <span class="cdf-summary__icon"><i class="bi bi-calendar-range-fill" aria-hidden="true"></i></span>
                <div><small>Periodo</small><strong><?= cdf_h($periodLabel) ?></strong></div>
            </div>
        </section>

        <section class="cdf-toolbar" aria-label="Filtros de checklist">
            <div class="cdf-toolbar__head">
                <div><i class="bi bi-sliders" aria-hidden="true"></i><strong>Filtros</strong></div>
                <?php if ($filters['q'] !== '' || $filters['tipo'] > 0 || $filters['desde'] !== '' || $filters['hasta'] !== ''): ?>
                    <span><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Filtros aplicados</span>
                <?php endif; ?>
            </div>
            <form method="get" class="cdf-filter-form">
                <label class="cdf-field cdf-field--search">
                    <span>Buscar</span>
                    <span class="cdf-input-icon">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" name="q" value="<?= cdf_h($filters['q']) ?>" placeholder="Correlativo, BUS, placa, tipo o responsable">
                    </span>
                </label>

                <label class="cdf-field">
                    <span>Tipo</span>
                    <select name="tipo">
                        <option value="0">Todos</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= (int)$type['clm_checktip_id'] ?>" <?= $filters['tipo'] === (int)$type['clm_checktip_id'] ? 'selected' : '' ?>>
                                <?= cdf_h($type['clm_checktip_nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="cdf-field">
                    <span>Desde</span>
                    <input type="date" name="desde" value="<?= cdf_h($filters['desde']) ?>">
                </label>

                <label class="cdf-field">
                    <span>Hasta</span>
                    <input type="date" name="hasta" value="<?= cdf_h($filters['hasta']) ?>">
                </label>

                <div class="cdf-filter-actions">
                    <button type="submit" class="cdf-btn cdf-btn--primary"><i class="bi bi-funnel-fill" aria-hidden="true"></i> Filtrar</button>
                    <a href="checklist_datafecha.php" class="cdf-icon-btn" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a>
                </div>
            </form>
        </section>

        <section class="cdf-data" aria-labelledby="cdfDataTitle">
            <div class="cdf-data__head">
                <div>
                    <h2 id="cdfDataTitle">Checklists registrados</h2>
                    <span>Ordenados por fecha mas reciente</span>
                </div>
                <div class="cdf-data__count"><strong><?= number_format($totalRows) ?></strong><span>registros</span></div>
            </div>

            <div class="cdf-table-wrap">
                <table class="cdf-table">
                    <thead>
                    <tr>
                        <th>Checklist</th>
                        <th>Unidad</th>
                        <th>Tipo</th>
                        <th>Fecha del checklist</th>
                        <th>Hora</th>
                        <th>Fecha/hora de registro</th>
                        <th>Responsable</th>
                        <th>Registrado por</th>
                        <th>Estado</th>
                        <th>Accion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" class="cdf-empty">No se encontraron checklists con los filtros seleccionados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $id = (int)$row['clm_checklist_id'];
                        $reference = trim((string)($row['clm_checklist_corr'] ?? ''));
                        $reference = $reference !== '' ? $reference : '#' . $id;
                        $bus = trim((string)($row['clm_placas_BUS'] ?? ''));
                        $plate = trim((string)($row['clm_placas_placa'] ?? ''));
                        $state = trim((string)($row['clm_checklist_estado'] ?? ''));
                        $typeId = (int)($row['clm_checklist_idtipo'] ?? 0);
                        $typeClass = $typeId >= 1 && $typeId <= 6 ? 'cdf-type--' . $typeId : 'cdf-type--default';
                        $stateKey = strtolower($state);
                        $stateLabel = $stateKey === 'h0' ? 'Historico' : ($stateKey === 'activo' ? 'Activo' : ($state !== '' ? $state : 'Sin estado'));
                        $stateClass = $stateKey === 'activo' ? 'cdf-state--active' : ($stateKey === 'h0' ? 'cdf-state--history' : '');
                        $registration = cdf_fecha_hora_ui($row['clm_checklist_fechahoraregistro'] ?? '');
                        ?>
                        <tr>
                            <td><strong><?= cdf_h($reference) ?></strong><small>ID <?= $id ?></small></td>
                            <td><strong><?= cdf_h($bus !== '' ? 'BUS ' . $bus : 'Sin BUS') ?></strong><small><?= cdf_h($plate !== '' ? $plate : 'Sin placa') ?></small></td>
                            <td><span class="cdf-type <?= cdf_h($typeClass) ?>"><?= cdf_h($row['clm_checktip_nombre'] ?? 'Sin tipo') ?></span></td>
                            <td><span class="cdf-date-pill"><i class="bi bi-calendar3" aria-hidden="true"></i><?= cdf_h(cdf_fecha_ui($row['clm_checklist_fecha'])) ?></span></td>
                            <td><span class="cdf-time"><i class="bi bi-clock" aria-hidden="true"></i><?= cdf_h($row['clm_checklist_hora'] ?? '-') ?></span></td>
                            <td class="<?= $registration['time'] === '' ? 'cdf-registration--empty' : '' ?>"><strong><?= cdf_h($registration['date']) ?></strong><?php if ($registration['time'] !== ''): ?><small><?= cdf_h($registration['time']) ?></small><?php endif; ?></td>
                            <td><?= cdf_h($row['clm_checklist_responsable'] ?: '-') ?></td>
                            <td><?= cdf_h($row['usuario_registra'] ?: '-') ?></td>
                            <td><span class="cdf-state <?= cdf_h($stateClass) ?>"><i class="bi <?= $stateKey === 'activo' ? 'bi-check-circle-fill' : 'bi-archive-fill' ?>" aria-hidden="true"></i><?= cdf_h($stateLabel) ?></span></td>
                            <td>
                                <button type="button"
                                        class="cdf-btn cdf-btn--change"
                                        data-bs-toggle="modal"
                                        data-bs-target="#cdfDateModal"
                                        data-cdf-id="<?= $id ?>"
                                        data-cdf-date="<?= cdf_h($row['clm_checklist_fecha']) ?>"
                                        data-cdf-date-display="<?= cdf_h(cdf_fecha_ui($row['clm_checklist_fecha'])) ?>"
                                        data-cdf-reference="<?= cdf_h($reference) ?>">
                                    <i class="bi bi-calendar2-event" aria-hidden="true"></i>
                                    Cambiar fecha
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="cdf-pagination" aria-label="Paginacion de checklists">
                    <a class="cdf-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= cdf_h($page > 1 ? cdf_filter_query($filters, $page - 1) : '#') ?>" aria-label="Pagina anterior">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    </a>
                    <span><?= $page ?> / <?= $totalPages ?></span>
                    <a class="cdf-page-link <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= cdf_h($page < $totalPages ? cdf_filter_query($filters, $page + 1) : '#') ?>" aria-label="Pagina siguiente">
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </nav>
            <?php endif; ?>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>

<div class="modal fade cdf-modal" id="cdfDateModal" tabindex="-1" aria-labelledby="cdfDateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="cdfDateForm" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= cdf_h($csrfToken) ?>">
                <input type="hidden" name="action" value="cambiar_fecha">
                <input type="hidden" name="checklist_id" value="">
                <input type="hidden" name="q" value="<?= cdf_h($filters['q']) ?>">
                <input type="hidden" name="tipo" value="<?= (int)$filters['tipo'] ?>">
                <input type="hidden" name="desde" value="<?= cdf_h($filters['desde']) ?>">
                <input type="hidden" name="hasta" value="<?= cdf_h($filters['hasta']) ?>">
                <input type="hidden" name="page" value="<?= $page ?>">

                <div class="cdf-modal__head">
                    <div>
                        <span><i class="bi bi-calendar2-event-fill" aria-hidden="true"></i> Checklist <strong data-cdf-modal-reference></strong></span>
                        <h2 id="cdfDateModalTitle">Cambiar fecha</h2>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="cdf-modal__body">
                    <div class="cdf-modal__current">
                        <span>Fecha actual</span>
                        <strong data-cdf-modal-current>-</strong>
                    </div>
                    <label class="cdf-field">
                        <span>Nueva fecha del checklist</span>
                        <input type="date" name="nueva_fecha" required>
                    </label>
                    <div class="cdf-modal__notice">
                        <i class="bi bi-lock-fill" aria-hidden="true"></i>
                        <span>La hora y la fecha/hora de registro se conservaran sin cambios.</span>
                    </div>
                </div>

                <div class="cdf-modal__foot">
                    <button type="button" class="cdf-btn" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="cdf-btn cdf-btn--primary" data-cdf-submit><i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar fecha</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= cdf_h(n360_asset('assets/js/header_n360.js')) ?>"></script>
<script src="<?= cdf_h(n360_asset('assets/js/sidebar_n360.js')) ?>"></script>
<script src="<?= cdf_h(n360_asset('assets/js/checklist_datafecha_n360.js')) ?>?v=20260922-2"></script>
</body>
</html>
