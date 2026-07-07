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
define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']); // eliminar la variable después de mostrar
// =====================================================
// GOOGLE FORM RRHH - FUNCIONES BASE
// =====================================================
function gf_norm($txt) {
    $txt = trim((string)$txt);
    if (function_exists('mb_strtolower')) {
        $txt = mb_strtolower($txt, 'UTF-8');
    } else {
        $txt = strtolower($txt);
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($ascii !== false) {
        $txt = $ascii;
    }
    $txt = preg_replace('/[^a-z0-9]+/i', ' ', $txt);
    return trim(preg_replace('/\s+/', ' ', $txt));
}
function gf_cut($txt, $max) {
    $txt = trim((string)$txt);
    if (function_exists('mb_substr')) {
        return mb_substr($txt, 0, $max, 'UTF-8');
    }
    return substr($txt, 0, $max);
}
function gf_field($row, $posibles) {
    foreach ($posibles as $nombreBuscado) {
        $nb = gf_norm($nombreBuscado);
        foreach ($row as $k => $v) {
            if (gf_norm($k) === $nb) {
                return trim((string)$v);
            }
        }
    }
    return '';
}
function gf_hash_row($row) {
    $base = implode('|', [
        gf_field($row, ['Marca temporal']),
        gf_field($row, ['Numero de DNI:', 'Número de DNI:', 'DNI']),
        gf_field($row, ['Correo electrónico:', 'Dirección de correo electrónico'])
    ]);
    return hash('sha256', $base);
}
function gf_parse_marca_temporal($valor) {
    $valor = trim((string)$valor);
    $formatos = [
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d'
    ];
    foreach ($formatos as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $valor);
        if ($dt instanceof DateTime) {
            return [
                'fecha' => $dt->format('Y-m-d'),
                'hora' => $dt->format('H:i:s')
            ];
        }
    }
    return [
        'fecha' => date('Y-m-d'),
        'hora' => date('H:i:s')
    ];
}
function gf_calcular_edad($fechaNacimiento) {
    $fechaNacimiento = trim((string)$fechaNacimiento);
    if ($fechaNacimiento === '') return 0;
    $formatos = ['d/m/Y', 'Y-m-d'];
    foreach ($formatos as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $fechaNacimiento);
        if ($dt instanceof DateTime) {
            return (new DateTime())->diff($dt)->y;
        }
    }
    return 0;
}
function gf_limpiar_dni($dni) {
    $dni = preg_replace('/\D+/', '', (string)$dni);
    return gf_cut($dni, 8);
}
function gf_observaciones($row) {
    $campos = [
        'Correo electrónico' => gf_field($row, ['Correo electrónico:', 'Dirección de correo electrónico']),
        'Dirección actual' => gf_field($row, ['Dirección actual:']),
        'Fecha de nacimiento' => gf_field($row, ['Fecha de nacimiento:']),
        'Estado civil' => gf_field($row, ['Estado civil:']),
        'Hijos' => gf_field($row, ['Hijos:']),
        'Contacto con personal de la empresa' => gf_field($row, ['¿Ha tenido o tiene algún contacto directo, familiar o de amistad con personas que forman parte de nuestra empresa?']),
        'Tipo de vínculo' => gf_field($row, ['Tipo de vínculo:']),
        'Nombres / Puesto vínculo' => gf_field($row, ['Nombres y Apellidos / Puesto']),
        'Ofimática' => gf_field($row, ['¿Cuenta con conocimientos en ofimática? (Word, Excel, PowerPoint u otros programas de oficina)', '¿Cuenta con conocimientos en ofimática?']),
        'Nivel de estudios' => gf_field($row, ['Nivel de estudios alcanzados:']),
        'Estudios técnicos / universitarios' => gf_field($row, ['Nombre de Estudios Técnicos / Universitarios:']),
        'Cargo y funciones' => gf_field($row, ['Cargo desempeñado y funciones:']),
        'Motivo de retiro' => gf_field($row, ['¿Cuál fue el motivo de retiro de su último trabajo?']),
        'Referencias laborales' => gf_field($row, ['Referencias laborales (Nombre, cargo y número de teléfono)']),
        'Disponibilidad de horario' => gf_field($row, ['¿Cuenta con disponibilidad de horario?']),
        'Expectativa salarial' => gf_field($row, ['¿Cuál es su expectativa salarial? Brinda un rango de remuneración']),
        'CV Google Forms' => gf_field($row, ['Adjuntar CV actualizado']),
        'Estado de salud declarado' => gf_field($row, ['Estado de salud declarado (enfermedades crónicas, restricciones físicas o discapacidad)']),
        'Cómo se enteró del puesto' => gf_field($row, ['¿Cómo se enteró del puesto?']),
        'Calificación experiencia formulario' => gf_field($row, ['En una escala del 1 al 5, donde 1 significa muy insatisfecho y 5 significa muy satisfecho, ¿Cómo calificarías tu experiencia al completar este formulario de postulación?'])
    ];
    $texto = "Registro capturado desde Google Forms.\n\n";
    foreach ($campos as $k => $v) {
        if (trim((string)$v) !== '') {
            $texto .= "{$k}: {$v}\n";
        }
    }
    return trim($texto);
}
function gf_leer_respuestas_sheet() {
    $spreadsheetId = '1Nd4qaKbP_v-_rLQzEItx0uFjERaKWGbJFJJK-Bqopf8';
    $gid = '609459197';
    $url = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&gid={$gid}&range=A:AA";
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: Norte360-RRHH\r\n"
        ]
    ]);
    $csv = @file_get_contents($url, false, $context);
    if ($csv === false || trim($csv) === '') {
        return [
            'ok' => false,
            'mensaje' => 'No se pudo leer el Google Sheet. Verifica que la hoja esté compartida o publicada correctamente.'
        ];
    }
    if (stripos($csv, '<html') !== false || stripos($csv, '<!doctype') !== false) {
        return [
            'ok' => false,
            'mensaje' => 'Google no devolvió CSV. Probablemente la hoja está privada o no está compartida como lector.'
        ];
    }
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $headers = fgetcsv($fp);
    if (!$headers) {
        fclose($fp);
        return [
            'ok' => false,
            'mensaje' => 'No se encontraron encabezados en la hoja.'
        ];
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_slice($headers, 0, 27);
    $rows = [];
    while (($data = fgetcsv($fp)) !== false) {
        $data = array_slice($data, 0, 27);
        $data = array_pad($data, count($headers), '');
        $vacia = true;
        foreach ($data as $valor) {
            if (trim((string)$valor) !== '') {
                $vacia = false;
                break;
            }
        }
        if ($vacia) continue;
        $fila = [];
        foreach ($headers as $i => $header) {
            $fila[$header] = $data[$i] ?? '';
        }
        $fila['_gf_hash'] = gf_hash_row($fila);
        $rows[] = $fila;
    }
    fclose($fp);
    $rows = array_reverse($rows);
    return [
        'ok' => true,
        'headers' => $headers,
        'rows' => $rows
    ];
}
function gf_buscar_row_por_hash($hash) {
    $data = gf_leer_respuestas_sheet();
    if (!$data['ok']) {
        return null;
    }
    foreach ($data['rows'] as $row) {
        if (($row['_gf_hash'] ?? '') === $hash) {
            return $row;
        }
    }
    return null;
}
function gf_json($arr) {
    if (ob_get_length()) {
        ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit();
}
function gf_db() {
    global $conn;
    if (!defined('ACCESS_GRANTED')) {
        define('ACCESS_GRANTED', true);
    }
    // Si ya existe una conexión válida, la reutilizamos
    if (isset($conn) && $conn instanceof mysqli && @$conn->ping()) {
        return $conn;
    }
    // Cargar conexión dentro del scope correcto
    require_once("../.c0nn3ct/db_securebd2.php");
    // Después del require, volvemos a validar
    if (isset($conn) && $conn instanceof mysqli && @$conn->ping()) {
        return $conn;
    }
    gf_json([
        'ok' => false,
        'mensaje' => 'No se pudo establecer conexión con la base de datos. Revisa si db_securebd2.php está creando $conn correctamente.'
    ]);
}
function gf_validar_ajax_base($permisos) {
    if (!isset($_SESSION['usuario'])) {
        http_response_code(401);
        gf_json([
            'ok' => false,
            'mensaje' => 'Sesión expirada.'
        ]);
    }
    if ($_SESSION['web_rol'] !== 'Admin' && !in_array(6, $permisos)) {
        http_response_code(403);
        gf_json([
            'ok' => false,
            'mensaje' => 'No tienes permisos para esta acción.'
        ]);
    }
}
// =====================================================
// AJAX: Listar respuestas del Google Form
// =====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'google_form_respuestas') {
    header('Content-Type: application/json; charset=utf-8');
    gf_validar_ajax_base($permisos);
    $conn = gf_db();
    $data = gf_leer_respuestas_sheet();
    if (!$data['ok']) {
        gf_json($data);
    }
    $rows = $data['rows'];
    $hashes = [];
    $dnis = [];
    foreach ($rows as $r) {
        $hashes[] = $r['_gf_hash'];
        $dni = gf_limpiar_dni(gf_field($r, ['Numero de DNI:', 'Número de DNI:', 'DNI']));
        if ($dni !== '') $dnis[] = $dni;
    }
    $controlMap = [];
    if (!empty($hashes)) {
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $types = str_repeat('s', count($hashes));
        $stmt = $conn->prepare("
            SELECT gf_hash, gf_estado, gf_id_entrevista, gf_motivo
            FROM tb_googleform_entrevista_estado
            WHERE gf_hash IN ($placeholders)
        ");
        $stmt->bind_param($types, ...$hashes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($x = $res->fetch_assoc()) {
            $controlMap[$x['gf_hash']] = $x;
        }
        $stmt->close();
    }
    $dniMap = [];
    $dnis = array_values(array_unique($dnis));
    if (!empty($dnis)) {
        $placeholders = implode(',', array_fill(0, count($dnis), '?'));
        $types = str_repeat('s', count($dnis));
        $stmt = $conn->prepare("
            SELECT id_entrevista, dni
            FROM entrevistas
            WHERE dni IN ($placeholders)
        ");
        $stmt->bind_param($types, ...$dnis);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($x = $res->fetch_assoc()) {
            $dniMap[$x['dni']] = $x['id_entrevista'];
        }
        $stmt->close();
    }
    foreach ($rows as &$r) {
        $hash = $r['_gf_hash'];
        $dni = gf_limpiar_dni(gf_field($r, ['Numero de DNI:', 'Número de DNI:', 'DNI']));
        $r['_gf_dni_limpio'] = $dni;
        $r['_gf_estado'] = 'PENDIENTE';
        $r['_gf_estado_txt'] = 'Pendiente';
        $r['_gf_id_entrevista'] = null;
        $r['_gf_motivo'] = null;
        if (isset($controlMap[$hash])) {
            $estado = $controlMap[$hash]['gf_estado'];
            if ($estado === 'DESCARTADO') {
                $r['_gf_estado'] = 'DESCARTADO';
                $r['_gf_estado_txt'] = 'Descartado';
                $r['_gf_motivo'] = $controlMap[$hash]['gf_motivo'];
            }
            if ($estado === 'AGREGADO') {
                $r['_gf_estado'] = 'AGREGADO';
                $r['_gf_estado_txt'] = 'Agregado a entrevistas';
                $r['_gf_id_entrevista'] = $controlMap[$hash]['gf_id_entrevista'];
            }
        } elseif ($dni !== '' && isset($dniMap[$dni])) {
            $r['_gf_estado'] = 'YA_REGISTRADO';
            $r['_gf_estado_txt'] = 'Ya registrado en entrevistas';
            $r['_gf_id_entrevista'] = $dniMap[$dni];
        }
    }
    unset($r);
    $conn->close();
    gf_json([
        'ok' => true,
        'total' => count($rows),
        'headers' => $data['headers'],
        'rows' => $rows
    ]);
}
// =====================================================
// AJAX: Agregar postulante del Google Form a entrevistas
// =====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'google_form_agregar') {
    header('Content-Type: application/json; charset=utf-8');
    gf_validar_ajax_base($permisos);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        gf_json(['ok' => false, 'mensaje' => 'Método no permitido.']);
    }
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        gf_json(['ok' => false, 'mensaje' => 'CSRF inválido.']);
    }
    $hash = trim($_POST['hash'] ?? '');
    if ($hash === '') {
        gf_json(['ok' => false, 'mensaje' => 'Hash inválido.']);
    }
    $row = gf_buscar_row_por_hash($hash);
    if (!$row) {
        gf_json(['ok' => false, 'mensaje' => 'No se encontró el registro en el Google Sheet.']);
    }
    $conn = gf_db();
    $nombre = gf_cut(gf_field($row, ['Nombres y Apellidos']), 50);
    $dni = gf_limpiar_dni(gf_field($row, ['Numero de DNI:', 'Número de DNI:', 'DNI']));
    $puesto = gf_cut(gf_field($row, ['Puesto al que postula:']), 100);
    $contacto = gf_cut(gf_field($row, ['Número de teléfono:', 'Numero de teléfono:', 'Celular', 'Teléfono']), 20);
    $sede = gf_cut(gf_field($row, ['Sede a la que postula:']), 50);
    $fechaNac = gf_field($row, ['Fecha de nacimiento:']);
    $edad = gf_calcular_edad($fechaNac);
    $marca = gf_field($row, ['Marca temporal']);
    $fh = gf_parse_marca_temporal($marca);
    $fecha = $fh['fecha'];
    $hora = $fh['hora'];
    $sexo = 'No definido';
    $observaciones = gf_observaciones($row);
    $referencia = 'Registrado desde visualización del Formulario del Google Forms [Referencia automática]';
    $usuarioreg = intval($_SESSION['id_usuario'] ?? 0);
    if ($nombre === '' || $dni === '') {
        gf_json([
            'ok' => false,
            'mensaje' => 'El registro no tiene nombre o DNI válido. No se puede agregar.'
        ]);
    }
    if ($puesto === '') $puesto = 'No especificado';
    if ($sede === '') $sede = 'No especificado';
    $stmt = $conn->prepare("SELECT id_entrevista FROM entrevistas WHERE dni = ? LIMIT 1");
    $stmt->bind_param("s", $dni);
    $stmt->execute();
    $existe = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existe) {
        $idEntrevista = intval($existe['id_entrevista']);
        $stmt = $conn->prepare("
            INSERT INTO tb_googleform_entrevista_estado
                (gf_hash, gf_dni, gf_nombre, gf_estado, gf_id_entrevista, gf_motivo, gf_idusuario)
            VALUES (?, ?, ?, 'AGREGADO', ?, 'Detectado automáticamente: el DNI ya existía en entrevistas.', ?)
            ON DUPLICATE KEY UPDATE
                gf_estado = 'AGREGADO',
                gf_id_entrevista = VALUES(gf_id_entrevista),
                gf_motivo = VALUES(gf_motivo),
                gf_idusuario = VALUES(gf_idusuario)
        ");
        $stmt->bind_param("sssii", $hash, $dni, $nombre, $idEntrevista, $usuarioreg);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        gf_json([
            'ok' => true,
            'mensaje' => 'Este DNI ya existía en entrevistas. Se marcó como ya registrado.',
            'id_entrevista' => $idEntrevista
        ]);
    }
    $stmt = $conn->prepare("
        INSERT INTO entrevistas
            (nombre, fecha, hora, puesto, observaciones, dni, sexo, contacto, edad, clm_usuarioreg, clm_sede, clm_referencia, clm_estado, clm_yesorno)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
    ");
    $stmt->bind_param(
        "ssssssssiiss",
        $nombre,
        $fecha,
        $hora,
        $puesto,
        $observaciones,
        $dni,
        $sexo,
        $contacto,
        $edad,
        $usuarioreg,
        $sede,
        $referencia
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        gf_json([
            'ok' => false,
            'mensaje' => 'Error al guardar entrevista: ' . $error
        ]);
    }
    $idEntrevista = $stmt->insert_id;
    $stmt->close();
    $stmt = $conn->prepare("
        INSERT INTO tb_googleform_entrevista_estado
            (gf_hash, gf_dni, gf_nombre, gf_estado, gf_id_entrevista, gf_motivo, gf_idusuario)
        VALUES (?, ?, ?, 'AGREGADO', ?, 'Agregado desde visualización del Google Forms.', ?)
        ON DUPLICATE KEY UPDATE
            gf_estado = 'AGREGADO',
            gf_id_entrevista = VALUES(gf_id_entrevista),
            gf_motivo = VALUES(gf_motivo),
            gf_idusuario = VALUES(gf_idusuario)
    ");
    $stmt->bind_param("sssii", $hash, $dni, $nombre, $idEntrevista, $usuarioreg);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    gf_json([
        'ok' => true,
        'mensaje' => 'Postulante agregado correctamente a entrevistas.',
        'id_entrevista' => $idEntrevista
    ]);
}
// =====================================================
// AJAX: Descartar registro del Google Form
// =====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'google_form_descartar') {
    header('Content-Type: application/json; charset=utf-8');
    gf_validar_ajax_base($permisos);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        gf_json(['ok' => false, 'mensaje' => 'Método no permitido.']);
    }
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        gf_json(['ok' => false, 'mensaje' => 'CSRF inválido.']);
    }
    $hash = trim($_POST['hash'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');
    if ($hash === '') {
        gf_json(['ok' => false, 'mensaje' => 'Hash inválido.']);
    }
    if ($motivo === '') {
        $motivo = 'Descartado desde visualización del Google Forms.';
    }
    $row = gf_buscar_row_por_hash($hash);
    if (!$row) {
        gf_json(['ok' => false, 'mensaje' => 'No se encontró el registro en el Google Sheet.']);
    }
    $nombre = gf_cut(gf_field($row, ['Nombres y Apellidos']), 255);
    $dni = gf_limpiar_dni(gf_field($row, ['Numero de DNI:', 'Número de DNI:', 'DNI']));
    $usuarioreg = intval($_SESSION['id_usuario'] ?? 0);
    $conn = gf_db();
    $stmt = $conn->prepare("
        INSERT INTO tb_googleform_entrevista_estado
            (gf_hash, gf_dni, gf_nombre, gf_estado, gf_id_entrevista, gf_motivo, gf_idusuario)
        VALUES (?, ?, ?, 'DESCARTADO', NULL, ?, ?)
        ON DUPLICATE KEY UPDATE
            gf_estado = 'DESCARTADO',
            gf_id_entrevista = NULL,
            gf_motivo = VALUES(gf_motivo),
            gf_idusuario = VALUES(gf_idusuario)
    ");
    $stmt->bind_param("ssssi", $hash, $dni, $nombre, $motivo, $usuarioreg);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        gf_json([
            'ok' => false,
            'mensaje' => 'Error al descartar: ' . $error
        ]);
    }
    $stmt->close();
    $conn->close();
    gf_json([
        'ok' => true,
        'mensaje' => 'Registro descartado correctamente. No se eliminó nada del Google Sheet.'
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevistas Registradas | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
  width: 250px;
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
.estado-aceptado {
  background: #27ae60;
  color: white;
}
.estado-rechazado {
  background: #e74c3c;
  color: white;
}
/* =====================================================
   MODAL GOOGLE FORM - RESPUESTAS
===================================================== */
.btn-google-form {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: auto;
    background: linear-gradient(120deg, #2c3e50, #2980b9);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 12px 18px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 6px 14px rgba(41, 128, 185, 0.25);
    transition: transform .2s ease, box-shadow .2s ease;
}
.btn-google-form:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(41, 128, 185, 0.35);
}
.modal-google-content {
    max-width: 96vw;
    width: 96vw;
    height: 88vh;
    margin: 3vh auto;
    padding: 0;
    overflow: hidden;
    border-radius: 16px;
    background: #f4f7fb;
}
.google-modal-header {
    background: linear-gradient(120deg, #2c3e50, #34495e);
    color: white;
    padding: 18px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.google-modal-header h2 {
    color: white;
    margin: 0;
    font-size: 22px;
}
.google-modal-body {
    padding: 18px;
    height: calc(88vh - 76px);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.google-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(160px, 1fr));
    gap: 12px;
}
.google-kpi-card {
    background: white;
    border-radius: 12px;
    padding: 14px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    border-left: 5px solid #2980b9;
}
.google-kpi-card span {
    display: block;
    color: #7f8c8d;
    font-size: 13px;
    font-weight: 700;
}
.google-kpi-card strong {
    display: block;
    color: #2c3e50;
    font-size: 22px;
    margin-top: 4px;
}
.google-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    background: white;
    padding: 12px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.google-toolbar input,
.google-toolbar select {
    padding: 11px 12px;
    border: 1px solid #d8dee6;
    border-radius: 9px;
    font-size: 14px;
    outline: none;
}
.google-toolbar input {
    flex: 1;
}
.google-table-wrap {
    background: white;
    border-radius: 12px;
    overflow: auto;
    flex: 1;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}
#tablaGoogleForm {
    width: max-content;
    min-width: 100%;
    border-collapse: collapse;
    box-shadow: none;
    border-radius: 0;
}
#tablaGoogleForm th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #2c3e50;
    color: white;
    font-size: 13px;
    white-space: nowrap;
    padding: 12px;
}
#tablaGoogleForm td {
    font-size: 13px;
    padding: 10px 12px;
    max-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#tablaGoogleForm tr:nth-child(even) {
    background: #f8fafc;
}
#tablaGoogleForm tr:hover {
    background: #eaf4ff;
}
.btn-ver-form {
    width: auto;
    padding: 7px 12px;
    border-radius: 8px;
    background: #2980b9;
    font-size: 13px;
}
.google-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(260px, 1fr));
    gap: 12px;
}
.google-detail-item {
    background: #f8fafc;
    border: 1px solid #e3e8ef;
    border-radius: 10px;
    padding: 10px 12px;
}
.google-detail-item small {
    display: block;
    color: #64748b;
    font-weight: 700;
    margin-bottom: 5px;
}
.google-detail-item div {
    color: #1f2937;
    word-break: break-word;
}
.google-link {
    color: #2980b9;
    font-weight: 700;
    text-decoration: none;
}
.google-link:hover {
    text-decoration: underline;
}
@media (max-width: 900px) {
    .google-kpis {
        grid-template-columns: repeat(2, 1fr);
    }
    .google-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .google-detail-grid {
        grid-template-columns: 1fr;
    }
}
.gf-chip {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
}
.gf-pendiente {
    background: #fff7e6;
    color: #9a5b00;
    border: 1px solid #ffd38a;
}
.gf-agregado {
    background: #e8f7ee;
    color: #0f7a3b;
    border: 1px solid #9be0b5;
}
.gf-descartado {
    background: #fdecec;
    color: #b42318;
    border: 1px solid #f5a3a3;
}
.gf-registrado {
    background: #eaf4ff;
    color: #1f5f99;
    border: 1px solid #9dccf5;
}
.gf-actions {
    display: flex;
    gap: 6px;
    flex-wrap: nowrap;
    align-items: center;
}
.gf-btn-mini {
    width: auto;
    padding: 7px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 800;
    border: none;
    cursor: pointer;
}
.gf-btn-add {
    background: #27ae60;
    color: white;
}
.gf-btn-discard {
    background: #e74c3c;
    color: white;
}
.gf-btn-view {
    background: #2980b9;
    color: white;
}
.gf-btn-disabled {
    background: #95a5a6;
    color: white;
    cursor: not-allowed;
}
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/header_n360.css">
<link rel="stylesheet" href="../assets/css/sidebar_n360.css">
<link rel="stylesheet" href="../assets/css/main_n360.css">
<link rel="stylesheet" href="../assets/css/footer_n360.css">
<link rel="stylesheet" href="../assets/css/content_n360.css">
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
<?php n360_render_header(); ?>
<?php n360_render_sidebar(); ?>
<div class="main-content n360-main n360-main--module">
<?php n360_render_content_separator('top'); ?>
  <h2>📝 Entrevistas Registradas</h2>
