<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');

require_once __DIR__ . '/../layout/sidebar_n360.php';

function req24_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['usuario'])) {
    req24_json(['ok' => false, 'message' => 'Sesion expirada. Inicia sesion nuevamente.'], 401);
}

if (!function_exists('n360_puede_modulo') || !n360_puede_modulo(12)) {
    req24_json(['ok' => false, 'message' => 'No tienes permiso para Contabilidad.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    req24_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
}

$token = (string)($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string)($_SESSION['requersen24_csrf'] ?? ''), $token)) {
    req24_json(['ok' => false, 'message' => 'Token de seguridad invalido. Actualiza la pagina.'], 403);
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function req24_is_admin(): bool
{
    return function_exists('n360_is_admin') && n360_is_admin();
}

function req24_uid(): int
{
    return (int)($_SESSION['id_usuario'] ?? 0);
}

function req24_text(string $key, int $max = 255): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function req24_required(string $key, string $label, int $max = 255): string
{
    $value = req24_text($key, $max);
    if ($value === null) {
        req24_json(['ok' => false, 'message' => $label . ' es obligatorio.'], 422);
    }
    return $value;
}

function req24_state(string $key = 'estado'): string
{
    $state = strtoupper(trim((string)($_POST[$key] ?? 'PENDIENTE')));
    $allowed = ['PENDIENTE', 'REVISADO', 'CORREGIDO', 'APROBADO', 'ANULADO'];
    return in_array($state, $allowed, true) ? $state : 'PENDIENTE';
}

function req24_allowed_areas(): array
{
    return [
        'ADMINISTRACION',
        'CONTABILIDAD',
        'FINANZAS',
        'OPERACIONES',
        'FLOTA',
        'MANTENIMIENTO',
        'ALMACEN',
        'COMBUSTIBLE',
        'RECURSOS_HUMANOS',
        'RECURSOS HUMANOS',
        'CALIDAD',
        'PEAJES',
        'ENCOMIENDAS',
        'SISTEMAS',
        'GERENCIA',
    ];
}

function req24_area_value(string $key = 'area'): ?string
{
    $value = strtoupper(trim((string)($_POST[$key] ?? '')));
    if ($value === '') {
        return null;
    }
    if (!array_key_exists($value, req24_allowed_areas())) {
        req24_json(['ok' => false, 'message' => 'Selecciona un area valida.'], 422);
    }
    return $value;
}

function req24_amount(): ?string
{
    $raw = trim(str_replace(',', '.', (string)($_POST['requerimiento_monto'] ?? '')));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw) || (float)$raw < 0) {
        req24_json(['ok' => false, 'message' => 'El monto del requerimiento no es valido.'], 422);
    }
    return number_format((float)$raw, 4, '.', '');
}

