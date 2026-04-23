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
.bus-info-banner {
  background: linear-gradient(135deg, #005bac, #0088cc);
  color: white;
  border-radius: 16px;
  padding: 25px 40px;
  margin: 30px auto;
  max-width: 900px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.15);
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
  <a href="../01_contratos/nregrcdn_h.php">➕ Nuevo Personal</a>
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
          echo "<li><a href='interbus_vld.php'>Ver Buses</a></li>";
          echo "<li><a href='viajes.php'>Ver Viajes</a></li>";
          echo "<li><a href='calendario_cheklist.php'>Calendario ChekList</a></li>";
          echo "<li><a href='categorias_items.php'>Gestionar Ítems ChekList</a></li>";
          if (in_array("c-lalu", $vistas)) {
            echo "<li><a href='interbus_vld.php'>Ver Buses</a></li>";
          }   
        }
    ?>
  </ul>
</div>



<div class="main-content" id="areaPDF">
  <hr>


  <h2>Generar Rutas</h2>

<form class="busqueda-form" method="GET" action="">
  <div class="input-container">
    <i class="fas fa-search"></i>
    <input type="hidden" name="bus_id" id="bus_id">
    <input type="text" id="bus_input" name="bus_nombre" placeholder="Buscar bus por nombre o placa..." autocomplete="off" required onkeydown="return event.key !== 'Enter';">
  </div>
  <div id="sugerencias_bus" class="sugerencias-container"></div>
</form>

  <?php include("inter_bus/interbus_logic.php"); ?>


<div class="btn-checklist-container" id="botonesChecklist" style="display:none;">
  <button class="btn-checklist imprimir" onclick="generarChecklist('imprimir')">
    <i class="fas fa-file-pdf"></i> Imprimir PDF
  </button>

  <button class="btn-checklist registrar" onclick="generarChecklist('guardar_imprimir')">
    <i class="fas fa-save"></i> Imprimir y Registrar
  </button>
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
document.addEventListener("DOMContentLoaded", function() {
    const inputBus = document.getElementById("bus_input");
    const sugerenciasDiv = document.getElementById("sugerencias_bus");
    const busIdInput = document.getElementById("bus_id");

    inputBus.addEventListener("input", function() {
        const valor = this.value.trim();
        const modal = document.getElementById("modal-cargando"); // ✅ Referencia al modal

        if (valor.length > 0) {
            modal.style.display = "flex"; // ✅ Mostrar modal antes de la petición

            fetch("inter_bus/ajax_buses.php?query=" + encodeURIComponent(valor))
                .then(response => response.json())
                .then(data => {
                    sugerenciasDiv.innerHTML = "";
                    if (data.length > 0) {
                        data.forEach(bus => {
                            const div = document.createElement("div");
                            div.textContent = bus.clm_placas_BUS + " (" + bus.clm_placas_placa + ")";
                            div.dataset.busId = bus.clm_placas_id;
                            div.classList.add("sugerencia-item");
                            div.addEventListener("click", function() {
                                inputBus.value = this.textContent;
                                busIdInput.value = this.dataset.busId;
                                sugerenciasDiv.innerHTML = "";

                                // Envío automático del formulario al seleccionar
                                inputBus.form.submit();
                            });
                            sugerenciasDiv.appendChild(div);
                        });
                    } else {
                    sugerenciasDiv.innerHTML = "<div class='no-result'>Sin resultados</div>";
                }
            })
            .catch(error => {
                console.error("❌ Error en la búsqueda:", error);
                alert("Ocurrió un error al buscar buses.");
            })
            .finally(() => {
                modal.style.display = "none"; // ✅ Ocultar modal siempre al terminar
            });
    } else {
        sugerenciasDiv.innerHTML = "";
    }
});

    // Cierra sugerencias si se hace clic fuera
    document.addEventListener("click", function(e) {
        if (!inputBus.contains(e.target) && !sugerenciasDiv.contains(e.target)) {
            sugerenciasDiv.innerHTML = "";
        }
    });
});
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    const bus_id = urlParams.get('bus_id');
    const fecha = urlParams.get('fecha');

    if (bus_id && !fecha) {
        // Si hay bus_id pero no fecha, recarga agregando la fecha actual
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        const fecha_actual = `${year}-${month}-${day}`;

        // Construir la nueva URL con fecha
        urlParams.set('fecha', fecha_actual);
        window.location.search = urlParams.toString();
    }


    // 🔽 NUEVO BLOQUE: mostrar botones según fecha buscada
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const fecha_actual = `${year}-${month}-${day}`;

    const botonesChecklist = document.getElementById("botonesChecklist");
    const btnImprimir = botonesChecklist.querySelector(".btn-checklist.imprimir");
    const btnRegistrar = botonesChecklist.querySelector(".btn-checklist.registrar");

    if (fecha) {
        botonesChecklist.style.display = "flex";

        if (fecha === fecha_actual) {
            btnImprimir.style.display = "none";
            btnRegistrar.style.display = "inline-flex";
        } else {
            btnImprimir.style.display = "inline-flex";
            btnRegistrar.style.display = "none";
        }
    }

});
</script>
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
const originalValues = {};
const conductorStatus = {
    conductor1: false,
    conductor2: false
};

