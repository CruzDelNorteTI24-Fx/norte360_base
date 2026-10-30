<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../01_almacen/movimiento_backend.php';
require_once __DIR__ . '/../01_almacen/producto_movimientos_helpers.php';

if (!isset($_SESSION['usuario'])) {
    alm_json(['ok' => false, 'message' => 'Sesion no iniciada.'], 401);
}

function trace_api_can_contabilidad(): bool {
    if (($_SESSION['web_rol'] ?? '') === 'Admin') {
        return true;
    }

    $moduleIds = alm_session_module_ids();
    if ($moduleIds === ['all']) {
        return true;
    }

    return in_array(12, $moduleIds, true);
}

if (!trace_api_can_contabilidad()) {
    alm_json(['ok' => false, 'message' => 'No tienes permiso para gestionar activos fijos.'], 403);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    alm_json(['ok' => false, 'message' => 'No se pudo conectar a la base de datos.'], 500);
}

$conn->set_charset('utf8mb4');

function trace_api_scope_sql(): string {
    return "(
        m.clm_alm_mov_orgn = 12
        OR UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), '')) = 'ACTIVOS'
        OR UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), '')) = 'ACTIVO_FIJO'
    )";
}

function trace_api_label(mysqli $conn, int $labelId, bool $forUpdate = false): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $detailSql = alm_activos_detalle_disponible($conn) ? 'e.clm_alm_etiquetado_ubicacion_detalle' : 'NULL';
    return alm_fetch_one($conn, "
        SELECT
            e.clm_alm_etiquetado_id AS etiqueta_id,
            COALESCE(NULLIF(TRIM(e.clm_etiquetado_CODIGO), ''), CONCAT('ETQ-', e.clm_alm_etiquetado_id)) AS etiqueta_codigo,
            COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'PENDIENTE') AS etiqueta_estado,
            e.clm_alm_etiquetado_oficina_destino AS sede_actual_id,
            $detailSql AS ubicacion_detalle,
            p.clm_alm_producto_codigo AS producto_codigo,
            p.clm_alm_producto_NOMBRE AS producto_nombre
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
        JOIN tb_alm_movimientos m ON e.clm_alm_etiquetado_idMOVIMIENTO = m.clm_alm_mov_id
        WHERE e.clm_alm_etiquetado_id = ?
          AND " . trace_api_scope_sql() . "
        LIMIT 1$lock
    ", 'i', [$labelId]);
}

function trace_api_validate_csrf(array $payload): void {
    $token = $payload['csrf'] ?? '';
    $sessionToken = $_SESSION['conta_trace_csrf'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || $token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        alm_json(['ok' => false, 'message' => 'Token de seguridad invalido. Actualiza la pagina.'], 419);
    }
}

