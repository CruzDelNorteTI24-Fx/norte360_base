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


function alm_action_crear_producto(mysqli $conn): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        alm_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
    }

    if (!alm_can_registrar()) {
        alm_json(['ok' => false, 'message' => 'No tienes permiso para crear productos.'], 403);
    }

    $payload = $_POST;
    alm_validate_csrf($payload);

    $originId = alm_origin_id_from_payload($payload);
    $context = alm_context_config_from_origin($originId);
    $categoriaId = (int)($payload['categoria_id'] ?? 0);
    $nombre = alm_clean_text($payload['nombre'] ?? '', 220);
    $unidad = strtoupper(alm_clean_text($payload['unidad'] ?? '', 30));
    $stockMinimo = alm_float($payload['stock_minimo'] ?? 0);
    $descripcion = alm_clean_text($payload['descripcion'] ?? '', 900);
    $areaControl = $context['area_control'];
    $tipoControl = $context['tipo_control'];

    if ($categoriaId <= 0 || $nombre === '' || $unidad === '') {
        alm_json(['ok' => false, 'message' => 'Categoria, nombre y unidad son obligatorios.'], 422);
    }

    if ($categoriaId === 11) {
        alm_json(['ok' => false, 'message' => 'La categoria combustible se registra desde el modulo de combustible.'], 422);
    }

    if ($stockMinimo < 0) {
        alm_json(['ok' => false, 'message' => 'El stock minimo no puede ser negativo.'], 422);
    }

    $category = alm_fetch_one($conn, "
        SELECT
            c.clm_alm_categoria_id,
            COALESCE(NULLIF(TRIM(c.clm_alm_categoria_NOMBRE), ''), 'PR') AS categoria_abv,
            COALESCE(NULLIF(TRIM(c.clm_alm_categoria_DESCRIPCION), ''), 'Sin categoria') AS categoria,
            COALESCE(NULLIF(TRIM(cod.clm_alm_codigo_NOMBRE), ''), '') AS codigo_grupo
        FROM tb_alm_categoria c
        JOIN tb_alm_codigo cod ON cod.clm_alm_codigo_id = c.clm_alm_categoria_idCODIGO
        WHERE c.clm_alm_categoria_id = ?
          AND c.clm_alm_categoria_id <> 11
        LIMIT 1
    ", 'i', [$categoriaId]);

    if (!$category) {
        alm_json(['ok' => false, 'message' => 'Categoria no disponible para crear productos.'], 422);
    }

    $imagenBin = null;
    if (isset($_FILES['imagen']) && is_uploaded_file($_FILES['imagen']['tmp_name'])) {
        $size = (int)($_FILES['imagen']['size'] ?? 0);
        if ($size > 4 * 1024 * 1024) {
            alm_json(['ok' => false, 'message' => 'La imagen supera el limite de 4 MB.'], 422);
        }

        $tmpPath = (string)$_FILES['imagen']['tmp_name'];
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmpPath) : '';
        if ($mime !== '' && !in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            alm_json(['ok' => false, 'message' => 'La imagen debe ser PNG, JPG, WEBP o GIF.'], 422);
        }

        $imagenBin = file_get_contents($tmpPath);
    }

    $fecha = date('Y-m-d H:i:s');
    $conn->begin_transaction();

    $params = [$categoriaId, $nombre, $unidad, $stockMinimo, $descripcion, $imagenBin, $fecha, $areaControl, $tipoControl];
    $stmt = $conn->prepare("
        INSERT INTO tb_alm_producto
        (clm_alm_producto_idCATEGORIA, clm_alm_producto_NOMBRE, clm_alm_producto_unidad,
         clm_alm_producto_stock_minimo, clm_alm_producto_DESCRIPCION, clm_alm_producto_IMG,
         clm_alm_producto_fecha_registro, clm_alm_producto_area_control, clm_alm_producto_tipo_control)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    alm_bind($stmt, 'issdsssss', $params);
    $stmt->execute();
    $productId = (int)$conn->insert_id;
    $stmt->close();

    $categoriaAbv = trim((string)($category['categoria_abv'] ?? 'PR'));
    if ($categoriaAbv === '') {
        $categoriaAbv = 'PR';
    }
    $codigoProducto = $categoriaAbv . str_pad((string)$productId, 4, '0', STR_PAD_LEFT);

    $updateParams = [$codigoProducto, $productId];
    $stmtUpdate = $conn->prepare("
        UPDATE tb_alm_producto
        SET clm_alm_producto_codigo = ?
        WHERE clm_alm_producto_id = ?
    ");
    alm_bind($stmtUpdate, 'si', $updateParams);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    $conn->commit();

    $product = alm_fetch_one($conn, "
        SELECT
            p.clm_alm_producto_id AS id,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_codigo), ''), p.clm_alm_producto_id) AS codigo,
            p.clm_alm_producto_NOMBRE AS producto,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_DESCRIPCION), ''), '') AS descripcion,
            COALESCE(NULLIF(TRIM(c.clm_alm_categoria_DESCRIPCION), ''), 'Sin categoria') AS categoria,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_unidad), ''), '-') AS unidad,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN') AS area_control,
            COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), 'CONSUMIBLE') AS tipo_control,
            COALESCE(p.clm_alm_producto_stock_minimo, 0) AS stock_min,
            0 AS stock,
            COALESCE(p.clm_alm_producto_prec_unit, 0) AS precio,
            0 AS tiene_movimientos
        FROM tb_alm_producto p
        JOIN tb_alm_categoria c ON c.clm_alm_categoria_id = p.clm_alm_producto_idCATEGORIA
        WHERE p.clm_alm_producto_id = ?
        LIMIT 1
    ", 'i', [$productId]);

    if (!alm_can_edit_prices() && $product) {
        $product['precio'] = 0;
    }

    alm_json([
        'ok' => true,
        'message' => 'Producto creado correctamente.',
        'product' => $product,
        'context' => [
            'origin_id' => $originId,
            'area_control' => $areaControl,
            'tipo_control' => $tipoControl,
        ],
    ]);
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