function toggleEdit(inputId) {
    const input = document.getElementById(inputId);
    const btn = document.getElementById('btn_' + inputId);

    if (!originalValues[inputId]) {
        originalValues[inputId] = input.value;
    }

    if (input.readOnly) {
        input.readOnly = false;
        input.classList.remove('readonly');
        input.classList.add('editable');
        input.focus();

        // Marca como editado (seleccionado)
        conductorStatus[inputId] = true;

        // Cambia botón a estado 'Cancelar'
        btn.style.background = '#c0392b'; // rojo
        btn.innerHTML = "<i class='fas fa-times'></i> Cancelar";

    } else {
        input.value = originalValues[inputId];
        input.readOnly = true;
        input.classList.remove('editable');
        input.classList.add('readonly');

        // Marca como NO editado (revertido)
        conductorStatus[inputId] = false;

        // Cambia botón a estado 'Editar'
        btn.style.background = '#3498db'; // azul
        btn.innerHTML = "<i class='fas fa-edit'></i> Editar";
    }

    console.log("Estado actual de conductores:", conductorStatus);
}

function verificarTransbordo() {
    const origen = document.getElementById('origen').value;
    const destino = document.getElementById('destino').value;
    const tag = document.getElementById('transbordoTag');

    if (origen && destino && origen === destino) {
        tag.style.display = 'inline-block';
    } else {
        tag.style.display = 'none';
    }
}


async function generarChecklist(modo = "imprimir") {
  const modal = document.getElementById('modal-cargando');
  modal.style.display = 'flex'; // ✅ Mostrar modal antes de ejecutar

  const { busId, fecha, horaActual, usuarioSesion, origen, destino, conductor1, conductor2 } = obtenerVariablesChecklist();

  if (!busId) {
    alert("Seleccione un bus antes de continuar.");
    return;
  }

  // ✅ Si el modo es guardar_imprimir, valida origen y destino antes de continuar
  if (modo === "guardar_imprimir") {
    if (!origen || origen.trim() === "") {
      alert("Por favor, seleccione un ORIGEN antes de registrar el viaje.");
      modal.style.display = 'none'; // ✅ Ocultar modal al finalizar
      return;
    }

    if (!destino || destino.trim() === "") {
      alert("Por favor, seleccione un DESTINO antes de registrar el viaje.");
      modal.style.display = 'none'; // ✅ Ocultar modal al finalizar
      return;
    }
  }

  try {
    const response = await fetch(`inter_bus/interbus_logic_api.php?bus_id=${busId}&fecha=${fecha}`);
    const data = await response.json();

    if (data.error) {
      alert(data.error);
      return;
    }

    // 👉 Generar PDF
    generarPDFDesdeData(data, { fecha, horaActual, usuarioSesion, origen, destino, conductor1, conductor2 });

    // 👉 Si el modo es imprimir, genera y descarga TXT


    // 👉 Si el modo es guardar_imprimir, registra viaje después de generar PDF
    if (modo === "guardar_imprimir") {
      registrarViajeDespuesDeMostrar();
    }

  } catch (err) {
    console.error(err);
    alert("Error al generar el checklist.");
  } finally {
    modal.style.display = 'none'; // ✅ Ocultar modal al finalizar
  }
}

