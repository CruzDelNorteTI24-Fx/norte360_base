<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit;
}
define('ACCESS_GRANTED', true);
require '../../.c0nn3ct/db_securebd2.php'; // <- Asegúrate que apunta correctamente a tu archivo de conexión

// 1) Recoge datos del formulario
$idtrabajador       = $_POST['idtrabajador']       ?? '';
$nombre_trabajador  = $_POST['nombre_trabajador']  ?? '';
$dni_trabajador     = $_POST['dni_trabajador']     ?? '';
$idtipo_documento   = $_POST['idtipo_documento']   ?? '';
$observaciones      = $_POST['observaciones']      ?? '';
$domicilio_trabajador      = $_POST['domicilio_trabajador']      ?? '';
$sexo      = $_POST['sexo']      ?? '';


$genero_trato = "Señor(a)"; // fallback seguro
if (is_string($sexo)) {
    $sx = trim(mb_strtolower($sexo, 'UTF-8'));
    if ($sx === 'masculino') {
        $genero_trato = 'el Señor';
    } elseif ($sx === 'femenino') {
        $genero_trato = 'la Señorita';
    }
}


// 2) Buscar el nombre del tipo de documento por ID
$tipo_doc_nombre = '';
if ($idtipo_documento !== '') {
    if ($stmt = $conn->prepare("SELECT nombre_tipo FROM tb_tipo_documento WHERE id_tipo_documento = ? LIMIT 1")) {
        $stmt->bind_param("i", $idtipo_documento);
        $stmt->execute();
        $stmt->bind_result($tipo_doc_nombre);
        $stmt->fetch();
        $stmt->close();
    }
}
// Fallback por si no encuentra: usa el ID
if ($tipo_doc_nombre === null || $tipo_doc_nombre === '') {
    $tipo_doc_nombre = $idtipo_documento;
}

// 3) Prepara los valores a inyectar
$tokens = [
  '{{idtrabajador}}'   => '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($idtrabajador),
  '{{nombre}}' => '<w:rPr><w:b/></w:rPr>' . mb_strtoupper(htmlspecialchars($nombre_trabajador), 'UTF-8'),
  '{{dni}}'            => '<w:rPr><w:b/></w:rPr>' . htmlspecialchars($dni_trabajador),
  '{{tipo_documento}}'       => mb_strtoupper(htmlspecialchars($idtipo_documento), 'UTF-8'),
  '{{tipo_documento_id}}'    => htmlspecialchars($idtipo_documento),
  '{{tipo_documento_nombre}}'=> '<w:rPr><w:b/></w:rPr>' . mb_strtoupper(htmlspecialchars($tipo_doc_nombre), 'UTF-8'),
  '{{observaciones}}'  => htmlspecialchars($observaciones),
  '{{domicilio_trabajador}}' => '<w:rPr><w:b/></w:rPr>' . mb_strtoupper(htmlspecialchars($domicilio_trabajador), 'UTF-8'),
  '{{genero}}' => htmlspecialchars($genero_trato),
  '{{fecha}}'          => date('d/m/Y H:i:s'),
];

// 4) Rutas
$original   = __DIR__ . '/plantillas/Carta Aceptación.docx';
// Genera un archivo temporal (garantiza extensión .docx)
$tmpPath    = tempnam(sys_get_temp_dir(), 'tpl');
rename($tmpPath, $tmpPath .= '.docx');  
// Copia la plantilla original al temporal
copy($original, $tmpPath);

// 5) Abre la copia con ZipArchive
$zip = new ZipArchive;
if ($zip->open($tmpPath) !== true) { @unlink($tmpPath); die("No se pudo abrir la plantilla temporal."); }

// 6) Lee, normaliza y reemplaza el XML
$xml = $zip->getFromName('word/document.xml');


// 6.2) Normaliza espacios que Word mete entre llaves {{  nombre  }}
$xml = preg_replace('/\{\{\s+/','{{', $xml);
$xml = preg_replace('/\s+\}\}/','}}', $xml);

// 6.3) Reemplaza tokens
$xml = strtr($xml, $tokens);

$zip->addFromString('word/document.xml', $xml);

$zip->close();

// 8) Envía al navegador con nombre marcado
$timestamp = date('dmYHi');
$filename  = "word_generado_{$timestamp}.docx";

header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
readfile($tmpPath);

// 9) Limpia el temporal
@unlink($tmpPath);
exit;