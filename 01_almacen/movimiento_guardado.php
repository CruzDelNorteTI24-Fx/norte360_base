<?php
require_once __DIR__ . '/movimiento_backend.php';

function alm_debug_sanitize($value) {
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $lower = strtolower((string)$key);
            if (in_array($lower, ['password', 'clave', 'contrasena'], true)) {
                $clean[$key] = '[oculto]';
                continue;
            }
            $clean[$key] = alm_debug_sanitize($item);
        }
        return $clean;
    }

    return $value;
}

function alm_action_debug_payload(mysqli $conn): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        alm_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
    }

    if (!alm_can_registrar()) {
        alm_json(['ok' => false, 'message' => 'No tienes permiso para registrar movimientos.'], 403);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        alm_json(['ok' => false, 'message' => 'Solicitud invalida.'], 400);
    }
    alm_validate_csrf($payload);

    $source = alm_clean_text($payload['source'] ?? 'registro', 40);
    $debugPayload = alm_debug_sanitize($payload['payload'] ?? []);
    error_log('[N360][almacen][pruebas][' . $source . '] ' . json_encode($debugPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    alm_json(['ok' => true, 'message' => 'Payload de prueba enviado al log del servidor.']);
}

function alm_action_save_entrada(mysqli $conn): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        alm_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
    }

    if (!alm_can_registrar()) {
        alm_json(['ok' => false, 'message' => 'No tienes permiso para registrar movimientos.'], 403);
    }

    $payload = $_POST;
    alm_validate_csrf($payload);

    $productId = (int)($payload['producto_id'] ?? 0);
    $cantidad = alm_float($payload['cantidad'] ?? 0);
    $observacion = alm_clean_text($payload['observacion'] ?? '', 900);
    $proveedor = alm_clean_text($payload['proveedor'] ?? '', 220);
    $factura = alm_clean_text($payload['factura'] ?? '', 120);
    $sedeId = (int)($payload['sede_id'] ?? 0);
    $anaquelId = (int)($payload['anaquel_id'] ?? 0);
    $ubicacionRaw = alm_clean_text($payload['ubicacion_raw'] ?? '', 25);
    $ubicacionLabel = alm_clean_text($payload['ubicacion_label'] ?? '', 220);
    $bb = strtoupper(alm_clean_text($payload['ubi_bloque'] ?? '00', 2));
    $nn = str_pad(preg_replace('/\D/', '', (string)($payload['ubi_nivel'] ?? '00')), 2, '0', STR_PAD_LEFT);
    $ssss = str_pad(preg_replace('/\D/', '', (string)($payload['ubi_seccion'] ?? '0000')), 4, '0', STR_PAD_LEFT);

    if ($productId <= 0 || $cantidad <= 0) {
        alm_json(['ok' => false, 'message' => 'Producto y cantidad son obligatorios.'], 422);
    }

    $product = alm_fetch_one($conn, "
        SELECT p.clm_alm_producto_id,
               COALESCE(p.clm_alm_producto_prec_unit, 0) AS precio
        FROM tb_alm_producto p
        WHERE p.clm_alm_producto_id = ?
          AND p.clm_alm_producto_idCATEGORIA NOT IN (11, 14)
        LIMIT 1
    ", 'i', [$productId]);

    if (!$product) {
        alm_json(['ok' => false, 'message' => 'Producto no encontrado o no pertenece a almacen.'], 404);
    }

    $precio = alm_can_edit_prices() ? alm_float($payload['precio_unitario'] ?? 0) : (float)($product['precio'] ?? 0);
    if ($precio < 0) {
        alm_json(['ok' => false, 'message' => 'El precio unitario no puede ser negativo.'], 422);
    }
    $monto = $cantidad * $precio;
    $autoPdf = !empty($payload['auto_pdf']);
    $originId = alm_origin_id_from_payload($payload);

    $tipo = alm_producto_tiene_movimientos($conn, $productId) ? 'ENTRADA' : 'INVENTARIADO';
    $generarEtq = !empty($payload['gen_etq']) && $sedeId > 0 ? 1 : 0;
    $etqCant = null;

    if ($generarEtq) {
        if (abs($cantidad - round($cantidad)) > 0.00001 || (int)round($cantidad) <= 0) {
            alm_json(['ok' => false, 'message' => 'Para generar etiquetas la cantidad debe ser entera y mayor a cero.'], 422);
        }
        $etqCant = (int)round($cantidad);
    }

    $documentoBin = null;
    if (isset($_FILES['documento']) && is_uploaded_file($_FILES['documento']['tmp_name'])) {
        if ((int)($_FILES['documento']['size'] ?? 0) > 8 * 1024 * 1024) {
            alm_json(['ok' => false, 'message' => 'El documento supera el limite de 8 MB.'], 422);
        }
        $documentoBin = file_get_contents($_FILES['documento']['tmp_name']);
    }

    $userId = alm_user_id($conn);
    if ($userId <= 0) {
        alm_json(['ok' => false, 'message' => 'No se pudo identificar al usuario de sesion.'], 422);
    }

    $fecha = date('Y-m-d H:i:s');
    $responsable = alm_clean_text($_SESSION['usuario'] ?? 'Usuario', 120);
    $dni = alm_clean_text($_SESSION['DNI'] ?? '', 30);
    $espacio = $ubicacionLabel !== '' ? $ubicacionLabel : 'ALMACEN (ALM)';
    $serie = 'NE';
    $placaNull = null;

    $conn->begin_transaction();

    $notaParams = [$fecha, $responsable, 'Almacen', $observacion, $placaNull, $espacio, $proveedor, $dni, $serie];
    $stmtNota = $conn->prepare("
        INSERT INTO tb_notas_salida
        (clm_nota_fecha, clm_nota_responsable, clm_nota_modulo, clm_nota_motivo,
         clm_nota_placa, clm_nota_espacio, clm_nota_proveedor, clm_nota_DNI, clm_nota_serie)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    alm_bind($stmtNota, 'sssssssss', $notaParams);
    $stmtNota->execute();
    $notaId = (int)$conn->insert_id;
    $stmtNota->close();

    $nota = alm_fetch_one($conn, 'SELECT clm_nota_sco FROM tb_notas_salida WHERE clm_nota_id = ?', 'i', [$notaId]);
    $notaCodigo = (string)($nota['clm_nota_sco'] ?? ('NE-' . str_pad((string)$notaId, 4, '0', STR_PAD_LEFT)));

    $sedeDb = $sedeId > 0 ? $sedeId : null;
    $anaquelDb = $anaquelId > 0 ? $anaquelId : null;
    $ubicacionDb = $ubicacionRaw !== '' ? $ubicacionRaw : null;

    $movParams = [
        $productId, $tipo, $cantidad, $precio, $monto, $fecha, $observacion, $documentoBin, $userId,
        $factura, $proveedor, $notaId, $originId, $generarEtq, $etqCant, $sedeDb, $anaquelDb, $bb, $nn, $ssss, $ubicacionDb
    ];

    $stmtMov = $conn->prepare("
        INSERT INTO tb_alm_movimientos
        (clm_alm_mov_idPRODUCTO, clm_alm_mov_TIPO, clm_alm_mov_cantidad, clm_alm_mov_preciounitario, clm_alm_mov_monto,
         clm_alm_mov_fecha_registro, clm_alm_mov_OBSERVACION, clm_alm_mov_documento, clm_alm_mov_iduser,
         clm_mov_factura, clm_mov_ruc, clm_alm_mov_idNOTA, clm_alm_mov_orgn,
         clm_alm_mov_gen_etq, clm_alm_mov_etq_cant, clm_alm_mov_ofic_destino, clm_alm_mov_anaquel,
         clm_alm_mov_ubi_bloque, clm_alm_mov_ubi_nivel, clm_alm_mov_ubi_seccion, clm_alm_mov_ubicacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $movTypes = 'isdddsssiss' . 'iiiiii' . 'ssss';
    alm_bind($stmtMov, $movTypes, $movParams);
    $stmtMov->execute();
    $movId = (int)$conn->insert_id;
    $stmtMov->close();

    $conn->commit();

    alm_json([
        'ok' => true,
        'message' => $tipo === 'INVENTARIADO' ? 'Inventariado inicial registrado correctamente.' : 'Entrada registrada correctamente.',
        'tipo' => $tipo,
        'movimiento_id' => $movId,
        'nota_id' => $notaId,
        'nota_codigo' => $notaCodigo,
        'auto_pdf' => $autoPdf,
        'pdf_params' => [
            'id_nota' => $notaId,
            'series' => $serie,
        ],
    ]);
}

function alm_action_save_salida(mysqli $conn): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        alm_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
    }

    if (!alm_can_registrar()) {
        alm_json(['ok' => false, 'message' => 'No tienes permiso para registrar movimientos.'], 403);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        alm_json(['ok' => false, 'message' => 'Solicitud invalida.'], 400);
    }
    alm_validate_csrf($payload);
    alm_validate_current_password($conn, (string)($payload['password'] ?? ''));
    unset($payload['password']);

    $salidaOriginId = alm_origin_id_from_payload($payload);
    $placaId = (int)($payload['placa_id'] ?? 0);
    $entregado = alm_clean_text($payload['entregado_a'] ?? '', 220);
    $motivo = alm_clean_text($payload['motivo'] ?? '', 900);
    $items = $payload['items'] ?? [];

    if ($placaId <= 0 || !is_array($items) || count($items) === 0) {
        alm_json(['ok' => false, 'message' => 'Selecciona unidad e items para la salida.'], 422);
    }

    $bus = alm_fetch_one($conn, "
        SELECT clm_placas_id
        FROM tb_placas
        WHERE clm_placas_id = ? AND clm_placas_ESTADO = 'Activo'
        LIMIT 1
    ", 'i', [$placaId]);

    if (!$bus) {
        alm_json(['ok' => false, 'message' => 'La unidad seleccionada no esta activa o no existe.'], 422);
    }

    $preparedItems = [];
    foreach ($items as $item) {
        $productId = (int)($item['producto_id'] ?? 0);
        $cantidad = alm_float($item['cantidad'] ?? 0);

        if ($productId <= 0 || $cantidad <= 0) {
            alm_json(['ok' => false, 'message' => 'Hay items con producto o cantidad invalida.'], 422);
        }

        $product = alm_fetch_one($conn, "
            SELECT
                p.clm_alm_producto_id AS id,
                p.clm_alm_producto_prec_unit AS precio,
                COALESCE(v.Stock_Actual, 0) AS stock
            FROM tb_alm_producto p
            LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
            WHERE p.clm_alm_producto_id = ?
              AND p.clm_alm_producto_idCATEGORIA NOT IN (11, 14)
            LIMIT 1
        ", 'i', [$productId]);

        if (!$product) {
            alm_json(['ok' => false, 'message' => 'Uno de los productos no existe o no pertenece a almacen.'], 422);
        }

        $stock = (float)$product['stock'];
        if ($cantidad > $stock + 0.00001) {
            alm_json(['ok' => false, 'message' => 'La cantidad solicitada supera el stock disponible de uno de los productos.'], 422);
        }

        $precio = (float)($product['precio'] ?? 0);
        $preparedItems[] = [
            'producto_id' => $productId,
            'cantidad' => $cantidad,
            'precio' => $precio,
            'monto' => $cantidad * $precio,
        ];
    }

    $userId = alm_user_id($conn);
    if ($userId <= 0) {
        alm_json(['ok' => false, 'message' => 'No se pudo identificar al usuario de sesion.'], 422);
    }

    $fecha = date('Y-m-d H:i:s');
    $responsable = alm_clean_text($_SESSION['usuario'] ?? 'Usuario', 120);
    $dni = alm_clean_text($_SESSION['DNI'] ?? '', 30);
    $serie = 'NS';
    $espacio = 'ALMACEN (ALM)';
    $tipo = 'SALIDA';

    $conn->begin_transaction();

    $notaParams = [$fecha, $responsable, 'Almacen', $motivo, $placaId, $espacio, $entregado, $dni, $serie];
    $stmtNota = $conn->prepare("
        INSERT INTO tb_notas_salida
        (clm_nota_fecha, clm_nota_responsable, clm_nota_modulo, clm_nota_motivo,
         clm_nota_placa, clm_nota_espacio, clm_nota_proveedor, clm_nota_DNI, clm_nota_serie)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    alm_bind($stmtNota, 'ssssissss', $notaParams);
    $stmtNota->execute();
    $notaId = (int)$conn->insert_id;
    $stmtNota->close();

    $nota = alm_fetch_one($conn, 'SELECT clm_nota_sco FROM tb_notas_salida WHERE clm_nota_id = ?', 'i', [$notaId]);
    $notaCodigo = (string)($nota['clm_nota_sco'] ?? ('NS-' . str_pad((string)$notaId, 4, '0', STR_PAD_LEFT)));

    $stmtMov = $conn->prepare("
        INSERT INTO tb_alm_movimientos
        (clm_alm_mov_itmtable, clm_alm_mov_TIPO, clm_alm_mov_cantidad, clm_alm_mov_monto,
         clm_alm_mov_preciounitario, clm_alm_mov_fecha_registro, clm_alm_mov_OBSERVACION,
         clm_alm_mov_placa, clm_alm_mov_idNOTA, clm_alm_mov_orgn, clm_alm_mov_idPRODUCTO, clm_alm_mov_iduser)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $movId = 0;
    foreach ($preparedItems as $index => $item) {
        $orden = $index + 1;
        $movParams = [
            $orden,
            $tipo,
            $item['cantidad'],
            $item['monto'],
            $item['precio'],
            $fecha,
            $motivo,
            $placaId,
            $notaId,
            $salidaOriginId,
            $item['producto_id'],
            $userId,
        ];
        alm_bind($stmtMov, 'isdddssiiiii', $movParams);
        $stmtMov->execute();
        $movId = (int)$conn->insert_id;
    }
    $stmtMov->close();

    $conn->commit();

    alm_json([
        'ok' => true,
        'message' => 'Salida registrada correctamente.',
        'movimiento_id' => $movId,
        'nota_id' => $notaId,
        'nota_codigo' => $notaCodigo,
    ]);
}
