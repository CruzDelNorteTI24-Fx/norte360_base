<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login/login.php");
    exit();
}
if (($_SESSION['web_rol'] ?? '') !== 'Admin') {
    header("Location: ../login/none_permisos.php");
    exit();
}

define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
require_once __DIR__ . "/checklist_versiones.php";

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

mysqli_report(MYSQLI_REPORT_OFF);

function gchk_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function gchk_flash(string $type, string $message): void {
    $_SESSION['gchk_flash'] = ['type' => $type, 'message' => $message];
}

function gchk_redirect(array $params = []): void {
    $query = http_build_query(array_filter($params, function ($value) {
        return $value !== null && $value !== '';
    }));
    header("Location: gestionar_checklists.php" . ($query ? "?{$query}" : ""));
    exit();
}

function gchk_post_int(string $key, int $default = 0): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

function gchk_post_text(string $key): string {
    return trim((string)($_POST[$key] ?? ''));
}

function gchk_date_or_null(string $key): ?string {
    $value = gchk_post_text($key);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function gchk_schema_ready(mysqli $conn): bool {
    return n360_cv_table_exists($conn, 'tb_checklist_versiones')
        && n360_cv_column_exists($conn, 'tb_items_checklist', 'clm_item_idversion')
        && n360_cv_column_exists($conn, 'tb_checklist_limpieza', 'clm_checklist_idversion');
}

function gchk_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') n360_cv_bind_params($stmt, $types, $params);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function gchk_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = gchk_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function gchk_scalar_int(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $row = gchk_fetch_one($conn, $sql, $types, $params);
    if (!$row) return 0;
    return (int)array_values($row)[0];
}

function gchk_next_version_number(mysqli $conn, int $tipoId): int {
    return gchk_scalar_int(
        $conn,
        "SELECT COALESCE(MAX(clm_checkver_numero), 0) + 1 FROM tb_checklist_versiones WHERE clm_checkver_idtipo = ?",
        'i',
        [$tipoId]
    );
}

function gchk_version_locked(mysqli $conn, int $versionId): bool {
    if ($versionId <= 0 || !n360_cv_checklist_version_ready($conn)) return false;
    return gchk_scalar_int(
        $conn,
        "SELECT COUNT(*) FROM tb_checklist_limpieza WHERE clm_checklist_idversion = ?",
        'i',
        [$versionId]
    ) > 0;
}

function gchk_category_locked(mysqli $conn, int $categoryId): bool {
    if ($categoryId <= 0 || !gchk_schema_ready($conn)) return false;
    return gchk_scalar_int(
        $conn,
        "SELECT COUNT(*)
         FROM tb_items_checklist i
         INNER JOIN tb_checklist_limpieza c ON c.clm_checklist_idversion = i.clm_item_idversion
         WHERE i.clm_item_id_categoria = ?",
        'i',
        [$categoryId]
    ) > 0;
}

