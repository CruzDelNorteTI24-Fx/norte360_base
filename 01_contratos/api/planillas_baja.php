<?php
// 01_contratos/api/planillas_baja.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_exception_handler(function(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
});

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$trabid  = isset($payload['trabid']) ? (int)$payload['trabid'] : 0;
$fecha_baja = isset($payload['fecha_baja']) ? $payload['fecha_baja'] : date('Y-m-d');
$nota = trim((string)($payload['nota'] ?? ''));
if ($nota==='') $nota = 'Baja automática por '.$_SESSION['usuario'];

if ($trabid <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

// 1) asegúrate de que hay vigente
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

if (!$hayVigente) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'No hay contrato vigente']); exit; }

// 2) obtén el último registro vigente (fecha de registro)
$sql = "SELECT MAX(clm_pl_fechregistro) AS max_reg
        FROM tb_tpln
        WHERE clm_pl_trabid=?
          AND (clm_pl_tra_estado IN (1,'ACTIVO','VIGENTE','EN PLANILLA'))
          AND (clm_pl_fechasalida IS NULL
               OR CAST(clm_pl_fechasalida AS CHAR)='0000-00-00'
               OR clm_pl_fechasalida>=CURDATE())";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i',$trabid);
$stmt->execute();
$max = $stmt->get_result()->fetch_assoc()['max_reg'] ?? null;
$stmt->close();

if (!$max) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'No se encontró el registro vigente']); exit; }

// 3) actualiza ese registro
$sql = "UPDATE tb_tpln
        SET clm_pl_tra_estado=2,
            clm_pl_fechasalida=?,
            clm_pl_com = CONCAT(
               COALESCE(clm_pl_com,''),
               CASE WHEN COALESCE(clm_pl_com,'')='' THEN '' ELSE ' | ' END,
               ?
            )
        WHERE clm_pl_trabid=? AND clm_pl_fechregistro=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssis', $fecha_baja, $nota, $trabid, $max);
$stmt->execute();

echo json_encode(['ok'=>true]);
