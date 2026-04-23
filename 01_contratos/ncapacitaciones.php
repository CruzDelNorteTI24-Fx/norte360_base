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

// Obtener trabajadores
// --- CAPACITACIONES (lista con cantidad de inscritos) ---
$capacitaciones = [];
$CAP_ESTADOS_MAP = [
  0 => 'PROGRAMADA',
  1 => 'EN CURSO',
  2 => 'FINALIZADA',
  3 => 'CANCELADA'
];
$CAP_ESTILOS = [
  0 => 'secondary',
  1 => 'info',
  2 => 'success',
  3 => 'danger'
];

$sqlCap = "
SELECT 
  c.clm_cap_id,
  c.clm_cap_capacitacion,
  c.clm_cap_estado,
  c.clm_cap_fecharegistro,
  c.clm_cap_fechainicio,
  c.clm_cap_fechafin,
  c.clm_cap_duracion_minutos,
  COALESCE(COUNT(t.clm_trcap_id),0) AS inscritos,
  CASE 
    WHEN c.clm_cap_documento IS NOT NULL 
         AND OCTET_LENGTH(c.clm_cap_documento) > 0
      THEN 1 
    ELSE 0 
  END AS has_doc
FROM tb_capacitaciones c
LEFT JOIN tb_trabincapacitaciones t ON t.clm_trcap_capid = c.clm_cap_id
GROUP BY c.clm_cap_id
ORDER BY c.clm_cap_id DESC;

";
$resCap = $conn->query($sqlCap);
if ($resCap) {
  while ($r = $resCap->fetch_assoc()) {
    // Mapea estado si es numérico; si aún es texto, úsalo tal cual.
    if (is_numeric($r['clm_cap_estado'])) {
      $k = (int)$r['clm_cap_estado'];
      $r['estado_texto'] = $CAP_ESTADOS_MAP[$k] ?? ('ESTADO '.$k);
      $r['estado_badge'] = $CAP_ESTILOS[$k] ?? 'secondary';
    } else {
      $r['estado_texto'] = $r['clm_cap_estado'];
      $r['estado_badge'] = 'secondary';
    }
    $capacitaciones[] = $r;
  }
  $resCap->free();
}



$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Trabajadores | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">     
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


.accordion-button.fw-bold:hover, 
.accordion-button.fw-bold:focus {
  background-color: #2c3e50 !important; /* Cambia este color por el que prefieras */
  color: white !important; /* Cambia el texto si quieres */
  transition: background 0.25s;
}

.btn-action {
  font-weight: bold;
  box-shadow: 0 3px 12px rgba(52,152,219,0.06);
  border-radius: 10px;
  padding-left: 18px;
  padding-right: 18px;
  transition: all 0.15s;
}
.btn-action:hover {
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 8px 16px rgba(52,152,219,0.18);
  opacity: 0.93;
}
.action-bar-pro {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 18px;
  margin-top: 10px;
  margin-bottom: 28px;
  background: rgba(255,255,255,0.44);
  border-radius: 18px;
  box-shadow: 0 6px 28px 0 rgba(41,128,185,0.07), 0 1.5px 10px rgba(44,62,80,0.03);
  padding: 16px 12px 10px 12px;
  backdrop-filter: blur(4px) saturate(1.2);
  border: 1.5px solid #eaf1fb;
  animation: fadeIn 0.5s;
}
.action-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 1.05rem;
  font-weight: 600;
  color: #24416c;
  text-decoration: none;
  padding: 13px 26px 13px 20px;
  border-radius: 14px;
  background: linear-gradient(120deg,rgba(240,245,255,0.82),rgba(232,243,255,0.88) 60%, #f2f8fd 100%);
  border: 1.5px solid #e2eafc;
  box-shadow: 0 2.5px 8px rgba(41,128,185,0.06);
  transition: 
    background 0.21s,
    box-shadow 0.21s,
    color 0.14s,
    transform 0.16s;
  position: relative;
  overflow: hidden;
}
.action-btn i {
  font-size: 1.45em;
  margin-right: 4px;
  vertical-align: middle;
  transition: color 0.18s;
}
.action-btn span {
  letter-spacing: .2px;
}
.action-btn:hover, .action-btn:focus {
  background: linear-gradient(120deg,rgba(52,152,219,0.12),rgba(149,232,255,0.23) 80%, #f2f8fd 100%);
  box-shadow: 0 8px 24px rgba(52,152,219,0.13);
  color: #17509c;
  transform: translateY(-2px) scale(1.03);
}
.action-btn:active {
  transform: scale(.98);
}
.action-btn:hover i, .action-btn:focus i {
  color: #2082da;
}

.action-btn.action-new     { border-left: 5px solid #4ade80; }
.action-btn.action-view    { border-left: 5px solid #60a5fa; }
.action-btn.action-license { border-left: 5px solid #fbbf24; }
.action-btn.action-job     { border-left: 5px solid #818cf8; }
.action-btn.action-alert   { border-left: 5px solid #f87171; }

@media (max-width: 650px) {
  .action-bar-pro {
    gap: 10px;
    padding: 10px 6px 6px 6px;
  }
  .action-btn {
    padding: 11px 13px 11px 13px;
    font-size: 0.97rem;
    border-radius: 10px;
  }
  .action-btn span {
    display: none;
  }
}
.offcanvas .nav-link,
aside .nav-link {
  color: #24416c;
  font-weight: 500;
  font-size: 1.05em;
  border-radius: 10px;
  padding: 10px 16px;
  margin-bottom: 2px;
  transition: background .16s, color .16s, padding .12s;
}
.offcanvas .nav-link:hover,
aside .nav-link:hover,
.offcanvas .nav-link.active,
aside .nav-link.active {
  background: linear-gradient(120deg, #e8f3fd 70%, #fff 100%);
  color: #166ab5;
  padding-left: 23px;
}
.offcanvas .nav-link i,
aside .nav-link i {
  font-size: 1.3em;
  color: #60a5fa;
}
.offcanvas-title img,
aside img {
  vertical-align: middle;
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
/* Encabezado de la tabla principal Capacitaciones */
#tablaCap thead th {
  background-color: #2c3e50 !important;
  color: #fff !important;
  border-color: #2c3e50 !important; /* opcional: bordes del mismo tono */
}
/* ===== Modal "Nueva capacitación" — look & feel ===== */
#modalNuevaCap .cerrar { display: none; } /* oculto la X antigua */

#modalNuevaCap .cap-modal {
  border: 0;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 18px 60px rgba(0,0,0,.25);
  background: #fff;
  position: relative;
}

#modalNuevaCap .cap-modal::before {
  content: "";
  position: absolute;
  inset: 0 0 auto 0;
  height: 4px;
  background: linear-gradient(90deg,#2c3e50, #3e5873, #2c3e50);
}

#modalNuevaCap .cap-modal-header {
  background: linear-gradient(135deg,#2c3e50 0%, #3b4b62 100%);
  color: #fff;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 20px;
}

#modalNuevaCap .cap-modal-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  letter-spacing: .2px;
}

#modalNuevaCap .cap-close {
  margin-left: auto;
  background: transparent;
  border: 0;
  color: #fff;
  font-size: 28px;
  line-height: 1;
  opacity: .85;
  cursor: pointer;
}
#modalNuevaCap .cap-close:hover { opacity: 1; transform: scale(1.05); }

#modalNuevaCap .cap-modal-body {
  background: #f7f9fc;
  padding: 18px 20px;
}

#modalNuevaCap .cap-modal-footer {
  background: #f0f4f8;
  border-top: 1px solid #e6eef5;
  padding: 12px 20px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* Inputs con icono */
#modalNuevaCap .input-with-icon { position: relative; }
#modalNuevaCap .input-with-icon > i {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  opacity: .6; font-size: 1.1rem; pointer-events: none;
}
#modalNuevaCap .input-with-icon > .form-control,
#modalNuevaCap .input-with-icon > .form-select,
#modalNuevaCap .input-with-icon > textarea {
  padding-left: 40px;
}

#modalNuevaCap .field-hint {
  font-size: .85rem;
  color: #6b7a90;
  margin-top: 6px;
}

