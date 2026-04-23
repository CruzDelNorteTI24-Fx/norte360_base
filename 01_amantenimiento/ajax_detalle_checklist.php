<?php
if (session_status() === PHP_SESSION_NONE) {    session_start();}
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}


if (!defined('ACCESS_GRANTED')) {
    define('ACCESS_GRANTED', true);
}

require_once("../.c0nn3ct/db_securebd2.php");
require_once("inter_bus/interbus_model.php");

$fecha = $_GET['fecha'] ?? null;
if (!$fecha) {
    echo "<p>Fecha no válida.</p>";
    exit;
}

$stmt = $conn->prepare("SELECT DISTINCT clm_checklist_id_bus FROM tb_checklist_limpieza WHERE clm_checklist_fecha = ?");
$stmt->bind_param("s", $fecha);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    echo "<p style='text-align:center; font-weight:bold; color:gray;'>No se encontraron checklists para esta fecha.</p>";
    exit;
}

echo "<div style='display: flex; flex-wrap: wrap; gap: 20px; justify-content: center;'>";

while ($row = $res->fetch_assoc()) {
    $bus_id = $row['clm_checklist_id_bus'];
    $placa_info = obtenerDatosBus($conn, $bus_id);
    $tipos = obtenerTiposChecklist($conn, $bus_id, $fecha);

    while ($t = $tipos->fetch_assoc()) {
        $tipo_id = $t['clm_checklist_idtipo'];
        $nombre_tipo = $t['clm_checktip_nombre'];
        $detalle = obtenerDetallesChecklist($conn, $bus_id, $fecha, $tipo_id);

        echo "<div style='background:#f8f9fa; border-left:6px solid #2980b9; border-radius:10px; padding:20px; box-shadow:0 4px 10px rgba(0,0,0,0.05); max-width:400px; flex:1;'>
            <h4 style='margin:0 0 8px 0; color:#2c3e50;'>{$placa_info['clm_placas_BUS']} - {$placa_info['clm_placas_placa']}</h4>
            <p style='margin:2px 0;'><strong>Tipo:</strong> $nombre_tipo</p>
            <p style='margin:2px 0;'><strong>Responsable:</strong> {$detalle['clm_checklist_responsable']}</p>
            <p style='margin:2px 0;'><strong>Observaciones:</strong><br> <span style='color:#555'>{$detalle['clm_checklist_observaciones']}</span></p>
            <p style='margin:2px 0; font-size:13px; color:#888;'>⏱ Fecha: $fecha</p>
        </div>";
    }
}

echo "</div>";
?>