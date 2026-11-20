<?php
ob_start();
define('N360_ENCOMIENDAS',true);
require_once __DIR__.'/../includes/encomiendas_helpers.php';
require_once __DIR__.'/../includes/encomiendas_kupos_archivo.php';
n360_start_secure_session();n360_send_security_headers();
header('Content-Type: text/plain; charset=utf-8');
if (!isset($_SESSION['usuario']) || enc_user_id()<=0) { http_response_code(401);exit('Sesión expirada.'); }
$canTrack=enc_can_view('enc-tracking');
if (!enc_can_module() || (!$canTrack && !enc_can_view('enc-register'))) { http_response_code(403);exit('No autorizado.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') { header('Allow: GET');http_response_code(405);exit('Método no permitido.'); }
$id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404);exit('Archivo no disponible.'); }
$conn=null;$stream=null;$size=0;$lote=null;$failure='';
try {
    $conn=enc_open_connection_json();
    if (!enc_kupos_original_ready($conn)) { http_response_code(409);$failure='Falta la actualización SQL para conservar originales en la base de datos.'; }
    else {
        $lote=enc_fetch_one($conn,'SELECT id,archivo_nombre,archivo_bytes,archivo_sha256,(archivo_original IS NOT NULL) archivo_guardado,OCTET_LENGTH(archivo_original) bytes_reales FROM tb_enc_importaciones WHERE id=?'.($canTrack?'':' AND creado_por=?'),
            $canTrack?'i':'ii',$canTrack?[$id]:[$id,enc_user_id()]);
        if (!$lote || !enc_kupos_original_available($lote)) { http_response_code(404);$failure='Esta importación no tiene un CSV original disponible.'; }
        else {
            if ((int)$lote['archivo_bytes']!==(int)$lote['bytes_reales']) throw new RuntimeException('El original guardado está incompleto.');
            $stream=enc_kupos_original_open($conn,$lote);
            $size=(int)$lote['archivo_bytes'];
        }
    }
} catch (Throwable $e) {
    if (is_resource($stream)) fclose($stream);
    enc_log($e);http_response_code(500);$failure='No se pudo descargar el archivo.';
} finally { if ($conn instanceof mysqli) $conn->close(); }
if ($failure!=='') exit($failure);
session_write_close();
while (ob_get_level()>0) ob_end_clean();
header('Content-Type: text/csv');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: '.enc_kupos_original_disposition($lote['archivo_nombre']));
header('Content-Length: '.$size);
try { fpassthru($stream); } finally { fclose($stream); }
