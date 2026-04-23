<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$id_mov = intval($_GET['id'] ?? 0);

// Buscar la nota de salida relacionada al movimiento
$sql = "
SELECT 
    ns.clm_nota_id AS nota_id,
    ns.clm_nota_sco AS correlativo,
    ns.clm_nota_fecha AS fecha_completa,
    u.nombre AS responsable,
    CONCAT(p.clm_placas_bus, ' (', p.clm_placas_placa, ')') AS placa_real,
    ns.clm_nota_modulo AS area,
    ns.clm_nota_motivo AS motivo
FROM tb_notas_salida ns
JOIN tb_alm_movimientos m ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
LEFT JOIN tb_placas p ON ns.clm_nota_placa = p.clm_placas_id
LEFT JOIN tb_usuarios u ON ns.clm_nota_responsable = u.usuario
WHERE m.clm_alm_mov_id = $id_mov
LIMIT 1
";

$res = $conn->query($sql);

if ($res->num_rows > 0) {
    $nota = $res->fetch_assoc();
    $nota_id = $nota['nota_id'];
    $fecha = date("d/m/Y", strtotime($nota['fecha_completa']));
    $hora = date("H:i", strtotime($nota['fecha_completa']));

    echo "<h3 style='margin-top:0;'>🧾 Nota: {$nota['correlativo']}</h3>";
    echo "<p><strong>📅 Fecha:</strong> $fecha &nbsp;&nbsp; <strong>🕒 Hora:</strong> $hora</p>";
    echo "<p><strong>🚚 Placa:</strong> " . htmlspecialchars($nota['placa_real'] ?? '[Sin placa]') . "</p>";
    echo "<p><strong>👨 Responsable:</strong> " . htmlspecialchars($nota['responsable']) . "</p>";
    echo "<p><strong>🏢 Área:</strong> " . htmlspecialchars($nota['area']) . "</p>";
    echo "<p><strong>📝 Motivo:</strong> " . htmlspecialchars($nota['motivo']) . "</p>";

    // Segunda consulta: productos relacionados con esa nota
    $productos_sql = "
    SELECT 
        p.clm_alm_producto_NOMBRE AS producto,
        m.clm_alm_mov_cantidad AS cantidad,
        -- m.clm_alm_mov_monto AS monto,
        m.clm_alm_mov_observacion AS observacion
    FROM tb_alm_movimientos m
    JOIN tb_alm_producto p ON m.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
    WHERE m.clm_alm_mov_idNOTA = $nota_id
    ORDER BY p.clm_alm_producto_NOMBRE
    ";
    
    $productos_res = $conn->query($productos_sql);

    if ($productos_res->num_rows > 0) {
        echo "<h4 style='margin-top:20px;'>📦 Productos Registrados:</h4>";
        echo "<table style='width:100%; border-collapse:collapse; margin-top:10px;' border='1' cellpadding='8'>";
        echo "<tr><th>Producto</th><th>Cantidad</th><th>Observación</th></tr>";
        while ($prod = $productos_res->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($prod['producto']) . "</td>";
            echo "<td>" . htmlspecialchars($prod['cantidad']) . "</td>";
            // echo "<td>" . htmlspecialchars($prod['monto']) . "</td>";
            echo "<td>" . htmlspecialchars($prod['observacion']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>⚠️ No hay productos registrados en esta nota.</p>";
    }

} else {
    echo "<p style='color: red;'>❌ No se encontró una Nota de Salida asociada a este movimiento.</p>";
}

$conn->close();
?>
