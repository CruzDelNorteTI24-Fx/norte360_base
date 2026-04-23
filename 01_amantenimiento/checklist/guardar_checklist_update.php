<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $id_checklist = $_POST['id_checklist'];

  foreach ($_POST as $key => $value) {
    if (strpos($key, 'item_') === 0) {
      $id_item = str_replace('item_', '', $key);
      $estado = $value;

      // Verificar si ya existe resultado
      $stmt_check = $conn->prepare("SELECT clm_resultado_id FROM tb_resultados_checklist WHERE clm_resultado_id_checklist=? AND clm_resultado_id_item=?");
      $stmt_check->bind_param("ii", $id_checklist, $id_item);
      $stmt_check->execute();
      $stmt_check->store_result();

      if ($stmt_check->num_rows > 0) {
        // Update
        $stmt_update = $conn->prepare("UPDATE tb_resultados_checklist SET clm_resultado_estado=? WHERE clm_resultado_id_checklist=? AND clm_resultado_id_item=?");
        $stmt_update->bind_param("sii", $estado, $id_checklist, $id_item);
        $stmt_update->execute();
      } else {
        // Insert
        $stmt_insert = $conn->prepare("INSERT INTO tb_resultados_checklist (clm_resultado_id_checklist, clm_resultado_id_item, clm_resultado_estado) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("iis", $id_checklist, $id_item, $estado);
        $stmt_insert->execute();
      }
    }
  }

  $_SESSION['exito'] = true;
  header("Location: ../lista_cheklist.php");
  exit();
}
?>
