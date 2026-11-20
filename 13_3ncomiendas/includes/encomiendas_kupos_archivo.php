<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');

function enc_kupos_original_ready(mysqli $conn): bool {
    static $cache=[];
    $key=spl_object_id($conn);
    if (array_key_exists($key,$cache)) return $cache[$key];
    try {
        $result=$conn->query('SELECT archivo_original,archivo_sha256,archivo_bytes FROM tb_enc_importaciones LIMIT 0');
        if (!$result) {
            if (in_array($conn->errno,[1054,1146],true)) return $cache[$key]=false;
            throw new RuntimeException('No se pudo verificar el almacenamiento de originales.');
        }
        $result->free();return $cache[$key]=true;
    } catch (mysqli_sql_exception $e) {
        if (in_array((int)$e->getCode(),[1054,1146],true)) return $cache[$key]=false;
        throw $e;
    }
}

function enc_kupos_original_available(array $lote): bool {
    return !empty($lote['archivo_guardado']) && (int)($lote['archivo_bytes'] ?? 0)>0
        && is_string($lote['archivo_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D',$lote['archivo_sha256']);
}

function enc_kupos_original_upload(?array $file,array $stage): array {
    $path=is_array($file) ? ($file['tmp_name'] ?? '') : '';
    if (!is_string($path) || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
        || !is_uploaded_file($path) || filesize($path)<=0) {
        throw new InvalidArgumentException('Vuelve a seleccionar el CSV original para conservarlo al importar. Verifica el tamaño de carga permitido por el servidor.');
    }
    $expected=$stage['archivo_sha256'] ?? '';
    $actual=hash_file('sha256',$path);
    if (!is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/D',$expected)
        || !is_string($actual) || !hash_equals($expected,$actual)) {
        throw new InvalidArgumentException('El CSV cambió después de la previsualización. Selecciónalo nuevamente y vuelve a validar.');
    }
    return ['path'=>$path,'sha256'=>$actual,'bytes'=>filesize($path)];
}

// BLOB binario, misma conexión y transacción que el control. No archiva en disco.
function enc_kupos_original_store(mysqli $conn,int $loteId,array $upload): void {
    $input=null;$stmt=null;
    try {
        $input=fopen($upload['path'],'rb');
        if (!$input) throw new RuntimeException('No se pudo leer el CSV original.');
        $stmt=$conn->prepare('UPDATE tb_enc_importaciones SET archivo_original=?,archivo_sha256=?,archivo_bytes=? WHERE id=? AND archivo_original IS NULL');
        $blob=null;$sha=$upload['sha256'];$bytes=(int)$upload['bytes'];
        $stmt->bind_param('bsii',$blob,$sha,$bytes,$loteId);
        $copied=0;$hash=hash_init('sha256');
        while (!feof($input)) {
            $part=fread($input,65536);
            if ($part===false || ($part==='' && !feof($input))) throw new RuntimeException('No se pudo leer el CSV completo.');
            if ($part==='') break;
            $copied+=strlen($part);hash_update($hash,$part);
            if (!$stmt->send_long_data(0,$part)) throw new RuntimeException('No se pudo enviar el CSV original a la base de datos.');
        }
        if ($copied!==$bytes || !hash_equals($sha,hash_final($hash))) throw new RuntimeException('El CSV original no coincide con el validado.');
        $stmt->execute();
        if ($stmt->affected_rows!==1) throw new RuntimeException('No se pudo asociar el original sin reemplazar otro archivo.');
        $saved=enc_fetch_one($conn,'SELECT OCTET_LENGTH(archivo_original) bytes,SHA2(archivo_original,256) sha FROM tb_enc_importaciones WHERE id=?','i',[$loteId]);
        if (!$saved || (int)$saved['bytes']!==$bytes || !hash_equals($sha,(string)$saved['sha'])) {
            throw new RuntimeException('El CSV original no se guardó completo.');
        }
    } finally {
        if (is_resource($input)) fclose($input);
        if ($stmt instanceof mysqli_stmt) $stmt->close();
    }
}

// Lee bloques sin cargar todo el BLOB en PHP; el temporal de entrega se destruye al cerrar.
function enc_kupos_original_open(mysqli $conn,array $lote) {
    if (!enc_kupos_original_available($lote)) throw new RuntimeException('El original no está disponible.');
    $stream=fopen('php://temp/maxmemory:1048576','w+b');$stmt=null;
    if (!$stream) throw new RuntimeException('No se pudo preparar la descarga.');
    try {
        $stmt=$conn->prepare('SELECT SUBSTRING(archivo_original,?,?) FROM tb_enc_importaciones WHERE id=? AND archivo_sha256=?');
        $offset=1;$size=262144;$id=(int)$lote['id'];$sha=$lote['archivo_sha256'];$bytes=(int)$lote['archivo_bytes'];
        $stmt->bind_param('iiis',$offset,$size,$id,$sha);$hash=hash_init('sha256');
        while ($offset<=$bytes) {
            $stmt->execute();$stmt->bind_result($part);
            if (!$stmt->fetch() || !is_string($part) || strlen($part)!==min($size,$bytes-$offset+1)) {
                throw new RuntimeException('El original guardado está incompleto.');
            }
            $stmt->free_result();hash_update($hash,$part);
            if (fwrite($stream,$part)!==strlen($part)) throw new RuntimeException('No se pudo preparar el archivo completo.');
            $offset+=strlen($part);
        }
        if (!hash_equals($sha,hash_final($hash))) throw new RuntimeException('El original guardado no superó la verificación de integridad.');
        rewind($stream);return $stream;
    } catch (Throwable $e) { fclose($stream);throw $e; }
    finally { if ($stmt instanceof mysqli_stmt) $stmt->close(); }
}

function enc_kupos_original_disposition(string $name): string {
    $name=basename(str_replace('\\','/',$name));
    $name=preg_replace('/[\x00-\x1f\x7f]/u','',$name) ?: 'reporte_kupos.csv';
    $ascii=function_exists('iconv') ? iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name) : $name;
    $ascii=preg_replace('/[^a-zA-Z0-9._ -]/','_',is_string($ascii)?$ascii:'') ?: 'reporte_kupos.csv';
    return 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name);
}
