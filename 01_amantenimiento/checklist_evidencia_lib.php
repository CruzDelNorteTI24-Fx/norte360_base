<?php

const N360_CHECKLIST_EVIDENCE_MAX_BYTES = 8388608;

function n360_checklist_evidence_column_names(): array
{
    return [
        'clm_checklist_evidencia',
        'clm_checklist_evidencia_nombre',
        'clm_checklist_evidencia_mime',
        'clm_checklist_evidencia_size',
        'clm_checklist_evidencia_sha256',
        'clm_checklist_evidencia_idusuario',
        'clm_checklist_evidencia_fechacarga',
    ];
}

function n360_checklist_evidence_schema_ready(mysqli $conn): bool
{
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $columns = n360_checklist_evidence_column_names();
    $quoted = implode(',', array_fill(0, count($columns), '?'));
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_checklist_limpieza'
              AND COLUMN_NAME IN ($quoted)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }

    $types = str_repeat('s', count($columns));
    $bind = [$types];
    foreach ($columns as $index => $column) {
        $bind[] = &$columns[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);

    if (!$stmt->execute()) {
        $stmt->close();
        $cache[$key] = false;
        return false;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cache[$key] = (int)($row['total'] ?? 0) === count($columns);
    return $cache[$key];
}

function n360_checklist_evidence_select_sql(mysqli $conn, string $alias = 'c'): string
{
    if (!n360_checklist_evidence_schema_ready($conn)) {
        return "NULL AS clm_checklist_evidencia_nombre,
                NULL AS clm_checklist_evidencia_mime,
                NULL AS clm_checklist_evidencia_size,
                NULL AS clm_checklist_evidencia_sha256,
                NULL AS clm_checklist_evidencia_idusuario,
                NULL AS clm_checklist_evidencia_fechacarga";
    }

    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    $prefix = $prefix !== '' ? $prefix . '.' : '';
    return "{$prefix}clm_checklist_evidencia_nombre,
            {$prefix}clm_checklist_evidencia_mime,
            {$prefix}clm_checklist_evidencia_size,
            {$prefix}clm_checklist_evidencia_sha256,
            {$prefix}clm_checklist_evidencia_idusuario,
            {$prefix}clm_checklist_evidencia_fechacarga";
}

function n360_checklist_evidence_upload_error(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'La evidencia supera el limite permitido de 8 MB.';
        case UPLOAD_ERR_PARTIAL:
            return 'La evidencia no termino de cargarse. Intenta nuevamente.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'El servidor no pudo recibir la evidencia.';
        default:
            return 'No se pudo cargar la evidencia.';
    }
}

function n360_checklist_evidence_parse_upload(array $files, string $field = 'evidencia_checklist', bool $required = false): ?array
{
    if (!isset($files[$field]) || !is_array($files[$field])) {
        if ($required) {
            throw new RuntimeException('Selecciona una imagen o un archivo PDF como evidencia.');
        }
        return null;
    }

    $upload = $files[$field];
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('Selecciona una imagen o un archivo PDF como evidencia.');
        }
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(n360_checklist_evidence_upload_error($error));
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('No se pudo validar el archivo temporal de la evidencia.');
    }

    $declaredSize = (int)($upload['size'] ?? 0);
    if ($declaredSize <= 0) {
        throw new RuntimeException('La evidencia seleccionada esta vacia.');
    }
    if ($declaredSize > N360_CHECKLIST_EVIDENCE_MAX_BYTES) {
        throw new RuntimeException('La evidencia supera el limite permitido de 8 MB.');
    }

    $bytes = file_get_contents($tmpName);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('No se pudo leer la evidencia seleccionada.');
    }
    $size = strlen($bytes);
    if ($size > N360_CHECKLIST_EVIDENCE_MAX_BYTES) {
        throw new RuntimeException('La evidencia supera el limite permitido de 8 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file($tmpName));
    $allowed = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato no permitido. Usa JPG, PNG, WEBP o PDF.');
    }

    $originalName = basename((string)($upload['name'] ?? 'evidencia'));
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed[$mime], true)) {
        throw new RuntimeException('La extension del archivo no coincide con su contenido.');
    }

    if (strpos($mime, 'image/') === 0 && @getimagesize($tmpName) === false) {
        throw new RuntimeException('La imagen seleccionada no es valida.');
    }

    $safeName = preg_replace('/[^\pL\pN._ -]+/u', '_', $originalName);
    $safeName = trim((string)$safeName, " .\t\n\r\0\x0B");
    if ($safeName === '') {
        $safeName = 'evidencia.' . $extension;
    }
    if (function_exists('mb_substr')) {
        $safeName = mb_substr($safeName, 0, 180, 'UTF-8');
    } else {
        $safeName = substr($safeName, 0, 180);
    }

    return [
        'bytes' => $bytes,
        'name' => $safeName,
        'mime' => $mime,
        'size' => $size,
        'sha256' => hash('sha256', $bytes),
    ];
}

function n360_checklist_evidence_store(mysqli $conn, int $checklistId, int $userId, array $evidence): void
{
    if ($checklistId <= 0) {
        throw new RuntimeException('El checklist seleccionado no es valido.');
    }
    if (!n360_checklist_evidence_schema_ready($conn)) {
        throw new RuntimeException('La base de datos aun no tiene habilitado el almacenamiento de evidencias.');
    }

    $name = (string)($evidence['name'] ?? '');
    $mime = (string)($evidence['mime'] ?? '');
    $size = (int)($evidence['size'] ?? 0);
    $sha256 = (string)($evidence['sha256'] ?? '');
    $bytes = (string)($evidence['bytes'] ?? '');
    if ($name === '' || $mime === '' || $size <= 0 || $sha256 === '' || $bytes === '') {
        throw new RuntimeException('La evidencia no contiene la informacion necesaria para guardarse.');
    }

    $stmt = $conn->prepare(
        'UPDATE tb_checklist_limpieza
         SET clm_checklist_evidencia = ?,
             clm_checklist_evidencia_nombre = ?,
             clm_checklist_evidencia_mime = ?,
             clm_checklist_evidencia_size = ?,
             clm_checklist_evidencia_sha256 = ?,
             clm_checklist_evidencia_idusuario = ?,
             clm_checklist_evidencia_fechacarga = NOW()
         WHERE clm_checklist_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el guardado de la evidencia.');
    }

    $blob = null;
    $stmt->bind_param('bssisii', $blob, $name, $mime, $size, $sha256, $userId, $checklistId);
    $length = strlen($bytes);
    for ($offset = 0; $offset < $length; $offset += 8192) {
        $stmt->send_long_data(0, substr($bytes, $offset, 8192));
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo guardar la evidencia del checklist.');
    }
    $stmt->close();
}

function n360_checklist_evidence_has(array $row): bool
{
    return trim((string)($row['clm_checklist_evidencia_nombre'] ?? '')) !== ''
        && trim((string)($row['clm_checklist_evidencia_mime'] ?? '')) !== ''
        && (int)($row['clm_checklist_evidencia_size'] ?? 0) > 0;
}

function n360_checklist_evidence_size_label($bytes): string
{
    $bytes = max(0, (int)$bytes);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

