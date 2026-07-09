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

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function n360_ap_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function n360_ap_module_catalog(): array {
    $catalog = [
        0 => ['title' => 'sistema', 'desc' => 'Panel principal, administracion y vistas internas del sistema.'],
        1 => ['title' => 'ventas', 'desc' => 'Gestion de ventas del sistema.'],
        2 => ['title' => 'compras', 'desc' => 'Gestion de compras del sistema.'],
        3 => ['title' => 'almacen', 'desc' => 'Control de inventarios y almacenamiento.'],
        4 => ['title' => 'peajes', 'desc' => 'Gestion de peajes para transporte.'],
        5 => ['title' => 'calidad', 'desc' => 'Control de mantenimiento de los vehiculos.'],
        6 => ['title' => 'recursos', 'desc' => 'Modulo de gestion de recursos humanos.'],
        7 => ['title' => 'liquidaciones', 'desc' => 'Modulo de gestion de liquidaciones.'],
        8 => ['title' => 'mantenimiento', 'desc' => 'Control de mantenimiento de los vehiculos.'],
        9 => ['title' => 'combustible', 'desc' => 'Modulo de gestion de combustible.'],
        10 => ['title' => 'flota', 'desc' => 'Gestion de unidades y generacion de reportes de flota.'],
        11 => ['title' => 'rutas', 'desc' => 'Hojas de rutas en el sistema.'],
        12 => ['title' => 'contabilidad', 'desc' => 'Modulo de contabilidad.'],
    ];

    foreach (n360_menu_config() as $module) {
        $id = (int)($module['modulo'] ?? 0);
        if (isset($catalog[$id])) {
            continue;
        }

        $catalog[$id] = [
            'title' => (string)($module['titulo'] ?? ('Modulo ' . $id)),
            'desc' => 'Modulo declarado en sidebar_n360.php.',
        ];
    }

    ksort($catalog);
    return $catalog;
}

function n360_ap_view_catalog(): array {
    $catalog = [
        'checklist-limpieza' => ['title' => 'Checklist limpieza legado', 'module' => 5, 'desc' => 'Redireccion antigua a checklistlimpieza.php.'],
        'checklist-carro' => ['title' => 'Checklist carro legado', 'module' => 5, 'desc' => 'Redireccion antigua a checklistcarro.php.'],
        'f-flotayoperaciones' => ['title' => 'Flota y operaciones general', 'module' => 10, 'desc' => 'Permiso general usado en login para volver al panel.'],
    ];

    foreach (n360_menu_config() as $module) {
        $moduleId = (int)($module['modulo'] ?? 0);
        foreach (($module['grupos'] ?? []) as $group) {
            foreach (($group['items'] ?? []) as $item) {
                $codes = [];
                if (!empty($item['vista'])) $codes[] = (string)$item['vista'];
                if (!empty($item['vistas'])) $codes = array_merge($codes, array_map('strval', (array)$item['vistas']));

                foreach (array_unique($codes) as $code) {
                    if ($code === '') continue;
                    $catalog[$code] = [
                        'title' => (string)($item['titulo'] ?? $code),
                        'module' => (int)($item['modulo'] ?? $moduleId),
                        'desc' => 'Codigo usado como vista_redirect en permisos.',
                    ];
                }
            }
        }
    }

    ksort($catalog);
    return $catalog;
}

