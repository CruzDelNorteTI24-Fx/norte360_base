<?php
date_default_timezone_set('America/Lima');

if (!defined('ACCESS_GRANTED')) {
    die("Acceso no autorizado.");
}

// Cargar variables desde .config.env
require_once(__DIR__ . "/loadenv.php");

loadEnv(__DIR__ . '/../.config.env');

$host = $_ENV['DB_HOST'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$port = $_ENV['DB_PORT'] ?? '';

$conn = new mysqli($host, $user, $pass, $name, (int)$port);

if ($conn->connect_error) {
    error_log("Error de conexión: " . $conn->connect_error);
    die("No se pudo conectar a la base de datos.");
}

// Opcional: charset seguro
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '-05:00'");
?>
