<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}
$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);
if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 6; // id_modulo de esta vista
    if (!in_array($modulo_actual, $_SESSION['permisos'])) {
        header("Location: ../login/none_permisos.php");
        exit();
    }
}
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
require_once("../trash/copidb_secure.php");
define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
$sql = "SELECT id_entrevista, nombre, puesto, clm_estado, clm_yesorno FROM entrevistas ORDER BY puesto, clm_estado";
$resultado = $conn->query($sql);
$kanban = [
    0 => [], // 👈 nuevo estado para Rechazado
    1 => [],
    2 => [],
    3 => [],
    4 => [],
    5 => [],
    6 => []
];
$rechazadosPorEtapa = [];
while ($row = $resultado->fetch_assoc()) {
    $estado = (int)$row["clm_estado"];
    $yesorno = (int)$row["clm_yesorno"];
    if ($yesorno === 2) {
        $rechazadosPorEtapa[$estado][] = $row;
    } else {
        $kanban[$estado][] = $row;
    }
}
$conn->close();

// =====================================================
// CONFIGURACIÓN VISUAL DEL KANBAN
// No altera estados ni lógica de negocio; solo centraliza la presentación.
// =====================================================
$etapasKanban = [
    1 => [
        'nombre' => 'Selección',
        'descripcion' => 'Preselección de candidatos',
        'icono' => 'bi-search'
    ],
    2 => [
        'nombre' => 'Entrevista',
        'descripcion' => 'Evaluación presencial',
        'icono' => 'bi-chat-dots'
    ],
    3 => [
        'nombre' => 'Inducción',
        'descripcion' => 'Ingreso e inducción',
        'icono' => 'bi-mortarboard'
    ],
    4 => [
        'nombre' => 'Mes de prueba',
        'descripcion' => 'Periodo inicial de validación',
        'icono' => 'bi-hourglass-split'
    ],
    5 => [
        'nombre' => 'Solicitud Trabajador',
        'descripcion' => 'Alta administrativa',
        'icono' => 'bi-file-earmark-check'
    ],
    6 => [
        'nombre' => 'Trabajando / En planilla',
        'descripcion' => 'Colaborador activo',
        'icono' => 'bi-person-check'
    ]
];

$totalActivosKanban = 0;
$totalProcesoKanban = 0;
$totalRechazadosKanban = 0;

for ($i = 1; $i <= 6; $i++) {
    $cantidadEtapa = count($kanban[$i] ?? []);
    $totalActivosKanban += $cantidadEtapa;
    if ($i <= 5) {
        $totalProcesoKanban += $cantidadEtapa;
    }
    $totalRechazadosKanban += count($rechazadosPorEtapa[$i] ?? []);
}

