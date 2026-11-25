<?php

const N360_AUDIT_TOUCH = 120;
const N360_AUDIT_ACTIVE = 600;
const N360_AUDIT_ABSENT = 1800;
const N360_AUDIT_RETENTION = 90 * 86400;

function n360_audit_path_within(string $path, string $root): bool
{
    $normalize = static function (string $value): string {
        $value = str_replace('\\', '/', $value);
        return rtrim(PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value, '/');
    };
    $path = $normalize($path);
    $root = $normalize($root);
    return $path === $root || str_starts_with($path, $root . '/');
}

function n360_audit_directory(): string
{
    static $resolved;
    if (isset($resolved)) return $resolved;
    $documentRoot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $repository = @realpath(dirname(__DIR__));
    $configured = getenv('N360_AUDIT_DIR');
    if (!isset($_SERVER['DOCUMENT_ROOT']) || $_SERVER['DOCUMENT_ROOT'] === '' || !$documentRoot || !$repository) {
        throw new RuntimeException('No se pudo verificar el directorio público.');
    }
    $path = $configured !== false && $configured !== ''
        ? $configured : dirname($documentRoot) . DIRECTORY_SEPARATOR . 'norte360-audit';
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/(?!/))~D', $path)) {
        throw new RuntimeException('La ruta de auditoría debe ser absoluta y local.');
    }
    $directory = @realpath($path);
    if (!$directory || !is_dir($directory) || !is_readable($directory) || !is_writable($directory)) {
        throw new RuntimeException('Crea el directorio privado de auditoría y habilita escritura para PHP.');
    }
    if (n360_audit_path_within($directory, $documentRoot) || n360_audit_path_within($directory, $repository)) {
        throw new RuntimeException('El directorio de auditoría debe estar fuera del sitio público y del repositorio.');
    }
    for ($parent = $directory; $parent !== dirname($parent); $parent = dirname($parent)) {
        if (@file_exists($parent . DIRECTORY_SEPARATOR . '.git')) {
            throw new RuntimeException('El directorio de auditoría debe estar fuera de Git.');
        }
    }
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('El directorio de auditoría requiere permisos privados (0700).');
    }
    return $resolved = $directory;
}

