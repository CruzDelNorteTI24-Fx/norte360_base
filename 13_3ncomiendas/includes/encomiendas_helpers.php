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
    return trim((string)($_SESSION['usuario'] ?? $_SESSION['nombre'] ?? 'Usuario'));
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

function enc_ascii_fold(string $value): string {
    $plain = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if ($plain === false) {
        $plain = $value;
    }
    return strtolower($plain);
}

function enc_pdf_clean_token(string $value): string {
    if ($value !== '' && !preg_match('//u', $value)) {
        $converted = function_exists('iconv') ? @iconv('Windows-1252', 'UTF-8//IGNORE', $value) : false;
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }
    $value = str_replace("\0", '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function enc_pdf_decode_utf16be(string $bytes): string {
    if (function_exists('mb_convert_encoding')) {
        $decoded = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }
    $decoded = function_exists('iconv') ? @iconv('UTF-16BE', 'UTF-8//IGNORE', $bytes) : false;
    return is_string($decoded) ? $decoded : $bytes;
}

function enc_pdf_decode_literal_string(string $value): string {
    $out = '';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $char = $value[$i];
        if ($char !== '\\') {
            $out .= $char;
            continue;
        }
        if ($i + 1 >= $len) {
            break;
        }
        $next = $value[++$i];
        if ($next === "\r" || $next === "\n") {
            if ($next === "\r" && $i + 1 < $len && $value[$i + 1] === "\n") {
                $i++;
            }
            continue;
        }
        $map = [
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\b",
            'f' => "\f",
            '(' => '(',
            ')' => ')',
            '\\' => '\\',
        ];
        if (isset($map[$next])) {
            $out .= $map[$next];
            continue;
        }
        if ($next >= '0' && $next <= '7') {
            $octal = $next;
            for ($j = 0; $j < 2 && $i + 1 < $len && $value[$i + 1] >= '0' && $value[$i + 1] <= '7'; $j++) {
                $octal .= $value[++$i];
            }
            $out .= chr(octdec($octal));
            continue;
        }
        $out .= $next;
    }

    if (substr($out, 0, 2) === "\xFE\xFF") {
        $out = enc_pdf_decode_utf16be(substr($out, 2));
    }

    return enc_pdf_clean_token($out);
}

function enc_pdf_decode_hex_string(string $hex): string {
    $hex = preg_replace('/\s+/', '', $hex) ?? '';
    if ($hex === '') {
        return '';
    }
    if (strlen($hex) % 2 === 1) {
        $hex .= '0';
    }
    $bytes = @hex2bin($hex);
    if (!is_string($bytes)) {
        return '';
    }
    if (substr($bytes, 0, 2) === "\xFE\xFF") {
        $bytes = enc_pdf_decode_utf16be(substr($bytes, 2));
    }
    return enc_pdf_clean_token($bytes);
}

function enc_pdf_extract_strings_from_chunk(string $chunk): array {
    $strings = [];
    $len = strlen($chunk);

    for ($i = 0; $i < $len; $i++) {
        if ($chunk[$i] !== '(') {
            continue;
        }
        $depth = 1;
        $raw = '';
        for ($i++; $i < $len; $i++) {
            $char = $chunk[$i];
            if ($char === '\\') {
                $raw .= $char;
                if ($i + 1 < $len) {
                    $raw .= $chunk[++$i];
                }
                continue;
            }
            if ($char === '(') {
                $depth++;
                $raw .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $raw .= $char;
                continue;
            }
            $raw .= $char;
        }
        $decoded = enc_pdf_decode_literal_string($raw);
        if ($decoded !== '') {
            $strings[] = $decoded;
        }
    }

    if (preg_match_all('/(?<!<)<([0-9A-Fa-f\s]{4,})>(?!>)/', $chunk, $matches)) {
        foreach ($matches[1] as $hex) {
            $decoded = enc_pdf_decode_hex_string($hex);
            if ($decoded !== '') {
                $strings[] = $decoded;
            }
        }
    }

    return $strings;
}

function enc_pdf_decode_stream_candidates(string $stream): array {
    $raw = preg_replace('/^\r?\n|\r?\n$/', '', $stream) ?? $stream;
    $candidates = [];

    $decoded = @gzuncompress($raw);
    if (is_string($decoded) && $decoded !== '') {
        $candidates[] = $decoded;
    }

    $decoded = @gzdecode($raw);
    if (is_string($decoded) && $decoded !== '') {
        $candidates[] = $decoded;
    }

    if (strlen($raw) > 6) {
        $decoded = @gzinflate(substr($raw, 2, -4));
        if (is_string($decoded) && $decoded !== '') {
            $candidates[] = $decoded;
        }
    }

    if (!$candidates) {
        $candidates[] = $raw;
    }

    return array_values(array_unique($candidates));
}

