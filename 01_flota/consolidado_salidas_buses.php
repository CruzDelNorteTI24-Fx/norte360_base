<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

if (!n360_puede_modulo(10) || (!n360_puede_vista('f-consalbus') && !n360_puede_vista('f-proghist'))) {
    header("Location: ../login/none_permisos.php");
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

mysqli_report(MYSQLI_REPORT_OFF);

function csb_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csb_uid(): int {
    if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
        return (int)$_SESSION['id_usuario'];
    }
    if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
        return (int)$_SESSION['web_id_usuario'];
    }
    return 1;
}

function csb_json(bool $ok, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function csb_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function csb_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function csb_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    }
    csb_bind($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error ?: 'No se pudo ejecutar la consulta.');
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function csb_valid_date($value, string $fallback): string {
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

function csb_date_label(?string $value, string $format = 'd/m/Y'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : $value;
}

function csb_hora_label(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('H:i', $time) : substr($value, 0, 5);
}

function csb_estado_class(string $estado): string {
    $estado = strtoupper(trim($estado));
    if ($estado === 'VALIDADO') {
        return 'csb-status--ok';
    }
    if ($estado === 'OBSERVADO') {
        return 'csb-status--warn';
    }
    if ($estado === 'CORREGIDO') {
        return 'csb-status--info';
    }
    return 'csb-status--pending';
}

if (empty($_SESSION['csb_token'])) {
    $_SESSION['csb_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csb_token'];
$tableReady = isset($conn) && $conn instanceof mysqli && csb_table_exists($conn, 'tb_progbuses_salida_consolidado');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        csb_json(false, [], 'Primero crea la tabla del consolidado.', 400);
    }
    if (!hash_equals($csrfToken, (string)($_POST['csrf'] ?? ''))) {
        csb_json(false, [], 'Sesion invalida. Actualiza la pagina.', 419);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'update_revision') {
        csb_json(false, [], 'Accion no reconocida.', 400);
    }

    $id = (int)($_POST['id'] ?? 0);
    $estado = strtoupper(trim((string)($_POST['estado'] ?? 'PENDIENTE')));
    $comentario = trim((string)($_POST['comentario'] ?? ''));
    $correccion = trim((string)($_POST['correccion'] ?? ''));
    $permitidos = ['PENDIENTE', 'VALIDADO', 'OBSERVADO', 'CORREGIDO'];

    if ($id <= 0 || !in_array($estado, $permitidos, true)) {
        csb_json(false, [], 'Datos incompletos para guardar.', 422);
    }

    $uid = csb_uid();
    $stmt = $conn->prepare("
        UPDATE tb_progbuses_salida_consolidado
           SET clm_salprog_revision_estado = ?,
               clm_salprog_comentario_revision = ?,
               clm_salprog_correccion = ?,
               clm_salprog_usuario_revision = ?,
               clm_salprog_datetime_revision = NOW()
         WHERE clm_salprog_id = ?
         LIMIT 1
    ");
    if (!$stmt) {
        csb_json(false, [], $conn->error ?: 'No se pudo preparar la actualizacion.', 500);
    }
    $stmt->bind_param('sssii', $estado, $comentario, $correccion, $uid, $id);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        csb_json(false, [], $error ?: 'No se pudo guardar.', 500);
    }

    csb_json(true, [
        'estado' => $estado,
        'clase' => csb_estado_class($estado),
        'actualizado' => date('d/m/Y H:i'),
    ], 'Cambios guardados.');
}

$defaultDate = date('Y-m-d', strtotime('-1 day'));
$fechaOperativa = csb_valid_date($_GET['fecha_operativa'] ?? '', $defaultDate);
$revision = strtoupper(trim((string)($_GET['revision'] ?? 'TODOS')));
$buscar = trim((string)($_GET['buscar'] ?? ''));
$revisionPermitidas = ['TODOS', 'PENDIENTE', 'VALIDADO', 'OBSERVADO', 'CORREGIDO'];
if (!in_array($revision, $revisionPermitidas, true)) {
    $revision = 'TODOS';
}