/* Enfoque de marca */
#modalNuevaCap .form-control:focus,
#modalNuevaCap .form-select:focus,
#modalNuevaCap textarea:focus {
  border-color: #2c3e50;
  box-shadow: 0 0 0 .2rem rgba(44,62,80,.15);
}

/* Resumen en chips */
#modalNuevaCap .cap-preview {
  background: #fff;
  border: 1px dashed #cbd5e1;
  border-radius: 12px;
  padding: 12px;
  display: flex; flex-wrap: wrap; gap: 8px;
}
#modalNuevaCap .cap-chip {
  background: #eef2f6;
  border-radius: 999px;
  padding: 8px 12px;
  font-size: .92rem;
  color: #2c3e50;
}
#modalNuevaCap .cap-chip b { margin-right: 6px; }

/* Botón primario de marca */
#modalNuevaCap .btn-brand {
  background: #2c3e50;
  color: #fff;
  border: 0;
  font-weight: 700;
}
#modalNuevaCap .btn-brand:hover { background: #22303d; }
/* Fin estimado: aspecto gris y no editable */
#modalNuevaCap #cap_fin_preview{
  background: #f1f5f9;
  color: #64748b;
  border: 1px dashed #cbd5e1;
  cursor: not-allowed;
  font-weight: 600;
}
#modalNuevaCap #cap_fin_preview::placeholder{
  color: #94a3b8;
}
/* No estirar los botones dentro de la tabla de Capacitaciones */
#tablaCap .btn { width: auto !important; }



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

<button class="menu-toggle" id="btnMenuToggle" onclick="toggleMenu()" aria-label="Menú"><span></span><span></span><span></span></button>

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


<!-- <img src="img/cdn_productos_lg.png" alt="Logo del sistema" class="logo-inicio"> -->
<div class="container mt-4 mb-5">


<h3 class="mb-4 text-black fw-bold">Capacitaciones</h3>

<!-- Barra superior: botón crear + buscador -->
<div class="d-flex flex-wrap align-items-center mb-3 gap-2">
  <button class="ms-auto action-btn action-new" data-bs-toggle="modal" data-bs-target="#modalNuevaCap">
    <i class="bi bi-plus-circle"></i> Nueva capacitación
  </button>
  <div style="min-width:260px; max-width:360px; width:100%;">
    <input id="filtroCap" class="form-control" type="text" placeholder="Buscar por nombre o estado...">
  </div>
</div>

