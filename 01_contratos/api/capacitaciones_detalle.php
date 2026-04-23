<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'No autorizado'], JSON_UNESCAPED_UNICODE);
  exit;
}

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$capid = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($capid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'ID inválido'], JSON_UNESCAPED_UNICODE);
  exit;
}

$CAP_ESTADOS_MAP = [0=>'PROGRAMADA',1=>'EN CURSO',2=>'FINALIZADA',3=>'CANCELADA'];
$CAP_ESTILOS     = [0=>'secondary',1=>'info',2=>'success',3=>'danger'];

$TR_ESTADOS_MAP = [
  0=>'PENDIENTE',
  1=>'INSCRITO',
  2=>'APROBADO',
  3=>'REPROBADO',
  4=>'ASISTIÓ',
  5=>'NO ASISTIÓ'
];
$TR_ESTILOS = [
  0=>'secondary',
  1=>'info',
  2=>'success',
  3=>'danger',
  4=>'primary',
  5=>'warning'
];

/* =========================
   Detalle de la capacitación
   ========================= */
$sqlC = "SELECT 
    c.clm_cap_id,
    c.clm_cap_capacitacion,
    c.clm_cap_estado,
    c.clm_cap_fecharegistro,
    c.clm_cap_fechainicio,
    c.clm_cap_fechafin,
    c.clm_cap_duracion_minutos,
    COALESCE(c.clm_cap_observacion,'') AS clm_cap_observacion,
    /* NUEVO: indicadores de documento */
    CASE 
      WHEN c.clm_cap_documento IS NULL OR COALESCE(OCTET_LENGTH(c.clm_cap_documento), LENGTH(c.clm_cap_documento)) = 0 THEN 0
      ELSE 1
    END AS has_doc,
    COALESCE(OCTET_LENGTH(c.clm_cap_documento), LENGTH(c.clm_cap_documento), 0) AS doc_size
  FROM tb_capacitaciones c
  WHERE c.clm_cap_id = ?";
$stmt = $conn->prepare($sqlC);
$stmt->bind_param('i', $capid);
$stmt->execute();
$resC = $stmt->get_result();
$cap = $resC->fetch_assoc();
if (!$cap) {
  echo json_encode(['ok'=>false,'error'=>'No encontrado'], JSON_UNESCAPED_UNICODE);
  exit;
}

$k = is_numeric($cap['clm_cap_estado']) ? (int)$cap['clm_cap_estado'] : null;
$cap_det = [
  'id'           => (int)$cap['clm_cap_id'],
  'nombre'       => $cap['clm_cap_capacitacion'],
  // NUEVO: si es numérico, se retorna como entero; si no, se respeta el texto original.
  'estado'       => is_null($k) ? $cap['clm_cap_estado'] : $k,
  'estado_texto' => is_null($k) ? $cap['clm_cap_estado'] : ($CAP_ESTADOS_MAP[$k] ?? ('ESTADO '.$k)),
  'estado_badge' => is_null($k) ? 'secondary' : ($CAP_ESTILOS[$k] ?? 'secondary'),
  'fechareg'     => $cap['clm_cap_fecharegistro'],
  'fechainicio'  => $cap['clm_cap_fechainicio'],
  'fechafin'     => $cap['clm_cap_fechafin'],
  'duracion_min' => isset($cap['clm_cap_duracion_minutos']) ? (int)$cap['clm_cap_duracion_minutos'] : 0,
  'observacion'  => $cap['clm_cap_observacion'],
  // NUEVO: indicadores de documento
  'has_doc'      => (bool)$cap['has_doc'],
  'doc_size'     => (int)$cap['doc_size']
];

/* ===============
   Inscritos (lista)
   =============== */
$sqlT = "SELECT 
    t.clm_trcap_id,
    t.clm_trcap_trabid,
    t.clm_trcap_estado,
    COALESCE(t.clm_trcap_observacion,'') AS obs,
    tra.clm_tra_nombres   AS nombres,
    tra.clm_tra_dni       AS dni,
    tra.clm_tra_cargo     AS cargo
  FROM tb_trabincapacitaciones t
  JOIN tb_trabajador tra ON tra.clm_tra_id = t.clm_trcap_trabid
  WHERE t.clm_trcap_capid = ?
  ORDER BY tra.clm_tra_nombres";
$stmt2 = $conn->prepare($sqlT);
stmt2_label:
$stmt2->bind_param('i', $capid);
$stmt2->execute();
$resT = $stmt2->get_result();

$list = [];
$conteo = [];
$total  = 0;
while ($r = $resT->fetch_assoc()) {
  $total++;
  $e = is_numeric($r['clm_trcap_estado']) ? (int)$r['clm_trcap_estado'] : 0;
  $conteo[$e] = ($conteo[$e] ?? 0) + 1;

  $list[] = [
    'trcap_id'      => (int)$r['clm_trcap_id'],
    'trab_id'       => (int)$r['clm_trcap_trabid'],
    'nombres'       => $r['nombres'],
    'dni'           => $r['dni'],
    'cargo'         => $r['cargo'],
    'estado'        => $e,
    'estado_texto'  => $TR_ESTADOS_MAP[$e] ?? ('ESTADO '.$e),
    'estado_badge'  => $TR_ESTILOS[$e] ?? 'secondary',
    'obs'           => $r['obs']
  ];
}

/* ==========================
   Resumen por estado (badges)
   ========================== */
$por_estado = [];
foreach ($conteo as $e => $c) {
  $por_estado[] = [
    'estado'   => (int)$e,
    'texto'    => $TR_ESTADOS_MAP[$e] ?? ('ESTADO '.$e),
    'badge'    => $TR_ESTILOS[$e] ?? 'secondary',
    'cantidad' => (int)$c
  ];
}

echo json_encode([
  'ok'            => true,
  'capacitacion'  => $cap_det,
  'inscritos'     => $list,
  'resumen'       => [
    'total'      => (int)$total,
    'por_estado' => $por_estado
  ]
], JSON_UNESCAPED_UNICODE);
