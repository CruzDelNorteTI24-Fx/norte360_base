<?php
ob_start();
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_inventario.php';
enc_inventory_require_api('enc-register');

$conn = null; $status = 200; $response = [];
try {
    $operation = (string)($_POST['operacion'] ?? '');
    if (!in_array($operation,['previsualizar','validar','importar'],true)) {
        throw new InvalidArgumentException('Operación no válida.');
    }
    if ($operation === 'previsualizar') {
        unset($_SESSION['enc_kupos_stage']);
        $file = $_FILES['archivo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new InvalidArgumentException('Selecciona un CSV válido dentro del tamaño permitido por el servidor.');
        }
        if ((int)$file['size'] <= 0) {
            throw new InvalidArgumentException('El CSV está vacío.');
        }
        $name = basename(str_replace('\\','/',(string)$file['name']));
        if (strtolower(pathinfo($name,PATHINFO_EXTENSION)) !== 'csv' || enc_kupos_length($name)>255) {
            throw new InvalidArgumentException('Selecciona un archivo con extensión .csv.');
        }
        $stream = fopen($file['tmp_name'],'rb');
        if (!$stream) throw new RuntimeException('No se pudo leer el archivo temporal.');
        try { $parsed = enc_kupos_parse($stream); } finally { fclose($stream); }
        $originalHash=hash_file('sha256',$file['tmp_name']);
        if (!is_string($originalHash)) throw new RuntimeException('No se pudo verificar el CSV original.');
        $stage = ['format_version'=>ENC_KUPOS_STAGE_VERSION,'token'=>bin2hex(random_bytes(24)),'user'=>enc_user_id(),'expires'=>time()+1800,
            'archivo'=>$name,'archivo_sha256'=>$originalHash,'parsed'=>$parsed,'fecha_corte'=>enc_kupos_cutoff((string)($_POST['fecha_corte'] ?? ''),$parsed)];
    } else {
        $stage = enc_kupos_stage();
        $stage['fecha_corte'] = enc_kupos_cutoff((string)($_POST['fecha_corte'] ?? $stage['fecha_corte']),$stage['parsed']);
    }
    $originalUpload=$operation==='importar' ? enc_kupos_original_upload($_FILES['archivo'] ?? null,$stage) : null;
    $conn = enc_open_connection_json();
    if (!enc_inventory_ready($conn)) {
        $status = 409; $response = ['ok'=>false,'message'=>'Falta instalar la actualización de inventario e importaciones de Encomiendas.'];
    } elseif (!enc_kupos_dates_ready($conn)) {
        $status = 409; $response = ['ok'=>false,'message'=>'Falta actualizar el inventario para conservar las fechas de creación y cobro de Kupos. Solicita la actualización a Administración.'];
    } elseif ($operation === 'importar' && !enc_kupos_original_ready($conn)) {
        $status=409;$response=['ok'=>false,'message'=>'Falta agregar las columnas para conservar el CSV original en la base de datos. Ejecuta la actualización SQL antes de importar.'];
    } elseif ($operation === 'importar') {
        $result = enc_kupos_commit($conn,$stage,enc_user_id(),$originalUpload);
        if (!empty($result['conflict'])) {
            $stage['baseline'] = $result['comparison']['baseline'];
            $stage['expires'] = time()+1800;
            $_SESSION['enc_kupos_stage'] = $stage;
            $status = 409;
            $response = ['ok'=>false,'message'=>'La validación cambió. Revisa la previsualización actualizada antes de importar.',
                'preview'=>enc_kupos_preview_result($stage,$result['comparison'])];
        } else {
            unset($_SESSION['enc_kupos_stage']);
            $response = ['ok'=>true,'message'=>!empty($result['duplicate'])
                ? 'Este contenido ya fue importado. Se conserva el control existente.'
                : 'Importación completada. Las encomiendas y sus historiales fueron guardados.',
                'control'=>$result['lote']['codigo_control'],'redirect'=>'importaciones.php?id='.(int)$result['lote']['id'],
                'duplicate'=>!empty($result['duplicate']),'counts'=>$result['counts'] ?? null];
        }
    } else {
        $comparison = enc_kupos_compare($conn,$stage['parsed'],$stage['fecha_corte']);
        $stage['baseline'] = $comparison['baseline'];
        $stage['expires'] = time()+1800;
        $_SESSION['enc_kupos_stage'] = $stage;
        $response = ['ok'=>true,'message'=>$comparison['errors'] ? 'Revisa los errores antes de importar.' : 'Validación completada.',
            'preview'=>enc_kupos_preview_result($stage,$comparison)];
    }
} catch (InvalidArgumentException $e) {
    $status = 422; $response = ['ok'=>false,'message'=>$e->getMessage()];
} catch (LengthException $e) {
    $status = 422; $response = ['ok'=>false,'message'=>$e->getMessage()];
} catch (Throwable $e) {
    enc_log($e);
    $status = in_array((int)$e->getCode(),[1205,1213],true) ? 409 : 500;
    $response = ['ok'=>false,'message'=>$status===409
        ? 'Hay otra operación en curso. Vuelve a validar el archivo.'
        : 'No se pudo completar la operación. No se aplicaron cambios de esta importación.'];
} finally {
    if ($conn instanceof mysqli) $conn->close();
}
http_response_code($status);
echo json_encode($response,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
