<?php
if (!defined('ACCESS_GRANTED')) {
    define('ACCESS_GRANTED', true);
}

require_once(__DIR__ . "/../../.c0nn3ct/db_securebd2.php");

//Obtener los datos básicos de un bus específico a partir de su ID.
//Mostrar la información del bus en la interfaz de mantenimiento o checklist.


function obtenerDatosBus($conn, $bus_id) {
    $stmt = $conn->prepare("SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS, clm_placas_TIPO_VEHÍCULO, clm_placas_SERVICIO FROM tb_placas WHERE clm_placas_id = ?");
    $stmt->bind_param("i", $bus_id);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc();
}


//Obtener los tipos de checklist generados para un bus específico en una fecha determinada.
//--Nombre del tipo de checklist (clm_checktip_nombre)
//--ID del tipo de checklist (clm_checklist_idtipo)

function obtenerTiposChecklist($conn, $bus_id, $fecha_actual) {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.clm_checktip_nombre, l.clm_checklist_idtipo
        FROM tb_checklist_limpieza l
        INNER JOIN tb_checklist_tipos t ON l.clm_checklist_idtipo = t.clm_checktip_id
        WHERE l.clm_checklist_id_bus = ? AND l.clm_checklist_fecha = ?
        ORDER BY t.clm_checktip_nombre ASC
    ");
    $stmt->bind_param("is", $bus_id, $fecha_actual);
    $stmt->execute();
    return $stmt->get_result();
}


//Calcular la completitud de un checklist, es decir
//     Total de items en ese tipo de checklist.
//     Cuántos han sido respondidos.
//Campos que trae:
//  total   cantidad total de items activos en el tipo de checklist.
//  respondidos cantidad de items respondidos con estado no nulo.

