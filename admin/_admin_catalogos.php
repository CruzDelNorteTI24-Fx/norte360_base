<?php
if (!defined('N360_ADMIN_CATALOG')) {
    exit('Acceso no permitido.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

function n360_admin_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function n360_admin_query_all(mysqli $conn, string $sql): array {
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function n360_admin_columns(mysqli $conn, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $result = $conn->query("SHOW COLUMNS FROM `$safeTable`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string)$row['Field'];
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function n360_admin_has_column(mysqli $conn, string $table, string $column): bool {
    return in_array($column, n360_admin_columns($conn, $table), true);
}

function n360_admin_module_name($value): string {
    $id = is_numeric($value) ? (int)$value : 0;
    $map = [
        1 => 'Ventas',
        2 => 'Compras',
        3 => 'Almacen',
        4 => 'Peajes',
        5 => 'Calidad',
        6 => 'Recursos Humanos',
        7 => 'Liquidaciones',
        8 => 'Mantenimiento',
        9 => 'Combustible',
        10 => 'Flota',
        11 => 'Rutas',
        12 => 'Contabilidad',
    ];

    return $map[$id] ?? ($id > 0 ? 'Modulo ' . $id : 'Oficina');
}

function n360_admin_space_module($value): string {
    $id = is_numeric($value) ? (int)$value : 0;
    if ($id === 1) return 'Almacen';
    if ($id === 2) return 'Combustible';
    return 'Oficina';
}

function n360_admin_photo_data_uri($blob): string {
    if (!is_string($blob) || $blob === '') {
        return '';
    }

    $mime = 'image/jpeg';
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($blob);
        if (is_array($info) && !empty($info['mime'])) {
            $mime = (string)$info['mime'];
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($blob);
}

function n360_admin_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return 'N3';
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $first = mb_substr($parts[0], 0, 1, 'UTF-8');
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : '';
        return mb_strtoupper($first . $second, 'UTF-8');
    }

    $first = substr($parts[0], 0, 1);
    $second = isset($parts[1]) ? substr($parts[1], 0, 1) : '';
    return strtoupper($first . $second);
}

function n360_admin_permissions_by_user(mysqli $conn): array {
    $rows = n360_admin_query_all($conn, "
        SELECT id_usuario, id_modulo, vista_redirect, tipo_permiso
        FROM tb_permisos
        ORDER BY id_usuario ASC, id_modulo ASC, vista_redirect ASC
    ");

    $grouped = [];
    foreach ($rows as $row) {
        $userId = (int)($row['id_usuario'] ?? 0);
        if ($userId <= 0) continue;

        $module = n360_admin_module_name($row['id_modulo'] ?? 0);
        $view = trim((string)($row['vista_redirect'] ?? ''));
        $type = trim((string)($row['tipo_permiso'] ?? 'lectura'));

        $grouped[$userId][] = [
            'module' => $module,
            'view' => $view !== '' ? $view : 'Modulo completo',
            'type' => $type,
        ];
    }

    return $grouped;
}

function n360_admin_render_head(string $title): void {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title><?= n360_admin_h($title) ?> | Norte360</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
        <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
        <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
        <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
        <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
        <link rel="stylesheet" href="<?= n360_asset('assets/css/admin_catalogos_n360.css') ?>">
    </head>
    <body>
    <?php
}

function n360_admin_render_close(): void {
    ?>
    <script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
    <script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
    </body>
    </html>
    <?php
}
