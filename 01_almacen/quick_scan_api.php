<?php
ob_start();
define('ACCESS_GRANTED', true);

require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

function n360_qs_clean_output(): void {
    if (ob_get_length()) {
        ob_clean();
    }
}

function n360_qs_json(array $payload, int $status = 200): void {
    n360_qs_clean_output();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function n360_qs_allowed(): bool {
    if (empty($_SESSION['id_usuario']) && empty($_SESSION['usuario'])) {
        return false;
    }

    if (($_SESSION['web_rol'] ?? '') === 'Admin') {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];
    if ($permisos === 'all') {
        return true;
    }

    $permisos = array_map('intval', (array)$permisos);
    return in_array(3, $permisos, true);
}

function n360_qs_text($value, string $fallback = '-'): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function n360_qs_number($value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    if (!is_numeric($value)) {
        return (string)$value;
    }

    return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
}

function n360_qs_stock_level($stock, string $estado): string {
    $estadoLower = mb_strtolower(trim($estado), 'UTF-8');
    $stockNumber = is_numeric($stock) ? (float)$stock : null;

    if ($stockNumber !== null && $stockNumber <= 0) {
        return 'danger';
    }

    if (str_contains($estadoLower, 'agot') || str_contains($estadoLower, 'crit') || str_contains($estadoLower, 'sin stock')) {
        return 'danger';
    }

    if (str_contains($estadoLower, 'bajo') || str_contains($estadoLower, 'min')) {
        return 'warning';
    }

    if (str_contains($estadoLower, 'ok') || str_contains($estadoLower, 'normal') || str_contains($estadoLower, 'dispon') || str_contains($estadoLower, 'activo')) {
        return 'ok';
    }

    return 'neutral';
}

function n360_qs_product_payload(array $row): array {
    $codigoCategoria = n360_qs_text($row['codigo_categoria'] ?? '', '');
    $categoriaBase = n360_qs_text($row['categoria_descripcion'] ?? '', '') ?: n360_qs_text($row['categoria'] ?? '', 'Sin categoria');
    $categoria = trim(($codigoCategoria !== '' ? '(' . $codigoCategoria . ') ' : '') . $categoriaBase);
    $stock = $row['Stock_Actual'] ?? null;
    $estado = n360_qs_text($row['Estado'] ?? 'Sin estado', 'Sin estado');

    return [
        'id' => (int)($row['id_producto'] ?? 0),
        'codigo' => n360_qs_text($row['codigo_producto'] ?? ''),
        'nombre' => n360_qs_text($row['producto_nombre'] ?? ''),
        'unidad' => n360_qs_text($row['unidproducto'] ?? '', ''),
        'categoria' => $categoria,
        'stock' => n360_qs_number($stock),
        'estado' => $estado,
        'stock_level' => n360_qs_stock_level($stock, $estado),
    ];
}

if (!n360_qs_allowed()) {
    n360_qs_json([
        'ok' => false,
        'message' => 'No tienes permiso para consultar productos de almacen.',
    ], 403);
}

require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

$query = trim((string)($_GET['q'] ?? $_GET['codigo'] ?? ''));

if ($query === '') {
    n360_qs_json([
        'ok' => false,
        'message' => 'Ingresa o escanea un codigo.',
    ], 422);
}

if (mb_strlen($query, 'UTF-8') > 80) {
    n360_qs_json([
        'ok' => false,
        'message' => 'El codigo escaneado es demasiado largo.',
    ], 422);
}

$isEtiqueta = strpos($query, '-') !== false;

if ($isEtiqueta) {
    $stmt = $conn->prepare("
        SELECT
            e.clm_alm_etiquetado_FECHA AS fecha_etiquetado,
            e.clm_alm_etiquetado_ESTADO AS estado_etiqueta,
            e.clm_alm_etiquetado_idPRODUCTO AS id_producto,
            e.clm_alm_etiquetado_idMOVIMIENTO AS id_movimiento,
            a.clm_alm_anaquel_nombre AS anaquel,
            s.clm_sedes_name AS sede_nombre,
            p.clm_alm_producto_codigo AS codigo_producto,
            p.clm_alm_producto_NOMBRE AS producto_nombre,
            p.clm_alm_producto_unidad AS unidproducto,
            c.clm_alm_categoria_NOMBRE AS categoria,
            c.clm_alm_categoria_DESCRIPCION AS categoria_descripcion,
            cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
            m.clm_alm_mov_OBSERVACION AS observacion_movimiento,
            m.clm_alm_mov_cantidad AS cantidad_movimiento,
            m.clm_alm_mov_fecha_registro AS fecha_movimiento,
            COALESCE(v.Stock_Actual, 0) AS Stock_Actual,
            COALESCE(v.Estado, 'Sin estado') AS Estado
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
        JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
        JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
        JOIN tb_alm_movimientos m ON e.clm_alm_etiquetado_idMOVIMIENTO = m.clm_alm_mov_id
        LEFT JOIN tb_sedes s ON e.clm_alm_etiquetado_oficina_destino = s.clm_sedes_id
        LEFT JOIN tb_alm_anaquel a ON e.clm_alm_etiquetado_anaquel = a.clm_alm_anaquel_id
        LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
        WHERE e.clm_etiquetado_CODIGO = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $query);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        n360_qs_json([
            'ok' => true,
            'mode' => 'etiqueta',
            'code' => $query,
            'product' => n360_qs_product_payload($row),
            'trace' => [
                'sede' => n360_qs_text($row['sede_nombre'] ?? ''),
                'anaquel' => n360_qs_text($row['anaquel'] ?? ''),
                'fecha_ingreso' => n360_qs_text($row['fecha_movimiento'] ?? ''),
                'fecha_etiquetado' => n360_qs_text($row['fecha_etiquetado'] ?? ''),
                'estado_etiqueta' => n360_qs_text($row['estado_etiqueta'] ?? ''),
                'cantidad_movimiento' => n360_qs_number($row['cantidad_movimiento'] ?? null),
                'observacion' => n360_qs_text($row['observacion_movimiento'] ?? ''),
            ],
        ]);
    }
}

