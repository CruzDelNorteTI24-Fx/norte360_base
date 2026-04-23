<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);

if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 5; // id_modulo de esta vista
    $vista_actuales = ["c-limp", "c-sab"];

    if (!in_array($modulo_actual, $_SESSION['permisos']) || empty(array_intersect($vista_actuales, $_SESSION['vistas']))) {
        header("Location: ../login/none_permisos.php");
        exit();
    }
}

define('ACCESS_GRANTED', true);
require_once("../trash/copidb_secure.php");
require_once("../.c0nn3ct/db_securebd2.php");
// Procesar filtros si se enviaron


$conductores = $conn->query("SELECT clm_tra_id, clm_tra_dni, clm_tra_nombres FROM tb_trabajador WHERE clm_tra_tipo_trabajador = 'Conductor'");


$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mantenimiento | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">      
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>


    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }

        .card {
transition: transform 0.2s;
            max-width: 700px;
            margin: 40px auto 20px auto;
            border-radius: 12px;
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
  left: 0;
  width: 240px;
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
    margin-left: 200px;
    padding: 30px;
}
.bus-card.selected {
  background: #2980b9 !important; /* azul */
}
input[type=date] {
  padding: 10px;
  font-size: 15px;
  border-radius: 8px;
  border: 1px solid #ccc;
}
#popup-error {
  position: fixed;
  top: 0; left: 0;
  width: 100vw; height: 100vh;
  background: rgba(0,0,0,0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}
.mensaje-error {
  background: white;
  padding: 30px 50px;
  border-radius: 12px;
  text-align: center;
  font-size: 18px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
.mensaje-error button {
  margin-top: 20px;
  background: #2980b9;
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 16px;
  cursor: pointer;
}
.mensaje-error button:hover {
  background: #1c5980;
}

/* Grid general responsive para cards checklist */
.checklist-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  max-width: 1400px;
  margin: 0 auto;
}

/* Títulos de tipo en grid */
.checklist-tipo-titulo {
  grid-column: 1 / -1;
  background: #2980b9;
  color: white;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 18px;
  font-weight: bold;
}

/* Card checklist */
.checklist-card {
  background: white;
  border-left: 5px solid #2980b9;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  transition: transform 0.2s;
}

.checklist-card:hover {
  transform: scale(1.02);
}

/* Estado badge */
.checklist-estado {
  padding: 6px 12px;
  border-radius: 20px;
  font-weight: bold;
  font-size: 13px;
  color: white;
}

