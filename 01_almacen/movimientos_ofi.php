<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

$isAdmin = (($_SESSION['web_rol'] ?? '') === 'Admin');

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
// 01_almacen\movimientos_ofi.php:
$permisos = (($_SESSION['permisos'] ?? '') == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = (($_SESSION['permisos'] ?? '') == 'all') ? [] : ($_SESSION['vistas'] ?? []);

if (!$isAdmin) {
    $modulo_actual = 3; // id_modulo de esta vista

    if (!in_array($modulo_actual, $_SESSION['permisos'])) {
        header("Location: ../login/none_permisos.php");
        exit();
    }
}






// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Construye WHERE + bind de forma segura
 */
$where = " WHERE 1=1 ";
$types = '';
$params = [];

// ---- Filtros (GET) ----
$tipo         = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$desde        = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$hasta        = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';
$categoria_id = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : 0; // usamos ID para evitar collation
$buscar       = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$placa        = isset($_GET['placa']) ? trim($_GET['placa']) : '';
$estadoetiq   = isset($_GET['estadoetiq']) ? trim($_GET['estadoetiq']) : '';
// Permitir que el filtro acepte "CONFORME" como equivalente a 0
if ($estadoetiq !== '' && mb_strtolower($estadoetiq, 'UTF-8') === 'conforme') {
    $estadoetiq = '0';
}
if (in_array($tipo, ['ENTRADA','SALIDA','INVENTARIADO'], true)) {
    $where .= " AND m.clm_alm_mov_TIPO = ? ";
    $types .= 's'; $params[] = $tipo;
}
if ($desde !== '') {
    $where .= " AND DATE(m.clm_alm_mov_fecha_registro) >= ? ";
    $types .= 's'; $params[] = $desde;
}
if ($hasta !== '') {
    $where .= " AND DATE(m.clm_alm_mov_fecha_registro) <= ? ";
    $types .= 's'; $params[] = $hasta;
}
if ($categoria_id > 0) {
    $where .= " AND c.clm_alm_categoria_id = ? ";
    $types .= 'i'; $params[] = $categoria_id;
}
if ($buscar !== '') {
    // Unificamos collation en los literales para evitar el error de "Illegal mix of collations"
    $where .= " AND (
        p.clm_alm_producto_NOMBRE LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR p.clm_alm_producto_codigo LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR m.clm_alm_mov_documento   LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR m.clm_mov_factura         LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR m.clm_mov_ruc             LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR cod.clm_alm_codigo_NOMBRE LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
    )";
    $types .= 'ssssss';
    array_push($params, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar);
}
if ($placa !== '') {
    $where .= " AND (
        pl.clm_placas_PLACA COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR pl.clm_placas_BUS  COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
        OR CAST(m.clm_alm_mov_placa AS CHAR) LIKE CONCAT('%', ? COLLATE utf8mb4_unicode_ci, '%')
    ) ";
    $types .= 'sss';
    array_push($params, $placa, $placa, $placa);
}
if ($estadoetiq !== '') {
    $where .= " AND m.clm_alm_movimientos_estadoetiq = ? ";
    $types .= 's'; $params[] = $estadoetiq;
}

// ---------- Paginación ----------
$por_pagina = 100;
$pagina = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;

// ---------- Conteo total ----------
$count_sql = "
    SELECT COUNT(*) AS total
    FROM tb_alm_movimientos m
    JOIN tb_alm_producto p  ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod  ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
    LEFT JOIN tb_placas pl ON pl.clm_placas_id = m.clm_alm_mov_placa    
    $where
