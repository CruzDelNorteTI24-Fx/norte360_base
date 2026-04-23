<?php
if (session_status() === PHP_SESSION_NONE) {    session_start();}
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}



if (!defined('ACCESS_GRANTED')) {
    define('ACCESS_GRANTED', true);
}
require_once(__DIR__ . "/../../.c0nn3ct/db_securebd2.php");
require_once("interbus_model.php");


if (isset($_GET['bus_id'])) {
    $bus_id = intval($_GET['bus_id']);
    $fecha_actual = isset($_GET['fecha']) ? $_GET['fecha'] : null;
    $conductores = [];

    $bus_row = obtenerDatosBus($conn, $bus_id);


    if ($bus_row) {
        // Banner profesional de bus
        // Banner profesional de bus con más información visual y estructurada
        // Banner principal
        echo "<div class='bus-info-banner'>";
        echo "  <div class='bus-header'>";
        echo "    <h3><i class='fas fa-bus'></i> " . htmlspecialchars($bus_row['clm_placas_BUS']) . "</h3>";
        echo "    <span class='bus-tag'>Placa: " . htmlspecialchars($bus_row['clm_placas_placa']) . "</span>";
        echo "  </div>";
        echo "</div>";

        // Nuevo bloque de detalles separado y horizontal
        echo "<div class='bus-details-grid'>";
        echo "  <div class='bus-detail-item'>";
        echo "    <i class='fas fa-layer-group'></i>";
        echo "    <p><strong>Servicio</strong><br>" . htmlspecialchars($bus_row['clm_placas_SERVICIO']) . "</p>";
        echo "  </div>";

        echo "  <div class='bus-detail-item'>";
        echo "    <i class='fas fa-shuttle-van'></i>";
        echo "    <p><strong>Tipo</strong><br>" . htmlspecialchars($bus_row['clm_placas_TIPO_VEHÍCULO']) . "</p>";
        echo "  </div>";

        echo "  <div class='bus-detail-item'>";
        echo "    <i class='fas fa-calendar-alt'></i>";
        echo "    <p><strong>Fecha Actual</strong><br>" . date('Y-m-d') . "</p>";
        echo "  </div>";

        echo "  <div class='bus-detail-item'>";
        echo "    <i class='fas fa-clock'></i>";
        echo "    <p><strong>Hora Actual</strong><br>" . date('H:i') . "</p>";
        echo "  </div>";
        echo "</div>"; // cierre bus-details-grid

        // Mostrar inputs de conductores (bloque separado profesional)
        echo "<div style='background:white; padding:20px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.08); margin:20px 0; max-width:800px; margin-left:auto; margin-right:auto;'>";
        echo "<h3 style='margin-top:0; color:#2980b9;'><i class='fas fa-user'></i> Conductores</h3>";

        echo "<div style='display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px;'>";

            // Inputs vacíos con IDs fijos
            echo "<div>";
                echo "<label for='conductor1'><strong>Conductor 1:</strong></label>";
                echo "<input type='text' id='conductor1' name='conductor1' value='' class='input-conductor readonly' readonly>";
                echo "<button type='button' id='btn_conductor1' onclick=\"toggleEdit('conductor1')\" style='right:5px; top:30px; background:#3498db; border:none; color:white; padding:5px 10px; border-radius:6px; cursor:pointer;'><i class='fas fa-edit'></i> Editar</button>";
            echo "</div>";

            echo "<div>";
                echo "<label for='conductor2'><strong>Conductor 2:</strong></label>";
                echo "<input type='text' id='conductor2' name='conductor2' value='' class='input-conductor readonly' readonly>";
                echo "<button type='button' id='btn_conductor2' onclick=\"toggleEdit('conductor2')\" style='right:5px; top:30px; background:#3498db; border:none; color:white; padding:5px 10px; border-radius:6px; cursor:pointer;'><i class='fas fa-edit'></i> Editar</button>";
            echo "</div>";

        echo "</div>"; // cierre grid inputs
        echo "</div>"; // cierre card conductores

        // Mostrar inputs de Fumigación (bloque separado profesional)
        echo "<div style='background:white; padding:20px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.08); margin:20px 0; max-width:800px; margin-left:auto; margin-right:auto;'>";
        echo "<h3 style='margin-top:0; color:#2980b9;'><i class='fas fa-bug'></i> Fumigación</h3>";

        echo "<div style='display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px;'>";

            // Inputs vacíos con IDs fijos
            echo "<div>";
            echo "  <label for='fumigacionultima'><strong>Última Fumigación:</strong></label>";

            echo "  <div style='display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-top:5px;'>";

            echo "    <input type='text' id='fumigacionultima' name='fumigacionultima' value='' class='input-fumigacion readonly' readonly style='flex:1; min-width:180px; padding:8px 12px; border:1px solid #ccc; border-radius:6px; background-color:#f9f9f9;'>";

            echo "    <span id='fumigacion_estado' style='margin-bottom:15px; padding:6px 12px; border-radius:20px; font-size:13px; font-weight:bold; background:#ecf0f1; color:#2c3e50;'>Estado</span>";

            // echo "    <button type='button' id='btn_fumigacionultima' onclick=\"toggleEdit('fumigacionultima')\" style='background:#3498db; border:none; color:white; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:13px;'><i class='fas fa-edit'></i> Editar</button>";

            echo "  </div>";
            echo "</div>";


        echo "</div>"; // cierre grid inputs
        echo "</div>"; // cierre card fumigacion


        // Inputs de origen, destino y observaciones
        echo "<div style='background:white; padding:20px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.08); margin:20px 0; max-width:800px; margin-left:auto; margin-right:auto;'>";
        echo "<h3 style='margin-top:0; color:#2980b9;'><i class='fas fa-map-marker-alt'></i> Ruta y Observaciones</h3>";

        echo "<div style='display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px;'>";


        $opciones_ruta = ["Chimbote", "Lima", "Trujillo", "Moro", "Santa", "San Jacinto"]; // Personaliza tus rutas

        // Origen
        echo "<div>";
        echo "<label for='origen' style='font-weight:bold; color:#2c3e50;'><i class='fas fa-map-pin'></i> Origen</label>";
        echo "<select id='origen' name='origen' onchange='verificarTransbordo()' style='width:100%; padding:12px; border:1px solid #ccc; border-radius:8px; font-size:15px;'>";
        echo "<option value=''>Seleccione origen...</option>";
        foreach ($opciones_ruta as $opcion) {
            echo "<option value='$opcion'>$opcion</option>";
        }
        echo "</select>";
        echo "</div>";

        // Destino
        echo "<div>";
        echo "<label for='destino' style='font-weight:bold; color:#2c3e50;'><i class='fas fa-map-pin'></i> Destino</label>";
        echo "<div style='display:flex; align-items:center; gap:10px;'>";
        echo "<select id='destino' name='destino' onchange='verificarTransbordo()' style='width:100%; padding:12px; border:1px solid #ccc; border-radius:8px; font-size:15px;'>";
        echo "<option value=''>Seleccione destino...</option>";
        foreach ($opciones_ruta as $opcion) {
            echo "<option value='$opcion'>$opcion</option>";
        }
        echo "</select>";
        echo "<span id='transbordoTag' style='display:none; background:#27ae60; color:white; padding:6px 12px; border-radius:20px; font-size:13px; font-weight:bold;'>Transbordo</span>";
        echo "</div>";
        echo "</div>";




        echo "<div style='grid-column:1/-1;'>";
        echo "<label for='observaciones' style='font-weight:bold; font-size:15px; color:#2c3e50;'><i class='fas fa-sticky-note'></i> Observaciones:</label>";
        echo "<textarea id='observaciones' name='observaciones' placeholder='Ingrese observaciones...' rows='4' style='width:100%; resize: none; min-height:100px; padding:10px 0px; border:1px solid #ccc; border-radius:8px; font-size:14px; line-height:1.5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; resize: none;'></textarea>";
        echo "</div>";


        echo "</div>"; // cierre grid

        echo "</div>"; // cierre card inputs


        echo "<div style='background:white; padding:20px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.08); margin:20px 0; max-width:800px; margin-left:auto; margin-right:auto;'>";

        echo "<h3 style='margin-top:0; color:#2980b9;'><i class='fas fa-tasks'></i> Lista de CheckList Generados</h3>";
        echo "<div class='card'>";

        // solo consulta checklist si hay fecha
        if ($fecha_actual) {

            $viajes = obtenerViajesPorFecha($conn, $bus_id, $fecha_actual);

            echo "<div style='background:white; padding:20px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.08); margin:20px 0; max-width:900px; margin-left:auto; margin-right:auto;'>";
            echo "<h3 style='margin-top:0; color:#2980b9;'><i class='fas fa-road'></i> Viajes realizados el " . htmlspecialchars($fecha_actual) . "</h3>";

            if ($viajes && $viajes->num_rows > 0) {
                echo "<div class='tabla-contenedor'>";
                echo "<table>";
                echo "<tr>
                        <th>ID</th>
                        <th>Hora salida</th>
                        <th>Origen</th>
                        <th>Destino</th>
                        <th>Conductor 1</th>
                        <th>Conductor 2</th>
                        <th>Estado</th>
                    </tr>";

                while ($v = $viajes->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_idviaje']) . "</td>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_hora']) . "</td>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_origen']) . "</td>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_destino']) . "</td>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_conductor1']) . "</td>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_conductor2']) . "</td>";
                    echo "<td>" . htmlspecialchars($v['clm_viarut_estado']) . "</td>";
                    echo "</tr>";
                }

                echo "</table>";
                echo "</div>"; // cierre tabla-contenedor
            } else {
                echo "<p>No se encontraron viajes para este bus en la fecha seleccionada.</p>";
            }

            echo "</div>"; // cierre card viajes


            // 1️⃣ Consulta los tipos de checklist existentes para ese bus y fecha
            $res_tipos = obtenerTiposChecklist($conn, $bus_id, $fecha_actual);
            // Creamos array para almacenar tipos y asegurar que el tipo 4 esté presente
            $tipos_existentes = [];
            $tipo_4_presente = false;

            while ($tipo_row = $res_tipos->fetch_assoc()) {
                $tipos_existentes[] = $tipo_row;
                if (intval($tipo_row['clm_checklist_idtipo']) === 4) {
                    $tipo_4_presente = true;
                }
            }

            // Si no se encontró tipo 4, lo agregamos manualmente
            if (!$tipo_4_presente) {
                $tipos_existentes[] = [
                    'clm_checktip_nombre' => 'Fumigación',
                    'clm_checklist_idtipo' => 4
                ];
            }

            // Ahora recorremos todos los tipos existentes (incluyendo tipo 4 forzado si era necesario)
            if (count($tipos_existentes) > 0) {
                foreach ($tipos_existentes as $tipo_row) {
                    $tipo_nombre = htmlspecialchars($tipo_row['clm_checktip_nombre']);
                    $tipo_id = intval($tipo_row['clm_checklist_idtipo']);

                    // 🔍 Consulta completitud para este tipo
                    $res_items = obtenerCompletitud($conn, $bus_id, $fecha_actual, $tipo_id);


                    $total = $res_items['total'];
                    $respondidos = $res_items['respondidos'];
                    $estado_completitud = ($total == $respondidos && $total > 0) ? "Completo" : "Incompleto";
                    $color_estado = ($estado_completitud == "Completo") ? "#27ae60" : "#c0392b";
                    // ✅ Obtener detalles (responsable, observaciones)
                    $detalles = obtenerDetallesChecklist($conn, $bus_id, $fecha_actual, $tipo_id);
                    // 2️⃣ Consulta checklist de ese tipo
                    $res_chk = obtenerChecklists($conn, $bus_id, $fecha_actual, $tipo_id);

                    $kpi = null;
                    if ($res_chk->num_rows > 0) {
                        $first_chk = $res_chk->fetch_assoc();
                        $first_chk_id = $first_chk['clm_checklist_id'];
                        $kpi = obtenerKPIChecklist($conn, $first_chk_id, $tipo_id, $bus_id); // ✅ Ahora sí pasa $bus_id
                        $res_chk->data_seek(0);
                    }
                    else {
                        if ($tipo_id === 4) {
                            $kpi = obtenerKPIChecklist($conn, 0, 4, $bus_id); // checklist_id = 0, pasa el bus_id correcto
                        }
                    }


                    // Mostrar como carpeta profesional con estado
                    echo "<div class='folder-container'>";
                    echo "<h3 class='folder-title' onclick='toggleFolder(this)'>
                            <i class='fas fa-folder-open'></i>Checklist - $tipo_nombre
                            <span style='margin-left:auto; font-size:14px; background:$color_estado; color:white; padding:4px 12px; border-radius:20px;'>$estado_completitud</span>
                            <i class='fas fa-chevron-down' style='color: #2980b9; margin-left:10px; transition: transform 0.3s;'></i>
                            </h3>";


                    // Mostrar los checklist dentro
                    echo "<div class='checklist-cards-container' style='display:none;'>";
                    while ($chk = $res_chk->fetch_assoc()) {
                        $estado_class = strtolower($chk['clm_checklist_estado']);
                        $chk_id = $chk['clm_checklist_id']; // 👈 obtener el id real de este checklist

                        echo "<div class='checklist-card-item'>";
                            echo "<i class='checklist-icon fas fa-clipboard-check'></i>";
                            echo "<h4><i class='fas fa-file-alt'></i> Checklist N° " . htmlspecialchars($chk['clm_checklist_corr']) . "</h4>";
                            echo "<p><strong>Responsable:</strong> " . htmlspecialchars($detalles['clm_checklist_responsable'] ?? 'No registrado') . "</p>";
                            echo "<p><strong>Observaciones:</strong> " . htmlspecialchars($detalles['clm_checklist_observaciones'] ?? 'Sin observaciones') . "</p>";
                            echo "<p><strong>Fecha:</strong> " . htmlspecialchars($chk['clm_checklist_fecha']) . "</p>";
                            echo "<p><strong>Hora:</strong> " . htmlspecialchars($chk['clm_checklist_hora']) . "</p>";
                            echo "<span class='estado {$estado_class}'>" . htmlspecialchars($chk['clm_checklist_estado']) . "</span>";
                            echo "<a href='ver_checklist.php?id=" . urlencode($chk['clm_checklist_id']) . "' class='ver-btn'><i class='fas fa-eye'></i> Ver</a>";
                        // 👤 Responsable y Observaciones
                        // ✅ CONSULTA KPI DE ESTE CHECKLIST, NO DEL PRIMERO
                        $kpi = obtenerKPIChecklist($conn, $chk_id, $tipo_id, $bus_id);

                        // 💡 Mostrar KPI si existe
                        if ($kpi) {
                            echo "<div style='background:#f8f9fa; border-left:5px solid #3498db; padding:15px 20px; border-radius:10px; margin:15px 0; box-shadow:0 2px 6px rgba(0,0,0,0.08);'>";
                            echo "<h4 style='margin-top:0; color:#2980b9; font-size:16px; display:flex; align-items:center; gap:8px;'><i class='fas fa-chart-line'></i> " . htmlspecialchars($kpi['titulo']) . "</h4>";

                            if (is_array($kpi['valor'])) {
                                foreach ($kpi['valor'] as $k) {
                                    // badge de estado
                                    $estado_color = ($k['estado'] == 'APTO') ? '#27ae60' : '#c0392b';
                                    echo "<div style='display:flex; justify-content:space-between; align-items:center; margin:6px 0;'>";
                                    echo "<div>";
                                    echo "<strong style='color:#2c3e50;'>" . htmlspecialchars($k['conductor']) . "</strong><br>";
                                    echo "<span style='color:#555; font-size:14px;'>" . htmlspecialchars($k['valor']) . "</span>";
                                    echo "</div>";
                                    echo "<span style='background:$estado_color; color:white; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:bold;'>" . htmlspecialchars($k['estado']) . "</span>";
                                    echo "</div>";
                                    $conductores[] = ['nombre' => htmlspecialchars($k['conductor'])];

                                }
                            } else {
                                echo "<p style='margin:8px 0;'><strong>Valor:</strong> " . htmlspecialchars($kpi['valor']) . "</p>";
                                echo "<p style='margin:8px 0;'><strong>Estado:</strong> " . htmlspecialchars($kpi['texto']) . "</p>";
                            }

                            echo "</div>";
                        }
                        echo "</div>";

                    }
                    // Si no hay checklist pero sí KPI (caso tipo 4 forzado)
                    if ($res_chk->num_rows === 0 && $kpi && $tipo_id === 4) {
                        $detalles = obtenerUltimoChecklistDetallesTipo4($conn, $bus_id); // ✅ nuevo
                        $estado_class = strtolower($detalles['clm_checklist_estado']);

                        echo "<div class='checklist-card-item'>";
                        echo "<h4><i class='fas fa-bug'></i> Última Fumigación " . htmlspecialchars($detalles['clm_checklist_corr'] ?? 'No registrado') . "</h4>";
                        echo "<p><strong>Responsable:</strong> " . htmlspecialchars($detalles['clm_checklist_responsable'] ?? 'No registrado') . "</p>";
                        echo "<p><strong>Observaciones:</strong> " . htmlspecialchars($detalles['clm_checklist_observaciones'] ?? 'Sin observaciones') . "</p>";
                        echo "<p><strong>Fecha:</strong> " . htmlspecialchars($detalles['clm_checklist_fecha'] ?? 'Sin observaciones') . "</p>";
                        echo "<p><strong>Hora:</strong> " . htmlspecialchars($detalles['clm_checklist_hora'] ?? 'Sin observaciones') . "</p>";
                        echo "<span class='estado {$estado_class}'>" . htmlspecialchars($detalles['clm_checklist_estado']) . "</span>";
                        echo "<a href='ver_checklist.php?id=" . urlencode($detalles['clm_checklist_id']) . "' class='ver-btn'><i class='fas fa-eye'></i> Ver</a>";

                        echo "<div style='background:#f8f9fa; border-left:5px solid #3498db; padding:15px 20px; border-radius:10px; margin:15px 0; box-shadow:0 2px 6px rgba(0,0,0,0.08);'>";
                        echo "<h4 style='margin-top:0; color:#2980b9; font-size:16px; display:flex; align-items:center; gap:8px;'><i class='fas fa-chart-line'></i> " . htmlspecialchars($kpi['titulo']) . "</h4>";
                        echo "<p><strong>Valor:</strong> " . htmlspecialchars($kpi['valor']) . "</p>";
                        echo "<p><strong>Estado:</strong> " . htmlspecialchars($kpi['texto']) . "</p>";
                        echo "</div>";
                        echo "</div>";
                    }


                    echo "</div>"; // cierre checklist-cards-container
                    echo "</div>"; // cierre folder-container

                }
            } else {
                echo "<p>No se encontraron checklist para este bus en la fecha actual.</p>";
            }

        } else {
            echo "<p>Seleccione una fecha para visualizar checklist.</p>";
        }
        echo "<script>";

        if (!empty($conductores)) {
            foreach ($conductores as $index => $conductor) {
                $input_id = 'conductor' . ($index + 1);
                $nombre = $conductor['nombre'];
                echo "document.getElementById('$input_id').value = '$nombre';";
            }
        }
        if ($kpi && $kpi['titulo'] === 'Última Fumigación Realizada') {
            $valor_fumi = htmlspecialchars($kpi['valor']);
            $texto_fumi = htmlspecialchars($kpi['texto']);

            // Detectar si el texto contiene "NO APTO"
            $color_estado = (strpos($texto_fumi, 'NO APTO') !== false) ? '#e74c3c' : '#27ae60';

            // Asignar los valores a los campos
            echo "document.getElementById('fumigacionultima').value = '$valor_fumi';";
            echo "document.getElementById('fumigacion_estado').innerText = `$texto_fumi`;";
            echo "document.getElementById('fumigacion_estado').style.color = '$color_estado';";
        }


    
        echo "</script>";
        echo "</div>"; // cierre card inputs
        echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
        const botones = document.getElementById('botonesChecklist');
        if(botones){ botones.style.display = 'flex'; }
        });
        </script>";

        echo "</div>"; // cierre card bus
    } else {
        echo "<p>No se encontró ningún bus con ese ID.</p>";
    }
}

?>