$rows = [];
$pageError = '';
$ultimoCierre = null;
$rowsForDateTotal = 0;
$kpis = [
    'registros' => 0,
    'unidades' => 0,
    'conductores' => 0,
    'pendientes' => 0,
    'observados' => 0,
    'validados' => 0,
    'corregidos' => 0,
];

if ($tableReady) {
    try {
        $dateTotalRows = csb_fetch_all($conn, "
            SELECT COUNT(*) AS total
            FROM tb_progbuses_salida_consolidado
            WHERE clm_salprog_fecha_operativa = ?
        ", 's', [$fechaOperativa]);
        $rowsForDateTotal = (int)($dateTotalRows[0]['total'] ?? 0);

        $where = ["clm_salprog_fecha_operativa = ?"];
        $types = 's';
        $params = [$fechaOperativa];

        if ($revision !== 'TODOS') {
            $where[] = "clm_salprog_revision_estado = ?";
            $types .= 's';
            $params[] = $revision;
        }

        if ($buscar !== '') {
            $like = '%' . $buscar . '%';
            $where[] = "(
                clm_salprog_bus LIKE ?
                OR clm_salprog_placa LIKE ?
                OR clm_salprog_servicio LIKE ?
                OR clm_salprog_origen LIKE ?
                OR clm_salprog_destino LIKE ?
                OR clm_salprog_ruta_texto LIKE ?
                OR clm_salprog_conductores_texto LIKE ?
                OR clm_salprog_comentario_horario LIKE ?
                OR clm_salprog_comentario_revision LIKE ?
                OR clm_salprog_correccion LIKE ?
            )";
            $types .= 'ssssssssss';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $rows = csb_fetch_all($conn, "
            SELECT *
            FROM tb_progbuses_salida_consolidado
            WHERE " . implode(' AND ', $where) . "
            ORDER BY clm_salprog_hora_orden ASC, clm_salprog_horasalida ASC, clm_salprog_bus ASC, clm_salprog_id ASC
        ", $types, $params);

        if (csb_table_exists($conn, 'tb_progbuses_cierre_operativo')) {
            $cierreRows = csb_fetch_all($conn, "
                SELECT *
                FROM tb_progbuses_cierre_operativo
                WHERE clm_cierre_fecha = ?
                ORDER BY clm_cierre_id DESC
                LIMIT 1
            ", 's', [$fechaOperativa]);
            $ultimoCierre = $cierreRows[0] ?? null;
        }
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$placas = [];
$conductoresSet = [];
foreach ($rows as $row) {
    $kpis['registros']++;
    $placaId = (int)($row['clm_salprog_idplaca'] ?? 0);
    if ($placaId > 0) {
        $placas[$placaId] = true;
    }

    $estadoRow = strtoupper((string)($row['clm_salprog_revision_estado'] ?? 'PENDIENTE'));
    if ($estadoRow === 'VALIDADO') $kpis['validados']++;
    elseif ($estadoRow === 'OBSERVADO') $kpis['observados']++;
    elseif ($estadoRow === 'CORREGIDO') $kpis['corregidos']++;
    else $kpis['pendientes']++;

    $hasConductor = false;
    $conductoresJson = json_decode((string)($row['clm_salprog_conductores_json'] ?? '[]'), true);
    if (is_array($conductoresJson)) {
        foreach ($conductoresJson as $conductor) {
            $key = (int)($conductor['idconductor'] ?? 0);
            if ($key > 0) {
                $conductoresSet[$key] = true;
                $hasConductor = true;
            }
        }
    }

    if (!$hasConductor) {
        $conductoresTexto = trim((string)($row['clm_salprog_conductores_texto'] ?? ''));
        if ($conductoresTexto !== '') {
            foreach (preg_split('/\s+\|\s+/', $conductoresTexto) as $conductorTexto) {
                $conductorTexto = trim((string)$conductorTexto);
                if ($conductorTexto !== '') {
                    $conductoresSet['txt:' . strtolower($conductorTexto)] = true;
                }
            }
        }
    }
}
$kpis['unidades'] = count($placas);
$kpis['conductores'] = count($conductoresSet);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flota | Consolidado de salidas</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/flota_consolidado_salidas_n360.css') ?>">
</head>
<body>
<?php n360_render_sidebar(); ?>
<?php n360_render_header(['title' => 'Flota', 'subtitle' => 'Consolidado operativo']); ?>

<div class="n360-main">
    <?php n360_render_content_separator('top'); ?>

    <main class="n360-content csb-page">
        <section class="csb-hero">
            <div>
                <span class="csb-eyebrow"><i class="bi bi-bus-front-fill"></i> Consolidado de salidas</span>
                <h1>Buses programados cerrados</h1>
                <p>Respaldo del cron diario antes de limpiar la pizarra. Filtra por fecha operativa y registra observaciones o correcciones.</p>
            </div>
            <div class="csb-hero-meta">
                <span><i class="bi bi-calendar2-check"></i> <?= csb_h(csb_date_label($fechaOperativa)) ?></span>
                <span><i class="bi bi-clock-history"></i> Cierre 04:59 Peru</span>
            </div>
        </section>

        <?php if (!$tableReady): ?>
            <div class="csb-alert csb-alert--warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Crea primero la tabla con el script <strong>database/tb_progbuses_salida_consolidado.sql</strong> y luego actualiza la rutina del cierre operativo.
            </div>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <div class="csb-alert csb-alert--danger">
                <i class="bi bi-x-octagon-fill"></i>
                <?= csb_h($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="csb-summary">
            <article><span>Registros</span><strong><?= number_format($kpis['registros']) ?></strong></article>
            <article><span>Unidades</span><strong><?= number_format($kpis['unidades']) ?></strong></article>
            <article><span>Conductores</span><strong><?= number_format($kpis['conductores']) ?></strong></article>
            <article><span>Pendientes</span><strong><?= number_format($kpis['pendientes']) ?></strong></article>
            <article><span>Observados</span><strong><?= number_format($kpis['observados']) ?></strong></article>
        </section>

        <section class="csb-filter">
            <form method="get" class="csb-filter-grid" autocomplete="off">
                <label>
                    <span>Fecha operativa</span>
                    <input type="date" name="fecha_operativa" value="<?= csb_h($fechaOperativa) ?>">
                </label>
                <label>
                    <span>Revision</span>
                    <select name="revision">
                        <?php foreach ($revisionPermitidas as $opcion): ?>
                            <option value="<?= csb_h($opcion) ?>" <?= $revision === $opcion ? 'selected' : '' ?>><?= csb_h($opcion) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="csb-filter-search">
                    <span>Buscar</span>
                    <input type="search" name="buscar" value="<?= csb_h($buscar) ?>" placeholder="Bus, placa, conductor, ruta, comentario...">
                </label>
                <div class="csb-filter-actions">
                    <button type="submit" class="csb-btn csb-btn--primary"><i class="bi bi-funnel"></i> Filtrar</button>
                    <a class="csb-btn csb-btn--soft" href="consolidado_salidas_buses.php"><i class="bi bi-x-circle"></i> Limpiar</a>
                </div>
            </form>
        </section>

        <section class="csb-close-info">
            <div>
                <span>Fecha cerrada</span>
                <strong><?= csb_h(csb_date_label($fechaOperativa)) ?></strong>
            </div>
            <div>
                <span>Ejecucion cron</span>
                <strong><?= $ultimoCierre ? csb_h(csb_date_label($ultimoCierre['clm_cierre_datetime'] ?? '', 'd/m/Y H:i')) : '-' ?></strong>
            </div>
            <div>
                <span>Retirados por rutina</span>
                <strong><?= $ultimoCierre ? number_format((int)($ultimoCierre['clm_cierre_total_retirados'] ?? 0)) : '-' ?></strong>
            </div>
            <div>
                <span>Estado cierre</span>
                <strong><?= $ultimoCierre ? csb_h($ultimoCierre['clm_cierre_estado'] ?? '-') : '-' ?></strong>
            </div>
        </section>

        <section class="csb-card">
            <div class="csb-card-head">
                <div>
                    <h2>Consolidado de salidas de buses programados</h2>
                    <p>Datos capturados antes de limpiar la pizarra; los comentarios se guardan en esta tabla auxiliar.</p>
                </div>
                <span><?= number_format(count($rows)) ?> registros</span>
            </div>

            <div class="csb-table-wrap">
                <table class="csb-table">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Unidad</th>
                            <th>Programacion</th>
                            <th>Conductores</th>
                            <th>Revision</th>
                            <th>Comentario / Correccion</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <?php
                                $emptyMessage = $rowsForDateTotal <= 0
                                    ? 'No hay consolidado para esta fecha operativa. Si es una fecha anterior a la implementacion, el cron aun no capturaba estos respaldos; si es posterior, probablemente no hubo unidades programadas al cierre.'
                                    : 'No hay registros para los filtros seleccionados.';
                            ?>
                            <tr>
                                <td colspan="7" class="csb-empty"><?= csb_h($emptyMessage) ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $id = (int)($row['clm_salprog_id'] ?? 0);
                                $estado = strtoupper((string)($row['clm_salprog_revision_estado'] ?? 'PENDIENTE'));
                                $busLabel = trim((string)($row['clm_salprog_bus'] ?? ''));
                                $placaLabel = trim((string)($row['clm_salprog_placa'] ?? ''));
                                $unidadLabel = $busLabel !== '' && $placaLabel !== '' ? "{$busLabel} ({$placaLabel})" : ($busLabel ?: ($placaLabel ?: '-'));
                            ?>
                            <tr data-csb-row="<?= $id ?>">
                                <td>
                                    <strong><?= csb_h(csb_hora_label($row['clm_salprog_horasalida'] ?? '')) ?></strong>
                                    <small>#<?= (int)($row['clm_salprog_progid'] ?? 0) ?></small>
                                </td>
                                <td>
                                    <strong><?= csb_h($unidadLabel) ?></strong>
                                    <small><?= csb_h($row['clm_salprog_servicio'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <strong><?= csb_h(($row['clm_salprog_origen'] ?? '-') . ' -> ' . ($row['clm_salprog_destino'] ?? '-')) ?></strong>
                                    <small><?= csb_h($row['clm_salprog_ruta_texto'] ?: 'Sin ruta adicional') ?></small>
                                    <?php if (trim((string)($row['clm_salprog_comentario_horario'] ?? '')) !== ''): ?>
                                        <em><?= csb_h($row['clm_salprog_comentario_horario']) ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= csb_h($row['clm_salprog_conductores_texto'] ?: 'Sin conductor asignado') ?></strong>
                                    <small>Asignacion capturada del modulo Conductores</small>
                                </td>
                                <td>
                                    <select data-csb-field="estado" aria-label="Estado revision">
                                        <?php foreach (['PENDIENTE', 'VALIDADO', 'OBSERVADO', 'CORREGIDO'] as $opcion): ?>
                                            <option value="<?= $opcion ?>" <?= $estado === $opcion ? 'selected' : '' ?>><?= $opcion ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="csb-status <?= csb_h(csb_estado_class($estado)) ?>" data-csb-status><?= csb_h($estado) ?></span>
                                </td>
                                <td>
                                    <textarea data-csb-field="comentario" rows="2" placeholder="Comentario de revision"><?= csb_h($row['clm_salprog_comentario_revision'] ?? '') ?></textarea>
                                    <textarea data-csb-field="correccion" rows="2" placeholder="Correccion aplicada o pendiente"><?= csb_h($row['clm_salprog_correccion'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <button type="button" class="csb-btn csb-btn--primary csb-btn--save" data-csb-save="<?= $id ?>">
                                        <i class="bi bi-save"></i> Guardar
                                    </button>
                                    <small data-csb-saved>
                                        <?= !empty($row['clm_salprog_datetime_revision']) ? csb_h(csb_date_label($row['clm_salprog_datetime_revision'], 'd/m/Y H:i')) : '' ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <?php n360_render_content_separator('bottom'); ?>
</div>

<script>
window.N360_CSB = {
    csrf: <?= json_encode($csrfToken) ?>,
    endpoint: 'consolidado_salidas_buses.php'
};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/flota_consolidado_salidas_n360.js') ?>"></script>
<?php n360_render_footer(); ?>
</body>
</html>
