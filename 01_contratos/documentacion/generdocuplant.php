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

// Obtener trabajadores
$trabajadores = [];
$result = $conn->query("SELECT clm_tra_id, clm_tra_nombres, clm_tra_dni, clm_tra_sexo, clm_tra_fecha_nacimiento, clm_tra_domicilio, clm_tra_sexo, clm_tra_cargo  FROM tb_trabajador ORDER BY clm_tra_nombres");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $trabajadores[] = $row;
    }
    $result->free();
}

// Obtener tipos de documento
$tipos_documento = [];
$result2 = $conn->query("SELECT id_tipo_documento, nombre_tipo, clm_namedocplant FROM tb_tipo_documento ORDER BY nombre_tipo");
if ($result2) {
    while ($row = $result2->fetch_assoc()) {
        $tipos_documento[] = $row;
    }
    $result2->free();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Agregar Documentación | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../../img/norte360.png">      
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
    transition: margin-left .3s ease; 
}

.campo-automatico input {
  background: #f8f9fb;
  border: 1px solid #d0d6e2;
  color: #2c3e50;
  font-weight: bold;
  opacity: 0.85;
}

.campo-automatico input:focus {
  border-color: #5fa8dc;
  box-shadow: 0 0 6px rgba(95, 168, 220, 0.4);
  background: #f1f4f8;
}

.campo-automatico label::after {
  content: " (automático)";
  color: #999;
  font-weight: normal;
  font-style: italic;
  font-size: 13px;
}
.seccion-formulario {
  margin-bottom: 30px;
  padding: 20px;
  border: 1px solid #e0e4e8;
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.seccion-formulario h3 {
  margin-top: 0;
  margin-bottom: 20px;
  color: #2c3e50;
  font-size: 18px;
  border-left: 4px solid #3498db;
  padding-left: 12px;
}

.grid-campos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
}
.input-iluminado {
  animation: glowFlash 1.3s ease-out;
}

@keyframes glowFlash {
  0% {
    box-shadow: 0 0 0px rgba(52, 152, 219, 0);
    background-color: #f0f9ff;
  }
  50% {
    box-shadow: 0 0 10px rgba(52, 152, 219, 0.4);
    background-color: #eaf6ff;
  }
  100% {
    box-shadow: 0 0 0px rgba(52, 152, 219, 0);
    background-color: #fff;
  }
}
.grid-trabajador {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  grid-auto-rows: auto;
  gap: 20px;
  align-items: end;
}

.btn-validar {
  margin-top: 30px;
  font-weight: bold;
  letter-spacing: 0.5px;
  box-shadow: 0 6px 14px rgba(52, 152, 219, 0.3);
}
.campo-form input[type="file"] {
  padding: 10px;
  background: #f7f9fc;
  border: 1px solid #ccc;
  border-radius: 10px;
  font-size: 14px;
  cursor: pointer;
  transition: border 0.3s, box-shadow 0.3s;
}

.campo-form input[type="file"]:hover {
  border-color: #3498db;
  box-shadow: 0 0 6px rgba(52, 152, 219, 0.3);
}
.grid-documento {
  display: grid;
  grid-template-columns: repeat(2, 1fr); /* Fuerza solo 2 columnas */
  gap: 20px;
}


/* ====== Secciones como cards elegantes ====== */
.seccion-formulario {
  margin-bottom: 22px;
  padding: 0;                /* limpiamos padding: lo delega card-body */
  border: 1px solid #e9edf3; /* borde sutil */
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 .35rem 1rem rgba(0,0,0,.04);
  overflow: hidden;
}

/* Header de sección */
.seccion-formulario > .section-header {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .9rem 1.1rem;
  background: #fff;
  border-bottom: 1px dashed #e9edf3;
}
.section-header .chip {
  font-size: .8rem;
  font-weight: 700;
  color: #0d6efd;
  background: #e9f2ff;
  border-radius: 999px;
  padding: .3rem .6rem;
}
.section-header h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 800;
  color: #2c3e50;
  letter-spacing: .2px;
}