function mostrarDatosViaje() {
  const fecha = new Date().toISOString().split('T')[0];
  const hora = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

  let busId = document.getElementById('bus_id').value;
  if (!busId) {
    const urlParams = new URLSearchParams(window.location.search);
    busId = urlParams.get('bus_id');
  }

  const origen = document.getElementById('origen') ? document.getElementById('origen').value : null;
  const destino = document.getElementById('destino') ? document.getElementById('destino').value : null;
  const conductor1 = document.getElementById('conductor1') ? document.getElementById('conductor1').value : null;
  const conductor2 = document.getElementById('conductor2') ? document.getElementById('conductor2').value : null;
  const observaciones = document.getElementById('observaciones') ? document.getElementById('observaciones').value : null;
  const transbordo = (origen && destino && origen === destino);

  const usuario = "<?= $_SESSION['usuario'] ?>";
  const usuario_id = "<?= $_SESSION['id_usuario'] ?>";

  const checklistCards = document.querySelectorAll('.checklist-card-item');
  const checklistIds = [];
  const checklistKpis = [];

  checklistCards.forEach((card, index) => {
    const h4 = card.querySelector('h4');
    let corr = null;
    if (h4) {
      const corrMatch = h4.textContent.match(/Checklist N°\s*([A-Za-z0-9\-]+)/);
      corr = corrMatch ? corrMatch[1] : null;
    }

    const verBtn = card.querySelector('.ver-btn');
    const href = verBtn ? verBtn.getAttribute('href') : "";
    const idMatch = href.match(/id=([0-9]+)/);
    const checklistId = idMatch ? idMatch[1] : null;

    checklistIds.push({
      index: index,
      id: checklistId,
      correlativo: corr
    });

    const kpiBlocks = card.querySelectorAll("div[style*='background:#f8f9fa']");
    kpiBlocks.forEach(kpiBlock => {
      const titulo = kpiBlock.querySelector('h4') ? kpiBlock.querySelector('h4').textContent.trim() : "";
      const items = [];
      const kpiItems = kpiBlock.querySelectorAll('div, p');

      kpiItems.forEach(k => {
        const strong = k.querySelector('strong');
        const spans = k.querySelectorAll('span');

        if (strong && spans.length > 0) {
          const conductor = strong.textContent.trim();
          const valor = spans[0].textContent.trim();
          items.push({ conductor, valor });
        } else if (strong) {
          const key = strong.textContent.trim().replace(':','');
          const valueNode = strong.nextSibling;
          const value = valueNode ? valueNode.textContent.trim() : "";
          items.push({ key, valor: value });
        }
      });

      checklistKpis.push({
        checklist_index: index,
        checklist_id: checklistId,
        checklist_correlativo: corr,
        kpi_titulo: titulo,
        kpi_items: items
      });
    });
  });

  const datosViaje = {
    fecha_viaje: fecha,
    hora_viaje: hora,
    id_vehiculo: busId,
    origen,
    destino,
    conductor1,
    conductor2,
    observaciones,
    transbordo,
    checklist_ids: checklistIds,
    checklist_kpis: checklistKpis,
    usuario_registro: usuario,
    usuario_id: usuario_id
  };
  return datosViaje;
}

/** 🔧 Función para obtener variables de contexto */
function obtenerVariablesChecklist() {
  let busId = document.getElementById('bus_id').value;
  if (!busId) {
    const urlParams = new URLSearchParams(window.location.search);
    busId = urlParams.get('bus_id');
  }

  const urlParams = new URLSearchParams(window.location.search);
  const fecha = urlParams.get('fecha') || new Date().toISOString().split('T')[0];
  const horaActual = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
  const usuarioSesion = "<?= $_SESSION['usuario'] ?>";

  const origen = document.getElementById('origen') ? document.getElementById('origen').value : "No registrado";
  const destino = document.getElementById('destino') ? document.getElementById('destino').value : "No registrado";
  const conductor1 = document.getElementById('conductor1') ? document.getElementById('conductor1').value : "No registrado";
  const conductor2 = document.getElementById('conductor2') ? document.getElementById('conductor2').value : "No registrado";

  return { busId, fecha, horaActual, usuarioSesion, origen, destino, conductor1, conductor2 };
}






