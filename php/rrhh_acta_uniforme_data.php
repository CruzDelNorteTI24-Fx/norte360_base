<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

function rrhh_acta_json(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rrhh_acta_fail(string $message, int $status = 400): void {
    rrhh_acta_json(['ok' => false, 'message' => $message], $status);
}

function rrhh_acta_has_access(): bool {
    if (!isset($_SESSION['usuario'])) return false;
    if (($_SESSION['web_rol'] ?? '') === 'Admin') return true;
    if (($_SESSION['permisos'] ?? []) === 'all') return true;
    $permisos = $_SESSION['permisos'] ?? [];
    $vistas = $_SESSION['vistas'] ?? [];
    return is_array($permisos)
        && in_array(6, array_map('intval', $permisos), true)
        && is_array($vistas)
        && in_array('rrhh-registeralm', $vistas, true);
}

function rrhh_acta_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = [];
    foreach ($params as $k => &$v) $refs[$k] = &$v;
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function rrhh_acta_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
    rrhh_acta_bind($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'No se pudo ejecutar la consulta.';
        $stmt->close();
        throw new RuntimeException($error);
    }
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function rrhh_acta_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = rrhh_acta_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function rrhh_acta_text($value, string $fallback = ''): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function rrhh_acta_number($value, int $decimals = 2): string {
    $n = (float)str_replace(',', '.', (string)($value ?? 0));
    return number_format($n, $decimals, '.', '');
}

function rrhh_acta_date_label($value): string {
    $ts = strtotime((string)$value);
    if (!$ts) $ts = time();
    $months = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',7=>'JULIO',8=>'AGOSTO',9=>'SETIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];
    return date('d', $ts) . ' de ' . $months[(int)date('n', $ts)] . ' del ' . date('Y', $ts);
}

function rrhh_acta_safe_filename(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));
    return trim($value, '_') ?: 'acta_uniformes';
}

function rrhh_acta_codigo(array $acta): string {
    $codigo = rrhh_acta_text($acta['clm_rrhh_acta_codigo'] ?? '');
    if ($codigo !== '') {
        return $codigo;
    }

    $serie = rrhh_acta_text($acta['clm_rrhh_acta_serie'] ?? 'RA', 'RA');
    $corr = (int)($acta['clm_rrhh_acta_corr'] ?? 0);
    if ($corr > 0) {
        return $serie . '-' . str_pad((string)$corr, 4, '0', STR_PAD_LEFT);
    }

    return 'RA-' . str_pad((string)(int)($acta['clm_rrhh_acta_id'] ?? 0), 4, '0', STR_PAD_LEFT);
}

if (!rrhh_acta_has_access()) {
    rrhh_acta_fail('No tienes permiso para descargar actas de uniformes.', isset($_SESSION['usuario']) ? 403 : 401);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    rrhh_acta_fail('No se pudo conectar a la base de datos.', 500);
}

$conn->set_charset('utf8mb4');

