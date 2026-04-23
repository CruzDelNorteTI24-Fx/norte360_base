<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $bus = $_POST['bus'];
  $responsable = $_POST['responsable'];
  $observaciones = $_POST['observaciones'];
  $id_registra = $_SESSION['id_usuario'];
  $fecha = $_POST['fecha_seleccionada'];
  $hora = date('H:i:s');

  $hoy = date('Y-m-d');
  if ($fecha != $hoy) {
    $_SESSION['error_fecha'] = true;
    header("Location: ../mantcdn.php");
    exit();
  }

  // Insertar en tb_checklist_limpieza
  $stmt = $conn->prepare("INSERT INTO tb_checklist_limpieza (clm_checklist_id_bus, clm_checklist_fecha, clm_checklist_hora, clm_checklist_responsable, clm_checklist_idpersonaregistra, clm_checklist_observaciones) VALUES (?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("isssis", $bus, $fecha, $hora, $responsable, $id_registra, $observaciones);
  $stmt->execute();
  $id_checklist = $stmt->insert_id;

  // Guardar cada resultado
  foreach ($_POST as $key => $value) {
    if (strpos($key, 'item_') === 0) {
      $id_item = str_replace('item_', '', $key);
      $estado = $value;

      $stmt_item = $conn->prepare("INSERT INTO tb_resultados_checklist (clm_resultado_id_checklist, clm_resultado_id_item, clm_resultado_estado) VALUES (?, ?, ?)");
      $stmt_item->bind_param("iis", $id_checklist, $id_item, $estado);
      $stmt_item->execute();
    }
  }

  $_SESSION['exito'] = true;
  header("Location: ../mantcdn.php");
  exit();
}
?>
