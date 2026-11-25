<?php
require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}
if (($_SESSION['web_rol'] ?? '') !== 'Admin') {
    header('Location: ../login/none_permisos.php');
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/session_audit_n360.php';
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function n360_audit_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function n360_audit_filter(string $name): string
{
    return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
}

function n360_audit_date(string $raw, DateTimeImmutable $fallback): DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) return $fallback;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $fallback->getTimezone());
    return $date && $date->format('Y-m-d') === $raw ? $date : $fallback;
}

function n360_audit_get_rows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function n360_audit_country(string $ip): string
{
    static $loaded = false, $reader, $cache = [];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '—';
    if (isset($cache[$ip])) return $cache[$ip];
    try {
        if (!$loaded) {
            $loaded = true;
            $directory = n360_audit_directory() . '/geoip';
            if (is_readable($directory . '/GeoLite2-Country.mmdb') && is_readable($directory . '/vendor/autoload.php')) {
                require_once $directory . '/vendor/autoload.php';
                $reader = new \MaxMind\Db\Reader($directory . '/GeoLite2-Country.mmdb');
            }
        }
        $country = $reader?->get($ip)['country'] ?? [];
        return $cache[$ip] = $country['names']['es'] ?? $country['names']['en'] ?? $country['iso_code'] ?? '—';
    } catch (Throwable $ignored) {
        return $cache[$ip] = '—';
    }
}

$tabs = ['sesiones' => 'Sesiones', 'accesos' => 'Inicios de sesión', 'navegacion' => 'Navegación'];
$states = ['activa' => 'Activa', 'ausente' => 'Ausente', 'inactiva' => 'Inactiva', 'cerrada' => 'Cerrada'];
$results = ['success' => 'Exitoso', 'failed' => 'Fallido', 'blocked' => 'Bloqueado'];
$reasons = ['' => '—', 'csrf' => 'Verificación de solicitud', 'incompleto' => 'Datos incompletos',
    'bloqueado' => 'Límite de intentos', 'credenciales' => 'Credenciales rechazadas', 'error_interno' => 'Error de validación'];
