<?php
if (!function_exists('alm_json')) {
    function alm_json(array $payload, int $status = 200): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('alm_bind')) {
    function alm_bind(mysqli_stmt $stmt, string $types, array &$params): void {
        if ($types === '') {
            return;
        }

        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('alm_fetch_all')) {
    function alm_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($conn->error ?: 'No se pudo preparar la consulta.');
        }

        alm_bind($stmt, $types, $params);

        if (!$stmt->execute()) {
            $error = $stmt->error ?: 'No se pudo ejecutar la consulta.';
            $stmt->close();
            throw new RuntimeException($error);
        }

        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('alm_fetch_one')) {
    function alm_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
        $rows = alm_fetch_all($conn, $sql, $types, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('alm_session_module_ids')) {
    function alm_session_module_ids(): array {
        $permisos = $_SESSION['permisos'] ?? [];
        if ($permisos === 'all') {
            return ['all'];
        }

        return is_array($permisos) ? array_map('intval', $permisos) : [];
    }
}

if (!function_exists('alm_session_has_vista')) {
    function alm_session_has_vista(array $codes): bool {
        if (($_SESSION['permisos'] ?? []) === 'all') {
            return true;
        }

        $vistas = $_SESSION['vistas'] ?? [];
        if (!is_array($vistas)) {
            return false;
        }

        foreach ($codes as $code) {
            if (in_array($code, $vistas, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('alm_can_almacen')) {
    function alm_can_almacen(): bool {
        if (($_SESSION['web_rol'] ?? '') === 'Admin') {
            return true;
        }

        $moduleIds = alm_session_module_ids();
        if ($moduleIds === ['all']) {
            return true;
        }

        return in_array(3, $moduleIds, true)
            || (in_array(6, $moduleIds, true) && alm_session_has_vista(['rrhh-registeralm']));
    }
}

if (!function_exists('alm_can_registrar')) {
    function alm_can_registrar(): bool {
        if (($_SESSION['web_rol'] ?? '') === 'Admin') {
            return true;
        }

        $moduleIds = alm_session_module_ids();
        if ($moduleIds === ['all']) {
            return true;
        }

        $fromAlmacen = in_array(3, $moduleIds, true) && alm_session_has_vista(['a-register', 'a-formulreg']);
        $fromRrhh = in_array(6, $moduleIds, true) && alm_session_has_vista(['rrhh-registeralm']);

        return $fromAlmacen || $fromRrhh;
    }
}

if (!function_exists('alm_allowed_origin_ids')) {
    function alm_allowed_origin_ids(): array {
        if (($_SESSION['web_rol'] ?? '') === 'Admin') {
            return [1, 4];
        }

        $moduleIds = alm_session_module_ids();
        if ($moduleIds === ['all']) {
            return [1, 4];
        }

        $allowed = [];
        if (in_array(3, $moduleIds, true) && alm_session_has_vista(['a-register', 'a-formulreg'])) {
            $allowed[] = 1;
        }
        if (in_array(6, $moduleIds, true) && alm_session_has_vista(['rrhh-registeralm'])) {
            $allowed[] = 4;
        }

        return $allowed;
    }
}

if (!function_exists('alm_origin_id_from_payload')) {
    function alm_origin_id_from_payload(array $payload): int {
        $allowed = alm_allowed_origin_ids();
        $requested = (int)($payload['orgn_id'] ?? 0);

        if ($requested > 0 && in_array($requested, $allowed, true)) {
            return $requested;
        }

        if ($requested <= 0 && $allowed) {
            return (int)$allowed[0];
        }

        alm_json(['ok' => false, 'message' => 'No tienes permiso para registrar movimientos desde este origen.'], 403);
    }
}

if (!function_exists('alm_can_edit_prices')) {
    function alm_can_edit_prices(): bool {
        if (($_SESSION['web_rol'] ?? '') === 'Admin') {
            return true;
        }

        $permisos = $_SESSION['permisos'] ?? [];
        if ($permisos === 'all') {
            return true;
        }

        if (!is_array($permisos)) {
            return false;
        }

        $moduleIds = array_map('intval', $permisos);
        return in_array(6, $moduleIds, true) || in_array(12, $moduleIds, true);
    }
}

if (!function_exists('alm_user_id')) {
    function alm_user_id(mysqli $conn): int {
        foreach (['id_usuario', 'web_id_usuario', 'usuario_id'] as $key) {
            if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
                return (int)$_SESSION[$key];
            }
        }

        $usuario = trim((string)($_SESSION['usuario'] ?? ''));
        if ($usuario !== '') {
            $row = alm_fetch_one($conn, 'SELECT id_usuario FROM tb_usuarios WHERE usuario = ? LIMIT 1', 's', [$usuario]);
            if ($row && is_numeric($row['id_usuario'] ?? null)) {
                return (int)$row['id_usuario'];
            }
        }

        return 0;
    }
}

if (!function_exists('alm_validate_current_password')) {
    function alm_validate_current_password(mysqli $conn, string $password): void {
        $password = trim($password);
        if ($password === '') {
            alm_json(['ok' => false, 'message' => 'Ingresa tu contrasena para confirmar la salida.'], 422);
        }

        $userId = alm_user_id($conn);
        if ($userId <= 0) {
            alm_json(['ok' => false, 'message' => 'No se pudo identificar al usuario de sesion.'], 422);
        }

        $row = alm_fetch_one($conn, 'SELECT contrasena FROM tb_usuarios WHERE id_usuario = ? LIMIT 1', 'i', [$userId]);
        $stored = (string)($row['contrasena'] ?? '');
        $received = hash('sha256', $password);

        if ($stored === '' || !hash_equals($stored, $received)) {
            alm_json(['ok' => false, 'message' => 'Contrasena incorrecta. La salida no fue registrada.'], 403);
        }
    }
}

if (!function_exists('alm_float')) {
    function alm_float($value): float {
        $value = str_replace(',', '.', trim((string)$value));
        return is_numeric($value) ? (float)$value : 0.0;
    }
}

if (!function_exists('alm_clean_text')) {
    function alm_clean_text($value, int $max = 800): string {
        $value = trim((string)$value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}

if (!function_exists('alm_validate_csrf')) {
    function alm_validate_csrf(array $payload): void {
        $expected = (string)($_SESSION['alm_mov_csrf'] ?? '');
        $received = (string)($payload['csrf'] ?? ($_SERVER['HTTP_X_N360_CSRF'] ?? ''));

        if ($expected === '' || !hash_equals($expected, $received)) {
            alm_json(['ok' => false, 'message' => 'Token de seguridad invalido. Actualiza la pagina e intenta de nuevo.'], 419);
        }
    }
}

if (!function_exists('alm_producto_tiene_movimientos')) {
    function alm_producto_tiene_movimientos(mysqli $conn, int $productId): bool {
        $row = alm_fetch_one(
            $conn,
            'SELECT 1 AS existe FROM tb_alm_movimientos WHERE clm_alm_mov_idPRODUCTO = ? LIMIT 1',
            'i',
            [$productId]
        );
        return (bool)$row;
    }
}