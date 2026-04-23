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
require_once("../trash/copidb_secure.php");

$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']); // eliminar la variable después de mostrar

?>



<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevistas Registradas | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">      
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
.filtros input[type="checkbox"] {
  transform: scale(1.3);
  cursor: pointer;
}

.main-content {
    margin-left: 240px;
    padding: 30px;
}

.filtros {
  display: flex;
  flex-wrap: nowrap; /* Fuerza en una fila */
  justify-content: center;
  align-items: flex-end;
  gap: 20px;
  flex-wrap: wrap; /* Si se reduce mucho la pantalla, se acomoda debajo */
}

.filtros div {
  display: flex;
  flex-direction: column;
}

.estado-label {
  font-weight: bold;
  padding: 6px 12px;
  border-radius: 20px;
  display: inline-block;
  text-align: center;
}
.fw-bold {
    font-weight: 700 !important;
}
.ms-2 {
    margin-left: .5rem !important;
}

.estado-aceptado {
  background: #27ae60;
  color: white;
}

.estado-rechazado {
  background: #e74c3c;
  color: white;
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
    <p class="texto-popup">¡Entrevista registrada correctamente!</p>
  </div>
</div>

<?php endif; ?>

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
<button class="menu-toggle" onclick="toggleMenu()">☰</button>

<!-- SIDEBAR FIJO EN DESKTOP -->
<nav class="menu-lateral" id="menuLateral">
  <button class="sidebar-toggle-btn" id="btnHideSidebar" aria-label="Ocultar menú">
    <i class="bi bi-chevron-left"></i>
  </button>

  <div class="menu-logo">
    <img src="../img/norte360_black.png" alt="Logo" style="height:40px;">
    <span class="fw-bold ms-2" style="color:#2c3e50;">Norte 360°</span>
  </div>
  <ul class="menu-list">
    <h3>Personal</h3>
    <li><a href="nregrcdn_h.php"><i class="bi bi-person-plus-fill"></i> Nuevo Trabajador</a></li>
    <li><a href="nlaskdrcdn_h.php"><i class="bi bi-search"></i> Buscar Trabajador</a></li>
    <li><a href="trabajadores/ver_personal.php"><i class="bi bi-people-fill"></i> Trabajadores</a></li>
    <li><a href="trabajadores/ver_licencias.php"><i class="bi bi-award-fill"></i> Licencias</a></li>
    <li><a href="trabajadores/ver_cumpleanos.php"><i class="bi bi-calendar2-event-fill"></i> Cumpleaños</a></li>
    <li><a href="trabajadores/ver_cargos.php"><i class="bi bi-briefcase-fill"></i> Cargos</a></li>
    <li><a href="trabajadores/ver_emergencia.php"><i class="bi bi-telephone-inbound-fill"></i> Emergencia</a></li>
    <li><a href="trabajadores/ver_listatrab.php"><i class="bi bi-table"></i> Tabla de Trabajadores</a></li>
    <li><a href="ncapacitaciones.php"><i class="bi bi-table"></i> Capacitaciones</a></li>
    <h3>Entrevistas</h3>
    <li><a href="tbvistacontratadosent.php"><i class="bi bi-file-earmark-person-fill"></i> Solicitud para Trabajador</a></li>
    <h3>Documentación</h3>
    <li><a href="documentacion/generdocuplant.php"><i class="bi bi-journal-plus"></i> Añadir Doc.</a></li>
    <li><a href="documentacion/ver.php"><i class="bi bi-folder2-open"></i> Ver Documentos</a></li>
    <li><a href="documentacion/tipo_docu.php"><i class="bi bi-archive-fill"></i> Tipos Documentos</a></li>
    <!-- Más módulos aquí -->
  </ul>
</nav>
<button class="sidebar-show-btn" id="sidebarShowBtn" aria-label="Mostrar menú">
  <i class="bi bi-chevron-right"></i>
</button>


<div class="main-content">
  <hr>

  <h2>📝 Entrevistas - Solicitud para Trabajadores</h2>

<div class="filtros" style="background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); max-width: 900px; margin: 20px auto;">

  <div>
    <label for="filtroNombre" style="font-weight: bold; color: #2c3e50; display: block; margin-bottom: 6px;">🔎 Nombre</label>
    <input type="text" id="filtroNombre" placeholder="Buscar por nombre" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc;">
  </div>

  <div>
    <label for="filtroPuesto" style="font-weight: bold; color: #2c3e50; display: block; margin-bottom: 6px;">Puesto</label>
    <select id="filtroPuesto" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc;">
      <option value="">Todos los puestos</option>
      <?php
        define('ACCESS_GRANTED', true);
        require_once("../.c0nn3ct/db_securebd2.php");
        $puestos = $conn->query("SELECT DISTINCT puesto FROM entrevistas WHERE puesto IS NOT NULL AND puesto != ''");
        while ($p = $puestos->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($p['puesto']) . "'>" . htmlspecialchars($p['puesto']) . "</option>";
        }
      ?>
    </select>
  </div>

  <div style="display: flex; align-items: center; gap: 10px;">
    <input type="checkbox" id="chkReservas" style="transform: scale(1.3); cursor: pointer;">
    <label for="chkReservas" style="font-weight: bold; color: #2c3e50; cursor: pointer; margin: 0;">Ver reservas</label>
  </div>

</div>



  <div class="tabla-contenedor">

    <table id="tablaEntrevistas">
          <thead>
              <tr>
                  <th>Fecha</th>
                  <th>DNI</th>
                  <th>Nombre</th>
                  <th>Puesto</th>
                  <th>Estado</th>
                  <th>Etapa</th>
                  <th>Acción</th>
              </tr>
          </thead>
          <tbody>
              <!-- Aquí se cargará dinámicamente desde PHP -->
        <?php
        $sql = "SELECT nombre, fecha, hora, puesto, observaciones, dni, sexo, contacto,edad, clm_estado, id_entrevista, clm_yesorno, clm_comentario_entrevistapersonal, clm_comentario_induccion, clm_comentario_contratado, clm_comentario_rechazado, clm_reservas  FROM entrevistas  WHERE clm_estado = 4 ORDER BY fecha DESC";
        $result = $conn->query($sql);
        $totalEntrevistas = $result->num_rows; // ✅ coloca aquí el conteo

        if ($result && $result->num_rows > 0) {
              while($row = $result->fetch_assoc()) {
              $estado = intval($row["clm_estado"]);  // ✅ primero defines el valor
              $estados = [
                  1 => "Selección",
                  2 => "Entrevista presencial",
                  3 => "Inducción",
                  4 => "Solicitud Trabajador",
                  5 => "Trabajador",
              ];
              $estadoTexto = isset($estados[$estado]) ? $estados[$estado] : "Desconocido";
              $estadonumsiguiente = $estado + 1;
              $estadoProximo = isset($estados[$estadonumsiguiente]) ? $estados[$estadonumsiguiente] : "Desconocido";
              $estadoHtml = "";
              if ($row["clm_reservas"] == 1) {
                $estadoHtml = "<span class='estado-label' style='background: gray; color: white;'>Reserva</span>";
              } else {
                if ($row["clm_yesorno"] == 1) {
                  $estadoHtml = "<span class='estado-label estado-aceptado'>Aceptado</span>";
                } else {
                  $estadoHtml = "<span class='estado-label estado-rechazado'>Rechazado</span>";
                }
              }
                  $boton = "<button class='btn-validar' onclick='abrirModal(this)' 
                      data-nombre='" . htmlspecialchars($row["nombre"]) . "'
                      data-fecha='" . htmlspecialchars($row["fecha"]) . "'
                      data-hora='" . htmlspecialchars($row["hora"]) . "'
                      data-dni='" . htmlspecialchars($row["dni"]) . "'
                      data-sexo='" . htmlspecialchars($row["sexo"]) . "'
                      data-contacto='" . htmlspecialchars($row["contacto"]) . "'
                      data-edad='" . htmlspecialchars($row["edad"]) . "'
                      data-puesto='" . htmlspecialchars($row["puesto"]) . "'
                      data-estado='" . htmlspecialchars($row["clm_estado"]) . "'
                      data-estadoTexto='" . htmlspecialchars($estadoTexto) . "'
                      data-estadoProximo='" . htmlspecialchars($estadoProximo) . "'
                      data-id_entrevista='" . htmlspecialchars($row["id_entrevista"]) . "'
                      data-yesorno='" . htmlspecialchars($row["clm_yesorno"]) . "'
                      data-clm_reservas='" . htmlspecialchars($row["clm_reservas"]) . "'
                      data-comentario2='" . htmlspecialchars($row["clm_comentario_entrevistapersonal"]) . "'
                      data-comentario3='" . htmlspecialchars($row["clm_comentario_induccion"]) . "'
                      data-comentario4='" . htmlspecialchars($row["clm_comentario_contratado"]) . "'
                      data-comentarioRechazo='" . htmlspecialchars($row["clm_comentario_rechazado"]) . "'
                      data-observaciones='" . htmlspecialchars($row["observaciones"]) . "'>📄 Ver Detalle</button>";



                  echo "<tr>
                      <td>" . htmlspecialchars($row["fecha"]) . "</td>
                      <td>" . htmlspecialchars($row["dni"]) . "</td>
                      <td>" . htmlspecialchars($row["nombre"]) . "</td>
                      <td>" . htmlspecialchars($row["puesto"]) . "</td>
                      <td>$estadoHtml</td>
                      <td>$estadoTexto</td>
                      <td>$boton</td>
                  </tr>";
              }

        } else {
          echo "<tr><td colspan='5' style='text-align:center;'>No se encontraron entrevistas.</td></tr>";
        }
        $conn->close();
        ?>
          </tbody>
      </table>
  </div>

  <div class="pagination" id="paginacion"></div>


  <div id="modalDetalle" class="modal">
    <div class="modal-content">
      <span class="cerrar" onclick="cerrarModal()">&times;</span>
      <p id="contenidoModal">Aquí irá el detalle del entrevistado.</p>

  <div id="radio_opciones">
    <label><input type="radio" name="decision" value="aceptado" onclick="toggleEvaluacion(true)"> ✅ ACEPTADO</label>
    <label style="margin-left: 20px;"><input type="radio" name="decision" value="rechazado" onclick="toggleEvaluacion(false)"> ❌ RECHAZADO</label>
  </div>

  <div id="mensaje_rechazado" style="display: none; color: #c0392b; font-weight: bold; font-size: 18px; text-align: center; margin: 20px 0;">
    ⚠️ Este postulante ha sido RECHAZADO
  </div>

  <div id="contenedor_interaccion">

      <div id="bloque_estado">

          <form id="formAprobacion" onsubmit="guardarEstado(event)">
            <hr>
  <div class="campo-form">
    <label for="estadoSelect"><b>📌 Estado de Evaluación</b></label>
    <select id="estadoSelect" name="estado" required class="input-evaluacion">
      <option value="">Selecciona una opción</option>
    </select>
  </div>

  <div class="campo-form">
    <label for="comentario" style="margin-top: 10px;"><b>🗒️ Comentario</b></label>
    <textarea id="comentario" name="comentario" rows="4" class="input-evaluacion" placeholder="Agrega una evaluación de esta etapa..."></textarea>
  </div>


              <input type="hidden" id="id_entrevistaSeleccionado" name="id_entrevista">

            <button type="submit" class="btn-validar" style="margin-top: 15px;">💾 Guardar Evaluación</button>
          </form>
      </div>
          <input type="hidden" id="clm_yesorno" name="clm_yesorno" value="1">

          <div id="bloque_rechazo" style="display:none; margin-top: 20px;">
            <label for="comentario_rechazo"><b>📝 Motivo del Rechazo:</b></label>
            <textarea id="comentario_rechazo" rows="3" style="width: 100%; padding: 10px; border-radius: 8px;"></textarea>
            <button type="button" class="btn-validar" onclick="rechazarEntrevista()">❌ Confirmar Rechazo</button>
          </div>

    </div>
  </div>

  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const filtroNombre = document.getElementById("filtroNombre");
      const filtroPuesto = document.getElementById("filtroPuesto");
      const filas = document.querySelectorAll("#tablaEntrevistas tbody tr");

      function filtrar() {
        const nombre = filtroNombre.value.toLowerCase();
        const puesto = filtroPuesto.value.toLowerCase();

        filas.forEach(fila => {
          const tdNombre = fila.cells[2].textContent.toLowerCase();
          const tdPuesto = fila.cells[3].textContent.toLowerCase();

          const coincideNombre = tdNombre.includes(nombre);
          const coincidePuesto = puesto === "" || tdPuesto === puesto;

          fila.style.display = (coincideNombre && coincidePuesto) ? "" : "none";
        });

        mostrarPagina(1); // ✅ recarga la paginación filtrada
      }


      filtroNombre.addEventListener("input", filtrar);
      filtroPuesto.addEventListener("change", filtrar);
    });
  </script>

  <script>