/* Cuerpo de sección */
.seccion-formulario > .section-body {
  padding: 1rem 1.1rem;
}

/* Inputs: look Bootstrap con bordes suaves */
.input-group-text { background: #f8fafc; border-color: #dfe6ee; }
.form-label { font-weight: 600; color: #2c3e50; margin-bottom: .35rem; }
.form-text { font-size: .82rem; color: #6c757d; margin-top: .2rem; }
.form-control, .input-evaluacion { border-radius: .55rem; }

/* Lista de sugerencias del buscador */
#lista_trabajadores{
  border: 1px solid #e9edf3 !important;
  box-shadow: 0 .5rem 1rem rgba(0,0,0,.05);
}
#lista_trabajadores > div:hover{ background:#f8f9fb; }

/* Compactamos filas del formulario */
.row.g-3 { --bs-gutter-y: .9rem; --bs-gutter-x: 1rem; }

input[type=text]{
  margin-bottom: 0px;
}

@media (max-width: 991px) {
  .main-content { margin-left: 0 !important; }
  aside { display: none !important; }
}
@media (max-width: 600px) {
  .row.g-3 > [class^="col-"] {
    flex: 0 0 100%;
    max-width: 100%;
  }
}
@media (max-width: 650px) {
  .action-btn span {
    display: none;
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

<button class="menu-toggle" id="btnMenuToggle" onclick="toggleMenu()" aria-label="Menú"><span></span><span></span><span></span></button>

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
    <li><a href="../trabajadores/ver_personal.php"><i class="bi bi-people-fill"></i> Trabajadores</a></li>
    <li><a href="../trabajadores/ver_licencias.php"><i class="bi bi-award-fill"></i> Licencias</a></li>
    <li><a href="../trabajadores/ver_cumpleanos.php"><i class="bi bi-calendar2-event-fill"></i> Cumpleaños</a></li>
    <li><a href="../trabajadores/ver_cargos.php"><i class="bi bi-briefcase-fill"></i> Cargos</a></li>
    <li><a href="../trabajadores/ver_emergencia.php"><i class="bi bi-telephone-inbound-fill"></i> Emergencia</a></li>
    <li><a href="../trabajadores/ver_listatrab.php">Tabla de Trabajadores</a></li>
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
<div class="container mt-4 mb-5">
    <h3 class="mb-4 text-black fw-bold">Agregar Documentación</h3>

    <form action="php_generarword.php" method="POST" enctype="multipart/form-data" class="formulario-entrevista">
      <div class="seccion-formulario">
        <div class="section-header">
          <span class="chip"><i class="bi bi-file-earmark-text me-1"></i> Documento</span>
          <h3>Datos del Documento</h3>
        </div>

        <div class="section-body">
          <div class="grid-campos grid-documento">
            <div class="campo-form">
              <label for="idtipo_documento" class="form-label">Tipo de Documento</label>

              <select name="idtipo_documento" id="idtipo_documento" required class="form-control input-evaluacion">
                <option value="">Seleccione...</option>
                <?php foreach ($tipos_documento as $tipo): ?>
                  <option value="<?= $tipo['id_tipo_documento'] ?>" data-plantilla="<?= htmlspecialchars($tipo['clm_namedocplant'] ?? '') ?>">
                    <?= htmlspecialchars($tipo['nombre_tipo']) ?>
                  </option>
                <?php endforeach; ?>
              </select>

            </div>

            <div class="campo-form campo-automatico">
              <label for="nombre_plantilla" class="form-label">Plantilla seleccionada</label>
              <input type="text" id="nombre_plantilla" class="form-control input-evaluacion" readonly placeholder="—">
            </div>

            <div class="campo-form" style="grid-column: span 2;">
              <label for="observaciones" class="form-label">Observaciones</label>
              <textarea name="observaciones" rows="3" class="form-control input-evaluacion" placeholder="Opcional..."></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="seccion-formulario">
        <div class="section-header">
          <span class="chip"><i class="bi bi-people me-1"></i> Personal</span>
          <h3>Datos del Trabajador</h3>
        </div>

        <div class="section-body">
          <div class="grid-campos grid-trabajador">

            <div class="campo-form campo-automatico">
              <label for="idtrabajador_visible" class="form-label">ID</label>
              <input type="text" id="idtrabajador_visible" class="form-control input-evaluacion" readonly>
            </div>

            <div class="campo-form">
              <label for="buscar_trabajador" class="form-label">Buscar (nombre o ID)</label>
              <input type="text" name="nombre_trabajador" id="buscar_trabajador" placeholder="Escriba el nombre o ID..." class="form-control input-evaluacion" autocomplete="off">
              <div id="lista_trabajadores" class="mt-2 rounded-3" style="background: white; border-radius: 10px; display: none; max-height: 240px; overflow-y: auto;"></div>
              <input type="hidden" name="idtrabajador" id="idtrabajador_seleccionado">
            </div>

            <div class="campo-form campo-automatico">
              <label for="dni_trabajador" class="form-label">DNI</label>
              <input type="text" name="dni_trabajador" id="dni_trabajador" class="form-control input-evaluacion" readonly>
            </div>

            <div class="campo-form campo-automatico">
              <label for="fecha_trabajador" class="form-label">Nacimiento</label>
              <input type="text" id="fecha_trabajador" class="form-control input-evaluacion" readonly>
            </div>

            <div class="campo-form campo-automatico">
              <label for="edad_trabajador" class="form-label">Edad</label>
              <input type="text" id="edad_trabajador" class="form-control input-evaluacion" readonly>
            </div>

            <div class="campo-form campo-automatico">
              <label for="domicilio_trabajador" class="form-label">Domicilio</label>
              <input type="text" name="domicilio_trabajador" id="domicilio_trabajador" class="form-control input-evaluacion" readonly>
            </div>

            <div class="campo-form campo-automatico">
              <label for="sexo" class="form-label">Sexo</label>
              <input type="text" name="sexo" id="sexo" class="form-control input-evaluacion" readonly>
            </div>

            <div class="campo-form campo-automatico">
              <label for="cargo_trabajador" class="form-label">Cargo</label>
              <input type="text" name="cargo_trabajador" id="cargo_trabajador" class="form-control input-evaluacion" readonly>
            </div>

          </div>
        </div>
      </div>
        
      <div class="seccion-formulario">
        <div class="section-header">
          <span class="chip"><i class="bi bi-briefcase me-1"></i> Contrato</span>
          <h3>Condiciones del Contrato</h3>
        </div>

        <div class="section-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="monto" class="form-label">Monto mensual (S/)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-cash-coin"></i></span>
                <input type="text" inputmode="decimal" pattern="^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(\.\d{1,2})?$"
                      class="form-control" id="monto" name="monto" placeholder="Ej. 2,500.00" required>
              </div>
              <div class="form-text">Formatos válidos: “2500.00”, “2,500.00” o “2500,00”.</div>
            </div>

            <div class="col-md-6">
              <label for="fecha_inicio" class="form-label">Fecha de inicio</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required>
              </div>
            </div>

            <div class="col-md-6">
              <label for="plazo_meses" class="form-label">Plazo (meses)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-clock-history"></i></span>
                <input type="number" min="1" step="1" class="form-control" id="plazo_meses" name="plazo_meses" placeholder="Ej. 6" required>
              </div>
              <div class="form-text">Calcularemos la fecha de fin automáticamente.</div>
            </div>

            <div class="col-md-6">
              <label for="fecha_fin" class="form-label">Fecha de fin (auto)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-calendar2-check"></i></span>
                <input type="text" class="form-control" id="fecha_fin" name="fecha_fin" placeholder="—" readonly>
              </div>
            </div>

            <div class="col-12">
              <label for="cargo_contrato" class="form-label">Cargo</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                <input type="text" class="form-control" id="cargo_contrato" name="cargo_contrato" placeholder="Ej. Asistente Administrativo" required>
              </div>
              <div class="form-text">Se autocompleta con el cargo del trabajador seleccionado; puedes ajustarlo.</div>
            </div>


<div class="col-12">
  <label for="detalle" class="form-label">Detalle (funciones)</label>
  <textarea class="form-control input-evaluacion" id="detalle" name="detalle" rows="6" placeholder="Se cargará automáticamente según el cargo…"></textarea>
  <div class="form-text">Se llena leyendo un .txt según el cargo (puedes editarlo manualmente si deseas).</div>
  <button type="button" id="btnRefrescarDetalle" class="btn btn-outline-primary mt-2">
    <i class="bi bi-arrow-repeat"></i> Actualizar desde cargo
  </button>
</div>


          </div>
        </div>
      </div>

      <div class="seccion-formulario">
        <div class="section-header">
          <span class="chip"><i class="bi bi-eye me-1"></i> Vista</span>
          <h3>Vista previa de la plantilla</h3>
        </div>
        <div class="section-body">
          <div id="preview_wrapper" style="display:none;">
            <div id="docx_container" style="width:100%; min-height:420px; border:1px dashed #e9edf3; border-radius:10px; padding:10px; overflow:auto;"></div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn-validar w-100">
        <i class="bi bi-file-earmark-arrow-up me-1"></i> Generar Documento
      </button>

    </form>

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
const trabajadores = <?= json_encode($trabajadores) ?>;
const input = document.getElementById("buscar_trabajador");
const lista = document.getElementById("lista_trabajadores");
const hidden = document.getElementById("idtrabajador_seleccionado");

input.addEventListener("input", () => {
  const valor = input.value.toLowerCase().trim();

  // Limpia selección anterior
  document.getElementById("idtrabajador_visible").value = "";
  document.getElementById("dni_trabajador").value = "";
  document.getElementById("sexo").value = "";
  document.getElementById("fecha_trabajador").value = "";
  document.getElementById("domicilio_trabajador").value = "";
  document.getElementById("cargo_trabajador").value = "";
  document.getElementById("edad_trabajador").value = "";
  hidden.value = "";

  lista.innerHTML = "";
  if (valor.length < 1) {
    lista.style.display = "none";
    return;
  }

  const filtrados = trabajadores.filter(t =>
    t.clm_tra_nombres.toLowerCase().includes(valor) || String(t.clm_tra_id).includes(valor)
  );
function calcularEdad(fechaNacimiento) {
  const hoy = new Date();
  const nacimiento = new Date(fechaNacimiento);
  let edad = hoy.getFullYear() - nacimiento.getFullYear();
  const mes = hoy.getMonth() - nacimiento.getMonth();
  if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) {
    edad--;
  }
  return edad;
}

  filtrados.forEach(t => {
    const div = document.createElement("div");
    div.textContent = `[${t.clm_tra_id}] ${t.clm_tra_nombres}`;
    div.style.padding = "10px";
    div.style.cursor = "pointer";
    div.style.borderBottom = "1px solid #eee";
    div.addEventListener("click", () => {
      input.value = t.clm_tra_nombres;
      hidden.value = t.clm_tra_id;
      document.getElementById("dni_trabajador").value = t.clm_tra_dni || "No disponible";
      document.getElementById("sexo").value = t.clm_tra_sexo || "No disponible";
      document.getElementById("fecha_trabajador").value = t.clm_tra_fecha_nacimiento || "No disponible";
      document.getElementById("idtrabajador_visible").value = t.clm_tra_id || "No disponible";
      document.getElementById("domicilio_trabajador").value = t.clm_tra_domicilio || "No disponible";
      document.getElementById("cargo_trabajador").value = t.clm_tra_cargo || "No disponible";
      document.getElementById("edad_trabajador").value = t.clm_tra_fecha_nacimiento
        ? calcularEdad(t.clm_tra_fecha_nacimiento) + " años"
        : "No disponible";
      lista.style.display = "none";

iluminar("idtrabajador_visible");
iluminar("dni_trabajador");
iluminar("sexo");
iluminar("fecha_trabajador");
iluminar("idtrabajador_visible");
iluminar("domicilio_trabajador");
iluminar("cargo_trabajador");
iluminar("edad_trabajador");

    });
    lista.appendChild(div);
  });

  lista.style.display = filtrados.length ? "block" : "none";
});

document.addEventListener("click", (e) => {
  if (!lista.contains(e.target) && e.target !== input) {
    lista.style.display = "none";
  }
});


function iluminar(idCampo) {
  const campo = document.getElementById(idCampo);
  if (campo) {
    campo.classList.add("input-iluminado");
    setTimeout(() => campo.classList.remove("input-iluminado"), 1400);
  }
}


</script>
<script>
// ---------- Utilidades ----------
function toNumberFromHuman(val) {
  if (!val) return NaN;
  const s = String(val).trim().replace(/\./g, '').replace(',', '.');
  return Number(s);
}

function formatMoney(val) {
  if (isNaN(val)) return '';
  return val.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// yyyy-mm-dd -> dd/mm/aaaa
function formatDDMMYYYY(ymd) {
  if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return '';
  const [y,m,d] = ymd.split('-');
  return `${d}/${m}/${y}`;
}

// Date -> yyyy-mm-dd
function toYMD(dateObj) {
  const y = dateObj.getFullYear();
  const m = String(dateObj.getMonth()+1).padStart(2,'0');
  const d = String(dateObj.getDate()).padStart(2,'0');
  return `${y}-${m}-${d}`;
}

// Suma N meses conservando el día (si no existe, usa último del mes)
function addMonthsKeepEnd(ymd, months) {
  try {
    const base = new Date(ymd + 'T00:00:00');
    if (isNaN(base.getTime())) return '';
    const targetMonth = base.getMonth() + Number(months);
    const y = base.getFullYear() + Math.floor(targetMonth / 12);
    const m = ((targetMonth % 12) + 12) % 12;
    const lastDay = new Date(y, m + 1, 0).getDate();
    const day = Math.min(base.getDate(), lastDay);
    return toYMD(new Date(y, m, day));
  } catch { return ''; }
}

function actualizarFechaFin() {
  const fi = document.getElementById('fecha_inicio')?.value;
  const pm = document.getElementById('plazo_meses')?.value;
  const ff = document.getElementById('fecha_fin');
  if (!ff) return;
  if (fi && pm) {
    const ymd = addMonthsKeepEnd(fi, pm);
    ff.value = ymd ? formatDDMMYYYY(ymd) : '—';
  } else {
    ff.value = '—';
  }
}

// ---------- Eventos monto ----------
const montoInput = document.getElementById('monto');
if (montoInput) {
  montoInput.addEventListener('blur', () => {
    const n = toNumberFromHuman(montoInput.value);
    montoInput.value = isNaN(n) ? '' : formatMoney(n);
  });
  montoInput.addEventListener('input', () => {
    montoInput.value = montoInput.value.replace(/[^\d.,]/g, '');
  });
}

// ---------- Eventos fechas/plazo ----------
const fechaInicioInput = document.getElementById('fecha_inicio');
const plazoMesesInput  = document.getElementById('plazo_meses');
if (fechaInicioInput) fechaInicioInput.addEventListener('change', actualizarFechaFin);
if (plazoMesesInput)  plazoMesesInput.addEventListener('input', actualizarFechaFin);

// ---------- Autocompletar cargo desde trabajador ----------
(function wireCargoAuto() {
  const cargoAuto = document.getElementById('cargo_trabajador');
  const cargoEdit = document.getElementById('cargo_contrato');
  if (!cargoEdit) return;

  const obs = new MutationObserver(() => {
    if (cargoAuto && cargoAuto.value && (!cargoEdit.value || cargoEdit.value === 'No disponible')) {
      cargoEdit.value = cargoAuto.value;
    }
  });
  if (cargoAuto) obs.observe(cargoAuto, { attributes: true, attributeFilter: ['value'] });

  document.addEventListener('click', () => {
    if (cargoAuto && cargoAuto.value && (!cargoEdit.value || cargoEdit.value === 'No disponible')) {
      cargoEdit.value = cargoAuto.value;
    }
  });
})();

// ---------- Setear HOY en fecha_inicio al cargar y calcular fecha_fin ----------
document.addEventListener('DOMContentLoaded', () => {
  const fi = document.getElementById('fecha_inicio');
  if (fi && !fi.value) {
    const hoy = new Date();
    fi.value = toYMD(hoy);          // <input type="date"> exige yyyy-mm-dd
  }
  actualizarFechaFin();              // pinta fecha_fin en dd/mm/aaaa
});
</script>

<script>
// === Ruta base donde estarán los .txt de funciones ===
const RUTA_FUNCIONES = new URL('funciones/', window.location.href).href;

// Normaliza a slug: quita tildes, minúsculas, reemplaza espacios y símbolos
function slugifyCargo(cargo) {
  if (!cargo) return '';
  return cargo
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // quita acentos
    .toLowerCase()
    .replace(/[^a-z0-9\s\-_.]/g, '') // deja letras/números/espacios/-_. 
    .trim()
    .replace(/\s+/g, '_'); // espacios -> _
}

// (Opcional) mapa de alias → archivo específico si necesitas controlar nombres
// Por ejemplo: "asistente administrativo" -> "asistente_admin.txt"
const MAPA_ARCHIVOS = {
  'Asistente Administrativo': 'Asistente Administrativo.txt',
  'Counter': 'Counter.txt'
};

// Carga el .txt (si existe) y lo pone en el textarea #detalle
async function cargarDetalleDesdeCargo(cargo) {
  const detalle = document.getElementById('detalle');
  if (!detalle) return;

  const slug = slugifyCargo(cargo);
  if (!slug) {
    detalle.value = '';
    return;
  }

  // 1) verificar si hay un alias explícito
  const archivo = MAPA_ARCHIVOS[slug] || `${slug}.txt`;
  const urlTxt = RUTA_FUNCIONES + encodeURIComponent(archivo);

  // Limpia mientras carga (no bloqueante)
  detalle.placeholder = 'Cargando funciones del cargo…';

  try {
    const resp = await fetch(urlTxt, { cache: 'no-store', credentials: 'same-origin' });
    if (!resp.ok) {
      // como fallback intenta con una versión sin guiones bajos (por si nombras distinto)
      const altArchivo = slug.replace(/_/g, '') + '.txt';
      const altUrl = RUTA_FUNCIONES + encodeURIComponent(altArchivo);
      const respAlt = await fetch(altUrl, { cache: 'no-store', credentials: 'same-origin' });
      if (!respAlt.ok) throw new Error('No se encontró el archivo de funciones');
      const txtAlt = await respAlt.text();
      detalle.value = txtAlt.trim();
    } else {
      const txt = await resp.text();
      detalle.value = txt.trim();
    }
  } catch (err) {
    // Si no existe, intenta un default.txt (opcional)
    try {
      const respDef = await fetch(RUTA_FUNCIONES + 'default.txt', { cache: 'no-store', credentials: 'same-origin' });
      if (respDef.ok) {
        const txtDef = await respDef.text();
        detalle.value = txtDef.trim();
      } else {
        detalle.value = ''; // lo dejas vacío si no hay nada
      }
    } catch {
      detalle.value = '';
    }
  } finally {
    detalle.placeholder = 'Se cargará automáticamente según el cargo…';
  }
}

// Dispara la carga cuando cambie el cargo editable
(function wireFuncionesPorCargo() {
  const cargoEdit = document.getElementById('cargo_contrato');
  const btnRefrescar = document.getElementById('btnRefrescarDetalle');

  if (cargoEdit) {
    cargoEdit.addEventListener('change', () => cargarDetalleDesdeCargo(cargoEdit.value));
    cargoEdit.addEventListener('blur',   () => cargarDetalleDesdeCargo(cargoEdit.value));
  }
  if (btnRefrescar) {
    btnRefrescar.addEventListener('click', () => cargarDetalleDesdeCargo(cargoEdit?.value));
  }

  // Si ya viene con un valor (porque seleccionaste trabajador antes), carga al iniciar
  document.addEventListener('DOMContentLoaded', () => {
    if (cargoEdit && cargoEdit.value) {
      cargarDetalleDesdeCargo(cargoEdit.value);
    }
  });
})();

// Integra con tu autocompletado de cargo (cuando escoges un trabajador).
// Al final de tu wireCargoAuto() donde copias el cargo al #cargo_contrato, agrega:
(function hookAutoFunciones() {
  const cargoAuto = document.getElementById('cargo_trabajador');
  const cargoEdit = document.getElementById('cargo_contrato');

  // Observa cambios en el readonly para replicar y jalar funciones
  if (cargoAuto) {
    const obs2 = new MutationObserver(() => {
      if (!cargoEdit) return;
      // si no hay override manual, copia y carga
      if (!cargoEdit.value || cargoEdit.value === 'No disponible') {
        cargoEdit.value = cargoAuto.value || '';
      }
      if (cargoEdit.value && cargoEdit.value !== 'No disponible') {
        cargarDetalleDesdeCargo(cargoEdit.value);
      }
    });
    obs2.observe(cargoAuto, { attributes: true, attributeFilter: ['value'] });
  }

  // Por seguridad, cuando se hace click en la lista, intenta cargar
  document.addEventListener('click', () => {
    if (cargoEdit && cargoEdit.value && cargoEdit.value !== 'No disponible') {
      cargarDetalleDesdeCargo(cargoEdit.value);
    }
  });
})();
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



<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js"></script>
<script>
  // Carpeta donde guardas las plantillas DOCX respecto a este PHP:
  const RUTA_PLANTILLAS = new URL('plantillas/', window.location.href).href;

  // Utilidad: asegura que tenga extensión .docx si no la trae
  function asegurarDocx(nombre) {
    if (!nombre) return '';
    const lower = nombre.toLowerCase().trim();
    if (lower.endsWith('.docx')) return nombre.trim();
    return nombre.trim() + '.docx';
  }

  document.addEventListener('DOMContentLoaded', () => {
    const sel   = document.getElementById('idtipo_documento');
    const wrap  = document.getElementById('preview_wrapper');
    const cont  = document.getElementById('docx_container');
    const lbl   = document.getElementById('nombre_plantilla');

    if (!sel || !wrap || !cont || !lbl) return;

    sel.addEventListener('change', async () => {
      const opt = sel.options[sel.selectedIndex];
      const plantillaDb = (opt?.dataset?.plantilla || '').trim();

      // Actualiza el label
      lbl.value = plantillaDb || '—';

      // Si no hay plantilla, limpia y oculta preview
      if (!plantillaDb) {
        cont.innerHTML = '';
        wrap.style.display = 'none';
        return;
      }

      // Arma URL absoluta de la plantilla
      const nombreArchivo = asegurarDocx(plantillaDb);
      const urlPlantilla  = RUTA_PLANTILLAS + encodeURIComponent(nombreArchivo);

      // Muestra y renderiza
      wrap.style.display = 'block';
      cont.textContent = 'Cargando vista previa…';

      try {
        const resp = await fetch(urlPlantilla, { cache: 'no-store', credentials: 'same-origin' });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const buf = await resp.arrayBuffer();
        cont.innerHTML = '';
        await window.docx.renderAsync(buf, cont);
      } catch (err) {
        console.error('Preview DOCX falló:', urlPlantilla, err);
        cont.textContent = 'No se pudo mostrar la vista previa de esta plantilla.';
      }
    });
  });
</script>






</body>



</html>

