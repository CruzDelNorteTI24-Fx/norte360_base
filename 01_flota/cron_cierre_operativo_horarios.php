<?php
date_default_timezone_set('America/Lima');

define('ACCESS_GRANTED', true);
require_once(__DIR__ . '/../.c0nn3ct/db_securebd2.php');

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

$tokenPermitido = 'NORTE360_CIERRE_2026_FABIO_SEGURIDAD';
$tokenRecibido = $_GET['token'] ?? '';

if ($tokenRecibido !== $tokenPermitido) {
    cron_log('ACCESO DENEGADO');
    http_response_code(403);
    echo "Acceso denegado.";
    exit;
}

try {
    if (!$conn) {
        throw new Exception("No existe conexión a la base de datos.");
    }

    cron_log('INICIO DE EJECUCIÓN');

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

        cron_log("OK | Día operativo cerrado: {$fechaOperativa} | Ejecución BD: {$fechaEjecucion} | Unidades retiradas: {$total} | Estado: {$estado} | {$motivo}");

        echo "OK - Cierre operativo ejecutado\n";
        echo "Día operativo cerrado: {$fechaOperativa}\n";
        echo "Unidades retiradas: {$total}\n";
        echo "Estado: {$estado}";
    } else {
        cron_log('OK - Procedure ejecutado, pero no se encontró registro de cierre.');
        echo "OK - Procedure ejecutado, pero no se encontró registro de cierre.";
    }

} catch (Throwable $e) {
    cron_log('ERROR - ' . $e->getMessage());
    http_response_code(500);
    echo "ERROR - " . $e->getMessage();
}