<div style="text-align:center; margin: 12px 0 20px 0;">
    <button type="button" class="btn-google-form" onclick="abrirModalGoogleForm()">
        Ver registros del Formulario Google
    </button>
</div>
<div class="filtros" style="background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); max-width: 900px; margin: 20px auto;">
  <div>
    <label for="filtroNombre" style="font-weight: bold; color: #2c3e50; display: block; margin-bottom: 6px;">🔎 Nombre</label>
    <input type="text" id="filtroNombre" placeholder="Buscar por nombre" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc;">
  </div>
  <div>
    <label for="filtroReferencia" style="font-weight: bold; color: #2c3e50; display: block; margin-bottom: 6px;">🔎 Referencia</label>
    <input type="text" id="filtroReferencia" placeholder="Buscar por nombre" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc;">
  </div>
  <div>
    <label for="filtroPuesto" style="font-weight: bold; color: #2c3e50; display: block; margin-bottom: 6px;">Puesto</label>
    <select id="filtroPuesto" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc;">
      <option value="">Todos los puestos</option>
      <?php
        if (!defined('ACCESS_GRANTED')) {
            define('ACCESS_GRANTED', true);
        }
        require_once("../.c0nn3ct/db_securebd2.php");
        $puestos = $conn->query("SELECT DISTINCT puesto FROM entrevistas WHERE puesto IS NOT NULL AND puesto != ''");
        while ($p = $puestos->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($p['puesto']) . "'>" . htmlspecialchars($p['puesto']) . "</option>";
        }
      ?>
    </select>
  </div>
  <div>
    <label for="filtroSede" style="font-weight: bold; color: #2c3e50; display: block; margin-bottom: 6px;">Sede</label>
    <select id="filtroSede" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc;">
      <option value="">Todas las sedes</option>
      <?php
        // Supongo que ya tienes $conn abierto
        $sedes = $conn->query("SELECT DISTINCT clm_sede FROM entrevistas WHERE clm_sede IS NOT NULL AND clm_sede<>''");
        while ($s = $sedes->fetch_assoc()) {
            $sede = htmlspecialchars($s['clm_sede']);
            echo "<option value='$sede'>$sede</option>";
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
                  <th>Sede</th>
                  <th>Puesto</th>
                  <th>Referencia</th>
                  <th>Estado</th>
                  <th>Etapa</th>
                  <th>Acción</th>
              </tr>
          </thead>
          <tbody>
              <!-- Aquí se cargará dinámicamente desde PHP -->
        <?php
        $sql = "SELECT nombre, fecha, hora, puesto, clm_referencia, observaciones, dni, sexo, contacto,edad, clm_estado, id_entrevista, clm_yesorno, clm_comentario_entrevistapersonal, clm_comentario_induccion, clm_comentario_contratado, clm_comentario_rechazado, clm_reservas, clm_sede  FROM entrevistas ORDER BY fecha DESC";
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
                      data-sede='" . htmlspecialchars($row["clm_sede"]) . "'
                      data-puesto='" . htmlspecialchars($row["puesto"]) . "'
                      data-clm_referencia='" . htmlspecialchars($row["clm_referencia"]) . "'
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
                      <td>" . htmlspecialchars($row["clm_sede"]) . "</td>
                      <td>" . htmlspecialchars($row["puesto"]) . "</td>
                      <td>" . htmlspecialchars($row["clm_referencia"]) . "</td>
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
<!-- =====================================================
     MODAL RESPUESTAS GOOGLE FORM
===================================================== -->
<div id="modalGoogleForm" class="modal">
    <div class="modal-content modal-google-content">
        <div class="google-modal-header">
            <h2>Registros del Formulario Google</h2>
            <span class="cerrar" onclick="cerrarModalGoogleForm()" style="color:white;">&times;</span>
        </div>
        <div class="google-modal-body">
            <div class="google-kpis">
                <div class="google-kpi-card">
                    <span>Total respuestas</span>
                    <strong id="gfTotal">0</strong>
                </div>
                <div class="google-kpi-card">
                    <span>Última respuesta</span>
                    <strong id="gfUltima" style="font-size:15px;">-</strong>
                </div>
                <div class="google-kpi-card">
                    <span>Sedes detectadas</span>
                    <strong id="gfSedes">0</strong>
                </div>
                <div class="google-kpi-card">
                    <span>Vista</span>
                    <strong style="font-size:15px;">A:AA</strong>
                </div>
            </div>
            <div class="google-toolbar">
                <input type="text" id="gfBuscar" placeholder="Buscar por nombre, DNI, teléfono, puesto, sede, correo...">
                <select id="gfFiltroSede">
                    <option value="">Todas las sedes</option>
                </select>
                <button type="button" class="btn-google-form" onclick="recargarGoogleForm()" style="padding:10px 14px;">
                    🔄 Recargar
                </button>
            </div>
            <div id="gfEstado" style="font-weight:700; color:#2c3e50;">
                Presiona el botón para cargar respuestas.
            </div>
            <div class="google-table-wrap">
                <table id="tablaGoogleForm">
                    <thead></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<!-- Modal pequeño para detalle individual -->
<div id="modalGoogleDetalle" class="modal">
    <div class="modal-content" style="max-width:950px;">
        <span class="cerrar" onclick="cerrarModalGoogleDetalle()">&times;</span>
        <h2 style="text-align:left;">📄 Detalle del Formulario Forms</h2>
        <div id="contenidoGoogleDetalle" class="google-detail-grid"></div>
    </div>
</div>
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
            <?php n360_render_content_separator('section'); ?>
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
  const filtroNombre  = document.getElementById("filtroNombre");
  const filtroPuesto  = document.getElementById("filtroPuesto");
  const filtroReferencia  = document.getElementById("filtroReferencia");
  const filtroSede    = document.getElementById("filtroSede");
  const chkReservas   = document.getElementById("chkReservas");
  const filas         = document.querySelectorAll("#tablaEntrevistas tbody tr");
  function filtrar() {
    const nombre = filtroNombre.value.toLowerCase().trim();
    const puesto = filtroPuesto.value.toLowerCase().trim();
    const clm_referencia = filtroReferencia.value.toLowerCase().trim();
    const sede   = filtroSede.value.toLowerCase().trim();
    const soloReservas = chkReservas.checked;
    filas.forEach(fila => {
      const tdNombre = fila.cells[2].textContent.toLowerCase().trim();
      const tdSede   = fila.cells[3].textContent.toLowerCase().trim();
      const tdPuesto = fila.cells[4].textContent.toLowerCase().trim(); // ← índice 4
      const tdReferencia = fila.cells[5].textContent.toLowerCase().trim(); // ← índice 4
      const btn      = fila.querySelector("button[data-clm_reservas]");
      const esReserva= btn && btn.getAttribute("data-clm_reservas") === "1";
      const coincideNombre = tdNombre.includes(nombre);
      const coincideSede   = sede === "" || tdSede.includes(sede);
      const coincidePuesto = puesto === "" || tdPuesto.includes(puesto);
      const coincideReferencia = tdReferencia.includes(clm_referencia);
      const coincideReserva= !soloReservas || esReserva;
      fila.style.display = (coincideNombre && coincideReferencia && coincidePuesto && coincideSede && coincideReserva)
                           ? "" : "none";
    });
    if (typeof mostrarPagina === "function") mostrarPagina(1);
  }
  filtroNombre.addEventListener("input", filtrar);
  filtroReferencia.addEventListener("input", filtrar);
  filtroPuesto.addEventListener("change", filtrar);
  filtroSede.addEventListener("change", filtrar);
  chkReservas.addEventListener("change", filtrar);
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
    window.__btnEntSel = boton;
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
      sede: boton.getAttribute("data-sede"),
      puesto: boton.getAttribute("data-puesto"),
      referencia: boton.getAttribute("data-clm_referencia"),
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
        <tr><th>Sede</th><td>${data.sede}</td></tr>
        <tr><th>Referencia:</th><td>${data.referencia}</td></tr>
        <tr>
          <th>📝 Observaciones</th>
          <td>
            <div id="obsTexto" style="white-space:pre-wrap;">${data.observaciones || ''}</div>
            <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
              <button type="button" class="btn-validar" style="max-width:220px;" onclick="editarObs()">✏️ Editar observaciones</button>
            </div>
            <div id="obsEditor" style="display:none; margin-top:10px;">
              <textarea id="obsTextarea" rows="4" class="input-evaluacion" placeholder="Escribe las observaciones..."></textarea>
              <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                <button type="button" class="btn-validar" onclick="guardarObs()">💾 Guardar</button>
                <button type="button" class="volver-btn" onclick="cancelarObs()">Cancelar</button>
              </div>
            </div>
          </td>
        </tr>
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
// ✅ Insertar botón solo si estado es Trabajador (5)
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
historialComentarios.innerHTML = "";
// Selección (observaciones)
if (data.estado >= 1) historialComentarios.innerHTML += `
  <li id="histSeleccion" style="background:#ecf0f1;margin-bottom:10px;padding:10px 15px;border-left:4px solid #3498db;border-radius:8px;">
    <strong>🟦 Selección:</strong>
    <span id="histSeleccionTexto">${data.observaciones || 'Sin comentario'}</span>
    <div style="margin-top:8px;">
      <button type="button" class="volver-btn" onclick="editarEtapa('seleccion')">✏️ Editar</button>
    </div>
    <div id="editor-seleccion" style="display:none;margin-top:10px;">
      <textarea id="ta-seleccion" rows="3" class="input-evaluacion"></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-validar" onclick="guardarEtapa('seleccion')">💾 Guardar</button>
        <button type="button" class="volver-btn" onclick="cancelarEtapa('seleccion')">Cancelar</button>
      </div>
    </div>
  </li>`;
// Entrevista presencial
if (data.estado >= 2) historialComentarios.innerHTML += `
  <li style="background:#ecf0f1;margin-bottom:10px;padding:10px 15px;border-left:4px solid #2980b9;border-radius:8px;">
    <strong>🔵 Entrevista presencial:</strong>
    <span id="histEntrevistaTexto">${data.comentario2 || 'Sin comentario'}</span>
    <div style="margin-top:8px;">
      <button type="button" class="volver-btn" onclick="editarEtapa('entrevista')">✏️ Editar</button>
    </div>
    <div id="editor-entrevista" style="display:none;margin-top:10px;">
      <textarea id="ta-entrevista" rows="3" class="input-evaluacion"></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-validar" onclick="guardarEtapa('entrevista')">💾 Guardar</button>
        <button type="button" class="volver-btn" onclick="cancelarEtapa('entrevista')">Cancelar</button>
      </div>
    </div>
  </li>`;
// Inducción
if (data.estado >= 3) historialComentarios.innerHTML += `
  <li style="background:#ecf0f1;margin-bottom:10px;padding:10px 15px;border-left:4px solid #8e44ad;border-radius:8px;">
    <strong>🟣 Inducción:</strong>
    <span id="histInduccionTexto">${data.comentario3 || 'Sin comentario'}</span>
    <div style="margin-top:8px;">
      <button type="button" class="volver-btn" onclick="editarEtapa('induccion')">✏️ Editar</button>
    </div>
    <div id="editor-induccion" style="display:none;margin-top:10px;">
      <textarea id="ta-induccion" rows="3" class="input-evaluacion"></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-validar" onclick="guardarEtapa('induccion')">💾 Guardar</button>
        <button type="button" class="volver-btn" onclick="cancelarEtapa('induccion')">Cancelar</button>
      </div>
    </div>
  </li>`;
// Solicitud Trabajador
if (data.estado >= 4) historialComentarios.innerHTML += `
  <li style="background:#ecf0f1;margin-bottom:10px;padding:10px 15px;border-left:4px solid #27ae60;border-radius:8px;">
    <strong>🟢 Solicitud Trabajador:</strong>
    <span id="histSolicitudTexto">${data.comentario4 || 'Sin comentario'}</span>
    <div style="margin-top:8px;">
      <button type="button" class="volver-btn" onclick="editarEtapa('solicitud')">✏️ Editar</button>
    </div>
    <div id="editor-solicitud" style="display:none;margin-top:10px;">
      <textarea id="ta-solicitud" rows="3" class="input-evaluacion"></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-validar" onclick="guardarEtapa('solicitud')">💾 Guardar</button>
        <button type="button" class="volver-btn" onclick="cancelarEtapa('solicitud')">Cancelar</button>
      </div>
    </div>
  </li>`;
// Rechazo (solo si fue rechazado)
if (data.yesorno === "2") historialComentarios.innerHTML += `
  <li style="background:#fdecea;margin-bottom:10px;padding:10px 15px;border-left:4px solid #e74c3c;border-radius:8px;">
    <strong>❌ Rechazo:</strong>
    <span id="histRechazoTexto">${data.comentarioRechazo || 'Sin detalle registrado.'}</span>
    <div style="margin-top:8px;">
      <button type="button" class="volver-btn" onclick="editarEtapa('rechazo')">✏️ Editar</button>
    </div>
    <div id="editor-rechazo" style="display:none;margin-top:10px;">
      <textarea id="ta-rechazo" rows="3" class="input-evaluacion"></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn-validar" onclick="guardarEtapa('rechazo')">💾 Guardar</button>
        <button type="button" class="volver-btn" onclick="cancelarEtapa('rechazo')">Cancelar</button>
      </div>
    </div>
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
      console.log("📥 Respuesta del servidor:", JSON.stringify(data)); // <- esto sí o sí debe salir en consola
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
<?php n360_render_content_separator('bottom'); ?>
<?php n360_render_footer(); ?>
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
function editarObs() {
  const btn = window.__btnEntSel;
  const actual = btn ? (btn.getAttribute("data-observaciones") || "") : "";
  document.getElementById("obsTextarea").value = actual;
  document.getElementById("obsEditor").style.display = "block";
}
function cancelarObs() {
  document.getElementById("obsEditor").style.display = "none";
}
function guardarObs() {
  const id = document.getElementById("id_entrevistaSeleccionado").value;
  const obs = document.getElementById("obsTextarea").value.trim();
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  if (!id) { alert("ID inválido"); return; }
  fetch("../php/actualizar_observaciones.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `id_entrevista=${encodeURIComponent(id)}&observaciones=${encodeURIComponent(obs)}&csrf=${encodeURIComponent(csrf)}`
  })
  .then(r => r.text())
  .then(t => {
    if (t.trim() === "OK") {
      // 1) Refresca el texto mostrado
      document.getElementById("obsTexto").textContent = obs;
      // 2) Actualiza el dataset del botón que abrió el modal (para que quede coherente)
      if (window.__btnEntSel) window.__btnEntSel.setAttribute("data-observaciones", obs);
      // 3) Actualiza el historial "Selección"
      const histTxt = document.getElementById("histSeleccionTexto");
      if (histTxt) histTxt.textContent = obs || "Sin comentario";
      // 4) Cierra el editor
      cancelarObs();
      alert("✅ Observaciones actualizadas");
    } else {
      alert("⚠️ " + t);
    }
  })
  .catch(err => {
    console.error(err);
    alert("❌ Error al guardar");
  });
}
function editarEtapa(key) {
  const spanMap = {
    seleccion: 'histSeleccionTexto',
    entrevista: 'histEntrevistaTexto',
    induccion: 'histInduccionTexto',
    solicitud: 'histSolicitudTexto',
    rechazo: 'histRechazoTexto'
  };
  const spanId = spanMap[key];
  const editor = document.getElementById('editor-' + key);
  const ta = document.getElementById('ta-' + key);
  if (!spanId || !editor || !ta) return;
  ta.value = (document.getElementById(spanId)?.textContent || '').trim().replace(/^Sin comentario$/i,'');
  editor.style.display = 'block';
}
function cancelarEtapa(key) {
  const editor = document.getElementById('editor-' + key);
  if (editor) editor.style.display = 'none';
}
function guardarEtapa(key) {
  const cfg = {
    // key -> {campoBD, spanId, dataAttr en el botón que abrió el modal}
    seleccion: { campo: 'observaciones', spanId: 'histSeleccionTexto', datasetAttr: 'data-observaciones' },
    entrevista: { campo: 'clm_comentario_entrevistapersonal', spanId: 'histEntrevistaTexto', datasetAttr: 'data-comentario2' },
    induccion:  { campo: 'clm_comentario_induccion',         spanId: 'histInduccionTexto',  datasetAttr: 'data-comentario3' },
    solicitud:  { campo: 'clm_comentario_contratado',        spanId: 'histSolicitudTexto',  datasetAttr: 'data-comentario4' },
    rechazo:    { campo: 'clm_comentario_rechazado',         spanId: 'histRechazoTexto',    datasetAttr: 'data-comentarioRechazo' }
  };
  const c = cfg[key];
  if (!c) return;
  const id = document.getElementById("id_entrevistaSeleccionado").value;
  const ta = document.getElementById('ta-' + key);
  const nuevo = (ta?.value || '').trim();
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  if (!id) { alert("ID inválido"); return; }
  fetch("../php/actualizar_comentario.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `id_entrevista=${encodeURIComponent(id)}&campo=${encodeURIComponent(c.campo)}&valor=${encodeURIComponent(nuevo)}&csrf=${encodeURIComponent(csrf)}`
  })
  .then(r => r.text())
  .then(t => {
    if (t.trim() === "OK") {
      // Actualiza el span del historial
      const span = document.getElementById(c.spanId);
      if (span) span.textContent = nuevo || 'Sin comentario';
      // Actualiza también el data-* del botón que abrió el modal (coherencia)
      if (window.__btnEntSel && c.datasetAttr) {
        window.__btnEntSel.setAttribute(c.datasetAttr, nuevo);
      }
      // Si es selección, también refresca el bloque de Observaciones arriba
      if (key === 'seleccion') {
        const obsTexto = document.getElementById("obsTexto");
        if (obsTexto) obsTexto.textContent = nuevo;
        if (window.__btnEntSel) window.__btnEntSel.setAttribute("data-observaciones", nuevo);
      }
      cancelarEtapa(key);
      alert("✅ Comentario actualizado");
    } else {
      alert("⚠️ " + t);
    }
  })
  .catch(err => {
    console.error(err);
    alert("❌ Error al guardar");
  });
}
</script>
<script>
let GF_HEADERS = [];
let GF_ROWS = [];
function escapeHtml(valor) {
    return String(valor ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
function normalizarTexto(txt) {
    return String(txt ?? '')
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}
function esUrl(valor) {
    return /^https?:\/\//i.test(String(valor ?? '').trim());
}
function renderValor(valor) {
    const v = String(valor ?? '').trim();
    if (!v) return '<span style="color:#94a3b8;">-</span>';
    if (esUrl(v)) {
        return `<a class="google-link" href="${escapeHtml(v)}" target="_blank" rel="noopener noreferrer">Abrir archivo</a>`;
    }
    return escapeHtml(v);
}
function obtenerCampo(row, posiblesNombres) {
    for (const nombre of posiblesNombres) {
        const key = Object.keys(row).find(k => normalizarTexto(k) === normalizarTexto(nombre));
        if (key) return row[key] ?? '';
    }
    return '';
}
function abrirModalGoogleForm() {
    document.getElementById("modalGoogleForm").style.display = "block";
    if (GF_ROWS.length === 0) {
        cargarGoogleForm();
    }
}
function cerrarModalGoogleForm() {
    document.getElementById("modalGoogleForm").style.display = "none";
}
function cerrarModalGoogleDetalle() {
    document.getElementById("modalGoogleDetalle").style.display = "none";
}
function recargarGoogleForm() {
    GF_HEADERS = [];
    GF_ROWS = [];
    cargarGoogleForm();
}
async function cargarGoogleForm() {
    const estado = document.getElementById("gfEstado");
    estado.textContent = "Cargando respuestas desde Google Forms...";
    estado.style.color = "#2c3e50";
    try {
        const resp = await fetch("bvisentrevisaf.php?accion=google_form_respuestas", {
            method: "GET",
            cache: "no-store"
        });
        const data = await resp.json();
        if (!data.ok) {
            estado.textContent = data.mensaje || "No se pudieron cargar las respuestas.";
            estado.style.color = "#c0392b";
            return;
        }
        GF_HEADERS = data.headers || [];
        GF_ROWS = data.rows || [];
        estado.textContent = `Respuestas cargadas correctamente: ${GF_ROWS.length}`;
        estado.style.color = "#27ae60";
        cargarFiltroSedesGoogle();
        actualizarKpisGoogle();
        renderTablaGoogleForm();
    } catch (error) {
        estado.textContent = "Error al consultar el formulario: " + error.message;
        estado.style.color = "#c0392b";
    }
}
function cargarFiltroSedesGoogle() {
    const select = document.getElementById("gfFiltroSede");
    select.innerHTML = '<option value="">Todas las sedes</option>';
    const sedes = new Set();
    GF_ROWS.forEach(row => {
        const sede = obtenerCampo(row, ["Sede a la que postula:"]);
        if (sede.trim()) sedes.add(sede.trim());
    });
    [...sedes].sort().forEach(sede => {
        const opt = document.createElement("option");
        opt.value = sede;
        opt.textContent = sede;
        select.appendChild(opt);
    });
}
function actualizarKpisGoogle() {
    document.getElementById("gfTotal").textContent = GF_ROWS.length;
    const ultima = GF_ROWS.length > 0
        ? obtenerCampo(GF_ROWS[0], ["Marca temporal"])
        : "-";
    document.getElementById("gfUltima").textContent = ultima || "-";
    const sedes = new Set();
    GF_ROWS.forEach(row => {
        const sede = obtenerCampo(row, ["Sede a la que postula:"]);
        if (sede.trim()) sedes.add(sede.trim());
    });
    document.getElementById("gfSedes").textContent = sedes.size;
}
function filtrarRowsGoogle() {
    const q = normalizarTexto(document.getElementById("gfBuscar").value);
    const sedeFiltro = normalizarTexto(document.getElementById("gfFiltroSede").value);
    return GF_ROWS.filter(row => {
        const textoFila = normalizarTexto(Object.values(row).join(" "));
        const sede = normalizarTexto(obtenerCampo(row, ["Sede a la que postula:"]));
        const coincideTexto = !q || textoFila.includes(q);
        const coincideSede = !sedeFiltro || sede === sedeFiltro;
        return coincideTexto && coincideSede;
    });
}
function renderEstadoGF(row) {
    const estado = row._gf_estado || "PENDIENTE";
    if (estado === "AGREGADO") {
        return `<span class="gf-chip gf-agregado">Agregado</span>`;
    }
    if (estado === "YA_REGISTRADO") {
        return `<span class="gf-chip gf-registrado">Ya registrado</span>`;
    }
    if (estado === "DESCARTADO") {
        return `<span class="gf-chip gf-descartado">Descartado</span>`;
    }
    return `<span class="gf-chip gf-pendiente">Pendiente</span>`;
}
function renderAccionesGF(row, index) {
    const estado = row._gf_estado || "PENDIENTE";
    const hash = row._gf_hash || "";
    let html = `<div class="gf-actions">`;
    html += `<button type="button" class="gf-btn-mini gf-btn-view" onclick="verDetalleGoogle(${index})">Ver</button>`;
    if (estado === "PENDIENTE") {
        html += `<button type="button" class="gf-btn-mini gf-btn-add" onclick="agregarGoogleAEntrevistas('${hash}')">Agregar</button>`;
        html += `<button type="button" class="gf-btn-mini gf-btn-discard" onclick="descartarGoogleForm('${hash}')">Descartar</button>`;
    } else if (estado === "DESCARTADO") {
        html += `<button type="button" class="gf-btn-mini gf-btn-add" onclick="agregarGoogleAEntrevistas('${hash}')">Agregar</button>`;
    } else {
        html += `<button type="button" class="gf-btn-mini gf-btn-disabled" disabled>Gestionado</button>`;
    }
    html += `</div>`;
    return html;
}
function renderTablaGoogleForm() {
    const thead = document.querySelector("#tablaGoogleForm thead");
    const tbody = document.querySelector("#tablaGoogleForm tbody");
    thead.innerHTML = "";
    tbody.innerHTML = "";
    const headersVista = [
        "Marca temporal",
        "Nombres y Apellidos",
        "Numero de DNI:",
        "Correo electrónico:",
        "Número de teléfono:",
        "Puesto al que postula:",
        "Sede a la que postula:",
        "Adjuntar CV actualizado",
        "Dirección de correo electrónico"
    ];
    const trHead = document.createElement("tr");
    headersVista.forEach(h => {
        const th = document.createElement("th");
        th.textContent = h;
        trHead.appendChild(th);
    });
    const thEstado = document.createElement("th");
    thEstado.textContent = "Estado";
    trHead.appendChild(thEstado);
    const thAccion = document.createElement("th");
    thAccion.textContent = "Acción";
    trHead.appendChild(thAccion);
    thead.appendChild(trHead);
    const rowsFiltradas = filtrarRowsGoogle();
    rowsFiltradas.forEach((row, index) => {
        const tr = document.createElement("tr");
        headersVista.forEach(h => {
            const td = document.createElement("td");
            td.innerHTML = renderValor(obtenerCampo(row, [h]));
            tr.appendChild(td);
        });
        const tdEstado = document.createElement("td");
        tdEstado.innerHTML = renderEstadoGF(row);
        tr.appendChild(tdEstado);
        const tdAccion = document.createElement("td");
        tdAccion.innerHTML = renderAccionesGF(row, index);
        tr.appendChild(tdAccion);
        tr.addEventListener("dblclick", () => verDetalleGoogle(index));
        tbody.appendChild(tr);
    });
    document.getElementById("gfEstado").textContent = `Mostrando ${rowsFiltradas.length} de ${GF_ROWS.length} respuestas.`;
}
async function agregarGoogleAEntrevistas(hash) {
    if (!hash) {
        alert("Hash inválido.");
        return;
    }
    if (!confirm("¿Agregar este postulante a la tabla de entrevistas?")) {
        return;
    }
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const resp = await fetch("bvisentrevisaf.php?accion=google_form_agregar", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: `hash=${encodeURIComponent(hash)}&csrf=${encodeURIComponent(csrf)}`
        });
        const data = await resp.json();
        if (!data.ok) {
            alert("⚠️ " + (data.mensaje || "No se pudo agregar."));
            return;
        }
        alert("✅ " + data.mensaje);
        await cargarGoogleForm();
        // Recarga la página principal para que la tabla entrevistas muestre el nuevo registro
        setTimeout(() => {
            location.reload();
        }, 500);
    } catch (error) {
        console.error(error);
        alert("❌ Error al agregar el postulante.");
    }
}
async function descartarGoogleForm(hash) {
    if (!hash) {
        alert("Hash inválido.");
        return;
    }
    const motivo = prompt("Motivo del descarte:", "No cumple con el perfil requerido.");
    if (motivo === null) {
        return;
    }
    if (!confirm("¿Descartar este registro? No se eliminará nada del Google Sheet.")) {
        return;
    }
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    try {
        const resp = await fetch("bvisentrevisaf.php?accion=google_form_descartar", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: `hash=${encodeURIComponent(hash)}&motivo=${encodeURIComponent(motivo)}&csrf=${encodeURIComponent(csrf)}`
        });
        const data = await resp.json();
        if (!data.ok) {
            alert("⚠️ " + (data.mensaje || "No se pudo descartar."));
            return;
        }
        alert("✅ " + data.mensaje);
        await cargarGoogleForm();
    } catch (error) {
        console.error(error);
        alert("❌ Error al descartar el registro.");
    }
}
function verDetalleGoogle(indexFiltrado) {
    const rowsFiltradas = filtrarRowsGoogle();
    const row = rowsFiltradas[indexFiltrado];
    if (!row) return;
    const cont = document.getElementById("contenidoGoogleDetalle");
    cont.innerHTML = "";
    const acciones = document.createElement("div");
    acciones.className = "google-detail-item";
    acciones.style.gridColumn = "1 / -1";
    acciones.innerHTML = `
        <small>Gestión del registro</small>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <div>${renderEstadoGF(row)}</div>
            <div>${renderAccionesGF(row, indexFiltrado)}</div>
        </div>
    `;
    cont.appendChild(acciones);
    if (row._gf_motivo) {
        const motivo = document.createElement("div");
        motivo.className = "google-detail-item";
        motivo.style.gridColumn = "1 / -1";
        motivo.innerHTML = `
            <small>Motivo / Observación de gestión</small>
            <div>${escapeHtml(row._gf_motivo)}</div>
        `;
        cont.appendChild(motivo);
    }
    GF_HEADERS.forEach(header => {
        const valor = row[header] ?? "";
        const item = document.createElement("div");
        item.className = "google-detail-item";
        item.innerHTML = `
            <small>${escapeHtml(header)}</small>
            <div>${renderValor(valor)}</div>
        `;
        cont.appendChild(item);
    });
    document.getElementById("modalGoogleDetalle").style.display = "block";
}
document.addEventListener("DOMContentLoaded", function () {
    const buscar = document.getElementById("gfBuscar");
    const sede = document.getElementById("gfFiltroSede");
    if (buscar) buscar.addEventListener("input", renderTablaGoogleForm);
    if (sede) sede.addEventListener("change", renderTablaGoogleForm);
});
window.addEventListener("click", function(event) {
    const modalGoogle = document.getElementById("modalGoogleForm");
    const modalDetalleGoogle = document.getElementById("modalGoogleDetalle");
    if (event.target === modalGoogle) {
        cerrarModalGoogleForm();
    }
    if (event.target === modalDetalleGoogle) {
        cerrarModalGoogleDetalle();
    }
});
</script>
<script src="../assets/js/header_n360.js"></script>
<script src="../assets/js/sidebar_n360.js"></script>
</body>
</html>
