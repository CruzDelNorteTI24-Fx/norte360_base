<?php
// Disponibilidad para ALTAS: nunca restringe productos, stock ni historial existentes.
function n360_categorias_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo consultar la disponibilidad de categorías.');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('No se pudo consultar la disponibilidad de categorías.');
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function n360_categorias_disponible(mysqli $conn): bool {
    // Solo la ausencia de la migración permite compatibilidad con el comportamiento anterior.
    // Una instalación dañada o un error de permisos no deben ignorar restricciones existentes.
    try {
        $result = $conn->query('SELECT categoria_id, modulo_id, habilitado, version, actualizado_por,
            actualizado_en FROM tb_alm_categoria_modulo LIMIT 0');
        if (!$result) {
            if ($conn->errno === 1146) return false;
            throw new RuntimeException('No se pudo consultar la configuración de categorías.');
        }
        $result->free();
        return true;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1146) return false;
        throw $e;
    }
}

function n360_categorias_modulo_origen(int $originId): int {
    // Orígenes operativos y módulos son identificadores distintos.
    $map = [1 => 3, 4 => 6, 12 => 12];
    if (!isset($map[$originId])) throw new InvalidArgumentException('Origen de producto no válido.');
    return $map[$originId];
}

function n360_categorias_filtro_alta(mysqli $conn, int $moduleId): string {
    if (!in_array($moduleId, [3, 6, 12], true)) throw new InvalidArgumentException('Módulo no válido para altas.');
    $sql = 'c.clm_alm_categoria_id <> 11';
    if (n360_categorias_disponible($conn)) {
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM tb_alm_categoria_modulo cm
            WHERE cm.categoria_id = c.clm_alm_categoria_id AND cm.modulo_id = ' . $moduleId . '
              AND cm.habilitado = 0)';
    }
    return $sql;
}

function n360_categorias_permite_alta(mysqli $conn, int $categoryId, int $moduleId): bool {
    if ($categoryId <= 0 || $categoryId === 11 || !in_array($moduleId, [3, 6, 12], true)) return false;
    if (!n360_categorias_disponible($conn)) return true;
    // El llamador bloquea primero tb_alm_categoria. Administración usa el mismo orden.
    $rows = n360_categorias_rows($conn, 'SELECT habilitado FROM tb_alm_categoria_modulo
        WHERE categoria_id = ? AND modulo_id = ? FOR UPDATE', 'ii', [$categoryId, $moduleId]);
    return !$rows || (int)$rows[0]['habilitado'] === 1;
}

function n360_categorias_version(int $categoryId, array $settings): string {
    $parts = [$categoryId];
    foreach ([3, 6, 12] as $moduleId) {
        $setting = $settings[$moduleId] ?? null;
        $parts[] = $setting ? [(int)$setting['habilitado'], (int)$setting['version']] : null;
    }
    return hash('sha256', json_encode($parts));
}
