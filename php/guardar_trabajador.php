<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
session_start();

// CSRF PROTECTION
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    die("CSRF detectado. Solicitud rechazada.");
}
unset($_SESSION['csrf_token']);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // === GENERAL ===
    $nombre      = $_POST['nombre'] ?? '';
    $tipo        = $_POST['tipo_trabajador'] ?? '';
    $cargo       = $_POST['puesto'] ?? '';
    $sexo        = $_POST['sexo'] ?? '';
    $observacion = $_POST['observaciones'] ?? '';
    $celular     = $_POST['celular'] ?? '';
    $fechahorahoy = date('Y-m-d');
    $correo      = $_POST['correo'] ?? '';
    $domicilio   = $_POST['domicilio'] ?? '';
    $fecha_registro = date("Y-m-d H:i:s"); // Hora exacta del servidor (Perú)
    $usuarioreg  = $_SESSION['id_usuario'];
    $id_entrevista  = isset($_POST['id_entrevista']) ? (int) $_POST['id_entrevista'] : null;
    // === RÉGIMEN (Planilla / RPH) ===
    // Si no llega el POST (por defecto Planilla)
    $regimen = isset($_POST['regimen']) ? strtoupper(trim($_POST['regimen'])) : 'PLANILLA';
    // Mapeo: PLANILLA => 1, RPH => 2
    $clm_pl_tipo = ($regimen === 'RPH') ? 2 : 1;

    // === DNI ===
    $dni              = $_POST['dni'] ?? '';
    $ubigeo           = $_POST['ubigeo'] ?? '';
    $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? '';
    $fecha_emision    = $_POST['fecha_dni_emision'] ?? '';
    $fecha_caducidad  = $_POST['fecha_dni_caducidad'] ?? '';

    // === LICENCIA ===
    $checkLicencia = isset($_POST['check_licencia']);

    if ($checkLicencia) {
        $nlicencia       = $_POST['nlicencia'] ?? '';
        $tipo_licencia   = $_POST['tipo_licencia'] ?? '';
        $categoria       = $_POST['categoría_licencia'] ?? '';
        $lic_expedicion  = !empty($_POST['fecha_licencia_expedicion']) ? $_POST['fecha_licencia_expedicion'] : null;
        $lic_revaluacion = !empty($_POST['fecha_licencia_revaluacion']) ? $_POST['fecha_licencia_revaluacion'] : null;
    } else {
        $nlicencia = null;
        $tipo_licencia = null;
        $categoria = null;
        $lic_expedicion = null;
        $lic_revaluacion = null;
    }

    // === ARCHIVOS ===
    function leerArchivo($campo) {
        return (isset($_FILES[$campo]) && $_FILES[$campo]['error'] === UPLOAD_ERR_OK)
            ? file_get_contents($_FILES[$campo]['tmp_name'])
            : null;
    }

    $cvpdf     = leerArchivo('cv_pdf');
    $dni_foto1 = leerArchivo('img_dni_frontal');
    $dni_foto2 = leerArchivo('img_dni_trasera');


    if ($checkLicencia) {
        $lic_foto1 = leerArchivo('img_lic_frontal');
        $lic_foto2 = leerArchivo('img_lic_trasera');
    } else {
        $lic_foto1 = null;
        $lic_foto2 = null;
    }

    $img_personal = leerArchivo('img_personal');

    // === INSERT PRINCIPAL ===
    $stmt = $conn->prepare("INSERT INTO tb_trabajador (
        clm_tra_nombres, clm_tra_tipo_trabajador, clm_tra_cargo, clm_tra_sexo, clm_tra_observaciones,
        clm_tra_dni, clm_tra_ubigeo, clm_tra_fecha_nacimiento, clm_tra_dni_fecha_emision, clm_tra_dni_fecha_caducidad,
        clm_tra_correo, clm_tra_domicilio, clm_tra_celular,
        clm_tra_cvpdf, clm_tra_dni_foto1, clm_tra_dni_foto2,
        clm_tra_nlicenciaconducir, clm_tra_tipolicencia, clm_tra_categorialicen,
        clm_tra_licfecha_expedicion, clm_tra_licfecha_revaluacion, clm_tra_lic_foto1, clm_tra_lic_foto2, clm_tra_imagen,
        clm_tra_fehoregistro, clm_tra_iduseregister, clm_tra_identrevista
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param("sssssssssssssbbbsssssbbbsii",
        $nombre, $tipo, $cargo, $sexo, $observacion,
        $dni, $ubigeo, $fecha_nacimiento, $fecha_emision, $fecha_caducidad,
        $correo, $domicilio, $celular,
        $cvpdf, $dni_foto1, $dni_foto2,
        $nlicencia, $tipo_licencia, $categoria,
        $lic_expedicion, $lic_revaluacion, $lic_foto1, $lic_foto2, $img_personal,
        $fecha_registro, $usuarioreg, $id_entrevista        
    );

    $stmt->send_long_data(10, $cvpdf);
    $stmt->send_long_data(11, $dni_foto1);
    $stmt->send_long_data(12, $dni_foto2);
    $stmt->send_long_data(18, $lic_foto1);
    $stmt->send_long_data(19, $lic_foto2);
    $stmt->send_long_data(20, $img_personal);

    $stmt->execute();

    // 👇 Insertar familiares después del trabajador
    $id_trabajador = $conn->insert_id;

    if ($id_entrevista) {
        $stmt_estado = $conn->prepare("UPDATE entrevistas SET clm_estado = 5 WHERE id_entrevista = ?");
        $stmt_estado->bind_param("i", $id_entrevista); // Ahora sí es entero real
        $stmt_estado->execute();
        $stmt_estado->close();
    }

    $_POST['trabajador_id'] = $id_trabajador;
    include("guardar_familiares_trabajador.php");
    $stmt->close();


    // === PLANILLA: crear fila en tb_tpln ===
    // Reglas: clm_pl_tipo: 1=Planilla, 2=RPH
    // Estados iniciales sugeridos (ajusta si usas otros): VIGENTE
    // === PLANILLA: crear fila en tb_tpln ===
    $pl_estado     = 1; // 1=VIGENTE
    $fecha_ingreso = date('Y-m-d');
    $fecha_reg     = date('Y-m-d H:i:s');
    $comentario    = 'Alta automática desde registro de trabajador';

    $sql_tpln = "INSERT INTO tb_tpln
        (clm_pl_trabid, clm_pl_tipo, clm_pl_tra_estado, clm_pl_fechregistro, clm_pl_fechaingrespl, clm_pl_fechasalida, clm_pl_doc, clm_pl_com)
        VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)";

    $stmtTpln = $conn->prepare($sql_tpln);
    //       id_trabajador (i), tipo (i), estado (i), fechreg (s), fechaing (s), comentario (s)
    $stmtTpln->bind_param("iiisss",
        $id_trabajador,
        $clm_pl_tipo,
        $pl_estado,     // <-- ahora es INT
        $fecha_reg,
        $fecha_ingreso,
        $comentario
    );
    $stmtTpln->execute();
    $stmtTpln->close();




    $conn->close();

    $_SESSION['exito'] = true;
    header("Location: ../01_contratos/nregrcdn_h.php");
    exit();
}
?>
