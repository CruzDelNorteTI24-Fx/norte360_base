<?php
session_start();

// Si no hay sesión, redirige a login
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

// Define roles permitidos por vista
$accesos = [
    'checklist' => ['Admin', 'Usuario'], // ejemplo: usuarios con web_rol Usuario pueden ver checklist
    'dashboard' => ['Admin'], // solo admin puede ver dashboard
    'reportes' => ['Admin'],
    // agrega aquí más vistas y sus roles permitidos
];

// Obtiene la vista actual desde el nombre del archivo que incluye este script
$vista_actual = basename($_SERVER['PHP_SELF'], ".php");

// Verifica acceso
if (isset($accesos[$vista_actual])) {
    if (!in_array($_SESSION['web_rol'], $accesos[$vista_actual])) {
        // Si no tiene acceso, redirige a página sin permiso o al inicio permitido
        header("Location: ../no_permiso.php"); // crea esta página con un mensaje de acceso denegado
        exit();
    }
}
// Si la vista no está definida en $accesos, permite acceso por defecto (opcional)
?>
