<?php
// El contexto lo define exclusivamente cada ruta PHP, nunca un parámetro del cliente.
function n360_trace_config(): array {
    $inventory = defined('N360_TRACE_CONTEXT') && N360_TRACE_CONTEXT === 'inventario';
    return $inventory ? [
        'context' => 'inventario', 'module' => 3,
        'title' => 'Trazabilidad de etiquetas', 'area' => 'Inventario', 'item' => 'artículo',
        'kicker' => 'Inventario - etiquetas de almacén',
        'description' => 'Consulta los artículos etiquetados de almacén, su ubicación vigente y el historial de traslados.',
        'view' => '01_almacen/trazabilidad_etiquetas.php',
        'api' => '01_almacen/trazabilidad_etiquetas_api.php',
        'register' => '01_almacen/formulario_movalm.php', 'register_label' => 'Registrar movimiento',
        'filename' => 'trazabilidad_inventario', 'doc_code' => 'N360-ALM-ETIQUETAS',
    ] : [
        'context' => 'contabilidad', 'module' => 12,
        'title' => 'Trazabilidad de activos', 'area' => 'Contabilidad', 'item' => 'activo',
        'kicker' => 'Contabilidad - activos fijos',
        'description' => 'Control por etiqueta, ubicación vigente e historial de traslados sin alterar los movimientos de almacén.',
        'view' => '01_contabilidad/trazabilidad_activos.php',
        'api' => '01_contabilidad/trazabilidad_activos_api.php',
        'register' => '01_contabilidad/registro_activos.php', 'register_label' => 'Registrar activo',
        'filename' => 'trazabilidad_activos', 'doc_code' => 'N360-CONT-ACTIVOS',
    ];
}

function n360_trace_permitido(): bool {
    if (($_SESSION['web_rol'] ?? '') === 'Admin' || ($_SESSION['permisos'] ?? null) === 'all') return true;
    $modules = $_SESSION['permisos'] ?? [];
    return is_array($modules) && in_array(n360_trace_config()['module'], array_map('intval', $modules), true);
}

function n360_trace_scope_sql(?string $context = null): string {
    $context = $context ?? n360_trace_config()['context'];
    if ($context === 'inventario') {
        return "(p.clm_alm_producto_idCATEGORIA <> 11 AND (
            m.clm_alm_mov_orgn = 1 OR (
                (m.clm_alm_mov_orgn IS NULL OR m.clm_alm_mov_orgn = 0)
                AND UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN')) = 'ALMACEN'
            )
        ))";
    }
    return "(m.clm_alm_mov_orgn = 12
        OR UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), '')) = 'ACTIVOS'
        OR UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), '')) = 'ACTIVO_FIJO')";
}
