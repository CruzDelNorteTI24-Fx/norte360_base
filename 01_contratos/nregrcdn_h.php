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

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

define('ACCESS_GRANTED', true);
require_once("../trash/copidb_secure.php");

$datos_pre = $_SESSION['prellenar_trabajador'] ?? null;
unset($_SESSION['prellenar_trabajador']);

$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']); // eliminar la variable después de mostrar


?>



<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Trabajador | Norte 360°</title>
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
            margin-bottom: 15px;
        }
      .campo-form select {
        background-color: #fff;
        border: 1px solid #ccc;
        border-radius: 8px;
        padding: 12px;
        font-size: 15px;
        color: #2c3e50;
        transition: border 0.3s ease, box-shadow 0.3s ease;
        width: 100%;
        appearance: none; /* elimina flecha fea en algunos navegadores */
        background-image: url('data:image/svg+xml;utf8,<svg fill="%233498db" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/></svg>');
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 18px 18px;
        padding-right: 38px; /* espacio para ícono flecha */
        box-sizing: border-box;
      }

      .campo-form select:focus {
        border-color: #3498db;
        box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
        outline: none;
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
        table {
            width: 90%;
            margin: auto;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            overflow: hidden;
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
            display: block;
            width: fit-content;
            margin: 30px auto 0;
            background: #2980b9;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
        }

        .volver-btn:hover {
            background: #1c5980;
        }
        .radio-group {
  display: flex;
  gap: 20px;
  margin-top: 8px;
  flex-wrap: wrap;
}

.radio-group input[type="radio"] {
  display: none;
}

.radio-label {
  padding: 10px 20px;
  border-radius: 20px;
  background: #ecf0f1;
  color: #2c3e50;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  border: 2px solid transparent;
  user-select: none;
}

.radio-group input[type="radio"]:checked + .radio-label {
  background: linear-gradient(90deg, #2980b9, #3498db);
  color: white;
  border: 2px solid #2980b9;
  box-shadow: 0 2px 10px rgba(41, 128, 185, 0.3);
  transform: scale(1.05);
}
.custom-file-upload {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  background: #ecf0f1;
  border: 2px dashed #bdc3c7;
  border-radius: 10px;
  transition: background 0.3s ease;
}

.upload-label {
  padding: 10px 16px;
  background: #2980b9;
  color: white;
  font-weight: bold;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.3s ease;
}

.upload-label:hover {
  background: #1f5e87;
}

.nombre-archivo {
  font-size: 14px;
  color: #34495e;
  font-style: italic;
}

input[type="file"] {
  display: none;
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
.preview-container {
  position: relative;
  border: 2px dashed #bdc3c7;
  padding: 10px;
  border-radius: 10px;
  background: #ecf0f1;
  cursor: pointer;
  text-align: center;
}

.img-preview {
  position: relative;
  width: 100%;
  height: 160px;
  border-radius: 8px;
  overflow: hidden;
  background: #dfe6e9;
  display: flex;
  justify-content: center;
  align-items: center;
}

.img-preview img {
  max-width: 100%;
  max-height: 100%;
  display: block;
  border-radius: 6px;
}

.texto-previo {
  color: #7f8c8d;
  font-style: italic;
}

.boton-cancelar {
  position: absolute;
  top: 6px;
  right: 8px;
  background: rgba(0, 0, 0, 0.6);
  color: white;
  border: none;
  border-radius: 50%;
  font-size: 16px;
  width: 24px;
  height: 24px;
  cursor: pointer;
  line-height: 20px;
  text-align: center;
}




.no-familiares {
  font-style: italic;
  color: #7f8c8d;
  padding: 8px;
  text-align: center;
  background: #ecf0f1;
  border-radius: 6px;
}

.fila-familiar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 12px;
  flex-wrap: wrap;
  background: #f9f9f9;
  padding: 12px 10px;
  border-radius: 10px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}

.fila-familiar .campo-form input,
.fila-familiar .campo-form select {
  width: 100%;
  margin-bottom: 0;
}

.fila-familiar .campo-form {
  flex: 1;
  min-width: 180px;
}

.fila-familiar {
  display: flex;
  gap: 15px;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 12px;
  background: #f9f9f9;
  padding: 12px;
  border-radius: 10px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}

.btn-eliminar-familiar:hover {
  background: #c0392b;
  transform: scale(1.05);
}

.btn-agregar-familiar {
  background: #3498db;
  color: white;
  font-weight: bold;
  padding: 10px;
  border: none;
  border-radius: 8px;
  margin-top: 12px;
  width: 100%;
  transition: background 0.3s ease;
}

.btn-agregar-familiar:hover {
  background: #1f618d;
}
.fila-familiar select {
  background-color: #fff;
  border: 1px solid #ccc;
  border-radius: 8px;
  padding: 12px;
  font-size: 15px;
  color: #2c3e50;
  transition: border 0.3s ease, box-shadow 0.3s ease;
  width: 100%;
  appearance: none; /* elimina flecha fea en algunos navegadores */
  background-image: url('data:image/svg+xml;utf8,<svg fill="%233498db" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/></svg>');
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 18px 18px;
  padding-right: 38px; /* espacio para ícono flecha */
  box-sizing: border-box;
}

.fila-familiar select:focus {
  border-color: #3498db;
  box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
  outline: none;
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

#progreso-registro .paso {
  margin-bottom: 10px;
  color: #2c3e50;
  font-size: 15px;
}

#progreso-registro .paso .estado {
  font-size: 13px;
  color: #888;
  margin-left: 6px;
}

#progreso-registro a {
  text-decoration: none;
  color: inherit;
}
#progreso-registro a:hover {
  text-decoration: underline;
  color: #2980b9;
}
@media (max-width: 900px) {
  #progreso-registro {
    position: static;
    width: auto;
    margin-bottom: 20px;
  }

  main {
    padding-left: 0 !important;
  }
}
#progreso-registro .paso.activo {
  background: #ecf0f1;
  border-radius: 6px;
  padding: 4px 8px;
}
#progreso-registro .paso.desactivado a {
  color: gray !important;
  text-decoration: none;
  cursor: not-allowed;
}
.btn-validar-mini {
  background: #3498db;
  color: white;
  padding: 8px 12px;
  font-size: 14px;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  transition: background 0.3s, transform 0.2s;
}