function n360_ap_fetch_all(mysqli $conn, string $sql): array {
    $result = $conn->query($sql);
    if (!$result) return [];

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function n360_ap_flash(string $type, string $message): void {
    $_SESSION['n360_perm_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function n360_ap_redirect(): void {
    header('Location: permisos_mapa.php');
    exit();
}

function n360_ap_csrf_token(): string {
    if (empty($_SESSION['n360_perm_token'])) {
        $_SESSION['n360_perm_token'] = bin2hex(random_bytes(16));
    }

    return (string)$_SESSION['n360_perm_token'];
}

function n360_ap_validate_csrf(): bool {
    $token = (string)($_POST['n360_perm_token'] ?? '');
    $sessionToken = (string)($_SESSION['n360_perm_token'] ?? '');

    return $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function n360_ap_normalized_view($value): ?string {
    $value = trim((string)$value);
    if ($value === '' || strtolower($value) === 'null' || strtolower($value) === 'sin-vista') {
        return null;
    }

    return $value;
}

function n360_ap_allowed_types(): array {
    return ['lectura', 'lectura/escritura'];
}

function n360_ap_find_permission(mysqli $conn, int $userId, int $moduleId, ?string $view): int {
    if ($view === null) {
        $stmt = $conn->prepare("
            SELECT id_permiso
            FROM tb_permisos
            WHERE id_usuario = ?
              AND id_modulo = ?
              AND (vista_redirect IS NULL OR vista_redirect = '')
            LIMIT 1
        ");
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $userId, $moduleId);
    } else {
        $stmt = $conn->prepare("
            SELECT id_permiso
            FROM tb_permisos
            WHERE id_usuario = ?
              AND id_modulo = ?
              AND vista_redirect = ?
            LIMIT 1
        ");
        if (!$stmt) return 0;
        $stmt->bind_param('iis', $userId, $moduleId, $view);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['id_permiso'] ?? 0);
}

function n360_ap_upsert_permission(mysqli $conn, int $userId, int $moduleId, ?string $view, string $type): string {
    $existingId = n360_ap_find_permission($conn, $userId, $moduleId, $view);

    if ($existingId > 0) {
        $stmt = $conn->prepare("
            UPDATE tb_permisos
            SET tipo_permiso = ?, fecha_asignacion = NOW()
            WHERE id_permiso = ?
        ");
        if (!$stmt) return 'error';
        $stmt->bind_param('si', $type, $existingId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'updated' : 'error';
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_permisos (id_usuario, id_modulo, vista_redirect, tipo_permiso, fecha_asignacion)
        VALUES (?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return 'error';
    $stmt->bind_param('iiss', $userId, $moduleId, $view, $type);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? 'created' : 'error';
}

function n360_ap_user_label(array $user): string {
    $name = trim((string)($user['nombre'] ?? ''));
    $username = trim((string)($user['usuario'] ?? ''));
    if ($name !== '' && $username !== '') return $name . ' (' . $username . ')';
    return $name !== '' ? $name : ($username !== '' ? $username : 'Usuario sin nombre');
}

function n360_ap_names(array $ids, array $users): array {
    $names = [];
    foreach ($ids as $id) {
        if (isset($users[$id])) $names[] = n360_ap_user_label($users[$id]);
    }
    natcasesort($names);
    return array_values($names);
}

function n360_ap_required_rule(array $item, int $moduleId): array {
    if (!empty($item['publico'])) return ['type' => 'publico', 'label' => 'Usuario autenticado', 'codes' => []];
    if (!empty($item['admin'])) return ['type' => 'admin', 'label' => 'Solo Admin', 'codes' => []];
    if (!empty($item['vistas'])) return ['type' => 'vistas', 'label' => 'Alguna vista_redirect', 'codes' => array_values((array)$item['vistas'])];
    if (!empty($item['vista'])) return ['type' => 'vista', 'label' => 'Vista_redirect', 'codes' => [(string)$item['vista']]];
    if ($moduleId > 0) return ['type' => 'modulo', 'label' => 'Modulo ' . $moduleId, 'codes' => []];
    return ['type' => 'publico', 'label' => 'Usuario autenticado', 'codes' => []];
}

$moduleCatalog = n360_ap_module_catalog();
$viewCatalog = n360_ap_view_catalog();
$users = [];
$adminIds = [];
$moduleUsers = [];
$viewUsers = [];

$userRows = n360_ap_fetch_all($conn, "SELECT id_usuario, usuario, nombre, web_rol, clm_usuarios_sede FROM tb_usuarios ORDER BY web_rol='Admin' DESC, nombre, usuario");
foreach ($userRows as $row) {
    $id = (int)$row['id_usuario'];
    $users[$id] = $row;
    if (($row['web_rol'] ?? '') === 'Admin') $adminIds[] = $id;
}

$csrfToken = n360_ap_csrf_token();
$flash = $_SESSION['n360_perm_flash'] ?? null;
unset($_SESSION['n360_perm_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!n360_ap_validate_csrf()) {
        n360_ap_flash('error', 'No se pudo validar la accion. Actualiza la pagina e intenta nuevamente.');
        n360_ap_redirect();
    }

    $action = (string)($_POST['action'] ?? '');
    $allowedTypes = n360_ap_allowed_types();

    if ($action === 'create') {
        $postedUsers = (array)($_POST['id_usuario'] ?? []);
        $userIds = [];
        foreach ($postedUsers as $postedUser) {
            $userId = (int)$postedUser;
            if ($userId > 0 && isset($users[$userId])) {
                $userIds[$userId] = true;
            }
        }

        $moduleId = (int)($_POST['id_modulo'] ?? -1);
        $view = n360_ap_normalized_view($_POST['vista_redirect'] ?? '');
        $type = (string)($_POST['tipo_permiso'] ?? 'lectura');
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'lectura';
        }

        if (empty($userIds)) {
            n360_ap_flash('error', 'Selecciona al menos un usuario para asignar el permiso.');
            n360_ap_redirect();
        }

        if (!isset($moduleCatalog[$moduleId])) {
            n360_ap_flash('error', 'Selecciona un modulo valido para el permiso.');
            n360_ap_redirect();
        }

        $created = 0;
        $updated = 0;
        $errors = 0;

        foreach (array_keys($userIds) as $userId) {
            $result = n360_ap_upsert_permission($conn, $userId, $moduleId, $view, $type);
            if ($result === 'created') $created++;
            if ($result === 'updated') $updated++;
            if ($result === 'error') $errors++;
        }

        $parts = [];
        if ($created > 0) $parts[] = $created . ' creados';
        if ($updated > 0) $parts[] = $updated . ' actualizados';
        if ($errors > 0) $parts[] = $errors . ' con error';

        n360_ap_flash($errors > 0 ? 'warning' : 'success', 'Gestion de permisos aplicada: ' . implode(', ', $parts) . '.');
        n360_ap_redirect();
    }

    if ($action === 'update') {
        $permissionId = (int)($_POST['id_permiso'] ?? 0);
        $userId = (int)($_POST['id_usuario'] ?? 0);
        $moduleId = (int)($_POST['id_modulo'] ?? -1);
        $view = n360_ap_normalized_view($_POST['vista_redirect'] ?? '');
        $type = (string)($_POST['tipo_permiso'] ?? 'lectura');

        if ($permissionId <= 0 || $userId <= 0 || !isset($users[$userId]) || !isset($moduleCatalog[$moduleId])) {
            n360_ap_flash('error', 'No se pudo actualizar el permiso porque los datos no son validos.');
            n360_ap_redirect();
        }

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'lectura';
        }

        $stmt = $conn->prepare("
            UPDATE tb_permisos
            SET id_usuario = ?, id_modulo = ?, vista_redirect = ?, tipo_permiso = ?, fecha_asignacion = NOW()
            WHERE id_permiso = ?
        ");

        if (!$stmt) {
            n360_ap_flash('error', 'No se pudo preparar la actualizacion del permiso.');
            n360_ap_redirect();
        }

        $stmt->bind_param('iissi', $userId, $moduleId, $view, $type, $permissionId);
        $ok = $stmt->execute();
        $stmt->close();

        n360_ap_flash($ok ? 'success' : 'error', $ok ? 'Permiso actualizado correctamente.' : 'No se pudo actualizar el permiso.');
        n360_ap_redirect();
    }

    if ($action === 'delete') {
        $permissionId = (int)($_POST['id_permiso'] ?? 0);
        if ($permissionId <= 0) {
            n360_ap_flash('error', 'No se pudo identificar el permiso a eliminar.');
            n360_ap_redirect();
        }

        $stmt = $conn->prepare("DELETE FROM tb_permisos WHERE id_permiso = ? LIMIT 1");
        if (!$stmt) {
            n360_ap_flash('error', 'No se pudo preparar la eliminacion del permiso.');
            n360_ap_redirect();
        }

        $stmt->bind_param('i', $permissionId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        n360_ap_flash(($ok && $affected > 0) ? 'success' : 'warning', ($ok && $affected > 0) ? 'Permiso eliminado correctamente.' : 'El permiso ya no existe o no pudo eliminarse.');
        n360_ap_redirect();
    }

    n360_ap_flash('error', 'Accion de permisos no reconocida.');
    n360_ap_redirect();
}

$permissionRows = n360_ap_fetch_all($conn, "
    SELECT p.id_permiso, p.id_usuario, p.id_modulo, p.vista_redirect, p.tipo_permiso, p.fecha_asignacion, u.usuario, u.nombre, u.web_rol, u.clm_usuarios_sede
    FROM tb_permisos p
    LEFT JOIN tb_usuarios u ON u.id_usuario = p.id_usuario
    ORDER BY p.id_usuario, p.id_modulo, p.vista_redirect, p.id_permiso
");

foreach ($permissionRows as $row) {
    $userId = (int)$row['id_usuario'];
    $moduleId = (int)$row['id_modulo'];
    $view = trim((string)($row['vista_redirect'] ?? ''));
    if ($moduleId > 0) $moduleUsers[$moduleId][$userId] = true;
    if ($view !== '') $viewUsers[$view][$userId] = true;
}

$interfaces = [];
foreach (n360_menu_config() as $module) {
    $moduleId = (int)($module['modulo'] ?? 0);
    foreach (($module['grupos'] ?? []) as $group) {
        foreach (($group['items'] ?? []) as $item) {
            $itemModule = (int)($item['modulo'] ?? $moduleId);
            $rule = n360_ap_required_rule($item, $itemModule);
            if ($rule['type'] === 'modulo' && isset($moduleCatalog[$itemModule])) {
                $rule['label'] = 'Modulo ' . $itemModule . ' - ' . $moduleCatalog[$itemModule]['title'];
            }
            $accessIds = [];

            foreach ($adminIds as $adminId) $accessIds[$adminId] = true;

            if ($rule['type'] === 'publico') {
                foreach (array_keys($users) as $userId) $accessIds[$userId] = true;
            } elseif ($rule['type'] === 'modulo') {
                foreach (array_keys($moduleUsers[$itemModule] ?? []) as $userId) $accessIds[$userId] = true;
            } elseif ($rule['type'] === 'vista' || $rule['type'] === 'vistas') {
                foreach ($rule['codes'] as $code) {
                    foreach (array_keys($viewUsers[$code] ?? []) as $userId) {
                        $accessIds[$userId] = true;
                    }
                }
            }

            $interfaces[] = [
                'module_id' => $itemModule,
                'module' => (string)($moduleCatalog[$itemModule]['title'] ?? ($module['titulo'] ?? 'Modulo')),
                'group' => (string)($group['titulo'] ?? 'General'),
                'title' => (string)($item['titulo'] ?? 'Vista'),
                'url' => (string)($item['url'] ?? ''),
                'rule' => $rule,
                'users' => n360_ap_names(array_keys($accessIds), $users),
            ];
        }
    }
}

$totalUsers = count($users);
$totalAdmins = count($adminIds);
$totalPermissionRows = count($permissionRows);
$totalInterfaces = count($interfaces);
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mapa de permisos | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/admin_permisos_n360.css') ?>">
</head>
<body>
<?php n360_render_header(); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-perms-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-perms-hero">
            <div>
                <span class="admin-perms-kicker"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i> Solo administradores</span>
                <h1>Mapa de permisos</h1>
                <p>Vista de consulta para revisar modulos, interfaces, codigos de permiso y usuarios con acceso asignado.</p>
            </div>
            <a href="<?= n360_ap_h(n360_base_url('index.php')) ?>" class="admin-perms-btn"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel principal</a>
        </section>

        <section class="admin-perms-summary" aria-label="Resumen de permisos">
            <div><span>Usuarios</span><strong><?= n360_ap_h($totalUsers) ?></strong></div>
            <div><span>Administradores</span><strong><?= n360_ap_h($totalAdmins) ?></strong></div>
            <div><span>Asignaciones</span><strong><?= n360_ap_h($totalPermissionRows) ?></strong></div>
            <div><span>Interfaces mapeadas</span><strong><?= n360_ap_h($totalInterfaces) ?></strong></div>
        </section>

        <?php if (is_array($flash)): ?>
            <div class="admin-perms-alert admin-perms-alert--<?= n360_ap_h($flash['type'] ?? 'success') ?>">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span><?= n360_ap_h($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>

        <section class="admin-perms-panel admin-perms-manage" aria-labelledby="managePermsTitle">
            <div class="admin-perms-panel-head">
                <h2 id="managePermsTitle">Gestionar permisos</h2>
                <span>Crear o asignar acceso</span>
            </div>
            <form method="post" class="admin-perms-form" id="adminPermsCreateForm">
                <input type="hidden" name="n360_perm_token" value="<?= n360_ap_h($csrfToken) ?>">
                <input type="hidden" name="action" value="create">

                <label>
                    <span>Usuarios</span>
                    <select name="id_usuario[]" multiple required size="7">
                        <?php foreach ($users as $userId => $user): ?>
                            <option value="<?= n360_ap_h($userId) ?>"><?= n360_ap_h(n360_ap_user_label($user)) ?> - <?= n360_ap_h($user['web_rol'] ?? 'Usuario') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Usa Ctrl o Shift para elegir varios usuarios.</small>
                </label>

                <label>
                    <span>Modulo</span>
                    <select name="id_modulo" id="adminPermsModuleSelect" required>
                        <?php foreach ($moduleCatalog as $moduleId => $module): ?>
                            <option value="<?= n360_ap_h($moduleId) ?>"><?= n360_ap_h($moduleId) ?> - <?= n360_ap_h($module['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Vista / codigo</span>
                    <input type="text" name="vista_redirect" id="adminPermsViewInput" list="adminPermsViewCodes" placeholder="Dejar vacio para permiso de modulo completo">
                </label>

                <label>
                    <span>Tipo</span>
                    <select name="tipo_permiso">
                        <?php foreach (n360_ap_allowed_types() as $type): ?>
                            <option value="<?= n360_ap_h($type) ?>"><?= n360_ap_h($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" class="admin-perms-primary-btn">
                    <i class="bi bi-person-check-fill" aria-hidden="true"></i>
                    <span>Asignar permiso</span>
                </button>
            </form>
            <datalist id="adminPermsViewCodes">
                <?php foreach ($viewCatalog as $code => $view): ?>
                    <option value="<?= n360_ap_h($code) ?>"><?= n360_ap_h($view['title']) ?></option>
                <?php endforeach; ?>
            </datalist>
        </section>

        <section class="admin-perms-toolbar" aria-label="Filtros">
            <label><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="adminPermsSearch" placeholder="Buscar modulo, vista, usuario o codigo..."></label>
        </section>

        <section class="admin-perms-panel" aria-labelledby="interfacesTitle">
            <div class="admin-perms-panel-head">
                <h2 id="interfacesTitle">Interfaces y usuarios con acceso</h2>
                <span><?= n360_ap_h($totalInterfaces) ?> interfaces</span>
            </div>
            <div class="admin-perms-table-wrap">
                <table class="admin-perms-table" id="adminPermsTable">
                    <thead>
                        <tr><th>Modulo</th><th>Interfaz</th><th>Regla</th><th>Usuarios con acceso</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($interfaces as $interface): ?>
                        <tr>
                            <td><strong><?= n360_ap_h($interface['module']) ?></strong><small>ID <?= n360_ap_h($interface['module_id']) ?> / <?= n360_ap_h($interface['group']) ?></small></td>
                            <td><strong><?= n360_ap_h($interface['title']) ?></strong><small><?= n360_ap_h($interface['url']) ?></small></td>
                            <td>
                                <span class="admin-perms-rule"><?= n360_ap_h($interface['rule']['label']) ?></span>
                                <?php if (!empty($interface['rule']['codes'])): ?>
                                    <div class="admin-perms-code-list">
                                        <?php foreach ($interface['rule']['codes'] as $code): ?><code><?= n360_ap_h($code) ?></code><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <button type="button"
                                        class="admin-perms-mini-btn"
                                        data-perm-fill
                                        data-module="<?= n360_ap_h($interface['module_id']) ?>"
                                        data-view="<?= n360_ap_h($interface['rule']['codes'][0] ?? '') ?>">
                                    <i class="bi bi-plus-circle" aria-hidden="true"></i>
                                    <span>Asignar</span>
                                </button>
                            </td>
                            <td>
                                <?php if (empty($interface['users'])): ?>
                                    <span class="admin-perms-muted">Sin usuarios asignados</span>
                                <?php else: ?>
                                    <div class="admin-perms-user-list">
                                        <?php foreach ($interface['users'] as $name): ?><span><?= n360_ap_h($name) ?></span><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-perms-grid">
            <article class="admin-perms-panel">
                <div class="admin-perms-panel-head"><h2>Modulos</h2><span><?= n360_ap_h(count($moduleCatalog)) ?> registros</span></div>
                <div class="admin-perms-list">
                    <?php foreach ($moduleCatalog as $id => $module): ?>
                        <div class="admin-perms-list-item"><code><?= n360_ap_h($id) ?></code><span><strong><?= n360_ap_h($module['title']) ?></strong><small><?= n360_ap_h($module['desc']) ?></small></span></div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-perms-panel">
                <div class="admin-perms-panel-head"><h2>Codigos de vista</h2><span><?= n360_ap_h(count($viewCatalog)) ?> codigos</span></div>
                <div class="admin-perms-list">
                    <?php foreach ($viewCatalog as $code => $view): ?>
                        <div class="admin-perms-list-item"><code><?= n360_ap_h($code) ?></code><span><strong><?= n360_ap_h($view['title']) ?></strong><small>Modulo <?= n360_ap_h($view['module']) ?> / <?= n360_ap_h($view['desc']) ?></small></span></div>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section class="admin-perms-panel" aria-labelledby="rawPermsTitle">
            <div class="admin-perms-panel-head"><h2 id="rawPermsTitle">Asignaciones actuales en tb_permisos</h2><span><?= n360_ap_h($totalPermissionRows) ?> filas</span></div>
            <div class="admin-perms-table-wrap admin-perms-table-wrap--small">
                <table class="admin-perms-table admin-perms-table--manage">
                    <thead><tr><th>Acciones</th><th>Usuario</th><th>Rol</th><th>Sede</th><th>Modulo</th><th>Vista</th><th>Tipo</th><th>Fecha</th></tr></thead>
                    <tbody>
                    <?php if (empty($permissionRows)): ?>
                        <tr><td colspan="8" class="admin-perms-muted">No hay permisos registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($permissionRows as $row): ?>
                            <?php
                                $permissionId = (int)$row['id_permiso'];
                                $moduleId = (int)$row['id_modulo'];
                                $viewCode = trim((string)$row['vista_redirect']);
                                $editFormId = 'permEdit' . $permissionId;
                                $deleteFormId = 'permDelete' . $permissionId;
                            ?>
                            <tr>
                                <td class="admin-perms-actions">
                                    <form method="post" id="<?= n360_ap_h($editFormId) ?>">
                                        <input type="hidden" name="n360_perm_token" value="<?= n360_ap_h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id_permiso" value="<?= n360_ap_h($permissionId) ?>">
                                    </form>
                                    <form method="post" id="<?= n360_ap_h($deleteFormId) ?>" onsubmit="return confirm('Seguro que deseas eliminar este permiso?');">
                                        <input type="hidden" name="n360_perm_token" value="<?= n360_ap_h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_permiso" value="<?= n360_ap_h($permissionId) ?>">
                                    </form>
                                    <button type="submit" form="<?= n360_ap_h($editFormId) ?>" class="admin-perms-icon-btn" title="Guardar cambios">
                                        <i class="bi bi-save-fill" aria-hidden="true"></i>
                                    </button>
                                    <button type="submit" form="<?= n360_ap_h($deleteFormId) ?>" class="admin-perms-icon-btn admin-perms-icon-btn--danger" title="Eliminar permiso">
                                        <i class="bi bi-trash3-fill" aria-hidden="true"></i>
                                    </button>
                                </td>
                                <td>
                                    <select name="id_usuario" form="<?= n360_ap_h($editFormId) ?>" class="admin-perms-cell-control">
                                        <?php foreach ($users as $userId => $user): ?>
                                            <option value="<?= n360_ap_h($userId) ?>" <?= (int)$row['id_usuario'] === (int)$userId ? 'selected' : '' ?>><?= n360_ap_h(n360_ap_user_label($user)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><?= n360_ap_h($row['web_rol'] ?? 'Usuario') ?></td>
                                <td><?= n360_ap_h($row['clm_usuarios_sede'] ?? 'No asignada') ?></td>
                                <td>
                                    <select name="id_modulo" form="<?= n360_ap_h($editFormId) ?>" class="admin-perms-cell-control">
                                        <?php foreach ($moduleCatalog as $catalogModuleId => $module): ?>
                                            <option value="<?= n360_ap_h($catalogModuleId) ?>" <?= $moduleId === (int)$catalogModuleId ? 'selected' : '' ?>><?= n360_ap_h($catalogModuleId) ?> - <?= n360_ap_h($module['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text"
                                           name="vista_redirect"
                                           form="<?= n360_ap_h($editFormId) ?>"
                                           list="adminPermsViewCodes"
                                           class="admin-perms-cell-control"
                                           value="<?= n360_ap_h($viewCode) ?>"
                                           placeholder="Sin vista">
                                </td>
                                <td>
                                    <select name="tipo_permiso" form="<?= n360_ap_h($editFormId) ?>" class="admin-perms-cell-control">
                                        <?php foreach (n360_ap_allowed_types() as $type): ?>
                                            <option value="<?= n360_ap_h($type) ?>" <?= (string)($row['tipo_permiso'] ?? '') === $type ? 'selected' : '' ?>><?= n360_ap_h($type) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><?= n360_ap_h($row['fecha_asignacion'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('adminPermsSearch');
    const table = document.getElementById('adminPermsTable');
    if (search && table) {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        search.addEventListener('input', function () {
            const value = search.value.trim().toLowerCase();
            rows.forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
            });
        });
    }

    const moduleSelect = document.getElementById('adminPermsModuleSelect');
    const viewInput = document.getElementById('adminPermsViewInput');
    const createForm = document.getElementById('adminPermsCreateForm');

    document.querySelectorAll('[data-perm-fill]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (moduleSelect) moduleSelect.value = button.dataset.module || '0';
            if (viewInput) viewInput.value = button.dataset.view || '';
            if (createForm) {
                createForm.scrollIntoView({behavior: 'smooth', block: 'center'});
                const users = createForm.querySelector('select[name="id_usuario[]"]');
                if (users) users.focus();
            }
        });
    });
});
</script>
</body>
</html>