function trace_api_history_rows(mysqli $conn, int $labelId): array {
    $hasDetail = alm_activos_detalle_disponible($conn);
    $beforeSql = $hasDetail ? 'h.clm_alm_etiquetadoofi_detalle_antes' : 'NULL';
    $afterSql = $hasDetail ? 'h.clm_alm_etiquetadoofi_detalle_despues' : 'NULL';
    return alm_fetch_all($conn, "
        SELECT
            h.clm_alm_etiquetadoofi_id AS id,
            h.clm_alm_etiquetadoofi_datetime AS fecha,
            h.clm_alm_etiquetadoofi_sedeIDantes AS sede_antes_id,
            h.clm_alm_etiquetadoofi_sedeIDdespues AS sede_despues_id,
            $beforeSql AS detalle_antes,
            $afterSql AS detalle_despues,
            COALESCE(NULLIF(TRIM(sa.clm_sedes_name), ''), IF(h.clm_alm_etiquetadoofi_sedeIDantes IS NULL, 'Sin ubicacion', CONCAT('Sede ', h.clm_alm_etiquetadoofi_sedeIDantes))) AS sede_antes,
            COALESCE(NULLIF(TRIM(sd.clm_sedes_name), ''), IF(h.clm_alm_etiquetadoofi_sedeIDdespues IS NULL, 'Sin ubicacion', CONCAT('Sede ', h.clm_alm_etiquetadoofi_sedeIDdespues))) AS sede_despues,
            COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u.usuario), ''), CONCAT('Usuario ', h.clm_alm_etiquetadoofi_idUSER)) AS usuario
        FROM tb_alm_etiquetadoofi h
        LEFT JOIN tb_sedes sa ON h.clm_alm_etiquetadoofi_sedeIDantes = sa.clm_sedes_id
        LEFT JOIN tb_sedes sd ON h.clm_alm_etiquetadoofi_sedeIDdespues = sd.clm_sedes_id
        LEFT JOIN tb_usuarios u ON h.clm_alm_etiquetadoofi_idUSER = u.id_usuario
        WHERE h.clm_alm_etiquetadoofi_etiref = ?
        ORDER BY h.clm_alm_etiquetadoofi_datetime DESC, h.clm_alm_etiquetadoofi_id DESC
    ", 'i', [$labelId]);

}

function trace_api_history(mysqli $conn): void {
    $labelId = (int)($_GET['id'] ?? 0);
    if ($labelId <= 0) {
        alm_json(['ok' => false, 'message' => 'Etiqueta invalida.'], 422);
    }
    $label = trace_api_label($conn, $labelId);
    if (!$label) {
        alm_json(['ok' => false, 'message' => 'No se encontro la etiqueta de activos.'], 404);
    }
    alm_json([
        'ok' => true,
        'label' => $label,
        'rows' => trace_api_history_rows($conn, $labelId),
    ]);
}

function trace_api_detail(mysqli $conn): void {
    $labelId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($labelId === false) {
        alm_json(['ok' => false, 'message' => 'Etiqueta invalida.'], 422);
    }
    $detailSql = alm_activos_detalle_disponible($conn) ? 'e.clm_alm_etiquetado_ubicacion_detalle' : 'NULL';
    $label = alm_fetch_one($conn, "
        SELECT
            e.clm_alm_etiquetado_id AS etiqueta_id,
            COALESCE(NULLIF(TRIM(e.clm_etiquetado_CODIGO), ''), CONCAT('ETQ-', e.clm_alm_etiquetado_id)) AS etiqueta_codigo,
            COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'PENDIENTE') AS etiqueta_estado,
            e.clm_alm_etiquetado_FECHA AS etiqueta_fecha,
            $detailSql AS ubicacion_detalle,
            COALESCE(NULLIF(TRIM(s.clm_sedes_name), ''), 'Sin ubicacion') AS sede_nombre,
            p.clm_alm_producto_id AS producto_id,
            p.clm_alm_producto_codigo AS producto_codigo,
            p.clm_alm_producto_NOMBRE AS producto_nombre,
            p.clm_alm_producto_unidad AS producto_unidad,
            p.clm_alm_producto_DESCRIPCION AS producto_descripcion,
            CONCAT_WS(' ', c.clm_alm_categoria_NOMBRE, c.clm_alm_categoria_DESCRIPCION) AS categoria,
            m.clm_alm_mov_id AS movimiento_id,
            m.clm_alm_mov_TIPO AS movimiento_tipo,
            m.clm_alm_mov_fecha_registro AS movimiento_fecha,
            m.clm_alm_mov_cantidad AS movimiento_cantidad,
            m.clm_alm_mov_OBSERVACION AS movimiento_observacion,
            m.clm_mov_factura AS documento_referencia,
            m.clm_mov_ruc AS proveedor,
            COALESCE(NULLIF(TRIM(n.clm_nota_sco), ''), CONCAT('MOV-', m.clm_alm_mov_id)) AS nota_codigo,
            COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(u.usuario), ''), CONCAT('Usuario ', m.clm_alm_mov_iduser)) AS movimiento_usuario
        FROM tb_alm_etiquetado e
        JOIN tb_alm_producto p ON p.clm_alm_producto_id = e.clm_alm_etiquetado_idPRODUCTO
        JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = e.clm_alm_etiquetado_idMOVIMIENTO
        LEFT JOIN tb_alm_categoria c ON c.clm_alm_categoria_id = p.clm_alm_producto_idCATEGORIA
        LEFT JOIN tb_sedes s ON s.clm_sedes_id = e.clm_alm_etiquetado_oficina_destino
        LEFT JOIN tb_notas_salida n ON n.clm_nota_id = m.clm_alm_mov_idNOTA
        LEFT JOIN tb_usuarios u ON u.id_usuario = m.clm_alm_mov_iduser
        WHERE e.clm_alm_etiquetado_id = ? AND " . trace_api_scope_sql() . "
        LIMIT 1
    ", 'i', [$labelId]);
    if (!$label) {
        alm_json(['ok' => false, 'message' => 'No se encontro la etiqueta de activos.'], 404);
    }
    alm_json([
        'ok' => true,
        'label' => $label,
        'history' => trace_api_history_rows($conn, $labelId),
        'product_movements' => alm_producto_movimientos($conn, (int)$label['producto_id']),
    ]);
}