function alm_parse_person_label(string $value): array {
    $value = alm_clean_text($value, 220);
    if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $value, $m)) {
        return [
            'nombre' => alm_clean_text($m[1] ?? '', 180),
            'dni' => alm_clean_text($m[2] ?? '', 20),
        ];
    }

    return ['nombre' => $value, 'dni' => ''];
}

function alm_whitelist_value($value, array $allowed, string $fallback): string {
    $value = strtoupper(alm_clean_text($value ?? '', 60));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function alm_valid_date_or_null($value): ?string {
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

function alm_save_rrhh_acta_uniformes(mysqli $conn, int $notaId, int $userId, array $payload, array $preparedItems, string $entregado): int {
    $acta = is_array($payload['acta'] ?? null) ? $payload['acta'] : [];
    $personalId = (int)($payload['personal_id'] ?? 0);
    $person = alm_parse_person_label($entregado);

    if ($personalId > 0) {
        $trab = alm_fetch_one($conn, "
            SELECT
                clm_tra_id,
                COALESCE(NULLIF(TRIM(clm_tra_nombres), ''), CONCAT('Trabajador ', clm_tra_id)) AS nombre,
                COALESCE(NULLIF(TRIM(clm_tra_dni), ''), '') AS dni,
                COALESCE(NULLIF(TRIM(clm_tra_cargo), ''), 'EMPLEADO') AS cargo
            FROM tb_trabajador
            WHERE clm_tra_id = ?
            LIMIT 1
        ", 'i', [$personalId]);

        if ($trab) {
            $person['nombre'] = alm_clean_text($trab['nombre'] ?? $person['nombre'], 180);
            $person['dni'] = alm_clean_text($trab['dni'] ?? $person['dni'], 20);
            if (empty($acta['recibe_cargo'])) {
                $acta['recibe_cargo'] = $trab['cargo'] ?? 'EMPLEADO';
            }
        }
    }

    if ($person['nombre'] === '') {
        alm_json(['ok' => false, 'message' => 'Ingresa el trabajador que recibira los bienes para generar el acta.'], 422);
    }

    $fechaEntrega = alm_valid_date_or_null($acta['fecha_entrega'] ?? null) ?: date('Y-m-d');
    $area = alm_whitelist_value($acta['area'] ?? '', ['COUNTER_H', 'COUNTER_M', 'CONDUCTOR', 'OFICINA'], 'OFICINA');
    $posicion = alm_whitelist_value($acta['posicion'] ?? '', ['PART_TIME', 'FULL_TIME'], 'FULL_TIME');
    $motivo = alm_whitelist_value($acta['motivo'] ?? '', ['INICIO_CONTRATO_CORTESIA', 'REPOSICION_DESGASTE', 'PERDIDA_ROBO', 'COMPRA'], 'INICIO_CONTRATO_CORTESIA');
    $total = 0.0;
    foreach ($preparedItems as $item) {
        $total += (float)($item['monto'] ?? 0);
    }

    $descuenta = !empty($acta['descuenta']) ? 1 : 0;
    $cuotas = max(1, min(24, (int)($acta['cuotas'] ?? 1)));
    $fechaDescuento = $descuenta ? alm_valid_date_or_null($acta['fecha_descuento'] ?? null) : null;
    $observaciones = alm_clean_text($acta['observaciones'] ?? '', 1200);

    $recibeNombre = alm_clean_text($acta['recibe_nombre'] ?? $person['nombre'], 180);
    $recibeDni = alm_clean_text($acta['recibe_dni'] ?? $person['dni'], 20);
    $recibeCargo = alm_clean_text($acta['recibe_cargo'] ?? 'EMPLEADO', 80);
    if ($recibeNombre === '') $recibeNombre = $person['nombre'];
    if ($recibeDni === '') $recibeDni = $person['dni'];
    if ($recibeCargo === '') $recibeCargo = 'EMPLEADO';

    $entregaNombre = alm_clean_text($acta['entrega_nombre'] ?? ($_SESSION['usuario'] ?? ''), 180);
    $entregaDni = alm_clean_text($acta['entrega_dni'] ?? ($_SESSION['DNI'] ?? ''), 20);
    $entregaCargo = alm_clean_text($acta['entrega_cargo'] ?? 'ASISTENTE', 80);
    if ($entregaNombre === '') $entregaNombre = alm_clean_text($_SESSION['usuario'] ?? 'Usuario', 180);
    if ($entregaCargo === '') $entregaCargo = 'ASISTENTE';

    $params = [
        $notaId, $fechaEntrega, $personalId > 0 ? $personalId : null, $person['nombre'], $person['dni'],
        $area, $posicion, $motivo, $total, $descuenta, $cuotas, $fechaDescuento, $observaciones,
        $recibeNombre, $recibeDni, $recibeCargo, $entregaNombre, $entregaDni, $entregaCargo, $userId
    ];

    $stmt = $conn->prepare("
        INSERT INTO tb_rrhh_acta_uniformes
        (clm_rrhh_acta_idnota, clm_rrhh_acta_fecha_entrega, clm_rrhh_acta_trabajador_id,
         clm_rrhh_acta_trabajador_nombre, clm_rrhh_acta_trabajador_dni, clm_rrhh_acta_area,
         clm_rrhh_acta_posicion, clm_rrhh_acta_motivo, clm_rrhh_acta_total,
         clm_rrhh_acta_descuenta, clm_rrhh_acta_cuotas, clm_rrhh_acta_fecha_descuento,
         clm_rrhh_acta_observaciones, clm_rrhh_acta_recibe_nombre, clm_rrhh_acta_recibe_dni,
         clm_rrhh_acta_recibe_cargo, clm_rrhh_acta_entrega_nombre, clm_rrhh_acta_entrega_dni,
         clm_rrhh_acta_entrega_cargo, clm_rrhh_acta_usuario_creacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            clm_rrhh_acta_fecha_entrega = VALUES(clm_rrhh_acta_fecha_entrega),
            clm_rrhh_acta_trabajador_id = VALUES(clm_rrhh_acta_trabajador_id),
            clm_rrhh_acta_trabajador_nombre = VALUES(clm_rrhh_acta_trabajador_nombre),
            clm_rrhh_acta_trabajador_dni = VALUES(clm_rrhh_acta_trabajador_dni),
            clm_rrhh_acta_area = VALUES(clm_rrhh_acta_area),
            clm_rrhh_acta_posicion = VALUES(clm_rrhh_acta_posicion),
            clm_rrhh_acta_motivo = VALUES(clm_rrhh_acta_motivo),
            clm_rrhh_acta_total = VALUES(clm_rrhh_acta_total),
            clm_rrhh_acta_descuenta = VALUES(clm_rrhh_acta_descuenta),
            clm_rrhh_acta_cuotas = VALUES(clm_rrhh_acta_cuotas),
            clm_rrhh_acta_fecha_descuento = VALUES(clm_rrhh_acta_fecha_descuento),
            clm_rrhh_acta_observaciones = VALUES(clm_rrhh_acta_observaciones),
            clm_rrhh_acta_recibe_nombre = VALUES(clm_rrhh_acta_recibe_nombre),
            clm_rrhh_acta_recibe_dni = VALUES(clm_rrhh_acta_recibe_dni),
            clm_rrhh_acta_recibe_cargo = VALUES(clm_rrhh_acta_recibe_cargo),
            clm_rrhh_acta_entrega_nombre = VALUES(clm_rrhh_acta_entrega_nombre),
            clm_rrhh_acta_entrega_dni = VALUES(clm_rrhh_acta_entrega_dni),
            clm_rrhh_acta_entrega_cargo = VALUES(clm_rrhh_acta_entrega_cargo),
            clm_rrhh_acta_fecha_actualizacion = CURRENT_TIMESTAMP
    ");

    alm_bind($stmt, 'isisssssdiissssssssi', $params);
    $stmt->execute();
    $stmt->close();

    $row = alm_fetch_one($conn, 'SELECT clm_rrhh_acta_id FROM tb_rrhh_acta_uniformes WHERE clm_rrhh_acta_idnota = ? LIMIT 1', 'i', [$notaId]);
    return (int)($row['clm_rrhh_acta_id'] ?? 0);
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
    $originId = alm_origin_id_from_payload($payload);
    $context = alm_context_config_from_origin($originId);

    if ($productId <= 0 || $cantidad <= 0) {
        alm_json(['ok' => false, 'message' => 'Producto y cantidad son obligatorios.'], 422);
    }

    $product = alm_fetch_one($conn, "
        SELECT p.clm_alm_producto_id,
               COALESCE(p.clm_alm_producto_prec_unit, 0) AS precio
        FROM tb_alm_producto p
        WHERE p.clm_alm_producto_id = ?
          AND p.clm_alm_producto_idCATEGORIA <> 11
          AND UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN')) = ?
          AND UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), 'CONSUMIBLE')) = ?
        LIMIT 1
    ", 'iss', [$productId, $context['area_control'], $context['tipo_control']]);

    if (!$product) {
        alm_json(['ok' => false, 'message' => 'Producto no encontrado para el origen actual.'], 404);
    }

    $precio = alm_can_edit_prices() ? alm_float($payload['precio_unitario'] ?? 0) : (float)($product['precio'] ?? 0);
    if ($precio < 0) {
        alm_json(['ok' => false, 'message' => 'El precio unitario no puede ser negativo.'], 422);
    }
    $monto = $cantidad * $precio;
    $autoPdf = !empty($payload['auto_pdf']);

    if ($originId !== 1) {
        $sedeId = 0;
        $anaquelId = 0;
        $ubicacionRaw = '';
        $ubicacionLabel = '';
        $bb = '00';
        $nn = '00';
        $ssss = '0000';
    }

    $tipo = alm_producto_tiene_movimientos($conn, $productId) ? 'ENTRADA' : 'INVENTARIADO';
    $generarEtq = ($originId === 1 && !empty($payload['gen_etq']) && $sedeId > 0) ? 1 : 0;
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
    $espacio = $originId === 1
        ? ($ubicacionLabel !== '' ? $ubicacionLabel : $context['espacio_default'])
        : $context['espacio_default'];
    $serie = $context['serie_entrada'];
    $notaModulo = $context['nota_modulo'];
    $placaNull = null;

    $conn->begin_transaction();

    $notaParams = [$fecha, $responsable, $notaModulo, $observacion, $placaNull, $espacio, $proveedor, $dni, $serie];
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
    $notaCodigo = (string)($nota['clm_nota_sco'] ?? ($serie . '-' . str_pad((string)$notaId, 4, '0', STR_PAD_LEFT)));

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
    alm_validate_config_password($conn, 'NOTA_SALIDA_PASS', (string)($payload['password'] ?? ''), 'salida');
    unset($payload['password']);

    $salidaOriginId = alm_origin_id_from_payload($payload);
    $context = alm_context_config_from_origin($salidaOriginId);
    $busBloqueado = !empty($payload['bus_bloqueado']);
    $placaId = $busBloqueado ? null : (int)($payload['placa_id'] ?? 0);
    $entregado = alm_clean_text($payload['entregado_a'] ?? '', 220);
    $motivo = alm_clean_text($payload['motivo'] ?? '', 900);
    $items = $payload['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
        alm_json(['ok' => false, 'message' => 'Agrega items para la salida.'], 422);
    }

    if ($salidaOriginId === 4 && $entregado === '') {
        alm_json(['ok' => false, 'message' => 'Selecciona o escribe el trabajador que recibira los bienes.'], 422);
    }

    if (!$busBloqueado && (int)$placaId <= 0) {
        alm_json(['ok' => false, 'message' => 'Selecciona unidad o activa Sin bus para la salida.'], 422);
    }

    if (!$busBloqueado) {
        $bus = alm_fetch_one($conn, "
            SELECT clm_placas_id
            FROM tb_placas
            WHERE clm_placas_id = ? AND clm_placas_ESTADO = 'Activo'
            LIMIT 1
        ", 'i', [$placaId]);

        if (!$bus) {
            alm_json(['ok' => false, 'message' => 'La unidad seleccionada no esta activa o no existe.'], 422);
        }
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
              AND p.clm_alm_producto_idCATEGORIA <> 11
              AND UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_area_control), ''), 'ALMACEN')) = ?
              AND UPPER(COALESCE(NULLIF(TRIM(p.clm_alm_producto_tipo_control), ''), 'CONSUMIBLE')) = ?
            LIMIT 1
        ", 'iss', [$productId, $context['area_control'], $context['tipo_control']]);

        if (!$product) {
            alm_json(['ok' => false, 'message' => 'Uno de los productos no existe para el origen actual.'], 422);
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
    $serie = $context['serie_salida'];
    $espacio = $context['espacio_default'];
    $notaModulo = $context['nota_modulo'];
    $tipo = 'SALIDA';

    $conn->begin_transaction();

    $notaParams = [$fecha, $responsable, $notaModulo, $motivo, $placaId, $espacio, $entregado, $dni, $serie];
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
    $notaCodigo = (string)($nota['clm_nota_sco'] ?? ($serie . '-' . str_pad((string)$notaId, 4, '0', STR_PAD_LEFT)));

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

    $actaId = null;
    if ($salidaOriginId === 4) {
        $actaId = alm_save_rrhh_acta_uniformes($conn, $notaId, $userId, $payload, $preparedItems, $entregado);
    }

    $conn->commit();

    alm_json([
        'ok' => true,
        'message' => 'Salida registrada correctamente.',
        'movimiento_id' => $movId,
        'nota_id' => $notaId,
        'nota_codigo' => $notaCodigo,
        'acta_id' => $actaId,
        'auto_pdf' => true,
        'pdf_params' => [
            'id_nota' => $notaId,
            'series' => $serie,
        ],
    ]);
}