$totalTrabajandoKanban = count($kanban[6] ?? []);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevistas por Etapa | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">      
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
#popup-exito {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    animation: fadeIn 0.4s ease forwards;
}
#popup-exito .mensaje {
    background: linear-gradient(to left, #2ecc71, #27ae60);
    padding: 20px 40px;
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
    font-size: 20px;
    font-weight: bold;
    color: white;
    text-align: center;
    animation: scaleIn 0.4s ease forwards;
    transform: scale(0.8);
    opacity: 0;
}
@keyframes fadeIn {
    to {
        opacity: 1;
    }
}
@keyframes scaleIn {
    to {
        transform: scale(1);
        opacity: 1;
    }
}
@keyframes fadeOut {
    to {
        opacity: 0;
        transform: scale(0.9);
    }
}
.check-icon {
  width: 80px;
  height: 80px;
  stroke: #fff;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  background: #2ecc71;
  border-radius: 50%;
  padding: 10px;
  box-shadow: 0 0 15px rgba(0, 0, 0, 0.3);
  margin: 0 auto 10px auto;
  display: block;
}
.check-circle {
  stroke-dasharray: 157;
  stroke-dashoffset: 157;
  animation: drawCircle 0.6s ease-out forwards;
}
.check-mark {
  stroke-dasharray: 36;
  stroke-dashoffset: 36;
  animation: drawCheck 0.4s ease-out 0.5s forwards;
}
.texto-popup {
  margin-top: 10px;
  font-size: 18px;
  color: white;
  font-weight: bold;
  animation: fadeInText 0.4s ease-in 0.8s forwards;
  opacity: 0;
}
@keyframes drawCircle {
  to {
    stroke-dashoffset: 0;
  }
}
@keyframes drawCheck {
  to {
    stroke-dashoffset: 0;
  }
}
@keyframes fadeInText {
  to {
    opacity: 1;
  }
}
.formulario-entrevista {
    display: flex;
    flex-direction: column;
    gap: 15px;
}
.campo-form {
    display: flex;
    flex-direction: column;
}
.campo-form label {
    font-weight: bold;
    color: #2c3e50;
    margin-bottom: 6px;
}
.campo-form input,
.campo-form textarea {
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 10px;
    font-size: 15px;
    transition: border 0.3s;
}
.campo-form input:focus,
.campo-form textarea:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
}
.grupo-flex {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}
.grupo-flex .campo-form {
    flex: 1;
}
    .filtros {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 20px;
      margin: 20px;
    }
    .filtros input, .filtros select {
      padding: 10px;
      border-radius: 8px;
      border: 1px solid #ccc;
      min-width: 180px;
    }
        .tabla-contenedor {
            overflow-x: auto;
            padding: 10px;
            display: flex;
            justify-content: center;
        padding: 10px;
        }
        table {
            width: 70%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            min-width: 600px;
        }
        th, td {
            padding: 14px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #2c3e50;
            color: white;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .volver-btn {
            display: inline-block;
            margin: 20px auto;
            background: linear-gradient(120deg, #2980b9, #3498db, #2980b9);
            background-size: 200% auto;
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
            transition: transform 0.3s ease;
            animation: shimmer 3s infinite linear;
            text-align: center;
        }
        .volver-btn:hover {
            background: #1c5980;
        }
        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }
        @media (max-width: 600px) {
        .tabla-contenedor {
            overflow-x: auto;
            justify-content: flex-start;
        }
        table {
            min-width: 100%;
        }
        }
.input-evaluacion {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid #ccc;
  border-radius: 10px;
  font-size: 15px;
  transition: border 0.3s, box-shadow 0.3s;
  font-family: 'Segoe UI', sans-serif;
}
.input-evaluacion:focus {
  border-color: #3498db;
  box-shadow: 0 0 5px rgba(52, 152, 219, 0.4);
  outline: none;
}
#estadoSelect {
  appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg fill='%233498db' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 20px;
}
.btn-cv-profesional {
    display: inline-block;
    background: linear-gradient(90deg, #1abc9c, #16a085);
    color: white;
    padding: 12px 28px;
    font-size: 16px;
    font-weight: bold;
    border-radius: 30px;
    text-decoration: none;
    box-shadow: 0 6px 14px rgba(22, 160, 133, 0.4);
    transition: all 0.3s ease;
    position: relative;
}
.btn-cv-profesional:hover {
    background: linear-gradient(90deg, #16a085, #1abc9c);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(22, 160, 133, 0.5);
}
.icono-pdf {
    font-size: 20px;
    margin-right: 10px;
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
    .kanban-container {
      display: flex;
      gap: 20px;
      justify-content: center;
      padding: 20px;
      flex-wrap: wrap;
    }
    .kanban-col {
      flex: 1;
      min-width: 250px;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 6px 14px rgba(0,0,0,0.08);
      padding: 15px;
    }
    .kanban-title {
      text-align: center;
      font-weight: bold;
      font-size: 18px;
      margin-bottom: 15px;
      color: #34495e;
    }
    .kanban-card {
      background: #3498db;
      color: white;
      padding: 10px 14px;
      border-radius: 8px;
      margin-bottom: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      transition: transform 0.2s;
    }
    .kanban-card:hover {
      transform: scale(1.03);
      background: #2980b9;
    }
    .kanban-card small {
      display: block;
      font-size: 13px;
      color: #dff9fb;
    }
.kanban-rechazo {
  background: #fcebea;
  border: 2px dashed #e74c3c;
}
.kanban-rechazo small{
  color: red;
}
.card-rechazo {
  background: #fff !important;
  color: black;
  border-left: 5px solid #c0392b;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.card-rechazo:hover {
  transform: scale(1.05);
  color: white;
  background: #c0392b !important;
  box-shadow: 0 0 15px rgba(231, 76, 60, 0.5);
}
.card-rechazo:hover small {
  color: white;
}
.btn-rechazados {
  background: #e74c3c;
  color: white;
  border: none;
  border-radius: 6px;
  padding: 6px 12px;
  cursor: pointer;
  font-size: 14px;
  font-weight: bold;
  transition: background 0.3s;
}
.btn-rechazados:hover {
  background: #c0392b;
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
  left: 0;
  width: 250px;
  height: calc(100% - 140px);
  background: #f7f9fb;
  color: #2d3436;
  padding: 30px 20px;
  box-shadow: 4px 0 12px rgba(0,0,0,0.06);
  box-sizing: border-box;
  z-index: 900;
  transition: transform 0.4s ease;
  border-right: 1px solid #e0e0e0;
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
/* Responsive en móviles */
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
    margin-left: 240px;
    padding: 30px;
}


/* =========================================================
   KANBAN RRHH · PALETA NORTE360
   Basado en el estilo visual usado en Consolidado de Checklist.
   Solo presentación: no modifica lógica, estados ni consultas.
   ========================================================= */
.rrhh-kanban-shell {
    --n360-navy: #12344b;
    --n360-navy-2: #1f5875;
    --n360-blue: #278fc4;
    --n360-blue-hover: #197cab;
    --n360-blue-soft: #edf7fc;
    --n360-bg: #eaf0f4;
    --n360-surface: #ffffff;
    --n360-border: #d7e1e8;
    --n360-text: #10283a;
    --n360-muted: #607485;
    --n360-success: #158457;
    --n360-danger: #c83b3b;
    width: 100%;
    margin: 0 auto;
}

/* ===== CABECERA TIPO NORTE360 ===== */
.rrhh-kanban-hero {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin: 8px 0 20px;
    padding: 28px 30px;
    min-height: 132px;
    background: linear-gradient(115deg, var(--n360-navy) 0%, var(--n360-navy-2) 100%);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 9px;
    box-shadow: 0 10px 25px rgba(14, 47, 68, .16);
}

.rrhh-kanban-hero::after {
    content: '';
    position: absolute;
    width: 340px;
    height: 340px;
    right: -150px;
    top: -175px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(76,177,224,.18) 0%, rgba(76,177,224,0) 70%);
    pointer-events: none;
}

.rrhh-kanban-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    color: #d8f1ff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .055em;
    text-transform: uppercase;
}

.rrhh-kanban-eyebrow i {
    color: #7ed3fb;
}

.rrhh-kanban-hero h1 {
    margin: 0;
    color: #ffffff;
    font-size: clamp(29px, 2.5vw, 40px);
    line-height: 1.1;
    letter-spacing: -.025em;
    text-align: left;
    font-weight: 800;
}

.rrhh-kanban-hero p {
    max-width: 820px;
    margin: 10px 0 0;
    color: #e7f0f5;
    font-size: 14px;
    line-height: 1.5;
}

.rrhh-kanban-hero-badge {
    position: relative;
    z-index: 1;
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 11px 15px;
    background: rgba(255,255,255,.08);
    border-radius: 8px;
    color: #ffffff;
    font-size: 12px;
    font-weight: 750;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
}

.rrhh-kanban-hero-badge i {
    color: #8bdcff;
    font-size: 16px;
}

/* ===== KPIs COMO LOS PANELES NORTE360 ===== */
.rrhh-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.rrhh-kpi {
    position: relative;
    min-height: 72px;
    padding: 15px 16px;
    background: var(--n360-surface);
    border: 1px solid var(--n360-border);
    border-radius: 9px;
    box-shadow: 0 4px 11px rgba(21, 56, 76, .055);
    display: flex;
    align-items: center;
    gap: 12px;
}

.rrhh-kpi::before {
    content: '';
    position: absolute;
    left: 0;
    top: 12px;
    bottom: 12px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: var(--kpi-color, var(--n360-blue));
}

.rrhh-kpi-icon {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    display: grid;
    place-items: center;
    border-radius: 7px;
    color: var(--kpi-color, var(--n360-blue));
    background: #f1f7fa;
    font-size: 16px;
}

.rrhh-kpi-copy {
    min-width: 0;
}

.rrhh-kpi-copy span {
    display: block;
    color: #566f7f;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .045em;
    text-transform: uppercase;
}

.rrhh-kpi-copy strong {
    display: block;
    margin-top: 3px;
    color: var(--n360-text);
    font-size: 24px;
    line-height: 1;
    font-weight: 800;
}

.rrhh-kpi--active,
.rrhh-kpi--process { --kpi-color: var(--n360-blue); }
.rrhh-kpi--working { --kpi-color: var(--n360-success); }
.rrhh-kpi--rejected { --kpi-color: var(--n360-danger); }

/* ===== CONTENEDOR DEL KANBAN ===== */
.kanban-board-wrap {
    position: relative;
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 2px 1px 14px;
    scrollbar-width: thin;
    scrollbar-color: #9fb4c2 transparent;
}

.kanban-board-wrap::-webkit-scrollbar {
    height: 8px;
}
.kanban-board-wrap::-webkit-scrollbar-track {
    background: transparent;
}
.kanban-board-wrap::-webkit-scrollbar-thumb {
    background: #a8bac6;
    border-radius: 999px;
}

.kanban-container.kanban-container--pro {
    display: grid;
    grid-template-columns: repeat(6, minmax(255px, 1fr));
    align-items: start;
    justify-content: initial;
    gap: 11px;
    min-width: 1590px;
    padding: 0;
    margin: 0;
    flex-wrap: nowrap;
}

/* Todas las etapas comparten la misma familia azul Norte360.
   Solo cambiamos ligeramente la intensidad para mantener lectura del flujo. */
.kanban-container--pro .kanban-col {
    --stage-color: #278fc4;
    --stage-soft: #eef7fb;
    position: relative;
    min-width: 0;
    padding: 0;
    overflow: hidden;
    background: #f7fafc;
    border: 1px solid #d6e1e8;
    border-radius: 9px;
    box-shadow: 0 4px 12px rgba(20, 57, 78, .065);
}

.kanban-container--pro .kanban-col::before {
    content: '';
    display: block;
    height: 4px;
    background: var(--stage-color);
}

.kanban-container--pro .stage-1 { --stage-color: #315b78; --stage-soft: #eef3f6; }
.kanban-container--pro .stage-2 { --stage-color: #197da9; --stage-soft: #ecf6fa; }
.kanban-container--pro .stage-3 { --stage-color: #278fc4; --stage-soft: #edf8fd; }
.kanban-container--pro .stage-4 { --stage-color: #217fa5; --stage-soft: #edf6f9; }
.kanban-container--pro .stage-5 { --stage-color: #176d91; --stage-soft: #ebf3f7; }
.kanban-container--pro .stage-6 { --stage-color: #155b78; --stage-soft: #eaf2f6; }

.kanban-column-header {
    display: grid;
    grid-template-columns: 35px minmax(0, 1fr) auto;
    align-items: center;
    gap: 9px;
    padding: 13px 12px 12px;
    background: #ffffff;
    border-bottom: 1px solid #dbe5eb;
}

.kanban-step-icon {
    width: 35px;
    height: 35px;
    display: grid;
    place-items: center;
    border-radius: 7px;
    color: var(--stage-color);
    background: var(--stage-soft);
    font-size: 15px;
}

.kanban-title-wrap {
    min-width: 0;
}

.kanban-container--pro .kanban-title {
    margin: 0;
    text-align: left;
    color: var(--n360-text);
    font-size: 13px;
    line-height: 1.22;
    font-weight: 800;
}

.kanban-subtitle {
    display: block;
    margin-top: 3px;
    overflow: hidden;
    color: #758998;
    font-size: 9.5px;
    line-height: 1.25;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.kanban-count {
    min-width: 28px;
    height: 28px;
    padding: 0 7px;
    display: inline-grid;
    place-items: center;
    border-radius: 999px;
    background: #f7fbfd;
    color: var(--stage-color);
    font-size: 11px;
    font-weight: 800;
}

.kanban-list {
    padding: 10px;
    min-height: 126px;
    background: #f5f9fb;
}

/* ===== TARJETAS DE POSTULANTES ===== */
.kanban-container--pro .kanban-card {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 9px;
    align-items: center;
    margin: 0 0 8px;
    padding: 10px;
    background: #ffffff;
    color: #1f2937;
    border: 1px solid #dce6ec;
    border-left: 3px solid var(--stage-color);
    border-radius: 7px;
    box-shadow: 0 2px 7px rgba(20, 57, 78, .055);
    transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}

.kanban-container--pro .kanban-card:hover {
    transform: translateY(-1px);
    background: #ffffff;
    color: #1f2937;
    border-color: #b9d2df;
    box-shadow: 0 5px 12px rgba(20, 57, 78, .11);
}

.kanban-person-icon {
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border-radius: 6px;
    color: var(--stage-color);
    background: var(--stage-soft);
    font-size: 14px;
}

.kanban-person-copy {
    min-width: 0;
}

.kanban-person-copy strong {
    display: block;
    overflow: hidden;
    color: #153246;
    font-size: 11.5px;
    font-weight: 750;
    line-height: 1.35;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.kanban-container--pro .kanban-card small {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 3px;
    overflow: hidden;
    color: #788a98;
    font-size: 9.5px;
    line-height: 1.3;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.kanban-container--pro .kanban-card small i {
    color: #8da1af;
}

.kanban-empty {
    display: grid;
    place-items: center;
    min-height: 98px;
    padding: 14px;
    color: #8799a6;
    text-align: center;
    border: 1px dashed #cbd9e1;
    border-radius: 7px;
    background: rgba(255,255,255,.66);
}

.kanban-empty i {
    display: block;
    margin-bottom: 6px;
    color: #9eafb9;
    font-size: 19px;
}

.kanban-empty span {
    font-size: 10px;
    font-weight: 650;
}

/* ===== RECHAZADOS: rojo solo como color semántico ===== */
.kanban-rejected-block {
    margin-top: 10px;
    padding-top: 9px;
    border-top: 1px solid #dfe7ec;
}

.kanban-container--pro .btn-rechazados {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 7px 8px;
    background: #fff5f5;
    color: #b93535;
    border: 1px solid #efcccc;
    border-radius: 6px;
    font-size: 9.5px;
    font-weight: 800;
    box-shadow: none;
    transition: background .16s ease, border-color .16s ease;
}

.kanban-container--pro .btn-rechazados:hover {
    background: #fdecec;
    border-color: #e6b6b6;
}

.rechazados-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.rechazados-count {
    min-width: 20px;
    height: 20px;
    display: inline-grid;
    place-items: center;
    padding: 0 5px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #e9c1c1;
    font-size: 9px;
}

.btn-rechazados .toggle-chevron {
    transition: transform .18s ease;
}
.btn-rechazados.is-open .toggle-chevron {
    transform: rotate(180deg);
}

.rejected-list {
    margin-top: 7px;
}

.kanban-container--pro .card-rechazo {
    --stage-color: #c83b3b;
    grid-template-columns: 31px minmax(0, 1fr);
    background: #fff !important;
    color: #1f2937 !important;
    border: 1px solid #efd5d5;
    border-left: 3px solid #c83b3b;
}

.kanban-container--pro .card-rechazo:hover {
    transform: translateY(-1px);
    background: #fff !important;
    color: #1f2937 !important;
    box-shadow: 0 5px 11px rgba(156, 36, 36, .10);
}

.kanban-container--pro .card-rechazo .kanban-person-icon {
    width: 29px;
    height: 29px;
    color: #b63838;
    background: #fff0f0;
}

.kanban-container--pro .card-rechazo small,
.kanban-container--pro .card-rechazo:hover small {
    color: #8a6a6a;
}

.kanban-board-hint {
    display: none;
    align-items: center;
    gap: 7px;
    margin: 0 0 9px;
    color: #617989;
    font-size: 10px;
    font-weight: 650;
}

/* Ajuste del fondo del área de trabajo para que acompañe la interfaz Norte360 */
.main-content.n360-main.n360-main--module {
    background: transparent;
}

@media (max-width: 1180px) {
    .rrhh-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .kanban-board-hint {
        display: flex;
    }
}

@media (max-width: 768px) {
    .rrhh-kanban-hero {
        align-items: flex-start;
        flex-direction: column;
        padding: 22px 20px;
        border-radius: 8px;
    }
    .rrhh-kanban-hero-badge {
        align-self: flex-start;
    }
    .rrhh-kpis {
        gap: 9px;
    }
    .rrhh-kpi {
        min-height: 67px;
        padding: 12px 11px;
    }
    .rrhh-kpi-icon {
        width: 34px;
        height: 34px;
        flex-basis: 34px;
    }
    .rrhh-kpi-copy strong {
        font-size: 21px;
    }
}

@media (max-width: 520px) {
    .rrhh-kpis {
        grid-template-columns: 1fr 1fr;
    }
    .rrhh-kpi {
        gap: 8px;
    }
    .rrhh-kpi-copy span {
        font-size: 8.5px;
    }
}
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
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
<?php n360_render_header(); ?>
<?php n360_render_sidebar(); ?>
<div class="main-content n360-main n360-main--module">
<?php n360_render_content_separator('top'); ?>
<div class="rrhh-kanban-shell">
  <div class="rrhh-kanban-hero">
    <div>
      <div class="rrhh-kanban-eyebrow">
        <i class="bi bi-diagram-3"></i>
        RRHH · Reclutamiento
      </div>
      <h1>Entrevistas por Etapa</h1>
    </div>
    <div class="rrhh-kanban-hero-badge">
      <i class="bi bi-columns-gap"></i>
      <span>6 etapas del proceso</span>
    </div>
  </div>

  <div class="rrhh-kpis">
    <div class="rrhh-kpi rrhh-kpi--active">
      <div class="rrhh-kpi-icon"><i class="bi bi-people"></i></div>
      <div class="rrhh-kpi-copy">
        <span>Postulantes activos</span>
        <strong><?= number_format($totalActivosKanban) ?></strong>
      </div>
    </div>

    <div class="rrhh-kpi rrhh-kpi--process">
      <div class="rrhh-kpi-icon"><i class="bi bi-hourglass-split"></i></div>
      <div class="rrhh-kpi-copy">
        <span>En proceso</span>
        <strong><?= number_format($totalProcesoKanban) ?></strong>
      </div>
    </div>

    <div class="rrhh-kpi rrhh-kpi--working">
      <div class="rrhh-kpi-icon"><i class="bi bi-person-workspace"></i></div>
      <div class="rrhh-kpi-copy">
        <span>En planilla</span>
        <strong><?= number_format($totalTrabajandoKanban) ?></strong>
      </div>
    </div>

    <div class="rrhh-kpi rrhh-kpi--rejected">
      <div class="rrhh-kpi-icon"><i class="bi bi-person-x"></i></div>
      <div class="rrhh-kpi-copy">
        <span>Rechazados</span>
        <strong><?= number_format($totalRechazadosKanban) ?></strong>
      </div>
    </div>
  </div>

  <div class="kanban-board-hint">
    <i class="bi bi-arrows"></i>
    Desliza horizontalmente para revisar todas las etapas.
  </div>

  <div class="kanban-board-wrap">
    <div class="kanban-container kanban-container--pro">
      <?php foreach ($etapasKanban as $idEtapa => $etapa): ?>
        <?php
          $postulantesEtapa = $kanban[$idEtapa] ?? [];
          $rechazadosEtapa = $rechazadosPorEtapa[$idEtapa] ?? [];
        ?>
        <div class="kanban-col stage-<?= (int)$idEtapa ?>">
          <div class="kanban-column-header">
            <div class="kanban-step-icon">
              <i class="bi <?= htmlspecialchars($etapa['icono']) ?>"></i>
            </div>
            <div class="kanban-title-wrap">
              <div class="kanban-title"><?= (int)$idEtapa ?>. <?= htmlspecialchars($etapa['nombre']) ?></div>
              <small class="kanban-subtitle"><?= htmlspecialchars($etapa['descripcion']) ?></small>
            </div>
            <span class="kanban-count" title="Postulantes activos en esta etapa">
              <?= count($postulantesEtapa) ?>
            </span>
          </div>

          <div class="kanban-list">
            <?php if (empty($postulantesEtapa)): ?>
              <div class="kanban-empty">
                <div>
                  <i class="bi bi-inbox"></i>
                  <span>Sin postulantes en esta etapa</span>
                </div>
              </div>
            <?php else: ?>
              <?php foreach ($postulantesEtapa as $e): ?>
                <div class="kanban-card" title="<?= htmlspecialchars($e['nombre']) ?>">
                  <div class="kanban-person-icon">
                    <i class="bi bi-person-fill"></i>
                  </div>
                  <div class="kanban-person-copy">
                    <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                    <small>
                      <i class="bi bi-briefcase"></i>
                      <?= htmlspecialchars($e['puesto'] ?: 'Puesto no especificado') ?>
                    </small>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($rechazadosEtapa)): ?>
              <div class="kanban-rejected-block">
                <button
                  type="button"
                  onclick="toggleRechazados(<?= (int)$idEtapa ?>, this)"
                  class="btn-rechazados"
                  aria-expanded="false"
                  aria-controls="rechazados-<?= (int)$idEtapa ?>">
                  <span class="rechazados-label">
                    <i class="bi bi-x-circle"></i>
                    Rechazados
                    <span class="rechazados-count"><?= count($rechazadosEtapa) ?></span>
                  </span>
                  <i class="bi bi-chevron-down toggle-chevron"></i>
                </button>

                <div id="rechazados-<?= (int)$idEtapa ?>" class="rejected-list" style="display:none;">
                  <?php foreach ($rechazadosEtapa as $r): ?>
                    <div class="kanban-card card-rechazo" title="<?= htmlspecialchars($r['nombre']) ?>">
                      <div class="kanban-person-icon">
                        <i class="bi bi-person-x-fill"></i>
                      </div>
                      <div class="kanban-person-copy">
                        <strong><?= htmlspecialchars($r['nombre']) ?></strong>
                        <small>
                          <i class="bi bi-briefcase"></i>
                          <?= htmlspecialchars($r['puesto'] ?: 'Puesto no especificado') ?>
                        </small>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
function abrirModal(boton) {
  const data = {
    yesorno: boton.getAttribute("data-yesorno"),
    estadoTexto: boton.getAttribute("data-estadoTexto"),
    id_entrevista: boton.getAttribute("data-id_entrevista"),
    nombre: boton.getAttribute("data-nombre"),
    fecha: boton.getAttribute("data-fecha"),
    hora: boton.getAttribute("data-hora"),
    dni: boton.getAttribute("data-dni"),
    sexo: boton.getAttribute("data-sexo"),
    edad: boton.getAttribute("data-edad"),
    puesto: boton.getAttribute("data-puesto"),
    estado: boton.getAttribute("data-estado"),
    observaciones: boton.getAttribute("data-observaciones"),
    comentario2: boton.getAttribute("data-comentario2"),
    comentario3: boton.getAttribute("data-comentario3"),
    comentarioMesPrueba: boton.getAttribute("data-comentarioMesPrueba"),
    comentario4: boton.getAttribute("data-comentario4"),
    comentarioRechazo: boton.getAttribute("data-comentarioRechazo"),
  };
  const contenido = `
  <h2>📄 Entrevista N°${data.id_entrevista}</h2>
  <h3>📅 ${data.fecha} ⏰ ${data.hora}</h3>
    <table>
      <tr><th>👤 Nombre</th><td>${data.nombre}</td></tr>
      <tr><th>DNI</th><td>${data.dni}</td></tr>
      <tr><th>Sexo</th><td>${data.sexo}</td></tr>
      <tr><th>Edad</th><td>${data.edad}</td></tr>
      <tr><th>📝 Observaciones</th><td>${data.observaciones}</td></tr>
    </table>
<div style="
  margin-top: 18px;
  padding: 12px 20px;
  background: #ecf0f1;
  color: #2c3e50;
  border-left: 6px solid #2980b9;
  border-radius: 8px;
  font-size: 16px;
  font-weight: bold;
  display: flex;
  align-items: center;
  gap: 10px;">
  <span style="font-size: 20px;">📊</span> Etapa actual: ${data.estadoTexto}
</div>
<div style="
  margin-top: 18px;
  padding: 12px 20px;
  background: #ecf0f1;
  color: #2c3e50;
  border-left: 6px solid #2980b9;
  border-radius: 8px;
  font-size: 16px;
  font-weight: bold;
  display: flex;
  align-items: center;
  gap: 10px;">
  <span style="font-size: 20px;">💼</span> Puesto: ${data.puesto}
</div>
<div style="margin-top: 25px; text-align: center;">
  <a href="../php/ver_cv.php?id=${data.id_entrevista}" target="_blank" class="btn-cv-profesional">
    <span class="icono-pdf">📎</span> Ver CV en PDF
  </a>
</div>
<div style="margin-top: 25px;">
  <h4 style="color:#2c3e50; font-size: 18px; margin-bottom: 12px;">📚 Historial de Comentarios</h4>
  <ul id="historialComentarios" style="
      list-style: none;
      padding: 0;
      margin: 0;
      font-size: 15px;
      color: #34495e;">
  </ul>
</div>
    `;
// Limpiar el select
const estadoSelect = document.getElementById("estadoSelect");
estadoSelect.innerHTML = "<option value=''>Selecciona una opción</option>";
// Estados posibles
const estados = {
  2: "Entrevista presencial",
  3: "Inducción",
  4: "Mes de prueba",
  5: "Solicitud Trabajador"
};
const estadoActual = parseInt(data.estado);
for (let clave in estados) {
  if (parseInt(clave) >= estadoActual +1 ) {
    const option = document.createElement("option");
    option.value = clave;
    option.textContent = estados[clave];
    estadoSelect.appendChild(option);
  }
}
// Reiniciar radios y vistas
document.querySelectorAll("input[name='decision']").forEach(r => r.checked = false);
document.getElementById("bloque_estado").style.display = "none";
document.getElementById("bloque_rechazo").style.display = "none";
document.getElementById("clm_yesorno").value = ""; // valor vacío hasta que se seleccione
  document.getElementById("contenidoModal").innerHTML = contenido;
  document.getElementById("id_entrevistaSeleccionado").value = data.id_entrevista; // ✅ ESTA LÍNEA ES CLAVE
  const historialComentarios = document.getElementById("historialComentarios");
historialComentarios.innerHTML = "";
historialComentarios.innerHTML = "";
if (data.estado >= 1) historialComentarios.innerHTML += `
<li style="background: #ecf0f1; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #3498db; border-radius: 8px;">
  <strong>🟦 Selección:</strong> ${data.observaciones || 'Sin comentario'}
</li>`;
if (data.estado >= 2) historialComentarios.innerHTML += `
<li style="background: #ecf0f1; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #2980b9; border-radius: 8px;">
  <strong>🔵 Entrevista presencial:</strong> ${data.comentario2 || 'Sin comentario'}
</li>`;
if (data.estado >= 3) historialComentarios.innerHTML += `
<li style="background: #ecf0f1; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #8e44ad; border-radius: 8px;">
  <strong>🟣 Inducción:</strong> ${data.comentario3 || 'Sin comentario'}
</li>`;
if (data.estado >= 4) historialComentarios.innerHTML += `
<li style="background: #ecf0f1; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #f39c12; border-radius: 8px;">
  <strong>🟠 Mes de prueba:</strong> ${data.comentarioMesPrueba || 'Sin comentario'}
</li>`;
if (data.estado >= 5) historialComentarios.innerHTML += `
<li style="background: #ecf0f1; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #27ae60; border-radius: 8px;">
  <strong>🟢 Solicitud Trabajador:</strong> ${data.comentario4 || 'Sin comentario'}
</li>`;
if (data.yesorno === "2") historialComentarios.innerHTML += `
<li style="background: #fdecea; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #e74c3c; border-radius: 8px;">
  <strong>❌ Rechazo:</strong> ${data.comentarioRechazo || 'Sin detalle registrado.'}
</li>`;
  document.getElementById("modalDetalle").style.display = "block";
  if (data.yesorno === "2") {
  document.getElementById("mensaje_rechazado").style.display = "block";
  document.getElementById("contenedor_interaccion").style.display = "none";
  document.getElementById("radio_opciones").style.display = "none"; // ⬅ OCULTAR RADIO
} else {
  document.getElementById("mensaje_rechazado").style.display = "none";
  document.getElementById("contenedor_interaccion").style.display = "block";
  document.getElementById("radio_opciones").style.display = "block"; // ⬅ MOSTRAR RADIO
}
}
function cerrarModal() {
  document.getElementById("modalDetalle").style.display = "none";
}
function guardarEstado(event) {
  event.preventDefault();
  const estado = document.getElementById("estadoSelect").value;
  const comentario = document.getElementById("comentario").value;
  const id_entrevista = document.getElementById("id_entrevistaSeleccionado").value;
  const clm_yesorno = document.getElementById("clm_yesorno").value;
  // Si es RECHAZADO (valor 2)
  if (clm_yesorno === "2") {
    if (!confirm("¿Estás seguro de que deseas rechazar esta entrevista?")) return;
    fetch("../php/rechazar_entrevista.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `id_entrevista=${id_entrevista}`
    })
    .then(response => response.text())
    .then(data => {
      alert("❌ Entrevista rechazada correctamente.");
      cerrarModal();
      location.reload();
    })
    .catch(error => {
      alert("⚠️ Error al rechazar.");
      console.error(error);
    });
    return; // IMPORTANTE: salimos del flujo de aceptado
  }
  // Si es ACEPTADO (valor 1)
  if (!estado || !id_entrevista) {
    alert("Por favor, selecciona un estado de evaluación.");
    return;
  }
  fetch("../php/actualizar_estado.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `estado=${estado}&comentario=${encodeURIComponent(comentario)}&id_entrevista=${id_entrevista}&clm_yesorno=${clm_yesorno}`
  })
  .then(response => response.text())
  .then(data => {
    console.log("📥 Respuesta del servidor:", JSON.stringify(data)); // <- esto sí o sí debe salir en consola
    if (data.includes("✅") || data.includes("⚠️")) {
      alert(data);
      cerrarModal();
      location.reload();
    } else {
      alert("❌ Error inesperado.");
      console.error("Respuesta no esperada:", data);
    }
  })
  .catch(error => {
    alert("❌ Error al actualizar.");
    console.error("ERROR:", error);
  });
}
</script>
<script>
function rechazarEntrevista() {
  const id_entrevista = document.getElementById("id_entrevistaSeleccionado").value;
  const comentario = document.getElementById("comentario_rechazo").value.trim();
  if (!id_entrevista) {
    alert("ID de entrevista no válido.");
    return;
  }
  if (comentario.length < 3) {
    alert("Debes ingresar un motivo de rechazo.");
    return;
  }
  if (!confirm("¿Estás seguro de rechazar esta entrevista?")) return;
  fetch("../php/rechazar_entrevista.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `id_entrevista=${id_entrevista}&comentario=${encodeURIComponent(comentario)}`
  })
  .then(response => response.text())
  .then(data => {
    console.log("📥 Rechazo -> Respuesta del servidor:", data);
    if (data.includes("OK")) {
      alert("❌ Entrevista rechazada correctamente.");
      cerrarModal();
      location.reload();
    } else {
      alert("⚠️ Error al rechazar: " + data);
    }
  })
  .catch(error => {
    alert("❌ Error de red.");
    console.error(error);
  });
}
</script>
<script>
function toggleEvaluacion(aceptado) {
  const bloque = document.getElementById("bloque_estado");
  const btnRechazo = document.getElementById("bloque_rechazo");
  const inputYesNo = document.getElementById("clm_yesorno");
  if (aceptado) {
    bloque.style.display = "block";
    btnRechazo.style.display = "none";
    inputYesNo.value = 1;
  } else {
    bloque.style.display = "none";
    btnRechazo.style.display = "block";
    inputYesNo.value = 2;
  }
}
</script>
<script>
function toggleRechazados(id, boton = null) {
  const cont = document.getElementById('rechazados-' + id);
  if (!cont) return;

  const estabaOculto = cont.style.display === 'none' || getComputedStyle(cont).display === 'none';
  cont.style.display = estabaOculto ? 'block' : 'none';

  if (boton) {
    boton.classList.toggle('is-open', estabaOculto);
    boton.setAttribute('aria-expanded', estabaOculto ? 'true' : 'false');
  }
}
</script>

        </div>
<?php n360_render_content_separator('bottom'); ?>
<?php n360_render_footer(); ?>
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
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
