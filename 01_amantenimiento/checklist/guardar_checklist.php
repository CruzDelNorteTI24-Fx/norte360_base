<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");
require_once __DIR__ . "/../checklist_versiones.php";

date_default_timezone_set('America/Lima');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $bus = $_POST['bus'];
  $responsable = $_POST['responsable'];
  $observaciones = $_POST['observaciones'];
  $id_tipo_checklist = (int)($_POST['id_tipo_checklist'] ?? 1);
  $id_registra = $_SESSION['id_usuario'];
  $fecha = $_POST['fecha_seleccionada'];
  $marca_tiempo_registro = time();
  $hora = date('H:i:s', $marca_tiempo_registro);
  $fecha_hora_registro = date('Y-m-d H:i:s', $marca_tiempo_registro);

  $hoy = date('Y-m-d', $marca_tiempo_registro);
  if ($fecha != $hoy) {
    $_SESSION['error_fecha'] = true;
    header("Location: ../mantcdn.php");
    exit();
  }

  // Insertar en tb_checklist_limpieza
  $id_version_checklist = n360_cv_current_version_id($conn, $id_tipo_checklist, $fecha);
  if ($id_version_checklist !== null && n360_cv_checklist_version_ready($conn)) {
    $stmt = $conn->prepare("INSERT INTO tb_checklist_limpieza (clm_checklist_id_bus, clm_checklist_fecha, clm_checklist_hora, clm_checklist_fechahoraregistro, clm_checklist_responsable, clm_checklist_idpersonaregistra, clm_checklist_observaciones, clm_checklist_idtipo, clm_checklist_idversion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssisii", $bus, $fecha, $hora, $fecha_hora_registro, $responsable, $id_registra, $observaciones, $id_tipo_checklist, $id_version_checklist);
  } else {
    $stmt = $conn->prepare("INSERT INTO tb_checklist_limpieza (clm_checklist_id_bus, clm_checklist_fecha, clm_checklist_hora, clm_checklist_fechahoraregistro, clm_checklist_responsable, clm_checklist_idpersonaregistra, clm_checklist_observaciones, clm_checklist_idtipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssisi", $bus, $fecha, $hora, $fecha_hora_registro, $responsable, $id_registra, $observaciones, $id_tipo_checklist);
  }
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
