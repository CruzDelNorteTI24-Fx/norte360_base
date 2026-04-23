<?php
session_start();
if (!isset($_SESSION['usuario'])) { exit; }

define('ACCESS_GRANTED', true);
require_once("../trash/copidb_secure.php");
require_once("../.c0nn3ct/db_securebd2.php");

$query = isset($_GET['query']) ? $_GET['query'] : '';
$query = "%$query%";

$stmt = $conn->prepare("SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS FROM tb_placas WHERE clm_placas_placa LIKE ? OR clm_placas_BUS LIKE ? LIMIT 10");
$stmt->bind_param("ss", $query, $query);
$stmt->execute();
$result = $stmt->get_result();

$buses = [];
while ($row = $result->fetch_assoc()) {
    $buses[] = $row;
}

header('Content-Type: application/json');
echo json_encode($buses);
?>