try {
    $idActa = (int)($_GET['id_acta'] ?? 0);
    $idNota = (int)($_GET['id_nota'] ?? 0);

    if ($idActa <= 0 && $idNota <= 0) {
        rrhh_acta_fail('No se encontro un acta valida.');
    }

    $where = $idActa > 0 ? 'a.clm_rrhh_acta_id = ?' : 'a.clm_rrhh_acta_idnota = ?';
    $param = $idActa > 0 ? $idActa : $idNota;

    $acta = rrhh_acta_fetch_one($conn, "
        SELECT
            a.*,
            ns.clm_nota_id,
            ns.clm_nota_fecha,
            ns.clm_nota_responsable,
            ns.clm_nota_DNI,
            ns.clm_nota_serie,
            ns.clm_nota_corr,
            COALESCE(NULLIF(TRIM(CAST(ns.clm_nota_sco AS CHAR)), ''), CONCAT(ns.clm_nota_serie, '-', LPAD(ns.clm_nota_corr, 4, '0'))) AS nota_codigo,
            ns.clm_nota_motivo
        FROM tb_rrhh_acta_uniformes a
        JOIN tb_notas_salida ns ON ns.clm_nota_id = a.clm_rrhh_acta_idnota
        WHERE {$where}
          AND a.clm_rrhh_acta_estado = 1
        LIMIT 1
    ", 'i', [$param]);

    if (!$acta) {
        rrhh_acta_fail('No se encontro el acta solicitada.', 404);
    }

    $actaCodigo = rrhh_acta_codigo($acta);
    $notaCodigo = rrhh_acta_text($acta['nota_codigo'] ?? '');

    $movs = rrhh_acta_fetch_all($conn, "
        SELECT
            m.clm_alm_mov_id,
            m.clm_alm_mov_cantidad,
            m.clm_alm_mov_preciounitario,
            m.clm_alm_mov_monto,
            m.clm_alm_mov_OBSERVACION,
            p.clm_alm_producto_codigo,
            p.clm_alm_producto_NOMBRE,
            p.clm_alm_producto_unidad
        FROM tb_alm_movimientos m
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
        WHERE m.clm_alm_mov_idNOTA = ?
        ORDER BY CAST(NULLIF(m.clm_alm_mov_itmtable, '') AS UNSIGNED) ASC, m.clm_alm_mov_id ASC
    ", 'i', [(int)$acta['clm_nota_id']]);

    $items = [];
    foreach ($movs as $idx => $mov) {
        $codigo = rrhh_acta_text($mov['clm_alm_producto_codigo'] ?? '', 'S/C');
        $nombre = rrhh_acta_text($mov['clm_alm_producto_NOMBRE'] ?? '', 'Producto sin nombre');
        $unidad = rrhh_acta_text($mov['clm_alm_producto_unidad'] ?? '');
        $items[] = [
            'item' => $idx + 1,
            'cantidad' => rrhh_acta_number($mov['clm_alm_mov_cantidad'] ?? 0, 3),
            'detalle' => '(' . $codigo . ') ' . $nombre . ($unidad !== '' ? ' - ' . $unidad : ''),
            'talla' => '',
            'valor' => rrhh_acta_number($mov['clm_alm_mov_monto'] ?? 0, 2),
            'observaciones' => rrhh_acta_text($mov['clm_alm_mov_OBSERVACION'] ?? ''),
        ];
    }

    rrhh_acta_json([
        'ok' => true,
        'actaData' => [
            'id' => (int)$acta['clm_rrhh_acta_id'],
            'actaCodigo' => $actaCodigo,
            'actaSerie' => rrhh_acta_text($acta['clm_rrhh_acta_serie'] ?? 'RA', 'RA'),
            'actaCorr' => (int)($acta['clm_rrhh_acta_corr'] ?? 0),
            'notaId' => (int)$acta['clm_nota_id'],
            'notaCodigo' => $notaCodigo,
            'fechaEntrega' => rrhh_acta_text($acta['clm_rrhh_acta_fecha_entrega'] ?? ''),
            'fechaEntregaLabel' => rrhh_acta_date_label($acta['clm_rrhh_acta_fecha_entrega'] ?? ''),
            'trabajadorNombre' => rrhh_acta_text($acta['clm_rrhh_acta_trabajador_nombre'] ?? ''),
            'trabajadorDni' => rrhh_acta_text($acta['clm_rrhh_acta_trabajador_dni'] ?? ''),
            'area' => rrhh_acta_text($acta['clm_rrhh_acta_area'] ?? 'OFICINA', 'OFICINA'),
            'posicion' => rrhh_acta_text($acta['clm_rrhh_acta_posicion'] ?? 'FULL_TIME', 'FULL_TIME'),
            'motivoActa' => rrhh_acta_text($acta['clm_rrhh_acta_motivo'] ?? 'INICIO_CONTRATO_CORTESIA', 'INICIO_CONTRATO_CORTESIA'),
            'motivoNota' => rrhh_acta_text($acta['clm_nota_motivo'] ?? ''),
            'total' => rrhh_acta_number($acta['clm_rrhh_acta_total'] ?? 0, 2),
            'descuenta' => (int)($acta['clm_rrhh_acta_descuenta'] ?? 0),
            'cuotas' => (int)($acta['clm_rrhh_acta_cuotas'] ?? 1),
            'fechaDescuento' => rrhh_acta_text($acta['clm_rrhh_acta_fecha_descuento'] ?? ''),
            'observaciones' => rrhh_acta_text($acta['clm_rrhh_acta_observaciones'] ?? ''),
            'recibe' => [
                'nombre' => rrhh_acta_text($acta['clm_rrhh_acta_recibe_nombre'] ?? ''),
                'dni' => rrhh_acta_text($acta['clm_rrhh_acta_recibe_dni'] ?? ''),
                'cargo' => rrhh_acta_text($acta['clm_rrhh_acta_recibe_cargo'] ?? 'EMPLEADO', 'EMPLEADO'),
            ],
            'entrega' => [
                'nombre' => rrhh_acta_text($acta['clm_rrhh_acta_entrega_nombre'] ?? ''),
                'dni' => rrhh_acta_text($acta['clm_rrhh_acta_entrega_dni'] ?? ''),
                'cargo' => rrhh_acta_text($acta['clm_rrhh_acta_entrega_cargo'] ?? 'ASISTENTE', 'ASISTENTE'),
            ],
            'items' => $items,
            'fileName' => rrhh_acta_safe_filename('acta_uniformes_' . $actaCodigo . ($notaCodigo !== '' ? '_' . $notaCodigo : '')) . '.pdf',
        ],
    ]);
} catch (Throwable $e) {
    rrhh_acta_fail($e->getMessage() ?: 'No se pudo preparar el acta.', 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}
