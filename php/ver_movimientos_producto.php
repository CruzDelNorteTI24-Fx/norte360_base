<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$id = intval($_GET['id'] ?? 0);

// Obtener info del producto
$info_sql = "
SELECT 
    p.clm_alm_producto_NOMBRE AS producto,
    c.clm_alm_categoria_NOMBRE AS categoria,
    c.clm_alm_categoria_descripcion AS descategoria,
    cod.clm_alm_codigo_NOMBRE AS codigo,
    cod.clm_alm_codigo_descripcion AS descodigo
FROM tb_alm_producto p
JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
WHERE p.clm_alm_producto_id = $id
";

$info = $conn->query($info_sql)->fetch_assoc();
// Mostrar encabezado del modal
if ($info) {
    echo "<h3 style='margin-top:0;'>📦 {$info['producto']}</h3>";
    echo "<p><strong>Grupo al que pertenece:</strong> " . htmlspecialchars($info['descodigo']) . "</p>";
    echo "<p><strong>Categoría:</strong> " . htmlspecialchars($info['descategoria']) . "</p>";
    echo "<p><strong>Código:</strong> " . htmlspecialchars($info['categoria'] . $id) . "</p>";
} else {
    echo "<p style='color:red;'>❌ Producto no encontrado.</p>";
    $conn->close();
    exit;
}

// Obtener movimientos
$sql = "
SELECT 
    clm_alm_mov_id AS id_movimiento,
    clm_alm_mov_fecha_registro AS fecha_registro_MOV, 
    clm_alm_mov_TIPO AS tipo_MOV, 
    clm_alm_mov_cantidad AS cantidad_MOV, 
    -- clm_alm_mov_monto AS monto_MOV, 
    clm_alm_mov_observacion AS observacion_MOV
FROM tb_alm_movimientos
WHERE clm_alm_mov_idPRODUCTO = $id
ORDER BY clm_alm_mov_fecha_registro DESC
";

$res = $conn->query($sql);

if ($res->num_rows > 0) {
    echo "<table border='1' cellpadding='8' style='width:100%; border-collapse:collapse; margin-top:10px;'>";
    echo "<tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Observación</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['fecha_registro_MOV']) . "</td>";

        $tipo = htmlspecialchars($row['tipo_MOV']);

        if ($tipo === 'ENTRADA') {
            echo "<td style='color: #1e7e34; background: #d4edda; font-weight: bold; text-align:center;'>$tipo</td>";
        } elseif ($tipo === 'SALIDA') {
            echo "<td 
                onclick=\"verNotaSalida(" . $row['id_movimiento'] . ")\" 
                style='color: #a71d2a; background: #f8d7da; font-weight: bold; text-align:center; cursor: pointer;'>
                $tipo
            </td>";
        } else {
            echo "<td style='color: #555; background: #f0f0f0; text-align:center;'>$tipo</td>";
        }
        
        echo "<td>" . htmlspecialchars($row['cantidad_MOV']) . "</td>";
        // echo "<td>" . htmlspecialchars($row['monto_MOV']) . "</td>";
        echo "<td>" . htmlspecialchars($row['observacion_MOV']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Este producto no tiene movimientos registrados.</p>";
}

$conn->close();
?>
