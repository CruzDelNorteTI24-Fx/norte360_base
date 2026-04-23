<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

if (!defined('ACCESS_GRANTED')) {
    define('ACCESS_GRANTED', true);
}
require_once("../.c0nn3ct/db_securebd2.php");

if (isset($_GET['bus_id'])) {
    $bus_id = intval($_GET['bus_id']);
    $fecha_actual = isset($_GET['fecha']) ? $_GET['fecha'] : null;

    $stmt_bus = $conn->prepare("SELECT clm_placas_id, clm_placas_placa, clm_placas_BUS,clm_placas_TIPO_VEHÍCULO, clm_placas_SERVICIO FROM tb_placas WHERE clm_placas_id = ?");
    $stmt_bus->bind_param("i", $bus_id);
    $stmt_bus->execute();
    $res_bus = $stmt_bus->get_result();

    if ($res_bus->num_rows > 0) {
        while ($bus_row = $res_bus->fetch_assoc()) {
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
            echo "    <p><strong>Fecha</strong><br>" . date('Y-m-d') . "</p>";
            echo "  </div>";

            echo "  <div class='bus-detail-item'>";
            echo "    <i class='fas fa-clock'></i>";
            echo "    <p><strong>Hora</strong><br>" . date('H:i') . "</p>";
            echo "  </div>";

            echo "</div>";
            echo "<div class='card'>";

            // solo consulta checklist si hay fecha
            if ($fecha_actual) {

                // 1️⃣ Consulta los tipos de checklist existentes para ese bus y fecha
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

                if ($res_tipos->num_rows > 0) {
                    while ($tipo_row = $res_tipos->fetch_assoc()) {
                        $tipo_nombre = htmlspecialchars($tipo_row['clm_checktip_nombre']);
                        $tipo_id = intval($tipo_row['clm_checklist_idtipo']);

                        // 🔍 Consulta completitud para este tipo
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
                        $estado_completitud = ($total == $respondidos && $total > 0) ? "Completo" : "Incompleto";
                        $color_estado = ($estado_completitud == "Completo") ? "#27ae60" : "#c0392b";

                        // Mostrar como carpeta profesional con estado
                        echo "<div class='folder-container'>";
                        echo "<h3 class='folder-title' onclick='toggleFolder(this)'>
                                <i class='fas fa-folder-open'></i>Checklist - $tipo_nombre
                                <span style='margin-left:auto; font-size:14px; background:$color_estado; color:white; padding:4px 12px; border-radius:20px;'>$estado_completitud</span>
                                <i class='fas fa-chevron-down' style='color: #2980b9; margin-left:10px; transition: transform 0.3s;'></i>
                              </h3>";

                        // 2️⃣ Consulta checklist de ese tipo
                        $stmt_chk = $conn->prepare("SELECT clm_checklist_id, clm_checklist_fecha, clm_checklist_hora, clm_checklist_estado, clm_checklist_corr 
                                                    FROM tb_checklist_limpieza 
                                                    WHERE clm_checklist_id_bus = ? AND clm_checklist_fecha = ? AND clm_checklist_idtipo = ?
                                                    ORDER BY clm_checklist_fecha DESC, clm_checklist_hora DESC");
                        $stmt_chk->bind_param("iss", $bus_id, $fecha_actual, $tipo_id);
                        $stmt_chk->execute();
                        $res_chk = $stmt_chk->get_result();

                        // Mostrar los checklist dentro
                        echo "<div class='checklist-cards-container' style='display:none;'>";
                        while ($chk = $res_chk->fetch_assoc()) {
                            $estado_class = strtolower($chk['clm_checklist_estado']);

                            echo "<div class='checklist-card-item'>";
                            echo "<i class='checklist-icon fas fa-clipboard-check'></i>";
                            echo "<h4><i class='fas fa-file-alt'></i> Checklist N° " . htmlspecialchars($chk['clm_checklist_corr']) . "</h4>";
                            echo "<p><strong>Fecha:</strong> " . htmlspecialchars($chk['clm_checklist_fecha']) . "</p>";
                            echo "<p><strong>Hora:</strong> " . htmlspecialchars($chk['clm_checklist_hora']) . "</p>";
                            echo "<span class='estado {$estado_class}'>" . htmlspecialchars($chk['clm_checklist_estado']) . "</span>";
                            echo "<a href='ver_checklist.php?id=" . urlencode($chk['clm_checklist_id']) . "' class='ver-btn'><i class='fas fa-eye'></i> Ver</a>";

                            echo "</div>";
                        }
                        echo "</div>"; // cierre checklist-cards-container

                        echo "</div>"; // cierre folder-container
                    }
                } else {
                    echo "<p>No se encontraron checklist para este bus en la fecha seleccionada.</p>";
                }

            } else {
                echo "<p>Seleccione una fecha para visualizar checklist.</p>";
            }

            echo "</div>"; // cierre card bus
        }
    } else {
        echo "<p>No se encontró ningún bus con ese ID.</p>";
    }
}

?>