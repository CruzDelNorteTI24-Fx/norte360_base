<?php
session_start();
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");

$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);

if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 3; // id_modulo de esta vista

    if (!in_array($modulo_actual, $_SESSION['permisos'])) {
        header("Location: ../../login/none_permisos.php");
        exit();
    }
}

// ============================
// Endpoint interno: servir IMG BLOB por ID
// URL: scanner.php?img_prod=123
// ============================
if (isset($_GET['img_prod'])) {
    $idp = (int)$_GET['img_prod'];

    // Seguridad básica
    if ($idp <= 0) {
        http_response_code(400);
        exit;
    }

    $stmtImg = $conn->prepare("
        SELECT clm_alm_producto_IMG
        FROM tb_alm_producto
        WHERE clm_alm_producto_id = ?
        LIMIT 1
    ");
    $stmtImg->bind_param("i", $idp);
    $stmtImg->execute();
    $stmtImg->store_result();
    $stmtImg->bind_result($blob);
    $stmtImg->fetch();
    $stmtImg->close();

    // Si no hay imagen, devolvemos un placeholder SVG (para que no salga roto)
    if (empty($blob)) {
        header("Content-Type: image/svg+xml; charset=UTF-8");
        header("Cache-Control: public, max-age=86400");
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120">
                <rect width="100%" height="100%" fill="#ecf0f1"/>
                <path d="M20 88 L48 60 L64 76 L82 58 L100 76 L100 100 L20 100 Z" fill="#bdc3c7"/>
                <circle cx="45" cy="45" r="10" fill="#bdc3c7"/>
              </svg>';
        exit;
    }

    // Detectar MIME real del BLOB (jpg/png/webp/etc.)
    $mime = "image/jpeg";
    if (function_exists("finfo_open")) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $det = finfo_buffer($finfo, $blob);
            if ($det) $mime = $det;
            finfo_close($finfo);
        }
    }

    header("Content-Type: $mime");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . strlen($blob));
    echo $blob;
    exit;
}


$codigo = $_GET['codigo'] ?? '';
$mensaje = '';

$anaquel = $_GET['anaquel'] ?? '';
$mensaje_anaquel = '';

$tab_activa = $anaquel ? 'anaquel' : 'producto';
if (!empty($codigo)) $tab_activa = 'producto';

function mostrar_imagen($ruta, $etiqueta) {
    if ($ruta && file_exists("img/$ruta")) {
        return "<div class='img-block'><p>$etiqueta</p><img src='img/$ruta'></div>";
    }
    return "<p class='no-image'>[Sin imagen de $etiqueta]</p>";
}

function detectar_columnas_anaquel(mysqli $conn): array {
    $cols = [];
    $rs = $conn->query("SHOW COLUMNS FROM tb_alm_anaquel");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) $cols[] = $r['Field'];
    }

    $pick_exact = function(array $prefer) use ($cols) {
        foreach ($prefer as $c) if (in_array($c, $cols, true)) return $c;
        return null;
    };
    $pick_contains = function(array $keywords) use ($cols) {
        foreach ($cols as $c) {
            foreach ($keywords as $k) {
                if (stripos($c, $k) !== false) return $c;
            }
        }
        return null;
    };

    $id_col     = $pick_exact(['clm_alm_anaquel_id']) ?: $pick_contains(['_id', 'id']);
    $nombre_col = $pick_exact(['clm_alm_anaquel_nombre','clm_alm_anaquel_NOMBRE']) ?: $pick_contains(['nombre','name']);
    $codigo_col = $pick_exact(['clm_alm_anaquel_codigo','clm_alm_anaquel_CODIGO']) ?: $pick_contains(['codigo','barra','code']);

    return [
        'id'     => $id_col,
        'nombre' => $nombre_col,
        'codigo' => $codigo_col,
    ];
}