function n360_audit_transaction(PDO $db, callable $work)
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $work();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function n360_audit_db(): PDO
{
    static $db;
    if ($db instanceof PDO) return $db;
    if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Habilita PDO SQLite para la auditoría.');
    }

    $directory = n360_audit_directory();
    $path = $directory . DIRECTORY_SEPARATOR . 'sessions.sqlite';
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
        if (is_link($file) || (file_exists($file) && (!is_file($file)
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0077) !== 0)))) {
            throw new RuntimeException('Los archivos SQLite deben ser privados y regulares.');
        }
    }

    // SQLite crea el archivo principal y los auxiliares con este umask.
    $previousMask = umask(0077);
    try {
        $connection = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $connection->exec('PRAGMA busy_timeout = 35');
        $header = $connection->query('SELECT user_version, application_id FROM pragma_user_version(), pragma_application_id()')->fetch();
        $version = (int)$header['user_version'];
        $appId = (int)$header['application_id'];
        if (($version === 0 && $appId !== 0) || ($version !== 0 && ($version !== 1 || $appId !== 0x4e333641))) {
            throw new RuntimeException('La versión o el tipo del archivo de auditoría es incompatible.');
        }
        if ($version === 0) {
            if ($connection->query('PRAGMA journal_mode = WAL')->fetchColumn() !== 'wal') {
                throw new RuntimeException('El almacenamiento privado no admite SQLite WAL.');
            }
            n360_audit_transaction($connection, static function () use ($connection) {
                if ((int)$connection->query('PRAGMA user_version')->fetchColumn() === 1) return;
                $connection->exec(<<<'SQL'
                    CREATE TABLE audit_settings (
                        name TEXT PRIMARY KEY, value TEXT NOT NULL
                    );
                    CREATE TABLE audit_sessions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        session_key TEXT NOT NULL UNIQUE,
                        user_id INTEGER NOT NULL, username TEXT NOT NULL COLLATE NOCASE,
                        started_at INTEGER NOT NULL, last_seen INTEGER NOT NULL, closed_at INTEGER,
                        ip TEXT NOT NULL, device TEXT NOT NULL, browser TEXT NOT NULL, os TEXT NOT NULL,
                        last_page TEXT NOT NULL DEFAULT ''
                    );
                    CREATE TABLE audit_events (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        occurred_at INTEGER NOT NULL, kind TEXT NOT NULL, reason TEXT NOT NULL,
                        user_id INTEGER, username TEXT NOT NULL COLLATE NOCASE, session_ref TEXT NOT NULL,
                        module TEXT NOT NULL, page TEXT NOT NULL,
                        ip TEXT NOT NULL, device TEXT NOT NULL, browser TEXT NOT NULL, os TEXT NOT NULL,
                        dedup_key TEXT UNIQUE
                    );
                    CREATE INDEX audit_sessions_seen ON audit_sessions(last_seen DESC, id DESC);
                    CREATE INDEX audit_sessions_user ON audit_sessions(username, last_seen DESC, id DESC);
                    CREATE INDEX audit_events_time ON audit_events(occurred_at DESC, id DESC);
                    CREATE INDEX audit_events_kind_time ON audit_events(kind, occurred_at DESC, id DESC);
                    CREATE INDEX audit_events_access_time ON audit_events(occurred_at DESC, id DESC)
                        WHERE kind IN ('success', 'failed', 'blocked');
                    CREATE INDEX audit_events_user_time ON audit_events(username, occurred_at DESC, id DESC);
                    CREATE INDEX audit_events_ref_time ON audit_events(session_ref, occurred_at DESC, id DESC);
                    INSERT INTO audit_settings VALUES ('next_cleanup', '0');
                    SQL);
                $statement = $connection->prepare("INSERT INTO audit_settings VALUES ('session_key', ?)");
                $statement->execute([bin2hex(random_bytes(32))]);
                $connection->exec('PRAGMA application_id = 0x4e333641; PRAGMA user_version = 1');
            });
        } elseif ($connection->query('PRAGMA journal_mode')->fetchColumn() !== 'wal') {
            throw new RuntimeException('Activa WAL para el archivo SQLite de auditoría.');
        }
        return $db = $connection;
    } finally {
        umask($previousMask);
    }
}

function n360_audit_text(string $value, int $maxLength): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value)) ?? '';
    return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
}

function n360_audit_client(): array
{
    $address = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
    $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    $device = match (true) {
        $agent === '' => 'Desconocido',
        (bool)preg_match('/iPad|Tablet|Kindle|Silk|Android(?!.*Mobile)|Macintosh.*Mobile/i', $agent) => 'Tableta',
        (bool)preg_match('/Mobile|iPhone|iPod|Android/i', $agent) => 'Móvil',
        (bool)preg_match('/Windows|Macintosh|X11|Linux|CrOS/i', $agent) => 'Escritorio',
        default => 'Desconocido',
    };
    $browser = match (true) {
        (bool)preg_match('/Edg(?:A|iOS)?\//i', $agent) => 'Edge',
        (bool)preg_match('/OPR\/|Opera/i', $agent) => 'Opera',
        str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
        (bool)preg_match('/Firefox\/|FxiOS\//i', $agent) => 'Firefox',
        (bool)preg_match('/Chrome\/|CriOS\//i', $agent) => 'Chrome',
        str_contains($agent, 'Safari/') => 'Safari',
        default => 'Desconocido',
    };
    $os = match (true) {
        (bool)preg_match('/iPhone|iPad|iPod|Macintosh.*Mobile/i', $agent) => 'iOS/iPadOS',
        str_contains($agent, 'Android') => 'Android',
        str_contains($agent, 'Windows') => 'Windows',
        str_contains($agent, 'Macintosh') => 'macOS',
        str_contains($agent, 'CrOS') => 'ChromeOS',
        str_contains($agent, 'Linux') => 'Linux',
        default => 'Desconocido',
    };
    return [$address !== false ? (string)inet_ntop(inet_pton($address)) : '', $device, $browser, $os];
}

