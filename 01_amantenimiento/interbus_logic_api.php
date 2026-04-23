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
require_once("../.c0nn3ct/db_securebd2.php");

$data = [];

if (isset($_GET['bus_id'])) {
    $bus_id = intval($_GET['bus_id']);
    $fecha_actual = isset($_GET['fecha']) ? $_GET['fecha'] : null;

    $stmt_bus = $conn->prepare("SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS, clm_placas_TIPO_VEHÍCULO, clm_placas_SERVICIO FROM tb_placas WHERE clm_placas_id = ?");
    $stmt_bus->bind_param("i", $bus_id);
    $stmt_bus->execute();
    $res_bus = $stmt_bus->get_result();

    if ($res_bus->num_rows > 0) {
        $bus_row = $res_bus->fetch_assoc();
        $data['bus'] = $bus_row;
        $data['fecha'] = date('Y-m-d');
        $data['hora'] = date('H:i');

        if ($fecha_actual) {
            $tipos = [];
            $stmt_tipos = $conn->prepare("
                SELECT DISTINCT t.clm_checktip_nombre, l.clm_checklist_idtipo
                FROM tb_checklist_limpieza l
                INNER JOIN tb_checklist_tipos t ON l.clm_checklist_idtipo = t.clm_checktip_id
                WHERE l.clm_checklist_id_bus = ? AND l.clm_checklist_fecha = ?
                ORDER BY t.clm_checktip_nombre ASC
            ");
            $stmt_tipos->bind_param("is", $bus_id, $fecha_actual);
            $stmt_tipos->execute();
            $res_tipos = $stmt_tipos->get_result();

            while ($tipo_row = $res_tipos->fetch_assoc()) {
                $tipo_id = intval($tipo_row['clm_checklist_idtipo']);
                $tipo_nombre = $tipo_row['clm_checktip_nombre'];

                // Completitud
                $stmt_items = $conn->prepare("
                    SELECT COUNT(*) as total, 
                           SUM(r.clm_resultado_estado IS NOT NULL) as respondidos
                    FROM tb_items_checklist i
                    LEFT JOIN tb_resultados_checklist r 
                           ON i.clm_item_id = r.clm_resultado_id_item
                          AND r.clm_resultado_id_checklist IN (
                                SELECT clm_checklist_id
                                FROM tb_checklist_limpieza
                                WHERE clm_checklist_id_bus = ? 
                                  AND clm_checklist_fecha = ? 
                                  AND clm_checklist_idtipo = ?
                          )
                    INNER JOIN tb_categorias_checklist c
                            ON i.clm_item_id_categoria = c.clm_categoria_id
                    WHERE i.clm_item_estado = 'activo'
                      AND c.clm_categorias_estado = 'activo'
                ");
                $stmt_items->bind_param("isi", $bus_id, $fecha_actual, $tipo_id);
                $stmt_items->execute();
                $res_items = $stmt_items->get_result()->fetch_assoc();
                $stmt_items->close();

                $total = $res_items['total'];
                $respondidos = $res_items['respondidos'];
                $completitud = ($total == $respondidos && $total > 0) ? "Completo" : "Incompleto";

                // Checklists
                $checklists = [];
                $stmt_chk = $conn->prepare("
                    SELECT clm_checklist_id, clm_checklist_fecha, clm_checklist_hora, clm_checklist_estado, clm_checklist_corr 
                    FROM tb_checklist_limpieza 
                    WHERE clm_checklist_id_bus = ? AND clm_checklist_fecha = ? AND clm_checklist_idtipo = ?
                    ORDER BY clm_checklist_fecha DESC, clm_checklist_hora DESC
                ");
                $stmt_chk->bind_param("iss", $bus_id, $fecha_actual, $tipo_id);
                $stmt_chk->execute();
                $res_chk = $stmt_chk->get_result();

                while ($chk = $res_chk->fetch_assoc()) {
                    $checklists[] = $chk;
                }

                $tipos[] = [
                    "nombre" => $tipo_nombre,
                    "completitud" => $completitud,
                    "total" => $total,
                    "respondidos" => $respondidos,
                    "checklists" => $checklists
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

echo json_encode($data);
?>