<?php
function fmtMin($min) {
  $min = (int)$min;
  if ($min <= 0) return '—';
  $h = intdiv($min, 60);
  $m = $min % 60;
  if ($h && $m) return $h.'h '.$m.'m';
  if ($h) return $h.'h';
  return $m.'m';
}
?>
<div class="tabla-contenedor">
  <table id="tablaCap" class="table table-hover align-middle">
    <thead>
      <tr>
        <th style="width:70px;">#</th>
        <th>Capacitación</th>
        <th style="width:170px;">Inicio</th>
        <th style="width:120px;">Duración</th>
        <th style="width:170px;">Fin</th>
        <th style="width:140px;">Estado</th>
        <th style="width:120px;">Inscritos</th>
        <th style="width:260px;">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($capacitaciones)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Sin capacitaciones registradas.</td></tr>
      <?php else: ?>
        <?php foreach ($capacitaciones as $c): ?>
        <?php $estado = (int)$c['clm_cap_estado']; ?>

          <tr>
            <td class="fw-semibold">#<?= (int)$c['clm_cap_id'] ?></td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($c['clm_cap_capacitacion']) ?></div>
              <small class="text-muted">Creada: <?= date('d/m/Y H:i', strtotime($c['clm_cap_fecharegistro'])) ?></small>
            </td>

            <td><?= date('d/m/Y H:i', strtotime($c['clm_cap_fechainicio'])) ?></td>

            <td><?= fmtMin($c['clm_cap_duracion_minutos']) ?></td>

            <td><?= $c['clm_cap_fechafin'] ? date('d/m/Y H:i', strtotime($c['clm_cap_fechafin'])) : '<span class="text-muted">—</span>' ?></td>

            <td>
              <span class="badge bg-<?= $c['estado_badge'] ?? 'secondary' ?>">
                <?= htmlspecialchars($c['estado_texto']) ?>
              </span>
            </td>

            <td><span class="fw-bold"><?= (int)$c['inscritos'] ?></span></td>

            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-primary btn-action me-1"
                href="#"
                onclick="openDetalle(<?= (int)$c['clm_cap_id'] ?>)">
                <i class="bi bi-eye"></i> Ver
              </a>

            <?php $urlDoc = 'api/capacitaciones_ver_documento.php?id='.(int)$c['clm_cap_id'].'&disposition=inline'; ?>

            <?php if ((int)$c['has_doc'] === 1): ?>
              <a class="btn btn-sm btn-outline-secondary btn-action me-1"
                href="<?= $urlDoc ?>" target="_blank" title="Ver documento">
                <i class="bi bi-paperclip"></i> Doc
              </a>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-secondary btn-action me-1" disabled title="Sin documento">
                <i class="bi bi-paperclip"></i> Doc
              </button>
            <?php endif; ?>

            <?php if ($estado !== 2 && $estado !== 3): ?>
              <a class="btn btn-sm btn-outline-success btn-action me-1"
                href="#"
                onclick="openInscribir(<?= (int)$c['clm_cap_id'] ?>,'<?= htmlspecialchars($c['clm_cap_capacitacion'], ENT_QUOTES) ?>')">
                <i class="bi bi-person-plus"></i> Inscribir
              </a>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-secondary btn-action me-1" disabled title="Capacitación cerrada">
                <i class="bi bi-person-plus"></i> Inscribir
              </button>
            <?php endif; ?>

              <button class="btn btn-sm btn-outline-danger btn-action" onclick="eliminarCap(<?= (int)$c['clm_cap_id'] ?>)">
                <i class="bi bi-trash"></i> Cancelar
              </button>

              
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>


  </div>
</div>
        <hr>
  </div>


<!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
<a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
</a>

<!-- Modal: Nueva Capacitación (mejorado) -->
<div class="modal" id="modalNuevaCap" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cap-modal">
      <div class="cap-modal-header">
        <div class="cap-modal-title">
          <i class="bi bi-mortarboard"></i>
          <span>Nueva capacitación</span>
        </div>
      </div>

      <form id="formNuevaCap" method="post" action="api/capacitaciones_guardar.php" enctype="multipart/form-data">
        <div class="cap-modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nombre / Tema</label>
              <div class="input-with-icon">
                <i class="bi bi-card-text"></i>
                <input name="capacitacion" class="form-control" required maxlength="255" placeholder="Ej. Inducción SST para Operarios">
              </div>
              <div class="field-hint">Escribe un título claro y corto.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Estado</label>
              <div class="input-with-icon">
                <i class="bi bi-flag"></i>
                <select name="estado" class="form-select" required>
                  <option value="0">PROGRAMADA</option>
                  <option value="1">EN CURSO</option>
                  <option value="2">FINALIZADA</option>
                  <option value="3">CANCELADA</option>
                </select>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Fecha y hora de inicio</label>
              <div class="input-with-icon">
                <i class="bi bi-calendar-event"></i>
                <input type="datetime-local" name="fechainicio" id="cap_fechainicio" class="form-control" required>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Duración (minutos)</label>
              <div class="input-with-icon">
                <i class="bi bi-hourglass-split"></i>
                <!-- Visible en MINUTOS (sin name) -->
                <input type="number" id="cap_duracion_min" class="form-control"
                      min="1" step="1" placeholder="Ej. 24 min" required>
              </div>
              <!-- Oculto en HORAS para compatibilidad con tu backend -->
              <input type="hidden" name="duracion" id="cap_duracion">
              <div class="field-hint">Ingresa minutos (p. ej., 90). El fin se calcula automáticamente.</div>
            </div>

            <div class="col-12">
              <label class="form-label">Fin Estimado (Representativo)</label>
              <div class="input-with-icon">
                <i class="bi bi-clock-history"></i>
                <input type="text" id="cap_fin_preview" class="form-control"
                      placeholder="Se calcula automáticamente..." readonly>
              </div>
            </div>


            <div class="col-12">
              <label class="form-label">Observación (opcional)</label>
              <div class="input-with-icon">
                <i class="bi bi-pencil-square"></i>
                <textarea name="observacion" class="form-control" rows="3" placeholder="Notas, docente, sala, enlace de videollamada, etc."></textarea>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Documento adjunto (opcional)</label>
              <div class="input-with-icon">
                <i class="bi bi-paperclip"></i>
                <input type="file" name="documento" id="cap_documento" class="form-control"
                      accept=".pdf,image/*,.doc,.docx,.ppt,.pptx">
              </div>
              <div class="field-hint">PDF/imagen/Office. Máx. 10 MB.</div>
            </div>

            <div class="col-12">
              <div class="cap-preview">
                <span class="cap-chip"><b>Tema:</b> <span id="prevTema">—</span></span>
                <span class="cap-chip"><b>Estado:</b> <span id="prevEst">PROGRAMADA</span></span>
                <span class="cap-chip"><b>Inicio:</b> <span id="prevIni">—</span></span>
                <span class="cap-chip"><b>Fin Estimado:</b> <span id="prevFin">—</span></span>
                <span class="cap-chip"><b>Duración:</b> <span id="prevDur">—</span></span>
                <span class="cap-chip"><b>Documento:</b> <span id="prevDoc">—</span></span>

              </div>
            </div>
          </div>
        </div>

        <div class="cap-modal-footer">
          <button type="button" class="btn btn-light" id="capBtnLimpiar">
            <i class="bi bi-eraser"></i> Limpiar
          </button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            Cancelar
          </button>
          <button type="submit" class="btn btn-brand">
            <i class="bi bi-check2-circle"></i> Guardar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Inscribir personal -->