/** 🔧 Función para generar PDF desde la data */
function generarPDFDesdeData(data, info) {
  const pdf = new jspdf.jsPDF();
  const logoUrl = "../img/norte360_blanco.jpg";

  // Header simple, profesional y serio
  pdf.addImage(logoUrl, 'PNG', 10, 10, 35, 18);
  pdf.setFont("helvetica", "bold").setFontSize(15).setTextColor(0, 51, 102)
    .text("Reporte Checklist", 105, 18, null, null, "center");
  pdf.setFontSize(9).setFont("helvetica", "normal").setTextColor(90, 90, 90)
    .text("Cruz del Norte | Norte 360°", 105, 24, null, null, "center");
  pdf.line(10, 28, 200, 28);

  pdf.setFontSize(10).setTextColor(60, 60, 60)
    .text(`Fecha impresión: ${info.fecha} ${info.horaActual}`, 10, 34)
    .text(`Usuario: ${info.usuarioSesion}`, 150, 34);

  pdf.setLineDashPattern([2, 2], 0); // [punto, espacio], fase=0
    pdf.line(10, 40, 200, 40);
  pdf.setLineDashPattern([], 0); // Restablece a línea sólida

  pdf.setFontSize(10).setTextColor(60, 60, 60)
    .text(`Bus: ${data.bus.clm_placas_BUS} | Placa: ${data.bus.clm_placas_placa}`, 10, 45)
    .text(`Servicio: ${data.bus.clm_placas_SERVICIO} | Tipo: ${data.bus.clm_placas_TIPO_VEHÍCULO}`, 10, 52)
    .text(`Fecha registro: ${data.fecha} ${data.hora}`, 10, 59)
    .text(`Origen: ${info.origen} | Destino: ${info.destino}`, 10, 66)
    .text(`Conductor 1: ${info.conductor1} | Conductor 2: ${info.conductor2}`, 10, 73);

  pdf.setLineDashPattern([2, 2], 0); // [punto, espacio], fase=0
    pdf.line(10, 76, 200, 76);
  pdf.setLineDashPattern([], 0); // Restablece a línea sólida

  let y = 85;

  data.tipos.forEach(tipo => {
    pdf.setFontSize(12).setTextColor(255).setFillColor(0, 102, 204)
      .rect(10, y - 5, 190, 8, 'F')
      .text(`Tipo: ${tipo.nombre} | Estado: ${tipo.completitud}`, 12, y);
    y += 8;

    pdf.setFontSize(10).setTextColor(80, 80, 80)
      .text(`Items respondidos: ${tipo.respondidos} / ${tipo.total}`, 12, y);
    y += 5;

    pdf.setFillColor(230, 240, 255).setTextColor(0, 51, 102)
      .rect(10, y - 4, 190, 6, 'F')
      .text("N°", 12, y).text("Fecha", 30, y).text("Hora", 80, y).text("Estado", 130, y).text("Corr", 170, y);
    y += 6;

    tipo.checklists.forEach(chk => {
      pdf.setFontSize(9).setTextColor(80, 80, 80)
        .text(`${chk.clm_checklist_id}`, 12, y)
        .text(`${chk.clm_checklist_fecha}`, 30, y)
        .text(`${chk.clm_checklist_hora}`, 80, y)
        .text(`${chk.clm_checklist_estado}`, 130, y)
        .text(`${chk.clm_checklist_corr}`, 170, y);
      y += 5;
      if (y > 280) { pdf.addPage(); y = 20; }
    });

    pdf.setFontSize(10).setTextColor(80, 80, 80)
      .text(`Responsable: ${tipo.responsable || 'No registrado'}`, 12, y);
    y += 5;
    pdf.text(`Observaciones: ${tipo.observaciones || 'Sin observaciones'}`, 12, y);
    y += 8;

    if (tipo.kpi) {
      pdf.setTextColor(0, 102, 204).text(`KPI: ${tipo.kpi.titulo}`, 12, y);
      y += 5;
      if (Array.isArray(tipo.kpi.valor)) {
        tipo.kpi.valor.forEach(k => {
          pdf.text(`${k.nombre} | Conductor: ${k.conductor} | Valor: ${k.valor} (${k.estado})`, 12, y);
          y += 5;
        });
      } else {
        pdf.text(`Valor: ${tipo.kpi.valor} | Estado: ${tipo.kpi.texto}`, 12, y);
        y += 5;
      }
      y += 5;
    }
  });

  pdf.setDrawColor(0, 102, 204).line(10, 285, 200, 285);
  pdf.setFontSize(9).setTextColor(150, 150, 150)
    .text("Generado automáticamente por Norte 360° | Sistema Integrado de Gestión Empresarial", 105, 290, null, null, "center");

  pdf.save(`Checklist_Bus_${data.bus.clm_placas_BUS}_${info.fecha}.pdf`);
}






/** 🔧 Mostrar y descargar datos viaje como TXT */

async function registrarViajeDespuesDeMostrar() {
  const datosViaje = mostrarDatosViaje(); // genera objeto datos (sin descargar txt)

  const formData = new FormData();
  formData.append("fecha", datosViaje.fecha_viaje);
  formData.append("hora", datosViaje.hora_viaje);
  formData.append("id_vehiculo", datosViaje.id_vehiculo);
  formData.append("origen", datosViaje.origen);
  formData.append("destino", datosViaje.destino);
  formData.append("conductor1", datosViaje.conductor1);
  formData.append("conductor2", datosViaje.conductor2);
  formData.append("observaciones", datosViaje.observaciones);
  formData.append("transbordo", datosViaje.transbordo);
  formData.append("checklist_ids", JSON.stringify(datosViaje.checklist_ids));
  formData.append("checklist_kpis", JSON.stringify(datosViaje.checklist_kpis));
  formData.append("usuario_id", datosViaje.usuario_id);

  try {
    const response = await fetch('inter_bus/registrar_viaje.php', {
      method: 'POST',
      body: formData
    });
    const data = await response.text();
    console.log("📝 Respuesta de registro:", data);
    alert(data);
  } catch (error) {
    console.error('❌ Error al registrar viaje:', error);
    alert("Error al registrar viaje.");
  }
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