";
$stmt = $conn->prepare($count_sql);
if($types){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$total = ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$total_paginas = max(1, (int)ceil($total / $por_pagina));
if ($pagina > $total_paginas) { $pagina = $total_paginas; $offset = ($pagina - 1) * $por_pagina; }

// ---------- Resumen (sumas) ----------
$sum_sql = "
    SELECT 
        SUM(CASE WHEN m.clm_alm_mov_TIPO='ENTRADA'      THEN m.clm_alm_mov_cantidad ELSE 0 END) AS cant_entradas,
        SUM(CASE WHEN m.clm_alm_mov_TIPO='SALIDA'       THEN m.clm_alm_mov_cantidad ELSE 0 END) AS cant_salidas,
        SUM(CASE WHEN m.clm_alm_mov_TIPO='INVENTARIADO' THEN m.clm_alm_mov_cantidad ELSE 0 END) AS cant_inventariado,

        SUM(CASE WHEN m.clm_alm_mov_TIPO='ENTRADA'      THEN m.clm_alm_mov_monto ELSE 0 END)    AS monto_entradas,
        SUM(CASE WHEN m.clm_alm_mov_TIPO='SALIDA'       THEN m.clm_alm_mov_monto ELSE 0 END)    AS monto_salidas,
        SUM(CASE WHEN m.clm_alm_mov_TIPO='INVENTARIADO' THEN m.clm_alm_mov_monto ELSE 0 END)    AS monto_inventariado
    FROM tb_alm_movimientos m
    JOIN tb_alm_producto p  ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod  ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
    LEFT JOIN tb_placas pl ON pl.clm_placas_id = m.clm_alm_mov_placa    
    $where
";
$stmt = $conn->prepare($sum_sql);
if($types){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$sumas = $stmt->get_result()->fetch_assoc() ?: ['cant_entradas'=>0,'cant_salidas'=>0,'cant_inventariado'=>0,'monto_entradas'=>0,'monto_salidas'=>0,'monto_inventariado'=>0];
$stmt->close();

// >>> AQUÍ, ya con $sumas poblado <<<
$neto_cantidad2 = (float)($sumas['cant_inventariado'] ?? 0)
               + (float)($sumas['cant_entradas'] ?? 0)
               - (float)($sumas['cant_salidas'] ?? 0);

$neto_monto = (float)($sumas['monto_inventariado'] ?? 0)
            + (float)($sumas['monto_entradas'] ?? 0)
            - (float)($sumas['monto_salidas'] ?? 0);

// ---------- Export CSV ----------






if (isset($_GET['export']) && $_GET['export'] == '1') {
    if (!$isAdmin) {
        header("Location: movimientos_ofi.php");
        exit();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=movimientos_export.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Fecha','Tipo','Producto','Código','Categoría','Código Cat','Cantidad','Monto','Documento','Factura','RUC','Placa','Nota','Estado','UsuarioID','Observación']);
    $exp_sql = "
        SELECT 
            m.clm_alm_mov_id, m.clm_alm_mov_fecha_registro, m.clm_alm_mov_TIPO,
            p.clm_alm_producto_NOMBRE, p.clm_alm_producto_codigo,
            c.clm_alm_categoria_DESCRIPCION, cod.clm_alm_codigo_NOMBRE,
            m.clm_alm_mov_cantidad, m.clm_alm_mov_monto,
            m.clm_alm_mov_documento, m.clm_mov_factura, m.clm_mov_ruc,
            m.clm_alm_mov_placa, m.clm_alm_mov_idNOTA, m.clm_alm_movimientos_estadoetiq, m.clm_alm_mov_iduser,
            m.clm_alm_mov_OBSERVACION
        FROM tb_alm_movimientos m
        JOIN tb_alm_producto p  ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
        JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
        JOIN tb_alm_codigo cod  ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
        $where
        ORDER BY m.clm_alm_mov_id DESC
        LIMIT 500000
    ";
    $stmt = $conn->prepare($exp_sql);
    if($types){ $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $r = $stmt->get_result();
    while($row = $r->fetch_row()){ fputcsv($out, $row); }
    fclose($out);
    exit;
}

// ---------- Page data ----------
$list_sql = "
    SELECT 
        m.clm_alm_mov_id, m.clm_alm_mov_fecha_registro, m.clm_alm_mov_TIPO,
        p.clm_alm_producto_NOMBRE AS producto, p.clm_alm_producto_codigo AS cod_prod,
        c.clm_alm_categoria_DESCRIPCION AS categoria, cod.clm_alm_codigo_NOMBRE AS codigo_categoria,
        m.clm_alm_mov_cantidad, m.clm_alm_mov_monto,
        m.clm_alm_mov_documento, m.clm_mov_factura, m.clm_mov_ruc,

        m.clm_alm_mov_placa,
        CASE
          WHEN pl.clm_placas_id IS NOT NULL
              AND TRIM(COALESCE(pl.clm_placas_BUS, '')) <> ''
              AND TRIM(COALESCE(pl.clm_placas_PLACA, '')) <> ''
          THEN CONCAT(TRIM(pl.clm_placas_BUS), ' (', TRIM(pl.clm_placas_PLACA), ')')

          WHEN pl.clm_placas_id IS NOT NULL
              AND TRIM(COALESCE(pl.clm_placas_BUS, '')) <> ''
          THEN TRIM(pl.clm_placas_BUS)

          WHEN pl.clm_placas_id IS NOT NULL
              AND TRIM(COALESCE(pl.clm_placas_PLACA, '')) <> ''
          THEN TRIM(pl.clm_placas_PLACA)

          ELSE CAST(m.clm_alm_mov_placa AS CHAR)
        END AS placa_label,

        m.clm_alm_mov_idNOTA,
        COALESCE(CAST(ns.clm_nota_sco AS CHAR), CAST(m.clm_alm_mov_idNOTA AS CHAR)) AS nota_label,
        m.clm_alm_movimientos_estadoetiq,
        CASE
          WHEN COALESCE(NULLIF(TRIM(CAST(m.clm_alm_movimientos_estadoetiq AS CHAR)), ''), '0') = '0'
          THEN 'CONFORME'
          ELSE TRIM(CAST(m.clm_alm_movimientos_estadoetiq AS CHAR))
        END AS estadoetiq_label,

        m.clm_alm_mov_iduser,
        COALESCE(NULLIF(TRIM(u.usuario),''), CAST(m.clm_alm_mov_iduser AS CHAR)) AS usuario_label,
        m.clm_alm_mov_OBSERVACION
    FROM tb_alm_movimientos m
    JOIN tb_alm_producto p  ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
    JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
    JOIN tb_alm_codigo cod  ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id

    LEFT JOIN tb_placas   pl ON pl.clm_placas_id   = m.clm_alm_mov_placa
    LEFT JOIN tb_usuarios u  ON u.id_usuario = m.clm_alm_mov_iduser
    LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA    
    $where
    ORDER BY m.clm_alm_mov_id DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($list_sql);
if($types){
    $types_full = $types.'ii';
    $stmt->bind_param($types_full, ...array_merge($params, [ $por_pagina, $offset ]));
} else {
    $stmt->bind_param('ii', $por_pagina, $offset);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Categorías para el filtro
$cat_rs = $conn->query("SELECT clm_alm_categoria_id AS id, clm_alm_categoria_NOMBRE AS nombre FROM tb_alm_categoria ORDER BY nombre");




?>



<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visualización de Movimientos | Norte 360°</title>
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
            max-width: 100%;
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




        .wrap{max-width:1300px;margin:30px auto;padding:0 16px}
        .card{background:#fff;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,.08);padding:20px}
        .filters{display:grid;grid-template-columns:repeat(6,minmax(160px,1fr));gap:12px}
        @media(max-width:1100px){.filters{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:600px){.filters{grid-template-columns:repeat(2,1fr)}}

        .summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:12px}
        @media(max-width:800px){.summary{grid-template-columns:repeat(2,1fr)}}
        .kpi{background:#f8fafc;border:1px solid #e9eef5;border-radius:10px;padding:14px}
        .kpi .t{font-size:13px;color:#566}
        .kpi .v{font-weight:700;font-size:18px}
.badge.b-inv{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}

        .table-shell{position:relative;margin-top:16px}
        .slide-ctrl{position:absolute;top:50%;transform:translateY(-50%);z-index:5;background:#fff;border:1px solid #e5e7eb;border-radius:50%;width:36px;height:36px;display:grid;place-items:center;box-shadow:0 2px 10px rgba(0,0,0,.12);cursor:pointer}
        .slide-left{left:-10px}
        .slide-right{right:-10px}
        .table-wrap{overflow:auto scroll;padding-bottom:6px;scroll-behavior:smooth}
        .table-wrap::after{content:"";position:absolute;inset:auto 0 0 0;height:1px;background:linear-gradient(90deg,transparent,#e5e7eb,transparent)}
        table{min-width:1200px;width:100%;border-collapse:collapse}
        th,td{padding:10px 12px;border-bottom:1px solid #eee;font-size:14px;vertical-align:top}
        th{position:sticky;top:0;background:#2c3e50;color:#fff;text-align:left;z-index:2}
        tr:hover{background:#f7fafc}
        .badge{padding:.3rem .5rem;border-radius:999px;font-weight:600;font-size:12px}
        .b-in{background:#e8f7ee;color:#117a3d;}
        .b-out{background:#fde8e7;color:#b42318;}
        .b-estado{background:#eef2ff;color:#3730a3;}
        .pagination{display:flex;gap:8px;justify-content:center;margin:18px 0}
        .pagination a,.pagination span{padding:8px 12px;border-radius:6px;font-weight:600;text-decoration:none}
        .pagination a{background:#3498db;color:#fff}
        .pagination a:hover{background:#21618c}
        .pagination .curr{color:#2980b9}
        .btn{border-radius:8px}
        .btn-export{background:#fff;border:1px solid #e5e7eb}
        .muted{color:#667085}
        .obs{max-width:420px;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}
/* ==== TOOLBAR FILTROS: MOVIMIENTOS ==== */
.filters-toolbar{
  background:#1f2a37;
  border:1px solid #0ea5e9;
  border-radius:14px;
  padding:16px 18px;
  margin:20px 0;
  color:#e5e7eb;
  box-shadow:0 6px 18px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.03);
}
.filters-toolbar .title{
  display:flex; align-items:center; gap:10px;
  font-weight:700; font-size:16px; letter-spacing:.2px; margin-bottom:12px;
}
.filters-row{
  display:grid;
  grid-template-columns: 1fr 1fr 1fr 1fr;
  gap:12px;
}
.filters-row .span-2{ grid-column: span 2; }
.filters-row .span-3{ grid-column: span 3; }

.filters-toolbar .input-icon{ position:relative; }
.filters-toolbar .input-icon .bi{
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  font-size:1rem; opacity:.85;
}
.filters-toolbar input.form-control,
.filters-toolbar select.form-select{
  padding-left:40px; height:44px; background:#111827; color:#e5e7eb; border-color:#233142;
}
.filters-toolbar input::placeholder{ color:#9ca3af; }
.filters-toolbar select.form-select{ background-image:none; }

.filters-toolbar .segmented{
  display:flex; gap:8px; flex-wrap:wrap; align-items:center; min-height:44px;
}
.filters-toolbar .segmented .btn{
  height:36px; line-height:22px; border-radius:999px; padding:.35rem .9rem;
}
.filters-toolbar .segmented .btn-check:checked + .btn{
  background:#0ea5e9; border-color:#0ea5e9; color:#0b1220; font-weight:700;
}

.filters-toolbar .quick{
  display:flex; gap:8px; flex-wrap:wrap; align-items:center;
}
.filters-toolbar .quick .chip{
  background:#0b1220; color:#e5e7eb; border:1px solid #233142;
  padding:.35rem .6rem; border-radius:999px; font-size:.85rem; cursor:pointer;
}
.filters-toolbar .quick .chip:hover{ background:#121a2b; }

.filters-toolbar .actions{
  display:flex; gap:10px; justify-content:flex-end; margin-top:8px;
}
.filters-toolbar .btn-apply,.filters-toolbar .btn-reset{
  height:44px;
}
.filters-toolbar .btn-apply{ background:#0ea5e9; border-color:#0ea5e9; }
.filters-toolbar .btn-apply:hover{ background:#0b8ec8; border-color:#0b8ec8; }
.filters-toolbar .btn-reset{ background:#0b1220; color:#e5e7eb; border:1px solid #233142; }
.filters-toolbar .btn-reset:hover{ background:#121a2b; }

@media (max-width: 1100px){
  .filters-row{ grid-template-columns: 1fr 1fr; }
  .filters-row .span-3{ grid-column: span 2; }
}
@media (max-width: 576px){
  .filters-row{ grid-template-columns: 1fr; }
  .filters-row .span-2, .filters-row .span-3{ grid-column: span 1; }
}
/* ==== ACTION BAR (Descargas / Acciones) ==== */
.actionbar{
  background:#0b1220;
  border:1px solid #233142;
  border-radius:14px;
  padding:12px 14px;
  margin:16px 0 12px;
  display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
  color:#e5e7eb;
  box-shadow:0 6px 18px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.03);
}
.actionbar .left, .actionbar .right{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

.btn-ghost, .btn-solid, .btn-soft{
  border-radius:10px; height:40px; display:inline-flex; align-items:center; gap:8px; padding:.45rem .8rem;
  font-weight:600; text-decoration:none; cursor:pointer; border:1px solid transparent;
}
.btn-solid{ background:#0ea5e9; border-color:#0ea5e9; color:#0b1220; }
.btn-solid:hover{ background:#0b8ec8; border-color:#0b8ec8; color:#0b1220; }

.btn-soft{ background:#0b1220; color:#e5e7eb; border-color:#233142; }
.btn-soft:hover{ background:#121a2b; }

.btn-ghost{ background:#0b1220; color:#e5e7eb; border-color:#233142; }
.btn-ghost:hover{ background:#121a2b; }

.stat-pill{
  display:inline-flex; align-items:center; gap:8px;
  background:#111827; color:#e5e7eb;
  padding:.35rem .65rem; border-radius:999px; font-size:.86rem; font-weight:700;
}

/* ==== KPIs PRO ==== */
.kpis-pro{
  display:grid; grid-template-columns: repeat(4,1fr); gap:12px; margin:12px 0 4px;
}
@media(max-width:1000px){ .kpis-pro{ grid-template-columns: repeat(2,1fr); } }
@media(max-width:560px){ .kpis-pro{ grid-template-columns: 1fr; } }

.kpi-card{
  background:#0f172a; border:1px solid #1f2a44; color:#e5e7eb;
  border-radius:14px; padding:14px 14px;
  box-shadow:0 6px 18px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.03);
  display:flex; gap:12px; align-items:center;
}
.kpi-card .ic{
  width:44px; height:44px; border-radius:12px; display:grid; place-items:center;
  background:#0b1220; border:1px solid #233142; font-size:20px;
}
.kpi-card .meta{ flex:1; min-width:0; }
.kpi-card .meta .label{ font-size:.82rem; opacity:.85; }
.kpi-card .meta .value{ font-weight:800; font-size:1.35rem; line-height:1.2; }
.kpi-card .meta .sub{ font-size:.8rem; opacity:.85; margin-top:2px; }

/* variantes color sutiles (borde/ícono) */
.kpi-total   { border-color:#334155; }
.kpi-in      { border-color:#16a34a; }
.kpi-out     { border-color:#dc2626; }
.kpi-inv     { border-color:#f59e0b; }
.kpi-in  .ic{ border-color:#14532d; }
.kpi-out .ic{ border-color:#7f1d1d; }
.kpi-inv .ic{ border-color:#78350f; }
.kpi-net { border-color:#06b6d4; }
.kpi-net .ic { border-color:#0e7490; }



/* =========================================================
   REDISEÑO GERENCIAL - MOVIMIENTOS DE ALMACÉN
   Mantiene lógica PHP actual; solo mejora lectura, orden y jerarquía visual.
   ========================================================= */
:root{
  --n360-bg:#eef3f8;
  --n360-ink:#0f172a;
  --n360-muted:#64748b;
  --n360-line:#dbe4ef;
  --n360-primary:#25364a;
  --n360-primary-2:#34495e;
  --n360-accent:#0ea5e9;
  --n360-green:#16a34a;
  --n360-red:#dc2626;
  --n360-orange:#f59e0b;
  --n360-card:#ffffff;
}
body{background:linear-gradient(180deg,#f7faff 0%,var(--n360-bg) 42%,#eaf0f7 100%);color:var(--n360-ink);}
hr{display:none;}
.main-content{padding:24px 26px 34px;margin-left:250px;}
body.sidebar-collapsed .main-content{margin-left:0!important;}
.wrap{width:100%;max-width:1680px;margin:0 auto;padding:0;}
.mov-hero{max-width:1680px;margin:0 auto 18px;padding:22px 24px;border-radius:22px;background:radial-gradient(circle at top right,rgba(14,165,233,.24),transparent 34%),linear-gradient(135deg,#172033,#2c3e50 62%,#111827);color:#fff;box-shadow:0 18px 40px rgba(15,23,42,.18);display:grid;grid-template-columns:minmax(280px,1fr) auto;gap:18px;align-items:center;position:relative;overflow:hidden;}
.mov-hero:after{content:"";position:absolute;left:24px;right:24px;bottom:0;height:3px;opacity:.9;border-radius:999px;}
.mov-eyebrow{display:inline-flex;gap:8px;align-items:center;background:rgba(255,255,255,.1);border-radius:999px;padding:6px 11px;font-weight:700;font-size:.82rem;color:#dbeafe;margin-bottom:10px;}
.mov-hero h1{margin:0;font-size:clamp(1.55rem,2.2vw,2.35rem);font-weight:850;letter-spacing:-.04em;}
.mov-hero p{margin:6px 0 0;color:#cbd5e1;max-width:700px;font-size:.98rem;}
.mov-hero-right{display:grid;grid-template-columns:repeat(3,minmax(145px,1fr));gap:10px;min-width:min(650px,100%);}
.hero-metric{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:16px;padding:13px 14px;backdrop-filter:blur(8px);}
.hero-metric span{display:block;color:#cbd5e1;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.hero-metric strong{display:block;font-size:1.2rem;margin-top:4px;color:#fff;white-space:nowrap;}
.hero-metric.accent{box-shadow:inset 0 0 0 1px rgba(14,165,233,.15);}
.filters-toolbar{background:#fff!important;border:1px solid var(--n360-line)!important;border-radius:18px!important;padding:16px!important;margin:0 0 14px!important;color:var(--n360-ink)!important;box-shadow:0 12px 28px rgba(15,23,42,.08)!important;}
.filters-toolbar .title{color:#172033;font-size:1rem;border-bottom:1px solid #e8eef6;padding-bottom:10px;margin-bottom:14px!important;}
.filters-toolbar .title i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:#e0f2fe;color:#0369a1;}
.filters-row{grid-template-columns:repeat(12,minmax(0,1fr))!important;gap:10px!important;align-items:end;}
.filters-toolbar .input-icon{grid-column:span 3;}
.filters-toolbar .segmented{grid-column:span 6;}
.filters-toolbar .quick{grid-column:span 6;}
.filters-toolbar input.form-control,.filters-toolbar select.form-select{height:43px!important;background:#f8fafc!important;color:#0f172a!important;border:1px solid #dbe4ef!important;border-radius:12px!important;box-shadow:none!important;font-size:.9rem;}
.filters-toolbar input.form-control:focus,.filters-toolbar select.form-select:focus{border-color:#0ea5e9!important;box-shadow:0 0 0 .18rem rgba(14,165,233,.13)!important;}
.filters-toolbar .input-icon .bi{color:#64748b!important;z-index:1;}
.filters-toolbar .segmented .btn{width:auto!important;border-radius:999px!important;color:#334155!important;border:1px solid #dbe4ef!important;background:#fff!important;font-weight:700!important;font-size:.83rem;height:37px!important;display:inline-flex;align-items:center;gap:6px;}
.filters-toolbar .segmented .btn-check:checked + .btn{background:#172033!important;border-color:#172033!important;color:#fff!important;}
.filters-toolbar .quick .chip{background:#f8fafc!important;color:#334155!important;border:1px solid #dbe4ef!important;font-weight:700;}
.filters-toolbar .quick .chip:hover{background:#e0f2fe!important;border-color:#7dd3fc!important;color:#075985!important;}
.filters-toolbar .actions{margin-top:12px!important;}
.filters-toolbar .actions button{width:auto!important;border-radius:12px!important;padding:0 16px!important;font-weight:800!important;}
.filters-toolbar .btn-apply{background:#0ea5e9!important;border-color:#0ea5e9!important;color:#fff!important;}
.filters-toolbar .btn-reset{background:#f8fafc!important;color:#334155!important;border:1px solid #dbe4ef!important;}
.actionbar{background:#fff!important;border:1px solid var(--n360-line)!important;border-radius:18px!important;color:#0f172a!important;box-shadow:0 12px 28px rgba(15,23,42,.07)!important;margin:0 0 14px!important;}
.actionbar button,.actionbar a{width:auto!important;}
.btn-solid,.btn-soft,.btn-ghost{border-radius:12px!important;height:40px!important;padding:.45rem .82rem!important;font-size:.9rem;}
.btn-solid{background:#172033!important;border-color:#172033!important;color:#fff!important;}
.btn-soft,.btn-ghost{background:#f8fafc!important;color:#334155!important;border-color:#dbe4ef!important;}
.stat-pill{background:#f8fafc!important;color:#334155!important;}
.kpis-pro{grid-template-columns:repeat(5,minmax(170px,1fr))!important;gap:12px!important;margin:0 0 14px!important;}
.kpi-card{background:#fff!important;border:1px solid #dbe4ef!important;color:#0f172a!important;border-radius:18px!important;box-shadow:0 12px 28px rgba(15,23,42,.07)!important;position:relative;overflow:hidden;}
.kpi-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:#334155;}
.kpi-in:before{background:var(--n360-green)}.kpi-out:before{background:var(--n360-red)}.kpi-inv:before{background:var(--n360-orange)}.kpi-net:before{background:#06b6d4}
.kpi-card .ic{background:#f8fafc!important;border:1px solid #e2e8f0!important;color:#172033!important;}
.kpi-card .meta .label{color:#64748b!important;font-weight:800;text-transform:uppercase;letter-spacing:.035em;font-size:.72rem!important;}
.kpi-card .meta .value{color:#0f172a!important;font-size:1.35rem!important;}
.kpi-card .meta .sub{color:#64748b!important;}
.mov-table-card{margin:0!important;padding:0!important;border:1px solid var(--n360-line)!important;border-radius:20px!important;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10)!important;background:#fff!important;}
.table-head-pro{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px 18px;border-bottom:1px solid #e8eef6;background:linear-gradient(180deg,#fff,#f8fafc);}
.table-head-pro h3{margin:0;font-size:1.05rem;color:#172033;font-weight:850;display:flex;align-items:center;gap:8px;}
.table-head-pro p{margin:3px 0 0;color:#64748b;font-size:.86rem;}
.table-head-count{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 11px;font-size:.84rem;font-weight:800;white-space:nowrap;}
.table-shell{margin-top:0!important;}
.table-wrap{max-height:calc(150vh - 360px);min-height:340px;overflow:auto!important;padding-bottom:10px;background:#fff;}
.mov-table{
  min-width:1450px!important;
  width:100%;
  table-layout:fixed!important;
  border-collapse:separate!important;
  border-spacing:0!important;
}
/* ==== ANCHOS PRO PARA TABLA SIN DOCUMENTO / FACTURA / RUC / CÓDIGO CAT ==== */
.mov-table{
  min-width:1380px!important;
  width:100%;
  table-layout:fixed!important;
  border-collapse:separate!important;
  border-spacing:0!important;
}

.mov-table th,
.mov-table td{
  border-right:0!important;
}

.mov-table th{
  text-align:center!important;
  vertical-align:middle!important;
}

.mov-table th:nth-child(4),
.mov-table th:nth-child(6),
.mov-table th:nth-child(12){
  text-align:left!important;
}

/* ID */
.mov-table th:nth-child(1),
.mov-table td:nth-child(1){ width:72px; }

/* Fecha */
.mov-table th:nth-child(2),
.mov-table td:nth-child(2){ width:150px; }

/* Tipo */
.mov-table th:nth-child(3),
.mov-table td:nth-child(3){ width:125px; }

/* Producto */
.mov-table th:nth-child(4),
.mov-table td:nth-child(4){ width:200px; }

/* Código */
.mov-table th:nth-child(5),
.mov-table td:nth-child(5){ width:105px; }

/* Categoría completa */
.mov-table th:nth-child(6),
.mov-table td:nth-child(6){ width:100px; }

/* Cantidad */
.mov-table th:nth-child(7),
.mov-table td:nth-child(7){ width:95px; }

/* Placa */
.mov-table th:nth-child(8),
.mov-table td:nth-child(8){ width:110px; }

/* Nota */
.mov-table th:nth-child(9),
.mov-table td:nth-child(9){ width:90px; }

/* Estado */
.mov-table th:nth-child(10),
.mov-table td:nth-child(10){ width:120px; }

/* Usuario */
.mov-table th:nth-child(11),
.mov-table td:nth-child(11){ width:110px; }

/* Observación */
.mov-table th:nth-child(12),
.mov-table td:nth-child(12){ width:220px; }

/* Acciones */
.mov-table th:nth-child(13),
.mov-table td:nth-child(13){ width:130px; }

.cell-category{
  font-weight:750;
  color:#334155!important;
  white-space:normal!important;
  line-height:1.25;
}


.mov-table th{position:sticky;top:0;z-index:4;background:#172033!important;color:#fff!important;border-bottom:0!important;padding:12px 12px!important;font-size:.78rem!important;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.mov-table td{padding:12px 12px!important;border-bottom:1px solid #eef2f7!important;color:#243447!important;font-size:.88rem!important;vertical-align:middle!important;background:#fff;}
.mov-table tbody tr:hover td{background:#f8fbff!important;}
.mov-table tbody tr.row-entrada td:first-child{box-shadow:inset 5px 0 0 var(--n360-green);}
.mov-table tbody tr.row-salida td:first-child{box-shadow:inset 5px 0 0 var(--n360-red);}
.mov-table tbody tr.row-inventariado td:first-child{box-shadow:inset 5px 0 0 var(--n360-orange);}
.cell-id span{display:inline-flex;color:#334155;border-radius:999px;padding:5px 9px;font-weight:850;}
.cell-date{white-space:nowrap;color:#475569!important;}
.cell-product{min-width:250px;}
.cell-product strong{display:block;color:#0f172a;font-weight:850;line-height:1.2;}
.cell-product small{display:block;color:#64748b;margin-top:3px;}
.code-pill{display:inline-flex;align-items:center;justify-content:center;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-weight:850;font-size:.78rem;white-space:nowrap;}
.code-pill.soft{background:#f8fafc;border-color:#dbe4ef;color:#475569;}
.cell-qty strong{display:block;font-size:.98rem;color:#0f172a;}
.cell-qty small{display:block;color:#64748b;margin-top:2px;}
.cell-doc{max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#334155;}
.cell-bus{white-space:nowrap;font-weight:750;color:#0f172a;}
.obs{
  max-width:none!important;
  white-space:normal!important;
  line-height:1.35;
  display:table-cell!important;
  overflow:visible!important;
}

.obs-text{
  display:-webkit-box;
  -webkit-line-clamp:2;
  -webkit-box-orient:vertical;
  overflow:hidden;
  line-height:1.35;
}
.badge{border-radius:999px!important;padding:.42rem .62rem!important;font-weight:850!important;letter-spacing:.02em;}
.b-in{background:#dcfce7!important;color:#166534!important;}
.b-out{background:#fee2e2!important;color:#991b1b!important;}
.badge.b-inv{background:#ffedd5!important;color:#9a3412!important;}
.b-estado{background:#eef2ff!important;color:#3730a3!important;}
.mov-row-actions{white-space:nowrap;}
.btn-note{width:auto!important;background:#eff6ff!important;color:#1d4ed8!important;border-radius:11px!important;padding:8px 10px!important;font-size:.82rem!important;font-weight:850!important;display:inline-flex;align-items:center;gap:6px;}
.btn-note:hover{background:#dbeafe!important;}
.no-action{color:#94a3b8;font-weight:700;}
.slide-ctrl{width:38px!important;height:38px!important;padding:0!important;background:#fff!important;color:#172033!important;border:1px solid #dbe4ef!important;box-shadow:0 8px 18px rgba(15,23,42,.14)!important;}
.pagination{padding:14px 16px;margin:0!important;background:#f8fafc;border-top:1px solid #e8eef6;}
.pagination a,.pagination span{border-radius:10px!important;text-decoration:none!important;}
.pagination a{background:#fff!important;color:#334155!important;border:1px solid #dbe4ef;}
.pagination a:hover{background:#172033!important;color:#fff!important;}
.pagination .curr{background:#172033!important;color:#fff!important;border:1px solid #172033;}
@media(max-width:1200px){.mov-hero{grid-template-columns:1fr}.mov-hero-right{grid-template-columns:repeat(3,1fr);min-width:0}.filters-toolbar .input-icon{grid-column:span 6}.filters-toolbar .segmented,.filters-toolbar .quick{grid-column:span 12}.kpis-pro{grid-template-columns:repeat(2,1fr)!important}.main-content{padding:18px;margin-left:0!important;}}
@media(max-width:680px){.mov-hero-right{grid-template-columns:1fr}.filters-toolbar .input-icon{grid-column:span 12}.kpis-pro{grid-template-columns:1fr!important}.table-head-pro{align-items:flex-start;flex-direction:column}.table-wrap{max-height:none}.mov-hero{padding:18px}.main-content{padding:14px;}}
@media print{.main-header,.nav-bar-pro,.menu-lateral,.sidebar-show-btn,.menu-toggle,.actionbar,.filters-toolbar,.slide-ctrl,.btn-flotante,.main-footer{display:none!important}.main-content{margin-left:0!important;padding:0!important}.mov-hero{box-shadow:none!important;color:#0f172a!important;background:#fff!important;border:1px solid #dbe4ef}.mov-hero p,.mov-eyebrow,.hero-metric span{color:#334155!important}.hero-metric strong{color:#0f172a!important}.table-wrap{max-height:none!important;overflow:visible!important}.mov-table{min-width:100%!important}.mov-table th{background:#172033!important;color:#fff!important}}
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
    </style>
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
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
  <?php n360_render_header(['title' => 'Movimientos de almacen', 'subtitle' => 'Inventario operativo']); ?>

<?php n360_render_sidebar(); ?>

<div class="main-content n360-main n360-main--module">
<?php n360_render_content_separator('top'); ?>
<main>

  <section class="mov-hero">
    <div class="mov-hero-left">
      <div class="mov-eyebrow"><i class="bi bi-box-seam"></i> Almacén · Control de inventario</div>
      <h1>Movimientos de Almacén</h1>
      <p>Panel de entradas, salidas e inventariados con lectura rápida para supervisión y gerencia.</p>
    </div>

    <?php if ($isAdmin): ?>
    <div class="mov-hero-right">
      <div class="hero-metric">
        <span>Registros filtrados</span>
        <strong><?= number_format($total) ?></strong>
      </div>
      <div class="hero-metric">
        <span>Saldo neto cantidad</span>
        <strong><?= number_format($neto_cantidad2, 2) ?></strong>
      </div>
      <div class="hero-metric accent">
        <span>Saldo valorizado</span>
        <strong>S/ <?= number_format($neto_monto, 2) ?></strong>
      </div>
    </div>
    <?php endif; ?>
  </section>




<div class="wrap">
<?php
  // Valores actuales (ya los tienes arriba)
  $desde        = h($desde);
  $hasta        = h($hasta);
  $tipo         = h($tipo);
  $buscar       = h($buscar);
  $placa        = h($placa);
  $estadoetiq   = h($estadoetiq);
  $categoria_id = (int)$categoria_id;
?>
<form id="filtrosMov" class="filters-toolbar" method="get">
  <div class="title">
    <i class="bi bi-funnel"></i>
    <span>Filtros de movimientos</span>
  </div>

  <div class="filters-row">
    <!-- Desde -->
    <div class="input-icon">
      <i class="bi bi-calendar-event"></i>
      <input type="date" id="desde" name="desde" value="<?=$desde?>" class="form-control" placeholder="Desde">
    </div>

    <!-- Hasta -->
    <div class="input-icon">
      <i class="bi bi-calendar-check"></i>
      <input type="date" id="hasta" name="hasta" value="<?=$hasta?>" class="form-control" placeholder="Hasta">
    </div>

    <!-- Categoría -->
    <div class="input-icon">
      <i class="bi bi-collection"></i>
      <select name="categoria_id" class="form-select" aria-label="Categoría">
        <option value="0">Categoría (Todas)</option>
        <?php while($c = $cat_rs->fetch_assoc()): ?>
          <option value="<?=$c['id']?>" <?= $categoria_id==intval($c['id'])?'selected':''; ?>>
            <?=h($c['nombre'])?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>

    <!-- Búsqueda libre -->
    <div class="input-icon">
      <i class="bi bi-search"></i>
      <input type="text" name="buscar" value="<?=$buscar?>" class="form-control" placeholder="Producto, código o grupo">
    </div>

    <!-- Tipo (segmentado) -->
    <div class="segmented span-2" role="group" aria-label="Tipo movimiento">
      <?php $is = fn($v)=> $tipo===$v ? 'checked' : ''; ?>
      <input type="radio" class="btn-check" name="tipo" id="tAll" value=""  <?= $tipo===''?'checked':''; ?>>
      <label class="btn btn-outline-light" for="tAll"><i class="bi bi-sliders"></i> Todos</label>

      <input type="radio" class="btn-check" name="tipo" id="tIn" value="ENTRADA" <?= $is('ENTRADA'); ?>>
      <label class="btn btn-outline-light" for="tIn"><i class="bi bi-box-arrow-in-down"></i> Entrada</label>

      <input type="radio" class="btn-check" name="tipo" id="tOut" value="SALIDA" <?= $is('SALIDA'); ?>>
      <label class="btn btn-outline-light" for="tOut"><i class="bi bi-box-arrow-up"></i> Salida</label>

      <input type="radio" class="btn-check" name="tipo" id="tInv" value="INVENTARIADO" <?= $is('INVENTARIADO'); ?>>
      <label class="btn btn-outline-light" for="tInv"><i class="bi bi-clipboard-check"></i> Inventariado</label>
    </div>

    <!-- Placa -->
    <div class="input-icon">
      <i class="bi bi-bus-front"></i>
      <input type="text" name="placa" value="<?=$placa?>" class="form-control" placeholder="Placa">
    </div>

    <!-- Estado etiqueta -->
    <div class="input-icon">
      <i class="bi bi-tags"></i>
      <input type="text" name="estadoetiq" value="<?=$estadoetiq?>" class="form-control" placeholder="Estado etiqueta">
    </div>

    <!-- Rango rápido -->
    <div class="quick span-3">
      <span class="chip" onclick="setQuickRange('today')">Hoy</span>
      <span class="chip" onclick="setQuickRange('7d')">Últimos 7 días</span>
      <span class="chip" onclick="setQuickRange('30d')">Últimos 30 días</span>
      <span class="chip" onclick="setQuickRange('ytd')">Año actual</span>
      <span class="chip" onclick="clearDates()">Sin fechas</span>
    </div>
  </div>

  <div class="actions">
    <button type="submit" class="btn btn-primary btn-apply">
      <i class="bi bi-check2-circle me-1"></i> Aplicar
    </button>
    <button type="button" class="btn btn-reset" onclick="limpiarFiltrosMov()">
      <i class="bi bi-x-circle me-1"></i> Limpiar
    </button>
  </div>
</form>


<!-- ACTION BAR -->
<div class="actionbar">
  <div class="left">
    <?php if ($isAdmin): ?>
    <a class="btn-solid" href="?<?php $q=$_GET; $q['export']=1; echo h(http_build_query($q)); ?>">
      <i class="bi bi-download"></i> Exportar CSV
    </a>
    <?php endif; ?>

    <button type="button" class="btn-soft" onclick="window.print()">
      <i class="bi bi-printer"></i> Imprimir
    </button>
    
    <?php if ($isAdmin): ?>
    <button type="button" class="btn-soft" onclick="exportarPDFMovimientos()">
      <i class="bi bi-filetype-pdf"></i> Exportar PDF
    </button>
    <?php endif; ?>

    <a class="btn-ghost" href="movimientos_ofi.php">
      <i class="bi bi-arrow-counterclockwise"></i> Limpiar
    </a>
  </div>

  <div class="right">
    <?php if ($isAdmin): ?>
    <span class="stat-pill"><i class="bi bi-collection"></i> Registros: <?= number_format($total) ?></span>
    <?php endif; ?>
    <?php if(!empty($tipo)): ?>
      <span class="stat-pill"><i class="bi bi-diagram-3"></i> Tipo: <?= h($tipo) ?></span>
    <?php endif; ?>
    <?php if(!empty($desde) || !empty($hasta)): ?>
      <span class="stat-pill"><i class="bi bi-calendar-range"></i>
        Rango: <?= $desde ?: '…' ?> → <?= $hasta ?: '…' ?>
      </span>
    <?php endif; ?>
  </div>
</div>


<!-- KPIs PRO -->
<?php if ($isAdmin): ?>
<div class="kpis-pro">
  <!-- Total registros -->
  <div class="kpi-card kpi-total">
    <div class="ic"><i class="bi bi-ui-checks-grid"></i></div>
    <div class="meta">
      <div class="label">Registros</div>
      <div class="value kpi-value" data-val="<?= (int)$total ?>"><?= number_format($total) ?></div>
      <div class="sub">Resultado de filtros activos</div>
    </div>
  </div>

  <!-- Entradas -->
  <div class="kpi-card kpi-in">
    <div class="ic"><i class="bi bi-box-arrow-in-down"></i></div>
    <div class="meta">
      <div class="label">Cant. Entradas</div>
      <div class="value kpi-value" data-val="<?= (float)($sumas['cant_entradas'] ?? 0) ?>">
        <?= number_format($sumas['cant_entradas'] ?? 0, 2) ?>
      </div>
      <div class="sub">S/ <?= number_format($sumas['monto_entradas'] ?? 0, 2) ?></div>
    </div>
  </div>

  <!-- Salidas -->
  <div class="kpi-card kpi-out">
    <div class="ic"><i class="bi bi-box-arrow-up"></i></div>
    <div class="meta">
      <div class="label">Cant. Salidas</div>
      <div class="value kpi-value" data-val="<?= (float)($sumas['cant_salidas'] ?? 0) ?>">
        <?= number_format($sumas['cant_salidas'] ?? 0, 2) ?>
      </div>
      <div class="sub">S/ <?= number_format($sumas['monto_salidas'] ?? 0, 2) ?></div>
    </div>
  </div>

  <!-- Inventariado -->
  <div class="kpi-card kpi-inv">
    <div class="ic"><i class="bi bi-clipboard-check"></i></div>
    <div class="meta">
      <div class="label">Cant. Inventariado</div>
      <div class="value kpi-value" data-val="<?= (float)($sumas['cant_inventariado'] ?? 0) ?>">
        <?= number_format($sumas['cant_inventariado'] ?? 0, 2) ?>
      </div>
      <div class="sub">S/ <?= number_format($sumas['monto_inventariado'] ?? 0, 2) ?></div>
    </div>
  </div>


<!-- Cantidad total (Inv + Entr - Sal) -->
<div class="kpi-card kpi-net">
  <div class="ic"><i class="bi bi-calculator"></i></div>
  <div class="meta">
    <div class="label">Cantidad total</div>
    <div class="value kpi-value" data-val="<?= (float)$neto_cantidad2 ?>">
      <?= number_format($neto_cantidad2, 2) ?>
    </div>
    <div class="sub">Neto = Inv + Entr − Sal · S/ <?= number_format($neto_monto, 2) ?></div>
  </div>
</div>


</div>
<?php endif; ?>


    </div>


    <div class="card mov-table-card">
        <div class="table-head-pro">
          <div>
            <h3><i class="bi bi-list-check"></i> Detalle de movimientos</h3>
            <p>Vista operativa con trazabilidad de producto, unidad, usuario y observación.</p>
          </div>
          <div class="table-head-count">Página <?= number_format($pagina) ?> / <?= number_format($total_paginas) ?></div>
        </div>
        <!-- Tabla con deslizamiento -->
        <div class="table-shell">
            <button type="button" class="slide-ctrl slide-left" onclick="scrollTbl(-1)" title="Deslizar izquierda"><i class="bi bi-chevron-left"></i></button>
            <div class="table-wrap" id="tblWrap">
                <table class="mov-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Producto</th>
                            <th>Código</th>
                            <th>Categoría</th>
                            <th class="text-end">Cantidad</th>
                            <th>Placa</th>
                            <th>Nota</th>
                            <th>Estado</th>
                            <th>Usuario</th>
                            <th>Observación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(!$rows): ?>
                        <tr><td colspan="13" class="text-center text-muted py-4">Sin resultados con los filtros actuales.</td></tr>
                    <?php else: foreach($rows as $r): ?>
                        <?php
                          $tipoRow = strtoupper(trim($r['clm_alm_mov_TIPO'] ?? ''));
                          $rowClass = $tipoRow === 'ENTRADA' ? 'row-entrada' : ($tipoRow === 'SALIDA' ? 'row-salida' : ($tipoRow === 'INVENTARIADO' ? 'row-inventariado' : ''));
                        ?>
                        <tr class="mov-row <?= $rowClass ?>">
                            <td class="cell-id"><span>#<?=h($r['clm_alm_mov_id'])?></span></td>
                            <td class="cell-date"><i class="bi bi-calendar3"></i> <?=h($r['clm_alm_mov_fecha_registro'])?></td>
                            <td>
                              <?php if($r['clm_alm_mov_TIPO']==='ENTRADA'): ?>
                                <span class="badge b-in">ENTRADA</span>
                              <?php elseif($r['clm_alm_mov_TIPO']==='SALIDA'): ?>
                                <span class="badge b-out">SALIDA</span>
                              <?php elseif($r['clm_alm_mov_TIPO']==='INVENTARIADO'): ?>
                                <span class="badge b-inv">INVENTARIADO</span>
                              <?php else: ?>
                                <span class="badge" style="background:#eef2f7;border:1px solid #d9e2ec;color:#334e68"><?=h($r['clm_alm_mov_TIPO'])?></span>
                              <?php endif; ?>
                            </td>

                            <td class="cell-product"><strong><?=h($r['producto'])?></strong><small><?=h($r['categoria'])?></small></td>
                            <td><span class="code-pill"><?=h($r['cod_prod'])?></span></td>
                            <td class="cell-category">
                              <?=h((!empty($r['codigo_categoria']) ? '(' . $r['codigo_categoria'] . ') ' : '') . ($r['categoria'] ?? '-'))?>
                            </td>
                            <td class="text-end cell-qty">
                              <strong><?=number_format((float)$r['clm_alm_mov_cantidad'],2)?></strong>
                              <?php if ($isAdmin): ?>
                                <small>S/ <?=number_format((float)($r['clm_alm_mov_monto'] ?? 0),2)?></small>
                              <?php endif; ?>
                            </td>
                            <td class="cell-bus"><i class="bi bi-bus-front"></i> <?=h($r['placa_label'] ?: '-')?></td>
                            <td><?=h($r['nota_label'] ?: '-')?></td>
                            <td><span class="badge b-estado"><?= h($r['estadoetiq_label'] ?? 'CONFORME') ?></span></td>
                            <td><?=h($r['usuario_label'])?></td>
                            <td class="obs" title="<?=h($r['clm_alm_mov_OBSERVACION'])?>">
                              <div class="obs-text"><?=h($r['clm_alm_mov_OBSERVACION'])?></div>
                            </td>
                            <td class="mov-row-actions">
                                <?php if(!empty($r['clm_alm_mov_idNOTA'])): ?>
                                    <button class="btn-note" onclick="verNotaSalida(<?= (int)$r['clm_alm_mov_id']?>)">
                                        <i class="bi bi-file-earmark-text"></i> Ver Nota
                                    </button>
                                <?php else: ?>
                                    <span class="no-action">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="slide-ctrl slide-right" onclick="scrollTbl(1)" title="Deslizar derecha"><i class="bi bi-chevron-right"></i></button>
        </div>

        <!-- Paginación -->
        <div class="pagination">
            <?php
            // Rango compacto
            $q = $_GET;
            $rango = 2;
            for($i=1; $i <= $total_paginas; $i++){
                if ($i==1 || $i==$total_paginas || ($i >= $pagina-$rango && $i <= $pagina+$rango)){
                    if($i==$pagina){
                        echo '<span class="curr">['. $i .']</span>';
                    } else {
                        $q['pagina']=$i;
                        echo '<a href="?'. h(http_build_query($q)) .'">'. $i .'</a>';
                    }
                } else if ($i==2 && $pagina>4 || $i==$total_paginas-1 && $pagina < $total_paginas-3){
                    echo '<span class="muted">…</span>';
                }
            }
            ?>
        </div>


</div>





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




</main>
</div>





<?php n360_render_content_separator('bottom'); ?>

<?php n360_render_footer(); ?>

<script>
window.N360_NOTA_PDF_CONFIG = {
  endpoint: '<?= h(n360_base_url('php/nota_pdf_data.php')) ?>',
  userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
  dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
  logoTicket: '<?= h(n360_base_url('img/completo.png')) ?>',
  footerLabel: 'NORTE 360'
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/plantillas/n360_pdf_a4.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_notas_common.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_entrada_almacen.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_entrada_bienes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_salida_bienes.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_tanqueada.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_abastecimiento.js') ?>"></script>
<script src="<?= n360_asset('assets/js/nota_pdf_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
<?php if ($isAdmin): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<?php endif; ?>

<script>
function limpiarFiltrosMov(){
  // Ajusta si tu archivo tiene otra ruta/nombre
  window.location.href = 'movimientos_ofi.php';
}

function clearDates(){
  document.getElementById('desde').value = '';
  document.getElementById('hasta').value = '';
}

function setQuickRange(preset){
  const tzOffsetMs = (new Date()).getTimezoneOffset() * 60000; // normaliza a local
  const today = new Date(Date.now() - tzOffsetMs);
  const fmt = (d)=> d.toISOString().slice(0,10); // YYYY-MM-DD local

  let d1 = '', d2 = '';
  if(preset==='today'){
    d1 = d2 = fmt(today);
  }else if(preset==='7d'){
    const from = new Date(today); from.setDate(from.getDate()-6);
    d1 = fmt(from); d2 = fmt(today);
  }else if(preset==='30d'){
    const from = new Date(today); from.setDate(from.getDate()-29);
    d1 = fmt(from); d2 = fmt(today);
  }else if(preset==='ytd'){
    const from = new Date(today.getFullYear(), 0, 1);
    d1 = fmt(from); d2 = fmt(today);
  }
  document.getElementById('desde').value = d1;
  document.getElementById('hasta').value = d2;
}
</script>


<script>
    function scrollTbl(dir){
  const wrap = document.getElementById('tblWrap');
  const step = Math.max(300, wrap.clientWidth * 0.7);
  wrap.scrollBy({left: dir * step, behavior:'smooth'});
}
</script>
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
      document.getElementById('contenido-nota').innerHTML = 'No se pudo cargar la nota.';
    });
}

function cerrarNotaModal() {
  document.getElementById('modal-nota').style.display = 'none';
}
document.addEventListener('click', function(e){
  const modalNota = document.getElementById('modal-nota');

  if (modalNota && e.target === modalNota && modalNota.style.display === 'block') {
    cerrarNotaModal();
  }
});
</script>

<script>
(function(){
  const els = document.querySelectorAll('.kpi-value[data-val]');
  els.forEach(el=>{
    const target = parseFloat(el.getAttribute('data-val')||'0');
    const isInt = Number.isInteger(target);
    const dur = 600;
    const start = performance.now();
    const dec = isInt ? 0 : 2;

    function fmt(n){ return n.toLocaleString(undefined, {minimumFractionDigits:dec, maximumFractionDigits:dec}); }

    function tick(t){
      const p = Math.min(1, (t - start) / dur);
      const val = target * (0.2 + 0.8*Math.pow(p, 0.85)); // ease-out
      el.textContent = fmt(p<1 ? val : target);
      if(p<1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  });
})();
</script>

<?php if ($isAdmin): ?>
<script>

async function exportarPDFMovimientos() {
  const { jsPDF } = window.jspdf;

  // ========= Helpers =========
  const pad = (n)=> String(n).padStart(2,'0');
  const now = new Date();
  const generatedAt = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

  const getVal = (sel) => (document.querySelector(sel)?.value ?? '').trim();
  const trunc = (s, n=90) => {
    s = (s ?? '').toString().trim();
    return s.length > n ? s.slice(0, n-1) + '…' : s;
  };

  const fmtTipo = (t) => {
    t = (t ?? '').toString().trim().toUpperCase();
    if (t === 'ENTRADA') return 'ENT';
    if (t === 'SALIDA') return 'SAL';
    if (t === 'INVENTARIADO') return 'INV';
    return trunc(t, 6);
  };

  const fmtFecha = (s) => {
    s = (s ?? '').toString().trim();
    if (!s) return '';
    const date = s.slice(0, 10);
    const time = s.slice(11, 16);
    return time ? `${date}\n${time}` : date;
  };

  // ========= Filtros visibles =========
  const filtros = {
    desde: getVal('input[name="desde"]'),
    hasta: getVal('input[name="hasta"]'),
    tipo:  (document.querySelector('input[name="tipo"]:checked')?.value ?? '').trim(),
    buscar: getVal('input[name="buscar"]'),
    placa: getVal('input[name="placa"]'),
    estadoetiq: getVal('input[name="estadoetiq"]'),
    categoria: (document.querySelector('select[name="categoria_id"] option:checked')?.textContent ?? '').trim()
  };

  // ========= KPIs desde DOM =========
  const kpis = (() => {
    const cards = document.querySelectorAll('.kpi-card');
    const arr = [];
    cards.forEach(c => {
      const label = (c.querySelector('.label')?.textContent ?? '').trim();
      const value = (c.querySelector('.value')?.textContent ?? '').trim();
      const sub   = (c.querySelector('.sub')?.textContent ?? '').trim();
      if(label && value) arr.push({ label, value, sub });
    });
    return arr;
  })();

  // ========= Data: página o completo =========
  const rows = [];

  document.querySelectorAll('table tbody tr').forEach(tr => {
    const tds = tr.querySelectorAll('td');
    if (tds.length < 13) return; // tu tabla tiene 13 columnas (incluye Acciones)

    rows.push({
      clm_alm_mov_id: (tds[0]?.textContent ?? '').trim(),
      clm_alm_mov_fecha_registro: (tds[1]?.textContent ?? '').trim(),
      clm_alm_mov_TIPO: (tds[2]?.textContent ?? '').trim(),
      producto: (tds[3]?.textContent ?? '').trim(),
      cod_prod: (tds[4]?.textContent ?? '').trim(),
      categoria: (tds[5]?.textContent ?? '').trim(),

      clm_alm_mov_cantidad: (tds[6]?.textContent ?? '').trim(),

      placa_label: (tds[7]?.textContent ?? '').trim(),
      nota_label: (tds[8]?.textContent ?? '').trim(),
      estadoetiq_label: (tds[9]?.textContent ?? '').trim(),

      usuario_label: (tds[10]?.textContent ?? '').trim(),
      clm_alm_mov_OBSERVACION: (tds[11]?.textContent ?? '').trim(),
    });
  });


  if (!rows.length) { alert('No hay data para exportar a PDF.'); return; }

  // ========= PDF Setup =========
  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
  const pageW = doc.internal.pageSize.getWidth();   // ~842
  const pageH = doc.internal.pageSize.getHeight();  // ~595
  const marginX = 36;

  // TIPOGRAFÍA UNIFICADA (y sin spacing raro)
  doc.setFont('helvetica', 'normal');
  if (doc.setCharSpace) doc.setCharSpace(0);

  const C_PRIMARY = [44, 62, 80];
  const C_ACCENT  = [52, 152, 219];
  const C_LINE    = [226, 232, 240];
  const C_TEXT    = [30, 41, 59];

  const totalPagesExp = "{total_pages_count_string}";

  async function loadImgAsDataURL(url) {
    const r = await fetch(url);
    const b = await r.blob();
    return await new Promise((resolve) => {
      const fr = new FileReader();
      fr.onload = () => resolve(fr.result);
      fr.readAsDataURL(b);
    });
  }
  let logoData = null;
  try { logoData = await loadImgAsDataURL('../img/norte360.png'); } catch(e) {}

  const userName = (document.querySelector('.usuario-barra span')?.textContent ?? '').replace('Hola,', '').trim();

  // ========= Layout fijo para TODAS las páginas =========
  const TABLE_TOP = 200; // margen superior real de la tabla (evita que pise filtros/KPIs)

  const drawHeader = () => {
    doc.setFont('helvetica', 'normal');
    if (doc.setCharSpace) doc.setCharSpace(0);

    // Banda superior
    doc.setFillColor(...C_PRIMARY);
    doc.rect(0, 0, pageW, 54, 'F');

    if (logoData) {
      try { doc.addImage(logoData, 'PNG', marginX, 12, 30, 30); } catch(e) {}
    }

    doc.setTextColor(255,255,255);
    doc.setFont('helvetica','bold');
    doc.setFontSize(14);
    doc.text('Norte 360° — Reporte de Movimientos', marginX + 42, 32);

    doc.setFont('helvetica','normal');
    doc.setFontSize(9);
    doc.text(`Generado: ${generatedAt}${userName ? ' | Usuario: ' + userName : ''}`, marginX + 42, 47);

    // Tarjeta de filtros
    const boxX = marginX;
    const boxY = 64;
    const boxW = pageW - marginX*2;
    const boxH = 56;

    doc.setDrawColor(...C_LINE);
    doc.setFillColor(248, 250, 252);
    doc.roundedRect(boxX, boxY, boxW, boxH, 10, 10, 'FD');

    const col3 = boxW / 3;
    const col4 = boxW / 4;

    const vRango = `${filtros.desde || '…'} → ${filtros.hasta || '…'}`;
    const vTipo  = filtros.tipo || 'Todos';
    const vCat   = filtros.categoria || 'Todas';
    const vBus   = filtros.buscar || '—';
    const vPlaca = filtros.placa || '—';
    const vEst   = filtros.estadoetiq || '—';
    const vModo  = 'Tabla actual';

    doc.setTextColor(...C_TEXT);
    doc.setFontSize(9);

    // ✅ DECLARAR ANTES DE USAR
    const writePair = (x, y, label, value, maxChars) => {
      doc.setFont('helvetica','bold');  doc.text(label, x, y);
      doc.setFont('helvetica','normal'); doc.text(trunc(value, maxChars), x + doc.getTextWidth(label) + 4, y);
    };

    writePair(boxX + 12,           boxY + 20, 'Rango:', vRango, 30);
    writePair(boxX + 12 + col3,    boxY + 20, 'Tipo:',  vTipo,  18);
    writePair(boxX + 12 + col3*2,  boxY + 20, 'Categoría:', vCat, 22);

    writePair(boxX + 12,            boxY + 40, 'Buscar:', vBus,   26);
    writePair(boxX + 12 + col4,     boxY + 40, 'Placa:',  vPlaca, 14);
    writePair(boxX + 12 + col4*2,   boxY + 40, 'Estado:', vEst,   16);
    writePair(boxX + 12 + col4*3,   boxY + 40, 'Modo:',   vModo,  18);
  };

  const drawKpis = () => {
    doc.setFont('helvetica', 'normal');
    if (doc.setCharSpace) doc.setCharSpace(0);

    const x0 = marginX;
    const y0 = 128;
    const gap = 10;
    const cardH = 46;
    const usable = pageW - marginX*2;
    const shown = kpis.slice(0, 5);
    const cardW = (usable - gap*(shown.length-1)) / shown.length;

    const accents = [
      [51, 65, 85],
      [22, 163, 74],
      [220, 38, 38],
      [245, 158, 11],
      [6, 182, 212]
    ];

    shown.forEach((k, i) => {
      const x = x0 + i*(cardW + gap);
      const ac = accents[i] || C_ACCENT;

      doc.setDrawColor(...C_LINE);
      doc.setFillColor(255,255,255);
      doc.roundedRect(x, y0, cardW, cardH, 10, 10, 'FD');

      doc.setFillColor(...ac);
      doc.roundedRect(x, y0, cardW, 6, 10, 10, 'F');

      doc.setTextColor(...C_TEXT);
      doc.setFontSize(8);
      doc.setFont('helvetica','normal');
      doc.text(trunc(k.label, 18), x + 10, y0 + 18);

      doc.setFont('helvetica','bold');
      doc.setFontSize(12);
      doc.text(trunc(k.value, 16), x + 10, y0 + 35);

      if (k.sub) {
        doc.setFont('helvetica','normal');
        doc.setFontSize(8);
        doc.setTextColor(100, 116, 139);
        doc.text(trunc(k.sub, 22), x + 10, y0 + 44);
      }
    });

    doc.setDrawColor(...C_ACCENT);
    doc.setLineWidth(1);
    doc.line(marginX, y0 + cardH + 12, pageW - marginX, y0 + cardH + 12);
  };

  const drawFooter = () => {
    doc.setFont('helvetica','normal');
    if (doc.setCharSpace) doc.setCharSpace(0);

    const pageNumber = doc.internal.getCurrentPageInfo().pageNumber;

    doc.setDrawColor(...C_LINE);
    doc.setLineWidth(1);
    doc.line(marginX, pageH - 30, pageW - marginX, pageH - 30);

    doc.setFontSize(9);
    doc.setTextColor(100, 116, 139);

    doc.text('Norte 360° · Inventario · Movimientos', marginX, pageH - 14);
    doc.text(`Página ${pageNumber} de ${totalPagesExp}`, pageW - marginX, pageH - 14, { align: 'right' });
  };

  // ========= Tabla (PLACA y NOTA separadas) =========
  const head = [[
    'ID','Fecha','Tipo','Producto','Categoría','Cant.','Placa','Nota','Estado','Obs.'

  ]];

  const body = rows.map(r => {
    const placa = (r.placa_label ?? '').toString().trim() || '—';
    const nota  = (r.nota_label ?? '').toString().trim() || '—';
    const prod = `${trunc(r.cod_prod ?? '', 14)}\n${trunc(r.producto ?? '', 34)}`;
    const cat  = trunc(r.categoria ?? '', 55);
    const estado = (r.estadoetiq_label ?? '').toString().trim() || '—';

    return [
      r.clm_alm_mov_id ?? '',
      fmtFecha(r.clm_alm_mov_fecha_registro ?? ''),
      fmtTipo(r.clm_alm_mov_TIPO ?? ''),
      prod,
      cat,
      (r.clm_alm_mov_cantidad ?? '').toString(),
      trunc(placa, 24),
      trunc(nota, 10),
      estado,
      trunc(r.clm_alm_mov_OBSERVACION ?? '', 140)
    ];
  });

  doc.autoTable({
    head,
    body,

    // IMPORTANTÍSIMO: esto evita que la tabla se meta en header/KPIs
    startY: TABLE_TOP,
    margin: { left: marginX, right: marginX, top: TABLE_TOP, bottom: 40 },

    theme: 'striped',
    styles: {
      font: 'helvetica',
      fontStyle: 'normal',
      fontSize: 8,
      cellPadding: 4,
      overflow: 'linebreak',
      valign: 'top',
      lineColor: C_LINE,
      lineWidth: 0.6,
    },
    headStyles: {
      font: 'helvetica',
      fontStyle: 'bold',
      fillColor: C_PRIMARY,
      textColor: 255,
      halign: 'left'
    },
    alternateRowStyles: { fillColor: [248, 250, 252] },

    // ANCHOS CUADRADOS (suman el ancho útil, no se deforma)
    columnStyles: {
      0: { cellWidth: 32 },                 // ID
      1: { cellWidth: 60 },                 // Fecha
      2: { cellWidth: 36, halign:'center' },// Tipo
      3: { cellWidth: 150 },                // Producto
      4: { cellWidth: 115 },                // Categoría
      5: { cellWidth: 48, halign:'right' }, // Cant.
      6: { cellWidth: 70 },                 // Placa
      7: { cellWidth: 46 },                 // Nota
      8: { cellWidth: 58 },                 // Estado
      9: { cellWidth: 155 }                 // Obs.
    },

    // Header/KPIs ANTES de la tabla (no se pisan)
    willDrawPage: function () {
      drawHeader();
      drawKpis();
    },

    // Footer DESPUÉS
    didDrawPage: function () {
      drawFooter();
    },

    didParseCell: function (data) {
      if (data.section === 'body' && data.column.index === 2) {
        const v = (data.cell.raw ?? '').toString().trim();
        if (v === 'ENT') {
          data.cell.styles.fillColor = [232, 247, 238];
          data.cell.styles.textColor = [17, 122, 61];
          data.cell.styles.fontStyle = 'bold';
        } else if (v === 'SAL') {
          data.cell.styles.fillColor = [253, 232, 231];
          data.cell.styles.textColor = [180, 35, 24];
          data.cell.styles.fontStyle = 'bold';
        } else if (v === 'INV') {
          data.cell.styles.fillColor = [255, 247, 237];
          data.cell.styles.textColor = [154, 52, 18];
          data.cell.styles.fontStyle = 'bold';
        }
      }
      if (data.section === 'body' && data.column.index === 8) {
        data.cell.styles.fillColor = [238, 242, 255];
        data.cell.styles.textColor = [55, 48, 163];
        data.cell.styles.fontStyle = 'bold';
      }
    }
  });

  if (doc.putTotalPages) doc.putTotalPages(totalPagesExp);

  const fname = `Movimientos_${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}.pdf`;
  doc.save(fname);
}


</script>
<?php endif; ?>


</html>
<?php $conn->close(); ?>
