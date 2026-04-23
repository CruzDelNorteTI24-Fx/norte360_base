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
    $vista_actuales = ["c-limp", "c-sab", "c-lalu"];

    if (!in_array($modulo_actual, $_SESSION['permisos']) || empty(array_intersect($vista_actuales, $_SESSION['vistas']))) {
        header("Location: ../../login/none_permisos.php");
        exit();
    }
}

define('ACCESS_GRANTED', true);
require_once("../trash/copidb_secure.php");
require_once("../.c0nn3ct/db_securebd2.php");
// conexión y función SOLO aquí
require_once("funciones_trabajador.php");
$conductores = obtenerConductores($conn);

$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);
$id_checklist = $_GET['id'] ?? null;

if (!$id_checklist) {
    die("Checklist no especificado.");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mantenimiento | Norte 360°</title>
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
    margin-left: 240px;
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
.checklist-item {
  background: white;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 15px 20px;
  margin-bottom: 15px;
  box-shadow: 0 4px 8px rgba(0,0,0,0.05);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.checklist-item p {
  font-weight: bold;
  color: #2c3e50;
  margin: 0;
}

.checklist-options {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
}

.checklist-options label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 500;
  color: #34495e;
  cursor: pointer;
}
.card {
  border-left: 5px solid #2980b9;
}
.card h3 {
  color: #2980b9;
  margin-top: 20px;
}
.card p {
  margin: 6px 0;
  font-size: 15px;
}

.card h2 {
  text-align: left;
  color: #2c3e50;
  margin-bottom: 20px;
  border-bottom: 2px solid #3498db;
  padding-bottom: 10px;
}

.card h3 {
  font-size: 17px;
  margin-top: 0;
}

.card strong {
  color: #34495e;
}
input[type=radio] {
  transform: scale(1.2);
}
.btn-cancelar {
    background: #c0392b;
    color: white;
    padding: 12px 24px;
    font-size: 16px;
    border: none;
    border-radius: 8px;
    text-decoration: none;
    display: inline-block;
    margin-left: 10px;
}

.btn-cancelar:hover {
    background: #922b21;
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
            echo "<li><a href='limpieza/mantcdn.php?id_tipo=4'>➕ Nueva Fumigación</a></li>";
          echo "</ul>";
        }
        if ($_SESSION['web_rol'] == 'Admin' || in_array("c-sab", $vistas)) {
          echo "<h3>Generar CheckList</h3>";

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
        if (in_array("c-sab", $vistas)) {
          echo "<li><a href='interbus_vld.php'>Generar Ruta</a></li>";
        }   
    ?>
  </ul>
</div>



<div class="main-content">
  <hr>

    <?php  // Obtener datos del checklist y vehículo


      $stmt_datos = $conn->prepare("SELECT c.*,
            p.clm_placas_placa, p.clm_placas_dueño, p.clm_placas_bus, p.clm_placas_tipo_vehículo, p.clm_placas_servicio,
            t.clm_checktip_nombre
          FROM tb_checklist_limpieza c
          LEFT JOIN tb_placas p ON c.clm_checklist_id_bus = p.clm_placas_id
          LEFT JOIN tb_checklist_tipos t ON c.clm_checklist_idtipo = t.clm_checktip_id

          WHERE c.clm_checklist_id = ?");
      $stmt_datos->bind_param("i", $id_checklist);
      $stmt_datos->execute();
      $res_datos = $stmt_datos->get_result();
      $datos = $res_datos->fetch_assoc();


      
      $is_cerrado = ($datos['clm_checklist_fecha'] != date('Y-m-d'));

      $stmt_datos->close();
    ?>

<div class="card">
  <div style="display:inline-block; padding:8px 16px; background:linear-gradient(90deg, #3498db, #2980b9); color:white; font-weight:bold; border-radius:30px; box-shadow:0 4px 10px rgba(0,0,0,0.1); font-size:16px; margin-bottom:10px;">
      <?= htmlspecialchars($datos['clm_checktip_nombre']) ?>
  </div>
  <h2>Datos del Checklist</h2>
  <div style="display: flex; flex-wrap: wrap; gap: 30px;">
    <div style="flex: 1; min-width: 200px;">
      <h3 style="color: #2980b9; margin-bottom: 10px;">Información General</h3>

  <?php
      if ($_SESSION['DNI'] == '72953637') {
          echo "<p><strong>ID Checklist:</strong> ".htmlspecialchars($datos['clm_checklist_id'])."</p>";
      }
  ?>
      <p><strong>Correlativo Checklist:</strong> <?= htmlspecialchars($datos['clm_checklist_corr']) ?></p>
      <p><strong>Fecha:</strong> <?= htmlspecialchars($datos['clm_checklist_fecha']) ?></p>
      <p><strong>Estado:</strong> <?= htmlspecialchars($datos['clm_checklist_estado']) ?></p>
      <p><strong>Estado Actual:</strong> 
        <span style="display:inline-block; padding:4px 10px; border-radius:20px; font-weight:bold; color:white; background:<?= $is_cerrado ? '#7f8c8d' : '#27ae60' ?>;">
          <?= $is_cerrado ? 'CERRADO' : 'ABIERTO' ?>
        </span>
      </p>

    </div>

    <div style="flex: 1; min-width: 200px;">
      <h3 style="color: #2980b9; margin-bottom: 10px;">Vehículo</h3>
      <p><strong>Placa:</strong> <?= htmlspecialchars($datos['clm_placas_placa']) ?></p>
      <p><strong>Número:</strong> <?= htmlspecialchars($datos['clm_placas_bus']) ?></p>
      <p><strong>Servicio:</strong> <?= htmlspecialchars($datos['clm_placas_servicio']) ?></p>
    </div>
  </div>
</div>


<form id="form-checklist-update" action="limpieza/checklist/guardar_checklist_update.php" method="POST" enctype="multipart/form-data" style="max-width:800px; margin:40px auto; background:white; border-radius:12px; padding:30px; box-shadow:0 4px 12px rgba(0,0,0,0.08);" <?= $is_cerrado ? 'class="form-cerrado"' : '' ?>>
  <input type="hidden" name="id_checklist" value="<?= $id_checklist ?>">
  <h2 style="text-align:center; color:#2c3e50; margin-bottom:30px;">Llenado de Checklist</h2>

  <?php
    // Obtener categorías
    $sql_cat = "SELECT * FROM tb_categorias_checklist WHERE clm_categorias_estado = 'activo' ORDER BY clm_categoria_id";
    $res_cat = $conn->query($sql_cat);
    $contador_pregunta = 1;

    while ($cat = $res_cat->fetch_assoc()) {
        // Obtener items de la categoría
      $stmt_items = $conn->prepare("SELECT i.clm_item_id, i.clm_item_nombre, i.clm_items_tipo, r.clm_resultados_obs, r.clm_resultados_id_user, r.clm_resultado_estado, r.clm_resultado_dfecd, r.clm_rescheck_conductor1, r.clm_rescheck_porcentaje1, r.clm_rescheck_imagen, r.clm_resultado_fecharegistro 
          FROM tb_items_checklist i
          LEFT JOIN tb_resultados_checklist r
          ON i.clm_item_id = r.clm_resultado_id_item AND r.clm_resultado_id_checklist = ?
          WHERE i.clm_item_id_categoria = ? 
          AND i.clm_item_estado = 'activo'
          AND i.clm_item_idtipocheck = ?");
      $stmt_items->bind_param("iii", $id_checklist, $cat['clm_categoria_id'], $datos['clm_checklist_idtipo']);

      $stmt_items->execute();
      $res_items = $stmt_items->get_result();
      if ($res_items->num_rows > 0) {
          // Mostrar categoría solo si hay ítems
          echo "<div style='margin-bottom:30px;'>
          <h3 style='color:#2980b9; border-bottom:2px solid #3498db; padding-bottom:6px; margin-bottom:20px;'>".htmlspecialchars($cat['clm_categoria_nombre'])."</h3>";

          
        while ($item = $res_items->fetch_assoc()) {
            switch ($item['clm_items_tipo']) {
              case 'R':
                $estado = $item['clm_resultado_estado'] ?? '';
                break;
              case 'E':
                $estado = $item['clm_resultado_estado'] ?? '';
                break;
              case 'Q':
                $estado = $item['clm_resultado_estado'] ?? '';
                break;
              case 'H':
                $estado = $item['clm_resultado_dfecd'] ?? '';
                break;
              case 'T':
                $estado = $item['clm_rescheck_conductor1'] ?? '';
                break;
              case 'O':
                $estado = $item['clm_rescheck_conductor1'] ?? '';
                break;
              case 'N':
                $estado = $item['clm_rescheck_porcentaje1'] ?? '';
                break;
              case 'F':
                $estado = $item['clm_rescheck_imagen'] ?? '';
                break;
              case 'D':
                $estado = $item['clm_rescheck_doc'] ?? '';
                break;
              default:
                $estado = '';
            }
            $obs = $item['clm_resultados_obs'] ?? '';
            $id_usuario = $item['clm_resultados_id_user'] ?? '';
            $fecha_hora_registro = $item['clm_resultado_fecharegistro'] ?? 'No registrado';

            echo "<div style='margin-bottom:20px; padding:15px 20px; border:1px solid #ddd; border-radius:10px;'>
                <p style='font-weight:bold; color:#2c3e50; margin:0 0 10px 0;'>".$contador_pregunta.". ".htmlspecialchars($item['clm_item_nombre'])."</p>";
  


          switch ($item['clm_items_tipo']) {
            case 'R': // Radio
              echo "<div class='checklist-options'>";
              // Opciones como botones elegantes
              $options = ['C'=>'Cumple', 'NC'=>'No Cumple', 'NA'=>'No Aplica'];
              foreach ($options as $val => $label) {
                $checked = ($estado==$val) ? 'checked' : '';
                $disabled = $is_cerrado ? 'disabled' : '';
                    echo "<label style='display:flex; align-items:center; gap:6px; font-weight:500; color:#34495e; cursor:pointer; background:".($checked?'#3498db':'#ecf0f1')."; color:".($checked?'white':'#34495e')."; padding:8px 12px; border-radius:6px; transition:all 0.3s;'>
                            <input type='radio' name='item_".$item['clm_item_id']."' value='$val' style='accent-color:#3498db;' $checked $disabled required>
                          $label

                          </label>";        
              }

              echo "</div>";
              break;

            case 'E': // Radio tipo Propio o Tercerizado
              echo "<div class='checklist-options'>";
              // Opciones como botones elegantes
              $options = ['P'=>'Propio', 'T'=>'Tercerizado'];
              foreach ($options as $val => $label) {
                $checked = ($estado==$val) ? 'checked' : '';
                $disabled = $is_cerrado ? 'disabled' : '';
                    echo "<label style='display:flex; align-items:center; gap:6px; font-weight:500; color:#34495e; cursor:pointer; background:".($checked?'#3498db':'#ecf0f1')."; color:".($checked?'white':'#34495e')."; padding:8px 12px; border-radius:6px; transition:all 0.3s;'>
                            <input type='radio' name='item_".$item['clm_item_id']."' value='$val' style='accent-color:#3498db;' $checked $disabled required>
                          $label

                          </label>";        
              }

              echo "</div>";
              break;

            case 'Q': // Radio tipo Propio o Tercerizado
              echo "<div class='checklist-options'>";
              // Opciones como botones elegantes
              $options = ['Q'=>'Requiere', 'NQ'=>'No Requiere', 'NP'=>'No Aplica'];
              foreach ($options as $val => $label) {
                $checked = ($estado==$val) ? 'checked' : '';
                $disabled = $is_cerrado ? 'disabled' : '';
                    echo "<label style='display:flex; align-items:center; gap:6px; font-weight:500; color:#34495e; cursor:pointer; background:".($checked?'#3498db':'#ecf0f1')."; color:".($checked?'white':'#34495e')."; padding:8px 12px; border-radius:6px; transition:all 0.3s;'>
                            <input type='radio' name='item_".$item['clm_item_id']."' value='$val' style='accent-color:#3498db;' $checked $disabled required>
                          $label

                          </label>";        
              }

              echo "</div>";
              break;


            case 'H': // Fecha y Hora
              $disabled = $is_cerrado ? 'readonly style="background:#e0e0e0;"' : '';
              $valor = $estado ? date('Y-m-d\TH:i', strtotime($estado)) : date('Y-m-d\TH:i'); // formatea valor
              echo "<input type='datetime-local' name='item_".$item['clm_item_id']."' class='input-evaluacion' value='".htmlspecialchars($valor, ENT_QUOTES)."' $disabled>";
              break;


            case 'T': // Lista de Conductores


              $disabled = $is_cerrado ? 'disabled' : '';
              echo "<select name='item_".$item['clm_item_id']."' class='input-evaluacion' $disabled>";
              echo "<option value=''>-- Seleccionar conductor --</option>";

              foreach ($conductores as $con) {
                  $nombre = $con['nombres'];
                  $dni = $con['dni'];
                  $selected = ($estado == $nombre) ? 'selected' : '';
                  echo "<option value='".htmlspecialchars($nombre, ENT_QUOTES)."' $selected>";
                  echo htmlspecialchars($nombre)." - DNI: ".htmlspecialchars($dni);
                  echo "</option>";
              }

              echo "</select>";
              break;

            case 'O': // Texto Libre
              $disabled = $is_cerrado ? 'readonly style="background:#e0e0e0;"' : '';
              echo "<input type='text' name='item_".$item['clm_item_id']."' class='input-evaluacion' placeholder='Respuesta de texto' value='".htmlspecialchars($estado, ENT_QUOTES)."' $disabled>";
              break;

            case 'N': // Número
              $disabled = $is_cerrado ? 'readonly style="background:#e0e0e0;"' : '';
              echo "<input type='number' step='0.01' name='item_".$item['clm_item_id']."' class='input-evaluacion' placeholder='Ingrese el porcentaje' value='".htmlspecialchars($estado, ENT_QUOTES)."' $disabled>";
              break;

            case 'F': // Foto
              echo "<div class='img-block' id='preview_block_".$item['clm_item_id']."'>";
              if ($estado) {
                echo "<img id='preview_img_".$item['clm_item_id']."' src='data:image/jpeg;base64,".base64_encode($estado)."' alt='Foto'>";
              } else {
                echo "<p class='no-image' id='preview_img_".$item['clm_item_id']."'>Sin foto registrada.</p>";
              }
              echo "</div>";


              if (!$is_cerrado) {
                echo "

                <div style='margin-top:10px; text-align:center;'>
                  <label for='item_".$item['clm_item_id']."' style='display:inline-block; background:#2980b9; color:white; padding:10px 20px; border-radius:8px; cursor:pointer; transition:background 0.3s;'>
                    📷 Subir Imagen
                  </label>
                  <input type='file' id='item_".$item['clm_item_id']."' name='item_".$item['clm_item_id']."' accept='image/*' style='display:none;'>
                </div>
                <h2 style='color: GRAY; font-size: 16px; text-align: center; margin: 10px 0;'>
                  Nota: Asegúrate de volver a seleccionar tu imagen antes de guardar.
                </h2>
                ";
              }
              break;




            case 'D': // Documento
              if ($is_cerrado) {
                if ($estado) {
                  echo "<p><a href='../uploads/checklist_docs/".htmlspecialchars($estado)."' target='_blank' class='btn'>Ver Documento</a></p>";
                } else {
                  echo "<p class='no-image'>Sin documento registrado.</p>";
                }
              } else {
                echo "<input type='file' name='item_".$item['clm_item_id']."' accept='.pdf,.doc,.docx' required>";
              }
              break;

            default:
              echo "<p style='color:red;'>Tipo no definido.</p>";
              break;
          }

            //Nuevo: campo de Observaciones
            echo "
            <div style='margin-top:10px;'>
              <label style='font-weight:bold; color:#2c3e50;'>
                <input type='checkbox' onclick=\"toggleObs('obs_".$item['clm_item_id']."')\"> Observaciones
              </label>
              <input type='text' id='obs_".$item['clm_item_id']."' name='obs_".$item['clm_item_id']."' class='input-evaluacion' placeholder='Observaciones del ítem' value='".htmlspecialchars($obs, ENT_QUOTES)."' style='display:none;' ".($is_cerrado ? 'readonly style="background:#e0e0e0;"' : '').">
            ";

            //Nuevo: campo oculto con el ID de usuario que registra
            echo "<input type='hidden' name='user_".$item['clm_item_id']."' value='".$_SESSION['id_usuario']."'>";
            if ($_SESSION['web_rol'] == 'Admin') {
              
                echo "<p style='font-size:14px; color:#888; margin-top:6px;'><strong>ID Usuario:</strong> ".htmlspecialchars($id_usuario)."</p>";
                echo "<p style='font-size:14px; color:#888; margin-top:6px;'><strong>Fecha y Hora del registro:</strong> ".htmlspecialchars($fecha_hora_registro)."</p>";
            }
            echo "</div></div>";
        $contador_pregunta++;
        }
        
        echo "</div>";
      }

      $stmt_items->close();
    }
  ?>

<div class="campo-form">
  <input type="hidden" id="fecha_hora" name="fecha_hora" value="<?= date('Y-m-d\TH:i') ?>" required>
</div>
<?php if (!$is_cerrado): ?>

  <a href="lista_cheklist.php" class="btn-cancelar">No guardar y Volver</a>

  <button type="submit" class="btn-validar" style="margin-top:20px;">💾 Guardar Cambios</button>
<?php endif; ?>

</form>

<script>
document.getElementById('item_<?= $item['clm_item_id'] ?>').addEventListener('change', function(e) {
  const file = e.target.files[0];
  const preview = document.getElementById('preview_img_<?= $item['clm_item_id'] ?>');

  if (file && file.type.startsWith('image/')) {
    const reader = new FileReader();
    reader.onload = function(event) {
      if (preview.tagName.toLowerCase() === 'img') {
        preview.src = event.target.result;
      } else {
        const img = document.createElement('img');
        img.src = event.target.result;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '180px';
        img.style.borderRadius = '6px';
        img.style.boxShadow = '0 2px 6px rgba(0,0,0,0.1)';
        const container = document.getElementById('preview_block_<?= $item['clm_item_id'] ?>');
        container.innerHTML = '';
        container.appendChild(img);
      }
    };
    reader.readAsDataURL(file);
  }
});
</script>

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
function toggleObs(id) {
  const campo = document.getElementById(id);
  if (campo.style.display === "none") {
    campo.style.display = "block";
  } else {
    campo.style.display = "none";
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
document.addEventListener("DOMContentLoaded", function() {
  const form = document.getElementById("form-checklist-update");
  const modal = document.getElementById("modal-cargando");

  if(form){ // Verifica que el formulario exista
    form.addEventListener("submit", function() {
      modal.style.display = "flex"; // ✅ muestra el modal al guardar
    });
  }
});
</script>

</body>



</html>