$stmt = $conn->prepare("
    SELECT
        p.clm_alm_producto_id AS id_producto,
        p.clm_alm_producto_codigo AS codigo_producto,
        p.clm_alm_producto_NOMBRE AS producto_nombre,
        p.clm_alm_producto_unidad AS unidproducto,
        c.clm_alm_categoria_NOMBRE AS categoria,
        c.clm_alm_categoria_DESCRIPCION AS categoria_descripcion,
        cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
        COALESCE(v.Stock_Actual, 0) AS Stock_Actual,
        COALESCE(v.Estado, 'Sin estado') AS Estado
    FROM tb_alm_producto p
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
    LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
    WHERE p.clm_alm_producto_codigo = ?
    LIMIT 1
");
$stmt->bind_param('s', $query);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    n360_qs_json([
        'ok' => true,
        'mode' => 'producto',
        'code' => $query,
        'product' => n360_qs_product_payload($row),
        'trace' => null,
    ]);
}

$like = '%' . $query . '%';
$stmt = $conn->prepare("
    SELECT
        p.clm_alm_producto_id AS id_producto,
        p.clm_alm_producto_codigo AS codigo_producto,
        p.clm_alm_producto_NOMBRE AS producto_nombre,
        p.clm_alm_producto_unidad AS unidproducto,
        c.clm_alm_categoria_NOMBRE AS categoria,
        c.clm_alm_categoria_DESCRIPCION AS categoria_descripcion,
        cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
        COALESCE(v.Stock_Actual, 0) AS Stock_Actual,
        COALESCE(v.Estado, 'Sin estado') AS Estado
    FROM tb_alm_producto p
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
    LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
    WHERE p.clm_alm_producto_codigo LIKE ? OR p.clm_alm_producto_NOMBRE LIKE ?
    ORDER BY p.clm_alm_producto_NOMBRE ASC
    LIMIT 8
");
$stmt->bind_param('ss', $like, $like);
$stmt->execute();
$result = $stmt->get_result();
$suggestions = [];

while ($item = $result->fetch_assoc()) {
    $suggestions[] = n360_qs_product_payload($item);
}

$stmt->close();

n360_qs_json([
    'ok' => false,
    'message' => $isEtiqueta ? 'Etiqueta no encontrada.' : 'Producto no encontrado.',
    'suggestions' => $suggestions,
], 404);
