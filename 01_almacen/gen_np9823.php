<?php
session_start();
//01_almacen\gen_np9823.php:
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

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_num($n) {
    if ($n === null || $n === '') return '0';
    $v = (float)$n;
    $txt = number_format($v, 4, '.', '');
    return rtrim(rtrim($txt, '0'), '.');
}

function estado_stock_class($estado, $stock) {
    $e = mb_strtolower(trim((string)$estado), 'UTF-8');
    $s = (float)($stock ?? 0);
    if ($s <= 0 || strpos($e, 'sin') !== false) return 'stock-zero';
    if (strpos($e, 'bajo') !== false || strpos($e, 'mín') !== false || strpos($e, 'min') !== false) return 'stock-low';
    return 'stock-ok';
}

function stmt_get_all($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function stmt_get_one($conn, $sql, $types = '', $params = []) {
    $rows = stmt_get_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function build_page_url($page) {
    $q = $_GET;
    $q['pagina'] = $page;
    return '?' . http_build_query($q);
}

function mostrar_imagen($img, $etiqueta = '') {
    if (empty($img)) {
        return "
        <div class='img-block product-img-placeholder'>
            <i class='bi bi-image'></i>
            <span>Sin imagen</span>
        </div>";
    }

    if (is_string($img) && strlen($img) < 200 && preg_match('/\.(jpe?g|png|gif|webp)$/i', $img)) {
        $ruta = "img/" . basename($img);
        if (is_file($ruta)) {
            return "<div class='img-block'>"
                 . ($etiqueta ? "<p>" . h($etiqueta) . "</p>" : "")
                 . "<img loading='lazy' src='" . h($ruta) . "' alt='Producto'>"
                 . "</div>";
        }
    }

    $mime = 'image/jpeg';
    if (class_exists('finfo')) {
        $f = new finfo(FILEINFO_MIME_TYPE);
        $det = @$f->buffer($img);
        if ($det) $mime = $det;
    }
    $base64 = base64_encode($img);

    return "<div class='img-block'>"
         . ($etiqueta ? "<p>" . h($etiqueta) . "</p>" : "")
         . "<img loading='lazy' src='data:$mime;base64,$base64' alt='Producto'>"
         . "</div>";
}

// ---------- Filtros ----------
$por_pagina = 100;
$pagina = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

$categoria_filtro = trim($_GET['categoria'] ?? '');
$buscar_filtro    = trim($_GET['buscar'] ?? '');
$codigo_filtro    = trim($_GET['codigo'] ?? '');

$where = " WHERE 1=1 ";
$types = '';
$params = [];

if ($categoria_filtro !== '') {
    $where .= " AND c.clm_alm_categoria_descripcion = ? ";
    $types .= 's';
    $params[] = $categoria_filtro;
}

if ($codigo_filtro !== '') {
    $where .= " AND p.clm_alm_producto_codigo = ? ";
    $types .= 's';
    $params[] = $codigo_filtro;
}

if ($buscar_filtro !== '') {
    $palabras = preg_split('/\s+/', $buscar_filtro, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($palabras as $palabra) {
        $where .= " AND (
            p.clm_alm_producto_NOMBRE LIKE CONCAT('%', ?, '%')
            OR p.clm_alm_producto_codigo LIKE CONCAT('%', ?, '%')
            OR c.clm_alm_categoria_NOMBRE LIKE CONCAT('%', ?, '%')
            OR c.clm_alm_categoria_descripcion LIKE CONCAT('%', ?, '%')
            OR cod.clm_alm_codigo_NOMBRE LIKE CONCAT('%', ?, '%')
        ) ";
        $types .= 'sssss';
        array_push($params, $palabra, $palabra, $palabra, $palabra, $palabra);
    }
}

// ---------- Conteo ----------
$count_sql = "
    SELECT COUNT(*) AS total
    FROM tb_alm_producto p
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
    $where
";
$count_row = stmt_get_one($conn, $count_sql, $types, $params);
$total = (int)($count_row['total'] ?? 0);
$total_paginas = max((int)ceil($total / $por_pagina), 1);
if ($pagina > $total_paginas) $pagina = $total_paginas;
$offset = ($pagina - 1) * $por_pagina;

// ---------- Data paginada ----------
$list_sql = "
    SELECT 
        p.clm_alm_producto_id AS id_producto,
        p.clm_alm_producto_NOMBRE AS producto,
        p.clm_alm_producto_IMG AS producto_img,
        p.clm_alm_producto_codigo AS codigo_producto,
        c.clm_alm_categoria_NOMBRE AS categoria_corta,
        c.clm_alm_categoria_descripcion AS categoria,
        cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
        COALESCE(v.Stock_Actual, 0) AS stock,
        COALESCE(v.Estado, 'Sin estado') AS estado
    FROM tb_alm_producto p
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
    LEFT JOIN vw_control_inventario v ON v.ID = p.clm_alm_producto_id
    $where
    ORDER BY cod.clm_alm_codigo_NOMBRE ASC, c.clm_alm_categoria_descripcion ASC, p.clm_alm_producto_NOMBRE ASC
    LIMIT ? OFFSET ?
";
$types_list = $types . 'ii';
$params_list = array_merge($params, [$por_pagina, $offset]);
$rows = stmt_get_all($conn, $list_sql, $types_list, $params_list);

// ---------- KPIs ligeros ----------
$filtros_activos = 0;
if ($categoria_filtro !== '') $filtros_activos++;
if ($buscar_filtro !== '') $filtros_activos++;
if ($codigo_filtro !== '') $filtros_activos++;
$stock_pagina = 0;
$sin_stock_pagina = 0;
foreach ($rows as $r) {
    $stock_pagina += (float)($r['stock'] ?? 0);
    if ((float)($r['stock'] ?? 0) <= 0) $sin_stock_pagina++;
}

// ---------- Render catálogo ----------
$mensaje = "";

if (count($rows) > 0) {
    $mensaje .= "<div class='catalogo-container'>";
    foreach ($rows as $row) {
        $id = (int)$row['id_producto'];
        $producto = h($row['producto'] ?? 'Producto sin nombre');
        $codigoProducto = h($row['codigo_producto'] ?? 'No disponible');
        $codigoCategoria = h($row['codigo_categoria'] ?? '');
        $categoria = h($row['categoria'] ?? $row['categoria_corta'] ?? 'No disponible');
        $stock = fmt_num($row['stock'] ?? 0);
        $estado = h($row['estado'] ?? 'Sin estado');
        $stockClass = estado_stock_class($row['estado'] ?? '', $row['stock'] ?? 0);
        $catLabel = trim(($codigoCategoria ? "($codigoCategoria) " : '') . $categoria);

        $mensaje .= "<article class='product-card product-card-pro'>";
        $mensaje .= "  <div class='product-image-area'>" . mostrar_imagen($row['producto_img'], '') . "</div>";
        $mensaje .= "  <div class='product-info-area'>";
        $mensaje .= "    <div class='product-topline'>";
        $mensaje .= "      <span class='code-pill'>" . $codigoProducto . "</span>";
        $mensaje .= "      <span class='stock-pill $stockClass'>" . $estado . "</span>";
        $mensaje .= "    </div>";
        $mensaje .= "    <h4 title='" . $producto . "'>" . $producto . "</h4>";
        $mensaje .= "    <div class='product-category'><i class='bi bi-diagram-3'></i><span>" . h($catLabel) . "</span></div>";
        $mensaje .= "    <div class='product-metrics'>";
        $mensaje .= "      <div><span>Stock actual</span><strong>" . h($stock) . "</strong></div>";
        $mensaje .= "      <div><span>ID producto</span><strong>#" . $id . "</strong></div>";
        $mensaje .= "    </div>";
        $mensaje .= "  </div>";
        $mensaje .= "  <div class='product-actions'>";
        $mensaje .= "    <button type='button' class='ver-mov-btn' onclick='verMovimientos($id)'><i class='bi bi-clock-history'></i> Ver movimientos</button>";
        $mensaje .= "  </div>";
        $mensaje .= "</article>";
    }
    $mensaje .= "</div>";

    $mensaje .= "<div class='pagination catalog-pagination'>";
    $rango = 2;
    for ($i = 1; $i <= $total_paginas; $i++) {
        if ($i == 1 || $i == $total_paginas || ($i >= $pagina - $rango && $i <= $pagina + $rango)) {
            if ($i == $pagina) {
                $mensaje .= "<span class='curr'>" . $i . "</span>";
            } else {
                $mensaje .= "<a href='" . h(build_page_url($i)) . "'>" . $i . "</a>";
            }
        } elseif (($i == 2 && $pagina > 4) || ($i == $total_paginas - 1 && $pagina < $total_paginas - 3)) {
            $mensaje .= "<span class='muted'>…</span>";
        }
    }
    $mensaje .= "</div>";
} else {
    $mensaje .= "<div class='empty-catalog'>";
    $mensaje .= "  <div class='empty-icon'><i class='bi bi-box-seam'></i></div>";
    $mensaje .= "  <h3>No hay productos con los filtros actuales</h3>";
    $mensaje .= "  <p>Prueba limpiando los filtros o revisando el código escaneado.</p>";
    $mensaje .= "  <a class='btn-empty' href='gen_np9823.php'><i class='bi bi-arrow-counterclockwise'></i> Limpiar filtros</a>";
    $mensaje .= "</div>";
}
?>



<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visualización de Productos | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">    
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">    
    <!-- Lector de códigos (QR/Code128/EAN/Code39, etc.) -->
    <script src="https://unpkg.com/html5-qrcode"></script>

    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }

        .card {
            background: #fff;
            max-width: 1500px;
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
            padding: 25px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
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
.catalogo-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    padding-top: 20px;
}

.product-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: transform 0.2s;
}

