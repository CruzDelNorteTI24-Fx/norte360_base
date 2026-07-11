<?php
if (!defined('N360_SECURITY_LOADED')) {
    define('N360_SECURITY_LOADED', true);
}

function n360_is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $forwardedSsl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));

    return $https === 'on'
        || $https === '1'
        || $forwardedProto === 'https'
        || $forwardedSsl === 'on';
}

function n360_send_security_headers(): void {
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    if (n360_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
        "img-src 'self' data:",
        "font-src 'self' data: https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

function n360_start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    if (n360_is_https()) {
        ini_set('session.cookie_secure', '1');
    }

    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => n360_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function n360_csrf_token(string $scope = 'default'): string {
    n360_start_secure_session();
    $key = 'n360_csrf_' . $scope;

    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION[$key];
}

function n360_verify_csrf(?string $token, string $scope = 'default'): bool {
    n360_start_secure_session();
    $key = 'n360_csrf_' . $scope;

    return is_string($token)
        && isset($_SESSION[$key])
        && is_string($_SESSION[$key])
        && hash_equals($_SESSION[$key], $token);
}

function n360_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function n360_login_rate_file(string $usuario): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'norte360_login_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $key = hash('sha256', strtolower(trim($usuario)) . '|' . n360_client_ip());
    return $dir . DIRECTORY_SEPARATOR . $key . '.json';
}

function n360_login_rate_status(string $usuario, int $limit = 6, int $windowSeconds = 900, int $lockSeconds = 900): array {
    $now = time();
    $file = n360_login_rate_file($usuario);
    $state = ['attempts' => [], 'locked_until' => 0];

    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }

    $attempts = array_values(array_filter(
        array_map('intval', (array)($state['attempts'] ?? [])),
        static fn($stamp) => $stamp >= ($now - $windowSeconds)
    ));

    $lockedUntil = (int)($state['locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        return [
            'blocked' => true,
            'remaining_seconds' => $lockedUntil - $now,
            'attempts' => count($attempts),
        ];
    }

    if ($lockedUntil > 0 && $lockedUntil <= $now) {
        $lockedUntil = 0;
    }

    if (count($attempts) >= $limit) {
        $lockedUntil = $now + $lockSeconds;
        @file_put_contents($file, json_encode([
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ]), LOCK_EX);

        return [
            'blocked' => true,
            'remaining_seconds' => $lockSeconds,
            'attempts' => count($attempts),
        ];
    }

    @file_put_contents($file, json_encode([
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
    ]), LOCK_EX);

    return [
        'blocked' => false,
        'remaining_seconds' => 0,
        'attempts' => count($attempts),
    ];
}

function n360_login_rate_register_failure(string $usuario): void {
    if (trim($usuario) === '') {
        return;
    }

    $status = n360_login_rate_status($usuario);
    if (!empty($status['blocked'])) {
        return;
    }

    $file = n360_login_rate_file($usuario);
    $state = ['attempts' => [], 'locked_until' => 0];

    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }

    $state['attempts'][] = time();
    @file_put_contents($file, json_encode($state), LOCK_EX);
    n360_login_rate_status($usuario);
}

function n360_login_rate_clear(string $usuario): void {
    if (trim($usuario) === '') {
        return;
    }

    $file = n360_login_rate_file($usuario);
    if (is_file($file)) {
        @unlink($file);
    }
}