$tab = n360_audit_filter('tab');
$tab = isset($tabs[$tab]) ? $tab : 'sesiones';
$status = n360_audit_filter('estado');
$status = isset(($tab === 'sesiones' ? $states : $results)[$status]) ? $status : '';
$username = n360_audit_text(n360_audit_filter('usuario'), 120);
$reference = strtolower(n360_audit_filter('sesion'));
$reference = preg_match('/^[a-f0-9]{16}$/D', $reference) ? $reference : '';
$zone = new DateTimeZone('America/Lima');
$today = new DateTimeImmutable('today', $zone);
$from = n360_audit_date(n360_audit_filter('desde'), $today->modify('-6 days'));
$until = n360_audit_date(n360_audit_filter('hasta'), $today);
if ($from > $until) [$from, $until] = [$until, $from];
$now = time();
$cutoff = $now - N360_AUDIT_RETENTION;
$active = $now - N360_AUDIT_ACTIVE;
$absent = $now - N360_AUDIT_ABSENT;
$cursorTime = filter_var(n360_audit_filter('antes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cursorId = filter_var(n360_audit_filter('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionsTab = $tab === 'sesiones';
$timeColumn = $sessionsTab ? 'last_seen' : 'occurred_at';
$filters = ['tab' => $tab, 'usuario' => $username, 'estado' => $status, 'sesion' => $reference];
if (!$sessionsTab) {
    $filters['desde'] = $from->format('Y-m-d');
    $filters['hasta'] = $until->format('Y-m-d');
}

$rows = [];
$stats = [];
$error = '';
$next = null;
try {
    $db = n360_audit_db();
    n360_audit_track_page();
    $stats = n360_audit_get_rows($db, '
        SELECT COUNT(DISTINCT CASE WHEN closed_at IS NULL AND last_seen >= ? THEN user_id END) AS users_online,
            SUM(CASE WHEN closed_at IS NULL AND last_seen >= ? THEN 1 ELSE 0 END) AS activa,
            SUM(CASE WHEN closed_at IS NULL AND last_seen < ? AND last_seen >= ? THEN 1 ELSE 0 END) AS ausente,
            SUM(CASE WHEN closed_at IS NULL AND last_seen < ? THEN 1 ELSE 0 END) AS inactiva,
            SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS cerrada
        FROM audit_sessions WHERE last_seen >= ?
    ', [$active, $active, $active, $absent, $absent, $cutoff])[0];

    $table = $sessionsTab ? 'audit_sessions' : 'audit_events';
    $conditions = ["{$timeColumn} >= ?"];
    $params = [$sessionsTab ? $cutoff : max($cutoff, $from->getTimestamp())];
    if (!$sessionsTab) {
        $conditions[] = 'occurred_at < ?';
        $params[] = $until->modify('+1 day')->getTimestamp();
        if ($tab === 'navegacion') {
            $conditions[] = "kind = 'navigation'";
            if ($reference !== '') {
                $conditions[] = 'session_ref = ?';
                $params[] = $reference;
            }
        } elseif ($status !== '') {
            $conditions[] = 'kind = ?';
            $params[] = $status;
        } else {
            $conditions[] = "kind IN ('success', 'failed', 'blocked')";
        }
    } elseif ($status !== '') {
        $conditions[] = $status === 'cerrada' ? 'closed_at IS NOT NULL' : 'closed_at IS NULL';
        switch ($status) {
            case 'activa':
                $conditions[] = 'last_seen >= ?';
                $params[] = $active;
                break;
            case 'ausente':
                $conditions[] = 'last_seen >= ? AND last_seen < ?';
                array_push($params, $absent, $active);
                break;
            case 'inactiva':
                $conditions[] = 'last_seen < ?';
                $params[] = $absent;
                break;
        }
    }
    if ($username !== '') {
        $conditions[] = 'username = ?';
        $params[] = $username;
    }
    if ($cursorTime !== false && $cursorId !== false) {
        $conditions[] = "({$timeColumn}, id) < (CAST(? AS INTEGER), CAST(? AS INTEGER))";
        array_push($params, $cursorTime, $cursorId);
    }
    $fields = $sessionsTab
        ? 'id, username, substr(session_key, 1, 16) AS session_ref, started_at, last_seen, closed_at, ip, device, browser, os'
        : 'id, occurred_at, username, session_ref, kind, reason, module, page, ip, device, browser, os';
    $rows = n360_audit_get_rows($db, 'SELECT ' . $fields . ' FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions)
        . " ORDER BY {$timeColumn} DESC, id DESC LIMIT 51", $params);
    if (count($rows) > 50) {
        array_pop($rows);
        $last = $rows[array_key_last($rows)];
        $next = '?' . http_build_query($filters + ['antes' => $last[$timeColumn], 'id' => $last['id']]);
    }
} catch (RuntimeException $failure) {
    $error = get_class($failure) === RuntimeException::class ? $failure->getMessage() : 'La auditoría no está disponible en este momento.';
} catch (Throwable $failure) {
    $error = 'La auditoría no está disponible en este momento.';
}

$dateLabel = static fn(int $stamp): string => (new DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format('d/m/Y H:i:s');
$moduleLabels = ['admin' => 'Administración', '01_flota' => 'Flota', '01_fcombustible' => 'Combustible',
    '01_almacen' => 'Almacén', '01_contabilidad' => 'Contabilidad', '01_amantenimiento' => 'Mantenimiento',
    '01_contratos' => 'Contratos', '01_entrevistas' => 'Entrevistas', '13_3ncomiendas' => 'Encomiendas', '24_peajes' => 'Peajes'];
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditoría de sesiones | Norte360</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php foreach (['sidebar', 'header', 'main', 'footer', 'content', 'admin_auditoria_sesiones'] as $asset): ?>
        <link rel="stylesheet" href="<?= n360_asset('assets/css/' . $asset . '_n360.css') ?>">
    <?php endforeach; ?>
</head>
<body class="n360-body">
<?php n360_render_header(['title' => 'Auditoría de sesiones']); ?>
<?php n360_render_sidebar(); ?>
<main class="main-content n360-main n360-main--module n360-main--compact-access">
    <div class="n360-main__inner session-audit">
        <?php n360_render_content_separator('top'); ?>
        <section class="session-audit-hero">
            <div>
                <h1>Auditoría de sesiones</h1>
                <p>Actividad observada al navegar en Norte360 · Hora de Lima</p>
            </div>
            <a class="session-audit-refresh" href="<?= n360_audit_h('?' . http_build_query($filters)) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar</a>
        </section>
        <?php if ($error !== ''): ?>
            <p class="session-audit-notice" role="status"><?= n360_audit_h($error) ?></p>
        <?php else: ?>
            <div class="session-audit-stats">
                <?php foreach (['users_online' => 'Usuarios en línea', 'activa' => 'Sesiones activas', 'ausente' => 'Ausentes', 'inactiva' => 'Inactivas', 'cerrada' => 'Cerradas'] as $key => $label): ?>
                    <div><span><?= n360_audit_h($label) ?></span><strong><?= (int)($stats[$key] ?? 0) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <section class="session-audit-panel">
                <nav class="session-audit-tabs" aria-label="Consultas de auditoría">
                    <?php foreach ($tabs as $key => $label): ?>
                        <a href="<?= n360_audit_h('?' . http_build_query(['tab' => $key, 'usuario' => $username])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                            <?= n360_audit_h($label) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <form class="session-audit-filters" method="get">
                    <input type="hidden" name="tab" value="<?= n360_audit_h($tab) ?>">
                    <label>Usuario <input name="usuario" value="<?= n360_audit_h($username) ?>" maxlength="120" placeholder="Usuario exacto"></label>
                    <?php if (!$sessionsTab): ?>
                        <label>Desde <input type="date" name="desde" value="<?= n360_audit_h($from->format('Y-m-d')) ?>"></label>
                        <label>Hasta <input type="date" name="hasta" value="<?= n360_audit_h($until->format('Y-m-d')) ?>"></label>
                    <?php endif; ?>
                    <?php if ($tab === 'navegacion'): ?>
                        <label>Sesión <input name="sesion" value="<?= n360_audit_h($reference) ?>" maxlength="16" placeholder="Referencia"></label>
                    <?php else: ?>
                        <label><?= $sessionsTab ? 'Estado' : 'Resultado' ?>
                            <select name="estado">
                                <option value="">Todos</option>
                                <?php foreach ($sessionsTab ? $states : $results as $key => $label): ?>
                                    <option value="<?= n360_audit_h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= n360_audit_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <button type="submit">Filtrar</button>
                </form>
                <?php if ($sessionsTab): ?>
                    <p class="session-audit-help">Activa hasta 10 minutos de última actividad. Ausente hasta 30 minutos. Inactiva después de 30 minutos. Cerrada al usar Cerrar sesión.</p>
                <?php endif; ?>
                <div class="session-audit-scroll">
                    <table>
                        <thead><tr>
                            <?php $headers = match ($tab) {
                                'sesiones' => ['Usuario', 'Sesión', 'Estado', 'Primera observación', 'Última actividad', 'IP', 'Dispositivo', 'Navegador', 'País aproximado'],
                                'accesos' => ['Fecha', 'Usuario', 'Sesión', 'Resultado', 'Motivo', 'IP', 'Dispositivo', 'Navegador', 'País aproximado'],
                                default => ['Fecha', 'Usuario', 'Sesión', 'Módulo', 'Página'],
                            }; ?>
                            <?php foreach ($headers as $heading): ?><th scope="col"><?= n360_audit_h($heading) ?></th><?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="<?= count($headers) ?>">No hay registros para esta consulta.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                            <?php if ($sessionsTab): ?>
                                <?php $state = $row['closed_at'] !== null ? 'cerrada' : ($row['last_seen'] >= $active ? 'activa' : ($row['last_seen'] >= $absent ? 'ausente' : 'inactiva'));
                                $ref = $row['session_ref']; ?>
                                <td><?= n360_audit_h($row['username']) ?></td>
                                <td><a href="<?= n360_audit_h('?' . http_build_query(['tab' => 'navegacion', 'usuario' => $row['username'], 'sesion' => $ref])) ?>"><?= n360_audit_h($ref) ?></a></td>
                                <td><span class="session-audit-state" data-state="<?= n360_audit_h($state) ?>"><?= n360_audit_h($states[$state]) ?></span></td>
                                <td><?= n360_audit_h($dateLabel((int)$row['started_at'])) ?></td>
                                <td><?= n360_audit_h($dateLabel((int)$row['last_seen'])) ?></td>
                            <?php else: ?>
                                <td><?= n360_audit_h($dateLabel((int)$row['occurred_at'])) ?></td>
                                <td><?= n360_audit_h($row['username'] ?: 'Sin cuenta identificada') ?></td>
                                <td><?= n360_audit_h($row['session_ref'] ?: '—') ?></td>
                                <?php if ($tab === 'accesos'): ?>
                                    <td><span class="session-audit-state" data-state="<?= n360_audit_h($row['kind']) ?>"><?= n360_audit_h($results[$row['kind']] ?? '—') ?></span></td>
                                    <td><?= n360_audit_h($reasons[$row['reason']] ?? '—') ?></td>
                                <?php else: ?>
                                    <td><?= n360_audit_h($moduleLabels[$row['module']] ?? $row['module']) ?></td>
                                    <td><?= n360_audit_h($row['page']) ?></td>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($tab !== 'navegacion'): ?>
                                <td><?= n360_audit_h($row['ip'] ?: '—') ?></td>
                                <td><?= n360_audit_h($row['device'] . ' · ' . $row['os']) ?></td>
                                <td><?= n360_audit_h($row['browser']) ?></td>
                                <td><?= n360_audit_h(n360_audit_country($row['ip'])) ?></td>
                            <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($next || $cursorId): ?>
                    <div class="session-audit-pagination">
                        <?php if ($cursorId): ?><a href="<?= n360_audit_h('?' . http_build_query($filters)) ?>">Más recientes</a><?php endif; ?>
                        <?php if ($next): ?><a href="<?= n360_audit_h($next) ?>">Siguientes 50</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>
<?php n360_render_footer(); ?>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
