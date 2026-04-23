<?php
if (session_status() === PHP_SESSION_NONE) {    session_start();}
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
if (!defined('ACCESS_GRANTED')) {
    define('ACCESS_GRANTED', true);
}
// Conexión a tu base de datos
require_once("../../.c0nn3ct/db_securebd2.php");

// Recoge los datos del formulario (o JS, ajusta según tu flujo)
// Ajusta la zona a la tuya (ej: Lima)
date_default_timezone_set('America/Lima');

$now   = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
$fecha = $now->format('Y-m-d');   // 2025-10-06
$hora  = $now->format('H:i:s');   // 20:31:45

$busId = $_POST['id_vehiculo'];
$origen = $_POST['origen'];
$destino = $_POST['destino'];
$conductor1 = $_POST['conductor1'];
$conductor2 = $_POST['conductor2'];
$observaciones = $_POST['observaciones'];
$transbordo = $_POST['transbordo'];
$checklistIds = $_POST['checklist_ids']; // asume JSON
$checklistKpis = $_POST['checklist_kpis']; // asume JSON
$usuario_id = $_POST['usuario_id'];

// 🔴 Validar que origen y destino no sean nulos
if (empty($origen) || empty($destino)) {
    die("Error: Debe seleccionar origen y destino.");
}

// 🔃 Convierte a JSON si es array
if (is_array($checklistIds)) {
    $checklistIds = json_encode($checklistIds, JSON_UNESCAPED_UNICODE);
}
if (is_array($checklistKpis)) {
    $checklistKpis = json_encode($checklistKpis, JSON_UNESCAPED_UNICODE);
}

// ✅ Prepara la consulta
$stmt = $conn->prepare("INSERT INTO tb_viajesruta
(clm_viarut_fecha, clm_viarut_hora, clm_viarut_idplaca, clm_viarut_origen, clm_viarut_destino,
clm_viarut_conductor1, clm_viarut_conductor2, clm_viarut_observaciones, clm_viarut_trasbordo,
clm_viarut_idchecklist_considerados, clm_viarut_resultados, clm_viarut_idusuarioregistra)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param("ssissssssssi",
    $fecha,
    $hora,
    $busId,
    $origen,
    $destino,
    $conductor1,
    $conductor2,
    $observaciones,
    $transbordo,
    $checklistIds,
    $checklistKpis,
    $usuario_id
);

if ($stmt->execute()) {
    echo "✅ Viaje registrado correctamente.";
} else {
    echo "❌ Error al registrar viaje: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>