.btn-validar-mini:hover {
  background: #2980b9;
  transform: scale(1.05);
}

.btn-validar-mini i {
  font-size: 16px;
}

.tooltip {
  position: relative;
  display: inline-block;
}

.tooltip .tooltiptext {
  visibility: hidden;
  width: 120px;
  background-color: #2c3e50;
  color: #fff;
  text-align: center;
  border-radius: 6px;
  padding: 6px 8px;
  position: absolute;
  z-index: 1;
  bottom: 125%; /* arriba del botón */
  left: 50%;
  margin-left: -60px;
  opacity: 0;
  transition: opacity 0.3s;
  font-size: 12px;
}

.tooltip:hover .tooltiptext {
  visibility: visible;
  opacity: 1;
}

#popup-error .mensaje {
  background: linear-gradient(to left, #3498db, #2980b9) !important;
}
.check-icon {
  background: #3498db !important;
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




@media (max-width: 600px) {
  #progreso-registro {
    width: 90vw !important;
    right: 2vw !important;
    min-width: unset !important;
    padding: 16px 10px 16px 14px !important;
    font-size: 15px !important;
    box-shadow: 0 4px 16px rgba(0,0,0,0.16);
    top: 70px !important;
    z-index: 9999 !important;
  }
  #btn-minimizar-progreso {
    display: flex !important;
  }
}
.fw-bold {
    font-weight: 700 !important;
}
.ms-2 {
    margin-left: .5rem !important;
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

<aside id="progreso-registro" style="position: fixed; top: 250px; right: 10px; width: 220px; background: #ffffff; border-radius: 10px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
  
  <!-- Botón solo visible en móvil para minimizar/mostrar progreso -->
  <button id="btn-minimizar-progreso" type="button" style="display:none;position:absolute;top:8px;right:8px;z-index:10;background:#2c3e50;color:white;border:none;border-radius:50%;width:36px;height:36px;box-shadow:0 2px 8px #0002;font-size:22px;align-items:center;justify-content:center;">
    <span id="icono-progreso">–</span>
  </button>

  <h4 style="margin-bottom: 20px; color: #2c3e50;">Barra de Progreso</h4>
  <ul style="list-style: none; padding: 0; font-weight: 500;">
    <li id="paso-general" class="paso activo"><a href="#seccion-general">Datos Generales <span style="color: #84082B;">*</span> <span class="estado">(completo)</span></li>
    <li id="paso-licencia" class="paso desactivado" style="pointer-events: none; opacity: 0.5;"><a href="#bloque_licencia">Licencia de Conducir <span class="estado">(pendiente)</span></li>
    <li id="paso-dni" class="paso"><a href="#seccion-dni">Datos de DNI <span style="color: #84082B;">*</span><span class="estado">(pendiente)</span></li>
    <li id="paso-familiares" class="paso"><a href="#seccion-familiares">Familiares <span style="color: #84082B;">*</span><span class="estado">(pendiente)</span></li>
  </ul>
  <div style="margin-top: 20px;">
    <div style="background: #ddd; border-radius: 30px; height: 12px; overflow: hidden;">
      <div id="barra-progreso" style="background: linear-gradient(to right, #2980b9, #3498db); width: 20%; height: 100%; transition: width 0.4s;"></div>
    </div>
    <p style="margin-top: 10px; text-align: center;" id="texto-progreso">20% completo</p>
      <!-- Indicador visual -->
    <span id="badge-opcional" style="display:none; margin:8px auto 0; background:#f9ca24; color:#2c3e50; font-weight:bold; border-radius:18px; padding:6px 14px; font-size:14px; box-shadow: 0 2px 10px rgba(52,152,219,0.13); text-align:center; display:block; width:fit-content;">
      ¡Tranquilo! El 25% restante corresponde a datos de <b>Licencia</b> (opcional)<br>
      Si el trabajador no aplica licencia, puedes continuar el registro sin problemas.
    </span>
  </div>
</aside>

<header class="main-header animated-border">
  <div class="header-content">
    <a href="../index.php">
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


<!-- Agrega más según módulos -->

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
    <img src="../img/norte360_black.png" alt="Logo" style="height:40px; vertical-align: middle;">
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
<main>

  <hr>

  <h2>Registro de Trabajador</h2>

  <div class="card">  
    <div style="text-align:right; margin-top: 25px;">
      <div class="tooltip">
        <button type="button" class="btn-validar-mini" onclick="validarfo()">
          <i class="fas fa-check-circle"></i> Validar
        </button>
        <span class="tooltiptext">Verificar campos obligatorios</span>
      </div>
    </div>

    <form action="../php/guardar_trabajador.php" method="POST" enctype="multipart/form-data" class="formulario-entrevista">

      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="id_entrevista" id="id_entrevista" value="<?= $datos_pre['id_entrevista'] ?? '' ?>">

      <div id="seccion-general">
                
        <!-- RÉGIMEN DE CONTRATACIÓN -->
        <div class="campo-form">
          <label>Régimen de contratación <span style="color:#84082B;">*</span></label>
          <div class="radio-group">
            <?php
              $regPre = isset($datos_pre['regimen']) ? strtoupper(trim($datos_pre['regimen'])) : 'PLANILLA';
            ?>
            <input type="radio" id="regimen_planilla" name="regimen" value="PLANILLA"
                  <?= ($regPre === 'PLANILLA') ? 'checked' : '' ?> required>
            <label for="regimen_planilla" class="radio-label">Planilla</label>

            <input type="radio" id="regimen_rph" name="regimen" value="RPH"
                  <?= ($regPre === 'RPH') ? 'checked' : '' ?>>
            <label for="regimen_rph" class="radio-label">Recibos por Honorarios</label>
          </div>
        </div>


      <div class="campo-form">
          <label for="nombre">Nombre del Trabajador <span style="color: #84082B;">*</span></label>
          <input type="text" name="nombre" id="nombre" placeholder="Ej. Juanito Alimaña" required
                value="<?= $datos_pre['nombre'] ?? '' ?>">
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="correo">Correo Electrónico  <span style="color: #84082B;">*</span></label>
            <input type="email" name="correo" id="correo" placeholder="Ej. correo@ejemplo.com" required>
            <small id="mensaje-correo" style="color: #e74c3c; font-weight: bold; display: none; margin-top: 5px;">✉️ Ingresa un correo válido.</small>
          </div>
          
          <div class="campo-form">
            <label for="celular">Celular  <span style="color: #84082B;">*</span></label>
            <input type="number" name="celular" id="celular" minlength="9" maxlength="9"
                    oninput="if(this.value.length>9)this.value=this.value.slice(0,9)"
                value="<?= $datos_pre['celular'] ?? '' ?>" placeholder="Ej. 963852741" required>
          </div>
        </div>

        <div class="campo-form">
          <label for="domicilio">Domicilio <span style="color: #84082B;">*</span></label>
          <input type="text" name="domicilio" id="domicilio" placeholder="Ej. Av. Siempre Viva 742" required>
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="tipo_trabajador">Tipo de Trabajador <span style="color: #84082B;">*</span></label>
            <select name="tipo_trabajador" id="tipo_trabajador" required>
              <option value="">Seleccionar</option>
              <option value="Personal" <?= (isset($datos_pre['puesto']) && $datos_pre['puesto'] === 'Personal') ? 'selected' : '' ?>>Personal</option>
              <option value="Conductor" <?= (isset($datos_pre['puesto']) && $datos_pre['puesto'] === 'Conductor') ? 'selected' : '' ?>>Conductor</option>

            </select>
          </div>

          <div class="campo-form">
            <label for="puesto">Cargo <span style="color: #84082B;">*</span></label>
            <input type="text" name="puesto" id="puesto" placeholder="Ej. Mecánico, Operador" required
                value="<?= $datos_pre['cargo'] ?? '' ?>">
          </div>
        </div>

        <div class="campo-form">
          <label>Sexo  <span style="color: #84082B;">*</span></label>
          <div class="radio-group">
            <input type="radio" id="sexo_m" name="sexo" value="Masculino" required>
            <label for="sexo_m" class="radio-label">Masculino</label>

            <input type="radio" id="sexo_f" name="sexo" value="Femenino">
            <label for="sexo_f" class="radio-label">Femenino</label>
          </div>
        </div>
        
        <div class="campo-form">
          <label for="observaciones">Observaciones</label>
          <textarea name="observaciones" id="observaciones" rows="4" placeholder="Detalles, impresiones, recomendaciones..."></textarea>
        </div>
              
        <div class="campo-form">
          <label for="cv_pdf">📎 Adjuntar CV (PDF)  <span style="color: #84082B;">*</span></label>
          <div class="custom-file-upload">
            <label for="cv_pdf" class="upload-label" style="color: white;">📁 Seleccionar archivo</label>
            <span id="nombre-archivo" class="nombre-archivo">Ningún archivo seleccionado</span>
            <input type="file" name="cv_pdf" id="cv_pdf" accept="application/pdf" onchange="mostrarNombreArchivo(this)">
          </div>
        </div>

        <div class="campo-form">
          <label for="img_personal">🖼️ Foto del Trabajador (+)  <span style="color: #84082B;">*</span></label>
          <div class="preview-container">
            <input type="file" id="img_personal" name="img_personal" accept="image/*" onchange="mostrarPreview(this, 'preview-personal')">
            <div class="img-preview" id="preview-personal">
              <span class="texto-previo">Haz clic para seleccionar imagen</span>
            </div>
          </div>
        </div>



        <div class="campo-form">
          <label>
            <input type="checkbox" id="check_licencia" name="check_licencia" onchange="activarPasoLicencia()">
            Licencia de Conducir
          </label>
        </div>

      </div>

      <div id="bloque_licencia" style="display: none;">
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%);">

        <b style="text-align: center;">INFORMACIÓN DE LICENCIA DE CONDUCIR</b> 

        <div class="grupo-flex">
          <div class="campo-form">
              <label for="nlicencia">N° de Licencia</label>
              <input type="text" name="nlicencia" id="nlicencia" placeholder="Ej. ABC">
          </div>

          <div class="campo-form">
              <label for="tipo_licencia">Tipo de Licencia</label>
              <input type="text" name="tipo_licencia" id="tipo_licencia" placeholder="Ej. A, B o C">
          </div>

          <div class="campo-form">
              <label for="categoría_licencia">Categoría de Licencia</label>
              <input type="text" name="categoría_licencia" id="categoría_licencia" placeholder="Ej. A, B o C">
          </div>
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="fecha">📅 Fecha de Expedición</label>
            <input type="date" name="fecha_licencia_expedicion" id="fecha_licencia_expedicion">
          </div>

          <div class="campo-form">
            <label for="fecha">📅 Fecha de Revaluación</label>
            <input type="date" name="fecha_licencia_revaluacion" id="fecha_licencia_revaluacion">
          </div>
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="img_lic_frontal">🖼️ Imagen Frontal de Licencia (+)</label>
            <div class="preview-container">
              <input type="file" id="img_lic_frontal" name="img_lic_frontal" accept="image/*" onchange="mostrarPreview(this, 'preview-frontal-lic')">
              <div class="img-preview" id="preview-frontal-lic">
                <span class="texto-previo">Haz clic para seleccionar imagen</span>
              </div>
            </div>
          </div>

          <div class="campo-form">
            <label for="img_lic_trasera">🖼️ Imagen Trasera de Licencia (+)</label>
            <div class="preview-container">
              <input type="file" id="img_lic_trasera" name="img_lic_trasera" accept="image/*" onchange="mostrarPreview(this, 'preview-trasera-lic')">
              <div class="img-preview" id="preview-trasera-lic">
                <span class="texto-previo">Haz clic para seleccionar imagen</span>
              </div>
            </div>
          </div>
        </div>

        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%);">
      </div>

      <div id="seccion-dni" style="display:none;">
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%);">
        <b style="text-align: center;">INFORMACIÓN DEL DNI</b> 

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="dni">DNI <span style="color: #84082B;">*</span></label>
            <input type="number" name="dni" id="dni" placeholder="8 dígitos" minlength="8" maxlength="8" required
            oninput="if(this.value.length>8)this.value=this.value.slice(0,8)"
                value="<?= $datos_pre['dni'] ?? '' ?>">
          </div>
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="ubigeo">Ubigeo <span style="color: #84082B;">*</span></label>
            <input type="number" name="ubigeo" id="ubigeo" placeholder="8 dígitos" minlength="8" maxlength="8" required
            oninput="if(this.value.length>8)this.value=this.value.slice(0,8)">
          </div>
          
          <div class="campo-form">
            <label for="fecha">📅 Fecha de Nacimiento <span style="color: #84082B;">*</span></label>
            <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required>
          </div>
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="fecha">📅 Fecha de Emisión <span style="color: #84082B;">*</span></label>
            <input type="date" name="fecha_dni_emision" id="fecha_dni_emision" required>
          </div>

          <div class="campo-form">
            <label for="fecha">📅 Fecha de Caducidad <span style="color: #84082B;">*</span></label>
            <input type="date" name="fecha_dni_caducidad" id="fecha_dni_caducidad" required>
          </div>
        </div>

        <div class="grupo-flex">
          <div class="campo-form">
            <label for="img_dni_frontal">🖼️ Imagen Frontal del DNI (+)</label>
            <div class="preview-container">
              <input type="file" id="img_dni_frontal" name="img_dni_frontal" accept="image/*" onchange="mostrarPreview(this, 'preview-frontal')">
              <div class="img-preview" id="preview-frontal">
                <span class="texto-previo">Haz clic en el texto para seleccionar imagen</span>
              </div>
            </div>
          </div>

          <div class="campo-form">
            <label for="img_dni_trasera">🖼️ Imagen Trasera del DNI (+)</label>
            <div class="preview-container">
              <input type="file" id="img_dni_trasera" name="img_dni_trasera" accept="image/*" onchange="mostrarPreview(this, 'preview-trasera')">
              <div class="img-preview" id="preview-trasera">
                <span class="texto-previo">Haz clic en el texto para seleccionar imagen</span>
              </div>
            </div>
          </div>
        </div>
        
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%);">
      </div>

      <div  id="seccion-familiares" class="campo-form" style="display: none">
        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%);">

        <label>👨‍👩‍👧 Familiares Relacionados</label>

        <div id="contenedor-familiares">
          <div id="sin-familiares" class="no-familiares">No hay familiares por añadir</div>
        </div>

        <button type="button" class="btn-agregar-familiar" onclick="agregarFamiliar()">➕ Añadir familiar</button>

        <hr style="background: linear-gradient(120deg, #2980b9 30%, black 50%, #2980b9 70%);">

        <label>🚨 Contacto de Emergencia</label>

        <div class="grupo-flex">

          <div class="campo-form">
            <label for="emerg_parentesco">Parentesco</label>
            <select id="emerg_parentesco" name="emerg_parentesco">
              <option value="">Parentesco</option>
              <option value="Padre">Padre</option>
              <option value="Madre">Madre</option>
              <option value="Hermano(a)">Hermano(a)</option>
              <option value="Esposo(a)">Esposo(a)</option>
              <option value="Conviviente">Conviviente</option>
              <option value="Amistad Cercana">Amistad Cercana</option>
            </select>
          </div>

          <div class="campo-form">
            <label for="emerg_nombre">Nombre del contacto</label>
            <input type="text" id="emerg_nombre" name="emerg_nombre" placeholder="Ej. Rosa Pérez">
          </div>

        </div>


        <div class="grupo-flex">
          <div class="campo-form">
            <label for="emerg_dni">Dni</label>
            <input type="number" id="emerg_dni" name="emerg_dni" placeholder="8 dígitos" minlength="8" maxlength="8"
              oninput="if(this.value.length>8)this.value=this.value.slice(0,8)">
          </div>

          <div class="campo-form">
            <label for="emerg_celular">Celular</label>
            <input type="number" id="emerg_celular" name="emerg_celular" placeholder="9 dígitos" minlength="9" maxlength="9"
              oninput="if(this.value.length>9)this.value=this.value.slice(0,9)">
          </div>
        </div>

        <div style="text-align:center; margin-top: 25px;">
          <button type="submit" class="btn-validar">Registrar Trabajador</button>
        </div>
      </div>

      </div>
      <script>
        function mostrarNombreArchivo(input) {
            const archivo = input.files[0];
            const nombreArchivo = document.getElementById("nombre-archivo");
            if (archivo) {
                nombreArchivo.textContent = archivo.name;
            } else {
                nombreArchivo.textContent = "Ningún archivo seleccionado";
            }
        }
      </script>
    </form>
  </div>

  <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
      <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
  </a>

  <hr>