function enc_pdf_extract_text_tokens(string $content): array {
    $plainObjects = preg_replace('/stream\r?\n?.*?\r?\n?endstream/s', '', $content) ?? $content;
    $tokens = enc_pdf_extract_strings_from_chunk($plainObjects);

    if (preg_match_all('/stream\r?\n?(.*?)\r?\n?endstream/s', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            foreach (enc_pdf_decode_stream_candidates($stream) as $candidate) {
                foreach (enc_pdf_extract_strings_from_chunk($candidate) as $token) {
                    $tokens[] = $token;
                }
            }
        }
    }

    $clean = [];
    foreach ($tokens as $token) {
        $token = enc_pdf_clean_token($token);
        if ($token !== '' && strlen($token) <= 2000) {
            $clean[] = $token;
        }
    }
    return $clean;
}

function enc_pdf_extract_text(string $content): string {
    return enc_pdf_clean_token(implode("\n", enc_pdf_extract_text_tokens($content)));
}

function enc_pdf_contains_manifest_title(string $content): bool {
    $text = enc_ascii_fold(enc_pdf_extract_text($content));
    return strpos($text, 'manifiesto de encomiendas') !== false;
}

function enc_manifest_doc_codes(string $value): array {
    if (!preg_match_all('/\b(?:[A-Z]{1,4}\d{0,4}|V\d{2,4}|\d{3,4})-\d{4,}\b/i', $value, $matches)) {
        return [];
    }
    return array_values(array_unique(array_map('strtoupper', $matches[0])));
}

function enc_manifest_is_doc_token(string $value): bool {
    return (bool)enc_manifest_doc_codes($value);
}

function enc_manifest_is_number_token(string $value): bool {
    return (bool)preg_match('/^-?\d+(?:[.,]\d+)?$/', trim($value));
}

function enc_manifest_decimal(?string $value): ?float {
    $value = trim((string)$value);
    if ($value === '' || !enc_manifest_is_number_token($value)) {
        return null;
    }
    return (float)str_replace(',', '.', $value);
}

function enc_manifest_is_payment_token(string $value): bool {
    $fold = enc_ascii_fold(trim($value));
    $payments = ['efectivo', 'yape', 'plin', 'transferencia', 'deposito', 'tarjeta', 'credito', 'por cobrar', 'gratis'];
    return in_array($fold, $payments, true);
}