function req24_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function req24_row(mysqli $conn, int $id): ?array
{
    $sql = 'SELECT * FROM tb_requersen24 WHERE clm_requersen24_id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $params = [$id];
    req24_bind($stmt, 'i', $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function req24_history(?array $old, string $action, array $changes, string $newState): string
{
    $history = [];
    if ($old && !empty($old['clm_requersen24_histor'])) {
        $decoded = json_decode((string)$old['clm_requersen24_histor'], true);
        if (is_array($decoded)) {
            $history = $decoded;
        }
    }

    $history[] = [
        'fecha' => date('Y-m-d H:i:s'),
        'usuario_id' => req24_uid(),
        'usuario' => (string)($_SESSION['usuario'] ?? ''),
        'accion' => $action,
        'estado_anterior' => $old['clm_requersen24_estado'] ?? null,
        'estado_nuevo' => $newState,
        'cambios' => $changes,
    ];

    return json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function req24_exec(mysqli $conn, string $sql, string $types, array $params): void
{
    $stmt = $conn->prepare($sql);
    req24_bind($stmt, $types, $params);
    $stmt->execute();
    $stmt->close();
}

function req24_next_code(mysqli $conn): string
{
    $year = date('Y');
    $prefix = 'REQ-' . $year . '-';
    $like = $prefix . '%';
    $start = strlen($prefix) + 1;

    $stmt = $conn->prepare("\n        SELECT COALESCE(MAX(CAST(SUBSTRING(clm_requersen24_CODIGO_INTERNO, ?) AS UNSIGNED)), 0) AS last_number\n        FROM tb_requersen24\n        WHERE clm_requersen24_CODIGO_INTERNO LIKE ?\n    ");
    $params = [$start, $like];
    req24_bind($stmt, 'is', $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ((int)($row['last_number'] ?? 0)) + 1;
    return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

$action = (string)($_POST['action'] ?? '');
$inTransaction = false;

try {
    if ($action === 'save_quote') {
        if (!req24_is_admin()) {
            req24_json(['ok' => false, 'message' => 'Solo administradores pueden crear o editar cotizaciones.'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $cotizacion = req24_text('cotizacion');
        $solicitante = req24_text('solicitante');
        $cargo = req24_text('cargo');
        $area = req24_area_value();
        $comentario = req24_text('comentario', 8000);
        $estado = 'PENDIENTE';
        $uid = req24_uid();

        $conn->begin_transaction();
        $inTransaction = true;

        if ($id > 0) {
            $old = req24_row($conn, $id);
            if (!$old) {
                req24_json(['ok' => false, 'message' => 'No se encontro el registro.'], 404);
            }

            $codigo = (string)($old['clm_requersen24_CODIGO_INTERNO'] ?? '');
            $estado = (string)($old['clm_requersen24_estado'] ?? 'PENDIENTE');
            $history = req24_history($old, 'ACTUALIZA_COTIZACION', [
                'codigo_interno' => $codigo,
                'cotizacion' => $cotizacion,
                'solicitante' => $solicitante,
                'cargo' => $cargo,
                'area' => $area,
                'comentario' => $comentario,
                'estado' => $estado,
            ], $estado);

            req24_exec(
                $conn,
                'UPDATE tb_requersen24
                 SET clm_requersen24_COTIZACION = ?,
                     clm_requersen24_SOLICITANTE = ?,
                     clm_requersen24_CARGO = ?,
                     clm_requersen24_AREA = ?,
                     clm_requersen24_comentario = ?,
                     clm_requersen24_user_update = ?,
                     clm_requersen24_histor = ?
                 WHERE clm_requersen24_id = ?',
                'sssssisi',
                [$cotizacion, $solicitante, $cargo, $area, $comentario, $uid, $history, $id]
            );
        } else {
            $codigo = req24_next_code($conn);
            $history = req24_history(null, 'CREA_COTIZACION', [
                'codigo_interno' => $codigo,
                'cotizacion' => $cotizacion,
                'solicitante' => $solicitante,
                'cargo' => $cargo,
                'area' => $area,
                'comentario' => $comentario,
                'estado' => $estado,
            ], $estado);

            req24_exec(
                $conn,
                'INSERT INTO tb_requersen24 (
                    clm_requersen24_CODIGO_INTERNO,
                    clm_requersen24_COTIZACION,
                    clm_requersen24_SOLICITANTE,
                    clm_requersen24_CARGO,
                    clm_requersen24_AREA,
                    clm_requersen24_comentario,
                    clm_requersen24_estado,
                    clm_requersen24_user_registro,
                    clm_requersen24_histor
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sssssssis',
                [$codigo, $cotizacion, $solicitante, $cargo, $area, $comentario, $estado, $uid, $history]
            );
            $id = (int)$conn->insert_id;
        }

        $conn->commit();
        $inTransaction = false;

        req24_json([
            'ok' => true,
            'message' => 'Cotizacion guardada correctamente. Codigo interno: ' . $codigo,
            'id' => $id,
            'codigo' => $codigo,
        ]);
    }
    if ($action === 'save_requirement') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            req24_json(['ok' => false, 'message' => 'Selecciona una cotizacion valida.'], 422);
        }

        $reqCodigo = req24_text('requerimiento_codigo');
        $reqName = req24_text('requerimiento_name');
        $reqMonto = req24_amount();
        $reqComentario = req24_text('requerimiento_comentario', 8000);
        $estado = req24_state();
        $uid = req24_uid();

        if ($estado === 'ANULADO' && !req24_is_admin()) {
            req24_json(['ok' => false, 'message' => 'Solo administradores pueden anular registros.'], 403);
        }

        $conn->begin_transaction();
        $inTransaction = true;
        $old = req24_row($conn, $id);
        if (!$old) {
            req24_json(['ok' => false, 'message' => 'No se encontro el registro.'], 404);
        }
        if (($old['clm_requersen24_estado'] ?? '') === 'ANULADO' && !req24_is_admin()) {
            req24_json(['ok' => false, 'message' => 'Este registro esta anulado y solo un administrador puede editarlo.'], 403);
        }

        $history = req24_history($old, 'ACTUALIZA_REQUERIMIENTO', [
            'requerimiento_codigo' => $reqCodigo,
            'requerimiento_name' => $reqName,
            'requerimiento_monto' => $reqMonto,
            'estado' => $estado,
        ], $estado);

        req24_exec(
            $conn,
            'UPDATE tb_requersen24
             SET clm_requersen24_requerimiento_codigo = ?,
                 clm_requersen24_requerimiento_name = ?,
                 clm_requersen24_requerimiento_monto = ?,
                 clm_requersen24_requerimiento_comentario = ?,
                 clm_requersen24_estado = ?,
                 clm_requersen24_user_update = ?,
                 clm_requersen24_histor = ?
             WHERE clm_requersen24_id = ?',
            'sssssisi',
            [$reqCodigo, $reqName, $reqMonto, $reqComentario, $estado, $uid, $history, $id]
        );

        $conn->commit();
        req24_json(['ok' => true, 'message' => 'Requerimiento guardado correctamente.', 'id' => $id]);
    }

    req24_json(['ok' => false, 'message' => 'Accion no reconocida.'], 400);
} catch (Throwable $e) {
    if ($inTransaction) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('[requersen24 rollback] ' . $rollbackError->getMessage());
        }
    }

    $message = $e->getMessage();
    error_log('[requersen24 api] ' . $message);
    if (strpos($message, 'uk_requersen24_codigo_interno') !== false || stripos($message, 'Duplicate') !== false) {
        req24_json(['ok' => false, 'message' => 'No se pudo generar el codigo interno porque ya existe. Intenta guardar nuevamente.'], 409);
    }
    req24_json(['ok' => false, 'message' => 'No se pudo completar la operacion.'], 500);
}
