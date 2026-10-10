<?php

const N360_FLOTA_EVIDENCE_MAX_BYTES = 8388608;

function n360_flota_evidence_can_upload(array $session): bool
{
    if (!isset($session['usuario'])) {
        return false;
    }
    if (($session['web_rol'] ?? '') === 'Admin') {
        return true;
    }
    $modules = (array)($session['permisos'] ?? []);
    $views = (array)($session['vistas'] ?? []);
    return in_array(10, $modules)
        && count(array_intersect(['f-proghor', 'f-consalbus', 'f-proghist'], $views)) > 0;
}

function n360_flota_evidence_can_access(array $session): bool
{
    return n360_flota_evidence_can_upload($session)
        || (isset($session['usuario'])
            && in_array(10, (array)($session['permisos'] ?? []))
            && count(array_intersect(['f-consalbus-ver', 'f-consalbus-aprobar'], (array)($session['vistas'] ?? []))) > 0);
}

function n360_flota_evidence_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Selecciona una fecha operativa valida.');
    }
    return $value;
}

function n360_flota_evidence_slot(int $slot): int
{
    if (!in_array($slot, [1, 2], true)) {
        throw new InvalidArgumentException('Solo hay dos espacios de evidencia por dia operativo.');
    }
    return $slot;
}

function n360_flota_evidence_schema_ready(mysqli $conn): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_progbuses_evidencias_operativas'");
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) === 1;
}

function n360_flota_evidence_metadata(mysqli $conn, string $start, string $end): array
{
    n360_flota_evidence_date($start);
    n360_flota_evidence_date($end);
    if ($end < $start) {
        throw new InvalidArgumentException('El periodo operativo no es valido.');
    }
    $stmt = $conn->prepare('SELECT
        clm_eviop_id AS id, clm_eviop_fecha_operativa AS fecha,
        clm_eviop_cupo AS cupo, clm_eviop_nombre AS nombre,
        clm_eviop_mime AS mime, clm_eviop_size AS size,
        clm_eviop_usuario AS usuario, clm_eviop_fechacarga AS fechacarga,
        clm_eviop_revision AS revision
        FROM tb_progbuses_evidencias_operativas
        WHERE clm_eviop_fecha_operativa BETWEEN ? AND ?
        ORDER BY clm_eviop_fecha_operativa, clm_eviop_cupo');
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar las evidencias.');
    }
    $stmt->bind_param('ss', $start, $end);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo consultar las evidencias.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function n360_flota_evidence_validate_file(string $path, string $originalName): array
{
    $size = filesize($path);
    if (!$size || $size > N360_FLOTA_EVIDENCE_MAX_BYTES) {
        throw new InvalidArgumentException('El archivo debe tener contenido y pesar hasta 8 MB.');
    }
    $allowed = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($path));
    $name = basename(str_replace('\\', '/', $originalName));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) {
        throw new InvalidArgumentException('Usa JPG, PNG, WEBP o PDF; la extension debe coincidir con su contenido.');
    }
    if (strpos($mime, 'image/') === 0 && @getimagesize($path) === false) {
        throw new InvalidArgumentException('La imagen seleccionada no es valida.');
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > N360_FLOTA_EVIDENCE_MAX_BYTES) {
        throw new InvalidArgumentException('No se pudo leer el archivo o supera los 8 MB.');
    }
    if ($mime === 'application/pdf' && strncmp($bytes, '%PDF-', 5) !== 0) {
        throw new InvalidArgumentException('El PDF seleccionado no es valido.');
    }
    $name = trim((string)preg_replace('/[^\pL\pN._ -]+/u', '_', $name), " .\t\r\n\0\x0B");
    if ($name === '') $name = 'evidencia.' . $extension;
    $name = function_exists('mb_strcut') ? mb_strcut($name, 0, 180, 'UTF-8') : substr($name, 0, 180);
    return ['bytes' => $bytes, 'nombre' => $name, 'mime' => $mime,
        'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
}

function n360_flota_evidence_upload(array $files): array
{
    $upload = $files['evidencia'] ?? null;
    if (!is_array($upload) || is_array($upload['error'] ?? null)) {
        throw new InvalidArgumentException('Selecciona un archivo para este espacio.');
    }
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new InvalidArgumentException('El archivo supera el limite de carga del servidor (maximo 8 MB).');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('El archivo no termino de cargarse. Seleccionalo e intenta nuevamente.');
    }
    $path = (string)($upload['tmp_name'] ?? '');
    if ($path === '' || !is_uploaded_file($path)) {
        throw new InvalidArgumentException('No se pudo validar el archivo recibido.');
    }
    return n360_flota_evidence_validate_file($path, (string)($upload['name'] ?? ''));
}

function n360_flota_evidence_store(mysqli $conn, string $date, int $slot, int $revision,
    int $userId, string $user, array $evidence): void
{
    n360_flota_evidence_date($date);
    n360_flota_evidence_slot($slot);
    if ($revision < 0 || $userId <= 0) {
        throw new InvalidArgumentException('La sesion o la evidencia seleccionada no es valida.');
    }
    $blob = null;
    $name = $evidence['nombre'];
    $mime = $evidence['mime'];
    $size = $evidence['size'];
    $hash = $evidence['sha256'];
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
    $user = function_exists('mb_strcut') ? mb_strcut($user, 0, 180, 'UTF-8') : substr($user, 0, 180);

    // La revision evita sobrescribir una carga concurrente; un espacio nuevo usa INSERT normal.
    if ($revision === 0) {
        $stmt = $conn->prepare('INSERT INTO tb_progbuses_evidencias_operativas
            (clm_eviop_archivo, clm_eviop_nombre, clm_eviop_mime, clm_eviop_size,
             clm_eviop_sha256, clm_eviop_idusuario, clm_eviop_usuario, clm_eviop_fechacarga,
             clm_eviop_fecha_operativa, clm_eviop_cupo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('No se pudo preparar la carga.');
        $stmt->bind_param('bssisisssi', $blob, $name, $mime, $size, $hash, $userId, $user, $now, $date, $slot);
    } else {
        $stmt = $conn->prepare('UPDATE tb_progbuses_evidencias_operativas
            SET clm_eviop_archivo = ?, clm_eviop_nombre = ?, clm_eviop_mime = ?,
                clm_eviop_size = ?, clm_eviop_sha256 = ?, clm_eviop_idusuario = ?,
                clm_eviop_usuario = ?, clm_eviop_fechacarga = ?, clm_eviop_revision = clm_eviop_revision + 1
            WHERE clm_eviop_fecha_operativa = ? AND clm_eviop_cupo = ? AND clm_eviop_revision = ?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar el reemplazo.');
        $stmt->bind_param('bssisisssii', $blob, $name, $mime, $size, $hash, $userId, $user, $now, $date, $slot, $revision);
    }
    try {
        $bytes = $evidence['bytes'];
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += 8192) {
            if (!$stmt->send_long_data(0, substr($bytes, $offset, 8192))) {
                throw new RuntimeException('No se pudo transferir el archivo a la base de datos.');
            }
        }
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) {
                throw new RuntimeException('Otra persona ya ocupo este espacio. Actualiza las evidencias.', 409);
            }
            throw new RuntimeException('No se pudo guardar la evidencia.');
        }
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('La evidencia cambio mientras la editabas. Actualiza antes de reemplazarla.', 409);
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            throw new RuntimeException('Otra persona ya ocupo este espacio. Actualiza las evidencias.', 409);
        }
        throw new RuntimeException('No se pudo guardar la evidencia.');
    } finally {
        $stmt->close();
    }
}
