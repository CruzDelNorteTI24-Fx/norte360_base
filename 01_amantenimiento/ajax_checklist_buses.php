<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../trash/copidb_secure.php");
require_once("../.c0nn3ct/db_securebd2.php");

$fecha = $_GET['fecha'] ?? date('Y-m-d');
$rol = $_SESSION['web_rol'];
$excluir_placas = ['H2U-919', '0'];

if ($rol == 'Admin') {
    $sql = "SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS FROM tb_placas ORDER BY clm_placas_BUS";
    $stmt = $conn->prepare($sql);
} else {
    $placeholders = implode(',', array_fill(0, count($excluir_placas), '?'));
    $sql = "SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS FROM tb_placas WHERE clm_placas_placa NOT IN ($placeholders) ORDER BY clm_placas_BUS";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($excluir_placas));
    $stmt->bind_param($types, ...$excluir_placas);
}

$stmt->execute();
$buses = $stmt->get_result();

$html = "";

while ($bus = $buses->fetch_assoc()) {
    $id_bus = $bus['clm_placas_id'];
    $placa = htmlspecialchars($bus['clm_placas_placa']);
    $nombrebus = htmlspecialchars($bus['clm_placas_BUS']);

    // Verificar si ya tiene checklist en esa fecha
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM tb_checklist_limpieza WHERE clm_checklist_id_bus=? AND clm_checklist_fecha=?");
    $stmt_check->bind_param("is", $id_bus, $fecha);
    $stmt_check->execute();
    $stmt_check->bind_result($count);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($count > 0) {
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