function gchk_clone_version(mysqli $conn, int $sourceVersionId, int $newVersionId, int $tipoId): void {
    if ($sourceVersionId <= 0 || $newVersionId <= 0 || $tipoId <= 0) return;

    $rows = gchk_fetch_all(
        $conn,
        "SELECT cat.clm_categoria_id, cat.clm_categoria_nombre,
                i.clm_item_nombre, i.clm_item_estado, i.clm_items_tipo
         FROM tb_items_checklist i
         INNER JOIN tb_categorias_checklist cat ON cat.clm_categoria_id = i.clm_item_id_categoria
         WHERE i.clm_item_idversion = ?
         ORDER BY cat.clm_categoria_id ASC, i.clm_item_id ASC",
        'i',
        [$sourceVersionId]
    );
    if (!$rows) return;

    $categoryMap = [];
    $stmtCat = $conn->prepare("
        INSERT INTO tb_categorias_checklist (clm_categoria_nombre, clm_categorias_estado)
        VALUES (?, 'activo')
    ");
    $stmtItem = $conn->prepare("
        INSERT INTO tb_items_checklist
            (clm_item_id_categoria, clm_item_nombre, clm_item_estado, clm_item_idtipocheck, clm_items_tipo, clm_item_idversion)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmtCat || !$stmtItem) return;

    foreach ($rows as $row) {
        $oldCategoryId = (int)$row['clm_categoria_id'];
        if (!isset($categoryMap[$oldCategoryId])) {
            $categoryName = (string)$row['clm_categoria_nombre'];
            $stmtCat->bind_param('s', $categoryName);
            $stmtCat->execute();
            $categoryMap[$oldCategoryId] = (int)$stmtCat->insert_id;
        }

        $newCategoryId = $categoryMap[$oldCategoryId];
        $itemName = (string)$row['clm_item_nombre'];
        $itemState = (string)$row['clm_item_estado'];
        $itemType = (string)$row['clm_items_tipo'];
        $stmtItem->bind_param('issisi', $newCategoryId, $itemName, $itemState, $tipoId, $itemType, $newVersionId);
        $stmtItem->execute();
    }

    $stmtCat->close();
    $stmtItem->close();
}

$schemaReady = gchk_schema_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = gchk_post_text('accion');
    $redirect = [
        'tipo' => gchk_post_int('tipo_id'),
        'version' => gchk_post_int('version_id'),
        'tab' => gchk_post_text('tab') ?: 'versiones',
    ];

    try {
        if ($action === 'tipo_crear') {
            $nombre = gchk_post_text('tipo_nombre');
            if ($nombre === '') throw new RuntimeException('Ingresa el nombre del tipo.');
            $stmt = $conn->prepare("INSERT INTO tb_checklist_tipos (clm_checktip_nombre) VALUES (?)");
            if (!$stmt) throw new RuntimeException('No se pudo preparar el registro.');
            $stmt->bind_param('s', $nombre);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo registrar el tipo.');
            $redirect['tipo'] = (int)$stmt->insert_id;
            $redirect['tab'] = 'tipos';
            $stmt->close();
            gchk_flash('ok', 'Tipo de checklist registrado.');
        } elseif ($action === 'tipo_editar') {
            $tipoId = gchk_post_int('tipo_id');
            $nombre = gchk_post_text('tipo_nombre');
            if ($tipoId <= 0 || $nombre === '') throw new RuntimeException('Datos incompletos del tipo.');
            $stmt = $conn->prepare("UPDATE tb_checklist_tipos SET clm_checktip_nombre = ? WHERE clm_checktip_id = ?");
            if (!$stmt) throw new RuntimeException('No se pudo preparar la actualizacion.');
            $stmt->bind_param('si', $nombre, $tipoId);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo actualizar el tipo.');
            $stmt->close();
            $redirect['tab'] = 'tipos';
            gchk_flash('ok', 'Tipo de checklist actualizado.');
        } elseif ($action === 'version_crear') {
            if (!$schemaReady) throw new RuntimeException('Primero ejecuta el query de versionado.');
            $tipoId = gchk_post_int('tipo_id');
            $numero = gchk_post_int('version_numero');
            $estado = gchk_post_text('version_estado') ?: 'borrador';
            $desde = gchk_date_or_null('version_desde');
            $hasta = gchk_date_or_null('version_hasta');
            $obs = gchk_post_text('version_observacion');
            $cloneFrom = gchk_post_int('clonar_version_id');
            if ($tipoId <= 0) throw new RuntimeException('Selecciona un tipo.');
            if ($numero <= 0) $numero = gchk_next_version_number($conn, $tipoId);
            if (!in_array($estado, ['borrador', 'activo', 'cerrado'], true)) $estado = 'borrador';
            $nombre = gchk_post_text('version_nombre');
            if ($nombre === '') $nombre = 'Version ' . $numero;
            $usuario = (int)($_SESSION['id_usuario'] ?? 0);

            $stmt = $conn->prepare("
                INSERT INTO tb_checklist_versiones
                    (clm_checkver_idtipo, clm_checkver_numero, clm_checkver_nombre, clm_checkver_estado,
                     clm_checkver_desde, clm_checkver_hasta, clm_checkver_observacion, clm_checkver_usuario)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) throw new RuntimeException('No se pudo preparar la version.');
            $stmt->bind_param('iisssssi', $tipoId, $numero, $nombre, $estado, $desde, $hasta, $obs, $usuario);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo crear la version. Revisa si el numero ya existe.');
            $newVersionId = (int)$stmt->insert_id;
            $stmt->close();

            if ($cloneFrom > 0) {
                gchk_clone_version($conn, $cloneFrom, $newVersionId, $tipoId);
            }

            $redirect['version'] = $newVersionId;
            $redirect['tab'] = 'versiones';
            gchk_flash('ok', 'Version registrada.');
        } elseif ($action === 'version_editar') {
            if (!$schemaReady) throw new RuntimeException('Primero ejecuta el query de versionado.');
            $versionId = gchk_post_int('version_id');
            $tipoId = gchk_post_int('tipo_id');
            $numero = gchk_post_int('version_numero');
            $nombre = gchk_post_text('version_nombre');
            $estado = gchk_post_text('version_estado');
            $desde = gchk_date_or_null('version_desde');
            $hasta = gchk_date_or_null('version_hasta');
            $obs = gchk_post_text('version_observacion');
            if ($versionId <= 0 || $tipoId <= 0 || $numero <= 0 || $nombre === '') {
                throw new RuntimeException('Datos incompletos de la version.');
            }
            if (!in_array($estado, ['borrador', 'activo', 'cerrado'], true)) $estado = 'borrador';

            $stmt = $conn->prepare("
                UPDATE tb_checklist_versiones
                SET clm_checkver_numero = ?,
                    clm_checkver_nombre = ?,
                    clm_checkver_estado = ?,
                    clm_checkver_desde = ?,
                    clm_checkver_hasta = ?,
                    clm_checkver_observacion = ?
                WHERE clm_checkver_id = ?
                  AND clm_checkver_idtipo = ?
            ");
            if (!$stmt) throw new RuntimeException('No se pudo preparar la actualizacion.');
            $stmt->bind_param('isssssii', $numero, $nombre, $estado, $desde, $hasta, $obs, $versionId, $tipoId);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo actualizar la version.');
            $stmt->close();
            $redirect['tab'] = 'versiones';
            gchk_flash('ok', 'Version actualizada.');
        } elseif ($action === 'categoria_crear') {
            $nombre = gchk_post_text('categoria_nombre');
            if ($nombre === '') throw new RuntimeException('Ingresa el nombre de la categoria.');
            $stmt = $conn->prepare("INSERT INTO tb_categorias_checklist (clm_categoria_nombre, clm_categorias_estado) VALUES (?, 'activo')");
            if (!$stmt) throw new RuntimeException('No se pudo preparar la categoria.');
            $stmt->bind_param('s', $nombre);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo crear la categoria.');
            $stmt->close();
            $redirect['tab'] = 'categorias';
            gchk_flash('ok', 'Categoria registrada.');
        } elseif ($action === 'categoria_editar') {
            $categoryId = gchk_post_int('categoria_id');
            $nombre = gchk_post_text('categoria_nombre');
            $estado = gchk_post_text('categoria_estado') ?: 'activo';
            if ($categoryId <= 0 || $nombre === '') throw new RuntimeException('Datos incompletos de la categoria.');
            if (gchk_category_locked($conn, $categoryId)) {
                throw new RuntimeException('Esta categoria pertenece a una version con checklists registrados.');
            }
            if (!in_array($estado, ['activo', 'inactivo', 'oculto'], true)) $estado = 'activo';
            $stmt = $conn->prepare("UPDATE tb_categorias_checklist SET clm_categoria_nombre = ?, clm_categorias_estado = ? WHERE clm_categoria_id = ?");
            if (!$stmt) throw new RuntimeException('No se pudo preparar la categoria.');
            $stmt->bind_param('ssi', $nombre, $estado, $categoryId);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo actualizar la categoria.');
            $stmt->close();
            $redirect['tab'] = 'categorias';
            gchk_flash('ok', 'Categoria actualizada.');
        } elseif ($action === 'item_crear') {
            if (!$schemaReady) throw new RuntimeException('Primero ejecuta el query de versionado.');
            $versionId = gchk_post_int('version_id');
            $tipoId = gchk_post_int('tipo_id');
            if (gchk_version_locked($conn, $versionId)) {
                throw new RuntimeException('Esta version ya tiene checklists registrados. Crea una nueva version.');
            }
            $categoryId = gchk_post_int('categoria_id');
            $nombre = gchk_post_text('item_nombre');
            $itemType = gchk_post_text('item_tipo') ?: 'R';
            $estado = gchk_post_text('item_estado') ?: 'activo';
            if ($versionId <= 0 || $tipoId <= 0 || $categoryId <= 0 || $nombre === '') {
                throw new RuntimeException('Datos incompletos del item.');
            }
            if (!in_array($itemType, ['R','E','Q','H','T','O','N','F','D'], true)) $itemType = 'R';
            if (!in_array($estado, ['activo', 'inactivo', 'oculto'], true)) $estado = 'activo';
            $stmt = $conn->prepare("
                INSERT INTO tb_items_checklist
                    (clm_item_id_categoria, clm_item_nombre, clm_item_estado, clm_item_idtipocheck, clm_items_tipo, clm_item_idversion)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) throw new RuntimeException('No se pudo preparar el item.');
            $stmt->bind_param('issisi', $categoryId, $nombre, $estado, $tipoId, $itemType, $versionId);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo crear el item.');
            $stmt->close();
            $redirect['tab'] = 'items';
            gchk_flash('ok', 'Item registrado.');
        } elseif ($action === 'item_editar') {
            if (!$schemaReady) throw new RuntimeException('Primero ejecuta el query de versionado.');
            $versionId = gchk_post_int('version_id');
            if (gchk_version_locked($conn, $versionId)) {
                throw new RuntimeException('Esta version ya tiene checklists registrados. Crea una nueva version.');
            }
            $itemId = gchk_post_int('item_id');
            $categoryId = gchk_post_int('categoria_id');
            $nombre = gchk_post_text('item_nombre');
            $itemType = gchk_post_text('item_tipo') ?: 'R';
            $estado = gchk_post_text('item_estado') ?: 'activo';
            if ($itemId <= 0 || $versionId <= 0 || $categoryId <= 0 || $nombre === '') {
                throw new RuntimeException('Datos incompletos del item.');
            }
            if (!in_array($itemType, ['R','E','Q','H','T','O','N','F','D'], true)) $itemType = 'R';
            if (!in_array($estado, ['activo', 'inactivo', 'oculto'], true)) $estado = 'activo';
            $stmt = $conn->prepare("
                UPDATE tb_items_checklist
                SET clm_item_id_categoria = ?,
                    clm_item_nombre = ?,
                    clm_item_estado = ?,
                    clm_items_tipo = ?
                WHERE clm_item_id = ?
                  AND clm_item_idversion = ?
            ");
            if (!$stmt) throw new RuntimeException('No se pudo preparar el item.');
            $stmt->bind_param('isssii', $categoryId, $nombre, $estado, $itemType, $itemId, $versionId);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo actualizar el item.');
            $stmt->close();
            $redirect['tab'] = 'items';
            gchk_flash('ok', 'Item actualizado.');
        }
    } catch (Throwable $e) {
        gchk_flash('error', $e->getMessage());
    }

    gchk_redirect($redirect);
}

$flash = $_SESSION['gchk_flash'] ?? null;
unset($_SESSION['gchk_flash']);

$tipos = gchk_fetch_all($conn, "SELECT clm_checktip_id, clm_checktip_nombre FROM tb_checklist_tipos ORDER BY clm_checktip_id ASC");
$selectedTypeId = isset($_GET['tipo']) ? (int)$_GET['tipo'] : (int)($tipos[0]['clm_checktip_id'] ?? 0);
$tab = $_GET['tab'] ?? 'versiones';
if (!in_array($tab, ['tipos', 'versiones', 'categorias', 'items'], true)) $tab = 'versiones';

$versions = [];
$selectedVersionId = 0;
$currentVersion = null;
$versionLocked = false;

if ($schemaReady && $selectedTypeId > 0) {
    $versions = gchk_fetch_all(
        $conn,
        "SELECT v.*,
                (SELECT COUNT(*) FROM tb_items_checklist i WHERE i.clm_item_idversion = v.clm_checkver_id) AS total_items,
                (SELECT COUNT(*) FROM tb_checklist_limpieza c WHERE c.clm_checklist_idversion = v.clm_checkver_id) AS total_checklists
         FROM tb_checklist_versiones v
         WHERE v.clm_checkver_idtipo = ?
         ORDER BY v.clm_checkver_numero DESC, v.clm_checkver_id DESC",
        'i',
        [$selectedTypeId]
    );
    $selectedVersionId = isset($_GET['version']) ? (int)$_GET['version'] : 0;
    if ($selectedVersionId <= 0 && $versions) {
        foreach ($versions as $version) {
            if ($version['clm_checkver_estado'] === 'activo') {
                $selectedVersionId = (int)$version['clm_checkver_id'];
                break;
            }
        }
        if ($selectedVersionId <= 0) $selectedVersionId = (int)$versions[0]['clm_checkver_id'];
    }
    foreach ($versions as $version) {
        if ((int)$version['clm_checkver_id'] === $selectedVersionId) {
            $currentVersion = $version;
            $versionLocked = (int)$version['total_checklists'] > 0;
            break;
        }
    }
}

$selectedTypeName = '';
foreach ($tipos as $tipo) {
    if ((int)$tipo['clm_checktip_id'] === $selectedTypeId) {
        $selectedTypeName = (string)$tipo['clm_checktip_nombre'];
        break;
    }
}

$versionCategories = [];
$allCategories = gchk_fetch_all(
    $conn,
    "SELECT clm_categoria_id, clm_categoria_nombre, clm_categorias_estado
     FROM tb_categorias_checklist
     ORDER BY clm_categorias_estado ASC, clm_categoria_nombre ASC"
);
$items = [];

if ($schemaReady && $selectedVersionId > 0) {
    $versionCategories = gchk_fetch_all(
        $conn,
        "SELECT cat.clm_categoria_id, cat.clm_categoria_nombre, cat.clm_categorias_estado,
                COUNT(i.clm_item_id) AS total_items
         FROM tb_categorias_checklist cat
         INNER JOIN tb_items_checklist i ON i.clm_item_id_categoria = cat.clm_categoria_id
         WHERE i.clm_item_idversion = ?
         GROUP BY cat.clm_categoria_id, cat.clm_categoria_nombre, cat.clm_categorias_estado
         ORDER BY cat.clm_categoria_id ASC",
        'i',
        [$selectedVersionId]
    );
    $items = gchk_fetch_all(
        $conn,
        "SELECT i.clm_item_id, i.clm_item_id_categoria, i.clm_item_nombre, i.clm_item_estado,
                i.clm_item_idtipocheck, i.clm_items_tipo, cat.clm_categoria_nombre
         FROM tb_items_checklist i
         LEFT JOIN tb_categorias_checklist cat ON cat.clm_categoria_id = i.clm_item_id_categoria
         WHERE i.clm_item_idversion = ?
         ORDER BY cat.clm_categoria_id ASC, i.clm_item_id ASC",
        'i',
        [$selectedVersionId]
    );
}

$itemTypes = [
    'R' => 'Radio C/NC/NA',
    'E' => 'Radio Empresa/Propio',
    'Q' => 'Requiere/No requiere',
    'H' => 'Fecha y hora',
    'T' => 'Texto conductor',
    'O' => 'Texto libre',
    'N' => 'Numero',
    'F' => 'Foto',
    'D' => 'Documento',
];

function gchk_status_badge(string $status): string {
    $class = $status === 'activo' ? 'is-ok' : ($status === 'borrador' ? 'is-warn' : 'is-muted');
    return "<span class=\"gchk-badge {$class}\">" . gchk_h($status) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Checklists | Norte 360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <style>
        body { background:#edf3f8; color:#10263a; font-family: 'Segoe UI', Arial, sans-serif; }
        .gchk-shell { max-width: 1560px; margin: 0 auto; padding: 26px 22px 42px; }
        .gchk-hero {
            background: linear-gradient(135deg, #123654 0%, #1d78a6 100%);
            color: #fff;
            border-radius: 8px;
            padding: 26px;
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            box-shadow: 0 18px 38px rgba(20,48,72,.18);
        }
        .gchk-kicker { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; font-weight: 800; opacity: .9; }
        .gchk-hero h1 { margin: 8px 0 4px; font-size: clamp(28px, 4vw, 42px); letter-spacing: 0; }
        .gchk-hero p { margin: 0; color: rgba(255,255,255,.82); }
        .gchk-summary { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
        .gchk-pill { background: rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.24); border-radius: 8px; padding: 10px 13px; min-width: 110px; }
        .gchk-pill span { display:block; font-size:11px; text-transform:uppercase; opacity:.75; font-weight:800; }
        .gchk-pill strong { display:block; font-size:20px; margin-top:2px; }
        .gchk-toolbar {
            margin-top: 18px;
            background:#fff;
            border:1px solid #d4e3ef;
            border-radius:8px;
            padding:16px;
            display:grid;
            grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) auto;
            gap:14px;
            align-items:end;
        }
        .gchk-field label { display:block; font-size:11px; text-transform:uppercase; font-weight:800; color:#4a6075; margin-bottom:6px; }
        .gchk-input, .gchk-select, .gchk-textarea {
            width:100%;
            border:1px solid #c9d9e7;
            border-radius:6px;
            padding:10px 11px;
            color:#10263a;
            background:#fff;
            min-height:40px;
            box-sizing:border-box;
        }
        .gchk-textarea { min-height: 42px; resize: vertical; }
        .gchk-btn {
            border:0;
            border-radius:6px;
            background:#1f8fd5;
            color:#fff;
            font-weight:800;
            padding:10px 14px;
            min-height:40px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            cursor:pointer;
            text-decoration:none;
            white-space:nowrap;
        }
        .gchk-btn:hover { background:#176fa7; color:#fff; }
        .gchk-btn.secondary { background:#eef6fc; color:#164568; border:1px solid #b9d8eb; }
        .gchk-btn.danger { background:#f9e8e8; color:#a33b34; border:1px solid #e7b8b5; }
        .gchk-btn:disabled, .gchk-input:disabled, .gchk-select:disabled, .gchk-textarea:disabled { opacity:.58; cursor:not-allowed; }
        .gchk-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:18px 0; }
        .gchk-tab {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 13px;
            border:1px solid #c9d9e7;
            background:#fff;
            color:#173b5a;
            border-radius:6px;
            font-weight:800;
            text-decoration:none;
        }
        .gchk-tab.active { background:#0f2d46; border-color:#0f2d46; color:#fff; }
        .gchk-panel {
            background:#fff;
            border:1px solid #d4e3ef;
            border-radius:8px;
            padding:18px;
            box-shadow:0 12px 26px rgba(16,38,58,.08);
            margin-bottom:18px;
        }
        .gchk-panel h2 { margin:0 0 14px; font-size:20px; }
        .gchk-form-grid { display:grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap:12px; align-items:end; }
        .gchk-form-grid .wide { grid-column: span 2; }
        .gchk-table-wrap { overflow:auto; border:1px solid #dbe7f1; border-radius:8px; }
        .gchk-table { width:100%; border-collapse:collapse; min-width:880px; }
        .gchk-table th { background:#0f2d46; color:#fff; text-align:left; padding:10px; font-size:12px; text-transform:uppercase; }
        .gchk-table td { border-top:1px solid #e4edf4; padding:9px; vertical-align:middle; }
        .gchk-table tbody tr:nth-child(even) { background:#f8fbfd; }
        .gchk-table .compact { width: 96px; }
        .gchk-badge {
            display:inline-flex;
            align-items:center;
            border-radius:999px;
            padding:5px 9px;
            font-size:12px;
            font-weight:800;
            background:#eaf1f6;
            color:#4d6070;
        }
        .gchk-badge.is-ok { background:#e6f6ed; color:#167344; }
        .gchk-badge.is-warn { background:#fff4d9; color:#8a5f00; }
        .gchk-badge.is-muted { background:#edf0f3; color:#65717c; }
        .gchk-alert {
            margin:18px 0 0;
            border-radius:8px;
            padding:12px 14px;
            font-weight:700;
            border:1px solid transparent;
        }
        .gchk-alert.ok { background:#e8f7ee; color:#17693e; border-color:#bfe8cf; }
        .gchk-alert.error { background:#fdecec; color:#9d2d27; border-color:#f2bebc; }
        .gchk-alert.warn { background:#fff6df; color:#745200; border-color:#f0d99a; }
        .gchk-empty { padding:24px; text-align:center; color:#667789; }
        .gchk-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .gchk-current { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px; color:#36536d; }
        .gchk-current strong { color:#10263a; }
        @media (max-width: 980px) {
            .gchk-hero { align-items:flex-start; flex-direction:column; }
            .gchk-summary { justify-content:flex-start; }
            .gchk-toolbar, .gchk-form-grid { grid-template-columns:1fr; }
            .gchk-form-grid .wide { grid-column: auto; }
            .gchk-shell { padding:18px 12px 32px; }
        }
    </style>
</head>
<body>
<?php n360_render_header(['title' => 'Calidad', 'subtitle' => 'Gestion de checklists']); ?>
<?php n360_render_sidebar(); ?>
<main class="main-content n360-main n360-main--module" role="main">
    <div class="gchk-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="gchk-hero">
            <div>
                <div class="gchk-kicker"><i class="bi bi-clipboard2-check-fill"></i> Calidad</div>
                <h1>Gestion de Checklists</h1>
                <p>Tipos, versiones, categorias e items operativos.</p>
            </div>
            <div class="gchk-summary">
                <div class="gchk-pill"><span>Tipos</span><strong><?= count($tipos) ?></strong></div>
                <div class="gchk-pill"><span>Versiones</span><strong><?= count($versions) ?></strong></div>
                <div class="gchk-pill"><span>Items</span><strong><?= count($items) ?></strong></div>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="gchk-alert <?= gchk_h($flash['type']) ?>"><?= gchk_h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="gchk-alert warn">
                Falta ejecutar el query de versionado para habilitar versiones e items por version.
            </div>
        <?php endif; ?>

        <section class="gchk-toolbar">
            <div class="gchk-field">
                <label for="tipoFiltro">Tipo checklist</label>
                <select id="tipoFiltro" class="gchk-select">
                    <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int)$tipo['clm_checktip_id'] ?>" <?= (int)$tipo['clm_checktip_id'] === $selectedTypeId ? 'selected' : '' ?>>
                            <?= gchk_h($tipo['clm_checktip_nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gchk-field">
                <label for="versionFiltro">Version</label>
                <select id="versionFiltro" class="gchk-select" <?= !$schemaReady ? 'disabled' : '' ?>>
                    <?php if (!$versions): ?>
                        <option value="">Sin versiones</option>
                    <?php endif; ?>
                    <?php foreach ($versions as $version): ?>
                        <option value="<?= (int)$version['clm_checkver_id'] ?>" <?= (int)$version['clm_checkver_id'] === $selectedVersionId ? 'selected' : '' ?>>
                            v<?= (int)$version['clm_checkver_numero'] ?> - <?= gchk_h($version['clm_checkver_nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="gchk-btn" onclick="gchkGo()"><i class="bi bi-funnel"></i> Ver</button>
        </section>

        <nav class="gchk-tabs" aria-label="Secciones de gestion">
            <?php
            $tabBase = 'gestionar_checklists.php?tipo=' . $selectedTypeId . '&version=' . $selectedVersionId . '&tab=';
            $tabs = [
                'tipos' => ['Tipos', 'bi-diagram-3-fill'],
                'versiones' => ['Versiones', 'bi-layers-fill'],
                'categorias' => ['Categorias', 'bi-tags-fill'],
                'items' => ['Items', 'bi-list-check'],
            ];
            foreach ($tabs as $key => $info):
            ?>
                <a class="gchk-tab <?= $tab === $key ? 'active' : '' ?>" href="<?= $tabBase . $key ?>">
                    <i class="bi <?= $info[1] ?>"></i> <?= $info[0] ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'tipos'): ?>
            <section class="gchk-panel">
                <h2>Tipos de checklist</h2>
                <form method="POST" class="gchk-form-grid">
                    <input type="hidden" name="accion" value="tipo_crear">
                    <input type="hidden" name="tab" value="tipos">
                    <div class="gchk-field wide">
                        <label>Nuevo tipo</label>
                        <input class="gchk-input" name="tipo_nombre" required placeholder="Nombre del checklist">
                    </div>
                    <button class="gchk-btn" type="submit"><i class="bi bi-plus-circle"></i> Crear tipo</button>
                </form>
            </section>

            <section class="gchk-panel">
                <div class="gchk-table-wrap">
                    <table class="gchk-table">
                        <thead>
                            <tr><th class="compact">ID</th><th>Nombre</th><th class="compact">Accion</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tipos as $tipo): $formId = 'tipoForm' . (int)$tipo['clm_checktip_id']; ?>
                            <tr>
                                <td><?= (int)$tipo['clm_checktip_id'] ?></td>
                                <td>
                                    <form id="<?= $formId ?>" method="POST"></form>
                                    <input form="<?= $formId ?>" type="hidden" name="accion" value="tipo_editar">
                                    <input form="<?= $formId ?>" type="hidden" name="tab" value="tipos">
                                    <input form="<?= $formId ?>" type="hidden" name="tipo_id" value="<?= (int)$tipo['clm_checktip_id'] ?>">
                                    <input form="<?= $formId ?>" class="gchk-input" name="tipo_nombre" value="<?= gchk_h($tipo['clm_checktip_nombre']) ?>" required>
                                </td>
                                <td><button form="<?= $formId ?>" class="gchk-btn secondary" type="submit"><i class="bi bi-save"></i> Guardar</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($tab === 'versiones'): ?>
            <section class="gchk-panel">
                <h2>Nueva version de <?= gchk_h($selectedTypeName) ?></h2>
                <form method="POST" class="gchk-form-grid">
                    <input type="hidden" name="accion" value="version_crear">
                    <input type="hidden" name="tab" value="versiones">
                    <input type="hidden" name="tipo_id" value="<?= $selectedTypeId ?>">
                    <div class="gchk-field">
                        <label>Numero</label>
                        <input class="gchk-input" type="number" min="1" name="version_numero" value="<?= $schemaReady ? gchk_next_version_number($conn, $selectedTypeId) : '' ?>" <?= !$schemaReady ? 'disabled' : '' ?>>
                    </div>
                    <div class="gchk-field wide">
                        <label>Nombre</label>
                        <input class="gchk-input" name="version_nombre" placeholder="Nombre de version" <?= !$schemaReady ? 'disabled' : '' ?>>
                    </div>
                    <div class="gchk-field">
                        <label>Estado</label>
                        <select class="gchk-select" name="version_estado" <?= !$schemaReady ? 'disabled' : '' ?>>
                            <option value="borrador">borrador</option>
                            <option value="activo">activo</option>
                            <option value="cerrado">cerrado</option>
                        </select>
                    </div>
                    <div class="gchk-field">
                        <label>Desde</label>
                        <input class="gchk-input" type="date" name="version_desde" <?= !$schemaReady ? 'disabled' : '' ?>>
                    </div>
                    <div class="gchk-field">
                        <label>Hasta</label>
                        <input class="gchk-input" type="date" name="version_hasta" <?= !$schemaReady ? 'disabled' : '' ?>>
                    </div>
                    <div class="gchk-field">
                        <label>Clonar de</label>
                        <select class="gchk-select" name="clonar_version_id" <?= !$schemaReady ? 'disabled' : '' ?>>
                            <option value="0">Sin clonar</option>
                            <?php foreach ($versions as $version): ?>
                                <option value="<?= (int)$version['clm_checkver_id'] ?>">
                                    v<?= (int)$version['clm_checkver_numero'] ?> - <?= gchk_h($version['clm_checkver_nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gchk-field wide">
                        <label>Observacion</label>
                        <input class="gchk-input" name="version_observacion" <?= !$schemaReady ? 'disabled' : '' ?>>
                    </div>
                    <button class="gchk-btn" type="submit" <?= !$schemaReady ? 'disabled' : '' ?>><i class="bi bi-plus-circle"></i> Crear version</button>
                </form>
            </section>

            <section class="gchk-panel">
                <h2>Versiones registradas</h2>
                <div class="gchk-table-wrap">
                    <table class="gchk-table">
                        <thead>
                            <tr>
                                <th class="compact">Version</th><th>Nombre</th><th>Estado</th><th>Desde</th><th>Hasta</th><th>Items</th><th>Usos</th><th>Observacion</th><th>Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$versions): ?>
                            <tr><td colspan="9" class="gchk-empty">No hay versiones registradas.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($versions as $version): $formId = 'verForm' . (int)$version['clm_checkver_id']; ?>
                            <tr>
                                <td>
                                    <form id="<?= $formId ?>" method="POST"></form>
                                    <input form="<?= $formId ?>" type="hidden" name="accion" value="version_editar">
                                    <input form="<?= $formId ?>" type="hidden" name="tab" value="versiones">
                                    <input form="<?= $formId ?>" type="hidden" name="tipo_id" value="<?= $selectedTypeId ?>">
                                    <input form="<?= $formId ?>" type="hidden" name="version_id" value="<?= (int)$version['clm_checkver_id'] ?>">
                                    <input form="<?= $formId ?>" class="gchk-input" type="number" min="1" name="version_numero" value="<?= (int)$version['clm_checkver_numero'] ?>">
                                </td>
                                <td><input form="<?= $formId ?>" class="gchk-input" name="version_nombre" value="<?= gchk_h($version['clm_checkver_nombre']) ?>"></td>
                                <td>
                                    <select form="<?= $formId ?>" class="gchk-select" name="version_estado">
                                        <?php foreach (['borrador','activo','cerrado'] as $state): ?>
                                            <option value="<?= $state ?>" <?= $version['clm_checkver_estado'] === $state ? 'selected' : '' ?>><?= $state ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input form="<?= $formId ?>" class="gchk-input" type="date" name="version_desde" value="<?= gchk_h($version['clm_checkver_desde']) ?>"></td>
                                <td><input form="<?= $formId ?>" class="gchk-input" type="date" name="version_hasta" value="<?= gchk_h($version['clm_checkver_hasta']) ?>"></td>
                                <td><?= (int)$version['total_items'] ?></td>
                                <td><?= (int)$version['total_checklists'] ?></td>
                                <td><input form="<?= $formId ?>" class="gchk-input" name="version_observacion" value="<?= gchk_h($version['clm_checkver_observacion']) ?>"></td>
                                <td class="gchk-actions">
                                    <button form="<?= $formId ?>" class="gchk-btn secondary" type="submit"><i class="bi bi-save"></i> Guardar</button>
                                    <a class="gchk-btn secondary" href="gestionar_checklists.php?tipo=<?= $selectedTypeId ?>&version=<?= (int)$version['clm_checkver_id'] ?>&tab=items"><i class="bi bi-list-check"></i> Items</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($tab === 'categorias'): ?>
            <section class="gchk-panel">
                <div class="gchk-current">
                    <strong><?= gchk_h($selectedTypeName) ?></strong>
                    <?php if ($currentVersion): ?>
                        <?= gchk_status_badge((string)$currentVersion['clm_checkver_estado']) ?>
                        <span>v<?= (int)$currentVersion['clm_checkver_numero'] ?> - <?= gchk_h($currentVersion['clm_checkver_nombre']) ?></span>
                    <?php endif; ?>
                </div>
                <form method="POST" class="gchk-form-grid">
                    <input type="hidden" name="accion" value="categoria_crear">
                    <input type="hidden" name="tab" value="categorias">
                    <input type="hidden" name="tipo_id" value="<?= $selectedTypeId ?>">
                    <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                    <div class="gchk-field wide">
                        <label>Nueva categoria</label>
                        <input class="gchk-input" name="categoria_nombre" required>
                    </div>
                    <button class="gchk-btn" type="submit"><i class="bi bi-plus-circle"></i> Crear categoria</button>
                </form>
            </section>

            <section class="gchk-panel">
                <h2>Categorias usadas por la version</h2>
                <div class="gchk-table-wrap">
                    <table class="gchk-table">
                        <thead><tr><th class="compact">ID</th><th>Categoria</th><th>Estado</th><th>Items</th><th>Accion</th></tr></thead>
                        <tbody>
                        <?php if (!$versionCategories): ?>
                            <tr><td colspan="5" class="gchk-empty">Esta version todavia no tiene categorias con items.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($versionCategories as $cat): $formId = 'catForm' . (int)$cat['clm_categoria_id']; $locked = gchk_category_locked($conn, (int)$cat['clm_categoria_id']); ?>
                            <tr>
                                <td><?= (int)$cat['clm_categoria_id'] ?></td>
                                <td>
                                    <form id="<?= $formId ?>" method="POST"></form>
                                    <input form="<?= $formId ?>" type="hidden" name="accion" value="categoria_editar">
                                    <input form="<?= $formId ?>" type="hidden" name="tab" value="categorias">
                                    <input form="<?= $formId ?>" type="hidden" name="tipo_id" value="<?= $selectedTypeId ?>">
                                    <input form="<?= $formId ?>" type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                                    <input form="<?= $formId ?>" type="hidden" name="categoria_id" value="<?= (int)$cat['clm_categoria_id'] ?>">
                                    <input form="<?= $formId ?>" class="gchk-input" name="categoria_nombre" value="<?= gchk_h($cat['clm_categoria_nombre']) ?>" <?= $locked ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <select form="<?= $formId ?>" class="gchk-select" name="categoria_estado" <?= $locked ? 'disabled' : '' ?>>
                                        <?php foreach (['activo','inactivo','oculto'] as $state): ?>
                                            <option value="<?= $state ?>" <?= $cat['clm_categorias_estado'] === $state ? 'selected' : '' ?>><?= $state ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><?= (int)$cat['total_items'] ?></td>
                                <td>
                                    <button form="<?= $formId ?>" class="gchk-btn secondary" type="submit" <?= $locked ? 'disabled' : '' ?>>
                                        <i class="bi bi-save"></i> Guardar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="gchk-panel">
                <div class="gchk-current">
                    <strong><?= gchk_h($selectedTypeName) ?></strong>
                    <?php if ($currentVersion): ?>
                        <?= gchk_status_badge((string)$currentVersion['clm_checkver_estado']) ?>
                        <span>v<?= (int)$currentVersion['clm_checkver_numero'] ?> - <?= gchk_h($currentVersion['clm_checkver_nombre']) ?></span>
                    <?php endif; ?>
                    <?php if ($versionLocked): ?>
                        <span class="gchk-badge is-warn">bloqueada por uso</span>
                    <?php endif; ?>
                </div>
                <form method="POST" class="gchk-form-grid">
                    <input type="hidden" name="accion" value="item_crear">
                    <input type="hidden" name="tab" value="items">
                    <input type="hidden" name="tipo_id" value="<?= $selectedTypeId ?>">
                    <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                    <div class="gchk-field wide">
                        <label>Nuevo item</label>
                        <input class="gchk-input" name="item_nombre" required <?= (!$schemaReady || !$selectedVersionId || $versionLocked) ? 'disabled' : '' ?>>
                    </div>
                    <div class="gchk-field">
                        <label>Categoria</label>
                        <select class="gchk-select" name="categoria_id" required <?= (!$schemaReady || !$selectedVersionId || $versionLocked) ? 'disabled' : '' ?>>
                            <option value="">Seleccionar</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?= (int)$cat['clm_categoria_id'] ?>">
                                    <?= gchk_h($cat['clm_categoria_nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gchk-field">
                        <label>Tipo dato</label>
                        <select class="gchk-select" name="item_tipo" <?= (!$schemaReady || !$selectedVersionId || $versionLocked) ? 'disabled' : '' ?>>
                            <?php foreach ($itemTypes as $key => $label): ?>
                                <option value="<?= $key ?>"><?= gchk_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gchk-field">
                        <label>Estado</label>
                        <select class="gchk-select" name="item_estado" <?= (!$schemaReady || !$selectedVersionId || $versionLocked) ? 'disabled' : '' ?>>
                            <option value="activo">activo</option>
                            <option value="inactivo">inactivo</option>
                            <option value="oculto">oculto</option>
                        </select>
                    </div>
                    <button class="gchk-btn" type="submit" <?= (!$schemaReady || !$selectedVersionId || $versionLocked) ? 'disabled' : '' ?>>
                        <i class="bi bi-plus-circle"></i> Crear item
                    </button>
                </form>
            </section>

            <section class="gchk-panel">
                <h2>Items de la version</h2>
                <div class="gchk-table-wrap">
                    <table class="gchk-table">
                        <thead><tr><th class="compact">ID</th><th>Item</th><th>Categoria</th><th>Tipo</th><th>Estado</th><th>Accion</th></tr></thead>
                        <tbody>
                        <?php if (!$items): ?>
                            <tr><td colspan="6" class="gchk-empty">No hay items para esta version.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($items as $item): $formId = 'itemForm' . (int)$item['clm_item_id']; ?>
                            <tr>
                                <td><?= (int)$item['clm_item_id'] ?></td>
                                <td>
                                    <form id="<?= $formId ?>" method="POST"></form>
                                    <input form="<?= $formId ?>" type="hidden" name="accion" value="item_editar">
                                    <input form="<?= $formId ?>" type="hidden" name="tab" value="items">
                                    <input form="<?= $formId ?>" type="hidden" name="tipo_id" value="<?= $selectedTypeId ?>">
                                    <input form="<?= $formId ?>" type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                                    <input form="<?= $formId ?>" type="hidden" name="item_id" value="<?= (int)$item['clm_item_id'] ?>">
                                    <input form="<?= $formId ?>" class="gchk-input" name="item_nombre" value="<?= gchk_h($item['clm_item_nombre']) ?>" <?= $versionLocked ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <select form="<?= $formId ?>" class="gchk-select" name="categoria_id" <?= $versionLocked ? 'disabled' : '' ?>>
                                        <?php foreach ($allCategories as $cat): ?>
                                            <option value="<?= (int)$cat['clm_categoria_id'] ?>" <?= (int)$cat['clm_categoria_id'] === (int)$item['clm_item_id_categoria'] ? 'selected' : '' ?>>
                                                <?= gchk_h($cat['clm_categoria_nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select form="<?= $formId ?>" class="gchk-select" name="item_tipo" <?= $versionLocked ? 'disabled' : '' ?>>
                                        <?php foreach ($itemTypes as $key => $label): ?>
                                            <option value="<?= $key ?>" <?= $item['clm_items_tipo'] === $key ? 'selected' : '' ?>><?= gchk_h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select form="<?= $formId ?>" class="gchk-select" name="item_estado" <?= $versionLocked ? 'disabled' : '' ?>>
                                        <?php foreach (['activo','inactivo','oculto'] as $state): ?>
                                            <option value="<?= $state ?>" <?= $item['clm_item_estado'] === $state ? 'selected' : '' ?>><?= $state ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button form="<?= $formId ?>" class="gchk-btn secondary" type="submit" <?= $versionLocked ? 'disabled' : '' ?>>
                                        <i class="bi bi-save"></i> Guardar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php n360_render_footer(); ?>
<script>
function gchkGo() {
    const tipo = document.getElementById('tipoFiltro').value || '';
    const version = document.getElementById('versionFiltro').value || '';
    const tab = <?= json_encode($tab) ?>;
    const query = new URLSearchParams({tipo, version, tab});
    window.location.href = 'gestionar_checklists.php?' + query.toString();
}
document.getElementById('tipoFiltro').addEventListener('change', function () {
    const tab = <?= json_encode($tab) ?>;
    window.location.href = 'gestionar_checklists.php?tipo=' + encodeURIComponent(this.value) + '&tab=' + encodeURIComponent(tab);
});
</script>
</body>
</html>