</main>

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



function mostrarPreview(input, idPreview) {
  const preview = document.getElementById(idPreview);
  preview.innerHTML = ''; // Limpiar contenido previo

  if (input.files && input.files[0]) {
    const img = document.createElement("img");
    const reader = new FileReader();

    const botonCancelar = document.createElement("button");
    botonCancelar.textContent = "×";
    botonCancelar.classList.add("boton-cancelar");
    botonCancelar.onclick = function () {
      input.value = "";
      preview.innerHTML = '<span class="texto-previo">Haz clic para seleccionar imagen</span>';
    };

    reader.onload = function (e) {
      img.src = e.target.result;
      preview.appendChild(img);
      preview.appendChild(botonCancelar);
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>

<script>
let contadorFamiliares = 0;
const maxFamiliares = 3;

function agregarFamiliar() {
  if (contadorFamiliares >= maxFamiliares) return;

  document.getElementById("sin-familiares").style.display = "none";
  const contenedor = document.getElementById("contenedor-familiares");
  const botonAgregar = document.querySelector(".btn-agregar-familiar");

  const fila = document.createElement("div");
  fila.className = "grupo-flex fila-familiar";
  fila.innerHTML = `
    <div class="campo-form" style="margin-bottom:0;">
      <select name="parentesco[]" required>
        <option value="">Parentesco</option>
        <option value="Padre">Padre</option>
        <option value="Madre">Madre</option>
        <option value="Conyugue">Conyugue</option>
        <option value="Hijo(a)">Hijo(a)</option>
        <option value="Hermano(a)">Hermano(a)</option>
      </select>
    </div>
    <div class="campo-form" style="margin-bottom:0;">
      <input type="text" name="nombre_familiar[]" placeholder="Nombre del familiar" required>
    </div>
    <div class="campo-form" style="margin-bottom:0;">
      <input type="number" name="dni_familiar[]" placeholder="DNI" maxlength="8"
        oninput="if(this.value.length>8)this.value=this.value.slice(0,8)" required>
    </div>
    <div class="campo-form" style="margin-bottom:0;">
      <input type="number" name="contacto_familiar[]" placeholder="Celular" maxlength="9"
        oninput="if(this.value.length>8)this.value=this.value.slice(0,9)" required>
    </div>
    <div class="campo-form" style="margin-bottom:0; flex: 0 0 auto;">
      <button type="button" class="btn-eliminar-familiar" onclick="eliminarFamiliar(this)">[X Borrar parentesco]</button>
    </div>
  `;
  
  contenedor.appendChild(fila);
  contadorFamiliares++;

  if (contadorFamiliares >= maxFamiliares) {
    botonAgregar.style.display = "none";
  }
}

function eliminarFamiliar(boton) {
  const fila = boton.closest(".fila-familiar");
  fila.remove();
  contadorFamiliares--;

  if (contadorFamiliares < maxFamiliares) {
    document.querySelector(".btn-agregar-familiar").style.display = "block";
  }

  if (contadorFamiliares === 0) {
    document.getElementById("sin-familiares").style.display = "block";
  }
}
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const correoInput = document.getElementById("correo");
  const mensaje = document.getElementById("mensaje-correo");

  correoInput.addEventListener("input", () => {
    if (correoInput.validity.typeMismatch || correoInput.validity.patternMismatch) {
      mensaje.style.display = "block";
      correoInput.style.borderColor = "#e74c3c";
    } else {
      mensaje.style.display = "none";
      correoInput.style.borderColor = "";
    }
  });
});
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
  const sexo = "<?= $datos_pre['sexo'] ?? '' ?>";
  if (sexo === "Masculino") {
    document.getElementById("sexo_m").checked = true;
  } else if (sexo === "Femenino") {
    document.getElementById("sexo_f").checked = true;
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
function actualizarProgreso() {

  const secciones = [
    {
      id: "general",
      nombre: "Datos Generales",
      campos: ["nombre","correo", "celular", "domicilio", "tipo_trabajador", "cv_pdf", "img_personal", "puesto", "sexo"],
      paso: "paso-general"
    },
    {
      id: "licencia",
      nombre: "Licencia de Conducir",
      campos: ["nlicencia", "tipo_licencia", "categoría_licencia", "fecha_licencia_expedicion", "fecha_licencia_revaluacion","img_lic_trasera", "img_lic_frontal"],
      paso: "paso-licencia"
    },
    {
      id: "dni",
      nombre: "Datos de DNI",
      campos: ["dni", "ubigeo", "fecha_nacimiento", "fecha_dni_emision", "fecha_dni_caducidad"],
      paso: "paso-dni"
    },
    {
      id: "familiares",
      nombre: "Familiares",
      campos: ["emerg_nombre", "emerg_parentesco", "emerg_dni", "emerg_celular"], // Puedes agregar validación por familiares aquí si quieres
      paso: "paso-familiares"
    }
  ];


  let totalSecciones = secciones.length;
  let seccionesCompletas = 0;
  let porcentajeTotal = 0;

  secciones.forEach(seccion => {
    if (seccion.id === "licencia" && !document.getElementById("check_licencia").checked) {
        // Si la sección es licencia y el check está desmarcado, la ignoras
        // Opcional: Puedes poner el estado visualmente como "pendiente"
        const paso = document.querySelector(`#${seccion.paso} .estado`);
        if (paso) {
            paso.textContent = "(pendiente)";
            paso.style.color = "#c0392b";
        }
        return; // No continúes con esta sección
    }
    let completos = 0;
    let visibles = 0;

    seccion.campos.forEach(id => {

        // Caso especial para sexo (radio group)
        if (id === "sexo") {
          const m = document.getElementById("sexo_m");
          const f = document.getElementById("sexo_f");
          visibles++;
          if ((m && m.checked) || (f && f.checked)) completos++;
          return;
        }
      const input = document.getElementById(id);
      if (input) { // Cuenta todos los inputs aunque estén ocultos
        visibles++;
        if (
          (input.type === "checkbox" && input.checked) ||
          (input.type === "radio" && input.checked) ||
          (input.value && input.value.trim() !== "")
        ) {
          completos++;
        }
      }
    });

    let estado = "incompleto";
    if (visibles === 0) {
      estado = "pendiente";
    } else if (completos === visibles && visibles > 0) {
      estado = "completo";
      seccionesCompletas++;
    } else if (completos > 0) {
      estado = "parcial";
    }

    // Actualiza el texto de estado visual
    const paso = document.querySelector(`#${seccion.paso} .estado`);
    if (paso) {
      paso.textContent = `(${estado})`;
      paso.style.color =
        estado === "completo" ? "#27ae60" :
        estado === "parcial" ? "#f39c12" :
        "#c0392b";
    }
  });

  // Progreso calculado por % de secciones completas
  porcentajeTotal = Math.round((seccionesCompletas / totalSecciones) * 100);

  // Para hacerlo más progresivo, puedes ponderar 'parciales' también
  // Ejemplo: por cada sección parcial, suma la mitad de puntos
  let parciales = secciones.filter(seccion => {
    const paso = document.querySelector(`#${seccion.paso} .estado`);
    return paso && paso.textContent.includes("parcial");
  }).length;

  porcentajeTotal += Math.round((parciales / totalSecciones) * 50);

  if (porcentajeTotal > 100) porcentajeTotal = 100;

  document.getElementById("barra-progreso").style.width = porcentajeTotal + "%";
  document.getElementById("texto-progreso").textContent = porcentajeTotal + "% completo";

const badge = document.getElementById("badge-opcional");
if (porcentajeTotal >= 75 && porcentajeTotal < 100) {
    badge.style.display = "block";
} else {
    badge.style.display = "none";
}

}
// Escuchar cambios
document.querySelectorAll("input, select").forEach(el => {
  el.addEventListener("input", actualizarProgreso);
});

document.addEventListener("DOMContentLoaded", actualizarProgreso);
// Escuchar todos los cambios en los inputs de las secciones
document.querySelectorAll("input, select, textarea").forEach(el => {
  el.addEventListener("input", actualizarProgreso);
});
document.addEventListener("DOMContentLoaded", actualizarProgreso);

</script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const pasos = {
    "paso-general": "seccion-general",
    "paso-licencia": "bloque_licencia",
    "paso-dni": "seccion-dni",
    "paso-familiares": "seccion-familiares"
  };

  Object.keys(pasos).forEach(idPaso => {
    const item = document.getElementById(idPaso);
    item.addEventListener("click", function (e) {
      e.preventDefault();

      // Ocultar todas las secciones
      Object.values(pasos).forEach(idSeccion => {
        const seccion = document.getElementById(idSeccion);
        if (seccion) seccion.style.display = "none";
      });

      // Mostrar la seleccionada
      const seccionMostrar = document.getElementById(pasos[idPaso]);
      if (seccionMostrar) seccionMostrar.style.display = "block";

      // Quitar clase 'activo' de todos los pasos
      document.querySelectorAll("#progreso-registro .paso").forEach(p => {
        p.classList.remove("activo");
      });

      // Agregar clase 'activo' al seleccionado
      item.classList.add("activo");

      // Scroll suave hacia la sección
      seccionMostrar.scrollIntoView({ behavior: "smooth" });
    });
  });
});
</script>