function obtenerCompletitud($conn, $bus_id, $fecha_actual, $tipo_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total, 
            SUM(
                CASE 
                WHEN i.clm_items_tipo = 'R' THEN r.clm_resultado_estado IS NOT NULL
                WHEN i.clm_items_tipo = 'E' THEN r.clm_resultado_estado IS NOT NULL
                WHEN i.clm_items_tipo = 'Q' THEN r.clm_resultado_estado IS NOT NULL
                WHEN i.clm_items_tipo = 'H' THEN r.clm_resultado_dfecd IS NOT NULL
                WHEN i.clm_items_tipo = 'T' THEN r.clm_rescheck_conductor1 IS NOT NULL AND r.clm_rescheck_conductor1 != ''
                WHEN i.clm_items_tipo = 'O' THEN r.clm_rescheck_conductor1 IS NOT NULL AND r.clm_rescheck_conductor1 != ''
                WHEN i.clm_items_tipo = 'N' THEN r.clm_rescheck_porcentaje1 IS NOT NULL AND r.clm_rescheck_porcentaje1 != ''
                WHEN i.clm_items_tipo = 'F' THEN r.clm_rescheck_imagen IS NOT NULL AND r.clm_rescheck_imagen != ''
                ELSE 0
                END
            ) as respondidos
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
        AND i.clm_item_idtipocheck = ?
    ");
    $stmt->bind_param("isii", $bus_id, $fecha_actual, $tipo_id, $tipo_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}



// Obtener la lista de checklists generados para un bus, en una fecha y de un tipo específico.
//Mostrar los checklists disponibles para ese bus en un historial ordenado por fecha y hora.

function obtenerChecklists($conn, $bus_id, $fecha_actual, $tipo_id) {
    $stmt = $conn->prepare("
        SELECT clm_checklist_id, clm_checklist_fecha, clm_checklist_hora, clm_checklist_estado, clm_checklist_corr 
        FROM tb_checklist_limpieza 
        WHERE clm_checklist_id_bus = ? AND clm_checklist_fecha = ? AND clm_checklist_idtipo = ?
        ORDER BY clm_checklist_fecha DESC, clm_checklist_hora DESC
    ");
    $stmt->bind_param("iss", $bus_id, $fecha_actual, $tipo_id);
    $stmt->execute();
    return $stmt->get_result();
}

function obtenerDetallesChecklist($conn, $bus_id, $fecha_actual, $tipo_id) {
    $stmt = $conn->prepare("
        SELECT clm_checklist_responsable, clm_checklist_observaciones
        FROM tb_checklist_limpieza
        WHERE clm_checklist_id_bus = ? AND clm_checklist_fecha = ? AND clm_checklist_idtipo = ?
        LIMIT 1
    ");
    $stmt->bind_param("isi", $bus_id, $fecha_actual, $tipo_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function obtenerUltimoChecklistDetallesTipo4($conn, $bus_id) {
    $stmt = $conn->prepare("
        SELECT clm_checklist_id,clm_checklist_responsable, clm_checklist_observaciones, clm_checklist_fecha, clm_checklist_hora, clm_checklist_corr, clm_checklist_estado
        FROM tb_checklist_limpieza
        WHERE clm_checklist_idtipo = 4
        AND clm_checklist_id_bus = ?
        ORDER BY clm_checklist_fecha DESC, clm_checklist_hora DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $bus_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        return $res->fetch_assoc();
    } else {
        return [
            'clm_checklist_responsable' => 'No registrado',
            'clm_checklist_observaciones' => 'Sin observaciones',
            'clm_checklist_fecha' => 'Sin fecha',
            'clm_checklist_hora' => 'Sin hora',
            'clm_checklist_corr' => 'Sin número',
            'clm_checklist_estado' => 'Sin estado',
            'clm_checklist_id' => 0
        ];
    }
}


function obtenerKPIChecklist($conn, $checklist_id, $tipo_id, $bus_id = null) {
    // Reutiliza la lógica de ver_kpi_checklist.php
    $stmt_items = $conn->prepare("
        SELECT i.clm_item_id, i.clm_item_nombre, i.clm_items_tipo,
               r.clm_resultado_dfecd, r.clm_rescheck_conductor1, r.clm_resultado_estado, r.clm_rescheck_porcentaje1
        FROM tb_items_checklist i
        LEFT JOIN tb_resultados_checklist r
          ON i.clm_item_id = r.clm_resultado_id_item
         AND r.clm_resultado_id_checklist = ?
        WHERE i.clm_item_idtipocheck = ?
          AND i.clm_item_estado = 'activo'
    ");
    $stmt_items->bind_param("ii", $checklist_id, $tipo_id);
    $stmt_items->execute();
    $res_items = $stmt_items->get_result();

    $total_c = 0;
    $items = [];
    while ($row = $res_items->fetch_assoc()) {
        if ($row['clm_items_tipo'] == 'R' && $row['clm_resultado_estado'] == 'C') $total_c++;
        $items[] = $row;
    }

    $total_items = count($items);

    if ($tipo_id == 1) { // Limpieza
        $porcentaje_c = ($total_items > 0) ? ($total_c / $total_items) * 100 : 0;
        return [
            "titulo" => "Estado de Limpieza",
            "valor" => number_format($porcentaje_c, 2) . "%",
            "texto" => ($porcentaje_c > 70) ? "EXCELENTE" : (($porcentaje_c >= 50) ? "ACEPTABLE" : "DEFICIENTE")
        ];
    }

    if ($tipo_id == 2) { // SAB
        $porcentaje_cSAB = ($total_items > 0) ? ($total_c / $total_items) * 100 : 0;
        return [
            "titulo" => "Estado de Servicio a Bordo",
            "valor" => number_format($porcentaje_cSAB, 2) . "%",
            "texto" => ($porcentaje_cSAB > 70) ? "EXCELENTE" : (($porcentaje_cSAB >= 50) ? "ACEPTABLE" : "DEFICIENTE")
        ];
    }
    
    elseif ($tipo_id == 3) { // Alcoholímetro
        $kpi_conductores = [];
        $conductores = [];

        // Primero recorre para obtener los conductores
        foreach ($items as $item) {
            if ($item['clm_items_tipo'] == 'T') {
                $conductores[] = $item['clm_rescheck_conductor1'];
            }
        }

        // Ahora recorre los porcentajes y asigna cada uno a su conductor por índice
        $index = 0;
        foreach ($items as $item) {
            if ($item['clm_items_tipo'] == 'N') {
                $valor = floatval($item['clm_rescheck_porcentaje1']);
                $estado = ($valor > 0.5) ? "NO APTO" : "APTO";

                $kpi_conductores[] = [
                    "nombre" => $item['clm_item_nombre'],
                    "conductor" => $conductores[$index] ?? "Sin nombre",
                    "valor" => $valor,
                    "estado" => $estado
                ];

                $index++;
            }
        }

        return [
            "titulo" => "Resultados Alcoholímetro",
            "valor" => $kpi_conductores,
            "texto" => "RESULTADOS ALCOHOLÍMETRO POR CONDUCTOR"
        ];
    }

    if ($tipo_id == 4) { // SAB - Fumigación más actual Embarque

        if (!$bus_id) {
            return [
                "titulo" => "Última Fumigación Realizada",
                "valor" => "Bus ID no disponible",
                "texto" => "-"
            ];
        }

        $stmt_fumi = $conn->prepare("
            SELECT r.clm_resultado_dfecd
            FROM tb_resultados_checklist r
            INNER JOIN tb_items_checklist i ON r.clm_resultado_id_item = i.clm_item_id
            INNER JOIN tb_checklist_limpieza c ON r.clm_resultado_id_checklist = c.clm_checklist_id
            WHERE c.clm_checklist_id_bus = ?
            AND c.clm_checklist_idtipo = 4
            AND i.clm_items_tipo = 'H'
            AND r.clm_resultado_dfecd IS NOT NULL
            ORDER BY r.clm_resultado_dfecd DESC
            LIMIT 1
        ");

        $stmt_fumi->bind_param("i", $bus_id);
        $stmt_fumi->execute();
        $res_fumi = $stmt_fumi->get_result();
        $row_fumi = $res_fumi->fetch_assoc();

        if ($row_fumi && isset($row_fumi['clm_resultado_dfecd'])) {
            $fecha_fumigacion = new DateTime($row_fumi['clm_resultado_dfecd']);
            $hoy = new DateTime();

            // Compara solo la parte de la fecha (sin hora)
            $es_hoy = ($fecha_fumigacion->format('Y-m-d') === $hoy->format('Y-m-d'));

            $intervalo = $fecha_fumigacion->diff($hoy);
            $dias_transcurridos = $intervalo->days;

            $estado = ($dias_transcurridos <= 15) ? "APTO para viajar" : "NO APTO para viajar";

            if ($es_hoy) {
                $texto = "La fumigación fue realizada HOY. " . $estado;
            } else {
                $texto = "Ha pasado $dias_transcurridos día(s) desde la última fumigación. " . $estado;
            }

            return [
                "titulo" => "Última Fumigación Realizada",
                "valor" => $fecha_fumigacion->format('Y-m-d H:i:s'),
                "texto" => $texto
            ];
        }
    }


    else {
        return [
            "titulo" => "Sin KPI definido DESDE INTERBUSMODEL",
            "valor" => "-",
            "texto" => "Sin KPI para este tipo"
        ];
    }
}

function obtenerViajesPorFecha($conn, $bus_id, $fecha) {
    $stmt = $conn->prepare("
        SELECT 
            clm_viarut_idviaje, 
            clm_viarut_fecha, 
            clm_viarut_hora, 
            clm_viarut_origen, 
            clm_viarut_destino, 
            clm_viarut_conductor1, 
            clm_viarut_conductor2, 
            clm_viarut_estado
        FROM tb_viajesruta
        WHERE clm_viarut_idplaca = ? AND clm_viarut_fecha = ?
        ORDER BY clm_viarut_hora ASC
    ");
    $stmt->bind_param("is", $bus_id, $fecha);
    $stmt->execute();
    return $stmt->get_result();
}

function obtenerDatosConductores() {
    // Importa solo dentro de esta función la conexión a la base de datos correcta
    require(__DIR__ . '/../../.c0nn3ct/db_securebd2.php');

    $conn2 = $conexion; // alias para claridad, ya que en db_secure.php se usa $conexion

    $stmt = $conn2->prepare("
        SELECT clm_tra_id, clm_tra_nombres, clm_tra_dni
        FROM tb_trabajador
        WHERE clm_tra_tipo_trabajador = 'Conductor'
        ORDER BY clm_tra_nombres ASC
    ");
    
    $stmt->execute();
    $resultado = $stmt->get_result();

    $conductores = [];
    while ($fila = $resultado->fetch_assoc()) {
        $conductores[] = $fila;
    }

    return $conductores;
}


?>