function n360_audit_identity(): ?array
{
    $id = (int)($_SESSION['id_usuario'] ?? 0);
    $username = (string)($_SESSION['usuario'] ?? '');
    if (session_status() !== PHP_SESSION_ACTIVE || $id <= 0 || $username === '' || session_id() === '') return null;
    return [$id, n360_audit_text($username, 120)];
}

function n360_audit_session_key(PDO $db): string
{
    static $secret;
    $secret ??= (string)$db->query("SELECT value FROM audit_settings WHERE name = 'session_key'")->fetchColumn();
    if (!preg_match('/^[a-f0-9]{64}$/D', $secret)) throw new RuntimeException('Falta la clave de auditoría.');
    return hash_hmac('sha256', session_id(), hex2bin($secret));
}

function n360_audit_event(PDO $db, int $now, string $kind, string $reason, array $identity, string $key,
                          string $module, string $page, array $client, ?string $dedup = null): void
{
    $statement = $db->prepare('
        INSERT INTO audit_events (occurred_at, kind, reason, user_id, username, session_ref, module, page, ip, device, browser, os, dedup_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(dedup_key) DO NOTHING
    ');
    $statement->execute([$now, $kind, $reason, ...$identity, substr($key, 0, 16), $module, $page, ...$client, $dedup]);
}

function n360_audit_cleanup(PDO $db, int $now): void
{
    $claim = $db->prepare("UPDATE audit_settings SET value = ? WHERE name = 'next_cleanup' AND CAST(value AS INTEGER) <= CAST(? AS INTEGER)");
    $claim->execute([$now + 3600, $now]);
    if ($claim->rowCount() === 0) return;

    $backlog = false;
    foreach (['audit_events' => 'occurred_at', 'audit_sessions' => 'last_seen'] as $table => $timestamp) {
        $statement = $db->prepare("DELETE FROM {$table} WHERE id IN (
            SELECT id FROM {$table} WHERE {$timestamp} < ? ORDER BY {$timestamp}, id LIMIT 500
        )");
        $statement->execute([$now - N360_AUDIT_RETENTION]);
        $backlog = $backlog || $statement->rowCount() === 500;
    }
    if ($backlog) {
        $next = $db->prepare("UPDATE audit_settings SET value = ? WHERE name = 'next_cleanup'");
        $next->execute([$now + 60]);
    }
}

function n360_audit_new_session(PDO $db, string $key, array $identity, int $now, array $client): void
{
    $statement = $db->prepare('
        INSERT INTO audit_sessions (session_key, user_id, username, started_at, last_seen, ip, device, browser, os)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(session_key) DO NOTHING
    ');
    $statement->execute([$key, ...$identity, $now, $now, ...$client]);
}

function n360_audit_login_success(): void
{
    try {
        $identity = n360_audit_identity();
        if ($identity === null) return;
        $db = n360_audit_db();
        $key = n360_audit_session_key($db);
        $now = time();
        $client = n360_audit_client();
        n360_audit_transaction($db, static function () use ($db, $key, $identity, $now, $client) {
            n360_audit_new_session($db, $key, $identity, $now, $client);
            n360_audit_event($db, $now, 'success', '', $identity, $key, 'Sistema', '', $client);
            n360_audit_cleanup($db, $now);
        });
        $_SESSION['n360_audit_observation'] = ['page' => '', 'seen' => $now];
    } catch (Throwable $ignored) {
        // La auditoría es pasiva y no condiciona el inicio de sesión.
    }
}

function n360_audit_login_failure(string $reason, ?int $userId = null, string $username = ''): void
{
    try {
        $db = n360_audit_db();
        $now = time();
        $client = n360_audit_client();
        $username = n360_audit_text($username, 120);
        $kind = $reason === 'bloqueado' ? 'blocked' : 'failed';
        $dedup = in_array($reason, ['bloqueado', 'csrf'], true)
            ? hash('sha256', serialize([$reason, $username, $client[0], intdiv($now, 60)])) : null;
        n360_audit_transaction($db, static function () use ($db, $now, $kind, $reason, $userId, $username, $client, $dedup) {
            n360_audit_event($db, $now, $kind, $reason, [$userId, $username], '', 'Sistema', '', $client, $dedup);
            n360_audit_cleanup($db, $now);
        });
    } catch (Throwable $ignored) {
        // Una falla de almacenamiento conserva el rechazo existente.
    }
}

function n360_audit_logout(): void
{
    try {
        $identity = n360_audit_identity();
        if ($identity === null) return;
        $db = n360_audit_db();
        $key = n360_audit_session_key($db);
        $now = time();
        n360_audit_transaction($db, static function () use ($db, $identity, $key, $now) {
            n360_audit_new_session($db, $key, $identity, $now, n360_audit_client());
            $statement = $db->prepare('UPDATE audit_sessions SET closed_at = ?, last_seen = MAX(last_seen, ?) WHERE session_key = ? AND closed_at IS NULL');
            $statement->execute([$now, $now, $key]);
            n360_audit_cleanup($db, $now);
        });
    } catch (Throwable $ignored) {
        // Una falla de almacenamiento conserva el cierre existente.
    }
}

function n360_audit_page(): ?string
{
    $root = realpath(dirname(__DIR__));
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!$root || !$script || !n360_audit_path_within($script, $root)) return null;
    $page = str_replace('\\', '/', substr($script, strlen($root) + 1));
    return preg_match('~^(?!login/)[a-z0-9_./-]{1,180}\.php$~iD', $page) && !str_contains($page, '/.')
        ? $page : null;
}

function n360_audit_track_page(): void
{
    static $handled = false;
    if ($handled || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || !in_array($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '', ['', 'document'], true)
        || stripos((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? ''), 'prefetch') !== false) return;
    $handled = true;
    try {
        $identity = n360_audit_identity();
        if ($identity === null) return;
        $observation = (array)($_SESSION['n360_audit_observation'] ?? []);
        $now = time();
        if (($observation['retry'] ?? 0) > $now) return;
        $page = n360_audit_page();
        if ($page === null || (($observation['page'] ?? '') === $page && ($observation['seen'] ?? 0) > $now - N360_AUDIT_TOUCH)) return;

        $db = n360_audit_db();
        $key = n360_audit_session_key($db);
        $seen = n360_audit_transaction($db, static function () use ($db, $identity, $key, $now, $page) {
            $statement = $db->prepare('SELECT last_seen, last_page, closed_at FROM audit_sessions WHERE session_key = ?');
            $statement->execute([$key]);
            $session = $statement->fetch();
            $statement->closeCursor();
            if (!$session) {
                n360_audit_new_session($db, $key, $identity, $now, n360_audit_client());
                $session = ['last_seen' => $now, 'last_page' => '', 'closed_at' => null];
            }
            if ($session['closed_at'] !== null) return $now;
            $lastSeen = (int)$session['last_seen'];
            if ($session['last_page'] !== $page) {
                $segments = explode('/', $page, 2);
                $module = count($segments) === 1 ? 'Panel' : $segments[0];
                n360_audit_event($db, $now, 'navigation', '', $identity, $key, $module, $page, ['', '', '', '']);
            }
            $seen = $lastSeen <= $now - N360_AUDIT_TOUCH ? $now : $lastSeen;
            if ($session['last_page'] !== $page || $seen !== $lastSeen) {
                $update = $db->prepare('UPDATE audit_sessions SET last_seen = ?, last_page = ? WHERE session_key = ?');
                $update->execute([$seen, $page, $key]);
            }
            n360_audit_cleanup($db, $now);
            return $seen;
        });
        $_SESSION['n360_audit_observation'] = ['page' => $page, 'seen' => $seen];
    } catch (Throwable $ignored) {
        $_SESSION['n360_audit_observation'] = ['retry' => time() + N360_AUDIT_TOUCH];
    }
}
