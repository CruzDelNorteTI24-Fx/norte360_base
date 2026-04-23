<?php
session_start();

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");

// Validar id_checklist recibido
$id_checklist = $_GET['id_checklist'] ?? null;
if (!$id_checklist) {
    echo json_encode(["error" => "Checklist no especificado."]);
    exit();
}

// Obtener el tipo de checklist (debe ir antes de la consulta)
//Obtener el tipo de checklist (clm_checklist_idtipo) para el checklist recibido como id_checklist
$stmt = $conn->prepare("SELECT clm_checklist_idtipo FROM tb_checklist_limpieza WHERE clm_checklist_id = ?");
$stmt->bind_param("i", $id_checklist);
$stmt->execute();

$res = $stmt->get_result();
if ($res->num_rows == 0) {
    echo json_encode(["error" => "Checklist no encontrado."]);
    exit();
}

$row = $res->fetch_assoc();
$tipo_checklist = $row['clm_checklist_idtipo'];
$stmt->close();


// ES IGUAL A INTER_BUSMODEL.PHP CORRESPONDE A LA FUNCIÓN obtenerKPIChecklist, SINTETIZAR
// Obtener todos los items y sus resultados para el checklist
//Traer todos los items de ese tipo de checklist junto con sus resultados registrados para ese checklist específico.
$stmt_items = $conn->prepare("
    SELECT i.clm_item_id, i.clm_item_nombre, i.clm_items_tipo, i.clm_item_idtipocheck,
           r.clm_resultado_dfecd, r.clm_rescheck_conductor1, r.clm_resultado_estado, r.clm_rescheck_porcentaje1, r.clm_rescheck_imagen
    FROM tb_items_checklist i
    LEFT JOIN tb_resultados_checklist r
      ON i.clm_item_id = r.clm_resultado_id_item
     AND r.clm_resultado_id_checklist = ?
    WHERE i.clm_item_idtipocheck = ?
      AND i.clm_item_estado = 'activo'
");

$stmt_items->bind_param("ii", $id_checklist, $tipo_checklist);
$stmt_items->execute();
$res_items = $stmt_items->get_result();

if (!$res_items) {
    echo json_encode(["error" => "Error en la consulta de items: ".$conn->error]);
    exit();
}

$items = [];
$total_c = 0;
$total_e = 0;

while ($row_item = $res_items->fetch_assoc()) {
    $tipo = $row_item['clm_items_tipo'];
    $resultado = "";

    switch ($tipo) {
        case 'T':
            $resultado = $row_item['clm_rescheck_conductor1'] ?? "";
            break;
        case 'O':
            $resultado = $row_item['clm_rescheck_conductor1'] ?? "";
            break;
        case 'H':
            $resultado = $row_item['clm_resultado_dfecd'] ?? "";
            break;
        case 'R':
            $resultado = $row_item['clm_resultado_estado'] ?? "";
            if ($resultado == 'C') $total_c++; // contar C en radio para KPI
            break;
        case 'E':
            $resultado = $row_item['clm_resultado_estado'] ?? "";
            if ($resultado == 'P') $total_e++; // contar E en radio para KPI
            break;
        case 'N':
            $resultado = $row_item['clm_rescheck_porcentaje1'] ?? "";
            break;
        case 'F':
            $blob = $row_item['clm_rescheck_imagen'];
            if (!empty($blob)) {
                $base64 = base64_encode($blob);
                $resultado = "data:image/jpeg;base64," . $base64;
                // Cambia image/jpeg por image/png si corresponde según el tipo real de imagen
            } else {
                $resultado = "";
            }
            break;
    }

    $items[] = [
        "id" => $row_item['clm_item_id'],
        "nombre" => $row_item['clm_item_nombre'],
        "tipo" => $tipo,
        "resultado" => $resultado,

        // NUEVO: incluir data completa llenada
        "data_completa" => [
            "texto" => $row_item['clm_rescheck_conductor1'] ?? "",
            "radio" => $row_item['clm_resultado_estado'] ?? "",
            "numero" => $row_item['clm_rescheck_porcentaje1'] ?? "",
            "imagen" => isset($blob) && !empty($blob) ? $resultado : ""
        ]
    ];
}
$stmt_items->close();

// Procesar KPI según tipo de checklist
$total_items = count($items);

if ($tipo_checklist == 1) {
    // Tipo 1: Limpieza
    $porcentaje_c = ($total_items > 0) ? ($total_c / $total_items) * 100 : 0;
    $kpi_titulo = "Estado de Limpieza de la Unidad";

    if ($porcentaje_c > 70) {
        $kpi_texto = "EXCELENTE";
    } elseif ($porcentaje_c >= 50) {
        $kpi_texto = "ACEPTABLE";
    } else {
        $kpi_texto = "DEFICIENTE";
    }

    $kpi_valor = number_format($porcentaje_c, 2) . "%";
}

elseif ($tipo_checklist == 2) {
    // Tipo 2: SAB ahora Embarque
    $porcentaje_cSAB = ($total_items > 0) ? ($total_c / $total_items) * 100 : 0;
    $kpi_titulo = "Estado de Limpieza de la Unidad";

    if ($porcentaje_cSAB > 70) {
        $kpi_texto = "EXCELENTE";
    } elseif ($porcentaje_cSAB >= 50) {
        $kpi_texto = "ACEPTABLE";
    } else {
        $kpi_texto = "DEFICIENTE";
    }

    $kpi_valor = number_format($porcentaje_cSAB, 2) . "%";
}

elseif ($tipo_checklist == 3) {
    // Tipo 3: Alcoholímetro
    $kpi_titulo = "Prueba Alcoholimetría";
    $kpi_texto = "RESULTADOS ALCOHOLÍMETRO POR CONDUCTOR";

    $kpi_conductores = [];
    foreach ($items as $item) {
        if ($item['tipo'] == 'N') { // si es tipo número (porcentaje alcoholímetro)
            $valor = floatval($item['resultado']);
            $estado = ($valor > 0.5) ? "NO APTO" : "APTO";

            $kpi_conductores[] = [
                "nombre" => $item['nombre'],
                "valor" => $valor,
                "estado" => $estado
            ];
        }
    }

    $kpi_valor = $kpi_conductores; // enviamos array con resultados por conductor
}
elseif ($tipo_checklist == 4) {
    $kpi_titulo = "Última Fumigación Realizada";
    
    // Obtener bus_id desde el checklist
    $stmt_bus = $conn->prepare("SELECT clm_checklist_id_bus FROM tb_checklist_limpieza WHERE clm_checklist_id = ?");
    $stmt_bus->bind_param("i", $id_checklist);
    $stmt_bus->execute();
    $res_bus = $stmt_bus->get_result();
    $bus_row = $res_bus->fetch_assoc();
    $bus_id = $bus_row['clm_checklist_id_bus'] ?? null;

    if (!$bus_id) {
        echo json_encode(["error" => "Este checklist no tiene un bus asociado."]);
        exit();
    }

    // Obtener la fecha de la última fumigación real de ese bus
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
        $dias = $fecha_fumigacion->diff($hoy)->days;
        $es_hoy = $fecha_fumigacion->format('Y-m-d') === $hoy->format('Y-m-d');

        $estado = ($dias <= 15) ? "APTO para viajar" : "NO APTO para viajar";
        $texto = $es_hoy
            ? "La fumigación fue realizada HOY. $estado"
            : "Ha pasado $dias día(s) desde la última fumigación. $estado";

        $kpi_texto = $texto;
        $kpi_valor = $fecha_fumigacion->format('Y-m-d H:i:s');
    } else {
        $kpi_valor = "Sin registros válidos";
        $kpi_texto = "No se encontró ninguna fumigación previa.";
    }
}




else {
    // Otros tipos (pendiente implementar)
    $kpi_titulo = "Sin Indicador definido para este tipo.";
    $kpi_texto = "Sin KPI definido para este tipo.";
    $kpi_valor = "-";
}



// lógica de Tipo

$response = [
    "kpi_titulo" => $kpi_titulo,
    "kpi_texto" => $kpi_texto,
    "kpi_valor" => $kpi_valor,
    "items" => $items
];

header('Content-Type: application/json');
echo json_encode($response);


// Puedes añadir lógica de Tipo 2 y Tipo 3 aquí

?>
