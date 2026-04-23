<?php
session_start();
if (!isset($_SESSION['usuario'])) { http_response_code(401); exit; }

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

// Inline o descarga
$disp = (isset($_GET['disposition']) && strtolower($_GET['disposition']) === 'attachment') ? 'attachment' : 'inline';

// ---------- LEE BLOB ----------
$sql = "SELECT clm_cap_documento FROM tb_capacitaciones WHERE clm_cap_id=? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();

$blob = null;
if ($res = @$stmt->get_result()) {
  $row = $res->fetch_row();
  if ($row) $blob = $row[0];
  $stmt->close();
} else {
  $stmt->store_result();
  $stmt->bind_result($blob);
  $stmt->fetch();
  $stmt->close();
}

if ($blob === null || $blob === '') {
  http_response_code(404); exit('Sin documento');
}

// Si alguien guardó TEXTO HEX en lugar de binario, conviértelo
$looksHex = (ctype_xdigit($blob) && (strlen($blob) % 2 === 0));
if ($looksHex && strncmp($blob, "%PDF", 4) !== 0 && strncmp($blob, "\xFF\xD8\xFF", 3) !== 0 && strncmp($blob, "\x89PNG", 4) !== 0) {
  $bin = @hex2bin($blob);
  if ($bin !== false) { $blob = $bin; }
}

// ---------- DETECCIÓN DE MIME SEGURA ----------
$mime = 'application/octet-stream';

// 1) Firmas rápidas
if (strncmp($blob, "%PDF", 4) === 0) {
  $mime = 'application/pdf';
} elseif (strncmp($blob, "\x89PNG", 4) === 0) {
  $mime = 'image/png';
} elseif (strncmp($blob, "\xFF\xD8\xFF", 3) === 0) {
  $mime = 'image/jpeg';
} elseif (substr($blob, 0, 2) === 'PK' && strpos($blob, '[Content_Types].xml') !== false) {
  // OOXML: DOCX/PPTX/XLSX (no se embeben en iframe, pero se pueden descargar/abrir aparte)
  if (strpos($blob, 'word/') !== false)       $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  elseif (strpos($blob, 'ppt/') !== false)    $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
  elseif (strpos($blob, 'xl/') !== false)     $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  else                                        $mime = 'application/zip';
} else {
  // 2) finfo como respaldo
  if (class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $det = $fi->buffer($blob);
    if ($det) $mime = $det;
  }
}

// Nombre sugerido
$ext = 'bin';
if     ($mime === 'application/pdf') $ext = 'pdf';
elseif ($mime === 'image/png')       $ext = 'png';
elseif ($mime === 'image/jpeg')      $ext = 'jpg';
elseif ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') $ext = 'docx';
elseif ($mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') $ext = 'pptx';
elseif ($mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') $ext = 'xlsx';

$filename = "capacitacion_{$id}.{$ext}";

// ---------- HEADERS SEGUROS PARA STREAM ----------
@session_write_close();                     // evita bloquear otras peticiones del modal
@ini_set('zlib.output_compression', '0');   // evita conflictos con Content-Length
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');

// Limpia cualquier buffer previo
while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }

// Encabezados
header('Content-Type: '.$mime);
header('Content-Disposition: '.$disp.'; filename="'.$filename.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Accept-Ranges: bytes');
header('Content-Transfer-Encoding: binary');

// Content-Length solo si NO hay compresión activa
$sendCL = !ini_get('zlib.output_compression');
if ($sendCL) {
  // strlen binaria segura
  $len = function_exists('mb_strlen') ? mb_strlen($blob, '8bit') : strlen($blob);
  header('Content-Length: '.$len);
  header('X-Doc-Len-PHP: '.$len);
}

// Cuerpo
echo $blob;
exit;
