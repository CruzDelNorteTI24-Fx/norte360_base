<?php
if (!defined('N360_COMB_REG')) exit('Acceso no permitido.');

function comb_prod_text(array $payload, string $key, string $label, int $max): string {
    $value = $payload[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new InvalidArgumentException('Revisa el campo ' . $label . '.');
    }
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value));
    if (mb_strlen($value, 'UTF-8') > $max) throw new InvalidArgumentException($label . ' admite hasta ' . $max . ' caracteres.');
    return $value;
}

function comb_reg_api_crear_producto(mysqli $conn, array $payload): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        comb_reg_json(['ok' => false, 'message' => 'Usa el formulario para crear un combustible.'], 405);
    }
    if (!comb_reg_is_admin()) comb_reg_json(['ok' => false, 'message' => 'Solo administradores pueden crear combustibles.'], 403);
    if (!is_string($payload['csrf'] ?? null)) comb_reg_json(['ok' => false, 'message' => 'Token de seguridad inválido. Actualiza la página.'], 419);
    comb_reg_validate_csrf($payload);

    try {
        $nombre = comb_prod_text($payload, 'nombre', 'Nombre', 220);
        $unidad = mb_strtoupper(comb_prod_text($payload, 'unidad', 'Unidad', 30), 'UTF-8');
        $descripcion = comb_prod_text($payload, 'descripcion', 'Descripción', 900);
        if ($nombre === '' || $unidad === '') throw new InvalidArgumentException('Nombre y unidad son obligatorios.');
        $stockRaw = $payload['stock_minimo'] ?? '0';
        if ((!is_string($stockRaw) && !is_int($stockRaw)) || !preg_match('/^\d{1,10}$/D', (string)$stockRaw)
            || (float)$stockRaw > 2147483647) {
            throw new InvalidArgumentException('El stock mínimo debe ser un número entero entre 0 y 2147483647.');
        }
        $stockMinimo = (int)$stockRaw;
        $imagen = null;
        if (isset($_FILES['imagen'])) {
            $file = $_FILES['imagen'];
            if (!is_array($file) || !is_int($file['error'] ?? null)) throw new InvalidArgumentException('Revisa la imagen seleccionada.');
            if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
                    || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('No se pudo recibir la imagen. Máximo 4 MB.');
                if ((int)$file['size'] > 4 * 1024 * 1024) throw new InvalidArgumentException('La imagen supera el límite de 4 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $size = @getimagesize($file['tmp_name']);
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) || !$size) {
                    throw new InvalidArgumentException('La imagen debe ser un archivo PNG, JPG, WEBP o GIF válido.');
                }
                $imagen = file_get_contents($file['tmp_name']);
                if ($imagen === false) throw new InvalidArgumentException('No se pudo leer la imagen.');
            }
        }

        $conn->begin_transaction();
        $category = comb_reg_fetch_one($conn, 'SELECT clm_alm_categoria_NOMBRE AS codigo
            FROM tb_alm_categoria WHERE clm_alm_categoria_id = 11 LIMIT 1 FOR UPDATE');
        if (!$category) throw new RuntimeException('La categoría de Combustible no está disponible. Contacta al administrador.', 409);

        // La clasificación se fija en servidor. No se aceptan categoría, área, tipo o precio del cliente.
        $params = [$nombre, $unidad, $stockMinimo, $descripcion, $imagen, date('Y-m-d H:i:s')];
        $stmt = $conn->prepare("INSERT INTO tb_alm_producto
            (clm_alm_producto_idCATEGORIA, clm_alm_producto_area_control, clm_alm_producto_tipo_control,
             clm_alm_producto_NOMBRE, clm_alm_producto_unidad, clm_alm_producto_stock_minimo,
             clm_alm_producto_DESCRIPCION, clm_alm_producto_IMG, clm_alm_producto_fecha_registro,
             clm_alm_producto_prec_unit, clm_alm_producto_precioextra)
            VALUES (11, 'COMBUSTIBLE', 'CONSUMIBLE', ?, ?, ?, ?, ?, ?, 0, 0)");
        if (!$stmt) throw new RuntimeException('No se pudo preparar el alta.');
        comb_reg_bind($stmt, 'ssisss', $params);
        if (!$stmt->execute()) throw new RuntimeException('No se pudo crear el producto.');
        $productId = (int)$conn->insert_id;
        $stmt->close();
        $prefix = trim((string)$category['codigo']) ?: 'CM';
        $codigo = $prefix . str_pad((string)$productId, 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare('UPDATE tb_alm_producto SET clm_alm_producto_codigo = ? WHERE clm_alm_producto_id = ?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar el código.');
        $stmt->bind_param('si', $codigo, $productId);
        if (!$stmt->execute()) throw new RuntimeException('No se pudo asignar el código.');
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $known = $e instanceof InvalidArgumentException || $e->getCode() === 409;
        if (!$known) error_log('combustible crear_producto: ' . $e->getMessage());
        comb_reg_json(['ok' => false, 'message' => $known ? $e->getMessage() : 'No se pudo crear el combustible. Inténtalo nuevamente.'],
            $e instanceof InvalidArgumentException ? 422 : ($known ? 409 : 500));
    }
    comb_reg_json(['ok' => true, 'message' => 'Combustible creado. Registra su primera entrada para ingresar existencias.',
        'product' => ['id' => $productId, 'codigo' => $codigo, 'nombre' => $nombre, 'unidad' => $unidad,
            'precio_unitario' => 0, 'precio_extra' => 0],
        'context' => ['context' => 'combustible', 'categoria_id' => 11, 'area_control' => 'COMBUSTIBLE', 'tipo_control' => 'CONSUMIBLE']]);
}