<div class="modal" id="modalInscribir" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <span class="cerrar" onclick="closeInscribir()">&times;</span>
      <h4 class="mb-3">Inscribir a: <span id="capNombreX" class="fw-bold"></span></h4>
      <input type="hidden" id="capIdX">

      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label">Buscar trabajador</label>
          <input id="buscarTrabX" class="form-control" placeholder="Nombre o DNI">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button class="btn btn-primary w-100" onclick="buscarTrabAJAX()">Buscar</button>
        </div>
      </div>

      <div class="mt-3">
        <div id="resTrabX" class="table-responsive" style="max-height:260px; overflow:auto; border:1px solid #eee; border-radius:8px;">
          <!-- resultados -->
        </div>
      </div>

      <div class="mt-3">
        <h6 class="mb-2">Seleccionados</h6>
        <div id="selChips" class="d-flex flex-wrap gap-2"></div>
      </div>

      <div class="d-flex justify-content-end gap-2 mt-3">
        <button class="btn btn-light" onclick="closeInscribir()">Cancelar</button>
        <button class="btn btn-success" onclick="enviarInscripciones()">Inscribir</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Detalle de capacitación -->
<div class="modal" id="modalDetalle" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <span class="cerrar" onclick="closeDetalle()">&times;</span>

      <div id="detHeader" class="mb-2">
        <h4 class="mb-1">Capacitación</h4>
        <div class="text-muted">Cargando...</div>
      </div>
      <!-- Acciones del detalle -->
      <div id="detActions" class="d-flex flex-wrap gap-2 justify-content-end mb-2" style="display:none;">
        <button id="btnFinalizarCap" class="btn btn-sm btn-success">
          <i class="bi bi-flag-checkered"></i> Finalizar ahora
        </button>
      </div>

      <div id="detResumen" class="row g-3 mb-2" style="display:none;">
        <div class="col-md-8">

          <div class="p-3 rounded" style="background:#f8fafc;border:1px solid #e9eef5;">
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <span id="detCapNombre" class="fw-bold fs-5"></span>
              <span id="detCapEstado" class="badge"></span>
            </div>
            <div class="mt-2 small">
              <div><b>Inicio:</b> <span id="detInicio"></span></div>
              <div><b>Fin:</b> <span id="detFin"></span> <span class="text-muted">(<span id="detDuracion"></span>)</span></div>
              <div class="mt-1"><b>Observación:</b> <span id="detObs" class="text-break"></span></div>
              <div class="mt-1"><b>Registrada:</b> <span id="detRegs"></span></div>
            </div>
          </div>
          
          <!-- Visor de documento — AHORA DENTRO DE LA COLUMNA -->
<div id="detDocContainer" class="mt-3 d-none">
  <div class="p-3 rounded" style="background:#f8fafc;border:1px solid #e9eef5;">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <div class="fw-bold"><i class="bi bi-paperclip me-1"></i> Documento adjunto</div>
      <div class="btn-group">
        <a id="detDocVerBtn"  class="btn btn-sm btn-primary" target="_blank"><i class="bi bi-eye"></i> Ver en pestaña</a>
        <a id="detDocDescBtn" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-download"></i> Descargar</a>
      </div>
    </div>
    <div class="ratio ratio-16x9">
      <iframe id="detDocFrame" title="Documento de la capacitación" style="border:0;"></iframe>
    </div>
    <div class="text-muted small mt-2">
      Si el archivo no se muestra aquí (p. ej., documentos de Office), usa “Ver en pestaña” o “Descargar”.
    </div>
  </div>
</div>

        </div>




          <div class="col-md-4">
          <div class="p-3 rounded" style="background:#f8fafc;border:1px solid #e9eef5;">
            <div class="fw-bold mb-2">Resumen de inscritos</div>
            <div id="detResumenBadges" class="d-flex flex-wrap gap-2"></div>
          </div>
        </div>
      </div>



      <div class="d-flex flex-wrap align-items-center gap-2 mt-3 mb-2" id="detFiltros" style="display:none;">
        <input id="detBuscador" class="form-control" style="max-width:320px" placeholder="Buscar por nombre o DNI">
        <select id="detFiltroEstado" class="form-select" style="max-width:220px">
          <option value="">Todos los estados</option>
          <option value="0">PENDIENTE</option>
          <option value="1">INSCRITO</option>
          <option value="2">APROBADO</option>
          <option value="3">REPROBADO</option>
          <option value="4">ASISTIÓ</option>
          <option value="5">NO ASISTIÓ</option>
        </select>
      </div>

      <div id="detTablaWrap" class="table-responsive" style="max-height:420px;overflow:auto;border:1px solid #eee;border-radius:8px;display:none;">
        <table class="table table-hover align-middle mb-0" id="detTabla">
          <thead class="table-light" style="position:sticky;top:0;z-index:1;">
            <tr>
              <th style="width:70px;">ID</th>
              <th>Nombre</th>
              <th style="width:130px;">DNI</th>
              <th>Cargo</th>
              <th style="width:140px;">Estado</th>
              <th>Obs.</th>
            </tr>
          </thead>
          <tbody><!-- rows --></tbody>
        </table>
      </div>

      <div id="detEmpty" class="text-center text-muted p-4" style="display:none;">Sin inscritos.</div>

      <div class="d-flex justify-content-end gap-2 mt-3">
        <button class="btn btn-light" onclick="closeDetalle()">Cerrar</button>
      </div>
    </div>
  </div>
</div>





