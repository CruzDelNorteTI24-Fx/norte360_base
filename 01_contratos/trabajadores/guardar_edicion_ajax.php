<?php
session_start();
if ($_SESSION['web_rol'] !== 'Admin') exit('No autorizado');

define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");

$id = intval($_POST['id']);
$campo = $_POST['campo'];
$valor = trim($_POST['valor']);

$permitidos = [
    'clm_tra_celular',
    'clm_tra_correo',
    'clm_tra_sexo',
    'clm_tra_domicilio',
    'clm_tra_cargo',
    'clm_tra_tipo_trabajador',
    'clm_tra_dni',
    'clm_tra_fecha_nacimiento',
    'clm_tra_dni_fecha_emision',
    'clm_tra_dni_fecha_caducidad',
    'clm_tra_ubigeo',
    'clm_tra_nlicenciaconducir',
    'clm_tra_tipolicencia',
    'clm_tra_categorialicen',
    'clm_tra_licfecha_expedicion',
    'clm_tra_licfecha_revaluacion'
];

if (!in_array($campo, $permitidos)) exit('Campo no permitido');

/* VALIDACIONES ESPECÍFICAS */
if ($campo === 'clm_tra_tipo_trabajador') {
    $tiposPermitidos = ['Conductor', 'Personal'];
    if (!in_array($valor, $tiposPermitidos, true)) {
        exit('Tipo de trabajador no permitido');
    }
}

if ($campo === 'clm_tra_sexo') {
    $sexosPermitidos = ['Masculino', 'Femenino'];
    if (!in_array($valor, $sexosPermitidos, true)) {
        exit('Sexo no permitido');
    }
}

$stmt = $conn->prepare("UPDATE tb_trabajador SET $campo = ? WHERE clm_tra_id = ?");
$stmt->bind_param("si", $valor, $id);
$stmt->execute();

echo "OK";