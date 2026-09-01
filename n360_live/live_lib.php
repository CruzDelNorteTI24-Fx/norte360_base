<?php

if (!function_exists('n360_live_now')) {
    function n360_live_now(): DateTimeImmutable {
        return new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    }
}

if (!function_exists('n360_live_uid')) {
    function n360_live_uid(): int {
        if (isset($_SESSION['id_usuario']) && is_numeric($_SESSION['id_usuario'])) {
            return (int)$_SESSION['id_usuario'];
        }

        if (isset($_SESSION['web_id_usuario']) && is_numeric($_SESSION['web_id_usuario'])) {
            return (int)$_SESSION['web_id_usuario'];
        }

        return 0;
    }
}

if (!function_exists('n360_live_client_ip')) {
    function n360_live_client_ip(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $value) {
            $ip = trim(explode(',', (string)$value)[0] ?? '');
            if ($ip !== '' && preg_match('/^[0-9a-fA-F:.]+$/', $ip)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }
}

if (!function_exists('n360_live_device_label')) {
    function n360_live_device_label(): string {
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

        if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
            return 'Celular';
        }

        if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) {
            return 'Tablet';
        }

        return 'PC';
    }
}

if (!function_exists('n360_live_can_access')) {
    function n360_live_can_access(?mysqli $conn = null, bool $useSessionCache = true): bool {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['usuario'])) {
            return false;
        }

        if ($useSessionCache && isset($_SESSION['n360_live_perm_checked'])) {
            return !empty($_SESSION['n360_live_perm']);
        }

        if (!$conn instanceof mysqli) {
            return false;
        }

        $uid = n360_live_uid();
        if ($uid <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(prmso3nvivo, 0) AS permitido
            FROM tb_usuarios
            WHERE id_usuario = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $_SESSION['n360_live_perm'] = 0;
            $_SESSION['n360_live_perm_checked'] = true;
            return false;
        }

        $stmt->bind_param('i', $uid);
        if (!$stmt->execute()) {
            $stmt->close();
            $_SESSION['n360_live_perm'] = 0;
            $_SESSION['n360_live_perm_checked'] = true;
            return false;
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $allowed = (int)($row['permitido'] ?? 0) === 1;
        $_SESSION['n360_live_perm'] = $allowed ? 1 : 0;
        $_SESSION['n360_live_perm_checked'] = true;

        return $allowed;
    }
}

if (!function_exists('n360_live_cache_dir')) {
    function n360_live_cache_dir(): string {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }
}