</div>


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
<script>
  // Filtro por texto (nombre/estado)
  document.getElementById('filtroCap')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#tablaCap tbody tr').forEach(tr => {
      const text = tr.innerText.toLowerCase();
      tr.style.display = text.includes(q) ? '' : 'none';
    });
  });

  // Eliminar (confirmación + fetch)
  function eliminarCap(id) {
    if (!confirm('¿Cancelar esta capacitación? Esta acción no se puede deshacer.')) return;
    fetch('api/capacitaciones_eliminar.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    })
    .then(r => r.json())
    .then(json => {
      if (json?.ok) location.reload();
      else alert(json?.error || 'No se pudo cancelar.');
    })
    .catch(() => alert('Error de red.'));
  }
</script>

<script>
(function() {
  const inicioEl   = document.getElementById('cap_fechainicio');
  const durMinEl   = document.getElementById('cap_duracion_min');
  const finPreview = document.getElementById('cap_fin_preview');

  function pad(n){ return n < 10 ? '0'+n : n; }
  function toDisplay(dt) {
    return pad(dt.getDate()) + '/' + pad(dt.getMonth()+1) + '/' + dt.getFullYear()
      + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
  }

  function calcFinPreview() {
    const inicioVal = inicioEl?.value?.trim();
    const durMins   = parseInt(durMinEl?.value?.trim(), 10);
    if (!inicioVal || !Number.isFinite(durMins) || durMins <= 0) {
      finPreview.value = '';
      return;
    }
    const inicio = new Date(inicioVal);
    if (isNaN(inicio.getTime())) { finPreview.value=''; return; }

    // SUMA MINUTOS DIRECTAMENTE
    const fin = new Date(inicio.getTime() + (durMins * 60000));
    finPreview.value = toDisplay(fin);
  }

  inicioEl?.addEventListener('input', calcFinPreview);
  durMinEl?.addEventListener('input', calcFinPreview);
})();
</script>



<script>
(function () {
  const form     = document.getElementById('formNuevaCap');
  const tema     = form?.querySelector('input[name="capacitacion"]');
  const estado   = form?.querySelector('select[name="estado"]');
  const ini      = document.getElementById('cap_fechainicio');

  // minutos visible y horas oculto (para el POST)
  const durMinEl = document.getElementById('cap_duracion_min');
  const durHrsEl = document.getElementById('cap_duracion');

  const finPrev  = document.getElementById('cap_fin_preview');

  const prevTema = document.getElementById('prevTema');
  const prevEst  = document.getElementById('prevEst');
  const prevIni  = document.getElementById('prevIni');
  const prevFin  = document.getElementById('prevFin');
  const prevDur  = document.getElementById('prevDur');
  const docInput = document.getElementById('cap_documento');
  const prevDoc  = document.getElementById('prevDoc');
  const MAX_FILE = 10 * 1024 * 1024; // 10MB

  function humanSize(n){
    if (!Number.isFinite(n)) return '';
    const u = ['B','KB','MB','GB']; let i=0; while(n>=1024 && i<u.length-1){ n/=1024; i++; }
    return (Math.round(n*10)/10)+' '+u[i];
  }

  docInput?.addEventListener('change', () => {
    const f = docInput.files?.[0];
    if (!f) { prevDoc.textContent = '—'; return; }
    if (f.size > MAX_FILE) {
      alert('El archivo supera el máximo permitido (10 MB).');
      docInput.value = '';
      prevDoc.textContent = '—';
      return;
    }
    prevDoc.textContent = `${f.name} (${humanSize(f.size)})`;
  });

  // ... dentro del form submit, antes de enviar, valida el archivo:
  form?.addEventListener('submit', (e) => {
    const f = docInput?.files?.[0];
    if (f && f.size > MAX_FILE) {
      e.preventDefault();
      alert('El archivo supera el máximo permitido (10 MB).');
      return;
    }
    // (esto ya existía) minutos -> horas:
    const mins = parseInt(durMinEl?.value, 10);
    if (!Number.isFinite(mins) || mins <= 0) {
      e.preventDefault();
      alert('Ingresa una duración en minutos mayor a 0.');
      return;
    }
    const horas = Math.round((mins / 60) * 100) / 100;
    durHrsEl.value = horas;
  });

  const MAP_EST = {0:'PROGRAMADA',1:'EN CURSO',2:'FINALIZADA',3:'CANCELADA'};

  const pad = n => (n < 10 ? '0' + n : n);
  function fmt(dt){
    return pad(dt.getDate())+'/'+pad(dt.getMonth()+1)+'/'+dt.getFullYear()+' '+pad(dt.getHours())+':'+pad(dt.getMinutes());
  }
  function humanizeMinutes(mins){
    const m = parseInt(mins||0,10);
    return m > 0 ? `${m} min` : '—';
  }

  function paint(){
    prevTema.textContent = (tema?.value || '').trim() || '—';
    prevEst.textContent  = MAP_EST[estado?.value] ?? '—';

    if (ini?.value) {
      const d = new Date(ini.value);
      prevIni.textContent = isNaN(d) ? '—' : fmt(d);
    } else prevIni.textContent = '—';

    prevFin.textContent = finPrev?.value || '—';
    prevDur.textContent = humanizeMinutes(durMinEl?.value);
  }

  [tema, estado, ini, durMinEl, finPrev].forEach(el => el && el.addEventListener('input', paint));

  document.getElementById('capBtnLimpiar')?.addEventListener('click', () => {
    form.reset();
    if (finPrev) finPrev.value = '';
    paint();
  });

  // Al enviar: traduce MINUTOS -> HORAS (con 2 decimales) en el campo oculto name="duracion"
  form?.addEventListener('submit', (e) => {
    const mins = parseInt(durMinEl?.value, 10);
    if (!Number.isFinite(mins) || mins <= 0) {
      e.preventDefault();
      alert('Ingresa una duración en minutos mayor a 0.');
      return;
    }
    const horas = Math.round((mins / 60) * 100) / 100; // 2 decimales
    durHrsEl.value = horas; // este es el que viaja como name="duracion"
  });

  paint(); // inicial
})();
</script>




<script>
let SEL_TRAB = new Map(); // id -> {id, nombre, dni, cargo}

function openInscribir(capId, capNombre) {
  SEL_TRAB.clear();
  document.getElementById('capIdX').value = capId;
  document.getElementById('capNombreX').textContent = capNombre;
  document.getElementById('buscarTrabX').value = '';
  document.getElementById('resTrabX').innerHTML = '';
  renderChips();
  // mostrar modal
  document.getElementById('modalInscribir').style.display = 'block';
}

function closeInscribir() {
  document.getElementById('modalInscribir').style.display = 'none';
}

function renderChips() {
  const cont = document.getElementById('selChips');
  cont.innerHTML = '';
  if (SEL_TRAB.size === 0) {
    cont.innerHTML = '<span class="text-muted">Nadie seleccionado aún.</span>';
    return;
  }
  SEL_TRAB.forEach(t => {
    const chip = document.createElement('span');
    chip.className = 'badge bg-primary d-flex align-items-center gap-2';
    chip.style.padding = '10px';
    chip.innerHTML = `
      <i class="bi bi-person-circle"></i>
      ${t.nombre} <small class="opacity-75">(${t.dni || 's/dni'})</small>
      <button type="button" class="btn btn-sm btn-light" style="border-radius:10px; padding:2px 6px"
              title="Quitar" onclick="quitarSel(${t.id})">&times;</button>`;
    cont.appendChild(chip);
  });
}

function quitarSel(id) {
  SEL_TRAB.delete(id);
  renderChips();
}

function addSel(t) {
  if (t.inscrito) {
    alert('Este trabajador ya está inscrito en esta capacitación.');
    return;
  }
  if (!SEL_TRAB.has(t.id)) {
    SEL_TRAB.set(t.id, {id: t.id, nombre: t.nombres, dni: t.dni, cargo: t.cargo});
    renderChips();
  }
}

function buscarTrabAJAX() {
  const q = document.getElementById('buscarTrabX').value.trim();
  const capid = document.getElementById('capIdX').value;
  if (q.length < 2) {
    alert('Escribe al menos 2 caracteres para buscar.');
    return;
  }
  const url = 'api/capacitaciones_buscar_trabajadores.php?q=' + encodeURIComponent(q) + '&capid=' + encodeURIComponent(capid);
  fetch(url, {headers:{'Accept':'application/json'}})
    .then(r => r.json())
    .then(json => {
      if (!Array.isArray(json)) throw new Error('Respuesta inesperada');
      renderResultados(json);
    })
    .catch(() => {
      document.getElementById('resTrabX').innerHTML = '<div class="p-3 text-danger">Error al buscar.</div>';
    });
}

function renderResultados(items) {
  const wrap = document.getElementById('resTrabX');
  if (!items.length) {
    wrap.innerHTML = '<div class="p-3 text-muted">Sin resultados.</div>';
    return;
  }
  let html = `<table class="table table-sm align-middle mb-0">
    <thead><tr>
      <th style="width:80px">ID</th>
      <th>Nombre</th>
      <th style="width:120px">DNI</th>
      <th>Cargo</th>
      <th style="width:110px">Acción</th>
    </tr></thead><tbody>`;
  for (const t of items) {
    const disabled = t.inscrito ? 'disabled' : '';
    const label    = t.inscrito ? 'Inscrito' : 'Agregar';
    html += `<tr>
      <td class="fw-semibold">#${t.id}</td>
      <td>${escapeHtml(t.nombres)}</td>
      <td>${t.dni ? escapeHtml(t.dni) : '<span class="text-muted">—</span>'}</td>
      <td>${t.cargo ? escapeHtml(t.cargo) : '<span class="text-muted">—</span>'}</td>
      <td>
        <button class="btn btn-sm ${t.inscrito ? 'btn-secondary' : 'btn-success'}"
                ${disabled}
                onclick='addSel(${JSON.stringify(t)})'>
          <i class="bi bi-plus-circle"></i> ${label}
        </button>
      </td>
    </tr>`;
  }
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function enviarInscripciones() {
  const capid = document.getElementById('capIdX').value;
  const ids = Array.from(SEL_TRAB.keys());
  if (!capid) return alert('Capacitación no válida.');
  if (ids.length === 0) return alert('Selecciona al menos un trabajador.');

  fetch('api/capacitaciones_inscribir.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'Accept':'application/json'},
    body: JSON.stringify({ capid: capid, trab_ids: ids })
  })
  .then(r => r.json())
  .then(json => {
    if (json?.ok) {
      // Opcional: refrescar contador en la fila; por simplicidad, recargamos
      location.reload();
    } else {
      alert(json?.error || 'No se pudo inscribir.');
    }
  })
  .catch(() => alert('Error de red.'));
}
</script>


<script>
let DET_CAP_ID = null; // id actual cargado en el modal

function finalizarCapacitacion() {
  if (!DET_CAP_ID) return;
  if (!confirm('¿Finalizar esta capacitación ahora? Se registrará la hora actual.')) return;

  const btn = document.getElementById('btnFinalizarCap');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Finalizando...';
  }

  fetch('api/capacitaciones_finalizar.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Accept': 'application/json'
    },
    body: 'id=' + encodeURIComponent(DET_CAP_ID)
  })
  .then(r => r.json())
  .then(json => {
    if (json?.ok) {
      // refresca el detalle
      openDetalle(DET_CAP_ID);
      // intenta actualizar la fila en la lista principal (sin recargar)
      try { actualizarFilaListadoComoFinalizada(DET_CAP_ID); } catch(e){}
    } else {
      alert(json?.error || 'No se pudo finalizar.');
    }
  })
  .catch(() => alert('Error de red.'))
  .finally(() => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-flag-checkered"></i> Finalizar ahora';
    }
  });
}

