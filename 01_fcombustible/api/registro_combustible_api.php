<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

define('ACCESS_GRANTED', true);
define('N360_COMB_REG', true);

require_once __DIR__ . '/../../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/../lib/combustible_registro_helpers.php';

if (!isset($_SESSION['usuario'])) {
    comb_reg_json(['ok' => false, 'message' => 'Sesion expirada. Vuelve a iniciar sesion.'], 401);
}

if (!comb_reg_can_modulo(9)) {
    comb_reg_json(['ok' => false, 'message' => 'No tienes permiso para el modulo de combustible.'], 403);
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    comb_reg_json(['ok' => false, 'message' => 'No se pudo conectar a la base de datos.'], 500);
}

$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

$rawBody = file_get_contents('php://input');
$payload = [];
if ($rawBody !== false && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$action = comb_reg_clean_text($_GET['action'] ?? ($_POST['action'] ?? ($payload['action'] ?? '')), 60);

function comb_reg_api_recent_payload(mysqli $conn): array {
    return comb_reg_is_admin() ? comb_reg_recent($conn) : [];
}

function comb_reg_api_bootstrap(mysqli $conn): void {
    comb_reg_json([
        'ok' => true,
        'productos' => comb_reg_productos($conn),
        'grifos' => comb_reg_grifos($conn),
        'stats' => comb_reg_stats($conn),
        'recent' => comb_reg_api_recent_payload($conn),
    ]);
}

function comb_reg_api_state(mysqli $conn): void {
    $productId = (int)($_GET['producto_id'] ?? 0);
    $grifoId = (int)($_GET['grifo_id'] ?? 0);

    $product = $productId > 0 ? comb_reg_producto($conn, $productId) : null;
    $stockProduct = ($productId > 0 && $grifoId > 0) ? comb_reg_stock_producto_grifo($conn, $productId, $grifoId) : 0.0;
    $stockGrifo = $grifoId > 0 ? comb_reg_stock_grifo($conn, $grifoId) : 0.0;
    $puRef = ($productId > 0 && $grifoId > 0) ? comb_reg_pu_salida_ref($conn, $productId, $grifoId) : null;

    comb_reg_json([
        'ok' => true,
        'product' => $product,
        'stock_producto_grifo' => $stockProduct,
        'stock_grifo' => $stockGrifo,
        'pu_ref_salida' => $puRef,
        'fuel_stocks' => $grifoId > 0 ? comb_reg_stocks_by_grifo($conn, $grifoId) : [],
        'grifo_label' => $grifoId > 0 ? comb_reg_grifo_label($conn, $grifoId) : '',
    ]);
}

function comb_reg_api_recent(mysqli $conn): void {
    if (!comb_reg_is_admin()) {
        comb_reg_json(['ok' => false, 'message' => 'Solo administradores pueden revisar los movimientos recientes.'], 403);
    }

    comb_reg_json([
        'ok' => true,
        'rows' => comb_reg_recent($conn),
    ]);
}

function comb_reg_api_buses(mysqli $conn): void {
    $q = comb_reg_clean_text($_GET['q'] ?? '', 80);
    if ($q === '') {
        comb_reg_json(['ok' => true, 'rows' => []]);
    }
    $like = '%' . $q . '%';
    $plateLike = '%' . str_replace('-', '', $q) . '%';

    $rows = comb_reg_fetch_all(
        $conn,
        "SELECT
            clm_placas_id AS id,
            COALESCE(NULLIF(TRIM(clm_placas_BUS), ''), CONCAT('Unidad ', clm_placas_id)) AS bus,
            COALESCE(NULLIF(TRIM(clm_placas_PLACA), ''), '-') AS placa,
            COALESCE(NULLIF(TRIM(clm_placas_servicio), ''), '') AS servicio
         FROM tb_placas
         WHERE UPPER(TRIM(COALESCE(clm_placas_ESTADO, ''))) = 'ACTIVO'
           AND (
                CONVERT(clm_placas_BUS USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                OR REPLACE(CONVERT(clm_placas_PLACA USING utf8mb4), '-', '') COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                OR CONVERT(clm_placas_PLACA USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
           )
         ORDER BY CAST(clm_placas_BUS AS UNSIGNED) ASC, clm_placas_BUS ASC, clm_placas_PLACA ASC
         LIMIT 15",
        'sss',
        [$like, $plateLike, $like]
    );

    comb_reg_json(['ok' => true, 'rows' => $rows]);
}

function comb_reg_api_conductores(mysqli $conn): void {
    $q = comb_reg_clean_text($_GET['q'] ?? '', 80);
    $where = "UPPER(TRIM(COALESCE(clm_tra_contrato, ''))) = 'ACTIVO'
              AND UPPER(TRIM(COALESCE(clm_tra_tipo_trabajador, ''))) IN ('CONDUCTOR', 'LIBRE')";
    $types = '';
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= " AND (
            CONVERT(clm_tra_nombres USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR CONVERT(clm_tra_dni USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR CONVERT(clm_tra_cargo USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        )";
        $types = 'sss';
        $params = [$like, $like, $like];
    }

    $rows = comb_reg_fetch_all(
        $conn,
        "SELECT
            clm_tra_id AS id,
            COALESCE(NULLIF(TRIM(clm_tra_nombres), ''), CONCAT('Conductor ', clm_tra_id)) AS nombre,
            COALESCE(NULLIF(TRIM(clm_tra_dni), ''), '-') AS dni,
            COALESCE(NULLIF(TRIM(clm_tra_tipo_trabajador), ''), '') AS tipo,
            COALESCE(NULLIF(TRIM(clm_tra_cargo), ''), '') AS cargo
         FROM tb_trabajador
         WHERE {$where}
         ORDER BY clm_tra_nombres ASC
         LIMIT 20",
        $types,
        $params
    );

    comb_reg_json(['ok' => true, 'rows' => $rows]);
}

function comb_reg_api_save_entrada(mysqli $conn, array $payload): void {
    comb_reg_validate_csrf($payload);
    comb_reg_validate_config_password($conn, 'NOTA_ABSTMT_PASS', (string)($payload['password'] ?? ''), 'el abastecimiento');

    $productId = (int)($payload['producto_id'] ?? 0);
    $grifoId = (int)($payload['grifo_id'] ?? 0);
    $cantidad = comb_reg_float($payload['cantidad'] ?? 0);
    $precioUnitario = round(comb_reg_float($payload['precio_unitario'] ?? 0), 4);
    $monto = round($cantidad * $precioUnitario, 4);
    $abastecedor = comb_reg_clean_text($payload['abastecedor'] ?? '', 180);
    $observacion = comb_reg_clean_text($payload['observacion'] ?? '', 800);

    $producto = $productId > 0 ? comb_reg_producto($conn, $productId) : null;
    if (!$producto) {
        comb_reg_json(['ok' => false, 'message' => 'Selecciona un combustible valido.'], 422);
    }
    if ($grifoId <= 0 || comb_reg_grifo_label($conn, $grifoId) === '') {
        comb_reg_json(['ok' => false, 'message' => 'Selecciona un grifo valido.'], 422);
    }
    if ($cantidad <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'La cantidad debe ser mayor a 0.'], 422);
    }
    if ($precioUnitario < 0) {
        comb_reg_json(['ok' => false, 'message' => 'El precio unitario no puede ser negativo.'], 422);
    }
    if ($abastecedor === '') {
        comb_reg_json(['ok' => false, 'message' => 'Ingresa el suministrador del abastecimiento.'], 422);
    }

    $now = date('Y-m-d H:i:s');
    $usuario = (string)($_SESSION['usuario'] ?? '');
    $dni = (string)($_SESSION['DNI'] ?? '');
    $userId = comb_reg_user_id($conn);
    $grifoLabel = comb_reg_grifo_label($conn, $grifoId);

    if ($userId <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'No se pudo identificar al usuario de sesion.'], 422);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn->begin_transaction();

        $serie = 'AB';
        $modulo = 'Combustible';
        $placaNull = null;

        $stmtNota = $conn->prepare(
            "INSERT INTO tb_notas_salida
             (clm_nota_fecha, clm_nota_responsable, clm_nota_modulo, clm_nota_motivo,
              clm_nota_placa, clm_nota_proveedor, clm_nota_espacio, clm_nota_DNI, clm_nota_serie)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtNota->bind_param('sssssssss', $now, $usuario, $modulo, $observacion, $placaNull, $abastecedor, $grifoLabel, $dni, $serie);
        $stmtNota->execute();
        $notaId = (int)$conn->insert_id;
        $stmtNota->close();

        $notaCode = comb_reg_nota_code($conn, $notaId);

        $tipo = 'ENTRADA';
        $stmtMov = $conn->prepare(
            "INSERT INTO tb_alm_movimientos
             (clm_alm_mov_idPRODUCTO, clm_alm_mov_TIPO, clm_alm_mov_fecha_registro,
              clm_alm_mov_cantidad, clm_alm_mov_preciounitario, clm_alm_mov_monto,
              clm_alm_mov_OBSERVACION, clm_alm_mov_iduser, clm_alm_mov_orgn, clm_alm_mov_idNOTA)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtMov->bind_param('issdddsiii', $productId, $tipo, $now, $cantidad, $precioUnitario, $monto, $observacion, $userId, $grifoId, $notaId);
        $stmtMov->execute();
        $movId = (int)$conn->insert_id;
        $stmtMov->close();

        $conn->commit();

        comb_reg_json([
            'ok' => true,
            'message' => 'Abastecimiento registrado correctamente.',
            'nota_id' => $notaId,
            'nota_codigo' => $notaCode['sco'],
            'movimiento_id' => $movId,
            'stats' => comb_reg_stats($conn),
            'recent' => comb_reg_api_recent_payload($conn),
            'state' => [
                'stock_producto_grifo' => comb_reg_stock_producto_grifo($conn, $productId, $grifoId),
                'stock_grifo' => comb_reg_stock_grifo($conn, $grifoId),
                'pu_ref_salida' => comb_reg_pu_salida_ref($conn, $productId, $grifoId),
                'fuel_stocks' => comb_reg_stocks_by_grifo($conn, $grifoId),
            ],
        ]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        $message = ((int)($e->getCode() ?? 0) === 1062)
            ? 'Se detecto un duplicado de serie/correlativo en la nota AB. Vuelve a intentarlo.'
            : 'No se pudo guardar el abastecimiento: ' . $e->getMessage();
        comb_reg_json(['ok' => false, 'message' => $message], 500);
    }
}

function comb_reg_api_save_salida(mysqli $conn, array $payload): void {
    comb_reg_validate_csrf($payload);
    comb_reg_validate_config_password($conn, 'NOTA_TANQUEO_PASS', (string)($payload['password'] ?? ''), 'la tanqueada');

    $productId = (int)($payload['producto_id'] ?? 0);
    $grifoId = (int)($payload['grifo_id'] ?? 0);
    $busId = (int)($payload['bus_id'] ?? 0);
    $conductorId = (int)($payload['conductor_id'] ?? 0);
    $cantidad = comb_reg_float($payload['cantidad'] ?? 0);
    $extra = round(comb_reg_float($payload['precio_extra'] ?? 0), 4);
    $observacion = comb_reg_clean_text($payload['observacion'] ?? '', 800);

    if (preg_match('/\|\s*PU/i', $observacion)) {
        comb_reg_json(['ok' => false, 'message' => "No escribas manualmente texto con '| PU' en la observacion. El sistema lo agrega cuando corresponde."], 422);
    }

    $producto = $productId > 0 ? comb_reg_producto($conn, $productId) : null;
    if (!$producto) {
        comb_reg_json(['ok' => false, 'message' => 'Selecciona un combustible valido.'], 422);
    }
    if ($grifoId <= 0 || comb_reg_grifo_label($conn, $grifoId) === '') {
        comb_reg_json(['ok' => false, 'message' => 'Selecciona un grifo valido.'], 422);
    }
    if ($busId <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'Selecciona una unidad valida para la tanqueada.'], 422);
    }
    if ($conductorId <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'Selecciona un conductor activo.'], 422);
    }
    if ($cantidad <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'La cantidad debe ser mayor a 0.'], 422);
    }
    if ($extra < 0) {
        comb_reg_json(['ok' => false, 'message' => 'El precio extra no puede ser negativo.'], 422);
    }

    $stockProducto = comb_reg_stock_producto_grifo($conn, $productId, $grifoId);
    if ($stockProducto <= 0 || $cantidad > $stockProducto) {
        $detail = $stockProducto <= 0
            ? 'No hay stock disponible del combustible en este grifo.'
            : 'La cantidad solicitada supera el stock disponible del combustible en este grifo.';
        comb_reg_json(['ok' => false, 'message' => $detail], 422);
    }

    $puRef = comb_reg_pu_salida_ref($conn, $productId, $grifoId);
    if ($puRef === null) {
        comb_reg_json(['ok' => false, 'message' => 'No se encontro PU de referencia para este combustible en el grifo seleccionado. Registra primero un abastecimiento.'], 422);
    }

    $puFinal = round((float)$puRef + $extra, 4);
    if ($puFinal <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'El PU final debe ser mayor a 0. Revisa el precio de referencia o el precio extra.'], 422);
    }

    $bus = comb_reg_fetch_one(
        $conn,
        "SELECT
            clm_placas_id AS id,
            COALESCE(NULLIF(TRIM(clm_placas_BUS), ''), CONCAT('Unidad ', clm_placas_id)) AS bus,
            COALESCE(NULLIF(TRIM(clm_placas_PLACA), ''), '-') AS placa
         FROM tb_placas
         WHERE clm_placas_id = ?
         LIMIT 1",
        'i',
        [$busId]
    );
    if (!$bus) {
        comb_reg_json(['ok' => false, 'message' => 'La unidad seleccionada no existe.'], 422);
    }

    $conductor = comb_reg_fetch_one(
        $conn,
        "SELECT
            clm_tra_id AS id,
            COALESCE(NULLIF(TRIM(clm_tra_nombres), ''), CONCAT('Conductor ', clm_tra_id)) AS nombre,
            COALESCE(NULLIF(TRIM(clm_tra_dni), ''), '-') AS dni
         FROM tb_trabajador
         WHERE clm_tra_id = ?
         LIMIT 1",
        'i',
        [$conductorId]
    );
    if (!$conductor) {
        comb_reg_json(['ok' => false, 'message' => 'El conductor seleccionado no existe.'], 422);
    }

    $busLabel = comb_reg_bus_label($bus);
    $conductorLabel = trim((string)$conductor['nombre']) . ' (' . trim((string)$conductor['dni']) . ')';
    $motivoNota = 'Tanqueo a unidad ' . $busLabel . ($observacion !== '' ? ' ' . $observacion : '');
    $obsGuardar = $observacion;
    if ($extra > 0) {
        $obsGuardar = trim($obsGuardar . ' | PU adicional: S/. ' . number_format($extra, 4, '.', ''));
    }

    $now = date('Y-m-d H:i:s');
    $usuario = (string)($_SESSION['usuario'] ?? '');
    $dni = (string)($_SESSION['DNI'] ?? '');
    $userId = comb_reg_user_id($conn);
    $grifoLabel = comb_reg_grifo_label($conn, $grifoId);
    $monto = round($cantidad * $puFinal, 4);

    if ($userId <= 0) {
        comb_reg_json(['ok' => false, 'message' => 'No se pudo identificar al usuario de sesion.'], 422);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn->begin_transaction();

        $serie = 'CM';
        $modulo = 'Combustible';

        $stmtNota = $conn->prepare(
            "INSERT INTO tb_notas_salida
             (clm_nota_fecha, clm_nota_responsable, clm_nota_modulo, clm_nota_motivo,
              clm_nota_placa, clm_nota_espacio, clm_nota_proveedor, clm_nota_DNI, clm_nota_serie)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtNota->bind_param('ssssissss', $now, $usuario, $modulo, $motivoNota, $busId, $grifoLabel, $conductorLabel, $dni, $serie);
        $stmtNota->execute();
        $notaId = (int)$conn->insert_id;
        $stmtNota->close();

        $notaCode = comb_reg_nota_code($conn, $notaId);

        $tipo = 'SALIDA';
        $itemTable = 1;
        $stmtMov = $conn->prepare(
            "INSERT INTO tb_alm_movimientos
             (clm_alm_mov_idPRODUCTO, clm_alm_mov_TIPO, clm_alm_mov_fecha_registro,
              clm_alm_mov_cantidad, clm_alm_mov_preciounitario, clm_alm_mov_monto,
              clm_alm_mov_OBSERVACION, clm_alm_mov_placa, clm_alm_mov_idNOTA,
              clm_alm_mov_itmtable, clm_alm_mov_iduser, clm_alm_mov_orgn)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtMov->bind_param('issdddsiiiii', $productId, $tipo, $now, $cantidad, $puFinal, $monto, $obsGuardar, $busId, $notaId, $itemTable, $userId, $grifoId);
        $stmtMov->execute();
        $movId = (int)$conn->insert_id;
        $stmtMov->close();

        $conn->commit();

        comb_reg_json([
            'ok' => true,
            'message' => 'Tanqueada registrada correctamente.',
            'nota_id' => $notaId,
            'nota_codigo' => $notaCode['sco'],
            'movimiento_id' => $movId,
            'pu_ref' => (float)$puRef,
            'pu_extra' => $extra,
            'pu_final' => $puFinal,
            'monto' => $monto,
            'stats' => comb_reg_stats($conn),
            'recent' => comb_reg_api_recent_payload($conn),
            'state' => [
                'stock_producto_grifo' => comb_reg_stock_producto_grifo($conn, $productId, $grifoId),
                'stock_grifo' => comb_reg_stock_grifo($conn, $grifoId),
                'pu_ref_salida' => comb_reg_pu_salida_ref($conn, $productId, $grifoId),
                'fuel_stocks' => comb_reg_stocks_by_grifo($conn, $grifoId),
            ],
        ]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        $message = ((int)($e->getCode() ?? 0) === 1062)
            ? 'Se detecto un duplicado de serie/correlativo en la nota CM. Vuelve a intentarlo.'
            : 'No se pudo guardar la tanqueada: ' . $e->getMessage();
        comb_reg_json(['ok' => false, 'message' => $message], 500);
    }
}

try {
    switch ($action) {
        case 'bootstrap':
            comb_reg_api_bootstrap($conn);
            break;
        case 'state':
            comb_reg_api_state($conn);
            break;
        case 'recent':
            comb_reg_api_recent($conn);
            break;
        case 'buses':
            comb_reg_api_buses($conn);
            break;
        case 'conductores':
            comb_reg_api_conductores($conn);
            break;
        case 'save_entrada':
            comb_reg_api_save_entrada($conn, $payload);
            break;
        case 'save_salida':
            comb_reg_api_save_salida($conn, $payload);
            break;
        default:
            comb_reg_json(['ok' => false, 'message' => 'Accion no reconocida.'], 400);
    }
} catch (Throwable $e) {
    comb_reg_json(['ok' => false, 'message' => $e->getMessage() ?: 'No se pudo completar la operacion.'], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
