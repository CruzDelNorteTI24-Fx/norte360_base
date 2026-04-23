<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../../.c0nn3ct/db_securebd2.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {    
  $bus = $_POST['bus'];
  $responsable = $_POST['responsable'];
  $observaciones = $_POST['observaciones'];
  $id_tipo_checklist = $_POST['id_tipo_checklist'];
  $id_registra = $_SESSION['id_usuario'];
  $fecha = $_POST['fecha_seleccionada'];
  $hora = date('H:i:s');
      
  $corrtipocheck = null;

  switch ($id_tipo_checklist) {
    case 1:
      $corrtipocheck = 'LMP';
      break;
    case 2:
      $corrtipocheck = 'EMB';
      break;
    case 3:
      $corrtipocheck = 'ALC';
      break;
    case 4:
      $corrtipocheck = 'FMG';
      break;
    }


  $hoy = date('Y-m-d');
  if ($fecha != $hoy) {
    $_SESSION['error_fecha'] = true;
    header("Location: ../mantcdn.php");
    exit();
  }

  // OBTENER servicio de la placa (clm_placas_servicio) y BUS
  $stmt_bus = $conn->prepare("SELECT clm_placas_servicio, clm_placas_BUS FROM tb_placas WHERE clm_placas_id = ?");
  $stmt_bus->bind_param("i", $bus);
  $stmt_bus->execute();
  $stmt_bus->bind_result($servicio, $bus_nombre);
  $stmt_bus->fetch();
  $stmt_bus->close();

  // PROCESAR LETRA según reglas
  $letra = '';

  if (strtoupper($servicio) === 'PRIMERA-CLASE') {
      $letra = 'PR';
  } else {
      $palabras = explode('-', $servicio);
      if (count($palabras) > 1) {
          foreach ($palabras as $p) {
              $letra .= strtoupper(substr($p, 0, 1));
          }
      } else {
          $letra = strtoupper(substr($palabras[0], 0, 2));
      }
  }

  // OBTENER cantidad de checklist existentes de ese bus
  $stmt_cont = $conn->prepare("SELECT COUNT(*) FROM tb_checklist_limpieza WHERE clm_checklist_id_bus = ? AND clm_checklist_idtipo = ?");
  $stmt_cont->bind_param("ii", $bus, $id_tipo_checklist);
  $stmt_cont->execute();
  $stmt_cont->bind_result($count_check);
  $stmt_cont->fetch();
  $stmt_cont->close();

  // GENERAR correlativo con +1 y formato de 5 ceros
  $correlativo = str_pad($count_check + 1, 5, '0', STR_PAD_LEFT);

  // CONCATENAR todo
  $clm_corr = $letra . '-' . $bus_nombre . '-' . $corrtipocheck . '-' . $correlativo;

  // Insertar en tb_checklist_limpieza
  $stmt = $conn->prepare("INSERT INTO tb_checklist_limpieza (clm_checklist_id_bus, clm_checklist_fecha, clm_checklist_hora, clm_checklist_responsable, clm_checklist_idpersonaregistra, clm_checklist_observaciones, clm_checklist_corr, clm_checklist_idtipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("isssissi", $bus, $fecha, $hora, $responsable, $id_registra, $observaciones, $clm_corr, $id_tipo_checklist);
  $stmt->execute();

  $_SESSION['exito'] = true;

  // Obtener el ID insertado
  $id_checklist = $stmt->insert_id;

  // Redireccionar directamente a la vista del checklist
  header("Location: ../../ver_checklist.php?id=" . urlencode($id_checklist));
  exit();
}
?>
