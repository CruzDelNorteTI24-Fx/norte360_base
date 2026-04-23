<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);
if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 6; // id_modulo de esta vista

    if (!in_array($modulo_actual, $_SESSION['permisos'])) {
        header("Location: ../../login/none_permisos.php");
        exit();
    }
}

$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");
require_once(__DIR__ . "/../lib/planillas_contratos.php"); // ajusta la ruta si es necesario

// Función para calcular la edad actual
function calcularEdad($fechaNacimiento) {
    $hoy = new DateTime();
    $fechaNac = new DateTime($fechaNacimiento);
    $edad = $hoy->diff($fechaNac)->y;
    return $edad;
}



// Config paginación
$limit = 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$offset = ($page - 1) * $limit;

$param = "%$search%";

if ($search != '') {
    $sql = "SELECT SQL_CALC_FOUND_ROWS clm_tra_id, clm_tra_nombres, clm_tra_dni, clm_tra_sexo, clm_tra_fecha_nacimiento, clm_tra_tipo_trabajador, clm_tra_cargo, clm_tra_nlicenciaconducir, clm_tra_celular, clm_tra_identrevista, clm_tra_fehoregistro, clm_tra_iduseregister
            FROM tb_trabajador
            WHERE clm_tra_nombres LIKE ? OR clm_tra_dni LIKE ?
            LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $param, $param);
} else {
    $sql = "SELECT SQL_CALC_FOUND_ROWS clm_tra_id, clm_tra_nombres, clm_tra_dni, clm_tra_sexo, clm_tra_fecha_nacimiento, clm_tra_tipo_trabajador, clm_tra_cargo, clm_tra_nlicenciaconducir, clm_tra_celular, clm_tra_identrevista, clm_tra_fehoregistro, clm_tra_iduseregister
            FROM tb_trabajador
            LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$resultado = $stmt->get_result();

$trabajadores = [];
while($row = $resultado->fetch_assoc()) {
    $row['edad_actual'] = calcularEdad($row['clm_tra_fecha_nacimiento']);
    $trabajadores[] = $row;
}
$ids_trab = array_map(fn($r) => (int)$r['clm_tra_id'], $trabajadores);
$mapa_contratos = contratos_estado_bulk($conn, $ids_trab);


// Total rows
$totalRows = $conn->query("SELECT FOUND_ROWS()")->fetch_row()[0];
$totalPages = ceil($totalRows / $limit);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lista de Trabajadores | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../../img/norte360.png">      
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
.tabla-scroll-x {
    width: 100%;
    overflow-x: auto;
    /* Opcional: para una sombra elegante debajo de la barra */
    box-shadow: 0 2px 8px rgba(52, 73, 94, 0.05);
    margin-bottom: 16px;
}
        table {
            width: 100%;
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
    margin-left: 240px;
    padding: 30px;
}

    .grid-personal {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 20px;
      padding: 10px;
    }

    .card-personal {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      display: flex;
      flex-direction: column;
      align-items: center;
      transition: transform 0.2s;
      position: relative;
    }

    .card-personal:hover {
      transform: scale(1.02);
    }

    .card-personal h3 {
      color: #2c3e50;
      margin: 10px 0 6px 0;
      text-align: center;
      font-size: 18px;
    }

    .card-personal p {
      margin: 4px 0;
      font-size: 14px;
      color: #555;
      text-align: center;
    }

    .btn-ver {
      margin-top: 10px;
      background: #2980b9;
      color: white;
      padding: 8px 20px;
      border-radius: 20px;
      text-decoration: none;
      font-weight: bold;
      transition: background 0.3s;
      font-size: 14px;
    }

    .btn-ver:hover {
      background: #1c5980;
    }

    .icono-persona {
      font-size: 40px;
      background: #3498db;
      color: white;
      width: 70px;
      height: 70px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 10px;
    }

    @media (max-width: 768px) {
      .main-content {
        margin-left: 0;
        padding: 20px;
      }
    }
        .search-form { text-align: center; margin-bottom: 20px; }
    .search-input {
      padding: 10px; width: 60%; max-width: 400px;
      border-radius: 8px; border: 1px solid #ccc; font-size: 16px;
    }
    .search-btn {
      padding: 10px 20px; background: #3498db; color: white;
      border: none; border-radius: 8px; cursor: pointer; font-size: 16px;
    }
    .search-btn:hover { background: #2980b9; }

    .grid-personal {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr));
      gap: 20px; margin-top: 20px;
    }

    .card-personal {
      background: white; border-radius: 12px; padding: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08); text-align: center;
      transition: transform 0.2s;
    }
    .card-personal:hover { transform: scale(1.02); }
    .card-personal h3 { margin: 10px 0 5px; color: #2c3e50; }
    .card-personal p { margin: 4px 0; font-size: 14px; color: #555; }

    .btn-ver {
      display: inline-block; margin-top: 10px;
      background: #2980b9; color: white; padding: 8px 20px;
      border-radius: 20px; text-decoration: none; font-weight: bold;
      font-size: 14px;
    }
    .btn-ver:hover { background: #1c5980; }

    .pagination {
      text-align: center; margin: 30px 0;
    }
    .pagination a, .pagination strong {
      margin: 0 5px; text-decoration: none; padding: 8px 12px;
      border-radius: 6px; background: #3498db; color: white;
      font-weight: bold; transition: background 0.3s;
    }
    .pagination a:hover { background: #21618c; }
    .pagination strong { background: #2980b9; }
        .search-form { text-align: center; margin-bottom: 20px; }
        .search-input {
            padding: 10px; width: 60%; max-width: 400px;
            border-radius: 8px; border: 1px solid #ccc; font-size: 16px;
        }
table {
    width: 100%;
    min-width: 1200px;
    border-collapse: collapse;
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    /* max-width: 1000px; <-- QUITA esto */
    margin: 0 auto;
}

        th, td { padding: 14px; border-bottom: 1px solid #ddd; text-align: left; font-size: 13px;}
        th { background: #2c3e50; text-align: center; color: white; }
        tr:hover { background: #f1f1f1; }
        .pagination {
            text-align: center; margin: 30px 0;
        }
        .pagination a, .pagination strong {
            margin: 0 5px; text-decoration: none;
            padding: 8px 12px; border-radius: 6px;
            background: #3498db; color: white;
            font-weight: bold; transition: background 0.3s;
        }
        .pagination a:hover { background: #21618c; }
        .pagination strong { background: #2980b9; }
    </style>
</head>

<body>

<?php if ($exito): ?>
    
<div id="popup-exito">
  <div class="mensaje">
    <svg class="check-icon" viewBox="0 0 52 52">
      <circle class="check-circle" cx="26" cy="26" r="25" fill="none" />
      <path class="check-mark" fill="none" d="M14 27 l8 8 l16 -16" />
    </svg>
    <p class="texto-popup">¡Trabajador registrado correctamente!</p>
  </div>
</div>

<?php endif; ?>

<header class="main-header animated-border">
  <div class="header-content">
    <a href="../../index.php"">
        <div class="logo-bloque">
            <img src="../../img/norte360.png" alt="Logo Empresa" class="logo-header">
        </div>
    </a>

    <div class="separador-vertical"></div>
        <a href="javascript:location.reload()">
            <div class="logo-bloque">
            <img src="../../img/completo.png" alt="Logo Sistema" class="logo-header2">
            </div>
        </a>


    <div class="usuario-contenedor" style="margin-left:auto; position: relative;">
      <div class="usuario-barra" onclick="toggleDropdown()">
        <span>Hola, <?= htmlspecialchars($_SESSION['usuario']) ?></span>
        <img src="../../img/icons/user.png" alt="Usuario">
      </div>
      <div class="usuario-dropdown" id="usuarioDropdown">
        <p><strong>Nombre:</strong> <?= htmlspecialchars($_SESSION['usuario']) ?></p>
        <p><strong>DNI:</strong> <?= htmlspecialchars($_SESSION['DNI']) ?></p>
        <p><strong>Edad:</strong> <?= $edad ?> años</p>
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%); margin: 12px 0; border: none; border-top: 1px solid #eee;">
        <p><strong>Rol:</strong> <?= htmlspecialchars($_SESSION['web_rol']) ?></p>
        <a href="../../login/logout.php" class="btn-logout-dropdown">Cerrar sesión</a>
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
  <a href="../../01_contratos/nregrcdn_h.php">➕ Nuevo Trabajador</a>
  <a href="../../01_entrevistas/reentrev.php">➕ Nueva Entrevista</a>
  <a href="../../01_contratos/documentacion/agregadocu.php">➕ Nueva Documentación</a>
  <a href="../../01_contratos/nlaskdrcdn_h.php">👤 Personal</a>
  <a href="../../01_entrevistas/bvisentrevisaf.php">📝 Entrevistas</a>
  <a href="../../01_contratos/dorrhcdn.php">📁 Documentación</a>
</div>

<div id="modulo-inventario" class="subnav" style="display: none;">
  <a href="../../01_almacen/scanner.php"> 🏷️ Código de Barra</a>
  <a href="../../01_almacen/gen_np9823.php">📋 Catálogo Productos</a>
</div>
<div id="modulo-mantenimiento" class="subnav" style="display: none;">
  <a href="../../01_amantenimiento\lista_cheklist.php">📝 CheckList</a>
</div>
<button class="menu-toggle" onclick="toggleMenu()">☰</button>


<!-- SIDEBAR FIJO EN DESKTOP -->
<nav class="menu-lateral" id="menuLateral">
  <button class="sidebar-toggle-btn" id="btnHideSidebar" aria-label="Ocultar menú">
    <i class="bi bi-chevron-left"></i>
  </button>

  <div class="menu-logo">
    <img src="../../img/norte360_black.png" alt="Logo" style="height:40px;">
    <span class="fw-bold ms-2" style="color:#2c3e50;">Norte 360°</span>
  </div>
  <ul class="menu-list">
    <h3>Personal</h3>
    <li><a href="../nregrcdn_h.php"><i class="bi bi-person-plus-fill"></i> Nuevo Trabajador</a></li>
    <li><a href="../nlaskdrcdn_h.php"><i class="bi bi-search"></i> Buscar Trabajador</a></li>
    <li><a href="ver_personal.php"><i class="bi bi-people-fill"></i> Trabajadores</a></li>
    <li><a href="ver_licencias.php"><i class="bi bi-award-fill"></i> Licencias</a></li>
    <li><a href="ver_cumpleanos.php"><i class="bi bi-calendar2-event-fill"></i> Cumpleaños</a></li>
    <li><a href="ver_cargos.php"><i class="bi bi-briefcase-fill"></i> Cargos</a></li>
    <li><a href="ver_emergencia.php"><i class="bi bi-telephone-inbound-fill"></i> Emergencia</a></li>
    <li><a href="ver_listatrab.php">Tabla de Trabajadores</a></li>
    <li><a href="ver_listanomina.php">Tabla de Nómina</a></li>    
    <li><a href="../planillas/ver_planillas.php"><i class="bi bi-table"></i> Planillas (Historial)</a></li>

    <h3>Entrevistas</h3>
    <li><a href="../tbvistacontratadosent.php"><i class="bi bi-file-earmark-person-fill"></i> Solicitud para Trabajador</a></li>
    <h3>Documentación</h3>
    <li><a href="../documentacion/generdocuplant.php"><i class="bi bi-journal-plus"></i> Añadir Doc.</a></li>
    <li><a href="../documentacion/ver.php"><i class="bi bi-folder2-open"></i> Ver Documentos</a></li>
    <li><a href="../documentacion/tipo_docu.php"><i class="bi bi-archive-fill"></i> Tipos Documentos</a></li>
    <!-- Más módulos aquí -->
  </ul>
</nav>
<button class="sidebar-show-btn" id="sidebarShowBtn" aria-label="Mostrar menú">
  <i class="bi bi-chevron-right"></i>
</button>


<div class="main-content">

        <hr>

<h2>Lista de Personal</h2>

<div class="page-wrap">
  <div class="card p-3 mb-3">
    <div class="d-flex align-items-center gap-2">
      <img src="../../img/norte360_black.png" style="height:40px">
      <h3 class="m-0">Historial de Planillas</h3>
      <div class="ms-auto">
        <a id="btnExport" class="btn btn-success btn-sm"><i class="bi bi-filetype-csv"></i> Exportar CSV</a>
      </div>
    </div>
    <hr>

    <!-- Filtros -->
    <div class="filters row g-2">
      <div class="col-12 col-lg-3">
        <label class="form-label mb-1">Buscar (Nombre o DNI)</label>
        <input id="f_search" type="text" class="form-control" placeholder="Ej: Juan / 12345678">
      </div>
      <div class="col-12 col-md-3 col-lg-3">
        <label class="form-label mb-1">Trabajador</label>
        <select id="f_trab" class="form-select">
          <option value="">Todos</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1">Estado</label>
        <select id="f_estado" class="form-select">
          <option value="">Todos</option>
          <option>ACTIVO</option>
          <option>VIGENTE</option>
          <option>EN PLANILLA</option>
          <option>INACTIVO</option>
          <option>CESADO</option>
          <option>BAJA</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1">Fecha por</label>
        <select id="f_ftipo" class="form-select">
          <option value="fechregistro">Registro</option>
          <option value="fechaingrespl">Ingreso Planilla</option>
          <option value="fechasalida">Salida</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-1">
        <label class="form-label mb-1">Desde</label>
        <input id="f_desde" type="date" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-lg-1">
        <label class="form-label mb-1">Hasta</label>
        <input id="f_hasta" type="date" class="form-control">
      </div>
      <div class="col-12 d-flex gap-2 mt-1">
        <button id="btnFiltrar" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
        <button id="btnLimpiar" class="btn btn-outline-secondary">Limpiar</button>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card p-0">
    <div class="table-responsive">
      <table class="table table-hover m-0">
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th>Trabajador</th>
            <th style="width:120px;">DNI</th>
            <th>Cargo</th>
            <th style="width:120px;">Estado</th>
            <th style="width:140px;">F. Registro</th>
            <th style="width:160px;">F. Ingreso Planilla</th>
            <th style="width:140px;">F. Salida</th>
            <th>Doc</th>
            <th>Comentario</th>
            <th style="width:90px;">Acciones</th>
          </tr>
        </thead>
        <tbody id="tbodyPlanillas">
          <tr><td colspan="11" class="text-center py-4">Cargando...</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div class="p-2 d-flex align-items-center justify-content-between">
      <div><small id="lblResumen" class="text-muted"></small></div>
      <div class="d-flex gap-1" id="paginador"></div>
    </div>
  </div>
</div>

<!-- Modal Detalle -->
<div class="modal fade" id="mdlDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detalle de Planilla</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0" id="dlDetalle"></dl>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>


    <!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
    <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
        <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
    </a>
    <hr>    
</div>



<footer class="main-footer animated-border">
  <div class="footer-top">
    <img src="../../img/norte360.png" alt="Logo Empresa" class="logo-header3">
    <div class="footer-info">
      <p class="footer-title">Contáctanos</p>
      <div class="footer-cajas">
        <div class="footer-box"><img src="../../img/icons/facebook.png" alt="Función 1"></div>
        <div class="footer-box"><img src="../../img/icons/social.png" alt="Función 2"></div>
      </div>
    </div>
  </div>
  <p class="footer-copy">© <?= date('Y') ?> Norte 360° (v1.0.6). Todos los derechos reservados.</p>
</footer>
<script>
document.getElementById('descargarPDF').addEventListener('click', function () {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({
        orientation: 'landscape',
        unit: 'pt',
        format: 'A4'
    });

    // Imágenes (asegúrate de la ruta)
    const logoDer = "../../img/norte360_black.png";     // Cambia si quieres otra
    const logoIzq = "../../img/IMG_3004.png";     // Cambia si quieres otra

    // Carga las dos imágenes (usa promesas para cargarlas antes de generar el PDF)
    Promise.all([
        getImageBase64(logoIzq),
        getImageBase64(logoDer)
    ]).then(function ([imgIzq, imgDer]) {
        // Izquierda
        doc.addImage(imgIzq, 'PNG', 40, 28, 90, 40); // (x, y, width, height)
        // Derecha
        doc.addImage(imgDer, 'PNG', doc.internal.pageSize.getWidth() - 130, 28, 90, 40);

        // Título
        doc.setFontSize(16);
        doc.setTextColor(44, 62, 80);
        doc.setFont('helvetica', 'bold');
        doc.text("Lista de Personal - Norte 360°", doc.internal.pageSize.getWidth() / 2, 55, {align: 'center'});
        doc.setLineWidth(1);
        doc.setDrawColor(44,62,80);
        doc.line(40, 78, doc.internal.pageSize.getWidth() - 40, 78);

        // Encabezado debajo de la línea
        doc.setFontSize(10);
        doc.setFont('helvetica', 'normal');
        doc.text("Generado: " + new Date().toLocaleString(), 40, 92);

        // Selecciona solo las columnas a exportar (sin 'Detalles')
        const table = document.querySelector('.tabla-scroll-x table');
        const headers = Array.from(table.querySelectorAll('thead th'))
                            .slice(0, -1) // omite el último (Detalles)
                            .map(th => th.textContent);
        const body = Array.from(table.querySelectorAll('tbody tr')).map(tr => {
            return Array.from(tr.querySelectorAll('td')).slice(0, -1).map(td => td.textContent.trim());
        });

        doc.autoTable({
            head: [headers],
            body: body,
            startY: 105,
            margin: { left: 40, right: 40 },
            theme: 'grid',
            headStyles: { fillColor: [44,62,80], textColor: [255,255,255], fontStyle: 'bold' },
            styles: { font: 'helvetica', fontSize: 8, cellPadding: 4 },
            didDrawPage: function (data) {
                let str = "Página " + doc.internal.getNumberOfPages();
                doc.setFontSize(8);
                doc.text(str, doc.internal.pageSize.getWidth() - 80, doc.internal.pageSize.getHeight() - 20);
                doc.text("Generado: " + new Date().toLocaleString(), 40, doc.internal.pageSize.getHeight() - 20);
            }
        });

        doc.save("lista-personal-norte360.pdf");
    });

    // Función para cargar la imagen y devolverla en base64
    function getImageBase64(url) {
        return new Promise(function (resolve) {
            var img = new window.Image();
            img.setAttribute('crossOrigin', 'anonymous');
            img.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = img.width;
                canvas.height = img.height;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                resolve(canvas.toDataURL('image/png'));
            };
            img.src = url;
        });
    }
});


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
document.addEventListener('DOMContentLoaded', function() {
    const buscador = document.getElementById('buscador');
    const filas = document.querySelectorAll('table tbody tr');

    buscador.addEventListener('input', function() {
        const filtro = this.value.toLowerCase();

        filas.forEach(fila => {
            const celdas = fila.querySelectorAll('td');
            let textoFila = '';
            celdas.forEach(celda => textoFila += celda.textContent.toLowerCase() + ' ');

            if (textoFila.includes(filtro)) {
                fila.style.display = "";
            } else {
                fila.style.display = "none";
            }
        });
    });
});
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const API = 'planillas_api.php';
    let state = { page:1, limit:50, sort:'t.clm_pl_fechregistro', dir:'DESC' };

    function qs(id){ return document.getElementById(id); }
    function esc(s){ return (s??'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

    function badgeEstado(est) {
    const e = (est||'').toUpperCase();
    if (['ACTIVO','VIGENTE','EN PLANILLA'].includes(e)) return `<span class="badge-estado estado-activo">`+esc(e)+`</span>`;
    if (['CESADO','BAJA'].includes(e)) return `<span class="badge-estado estado-cesado">`+esc(e)+`</span>`;
    return `<span class="badge-estado estado-otro">`+esc(e||'—')+`</span>`;
    }

    async function cargarTrabajadores() {
    const res = await fetch(`${API}?action=trabajadores`);
    const j = await res.json();
    if (j.ok) {
        const sel = qs('f_trab');
        j.options.forEach(o=>{
        const opt = document.createElement('option');
        opt.value = o.clm_tra_id;
        opt.textContent = `${o.clm_tra_nombres} (${o.clm_tra_dni})`;
        sel.appendChild(opt);
        });
    }
    }

    function buildParams(extra={}) {
    const p = new URLSearchParams({
        action:'list',
        page: state.page, limit: state.limit, sort: state.sort, dir: state.dir,
        search: qs('f_search').value.trim(),
        trab_id: qs('f_trab').value,
        estado: qs('f_estado').value,
        fecha_tipo: qs('f_ftipo').value,
        desde: qs('f_desde').value,
        hasta: qs('f_hasta').value
    });
    Object.entries(extra).forEach(([k,v])=> p.set(k,v));
    return p.toString();
    }

    async function cargarTabla() {
    const res = await fetch(`${API}?${buildParams()}`);
    const j = await res.json();
    const tb = qs('tbodyPlanillas');
    tb.innerHTML = '';
    if (!j.ok) {
        tb.innerHTML = `<tr><td colspan="11" class="text-danger text-center py-4">${esc(j.error||'Error')}</td></tr>`;
        return;
    }
    if (!j.rows.length) {
        tb.innerHTML = `<tr><td colspan="11" class="text-center py-4">Sin resultados</td></tr>`;
    } else {
        j.rows.forEach(r=>{
        const linkDoc = r.clm_pl_doc ? `<a href="${esc(r.clm_pl_doc)}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i></a>` : '<span class="text-muted">—</span>';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${esc(r.clm_pl_id)}</td>
            <td>${esc(r.clm_tra_nombres)}</td>
            <td>${esc(r.clm_tra_dni)}</td>
            <td>${esc(r.clm_tra_cargo||'')}</td>
            <td>${badgeEstado(r.clm_pl_tra_estado)}</td>
            <td>${esc(r.clm_pl_fechregistro||'')}</td>
            <td>${esc(r.clm_pl_fechaingrespl||'')}</td>
            <td>${esc(r.clm_pl_fechasalida||'')}</td>
            <td>${linkDoc}</td>
            <td><span class="truncate" title="${esc(r.clm_pl_com||'')}">${esc(r.clm_pl_com||'')}</span></td>
            <td>
            <button class="btn btn-sm btn-primary" onclick="verDetalle(${r.clm_pl_id})"><i class="bi bi-eye"></i></button>
            </td>
        `;
        tb.appendChild(tr);
        });
    }
    // Resumen y paginación
    const desde = (j.rows.length ? ((state.page-1)*state.limit+1) : 0);
    const hasta = (j.rows.length ? (desde + j.rows.length - 1) : 0);
    qs('lblResumen').textContent = `Mostrando ${desde}–${hasta} de ${j.total}`;

    const pag = qs('paginador'); pag.innerHTML = '';
    const totalPages = Math.max(1, Math.ceil(j.total/state.limit));
    function addBtn(label, page, active=false, disabled=false) {
        const btn = document.createElement('button');
        btn.className = `btn btn-sm ${active?'btn-primary':'btn-outline-primary'}`;
        btn.textContent = label;
        btn.disabled = disabled;
        btn.onclick = ()=>{ state.page = page; cargarTabla(); };
        pag.appendChild(btn);
    }
    addBtn('«', 1, false, state.page===1);
    addBtn('‹', Math.max(1,state.page-1), false, state.page===1);
    const windowSize = 5;
    const start = Math.max(1, state.page - Math.floor(windowSize/2));
    const end   = Math.min(totalPages, start + windowSize - 1);
    for (let p=start; p<=end; p++) addBtn(String(p), p, p===state.page, false);
    addBtn('›', Math.min(totalPages,state.page+1), false, state.page===totalPages);
    addBtn('»', totalPages, false, state.page===totalPages);
    }

    async function verDetalle(id) {
    const res = await fetch(`${API}?action=detalle&id=${id}`);
    const j = await res.json();
    const dl = qs('dlDetalle');
    dl.innerHTML = '';
    if (!j.ok || !j.row) {
        dl.innerHTML = `<div class="text-danger">No se encontró el registro.</div>`;
    } else {
        const r = j.row;
        const rows = [
        ['ID', r.clm_pl_id],
        ['Trabajador', r.clm_tra_nombres],
        ['DNI', r.clm_tra_dni],
        ['Cargo', r.clm_tra_cargo||''],
        ['Estado', r.clm_pl_tra_estado||''],
        ['Fecha Registro', r.clm_pl_fechregistro||''],
        ['Ingreso Planilla', r.clm_pl_fechaingrespl||''],
        ['Salida', r.clm_pl_fechasalida||''],
        ['Documento', r.clm_pl_doc ? `<a href="${esc(r.clm_pl_doc)}" target="_blank">Abrir</a>` : '—'],
        ['Comentario', esc(r.clm_pl_com||'')]
        ];
        rows.forEach(([k,v])=>{
        dl.insertAdjacentHTML('beforeend', `
            <dt class="col-sm-4">${k}</dt>
            <dd class="col-sm-8">${v}</dd>
        `);
        });
        new bootstrap.Modal(document.getElementById('mdlDetalle')).show();
    }
    }

    qs('btnFiltrar').addEventListener('click', ()=>{
    state.page = 1; cargarTabla();
    });
    qs('btnLimpiar').addEventListener('click', ()=>{
    ['f_search','f_trab','f_estado','f_ftipo','f_desde','f_hasta'].forEach(id=>{
        const el = qs(id);
        if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
    });
    state.page=1; cargarTabla();
    });
    qs('btnExport').addEventListener('click', ()=>{
    const url = `${API}?${buildParams({action:'export_csv'})}`;
    window.open(url, '_blank');
    });

    // Orden por click en cabecera (simple)
    document.querySelectorAll('th').forEach(th=>{
    th.style.cursor = 'pointer';
    th.addEventListener('click', ()=>{
        const map = {
        'ID':'t.clm_pl_id',
        'Trabajador':'tr.clm_tra_nombres',
        'DNI':'tr.clm_tra_dni',
        'Cargo':'tr.clm_tra_cargo',
        'Estado':'t.clm_pl_tra_estado',
        'F. Registro':'t.clm_pl_fechregistro',
        'F. Ingreso Planilla':'t.clm_pl_fechaingrespl',
        'F. Salida':'t.clm_pl_fechasalida'
        };
        const key = th.innerText.trim();
        if (!map[key]) return;
        if (state.sort === map[key]) { state.dir = (state.dir==='ASC'?'DESC':'ASC'); }
        else { state.sort = map[key]; state.dir = 'ASC'; }
        state.page = 1;
        cargarTabla();
    });
    });

    // Init
    (async function init(){
    await cargarTrabajadores();
    await cargarTabla();
    })();
</script>



</body>


</html>

