<?php
if (!defined('N360_ENCOMIENDAS')) {
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/../../layout/security_n360.php';

const ENC_MODULO_ID = 13;
const ENC_CSRF_SCOPE = 'encomiendas';
const ENC_MAX_PDF_BYTES = 10485760;

function enc_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function enc_now_date(): string {
    return date('Y-m-d');
}

function enc_user_id(): int {
    return (int)($_SESSION['id_usuario'] ?? $_SESSION['usuario_id'] ?? 0);
}

function enc_user_name(): string {
    return trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario'));
}

function enc_is_admin(): bool {
    return (string)($_SESSION['web_rol'] ?? '') === 'Admin';
}

function enc_session_values(string $key): array {
    if (($_SESSION['permisos'] ?? null) === 'all') {
        return [];
    }
    $values = $_SESSION[$key] ?? [];
    return is_array($values) ? $values : [];
}

function enc_can_module(int $moduleId = ENC_MODULO_ID): bool {
    if (enc_is_admin()) return true;
    $permisos = array_map('intval', enc_session_values('permisos'));
    return in_array($moduleId, $permisos, true);
}

function enc_can_view(string $viewCode): bool {
    if (enc_is_admin()) return true;
    $views = array_map('strval', enc_session_values('vistas'));
    return in_array($viewCode, $views, true);
}

function enc_can_any_view(array $viewCodes): bool {
    foreach ($viewCodes as $viewCode) {
        if (enc_can_view((string)$viewCode)) return true;
    }
    return false;
}

function enc_open_connection_json(): mysqli {
    if (!defined('ACCESS_GRANTED')) {
        define('ACCESS_GRANTED', true);
    }
    require __DIR__ . '/../../.c0nn3ct/db_securebd2.php';

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        enc_json(false, 'No se pudo conectar a la base de datos.', [], 500);
    }

    $conn->set_charset('utf8mb4');
    @$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
    return $conn;
}

function enc_start_page(string $viewCode, string $viewLabel): mysqli {
    n360_start_secure_session();
    n360_send_security_headers();
    date_default_timezone_set('America/Lima');

    if (!isset($_SESSION['usuario'])) {
        header('Location: ../login/login.php');
        exit;
    }

    if (!enc_can_module() || ($viewCode !== '' && !enc_can_view($viewCode))) {
        header('Location: ../login/none_permisos.php?vista=' . urlencode($viewLabel));
        exit;
    }

    if (!defined('ACCESS_GRANTED')) {
        define('ACCESS_GRANTED', true);
    }
    require __DIR__ . '/../../.c0nn3ct/db_securebd2.php';

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        die('No se pudo conectar a la base de datos.');
    }

    $conn->set_charset('utf8mb4');
    @$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
    return $conn;
}

function enc_start_action(string $viewCode = ''): mysqli {
    n360_start_secure_session();
    n360_send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    date_default_timezone_set('America/Lima');

    if (!isset($_SESSION['usuario'])) {
        enc_json(false, 'Sesion expirada. Vuelve a iniciar sesion.', [], 401);
    }

    if (!enc_can_module() || ($viewCode !== '' && !enc_can_view($viewCode))) {
        enc_json(false, 'No tienes permiso para realizar esta operacion.', [], 403);
    }

    return enc_open_connection_json();
}

function enc_start_read_json(string $viewCode = ''): mysqli {
    n360_start_secure_session();
    n360_send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    date_default_timezone_set('America/Lima');

    if (!isset($_SESSION['usuario'])) {
        enc_json(false, 'Sesion expirada. Vuelve a iniciar sesion.', [], 401);
    }

    if (!enc_can_module() || ($viewCode !== '' && !enc_can_view($viewCode))) {
        enc_json(false, 'No tienes permiso para consultar esta informacion.', [], 403);
    }

    return enc_open_connection_json();
}

function enc_verify_action_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!n360_verify_csrf($token, ENC_CSRF_SCOPE)) {
        enc_json(false, 'Token de seguridad invalido. Actualiza la pagina e intenta otra vez.', [], 419);
    }
}

function enc_csrf_token(): string {
    return n360_csrf_token(ENC_CSRF_SCOPE);
}

function enc_json(bool $ok, string $message, array $extra = [], int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function enc_bind(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '') return;
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    $stmt->bind_param($types, ...$refs);
}

function enc_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    enc_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function enc_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = enc_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function enc_execute(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    enc_bind($stmt, $types, $params);
    $stmt->execute();
    return $stmt;
}

function enc_like_expr(string $columnSql): string {
    return "CONVERT($columnSql USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, '%')";
}