function actualizarFilaListadoComoFinalizada(capId) {
  const rows = document.querySelectorAll('#tablaCap tbody tr');
  for (const tr of rows) {
    const idCell = tr.querySelector('td.fw-semibold');
    if (!idCell) continue;
    if ((idCell.textContent || '').trim() === '#' + capId) {
      const tds = tr.querySelectorAll('td');
      const finTd = tds[4];
      const estadoTd = tds[5];
      const accionesTd = tds[7];

      const now = new Date();
      const pad = n => (n<10?'0'+n:n);
      const str = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
      finTd.innerHTML = str;
      estadoTd.innerHTML = '<span class="badge bg-success">FINALIZADA</span>';

      const insBtn = accionesTd.querySelector('.btn-outline-success');
      if (insBtn) {
        const disabled = document.createElement('button');
        disabled.className = 'btn btn-sm btn-outline-secondary btn-action me-1';
        disabled.disabled = true;
        disabled.title = 'Capacitación cerrada';
        disabled.innerHTML = '<i class="bi bi-person-plus"></i> Inscribir';
        insBtn.replaceWith(disabled);
      }
      break;
    }
  }
}

</script>




<script>
function openDetalle(capId) {
  DET_CAP_ID = capId; // <--- guarda el ID actual

  document.getElementById('modalDetalle').style.display = 'block';
  document.querySelector('#detHeader .text-muted').textContent = 'Cargando...';

  // limpia vistas
  document.getElementById('detResumen').style.display   = 'none';
  document.getElementById('detTablaWrap').style.display = 'none';
  document.getElementById('detFiltros').style.display   = 'none';
  document.getElementById('detEmpty').style.display     = 'none';

  fetch('api/capacitaciones_detalle.php?id=' + encodeURIComponent(capId), {headers:{'Accept':'application/json'}})
    .then(r => r.json())
    .then(json => {
      if (!json?.ok) throw new Error(json?.error || 'Error');
      renderDetalle(json);
    })
    .catch(err => {
      document.querySelector('#detHeader .text-muted').textContent = 'Error al cargar.';
      console.error(err);
    });
}