function enc_manifest_label_key(string $value): string {
    $value = enc_ascii_fold($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function enc_manifest_token_is_label_like(string $value): bool {
    $key = enc_manifest_label_key($value);
    if ($key === '') {
        return true;
    }
    $labels = [
        'origen',
        'destino',
        'bus nro',
        'bus',
        'placa',
        'marca',
        'brevete',
        'piloto',
        'copiloto',
        'certificado',
        'documento',
        'asistente',
        'manifiesto de encomiendas',
        'empresa de trans cruz del norte sac compania de bus',
    ];
    return in_array($key, $labels, true);
}

function enc_manifest_next_value(array $tokens, array $labels): ?string {
    $labelKeys = array_map('enc_manifest_label_key', $labels);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = trim((string)$tokens[$i]);
        $key = enc_manifest_label_key($token);
        foreach ($labelKeys as $labelKey) {
            if ($key === $labelKey && isset($tokens[$i + 1])) {
                $next = enc_pdf_clean_token((string)$tokens[$i + 1]);
                if (!enc_manifest_token_is_label_like($next)) {
                    return $next;
                }
                $previous = isset($tokens[$i - 1]) ? enc_pdf_clean_token((string)$tokens[$i - 1]) : '';
                if ($previous !== '' && !enc_manifest_token_is_label_like($previous)) {
                    if ($labelKey !== 'placa' || preg_match('/^[A-Z0-9]{2,5}-[A-Z0-9]{2,5}$/i', $previous)) {
                        return $previous;
                    }
                }
            }
            if (strncmp($key, $labelKey . ' ', strlen($labelKey) + 1) === 0) {
                $value = preg_replace('/^.*?:\s*/', '', $token, 1) ?? $token;
                if ($value !== '') {
                    return enc_pdf_clean_token($value);
                }
            }
        }
    }
    return null;
}

function enc_manifest_parse_items(array $tokens): array {
    $start = 0;
    $end = count($tokens);
    foreach ($tokens as $idx => $token) {
        $fold = enc_ascii_fold($token);
        if (strpos($fold, 'guia de remision') !== false || strpos($fold, 'guia de remision') !== false) {
            $start = $idx + 1;
        }
        if ($idx > $start && (strpos($fold, 'totales') !== false || strpos($fold, 'recibi conforme') !== false || strpos($fold, 'entregue conforme') !== false)) {
            $end = $idx;
            break;
        }
    }

    $rowStarts = [];
    for ($i = $start; $i < $end; $i++) {
        $token = (string)$tokens[$i];
        if (!enc_manifest_is_doc_token($token)) {
            continue;
        }
        $looksLikeRowDocument = (bool)preg_match('/-\s*$/', trim($token)) || strpos($token, ' - ') !== false;
        if (!$looksLikeRowDocument) {
            $previous = '';
            for ($j = $i - 1; $j >= $start; $j--) {
                $previous = trim((string)$tokens[$j]);
                if ($previous !== '') {
                    break;
                }
            }
            if ($previous !== '' && (enc_manifest_is_doc_token($previous) || enc_manifest_is_number_token($previous) || enc_manifest_is_payment_token($previous))) {
                continue;
            }
        }
        $next = '';
        for ($j = $i + 1; $j < $end; $j++) {
            $next = trim((string)$tokens[$j]);
            if ($next !== '') {
                if (enc_manifest_is_doc_token($next)) {
                    continue;
                }
                break;
            }
        }
        if ($next === '' || enc_manifest_is_doc_token($next) || enc_manifest_is_number_token($next) || enc_manifest_is_payment_token($next)) {
            continue;
        }
        $rowStarts[] = $i;
    }

    $items = [];
    foreach ($rowStarts as $pos => $rowStart) {
        $rowEnd = $rowStarts[$pos + 1] ?? $end;
        $segment = array_values(array_filter(array_map('enc_pdf_clean_token', array_slice($tokens, $rowStart, $rowEnd - $rowStart)), static fn($value) => $value !== ''));
        if (!$segment) {
            continue;
        }

        $documento = $segment[0];
        $documentDocLimit = 0;
        if (isset($segment[1]) && enc_manifest_is_doc_token($segment[1])) {
            $documento = trim($documento . ' ' . $segment[1]);
            $documentDocLimit = 1;
        }
        $numberIndexes = [];
        $paymentIndex = null;
        $guideRemision = null;
        foreach ($segment as $idx => $value) {
            if ($idx > $documentDocLimit && enc_manifest_is_doc_token($value)) {
                $codes = enc_manifest_doc_codes($value);
                $guideRemision = end($codes) ?: $value;
            }
            if (enc_manifest_is_number_token($value)) {
                $numberIndexes[] = $idx;
            }
            if ($paymentIndex === null && enc_manifest_is_payment_token($value)) {
                $paymentIndex = $idx;
            }
        }

        $peso = null;
        $importe = null;
        if ($numberIndexes) {
            $importe = enc_manifest_decimal($segment[$numberIndexes[0]] ?? null);
            $peso = enc_manifest_decimal($segment[end($numberIndexes)] ?? null);
        }

        $firstNumberIndex = $numberIndexes[0] ?? count($segment);
        $textBeforeNumbers = [];
        for ($idx = 1; $idx < count($segment); $idx++) {
            $value = $segment[$idx];
            if ($idx >= $firstNumberIndex) {
                break;
            }
            if (enc_manifest_is_number_token($value) || enc_manifest_is_payment_token($value) || enc_manifest_is_doc_token($value)) {
                continue;
            }
            $textBeforeNumbers[] = $value;
        }

        $consignado = $textBeforeNumbers ? (string)array_pop($textBeforeNumbers) : '';
        $referencia = $textBeforeNumbers;

        $tipoPago = $paymentIndex !== null ? $segment[$paymentIndex] : '';
        $items[] = [
            'orden' => count($items) + 1,
            'documento' => $documento,
            'consignado' => $consignado,
            'referencia_envio' => implode(' ', $referencia),
            'peso' => $peso,
            'tipo_pago' => $tipoPago,
            'importe_cobrado' => $importe,
            'guia_remision' => $guideRemision,
            'estado' => 'PENDIENTE',
            'observacion' => '',
        ];
    }

    return $items;
}

function enc_manifest_code_from_tokens(array $tokens): ?string {
    foreach ($tokens as $token) {
        if (preg_match('/^\s*(\d{4})\s*-\s*(\d{4,})\s*$/', (string)$token, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
    }
    return null;
}

function enc_manifest_text_after_label(array $tokens, string $labelKey): ?string {
    foreach ($tokens as $token) {
        $token = enc_pdf_clean_token((string)$token);
        if ($token === '') {
            continue;
        }
        $key = enc_manifest_label_key($token);
        if ($key === $labelKey || strncmp($key, $labelKey . ' ', strlen($labelKey) + 1) === 0) {
            $value = preg_replace('/^.*?:\s*/', '', $token, 1) ?? '';
            $value = enc_pdf_clean_token($value);
            return $value !== '' && $value !== $token ? $value : null;
        }
    }
    return null;
}

function enc_manifest_split_pages(array $tokens): array {
    $starts = [];
    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        if (!preg_match('/^\s*\d{4}\s*-\s*\d{4,}\s*$/', (string)$tokens[$i])) {
            continue;
        }
        $hasTitleAhead = false;
        for ($j = $i; $j < min($total, $i + 45); $j++) {
            if (strpos(enc_ascii_fold((string)$tokens[$j]), 'manifiesto de encomiendas') !== false) {
                $hasTitleAhead = true;
                break;
            }
        }
        if ($hasTitleAhead) {
            $starts[] = $i;
        }
    }

    if (!$starts) {
        for ($i = 0; $i < $total; $i++) {
            if (strpos(enc_ascii_fold((string)$tokens[$i]), 'manifiesto de encomiendas') !== false) {
                $starts[] = max(0, $i - 25);
            }
        }
    }

    $starts = array_values(array_unique($starts));
    sort($starts);
    if (!$starts && $tokens) {
        $starts = [0];
    }

    $pages = [];
    foreach ($starts as $idx => $start) {
        $end = $starts[$idx + 1] ?? $total;
        $slice = array_slice($tokens, $start, max(0, $end - $start));
        $sliceText = enc_ascii_fold(implode(' ', $slice));
        if (strpos($sliceText, 'manifiesto de encomiendas') === false) {
            continue;
        }
        $pages[] = $slice;
    }

    return $pages;
}

function enc_manifest_parse_page(array $tokens, int $sheetOrder): array {
    $text = enc_pdf_clean_token(implode("\n", $tokens));
    $code = enc_manifest_code_from_tokens($tokens);
    if ($code === null && preg_match('/\b(\d{4})\s*-\s*(\d{4,})\b/', $text, $matches)) {
        $code = $matches[1] . '-' . $matches[2];
    }

    $items = enc_manifest_parse_items($tokens);
    foreach ($items as $idx => $item) {
        $items[$idx]['orden_hoja'] = $sheetOrder;
    }

    return [
        'orden_hoja' => $sheetOrder,
        'title_ok' => strpos(enc_ascii_fold($text), 'manifiesto de encomiendas') !== false,
        'tokens' => $tokens,
        'text' => $text,
        'meta' => [
            'codigo_manifiesto' => $code,
            'origen' => enc_manifest_next_value($tokens, ['Origen']),
            'destino' => enc_manifest_next_value($tokens, ['Destino']),
            'oficina_destino' => enc_manifest_text_after_label($tokens, 'ciudad oficina de destino'),
            'bus' => enc_manifest_next_value($tokens, ['Bus Nro', 'Bus']),
            'placa' => enc_manifest_next_value($tokens, ['Placa']),
            'fecha_viaje' => enc_manifest_next_value($tokens, ['Fecha de viaje', 'Fecha Viaje']),
        ],
        'items' => $items,
    ];
}

function enc_parse_manifest_pdf_pages(string $content): array {
    $tokens = enc_pdf_extract_text_tokens($content);
    $slices = enc_manifest_split_pages($tokens);
    $pages = [];
    foreach ($slices as $slice) {
        $page = enc_manifest_parse_page($slice, count($pages) + 1);
        if ($page['title_ok']) {
            $pages[] = $page;
        }
    }
    if (!$pages && enc_pdf_contains_manifest_title($content)) {
        $pages[] = enc_manifest_parse_page($tokens, 1);
    }
    return $pages;
}

function enc_parse_manifest_pdf(string $content): array {
    $tokens = enc_pdf_extract_text_tokens($content);
    $text = enc_pdf_extract_text($content);
    $pages = enc_parse_manifest_pdf_pages($content);
    $items = [];
    foreach ($pages as $page) {
        foreach ($page['items'] as $item) {
            $item['orden_hoja'] = (int)$page['orden_hoja'];
            $item['hoja_label'] = 'Hoja ' . str_pad((string)(int)$page['orden_hoja'], 2, '0', STR_PAD_LEFT);
            $items[] = $item;
        }
    }
    $firstMeta = $pages[0]['meta'] ?? [];

    return [
        'title_ok' => enc_pdf_contains_manifest_title($content),
        'tokens' => $tokens,
        'text' => $text,
        'pages' => $pages,
        'meta' => [
            'codigo_manifiesto' => $firstMeta['codigo_manifiesto'] ?? enc_manifest_code_from_tokens($tokens),
            'origen' => $firstMeta['origen'] ?? enc_manifest_next_value($tokens, ['Origen']),
            'destino' => $firstMeta['destino'] ?? enc_manifest_next_value($tokens, ['Destino']),
            'oficina_destino' => $firstMeta['oficina_destino'] ?? enc_manifest_text_after_label($tokens, 'ciudad oficina de destino'),
            'bus' => $firstMeta['bus'] ?? enc_manifest_next_value($tokens, ['Bus Nro', 'Bus']),
            'placa' => $firstMeta['placa'] ?? enc_manifest_next_value($tokens, ['Placa']),
            'fecha_viaje' => $firstMeta['fecha_viaje'] ?? enc_manifest_next_value($tokens, ['Fecha de viaje', 'Fecha Viaje']),
        ],
        'items' => $items,
    ];
}

function enc_log(Throwable $e): void {
    error_log('[N360 Encomiendas] ' . $e->getMessage());
}

function enc_db_message(Throwable $e): string {
    $message = $e->getMessage();
    $plainMessage = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message) : false;
    if ($plainMessage === false) {
        $plainMessage = $message;
    }
    if (stripos($message, 'uq_enc_guia') !== false || stripos($message, 'uq_enc_serie_correlativo') !== false || stripos($message, 'Duplicate') !== false) {
        return 'Ya existe una Control Encomienda con ese correlativo.';
    }
    if (stripos($plainMessage, 'unknown column') !== false || stripos($plainMessage, 'doesn\'t exist') !== false || stripos($plainMessage, 'Base table or view not found') !== false) {
        return 'Falta ejecutar la migracion SQL de Control Encomiendas antes de usar esta vista.';
    }
    if (stripos($plainMessage, 'foreign key') !== false) {
        return 'Uno de los datos seleccionados ya no existe o no esta disponible.';
    }

    $knownBusinessMessages = [
        'La oficina de embarque no puede ser igual a la oficina de desembarque',
        'El numero de guia es obligatorio',
        'Debe registrar correctamente el tipo y numero del comprobante',
        'Para confirmar el embarque debes seleccionar una unidad',
        'Debes indicar el usuario que realiza el embarque',
        'Debes indicar el usuario que observa el embarque',
        'Primero debes registrar la Control Encomienda y posteriormente procesar el desembarque',
        'No puedes procesar el desembarque si la Control Encomienda no fue embarcada',
        'Para finalizar el desembarque debes adjuntar los manifiestos PDF de todos los puntos obligatorios',
        'El manifiesto debe estar asociado a un punto de la Control Encomienda',
        'Tipo de documento de encomienda no valido',
        'El documento de encomienda debe tener extension PDF',
        'El archivo adjuntado no contiene una firma PDF valida',
        'El archivo PDF no puede estar vacio',
        'Debes indicar el usuario que realiza la modificacion',
        'Pendiente ejecutar la migracion de revisiones de manifiestos',
        'Pendiente ejecutar la migracion de revisiones de manifiestos por hoja',
        'El PDF guardado no contiene "Manifiesto de Encomiendas"',
        'Manifiesto no identificado',
        'No se encontro el manifiesto solicitado',
        'No hay items del manifiesto para guardar',
    ];
    foreach ($knownBusinessMessages as $businessMessage) {
        if (stripos($message, $businessMessage) !== false || stripos($plainMessage, $businessMessage) !== false) {
            return rtrim($businessMessage, '.') . '.';
        }
    }

    return 'No se pudo completar la operacion. Revisa los datos e intenta nuevamente.';
}
