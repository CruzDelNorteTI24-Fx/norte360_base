<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../01_almacen/movimiento_backend.php';

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
    return alm_fetch_one($conn, "
        SELECT
            e.clm_alm_etiquetado_id AS etiqueta_id,
            COALESCE(NULLIF(TRIM(e.clm_etiquetado_CODIGO), ''), CONCAT('ETQ-', e.clm_alm_etiquetado_id)) AS etiqueta_codigo,
            COALESCE(NULLIF(TRIM(e.clm_alm_etiquetado_ESTADO), ''), 'PENDIENTE') AS etiqueta_estado,
            e.clm_alm_etiquetado_oficina_destino AS sede_actual_id,
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
    $token = (string)($payload['csrf'] ?? '');
    $sessionToken = (string)($_SESSION['conta_trace_csrf'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        alm_json(['ok' => false, 'message' => 'Token de seguridad invalido. Actualiza la pagina.'], 419);
    }
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

    $rows = alm_fetch_all($conn, "
        SELECT
            h.clm_alm_etiquetadoofi_id AS id,
            h.clm_alm_etiquetadoofi_datetime AS fecha,
            h.clm_alm_etiquetadoofi_sedeIDantes AS sede_antes_id,
            h.clm_alm_etiquetadoofi_sedeIDdespues AS sede_despues_id,
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

    alm_json([
        'ok' => true,
        'label' => $label,
        'rows' => $rows,
    ]);
}

function trace_api_move(mysqli $conn, array $payload): void {
    trace_api_validate_csrf($payload);

    $labelId = (int)($payload['label_id'] ?? 0);
    $newSedeId = (int)($payload['new_sede_id'] ?? 0);

    if ($labelId <= 0 || $newSedeId <= 0) {
        alm_json(['ok' => false, 'message' => 'Selecciona una etiqueta y una nueva ubicacion.'], 422);
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

    $conn->begin_transaction();
    try {
        $label = trace_api_label($conn, $labelId, true);
        if (!$label) {
            throw new RuntimeException('No se encontro la etiqueta de activos.');
        }

        $estado = strtoupper(trim((string)($label['etiqueta_estado'] ?? '')));
        if ($estado === 'CONSUMIDO') {
            throw new RuntimeException('La etiqueta ya fue consumida y no puede trasladarse.');
        }

        $currentSedeId = (int)($label['sede_actual_id'] ?? 0);
        if ($currentSedeId === $newSedeId) {
            $conn->commit();
            alm_json([
                'ok' => true,
                'message' => 'La etiqueta ya se encuentra en esa ubicacion.',
                'same_location' => true,
            ]);
        }

        $before = $currentSedeId > 0 ? $currentSedeId : null;
        $after = $newSedeId;

        $stmtHistory = $conn->prepare("
            INSERT INTO tb_alm_etiquetadoofi
            (clm_alm_etiquetadoofi_etiref, clm_alm_etiquetadoofi_sedeIDantes,
             clm_alm_etiquetadoofi_sedeIDdespues, clm_alm_etiquetadoofi_idUSER)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmtHistory) {
            throw new RuntimeException($conn->error ?: 'No se pudo preparar el historial de ubicacion.');
        }
        $historyParams = [$labelId, $before, $after, $userId];
        alm_bind($stmtHistory, 'iiii', $historyParams);
        $stmtHistory->execute();
        $stmtHistory->close();

        $stmtUpdate = $conn->prepare("
            UPDATE tb_alm_etiquetado
            SET clm_alm_etiquetado_oficina_destino = ?
            WHERE clm_alm_etiquetado_id = ?
            LIMIT 1
        ");
        if (!$stmtUpdate) {
            throw new RuntimeException($conn->error ?: 'No se pudo actualizar la ubicacion de la etiqueta.');
        }
        $updateParams = [$after, $labelId];
        alm_bind($stmtUpdate, 'ii', $updateParams);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $conn->commit();
        alm_json([
            'ok' => true,
            'message' => 'Ubicacion actualizada correctamente.',
            'label_id' => $labelId,
            'new_sede_id' => $newSedeId,
            'new_sede_name' => (string)($sede['nombre'] ?? ('Sede ' . $newSedeId)),
        ]);
    } catch (Throwable $e) {
        if ($conn->errno === 0) {
            $conn->rollback();
        } else {
            @mysqli_rollback($conn);
        }
        alm_json(['ok' => false, 'message' => $e->getMessage()], 422);
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

alm_json(['ok' => false, 'message' => 'Accion no reconocida.'], 400);