.product-card:hover {
    transform: scale(1.02);
}

.product-card img {
    max-width: 100%;
    max-height: 150px;
    border-radius: 8px;
    object-fit: cover;
    margin-bottom: 12px;
}

.product-card h4 {
    color: #2c3e50;
    font-size: 16px;
    margin-bottom: 8px;
    text-align: center;
}

.product-card p {
    font-size: 14px;
    color: #555;
    margin: 2px 0;
    text-align: center;
}

.pagination {
    text-align: center;
    margin-top: 30px;
}

.pagination a {
    margin: 0 5px;
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 6px;
    background: #3498db;
    color: white;
    font-weight: bold;
    transition: background 0.3s;
}

.pagination a:hover {
    background: #21618c;
}

.pagination strong {
    margin: 0 5px;
    color: #2980b9;
}



.modal {
  display: none;
  position: fixed;
  z-index: 9999;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.5);
  overflow: auto;
}

.modal-content {
  background-color: #fff;
  margin: 5% auto;
  padding: 30px;
  border-radius: 12px;
  max-width: 900px;
  width: 90%;
  animation: fadeIn 0.3s ease;
  box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}

.cerrar {
  float: right;
  font-size: 24px;
  color: #aaa;
  font-weight: bold;
  cursor: pointer;
}