<script>
function activarPasoLicencia() {
  const checkbox = document.getElementById("check_licencia");
  const pasoLicencia = document.getElementById("paso-licencia");
  const bloqueLicencia = document.getElementById("bloque_licencia");

  if (checkbox.checked) {
    pasoLicencia.style.pointerEvents = "auto";
    pasoLicencia.style.opacity = "1";
    pasoLicencia.classList.remove("desactivado");
    
  } else {
    pasoLicencia.style.pointerEvents = "none";
    pasoLicencia.style.opacity = "0.5";
    pasoLicencia.classList.add("desactivado");
  }
}
</script>
<!-- MODAL DE ERROR -->
<div id="popup-error" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
  <div class="mensaje" style="background: linear-gradient(to left, #3498db, #2980b9); padding: 20px 40px; border-radius: 16px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3); font-size: 20px; font-weight: bold; color: white; text-align: center; animation: scaleIn 0.4s ease forwards; transform: scale(0.8); opacity: 0;">
    
    <!-- Ícono de información -->
    <svg xmlns="http://www.w3.org/2000/svg" style="width:60px; height:60px; margin:0 auto 10px auto; display:block; background:#3498db; border-radius:50%; padding:10px; box-shadow:0 0 15px rgba(0,0,0,0.3);" fill="white" viewBox="0 0 24 24">
      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 17c-.55 0-1-.45-1-1v-6c0-.55.45-1 1-1s1 .45 1 1v6c0 .55-.45 1-1 1zm0-10c-.55 0-1-.45-1-1V7c0-.55.45-1 1-1s1 .45 1 1v1c0 .55-.45 1-1 1z"/>
    </svg>

    <p class="texto-popup">ℹ️ Verifica los campos obligatorios antes de continuar.</p>
  </div>
