<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}

$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
$vistas = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['vistas'] ?? []);

if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 10; // id_modulo de esta vista
    $vista_actuales = ["f-progcond"];

    if (!in_array($modulo_actual, $_SESSION['permisos']) || empty(array_intersect($vista_actuales, $_SESSION['vistas']))) {
        header("Location: ../../login/none_permisos.php");
        exit();
    }
}

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
require_once("../trash/copidb_secure.php");
$exito = isset($_SESSION['exito']) && $_SESSION['exito'] === true;
unset($_SESSION['exito']);

?>
<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function condt_db(): mysqli {
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->set_charset("utf8mb4");
        return $conn;
    }
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $GLOBALS['conn']->set_charset("utf8mb4");
        return $GLOBALS['conn'];
    }
    throw new Exception("No se encontró una conexión mysqli válida.");
}

function condt_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function condt_safe($v): string {
    return $v === null ? '' : trim((string)$v);
}

function condt_user_id(): int {
    if (!empty($_SESSION['id_usuario'])) return (int)$_SESSION['id_usuario'];
    if (!empty($_SESSION['web_id_usuario'])) return (int)$_SESSION['web_id_usuario'];
    return 0;
}

function condt_bus_label($bus, $placa): string {
    $bus   = condt_safe($bus);
    $placa = condt_safe($placa);
    if ($bus !== '' && $placa !== '') return "{$bus} ({$placa})";
    return $bus !== '' ? $bus : ($placa !== '' ? $placa : '—');
}

function condt_max_por_placa(): int {
    $db = condt_db();
    $sql = "
        SELECT COALESCE(clm_progconductorescfg_max_por_placa, 2) AS maximo
        FROM tb_progconductores_config
        WHERE clm_progconductorescfg_id = 1
        LIMIT 1
    ";
    $rs = $db->query($sql);
    $row = $rs->fetch_assoc();
    $valor = isset($row['maximo']) ? (int)$row['maximo'] : 2;
    if ($valor < 1) $valor = 1;
    if ($valor > 5) $valor = 5;
    return $valor;
}

function condt_usuario_texto(?int $uid): string {
    if (!$uid) return '—';
    try {
        $db = condt_db();
        $stmt = $db->prepare("
            SELECT COALESCE(NULLIF(TRIM(usuario), ''), NULLIF(TRIM(nombre), ''), '') AS txt
            FROM tb_usuarios
            WHERE id_usuario = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && trim((string)$row['txt']) !== '') {
            return trim((string)$row['txt']);
        }
    } catch (Throwable $e) {
    }
    return "ID {$uid}";
}

function condt_fetch_placas_activas(): array {
    $db = condt_db();
    $sql = "
        SELECT
            clm_placas_id,
            IFNULL(clm_placas_BUS, '')   AS bus,
            IFNULL(clm_placas_PLACA, '') AS placa
        FROM tb_placas
        WHERE UPPER(TRIM(IFNULL(clm_placas_ESTADO, 'ACTIVO'))) = 'ACTIVO'
          AND clm_placas_TIPO_VEHÍCULO IN ('BUS', 'CARGUERO')
        ORDER BY clm_placas_BUS ASC, clm_placas_PLACA ASC
    ";
    $rs = $db->query($sql);
    return $rs->fetch_all(MYSQLI_ASSOC);
}

