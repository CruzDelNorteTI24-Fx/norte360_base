<?php
require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => n360_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_unset();
session_destroy();

header('Location: login.php');
exit();
?>