if ($codigo) {
    $codigo_busqueda = trim($codigo);
    $codigo_html = htmlspecialchars($codigo_busqueda, ENT_QUOTES, 'UTF-8');

    // Regla:
    // DE0215-1  => etiqueta/item específico con trazabilidad
    // DE0215    => producto general sin detalle de trazabilidad
    $es_etiqueta_item = (strpos($codigo_busqueda, '-') !== false);

    if ($es_etiqueta_item) {
        // =========================================================
        // BÚSQUEDA POR ETIQUETA / ITEM ESPECÍFICO
        // =========================================================
        $stmt = $conn->prepare("
            SELECT 
                e.clm_alm_etiquetado_FECHA AS fecha_etiquetado,
                e.clm_alm_etiquetado_ESTADO AS estado_etiqueta,
                e.clm_alm_etiquetado_idPRODUCTO AS id_producto,
                e.clm_alm_etiquetado_idMOVIMIENTO AS id_movimiento,
                a.clm_alm_anaquel_nombre  AS anaquel,
                s.clm_sedes_name AS sede_nombre,
                CONCAT('[', COALESCE(p.clm_alm_producto_codigo,''), '] ', COALESCE(p.clm_alm_producto_NOMBRE,'')) AS producto,
                p.clm_alm_producto_unidad AS unidproducto,
                p.clm_alm_producto_IMG AS producto_img,
                c.clm_alm_categoria_NOMBRE AS categoria,
                c.clm_alm_categoria_DESCRIPCION AS categoria_descripcion,
                cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
                m.clm_alm_mov_IMG AS movimiento_img,
                m.clm_alm_mov_OBSERVACION AS observacion_movimiento,
                m.clm_alm_mov_cantidad AS cantidad_movimiento,
                m.clm_alm_mov_fecha_registro AS fecha_movimiento
            FROM tb_alm_etiquetado e
            JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
            JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
            JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
            JOIN tb_alm_movimientos m ON e.clm_alm_etiquetado_idMOVIMIENTO = m.clm_alm_mov_id
            LEFT JOIN tb_sedes s ON e.clm_alm_etiquetado_oficina_destino = s.clm_sedes_id
            LEFT JOIN tb_alm_anaquel a ON e.clm_alm_etiquetado_anaquel = a.clm_alm_anaquel_id
            WHERE e.clm_etiquetado_CODIGO = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $codigo_busqueda);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $id_producto = (int)$row['id_producto'];

            $stock_stmt = $conn->prepare("SELECT Stock_Actual, Estado FROM vw_control_inventario WHERE ID = ? LIMIT 1");
            $stock_stmt->bind_param("i", $id_producto);
            $stock_stmt->execute();
            $stock = $stock_stmt->get_result()->fetch_assoc() ?: [];
            $stock_stmt->close();

            $mensaje = "<div class='alerta ok'><i class='bi bi-check-circle'></i><strong>Etiqueta válida · Item específico</strong></div>";
            $mensaje .= "<section><h3>🔹 Código de Etiqueta Escaneado</h3><p class='codigo'>{$codigo_html}</p></section>";

            $mensaje .= "<section><h3>📦 Información del Producto</h3>";
            $mensaje .= "<ul>";
            $mensaje .= "<li><strong>Categoría:</strong> " . htmlspecialchars(($row['codigo_categoria'] ? '(' . $row['codigo_categoria'] . ') ' : '') . ($row['categoria_descripcion'] ?: $row['categoria']), ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Producto:</strong> " . htmlspecialchars($row['producto'] . ' - ' . $row['unidproducto'], ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Stock Disponible:</strong> " . htmlspecialchars(($stock['Stock_Actual'] ?? 'No disponible') . ' - ' . $row['unidproducto'], ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Estado del Inventario:</strong> " . htmlspecialchars($stock['Estado'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "</ul>";
            $mensaje .= "<div class='img-block'><p>Imagen del Producto</p><img src='scanner.php?img_prod={$id_producto}'></div>";
            $mensaje .= "</section>";

            $mensaje .= "<section><h3>🧾 Detalle de Trazabilidad</h3>";
            $mensaje .= "<ul>";
            $mensaje .= "<li><strong>Fecha de Ingreso:</strong> " . htmlspecialchars($row['fecha_movimiento'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Oficina/Sede Actual:</strong> " . htmlspecialchars($row['sede_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Anaquel:</strong> " . htmlspecialchars($row['anaquel'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Observaciones:</strong> " . htmlspecialchars($row['observacion_movimiento'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Cantidad Ingresada:</strong> " . htmlspecialchars($row['cantidad_movimiento'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Fecha de Etiquetado:</strong> " . htmlspecialchars($row['fecha_etiquetado'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "<li><strong>Estado de Etiqueta:</strong> " . htmlspecialchars($row['estado_etiqueta'] ?? '-', ENT_QUOTES, 'UTF-8') . "</li>";
            $mensaje .= "</ul>";
            $mensaje .= mostrar_imagen($row['movimiento_img'], 'Imagen del Movimiento');
            $mensaje .= "</section>";
        } else {
            $mensaje = "<div class='alerta bad'><i class='bi bi-x-circle'></i><strong>Etiqueta no encontrada</strong></div>";
            $mensaje .= "<section><h3>🔎 Código consultado</h3><p class='codigo'>{$codigo_html}</p><p class='text-muted'>Se buscó como item específico porque contiene guion.</p></section>";
        }

        $stmt->close();

    } else {
        // =========================================================
        // BÚSQUEDA POR PRODUCTO GENERAL
        // =========================================================
        $stmt = $conn->prepare("
            SELECT 
                p.clm_alm_producto_id AS id_producto,
                p.clm_alm_producto_codigo AS codigo_producto,
                p.clm_alm_producto_NOMBRE AS producto_nombre,
                p.clm_alm_producto_unidad AS unidproducto,
                p.clm_alm_producto_IMG AS producto_img,
                c.clm_alm_categoria_NOMBRE AS categoria,
                c.clm_alm_categoria_DESCRIPCION AS categoria_descripcion,
                cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
                COALESCE(v.Stock_Actual, 0) AS Stock_Actual,
                COALESCE(v.Estado, 'Sin estado') AS Estado
            FROM tb_alm_producto p
            JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
            JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
            LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
            WHERE p.clm_alm_producto_codigo = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $codigo_busqueda);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $id_producto = (int)$row['id_producto'];
            $codigo_producto = htmlspecialchars($row['codigo_producto'] ?? '', ENT_QUOTES, 'UTF-8');
            $producto_nombre = htmlspecialchars($row['producto_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
            $unidad = htmlspecialchars($row['unidproducto'] ?? '', ENT_QUOTES, 'UTF-8');
            $categoria_full = htmlspecialchars((($row['codigo_categoria'] ?? '') ? '(' . $row['codigo_categoria'] . ') ' : '') . ($row['categoria_descripcion'] ?: $row['categoria']), ENT_QUOTES, 'UTF-8');
            $stock_actual = htmlspecialchars((string)($row['Stock_Actual'] ?? '0'), ENT_QUOTES, 'UTF-8');
            $estado_inv = htmlspecialchars($row['Estado'] ?? 'Sin estado', ENT_QUOTES, 'UTF-8');

            $mensaje = "<div class='alerta ok'><i class='bi bi-check-circle'></i><strong>Producto encontrado · Consulta general</strong></div>";
            $mensaje .= "<section><h3>🔹 Código de Producto Consultado</h3><p class='codigo'>{$codigo_producto}</p></section>";

            $mensaje .= "<section><h3>📦 Detalle del Producto</h3>";
            $mensaje .= "<ul>";
            $mensaje .= "<li><strong>Categoría:</strong> {$categoria_full}</li>";
            $mensaje .= "<li><strong>Producto:</strong> [{$codigo_producto}] {$producto_nombre}" . ($unidad ? " - {$unidad}" : "") . "</li>";
            $mensaje .= "<li><strong>Stock Disponible:</strong> {$stock_actual}" . ($unidad ? " - {$unidad}" : "") . "</li>";
            $mensaje .= "<li><strong>Estado del Inventario:</strong> {$estado_inv}</li>";
            $mensaje .= "</ul>";
            $mensaje .= "<div class='img-block'><p>Imagen del Producto</p><img src='scanner.php?img_prod={$id_producto}'></div>";
            $mensaje .= "</section>";
        } else {
            $mensaje = "<div class='alerta bad'><i class='bi bi-x-circle'></i><strong>Producto no encontrado</strong></div>";
            $mensaje .= "<section><h3>🔎 Código consultado</h3><p class='codigo'>{$codigo_html}</p><p class='text-muted'>Se buscó como producto general porque no contiene guion.</p></section>";
        }

        $stmt->close();
    }
}

if ($anaquel) {
    $anaquel_in = trim($anaquel);
    $anaquel_html = htmlspecialchars($anaquel_in, ENT_QUOTES, 'UTF-8');

    // Detectar columnas reales de la tabla de anaqueles
    $colsA = detectar_columnas_anaquel($conn);
    if (empty($colsA['id'])) {
        $mensaje_anaquel = "<div class='alerta bad'><i class='bi bi-x-circle'></i><strong>No se pudo detectar la clave primaria de la tabla de anaqueles.</strong></div>";
    } else {
        $col_id     = $colsA['id'];
        $col_nombre = $colsA['nombre'] ?: $colsA['id'];
        $col_codigo = $colsA['codigo']; // puede ser null

        // Elegimos por dónde buscar: primero por "codigo" si existe, si no por "nombre"
        $col_buscar = $col_codigo ?: $col_nombre;

        // 1) Buscar el anaquel
        $sqlAna = "SELECT 
                    a.`$col_id`     AS id_anaquel,
                    a.`$col_nombre` AS nombre_anaquel"
                    . ($col_codigo ? ", a.`$col_codigo` AS codigo_anaquel" : ", '' AS codigo_anaquel") . "
                  FROM tb_alm_anaquel a
                  WHERE a.`$col_buscar` = ?
                  LIMIT 1";

        $stA = $conn->prepare($sqlAna);
        $stA->bind_param("s", $anaquel_in);
        $stA->execute();
        $rA = $stA->get_result();

        if ($ana = $rA->fetch_assoc()) {
            $id_anaquel = (int)$ana['id_anaquel'];
            $ana_nom = htmlspecialchars($ana['nombre_anaquel'] ?? '', ENT_QUOTES, 'UTF-8');
            $ana_cod = htmlspecialchars($ana['codigo_anaquel'] ?? '', ENT_QUOTES, 'UTF-8');

            $mensaje_anaquel = "<div class='alerta ok'><i class='bi bi-check-circle'></i><strong>Anaquel encontrado</strong></div>";
            $mensaje_anaquel .= "<section><h3><i class='bi bi-grid-3x3-gap'></i> Anaquel</h3>";
            $mensaje_anaquel .= "<ul>";
            $mensaje_anaquel .= "<li><strong>Código:</strong> " . ($ana_cod ?: $anaquel_html) . "</li>";
            $mensaje_anaquel .= "<li><strong>Nombre:</strong> " . ($ana_nom ?: '-') . "</li>";
            $mensaje_anaquel .= "</ul>";
            $mensaje_anaquel .= "</section>";

            // 2) Listar productos dentro del anaquel (usando etiquetado → producto)
            $stP = $conn->prepare("
                SELECT
                    p.clm_alm_producto_id AS id_producto,
                    CONCAT('[', COALESCE(p.clm_alm_producto_codigo,''), '] ', COALESCE(p.clm_alm_producto_NOMBRE,'')) AS producto,
                    p.clm_alm_producto_IMG AS producto_img,
                    c.clm_alm_categoria_NOMBRE AS categoria,
                    cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
                    COALESCE(v.Stock_Actual, NULL) AS stock_actual,
                    COALESCE(v.Estado, '') AS estado_stock,
                    COUNT(*) AS unidades_en_anaquel,
                    MAX(e.clm_alm_etiquetado_FECHA) AS ultima_fecha_etiquetado
                FROM tb_alm_etiquetado e
                JOIN tb_alm_producto p ON e.clm_alm_etiquetado_idPRODUCTO = p.clm_alm_producto_id
                JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
                JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
                LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
                WHERE e.clm_alm_etiquetado_anaquel = ?
                GROUP BY
                    p.clm_alm_producto_id,
                    p.clm_alm_producto_NOMBRE,
                    p.clm_alm_producto_IMG,
                    c.clm_alm_categoria_NOMBRE,
                    cod.clm_alm_codigo_NOMBRE,
                    v.Stock_Actual,
                    v.Estado
                ORDER BY c.clm_alm_categoria_NOMBRE, p.clm_alm_producto_NOMBRE
            ");
            $stP->bind_param("i", $id_anaquel);
            $stP->execute();
            $rP = $stP->get_result();

            $rows = [];
            while ($x = $rP->fetch_assoc()) $rows[] = $x;
            
            $mensaje_anaquel .= "<section>";
            $mensaje_anaquel .= "<h3><i class='bi bi-diagram-3'></i> Distribución por ubicación</h3>";

            $mensaje_anaquel .= "
            <div class='hint-box'>
                <div class='hint-title'><i class='bi bi-info-circle'></i> ¿Qué significan estos valores?</div>
                <ul class='hint-list'>
                <li><strong>Stock total</strong>: cantidad actual registrada por movimientos.</li>
                <li><strong>En este anaquel</strong>: unidades ubicadas aquí (según etiquetas generadas).</li>
                <li><strong>En otros</strong>: diferencia entre stock total y lo ubicado en este anaquel (otros anaqueles o sin ubicación).</li>
                </ul>
            </div>
            ";

            if (!$rows) {
                $mensaje_anaquel .= "<div class='alerta warn'><i class='bi bi-exclamation-triangle'></i><strong>No hay productos registrados en este anaquel.</strong></div>";
            } else {
                $mensaje_anaquel .= "<p class='meta-line'><strong>Total de productos:</strong> " . count($rows) . "</p>";

                $mensaje_anaquel .= "<div class='tabla-wrap'><table class='tabla-pro'>";
                $mensaje_anaquel .= "<thead><tr>
                        <th>Img</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Stock total</th>
                        <th>En este anaquel</th>
                        <th>En otros</th>
                    </tr></thead><tbody>";

                foreach ($rows as $it) {
                    $idp = (int)($it['id_producto'] ?? 0);
                    $imgHtml = ($idp > 0)
                        ? "<img class='mini-img' src='scanner.php?img_prod={$idp}' alt=''>"
                        : "<span class='no-image'>—</span>";

                    $prod = htmlspecialchars($it['producto'] ?? '', ENT_QUOTES, 'UTF-8');
                    $cat  = htmlspecialchars(($it['categoria'] ?? '') . " (" . ($it['codigo_categoria'] ?? '') . ")", ENT_QUOTES, 'UTF-8');

                    // Stock total (inventario)
                    $totalStock = (is_numeric($it['stock_actual'] ?? null)) ? (float)$it['stock_actual'] : null;

                    // En este anaquel (etiquetas)
                    $enAnaquel = (int)($it['unidades_en_anaquel'] ?? 0);

                    // Diferencia (otros anaqueles o sin ubicación)
                    $enOtros = ($totalStock === null) ? null : max($totalStock - $enAnaquel, 0);

                    // Badge stock total (mantienes tu lógica)
                    $stockTxt = ($totalStock === null) ? "-" : rtrim(rtrim(number_format($totalStock, 3, '.', ''), '0'), '.');
                    $estado   = strtolower(trim((string)($it['estado_stock'] ?? '')));
                    $badgeCls = ($estado === 'ok' || $estado === 'normal' || $estado === 'activo') ? "badge-ok" : (($estado === '') ? "badge-soft" : "badge-bad");

                    // Barra de distribución (porcentaje en anaquel)
                    $pct = ($totalStock && $totalStock > 0) ? min(($enAnaquel / $totalStock) * 100, 100) : 0;
                    $pctCss = number_format($pct, 2, '.', '');

                    $otrosTxt = ($enOtros === null) ? "-" : rtrim(rtrim(number_format($enOtros, 3, '.', ''), '0'), '.');

                    $mensaje_anaquel .= "<tr>
                        <td>$imgHtml</td>
                        <td><strong>$prod</strong></td>
                        <td>$cat</td>

                        <td>
                        <span class='badge $badgeCls'>$stockTxt</span>
                        </td>

                        <td>
                        <div class='qty-cell'>
                            <div class='qty-main'>$enAnaquel</div>
                            <div class='distbar'><span style='width:$pctCss%'></span></div>
                            <div class='qty-sub'>Ubicado en este anaquel</div>
                        </div>
                        </td>

                        <td>
                        <div class='qty-cell other'>
                            <div class='qty-main'>$otrosTxt</div>
                            <div class='qty-sub'>En otros anaqueles / sin ubicación</div>
                        </div>
                        </td>
                    </tr>";
                }

                $mensaje_anaquel .= "</tbody></table></div>";
            }

            $mensaje_anaquel .= "</section>";

            $stP->close();
        } else {
            $mensaje_anaquel = "<div class='alerta bad'><i class='bi bi-x-circle'></i><strong>Anaquel no encontrado:</strong> $anaquel_html</div>";
        }

        $stA->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Validación de Código | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">  
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Bootstrap 5 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">   
<style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }

        .card {
            background: #fff;
            max-width: 700px;
            margin: 40px auto 20px auto;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        h2 {
            text-align: center;
            color: #2c3e50;
        }

        form {
            margin-bottom: 25px;
        }

        input[type=text] {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            margin-bottom: 15px;
        }

        button {
            background: #2980b9;
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
        }

        button:hover {
            background: #1c5980;
        }

        .resultado {
            font-size: 16px;
            color: #34495e;
            line-height: 1.7;
        }

        section {
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        section h3 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 18px;
        }

        ul {
            list-style: none;
            padding-left: 0;
        }

        ul li {
            margin-bottom: 8px;
        }

        .img-block {
            text-align: center;
            margin-top: 15px;
        }

        .img-block img {
            max-width: 100%;
            height: auto;
            max-height: 180px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .img-block p {
            margin-bottom: 6px;
            font-weight: bold;
            color: #555;
        }

        .no-image {
            color: #aaa;
            font-style: italic;
        }

        .codigo {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 18px;
            text-align: center;
        }

        .valid { color: #27ae60; font-weight: bold; text-align: center; margin-bottom: 15px; }
        .invalid { color: #c0392b; font-weight: bold; text-align: center; margin-bottom: 15px; }

        .logo-inicio {
    display: block;
    margin: 0 auto 20px auto;
    max-width: 200px;
    width: 100%;
    height: auto;
    }
    .metodos-extra {
        background: #fff;
        border-radius: 12px;
        padding: 25px 20px;
        margin: 40px auto 20px auto;
        max-width: 750px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        text-align: center;
    }

    .metodos-extra h3 {
        font-size: 20px;
        margin-bottom: 25px;
        color: #2c3e50;
    }

    .opciones-validacion {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 20px;
    }

    .card-opcion {
        background: #3498db;
        color: white;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
        font-size: 17px;
        font-weight: bold;
        width: 180px;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        transition: background 0.3s, transform 0.3s;
    }

    .card-opcion:hover {
        background: #21618c;
        transform: scale(1.05);
    }

    hr {
        border: none;
        height: 2px;
        background: linear-gradient(to right, #3498db, yellow, #3498db);
        margin: 50px auto 30px auto;
        width: 80%;
        border-radius: 4px;
    }
    /* BOTÓN FLOTANTE DE SOPORTE */
    .btn-flotante {
        position: fixed;
        bottom: 25px;
        right: 25px;
        background: #28a745;
        color: white;
        padding: 15px 20px;
        border-radius: 50px;
        font-size: 18px;
        text-decoration: none;
        box-shadow: 0 6px 12px rgba(0,0,0,0.2);
        transition: background 0.3s, transform 0.3s;
        z-index: 1000;
    }

    .btn-flotante:hover {
        background: #218838;
        transform: scale(1.1);
    }
    .main-header {
        background: #2c3e50;
        width: 100%;
        padding: 20px 30px;
        color: white;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        box-sizing: border-box;
    }

    .header-content {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
        max-width: none;
        padding: 0 30px;
        box-sizing: border-box;
        gap: 20px;
        flex-wrap: wrap;
    }

    .logo-bloque {
        display: flex;
        align-items: center;
    }

    .logo-header {
        max-width: 60px;
        height: auto;
        width: auto;
    }
    .logo-header2 {
        max-width: 60px;
        height: auto;
        max-width: 300px;
    }
    .logo-header3 {
        align-items: center;

        max-width: 150px;
        height: auto;
        width: auto;
    }
    .separador-vertical {
        width: 4px;
        height: 50px;
        background: #ecf0f1;
        margin: 0 10px;
    }



    .main-footer {
        background: #2c3e50;
        color: white;
        padding: 30px 20px;
        font-size: 14px;
        width: 100%;
        box-sizing: border-box;
    }

    .footer-top {
        display: flex;
        align-items: flex-start;
        justify-content: center;
        gap: 20px;
        flex-wrap: wrap;
    }


    .footer-info {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .footer-title {
        font-weight: bold;
        font-size: 16px;
        margin: 0 0 10px 0;
    }

    .footer-cajas {
        display: flex;
        gap: 15px;
    }

    .footer-box {
        padding: 10px;
        border-radius: 8px;
        width: 40px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .footer-box img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    .footer-copy {
        text-align: center;
        margin-top: 30px;
        font-size: 13px;
        color: #ccc;
    }




    @media (max-width: 600px) {
        .header-content {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
            padding: 10px 20px;
        }
        .separador-vertical {
            display: none;
        }
        
        .logo-header {
            display: none;

    }
        
                .card, .metodos-extra {
                    padding: 20px;
    margin: 20px
                }

                h2 {
                    font-size: 22px;
                }

                section h3 {
                    font-size: 16px;
                }
            }


            @keyframes pulse {
        0% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7);
        }
        70% {
            transform: scale(1.08);
            box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
        }
        100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
        }
    }

    .btn-flotante {
        animation: pulse 6s infinite;
    }
    @keyframes shimmer {
        0% {
            background-position: -200% 0;
        }
        100% {
            background-position: 200% 0;
        }
    }

    .btn-validar {
        background: linear-gradient(120deg, #2980b9 30%, #3498db 50%, #2980b9 70%);
        background-size: 200% auto;
        color: white;
        padding: 12px 24px;
        font-size: 16px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        width: 100%;
        animation: shimmer 4s infinite linear;
        transition: transform 0.3s ease;
    }

    .btn-validar:hover {
        transform: scale(1.05);
    }
    @keyframes movingLine {
    0% {
        background-position: -200% 0;
    }
    100% {
        background-position: 200% 0;
    }
    }

    .animated-border {
    background: linear-gradient(
        110deg,
        #2c3e50 10%,
        #34495e 50%,
        #2c3e50 90%
    );
    background-size: 300% 100%;
    animation: movingLine 6s linear infinite;
    }

    .nav-bar-pro {
        background: #34495e;
        box-shadow: inset 0 -2px 4px rgba(0,0,0,0.1);
        overflow-x: auto;
        white-space: nowrap;
    }

    .nav-list-pro {
        list-style: none;
        margin: 0;
        padding: 0 20px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 30px;
    }

    .nav-list-pro li a {
        color: white;
        font-weight: bold;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 30px;
        transition: background 0.3s, transform 0.3s;
        position: relative;
    }

    .nav-list-pro li a:hover {
        background: #2c3e50;
        transform: scale(1.05);
    }

    .nav-list-pro li a::after {
        content: '';
        position: absolute;
        height: 3px;
        background: #3498db;
        width: 0%;
        left: 50%;
        bottom: 4px;
        transition: all 0.3s ease-in-out;
        transform: translateX(-50%);
    }

    .nav-list-pro li a:hover::after {
        width: 60%;
    }

    @media (max-width: 768px) {
    .nav-list-pro {
        gap: 16px;
        padding: 10px;
    }

    .nav-list-pro li a {
        font-size: 14px;
        padding: 8px 12px;
    }
    }

.subnav {
  display: flex;
  gap: 20px;
  padding: 12px 30px;
  background: #dff3f9;
  border-bottom: 3px solid #3498db;
  animation: fadeIn 0.3s ease;
}

.subnav a {
  color: #2c3e50;
  font-weight: 600;
  text-decoration: none;
  background: #ecf0f1;
  padding: 8px 16px;
  border-radius: 20px;
  transition: all 0.3s ease;
}

.subnav a:hover {
  background: #3498db;
  color: white;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.usuario-barra {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10px;
  color: white;
  font-weight: bold;
}
.usuario-barra img {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: white;
  padding: 2px;
}
.usuario-barra span {
  font-weight: bold;
  font-size: 15px;
  white-space: nowrap;
}
.usuario-dropdown {
  position: absolute;
  top: 100%;
  right: 30px;
  margin-top: 5px;
  background: white;
  color: #2c3e50;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  padding: 15px 20px;
  min-width: 220px;
  display: none;
  z-index: 999;
  font-size: 15px;
  animation: fadeIn 0.3s ease-in-out;
    transition: all 0.3s ease-in-out;
}

.usuario-dropdown p {
  margin: 8px 0;
}

.usuario-barra {
  cursor: pointer;
  position: relative;
}
.btn-logout-dropdown {
  display: block;
  background: #e74c3c;
  color: white;
  text-align: center;
  padding: 10px 0;
  border-radius: 6px;
  text-decoration: none;
  font-weight: bold;
  transition: background 0.3s, transform 0.2s;
}

.btn-logout-dropdown:hover {
  background: #c0392b;
  transform: scale(1.03);
}


.menu-lateral {
  position: fixed;
  top: 0; /* Se fija desde la parte superior de la pantalla */
  left: 0;
  width: 250px;
  height: 100%; /* Que ocupe toda la altura */
  background: #f7f9fb;
  color: #2d3436;
  padding: 30px 20px;
  box-shadow: 4px 0 12px rgba(0,0,0,0.06);
  box-sizing: border-box;
  z-index: 900;
  overflow-y: auto; /* Para que el menú lateral pueda hacer scroll interno si hay muchos elementos */
  transition: transform .3s ease;
}


.menu-lateral h3 {
  font-size: 17px;
  margin-bottom: 20px;
  color: #0984e3;
  border-bottom: 2px solid #0984e3;
  padding-bottom: 10px;
  font-weight: 600;
}

.menu-lateral ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.menu-lateral ul li {
  margin-bottom: 14px;
}

.menu-lateral ul li a {
  color: #2d3436;
  text-decoration: none;
  font-weight: 500;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s;
  padding: 8px 12px;
  border-radius: 6px;
}

.menu-lateral ul li a:hover {
  background: #dcdde1;
  color: #0984e3;
  transform: translateX(4px);
}

.menu-toggle {
  display: none;
  position: fixed;
  top: 100px;
  left: 20px;
  background: #0984e3;
  color: white;
  border: none;
  border-radius: 6px;
  padding: 10px 14px;
  font-size: 20px;
  z-index: 1001;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  cursor: pointer;
}

/* ---------- Escritorio ---------- */
@media (min-width: 992px) {
  /* Botón para ocultar (dentro del menú) */
  .sidebar-toggle-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    border: 0;
    background: #e9eef5;
    color: #2c3e50;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
        padding: 0px 0px;
  }
  .sidebar-toggle-btn:hover { background: #dbe7f6; }

  /* Botón para mostrar (fuera, flotante en el borde izquierdo) */
  .sidebar-show-btn {
    position: fixed;
    top: 160px;           /* ajústalo si tu header es más alto/bajo */
    left: 10px;
    border: 0;
    background: #e9eef5;
    color: #2c3e50;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(0,0,0,.12);
    z-index: 1002;
    opacity: 0;                 /* oculto por defecto */
    pointer-events: none;       /* no clickeable por defecto */
    transition: opacity .2s ease;
    padding: 0px 0px;
  }
  .sidebar-show-btn:hover { background: #dbe7f6; }

  /* Cuando el body tiene el colapso activado */
  body.sidebar-collapsed .menu-lateral {
    transform: translateX(-100%);   /* se sale de pantalla a la izquierda */
  }
  body.sidebar-collapsed .main-content {
    margin-left: 0 !important;      /* el contenido ocupa todo */
  }
  body.sidebar-collapsed #sidebarShowBtn {
    opacity: 1;
    pointer-events: auto;
  }
}

/* ---------- Móvil/Tablet: no mostrar botón flotante de escritorio ---------- */
@media (max-width: 991px) {
  .sidebar-toggle-btn,
  .sidebar-show-btn { display: none !important; }
}




/* Responsive en móviles */
@media (max-width: 768px) {
  .menu-lateral {
    position: fixed; /* Mejor experiencia móvil */
    top: 0;
    left: 0;
    width: 250px;
    height: 100%;
    background: #fff; /* O el color de tu menú */
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    z-index: 9;
  }

  .menu-lateral.active {
    transform: translateX(0);
  }

  .main-content {
    margin-left: 0 !important;
    transition: margin-left 0.3s ease;
  }

  .menu-toggle {
    position: fixed; /* Para que siempre sea visible */
    top: 15px;
    left: 15px;
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    width: 30px;
    height: 30px;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0;
    z-index: 10;
  }

  .menu-toggle span {
    width: 100%;
    height: 3px;
    background-color: #333; /* Cambia según tu paleta */
    border-radius: 2px;
    transition: all 0.3s ease-in-out;
    transform-origin: 1px;
  }

  /* ANIMACIÓN AL ACTIVAR (hamburger a X) */
  .menu-toggle.active span:nth-child(1) {
    transform: rotate(45deg) translate(5px, 5px);
  }

  .menu-toggle.active span:nth-child(2) {
    opacity: 0;
  }

  .menu-toggle.active span:nth-child(3) {
    transform: rotate(-45deg) translate(5px, -5px);
  }
}
.fw-bold {
    font-weight: 700 !important;
}

.ms-2 {
    margin-left: .5rem !important;
}
.main-content {
    margin-left: 240px;
    padding: 30px;
}
.fw-bold {
    font-weight: 700 !important;
}
.ms-2 {
    margin-left: .5rem !important;
}

.tabla-wrap { overflow-x: auto; }
.tabla-pro {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 4px 14px rgba(0,0,0,0.06);
}
.tabla-pro thead th {
  background: #2c3e50;
  color: #fff;
  padding: 10px 12px;
  font-size: 13px;
  text-align: left;
}
.tabla-pro tbody td {
  background: #fff;
  padding: 10px 12px;
  border-bottom: 1px solid #eef2f6;
  font-size: 14px;
  vertical-align: middle;
}
.tabla-pro tbody tr:hover td { background: #f6fbff; }

.mini-img {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: cover;
  box-shadow: 0 2px 8px rgba(0,0,0,0.10);
}

.badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  font-weight: 800;
  font-size: 12px;
}
.badge-ok   { background: #e8f8ef; color: #1e8449; }
.badge-bad  { background: #fdecea; color: #c0392b; }
.badge-soft { background: #ecf0f1; color: #2c3e50; }
/* ===== Tabs (Producto / Anaquel) ===== */
.tab-switch{
  display:flex;
  gap:10px;
  background:#f3f6fb;
  padding:8px;
  border-radius:14px;
  border:1px solid #e5e9f2;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
  margin: 10px 0 18px 0;
}

.tab-btn{
  flex:1;
  border:0;
  cursor:pointer;
  border-radius:12px;
  padding:12px 14px;
  font-weight:800;
  font-size:15px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  background:transparent;
  color:#2c3e50;
  transition: all .25s ease;
}

.tab-btn:hover{
  transform: translateY(-1px);
  background: rgba(52,152,219,.10);
}

.tab-btn.active{
  background: #2980b9;
  color:#fff;
  box-shadow: 0 10px 18px rgba(41,128,185,.20);
}

.tab-pane{ display:none; }
.tab-pane.active{ display:block; }

.tab-subtitle{
  text-align:center;
  color:#34495e;
  font-weight:600;
  margin: 0 0 12px 0;
  font-size: 14px;
}

.form-pro{ margin-bottom: 18px; }
/* ===== Alertas profesionales ===== */
.alerta{
  display:flex;
  align-items:center;
  gap:10px;
  padding:12px 14px;
  border-radius:12px;
  font-weight:700;
  margin-bottom:14px;
  border:1px solid transparent;
}
.alerta i{ font-size:18px; }

.alerta.ok{
  background:#e8f8ef;
  color:#1e8449;
  border-color:#bfead0;
}
.alerta.bad{
  background:#fdecea;
  color:#c0392b;
  border-color:#f5c6cb;
}
.alerta.warn{
  background:#fff7e6;
  color:#8a6d3b;
  border-color:#ffe0a3;
}

/* Opcional: micro-ajustes "corporativos" */
.card h2{
  letter-spacing:.2px;
}
.tab-subtitle{
  opacity:.92;
}
.card-opcion i{
  margin-right:8px;
}
/* ===== Explicación y distribución por ubicación ===== */
.hint-box{
  background:#f6f8fb;
  border:1px solid #e5e9f2;
  border-radius:12px;
  padding:14px 14px;
  margin: 10px 0 14px 0;
}
.hint-title{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight:800;
  color:#2c3e50;
  margin-bottom:8px;
}
.hint-list{
  margin:0;
  padding-left:18px;
  color:#34495e;
  font-size:14px;
  line-height:1.6;
}
.meta-line{
  margin: 0 0 10px 0;
  color:#34495e;
  font-size:14px;
}

.qty-cell{ line-height:1.15; }
.qty-main{
  font-weight:900;
  font-size:15px;
  color:#2c3e50;
}
.qty-sub{
  margin-top:6px;
  font-size:12px;
  color:#6c757d;
}
.qty-cell.other .qty-main{ color:#6c757d; } /* plomo/gris para “En otros” */

/* Barra de distribución */
.distbar{
  margin-top:8px;
  height:8px;
  background:#e9eef5;
  border-radius:999px;
  overflow:hidden;
}
.distbar span{
  display:block;
  height:100%;
  background:#2980b9;
  border-radius:999px;
}




/* =========================================================
   REDISEÑO PRO - SCANNER / VALIDACIÓN NORTE 360
   Mantiene la lógica: DE0215 = producto, DE0215-1 = trazabilidad
   ========================================================= */
:root{
  --scan-bg:#eef3f8;
  --scan-ink:#0f172a;
  --scan-muted:#64748b;
  --scan-line:#dbe4ef;
  --scan-primary:#172033;
  --scan-primary-2:#2c3e50;
  --scan-accent:#0ea5e9;
  --scan-green:#16a34a;
  --scan-red:#dc2626;
  --scan-orange:#f59e0b;
  --scan-card:#ffffff;
}
body{
  background:linear-gradient(180deg,#f8fbff 0%,var(--scan-bg) 48%,#e9f0f7 100%)!important;
  color:var(--scan-ink);
}
hr{display:none!important;}
.scanner-shell{
  width:min(1500px, 100%);
  margin:0 auto;
  padding:24px 26px 34px;
  box-sizing:border-box;
}

/* Cuando el menú lateral está visible */
@media (min-width: 1201px){
  body:not(.sidebar-collapsed) .scanner-shell{
    width:min(1500px, calc(100% - 250px));
    margin-left:250px;
    margin-right:auto;
  }
}

/* Cuando ocultas el menú lateral */
body.sidebar-collapsed .scanner-shell{
  margin-left:auto!important;
  margin-right:auto!important;
}
.scanner-hero{
  position:relative;
  overflow:hidden;
  border-radius:22px;
  padding:24px;
  margin:0 auto 16px;
  color:#fff;
  background:radial-gradient(circle at top right,rgba(14,165,233,.24),transparent 34%),linear-gradient(135deg,#172033,#2c3e50 62%,#111827);
  box-shadow:0 18px 40px rgba(15,23,42,.18);
  display:grid;
  grid-template-columns:minmax(280px,1fr) auto;
  gap:20px;
  align-items:center;
}
.scanner-hero:after{
  content:"";
  position:absolute;
  left:24px;right:24px;bottom:0;
  height:3px;
  border-radius:999px;
  opacity:.95;
}
.scanner-eyebrow{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:rgba(255,255,255,.10);
  border:1px solid rgba(255,255,255,.14);
  border-radius:999px;
  padding:6px 11px;
  color:#dbeafe;
  font-size:.82rem;
  font-weight:850;
  margin-bottom:10px;
}
.scanner-hero h1{
  margin:0;
  font-size:clamp(1.55rem,2.2vw,2.35rem);
  font-weight:950;
  letter-spacing:-.04em;
}
.scanner-hero p{
  margin:7px 0 0;
  color:#cbd5e1;
  max-width:760px;
  font-size:.98rem;
}
.scanner-hero-logo{
  width:145px;
  max-width:100%;
  filter:drop-shadow(0 14px 18px rgba(0,0,0,.24));
}
.scanner-grid{
  display:grid;
  grid-template-columns:minmax(0,1fr) 360px;
  gap:16px;
  align-items:start;
}
.scanner-panel,
.scan-side-card,
.scan-methods-pro,
.camera-box{
  background:#fff;
  border:1px solid var(--scan-line);
  border-radius:20px;
  box-shadow:0 14px 34px rgba(15,23,42,.09);
}
.scanner-panel{
  overflow:hidden;
}
.scanner-panel-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:14px;
  padding:18px 20px;
  background:linear-gradient(180deg,#fff,#f8fafc);
  border-bottom:1px solid #e8eef6;
}
.scanner-panel-head h2{
  text-align:left!important;
  margin:0!important;
  color:#172033!important;
  font-size:1.15rem!important;
  font-weight:950!important;
  display:flex;
  align-items:center;
  gap:9px;
}
.scanner-panel-head p{
  margin:4px 0 0;
  color:#64748b;
  font-size:.88rem;
}
.scan-mode-pill{
  display:inline-flex;
  align-items:center;
  gap:7px;
  border-radius:999px;
  padding:7px 11px;
  background:#eff6ff;
  color:#1d4ed8;
  font-size:.82rem;
  font-weight:900;
  white-space:nowrap;
}
.scanner-panel-body{padding:18px 20px 20px;}
.scanner-logo-soft{
  width:118px;
  max-width:45%;
  height:auto;
  display:block;
  margin:0 auto 12px;
  opacity:.96;
  filter:drop-shadow(0 8px 12px rgba(15,23,42,.10));
}
.tab-switch{
  margin:0 0 16px!important;
  padding:7px!important;
  background:#f3f7fb!important;
  border:1px solid #dbe4ef!important;
  border-radius:16px!important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.9)!important;
}
.tab-btn{
  min-height:45px!important;
  border-radius:13px!important;
  color:#334155!important;
  font-weight:900!important;
  letter-spacing:.01em;
  width:100%!important;
}
.tab-btn.active{
  background:#172033!important;
  color:#fff!important;
  box-shadow:0 12px 22px rgba(23,32,51,.18)!important;
}
.tab-btn:hover{background:#e0f2fe!important;color:#075985!important;transform:translateY(-1px)!important;}
.tab-btn.active:hover{background:#172033!important;color:#fff!important;}
.tab-subtitle{
  text-align:left!important;
  background:#f8fafc;
  border:1px solid #e8eef6;
  border-radius:14px;
  padding:11px 13px;
  color:#475569!important;
  font-weight:750!important;
  margin:0 0 12px!important;
}
.scan-form-line{
  display:grid;
  grid-template-columns:minmax(0,1fr) 170px;
  gap:10px;
  align-items:center;
  margin-bottom:16px;
}
.scan-input-wrap{
  position:relative;
}
.scan-input-wrap i{
  position:absolute;
  left:13px;
  top:50%;
  transform:translateY(-50%);
  color:#64748b;
  font-size:1.05rem;
}
.scan-input-wrap input[type=text],
.scanner-panel input[type=text]{
  width:100%!important;
  height:48px!important;
  padding:0 14px 0 42px!important;
  border-radius:14px!important;
  border:1px solid #dbe4ef!important;
  background:#fff!important;
  color:#0f172a!important;
  font-weight:850;
  margin:0!important;
  box-shadow:0 1px 0 rgba(15,23,42,.02)!important;
}
.scan-input-wrap input:focus,
.scanner-panel input[type=text]:focus{
  outline:none!important;
  border-color:#0ea5e9!important;
  box-shadow:0 0 0 .20rem rgba(14,165,233,.14)!important;
}
.btn-validar{
  height:48px!important;
  border-radius:14px!important;
  background:linear-gradient(135deg,#172033,#0f172a)!important;
  border:1px solid #172033!important;
  color:#fff!important;
  font-weight:950!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  gap:8px!important;
  padding:0 14px!important;
  width:100%!important;
  animation:none!important;
  box-shadow:0 12px 22px rgba(15,23,42,.15);
}
.btn-validar:hover{transform:translateY(-1px)!important;background:#0f172a!important;}
.resultado{
  font-size:.95rem!important;
  color:#334155!important;
  line-height:1.45!important;
}
.resultado:empty{display:none;}
.alerta{
  border-radius:15px!important;
  padding:13px 14px!important;
  margin:0 0 14px!important;
  font-weight:900!important;
  box-shadow:0 8px 18px rgba(15,23,42,.05);
}
.alerta.ok{background:#ecfdf5!important;color:#166534!important;border-color:#bbf7d0!important;}
.alerta.bad{background:#fef2f2!important;color:#991b1b!important;border-color:#fecaca!important;}
.alerta.warn{background:#fffbeb!important;color:#92400e!important;border-color:#fde68a!important;}
.scanner-panel .resultado section{
  background:#fff;
  border:1px solid #e8eef6!important;
  border-radius:18px;
  padding:16px!important;
  margin:0 0 14px!important;
  box-shadow:0 10px 24px rgba(15,23,42,.06);
}
.scanner-panel .resultado section h3{
  margin:0 0 12px!important;
  padding:0 0 10px;
  border-bottom:1px solid #eef2f7;
  color:#172033!important;
  font-size:1rem!important;
  font-weight:950!important;
  display:flex;
  align-items:center;
  gap:8px;
}
.scanner-panel .resultado ul{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
  margin:0!important;
  padding:0!important;
}
.scanner-panel .resultado ul li{
  margin:0!important;
  background:#f8fafc;
  border:1px solid #eef2f7;
  border-radius:13px;
  padding:10px 11px;
  color:#334155;
}
.scanner-panel .resultado ul li strong{
  display:block;
  color:#64748b;
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.035em;
  margin-bottom:3px;
}
.codigo{
  display:inline-flex!important;
  align-items:center;
  justify-content:center;
  min-width:180px;
  background:#eff6ff!important;
  border:1px solid #bfdbfe;
  color:#1d4ed8!important;
  padding:11px 16px!important;
  border-radius:999px!important;
  font-size:1.12rem!important;
  letter-spacing:.035em;
  box-shadow:0 8px 18px rgba(29,78,216,.08);
}
.img-block{
  margin-top:14px!important;
  background:linear-gradient(180deg,#f8fafc,#eef3f8);
  border:1px solid #e8eef6;
  border-radius:18px;
  padding:14px;
  text-align:center;
}
.img-block p{
  margin:0 0 10px!important;
  color:#64748b!important;
  font-size:.76rem;
  text-transform:uppercase;
  letter-spacing:.04em;
  font-weight:950!important;
}
.img-block img{
  max-height:230px!important;
  object-fit:contain!important;
  border-radius:14px!important;
  box-shadow:none!important;
  background:#fff;
  padding:8px;
}
.no-image{
  background:#f8fafc;
  border:1px dashed #cbd5e1;
  border-radius:14px;
  padding:18px;
  color:#94a3b8!important;
  text-align:center;
  font-weight:800;
}
.scanner-side{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.scan-side-card,
.scan-methods-pro{padding:16px;}
.scan-side-card h3,
.scan-methods-pro h3{
  margin:0 0 10px!important;
  color:#172033!important;
  font-size:1rem!important;
  font-weight:950!important;
  display:flex;
  align-items:center;
  gap:8px;
}
.rule-list{display:grid;gap:9px;margin:0;padding:0;list-style:none;}
.rule-item{
  display:flex;
  gap:10px;
  align-items:flex-start;
  background:#f8fafc;
  border:1px solid #e8eef6;
  border-radius:14px;
  padding:10px;
}
.rule-ic{
  width:34px;height:34px;
  border-radius:11px;
  display:grid;
  place-items:center;
  background:#eff6ff;
  color:#1d4ed8;
  flex:0 0 auto;
}
.rule-item strong{display:block;color:#0f172a;font-size:.88rem;}
.rule-item span{display:block;color:#64748b;font-size:.80rem;margin-top:2px;line-height:1.35;}
.scan-methods-pro .opciones-validacion{
  display:grid!important;
  grid-template-columns:1fr;
  gap:10px!important;
}
.card-opcion{
  width:100%!important;
  min-height:46px;
  border-radius:14px!important;
  padding:0 14px!important;
  background:#fff!important;
  color:#172033!important;
  border:1px solid #dbe4ef!important;
  box-shadow:0 8px 18px rgba(15,23,42,.06)!important;
  display:flex!important;
  align-items:center!important;
  justify-content:center!important;
  gap:9px;
  font-size:.92rem!important;
  font-weight:950!important;
}
.card-opcion:hover{
  background:#172033!important;
  color:#fff!important;
  transform:translateY(-1px)!important;
}
.camera-box{
  display:none;
  margin-top:16px;
  padding:16px;
  overflow:hidden;
}
#reader.camera-reader,
.camera-reader{
  width:100%!important;
  max-width:780px!important;
  margin:16px auto 0!important;
  display:none;
  background:#fff;
  border:1px solid #dbe4ef;
  border-radius:20px;
  padding:16px;
  box-shadow:0 14px 34px rgba(15,23,42,.10);
}
#reader video{border-radius:16px!important;}
#reader button{
  width:auto!important;
  border-radius:12px!important;
  background:#172033!important;
  color:#fff!important;
  padding:9px 12px!important;
  font-weight:850!important;
}
.scan-result{
  max-width:780px;
  margin:10px auto 0!important;
  padding:10px 14px;
  border-radius:14px;
  background:#eff6ff;
  color:#1d4ed8;
  border:1px solid #bfdbfe;
  text-align:center!important;
}
.hint-box{
  background:#f8fafc!important;
  border:1px solid #e8eef6!important;
  border-radius:16px!important;
  padding:14px!important;
}
.tabla-wrap{border-radius:16px!important;border:1px solid #e8eef6;box-shadow:0 10px 24px rgba(15,23,42,.06)!important;}
.tabla-pro{box-shadow:none!important;border-radius:0!important;}
.tabla-pro thead th{background:#172033!important;font-size:.75rem!important;text-transform:uppercase;letter-spacing:.035em;}
.tabla-pro tbody td{font-size:.86rem!important;}
.mini-img{width:52px!important;height:52px!important;border-radius:13px!important;object-fit:contain!important;background:#f8fafc;border:1px solid #e8eef6;padding:4px;}
.distbar span{background:linear-gradient(90deg,#0ea5e9,#2563eb)!important;}
.btn-flotante{z-index:1000!important;}
@media(max-width:1200px){
  .scanner-shell{
    width:100%;
    margin:0 auto!important;
    padding:18px;
  }

  .scanner-grid{grid-template-columns:1fr;}
  .scanner-side{grid-template-columns:1fr 1fr;display:grid;}
  .scanner-hero{grid-template-columns:1fr;}
  .scanner-hero-logo{display:none;}
}
@media(max-width:760px){
  .scanner-shell{padding:14px;}
  .scanner-panel-head{flex-direction:column;}
  .scanner-side{display:flex;}
  .scan-form-line{grid-template-columns:1fr;}
  .scanner-panel .resultado ul{grid-template-columns:1fr;}
  .scanner-hero{padding:18px;}
  .scanner-panel-body{padding:14px;}
}

    </style>
</head>
<body>
<?php
function calcularEdad($fechaNacimiento) {
    $hoy = new DateTime();
    $nac = new DateTime($fechaNacimiento);
    $edad = $hoy->diff($nac);
    return $edad->y;
}

$edad = calcularEdad("2000-04-12"); // ejemplo
?>
<header class="main-header animated-border">
  <div class="header-content">
    <a href="../index.php"">
        <div class="logo-bloque">
            <img src="../img/norte360.png" alt="Logo Empresa" class="logo-header">
        </div>
    </a>

    <div class="separador-vertical"></div>
        <a href="javascript:location.reload()">
            <div class="logo-bloque">
            <img src="../img/completo.png" alt="Logo Sistema" class="logo-header2">
            </div>
        </a>


    <div class="usuario-contenedor" style="margin-left:auto; position: relative;">
      <div class="usuario-barra" onclick="toggleDropdown()">
        <span>Hola, <?= htmlspecialchars($_SESSION['usuario']) ?></span>
        <img src="../img/icons/user.png" alt="Usuario">
      </div>
      <div class="usuario-dropdown" id="usuarioDropdown">
        <p><strong>Nombre:</strong> <?= htmlspecialchars($_SESSION['usuario']) ?></p>
        <p><strong>DNI:</strong> <?= htmlspecialchars($_SESSION['DNI']) ?></p>
        <p><strong>Edad:</strong> <?= $edad ?> años</p>
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%); margin: 12px 0; border: none; border-top: 1px solid #eee;">
        <p><strong>Rol:</strong> <?= htmlspecialchars($_SESSION['web_rol']) ?></p>
        <a href="../login/logout.php" class="btn-logout-dropdown">Cerrar sesión</a>
      </div>
    </div>

    </div>

</header>

<nav id="nav-modulos" class="nav-bar-pro">
  <ul class="nav-list-pro">
  <?php
    if ($_SESSION['web_rol'] === 'Admin' || in_array(6, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-personal\')">👥 Recursos Humanos</a></li>';
    }
    if ($_SESSION['web_rol'] === 'Admin' || in_array(5, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-mantenimiento\')">🔧 Mantenimiento</a></li>';
    }
    if ($_SESSION['web_rol'] === 'Admin' || in_array(3, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-inventario\')">📦 Inventario</a></li>';
    }
  ?>
  </ul>
</nav>

<div id="modulo-personal" class="subnav" style="display: none;">
  <a href="../01_contratos/nregrcdn_h.php">➕ Nuevo Trabajador</a>
  <a href="../01_entrevistas/reentrev.php">➕ Nueva Entrevista</a>
  <a href="../01_contratos/documentacion/agregadocu.php">➕ Nueva Documentación</a>
  <a href="../01_contratos/nlaskdrcdn_h.php">👤 Personal</a>
  <a href="../01_entrevistas/bvisentrevisaf.php">📝 Entrevistas</a>
  <a href="../01_contratos/dorrhcdn.php">📁 Documentación</a>
</div>

<div id="modulo-inventario" class="subnav" style="display: none;">
  <a href="../01_almacen/scanner.php"> 🏷️ Código de Barra</a>
  <a href="../01_almacen/gen_np9823.php">📋 Catálogo Productos</a>
</div>
<div id="modulo-mantenimiento" class="subnav" style="display: none;">
  <a href="../01_amantenimiento\lista_cheklist.php">📝 CheckList</a>
</div>

<button class="menu-toggle" id="btnMenuToggle" onclick="toggleMenu()" aria-label="Menú"><span></span><span></span><span></span></button>

<!-- SIDEBAR FIJO EN DESKTOP -->
<nav class="menu-lateral" id="menuLateral">
  <button class="sidebar-toggle-btn" id="btnHideSidebar" aria-label="Ocultar menú">
    <i class="bi bi-chevron-left"></i>
  </button>

  <div class="menu-logo">
    <img src="../img/norte360_black.png" alt="Logo" style="height:40px; vertical-align: middle;">
    <span class="fw-bold ms-2" style="color:#2c3e50;">Norte 360°</span>
  </div>
  <ul class="menu-list">
    <h3>Inventario</h3>
    <li><a href="gen_np9823.php"><i class="bi bi-boxes me-2"></i> Catálogo Productos</a></li>
    <li><a href="scanner.php"><i class="bi bi-upc-scan me-2"></i> Código de Barras</a></li>
    <li><a href="movimientos_ofi.php"><i class="bi bi-arrow-left-right me-2"></i> Movimientos</a></li>
  </ul>
</nav>
<button class="sidebar-show-btn" id="sidebarShowBtn" aria-label="Mostrar menú">
  <i class="bi bi-chevron-right"></i>
</button>



<div class="scanner-shell">
  <section class="scanner-hero">
    <div>
      <div class="scanner-eyebrow"><i class="bi bi-upc-scan"></i> Inventario · Scaner</div>
      <h1>Scanner de Productos y Trazabilidad</h1>
    </div>
    <img src="../img/cdn_etiquetas_lg.png" alt="Scanner Norte 360" class="scanner-hero-logo">
  </section>

  <div class="scanner-grid">
    <section class="scanner-panel" id="cardBusqueda" data-tab="<?= htmlspecialchars($tab_activa) ?>">
      <div class="scanner-panel-head">
        <div>
          <h2><i class="bi bi-search"></i> Búsqueda operativa</h2>
        </div>
        <span class="scan-mode-pill"><i class="bi bi-lightning-charge"></i> Lectura rápida</span>
      </div>

      <div class="scanner-panel-body">
        <img src="../img/cdn_etiquetas_lg.png" alt="Logo del sistema" class="scanner-logo-soft">

        <div class="tab-switch">
          <button type="button" class="tab-btn" data-tab="producto">
            <i class="bi bi-upc-scan"></i> Producto / Etiqueta
          </button>
          <button type="button" class="tab-btn" data-tab="anaquel">
            <i class="bi bi-grid-3x3-gap"></i> Anaquel
          </button>
        </div>

        <div class="tab-pane" id="tab-producto">

          <form method="get" class="form-pro">
            <div class="scan-form-line">
              <div class="scan-input-wrap">
                <i class="bi bi-upc"></i>
                <input
                  type="text"
                  name="codigo"
                  placeholder="Etiqueta de Producto o ítem ..."
                  value="<?= htmlspecialchars($codigo) ?>"
                  autocomplete="off"
                  <?= ($tab_activa === 'producto' ? 'required' : '') ?>
                >
              </div>
              <button type="submit" class="btn-validar"><i class="bi bi-check2-circle"></i> Validar</button>
            </div>
          </form>

          <div class="resultado"><?= $mensaje ?></div>
        </div>

        <div class="tab-pane" id="tab-anaquel">
          <div class="tab-subtitle">
            <i class="bi bi-info-circle"></i>
            Consulta un anaquel para visualizar productos asociados, stock y distribución por ubicación.
          </div>

          <form method="get" class="form-pro">
            <div class="scan-form-line">
              <div class="scan-input-wrap">
                <i class="bi bi-grid-3x3-gap"></i>
                <input
                  type="text"
                  name="anaquel"
                  placeholder="Código del anaquel..."
                  value="<?= htmlspecialchars($anaquel) ?>"
                  autocomplete="off"
                  <?= ($tab_activa === 'anaquel' ? 'required' : '') ?>
                >
              </div>
              <button type="submit" class="btn-validar"><i class="bi bi-search"></i> Buscar</button>
            </div>
          </form>

          <div class="resultado"><?= $mensaje_anaquel ?></div>
        </div>
      </div>
    </section>

    <aside class="scanner-side">
      <div class="scan-side-card">
        <h3><i class="bi bi-diagram-3"></i> Regla de lectura</h3>
        <ul class="rule-list">
          <li class="rule-item">
            <div class="rule-ic"><i class="bi bi-box-seam"></i></div>
            <div><strong>Producto general</strong><span>Muestra stock, estado, categoría e imagen.</span></div>
          </li>
          <li class="rule-item">
            <div class="rule-ic"><i class="bi bi-geo-alt"></i></div>
            <div><strong>Item específico</strong><span>Muestra trazabilidad, anaquel, sede y movimiento.</span></div>
          </li>
          <li class="rule-item">
            <div class="rule-ic"><i class="bi bi-grid-3x3-gap"></i></div>
            <div><strong>Anaquel</strong><span>Usa la pestaña Anaquel para revisar distribución de productos por ubicación.</span></div>
          </li>
        </ul>
      </div>

      <div class="scan-methods-pro">
        <h3><i class="bi bi-camera"></i> Métodos de captura</h3>
        <div class="opciones-validacion">
          <label class="card-opcion">
            <i class="bi bi-upload"></i> Subir imagen
            <input type="file" id="imgUpload" accept="image/*" hidden>
          </label>

          <button id="startScanner" class="card-opcion" type="button">
            <i class="bi bi-camera-video"></i> Usar cámara
          </button>
        </div>
      </div>
    </aside>
  </div>

  <div id="reader" class="camera-reader"></div>
  <p id="scan-result" class="scan-result" style="display:none;"></p>
</div>

    <!-- PRIMERA LIBERÍA -->
    <script src="https://unpkg.com/@ericblade/quagga2@1.2.6/dist/quagga.min.js"></script>
    <script>

        document.getElementById('imgUpload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function() {
                const img = new Image();
                img.onload = function() {
                    Quagga.decodeSingle({
                        src: img.src,
                        numOfWorkers: 0,
                        inputStream: {
                            size: 800  // ajusta tamaño si es necesario
                        },
                        decoder: {
                            readers: ["code_128_reader"] // Usa el que generas
                        }
                    }, function(result) {
                        if (result && result.codeResult) {
                            const tab = (window.__scanTab || 'producto');
                            const param = (tab === 'anaquel') ? 'anaquel' : 'codigo';
                            window.location.href = `?${param}=${encodeURIComponent(result.codeResult.code)}`;
                        } else {
                            alert("No se pudo leer el código desde la imagen.");
                        }
                    });
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    </script>

    <!-- SEGUNDA LIBERÍA -->
    <!-- HTML5 QRCode Scanner -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
        let encontrado = false;
// Botón para activar el lector de cámara
document.getElementById('startScanner').addEventListener('click', function() {
    document.getElementById('reader').style.display = 'block';
    this.style.display = 'none'; // ocultar botón
});

        const qrScanner = new Html5QrcodeScanner("reader", {
            fps: 10,
            qrbox: { width: 250, height: 100 },
            formatsToSupport: [ Html5QrcodeSupportedFormats.CODE_128 ]
        });

        qrScanner.render(
            (decodedText) => {
                encontrado = true;
                const scanResult = document.getElementById("scan-result");
                scanResult.style.display = "block";
                scanResult.innerText = "Código detectado: " + decodedText;
                setTimeout(() => {
                    const tab = (window.__scanTab || 'producto');
                    const param = (tab === 'anaquel') ? 'anaquel' : 'codigo';
                    window.location.href = `?${param}=${encodeURIComponent(decodedText)}`;
                }, 1000);
            },
            (errorMessage) => {
                // Errores de escaneo individuales (opcional)
            }
        );

        // Detectar cuando se detiene el escaneo (por botón STOP)
        const observer = new MutationObserver(() => {
            const btnStop = document.querySelector("#reader button[aria-label='Stop scanning']");
            if (btnStop) {
                btnStop.addEventListener('click', () => {
                    if (!encontrado) {
                        const scanResult = document.getElementById("scan-result");
                        scanResult.style.display = "block";
                        scanResult.innerText = "No se detectó ningún código.";
                    }
                });
            }
        });

        observer.observe(document.getElementById("reader"), { childList: true, subtree: true });
    </script>
    
    <script>
        // Si la URL tiene parámetros tipo "?codigo=algo", lo limpiamos en un refresh
        if (window.performance && window.performance.navigation.type === window.performance.navigation.TYPE_RELOAD) {
            if (window.location.search.includes("codigo=") || window.location.search.includes("anaquel=")) {
                window.location.href = window.location.pathname;
            }
        }
    </script>
<!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
<a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
</a>

<footer class="main-footer animated-border">
  <div class="footer-top">
    <img src="../img/norte360.png" alt="Logo Empresa" class="logo-header3">
    <div class="footer-info">
      <p class="footer-title">Contáctanos</p>
      <div class="footer-cajas">
        <div class="footer-box"><img src="../img/icons/facebook.png" alt="Función 1"></div>
        <div class="footer-box"><img src="../img/icons/social.png" alt="Función 2"></div>
      </div>
    </div>
  </div>
  <p class="footer-copy">© <?= date('Y') ?> Norte 360° (v1.0.6). Todos los derechos reservados.</p>
</footer>

<script>
function mostrarSubmenu(id) {
  const seleccionado = document.getElementById(id);
  const estaVisible = seleccionado && seleccionado.style.display === 'flex';

  document.querySelectorAll('.subnav').forEach(el => el.style.display = 'none');

  if (!estaVisible && seleccionado) {
    seleccionado.style.display = 'flex';
  }
}
</script>

<script>
function toggleDropdown() {
  const dropdown = document.getElementById("usuarioDropdown");
  dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
}

// Cierra si haces clic fuera
document.addEventListener("click", function (e) {
  const barra = document.querySelector(".usuario-barra");
  const dropdown = document.getElementById("usuarioDropdown");

  if (!barra.contains(e.target) && !dropdown.contains(e.target)) {
    dropdown.style.display = "none";
  }
});
</script>
<script>
function toggleMenu() {
  const menu = document.querySelector('.menu-lateral');
  menu.classList.toggle('active');
}
</script>

<script>
function toggleDropdown() {
  const dropdown = document.getElementById("usuarioDropdown");
  dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
}

// Cierra si haces clic fuera
document.addEventListener("click", function (e) {
  const barra = document.querySelector(".usuario-barra");
  const dropdown = document.getElementById("usuarioDropdown");

  if (!barra.contains(e.target) && !dropdown.contains(e.target)) {
    dropdown.style.display = "none";
  }
});
</script>

<script>
function toggleMenu() {
  const menu = document.querySelector('.menu-lateral');
  menu.classList.toggle('active');
}
</script>

<script>
  (function () {
    const body = document.body;
    const hideBtn = document.getElementById('btnHideSidebar');
    const showBtn = document.getElementById('sidebarShowBtn');
    const STORAGE_KEY = 'sidebarCollapsed';

    function setSidebar(collapsed) {
      body.classList.toggle('sidebar-collapsed', collapsed);
      try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch(e) {}
    }

    // Estado inicial desde localStorage (solo aplica en escritorio)
    const prefersCollapsed = (localStorage.getItem(STORAGE_KEY) === '1');
    if (window.matchMedia('(min-width: 992px)').matches && prefersCollapsed) {
      setSidebar(true);
    }

    // Eventos
    if (hideBtn) hideBtn.addEventListener('click', () => setSidebar(true));
    if (showBtn) showBtn.addEventListener('click', () => setSidebar(false));

    // Si cambias de tamaño de ventana, respeta el estado en escritorio y limpia en móvil
    window.addEventListener('resize', () => {
      if (window.matchMedia('(min-width: 992px)').matches) {
        const collapsed = (localStorage.getItem(STORAGE_KEY) === '1');
        body.classList.toggle('sidebar-collapsed', collapsed);
      } else {
        body.classList.remove('sidebar-collapsed'); // en móvil usamos tu menú responsive existente
      }
    });
  })();
</script>

<script>
  (function(){
    const card = document.getElementById('cardBusqueda');
    if(!card) return;

    const btns = card.querySelectorAll('.tab-btn');
    const paneProd = document.getElementById('tab-producto');
    const paneAna  = document.getElementById('tab-anaquel');

    const inputProd = card.querySelector('input[name="codigo"]');
    const inputAna  = card.querySelector('input[name="anaquel"]');

    // Estado global para que el escaneo (imagen/cámara) use el modo activo
    window.__scanTab = card.getAttribute('data-tab') || 'producto';

    function setTab(tab){
      window.__scanTab = tab;

      btns.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
      paneProd.classList.toggle('active', tab === 'producto');
      paneAna.classList.toggle('active', tab === 'anaquel');

      // Importante: required dinámico para que no bloquee el submit del otro form
      if(inputProd) inputProd.required = (tab === 'producto');
      if(inputAna)  inputAna.required  = (tab === 'anaquel');

      // UX: focus directo
      setTimeout(() => {
        if(tab === 'producto' && inputProd) inputProd.focus();
        if(tab === 'anaquel'  && inputAna)  inputAna.focus();
      }, 50);
    }

    // Inicial según PHP (si vienes con ?anaquel=... o ?codigo=...)
    setTab(window.__scanTab);

    btns.forEach(b => {
      b.addEventListener('click', () => setTab(b.dataset.tab));
    });
  })();
</script>

</body>
</html>