function enc_nullable_string($value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function enc_nullable_date($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : null;
}

function enc_valid_date_required($value): ?string {
    return enc_nullable_date($value);
}

function enc_id_or_null($value): ?int {
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

function enc_fmt_date($value): string {
    if (!$value) return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y', $ts) : (string)$value;
}

function enc_fmt_datetime($value): string {
    if (!$value) return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
}

function enc_file_size($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes, 0) . ' B';
}

function enc_state_meta(?string $state): array {
    $state = strtoupper((string)$state);
    $map = [
        'REGISTRADA' => ['Registrada', 'info', 'bi-journal-check'],
        'PENDIENTE' => ['Pendiente', 'muted', 'bi-hourglass-split'],
        'EMBARCADO' => ['Embarcada', 'primary', 'bi-box-arrow-up-right'],
        'EN_TRANSITO' => ['En transito', 'primary', 'bi-truck-front-fill'],
        'RECIBIDO' => ['Recibida', 'success', 'bi-check2-circle'],
        'FINALIZADA' => ['Finalizada', 'success', 'bi-patch-check-fill'],
        'OBSERVADO' => ['Observada', 'warning', 'bi-exclamation-triangle-fill'],
        'OBSERVADA' => ['Observada', 'warning', 'bi-exclamation-triangle-fill'],
        'INCOMPLETO' => ['Incompleta', 'warning', 'bi-clipboard-x-fill'],
        'ANULADA' => ['Anulada', 'danger', 'bi-x-octagon-fill'],
        'ORIGEN' => ['Origen', 'info', 'bi-geo-alt-fill'],
        'RUTA' => ['Ruta', 'primary', 'bi-signpost-split-fill'],
        'DESTINO' => ['Destino', 'success', 'bi-flag-fill'],
    ];
    return $map[$state] ?? [$state !== '' ? $state : '-', 'muted', 'bi-circle'];
}

function enc_state_badge(?string $state): string {
    [$label, $variant, $icon] = enc_state_meta($state);
    return '<span class="enc-state enc-state--' . enc_h($variant) . '"><i class="bi ' . enc_h($icon) . '"></i>' . enc_h($label) . '</span>';
}

function enc_doc_label(string $type): string {
    $type = strtoupper($type);
    if ($type === 'MANIFIESTO_ENCOMIENDAS') return 'Manifiesto de encomiendas';
    if ($type === 'GUIA_TRANSPORTISTA') return 'Guia de transportista';
    return 'Documento de encomienda';
}

function enc_doc_comprobante_label(?string $type): string {
    $type = strtoupper((string)$type);
    $map = [
        'FACTURA' => 'Factura',
        'BOLETA' => 'Boleta',
        'RECIBO' => 'Recibo',
        'SIN_COMPROBANTE' => 'Sin comprobante',
    ];
    return $map[$type] ?? 'Sin dato';
}

function enc_point_label(?string $type): string {
    [$label] = enc_state_meta($type);
    return $label;
}

function enc_safe_pdf_name(string $name, string $fallback): string {
    $name = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', trim($name));
    if ($name === '' || strtolower(substr($name, -4)) !== '.pdf') {
        $name = $fallback . '.pdf';
    }
    return $name;
}

function enc_validate_pdf_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'No se pudo cargar el archivo PDF. Codigo: ' . (int)($file['error'] ?? -1), null];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [false, 'El archivo cargado no es valido.', null];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return [false, 'El PDF no puede estar vacio.', null];
    }
    if ($size > ENC_MAX_PDF_BYTES) {
        return [false, 'El PDF supera el tamano maximo permitido de 10 MB.', null];
    }

    $originalName = (string)($file['name'] ?? 'documento.pdf');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
        return [false, 'Solo se permiten archivos con extension PDF.', null];
    }

    $handle = fopen($tmp, 'rb');
    $signature = $handle ? fread($handle, 4) : '';
    if ($handle) fclose($handle);
    if ($signature !== '%PDF') {
        return [false, 'El archivo no contiene una firma PDF valida.', null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        return [false, 'El contenido cargado no corresponde a un PDF.', null];
    }

    $content = file_get_contents($tmp);
    if ($content === false || $content === '') {
        return [false, 'No se pudo leer el PDF cargado.', null];
    }

    return [true, '', [
        'name' => enc_safe_pdf_name($originalName, 'documento_encomienda'),
        'mime' => 'application/pdf',
        'size' => strlen($content),
        'sha256' => hash('sha256', $content),
        'content' => $content,
    ]];
}

function enc_log(Throwable $e): void {
    error_log('[N360 Encomiendas] ' . $e->getMessage());
}

function enc_db_message(Throwable $e): string {
    $message = $e->getMessage();
    if (stripos($message, 'uq_enc_guia') !== false || stripos($message, 'uq_enc_serie_correlativo') !== false || stripos($message, 'Duplicate') !== false) {
        return 'Ya existe una Control Encomienda con ese correlativo.';
    }
    if (stripos($message, 'unknown column') !== false || stripos($message, 'doesn\'t exist') !== false || stripos($message, 'Base table or view not found') !== false) {
        return 'Falta ejecutar la migracion SQL de Control Encomiendas antes de usar esta vista.';
    }
    if (stripos($message, 'foreign key') !== false) {
        return 'Uno de los datos seleccionados ya no existe o no esta disponible.';
    }

    $knownBusinessMessages = [
        'La oficina de embarque no puede ser igual a la oficina de desembarque',
        'Primero debes registrar la Control Encomienda y posteriormente procesar el desembarque',
        'No puedes procesar el desembarque si la Control Encomienda no fue embarcada',
        'Para finalizar el desembarque debes adjuntar los manifiestos PDF de todos los puntos obligatorios',
        'El manifiesto debe estar asociado a un punto de la Control Encomienda',
        'Tipo de documento de encomienda no valido',
        'El documento de encomienda debe tener extension PDF',
        'El archivo adjuntado no contiene una firma PDF valida',
        'El archivo PDF no puede estar vacio',
        'Debes indicar el usuario que realiza la modificacion',
    ];
    foreach ($knownBusinessMessages as $businessMessage) {
        if (stripos($message, $businessMessage) !== false) {
            return rtrim($businessMessage, '.') . '.';
        }
    }

    return 'No se pudo completar la operacion. Revisa los datos e intenta nuevamente.';
}