.cerrar:hover {
  color: #e74c3c;
}

/* Estilo tabla dentro del modal */
.modal-content table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 20px;
}

.modal-content th, .modal-content td {
  padding: 10px 14px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}

.modal-content th {
  background-color: #2c3e50;
  color: white;
}

.modal-content tr:hover {
  background-color: #f1f1f1;
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

/* === FILTROS (Look Transporte) === */
.filters-toolbar{
  background: #ffff; /* gris azulado operacional */
  border-radius:14px;
  padding:16px 18px;
  margin:24px auto 10px;
  max-width:1100px;
  color: #000;
  box-shadow:0 6px 18px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.03);
}
.filters-toolbar .title{
  display:flex; align-items:center; gap:10px;
  font-weight:700; font-size:16px; letter-spacing:.2px; margin-bottom:12px;
}
.filters-row{
  display:grid;
  grid-template-columns: 1.2fr 1fr 1.2fr auto auto;
  gap:12px; align-items:center;
}

.mt-2 .bi{
    color: white;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1rem;
}


.filters-toolbar .input-icon{ max-width: 100%; position:relative; margin:10px;}
.filters-toolbar .input-icon .bi{
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  font-size:1rem; opacity:.8;
}
.filters-toolbar input.form-control,
.filters-toolbar select.form-select{
  padding-left:40px; height:44px; background: #ffff; color: #000; border-color:#233142;
}
.filters-toolbar input.form-control::placeholder{ color:#9ca3af; }
.filters-toolbar select.form-select{ background-image:none; }
.filters-toolbar .btn-apply,.filters-toolbar .btn-reset,.filters-toolbar .btn-scan{ height:44px; }
.filters-toolbar .btn-apply{ background:#0ea5e9; border-color:#0ea5e9; }
.filters-toolbar .btn-apply:hover{ background:#0b8ec8; border-color:#0b8ec8; }
.filters-toolbar .btn-scan{ 
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
        transition: transform 0.3s ease;}
.filters-toolbar .btn-scan:hover{ background:#121a2b; }

@media (max-width: 992px){
  .filters-row{ grid-template-columns: 1fr 1fr; }
}
@media (max-width: 576px){
  .filters-row{ grid-template-columns: 1fr; }
}


    

/* =========================================================
   REDISEÑO GERENCIAL - CATÁLOGO DE PRODUCTOS NORTE 360
   ========================================================= */
:root{
  --cat-bg:#eef3f8;
  --cat-ink:#0f172a;
  --cat-muted:#64748b;
  --cat-line:#dbe4ef;
  --cat-primary:#172033;
  --cat-primary-2:#2c3e50;
  --cat-accent:#0ea5e9;
  --cat-card:#ffffff;
  --cat-green:#16a34a;
  --cat-red:#dc2626;
  --cat-orange:#f59e0b;
}
body{background:linear-gradient(180deg,#f8fbff 0%,var(--cat-bg) 48%,#e9f0f7 100%);color:var(--cat-ink);}
hr{display:none;}
.main-content{padding:24px 26px 34px;margin-left:250px;}
body.sidebar-collapsed .main-content{margin-left:0!important;}
.catalog-page{width:100%;max-width:1680px;margin:0 auto;}
.catalog-hero{position:relative;overflow:hidden;border-radius:22px;padding:22px 24px;margin-bottom:16px;color:#fff;background:radial-gradient(circle at top right,rgba(14,165,233,.22),transparent 34%),linear-gradient(135deg,#172033,#2c3e50 62%,#111827);box-shadow:0 18px 40px rgba(15,23,42,.18);display:grid;grid-template-columns:minmax(320px,1fr) auto;gap:18px;align-items:center;}
.catalog-hero:after{content:"";position:absolute;left:24px;right:24px;bottom:0;height:3px;border-radius:999px;opacity:.9;}
.catalog-eyebrow{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);border-radius:999px;padding:6px 11px;font-size:.82rem;font-weight:800;color:#dbeafe;margin-bottom:10px;}
.catalog-hero h1{margin:0;font-size:clamp(1.55rem,2.2vw,2.35rem);font-weight:900;letter-spacing:-.04em;}
.catalog-hero p{margin:6px 0 0;color:#cbd5e1;max-width:720px;font-size:.98rem;}
.catalog-hero-logo{width:150px;max-width:100%;filter:drop-shadow(0 12px 18px rgba(0,0,0,.22));}
.catalog-hero-metrics{display:grid;grid-template-columns:repeat(4,minmax(145px,1fr));gap:10px;margin-bottom:14px;}
.catalog-mini-kpi{background:#fff;border:1px solid var(--cat-line);border-radius:18px;padding:14px 15px;box-shadow:0 12px 28px rgba(15,23,42,.07);display:flex;align-items:center;gap:12px;position:relative;overflow:hidden;}
.catalog-mini-kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:#334155;}
.catalog-mini-kpi.kpi-products:before{background:#0ea5e9;}
.catalog-mini-kpi.kpi-page:before{background:#8b5cf6;}
.catalog-mini-kpi.kpi-stock:before{background:#16a34a;}
.catalog-mini-kpi.kpi-filter:before{background:#f59e0b;}
.catalog-mini-kpi .ic{width:42px;height:42px;border-radius:13px;background:#f8fafc;border:1px solid #e2e8f0;display:grid;place-items:center;color:#172033;font-size:1.1rem;}
.catalog-mini-kpi span{display:block;color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.035em;font-weight:850;}
.catalog-mini-kpi strong{display:block;color:#0f172a;font-size:1.22rem;line-height:1.15;font-weight:900;margin-top:2px;}
.catalog-filters{background:#fff!important;border:1px solid var(--cat-line)!important;border-radius:18px!important;padding:16px!important;margin:0 0 14px!important;color:var(--cat-ink)!important;box-shadow:0 12px 28px rgba(15,23,42,.08)!important;max-width:none!important;}
.catalog-filters .title{display:flex;align-items:center;gap:10px;font-weight:850;color:#172033;border-bottom:1px solid #e8eef6;padding-bottom:10px;margin-bottom:14px;}
.catalog-filters .title i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:#e0f2fe;color:#0369a1;}
.catalog-filter-grid{display:grid;grid-template-columns:1.3fr 1fr 1.5fr auto auto auto;gap:10px;align-items:end;}
.catalog-filters .input-icon{position:relative;margin:0!important;}
.catalog-filters .input-icon i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#64748b;z-index:1;}
.catalog-filters input.form-control,.catalog-filters select.form-select{height:43px!important;background:#f8fafc!important;color:#0f172a!important;border:1px solid #dbe4ef!important;border-radius:12px!important;box-shadow:none!important;padding-left:40px!important;font-size:.9rem!important;margin:0!important;}
.catalog-filters input.form-control:focus,.catalog-filters select.form-select:focus{border-color:#0ea5e9!important;box-shadow:0 0 0 .18rem rgba(14,165,233,.13)!important;}
.catalog-filters button,.catalog-filters a{width:auto!important;height:43px!important;border-radius:12px!important;display:inline-flex;align-items:center;justify-content:center;gap:7px;font-weight:850;text-decoration:none;white-space:nowrap;padding:0 14px!important;font-size:.9rem!important;}
.catalog-filters .btn-apply{background:#172033!important;color:#fff!important;border:1px solid #172033!important;}
.catalog-filters .btn-reset{background:#f8fafc!important;color:#334155!important;border:1px solid #dbe4ef!important;}
.catalog-filters .btn-scan{background:#0ea5e9!important;color:#fff!important;border:1px solid #0ea5e9!important;animation:none!important;}
.catalog-filters .btn-scan:hover{background:#0284c7!important;transform:translateY(-1px);}
.catalog-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid var(--cat-line);border-radius:18px;padding:13px 16px;margin-bottom:14px;box-shadow:0 12px 28px rgba(15,23,42,.07);}
.catalog-actions .left,.catalog-actions .right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.catalog-chip{display:inline-flex;align-items:center;gap:7px;background:#f8fafc;border:1px solid #dbe4ef;border-radius:999px;padding:7px 11px;color:#334155;font-weight:850;font-size:.84rem;}
.catalog-panel{background:#fff;border:1px solid var(--cat-line);border-radius:20px;box-shadow:0 18px 40px rgba(15,23,42,.10);overflow:hidden;}
.catalog-panel-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px 18px;border-bottom:1px solid #e8eef6;background:linear-gradient(180deg,#fff,#f8fafc);}
.catalog-panel-head h3{margin:0;color:#172033;font-size:1.05rem;font-weight:900;display:flex;align-items:center;gap:8px;}
.catalog-panel-head p{margin:3px 0 0;color:#64748b;font-size:.86rem;}
.catalog-page-pill{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 11px;font-size:.84rem;font-weight:900;white-space:nowrap;}
.catalog-panel-body{padding:18px;}
.resultado{line-height:1.45!important;}
.catalogo-container{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px!important;padding-top:0!important;}
.product-card-pro{background:#fff!important;border:1px solid #dbe4ef!important;border-radius:18px!important;padding:0!important;box-shadow:0 12px 28px rgba(15,23,42,.08)!important;overflow:hidden;display:flex!important;flex-direction:column!important;align-items:stretch!important;min-height:100%;transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;}
.product-card-pro:hover{transform:translateY(-3px)!important;box-shadow:0 18px 36px rgba(15,23,42,.13)!important;border-color:#bfdbfe!important;}
.product-image-area{height:172px;background:linear-gradient(180deg,#f8fafc,#eef3f8);display:grid;place-items:center;border-bottom:1px solid #e8eef6;overflow:hidden;}
.product-image-area .img-block{width:100%;height:100%;margin:0!important;display:grid;place-items:center;padding:12px;}
.product-image-area .img-block img{max-width:100%!important;max-height:148px!important;width:auto!important;height:auto!important;object-fit:contain!important;border-radius:12px!important;box-shadow:none!important;margin:0!important;}
.product-img-placeholder{color:#94a3b8;gap:6px;font-weight:800;font-size:.86rem;}
.product-img-placeholder i{font-size:2rem;display:block;}
.product-info-area{padding:14px 15px 10px;display:flex;flex-direction:column;gap:10px;flex:1;}
.product-topline{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.code-pill{display:inline-flex;align-items:center;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-weight:900;font-size:.78rem;max-width:145px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.stock-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-weight:900;font-size:.72rem;text-transform:uppercase;white-space:nowrap;}
.stock-ok{background:#dcfce7;color:#166534;}
.stock-low{background:#ffedd5;color:#9a3412;}
.stock-zero{background:#fee2e2;color:#991b1b;}
.product-card-pro h4{margin:0!important;color:#0f172a!important;font-size:.98rem!important;line-height:1.25!important;text-align:left!important;font-weight:900!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.45em;}
.product-category{display:flex;gap:8px;align-items:flex-start;color:#475569;background:#f8fafc;border:1px solid #edf2f7;border-radius:13px;padding:9px 10px;font-size:.83rem;font-weight:750;min-height:44px;}
.product-category i{color:#0ea5e9;margin-top:1px;}
.product-category span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.product-metrics{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.product-metrics div{background:#fff;border:1px solid #e8eef6;border-radius:13px;padding:9px 10px;}
.product-metrics span{display:block;color:#64748b;font-size:.72rem;font-weight:850;text-transform:uppercase;letter-spacing:.025em;}
.product-metrics strong{display:block;color:#0f172a;font-size:.96rem;font-weight:900;margin-top:2px;}
.product-actions{padding:0 15px 15px;}
.ver-mov-btn{width:100%!important;height:40px!important;background:#172033!important;color:#fff!important;border:1px solid #172033!important;border-radius:12px!important;font-size:.88rem!important;font-weight:900!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;padding:0 12px!important;}
.ver-mov-btn:hover{background:#0f172a!important;transform:translateY(-1px);}
.catalog-pagination{display:flex;gap:8px;justify-content:center;align-items:center;margin:18px 0 0!important;flex-wrap:wrap;}
.catalog-pagination a,.catalog-pagination span{padding:8px 12px;border-radius:10px;font-weight:850;text-decoration:none;min-width:38px;text-align:center;}
.catalog-pagination a{background:#fff;color:#334155;border:1px solid #dbe4ef;}
.catalog-pagination a:hover{background:#172033;color:#fff;}
.catalog-pagination .curr{background:#172033;color:#fff;border:1px solid #172033;}
.catalog-pagination .muted{color:#94a3b8;}
.empty-catalog{text-align:center;padding:46px 20px;color:#475569;}
.empty-icon{width:72px;height:72px;margin:0 auto 14px;border-radius:22px;background:#f8fafc;border:1px solid #dbe4ef;display:grid;place-items:center;color:#64748b;font-size:2rem;}
.empty-catalog h3{margin:0;color:#0f172a;font-weight:900;}
.empty-catalog p{margin:6px 0 16px;color:#64748b;}
.btn-empty{display:inline-flex;align-items:center;gap:8px;background:#172033;color:#fff!important;border-radius:12px;padding:10px 14px;text-decoration:none;font-weight:850;}
.modal-content{border:1px solid #dbe4ef!important;border-radius:20px!important;box-shadow:0 24px 60px rgba(15,23,42,.28)!important;}
#scannerModal .modal-content h3{color:#172033;font-weight:900;display:flex;align-items:center;gap:8px;}
#scannerModal .modal-content h3:before{content:"\\F3EF";font-family:"bootstrap-icons";color:#0ea5e9;}
@media(max-width:1250px){.catalog-hero{grid-template-columns:1fr}.catalog-hero-logo{display:none}.catalog-hero-metrics{grid-template-columns:repeat(2,1fr)}.catalog-filter-grid{grid-template-columns:1fr 1fr}.catalog-filters button,.catalog-filters a{width:100%!important}.main-content{margin-left:0!important;padding:18px;}}
@media(max-width:680px){.catalog-hero-metrics{grid-template-columns:1fr}.catalog-filter-grid{grid-template-columns:1fr}.catalog-panel-head{flex-direction:column;align-items:flex-start}.catalog-panel-body{padding:14px}.catalogo-container{grid-template-columns:1fr!important}.main-content{padding:14px}.catalog-hero{padding:18px}.product-image-area{height:150px}}
@media print{.main-header,.nav-bar-pro,.menu-lateral,.sidebar-show-btn,.menu-toggle,.catalog-filters,.catalog-actions,.btn-flotante,.main-footer,.product-actions{display:none!important}.main-content{margin-left:0!important;padding:0!important}.catalog-hero{background:#fff!important;color:#0f172a!important;box-shadow:none!important;border:1px solid #dbe4ef}.catalog-hero p,.catalog-eyebrow{color:#334155!important}.catalogo-container{grid-template-columns:repeat(3,1fr)!important}.product-card-pro{break-inside:avoid}}
/* =========================================================
   DRAWER LATERAL DERECHO - MOVIMIENTOS DE PRODUCTO
   Solo afecta al modal #modal-movimientos
   ========================================================= */
#modal-movimientos{
  display:block!important;
  position:fixed!important;
  inset:0!important;
  z-index:999998!important;
  background:rgba(15,23,42,.52)!important;
  backdrop-filter:blur(3px);
  opacity:0;
  visibility:hidden;
  pointer-events:none;
  transition:opacity .25s ease, visibility .25s ease;
  overflow:hidden!important;
}
/* =========================================================
   MODAL NOTA DE ALMACÉN ENCIMA DEL DRAWER
   ========================================================= */
#modal-nota{
  z-index:1000005!important;
  background:rgba(15,23,42,.62)!important;
  backdrop-filter:blur(4px);
}

#modal-nota .modal-content{
  position:relative!important;
  z-index:1000006!important;
  max-width:940px!important;
  width:min(940px, 92vw)!important;
  margin:4.5vh auto!important;
  border-radius:22px!important;
  padding:28px!important;
  background:#fff!important;
  box-shadow:0 28px 70px rgba(15,23,42,.36)!important;
  animation:notaPop .22s ease both!important;
}

#modal-nota .cerrar{
  position:absolute!important;
  top:14px!important;
  right:16px!important;
  z-index:1000007!important;
  width:38px!important;
  height:38px!important;
  border-radius:12px!important;
  display:grid!important;
  place-items:center!important;
  background:#111827!important;
  color:#fff!important;
  font-size:24px!important;
  line-height:1!important;
  cursor:pointer!important;
}

#modal-nota .cerrar:hover{
  background:#dc2626!important;
  color:#fff!important;
}

@keyframes notaPop{
  from{
    opacity:0;
    transform:translateY(12px) scale(.98);
  }
  to{
    opacity:1;
    transform:translateY(0) scale(1);
  }
}
#modal-movimientos.active{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
}

#modal-movimientos .modal-content{
  position:fixed!important;
  top:0!important;
  right:0!important;
  margin:0!important;
  width:min(1120px, 92vw)!important;
  max-width:none!important;
  height:100vh!important;
  max-height:100vh!important;
  border-radius:22px 0 0 22px!important;
  padding:0!important;
  overflow:hidden!important;
  background:#f3f7fb!important;
  box-shadow:-18px 0 45px rgba(15,23,42,.25)!important;
  transform:translateX(105%);
  transition:transform .34s cubic-bezier(.22,.8,.24,1);
}

#modal-movimientos.active .modal-content{
  transform:translateX(0);
}

#modal-movimientos .cerrar{
  position:absolute!important;
  top:14px!important;
  right:18px!important;
  z-index:30!important;
  width:40px!important;
  height:40px!important;
  border-radius:12px!important;
  display:grid!important;
  place-items:center!important;
  background:rgba(15,23,42,.75)!important;
  border:1px solid rgba(255,255,255,.18)!important;
  color:#fff!important;
  font-size:26px!important;
  line-height:1!important;
  cursor:pointer!important;
  transition:.2s ease!important;
}

#modal-movimientos .cerrar:hover{
  background:#dc2626!important;
  color:#fff!important;
  transform:scale(1.04);
}

#contenido-movimientos{
  height:100%;
  overflow-y:auto;
  background:#f3f7fb;
}

body.drawer-open{
  overflow:hidden!important;
}

.drawer-loading{
  height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  flex-direction:column;
  gap:12px;
  color:#334155;
  background:#f3f7fb;
}

.drawer-loading .spinner{
  width:42px;
  height:42px;
  border-radius:50%;
  border:4px solid #dbe4ef;
  border-top-color:#0ea5e9;
  animation:drawerSpin .8s linear infinite;
}

@keyframes drawerSpin{
  to{ transform:rotate(360deg); }
}

@media(max-width:768px){
  #modal-movimientos .modal-content{
    width:100vw!important;
    border-radius:0!important;
  }
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
    <a href="../01_almacen/gen_np9823.php">📋 Productos</a>
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

  <hr>

<div class="main-content">
<main>
  <section class="catalog-page">
    <section class="catalog-hero">
      <div>
        <div class="catalog-eyebrow"><i class="bi bi-boxes"></i> Inventario · Catálogo maestro</div>
        <h1>Catálogo de Productos</h1>
        <p>Vista ordenada para consultar productos, códigos, categorías, stock y movimientos de almacén.</p>
      </div>
      <img src="../img/cdn_productos_lg.png" alt="Catálogo de productos" class="catalog-hero-logo">
    </section>

    <div class="catalog-hero-metrics">
      <div class="catalog-mini-kpi kpi-products">
        <div class="ic"><i class="bi bi-box-seam"></i></div>
        <div><span>Productos filtrados</span><strong><?= number_format($total) ?></strong></div>
      </div>
      <div class="catalog-mini-kpi kpi-page">
        <div class="ic"><i class="bi bi-grid-3x3-gap"></i></div>
        <div><span>Mostrados en página</span><strong><?= count($rows) ?></strong></div>
      </div>
      <div class="catalog-mini-kpi kpi-stock">
        <div class="ic"><i class="bi bi-clipboard-data"></i></div>
        <div><span>Stock página</span><strong><?= h(fmt_num($stock_pagina)) ?></strong></div>
      </div>
      <div class="catalog-mini-kpi kpi-filter">
        <div class="ic"><i class="bi bi-funnel"></i></div>
        <div><span>Filtros activos</span><strong><?= (int)$filtros_activos ?></strong></div>
      </div>
    </div>

    <?php
      $codigo_val    = h($_GET['codigo'] ?? '');
      $buscar_val    = h($_GET['buscar'] ?? '');
      $categoria_val = $_GET['categoria'] ?? '';
    ?>
    <form id="formFiltros" class="catalog-filters" method="get">
      <div class="title">
        <i class="bi bi-funnel"></i>
        <span>Filtros del catálogo</span>
      </div>

      <div class="catalog-filter-grid">
        <div class="input-icon">
          <i class="bi bi-collection"></i>
          <select class="form-select" id="categoria" name="categoria">
            <option value="">Todas las categorías</option>
            <?php
              $cat_result = $conn->query("SELECT DISTINCT clm_alm_categoria_descripcion FROM tb_alm_categoria ORDER BY clm_alm_categoria_descripcion");
              while ($cat = $cat_result->fetch_assoc()) {
                  $val = $cat['clm_alm_categoria_descripcion'];
                  $sel = ($categoria_val === $val) ? 'selected' : '';
                  echo '<option value="'.h($val).'" '.$sel.'>'.h($val).'</option>';
              }
            ?>
          </select>
        </div>

        <div class="input-icon">
          <i class="bi bi-upc-scan"></i>
          <input type="text" class="form-control" id="codigo" name="codigo" value="<?= $codigo_val ?>" placeholder="Código de producto" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarPorCodigo();}">
        </div>

        <div class="input-icon">
          <i class="bi bi-search"></i>
          <input type="text" class="form-control" id="buscar" name="buscar" value="<?= $buscar_val ?>" placeholder="Buscar producto por palabras">
        </div>

        <button type="submit" class="btn-apply"><i class="bi bi-check2-circle"></i> Aplicar</button>
        <button type="button" class="btn-reset" onclick="limpiarFiltros()"><i class="bi bi-x-circle"></i> Limpiar</button>
        <button type="button" class="btn-scan" onclick="abrirScanner()"><i class="bi bi-camera-video"></i> Escanear</button>
      </div>
    </form>

    <div class="catalog-actions">
      <div class="left">
        <span class="catalog-chip"><i class="bi bi-file-earmark-text"></i> Página <?= number_format($pagina) ?> / <?= number_format($total_paginas) ?></span>
        <span class="catalog-chip"><i class="bi bi-box"></i> <?= number_format($por_pagina) ?> por página</span>
      </div>
      <div class="right">
        <?php if($categoria_filtro !== ''): ?><span class="catalog-chip"><i class="bi bi-collection"></i> <?= h($categoria_filtro) ?></span><?php endif; ?>
        <?php if($codigo_filtro !== ''): ?><span class="catalog-chip"><i class="bi bi-upc"></i> <?= h($codigo_filtro) ?></span><?php endif; ?>
        <?php if($buscar_filtro !== ''): ?><span class="catalog-chip"><i class="bi bi-search"></i> <?= h($buscar_filtro) ?></span><?php endif; ?>
      </div>
    </div>

    <section class="catalog-panel">
      <div class="catalog-panel-head">
        <div>
          <h3><i class="bi bi-card-checklist"></i> Productos registrados</h3>
          <p>Cards compactas con imagen, código, categoría, stock y acceso directo a movimientos.</p>
        </div>
        <div class="catalog-page-pill">Página <?= number_format($pagina) ?> / <?= number_format($total_paginas) ?></div>
      </div>
      <div class="catalog-panel-body">
        <div class="resultado"><?= $mensaje ?></div>
      </div>
    </section>
  </section>

  <div id="modal-movimientos" class="modal">
    <div class="modal-content">
      <span class="cerrar" onclick="cerrarModal()">&times;</span>
      <div id="contenido-movimientos">Cargando movimientos...</div>
    </div>
  </div>

  <div id="modal-nota" class="modal">
    <div class="modal-content">
      <span class="cerrar" onclick="cerrarNotaModal()">&times;</span>
      <div id="contenido-nota">Cargando Nota de Salida...</div>
    </div>
  </div>
</main>
</div>

<div id="scannerModal" class="modal">
  <div class="modal-content" style="max-width:560px">
    <span class="cerrar" onclick="detenerScanner()">&times;</span>
    <h3 style="margin-top:0;">Escanear código</h3>
    <div id="qr-reader" style="width:100%;"></div>
    <div style="margin-top:12px; text-align:right;">
      <button onclick="detenerScanner()" class="btn-validar" style="width:auto; background:#e74c3c;">Detener</button>
    </div>
  </div>
</div>



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

function limpiarFiltros(){
  window.location.href = 'gen_np9823.php';
}
document.getElementById('formFiltros').addEventListener('submit', function(e){
  e.preventDefault();
  const params = new URLSearchParams(new FormData(this));
  params.delete('pagina');
  window.location.search = params.toString();
});
function buscarPorCodigo(){
  const code = (document.getElementById('codigo').value || '').trim();
  if(!code){ alert('Ingresa o escanea un código.'); return; }
  const form = document.getElementById('formFiltros');
  const params = new URLSearchParams(new FormData(form));
  params.set('codigo', code);
  params.delete('pagina'); // reinicia paginación
  window.location.search = params.toString();
}

  let html5QrCode = null;
  let scannerRunning = false;

  async function abrirScanner() {
    const modal = document.getElementById('scannerModal');
    modal.style.display = 'block';

    if (!window.Html5Qrcode) {
      alert('No se pudo cargar el lector. Revisa tu conexión.');
      return;
    }

    try {
      // Contenedor del lector
      if (html5QrCode) {
        try { await html5QrCode.stop(); await html5QrCode.clear(); } catch(e){}
      }
      html5QrCode = new Html5Qrcode("qr-reader");

      // Elegir cámara (prioriza trasera)
      const devices = await Html5Qrcode.getCameras();
      if (!devices || !devices.length) {
        alert('No se encontraron cámaras.');
        return;
      }
      const backCam = devices.find(d => /back|rear|environment/i.test(d.label));
      const camId = backCam ? backCam.id : devices[0].id;

      const formats = [
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.EAN_8,
        Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.QR_CODE
      ];

      const onSuccess = (decodedText) => {
        if (scannerRunning) {
          // Evita múltiples lecturas seguidas
          scannerRunning = false;
          onCodigoDetectado(decodedText);
        }
      };

      scannerRunning = true;
      // Ajusta fps y tamaño de caja si quieres
      await html5QrCode.start(
        camId,
        { fps: 10, formatsToSupport: formats, qrbox: { width: 280, height: 180 } },
        onSuccess,
        (errMsg) => { /* errores de escaneo por frame: ignóralos */ }
      );

    } catch (e) {
      console.error(e);
      alert('No fue posible iniciar la cámara. Recuerda usar HTTPS o localhost.');
      detenerScanner();
    }
  }

  async function detenerScanner() {
    try {
      scannerRunning = false;
      if (html5QrCode) {
        await html5QrCode.stop();
        await html5QrCode.clear();
      }
    } catch(e) { /* noop */ }
    document.getElementById('scannerModal').style.display = 'none';
  }

  function onCodigoDetectado(code) {
    detenerScanner();
    const limpio = (code || '').trim();
    if (!limpio) return;
    document.getElementById('codigo').value = limpio;
    buscarPorCodigo();
  }
</script>

</body>

<script>
function verMovimientos(id_producto) {
  const modal = document.getElementById('modal-movimientos');
  const contenido = document.getElementById('contenido-movimientos');

  if (!modal || !contenido) return;

  contenido.innerHTML = `
    <div class="drawer-loading">
      <div class="spinner"></div>
      <strong>Cargando movimientos del producto...</strong>
      <small>Un momento por favor</small>
    </div>
  `;

  modal.classList.add('active');
  document.body.classList.add('drawer-open');

  fetch('../php/ver_movimientos_producto.php?id=' + encodeURIComponent(id_producto))
    .then(response => response.text())
    .then(data => {
      contenido.innerHTML = data;
    })
    .catch(error => {
      contenido.innerHTML = `
        <div class="drawer-loading">
          <strong style="color:#dc2626;">Error al cargar movimientos.</strong>
          <small>Intenta nuevamente.</small>
        </div>
      `;
    });
}

document.addEventListener('click', function(e){
  const modal = document.getElementById('modal-movimientos');

  if (modal && e.target === modal && modal.classList.contains('active')) {
    cerrarModal();
  }
});

document.addEventListener('keydown', function(e){
  const modal = document.getElementById('modal-movimientos');

  if (modal && e.key === 'Escape' && modal.classList.contains('active')) {
    cerrarModal();
  }
});


function cerrarModal() {
  const modal = document.getElementById('modal-movimientos');
  const contenido = document.getElementById('contenido-movimientos');

  if (!modal) return;

  modal.classList.remove('active');
  document.body.classList.remove('drawer-open');

  setTimeout(() => {
    if (contenido) contenido.innerHTML = 'Cargando movimientos...';
  }, 350);
}


</script>
<script>
function verNotaSalida(id_movimiento) {
  fetch('../php/ver_nota_salida.php?id=' + id_movimiento)
    .then(response => response.text())
    .then(data => {
      document.getElementById('contenido-nota').innerHTML = data;
      document.getElementById('modal-nota').style.display = 'block';
    })
    .catch(error => {
      document.getElementById('contenido-nota').innerHTML = '❌ Error al cargar Nota de Salida.';
    });
}

function cerrarNotaModal() {
  document.getElementById('modal-nota').style.display = 'none';
}
</script>


<script>
function mostrarSubmenu(id) {
  const seleccionado = document.getElementById(id);
  const estaVisible = seleccionado && seleccionado.style.display === 'flex';

  document.querySelectorAll('.subnav').forEach(el => el.style.display = 'none');

  if (!estaVisible && seleccionado) {
    seleccionado.style.display = 'flex';
  }
}

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

function toggleMenu() {
  const menu = document.querySelector('.menu-lateral');
  menu.classList.toggle('active');
}
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

</html>
<?php $conn->close(); ?>
