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
        0 => ['title' => 'Panel principal', 'desc' => 'Inicio del sistema.'],
        1 => ['title' => 'Checklist legado', 'desc' => 'Permisos antiguos de checklist.'],
    ];

    foreach (n360_menu_config() as $module) {
        $id = (int)($module['modulo'] ?? 0);
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
        'checklist-limpieza' => ['title' => 'Checklist limpieza legado', 'module' => 1, 'desc' => 'Redireccion antigua a checklistlimpieza.php.'],
        'checklist-carro' => ['title' => 'Checklist carro legado', 'module' => 1, 'desc' => 'Redireccion antigua a checklistcarro.php.'],
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

$permissionRows = n360_ap_fetch_all($conn, "
    SELECT p.id_usuario, p.id_modulo, p.vista_redirect, u.usuario, u.nombre, u.web_rol, u.clm_usuarios_sede
    FROM tb_permisos p
    LEFT JOIN tb_usuarios u ON u.id_usuario = p.id_usuario
    ORDER BY p.id_modulo, p.vista_redirect, u.nombre, u.usuario
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
                'module' => (string)($module['titulo'] ?? 'Modulo'),
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
                            </td>
                            <td>
                                <?php if (empty($interface['users'])): ?>
                                    <span class="admin-perms-muted">Sin usuarios asignados</span>
                                <?php else: ?>
                                    <div class="admin-perms-user-list">
                                        <?php foreach (array_slice($interface['users'], 0, 8) as $name): ?><span><?= n360_ap_h($name) ?></span><?php endforeach; ?>
                                        <?php if (count($interface['users']) > 8): ?><span>+<?= n360_ap_h(count($interface['users']) - 8) ?> mas</span><?php endif; ?>
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
                <table class="admin-perms-table">
                    <thead><tr><th>Usuario</th><th>Rol</th><th>Sede</th><th>Modulo</th><th>Vista</th></tr></thead>
                    <tbody>
                    <?php if (empty($permissionRows)): ?>
                        <tr><td colspan="5" class="admin-perms-muted">No hay permisos registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($permissionRows as $row): ?>
                            <?php $moduleId = (int)$row['id_modulo']; $viewCode = trim((string)$row['vista_redirect']); ?>
                            <tr>
                                <td><strong><?= n360_ap_h(n360_ap_user_label($row)) ?></strong></td>
                                <td><?= n360_ap_h($row['web_rol'] ?? 'Usuario') ?></td>
                                <td><?= n360_ap_h($row['clm_usuarios_sede'] ?? 'No asignada') ?></td>
                                <td><code><?= n360_ap_h($moduleId) ?></code> <?= n360_ap_h($moduleCatalog[$moduleId]['title'] ?? 'Modulo no catalogado') ?></td>
                                <td><code><?= n360_ap_h($viewCode !== '' ? $viewCode : 'sin-vista') ?></code> <?= n360_ap_h($viewCatalog[$viewCode]['title'] ?? 'Vista no catalogada') ?></td>
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
    if (!search || !table) return;
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    search.addEventListener('input', function () {
        const value = search.value.trim().toLowerCase();
        rows.forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
        });
    });
});
</script>
</body>
</html>