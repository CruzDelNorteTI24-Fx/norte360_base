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

if (!function_exists('alm_can_almacen')) {
    function alm_can_almacen(): bool {
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

        return in_array(3, array_map('intval', $permisos), true);
    }
}

if (!function_exists('alm_can_registrar')) {
    function alm_can_registrar(): bool {
        if (($_SESSION['web_rol'] ?? '') === 'Admin') {
            return true;
        }

        if (!alm_can_almacen()) {
            return false;
        }

        if (($_SESSION['permisos'] ?? []) === 'all') {
            return true;
        }

        $vistas = $_SESSION['vistas'] ?? [];
        return is_array($vistas) && in_array('a-formulreg', $vistas, true);
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