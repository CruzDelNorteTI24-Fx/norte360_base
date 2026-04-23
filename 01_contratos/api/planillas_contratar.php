<?php
// 01_contratos/api/planillas_contratar.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

// HAZ visibles los errores como JSON:
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_exception_handler(function(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
});

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$trabid = isset($payload['trabid']) ? (int)$payload['trabid'] : 0;
$tipo   = isset($payload['tipo']) ? (int)$payload['tipo'] : 1; // 1 planilla, 2 rph
$doc    = array_key_exists('doc',$payload) ? ($payload['doc'] ?? null) : null; // puede ser null
$com    = trim((string)($payload['com'] ?? ''));

// nueva: fecha de inicio (YYYY-MM-DD), por defecto hoy
$fecha_ing = trim((string)($payload['fecha_ingreso'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_ing)) { $fecha_ing = date('Y-m-d'); }

if ($trabid <= 0 || !in_array($tipo,[1,2],true)) {
  http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit;
}
if ($com === '') $com = 'Alta automática por '.$_SESSION['usuario'];

// ¿ya hay vigente?
$sql = "SELECT 1
        FROM tb_tpln
        WHERE clm_pl_trabid=?
          AND (clm_pl_tra_estado IN (1,'ACTIVO','VIGENTE','EN PLANILLA'))
          AND (clm_pl_fechasalida IS NULL
               OR CAST(clm_pl_fechasalida AS CHAR)='0000-00-00'
               OR clm_pl_fechasalida>=CURDATE())
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i',$trabid);
$stmt->execute();
$hayVigente = (bool)$stmt->get_result()->fetch_row();
$stmt->close();

if ($hayVigente) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Ya existe un contrato vigente']); exit; }

// INSERT incluyendo clm_pl_fechaingrespl
$sql = "INSERT INTO tb_tpln
          (clm_pl_trabid, clm_pl_fechregistro, clm_pl_fechaingrespl,
           clm_pl_tra_estado, clm_pl_fechasalida, clm_pl_doc, clm_pl_tipo, clm_pl_com)
        VALUES (?, NOW(), ?, 1, NULL, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('issis', $trabid, $fecha_ing, $doc, $tipo, $com);
$stmt->execute();

echo json_encode(['ok'=>true]);
