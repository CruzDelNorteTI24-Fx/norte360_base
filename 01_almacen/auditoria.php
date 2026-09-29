<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

if (!n360_is_admin() && !n360_puede_vista('a-auditoria')) {
    header('Location: ../login/none_permisos.php');
    exit();
}

function aud_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function aud_user_id(): int {
    foreach (['id_usuario', 'web_id_usuario', 'usuario_id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }
    return 0;
}

function aud_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function aud_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function aud_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $rows = aud_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function aud_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la operacion: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function aud_fmt_dt($value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return '';
    $time = strtotime($value);
    return $time ? date('d/m/Y H:i', $time) : $value;
}

function aud_fmt_d($value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return '';
    $time = strtotime($value);
    return $time ? date('d/m/Y', $time) : $value;
}

function aud_estado_txt(int $estado): string {
    if ($estado === 2) return 'Realizado';
    if ($estado === 3) return 'Anulado';
    return 'Pendiente';
}

function aud_estado_class(int $estado): string {
    if ($estado === 2) return 'realizado';
    if ($estado === 3) return 'anulado';
    return 'pendiente';
}

function aud_num($value): ?float {
    if ($value === null || $value === '') return null;
    $value = str_replace(',', '.', (string)$value);
    return is_numeric($value) ? (float)$value : null;
}

function aud_num_txt($value): string {
    if ($value === null || $value === '') return '';
    $n = (float)$value;
    $txt = number_format($n, 4, '.', '');
    return rtrim(rtrim($txt, '0'), '.');
}

function aud_status_payload(array $row): array {
    $estado = (int)($row['clm_aud_alm_estado'] ?? 1);
    return [
        'id' => (int)($row['clm_aud_alm_id'] ?? 0),
        'codigo' => (string)($row['clm_aud_alm_codigo'] ?? ''),
        'estado' => $estado,
        'estado_txt' => aud_estado_txt($estado),
        'estado_class' => aud_estado_class($estado),
        'fecha_creada' => (string)($row['clm_aud_alm_fechacreada'] ?? ''),
        'fecha_creada_txt' => aud_fmt_dt($row['clm_aud_alm_fechacreada'] ?? ''),
        'fecha_prog' => (string)($row['clm_aud_alm_fechaprog'] ?? ''),
        'fecha_prog_txt' => aud_fmt_d($row['clm_aud_alm_fechaprog'] ?? ''),
        'fecha_realiz' => (string)($row['clm_aud_alm_fecharealiz'] ?? ''),
        'fecha_realiz_txt' => aud_fmt_dt($row['clm_aud_alm_fecharealiz'] ?? ''),
        'fecha_anul' => (string)($row['clm_aud_alm_fechaanul'] ?? ''),
        'fecha_anul_txt' => aud_fmt_dt($row['clm_aud_alm_fechaanul'] ?? ''),
        'espacio_id' => (int)($row['clm_aud_espid'] ?? 0),
        'espacio_txt' => (string)($row['espacio_txt'] ?? 'Sin espacio'),
        'usuario_prog' => (string)($row['usuario_prog'] ?? ''),
        'usuario_real' => (string)($row['usuario_real'] ?? ''),
        'usuario_anul' => (string)($row['usuario_anul'] ?? ''),
        'responsable' => (string)($row['clm_aud_alm_responsable'] ?? ''),
        'supervisor' => (string)($row['clm_aud_alm_supervisor'] ?? ''),
        'veedor' => (string)($row['clm_aud_alm_veedorcontable'] ?? ''),
        'comentarios' => (string)($row['clm_aud_alm_comentarios'] ?? ''),
        'doc_name' => (string)($row['clm_aud_alm_doc_name'] ?? ''),
        'doc_mime' => (string)($row['clm_aud_alm_doc_mime'] ?? ''),
        'doc_size' => (int)($row['clm_aud_alm_doc_size'] ?? 0),
        'doc_sha256' => (string)($row['clm_aud_alm_doc_sha256'] ?? ''),
        'doc_fecha' => (string)($row['clm_aud_alm_doc_fechacarga'] ?? ''),
        'doc_fecha_txt' => aud_fmt_dt($row['clm_aud_alm_doc_fechacarga'] ?? ''),
        'has_doc' => !empty($row['clm_aud_alm_doc_name']) || ((int)($row['doc_len'] ?? 0) > 0),
    ];
}

function aud_audit_sql(bool $withDoc = false): string {
    $docSelect = $withDoc
        ? ", a.clm_aud_alm_doc"
        : ", LENGTH(a.clm_aud_alm_doc) AS doc_len";

    return "
        SELECT
            a.clm_aud_alm_id,
            a.clm_aud_alm_fechacreada,
            a.clm_aud_alm_codigo,
            a.clm_aud_alm_estado,
            a.clm_aud_alm_fechaprog,
            a.clm_aud_alm_fecharealiz,
            a.clm_aud_alm_fechaanul,
            a.clm_aud_espid,
            CONCAT('(', COALESCE(e.clm_esp_nombre, ''), ') ', COALESCE(e.clm_esp_desc, '')) AS espacio_txt,
            COALESCE(up.nombre, up.usuario, '') AS usuario_prog,
            COALESCE(ur.nombre, ur.usuario, '') AS usuario_real,
            COALESCE(ua.nombre, ua.usuario, '') AS usuario_anul,
            a.clm_aud_alm_responsable,
            a.clm_aud_alm_supervisor,
            a.clm_aud_alm_veedorcontable,
            a.clm_aud_alm_comentarios,
            a.clm_aud_alm_contenido,
            a.clm_aud_alm_doc_name,
            a.clm_aud_alm_doc_mime,
            a.clm_aud_alm_doc_size,
            a.clm_aud_alm_doc_sha256,
            a.clm_aud_alm_doc_fechacarga
            $docSelect
        FROM tb_auditoria_alm a
        LEFT JOIN tb_espacio e ON e.clm_esp_id = a.clm_aud_espid
        LEFT JOIN tb_usuarios up ON up.id_usuario = a.clm_aud_alm_iduserprog
        LEFT JOIN tb_usuarios ur ON ur.id_usuario = a.clm_aud_alm_iduserreal
        LEFT JOIN tb_usuarios ua ON ua.id_usuario = a.clm_aud_alm_iduseranul
    ";
}

function aud_get_audit(mysqli $conn, int $id, bool $withDoc = false): array {
    if ($id <= 0) return [];
    return aud_fetch_one($conn, aud_audit_sql($withDoc) . " WHERE a.clm_aud_alm_id = ? LIMIT 1", 'i', [$id]);
}

function aud_summary_from_items(array $items): array {
    $total = count($items);
    $contados = 0;
    $conformes = 0;
    $pendientes = 0;
    $ok = 0;
    $diferencias = 0;
    $docOk = 0;

    foreach ($items as $item) {
        $stockFisico = $item['stock_fisico'] ?? null;
        $diff = $item['diferencia'] ?? null;
        if ($stockFisico === null || $stockFisico === '') {
            $pendientes++;
        } else {
            $contados++;
            if ((float)$diff == 0.0) $ok++;
            else $diferencias++;
        }
        if (!empty($item['conforme']) || ($stockFisico !== null && $stockFisico !== '' && (float)$diff == 0.0)) {
            $conformes++;
        }
        if (!empty($item['doc_ok'])) $docOk++;
    }

    return [
        'total' => $total,
        'contados' => $contados,
        'conformes' => $conformes,
        'pendientes' => $pendientes,
        'ok' => $ok,
        'diferencias' => $diferencias,
        'doc_ok' => $docOk,
    ];
}

function aud_parse_content($json): array {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
    if (!isset($data['resumen']) || !is_array($data['resumen'])) {
        $data['resumen'] = aud_summary_from_items($data['items']);
    }
    return $data;
}

function aud_history_table_exists(mysqli $conn): bool {
    static $exists = null;
    if ($exists !== null) return $exists;

    $row = aud_fetch_one($conn, "
        SELECT 1 AS found
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tb_hist_auditoria_alm'
        LIMIT 1
    ");
    $exists = !empty($row);
    return $exists;
}

function aud_user_label(): string {
    foreach (['nombre', 'usuario', 'web_usuario'] as $key) {
        $value = trim((string)($_SESSION[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return 'Usuario web';
}

function aud_history_summary(string $contenido): array {
    $data = aud_parse_content($contenido);
    return aud_summary_from_items($data['items'] ?? []);
}

function aud_insert_progress_history(
    mysqli $conn,
    int $auditId,
    int $version,
    string $type,
    string $contenido
): void {
    $summary = aud_history_summary($contenido);
    $userId = aud_user_id();
    $userLabel = aud_user_label();
    $hash = hash('sha256', $contenido);

    $inserted = aud_exec($conn, "
        INSERT INTO tb_hist_auditoria_alm (
            clm_aud_alm_id,
            clm_hist_aud_alm_version,
            clm_hist_aud_alm_tipo,
            clm_hist_aud_alm_fecha,
            clm_hist_aud_alm_idusuario,
            clm_hist_aud_alm_usuario,
            clm_hist_aud_alm_total,
            clm_hist_aud_alm_contados,
            clm_hist_aud_alm_conformes,
            clm_hist_aud_alm_pendientes,
            clm_hist_aud_alm_diferencias,
            clm_hist_aud_alm_contenido,
            clm_hist_aud_alm_sha256
        ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ", 'iisisiiiiiss', [
        $auditId,
        $version,
        strtoupper($type),
        $userId,
        $userLabel,
        (int)($summary['total'] ?? 0),
        (int)($summary['contados'] ?? 0),
        (int)($summary['conformes'] ?? 0),
        (int)($summary['pendientes'] ?? 0),
        (int)($summary['diferencias'] ?? 0),
        $contenido,
        $hash,
    ]);

    if ($inserted !== 1) {
        throw new RuntimeException('No se pudo registrar la version del progreso.');
    }
}

function aud_history_payload(array $row): array {
    return [
        'id' => (int)($row['clm_hist_aud_alm_id'] ?? 0),
        'auditoria_id' => (int)($row['clm_aud_alm_id'] ?? 0),
        'version' => (int)($row['clm_hist_aud_alm_version'] ?? 0),
        'tipo' => (string)($row['clm_hist_aud_alm_tipo'] ?? 'PROGRESO'),
        'fecha' => (string)($row['clm_hist_aud_alm_fecha'] ?? ''),
        'fecha_txt' => aud_fmt_dt($row['clm_hist_aud_alm_fecha'] ?? ''),
        'usuario' => (string)($row['clm_hist_aud_alm_usuario'] ?? ''),
        'total' => (int)($row['clm_hist_aud_alm_total'] ?? 0),
        'contados' => (int)($row['clm_hist_aud_alm_contados'] ?? 0),
        'conformes' => (int)($row['clm_hist_aud_alm_conformes'] ?? 0),
        'pendientes' => (int)($row['clm_hist_aud_alm_pendientes'] ?? 0),
        'diferencias' => (int)($row['clm_hist_aud_alm_diferencias'] ?? 0),
        'bytes' => (int)($row['contenido_bytes'] ?? 0),
        'sha256' => (string)($row['clm_hist_aud_alm_sha256'] ?? ''),
    ];
}

function aud_fetch_inventory(mysqli $conn): array {
    $rows = aud_fetch_all($conn, "
        SELECT ID, CODIPRODUCTO, Producto, Unidad, Categoria, Stock_Actual
        FROM vw_control_inventario
        ORDER BY Categoria, Producto, Stock_Actual
    ");

    foreach ($rows as &$row) {
        $row['ID'] = (int)($row['ID'] ?? 0);
        $row['Stock_Actual'] = aud_num($row['Stock_Actual'] ?? 0) ?? 0;
    }
    unset($row);

    return $rows;
}

function aud_print_css(): string {
    return "
        body{font-family:'Segoe UI',Arial,sans-serif;color:#0f2537;margin:0;background:#f4f8fb}
        .print-page{max-width:1080px;margin:0 auto;padding:28px}
        .print-actions{display:flex;justify-content:flex-end;gap:10px;margin-bottom:16px}
        .print-actions button,.print-actions a{border:1px solid #c8d7e6;border-radius:8px;background:#fff;color:#16324a;padding:10px 14px;text-decoration:none;font-weight:800;cursor:pointer}
        .print-header{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #2389c9;padding-bottom:16px;margin-bottom:20px}
        .print-brand{display:flex;align-items:center;gap:12px}
        .print-logo{width:54px;height:54px;object-fit:contain}
        h1{font-size:28px;margin:0;color:#102a40}.muted{color:#60758a}
        .box{background:#fff;border:1px solid #d9e5f0;border-radius:8px;padding:16px;margin:14px 0}
        .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
        .field{border:1px solid #e1ebf4;border-radius:8px;padding:10px;background:#f8fbfe}
        .label{display:block;font-size:11px;text-transform:uppercase;font-weight:900;color:#526b82;margin-bottom:4px}
        table{width:100%;border-collapse:collapse;background:#fff;font-size:12px}
        th{background:#16324a;color:#fff;text-align:left;padding:8px}
        td{border-bottom:1px solid #e0e8ef;padding:7px;vertical-align:top}
        .signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;margin-top:48px}
        .signature{border-top:1px solid #16324a;text-align:center;padding-top:8px;font-weight:800}
        .pill{display:inline-flex;border-radius:999px;padding:4px 9px;background:#e9f5fd;color:#0874b9;font-weight:900;font-size:11px}
        @media print{body{background:#fff}.print-actions{display:none}.print-page{max-width:none;padding:0}.box{break-inside:avoid}}
    ";
}

function aud_print_layout_start(string $title): void {
    echo "<!doctype html><html lang='es'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>" . aud_h($title) . "</title><style>" . aud_print_css() . "</style></head><body><div class='print-page'>";
    echo "<div class='print-actions'><button onclick='window.print()'>Imprimir / guardar PDF</button><a href='auditoria.php'>Volver</a></div>";
}

function aud_print_header(string $title, array $audit): void {
    echo "<header class='print-header'><div class='print-brand'>";
    echo "<img class='print-logo' src='../img/norte360.png' alt='Norte 360'>";
    echo "<div><h1>" . aud_h($title) . "</h1><div class='muted'>Cruz del Norte - ERP Operativo de Transporte</div></div>";
    echo "</div><div><span class='pill'>" . aud_h($audit['clm_aud_alm_codigo'] ?? 'Auditoria') . "</span></div></header>";
}

function aud_print_meta(array $audit): void {
    $estado = aud_estado_txt((int)($audit['clm_aud_alm_estado'] ?? 1));
    echo "<section class='box'><div class='grid'>";
    $fields = [
        'Estado' => $estado,
        'Fecha programada' => aud_fmt_d($audit['clm_aud_alm_fechaprog'] ?? ''),
        'Espacio' => $audit['espacio_txt'] ?? '',
        'Responsable' => $audit['clm_aud_alm_responsable'] ?? '',
        'Supervisor' => $audit['clm_aud_alm_supervisor'] ?? '',
        'Veedor contable' => $audit['clm_aud_alm_veedorcontable'] ?? '',
    ];
    foreach ($fields as $label => $value) {
        echo "<div class='field'><span class='label'>" . aud_h($label) . "</span>" . aud_h($value) . "</div>";
    }
    echo "</div></section>";
}

function aud_render_print(mysqli $conn, string $type, int $id): void {
    $audit = aud_get_audit($conn, $id, false);
    if (!$audit) {
        http_response_code(404);
        echo 'Auditoria no encontrada.';
        exit();
    }

    $title = $type === 'manual' ? 'Manual de auditoria de almacen' : ($type === 'audit' ? 'Resultado de auditoria de almacen' : 'Orden de auditoria de almacen');
    aud_print_layout_start($title);
    aud_print_header($title, $audit);
    aud_print_meta($audit);

    if ($type === 'manual') {
        $items = aud_fetch_all($conn, "
            SELECT CODIPRODUCTO, Producto, Unidad, Categoria, Stock_Actual, Diferencia, Estado
            FROM vw_control_inventario
            ORDER BY Categoria, Producto, Stock_Actual
        ");
        echo "<section class='box'><h2>Checklist fisico</h2><table><thead><tr><th>Categoria</th><th>Producto</th><th>Stock sistema</th><th>Stock fisico</th><th>Obs.</th></tr></thead><tbody>";
        foreach ($items as $item) {
            $productoTxt = '(' . (string)($item['CODIPRODUCTO'] ?? '') . ') ' . (string)($item['Producto'] ?? '') . ' - ' . (string)($item['Unidad'] ?? '');
            echo "<tr><td>" . aud_h($item['Categoria'] ?? '') . "</td><td>" . aud_h($productoTxt) . "</td><td>" . aud_h(aud_num_txt($item['Stock_Actual'] ?? 0)) . "</td><td></td><td></td></tr>";
        }
        echo "</tbody></table></section>";
    } elseif ($type === 'audit') {
        $data = aud_parse_content($audit['clm_aud_alm_contenido'] ?? '');
        $items = $data['items'] ?? [];
        $summary = $data['resumen'] ?? aud_summary_from_items($items);
        echo "<section class='box'><h2>Resumen</h2><div class='grid'>";
        foreach (['total' => 'Total', 'contados' => 'Contados', 'conformes' => 'Conformes', 'pendientes' => 'Pendientes', 'diferencias' => 'Diferencias'] as $key => $label) {
            echo "<div class='field'><span class='label'>" . aud_h($label) . "</span>" . aud_h($summary[$key] ?? 0) . "</div>";
        }
        echo "</div></section><section class='box'><h2>Detalle auditado</h2><table><thead><tr><th>Categoria</th><th>Producto</th><th>Sistema</th><th>Fisico</th><th>Diferencia</th><th>Conforme</th><th>Obs.</th></tr></thead><tbody>";
        foreach ($items as $item) {
            $counted = isset($item['stock_fisico']) && $item['stock_fisico'] !== '';
            $conforme = $counted && (!empty($item['conforme']) || (isset($item['diferencia']) && (float)$item['diferencia'] == 0.0));
            $productoTxt = '(' . (string)($item['cod'] ?? '') . ') ' . (string)($item['producto'] ?? '') . ' - ' . (string)($item['unidad'] ?? '');
            echo "<tr><td>" . aud_h($item['categoria'] ?? '') . "</td><td>" . aud_h($productoTxt) . "</td><td>" . aud_h(aud_num_txt($item['stock_sistema'] ?? 0)) . "</td><td>" . aud_h(aud_num_txt($item['stock_fisico'] ?? '')) . "</td><td>" . aud_h(aud_num_txt($item['diferencia'] ?? '')) . "</td><td>" . ($conforme ? 'Conforme' : '') . "</td><td>" . aud_h($item['obs'] ?? '') . "</td></tr>";
        }
        echo "</tbody></table></section>";
    } else {
        echo "<section class='box'><h2>Indicaciones</h2><p>Realizar conteo fisico del espacio indicado, registrar diferencias, observaciones y adjuntar evidencia documental al finalizar.</p>";
        echo "<p><strong>Comentarios:</strong><br>" . nl2br(aud_h($audit['clm_aud_alm_comentarios'] ?? '')) . "</p></section>";
    }

    echo "<section class='signatures'><div class='signature'>Responsable</div><div class='signature'>Supervisor</div><div class='signature'>Veedor contable</div></section>";
    echo "</div></body></html>";
    exit();
}

try {
    $printType = (string)($_GET['print'] ?? '');
    if ($printType !== '') {
        aud_render_print($conn, $printType, (int)($_GET['id'] ?? 0));
    }

    if (($_GET['action'] ?? '') === 'download_doc') {
        $audit = aud_get_audit($conn, (int)($_GET['id'] ?? 0), true);
        if (!$audit || empty($audit['clm_aud_alm_doc'])) {
            http_response_code(404);
            echo 'Documento no encontrado.';
            exit();
        }

        $name = trim((string)($audit['clm_aud_alm_doc_name'] ?? 'auditoria_documento'));
        $mime = trim((string)($audit['clm_aud_alm_doc_mime'] ?? 'application/octet-stream'));
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($audit['clm_aud_alm_doc']));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        echo $audit['clm_aud_alm_doc'];
        exit();
    }

    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    if ($action !== '') {
        if ($action === 'list') {
            $estado = (int)($_GET['estado'] ?? 0);
            $desde = trim((string)($_GET['desde'] ?? ''));
            $hasta = trim((string)($_GET['hasta'] ?? ''));
            $buscar = trim((string)($_GET['buscar'] ?? ''));

            $where = ['1=1'];
            $types = '';
            $params = [];

            if (in_array($estado, [1, 2, 3], true)) {
                $where[] = 'a.clm_aud_alm_estado = ?';
                $types .= 'i';
                $params[] = $estado;
            }
            if ($desde !== '') {
                $where[] = 'DATE(a.clm_aud_alm_fechaprog) >= ?';
                $types .= 's';
                $params[] = $desde;
            }
            if ($hasta !== '') {
                $where[] = 'DATE(a.clm_aud_alm_fechaprog) <= ?';
                $types .= 's';
                $params[] = $hasta;
            }
            if ($buscar !== '') {
                $where[] = "(
                    a.clm_aud_alm_codigo LIKE CONCAT('%', ?, '%')
                    OR a.clm_aud_alm_responsable LIKE CONCAT('%', ?, '%')
                    OR a.clm_aud_alm_supervisor LIKE CONCAT('%', ?, '%')
                    OR a.clm_aud_alm_veedorcontable LIKE CONCAT('%', ?, '%')
                    OR a.clm_aud_alm_comentarios LIKE CONCAT('%', ?, '%')
                    OR e.clm_esp_nombre LIKE CONCAT('%', ?, '%')
                    OR e.clm_esp_desc LIKE CONCAT('%', ?, '%')
                )";
                $types .= 'sssssss';
                array_push($params, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar, $buscar);
            }

            $rows = aud_fetch_all(
                $conn,
                aud_audit_sql(false) . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.clm_aud_alm_fechaprog DESC, a.clm_aud_alm_id DESC LIMIT 300',
                $types,
                $params
            );

            $payloadRows = [];
            $kpi = ['total' => 0, 'pendientes' => 0, 'realizados' => 0, 'anulados' => 0];
            foreach ($rows as $row) {
                $payload = aud_status_payload($row);
                $payloadRows[] = $payload;
                $kpi['total']++;
                if ($payload['estado'] === 1) $kpi['pendientes']++;
                if ($payload['estado'] === 2) $kpi['realizados']++;
                if ($payload['estado'] === 3) $kpi['anulados']++;
            }
            aud_json(['ok' => true, 'rows' => $payloadRows, 'kpi' => $kpi]);
        }

        if ($action === 'spaces') {
            $rows = aud_fetch_all($conn, "
                SELECT clm_esp_id, clm_esp_nombre, clm_esp_desc
                FROM tb_espacio
                ORDER BY clm_esp_nombre ASC
            ");
            aud_json(['ok' => true, 'rows' => $rows]);
        }

        if ($action === 'inventory') {
            aud_json(['ok' => true, 'rows' => aud_fetch_inventory($conn)]);
        }

        if ($action === 'detail') {
            $audit = aud_get_audit($conn, (int)($_GET['id'] ?? 0), false);
            if (!$audit) aud_json(['ok' => false, 'message' => 'Auditoria no encontrada.'], 404);
            $data = aud_parse_content($audit['clm_aud_alm_contenido'] ?? '');
            aud_json(['ok' => true, 'audit' => aud_status_payload($audit), 'contenido' => $data]);
        }

        if ($action === 'progress_history') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) aud_json(['ok' => false, 'message' => 'Auditoria invalida.'], 422);
            if (!aud_history_table_exists($conn)) {
                aud_json(['ok' => true, 'installed' => false, 'rows' => []]);
            }

            $rows = aud_fetch_all($conn, "
                SELECT
                    clm_hist_aud_alm_id,
                    clm_aud_alm_id,
                    clm_hist_aud_alm_version,
                    clm_hist_aud_alm_tipo,
                    clm_hist_aud_alm_fecha,
                    clm_hist_aud_alm_usuario,
                    clm_hist_aud_alm_total,
                    clm_hist_aud_alm_contados,
                    clm_hist_aud_alm_conformes,
                    clm_hist_aud_alm_pendientes,
                    clm_hist_aud_alm_diferencias,
                    OCTET_LENGTH(clm_hist_aud_alm_contenido) AS contenido_bytes,
                    clm_hist_aud_alm_sha256
                FROM tb_hist_auditoria_alm
                WHERE clm_aud_alm_id = ?
                ORDER BY clm_hist_aud_alm_version DESC, clm_hist_aud_alm_id DESC
            ", 'i', [$id]);

            aud_json([
                'ok' => true,
                'installed' => true,
                'rows' => array_map('aud_history_payload', $rows),
            ]);
        }

        if ($action === 'progress_snapshot') {
            $auditId = (int)($_GET['id'] ?? 0);
            $historyId = (int)($_GET['history_id'] ?? 0);
            if ($auditId <= 0 || $historyId <= 0) {
                aud_json(['ok' => false, 'message' => 'Version de auditoria invalida.'], 422);
            }
            if (!aud_history_table_exists($conn)) {
                aud_json(['ok' => false, 'message' => 'La tabla de historial de auditorias aun no esta instalada.'], 409);
            }

            $row = aud_fetch_one($conn, "
                SELECT
                    clm_hist_aud_alm_id,
                    clm_aud_alm_id,
                    clm_hist_aud_alm_version,
                    clm_hist_aud_alm_tipo,
                    clm_hist_aud_alm_fecha,
                    clm_hist_aud_alm_usuario,
                    clm_hist_aud_alm_total,
                    clm_hist_aud_alm_contados,
                    clm_hist_aud_alm_conformes,
                    clm_hist_aud_alm_pendientes,
                    clm_hist_aud_alm_diferencias,
                    OCTET_LENGTH(clm_hist_aud_alm_contenido) AS contenido_bytes,
                    clm_hist_aud_alm_sha256,
                    clm_hist_aud_alm_contenido
                FROM tb_hist_auditoria_alm
                WHERE clm_hist_aud_alm_id = ? AND clm_aud_alm_id = ?
                LIMIT 1
            ", 'ii', [$historyId, $auditId]);

            if (!$row) aud_json(['ok' => false, 'message' => 'Version de progreso no encontrada.'], 404);
            aud_json([
                'ok' => true,
                'entry' => aud_history_payload($row),
                'contenido' => aud_parse_content($row['clm_hist_aud_alm_contenido'] ?? ''),
            ]);
        }

        if ($action === 'save_progress') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') aud_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);

            $id = (int)($_POST['auditoria_id'] ?? 0);
            $contenido = trim((string)($_POST['contenido'] ?? ''));
            if ($contenido === '' || !is_array(json_decode($contenido, true))) aud_json(['ok' => false, 'message' => 'Contenido de auditoria invalido.'], 422);
            if (!aud_history_table_exists($conn)) {
                aud_json(['ok' => false, 'message' => 'Instala la tabla tb_hist_auditoria_alm antes de guardar progreso.'], 409);
            }

            $conn->begin_transaction();
            try {
                $audit = aud_fetch_one($conn, "
                    SELECT clm_aud_alm_id, clm_aud_alm_estado, clm_aud_alm_contenido
                    FROM tb_auditoria_alm
                    WHERE clm_aud_alm_id = ?
                    LIMIT 1
                    FOR UPDATE
                ", 'i', [$id]);

                if (!$audit) {
                    $conn->rollback();
                    aud_json(['ok' => false, 'message' => 'Auditoria no encontrada.'], 404);
                }
                if ((int)$audit['clm_aud_alm_estado'] !== 1) {
                    $conn->rollback();
                    aud_json(['ok' => false, 'message' => 'Solo se puede guardar progreso en una auditoria pendiente.'], 409);
                }

                $last = aud_fetch_one($conn, "
                    SELECT COALESCE(MAX(clm_hist_aud_alm_version), 0) AS ultima_version
                    FROM tb_hist_auditoria_alm
                    WHERE clm_aud_alm_id = ?
                ", 'i', [$id]);
                $version = (int)($last['ultima_version'] ?? 0);
                $previousContent = trim((string)($audit['clm_aud_alm_contenido'] ?? ''));

                if ($version === 0 && $previousContent !== '' && is_array(json_decode($previousContent, true))) {
                    $version++;
                    aud_insert_progress_history($conn, $id, $version, 'BASE', $previousContent);
                }

                $version++;
                aud_insert_progress_history($conn, $id, $version, 'PROGRESO', $contenido);
                aud_exec($conn, "
                    UPDATE tb_auditoria_alm
                    SET clm_aud_alm_contenido = ?
                    WHERE clm_aud_alm_id = ? AND clm_aud_alm_estado = 1
                ", 'si', [$contenido, $id]);

                $conn->commit();
            } catch (Throwable $historyError) {
                $conn->rollback();
                throw $historyError;
            }

            aud_json([
                'ok' => true,
                'version' => $version,
                'message' => 'Progreso guardado como version ' . $version . '. Puedes continuar esta auditoria luego.',
            ]);
        }

        if ($action === 'schedule') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') aud_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);

            $fecha = trim((string)($_POST['fecha_prog'] ?? ''));
            $espacio = (int)($_POST['espacio_id'] ?? 0);
            $responsable = trim((string)($_POST['responsable'] ?? ''));
            $supervisor = trim((string)($_POST['supervisor'] ?? ''));
            $veedor = trim((string)($_POST['veedor'] ?? ''));
            $comentarios = trim((string)($_POST['comentarios'] ?? ''));
            $uid = aud_user_id();

            if ($fecha === '' || $espacio <= 0 || $responsable === '' || $supervisor === '' || $veedor === '') {
                aud_json(['ok' => false, 'message' => 'Completa fecha, espacio, responsable, supervisor y veedor.'], 422);
            }

            $stmt = $conn->prepare("
                INSERT INTO tb_auditoria_alm (
                    clm_aud_alm_fechacreada,
                    clm_aud_alm_codigo,
                    clm_aud_alm_estado,
                    clm_aud_alm_fechaprog,
                    clm_aud_alm_iduserprog,
                    clm_aud_alm_responsable,
                    clm_aud_alm_supervisor,
                    clm_aud_alm_veedorcontable,
                    clm_aud_alm_comentarios,
                    clm_aud_espid
                ) VALUES (NOW(), 'TMP', 1, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('sissssi', $fecha, $uid, $responsable, $supervisor, $veedor, $comentarios, $espacio);
            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();

            $codigo = 'AUD-ALM-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
            aud_exec($conn, "UPDATE tb_auditoria_alm SET clm_aud_alm_codigo = ? WHERE clm_aud_alm_id = ?", 'si', [$codigo, $id]);
            aud_json(['ok' => true, 'id' => $id, 'codigo' => $codigo, 'message' => 'Auditoria programada.']);
        }

        if ($action === 'realize') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') aud_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);

            $id = (int)($_POST['auditoria_id'] ?? 0);
            $contenido = trim((string)($_POST['contenido'] ?? ''));
            $comentarioExtra = trim((string)($_POST['comentarios_extra'] ?? ''));
            $audit = aud_get_audit($conn, $id, false);

            if (!$audit) aud_json(['ok' => false, 'message' => 'Auditoria no encontrada.'], 404);
            if ((int)$audit['clm_aud_alm_estado'] !== 1) aud_json(['ok' => false, 'message' => 'Solo se puede realizar una auditoria pendiente.'], 409);
            if ($contenido === '' || !is_array(json_decode($contenido, true))) aud_json(['ok' => false, 'message' => 'Contenido de auditoria invalido.'], 422);
            if (empty($_FILES['documento']) || (int)$_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
                aud_json(['ok' => false, 'message' => 'Adjunta el documento de evidencia.'], 422);
            }

            $tmp = $_FILES['documento']['tmp_name'];
            $docBytes = file_get_contents($tmp);
            if ($docBytes === false || $docBytes === '') aud_json(['ok' => false, 'message' => 'No se pudo leer el documento adjunto.'], 422);

            $docName = basename((string)($_FILES['documento']['name'] ?? 'documento_auditoria'));
            $docSize = (int)($_FILES['documento']['size'] ?? strlen($docBytes));
            $docMime = 'application/octet-stream';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detected = @$finfo->file($tmp);
                if ($detected) $docMime = $detected;
            }
            $docSha = hash('sha256', $docBytes);
            $append = $comentarioExtra !== '' ? "\n[REALIZADO] " . $comentarioExtra : '';
            $uid = aud_user_id();

            $sql = "
                UPDATE tb_auditoria_alm
                SET
                    clm_aud_alm_estado = 2,
                    clm_aud_alm_fecharealiz = NOW(),
                    clm_aud_alm_iduserreal = ?,
                    clm_aud_alm_contenido = ?,
                    clm_aud_alm_comentarios = CONCAT(COALESCE(clm_aud_alm_comentarios, ''), ?),
                    clm_aud_alm_doc = ?,
                    clm_aud_alm_doc_name = ?,
                    clm_aud_alm_doc_mime = ?,
                    clm_aud_alm_doc_size = ?,
                    clm_aud_alm_doc_sha256 = ?,
                    clm_aud_alm_doc_fechacarga = NOW()
                WHERE clm_aud_alm_id = ? AND clm_aud_alm_estado = 1
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('isssssisi', $uid, $contenido, $append, $docBytes, $docName, $docMime, $docSize, $docSha, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected <= 0) aud_json(['ok' => false, 'message' => 'No se pudo marcar como realizada.'], 409);
            aud_json(['ok' => true, 'message' => 'Auditoria realizada y documento guardado.']);
        }

        if ($action === 'annul') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') aud_json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);

            $id = (int)($_POST['auditoria_id'] ?? 0);
            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if ($id <= 0 || $motivo === '') aud_json(['ok' => false, 'message' => 'Indica el motivo de anulacion.'], 422);

            $audit = aud_get_audit($conn, $id, false);
            if (!$audit) aud_json(['ok' => false, 'message' => 'Auditoria no encontrada.'], 404);
            if ((int)$audit['clm_aud_alm_estado'] === 3) aud_json(['ok' => false, 'message' => 'La auditoria ya esta anulada.'], 409);

            $append = "\n[ANULADO] " . $motivo;
            $uid = aud_user_id();
            $affected = aud_exec($conn, "
                UPDATE tb_auditoria_alm
                SET clm_aud_alm_estado = 3,
                    clm_aud_alm_fechaanul = NOW(),
                    clm_aud_alm_iduseranul = ?,
                    clm_aud_alm_comentarios = CONCAT(COALESCE(clm_aud_alm_comentarios, ''), ?)
                WHERE clm_aud_alm_id = ?
            ", 'isi', [$uid, $append, $id]);

            if ($affected <= 0) aud_json(['ok' => false, 'message' => 'No se pudo anular la auditoria.'], 409);
            aud_json(['ok' => true, 'message' => 'Auditoria anulada.']);
        }

        aud_json(['ok' => false, 'message' => 'Accion no reconocida.'], 400);
    }
} catch (Throwable $e) {
    if ((string)($_GET['action'] ?? $_POST['action'] ?? '') !== '') {
        aud_json(['ok' => false, 'message' => $e->getMessage()], 500);
    }
    throw $e;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditorias de almacen | Norte 360</title>
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link rel="shortcut icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/loader_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/auditoria_alm_n360.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Auditorias de almacen', 'subtitle' => 'Inventario operativo']); ?>
<?php n360_render_sidebar(); ?>

<div class="main-content n360-main n360-main--module">
<?php n360_render_content_separator('top'); ?>
<main class="audalm-shell">
    <section class="audalm-hero">
        <div>
            <div class="audalm-kicker"><i class="bi bi-clipboard2-check"></i> Inventario - Control en tiempo real</div>
            <h1>Auditorias de almacen</h1>
            <p>Programa conteos, realiza el registro fisico contra el stock del sistema, guarda evidencia documental y consulta resultados desde una sola vista operativa.</p>
        </div>
        <div class="audalm-actions">
            <button type="button" class="audalm-btn" id="btnOpenSchedule"><i class="bi bi-calendar2-plus"></i> Programar</button>
            <button type="button" class="audalm-btn audalm-btn--ghost" id="btnRefresh"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
        </div>
    </section>

    <section class="audalm-kpis" aria-label="Resumen de auditorias">
        <article class="audalm-kpi"><span>Total filtrado</span><strong id="kpiTotal">0</strong></article>
        <article class="audalm-kpi"><span>Pendientes</span><strong id="kpiPendientes">0</strong></article>
        <article class="audalm-kpi"><span>Realizadas</span><strong id="kpiRealizadas">0</strong></article>
        <article class="audalm-kpi"><span>Anuladas</span><strong id="kpiAnuladas">0</strong></article>
    </section>

    <section class="audalm-toolbar">
        <div class="audalm-filter">
            <label for="filterEstado">Estado</label>
            <select id="filterEstado">
                <option value="0">Todos</option>
                <option value="1" selected>Pendientes</option>
                <option value="2">Realizadas</option>
                <option value="3">Anuladas</option>
            </select>
        </div>
        <div class="audalm-filter">
            <label for="filterDesde">Desde</label>
            <input type="date" id="filterDesde">
        </div>
        <div class="audalm-filter">
            <label for="filterHasta">Hasta</label>
            <input type="date" id="filterHasta">
        </div>
        <div class="audalm-filter audalm-filter--grow">
            <label for="filterBuscar">Buscar</label>
            <input type="search" id="filterBuscar" placeholder="Codigo, responsable, supervisor, espacio...">
        </div>
        <div class="audalm-toolbar-actions">
            <button type="button" class="audalm-btn" id="btnApplyFilters"><i class="bi bi-funnel"></i> Filtrar</button>
            <button type="button" class="audalm-btn audalm-btn--soft" id="btnClearFilters"><i class="bi bi-x-circle"></i> Limpiar</button>
        </div>
    </section>

    <section class="audalm-panel">
        <div class="audalm-panel-head">
            <div>
                <h2>Auditorias registradas</h2>
                <p>Selecciona una fila para habilitar acciones segun el estado.</p>
            </div>
            <span class="audalm-pill" id="selectedLabel">Sin seleccion</span>
        </div>
        <div class="audalm-table-wrap">
            <table class="audalm-table">
                <thead>
                    <tr>
                        <th>Codigo</th>
                        <th>Estado</th>
                        <th>Programada</th>
                        <th>Espacio</th>
                        <th>Responsable</th>
                        <th>Supervisor</th>
                        <th>Veedor</th>
                        <th>Documento</th>
                    </tr>
                </thead>
                <tbody id="auditRows">
                    <tr><td colspan="8" class="audalm-empty">Cargando auditorias...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="audalm-row-actions">
            <button type="button" class="audalm-btn" id="btnDetail" disabled><i class="bi bi-eye"></i> Detalle</button>
            <button type="button" class="audalm-btn" id="btnRealize" disabled><i class="bi bi-check2-square"></i> Realizar</button>
            <button type="button" class="audalm-btn audalm-btn--soft" id="btnOrder" disabled><i class="bi bi-file-earmark-text"></i> Orden</button>
            <button type="button" class="audalm-btn audalm-btn--soft" id="btnManual" disabled><i class="bi bi-journal-text"></i> Manual</button>
            <button type="button" class="audalm-btn audalm-btn--soft" id="btnPrintAudit" disabled><i class="bi bi-printer"></i> Auditoria</button>
            <button type="button" class="audalm-btn audalm-btn--danger" id="btnAnnul" disabled><i class="bi bi-slash-circle"></i> Anular</button>
        </div>
    </section>
</main>
<?php n360_render_content_separator('bottom'); ?>
</div>

<?php n360_render_footer(); ?>

<div class="audalm-modal" id="scheduleModal" aria-hidden="true">
    <div class="audalm-modal-card">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-calendar2-plus"></i> Nueva auditoria</span>
                <h3>Programar auditoria</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="scheduleModal" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="scheduleForm" class="audalm-form">
            <div class="audalm-form-grid">
                <label>Fecha programada<input type="date" name="fecha_prog" required></label>
                <label>Espacio<select name="espacio_id" id="spaceSelect" required><option value="">Seleccionar espacio</option></select></label>
                <label>Responsable<input type="text" name="responsable" required></label>
                <label>Supervisor<input type="text" name="supervisor" required></label>
                <label>Veedor contable<input type="text" name="veedor" required></label>
            </div>
            <label>Comentarios<textarea name="comentarios" rows="4" placeholder="Notas de programacion"></textarea></label>
            <div class="audalm-modal-actions">
                <button type="submit" class="audalm-btn"><i class="bi bi-save"></i> Guardar</button>
                <button type="button" class="audalm-btn audalm-btn--soft" data-close="scheduleModal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div class="audalm-modal audalm-modal--wide" id="realizeModal" aria-hidden="true">
    <div class="audalm-modal-card">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-check2-square"></i> Registro fisico</span>
                <h3 id="realizeTitle">Realizar auditoria</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="realizeModal" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="audalm-inventory-tools">
            <input type="search" id="invSearch" placeholder="Buscar producto, codigo o categoria">
            <select id="invCategory"><option value="">Todas las categorias</option></select>
            <select id="invView">
                <option value="todos">Todos</option>
                <option value="pendientes">Pendientes</option>
                <option value="contados">Contados</option>
                <option value="conformes">Conformes</option>
                <option value="diferencias">Con diferencias</option>
            </select>
            <button type="button" class="audalm-btn audalm-btn--soft" id="btnRefreshSystemStock"><i class="bi bi-arrow-repeat"></i> Actualizar stock sistema</button>
            <button type="button" class="audalm-btn audalm-btn--soft" id="btnCompletePending"><i class="bi bi-magic"></i> Completar pendientes</button>
        </div>

        <section class="audalm-mini-kpis">
            <div><span>Total</span><strong id="sumTotal">0</strong></div>
            <div><span>Contados</span><strong id="sumContados">0</strong></div>
            <div><span>Conformes</span><strong id="sumConformes">0</strong></div>
            <div><span>Pendientes</span><strong id="sumPendientes">0</strong></div>
            <div><span>Diferencias</span><strong id="sumDiferencias">0</strong></div>
        </section>

        <div class="audalm-inventory-wrap">
            <table class="audalm-table audalm-table--compact">
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th>Producto</th>
                        <th>Sistema</th>
                        <th>Fisico</th>
                        <th>Diferencia</th>
                        <th>Conforme</th>
                        <th>Obs.</th>
                    </tr>
                </thead>
                <tbody id="inventoryRows">
                    <tr><td colspan="7" class="audalm-empty">Abre una auditoria pendiente para cargar productos.</td></tr>
                </tbody>
            </table>
        </div>

        <form id="realizeForm" class="audalm-form audalm-form--footer">
            <input type="hidden" name="auditoria_id" id="realizeAuditId">
            <label>Documento de evidencia<input type="file" name="documento" id="realizeDoc"></label>
            <label>Comentario al finalizar<textarea name="comentarios_extra" id="realizeComment" rows="3" placeholder="Detalle adicional del cierre"></textarea></label>
            <div class="audalm-modal-actions">
                <button type="button" class="audalm-btn audalm-btn--soft" id="btnSaveProgress"><i class="bi bi-save"></i> Guardar progreso</button>
                <button type="submit" class="audalm-btn audalm-btn--green"><i class="bi bi-check2-circle"></i> Finalizar auditoria</button>
                <button type="button" class="audalm-btn audalm-btn--soft" data-close="realizeModal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div class="audalm-modal audalm-modal--wide" id="detailModal" aria-hidden="true">
    <div class="audalm-modal-card">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-eye"></i> Consulta</span>
                <h3 id="detailTitle">Detalle de auditoria</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="detailModal" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="detailBody" class="audalm-detail"></div>
    </div>
</div>

<div class="audalm-modal audalm-modal--wide" id="progressHistoryModal" aria-hidden="true">
    <div class="audalm-modal-card">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-clock-history"></i> Historial de guardados</span>
                <h3 id="progressHistoryTitle">Version de progreso</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="progressHistoryModal" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="progressHistoryBody" class="audalm-detail audalm-history-snapshot"></div>
    </div>
</div>

<div class="audalm-modal audalm-modal--wide" id="modal-movimientos" aria-hidden="true">
    <div class="audalm-modal-card audalm-modal-card--remote">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-clock-history"></i> Movimientos</span>
                <h3>Historial del producto</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="modal-movimientos" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="contenido-movimientos" class="audalm-remote-body">Cargando movimientos...</div>
    </div>
</div>

<div class="audalm-modal audalm-modal--wide" id="modal-nota" aria-hidden="true">
    <div class="audalm-modal-card audalm-modal-card--remote">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-file-earmark-text"></i> Documento relacionado</span>
                <h3>Detalle de nota</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="modal-nota" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="contenido-nota" class="audalm-remote-body">Cargando nota...</div>
    </div>
</div>

<div class="audalm-modal" id="annulModal" aria-hidden="true">
    <div class="audalm-modal-card">
        <div class="audalm-modal-head">
            <div>
                <span class="audalm-kicker audalm-kicker--dark"><i class="bi bi-slash-circle"></i> Anulacion</span>
                <h3>Anular auditoria</h3>
            </div>
            <button type="button" class="audalm-icon-btn" data-close="annulModal" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="annulForm" class="audalm-form">
            <input type="hidden" name="auditoria_id" id="annulAuditId">
            <label>Motivo<textarea name="motivo" rows="5" required placeholder="Indica por que se anula esta auditoria"></textarea></label>
            <div class="audalm-modal-actions">
                <button type="submit" class="audalm-btn audalm-btn--danger"><i class="bi bi-slash-circle"></i> Anular</button>
                <button type="button" class="audalm-btn audalm-btn--soft" data-close="annulModal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div class="audalm-toast" id="audToast" role="status" aria-live="polite"></div>

<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/barcode_n360.js') ?>"></script>
<script>
const productHistoryEndpoint = <?= json_encode(n360_base_url('php/ver_movimientos_producto.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const productNoteEndpoint = <?= json_encode(n360_base_url('php/ver_nota_salida.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const state = {
    rows: [],
    selected: null,
    spacesLoaded: false,
    inventoryLoaded: false,
    inventory: [],
    records: [],
    activeAudit: null,
};

const qs = (sel, root = document) => root.querySelector(sel);
const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[ch]));
}

function fmtNum(value) {
    if (value === null || value === undefined || value === '') return '';
    const n = Number(value);
    if (Number.isNaN(n)) return '';
    return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(4)));
}

function toast(message, type = 'ok') {
    const box = qs('#audToast');
    box.textContent = message;
    box.className = `audalm-toast is-visible is-${type}`;
    window.clearTimeout(box._timer);
    box._timer = window.setTimeout(() => box.classList.remove('is-visible'), 3600);
}

async function api(action, options = {}) {
    const url = new URL('auditoria.php', window.location.href);
    url.searchParams.set('action', action);
    if (options.params) {
        Object.entries(options.params).forEach(([key, value]) => url.searchParams.set(key, value ?? ''));
    }
    const response = await fetch(url.toString(), {
        method: options.method || 'GET',
        body: options.body || null,
        headers: options.headers || {},
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo completar la operacion.');
    return data;
}

async function withLoading(options, task) {
    const config = typeof options === 'string' ? {title: options} : (options || {});
    const run = typeof task === 'function' ? task : async () => task;

    if (window.N360Loader && typeof window.N360Loader.during === 'function') {
        return window.N360Loader.during(run, {
            title: config.title || 'Procesando...',
            detail: config.detail || 'Por favor espere',
            button: config.button || null
        });
    }

    return run();
}

function openModal(id) {
    const modal = qs(`#${id}`);
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('audalm-lock');
}

function closeModal(id) {
    const modal = qs(`#${id}`);
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (!qsa('.audalm-modal.is-open').length) document.body.classList.remove('audalm-lock');
}

function setSelected(row) {
    state.selected = row || null;
    qsa('#auditRows tr').forEach(tr => tr.classList.toggle('is-selected', Number(tr.dataset.id) === Number(row?.id)));
    qs('#selectedLabel').textContent = row ? `${row.codigo} - ${row.estado_txt}` : 'Sin seleccion';

    const has = !!row;
    const pending = has && Number(row.estado) === 1;
    const done = has && Number(row.estado) === 2;
    qs('#btnDetail').disabled = !has;
    qs('#btnRealize').disabled = !pending;
    qs('#btnAnnul').disabled = !has || Number(row.estado) === 3;
    qs('#btnOrder').disabled = !has;
    qs('#btnManual').disabled = !has;
    qs('#btnPrintAudit').disabled = !done;
}

function renderKpi(kpi) {
    qs('#kpiTotal').textContent = kpi.total || 0;
    qs('#kpiPendientes').textContent = kpi.pendientes || 0;
    qs('#kpiRealizadas').textContent = kpi.realizados || 0;
    qs('#kpiAnuladas').textContent = kpi.anulados || 0;
}

function renderRows(rows) {
    const tbody = qs('#auditRows');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="audalm-empty">No hay auditorias para los filtros aplicados.</td></tr>';
        setSelected(null);
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr data-id="${row.id}">
            <td><strong>${esc(row.codigo)}</strong><small>${esc(row.fecha_creada_txt || '')}</small></td>
            <td><span class="audalm-status audalm-status--${esc(row.estado_class)}">${esc(row.estado_txt)}</span></td>
            <td>${esc(row.fecha_prog_txt)}</td>
            <td>${esc(row.espacio_txt)}</td>
            <td>${esc(row.responsable)}</td>
            <td>${esc(row.supervisor)}</td>
            <td>${esc(row.veedor)}</td>
            <td>${row.has_doc ? '<span class="audalm-doc-ok"><i class="bi bi-paperclip"></i> Adjunto</span>' : '<span class="audalm-muted">Pendiente</span>'}</td>
        </tr>
    `).join('');

    qsa('#auditRows tr').forEach(tr => {
        tr.addEventListener('click', () => {
            const row = state.rows.find(item => Number(item.id) === Number(tr.dataset.id));
            setSelected(row);
        });
    });

    if (state.selected) {
        const same = rows.find(row => Number(row.id) === Number(state.selected.id));
        setSelected(same || rows[0]);
    } else {
        setSelected(rows[0]);
    }
}

async function loadAudits(options = {}) {
    const run = async () => {
        const data = await api('list', {
            params: {
                estado: qs('#filterEstado').value,
                desde: qs('#filterDesde').value,
                hasta: qs('#filterHasta').value,
                buscar: qs('#filterBuscar').value.trim(),
            }
        });
        state.rows = data.rows || [];
        renderKpi(data.kpi || {});
        renderRows(state.rows);
    };

    try {
        if (options.loading) {
            await withLoading({
                title: options.title || 'Cargando auditorias...',
                detail: options.detail || 'Actualizando la informacion',
                button: options.button || null
            }, run);
            return;
        }

        await run();
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function loadSpaces() {
    if (state.spacesLoaded) return;
    const data = await api('spaces');
    const select = qs('#spaceSelect');
    select.innerHTML = '<option value="">Seleccionar espacio</option>' + (data.rows || []).map(row => {
        const label = `(${row.clm_esp_nombre || ''}) ${row.clm_esp_desc || ''}`.trim();
        return `<option value="${esc(row.clm_esp_id)}">${esc(label)}</option>`;
    }).join('');
    state.spacesLoaded = true;
}

async function loadInventory(force = false) {
    if (state.inventoryLoaded && !force) return state.inventory;
    const data = await api('inventory', force ? {params: {fresh: Date.now()}} : {});
    state.inventory = data.rows || [];
    state.inventoryLoaded = true;
    return state.inventory;
}

function makeRecords() {
    state.records = state.inventory.map(item => {
        const system = Number(item.Stock_Actual || 0);
        return {
            idprod: Number(item.ID || 0),
            cod: item.CODIPRODUCTO || '',
            producto: item.Producto || '',
            unidad: item.Unidad || '',
            categoria: item.Categoria || '',
            stock_sistema: system,
            stock_fisico: null,
            diferencia: null,
            conforme: false,
            doc_ok: false,
            obs: '',
        };
    });
}

function makeRecordsFromSaved(items) {
    const savedById = new Map((items || []).map(item => [Number(item.idprod || item.ID || 0), item]));
    const records = state.inventory.map(current => {
        const id = Number(current.ID || 0);
        const saved = savedById.get(id) || {};
        const system = saved.stock_sistema !== undefined && saved.stock_sistema !== null && saved.stock_sistema !== ''
            ? Number(saved.stock_sistema)
            : Number(current.Stock_Actual || 0);
        const physical = saved.stock_fisico === null || saved.stock_fisico === undefined || saved.stock_fisico === ''
            ? null
            : Number(saved.stock_fisico);
        const diff = physical === null ? null : Number((physical - system).toFixed(4));
        const conforme = Boolean(saved.conforme) || (physical !== null && Number(diff) === 0);

        return {
            idprod: id,
            cod: saved.cod || current.CODIPRODUCTO || '',
            producto: saved.producto || current.Producto || '',
            unidad: saved.unidad || current.Unidad || '',
            categoria: saved.categoria || current.Categoria || '',
            stock_sistema: system,
            stock_fisico: physical,
            diferencia: diff,
            conforme,
            doc_ok: conforme || Boolean(saved.doc_ok),
            obs: saved.obs || '',
        };
    });

    (items || []).forEach(saved => {
        const id = Number(saved.idprod || saved.ID || 0);
        if (!id || records.some(item => Number(item.idprod) === id)) return;

        const system = Number(saved.stock_sistema || 0);
        const physical = saved.stock_fisico === null || saved.stock_fisico === undefined || saved.stock_fisico === ''
            ? null
            : Number(saved.stock_fisico);
        const diff = physical === null ? null : Number((physical - system).toFixed(4));
        const conforme = Boolean(saved.conforme) || (physical !== null && Number(diff) === 0);

        records.push({
            idprod: id,
            cod: saved.cod || '',
            producto: saved.producto || '',
            unidad: saved.unidad || '',
            categoria: saved.categoria || '',
            stock_sistema: system,
            stock_fisico: physical,
            diferencia: diff,
            conforme,
            doc_ok: conforme || Boolean(saved.doc_ok),
            obs: saved.obs || '',
        });
    });

    state.records = records;
}

function recordCounted(item) {
    return item.stock_fisico !== null && item.stock_fisico !== undefined && item.stock_fisico !== '';
}

function recordConforme(item) {
    return recordCounted(item) && (Boolean(item.conforme) || Number(item.diferencia) === 0);
}

function inventorySummary() {
    return state.records.reduce((acc, item) => {
        acc.total++;
        if (!recordCounted(item)) acc.pendientes++;
        else {
            acc.contados++;
            if (Number(item.diferencia) === 0) acc.ok++;
            else acc.diferencias++;
        }
        if (recordConforme(item)) acc.conformes++;
        if (item.doc_ok) acc.doc_ok++;
        return acc;
    }, {total: 0, contados: 0, conformes: 0, pendientes: 0, ok: 0, diferencias: 0, doc_ok: 0});
}

function renderInventoryStats() {
    const s = inventorySummary();
    qs('#sumTotal').textContent = s.total;
    qs('#sumContados').textContent = s.contados;
    qs('#sumConformes').textContent = s.conformes;
    qs('#sumPendientes').textContent = s.pendientes;
    qs('#sumDiferencias').textContent = s.diferencias;
}

function filteredRecords() {
    const term = qs('#invSearch').value.trim().toLowerCase();
    const cat = qs('#invCategory').value;
    const view = qs('#invView').value;
    return state.records.filter(item => {
        if (cat && item.categoria !== cat) return false;
        if (term) {
            const hay = `${item.cod} ${item.producto} ${item.categoria}`.toLowerCase();
            if (!hay.includes(term)) return false;
        }
        const counted = recordCounted(item);
        if (view === 'pendientes') return !counted;
        if (view === 'contados') return counted;
        if (view === 'conformes') return recordConforme(item);
        if (view === 'diferencias') return counted && Number(item.diferencia) !== 0;
        return true;
    });
}

function syncRecordFromInput(id, key, value) {
    const item = state.records.find(row => Number(row.idprod) === Number(id));
    if (!item) return;
    if (key === 'stock_fisico') {
        item.stock_fisico = value === '' ? null : Number(value);
        item.diferencia = item.stock_fisico === null ? null : Number((item.stock_fisico - item.stock_sistema).toFixed(4));
        item.conforme = item.stock_fisico !== null && Number(item.diferencia) === 0;
        item.doc_ok = item.conforme;
    } else if (key === 'conforme') {
        item.conforme = !!value;
        item.doc_ok = item.conforme;
        if (item.conforme) {
            item.stock_fisico = item.stock_sistema;
            item.diferencia = 0;
        }
    } else {
        item[key] = value;
    }
    renderInventoryStats();
}

function auditRecordClass(item) {
    if (!recordCounted(item)) return 'is-pending';
    if (recordConforme(item)) return 'is-conforme';
    return 'is-difference';
}

function productDisplay(item) {
    const code = item.cod ? `(${esc(item.cod)}) ` : '';
    const unit = item.unidad ? ` - ${esc(item.unidad)}` : '';
    return `<div class="audalm-product-info">
        <div><strong>${code}</strong><span>${esc(item.producto)}</span><small>${unit}</small></div>
        <button type="button" class="audalm-product-history-btn" data-product-history="${esc(item.idprod)}" title="Ver historial de movimientos" aria-label="Ver historial de ${esc(item.producto)}">
            <i class="bi bi-clock-history"></i>
        </button>
    </div>`;
}

function focusNextStockInput(currentId, fallbackIndex) {
    window.setTimeout(() => {
        const rows = qsa('#inventoryRows tr');
        if (!rows.length) return;

        const visibleIndex = rows.findIndex(row => String(row.dataset.id) === String(currentId));
        let targetIndex = visibleIndex >= 0 ? visibleIndex + 1 : fallbackIndex;

        if (targetIndex >= rows.length) targetIndex = rows.length - 1;
        if (targetIndex < 0) return;

        const input = qs('.js-stock', rows[targetIndex]);
        if (!input) return;

        input.focus();
        input.select();
        input.scrollIntoView({block: 'center', inline: 'nearest'});
    }, 0);
}

function renderInventoryRows() {
    const tbody = qs('#inventoryRows');
    const rows = filteredRecords();
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="audalm-empty">No hay productos para los filtros del conteo.</td></tr>';
        renderInventoryStats();
        return;
    }
    tbody.innerHTML = rows.map(item => `
        <tr data-id="${item.idprod}" class="${auditRecordClass(item)}">
            <td>${esc(item.categoria)}</td>
            <td class="audalm-product-cell">${productDisplay(item)}</td>
            <td class="audalm-num">${fmtNum(item.stock_sistema)}</td>
            <td><input class="audalm-cell-input js-stock" type="number" step="0.0001" inputmode="decimal" enterkeyhint="next" value="${item.stock_fisico === null ? '' : esc(item.stock_fisico)}"></td>
            <td class="audalm-num ${Number(item.diferencia || 0) === 0 ? '' : 'is-diff'}">${item.diferencia === null ? '' : fmtNum(item.diferencia)}</td>
            <td>
                <label class="audalm-conforme-check">
                    <input class="js-conforme" type="checkbox" ${item.conforme ? 'checked' : ''}>
                    <span>Conforme</span>
                </label>
            </td>
            <td><input class="audalm-cell-input js-obs" type="text" value="${esc(item.obs)}" placeholder="Obs."></td>
        </tr>
    `).join('');

    qsa('#inventoryRows tr').forEach(tr => {
        const id = tr.dataset.id;
        const stockInput = qs('.js-stock', tr);
        stockInput?.addEventListener('keydown', ev => {
            if (ev.key !== 'Enter') return;

            ev.preventDefault();
            const currentIndex = qsa('#inventoryRows tr').indexOf(tr);
            syncRecordFromInput(id, 'stock_fisico', ev.target.value);
            renderInventoryRows();
            focusNextStockInput(id, currentIndex);
        });
        stockInput?.addEventListener('change', ev => {
            syncRecordFromInput(id, 'stock_fisico', ev.target.value);
            renderInventoryRows();
        });
        qs('.js-conforme', tr)?.addEventListener('change', ev => {
            syncRecordFromInput(id, 'conforme', ev.target.checked);
            renderInventoryRows();
        });
        qs('.js-obs', tr)?.addEventListener('input', ev => syncRecordFromInput(id, 'obs', ev.target.value));
    });

    renderInventoryStats();
}

function populateCategories() {
    const cats = Array.from(new Set(state.records.map(item => item.categoria).filter(Boolean))).sort((a, b) => a.localeCompare(b));
    qs('#invCategory').innerHTML = '<option value="">Todas las categorias</option>' + cats.map(cat => `<option value="${esc(cat)}">${esc(cat)}</option>`).join('');
}

async function openRealize(button = null) {
    if (!state.selected || Number(state.selected.estado) !== 1) return;
    try {
        await withLoading({
            title: 'Preparando auditoria...',
            detail: 'Cargando productos y progreso guardado',
            button
        }, async () => {
            await loadInventory();
            const detail = await api('detail', {params: {id: state.selected.id}});
            const savedItems = Array.isArray(detail.contenido?.items) ? detail.contenido.items : [];
            state.activeAudit = state.selected;
            if (savedItems.length) makeRecordsFromSaved(savedItems);
            else makeRecords();
            populateCategories();
            qs('#realizeAuditId').value = state.selected.id;
            qs('#realizeTitle').textContent = `Realizar auditoria ${state.selected.codigo}`;
            qs('#realizeDoc').value = '';
            qs('#realizeComment').value = '';
            qs('#invSearch').value = '';
            qs('#invCategory').value = '';
            qs('#invView').value = 'todos';
            renderInventoryRows();
            openModal('realizeModal');
        });
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function refreshSystemStock(button = null) {
    if (!state.activeAudit || !state.records.length) return;
    if (!confirm('Se consultara nuevamente el stock actual del sistema. El stock fisico y las observaciones no se modificaran. Deseas continuar?')) return;

    try {
        await withLoading({
            title: 'Actualizando stock del sistema...',
            detail: 'Consultando el inventario actual y recalculando diferencias',
            button
        }, async () => {
            const inventory = await loadInventory(true);
            const currentById = new Map(inventory.map(item => [Number(item.ID || 0), item]));
            let updated = 0;
            let unchanged = 0;
            let missing = 0;

            state.records.forEach(item => {
                const current = currentById.get(Number(item.idprod));
                if (!current) {
                    missing++;
                    return;
                }

                const newSystemStock = Number(current.Stock_Actual || 0);
                if (Number(item.stock_sistema) === newSystemStock) unchanged++;
                else updated++;

                item.stock_sistema = newSystemStock;
                if (recordCounted(item)) {
                    item.diferencia = Number((Number(item.stock_fisico) - newSystemStock).toFixed(4));
                    item.conforme = Number(item.diferencia) === 0;
                    item.doc_ok = item.conforme;
                } else {
                    item.diferencia = null;
                    item.conforme = false;
                    item.doc_ok = false;
                }
            });

            renderInventoryRows();
            const missingText = missing ? ` ${missing} producto(s) no aparecieron en el inventario actual y conservaron su stock anterior.` : '';
            toast(`${updated} stock(s) actualizados y ${unchanged} sin cambios.${missingText} Revisa y guarda el progreso.`);
        });
    } catch (err) {
        toast(err.message, 'error');
    }
}

function detailSummaryCards(summary) {
    return `
        <div class="audalm-mini-kpis audalm-mini-kpis--detail">
            <div><span>Total</span><strong>${esc(summary.total || 0)}</strong></div>
            <div><span>Contados</span><strong>${esc(summary.contados || 0)}</strong></div>
            <div><span>Conformes</span><strong>${esc(summary.conformes || summary.ok || 0)}</strong></div>
            <div><span>Pendientes</span><strong>${esc(summary.pendientes || 0)}</strong></div>
            <div><span>Diferencias</span><strong>${esc(summary.diferencias || 0)}</strong></div>
        </div>
    `;
}

function detailItemsTable(items, summary, emptyMessage = 'No hay detalle de productos registrado.') {
    if (!items.length) return `<div class="audalm-empty audalm-empty--box">${esc(emptyMessage)}</div>`;
    return `
        ${detailSummaryCards(summary)}
        <div class="audalm-inventory-wrap audalm-inventory-wrap--detail">
            <table class="audalm-table audalm-table--compact">
                <thead><tr><th>Categoria</th><th>Producto</th><th>Sistema</th><th>Fisico</th><th>Diferencia</th><th>Conforme</th><th>Obs.</th></tr></thead>
                <tbody>${items.map(item => `
                    <tr class="${auditRecordClass(item)}">
                        <td>${esc(item.categoria)}</td>
                        <td class="audalm-product-cell">${productDisplay(item)}</td>
                        <td class="audalm-num">${fmtNum(item.stock_sistema)}</td>
                        <td class="audalm-num">${fmtNum(item.stock_fisico)}</td>
                        <td class="audalm-num ${Number(item.diferencia || 0) === 0 ? '' : 'is-diff'}">${fmtNum(item.diferencia)}</td>
                        <td>${recordConforme(item) ? 'Conforme' : ''}</td>
                        <td>${esc(item.obs || '')}</td>
                    </tr>
                `).join('')}</tbody>
            </table>
        </div>
    `;
}

function historyTypeLabel(type) {
    return String(type || '').toUpperCase() === 'BASE' ? 'Base recuperada' : 'Progreso guardado';
}

function renderProgressHistory(data) {
    const target = qs('#detailProgressHistory');
    const count = qs('#detailProgressHistoryCount');
    if (!target || !count) return;

    if (!data.installed) {
        count.textContent = 'No instalado';
        target.innerHTML = '<div class="audalm-history-state is-warning">Instala la tabla <strong>tb_hist_auditoria_alm</strong> para comenzar a registrar versiones.</div>';
        return;
    }

    const entries = Array.isArray(data.rows) ? data.rows : [];
    count.textContent = `${entries.length} version(es)`;
    if (!entries.length) {
        target.innerHTML = '<div class="audalm-history-state">Esta auditoria aun no tiene versiones guardadas. El primer guardado conservara tambien el progreso actual como base.</div>';
        return;
    }

    target.innerHTML = entries.map(entry => {
        const typeClass = String(entry.tipo || '').toUpperCase() === 'BASE' ? 'is-base' : 'is-progress';
        return `<button type="button" class="audalm-history-entry ${typeClass}" data-progress-history-id="${entry.id}" data-audit-id="${entry.auditoria_id}">
            <span class="audalm-history-version"><i class="bi bi-clock-history"></i> Version ${entry.version}</span>
            <span class="audalm-history-kind">${esc(historyTypeLabel(entry.tipo))}</span>
            <strong>${esc(entry.fecha_txt || '-')}</strong>
            <small>${esc(entry.usuario || 'Usuario no identificado')}</small>
            <span class="audalm-history-metrics">
                <b>${entry.contados} contados</b><b>${entry.pendientes} pendientes</b><b>${entry.diferencias} diferencias</b>
            </span>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>`;
    }).join('');
}

async function loadProgressHistory(auditId) {
    const target = qs('#detailProgressHistory');
    if (target) target.innerHTML = '<div class="audalm-history-state">Cargando versiones...</div>';
    try {
        const data = await api('progress_history', {params: {id: auditId}});
        renderProgressHistory(data);
    } catch (err) {
        if (target) target.innerHTML = `<div class="audalm-history-state is-error">${esc(err.message)}</div>`;
    }
}

function renderProgressSnapshot(data) {
    const entry = data.entry || {};
    const content = data.contenido || {};
    const items = Array.isArray(content.items) ? content.items : [];
    const summary = content.resumen || entry;
    qs('#progressHistoryTitle').textContent = `Version ${entry.version || '-'} - ${historyTypeLabel(entry.tipo)}`;
    qs('#progressHistoryBody').innerHTML = `
        <section class="audalm-history-snapshot-head">
            <div><span>Guardado</span><strong>${esc(entry.fecha_txt || '-')}</strong></div>
            <div><span>Usuario</span><strong>${esc(entry.usuario || 'Usuario no identificado')}</strong></div>
            <div><span>Integridad</span><strong title="${esc(entry.sha256 || '')}">${esc((entry.sha256 || '').slice(0, 16) || '-')}</strong></div>
        </section>
        ${detailItemsTable(items, summary, 'Esta version no contiene productos.')}
    `;
}

async function openProgressSnapshot(auditId, historyId, button = null) {
    try {
        await withLoading({
            title: 'Cargando version...',
            detail: 'Recuperando la fotografia guardada del progreso',
            button
        }, async () => {
            const data = await api('progress_snapshot', {params: {id: auditId, history_id: historyId}});
            renderProgressSnapshot(data);
            openModal('progressHistoryModal');
        });
    } catch (err) {
        toast(err.message, 'error');
    }
}

function renderDetail(data) {
    const audit = data.audit;
    const content = data.contenido || {};
    const items = Array.isArray(content.items) ? content.items : [];
    const summary = content.resumen || inventorySummary();
    const docLink = audit.has_doc
        ? `<a class="audalm-btn audalm-btn--soft" href="auditoria.php?action=download_doc&id=${audit.id}"><i class="bi bi-paperclip"></i> Descargar documento</a>`
        : '<span class="audalm-muted">Sin documento adjunto</span>';

    const itemTable = detailItemsTable(
        items,
        summary,
        audit.estado === 1 ? 'Auditoria pendiente de realizacion.' : 'No hay detalle de productos registrado.'
    );

    qs('#detailTitle').textContent = `${audit.codigo} - ${audit.estado_txt}`;
    qs('#detailBody').innerHTML = `
        <section class="audalm-detail-grid">
            <div><span>Estado</span><strong>${esc(audit.estado_txt)}</strong></div>
            <div><span>Programada</span><strong>${esc(audit.fecha_prog_txt)}</strong></div>
            <div><span>Espacio</span><strong>${esc(audit.espacio_txt)}</strong></div>
            <div><span>Responsable</span><strong>${esc(audit.responsable)}</strong></div>
            <div><span>Supervisor</span><strong>${esc(audit.supervisor)}</strong></div>
            <div><span>Veedor</span><strong>${esc(audit.veedor)}</strong></div>
        </section>
        <section class="audalm-detail-notes">
            <h4>Comentarios</h4>
            <p>${esc(audit.comentarios || 'Sin comentarios').replace(/\n/g, '<br>')}</p>
        </section>
        <section class="audalm-detail-doc">
            <div>
                <span>Documento</span>
                <strong>${esc(audit.doc_name || 'Sin adjunto')}</strong>
                <small>${audit.doc_size ? esc(Math.round(audit.doc_size / 1024) + ' KB') : ''} ${audit.doc_fecha_txt ? ' - ' + esc(audit.doc_fecha_txt) : ''}</small>
            </div>
            ${docLink}
        </section>
        ${itemTable}
        <section class="audalm-progress-history">
            <div class="audalm-progress-history__head">
                <div><span>Versiones</span><h4>Historial de guardados</h4></div>
                <strong id="detailProgressHistoryCount">Cargando...</strong>
            </div>
            <div class="audalm-history-list" id="detailProgressHistory"><div class="audalm-history-state">Cargando versiones...</div></div>
        </section>
    `;
}

async function openDetail(button = null) {
    if (!state.selected) return;
    try {
        await withLoading({
            title: 'Cargando detalle...',
            detail: 'Leyendo la auditoria seleccionada',
            button
        }, async () => {
            const data = await api('detail', {params: {id: state.selected.id}});
            renderDetail(data);
            openModal('detailModal');
            await loadProgressHistory(state.selected.id);
        });
    } catch (err) {
        toast(err.message, 'error');
    }
}

function remoteLoading(message) {
    return `<div class="audalm-remote-state"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><strong>${esc(message)}</strong></div>`;
}

async function openProductHistory(productId) {
    const content = qs('#contenido-movimientos');
    if (!content || !productId) return;
    content.innerHTML = remoteLoading('Cargando movimientos del producto...');
    openModal('modal-movimientos');

    try {
        const url = `${productHistoryEndpoint}${productHistoryEndpoint.includes('?') ? '&' : '?'}id=${encodeURIComponent(productId)}`;
        const response = await fetch(url, {credentials: 'same-origin'});
        if (!response.ok) throw new Error('No se pudo consultar el historial del producto.');
        content.innerHTML = await response.text();
        if (window.N360Barcode && typeof window.N360Barcode.renderAll === 'function') {
            window.N360Barcode.renderAll(content);
        }
    } catch (err) {
        content.innerHTML = `<div class="audalm-remote-state is-error"><strong>${esc(err.message)}</strong></div>`;
    }
}

async function openProductNote(movementId) {
    const content = qs('#contenido-nota');
    if (!content || !movementId) return;
    content.innerHTML = remoteLoading('Cargando nota relacionada...');
    openModal('modal-nota');

    try {
        const url = `${productNoteEndpoint}${productNoteEndpoint.includes('?') ? '&' : '?'}id=${encodeURIComponent(movementId)}`;
        const response = await fetch(url, {credentials: 'same-origin'});
        if (!response.ok) throw new Error('No se pudo consultar la nota relacionada.');
        content.innerHTML = await response.text();
    } catch (err) {
        content.innerHTML = `<div class="audalm-remote-state is-error"><strong>${esc(err.message)}</strong></div>`;
    }
}

window.verNotaSalida = openProductNote;

function openPrint(type) {
    if (!state.selected) return;
    const url = `auditoria.php?print=${encodeURIComponent(type)}&id=${encodeURIComponent(state.selected.id)}`;
    window.open(url, '_blank', 'noopener');
}

qsa('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
qsa('.audalm-modal').forEach(modal => modal.addEventListener('click', ev => {
    if (ev.target === modal) closeModal(modal.id);
}));
document.addEventListener('click', ev => {
    const productButton = ev.target.closest('[data-product-history]');
    if (productButton) {
        ev.preventDefault();
        ev.stopPropagation();
        openProductHistory(productButton.dataset.productHistory);
        return;
    }

    const historyButton = ev.target.closest('[data-progress-history-id]');
    if (historyButton) {
        ev.preventDefault();
        openProgressSnapshot(
            historyButton.dataset.auditId,
            historyButton.dataset.progressHistoryId,
            historyButton
        );
    }
});

qs('#btnOpenSchedule').addEventListener('click', async ev => {
    try {
        await withLoading({
            title: 'Preparando formulario...',
            detail: 'Cargando espacios de almacen',
            button: ev.currentTarget
        }, async () => {
            await loadSpaces();
            qs('#scheduleForm').reset();
            qs('#scheduleForm [name="fecha_prog"]').valueAsDate = new Date();
            openModal('scheduleModal');
        });
    } catch (err) {
        toast(err.message, 'error');
    }
});

qs('#scheduleForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const formEl = ev.currentTarget;
    const submitter = ev.submitter || qs('#scheduleForm button[type="submit"]');
    try {
        await withLoading({
            title: 'Programando auditoria...',
            detail: 'Guardando la programacion',
            button: submitter
        }, async () => {
            const form = new FormData(formEl);
            form.append('action', 'schedule');
            const data = await api('schedule', {method: 'POST', body: form});
            closeModal('scheduleModal');
            toast(data.message || 'Auditoria programada.');
            await loadAudits();
        });
    } catch (err) {
        toast(err.message, 'error');
    }
});

function buildAuditPayload(stage = 'progreso') {
    const summary = inventorySummary();
    return {
        auditoria_id: Number(qs('#realizeAuditId').value),
        codigo: state.activeAudit?.codigo || '',
        origen: 'vw_control_inventario',
        estado_registro: stage,
        fecha_guardado: new Date().toISOString(),
        resumen: summary,
        items: state.records,
    };
}

async function saveProgress() {
    if (!state.activeAudit) return;

    const form = new FormData();
    form.append('action', 'save_progress');
    form.append('auditoria_id', qs('#realizeAuditId').value);
    form.append('contenido', JSON.stringify(buildAuditPayload('progreso')));

    const data = await api('save_progress', {method: 'POST', body: form});
    toast(data.message || 'Progreso guardado.');
    await loadAudits();
}

qs('#btnSaveProgress').addEventListener('click', async ev => {
    try {
        await withLoading({
            title: 'Guardando progreso...',
            detail: 'Actualizando la memoria de auditoria',
            button: ev.currentTarget
        }, saveProgress);
    } catch (err) {
        toast(err.message, 'error');
    }
});

qs('#realizeForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const formEl = ev.currentTarget;
    const submitter = ev.submitter || qs('#realizeForm button[type="submit"]');
    const summary = inventorySummary();
    if (summary.total <= 0 || summary.contados <= 0) {
        toast('Registra al menos un producto contado.', 'error');
        return;
    }
    const confirmMessage = [
        'Vas a finalizar esta auditoria.',
        `Conformes: ${summary.conformes}`,
        `Pendientes: ${summary.pendientes}`,
        `Diferencias: ${summary.diferencias}`,
        'Confirmas que la auditoria esta conforme para finalizar?'
    ].join('\n');
    if (!confirm(confirmMessage)) return;

    if (summary.pendientes > 0 && !confirm(`Quedan ${summary.pendientes} productos pendientes. Deseas finalizar igual?`)) return;
    if (summary.diferencias > 0 && !confirm(`Hay ${summary.diferencias} diferencias registradas. Deseas finalizar igual?`)) return;
    if (!qs('#realizeDoc').files.length) {
        toast('Adjunta el documento de evidencia para finalizar.', 'error');
        return;
    }

    try {
        await withLoading({
            title: 'Finalizando auditoria...',
            detail: 'Guardando conteo y documento de evidencia',
            button: submitter
        }, async () => {
            const form = new FormData(formEl);
            form.append('action', 'realize');
            form.append('contenido', JSON.stringify(buildAuditPayload('finalizado')));
            const data = await api('realize', {method: 'POST', body: form});
            closeModal('realizeModal');
            toast(data.message || 'Auditoria realizada.');
            await loadAudits();
        });
    } catch (err) {
        toast(err.message, 'error');
    }
});

qs('#annulForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const formEl = ev.currentTarget;
    const submitter = ev.submitter || qs('#annulForm button[type="submit"]');
    try {
        await withLoading({
            title: 'Anulando auditoria...',
            detail: 'Registrando el motivo',
            button: submitter
        }, async () => {
            const form = new FormData(formEl);
            form.append('action', 'annul');
            const data = await api('annul', {method: 'POST', body: form});
            closeModal('annulModal');
            toast(data.message || 'Auditoria anulada.');
            await loadAudits();
        });
    } catch (err) {
        toast(err.message, 'error');
    }
});

qs('#btnRefresh').addEventListener('click', ev => loadAudits({
    loading: true,
    title: 'Actualizando auditorias...',
    detail: 'Consultando informacion reciente',
    button: ev.currentTarget
}));
qs('#btnApplyFilters').addEventListener('click', ev => loadAudits({
    loading: true,
    title: 'Filtrando auditorias...',
    detail: 'Aplicando criterios de busqueda',
    button: ev.currentTarget
}));
qs('#btnClearFilters').addEventListener('click', ev => {
    qs('#filterEstado').value = '0';
    qs('#filterDesde').value = '';
    qs('#filterHasta').value = '';
    qs('#filterBuscar').value = '';
    loadAudits({
        loading: true,
        title: 'Limpiando filtros...',
        detail: 'Recargando auditorias',
        button: ev.currentTarget
    });
});
qs('#filterBuscar').addEventListener('keydown', ev => {
    if (ev.key === 'Enter') loadAudits({
        loading: true,
        title: 'Buscando auditorias...',
        detail: 'Revisando coincidencias'
    });
});

qs('#btnDetail').addEventListener('click', ev => openDetail(ev.currentTarget));
qs('#btnRealize').addEventListener('click', ev => openRealize(ev.currentTarget));
qs('#btnOrder').addEventListener('click', () => openPrint('order'));
qs('#btnManual').addEventListener('click', () => openPrint('manual'));
qs('#btnPrintAudit').addEventListener('click', () => openPrint('audit'));
qs('#btnAnnul').addEventListener('click', () => {
    if (!state.selected) return;
    qs('#annulForm').reset();
    qs('#annulAuditId').value = state.selected.id;
    openModal('annulModal');
});

['#invSearch', '#invCategory', '#invView'].forEach(sel => qs(sel).addEventListener('input', renderInventoryRows));
qs('#btnRefreshSystemStock').addEventListener('click', ev => refreshSystemStock(ev.currentTarget));
qs('#btnCompletePending').addEventListener('click', () => {
    state.records.forEach(item => {
        if (item.stock_fisico === null || item.stock_fisico === '') {
            item.stock_fisico = item.stock_sistema;
            item.diferencia = 0;
            item.conforme = true;
            item.doc_ok = true;
        }
    });
    renderInventoryRows();
});

loadAudits();
</script>
</body>
</html>
