<?php
date_default_timezone_set('America/Lima');

header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/cron_cierre_operativo.log';

function cron_log(string $mensaje): void {
    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('d/m/Y H:i:s') . '] ' . $mensaje . PHP_EOL,
        FILE_APPEND
    );
}

function cron_obtener_token(): array {
    if (isset($_GET['token']) && $_GET['token'] !== '') {
        return [(string)$_GET['token'], 'get'];
    }

    if (!empty($_SERVER['QUERY_STRING'])) {
        parse_str((string)$_SERVER['QUERY_STRING'], $query);
        if (!empty($query['token'])) {
            return [(string)$query['token'], 'query_string'];
        }
    }

    if (php_sapi_name() === 'cli') {
        global $argv;
        foreach (array_slice((array)$argv, 1) as $arg) {
            $arg = trim((string)$arg);
            if ($arg === '') {
                continue;
            }

            if (strpos($arg, 'token=') === 0) {
                parse_str($arg, $query);
                if (!empty($query['token'])) {
                    return [(string)$query['token'], 'cli_arg'];
                }
            }

            $queryString = parse_url($arg, PHP_URL_QUERY);
            if ($queryString) {
                parse_str($queryString, $query);
                if (!empty($query['token'])) {
                    return [(string)$query['token'], 'cli_url'];
                }
            }
        }

        $envToken = getenv('NORTE360_CIERRE_TOKEN');
        if ($envToken) {
            return [(string)$envToken, 'env'];
        }
    }

    return ['', 'sin_token'];
}

$runId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
$tokenPermitido = 'NORTE360_CIERRE_2026_FABIO_SEGURIDAD';
$esCli = (php_sapi_name() === 'cli');
[$tokenRecibido, $tokenFuente] = cron_obtener_token();

cron_log("PING_CRON | run={$runId} | sapi=" . php_sapi_name() . " | ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'CLI') . " | token_source={$tokenFuente}");

if (!$esCli && $tokenRecibido !== $tokenPermitido) {
    cron_log("ACCESO DENEGADO | run={$runId} | token_source={$tokenFuente}");
    http_response_code(403);
    echo "Acceso denegado.";
    exit;
}

if ($esCli && $tokenRecibido !== '' && $tokenRecibido !== $tokenPermitido) {
    cron_log("ACCESO CLI DENEGADO | run={$runId} | token_source={$tokenFuente}");
    echo "Acceso denegado.";
    exit;
}

if ($esCli) {
    cron_log("ACCESO CLI AUTORIZADO | run={$runId} | token_source={$tokenFuente}");
}
define('ACCESS_GRANTED', true);

try {
    cron_log("CARGANDO_CONEXION | run={$runId}");

    require_once(__DIR__ . '/../.c0nn3ct/db_securebd2.php');

    if (!isset($conn) || !$conn) {
        throw new Exception("No existe conexión a la base de datos.");
    }

    cron_log("INICIO DE EJECUCIÓN | run={$runId}");

    if (!$conn->query("CALL sp_cierre_operativo_progbuses()")) {
        throw new Exception($conn->error);
    }

    while ($conn->more_results() && $conn->next_result()) {
        // Limpia resultados pendientes del CALL
    }

    $sqlLog = "
        SELECT 
            clm_cierre_fecha,
            clm_cierre_datetime,
            clm_cierre_total_retirados,
            clm_cierre_estado,
            clm_cierre_motivo
        FROM tb_progbuses_cierre_operativo
        ORDER BY clm_cierre_id DESC
        LIMIT 1
    ";

    $res = $conn->query($sqlLog);

    if ($res && $row = $res->fetch_assoc()) {
        $fechaOperativa = date('d/m/Y', strtotime($row['clm_cierre_fecha']));
        $fechaEjecucion = date('d/m/Y H:i:s', strtotime($row['clm_cierre_datetime']));
        $total = (int)$row['clm_cierre_total_retirados'];
        $estado = $row['clm_cierre_estado'] ?? 'SIN ESTADO';
        $motivo = $row['clm_cierre_motivo'] ?? '';

        cron_log("OK | run={$runId} | Día operativo cerrado: {$fechaOperativa} | Ejecución BD: {$fechaEjecucion} | Unidades retiradas: {$total} | Estado: {$estado} | {$motivo}");

        echo "OK - Cierre operativo ejecutado\n";
        echo "Run: {$runId}\n";
        echo "Día operativo cerrado: {$fechaOperativa}\n";
        echo "Unidades retiradas: {$total}\n";
        echo "Estado: {$estado}";
    } else {
        cron_log("OK_SIN_REGISTRO | run={$runId} | Procedure ejecutado, pero no se encontró registro de cierre.");
        echo "OK - Procedure ejecutado, pero no se encontró registro de cierre.";
    }

} catch (Throwable $e) {
    cron_log("ERROR | run={$runId} | " . $e->getMessage());
    http_response_code(500);
    echo "ERROR - " . $e->getMessage();
}
