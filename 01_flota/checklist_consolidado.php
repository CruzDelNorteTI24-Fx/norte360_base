<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

header('Location: ../01_amantenimiento/checklist_consolidado.php');
exit();