if (!function_exists('n360_live_read_json')) {
    function n360_live_read_json(string $file): array {
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('n360_live_write_json')) {
    function n360_live_write_json(string $file, array $data): void {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        @file_put_contents($file, json_encode($data, $flags), LOCK_EX);
    }
}

if (!function_exists('n360_live_snapshot_file')) {
    function n360_live_snapshot_file(): string {
        return n360_live_cache_dir() . DIRECTORY_SEPARATOR . 'snapshot.json';
    }
}

if (!function_exists('n360_live_presence_file')) {
    function n360_live_presence_file(): string {
        return n360_live_cache_dir() . DIRECTORY_SEPARATOR . 'presence.json';
    }
}

if (!function_exists('n360_live_history_file')) {
    function n360_live_history_file(): string {
        return n360_live_cache_dir() . DIRECTORY_SEPARATOR . 'access_history.jsonl';
    }
}

if (!function_exists('n360_live_request_user_agent')) {
    function n360_live_request_user_agent(): string {
        $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $agent = preg_replace('/[[:cntrl:]]/', ' ', $agent) ?? '';

        if (strlen($agent) > 350) {
            $agent = substr($agent, 0, 350);
        }

        return $agent;
    }
}

if (!function_exists('n360_live_log_access')) {
    function n360_live_log_access(string $event, array $extra = []): void {
        $event = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $event) ?: 'EVENTO');
        $now = n360_live_now();
        $username = trim((string)($_SESSION['usuario'] ?? 'SIN_SESION'));
        $name = trim((string)($_SESSION['nombre'] ?? $username));
        $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';

        $row = [
            'event' => $event,
            'fecha' => $now->format(DateTimeInterface::ATOM),
            'fecha_label' => $now->format('d/m/Y H:i:s'),
            'usuario_id' => n360_live_uid(),
            'usuario' => $username !== '' ? $username : 'SIN_SESION',
            'nombre' => $name !== '' ? $name : 'SIN_SESION',
            'rol' => trim((string)($_SESSION['web_rol'] ?? '')),
            'ip' => n360_live_client_ip(),
            'dispositivo' => n360_live_device_label(),
            'session_hash' => $sessionId !== '' ? hash('sha256', $sessionId) : '',
            'user_agent' => n360_live_request_user_agent(),
            'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        ];

        foreach ($extra as $key => $value) {
            $safeKey = preg_replace('/[^A-Za-z0-9_]/', '', (string)$key);
            if ($safeKey !== '') {
                $row[$safeKey] = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        @file_put_contents(n360_live_history_file(), json_encode($row, $flags) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('n360_live_log_enter_once')) {
    function n360_live_log_enter_once(string $source = 'live'): void {
        if (!empty($_SESSION['n360_live_history_enter_logged'])) {
            return;
        }

        $_SESSION['n360_live_history_enter_logged'] = true;
        n360_live_log_access('ENTER', ['source' => $source]);
    }
}

if (!function_exists('n360_live_log_denied_once')) {
    function n360_live_log_denied_once(string $reason, string $source = 'live'): void {
        $key = 'n360_live_history_denied_' . md5($source . '|' . $reason);
        if (!empty($_SESSION[$key])) {
            return;
        }

        $_SESSION[$key] = true;
        n360_live_log_access('DENIED', [
            'source' => $source,
            'reason' => $reason,
        ]);
    }
}

if (!function_exists('n360_live_fetch_snapshot')) {
    function n360_live_fetch_snapshot(mysqli $conn, bool $force = false): array {
        $ttl = 180;
        $cacheVersion = 3;
        $file = n360_live_snapshot_file();
        $now = time();
        $cached = n360_live_read_json($file);
        $cachedAt = (int)($cached['cached_at'] ?? 0);
        $cachedVersion = (int)($cached['cache_version'] ?? 0);

        if (!$force && $cached && $cachedVersion === $cacheVersion && $cachedAt > 0 && ($now - $cachedAt) < $ttl) {
            $cached['cache_hit'] = true;
            $cached['cache_age'] = $now - $cachedAt;
            $cached['cache_ttl'] = $ttl;
            return $cached;
        }

        $sql = "
            SELECT
                COALESCE(bus, 'SIN ASIGNAR') AS bus,
                COALESCE(origen, '-') AS origen,
                COALESCE(destino, '-') AS destino,
                COALESCE(ruta, '') AS ruta,
                TIME_FORMAT(hora_salida, '%H:%i') AS hora_salida,
                CAST(orden_operativo AS UNSIGNED) AS orden_operativo,
                DATE_FORMAT(ultima_actualizacion, '%d/%m/%Y %H:%i') AS ultima_actualizacion
            FROM vw_progbuses_n360live
            WHERE bus IS NOT NULL AND bus != 'SIN ASIGNAR'
            ORDER BY orden_operativo ASC, bus ASC
        ";

        $res = $conn->query($sql);
        if (!$res) {
            throw new RuntimeException($conn->error ?: 'No se pudo leer vw_progbuses_n360live.');
        }

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'bus' => trim((string)($row['bus'] ?? 'SIN ASIGNAR')),
                'origen' => trim((string)($row['origen'] ?? '-')),
                'destino' => trim((string)($row['destino'] ?? '-')),
                'ruta' => trim((string)($row['ruta'] ?? '')),
                'hora_salida' => trim((string)($row['hora_salida'] ?? '')),
                'orden_operativo' => (int)($row['orden_operativo'] ?? 0),
                'estado_codigo' => 1,
                'estado' => 'ACTIVO',
                'ultima_actualizacion' => trim((string)($row['ultima_actualizacion'] ?? '')),
            ];
        }

        $generatedAt = n360_live_now();
        $payload = [
            'cache_version' => $cacheVersion,
            'cached_at' => $now,
            'cache_hit' => false,
            'cache_age' => 0,
            'cache_ttl' => $ttl,
            'generated_at' => $generatedAt->format(DateTimeInterface::ATOM),
            'generated_label' => $generatedAt->format('d/m/Y H:i:s'),
            'rows' => $rows,
            'total' => count($rows),
        ];

        n360_live_write_json($file, $payload);
        return $payload;
    }
}

if (!function_exists('n360_live_presence_key')) {
    function n360_live_presence_key(): string {
        return hash('sha256', session_id() . '|' . n360_live_client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}

if (!function_exists('n360_live_clean_viewers')) {
    function n360_live_clean_viewers(array $state, int $ttl = 600): array {
        $now = time();
        $viewers = [];

        foreach ((array)($state['viewers'] ?? []) as $key => $viewer) {
            $lastSeen = (int)($viewer['last_seen'] ?? 0);
            if ($lastSeen > 0 && ($now - $lastSeen) <= $ttl) {
                $viewer['age_seconds'] = $now - $lastSeen;
                $viewers[$key] = $viewer;
            }
        }

        uasort($viewers, static function ($a, $b) {
            return (int)($b['last_seen'] ?? 0) <=> (int)($a['last_seen'] ?? 0);
        });

        return ['viewers' => $viewers, 'updated_at' => $now];
    }
}

if (!function_exists('n360_live_touch_presence')) {
    function n360_live_touch_presence(): array {
        $file = n360_live_presence_file();
        $state = n360_live_clean_viewers(n360_live_read_json($file));
        $now = time();
        $labelTime = n360_live_now()->format('d/m/Y H:i');
        $key = n360_live_presence_key();
        $username = trim((string)($_SESSION['usuario'] ?? 'Usuario'));
        $name = trim((string)($_SESSION['nombre'] ?? $username));

        $state['viewers'][$key] = [
            'key' => $key,
            'usuario' => $username,
            'nombre' => $name,
            'rol' => trim((string)($_SESSION['web_rol'] ?? 'Usuario')),
            'ip' => n360_live_client_ip(),
            'dispositivo' => n360_live_device_label(),
            'last_seen' => $now,
            'last_seen_label' => $labelTime,
            'age_seconds' => 0,
        ];

        n360_live_write_json($file, $state);
        return n360_live_viewers();
    }
}

if (!function_exists('n360_live_leave_presence')) {
    function n360_live_leave_presence(): array {
        $file = n360_live_presence_file();
        $state = n360_live_clean_viewers(n360_live_read_json($file));
        $key = n360_live_presence_key();

        unset($state['viewers'][$key]);
        n360_live_write_json($file, $state);

        return n360_live_viewers();
    }
}

if (!function_exists('n360_live_viewers')) {
    function n360_live_viewers(): array {
        $file = n360_live_presence_file();
        $state = n360_live_clean_viewers(n360_live_read_json($file));
        n360_live_write_json($file, $state);

        return array_values((array)($state['viewers'] ?? []));
    }
}
