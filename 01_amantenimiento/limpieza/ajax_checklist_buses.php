<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../trash/copidb_secure.php");
require_once("../../.c0nn3ct/db_securebd2.php");

$fecha = $_GET['fecha'] ?? date('Y-m-d');
$excluir_placas = ['0'];

// Obtener lista de buses visibles según el rol
$placeholders = implode(',', array_fill(0, count($excluir_placas), '?'));
$sql = ($_SESSION['web_rol'] == 'Admin') 
    ? "SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS FROM tb_placas WHERE clm_placas_placa NOT IN ($placeholders) AND clm_placas_estado = 'Activo' AND clm_placas_TIPO_VEHÍCULO <> 'CAMIONETA' ORDER BY clm_placas_BUS"
    : "SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS FROM tb_placas WHERE clm_placas_placa NOT IN ($placeholders) AND clm_placas_estado = 'Activo' AND clm_placas_TIPO_VEHÍCULO <> 'CAMIONETA' ORDER BY clm_placas_BUS";

$stmt = $conn->prepare($sql);
$types = str_repeat('s', count($excluir_placas));
$stmt->bind_param($types, ...$excluir_placas);
$stmt->execute();
$result = $stmt->get_result();

// Crear array con buses
$buses = [];
$ids_buses = [];
while ($row = $result->fetch_assoc()) {
    $buses[] = $row;
    $ids_buses[] = $row['clm_placas_id'];
}

// Verificar cuáles tienen checklist en esa fecha
$checklist_ids = [];
if (!empty($ids_buses)) {
    $placeholders_bus = implode(',', array_fill(0, count($ids_buses), '?'));
    $sql_check = "SELECT clm_checklist_id_bus FROM tb_checklist_limpieza WHERE clm_checklist_fecha = ? AND clm_checklist_id_bus IN ($placeholders_bus)";
    $stmt_check = $conn->prepare($sql_check);

    $types_check = 's' . str_repeat('i', count($ids_buses));
    $stmt_check->bind_param($types_check, $fecha, ...$ids_buses);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    while ($row = $result_check->fetch_assoc()) {
        $checklist_ids[] = $row['clm_checklist_id_bus'];
    }
}

// Construir HTML
$html = "";
foreach ($buses as $bus) {
    $id_bus = $bus['clm_placas_id'];
    $placa = htmlspecialchars($bus['clm_placas_placa']);
    $nombrebus = htmlspecialchars($bus['clm_placas_BUS']);

    if (in_array($id_bus, $checklist_ids)) {
        $color = '#27ae60'; // verde
        $estado = "✔ Generado";
    } else {
        $color = 'gray'; // gris
        $estado = "✖ No generado";
    }

    $html .= "
    <div class='bus-card' data-id='$id_bus' data-estado='$estado' onclick=\"seleccionarBus('$id_bus', '$estado')\" style=\"
      flex: 1 1 150px;
      background: $color;
      color: white;
      padding: 10px;
      border-radius: 8px;
      text-align: center;
      cursor: pointer;
      transition: transform 0.2s;
    \">
      <strong>$nombrebus ($placa)</strong><br>$estado
    </div>";
}

echo $html;
?>
