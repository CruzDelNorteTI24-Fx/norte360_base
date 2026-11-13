<?php
ob_start();
session_start();

function n360_controles_json(array $data, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['usuario'])) n360_controles_json(['ok' => false, 'message' => 'Tu sesión terminó. Vuelve a iniciar sesión.'], 401);
if (($_SESSION['web_rol'] ?? '') !== 'Admin') n360_controles_json(['ok' => false, 'message' => 'No tienes permiso para consultar este catálogo.'], 403);
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    n360_controles_json(['ok' => false, 'message' => 'Este catálogo permite únicamente consultas.'], 405);
}
$scope = $_GET['scope'] ?? '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
$query = $_GET['q'] ?? '';
if (!is_string($scope) || !in_array($scope, ['mapa', 'areas', 'tipos', 'categorias'], true) || $id === false || $page === false || !is_string($query)) {
    n360_controles_json(['ok' => false, 'message' => 'Revisa el control y los filtros seleccionados.'], 422);
}
$query = trim($query);
if ((function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) > 120) {
    n360_controles_json(['ok' => false, 'message' => 'La búsqueda admite hasta 120 caracteres.'], 422);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/_controles_productos.php';

try {
    require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) throw new RuntimeException('Conexión no disponible.');
    $conn->set_charset('utf8mb4');
    if ($scope !== 'categorias') {
        $schema = n360_controles_schema($conn);
        if (!$schema[$scope === 'tipos' ? 'tipos' : 'mapa']) {
            n360_controles_json(['ok' => false, 'message' => 'El catálogo de controles aún está pendiente de habilitación.'], 409);
        }
    }
    $area = null; $type = null; $categoryLabel = null;
    if ($scope === 'categorias') {
        $category = n360_controles_rows($conn, 'SELECT clm_alm_categoria_NOMBRE AS codigo,
            clm_alm_categoria_DESCRIPCION AS nombre FROM tb_alm_categoria WHERE clm_alm_categoria_id = ?', 'i', [$id])[0] ?? null;
        if (!$category) n360_controles_json(['ok' => false, 'message' => 'La categoría seleccionada no existe.'], 404);
        $categoryLabel = trim(implode(' - ', array_filter([$category['codigo'], $category['nombre']], static fn($value) => trim((string)$value) !== '')));
        $title = 'Productos de la categoría ' . ($category['nombre'] ?: $category['codigo']);
    } elseif ($scope === 'tipos') {
        $control = n360_controles_rows($conn, 'SELECT clm_alm_tipo_control_codigo AS codigo,
            clm_alm_tipo_control_nombre AS nombre FROM tb_alm_tipo_control WHERE clm_alm_tipo_control_id = ?', 'i', [$id])[0] ?? null;
        if (!$control) n360_controles_json(['ok' => false, 'message' => 'El tipo de control seleccionado no existe.'], 404);
        $type = strtoupper(trim($control['codigo']));
        $title = 'Productos de tipo ' . $control['nombre'];
    } else {
        $control = n360_controles_rows($conn, 'SELECT a.clm_alm_area_control_codigo AS codigo,
            a.clm_alm_area_control_nombre AS nombre, t.clm_alm_tipo_control_codigo AS tipo,
            m.nombre_modulo AS modulo FROM tb_alm_area_control a
            LEFT JOIN tb_alm_tipo_control t ON t.clm_alm_tipo_control_id = a.clm_alm_area_control_idtipo
            LEFT JOIN tb_modulos m ON m.id_modulo = a.clm_alm_area_control_idmodulo
            WHERE a.clm_alm_area_control_id = ?', 'i', [$id])[0] ?? null;
        if (!$control) n360_controles_json(['ok' => false, 'message' => 'El área de control seleccionada no existe.'], 404);
        $area = strtoupper(trim($control['codigo']));
        if ($scope === 'mapa') {
            $type = strtoupper(trim((string)$control['tipo']));
            $title = 'Productos de ' . n360_controles_module_name($control['modulo']);
        } else {
            $title = 'Productos del área ' . $control['nombre'];
        }
    }
    if ($area === '' || $type === '') n360_controles_json(['ok' => false, 'message' => 'La relación de este control está incompleta.'], 409);

    $areaSql = "UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN'))";
    $typeSql = "UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), 'CONSUMIBLE'))";
    $where = []; $paramTypes = ''; $params = [];
    if ($scope === 'categorias') { $where[] = 'p.clm_alm_producto_idCATEGORIA = ?'; $paramTypes .= 'i'; $params[] = $id; }
    if ($area !== null) { $where[] = "$areaSql = ?"; $paramTypes .= 's'; $params[] = $area; }
    if ($type !== null) { $where[] = "$typeSql = ?"; $paramTypes .= 's'; $params[] = $type; }
    if ($query !== '') {
        $where[] = "(CONVERT(p.clm_alm_producto_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(p.clm_alm_producto_NOMBRE USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
            OR CONVERT(CONCAT_WS(' ', c.clm_alm_categoria_NOMBRE, c.clm_alm_categoria_DESCRIPCION) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?)";
        $paramTypes .= 'sss';
        array_push($params, '%' . $query . '%', '%' . $query . '%', '%' . $query . '%');
    }
    $from = 'FROM tb_alm_producto p LEFT JOIN tb_alm_categoria c ON c.clm_alm_categoria_id = p.clm_alm_producto_idCATEGORIA';
    $predicate = implode(' AND ', $where);
    $total = (int)n360_controles_rows($conn, "SELECT COUNT(*) AS total $from WHERE $predicate", $paramTypes, $params)[0]['total'];
    $pageSize = 25;
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $pages);
    $rows = n360_controles_rows($conn, "SELECT p.clm_alm_producto_id AS id,
        COALESCE(NULLIF(TRIM(p.clm_alm_producto_codigo), ''), CAST(p.clm_alm_producto_id AS CHAR)) AS codigo,
        p.clm_alm_producto_NOMBRE AS nombre,
        COALESCE(NULLIF(TRIM(p.clm_alm_producto_unidad), ''), '-') AS unidad,
        TRIM(CONCAT_WS(' ', c.clm_alm_categoria_NOMBRE, c.clm_alm_categoria_DESCRIPCION)) AS categoria,
        $areaSql AS area, $typeSql AS tipo $from WHERE $predicate
        ORDER BY p.clm_alm_producto_NOMBRE, p.clm_alm_producto_id LIMIT ? OFFSET ?",
        $paramTypes . 'ii', array_merge($params, [$pageSize, ($page - 1) * $pageSize]));
    n360_controles_json(['ok' => true, 'context' => ['title' => $title, 'area' => $area, 'tipo' => $type, 'categoria' => $categoryLabel],
        'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => $pageSize]);
} catch (Throwable $e) {
    error_log('controles_productos_api.php: ' . $e->getMessage());
    n360_controles_json(['ok' => false, 'message' => 'No se pudo consultar el catálogo de productos. Inténtalo nuevamente.'], 500);
}
