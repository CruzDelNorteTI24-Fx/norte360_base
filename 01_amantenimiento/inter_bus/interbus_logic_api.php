<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["error" => "No autorizado"]);
    exit();
}

header('Content-Type: application/json');
if (!defined('ACCESS_GRANTED')) {
    define('ACCESS_GRANTED', true);
}
require_once(__DIR__ . "/../../.c0nn3ct/db_securebd2.php");
require_once(__DIR__ . "/interbus_model.php");



$data = [];
if (isset($_GET['bus_id'])) {
    $bus_id = intval($_GET['bus_id']);
    $fecha_actual = isset($_GET['fecha']) ? $_GET['fecha'] : null;

    $bus_row = obtenerDatosBus($conn, $bus_id);


    if ($bus_row) {
        $data['bus'] = $bus_row;
        $data['fecha'] = date('Y-m-d');
        $data['hora'] = date('H:i');
        // Comparar si la fecha es pasada
        $es_fecha_pasada = ($fecha_actual < date('Y-m-d'));
        $data['es_fecha_pasada'] = $es_fecha_pasada;

        if ($es_fecha_pasada) {
            $viajes = [];
            $res_viajes = obtenerViajesPorFecha($conn, $bus_id, $fecha_actual);
            while ($row = $res_viajes->fetch_assoc()) {
                $viajes[] = $row;
            }
            $data['viajes_realizados'] = $viajes;
        }

        if ($fecha_actual) {
            $tipos = [];
            $res_tipos = obtenerTiposChecklist($conn, $bus_id, $fecha_actual);
            $tipos_existentes = [];
            $tipo_4_presente = false;

            while ($tipo_row = $res_tipos->fetch_assoc()) {
                $tipos_existentes[] = $tipo_row;
                if (intval($tipo_row['clm_checklist_idtipo']) === 4) {
                    $tipo_4_presente = true;
                }
            }

            if (!$tipo_4_presente) {
                $tipos_existentes[] = [
                    'clm_checktip_nombre' => 'Fumigación',
                    'clm_checklist_idtipo' => 4
                ];
            }

            foreach ($tipos_existentes as $tipo_row) {
                $tipo_nombre = $tipo_row['clm_checktip_nombre'];
                $tipo_id = intval($tipo_row['clm_checklist_idtipo']);

                $res_items = obtenerCompletitud($conn, $bus_id, $fecha_actual, $tipo_id);
                $total = $res_items['total'];
                $respondidos = $res_items['respondidos'];
                $completitud = ($total == $respondidos && $total > 0) ? "Completo" : "Incompleto";

                $checklists = [];
                $res_chk = obtenerChecklists($conn, $bus_id, $fecha_actual, $tipo_id);
                while ($chk = $res_chk->fetch_assoc()) {
                    $checklists[] = $chk;
                }

                // 👇 Si tipo 4 y no hay checklists del día, agregar un "checklist virtual" con datos del último
                if (empty($checklists) && $tipo_id === 4) {
                    $ultimo = obtenerUltimoChecklistDetallesTipo4($conn, $bus_id);

                    if ($ultimo) {
                        $checklists[] = [
                            "clm_checklist_id" => $ultimo['clm_checklist_id'] ?? 0,
                            "clm_checklist_corr" => $ultimo['clm_checklist_corr'] ?? 'Sin número',
                            "clm_checklist_fecha" => $ultimo['clm_checklist_fecha'] ?? 'Sin fecha',
                            "clm_checklist_hora" => $ultimo['clm_checklist_hora'] ?? 'Sin hora',
                            "clm_checklist_estado" => $ultimo['clm_checklist_estado'] ?? 'Sin estado',
                        ];
                    }
                }


                $kpi = null;
                $detalles = null;

                if (!empty($checklists)) {
                    $first_chk_id = $checklists[0]['clm_checklist_id'];

                    $kpi = obtenerKPIChecklist($conn, $first_chk_id, $tipo_id, $bus_id);

                    // 👇 Si es checklist virtual (tipo 4 con datos antiguos)
                    if ($tipo_id === 4 && $checklists[0]['clm_checklist_fecha'] !== $fecha_actual) {
                        $detalles = obtenerUltimoChecklistDetallesTipo4($conn, $bus_id);
                    } else {
                        $detalles = obtenerDetallesChecklist($conn, $bus_id, $fecha_actual, $tipo_id);
                    }
                } else {
                    if ($tipo_id === 4) {
                        $kpi = obtenerKPIChecklist($conn, 0, 4, $bus_id);
                        $detalles = obtenerUltimoChecklistDetallesTipo4($conn, $bus_id) ?? [];
                    }
                }


                $tipos[] = [
                    "nombre" => $tipo_nombre,
                    "completitud" => $completitud,
                    "total" => $total,
                    "respondidos" => $respondidos,
                    "responsable" => $detalles['clm_checklist_responsable'] ?? 'None',
                    "observaciones" => $detalles['clm_checklist_observaciones'] ?? 'Sin observaciones',
                    "fecha" => $detalles['clm_checklist_fecha'] ?? 'Sin fecha',
                    "hora" => $detalles['clm_checklist_hora'] ?? 'Sin hora',
                    "estado" => $detalles['clm_checklist_estado'] ?? 'Sin estado',
                    "correlativo" => $detalles['clm_checklist_corr'] ?? 'Sin número',
                    "checklists" => $checklists,
                    "kpi" => $kpi
                ];
            }

            $data['tipos'] = $tipos;
        }
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Bus no encontrado"]);
        exit();
    }
}

if (!empty($data)) {
    echo json_encode($data);
} else {
    http_response_code(400);
    echo json_encode(["error" => "Parámetros insuficientes"]);
}

?>