function mostrarPagina(pagina) {
  const filasVisibles = Array.from(filas).filter(f => f.style.display !== "none");
  const inicio = (pagina - 1) * filasPorPagina;
  const fin = inicio + filasPorPagina;

  filasVisibles.forEach((fila, i) => {
    fila.style.display = (i >= inicio && i < fin) ? "" : "none";
  });

  paginacion.innerHTML = "";
  const totalPaginas = Math.ceil(filasVisibles.length / filasPorPagina);

  for (let i = 1; i <= totalPaginas; i++) {
    const boton = document.createElement("a");
    boton.href = "#";
    boton.textContent = i;
    boton.style.margin = "0 5px";
    if (i === pagina) {
      boton.style.fontWeight = "bold";
      boton.style.textDecoration = "underline";
    }
    boton.addEventListener("click", function (e) {
      e.preventDefault();
      mostrarPagina(i);
    });
    paginacion.appendChild(boton);
  }
}


  </script>

  <script>
  function abrirModal(boton) {
    const data = {
      yesorno: boton.getAttribute("data-yesorno"),
      estadoTexto: boton.getAttribute("data-estadoTexto"),
      estadoProximo: boton.getAttribute("data-estadoProximo"),
      id_entrevista: boton.getAttribute("data-id_entrevista"),
      nombre: boton.getAttribute("data-nombre"),
      fecha: boton.getAttribute("data-fecha"),
      hora: boton.getAttribute("data-hora"),
      dni: boton.getAttribute("data-dni"),
      sexo: boton.getAttribute("data-sexo"),
      contacto: boton.getAttribute("data-contacto"),
      edad: boton.getAttribute("data-edad"),
      puesto: boton.getAttribute("data-puesto"),
      estado: boton.getAttribute("data-estado"),
      observaciones: boton.getAttribute("data-observaciones"),
      comentario2: boton.getAttribute("data-comentario2"),
      comentario3: boton.getAttribute("data-comentario3"),
      comentario4: boton.getAttribute("data-comentario4"),
      reservas : boton.getAttribute("data-clm_reservas"),
      comentarioRechazo: boton.getAttribute("data-comentarioRechazo"),
    };

    const filaReserva = (data.reservas === "1") 
      ? `<tr>
          <th>Reserva</th>
          <td style="background: gray; color:white; font-weight:bold; text-align:center;">
            Reservado
          </td>
        </tr>`
      : "";

    const btnReserva = (data.reservas === "1")
      ? `<button onclick="actualizarReserva(${data.id_entrevista}, 'quitar')" 
                class="btn-validar" 
                style="background:#e74c3c; max-width:250px; margin:auto; display:block;">
          Quitar Reserva
        </button>`
      : `<button onclick="actualizarReserva(${data.id_entrevista}, 'reservar')" 
                class="btn-validar" 
                style="max-width:250px; margin:auto; display:block;">
          Marcar Reserva
        </button>`;


    const contenido = `

    <div style="display: flex; justify-content: center; align-items: center; position: relative; margin-top: 10px; margin-bottom: 20px;">
      <h2 style="margin: 0; text-align: center; flex: 1;">📄 Entrevista N°${data.id_entrevista}</h2>
      <div style="position: absolute; left: 0;">
        ${btnReserva}
      </div>
    </div>



    <h3>📅 ${data.fecha} ⏰ ${data.hora}</h3>

      <table>
        <tr><th>👤 Nombre</th><td>${data.nombre}</td></tr>
        <tr><th>DNI</th><td>${data.dni}</td></tr>
        <tr><th>Sexo</th><td>${data.sexo}</td></tr>
        <tr><th>Edad</th><td>${data.edad}</td></tr>
        <tr><th>Contacto</th><td>${data.contacto}</td></tr>
        <tr><th>📝 Observaciones</th><td>${data.observaciones}</td></tr>
        ${filaReserva}
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
    ${data.yesorno === "2" 
      ? `<span style="font-size: 20px;">📊</span> Etapa actual: ${data.estadoTexto} | Esta entrevista fue rechazada ❌`
      : `<span style="font-size: 20px;">📊</span> Etapa actual: ${data.estadoTexto} | Etapa siguiente: ${data.estadoProximo}`
    }

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
    4: "Solicitud Trabajador"
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

//  Insertar botón solo si estado es Trabajador (5)
if (data.estado === "4") {
  const btnContratar = document.createElement("div");
  btnContratar.innerHTML = `
    <div style="margin-top: 20px; text-align: center;">
      <button onclick="contratarTrabajador()" class="btn-validar" style="background:#27ae60;">
         Registrar como Trabajador
      </button>
    </div>
  `;
  document.getElementById("contenidoModal").appendChild(btnContratar);
}

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
  <li style="background: #ecf0f1; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #27ae60; border-radius: 8px;">
    <strong>🟢 Solicitud Trabajador:</strong> ${data.comentario4 || 'Sin comentario'}
  </li>`;

  if (data.yesorno === "2") historialComentarios.innerHTML += `
  <li style="background: #fdecea; margin-bottom: 10px; padding: 10px 15px; border-left: 4px solid #e74c3c; border-radius: 8px;">
    <strong>❌ Rechazo:</strong> ${data.comentarioRechazo || 'Sin detalle registrado.'}
  </li>`;




    document.getElementById("modalDetalle").style.display = "block";


// === Nueva lógica ===
if (data.estado === "5") {
  // Estado Trabajador: ocultar TODO lo de evaluación
  document.getElementById("radio_opciones").style.display = "none";
  document.getElementById("contenedor_interaccion").style.display = "none";
  document.getElementById("mensaje_rechazado").style.display = "none";

} else if (data.estado === "4") {
  // Estado Solicitud Trabajador: ocultar SOLO el radio de Aceptado/Rechazado y todo el bloque
  document.getElementById("radio_opciones").style.display = "none";
  document.getElementById("contenedor_interaccion").style.display = "none";
  document.getElementById("mensaje_rechazado").style.display = "none";

} else if (data.yesorno === "2") {
  // Fue rechazado
  document.getElementById("radio_opciones").style.display = "none";
  document.getElementById("contenedor_interaccion").style.display = "none";
  document.getElementById("mensaje_rechazado").style.display = "block";

} else {
  // Cualquier otro estado (1,2,3)
  document.getElementById("mensaje_rechazado").style.display = "none";
  document.getElementById("contenedor_interaccion").style.display = "block";
  document.getElementById("radio_opciones").style.display = "block";
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
    //  console.log("📥 Respuesta del servidor:", JSON.stringify(data));  <- esto sí o sí debe salir en consola
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

function contratarTrabajador() {
  const id = document.getElementById("id_entrevistaSeleccionado").value;

  const boton = document.querySelector(`button[data-id_entrevista='${id}']`);
  const data = {
    nombre: boton.getAttribute("data-nombre"),
    dni: boton.getAttribute("data-dni"),
    sexo: boton.getAttribute("data-sexo"),
    celular: boton.getAttribute("data-contacto"),
    cargo: boton.getAttribute("data-puesto"),
    id_entrevista: id
  };

  const form = document.createElement("form");
  form.method = "POST";
  form.action = "../php/contratar_trabajador.php";

  for (const clave in data) {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = clave;
    input.value = data[clave];
    form.appendChild(input);
  }

  document.body.appendChild(form);
  form.submit();
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
document.addEventListener("DOMContentLoaded", function () {
  const filtroNombre = document.getElementById("filtroNombre");
  const filtroPuesto = document.getElementById("filtroPuesto");
  const chkReservas = document.getElementById("chkReservas");
  const filas = document.querySelectorAll("#tablaEntrevistas tbody tr");

  function filtrar() {
    const nombre = filtroNombre.value.toLowerCase();
    const puesto = filtroPuesto.value.toLowerCase();
    const soloReservas = chkReservas.checked;

    filas.forEach(fila => {
      const tdNombre = fila.cells[2].textContent.toLowerCase();
      const tdPuesto = fila.cells[3].textContent.toLowerCase();
      const btn = fila.querySelector("button[data-clm_reservas]");
      const esReserva = btn && btn.getAttribute("data-clm_reservas") === "1";

      const coincideNombre = tdNombre.includes(nombre);
      const coincidePuesto = puesto === "" || tdPuesto === puesto;
      const coincideReserva = !soloReservas || esReserva;

      fila.style.display = (coincideNombre && coincidePuesto && coincideReserva) ? "" : "none";
    });

    mostrarPagina(1);
  }

  filtroNombre.addEventListener("input", filtrar);
  filtroPuesto.addEventListener("change", filtrar);
  chkReservas.addEventListener("change", filtrar);
});

function actualizarReserva(id, accion) {
  const confirmMsg = accion === "quitar"
    ? "¿Quitar la reserva? Esto la marcará como 0."
    : "¿Marcar esta persona como reservada?";

  if (!confirm(confirmMsg)) return;

  fetch("../php/actualizar_reserva.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `id_entrevista=${id}&accion=${accion}`
  })
  .then(response => response.text())
  .then(data => {
    alert(data);
    cerrarModal();
    location.reload();
  })
  .catch(error => {
    alert("❌ Error al actualizar reserva.");
    console.error(error);
  });
}



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

  <!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
  <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
      <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
  </a>
  </div>

        <hr>
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
</body>


</html>