</div>
<script>
function toggleMenu() {
  const menu = document.querySelector('.menu-lateral');
  menu.classList.toggle('active');
}
// Minimizar barra de progreso en móvil
document.addEventListener('DOMContentLoaded', function() {
  const aside = document.getElementById('progreso-registro');
  const btn = document.getElementById('btn-minimizar-progreso');
  const icono = document.getElementById('icono-progreso');
  let minimizado = false;

  if (btn && aside) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      minimizado = !minimizado;
      if (minimizado) {
        aside.style.height = '40px';
        aside.style.overflow = 'hidden';
        aside.style.transition = 'height 0.25s cubic-bezier(.4,2,.6,1)';
        icono.textContent = '+';
      } else {
        aside.style.height = '';
        aside.style.overflow = '';
        icono.textContent = '–';
      }
    });

    // Opcional: si el usuario cambia de tamaño la ventana, restaurar el aside
    window.addEventListener('resize', () => {
      if (window.innerWidth > 600) {
        aside.style.height = '';
        aside.style.overflow = '';
        minimizado = false;
        icono.textContent = '–';
      }
    });
  }
});

</script>

<script>
function validarfo() {
  const camposObligatorios = [
    { id: "nombre", label: "Nombre del trabajador" },
    { id: "tipo_trabajador", label: "Tipo de trabajador" },
    { id: "sexo_m", label: "Sexo" },
    { id: "dni", label: "DNI" },
    { id: "ubigeo", label: "Ubigeo" },
    { id: "fecha_nacimiento", label: "Fecha de nacimiento" },
    { id: "fecha_dni_emision", label: "Fecha de emisión del DNI" },
    { id: "fecha_dni_caducidad", label: "Fecha de caducidad del DNI" }
  ];

  let camposFaltantes = [];

  for (const campo of camposObligatorios) {
    const el = document.getElementById(campo.id);
    if (el && el.offsetParent !== null && !el.checkValidity()) {
      camposFaltantes.push(campo.label);
    }
  }

  const popupError = document.getElementById("popup-error");
  const mensaje = popupError.querySelector(".mensaje");
  const texto = popupError.querySelector(".texto-popup");

  if (camposFaltantes.length > 0) {
    const listaErrores = camposFaltantes.map(txt => `• ${txt}`).join("<br>");
    texto.innerHTML = `Faltan campos por completar:<br><br>${listaErrores}`;
  } else {
    texto.innerHTML = `Todos los campos obligatorios visibles están completos.`;
  }

  popupError.style.display = "flex";
  mensaje.style.animation = "scaleIn 0.4s ease forwards";

  setTimeout(() => {
    mensaje.style.animation = 'fadeOut 0.4s ease forwards';
    setTimeout(() => popupError.style.display = "none", 500);
  }, 3500);
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