function condt_fetch_retenes(string $buscar = ''): array {
    $db = condt_db();
    $sql = "
        SELECT
            pc.clm_progconductores_progid,
            pc.clm_progconductores_idplaca,
            pc.clm_progconductores_idconductor,
            IFNULL(p.clm_placas_BUS, '')   AS bus,
            IFNULL(p.clm_placas_PLACA, '') AS placa,
            IFNULL(t.clm_tra_nombres, '')           AS conductor,
            IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia,
            IFNULL(t.clm_tra_tipolicencia, '')      AS tipolicencia,
            IFNULL(t.clm_tra_categorialicen, '')    AS categoria,
            IFNULL(t.clm_tra_dni, '')               AS dni,
            IFNULL(t.clm_tra_celular, '')           AS celular
        FROM tb_progconductores pc
        LEFT JOIN tb_trabajador t
            ON t.clm_tra_id = pc.clm_progconductores_idconductor
        LEFT JOIN tb_placas p
            ON p.clm_placas_id = pc.clm_progconductores_idplaca
        WHERE pc.clm_progconductores_estado = 1
        AND pc.clm_progconductores_tipoprog = 2
        AND pc.clm_progconductores_idconductor IS NOT NULL
    ";

    if ($buscar !== '') {
        $buscarLike = '%' . $buscar . '%';
        $sql .= "
          AND (
                t.clm_tra_nombres LIKE ?
             OR t.clm_tra_nlicenciaconducir LIKE ?
             OR t.clm_tra_dni LIKE ?
             OR t.clm_tra_celular LIKE ?
             OR t.clm_tra_categorialicen LIKE ?
          )
        ORDER BY t.clm_tra_nombres ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $buscarLike, $buscarLike, $buscarLike, $buscarLike, $buscarLike);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    $sql .= " ORDER BY t.clm_tra_nombres ASC";
    return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function condt_fetch_pendientes_reten(string $buscar = ''): array {
    $db = condt_db();

    $viewExists = false;
    $chk = $db->query("
        SELECT COUNT(*) AS c
        FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'view_trabajadores_conductores'
    ");
    if ($chk) {
        $row = $chk->fetch_assoc();
        $viewExists = ((int)$row['c'] > 0);
    }

    if ($viewExists) {
        $sql = "
            SELECT
                t.clm_tra_id,
                IFNULL(t.clm_tra_nombres, '')           AS conductor,
                IFNULL(t.clm_tra_dni, '')               AS dni,
                IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia,
                IFNULL(t.clm_tra_categorialicen, '')    AS categoria,
                IFNULL(t.clm_tra_celular, '')           AS celular
            FROM view_trabajadores_conductores t
            LEFT JOIN tb_progconductores pc
                ON pc.clm_progconductores_idconductor = t.clm_tra_id
               AND pc.clm_progconductores_estado = 1
            WHERE pc.clm_progconductores_progid IS NULL
        ";
    } else {
        $sql = "
            SELECT
                t.clm_tra_id,
                IFNULL(t.clm_tra_nombres, '')           AS conductor,
                IFNULL(t.clm_tra_dni, '')               AS dni,
                IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia,
                IFNULL(t.clm_tra_categorialicen, '')    AS categoria,
                IFNULL(t.clm_tra_celular, '')           AS celular
            FROM tb_trabajador t
            LEFT JOIN tb_progconductores pc
                ON pc.clm_progconductores_idconductor = t.clm_tra_id
               AND pc.clm_progconductores_estado = 1
            WHERE UPPER(TRIM(IFNULL(t.clm_tra_tipo_trabajador, ''))) = 'CONDUCTOR'
              AND UPPER(TRIM(IFNULL(t.clm_tra_contrato, ''))) = 'ACTIVO'
              AND pc.clm_progconductores_progid IS NULL
        ";
    }

    if ($buscar !== '') {
        $buscarLike = '%' . $buscar . '%';
        $sql .= "
          AND (
                t.clm_tra_nombres LIKE ?
             OR t.clm_tra_nlicenciaconducir LIKE ?
             OR t.clm_tra_dni LIKE ?
             OR t.clm_tra_celular LIKE ?
             OR t.clm_tra_categorialicen LIKE ?
          )
          ORDER BY t.clm_tra_nombres ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $buscarLike, $buscarLike, $buscarLike, $buscarLike, $buscarLike);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    $sql .= " ORDER BY t.clm_tra_nombres ASC";
    return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function condt_fetch_detalle_conductor(int $idConductor): ?array {
    $db = condt_db();
    $stmt = $db->prepare("
        SELECT
            clm_tra_id,
            clm_tra_nombres,
            clm_tra_dni,
            clm_tra_contrato,
            clm_tra_tipo_trabajador,
            clm_tra_cargo,
            clm_tra_imagen,
            clm_tra_nlicenciaconducir,
            clm_tra_tipolicencia,
            clm_tra_categorialicen,
            DATE_FORMAT(clm_tra_licfecha_expedicion, '%Y-%m-%d')  AS clm_tra_licfecha_expedicion,
            DATE_FORMAT(clm_tra_licfecha_revaluacion, '%Y-%m-%d') AS clm_tra_licfecha_revaluacion,
            clm_tra_correo,
            clm_tra_domicilio,
            clm_tra_celular
        FROM tb_trabajador
        WHERE clm_tra_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $idConductor);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

    $imgBase64 = null;
    if (!empty($row['clm_tra_imagen'])) {
        $imgBase64 = 'data:image/jpeg;base64,' . base64_encode($row['clm_tra_imagen']);
    }
    unset($row['clm_tra_imagen']);
    $row['imagen_base64'] = $imgBase64;

    return $row;
}

function condt_fetch_conductores_estado(string $buscar = ''): array {
    $db = condt_db();

    $sql = "
        SELECT
            t.clm_tra_id,
            IFNULL(t.clm_tra_nombres, '')           AS nombres,
            IFNULL(t.clm_tra_dni, '')               AS dni,
            IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia,
            IFNULL(t.clm_tra_celular, '')           AS celular,
            IFNULL(t.clm_tra_cargo, '')             AS cargo,
            CASE
                WHEN UPPER(TRIM(IFNULL(t.clm_tra_contrato, ''))) = 'ACTIVO' THEN 'Activo'
                ELSE 'Inactivo'
            END AS contrato
        FROM tb_trabajador t
        WHERE UPPER(TRIM(IFNULL(t.clm_tra_tipo_trabajador, ''))) = 'CONDUCTOR'
    ";

    if ($buscar !== '') {
        $buscarLike = '%' . $buscar . '%';
        $sql .= "
            AND (
                   t.clm_tra_nombres LIKE ?
                OR t.clm_tra_dni LIKE ?
                OR t.clm_tra_nlicenciaconducir LIKE ?
                OR t.clm_tra_celular LIKE ?
                OR t.clm_tra_cargo LIKE ?
            )
        ";
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN UPPER(TRIM(IFNULL(t.clm_tra_contrato, ''))) = 'ACTIVO' THEN 0
                ELSE 1
            END,
            t.clm_tra_nombres ASC
    ";

    if ($buscar !== '') {
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $buscarLike, $buscarLike, $buscarLike, $buscarLike, $buscarLike);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function condt_actualizar_estado_conductor(int $idTrabajador, string $estado): void {
    $db = condt_db();

    $permitidos = ['Activo', 'Inactivo'];
    if (!in_array($estado, $permitidos, true)) {
        throw new Exception("Estado no permitido.");
    }

    $stmt = $db->prepare("
        UPDATE tb_trabajador
        SET clm_tra_contrato = ?
        WHERE clm_tra_id = ?
          AND UPPER(TRIM(IFNULL(clm_tra_tipo_trabajador, ''))) = 'CONDUCTOR'
        LIMIT 1
    ");
    $stmt->bind_param("si", $estado, $idTrabajador);
    $stmt->execute();

    if ($stmt->affected_rows < 0) {
        $stmt->close();
        throw new Exception("No se pudo actualizar el conductor.");
    }

    $stmt->close();
}

function condt_fetch_historial(int $limit = 300, string $buscar = ''): array {
    $db = condt_db();
    $limit = max(1, min(500, $limit));

    $sql = "
        SELECT
            h.clm_hist_progconductores_id            AS hist_id,
            UPPER(h.clm_hist_progconductores_accion) AS accion,
            DATE_FORMAT(h.clm_hist_progconductores_fechaevento, '%d/%m/%Y %H:%i') AS fechaevento,
            h.clm_hist_progconductores_fechaevento   AS fechaevento_raw,
            h.clm_progconductores_progid             AS progid,
            h.clm_progconductores_tipoprog           AS tipoprog,
            h.clm_progconductores_estado             AS estado,
            h.clm_progconductores_idusuario          AS idusuario,
            h.clm_progconductores_idplaca            AS idplaca,
            h.clm_progconductores_idconductor        AS idconductor,
            IFNULL(h.clm_progconductores_motivo, '') AS motivo,
            IFNULL(p.clm_placas_BUS, '')             AS bus,
            IFNULL(p.clm_placas_PLACA, '')           AS placa,
            IFNULL(t.clm_tra_nombres, '')            AS conductor,
            IFNULL(t.clm_tra_dni, '')                AS dni,
            IFNULL(t.clm_tra_nlicenciaconducir, '')  AS licencia
        FROM tb_hist_progconductores h
        LEFT JOIN tb_placas p
            ON p.clm_placas_id = h.clm_progconductores_idplaca
        LEFT JOIN tb_trabajador t
            ON t.clm_tra_id = h.clm_progconductores_idconductor
        WHERE 1 = 1
    ";

    $params = [];
    $types  = '';

    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $sql .= "
            AND (
                   t.clm_tra_nombres LIKE ?
                OR t.clm_tra_dni LIKE ?
                OR t.clm_tra_nlicenciaconducir LIKE ?
                OR p.clm_placas_BUS LIKE ?
                OR p.clm_placas_PLACA LIKE ?
                OR h.clm_hist_progconductores_accion LIKE ?
                OR h.clm_progconductores_motivo LIKE ?
            )
        ";
        $params = [$like, $like, $like, $like, $like, $like, $like];
        $types  = 'sssssss';
    }

    $sql .= " ORDER BY h.clm_hist_progconductores_fechaevento DESC, h.clm_hist_progconductores_id DESC LIMIT {$limit}";

    if ($types !== '') {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    $out = [];
    foreach ($rows as $row) {
        $accion    = strtoupper(condt_safe($row['accion']));
        $tipoprog  = isset($row['tipoprog']) ? (int)$row['tipoprog'] : null;
        $estado    = isset($row['estado']) ? (int)$row['estado'] : null;
        $conductor = condt_safe($row['conductor']) ?: 'Conductor no identificado';
        $unidad    = !empty($row['idplaca']) ? condt_bus_label($row['bus'], $row['placa']) : 'Sin unidad asignada';
        $motivo    = condt_safe($row['motivo']);

        $detalle = "Se registró un cambio asociado a {$conductor}.";
        $titulo = 'Movimiento registrado';
        $etiqueta = 'Movimiento';
        $color = '#34495e';

        $conMotivo = function(string $txt) use ($motivo): string {
            return $motivo !== '' ? $txt . " Motivo: {$motivo}." : $txt;
        };

        if ($accion === 'INSERT') {
            if ($tipoprog === 2 && empty($row['idplaca'])) {
                $titulo   = 'Retén incorporado';
                $detalle  = $conMotivo("{$conductor} fue incorporado al grupo de retenes y quedó disponible para apoyo.");
                $etiqueta = 'Nuevo retén';
                $color    = '#16a085';
            } elseif ($tipoprog === 1 && !empty($row['idplaca'])) {
                $titulo   = 'Conductor asignado a unidad';
                $detalle  = $conMotivo("{$conductor} fue asignado a la unidad {$unidad}.");
                $etiqueta = 'Asignación';
                $color    = '#27ae60';
            }
        } elseif ($accion === 'UPDATE') {
            if ($estado === 2) {
                $titulo   = 'Asignación desactivada';
                $detalle  = $conMotivo("Se dejó sin efecto el registro asociado a {$conductor}.");
                $etiqueta = 'Desactivado';
                $color    = '#7f8c8d';
            } elseif ($tipoprog === 2 && empty($row['idplaca'])) {
                $titulo   = 'Conductor liberado a retén';
                $detalle  = $conMotivo("{$conductor} quedó libre como conductor de apoyo.");
                $etiqueta = 'Retén libre';
                $color    = '#e67e22';
            } elseif ($tipoprog === 1 && !empty($row['idplaca'])) {
                $titulo   = 'Asignación actualizada';
                $detalle  = $conMotivo("{$conductor} figura asignado a la unidad {$unidad}.");
                $etiqueta = 'Actualización';
                $color    = '#2980b9';
            }
        } elseif ($accion === 'DELETE') {
            $titulo   = 'Registro retirado';
            $detalle  = $conMotivo("Se eliminó un registro relacionado con {$conductor}.");
            $etiqueta = 'Eliminado';
            $color    = '#c0392b';
        }

        $out[] = [
            'hist_id'      => (int)$row['hist_id'],
            'fechaevento'  => condt_safe($row['fechaevento']),
            'titulo'       => $titulo,
            'detalle'      => $detalle,
            'etiqueta'     => $etiqueta,
            'color'        => $color,
            'conductor'    => $conductor,
            'licencia'     => condt_safe($row['licencia']) ?: '—',
            'unidad'       => !empty($row['idplaca']) ? condt_bus_label($row['bus'], $row['placa']) : 'Libre / apoyo',
            'usuario'      => condt_usuario_texto((int)$row['idusuario']),
            'tipoprog'     => $tipoprog,
            'estado'       => $estado,
            'motivo'       => $motivo,
            'idconductor'  => (int)$row['idconductor'],
            'idplaca'      => !empty($row['idplaca']) ? (int)$row['idplaca'] : null,
        ];
    }

    return $out;
}

function condt_fetch_panel(string $buscarReten = ''): array {
    $db = condt_db();
    $maxPorPlaca = condt_max_por_placa();

    $unidades = condt_fetch_placas_activas();

    $sqlFlota = "
        SELECT
            pc.clm_progconductores_progid,
            pc.clm_progconductores_idplaca,
            pc.clm_progconductores_idconductor,
            IFNULL(t.clm_tra_nombres, '')           AS conductor,
            IFNULL(t.clm_tra_nlicenciaconducir, '') AS licencia,
            IFNULL(t.clm_tra_tipolicencia, '')      AS tipolicencia,
            IFNULL(t.clm_tra_categorialicen, '')    AS categoria,
            IFNULL(t.clm_tra_dni, '')               AS dni,
            IFNULL(t.clm_tra_celular, '')           AS celular
        FROM tb_progconductores pc
        LEFT JOIN tb_trabajador t
            ON t.clm_tra_id = pc.clm_progconductores_idconductor
        WHERE pc.clm_progconductores_estado = 1
        AND pc.clm_progconductores_tipoprog = 1
        ORDER BY pc.clm_progconductores_idplaca, pc.clm_progconductores_progid
    ";
    $asigFlota = $db->query($sqlFlota)->fetch_all(MYSQLI_ASSOC);

    $retenes = condt_fetch_retenes($buscarReten);

    $mapa = [];
    foreach ($asigFlota as $a) {
        $placaId = (int)$a['clm_progconductores_idplaca'];
        if (!isset($mapa[$placaId])) $mapa[$placaId] = [];
        $mapa[$placaId][] = $a;
    }

    $flotas = [];
    foreach ($unidades as $placa) {
        $placaId = (int)$placa['clm_placas_id'];
        $filasAsignadas = $mapa[$placaId] ?? [];

        for ($idx = 0; $idx < $maxPorPlaca; $idx++) {
            if ($idx < count($filasAsignadas)) {
                $a = $filasAsignadas[$idx];
                $flotas[] = [
                    'slot' => $idx + 1,
                    'clm_placas_id' => $placaId,
                    'bus' => condt_safe($placa['bus']),
                    'placa' => condt_safe($placa['placa']),
                    'clm_progconductores_progid' => (int)$a['clm_progconductores_progid'],
                    'clm_progconductores_idconductor' => (int)$a['clm_progconductores_idconductor'],
                    'conductor' => condt_safe($a['conductor']),
                    'licencia' => condt_safe($a['licencia']),
                    'tipolicencia' => condt_safe($a['tipolicencia']),
                    'categoria' => condt_safe($a['categoria']),
                    'dni' => condt_safe($a['dni']),
                    'celular' => condt_safe($a['celular']),
                ];
            } else {
                $flotas[] = [
                    'slot' => $idx + 1,
                    'clm_placas_id' => $placaId,
                    'bus' => condt_safe($placa['bus']),
                    'placa' => condt_safe($placa['placa']),
                    'clm_progconductores_progid' => null,
                    'clm_progconductores_idconductor' => null,
                    'conductor' => '',
                    'licencia' => '',
                    'tipolicencia' => '',
                    'categoria' => '',
                    'dni' => '',
                    'celular' => '',
                ];
            }
        }
    }

    return [
        'max_por_placa' => $maxPorPlaca,
        'unidades' => $unidades,
        'flotas' => $flotas,
        'retenes' => $retenes,
        'resumen' => [
            'unidades_activas' => count($unidades),
            'cabinas_visualizadas' => count($flotas),
            'retenes_disponibles' => count($retenes),
            'asignados' => count(array_filter($flotas, fn($r) => !empty($r['clm_progconductores_idconductor']))),
            'sin_asignar' => count(array_filter($flotas, fn($r) => empty($r['clm_progconductores_idconductor']))),
        ]
    ];
}

function condt_insert_reten_desde_trabajador(int $idTrabajador): void {
    $db = condt_db();
    $uid = condt_user_id();
    $stmt = $db->prepare("
        INSERT INTO tb_progconductores (
            clm_progconductores_fechacreated,
            clm_progconductores_idplaca,
            clm_progconductores_idconductor,
            clm_progconductores_tipoprog,
            clm_progconductores_estado,
            clm_progconductores_idusuario
        ) VALUES (
            NOW(), NULL, ?, 2, 1, ?
        )
    ");
    $stmt->bind_param("ii", $idTrabajador, $uid);
    $stmt->execute();
    $stmt->close();
}

function condt_asignar_reten_a_unidad(int $progidReten, int $idPlaca): void {
    $db = condt_db();
    $uid = condt_user_id();
    $stmt = $db->prepare("
        UPDATE tb_progconductores
        SET
            clm_progconductores_idplaca = ?,
            clm_progconductores_tipoprog = 1,
            clm_progconductores_estado = 1,
            clm_progconductores_idusuario = ?,
            clm_progconductores_motivo = NULL
        WHERE clm_progconductores_progid = ?
    ");
    $stmt->bind_param("iii", $idPlaca, $uid, $progidReten);
    $stmt->execute();
    $stmt->close();
}

function condt_cambiar_conductor_unidad(int $progidFlotaActual, int $progidRetenNuevo, int $idPlaca, string $motivo): void {
    $db = condt_db();
    $uid = condt_user_id();
    $db->begin_transaction();

    try {
        $stmtVar = $db->prepare("SET @motivo_hist_progconductores = ?");
        $stmtVar->bind_param("s", $motivo);
        $stmtVar->execute();
        $stmtVar->close();

        $stmt1 = $db->prepare("
            UPDATE tb_progconductores
            SET
                clm_progconductores_idplaca = NULL,
                clm_progconductores_tipoprog = 2,
                clm_progconductores_estado = 1,
                clm_progconductores_idusuario = ?,
                clm_progconductores_motivo = NULL
            WHERE clm_progconductores_progid = ?
        ");
        $stmt1->bind_param("ii", $uid, $progidFlotaActual);
        $stmt1->execute();
        $stmt1->close();

        $db->query("SET @motivo_hist_progconductores = NULL");

        $stmt2 = $db->prepare("
            UPDATE tb_progconductores
            SET
                clm_progconductores_idplaca = ?,
                clm_progconductores_tipoprog = 1,
                clm_progconductores_estado = 1,
                clm_progconductores_idusuario = ?,
                clm_progconductores_motivo = NULL
            WHERE clm_progconductores_progid = ?
        ");
        $stmt2->bind_param("iii", $idPlaca, $uid, $progidRetenNuevo);
        $stmt2->execute();
        $stmt2->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function condt_liberar_conductor_unidad(int $progidFlota, string $motivo): void {
    $db = condt_db();
    $uid = condt_user_id();
    $db->begin_transaction();

    try {
        $stmtVar = $db->prepare("SET @motivo_hist_progconductores = ?");
        $stmtVar->bind_param("s", $motivo);
        $stmtVar->execute();
        $stmtVar->close();

        $stmt = $db->prepare("
            UPDATE tb_progconductores
            SET
                clm_progconductores_idplaca = NULL,
                clm_progconductores_tipoprog = 2,
                clm_progconductores_estado = 1,
                clm_progconductores_idusuario = ?,
                clm_progconductores_motivo = NULL
            WHERE clm_progconductores_progid = ?
        ");
        $stmt->bind_param("ii", $uid, $progidFlota);
        $stmt->execute();
        $stmt->close();

        $db->query("SET @motivo_hist_progconductores = NULL");

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/* ============================
   AJAX GET
============================ */
if (isset($_GET['ajax'])) {
    try {
        $ajax = $_GET['ajax'];

        if ($ajax === 'panel') {
            condt_json([
                'ok' => true,
                'data' => condt_fetch_panel(trim($_GET['buscar_reten'] ?? ''))
            ]);
        }
        if ($ajax === 'conductores_estado') {
            $buscar = trim($_GET['buscar'] ?? '');
            condt_json([
                'ok' => true,
                'data' => condt_fetch_conductores_estado($buscar)
            ]);
        }
        if ($ajax === 'detalle_conductor') {
            $id = (int)($_GET['id_conductor'] ?? 0);
            if ($id <= 0) {
                condt_json(['ok' => false, 'msg' => 'ID de conductor inválido.'], 422);
            }
            $data = condt_fetch_detalle_conductor($id);
            if (!$data) {
                condt_json(['ok' => false, 'msg' => 'No se encontró el conductor.'], 404);
            }
            condt_json(['ok' => true, 'data' => $data]);
        }

        if ($ajax === 'historial') {
            $buscar = trim($_GET['buscar'] ?? '');
            condt_json([
                'ok' => true,
                'data' => condt_fetch_historial(300, $buscar)
            ]);
        }

        if ($ajax === 'retenes') {
            $buscar = trim($_GET['buscar'] ?? '');
            condt_json([
                'ok' => true,
                'data' => condt_fetch_retenes($buscar)
            ]);
        }

        if ($ajax === 'pendientes_reten') {
            $buscar = trim($_GET['buscar'] ?? '');
            condt_json([
                'ok' => true,
                'data' => condt_fetch_pendientes_reten($buscar)
            ]);
        }

        condt_json(['ok' => false, 'msg' => 'Acción AJAX no válida.'], 404);
    } catch (Throwable $e) {
        condt_json(['ok' => false, 'msg' => $e->getMessage()], 500);
    }
}

/* ============================
   AJAX POST
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    try {
        $action = $_POST['ajax_action'];

        if ($action === 'convertir_pendiente_a_reten') {
            $idTrabajador = (int)($_POST['id_trabajador'] ?? 0);
            if ($idTrabajador <= 0) throw new Exception("Trabajador inválido.");
            condt_insert_reten_desde_trabajador($idTrabajador);
            condt_json(['ok' => true, 'msg' => 'El conductor fue registrado como retén.']);
        }

        if ($action === 'asignar_reten') {
            $progidReten = (int)($_POST['progid_reten'] ?? 0);
            $idPlaca     = (int)($_POST['idplaca'] ?? 0);
            if ($progidReten <= 0 || $idPlaca <= 0) throw new Exception("Parámetros inválidos.");
            condt_asignar_reten_a_unidad($progidReten, $idPlaca);
            condt_json(['ok' => true, 'msg' => 'Conductor asignado correctamente.']);
        }

        if ($action === 'cambiar_conductor') {
            $progidFlotaActual = (int)($_POST['progid_flota_actual'] ?? 0);
            $progidRetenNuevo  = (int)($_POST['progid_reten_nuevo'] ?? 0);
            $idPlaca           = (int)($_POST['idplaca'] ?? 0);
            $motivo            = trim($_POST['motivo'] ?? '');

            if ($progidFlotaActual <= 0 || $progidRetenNuevo <= 0 || $idPlaca <= 0) {
                throw new Exception("Parámetros inválidos para el cambio.");
            }
            if ($motivo === '') throw new Exception("Debes escribir un motivo.");

            condt_cambiar_conductor_unidad($progidFlotaActual, $progidRetenNuevo, $idPlaca, $motivo);
            condt_json(['ok' => true, 'msg' => 'Cambio de conductor realizado correctamente.']);
        }

        if ($action === 'liberar_conductor') {
            $progidFlota = (int)($_POST['progid_flota'] ?? 0);
            $motivo      = trim($_POST['motivo'] ?? '');

            if ($progidFlota <= 0) throw new Exception("Registro de flota inválido.");
            if ($motivo === '') throw new Exception("Debes escribir un motivo.");

            condt_liberar_conductor_unidad($progidFlota, $motivo);
            condt_json(['ok' => true, 'msg' => 'Conductor liberado a retén correctamente.']);
        }
        if ($action === 'actualizar_estado_conductor') {
            $idTrabajador = (int)($_POST['id_trabajador'] ?? 0);
            $estado       = trim($_POST['estado'] ?? '');

            if ($idTrabajador <= 0) throw new Exception("Trabajador inválido.");

            condt_actualizar_estado_conductor($idTrabajador, $estado);
            condt_json(['ok' => true, 'msg' => 'Estado actualizado correctamente.']);
        }
        condt_json(['ok' => false, 'msg' => 'Acción POST no válida.'], 404);
    } catch (Throwable $e) {
        condt_json(['ok' => false, 'msg' => $e->getMessage()], 500);
    }
}
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>


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


/* =========================
   PROGRAMACIÓN CONDUCTORES
========================= */


.condt-shell .card {
    max-width: 100%;
    margin: 0;
    padding: 0;
}

.condt-header {
    background: linear-gradient(135deg, #243447, #30475e);
    color: #fff;
    border-radius: 18px;
    padding: 22px 24px;
    margin-bottom: 18px;
    box-shadow: 0 12px 25px rgba(44, 62, 80, 0.18);
}

.condt-header h2 {
    margin: 0;
    color: #fff;
    text-align: left;
    font-weight: 800;
    font-size: 2rem;
}

.condt-header p {
    margin: 8px 0 0 0;
    color: #dbe7f3;
    font-size: 14px;
}

.condt-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.condt-btn,
.condt-mini-btn {
    width: auto !important;
}

.condt-btn {
    border: none;
    border-radius: 12px;
    padding: 11px 16px;
    font-weight: 700;
    transition: .22s ease;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    box-shadow: 0 5px 12px rgba(0,0,0,.08);
}

.condt-btn:hover {
    transform: translateY(-1px);
}

.condt-btn-xs {
    padding: 8px 12px;
    font-size: 13px;
}

.condt-btn-primary { background: #2980b9; color: #fff; }
.condt-btn-success { background: #16a085; color: #fff; }
.condt-btn-warning { background: #e67e22; color: #fff; }
.condt-btn-danger  { background: #c0392b; color: #fff; }
.condt-btn-dark    { background: #64748b; color: #fff; }
.condt-btn-light   { background: #eef2f7; color: #243447; }

.condt-summary-card {
    border: none;
    border-radius: 18px;
    color: white;
    overflow: hidden;
    box-shadow: 0 12px 22px rgba(0,0,0,.08);
}

.condt-summary-card .card-body {
    padding: 20px;
}

.condt-summary-label {
    font-size: 13px;
    opacity: 0.92;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    font-weight: 700;
}

.condt-summary-value {
    font-size: 32px;
    font-weight: 800;
    line-height: 1;
}

.condt-panel-card {
    border: none;
    border-radius: 20px;
    box-shadow: 0 12px 26px rgba(44,62,80,.08);
    overflow: hidden;
}

.condt-panel-head {
    background: linear-gradient(135deg, #243447, #30475e);
    color: white;
    padding: 15px 18px;
    font-weight: 800;
    font-size: 15px;
}

.condt-panel-body {
    padding: 18px;
    background: #fff;
}

.condt-inline-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.condt-search-bar {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.condt-search-group {
    position: relative;
}

.condt-search-group i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 15px;
    pointer-events: none;
}

.condt-search-group .form-control {
    padding-left: 40px;
    border-radius: 12px;
    border: 1px solid #dbe4ee;
    min-height: 46px;
    box-shadow: none;
}

.condt-search-group .form-control:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 0.18rem rgba(52, 152, 219, 0.12);
}

.condt-search-hint {
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
}

.condt-unit-card {
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    margin-bottom: 14px;
    overflow: hidden;
    box-shadow: 0 7px 18px rgba(0,0,0,0.045);
    background: #fff;
}

.condt-unit-head {
    background: linear-gradient(135deg, #34495e, #3c5871);
    color: white;
    padding: 14px 16px;
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 12px;
    align-items: center;
}

.condt-unit-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.condt-unit-title {
    font-weight: 800;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.condt-unit-sub {
    font-size: 13px;
    color: #d6e2ee;
}

.condt-unit-metrics {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.condt-chip {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.16);
    color: #fff;
    border-radius: 999px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.condt-unit-toggle {
    width: 42px !important;
    height: 42px;
    border: none;
    border-radius: 12px;
    background: rgba(255,255,255,.14);
    color: white;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: .2s ease;
}

.condt-unit-toggle:hover {
    background: rgba(255,255,255,.22);
}

.condt-unit-toggle i {
    transition: transform .2s ease;
}

.condt-unit-toggle[aria-expanded="true"] i {
    transform: rotate(180deg);
}

.condt-unit-body {
    background: #f8fbfe;
    border-top: 1px solid #edf2f7;
}

.condt-slot-row {
    display: grid;
    grid-template-columns: 120px 1fr auto;
    gap: 12px;
    padding: 14px 16px;
    border-top: 1px solid #eef2f7;
    align-items: center;
    background: #fff;
}

.condt-slot-row:nth-child(even) {
    background: #fbfdff;
}

.condt-slot-badge {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    background: #eaf2f8;
    color: #1f2937;
    font-weight: 700;
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 13px;
}

.condt-driver-name {
    font-weight: 800;
    color: #243447;
    font-size: 15px;
}

.condt-driver-meta {
    color: #64748b;
    font-size: 13px;
    margin-top: 5px;
    line-height: 1.5;
}

.condt-empty {
    color: #94a3b8;
    font-style: italic;
    font-weight: 600;
}

.condt-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.condt-mini-btn {
    border: none;
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: .18s ease;
}

.condt-mini-btn:hover {
    transform: translateY(-1px);
}

.condt-mini-btn.detalle { background: #2980b9; color: #fff; }
.condt-mini-btn.asignar { background: #16a085; color: #fff; }
.condt-mini-btn.cambiar { background: #e67e22; color: #fff; }
.condt-mini-btn.liberar { background: #c0392b; color: #fff; }

.condt-reten-item,
.condt-pend-item,
.condt-hist-item {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 12px;
    background: #fff;
    box-shadow: 0 4px 10px rgba(0,0,0,.03);
}

.condt-clickable {
    color: #2980b9;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
}

.condt-clickable:hover {
    color: #1f6692;
    text-decoration: underline;
}

.condt-hist-chip {
    display: inline-block;
    color: white;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 8px;
}

.condt-muted {
    color: #64748b;
    font-size: 13px;
}

.condt-modal-label {
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 6px;
}

.condt-photo-wrap {
    width: 100%;
    min-height: 240px;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.condt-photo-wrap img {
    max-width: 100%;
    max-height: 260px;
    object-fit: contain;
}

.condt-no-results {
    border: 1px dashed #cbd5e1;
    border-radius: 16px;
    padding: 24px;
    text-align: center;
    background: #f8fafc;
    color: #64748b;
    font-weight: 700;
}

@media (max-width: 992px) {
    .condt-toolbar {
        justify-content: stretch;
    }

    .condt-toolbar .condt-btn {
        flex: 1;
    }

    .condt-unit-head {
        grid-template-columns: 1fr;
    }

    .condt-slot-row {
        grid-template-columns: 1fr;
    }

    .condt-actions {
        justify-content: flex-start;
    }

    .condt-inline-actions {
        width: 100%;
    }
}
.condt-btn-danger {
    background: #c0392b;
    color: #fff;
}

.condt-driver-toggle-item {
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 12px;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,.04);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.condt-driver-toggle-main {
    flex: 1;
    min-width: 260px;
}

.condt-switch-estado-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.condt-switch-estado {
    position: relative;
    display: inline-block;
    width: 68px;
    height: 36px;
}

.condt-switch-estado input {
    opacity: 0;
    width: 0;
    height: 0;
}

.condt-slider-estado {
    position: absolute;
    inset: 0;
    cursor: pointer;
    background: #c0392b;
    border-radius: 999px;
    transition: .25s ease;
    box-shadow: inset 0 2px 6px rgba(0,0,0,.18);
}

.condt-slider-estado:before {
    content: "";
    position: absolute;
    width: 28px;
    height: 28px;
    left: 4px;
    top: 4px;
    border-radius: 50%;
    background: white;
    transition: .25s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
}

.condt-switch-estado input:checked + .condt-slider-estado {
    background: #27ae60;
}

.condt-switch-estado input:checked + .condt-slider-estado:before {
    transform: translateX(32px);
}

.condt-badge-estado {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 110px;
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 14px;
    font-weight: 800;
    transition: .25s ease;
}

.condt-badge-estado.activo {
    background: #eafaf1;
    color: #1e8449;
    border: 1px solid #b7e4c7;
}

.condt-badge-estado.inactivo {
    background: #fdeeee;
    color: #c0392b;
    border: 1px solid #f5b7b1;
}

.condt-estado-loading {
    opacity: .65;
    pointer-events: none;
}

@media (max-width: 768px) {
    .condt-driver-toggle-item {
        align-items: flex-start;
    }
}
@keyframes condtSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
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
    if ($_SESSION['web_rol'] === 'Admin' || in_array(10, $permisos)) {
        echo '<li><a href="#" onclick="mostrarSubmenu(\'modulo-flotayoperaciones\')">🚌 Flota y Operaciones</a></li>';
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
  <a href="../01_amantenimiento/lista_cheklist.php">📝 CheckList</a>
</div>

<div id="modulo-flotayoperaciones" class="subnav" style="display: none;">
  <a href="../01_flota/programacion_horarios.php">📋 Programación Horarios</a>
  <a href="../01_flota/programacion_condt.php">👤 Conductores</a>
  <a href="../01_flota/gest_plac.php">📝 Gestión de Placas</a>
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
    <h3>Programación</h3>
    <li><a href="programacion_condt.php"><i class="bi bi-person-plus-fill"></i> Programación de Conductores</a></li>
    <li><a href="programacion_horarios.php" class="active"><i class="bi bi-clock-history"></i> Programación de Horarios</a></li>
    <li><a href="gest_prog_horarios.php" class="active"><i class="bi bi-bar-chart-line-fill"></i> Historial Gerencial</a></li>
  </ul>
  <ul class="menu-list">
    <h3>Vehículos</h3>
    <li><a href="gest_plac.php"><i class="bi bi-bus-front"></i> Gestionar Placas</a></li>
  </ul>
</nav>

<button class="sidebar-show-btn" id="sidebarShowBtn" aria-label="Mostrar menú">
  <i class="bi bi-chevron-right"></i>
</button>




<div class="main-content">
  <hr>

  <div class="container mt-4 mb-5">
    <div class="condt-shell">
      <div class="condt-header">
  <div class="row g-3 align-items-center">
    <div class="col-lg-7">
      <h2><i class="bi bi-bus-front-fill me-2"></i>Programación de Conductores</h2>
      <p>Cada unidad muestra sus cabinas de conductor según la configuración actual.</p>
    </div>
    <div class="col-lg-5">
      <div class="condt-toolbar">

        <button class="condt-btn condt-btn-warning" id="btnConductoresEstado">
        <i class="bi bi-toggles2 me-2"></i>Lista de Conductores
        </button>

        <button class="condt-btn condt-btn-success" id="btnPendientesReten">
          <i class="bi bi-person-lock me-2"></i>Pendientes para Retén
        </button>
        <button class="condt-btn condt-btn-dark" id="btnHistorial">
          <i class="bi bi-clock-history me-2"></i>Historial de cambios
        </button>

<button class="condt-btn condt-btn-danger" id="btnExportarPdf">
  <i class="bi bi-file-earmark-pdf-fill me-2"></i>PDF Conductores
</button>
<button class="condt-btn condt-btn-dark" id="btnExportarPdfLicencias">
  <i class="bi bi-file-earmark-pdf-fill me-2"></i>PDF Licencias
</button>

        <button class="condt-btn condt-btn-primary" id="btnRecargar">
          <i class="bi bi-arrow-repeat me-2"></i>Recargar
        </button>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4" id="condtResumenWrap" style="display:none;">
  <div class="col-md-6 col-xl-3">
    <div class="card condt-summary-card" style="background:#2c3e50;">
      <div class="card-body">
        <div class="condt-summary-label"><i class="bi bi-bus-front me-2"></i>Unidades activas</div>
        <div class="condt-summary-value" id="sumUnidades">0</div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card condt-summary-card" style="background:#e67e22;">
      <div class="card-body">
        <div class="condt-summary-label"><i class="bi bi-layout-three-columns me-2"></i>Cabinas visualizadas</div>
        <div class="condt-summary-value" id="sumCabinas">0</div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card condt-summary-card" style="background:#16a085;">
      <div class="card-body">
        <div class="condt-summary-label"><i class="bi bi-person-check me-2"></i>Retenes disponibles</div>
        <div class="condt-summary-value" id="sumRetenes">0</div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card condt-summary-card" style="background:#2980b9;">
      <div class="card-body">
        <div class="condt-summary-label"><i class="bi bi-people-fill me-2"></i>Conductores asignados</div>
        <div class="condt-summary-value" id="sumAsignados">0</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card condt-panel-card">
      <div class="condt-panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-diagram-3-fill me-2"></i>Programación por unidad</span>
        <div class="condt-inline-actions">
          <button class="condt-btn condt-btn-light condt-btn-xs" id="btnExpandirTodo">
            <i class="bi bi-arrows-expand me-1"></i>Expandir
          </button>
          <button class="condt-btn condt-btn-light condt-btn-xs" id="btnContraerTodo">
            <i class="bi bi-arrows-collapse me-1"></i>Contraer
          </button>
        </div>
      </div>

      <div class="condt-panel-body">
        <div class="condt-search-bar mb-3">
          <div class="condt-search-group">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" id="txtBuscarUnidadPanel"
                   placeholder="Buscar por bus, placa, conductor, licencia, DNI o celular...">
          </div>
          <div class="condt-search-hint" id="condtCantidadVisible">Mostrando todas las unidades</div>
        </div>

        <div id="condtFlotasWrap">
          <div class="text-muted">Cargando programación...</div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card condt-panel-card">
      <div class="condt-panel-head d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill me-2"></i>Retenes disponibles</span>
        <span class="badge bg-light text-dark" id="badgeRetenes">0</span>
      </div>
      <div class="condt-panel-body">
        <div class="mb-3">
          <div class="condt-search-group">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" id="txtBuscarRetenPanel" placeholder="Buscar retén...">
          </div>
        </div>
        <div id="condtRetenesWrap">
          <div class="text-muted">Cargando retenes...</div>
        </div>
      </div>
    </div>
  </div>
</div>

    </div>
  </div>

  <hr>
</div>

<!-- ===========================
     MODAL DETALLE CONDUCTOR
=========================== -->
<div class="modal fade" id="modalDetalleConductor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header" style="background:#2c3e50;color:white;">
        <h5 class="modal-title"><i class="bi bi-person-vcard-fill me-2"></i>Detalle del Conductor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-4">
          <div class="col-lg-8">
            <h4 id="detNombre" class="mb-1">—</h4>
            <div class="text-muted mb-3">DNI: <span id="detDniTop">—</span></div>

            <div class="card mb-3">
              <div class="card-body">
                <div class="fw-bold mb-2"><i class="bi bi-pin-angle-fill me-2"></i>Contexto actual</div>
                <div id="detContexto" class="text-muted">Sin contexto.</div>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-md-6"><div class="condt-modal-label">ID</div><div class="form-control" id="detId"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Contrato</div><div class="form-control" id="detContrato"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Tipo trabajador</div><div class="form-control" id="detTipoTrab"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Cargo</div><div class="form-control" id="detCargo"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">N° licencia</div><div class="form-control" id="detLicencia"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Tipo licencia</div><div class="form-control" id="detTipoLic"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Categoría licencia</div><div class="form-control" id="detCatLic"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Expedición</div><div class="form-control" id="detExp"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Revaluación</div><div class="form-control" id="detRev"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Correo</div><div class="form-control" id="detCorreo"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Celular</div><div class="form-control" id="detCel"></div></div>
              <div class="col-md-6"><div class="condt-modal-label">Domicilio</div><div class="form-control" id="detDom"></div></div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="condt-modal-label">Fotografía</div>
            <div class="condt-photo-wrap">
              <img id="detImagen" src="" alt="Foto conductor" style="display:none;">
              <div id="detSinImagen" class="text-muted">Sin imagen</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===========================
     MODAL RETENES CATÁLOGO
=========================== -->
<div class="modal fade" id="modalRetenes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header" style="background:#2c3e50;color:white;">
        <h5 class="modal-title" id="retenModalTitulo">Seleccionar Conductor Retén</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3">
          <div class="card-body">
            <div class="fw-bold" id="retenUnidadInfo">Unidad: —</div>
            <div class="text-muted" id="retenCabinaInfo">Cabina: —</div>
            <div class="mt-2 fw-bold text-warning" id="retenActualInfo" style="display:none;"></div>
          </div>
        </div>

        <div class="mb-3">
          <input type="text" class="form-control" id="txtBuscarRetenModal" placeholder="Buscar retén...">
        </div>

        <div id="retenCatalogoWrap"></div>
      </div>
      <div class="modal-footer">
        <button class="condt-btn condt-btn-light" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modalConductoresEstado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header" style="background:#2c3e50;color:white;">
        <h5 class="modal-title">
          <i class="bi bi-toggles2 me-2"></i>Conductores registrados
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3 align-items-center mb-3">
          <div class="col-lg-8">
            <div class="condt-search-group">
              <i class="bi bi-search"></i>
              <input type="text" class="form-control" id="txtBuscarConductoresEstado"
                     placeholder="Buscar por nombre, DNI, licencia, celular o cargo...">
            </div>
          </div>
          <div class="col-lg-4 text-lg-end">
            <span class="badge bg-dark" id="badgeConductoresEstado">0</span>
          </div>
        </div>

        <div id="conductoresEstadoWrap"></div>
      </div>
    </div>
  </div>
</div>
<!-- ===========================
     MODAL PENDIENTES RETEN
=========================== -->
<div class="modal fade" id="modalPendientesReten" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header" style="background:#2c3e50;color:white;">
        <h5 class="modal-title"><i class="bi bi-person-lock me-2"></i>Conductores pendientes para convertirse en Retén</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <input type="text" class="form-control" id="txtBuscarPendienteReten" placeholder="Buscar conductor pendiente...">
        </div>
        <div id="pendientesRetenWrap"></div>
      </div>
    </div>
  </div>
</div>

<!-- ===========================
     MODAL HISTORIAL
=========================== -->
<div class="modal fade" id="modalHistorial" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header" style="background:#2c3e50;color:white;">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Historial de cambios</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <input type="text" class="form-control" id="txtBuscarHistorial" placeholder="Buscar en historial...">
        </div>
        <div id="historialWrap"></div>
      </div>
    </div>
  </div>
</div>

<!-- ===========================
     MODAL MOTIVO
=========================== -->
<div class="modal fade" id="modalMotivo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header" style="background:#2c3e50;color:white;">
        <h5 class="modal-title" id="motivoTitulo">Motivo del cambio</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="condt-modal-label" for="txtMotivoAccion">Escriba el motivo:</label>
        <textarea class="form-control" id="txtMotivoAccion" rows="6"></textarea>
      </div>
      <div class="modal-footer">
        <button class="condt-btn condt-btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button class="condt-btn condt-btn-success" id="btnGuardarMotivo">Guardar</button>
      </div>
    </div>
  </div>
</div>


<!-- <a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20el%20servicio.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank">💬 Soporte</a> -->
<a href="https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20una%20etiqueta.%20Agradezco%20su%20atención." class="btn-flotante" target="_blank" title="Soporte por WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="Soporte" style="width:30px; height:30px;">
</a>


</div>
<img id="pdfLogoDer" src="../img/norte360_blanco.jpg" alt="Logo izquierdo" style="display:none;">
<img id="pdfLogoIzq" src="../img/icon.png" alt="Logo derecho" style="display:none;">
<!-- MODAL DE CARGA -->
<div id="modal-cargando" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
  <div style="background:white; padding:30px 50px; border-radius:12px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3); min-width:280px;">
    <i class="bi bi-arrow-repeat" style="font-size:30px; color:#2980b9; display:inline-block; animation: condtSpin 1s linear infinite;"></i>
    <p style="margin-top:15px; font-size:18px; font-weight:bold; color:#2c3e50;">
      Procesando...<br>Por favor espere
    </p>
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
const modalDetalleConductor = new bootstrap.Modal(document.getElementById('modalDetalleConductor'));
const modalRetenes          = new bootstrap.Modal(document.getElementById('modalRetenes'));
const modalPendientesReten  = new bootstrap.Modal(document.getElementById('modalPendientesReten'));
const modalHistorial        = new bootstrap.Modal(document.getElementById('modalHistorial'));
const modalMotivo           = new bootstrap.Modal(document.getElementById('modalMotivo'));
const modalConductoresEstado = new bootstrap.Modal(document.getElementById('modalConductoresEstado'));

let CONDT_STATE = {
    panel: null,
    retenCatalogMode: null,
    unidadSeleccionada: null,
    retenSeleccionado: null,
    accionPendiente: null,
    expandedUnits: {}
};
function condtMostrarLoader() {
    const modal = document.getElementById('modal-cargando');
    if (modal) modal.style.display = 'flex';
}

function condtOcultarLoader() {
    const modal = document.getElementById('modal-cargando');
    if (modal) modal.style.display = 'none';
}
function condtEscapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function condtEncodeData(obj) {
    return encodeURIComponent(JSON.stringify(obj));
}

function condtDecodeData(payload) {
    try {
        return JSON.parse(decodeURIComponent(payload));
    } catch (e) {
        return null;
    }
}

async function condtGet(url, usarLoader = false) {
    if (usarLoader) condtMostrarLoader();

    try {
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.msg || 'Error en la solicitud.');
        return data;
    } finally {
        if (usarLoader) condtOcultarLoader();
    }
}

async function condtPost(formDataObj, usarLoader = true) {
    const fd = new FormData();
    Object.keys(formDataObj).forEach(k => fd.append(k, formDataObj[k]));

    if (usarLoader) condtMostrarLoader();

    try {
        const res = await fetch('programacion_condt.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.msg || 'Error al guardar.');
        return data;
    } finally {
        if (usarLoader) condtOcultarLoader();
    }
}

function condtDebounce(fn, wait = 350) {
    let t = null;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
    };
}

function condtGroupFlotas(flotas) {
    const map = new Map();

    flotas.forEach(r => {
        const key = String(r.clm_placas_id);

        if (!map.has(key)) {
            map.set(key, {
                clm_placas_id: r.clm_placas_id,
                bus: r.bus,
                placa: r.placa,
                slots: []
            });
        }

        map.get(key).slots.push(r);
    });

    return Array.from(map.values());
}

function condtRenderResumen(resumen) {
    document.getElementById('sumUnidades').textContent   = resumen.unidades_activas ?? 0;
    document.getElementById('sumCabinas').textContent    = resumen.cabinas_visualizadas ?? 0;
    document.getElementById('sumRetenes').textContent    = resumen.retenes_disponibles ?? 0;
    document.getElementById('sumAsignados').textContent  = resumen.asignados ?? 0;
    document.getElementById('badgeRetenes').textContent  = resumen.retenes_disponibles ?? 0;
}

function condtMatchesUnitSearch(grupo, q) {
    if (!q) return true;

    const head = `${grupo.bus || ''} ${grupo.placa || ''}`.toLowerCase();
    if (head.includes(q)) return true;

    return grupo.slots.some(slot => {
        const raw = [
            slot.conductor || '',
            slot.licencia || '',
            slot.dni || '',
            slot.celular || '',
            `conductor ${slot.slot}`
        ].join(' ').toLowerCase();

        return raw.includes(q);
    });
}

function condtHandleAccordionToggle(placaId) {
    CONDT_STATE.expandedUnits[placaId] = !CONDT_STATE.expandedUnits[placaId];
}

function condtSetAllUnitsExpanded(expanded) {
    const grupos = condtGroupFlotas(CONDT_STATE.panel?.flotas || []);
    grupos.forEach(g => {
        CONDT_STATE.expandedUnits[g.clm_placas_id] = expanded;
    });
    condtRenderFlotas(CONDT_STATE.panel?.flotas || []);
}

function condtRenderFlotas(flotas) {
    const wrap = document.getElementById('condtFlotasWrap');
    const info = document.getElementById('condtCantidadVisible');
    const grupos = condtGroupFlotas(flotas);
    const q = (document.getElementById('txtBuscarUnidadPanel')?.value || '').trim().toLowerCase();

    const gruposFiltrados = grupos.filter(g => condtMatchesUnitSearch(g, q));

    if (info) {
        info.textContent = q
            ? `Mostrando ${gruposFiltrados.length} de ${grupos.length} unidad(es)`
            : `Mostrando todas las unidades (${grupos.length})`;
    }

    if (!gruposFiltrados.length) {
        wrap.innerHTML = `
            <div class="condt-no-results">
                <i class="bi bi-search me-2"></i>No se encontraron unidades con ese criterio.
            </div>
        `;
        return;
    }

    wrap.innerHTML = gruposFiltrados.map(g => {
        const collapseId = `condtCollapse_${g.clm_placas_id}`;
        const expanded = q !== '' ? true : !!CONDT_STATE.expandedUnits[g.clm_placas_id];
        const totalAsignados = g.slots.filter(s => !!s.clm_progconductores_idconductor).length;
        const totalLibres = g.slots.length - totalAsignados;

        const slotsHtml = g.slots.map(slot => {
            const tieneConductor = !!slot.clm_progconductores_idconductor;
            const slotPayload = condtEncodeData(slot);
            const contextoPayload = condtEncodeData({
                tipo: 'flota',
                unidad: `${slot.bus} (${slot.placa})`
            });

            return `
                <div class="condt-slot-row">
                    <div>
                        <span class="condt-slot-badge">Conductor ${slot.slot}</span>
                    </div>

                    <div>
                        ${
                            tieneConductor
                            ? `
                                <div class="condt-driver-name">${condtEscapeHtml(slot.conductor)}</div>
                                <div class="condt-driver-meta">
                                    <strong>LIC:</strong> ${condtEscapeHtml(slot.licencia || '—')}
                                    &nbsp;&nbsp;|&nbsp;&nbsp;
                                    <strong>DNI:</strong> ${condtEscapeHtml(slot.dni || '—')}
                                    &nbsp;&nbsp;|&nbsp;&nbsp;
                                    <strong>CEL:</strong> ${condtEscapeHtml(slot.celular || '—')}
                                </div>
                              `
                            : `<div class="condt-empty">Sin conductor asignado</div>`
                        }
                    </div>

                    <div class="condt-actions">
                        ${
                            tieneConductor
                            ? `
                                <button class="condt-mini-btn detalle" onclick="condtAbrirDetalleConductor(${slot.clm_progconductores_idconductor}, '${contextoPayload}')">
                                  <i class="bi bi-person-vcard me-1"></i>Detalle
                                </button>
                                <button class="condt-mini-btn cambiar" onclick="condtAbrirCatalogoRetenes('cambiar', '${slotPayload}')">
                                  <i class="bi bi-arrow-left-right me-1"></i>Cambiar
                                </button>
                                <button class="condt-mini-btn liberar" onclick="condtAbrirMotivoLiberar('${slotPayload}')">
                                  <i class="bi bi-box-arrow-up-right me-1"></i>Liberar
                                </button>
                              `
                            : `
                                <button class="condt-mini-btn asignar" onclick="condtAbrirCatalogoRetenes('asignar', '${slotPayload}')">
                                  <i class="bi bi-person-plus-fill me-1"></i>Asignar
                                </button>
                              `
                        }
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="condt-unit-card">
                <div class="condt-unit-head">
                    <div class="condt-unit-main">
                        <div class="condt-unit-title">
                            <i class="bi bi-bus-front-fill"></i>
                            <span>${condtEscapeHtml(g.bus || '—')}</span>
                        </div>
                        <div class="condt-unit-sub">Placa: ${condtEscapeHtml(g.placa || '—')}</div>
                    </div>

                    <div class="condt-unit-metrics">
                        <span class="condt-chip"><i class="bi bi-grid-3x2-gap-fill me-1"></i>${g.slots.length} cabina(s)</span>
                        <span class="condt-chip"><i class="bi bi-person-check me-1"></i>${totalAsignados} asignado(s)</span>
                        <span class="condt-chip"><i class="bi bi-person-dash me-1"></i>${totalLibres} libre(s)</span>
                    </div>

                    <button class="condt-unit-toggle ${expanded ? '' : 'collapsed'}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#${collapseId}"
                            aria-expanded="${expanded ? 'true' : 'false'}"
                            aria-controls="${collapseId}"
                            onclick="condtHandleAccordionToggle(${g.clm_placas_id})">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>

                <div id="${collapseId}" class="collapse ${expanded ? 'show' : ''}">
                    <div class="condt-unit-body">
                        ${slotsHtml}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function condtRenderRetenes(retenes, targetId = 'condtRetenesWrap', modoModal = false) {
    const wrap = document.getElementById(targetId);

    if (!retenes.length) {
        wrap.innerHTML = `<div class="text-muted">No hay retenes disponibles.</div>`;
        return;
    }

    wrap.innerHTML = retenes.map(row => {
        const unidadOculta = (row.bus || row.placa)
            ? `${row.bus || ''}${row.bus && row.placa ? ' ' : ''}${row.placa ? '(' + row.placa + ')' : ''}`
            : null;

        const contextoPayload = condtEncodeData({
            tipo: 'reten',
            unidad_oculta: unidadOculta
        });

        const rowPayload = condtEncodeData(row);

        return `
            <div class="condt-reten-item">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <div class="condt-clickable"
                             onclick="condtAbrirDetalleConductor(${row.clm_progconductores_idconductor}, '${contextoPayload}')">
                             ${condtEscapeHtml(row.conductor || '—')}
                        </div>
                        <div class="condt-muted mt-1">
                            DNI: ${condtEscapeHtml(row.dni || '—')}
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            LIC: ${condtEscapeHtml(row.licencia || '—')}
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            CAT: ${condtEscapeHtml(row.categoria || '—')}
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            CEL: ${condtEscapeHtml(row.celular || '—')}
                        </div>
                    </div>
                    ${
                        modoModal
                        ? `<button class="condt-btn condt-btn-success" onclick="condtSeleccionarReten('${rowPayload}')"><i class="bi bi-check2-circle me-1"></i>Seleccionar</button>`
                        : `<button class="condt-mini-btn detalle" onclick="condtAbrirDetalleConductor(${row.clm_progconductores_idconductor}, '${contextoPayload}')"><i class="bi bi-person-vcard me-1"></i>Detalle</button>`
                    }
                </div>
            </div>
        `;
    }).join('');
}

async function condtCargarPanel() {
    try {
        const buscarReten = document.getElementById('txtBuscarRetenPanel').value.trim();
        const { data } = await condtGet(`programacion_condt.php?ajax=panel&buscar_reten=${encodeURIComponent(buscarReten)}`, true);
        CONDT_STATE.panel = data;

        condtRenderResumen(data.resumen);
        condtRenderFlotas(data.flotas);
        condtRenderRetenes(data.retenes);
    } catch (err) {
        console.error(err);
        document.getElementById('condtFlotasWrap').innerHTML = `<div class="text-danger">${condtEscapeHtml(err.message)}</div>`;
        document.getElementById('condtRetenesWrap').innerHTML = `<div class="text-danger">${condtEscapeHtml(err.message)}</div>`;
    }
}

async function condtAbrirDetalleConductor(idConductor, contexto = null) {
    try {
        const contextoObj = typeof contexto === 'string' ? condtDecodeData(contexto) : contexto;
        const { data } = await condtGet(`programacion_condt.php?ajax=detalle_conductor&id_conductor=${idConductor}`);

        document.getElementById('detNombre').textContent   = data.clm_tra_nombres || 'Sin nombre';
        document.getElementById('detDniTop').textContent   = data.clm_tra_dni || '—';
        document.getElementById('detId').textContent       = data.clm_tra_id || '—';
        document.getElementById('detContrato').textContent = data.clm_tra_contrato || '—';
        document.getElementById('detTipoTrab').textContent = data.clm_tra_tipo_trabajador || '—';
        document.getElementById('detCargo').textContent    = data.clm_tra_cargo || '—';
        document.getElementById('detLicencia').textContent = data.clm_tra_nlicenciaconducir || '—';
        document.getElementById('detTipoLic').textContent  = data.clm_tra_tipolicencia || '—';
        document.getElementById('detCatLic').textContent   = data.clm_tra_categorialicen || '—';
        document.getElementById('detExp').textContent      = data.clm_tra_licfecha_expedicion || '—';
        document.getElementById('detRev').textContent      = data.clm_tra_licfecha_revaluacion || '—';
        document.getElementById('detCorreo').textContent   = data.clm_tra_correo || '—';
        document.getElementById('detCel').textContent      = data.clm_tra_celular || '—';
        document.getElementById('detDom').textContent      = data.clm_tra_domicilio || '—';

        let contextoHtml = 'Sin contexto.';
        if (contextoObj) {
            if (contextoObj.tipo === 'flota') {
                contextoHtml = `Modo: Flota<br>Unidad: ${condtEscapeHtml(contextoObj.unidad || '—')}`;
            } else if (contextoObj.tipo === 'reten') {
                contextoHtml = `Modo: Retén<br>Unidad referencial: ${condtEscapeHtml(contextoObj.unidad_oculta || 'Libre')}`;
            }
        }
        document.getElementById('detContexto').innerHTML = contextoHtml;

        const img = document.getElementById('detImagen');
        const sin = document.getElementById('detSinImagen');
        if (data.imagen_base64) {
            img.src = data.imagen_base64;
            img.style.display = 'block';
            sin.style.display = 'none';
        } else {
            img.src = '';
            img.style.display = 'none';
            sin.style.display = 'block';
        }

        if (document.activeElement) {
            document.activeElement.blur();
        }

        modalConductoresEstado.hide();

        setTimeout(() => {
            modalDetalleConductor.show();
        }, 180);

    } catch (err) {
        alert(err.message);
    }
}

async function condtAbrirCatalogoRetenes(modo, rowUnidadEncoded) {
    const rowUnidad = typeof rowUnidadEncoded === 'string' ? condtDecodeData(rowUnidadEncoded) : rowUnidadEncoded;
    CONDT_STATE.retenCatalogMode = modo;
    CONDT_STATE.unidadSeleccionada = rowUnidad;
    CONDT_STATE.retenSeleccionado = null;

    document.getElementById('retenModalTitulo').innerHTML =
        modo === 'asignar'
        ? '<i class="bi bi-person-plus-fill me-2"></i>Asignar conductor desde catálogo de retenes'
        : '<i class="bi bi-arrow-left-right me-2"></i>Cambiar conductor desde catálogo de retenes';

    document.getElementById('retenUnidadInfo').textContent =
        `Unidad: ${rowUnidad.bus || ''} (${rowUnidad.placa || ''})`;

    document.getElementById('retenCabinaInfo').textContent =
        `Cabina: Conductor ${rowUnidad.slot}`;

    const actual = document.getElementById('retenActualInfo');
    if (modo === 'cambiar') {
        actual.style.display = 'block';
        actual.textContent = `Actual: ${rowUnidad.conductor || '—'} | LIC: ${rowUnidad.licencia || '—'} | DNI: ${rowUnidad.dni || '—'}`;
    } else {
        actual.style.display = 'none';
        actual.textContent = '';
    }

    document.getElementById('txtBuscarRetenModal').value = '';
    await condtCargarCatalogoRetenes('');
    modalRetenes.show();
}

async function condtCargarCatalogoRetenes(buscar = '') {
    try {
        const { data } = await condtGet(`programacion_condt.php?ajax=retenes&buscar=${encodeURIComponent(buscar)}`);
        condtRenderRetenes(data, 'retenCatalogoWrap', true);
    } catch (err) {
        document.getElementById('retenCatalogoWrap').innerHTML = `<div class="text-danger">${condtEscapeHtml(err.message)}</div>`;
    }
}

function condtSeleccionarReten(rowEncoded) {
    const row = typeof rowEncoded === 'string' ? condtDecodeData(rowEncoded) : rowEncoded;
    CONDT_STATE.retenSeleccionado = row;

    if (CONDT_STATE.retenCatalogMode === 'asignar') {
        if (!confirm(`¿Deseas asignar a ${row.conductor} a la unidad ${CONDT_STATE.unidadSeleccionada.bus} (${CONDT_STATE.unidadSeleccionada.placa})?`)) {
            return;
        }

        condtPost({
            ajax_action: 'asignar_reten',
            progid_reten: row.clm_progconductores_progid,
            idplaca: CONDT_STATE.unidadSeleccionada.clm_placas_id
        })
        .then(r => {
            modalRetenes.hide();
            alert(r.msg);
            condtCargarPanel();
        })
        .catch(err => alert(err.message));

        return;
    }

    if (CONDT_STATE.retenCatalogMode === 'cambiar') {
        modalRetenes.hide();
        CONDT_STATE.accionPendiente = 'cambiar';
        document.getElementById('motivoTitulo').innerHTML = '<i class="bi bi-chat-left-text-fill me-2"></i>Motivo del cambio';
        document.getElementById('txtMotivoAccion').value = '';
        modalMotivo.show();
    }
}

function condtAbrirMotivoLiberar(rowEncoded) {
    const row = typeof rowEncoded === 'string' ? condtDecodeData(rowEncoded) : rowEncoded;
    CONDT_STATE.unidadSeleccionada = row;
    CONDT_STATE.accionPendiente = 'liberar';
    document.getElementById('motivoTitulo').innerHTML = '<i class="bi bi-box-arrow-up-right me-2"></i>Motivo de liberación';
    document.getElementById('txtMotivoAccion').value = '';
    modalMotivo.show();
}
document.getElementById('btnGuardarMotivo').addEventListener('click', async () => {
    const motivo = document.getElementById('txtMotivoAccion').value.trim();
    if (!motivo) {
        alert('Debes escribir un motivo.');
        return;
    }

    try {
        if (CONDT_STATE.accionPendiente === 'liberar') {
            const row = CONDT_STATE.unidadSeleccionada;
            const r = await condtPost({
                ajax_action: 'liberar_conductor',
                progid_flota: row.clm_progconductores_progid,
                motivo
            });
            modalMotivo.hide();
            alert(r.msg);
            condtCargarPanel();
            return;
        }

        if (CONDT_STATE.accionPendiente === 'cambiar') {
            const rowUnidad = CONDT_STATE.unidadSeleccionada;
            const reten = CONDT_STATE.retenSeleccionado;
            const r = await condtPost({
                ajax_action: 'cambiar_conductor',
                progid_flota_actual: rowUnidad.clm_progconductores_progid,
                progid_reten_nuevo: reten.clm_progconductores_progid,
                idplaca: rowUnidad.clm_placas_id,
                motivo
            });
            modalMotivo.hide();
            alert(r.msg);
            condtCargarPanel();
        }
    } catch (err) {
        alert(err.message);
    }
});

async function condtAbrirPendientesReten() {
    document.getElementById('txtBuscarPendienteReten').value = '';
    await condtCargarPendientesReten('');
    modalPendientesReten.show();
}

async function condtCargarPendientesReten(buscar = '') {
    try {
        const { data } = await condtGet(`programacion_condt.php?ajax=pendientes_reten&buscar=${encodeURIComponent(buscar)}`);
        const wrap = document.getElementById('pendientesRetenWrap');

        if (!data.length) {
            wrap.innerHTML = `<div class="text-muted">No hay conductores pendientes para convertirse en retén.</div>`;
            return;
        }

        wrap.innerHTML = data.map(row => {
            const contextoPayload = condtEncodeData({ tipo: 'reten', unidad_oculta: null });
            return `
                <div class="condt-pend-item">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div class="flex-grow-1">
                            <div class="condt-clickable"
                                 onclick="condtAbrirDetalleConductor(${row.clm_tra_id}, '${contextoPayload}')">
                                ${condtEscapeHtml(row.conductor || '—')}
                            </div>
                            <div class="condt-muted mt-1">
                                DNI: ${condtEscapeHtml(row.dni || '—')}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                LIC: ${condtEscapeHtml(row.licencia || '—')}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                CAT: ${condtEscapeHtml(row.categoria || '—')}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                CEL: ${condtEscapeHtml(row.celular || '—')}
                            </div>
                        </div>
                        <button class="condt-btn condt-btn-success" onclick="condtConvertirPendienteAReten(${row.clm_tra_id}, '${condtEscapeHtml(row.conductor || 'Conductor')}')">
                            <i class="bi bi-person-plus-fill me-1"></i>Convertir a Retén
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    } catch (err) {
        document.getElementById('pendientesRetenWrap').innerHTML = `<div class="text-danger">${condtEscapeHtml(err.message)}</div>`;
    }
}

async function condtConvertirPendienteAReten(idTrabajador, nombre) {
    if (!confirm(`¿Deseas convertir a retén a:\n\n${nombre}?`)) return;

    try {
        const r = await condtPost({
            ajax_action: 'convertir_pendiente_a_reten',
            id_trabajador: idTrabajador
        });
        alert(r.msg);
        await condtCargarPendientesReten(document.getElementById('txtBuscarPendienteReten').value.trim());
        await condtCargarPanel();
    } catch (err) {
        alert(err.message);
    }
}

async function condtAbrirHistorial() {
    document.getElementById('txtBuscarHistorial').value = '';
    await condtCargarHistorial('');
    modalHistorial.show();
}

async function condtCargarHistorial(buscar = '') {
    try {
        const { data } = await condtGet(`programacion_condt.php?ajax=historial&buscar=${encodeURIComponent(buscar)}`);
        const wrap = document.getElementById('historialWrap');

        if (!data.length) {
            wrap.innerHTML = `<div class="text-muted">No hay movimientos para mostrar.</div>`;
            return;
        }

        wrap.innerHTML = data.map(row => {
            const contextoPayload = condtEncodeData({
                tipo: row.tipoprog === 1 ? 'flota' : 'reten',
                unidad: row.tipoprog === 1 ? row.unidad : null,
                unidad_oculta: row.tipoprog === 2 ? row.unidad : null
            });

            return `
                <div class="condt-hist-item">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div class="flex-grow-1">
                            <span class="condt-hist-chip" style="background:${row.color};">${condtEscapeHtml(row.etiqueta)}</span>
                            <div class="fw-bold">${condtEscapeHtml(row.titulo)}</div>
                            <div class="mt-2">${condtEscapeHtml(row.detalle)}</div>
                            <div class="condt-muted mt-2">
                                Fecha: ${condtEscapeHtml(row.fechaevento)}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                Conductor: ${condtEscapeHtml(row.conductor)}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                Licencia: ${condtEscapeHtml(row.licencia)}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                Unidad: ${condtEscapeHtml(row.unidad)}
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                Usuario: ${condtEscapeHtml(row.usuario)}
                            </div>
                        </div>
                        <button class="condt-mini-btn detalle"
                            onclick="condtAbrirDetalleConductor(${row.idconductor}, '${contextoPayload}')">
                            <i class="bi bi-person-vcard me-1"></i>Detalle
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    } catch (err) {
        document.getElementById('historialWrap').innerHTML = `<div class="text-danger">${condtEscapeHtml(err.message)}</div>`;
    }
}
function condtPintarEstadoConductor(idTrabajador, valor) {
    const badge = document.getElementById(`condtTextoEstado_${idTrabajador}`);
    if (!badge) return;

    badge.textContent = valor;
    badge.classList.remove('activo', 'inactivo');
    badge.classList.add(valor === 'Activo' ? 'activo' : 'inactivo');
}

function condtRenderConductoresEstado(rows) {
    const wrap = document.getElementById('conductoresEstadoWrap');
    const badge = document.getElementById('badgeConductoresEstado');

    badge.textContent = rows.length;

    if (!rows.length) {
        wrap.innerHTML = `
            <div class="condt-no-results">
                <i class="bi bi-search me-2"></i>No se encontraron conductores.
            </div>
        `;
        return;
    }

    wrap.innerHTML = rows.map(row => {
        const contextoPayload = condtEncodeData({
            tipo: 'reten',
            unidad_oculta: null
        });

        return `
            <div class="condt-driver-toggle-item">
                <div class="condt-driver-toggle-main">
                    <div class="condt-driver-name">${condtEscapeHtml(row.nombres || '—')}</div>
                    <div class="condt-driver-meta">
                        <strong>DNI:</strong> ${condtEscapeHtml(row.dni || '—')}
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>LIC:</strong> ${condtEscapeHtml(row.licencia || '—')}
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>CEL:</strong> ${condtEscapeHtml(row.celular || '—')}
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>CARGO:</strong> ${condtEscapeHtml(row.cargo || '—')}
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button class="condt-mini-btn detalle"
                            onclick="condtAbrirDetalleConductor(${row.clm_tra_id}, '${contextoPayload}')">
                        <i class="bi bi-person-vcard me-1"></i>Detalle
                    </button>

                    <div class="condt-switch-estado-wrap" id="condtEstadoWrap_${row.clm_tra_id}">
                        <label class="condt-switch-estado">
                            <input
                                type="checkbox"
                                ${row.contrato === 'Activo' ? 'checked' : ''}
                                onchange="condtToggleEstadoConductor(this, ${row.clm_tra_id})"
                            >
                            <span class="condt-slider-estado"></span>
                        </label>

                        <span id="condtTextoEstado_${row.clm_tra_id}"
                              class="condt-badge-estado ${row.contrato === 'Activo' ? 'activo' : 'inactivo'}">
                            ${condtEscapeHtml(row.contrato)}
                        </span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

async function condtCargarConductoresEstado(buscar = '') {
    try {
        const { data } = await condtGet(`programacion_condt.php?ajax=conductores_estado&buscar=${encodeURIComponent(buscar)}`);
        condtRenderConductoresEstado(data);
    } catch (err) {
        document.getElementById('conductoresEstadoWrap').innerHTML = `<div class="text-danger">${condtEscapeHtml(err.message)}</div>`;
    }
}

async function condtAbrirConductoresEstado() {
    document.getElementById('txtBuscarConductoresEstado').value = '';
    await condtCargarConductoresEstado('');
    modalConductoresEstado.show();
}

async function condtToggleEstadoConductor(checkbox, idTrabajador) {
    const nuevoEstado = checkbox.checked ? 'Activo' : 'Inactivo';
    const estadoAnterior = checkbox.checked ? 'Inactivo' : 'Activo';
    const wrap = document.getElementById(`condtEstadoWrap_${idTrabajador}`);

    if (wrap) wrap.classList.add('condt-estado-loading');
    condtPintarEstadoConductor(idTrabajador, nuevoEstado);

    try {
        const r = await condtPost({
            ajax_action: 'actualizar_estado_conductor',
            id_trabajador: idTrabajador,
            estado: nuevoEstado
        });

        condtPintarEstadoConductor(idTrabajador, nuevoEstado);

        // Importante: si tus triggers mueven programación/historial,
        // la pantalla principal se refresca inmediatamente.
        await condtCargarPanel();
    } catch (err) {
        checkbox.checked = !checkbox.checked;
        condtPintarEstadoConductor(idTrabajador, estadoAnterior);
        alert(err.message);
    } finally {
        if (wrap) wrap.classList.remove('condt-estado-loading');
    }
}
/* Eventos */
document.getElementById('btnRecargar').addEventListener('click', condtCargarPanel);
document.getElementById('btnHistorial').addEventListener('click', condtAbrirHistorial);
document.getElementById('btnPendientesReten').addEventListener('click', condtAbrirPendientesReten);
document.getElementById('btnExpandirTodo').addEventListener('click', () => condtSetAllUnitsExpanded(true));
document.getElementById('btnContraerTodo').addEventListener('click', () => condtSetAllUnitsExpanded(false));
document.getElementById('btnConductoresEstado').addEventListener('click', condtAbrirConductoresEstado);

document.getElementById('txtBuscarConductoresEstado').addEventListener('input', condtDebounce(() => {
    condtCargarConductoresEstado(document.getElementById('txtBuscarConductoresEstado').value.trim());
}, 300));
document.getElementById('txtBuscarUnidadPanel').addEventListener('input', condtDebounce(() => {
    condtRenderFlotas(CONDT_STATE.panel?.flotas || []);
}, 250));

document.getElementById('txtBuscarRetenPanel').addEventListener('input', condtDebounce(condtCargarPanel, 300));
document.getElementById('txtBuscarRetenModal').addEventListener('input', condtDebounce(() => condtCargarCatalogoRetenes(document.getElementById('txtBuscarRetenModal').value.trim()), 300));
document.getElementById('txtBuscarPendienteReten').addEventListener('input', condtDebounce(() => condtCargarPendientesReten(document.getElementById('txtBuscarPendienteReten').value.trim()), 300));
document.getElementById('txtBuscarHistorial').addEventListener('input', condtDebounce(() => condtCargarHistorial(document.getElementById('txtBuscarHistorial').value.trim()), 300));

document.addEventListener('DOMContentLoaded', async () => {
    condtMostrarLoader();
    try {
        await condtCargarPanel();
    } finally {
        condtOcultarLoader();
    }
});
</script>
<script>
function condtFormatDateTimeForFile() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
}

function condtFormatDateTimeHuman() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    return `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}

function condtGroupFlotasForPdf(flotas) {
    const map = new Map();

    flotas.forEach(r => {
        const key = String(r.clm_placas_id);

        if (!map.has(key)) {
            map.set(key, {
                clm_placas_id: r.clm_placas_id,
                bus: r.bus || '',
                placa: r.placa || '',
                slots: []
            });
        }

        map.get(key).slots.push(r);
    });

    return Array.from(map.values());
}

function condtDrawHeaderFooter(doc, pageNumber, totalPages, title, docCode) {
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const logoIzq = document.getElementById('pdfLogoIzq');
    const logoDer = document.getElementById('pdfLogoDer');

    // 1) PRIMERO fondo del encabezado
    doc.setFillColor(255, 255, 255);
    doc.rect(0, 0, pageWidth, 28, 'F');

    // 2) DESPUÉS las imágenes
    if (logoIzq && logoIzq.complete && logoIzq.naturalWidth > 0) {
        doc.addImage(logoIzq, 'PNG', 15, 8, 10, 10);
    }

    if (logoDer && logoDer.complete && logoDer.naturalWidth > 0) {
        doc.addImage(logoDer, 'JPG', pageWidth - 30, 8, 14, 12);
    }

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(0, 0, 0);
    doc.text('EMPRESA DE TRANSPORTES CRUZ DEL NORTE S.A.C.', pageWidth / 2, 8, { align: 'center' });

    doc.setFontSize(7);
    doc.text('RUC: 20403002101', pageWidth / 2, 12, { align: 'center' });

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(13);
    doc.text(title, pageWidth / 2, 19, { align: 'center' });

    doc.setDrawColor(0, 0, 0);
    doc.setLineWidth(0.2);
    doc.setLineDashPattern([1, 1], 0);
    doc.line(15, 22, pageWidth - 15, 22);
    doc.setLineDashPattern([], 0);

    doc.setDrawColor(203, 213, 225);
    doc.line(15, pageHeight - 16, pageWidth - 15, pageHeight - 16);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7);
    doc.setTextColor(100, 116, 139);
    doc.text('Norte 360° · ERP Operativo de Transporte', 15, pageHeight - 12);
    doc.text(docCode, pageWidth / 2, pageHeight - 12, { align: 'center' });
    doc.text(`Página ${pageNumber} de ${totalPages}`, pageWidth - 15, pageHeight - 12, { align: 'right' });

    doc.setFontSize(6);
    doc.text(`Fecha y hora de impresión: ${condtFormatDateTimeHuman()}`, 15, pageHeight - 9);
    doc.text('Usuario: <?= htmlspecialchars($_SESSION['usuario']) ?>', pageWidth - 15, pageHeight - 9, { align: 'right' });
}

function condtEnsureSpace(doc, y, neededHeight, title, docCode, pageState) {
    const pageHeight = doc.internal.pageSize.getHeight();
    const bottomLimit = pageHeight - 22;

    if (y + neededHeight <= bottomLimit) return y;

    doc.addPage();
    pageState.current += 1;
    condtDrawHeaderFooter(doc, pageState.current, pageState.total, title, docCode);
    return 36;
}

function condtDrawCell(doc, x, y, w, h, text, opts = {}) {
    const {
        fillColor = null,
        textColor = [0, 0, 0],
        align = 'left',
        valign = 'middle',
        fontStyle = 'normal',
        fontSize = 8,
        drawColor = [0, 0, 0],
        lineWidth = 0.2,
        paddingX = 2,
        paddingY = 2
    } = opts;

    if (fillColor) {
        doc.setFillColor(...fillColor);
        doc.rect(x, y, w, h, 'F');
    }

    doc.setDrawColor(...drawColor);
    doc.setLineWidth(lineWidth);
    doc.rect(x, y, w, h);

    doc.setFont('times', fontStyle);
    doc.setFontSize(fontSize);
    doc.setTextColor(...textColor);

    const txt = text == null || text === '' ? '—' : String(text);
    const lines = doc.splitTextToSize(txt, Math.max(5, w - (paddingX * 2)));

    let textY = y + paddingY + (fontSize * 0.35);

    if (valign === 'middle') {
        const lineHeight = (fontSize * 0.35) + 2;
        const textHeight = lines.length * lineHeight;
        textY = y + ((h - textHeight) / 2) + (fontSize * 0.35);
    }

    let textX = x + paddingX;
    if (align === 'center') textX = x + w / 2;
    if (align === 'right') textX = x + w - paddingX;

    doc.text(lines, textX, textY, { align });
}

function condtGenerateProgramacionPdf() {
    const panel = CONDT_STATE.panel;
    if (!panel || !panel.flotas) {
        alert('Primero se debe cargar la programación.');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

    const DOC_CODE = 'PRG_RPT_ASIGNACION_CONDUCTORES';
    const TITLE = 'Programación General de Conductores';

    const flotas = panel.flotas || [];
    const retenes = panel.retenes || [];
    const unidades = panel.unidades || [];

    if (!flotas.length && !retenes.length) {
        alert('No hay información para exportar.');
        return;
    }

    const grupos = condtGroupFlotasForPdf(flotas);

    const pageState = {
        current: 1,
        total: 1
    };

    const estimatePages = () => {
        let y = 36;
        let pages = 1;

        y += 16;
        y += 14;

        const headerH = 9;
        const rowH = 8;
        const rowHEmpty = 8;

        y += headerH;

        grupos.forEach(g => {
            const blockRows = g.slots.length;
            const blockHeight = blockRows * rowH;
            if (y + blockHeight > 275) {
                pages += 1;
                y = 36 + headerH;
            }
            y += blockHeight;
        });

        y += 10;

        if (retenes.length) {
            y += 9;
            retenes.forEach(() => {
                if (y + rowHEmpty > 275) {
                    pages += 1;
                    y = 36 + 9;
                }
                y += rowHEmpty;
            });
        } else {
            y += 8;
        }

        return pages;
    };

    pageState.total = estimatePages();
    condtDrawHeaderFooter(doc, pageState.current, pageState.total, TITLE, DOC_CODE);

    let y = 28;
    const pageWidth = doc.internal.pageSize.getWidth();

    doc.setFont('times', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(75, 85, 99);
    doc.text(`Generado: ${condtFormatDateTimeHuman()}`, pageWidth / 2, y, { align: 'center' });
    y += 3;
    doc.text(
        `Unidades activas: ${unidades.length} | Cabinas sin asignar: ${flotas.filter(r => !r.clm_progconductores_idconductor).length} | Conductores asignados: ${flotas.filter(r => !!r.clm_progconductores_idconductor).length} | Retenes disponibles: ${retenes.length}`,
        pageWidth / 2,
        y,
        { align: 'center' }
    );
    y += 5;

    const x0 = 15;
    const widths = [20, 25, 21, 23, 66, 20];
    const headers = ['BUS', 'PLACA', 'LICENCIA', 'DNI', 'CONDUCTOR', 'CEL'];
    const totalW = widths.reduce((a, b) => a + b, 0);

    const drawMainHeader = (yy) => {
        let x = x0;
        headers.forEach((h, i) => {
            condtDrawCell(doc, x, yy, widths[i], 10, h, {
                fillColor: [255, 242, 0],
                textColor: [0, 0, 0],
                align: 'center',
                fontStyle: 'bold',
                fontSize: 9,
                lineWidth: 0.35
            });
            x += widths[i];
        });
    };

    drawMainHeader(y);
    y += 8;

    grupos.forEach((grupo, groupIdx) => {
        const rowHeight = 7.5;
        const groupHeight = grupo.slots.length * rowHeight;
        y = condtEnsureSpace(doc, y, groupHeight, TITLE, DOC_CODE, pageState);

        if (y === 36) {
            drawMainHeader(y);
            y += 9;
        }

        const groupBg = groupIdx % 2 === 0 ? [255, 255, 255] : [248, 250, 252];
        const busY = y;
        const mergedHeight = groupHeight;

        condtDrawCell(doc, x0, busY, widths[0], mergedHeight, grupo.bus || '—', {
            fillColor: groupBg,
            textColor: [255, 0, 0],
            align: 'center',
            valign: 'middle',
            fontStyle: 'bolditalic',
            fontSize: 18,
            lineWidth: 0.25,
            paddingX: 0,
            paddingY: 0
        });

        condtDrawCell(doc, x0 + widths[0], busY, widths[1], mergedHeight, grupo.placa || '—', {
            fillColor: groupBg,
            textColor: [0, 32, 96],
            align: 'center',
            valign: 'middle',
            fontStyle: 'bolditalic',
            fontSize: 14,
            lineWidth: 0.25
        });

        grupo.slots.forEach((slot, idxLocal) => {
            const ry = y + (idxLocal * rowHeight);
            const hasDriver = !!slot.clm_progconductores_idconductor;

            condtDrawCell(doc, x0 + widths[0] + widths[1], ry, widths[2], rowHeight, hasDriver ? (slot.licencia || '—') : '—', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'center',
                fontStyle: 'normal',
                fontSize: 8,
                lineWidth: 0.25
            });

            condtDrawCell(doc, x0 + widths[0] + widths[1] + widths[2], ry, widths[3], rowHeight, hasDriver ? (slot.dni || '—') : '—', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'center',
                fontStyle: 'normal',
                fontSize: 8,
                lineWidth: 0.25
            });

            condtDrawCell(doc, x0 + widths[0] + widths[1] + widths[2] + widths[3], ry, widths[4], rowHeight, hasDriver ? (slot.conductor || '—') : 'Sin conductor asignado', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'left',
                fontStyle: hasDriver ? 'normal' : 'italic',
                fontSize: 8,
                lineWidth: 0.25
            });

            condtDrawCell(doc, x0 + widths[0] + widths[1] + widths[2] + widths[3] + widths[4], ry, widths[5], rowHeight, hasDriver ? (slot.celular || '—') : '—', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'center',
                fontStyle: 'normal',
                fontSize: 8,
                lineWidth: 0.25
            });
        });

        doc.setDrawColor(0, 0, 0);
        doc.setLineWidth(0.45);
        doc.line(x0, y + groupHeight, x0 + totalW, y + groupHeight);

        y += groupHeight;
    });

    y += 8;

    if (retenes.length) {
        y = condtEnsureSpace(doc, y, 9 + (retenes.length * 8), TITLE, DOC_CODE, pageState);

        const retenWidths = [38, 21, 23, 66, 20];
        const retenHeaders = ['RETENES', 'LICENCIA', 'DNI', 'CONDUCTOR', 'CEL'];

        let x = x0;
        retenHeaders.forEach((h, i) => {
            condtDrawCell(doc, x, y, retenWidths[i], 9, h, {
                fillColor: [44, 62, 80],
                textColor: [255, 255, 255],
                align: 'center',
                fontStyle: 'bold',
                fontSize: 8,
                lineWidth: 0.25
            });
            x += retenWidths[i];
        });

        y += 9;

        retenes.forEach((row, idx) => {
            y = condtEnsureSpace(doc, y, 8, TITLE, DOC_CODE, pageState);

            if (y === 36) {
                let xx = x0;
                retenHeaders.forEach((h, i) => {
                    condtDrawCell(doc, xx, y, retenWidths[i], 9, h, {
                        fillColor: [44, 62, 80],
                        textColor: [255, 255, 255],
                        align: 'center',
                        fontStyle: 'bold',
                        fontSize: 8,
                        lineWidth: 0.25
                    });
                    xx += retenWidths[i];
                });
                y += 9;
            }

            const bg = idx % 2 === 0 ? [255,255,255] : [248,250,252];

            condtDrawCell(doc, x0, y, retenWidths[0], 8, ' ', {
                fillColor: bg,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0], y, retenWidths[1], 8, row.licencia || '—', {
                fillColor: bg,
                align: 'center',
                fontSize: 8,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0] + retenWidths[1], y, retenWidths[2], 8, row.dni || '—', {
                fillColor: bg,
                align: 'center',
                fontSize: 8,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0] + retenWidths[1] + retenWidths[2], y, retenWidths[3], 8, row.conductor || '—', {
                fillColor: bg,
                align: 'left',
                fontSize: 8,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0] + retenWidths[1] + retenWidths[2] + retenWidths[3], y, retenWidths[4], 8, row.celular || '—', {
                fillColor: bg,
                align: 'center',
                fontSize: 8,
                lineWidth: 0.25
            });

            y += 8;
        });
    } else {
        y = condtEnsureSpace(doc, y, 8, TITLE, DOC_CODE, pageState);
        doc.setFont('times', 'normal');
        doc.setFontSize(8);
        doc.setTextColor(0, 0, 0);
        doc.text('No hay conductores retenes disponibles.', 15, y);
    }

    doc.save(`Programacion_General_Conductores_${condtFormatDateTimeForFile()}.pdf`);
}

function condtGenerateProgramacionPdfLicencias() {
    const panel = CONDT_STATE.panel;
    if (!panel || !panel.flotas) {
        alert('Primero se debe cargar la programación.');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

    const DOC_CODE = 'PRG_RPT_LICENCIAS_CONDUCTORES';
    const TITLE = 'Programación General de Conductores - Licencias';

    const flotas = panel.flotas || [];
    const retenes = panel.retenes || [];
    const unidades = panel.unidades || [];

    if (!flotas.length && !retenes.length) {
        alert('No hay información para exportar.');
        return;
    }

    const grupos = condtGroupFlotasForPdf(flotas);

    const pageState = {
        current: 1,
        total: 1
    };

    const estimatePages = () => {
        let y = 36;
        let pages = 1;

        y += 16;
        y += 14;

        const headerH = 9;
        const rowH = 8;
        const rowHEmpty = 8;

        y += headerH;

        grupos.forEach(g => {
            const blockRows = g.slots.length;
            const blockHeight = blockRows * rowH;
            if (y + blockHeight > 275) {
                pages += 1;
                y = 36 + headerH;
            }
            y += blockHeight;
        });

        y += 10;

        if (retenes.length) {
            y += 9;
            retenes.forEach(() => {
                if (y + rowHEmpty > 275) {
                    pages += 1;
                    y = 36 + 9;
                }
                y += rowHEmpty;
            });
        } else {
            y += 8;
        }

        return pages;
    };

    pageState.total = estimatePages();
    condtDrawHeaderFooter(doc, pageState.current, pageState.total, TITLE, DOC_CODE);

    let y = 28;
    const pageWidth = doc.internal.pageSize.getWidth();

    doc.setFont('times', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(75, 85, 99);
    doc.text(`Generado: ${condtFormatDateTimeHuman()}`, pageWidth / 2, y, { align: 'center' });
    y += 3;
    doc.text(
        `Unidades activas: ${unidades.length} | Cabinas sin asignar: ${flotas.filter(r => !r.clm_progconductores_idconductor).length} | Conductores asignados: ${flotas.filter(r => !!r.clm_progconductores_idconductor).length} | Retenes disponibles: ${retenes.length}`,
        pageWidth / 2,
        y,
        { align: 'center' }
    );
    y += 5;

    const x0 = 15;
    const widths = [18, 24, 28, 16, 22, 67];
    const headers = ['BUS', 'PLACA', 'LICENCIA', 'CLASE', 'CATEG.', 'CONDUCTOR'];
    const totalW = widths.reduce((a, b) => a + b, 0);

    const drawMainHeader = (yy) => {
        let x = x0;
        headers.forEach((h, i) => {
            condtDrawCell(doc, x, yy, widths[i], 10, h, {
                fillColor: [255, 242, 0],
                textColor: [0, 0, 0],
                align: 'center',
                fontStyle: 'bold',
                fontSize: 8.5,
                lineWidth: 0.35
            });
            x += widths[i];
        });
    };

    drawMainHeader(y);
    y += 8;

    grupos.forEach((grupo, groupIdx) => {
        const rowHeight = 7.5;
        const groupHeight = grupo.slots.length * rowHeight;
        y = condtEnsureSpace(doc, y, groupHeight, TITLE, DOC_CODE, pageState);

        if (y === 36) {
            drawMainHeader(y);
            y += 9;
        }

        const groupBg = groupIdx % 2 === 0 ? [255, 255, 255] : [248, 250, 252];
        const busY = y;
        const mergedHeight = groupHeight;

        condtDrawCell(doc, x0, busY, widths[0], mergedHeight, grupo.bus || '—', {
            fillColor: groupBg,
            textColor: [255, 0, 0],
            align: 'center',
            valign: 'middle',
            fontStyle: 'bolditalic',
            fontSize: 16,
            lineWidth: 0.25,
            paddingX: 0,
            paddingY: 0
        });

        condtDrawCell(doc, x0 + widths[0], busY, widths[1], mergedHeight, grupo.placa || '—', {
            fillColor: groupBg,
            textColor: [0, 32, 96],
            align: 'center',
            valign: 'middle',
            fontStyle: 'bolditalic',
            fontSize: 12,
            lineWidth: 0.25
        });

        grupo.slots.forEach((slot, idxLocal) => {
            const ry = y + (idxLocal * rowHeight);
            const hasDriver = !!slot.clm_progconductores_idconductor;

            condtDrawCell(doc, x0 + widths[0] + widths[1], ry, widths[2], rowHeight, hasDriver ? (slot.licencia || '—') : '—', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'center',
                fontStyle: 'normal',
                fontSize: 8,
                lineWidth: 0.25
            });

            condtDrawCell(doc, x0 + widths[0] + widths[1] + widths[2], ry, widths[3], rowHeight, hasDriver ? (slot.tipolicencia || '—') : '—', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'center',
                fontStyle: 'normal',
                fontSize: 8,
                lineWidth: 0.25
            });

            condtDrawCell(doc, x0 + widths[0] + widths[1] + widths[2] + widths[3], ry, widths[4], rowHeight, hasDriver ? (slot.categoria || '—') : '—', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'center',
                fontStyle: 'normal',
                fontSize: 8,
                lineWidth: 0.25
            });

            condtDrawCell(doc, x0 + widths[0] + widths[1] + widths[2] + widths[3] + widths[4], ry, widths[5], rowHeight, hasDriver ? (slot.conductor || '—') : 'Sin conductor asignado', {
                fillColor: groupBg,
                textColor: hasDriver ? [0,0,0] : [148,163,184],
                align: 'left',
                fontStyle: hasDriver ? 'normal' : 'italic',
                fontSize: 8,
                lineWidth: 0.25
            });
        });

        doc.setDrawColor(0, 0, 0);
        doc.setLineWidth(0.45);
        doc.line(x0, y + groupHeight, x0 + totalW, y + groupHeight);

        y += groupHeight;
    });

    y += 8;

    if (retenes.length) {
        y = condtEnsureSpace(doc, y, 9 + (retenes.length * 8), TITLE, DOC_CODE, pageState);

        const retenWidths = [42, 28, 16, 22, 67];
        const retenHeaders = ['RETENES', 'LICENCIA', 'CLASE', 'CATEG.', 'CONDUCTOR'];

        let x = x0;
        retenHeaders.forEach((h, i) => {
            condtDrawCell(doc, x, y, retenWidths[i], 9, h, {
                fillColor: [44, 62, 80],
                textColor: [255, 255, 255],
                align: 'center',
                fontStyle: 'bold',
                fontSize: 8,
                lineWidth: 0.25
            });
            x += retenWidths[i];
        });

        y += 9;

        retenes.forEach((row, idx) => {
            y = condtEnsureSpace(doc, y, 8, TITLE, DOC_CODE, pageState);

            if (y === 36) {
                let xx = x0;
                retenHeaders.forEach((h, i) => {
                    condtDrawCell(doc, xx, y, retenWidths[i], 9, h, {
                        fillColor: [44, 62, 80],
                        textColor: [255, 255, 255],
                        align: 'center',
                        fontStyle: 'bold',
                        fontSize: 8,
                        lineWidth: 0.25
                    });
                    xx += retenWidths[i];
                });
                y += 9;
            }

            const bg = idx % 2 === 0 ? [255,255,255] : [248,250,252];

            condtDrawCell(doc, x0, y, retenWidths[0], 8, ' ', {
                fillColor: bg,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0], y, retenWidths[1], 8, row.licencia || '—', {
                fillColor: bg,
                align: 'center',
                fontSize: 8,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0] + retenWidths[1], y, retenWidths[2], 8, row.tipolicencia || '—', {
                fillColor: bg,
                align: 'center',
                fontSize: 8,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0] + retenWidths[1] + retenWidths[2], y, retenWidths[3], 8, row.categoria || '—', {
                fillColor: bg,
                align: 'center',
                fontSize: 8,
                lineWidth: 0.25
            });
            condtDrawCell(doc, x0 + retenWidths[0] + retenWidths[1] + retenWidths[2] + retenWidths[3], y, retenWidths[4], 8, row.conductor || '—', {
                fillColor: bg,
                align: 'left',
                fontSize: 8,
                lineWidth: 0.25
            });

            y += 8;
        });
    } else {
        y = condtEnsureSpace(doc, y, 8, TITLE, DOC_CODE, pageState);
        doc.setFont('times', 'normal');
        doc.setFontSize(8);
        doc.setTextColor(0, 0, 0);
        doc.text('No hay conductores retenes disponibles.', 15, y);
    }

    doc.save(`Programacion_Licencias_Conductores_${condtFormatDateTimeForFile()}.pdf`);
}
document.getElementById('btnExportarPdfLicencias').addEventListener('click', condtGenerateProgramacionPdfLicencias);
document.getElementById('btnExportarPdf').addEventListener('click', condtGenerateProgramacionPdf);


</script>

</body>


</html>