<?php
// 01_contratos/planillas/planillas_service.php
if (!defined('ACCESS_GRANTED')) { http_response_code(403); exit('No direct access'); }

/**
 * Construye condiciones WHERE + parámetros para filtros.
 * $filters = [
 *  'search' => string (nombre o DNI),
 *  'trab_id' => int|null,
 *  'estado' => string|null (valor en clm_pl_tra_estado),
 *  'fecha_tipo' => 'fechregistro'|'fechaingrespl'|'fechasalida',
 *  'desde' => 'YYYY-MM-DD'|null,
 *  'hasta' => 'YYYY-MM-DD'|null
 * ]
 */
function planillas_build_where(array $filters, array &$params, string &$types): string {
    $w = ["1=1"];

    if (!empty($filters['search'])) {
        $w[] = "(tr.clm_tra_nombres LIKE ? OR tr.clm_tra_dni LIKE ?)";
        $like = "%".$filters['search']."%";
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
    }

    if (!empty($filters['trab_id'])) {
        $w[] = "t.clm_pl_trabid = ?";
        $params[] = (int)$filters['trab_id']; $types .= 'i';
    }

    if (!empty($filters['estado'])) {
        $w[] = "UPPER(t.clm_pl_tra_estado) = UPPER(?)";
        $params[] = $filters['estado']; $types .= 's';
    }

    // Rango de fechas por tipo
    $fechaCampo = "t.clm_pl_fechregistro";
    if (!empty($filters['fecha_tipo'])) {
        if ($filters['fecha_tipo'] === 'fecharegistro') $fechaCampo = "t.clm_pl_fechregistro"; // alias tolerante
        if ($filters['fecha_tipo'] === 'fechregistro')   $fechaCampo = "t.clm_pl_fechregistro";
        if ($filters['fecha_tipo'] === 'fechaingrespl')  $fechaCampo = "t.clm_pl_fechaingrespl";
        if ($filters['fecha_tipo'] === 'fechasalida')    $fechaCampo = "t.clm_pl_fechasalida";
    }

    if (!empty($filters['desde'])) {
        $w[] = "$fechaCampo >= ?";
        $params[] = $filters['desde']; $types .= 's';
    }
    if (!empty($filters['hasta'])) {
        $w[] = "$fechaCampo <= ?";
        $params[] = $filters['hasta']; $types .= 's';
    }

    return "WHERE ".implode(" AND ", $w);
}

/**
 * Lista paginada de planillas.
 * Retorna: ['rows'=>[], 'total'=>int]
 */
function planillas_list(mysqli $conn, array $filters, int $page=1, int $limit=50, string $sort='t.clm_pl_fechregistro', string $dir='DESC'): array {
    $page   = max(1, $page);
    $limit  = max(1, min(200, $limit));
    $offset = ($page-1)*$limit;

    // White-list de campos ordenables
    $sortable = [
        't.clm_pl_fechregistro','t.clm_pl_fechaingrespl','t.clm_pl_fechasalida',
        't.clm_pl_tra_estado','tr.clm_tra_nombres','tr.clm_tra_dni'
    ];
    if (!in_array($sort, $sortable, true)) $sort = 't.clm_pl_fechregistro';
    $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

    $params = []; $types = '';
    $where = planillas_build_where($filters, $params, $types);

    // COUNT
    $sqlCount = "
        SELECT COUNT(*) AS total
        FROM tb_tpln t
        INNER JOIN tb_trabajador tr ON tr.clm_tra_id = t.clm_pl_trabid
        $where
    ";
    $stmt = $conn->prepare($sqlCount);
    if ($types) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // DATA
    $sql = "
        SELECT
            t.clm_pl_id, t.clm_pl_trabid, tr.clm_tra_nombres, tr.clm_tra_dni, tr.clm_tra_cargo,
            t.clm_pl_tra_estado, t.clm_pl_fechregistro, t.clm_pl_fechaingrespl, t.clm_pl_fechasalida,
            t.clm_pl_doc, t.clm_pl_com
        FROM tb_tpln t
        INNER JOIN tb_trabajador tr ON tr.clm_tra_id = t.clm_pl_trabid
        $where
        ORDER BY $sort $dir
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    if ($types) { mysqli_bind_params_ref($stmt, $types, $params); }
    else { $stmt->bind_param('ii', $limit, $offset); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows'=>$rows, 'total'=>$total];
}

/** Detalle por ID de planilla */
function planillas_get(mysqli $conn, int $pl_id): ?array {
    $sql = "
        SELECT
            t.*,
            tr.clm_tra_nombres, tr.clm_tra_dni, tr.clm_tra_cargo
        FROM tb_tpln t
        INNER JOIN tb_trabajador tr ON tr.clm_tra_id = t.clm_pl_trabid
        WHERE t.clm_pl_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $pl_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ?: null;
}

/** Opciones de trabajadores (para el filtro) */
function planillas_trabajadores_options(mysqli $conn): array {
    $sql = "SELECT clm_tra_id, clm_tra_nombres, clm_tra_dni FROM tb_trabajador ORDER BY clm_tra_nombres ASC";
    $res = $conn->query($sql);
    $ops = [];
    while ($r = $res->fetch_assoc()) {
        $ops[] = $r;
    }
    return $ops;
}