function trace_api_move(mysqli $conn, array $payload): void {
    trace_api_validate_csrf($payload);

    $labelId = filter_var($payload['label_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $newSedeId = filter_var($payload['new_sede_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($labelId === false || $newSedeId === false) {
        alm_json(['ok' => false, 'message' => 'Selecciona una etiqueta y una nueva ubicacion.'], 422);
    }

    $hasDetail = alm_activos_detalle_disponible($conn);
    try {
        $newDetail = alm_activos_detalle($payload['ubicacion_detalle'] ?? '');
        $expectedDetail = alm_activos_detalle($payload['expected_detalle'] ?? '');
    } catch (InvalidArgumentException $e) {
        alm_json(['ok' => false, 'message' => $e->getMessage()], 422);
    }
    if (!$hasDetail && $newDetail !== '') {
        alm_json(['ok' => false, 'message' => 'El detalle de ubicación aún no está habilitado. Contacta al administrador.'], 409);
    }
    $expectedSede = filter_var($payload['expected_sede_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($hasDetail && ($expectedSede === false || !array_key_exists('expected_detalle', $payload))) {
        alm_json(['ok' => false, 'message' => 'Actualiza la página antes de mover el activo.'], 409);
    }

    $sede = alm_fetch_one($conn, "
        SELECT clm_sedes_id AS id, clm_sedes_name AS nombre
        FROM tb_sedes
        WHERE clm_sedes_id = ?
        LIMIT 1
    ", 'i', [$newSedeId]);

    if (!$sede) {
        alm_json(['ok' => false, 'message' => 'La nueva ubicacion no existe.'], 422);
    }

    $userId = alm_user_id($conn);
    if ($userId <= 0) {
        alm_json(['ok' => false, 'message' => 'No se pudo identificar al usuario de sesion.'], 422);
    }

    try {
        $conn->begin_transaction();
        $label = trace_api_label($conn, $labelId, true);
        if (!$label) {
            throw new RuntimeException('No se encontro la etiqueta de activos.', 404);
        }

        $estado = strtoupper(trim((string)($label['etiqueta_estado'] ?? '')));
        if ($estado === 'CONSUMIDO') {
            throw new RuntimeException('La etiqueta ya fue consumida y no puede trasladarse.', 409);
        }

        $currentSedeId = (int)($label['sede_actual_id'] ?? 0);
        $currentDetail = (string)($label['ubicacion_detalle'] ?? '');
        if ($hasDetail && ($expectedSede !== $currentSedeId || $expectedDetail !== $currentDetail)) {
            throw new RuntimeException('La ubicación cambió mientras tenías el formulario abierto. Actualiza la página antes de reintentar.', 409);
        }
        if ($currentSedeId === $newSedeId && $currentDetail === $newDetail) {
            $conn->commit();
            alm_json([
                'ok' => true,
                'message' => 'La etiqueta ya se encuentra en esa ubicacion.',
                'same_location' => true,
            ]);
        }

        $before = $currentSedeId > 0 ? $currentSedeId : null;
        $after = $newSedeId;
        $beforeDetail = $currentDetail !== '' ? $currentDetail : null;
        $afterDetail = $newDetail !== '' ? $newDetail : null;
        $detailColumns = $hasDetail ? ', clm_alm_etiquetadoofi_detalle_antes, clm_alm_etiquetadoofi_detalle_despues' : '';
        $detailValues = $hasDetail ? ', ?, ?' : '';

        $stmtHistory = $conn->prepare("
            INSERT INTO tb_alm_etiquetadoofi
            (clm_alm_etiquetadoofi_etiref, clm_alm_etiquetadoofi_sedeIDantes,
             clm_alm_etiquetadoofi_sedeIDdespues, clm_alm_etiquetadoofi_idUSER $detailColumns)
            VALUES (?, ?, ?, ? $detailValues)
        ");
        if (!$stmtHistory) {
            throw new RuntimeException('No se pudo preparar el historial de ubicación.');
        }
        $historyParams = [$labelId, $before, $after, $userId];
        if ($hasDetail) {
            array_push($historyParams, $beforeDetail, $afterDetail);
        }
        alm_bind($stmtHistory, $hasDetail ? 'iiiiss' : 'iiii', $historyParams);
        if (!$stmtHistory->execute()) {
            throw new RuntimeException('No se pudo registrar el historial de ubicación.');
        }
        $stmtHistory->close();

        $detailSet = $hasDetail ? ', clm_alm_etiquetado_ubicacion_detalle = ?' : '';
        $stmtUpdate = $conn->prepare("
            UPDATE tb_alm_etiquetado
            SET clm_alm_etiquetado_oficina_destino = ? $detailSet
            WHERE clm_alm_etiquetado_id = ?
            LIMIT 1
        ");
        if (!$stmtUpdate) {
            throw new RuntimeException('No se pudo actualizar la ubicación de la etiqueta.');
        }
        $updateParams = $hasDetail ? [$after, $afterDetail, $labelId] : [$after, $labelId];
        alm_bind($stmtUpdate, $hasDetail ? 'isi' : 'ii', $updateParams);
        if (!$stmtUpdate->execute()) {
            throw new RuntimeException('No se pudo actualizar la ubicación de la etiqueta.');
        }
        $stmtUpdate->close();

        $conn->commit();
        alm_json([
            'ok' => true,
            'message' => 'Ubicacion actualizada correctamente.',
            'label_id' => $labelId,
            'new_sede_id' => $newSedeId,
            'new_sede_name' => (string)($sede['nombre'] ?? ('Sede ' . $newSedeId)),
            'new_ubicacion_detalle' => $afterDetail,
        ]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        $status = in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 500;
        alm_json(['ok' => false, 'message' => $status === 500 ? 'No se pudo guardar el traslado. Inténtalo nuevamente.' : $e->getMessage()], $status);
    }
}

$action = (string)($_GET['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = (string)($payload['action'] ?? $action);

    if ($action === 'move_location') {
        trace_api_move($conn, $payload);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'history') {
    trace_api_history($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'detail') {
    try {
        trace_api_detail($conn);
    } catch (Throwable $e) {
        alm_json(['ok' => false, 'message' => 'No se pudo consultar la informacion del activo. Intenta nuevamente.'], 500);
    }
}

alm_json(['ok' => false, 'message' => 'Accion no reconocida.'], 400);
