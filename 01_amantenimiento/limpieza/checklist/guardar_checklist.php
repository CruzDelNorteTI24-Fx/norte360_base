<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../../../.c0nn3ct/db_securebd2.php");
require_once __DIR__ . "/../../checklist_versiones.php";
require_once __DIR__ . "/../../checklist_evidencia_lib.php";

date_default_timezone_set('America/Lima');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $bus = (int)($_POST['bus'] ?? 0);
  $responsable = trim((string)($_POST['responsable'] ?? ''));
  $observaciones = trim((string)($_POST['observaciones'] ?? ''));
  $id_tipo_checklist = (int)($_POST['id_tipo_checklist'] ?? 0);
  $id_registra = (int)($_SESSION['id_usuario'] ?? 0);
  $fecha = trim((string)($_POST['fecha_seleccionada'] ?? ''));
  $redirect_form = '../mantcdn.php?id_tipo=' . max(1, $id_tipo_checklist);

  try {
    if ($bus <= 0 || $responsable === '' || $observaciones === '' || $id_tipo_checklist <= 0 || $id_registra <= 0) {
      throw new RuntimeException('Completa los datos obligatorios del checklist.');
    }
    $evidencia = n360_checklist_evidence_parse_upload($_FILES, 'evidencia_checklist', false);

  $marca_tiempo_registro = time();
  $hora = date('H:i:s', $marca_tiempo_registro);
  $fecha_hora_registro = date('Y-m-d H:i:s', $marca_tiempo_registro);
      
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
    case 5:
      $corrtipocheck = 'OPR';
      break;
    case 6:
      $corrtipocheck = 'LSM';
      break;
    }


  $hoy = date('Y-m-d', $marca_tiempo_registro);
  if ($fecha != $hoy) {
    $_SESSION['error_fecha'] = true;
    header("Location: " . $redirect_form);
    exit();
  }

  // OBTENER servicio de la placa (clm_placas_servicio) y BUS
  $stmt_bus = $conn->prepare("SELECT clm_placas_servicio, clm_placas_BUS FROM tb_placas WHERE clm_placas_id = ?");
  $stmt_bus->bind_param("i", $bus);
  $stmt_bus->execute();
  $stmt_bus->bind_result($servicio, $bus_nombre);
  if (!$stmt_bus->fetch()) {
    $stmt_bus->close();
    throw new RuntimeException('La unidad seleccionada no existe.');
  }
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
  $id_version_checklist = n360_cv_current_version_id($conn, $id_tipo_checklist, $fecha);
  $conn->begin_transaction();
  if ($id_version_checklist !== null && n360_cv_checklist_version_ready($conn)) {
    $stmt = $conn->prepare("INSERT INTO tb_checklist_limpieza (clm_checklist_id_bus, clm_checklist_fecha, clm_checklist_hora, clm_checklist_fechahoraregistro, clm_checklist_responsable, clm_checklist_idpersonaregistra, clm_checklist_observaciones, clm_checklist_corr, clm_checklist_idtipo, clm_checklist_idversion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssissii", $bus, $fecha, $hora, $fecha_hora_registro, $responsable, $id_registra, $observaciones, $clm_corr, $id_tipo_checklist, $id_version_checklist);
  } else {
    $stmt = $conn->prepare("INSERT INTO tb_checklist_limpieza (clm_checklist_id_bus, clm_checklist_fecha, clm_checklist_hora, clm_checklist_fechahoraregistro, clm_checklist_responsable, clm_checklist_idpersonaregistra, clm_checklist_observaciones, clm_checklist_corr, clm_checklist_idtipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssissi", $bus, $fecha, $hora, $fecha_hora_registro, $responsable, $id_registra, $observaciones, $clm_corr, $id_tipo_checklist);
  }
  if (!$stmt || !$stmt->execute()) {
    throw new RuntimeException('No se pudo crear el checklist.');
  }

  // Obtener el ID insertado
  $id_checklist = $stmt->insert_id;
  $stmt->close();

  if ($evidencia !== null) {
    n360_checklist_evidence_store($conn, $id_checklist, $id_registra, $evidencia);
  }

  $conn->commit();
  $_SESSION['exito'] = true;

  // Redireccionar directamente a la vista del checklist
  header("Location: ../../ver_checklist.php?id=" . urlencode($id_checklist));
  exit();
  } catch (Throwable $error) {
    try {
      $conn->rollback();
    } catch (Throwable $ignored) {
    }
    $_SESSION['error_checklist'] = $error->getMessage();
    header("Location: " . $redirect_form);
    exit();
  }
}
?>
