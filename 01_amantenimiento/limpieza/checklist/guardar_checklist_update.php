<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../../.c0nn3ct/db_securebd2.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $id_checklist = $_POST['id_checklist'];

  $all_items = array_merge(array_keys($_POST), array_keys($_FILES));


  foreach ($all_items as $key) {
    if (strpos($key, 'item_') === 0) {
      $fecha_hora = $_POST['fecha_hora'];
      $id_item = str_replace('item_', '', $key);
      $estado = $_POST[$key] ?? ($_FILES[$key] ?? null);

      // Obtiene los nombres de los inputs adicionales en POST
      $obs_key = 'obs_' . $id_item;
      $user_key = 'user_' . $id_item;

      $obs = $_POST[$obs_key] ?? '';
      $id_usuario = $_POST[$user_key] ?? 0;

      // Obtener el tipo de ítem desde la base de datos
      $stmt_tipo = $conn->prepare("SELECT clm_items_tipo FROM tb_items_checklist WHERE clm_item_id=?");
      $stmt_tipo->bind_param("i", $id_item);
      $stmt_tipo->execute();
      $stmt_tipo->bind_result($tipo_item);
      $stmt_tipo->fetch();
      $stmt_tipo->close();

      // Asigna la columna y el valor correspondiente según tipo
      $columna = '';
      $valor_final = null;

      switch ($tipo_item) {
        case 'R':
          $columna = 'clm_resultado_estado';
          $valor_final = $_POST[$key] ?? null;
          break;
        case 'E':
          $columna = 'clm_resultado_estado';
          $valor_final = $_POST[$key] ?? null;
          break;
        case 'Q':
          $columna = 'clm_resultado_estado';
          $valor_final = $_POST[$key] ?? null;
          break;
        case 'T':
          $columna = 'clm_rescheck_conductor1';
          $valor_final = $_POST[$key] ?? null;
          break;
        case 'O':
          $columna = 'clm_rescheck_conductor1';
          $valor_final = $_POST[$key] ?? null;
          break;
        case 'N':
          $columna = 'clm_rescheck_porcentaje1';
          $valor_final = $_POST[$key] ?? null;
          break;
        case 'H':
          $columna = 'clm_resultado_dfecd';
          $valor_final = date('Y-m-d H:i:s', strtotime($_POST[$key]));
          break;
        case 'F':
          $columna = 'clm_rescheck_imagen';
          if (isset($_FILES[$key]) && $_FILES[$key]['error'] == 0) {
            $valor_final = file_get_contents($_FILES[$key]['tmp_name']);
          }
          break;
        case 'D':
          $columna = 'clm_rescheck_doc';
          if (isset($_FILES[$key]) && $_FILES[$key]['error'] == 0) {
            $valor_final = file_get_contents($_FILES[$key]['tmp_name']);
          }
          break;
      }

      // Verificar si ya existe resultado
      $stmt_check = $conn->prepare("SELECT clm_resultado_id FROM tb_resultados_checklist WHERE clm_resultado_id_checklist=? AND clm_resultado_id_item=?");
      $stmt_check->bind_param("ii", $id_checklist, $id_item);
      $stmt_check->execute();
      $stmt_check->store_result();

      if ($stmt_check->num_rows > 0) {
        // Update
        $stmt_update = $conn->prepare("UPDATE tb_resultados_checklist SET $columna=?, clm_resultados_obs=?, clm_resultados_id_user=?, clm_resultado_fecharegistro=? WHERE clm_resultado_id_checklist=? AND clm_resultado_id_item=?");
        $stmt_update->bind_param("ssissi", $valor_final, $obs, $id_usuario, $fecha_hora, $id_checklist, $id_item);
        $stmt_update->execute();
        $stmt_update->close();

      } else {
        // Insert en resultados
        $stmt_insert = $conn->prepare("INSERT INTO tb_resultados_checklist (clm_resultado_id_checklist, clm_resultado_id_item, $columna, clm_resultados_obs, clm_resultados_id_user, clm_resultado_fecharegistro) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_insert->bind_param("iissis", $id_checklist, $id_item, $valor_final, $obs, $id_usuario, $fecha_hora);
        $stmt_insert->execute();
        $stmt_insert->close();
      }
      $stmt_check->close();
    }
  }

  $_SESSION['exito'] = true;
  header("Location: ../../lista_cheklist.php");
  exit();
}
?>