function closeDetalle() {
  document.getElementById('modalDetalle').style.display = 'none';
}

function humanizeMin(min){
  min = parseInt(min||0,10);
  if (!min) return '—';
  const h = Math.floor(min/60), m = min%60;
  if (h && m) return `${h}h ${m}m`;
  if (h) return `${h}h`;
  return `${m}m`;
}

function renderDetalle(data) {
  const cap = data.capacitacion;
  const lista = Array.isArray(data.inscritos) ? data.inscritos : [];
  const resumen = data.resumen || {total:0, por_estado:[]};

// ===== Documento adjunto =====
const docBox   = document.getElementById('detDocContainer');
const docFrame = document.getElementById('detDocFrame');
const verBtn   = document.getElementById('detDocVerBtn');
const descBtn  = document.getElementById('detDocDescBtn');

if (cap.has_doc) {
  const base = 'api/capacitaciones_ver_documento.php?id=' + encodeURIComponent(cap.id);
  docBox.classList.remove('d-none');              // ← muestra el bloque
  docFrame.src = base + '&disposition=inline&v=' + Date.now(); // ← pinta el iframe
  verBtn.href  = base + '&disposition=inline';    // ← ver en pestaña
  descBtn.href = base + '&disposition=attachment';// ← descargar
} else {
  docBox.classList.add('d-none');
  if (docFrame) docFrame.src = 'about:blank';
}

  // Header
  document.querySelector('#detHeader h4').textContent = 'Capacitación #' + cap.id;
  document.querySelector('#detHeader .text-muted').textContent = '';
  // === Acciones ===
  const actions = document.getElementById('detActions');
  actions.innerHTML = '';
  // Necesitamos un código de estado numérico para decidir si se muestra el botón.
  // Asegúrate de que el endpoint devuelva cap.estado como entero (0..3).
  const estCode = typeof cap.estado === 'number' ? cap.estado : null;

  if (estCode !== 2 && estCode !== 3) {
    actions.style.display = 'flex';
    actions.innerHTML = `
      <button id="btnFinalizarCap" class="btn btn-sm btn-success">
        <i class="bi bi-flag-checkered"></i> Finalizar ahora
      </button>`;
    document.getElementById('btnFinalizarCap').onclick = finalizarCapacitacion;
  } else {
    actions.style.display = 'none';
  }
  // Card resumen izquierda
  document.getElementById('detCapNombre').textContent = cap.nombre || '—';
  const estBadge = document.getElementById('detCapEstado');
  estBadge.className = 'badge bg-' + (cap.estado_badge || 'secondary');
  estBadge.textContent = cap.estado_texto || '—';

  document.getElementById('detInicio').textContent = fmtFecha(cap.fechainicio);
  document.getElementById('detFin').textContent    = cap.fechafin ? fmtFecha(cap.fechafin) : '—';
  document.getElementById('detDuracion').textContent = cap.duracion_min ? humanizeMin(cap.duracion_min) : '—';
  document.getElementById('detObs').textContent    = cap.observacion || '—';
  document.getElementById('detRegs').textContent   = fmtFecha(cap.fechareg);


  // Card resumen derecha (badges por estado)
  const wrapBadges = document.getElementById('detResumenBadges');
  wrapBadges.innerHTML = '';
  const badgeTotal = document.createElement('span');
  badgeTotal.className = 'badge text-bg-dark';
  badgeTotal.textContent = 'TOTAL ' + (resumen.total ?? 0);
  wrapBadges.appendChild(badgeTotal);

  (resumen.por_estado || []).forEach(x => {
    const b = document.createElement('span');
    b.className = 'badge bg-' + (x.badge || 'secondary');
    b.textContent = x.texto + ' ' + x.cantidad;
    wrapBadges.appendChild(b);
  });

  document.getElementById('detResumen').style.display = 'flex';

  // Tabla
  const tbody = document.querySelector('#detTabla tbody');
  tbody.innerHTML = '';
  if (!lista.length) {
    document.getElementById('detEmpty').style.display = 'block';
    return;
  }
  document.getElementById('detEmpty').style.display = 'none';

  lista.forEach(t => {
    const tr = document.createElement('tr');
    tr.dataset.estado = String(t.estado ?? '');
    tr.dataset.search = (t.nombres + ' ' + (t.dni||'')).toLowerCase();

    tr.innerHTML = `
      <td class="fw-semibold">#${t.trab_id}</td>
      <td>${esc(t.nombres)}</td>
      <td>${t.dni ? esc(t.dni) : '<span class="text-muted">—</span>'}</td>
      <td>${t.cargo ? esc(t.cargo) : '<span class="text-muted">—</span>'}</td>
      <td class="td-estado" data-trabid="${t.trab_id}" data-estado="${t.estado ?? ''}" title="Click para cambiar estado">
        <span class="badge bg-${t.estado_badge || 'secondary'}">${esc(t.estado_texto || '')}</span>
      </td>      
      <td>${t.obs ? esc(t.obs) : '<span class="text-muted">—</span>'}</td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('detTablaWrap').style.display = 'block';
  document.getElementById('detFiltros').style.display   = 'flex';

  // Filtros
// === Editor inline de estado ===
const ESTADOS_TR = {
  0: { txt: 'PENDIENTE',  badge: 'secondary' },
  1: { txt: 'INSCRITO',   badge: 'info' },
  2: { txt: 'APROBADO',   badge: 'success' },
  3: { txt: 'REPROBADO',  badge: 'danger' },
  4: { txt: 'ASISTIÓ',    badge: 'primary' },
  5: { txt: 'NO ASISTIÓ', badge: 'warning' }
};

function recalcResumen(){
  const counts = {0:0,1:0,2:0,3:0,4:0,5:0};
  let total = 0;
  document.querySelectorAll('#detTabla tbody tr').forEach(tr=>{
    const td = tr.querySelector('td.td-estado');
    if (!td) return;
    const e = parseInt(td.dataset.estado ?? 'NaN',10);
    if (!Number.isNaN(e)) { counts[e]++; total++; }
  });
  const wrap = document.getElementById('detResumenBadges');
  if (!wrap) return;
  wrap.innerHTML = '';
  const badgeTotal = document.createElement('span');
  badgeTotal.className = 'badge text-bg-dark';
  badgeTotal.textContent = 'TOTAL ' + total;
  wrap.appendChild(badgeTotal);
  [0,1,2,3,4,5].forEach(k=>{
    const b = document.createElement('span');
    b.className = 'badge bg-' + ESTADOS_TR[k].badge;
    b.textContent = ESTADOS_TR[k].txt + ' ' + counts[k];
    wrap.appendChild(b);
  });
}

// Permite editar (bloquea si la capacitación está CANCELADA = 3)
window.DET_CAP_ESTADO = estCode; // ya calculado arriba
const tbodyEl = document.querySelector('#detTabla tbody');
tbodyEl.addEventListener('click', function(e){
  const td = e.target.closest('td.td-estado');
  if (!td) return;
  if (window.DET_CAP_ESTADO === 3) {
    alert('Capacitación cancelada: no se pueden editar estados.');
    return;
  }
  if (td.querySelector('select')) return; // ya editando

  const trabId = parseInt(td.dataset.trabid,10);
  const valorActual = String(td.dataset.estado ?? '');

  const select = document.createElement('select');
  select.className = 'form-select form-select-sm';
  Object.entries(ESTADOS_TR).forEach(([val,info])=>{
    const opt = document.createElement('option');
    opt.value = val;
    opt.textContent = info.txt;
    if (String(val) === valorActual) opt.selected = true;
    select.appendChild(opt);
  });

  const oldHTML = td.innerHTML;
  td.innerHTML = '';
  td.appendChild(select);
  select.focus();

  function cancelar(){
    td.innerHTML = oldHTML;
  }
  function commit(){
    const nuevo = parseInt(select.value,10);
    if (String(nuevo) === valorActual) { cancelar(); return; }

    td.classList.add('opacity-50');
    fetch('api/capacitaciones_cambiar_estado.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({ capid: DET_CAP_ID, trab_id: trabId, estado: nuevo })
    })
    .then(async (r) => {
      const raw = await r.text();              // <- leemos como texto SIEMPRE
      // console.debug('RESP cambiar_estado', r.status, raw); // descomenta para debug
      let data;
      try {
        data = raw ? JSON.parse(raw) : {};     // <- parse seguro
      } catch (e) {
        throw { type: 'BAD_JSON', status: r.status, raw };
      }
      if (!r.ok || data?.ok === false) {       // <- si HTTP no OK o ok=false del server
        throw { type: 'HTTP', status: r.status, data };
      }
      return data;
    })
    .then((json) => {
      const meta = ESTADOS_TR[nuevo] || {txt:'',badge:'secondary'};
      td.dataset.estado = String(nuevo);
      td.innerHTML = `<span class="badge bg-${meta.badge}">${meta.txt}</span>`;
      const tr = td.closest('tr');
      if (tr) tr.dataset.estado = String(nuevo);
      recalcResumen();
    })
    .catch((err) => {
      console.error('cambiar_estado error:', err);
      if (err.type === 'BAD_JSON') {
        alert('Respuesta inválida del servidor (no es JSON). Revisa la consola.');
      } else if (err.type === 'HTTP') {
        alert(err.data?.error || ('Error del servidor ' + err.status));
      } else {
        alert('Error de red.');
      }
      cancelar();
    })
    .finally(() => td.classList.remove('opacity-50'));

  }

  select.addEventListener('change', commit);
  select.addEventListener('blur', cancelar);
  select.addEventListener('keydown', (ev)=>{
    if (ev.key === 'Enter') { ev.preventDefault(); commit(); }
    if (ev.key === 'Escape') { ev.preventDefault(); cancelar(); }
  });
});

  const busc = document.getElementById('detBuscador');
  const sel  = document.getElementById('detFiltroEstado');
  function applyFilters() {
    const q = (busc.value || '').toLowerCase().trim();
    const e = sel.value;
    document.querySelectorAll('#detTabla tbody tr').forEach(tr => {
      const byTxt = tr.dataset.search.includes(q);
      const byEst = e === '' || tr.dataset.estado === e;
      tr.style.display = (byTxt && byEst) ? '' : 'none';
    });
  }
  busc.oninput = applyFilters;
  sel.onchange = applyFilters;
}

function esc(s){return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));}
function pad(n){return n<10?'0'+n:n;}
function fmtFecha(val){
  if(!val) return '—';
  const d = new Date(val.replace(' ', 'T'));
  if(isNaN(d)) return val;
  return pad(d.getDate())+'/'+pad(d.getMonth()+1)+'/'+d.getFullYear()+' '+pad(d.getHours())+':'+pad(d.getMinutes());
}
function calcDur(ini, fin){
  const a = new Date(ini.replace(' ', 'T'));
  const b = new Date(fin.replace(' ', 'T'));
  if(isNaN(a) || isNaN(b)) return '—';
  const diffMin = Math.round((b - a)/60000);
  const h = Math.floor(diffMin/60), m = diffMin%60;
  if(h && m) return `${h}h ${m}m`;
  if(h) return `${h}h`;
  return `${m}m`;
}
</script>
<script>
function verDocCap(id) {
  // Abre el documento (si existe) en una nueva pestaña.
  // Si no existe, el endpoint debería responder con error o página vacía.
  const url = 'api/capacitaciones_ver_documento.php?id=' + encodeURIComponent(id) + '&disposition=inline';
  window.open(url, '_blank');
}
</script>

</body>


</html>