/* Botón Ver/Llenar */
.checklist-btn {
  margin-top: auto;
  background: linear-gradient(120deg, #2980b9, #3498db);
  color: white;
  text-align: center;
  padding: 8px 0;
  border-radius: 8px;
  text-decoration: none;
  font-weight: bold;
  transition: background 0.3s;
}

.checklist-btn:hover {
  background: #1c5980;
}

/* Responsive celular */
@media (max-width: 600px) {
  .checklist-grid {
    grid-template-columns: 1fr;
    padding: 0 10px;
  }
}
form input[type="date"], form button {
  width: auto;
}

@media (max-width: 600px) {
  form {
    flex-direction: column;
    align-items: stretch;
  }
  form input[type="date"], form button {
    width: 100%;
  }
}
.sugerencias-container {
  background: white;
  border: 1px solid #ccc;
  max-height: 180px;
  overflow-y: auto;
  border-radius: 8px;
  margin-top: -10px;
  position: absolute;
  z-index: 1000;
  width: calc(100% - 40px);
}

.sugerencia-item {
  padding: 10px;
  cursor: pointer;
}

.sugerencia-item:hover {
  background: #f0f0f0;
}

.no-result {
  padding: 10px;
  color: #888;
}
.checklist-cards-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.checklist-card-item {
  background: white;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  transition: transform 0.2s;
  border-left: 5px solid #2980b9;
}

.checklist-card-item:hover {
  transform: scale(1.02);
}

.checklist-card-item h4 {
  margin: 0 0 10px 0;
  font-size: 16px;
  color: #2c3e50;
}

.checklist-card-item p {
  margin: 4px 0;
  font-size: 14px;
  color: #555;
}

.checklist-card-item .estado {
  display: inline-block;
  margin-top: 8px;
  padding: 6px 12px;
  border-radius: 20px;
  font-weight: bold;
  font-size: 13px;
  color: white;
  background: #27ae60;
}

.checklist-card-item .estado.pendiente {
  background: #e67e22;
}

.checklist-card-item .ver-btn {
  margin-top: auto;
  background: linear-gradient(120deg, #2980b9, #3498db);
  color: white;
  text-align: center;
  padding: 8px 0;
  border-radius: 8px;
  text-decoration: none;
  font-weight: bold;
  transition: background 0.3s;
}

.checklist-card-item .ver-btn:hover {
  background: #1c5980;
}
.checklist-cards-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.checklist-card-item {
  background: white;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 6px 14px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  transition: transform 0.2s, box-shadow 0.2s;
  border-left: 6px solid #3498db;
  position: relative;
}

.checklist-card-item:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(0,0,0,0.12);
}

.checklist-card-item h4 {
  margin: 0 0 10px 0;
  font-size: 17px;
  color: #2c3e50;
  display: flex;
  align-items: center;
  gap: 8px;
}

.checklist-card-item h4 i {
  color: #3498db;
}

.checklist-card-item p {
  margin: 4px 0;
  font-size: 14px;
  color: #555;
}

.checklist-card-item .estado {
  display: inline-block;
  margin-top: 12px;
  padding: 6px 14px;
  border-radius: 20px;
  font-weight: bold;
  font-size: 13px;
  color: white;
  background: #27ae60;
  align-self: flex-start;
}

.checklist-card-item .estado.pendiente {
  background: #e67e22;
}

.checklist-card-item .estado.inactivo {
  background: #c0392b;
}

.checklist-card-item .ver-btn {
  margin-top: auto;
  background: linear-gradient(120deg, #2980b9, #3498db);
  color: white;
  text-align: center;
  padding: 10px 0;
  border-radius: 8px;
  text-decoration: none;
  font-weight: bold;
  transition: background 0.3s;
}

.checklist-card-item .ver-btn:hover {
  background: #1c5980;
}

.checklist-icon {
  position: absolute;
  top: 20px;
  right: 20px;
  font-size: 24px;
  color: #2980b9;
}
.checklist-card-item .ver-btn {
  margin-top: 15px;
  background: #2980b9;
  color: white;
  text-align: center;
  padding: 12px 0;
  border-radius: 30px;
  text-decoration: none;
  font-weight: bold;
  font-size: 15px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
  transition: all 0.3s ease;
}

.checklist-card-item .ver-btn:hover {
  background: #1c5980;
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.2);
}
.folder-container {
  background: #ffff;
  border-radius: 12px;
  margin-bottom: 30px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
  padding: 20px;
  border-bottom: 2px solid #2980b9;
}
.folder-container:hover {
    transform: scale(1.02);
}
.folder-title {
  font-size: 18px;
  font-weight: bold;
  color: #2980b9;
  margin-bottom: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.folder-title i {
  color: #f1c40f;
  font-size: 20px;
}
.bus-info-banner {
  background: linear-gradient(90deg, #2980b9, #3498db);
  color: white;
  border-radius: 12px;
  padding: 20px 30px;
  margin: 30px auto;
  max-width: 800px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  text-align: center;
}

.bus-info-banner {
  background: linear-gradient(135deg, #005bac, #0088cc);
  color: white;
  border-radius: 16px;
  padding: 25px 40px;
  margin: 30px auto;
  max-width: 800px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.15);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}
.bus-info-banner {
  background: linear-gradient(135deg, #005bac, #0088cc);
  color: white;
  border-radius: 16px;
  padding: 25px 40px;
  margin: 30px auto;
  max-width: 900px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

.bus-info-banner .bus-details {
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}

.bus-info-banner i {
  font-size: 38px;
}

.bus-info-banner h3 {
  margin: 0;
  font-size: 26px;
}

.bus-info-banner p {
  margin: 0;
  font-size: 18px;
  font-weight: 500;
}

.bus-info-banner .tag {
  background: rgba(255,255,255,0.2);
  padding: 8px 16px;
  border-radius: 30px;
  font-weight: bold;
  font-size: 16px;
}

.bus-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
}

.bus-header h3 {
  margin: 0;
  font-size: 26px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.bus-tag {
  background: rgba(255,255,255,0.2);
  padding: 8px 16px;
  border-radius: 30px;
  font-weight: bold;
  font-size: 16px;
  margin-left: auto; /* añade esto */
}
.bus-details-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.bus-detail-item {
  background: rgba(255,255,255,0.1);
  border-radius: 12px;
  padding: 12px 16px;
  text-align: center;
}

.bus-detail-item i {
  font-size: 20px;
  margin-bottom: 8px;
}

.bus-detail-item p {
  margin: 0;
  font-size: 14px;
}
.bus-details-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.bus-detail-item {
  background: rgba(255,255,255,0.1);
  border-radius: 12px;
  padding: 12px 16px;
  text-align: center;
}

.bus-detail-item i {
  font-size: 20px;
  margin-bottom: 8px;
}

.bus-detail-item p {
  margin: 0;
  font-size: 14px;
}
.bus-detail-item p {
  margin: 0;
  font-size: 14px;
  line-height: 1.4;
}

.bus-detail-item strong {
  font-size: 15px;
  display: block;
  margin-bottom: 4px;
}
.bus-details-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 20px;
  margin: 20px auto;
  max-width: 900px;
}

.bus-detail-item {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  padding: 15px;
  text-align: center;
  transition: transform 0.2s;
}

.bus-detail-item:hover {
  transform: scale(1.05);
}

.bus-detail-item i {
  font-size: 20px;
  margin-bottom: 8px;
  color: #2980b9;
}

.bus-detail-item p {
  margin: 0;
  font-size: 14px;
  line-height: 1.4;
}

.bus-detail-item strong {
  display: block;
  margin-bottom: 4px;
  font-size: 15px;
  color: #2c3e50;
}

/* Responsive */
@media (max-width: 600px) {
  .bus-details-grid {
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }
.folder-container {
  padding: 10px;
}

}
.busqueda-form {
  max-width: 500px;
  margin: 30px auto;
  position: relative;
}

.input-container {
  position: relative;
}

.input-container i {
  position: absolute;
  left: 15px;
  top: 50%;
  transform: translateY(-50%);
  color: #2980b9;
  font-size: 18px;
}

.input-container input[type=text] {
  width: 100%;
  padding: 14px 14px 14px 45px; /* espacio para el icono */
  font-size: 16px;
  border: 2px solid #2980b9;
  border-radius: 8px;
  box-sizing: border-box;
  transition: all 0.3s ease;
}

.input-container input[type=text]:focus {
  border-color: #1c5980;
  box-shadow: 0 0 5px rgba(28, 89, 128, 0.4);
  outline: none;
}
.input-conductor {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 8px;
    transition: background 0.3s, border 0.3s;
}

.input-conductor.readonly {
    background: #f0f0f0; /* gris claro */
    color: #666;
}

.input-conductor.editable {
    background: #e6f7ff; /* azul claro */
    border-color: #3498db;
    color: #2c3e50;
}
.btn-checklist {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 14px 28px;
  font-size: 16px;
  font-weight: bold;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  color: white;
  width: 100%;
  max-width: 300px;
  transition: transform 0.2s, box-shadow 0.2s;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.btn-checklist i {
  font-size: 18px;
}

.btn-checklist.imprimir {
  background: linear-gradient(120deg, #2980b9, #3498db);
}

.btn-checklist.imprimir:hover {
  background: #1c5980;
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.2);
}

.btn-checklist.registrar {
  background: linear-gradient(120deg, #27ae60, #2ecc71);
}

.btn-checklist.registrar:hover {
  background: #1e8449;
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.2);
}
.btn-checklist-container {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px; /* separación entre botones */
  flex-wrap: wrap;
  margin: 30px 0;
}
.fa-pulse {
  animation: fa-spin 1s infinite linear;
}

@keyframes fa-spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(359deg); }
}
.campo-form label i {
  margin-right: 6px;
  color: #2980b9;
}

.campo-form select:focus,
.campo-form input[type="date"]:focus {
  border-color: #3498db;
  box-shadow: 0 0 5px rgba(52,152,219,0.3);
  outline: none;
}

button[type="submit"]:hover {
  background: #1c5980 !important;
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
    <p class="texto-popup">¡Registrado correctamente!</p>
  </div>
</div>


<?php endif; ?>



<?php if (isset($_SESSION['error_fecha']) && $_SESSION['error_fecha'] === true): ?>
<div id="popup-error">
  <div class="mensaje-error">
    <p>La fecha seleccionada no es la actual.</p>
    <button onclick="cerrarError()">Aceptar</button>
  </div>
</div>
<?php unset($_SESSION['error_fecha']); endif; ?>
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
  <a href="lista_cheklist.php">📝 CheckList</a>
</div>

<button class="menu-toggle" onclick="toggleMenu()">☰</button>

<div class="menu-lateral" id="menuLateral">
    <?php
        if ($_SESSION['web_rol'] == 'Admin' || in_array("c-lalu", $vistas)) {

          echo "<h3>Generar CheckList</h3>";
          echo "<ul>";
            echo "<li><a href='limpieza/mantcdn.php?id_tipo=1'>➕ Nueva Limpieza</a></li>";
            echo "<li><a href='limpieza/mantcdn.php?id_tipo=3'>➕ Nuevo Alcoholímetro</a></li>";
          echo "</ul>";
        }
        if ($_SESSION['web_rol'] == 'Admin' || in_array("c-sab", $vistas)) {

          echo "<ul>";
            echo "<li><a href='limpieza/mantcdn.php?id_tipo=2'>➕ Nuevo Embarque</a></li>";
          echo "</ul>";
        }
    ?>
  <h3>Lista CheckList</h3>  
  <ul>
    <li><a href="lista_cheklist.php">Ver CheckList</a></li>
    <?php
        if ($_SESSION['web_rol'] == 'Admin') {
          echo "<li><a href='interbus_vld.php'>Generar Ruta</a></li>";
          echo "<li><a href='viajes.php'>Ver Viajes</a></li>";
          echo "<li><a href='calendario_cheklist.php'>Calendario ChekList</a></li>";
          echo "<li><a href='categorias_items.php'>Gestionar Ítems ChekList</a></li>";
          if (in_array("c-lalu", $vistas)) {
            echo "<li><a href='interbus_vld.php'>Generar Ruta</a></li>";
          }   
        }
    ?>
  </ul>
</div>



<div class="main-content" id="areaPDF">
  <hr>

<h2>Visualizar Viajes por Bus y Fecha</h2>




<div class="catalogo-container">
  <?php while($c = $conductores->fetch_assoc()): ?>
    <div class="product-card">
      <img src="../img/icons/user.png" alt="Foto" style="max-height:120px;">
      <h4><?= htmlspecialchars($c['clm_tra_nombres']) ?></h4>
      <p><strong>DNI:</strong> <?= htmlspecialchars($c['clm_tra_dni']) ?></p>
      <a class="ver-btn" href="../01_contratos/trabajadores/detalle_trabajador.php?id=<?= urlencode($c['clm_tra_id']) ?>">Ver Detalles</a>
    </div>
  <?php endwhile; ?>
</div>


<!-- Modal de Carga -->

<div id="modal-cargando" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">

  <div style="background:white; padding:30px 50px; border-radius:12px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <i class="fas fa-spinner fa-pulse" style="font-size:30px; color:#2980b9;"></i>
    <p style="margin-top:15px; font-size:18px; font-weight:bold;">Procesando...<br>Por favor espere</p>
  </div>
</div>


  <hr>
</div>


<script>
function toggleFolder(element) {
    const content = element.nextElementSibling;
    const icon = element.querySelector('i.fa-chevron-down');

    if (content.style.display === "none" || content.style.display === "") {
        content.style.display = "grid";
        icon.style.transform = "rotate(180deg)";
    } else {
        content.style.display = "none";
        icon.style.transform = "rotate(0deg)";
    }
}
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const chkRango = document.getElementById("chk_rango");
  const chkHoy = document.getElementById("chk_hoy");
  const fecha = document.getElementById("fecha");
  const fechaInicio = document.getElementById("fecha_inicio");
  const fechaFin = document.getElementById("fecha_fin");

  // Inicial
  fechaInicio.disabled = !chkRango.checked;
  fechaFin.disabled = !chkRango.checked;
  fecha.readOnly = chkHoy.checked;

  chkRango.addEventListener("change", function() {
    fechaInicio.disabled = !this.checked;
    fechaFin.disabled = !this.checked;

    if (this.checked) {
      chkHoy.checked = false;
      fecha.readOnly = true;
    }
  });

  chkHoy.addEventListener("change", function() {
    if (this.checked) {
      fecha.readOnly = true;
      chkRango.checked = false;
      fechaInicio.disabled = true;
      fechaFin.disabled = true;
      fecha.value = new Date().toISOString().split('T')[0];
    } else {
      fecha.readOnly = false;
    }
  });
});
</script>



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
  <style>.footer-h2bd {position: absolute;bottom: 10px;right: 10px;opacity: 0;transition: opacity 0.4s ease;width: 80px;}.main-footer:hover .footer-h2bd {opacity: 0.6;}.footer-h2bd {filter: grayscale(40%);}</style>
  <div id="h2bd" style="display:none; position:fixed; bottom:10px; left:10px; z-index:9999; text-align:center;"><img src="<?= $h2bd_img ?>" alt="icong" style="width:80px; opacity:0.8; filter: grayscale(40%); display:block; margin:0 auto;"><p style="color:white; font-size:12px; margin:4px 0 0 0;"><?= $h2bd_name ?></p></div>
  <script>document.addEventListener('keydown', function(e) {if (e.ctrlKey && e.altKey && e.key === 'm') {const egg = document.getElementById('h2bd');egg.style.display = egg.style.display === 'none' ? 'block' : 'none';}});</script>

</footer>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const popup = document.getElementById("popup-exito");
    if (popup) {
        const mensaje = popup.querySelector('.mensaje');
        setTimeout(() => {
            mensaje.style.animation = 'fadeOut 0.5s ease forwards';
            setTimeout(() => popup.remove(), 400);
        }, 1000);
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
function toggleMenu() {
  const menu = document.querySelector('.menu-lateral');
  menu.classList.toggle('active');
}
</script>

</body>



</html>

