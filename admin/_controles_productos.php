<?php
// Consultas compartidas por la página de controles y su catálogo de productos.
function n360_controles_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    if ($types === '') {
        $result = $conn->query($sql);
        if (!$result) throw new RuntimeException('No se pudo consultar el catálogo de controles.');
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo consultar el catálogo de controles.');
    try {
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new RuntimeException('No se pudo consultar el catálogo de controles.');
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

function n360_controles_module_name($value): string {
    $name = trim((string)$value);
    if ($name === '') return 'Sin módulo relacionado';
    return function_exists('mb_convert_case') ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : ucwords($name);
}

function n360_controles_schema(mysqli $conn): array {
    $columns = [];
    foreach (n360_controles_rows($conn, "SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('tb_alm_area_control', 'tb_alm_tipo_control')") as $column) {
        $columns[$column['tabla']][] = $column['columna'];
    }
    $areaReady = !array_diff([
        'clm_alm_area_control_id', 'clm_alm_area_control_codigo', 'clm_alm_area_control_nombre',
        'clm_alm_area_control_descripcion', 'clm_alm_area_control_idmodulo', 'clm_alm_area_control_idtipo',
    ], $columns['tb_alm_area_control'] ?? []);
    $typeReady = !array_diff([
        'clm_alm_tipo_control_id', 'clm_alm_tipo_control_codigo',
        'clm_alm_tipo_control_nombre', 'clm_alm_tipo_control_descripcion',
    ], $columns['tb_alm_tipo_control'] ?? []);
    return ['areas' => $areaReady, 'tipos' => $typeReady, 'mapa' => $areaReady && $typeReady];
}
