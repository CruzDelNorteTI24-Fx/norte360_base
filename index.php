<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}
//hola
define('ACCESS_GRANTED', true);
require_once("trash/copidb_secure copy.php");

define('N360_LAYOUT', true);
define('N360_BASE_URL', './');

require_once __DIR__ . '/layout/sidebar_n360.php';
require_once __DIR__ . '/layout/header_n360.php';
require_once __DIR__ . '/layout/footer_n360.php';
require_once __DIR__ . '/layout/content_n360.php';

$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Norte360 | Panel Principal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="icon" href="img/norte360.png">    
    <style>
        body {
  background: linear-gradient(to bottom right, #ecf0f1, #d9e2ec);
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

@media (max-width: 600px) {
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

.admin-access-card {
    position: relative;
    border: 1px solid rgba(35, 137, 201, 0.22);
}

.admin-access-card__icon {
    width: 116px;
    height: 116px;
    border-radius: 8px;
    margin-bottom: 18px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #17283a 0%, #2389c9 100%);
    color: #fff;
    font-size: 48px;
    box-shadow: 0 14px 26px rgba(23, 40, 58, 0.18);
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

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.product-card:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}
.btn-validar {
    background: linear-gradient(120deg, #2980b9 30%, #3498db 50%, #2980b9 70%);
    background-size: 200% auto;
    color: white;
    padding: 8px 25px; /* reducido */
    font-size: 14px; /* reducido */
    border: none;
    border-radius: 8px;
    cursor: pointer;
    width: auto; /* para que no ocupe todo el ancho */
    animation: shimmer 4s infinite linear;
    transition: transform 0.3s ease;
    text-decoration: none; /* elimina subrayado */
    display: inline-block; /* para que respete el tamaño de contenido */
    margin-top: 10px;
}

.btn-validar:hover {
    transform: scale(1.05);
    text-decoration: none; /* asegura sin subrayado en hover */
}

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

<?php n360_render_header([
    'title' => 'Panel principal',
    'subtitle' => 'Resumen general del sistema',
]); ?>

<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--dashboard" role="main">
    <div class="n360-main__inner">
        <?php n360_render_content_separator('top'); ?>

<?php
date_default_timezone_set('America/Lima');


$hora = date("H");

if ($hora >= 5 && $hora < 12) {
    $saludo = "Buenos días";
} elseif ($hora >= 12 && $hora < 18) {
    $saludo = "Buenas tardes";
} else {
    $saludo = "Buenas noches";
}

$frases = [
    "¡Hoy es un gran día para alcanzar metas!",
    "¡Cada paso que das te acerca al éxito!",
    "¡Gracias por tu compromiso con Norte360!",
    "¡Juntos hacemos posible lo imposible!",
    "Un cafecito y como nuev@",
    "Atrévete ;)"
];

$mensaje_extra = $frases[array_rand($frases)];
?>

<div style="background: linear-gradient(90deg, #2980b9, #3498db); color:white; padding: 30px 20px; border-radius: 12px; text-align: center; box-shadow: 0 6px 14px rgba(0,0,0,0.1); margin-bottom: 30px; animation: fadeIn 1s ease;">
    <h1 style="font-size: 36px; margin: 0 0 10px 0;"><?= $saludo ?>, <?= htmlspecialchars($_SESSION['nombre']) ?> 👋</h1>
    <p style="font-size: 18px; margin: 0;"><?= $mensaje_extra ?></p>
</div>



    <p style="text-align:center; font-size:18px; color:#555;">Selecciona un módulo para iniciar</p>

<div class="catalogo-container">
<?php
if (n360_is_admin()) {
  echo '
  <div class="product-card admin-access-card">
    <div class="admin-access-card__icon" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></div>
    <h4>Permisos y accesos</h4>
    <p>Mapa de modulos, interfaces y usuarios</p>
    <a href="admin/permisos_mapa.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_modulo(6)) {
  echo '
  <div class="product-card">
    <img src="img/icons/entrevistas.png" alt="Entrevistas">
    <h4>Entrevistas</h4>
    <p>Gestiona entrevistas de personal</p>
    <a href="01_entrevistas/reentrev.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_modulo(6)) {
  echo '
  <div class="product-card">
    <img src="img/icons/personal-information.png" alt="Personal">
    <h4>Recursos Humanos</h4>
    <p>Visualiza y registra trabajadores</p>
    <a href="01_contratos/nlaskdrcdn_h.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_modulo(5)) {
  echo '
  <div class="product-card">
    <img src="img/icons/mantenimiento.png" alt="Mantenimiento">
    <h4>Mantenimiento</h4>
    <p>Programación y seguimiento de buses</p>
    <a href="01_amantenimiento/lista_cheklist.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_modulo(3)) {
  echo '
  <div class="product-card">
    <img src="img/icons/almacen.png" alt="Inventario">
    <h4>Inventario</h4>
    <p>Gestiona almacén y productos</p>
    <a href="01_almacen/scanner.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_vista('f-progcond')) {
  echo '
  <div class="product-card">
    <img src="img/icons/roles.png" alt="Roles">
    <h4>Conductores</h4>
    <p>Programación de Conductores</p>
    <a href="01_flota/programacion_condt.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_vista('f-proghor')) {
  echo '
  <div class="product-card">
    <img src="img/icons/calendario.png" alt="Roles">
    <h4>Programación</h4>
    <p>Programación Horarios</p>
    <a href="01_flota/programacion_horarios.php" class="btn-validar">Ingresar</a>
  </div>';
}

if (n360_puede_alguna_vista(['f-placas', 'f-flotas'])) {
  echo '
  <div class="product-card">
    <img src="img/icons/placa.png" alt="Roles">
    <h4>Vehículos</h4>
    <p>Gestión de Placas</p>
    <a href="01_flota/gest_plac.php" class="btn-validar">Ingresar</a>
  </div>';
}
?>


</div>




    <!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
    <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
        <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
    </a>
        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>




<?php n360_render_footer(); ?>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>



</html>

