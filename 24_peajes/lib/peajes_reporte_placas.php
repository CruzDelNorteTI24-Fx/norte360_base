<?php
if (!defined('N360_PEAJES')) {
    exit('Acceso no permitido.');
}

function pje_report_plate_sql(string $column): string {
    return "UPPER(REPLACE(REPLACE(TRIM(" . pje_sql_text_expr($column) . "), '-', ''), ' ', ''))";
}

function pje_report_suppliers(mysqli $conn, bool $enabledOnly = true): array {
    return pje_fetch_all($conn, "
        SELECT r.clm_ruc_id AS id, TRIM(r.clm_ruc_BDRUCPROVEEDOR) AS ruc,
            r.clm_ruc_BDPROVEEDORES AS nombre,
            r.clm_ruc_BDABREVIATURA AS abreviatura,
            r.clm_ruc_reporte_placas AS habilitado,
            (SELECT COUNT(*) FROM tb_peajes_reporte_placas c
             WHERE c.clm_pjrp_idruc = r.clm_ruc_id AND c.clm_pjrp_activo = 1) AS placas
        FROM tb_ruc r
        WHERE TRIM(r.clm_ruc_BDRUCPROVEEDOR) REGEXP '^[0-9]{11}$'
        " . ($enabledOnly ? 'AND r.clm_ruc_reporte_placas = 1' : '') . "
        ORDER BY nombre, id
    ");
}

function pje_report_supplier(mysqli $conn, int $id, bool $lock = false): array {
    $row = pje_fetch_one($conn, "
        SELECT clm_ruc_id AS id, TRIM(clm_ruc_BDRUCPROVEEDOR) AS ruc,
            clm_ruc_BDPROVEEDORES AS nombre,
            clm_ruc_reporte_placas AS habilitado
        FROM tb_ruc WHERE clm_ruc_id = ?
        " . ($lock ? 'FOR UPDATE' : ''), 'i', [$id]);
    if (!$row || !preg_match('/^[0-9]{11}$/D', (string)$row['ruc'])) {
        throw new InvalidArgumentException('Selecciona un proveedor con un RUC valido.');
    }
    return $row;
}

function pje_report_selected(mysqli $conn, int $supplierId): array {
    $rows = pje_fetch_all($conn, '
        SELECT clm_pjrp_idplaca AS id FROM tb_peajes_reporte_placas
        WHERE clm_pjrp_idruc = ? AND clm_pjrp_activo = 1 ORDER BY clm_pjrp_idplaca
    ', 'i', [$supplierId]);
    return array_map(static fn(array $row): int => (int)$row['id'], $rows);
}

function pje_report_version(array $supplier, array $selected): string {
    sort($selected, SORT_NUMERIC);
    return hash('sha256', json_encode([(int)$supplier['id'], (int)$supplier['habilitado'], $selected]));
}

function pje_report_configuration(mysqli $conn, int $supplierId): array {
    $supplier = pje_report_supplier($conn, $supplierId);
    $selected = pje_report_selected($conn, $supplierId);
    $plates = pje_fetch_all($conn, "
        SELECT clm_placas_id AS id, clm_placas_PLACA AS placa,
            clm_placas_BUS AS bus, clm_placas_ESTADO AS estado
        FROM tb_placas
        WHERE NULLIF(TRIM(clm_placas_PLACA), '') IS NOT NULL
        ORDER BY clm_placas_BUS, clm_placas_PLACA, clm_placas_id
    ");
    return ['proveedor' => $supplier, 'placas' => $plates, 'seleccionadas' => $selected,
        'version' => pje_report_version($supplier, $selected)];
}

function pje_report_save(mysqli $conn, int $supplierId, int $enabled, array $selected, string $version): void {
    if (!in_array($enabled, [0, 1], true) || count($selected) > 5000 || !array_is_list($selected)) {
        throw new InvalidArgumentException('La seleccion de placas no es valida.');
    }
    foreach ($selected as $id) {
        if (!is_int($id) || $id <= 0) {
            throw new InvalidArgumentException('La seleccion contiene una placa no valida.');
        }
    }
    $selected = array_values(array_unique($selected));
    sort($selected, SORT_NUMERIC);
    $conn->begin_transaction();
    try {
        $supplier = pje_report_supplier($conn, $supplierId, true);
        if (!hash_equals(pje_report_version($supplier, pje_report_selected($conn, $supplierId)), $version)) {
            throw new RuntimeException('La lista cambio en otra sesion. Vuelve a cargarla antes de guardar.', 409);
        }
        if ($selected) {
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            $plates = pje_fetch_all($conn, "SELECT clm_placas_id AS id,
                " . pje_report_plate_sql('clm_placas_PLACA') . " AS placa
                FROM tb_placas WHERE clm_placas_id IN ($placeholders) FOR UPDATE",
                str_repeat('i', count($selected)), $selected);
            if (count($plates) !== count($selected)) {
                throw new InvalidArgumentException('Una de las placas ya no existe en el catalogo.');
            }
            $normalized = array_column($plates, 'placa');
            if (in_array('', $normalized, true) || in_array(null, $normalized, true)
                || count(array_unique($normalized)) !== count($normalized)) {
                throw new InvalidArgumentException('Selecciona placas distintas y con un numero de placa registrado.');
            }
        }
        $stmt = $conn->prepare('UPDATE tb_ruc SET clm_ruc_reporte_placas = ? WHERE clm_ruc_id = ?');
        $stmt->bind_param('ii', $enabled, $supplierId);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo guardar el proveedor.');
        }
        $stmt->close();
        $removeSql = 'UPDATE tb_peajes_reporte_placas SET clm_pjrp_activo = 0
            WHERE clm_pjrp_idruc = ? AND clm_pjrp_activo = 1';
        $params = [$supplierId];
        if ($selected) {
            $removeSql .= ' AND clm_pjrp_idplaca NOT IN (' . implode(',', array_fill(0, count($selected), '?')) . ')';
            array_push($params, ...$selected);
        }
        $stmt = $conn->prepare($removeSql);
        pje_bind_params($stmt, str_repeat('i', count($params)), $params);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo actualizar la seleccion.');
        }
        $stmt->close();
        if ($selected) {
            $sql = 'INSERT INTO tb_peajes_reporte_placas (clm_pjrp_idruc, clm_pjrp_idplaca, clm_pjrp_activo) VALUES '
                . implode(',', array_fill(0, count($selected), '(?, ?, 1)'))
                . ' ON DUPLICATE KEY UPDATE clm_pjrp_activo = 1';
            $params = [];
            foreach ($selected as $id) {
                array_push($params, $supplierId, $id);
            }
            $stmt = $conn->prepare($sql);
            pje_bind_params($stmt, str_repeat('i', count($params)), $params);
            if (!$stmt->execute()) {
                throw new RuntimeException('No se pudo guardar la seleccion.');
            }
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function pje_report_data(mysqli $conn, array $filters, int $supplierId): array {
    $supplier = pje_report_supplier($conn, $supplierId);
    if ((int)$supplier['habilitado'] !== 1) {
        throw new RuntimeException('Este proveedor no tiene habilitado el reporte por placas.', 409);
    }
    $selected = pje_report_selected($conn, $supplierId);
    if (!$selected) {
        throw new RuntimeException('El proveedor no tiene placas seleccionadas para el reporte.', 409);
    }
    $baseFilters = $filters;
    $baseFilters['placa'] = '';
    $where = pje_where($baseFilters);
    $where['sql'] .= ' AND p.clm_pje_RUC = ?';
    $where['types'] .= 's';
    $where['params'][] = $supplier['ruc'];
    if (($filters['placa'] ?? '') !== '') {
        $where['sql'] .= ' AND ' . pje_report_plate_sql('p.clm_pje_NRO_PLACA') . " LIKE CONCAT('%', ?, '%')";
        $where['types'] .= 's';
        $where['params'][] = strtoupper(str_replace(['-', ' '], '', $filters['placa']));
    }
    // A normalized plate must match only once, even if the master has duplicate entries.
    $from = "FROM tb_peajes p
        INNER JOIN (
            SELECT MIN(pl.clm_placas_id) AS placa_id,
                " . pje_report_plate_sql('pl.clm_placas_PLACA') . " AS placa_normalizada
            FROM tb_peajes_reporte_placas c
            INNER JOIN tb_placas pl ON pl.clm_placas_id = c.clm_pjrp_idplaca
            WHERE c.clm_pjrp_idruc = ? AND c.clm_pjrp_activo = 1
                AND NULLIF(TRIM(pl.clm_placas_PLACA), '') IS NOT NULL
            GROUP BY placa_normalizada
        ) seleccion ON seleccion.placa_normalizada = " . pje_report_plate_sql('p.clm_pje_NRO_PLACA') . "
        INNER JOIN tb_placas pl ON pl.clm_placas_id = seleccion.placa_id
        WHERE {$where['sql']}";
    $types = 'i' . $where['types'];
    $params = array_merge([$supplierId], $where['params']);
    $rows = pje_fetch_all($conn, "SELECT
        MAX(pl.clm_placas_PLACA) AS placa, seleccion.placa_id,
        MAX(pl.clm_placas_BUS) AS bus, MAX(pl.`clm_placas_DUEÑO`) AS dueno,
        MAX(pl.`clm_placas_TIPO_VEHÍCULO`) AS tipo_vehiculo,
        COUNT(*) AS registros,
        COUNT(DISTINCT NULLIF(TRIM(p.clm_pje_NFACTURA), '')) AS facturas,
        COALESCE(SUM(p.clm_pje_TOTAL), 0) AS total,
        COALESCE(SUM(p.clm_pje_DETRACCION), 0) AS detraccion
        $from GROUP BY seleccion.placa_id
        ORDER BY tipo_vehiculo, registros DESC, total DESC, placa", $types, $params);
    $kpis = pje_fetch_one($conn, "SELECT COUNT(*) AS registros,
        COUNT(DISTINCT NULLIF(TRIM(p.clm_pje_NFACTURA), '')) AS facturas,
        COUNT(DISTINCT seleccion.placa_id) AS placas,
        COALESCE(SUM(p.clm_pje_TOTAL), 0) AS total,
        COALESCE(SUM(p.clm_pje_DETRACCION), 0) AS detraccion $from", $types, $params);
    return ['proveedor' => $supplier, 'rows' => $rows, 'kpis' => $kpis, 'filters' => $filters,
        'seleccionadas' => count($selected)];
}
