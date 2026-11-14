<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';
require_once __DIR__ . '/../01_almacen/movimiento_backend.php';
require_once __DIR__ . '/_espacios_catalogo.php';
require_once __DIR__ . '/_espacios_personal.php';
require_once __DIR__ . '/../layout/espacios_personal_n360.php';
$conn->set_charset('utf8mb4');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) { header('Allow: GET, HEAD, POST'); http_response_code(405); exit; }

if (empty($_SESSION['sede_espacios_csrf'])) $_SESSION['sede_espacios_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['sede_espacios_csrf'];
$ready = alm_activos_espacios_disponibles($conn);
$classReady = $ready && alm_espacios_clasificacion_disponible($conn);
$pavilionReady = $ready && alm_espacios_pabellon_disponible($conn);
$personnelReady = $ready && n360_espper_schema($conn)['ready'];
$clasificaciones = alm_espacios_clasificaciones($conn);
$error = '';
$success = (string)($_SESSION['sede_espacios_flash'] ?? '');
unset($_SESSION['sede_espacios_flash']);
$values = ['sede_id' => '', 'pabellon' => '', 'area' => '', 'espacio' => '', 'clasificacion_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            throw new RuntimeException('La sesión del formulario venció. Actualiza la página.', 419);
        }
        if (!$ready) throw new RuntimeException('La gestión de espacios aún no está habilitada.', 409);
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            foreach ($values as $key => $_) {
                if (!is_string($_POST[$key] ?? '')) throw new InvalidArgumentException('Revisa los datos del formulario.');
                $values[$key] = alm_activos_texto($_POST[$key] ?? '');
            }
            $sedeId = alm_activos_espacio_id($values['sede_id']);
            $area = preg_replace('/\s+/u', ' ', $values['area']);
            $space = preg_replace('/\s+/u', ' ', $values['espacio']);
            $pavilion = preg_replace('/\s+/u', ' ', $values['pabellon']);
            if (!$pavilionReady && $pavilion !== '') throw new RuntimeException('Los pabellones aún no están habilitados.', 409);
            if (alm_activos_longitud($pavilion) > 100) throw new InvalidArgumentException('El pabellón admite hasta 100 caracteres.');
            $classificationId = alm_activos_espacio_id($values['clasificacion_id']);
            alm_espacios_validar_clasificacion($conn, $classificationId);
            if (!$sedeId || $area === '') throw new InvalidArgumentException('Selecciona la sede e indica el nombre del área.');
            if (alm_activos_longitud($area) > 150 || alm_activos_longitud($space) > 150) {
                throw new InvalidArgumentException('Área y espacio admiten hasta 150 caracteres cada uno.');
            }
            $conn->begin_transaction();
            // Serializa las altas de una misma sede para evitar duplicados concurrentes.
            $sedeRow = alm_fetch_one($conn, 'SELECT clm_sedes_id FROM tb_sedes WHERE clm_sedes_id = ? FOR UPDATE', 'i', [$sedeId]);
            if (!$sedeRow) throw new InvalidArgumentException('La sede seleccionada no existe.');
            $duplicates = alm_fetch_all($conn, 'SELECT clm_sedeesp_id, ' . ($pavilionReady ? 'clm_sedeesp_pabellon' : 'NULL') . " AS pabellon FROM tb_sedes_espacios
                WHERE clm_sedeesp_idsede = ? AND clm_sedeesp_area = ? AND COALESCE(clm_sedeesp_espacio, '') = ?", 'iss', [$sedeId, $area, $space]);
            foreach ($duplicates as $duplicate) {
                if ($pavilion === '' || !$duplicate['pabellon'] || n360_espacios_key($duplicate['pabellon']) === n360_espacios_key($pavilion)) {
                    throw new RuntimeException('El área y espacio ya existen en la sede. Revisa su pabellón o activa el registro existente.', 409);
                }
            }
            // Referencias reservadas para una futura actualización de personal.
            // Esta interfaz no las lee del POST ni modifica valores existentes.
            $params = [$sedeId, $area, $space !== '' ? $space : null];
            $pavilionColumn = $pavilionReady ? ', clm_sedeesp_pabellon' : '';
            $pavilionPlaceholder = $pavilionReady ? ', ?' : '';
            if ($pavilionReady) $params[] = $pavilion !== '' ? $pavilion : null;
            $classColumn = $classReady ? ', clm_sedeesp_idespacio' : '';
            $classPlaceholder = $classReady ? ', ?' : '';
            if ($classReady) $params[] = $classificationId;
            $stmt = $conn->prepare('INSERT INTO tb_sedes_espacios
                (clm_sedeesp_idsede, clm_sedeesp_area, clm_sedeesp_espacio' . $pavilionColumn . $classColumn . ')
                VALUES (?, ?, ?' . $pavilionPlaceholder . $classPlaceholder . ')');
            alm_bind($stmt, 'iss' . ($pavilionReady ? 's' : '') . ($classReady ? 'i' : ''), $params);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo crear el espacio.');
            $stmt->close();
            $conn->commit();
            $_SESSION['sede_espacios_flash'] = 'Espacio creado correctamente.';
        } elseif ($action === 'catalogo') {
            if (!is_string($_POST['revision'] ?? null)) throw new InvalidArgumentException('Revisa primero la propuesta del catálogo.');
            [$created, $kept] = n360_espacios_catalogo_cargar($conn, $_POST['revision']);
            $_SESSION['sede_espacios_flash'] = "$created espacios creados. $kept coincidencias conservadas. Revisa los espacios inactivos y los pabellones pendientes.";
        } elseif ($action === 'pabellon') {
            if (!$pavilionReady) throw new RuntimeException('Los pabellones aún no están habilitados.', 409);
            $id = alm_activos_espacio_id($_POST['id'] ?? null);
            if (!$id || !is_string($_POST['pabellon'] ?? null)) throw new InvalidArgumentException('Selecciona un espacio e indica el pabellón.');
            $pavilion = preg_replace('/\s+/u', ' ', trim($_POST['pabellon']));
            if ($pavilion === '' || alm_activos_longitud($pavilion) > 100) throw new InvalidArgumentException('Indica un pabellón de hasta 100 caracteres.');
            $conn->begin_transaction();
            $location = alm_fetch_one($conn, 'SELECT clm_sedeesp_idsede AS sede_id FROM tb_sedes_espacios WHERE clm_sedeesp_id = ?', 'i', [$id]);
            if (!$location) throw new InvalidArgumentException('El espacio ya no existe.');
            alm_fetch_one($conn, 'SELECT clm_sedes_id FROM tb_sedes WHERE clm_sedes_id = ? FOR UPDATE', 'i', [(int)$location['sede_id']]);
            $row = alm_fetch_one($conn, 'SELECT clm_sedeesp_pabellon AS pabellon, clm_sedeesp_area AS area, clm_sedeesp_espacio AS espacio FROM tb_sedes_espacios WHERE clm_sedeesp_id = ? FOR UPDATE', 'i', [$id]);
            if (!$row || trim((string)$row['pabellon']) !== '') throw new RuntimeException('Este espacio ya tiene pabellón. Actualiza la página para revisar su ubicación.', 409);
            $duplicates = alm_fetch_all($conn, "SELECT clm_sedeesp_pabellon AS pabellon FROM tb_sedes_espacios WHERE clm_sedeesp_idsede = ? AND clm_sedeesp_id <> ? AND clm_sedeesp_area = ? AND COALESCE(clm_sedeesp_espacio, '') = ?", 'iiss', [(int)$location['sede_id'], $id, $row['area'], $row['espacio'] ?? '']);
            foreach ($duplicates as $duplicate) {
                if (!$duplicate['pabellon'] || n360_espacios_key($duplicate['pabellon']) === n360_espacios_key($pavilion)) throw new RuntimeException('Ya hay una ubicación coincidente. Revisa los espacios antes de definir el pabellón.', 409);
            }
            $stmt = $conn->prepare('UPDATE tb_sedes_espacios SET clm_sedeesp_pabellon = ? WHERE clm_sedeesp_id = ?');
            $params = [$pavilion, $id]; alm_bind($stmt, 'si', $params);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo definir el pabellón.');
            $stmt->close(); $conn->commit();
            $_SESSION['sede_espacios_flash'] = 'Pabellón definido. Las asignaciones y el historial se conservan.';
        } elseif ($action === 'state') {
            $id = alm_activos_espacio_id($_POST['id'] ?? null);
            $expected = filter_var($_POST['expected_estado'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !in_array($expected, [0, 1], true)) throw new InvalidArgumentException('Selecciona un espacio válido.');
            $conn->begin_transaction();
            $row = alm_fetch_one($conn, 'SELECT clm_sedeesp_estado AS estado FROM tb_sedes_espacios WHERE clm_sedeesp_id = ? FOR UPDATE', 'i', [$id]);
            if (!$row || (int)$row['estado'] !== $expected) throw new RuntimeException('El estado cambió. Actualiza la página antes de reintentar.', 409);
            $params = [1 - $expected, $id];
            $stmt = $conn->prepare('UPDATE tb_sedes_espacios SET clm_sedeesp_estado = ? WHERE clm_sedeesp_id = ?');
            alm_bind($stmt, 'ii', $params);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo actualizar el estado.');
            $stmt->close();
            $conn->commit();
            $_SESSION['sede_espacios_flash'] = $expected ? 'Espacio desactivado. Sus asignaciones e historial se conservan.' : 'Espacio activado correctamente.';
        } elseif ($action === 'classify') {
            if (!$classReady) throw new RuntimeException('La clasificación de espacios aún no está habilitada.', 409);
            $id = alm_activos_espacio_id($_POST['id'] ?? null);
            $classificationId = alm_activos_espacio_id($_POST['clasificacion_id'] ?? null);
            $expected = alm_activos_espacio_id($_POST['expected_clasificacion_id'] ?? null);
            if (!$id || !array_key_exists('expected_clasificacion_id', $_POST)) throw new InvalidArgumentException('Selecciona una ubicación válida.');
            alm_espacios_validar_clasificacion($conn, $classificationId);
            $conn->begin_transaction();
            $row = alm_fetch_one($conn, 'SELECT clm_sedeesp_idespacio AS clasificacion_id FROM tb_sedes_espacios WHERE clm_sedeesp_id = ? FOR UPDATE', 'i', [$id]);
            if (!$row) throw new InvalidArgumentException('El espacio ya no existe.');
            $current = $row['clasificacion_id'] === null ? null : (int)$row['clasificacion_id'];
            if ($current !== $expected) throw new RuntimeException('La clasificación cambió. Revisa el valor actual antes de guardar.', 409);
            $params = [$classificationId, $id];
            $stmt = $conn->prepare('UPDATE tb_sedes_espacios SET clm_sedeesp_idespacio = ? WHERE clm_sedeesp_id = ?');
            alm_bind($stmt, 'ii', $params);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo guardar la clasificación.');
            $stmt->close();
            $conn->commit();
            $_SESSION['sede_espacios_flash'] = 'Clasificación actualizada correctamente.';
        } else {
            throw new InvalidArgumentException('Operación inválida.');
        }
        header('Location: espacios.php' . ($action === 'catalogo' ? '?estado=todos' : ''));
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $known = $e instanceof InvalidArgumentException || in_array($e->getCode(), [409, 419], true);
        if (!$known) error_log('espacios_sedes.php: ' . $e->getMessage());
        $error = $known ? $e->getMessage() : 'No se pudo guardar el espacio. Inténtalo nuevamente.';
        http_response_code($known ? ($e instanceof InvalidArgumentException ? 422 : (int)$e->getCode()) : 500);
    }
}

$sedes = alm_fetch_all($conn, 'SELECT clm_sedes_id AS id, clm_sedes_name AS nombre FROM tb_sedes ORDER BY clm_sedes_name');
$filterSede = max(0, (int)(is_string($_GET['sede'] ?? null) ? $_GET['sede'] : 0));
$filterState = is_string($_GET['estado'] ?? null) && in_array($_GET['estado'], ['0', '1', 'todos'], true) ? $_GET['estado'] : '1';
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filterPavilion = is_string($_GET['pabellon'] ?? null) ? trim($_GET['pabellon']) : '';
$pavilionExpr = $pavilionReady ? 'e.clm_sedeesp_pabellon' : 'NULL';
$pavilions = $pavilionReady ? alm_fetch_all($conn, "SELECT DISTINCT clm_sedeesp_pabellon AS nombre FROM tb_sedes_espacios WHERE NULLIF(TRIM(clm_sedeesp_pabellon), '') IS NOT NULL ORDER BY clm_sedeesp_pabellon") : [];
$filterClass = max(-1, (int)(is_string($_GET['clasificacion'] ?? null) ? $_GET['clasificacion'] : 0));
$classExpr = $classReady ? 'e.clm_sedeesp_idespacio' : 'NULL';
$className = $classReady ? "CONCAT_WS(' - ', NULLIF(TRIM(ce.clm_esp_nombre), ''), NULLIF(TRIM(ce.clm_esp_desc), ''))" : "''";
$classJoin = $classReady ? 'LEFT JOIN tb_espacio ce ON ce.clm_esp_id = e.clm_sedeesp_idespacio' : '';
$rows = [];
$areas = [];
if ($ready) {
    $areas = alm_fetch_all($conn, 'SELECT DISTINCT clm_sedeesp_idsede AS sede_id, clm_sedeesp_area AS area
        FROM tb_sedes_espacios ORDER BY clm_sedeesp_area');
    $where = ['1 = 1']; $types = ''; $params = [];
    if ($filterSede) { $where[] = 'e.clm_sedeesp_idsede = ?'; $types .= 'i'; $params[] = $filterSede; }
    if ($filterState !== 'todos') { $where[] = 'e.clm_sedeesp_estado = ?'; $types .= 'i'; $params[] = (int)$filterState; }
    if ($pavilionReady && $filterPavilion === '__sin_definir__') $where[] = "NULLIF(TRIM($pavilionExpr), '') IS NULL";
    elseif ($pavilionReady && $filterPavilion !== '') { $where[] = "$pavilionExpr = ?"; $types .= 's'; $params[] = $filterPavilion; }
    if ($classReady && $filterClass > 0) { $where[] = "$classExpr = ?"; $types .= 'i'; $params[] = $filterClass; }
    if ($classReady && $filterClass === -1) $where[] = "$classExpr IS NULL";
    if ($query !== '') {
        $where[] = "CONCAT_WS(' ', s.clm_sedes_name, $pavilionExpr, e.clm_sedeesp_area, e.clm_sedeesp_espacio) LIKE CONCAT('%', ?, '%')";
        $types .= 's'; $params[] = $query;
    }
    $rows = alm_fetch_all($conn, 'SELECT e.clm_sedeesp_id, e.clm_sedeesp_area, e.clm_sedeesp_espacio,
        e.clm_sedeesp_estado, ' . $pavilionExpr . ' AS pabellon, s.clm_sedes_name AS sede_nombre, ' . $classExpr . ' AS clasificacion_id, ' . $className . ' AS clasificacion_nombre FROM tb_sedes_espacios e
        JOIN tb_sedes s ON s.clm_sedes_id = e.clm_sedeesp_idsede ' . $classJoin . ' WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.clm_sedes_name, pabellon, e.clm_sedeesp_area, e.clm_sedeesp_espacio, e.clm_sedeesp_id LIMIT 1000', $types, $params);
}
$personnelSummary = $personnelReady ? n360_espper_resumen($conn, array_column($rows, 'clm_sedeesp_id')) : [];
unset($_SESSION['sede_espacios_personal_catalogo']);
if ($personnelReady) {
    try { n360_espper_catalogo_cargar($conn); }
    catch (Throwable $e) { error_log('espacios_sedes.php catálogo de personal: ' . $e->getMessage()); }
}
$catalogPlan = null;
$catalogAvailable = false;
if ($pavilionReady) {
    try {
        $initialPlan = n360_espacios_catalogo_estado($conn);
        $catalogAvailable = n360_espacios_catalogo_pendientes($initialPlan) > 0;
        if ($catalogAvailable && ($_GET['catalogo'] ?? '') === '1') $catalogPlan = $initialPlan;
    } catch (Throwable $e) {
        if ($e->getCode() !== 409) error_log('espacios_sedes.php catálogo inicial: ' . $e->getMessage());
        if (($_GET['catalogo'] ?? '') === '1') $error = $e->getCode() === 409 ? $e->getMessage() : 'No se pudo revisar el catálogo inicial.';
    }
}
$operational = alm_fetch_all($conn, 'SELECT clm_esp_id AS id, clm_esp_nombre AS codigo, clm_esp_desc AS nombre, clm_esp_obs AS naturaleza FROM tb_espacio ORDER BY clm_esp_id');
$createError = $ready && $error !== '' && ($_POST['action'] ?? '') === 'create';
$classifyError = $classReady && $error !== '' && ($_POST['action'] ?? '') === 'classify';
$classifyRow = null;
if ($classifyError && filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT)) {
    $classifyRow = alm_fetch_one($conn, 'SELECT e.clm_sedeesp_id AS id, e.clm_sedeesp_idespacio AS clasificacion_id,
        CONCAT_WS(" - ", s.clm_sedes_name, e.clm_sedeesp_area, e.clm_sedeesp_espacio) AS nombre
        FROM tb_sedes_espacios e JOIN tb_sedes s ON s.clm_sedes_id = e.clm_sedeesp_idsede WHERE e.clm_sedeesp_id = ?', 'i', [(int)$_POST['id']]);
}
n360_admin_render_head('Espacios');
?>
<link rel="stylesheet" href="<?= n360_asset('assets/css/admin_espacios_sedes_n360.css?espacios=5') ?>">
<link rel="stylesheet" href="<?= n360_asset('assets/css/admin_espacios_personal_n360.css?personal=1') ?>">
<?php n360_render_header(['title' => 'Espacios', 'subtitle' => 'Administración']); ?>
<?php n360_render_sidebar(); ?>
<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
<div class="n360-main__inner admin-cat-shell sedeesp-page">
    <?php n360_render_content_separator('top'); ?>
    <section class="admin-cat-hero"><div>
        <span class="admin-cat-kicker"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Administración · Maestros</span>
        <h1>Espacios</h1><p>Organiza cada sede por pabellón, área y puesto. Una misma ubicación puede recibir artículos de distintas gestiones.</p>
    </div></section>
    <?php if ($success !== ''): ?><div class="admin-cat-note sedeesp-success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= n360_admin_h($success) ?></div><?php endif; ?>
    <?php if ($error !== '' && !$createError && !$classifyRow): ?><div class="admin-cat-note sedeesp-error" role="alert"><?= n360_admin_h($error) ?></div><?php endif; ?>
    <?php if (!$ready): ?><div class="admin-cat-note" role="status">La gestión de espacios por sede aún no está habilitada. Contacta al administrador.</div><?php endif; ?>
    <?php if ($ready && !$classReady): ?><div class="admin-cat-note" role="status">La clasificación de ubicaciones aún no está habilitada. Puedes continuar gestionando los espacios existentes.</div><?php endif; ?>
    <?php if ($ready && !$pavilionReady): ?><div class="admin-cat-note" role="status">Los pabellones y el catálogo inicial requieren la actualización de espacios. Puedes seguir usando las ubicaciones actuales.</div><?php endif; ?>
    <?php if ($ready && !$personnelReady): ?><div class="admin-cat-note" role="status"><i class="bi bi-people" aria-hidden="true"></i> La asignación de personal por turnos está pendiente de habilitación.</div><?php endif; ?>
    <?php if ($catalogPlan): ?>
    <section class="admin-cat-panel sedeesp-seed" id="catalogoInicial">
        <div class="sedeesp-catalog__head"><div><h2>Revisar catálogo inicial</h2><p>Villamaría y Bruces. Los pabellones sugeridos pueden revisarse antes de asignar los artículos.</p></div><a href="espacios.php" class="sedeesp-button sedeesp-button--soft">Cerrar revisión</a></div>
        <p class="sedeesp-catalog__note">Se crearán solo ubicaciones nuevas. Las coincidencias conservan sus datos e ID. Marketing, Srita. Camila y Tercer piso se crearán inactivos, pendientes de confirmar.</p>
        <div class="admin-cat-table-wrap"><table class="admin-cat-table sedeesp-table"><thead><tr><th>Sede</th><th>Pabellón propuesto</th><th>Área / puesto</th><th>Clasificación propuesta</th><th>Estado propuesto</th><th>Resultado</th></tr></thead><tbody>
        <?php foreach ($catalogPlan['rows'] as $item): ?><tr><td><?= n360_admin_h($item['sede_nombre']) ?></td><td><?= n360_admin_h($item['pabellon'] ?? 'Por confirmar') ?></td><td><?= n360_admin_h($item['area'] . ($item['espacio'] ? ' - ' . $item['espacio'] : '')) ?></td><td><?= n360_admin_h($item['clasificacion_id'] ? $item['clasificacion'] : 'Sin clasificar') ?></td><td><?= $item['estado'] ? 'Activo' : 'Pendiente / inactivo' ?></td><td><?= n360_admin_h($item['resultado']) ?><?= $item['existente'] ? ' #' . (int)$item['existente'] : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <form method="post" class="sedeesp-seed__confirm"><input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="catalogo"><input type="hidden" name="revision" value="<?= n360_admin_h($catalogPlan['token']) ?>"><span><?= n360_espacios_catalogo_pendientes($catalogPlan) ?> ubicaciones nuevas. Después podrás completar pabellones y asignar cada artículo.</span><button type="submit" class="sedeesp-button">Cargar ubicaciones nuevas</button></form>
    </section>
    <?php endif; ?>
    <section class="admin-cat-panel sedeesp-catalog">
        <div class="sedeesp-catalog__head">
            <div class="sedeesp-catalog__title"><span class="sedeesp-icon"><i class="bi bi-pin-map" aria-hidden="true"></i></span><div><h2>Ubicaciones por sede <span class="sedeesp-total"><?= count($rows) ?> mostradas</span></h2><p>Consulta y organiza las áreas, ambientes y puestos registrados.</p></div></div>
            <div class="sedeesp-filter-actions"><?php if ($catalogAvailable): ?><a href="espacios.php?catalogo=1#catalogoInicial" class="sedeesp-button sedeesp-button--soft">Revisar catálogo inicial</a><?php endif; ?><button type="button" class="sedeesp-button" data-sedeesp-open <?= $ready ? '' : 'disabled' ?>><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo espacio</button></div>
        </div>
        <form method="get" class="sedeesp-filters" id="sedeespFilters">
            <label>Sede / oficina<select name="sede" id="sedeespFilterSede"><option value="0">Todas las sedes</option><?php foreach ($sedes as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $filterSede ? 'selected' : '' ?>><?= n360_admin_h($s['nombre']) ?></option><?php endforeach; ?></select></label>
            <label>Pabellón<select name="pabellon" <?= $pavilionReady ? '' : 'disabled' ?>><option value="">Todos</option><option value="__sin_definir__" <?= $filterPavilion === '__sin_definir__' ? 'selected' : '' ?>>Por confirmar</option><?php foreach ($pavilions as $option): ?><option value="<?= n360_admin_h($option['nombre']) ?>" <?= $filterPavilion === $option['nombre'] ? 'selected' : '' ?>><?= n360_admin_h($option['nombre']) ?></option><?php endforeach; ?></select></label>
            <label>Clasificación<select name="clasificacion" <?= $classReady ? '' : 'disabled' ?>><option value="0">Todas</option><option value="-1" <?= $filterClass === -1 ? 'selected' : '' ?>>Sin clasificar</option><?php foreach ($clasificaciones as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === $filterClass ? 'selected' : '' ?>><?= n360_admin_h($option['nombre']) ?></option><?php endforeach; ?></select></label>
            <label>Estado<select name="estado"><?php foreach (['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'] as $key => $name): ?><option value="<?= $key ?>" <?= (string)$key === $filterState ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?></select></label>
            <label class="sedeesp-filter-search">Buscar ubicación<div class="sedeesp-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= n360_admin_h($query) ?>" placeholder="Buscar por sede, pabellón, área o espacio..."></div></label>
            <div class="sedeesp-filter-actions"><button class="sedeesp-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button><a href="espacios.php" class="sedeesp-button sedeesp-button--soft" aria-label="Limpiar filtros"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Limpiar</a></div>
        </form>
        <?php if (!$rows): ?>
            <div class="sedeesp-empty"><span class="sedeesp-empty__icon"><i class="bi bi-pin-map" aria-hidden="true"></i></span><h3>No hay espacios para mostrar</h3><p><?= $query !== '' || $filterSede || $filterState !== '1' ? 'Prueba con otros filtros para encontrar una ubicación.' : 'Empieza con una sede y su área. Luego puedes indicar el escritorio o ambiente.' ?></p>
                <?php if ($ready): ?><button type="button" class="sedeesp-button sedeesp-button--soft" data-sedeesp-open><i class="bi bi-plus-lg" aria-hidden="true"></i> Crear espacio</button><?php endif; ?>
            </div>
        <?php else: ?>
        <div class="admin-cat-table-wrap" tabindex="0" role="region" aria-label="Ubicaciones registradas"><table class="admin-cat-table sedeesp-table sedeesp-table--locations"><thead><tr><th scope="col">Sede / oficina</th><th scope="col">Pabellón</th><th scope="col">Área / espacio</th><th scope="col">Clasificación</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr>
            <td><span class="sedeesp-sede"><i class="bi bi-building" aria-hidden="true"></i><?= n360_admin_h($row['sede_nombre']) ?></span></td>
            <td><span class="sedeesp-pavilion-name <?= !$row['pabellon'] ? 'sedeesp-muted' : '' ?>"><?= n360_admin_h($row['pabellon'] ?? 'Sin definir') ?></span><?php if ($pavilionReady && !$row['pabellon']): ?><button type="button" class="sedeesp-state-button sedeesp-state-button--link" data-sedeesp-pavilion data-id="<?= (int)$row['clm_sedeesp_id'] ?>" data-location="<?= n360_admin_h($row['sede_nombre'] . ' - ' . $row['clm_sedeesp_area'] . ' - ' . $row['clm_sedeesp_espacio']) ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i> Definir pabellón</button><?php endif; ?></td>
            <td><div class="sedeesp-place"><strong><?= n360_admin_h($row['clm_sedeesp_area']) ?></strong><span><i class="bi bi-door-open" aria-hidden="true"></i><?= n360_admin_h($row['clm_sedeesp_espacio'] ?? 'Solo área, sin puesto específico') ?></span></div><?php if ($personnelReady): ?><div class="sedeesp-personnel-summary" data-personnel-summary="<?= (int)$row['clm_sedeesp_id'] ?>"><?php foreach ([1, 2] as $turn): ?><span data-summary-turn="<?= $turn ?>">T<?= $turn ?>: <?= (int)($personnelSummary[(int)$row['clm_sedeesp_id']][$turn] ?? 0) ?></span><?php endforeach; ?></div><?php endif; ?></td>
            <td><span class="sedeesp-classification <?= $row['clasificacion_nombre'] === '' ? 'is-empty' : '' ?>"><?= n360_admin_h($row['clasificacion_nombre'] !== '' ? $row['clasificacion_nombre'] : 'Sin clasificar') ?></span></td>
            <td><span class="sedeesp-status <?= (int)$row['clm_sedeesp_estado'] === 1 ? 'is-active' : '' ?>"><span aria-hidden="true"></span><?= (int)$row['clm_sedeesp_estado'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
            <td><div class="sedeesp-row-actions">
                <button type="button" class="sedeesp-state-button sedeesp-personnel-button" data-sedeesp-personnel data-id="<?= (int)$row['clm_sedeesp_id'] ?>" data-location="<?= n360_admin_h($row['sede_nombre'] . ' - ' . $row['clm_sedeesp_area'] . ($row['clm_sedeesp_espacio'] ? ' - ' . $row['clm_sedeesp_espacio'] : '')) ?>" <?= $personnelReady ? '' : 'disabled' ?>><i class="bi bi-people" aria-hidden="true"></i> Personal</button>
                <button type="button" class="sedeesp-state-button" data-sedeesp-classify data-id="<?= (int)$row['clm_sedeesp_id'] ?>" data-classification="<?= (int)($row['clasificacion_id'] ?? 0) ?>" data-location="<?= n360_admin_h($row['sede_nombre'] . ' - ' . $row['clm_sedeesp_area'] . ($row['clm_sedeesp_espacio'] ? ' - ' . $row['clm_sedeesp_espacio'] : '')) ?>" <?= $classReady ? '' : 'disabled' ?>><i class="bi bi-diagram-3" aria-hidden="true"></i> Clasificar</button>
                <form method="post"><input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="state"><input type="hidden" name="id" value="<?= (int)$row['clm_sedeesp_id'] ?>"><input type="hidden" name="expected_estado" value="<?= (int)$row['clm_sedeesp_estado'] ?>"><button class="sedeesp-state-button <?= (int)$row['clm_sedeesp_estado'] === 1 ? 'sedeesp-state-button--deactivate' : 'sedeesp-state-button--activate' ?>" type="submit"><i class="bi bi-power" aria-hidden="true"></i> <?= (int)$row['clm_sedeesp_estado'] === 1 ? 'Desactivar' : 'Activar' ?></button></form>
            </div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <p class="sedeesp-catalog__note"><i class="bi bi-info-circle" aria-hidden="true"></i> Desactivar un espacio conserva sus asignaciones e historial.<?= count($rows) === 1000 ? ' Se muestran hasta 1000 espacios; ajusta los filtros para consultar otros.' : '' ?></p>
    </section>
    <details class="admin-cat-panel sedeesp-operational"><summary><span class="sedeesp-operational__title"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Espacios operativos <span class="sedeesp-total"><?= count($operational) ?></span></span><span class="sedeesp-operational__caption">Almacenes, grifos y clasificaciones existentes</span><i class="bi bi-chevron-down sedeesp-operational__chevron" aria-hidden="true"></i></summary><p class="sedeesp-catalog__note">Estas referencias mantienen la operación de inventario. Los puestos y escritorios se gestionan en el catálogo superior.</p><div class="admin-cat-table-wrap"><table class="admin-cat-table"><thead><tr><th>ID</th><th>Abreviatura</th><th>Nombre</th><th>Naturaleza</th></tr></thead><tbody><?php foreach ($operational as $item): ?><tr><td><?= (int)$item['id'] ?></td><td><span class="sedeesp-classification"><?= n360_admin_h($item['codigo']) ?></span></td><td><?= n360_admin_h($item['nombre']) ?></td><td><?= (int)$item['naturaleza'] === 2 ? 'Combustible / grifo' : 'Espacio general' ?></td></tr><?php endforeach; ?></tbody></table></div></details>
    <?php n360_render_content_separator('bottom'); ?>
</div></main>
<dialog class="sedeesp-dialog" id="sedeespCreateDialog" aria-labelledby="sedeespCreateTitle" aria-describedby="sedeespCreateDescription" data-open="<?= $createError ? '1' : '0' ?>">
    <header class="sedeesp-dialog__head">
        <div><span class="sedeesp-dialog__kicker"><i class="bi bi-geo-alt" aria-hidden="true"></i> Administración - Ubicaciones</span><h2 id="sedeespCreateTitle">Nuevo espacio</h2><p id="sedeespCreateDescription">Define el área y puesto donde se ubicarán los artículos.</p></div>
        <button type="button" class="sedeesp-dialog__close" data-sedeesp-close aria-label="Cerrar formulario">&times;</button>
    </header>
    <form method="post" id="sedeespCreateForm" class="sedeesp-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="create">
        <div class="sedeesp-dialog__body">
            <?php if ($createError): ?><div class="sedeesp-form-error" id="sedeespCreateError" role="alert"><?= n360_admin_h($error) ?></div><?php endif; ?>
            <fieldset <?= $ready ? '' : 'disabled' ?>>
                <label for="sedeespCreateSede">Sede / oficina <select name="sede_id" id="sedeespCreateSede" required autofocus><option value="">Seleccionar sede...</option>
                    <?php foreach ($sedes as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $values['sede_id'] ? 'selected' : '' ?>><?= n360_admin_h($s['nombre']) ?></option><?php endforeach; ?>
                </select></label>
                <label for="sedeespCreatePabellon">Pabellón <span class="sedeesp-optional">Opcional</span><input name="pabellon" id="sedeespCreatePabellon" list="sedeespPavilions" maxlength="100" placeholder="Ej. Pabellón de oficinas" value="<?= n360_admin_h($values['pabellon']) ?>" <?= $pavilionReady ? '' : 'disabled' ?>></label>
                <label for="sedeespCreateArea">Área<input name="area" id="sedeespCreateArea" maxlength="150" required list="sedeespAreas" placeholder="Ej. Contabilidad" value="<?= n360_admin_h($values['area']) ?>">
                    <datalist id="sedeespAreas"><?php foreach ($areas as $area): ?><option value="<?= n360_admin_h($area['area']) ?>" data-sede="<?= (int)$area['sede_id'] ?>"></option><?php endforeach; ?></datalist>
                </label>
                <label for="sedeespCreateEspacio">Espacio <span class="sedeesp-optional">Opcional</span><input name="espacio" id="sedeespCreateEspacio" maxlength="150" placeholder="Ej. Escritorio 1" value="<?= n360_admin_h($values['espacio']) ?>"></label>
                <label class="sedeesp-wide" for="sedeespCreateClassification">Clasificación <span class="sedeesp-optional">Opcional</span><select name="clasificacion_id" id="sedeespCreateClassification" <?= $classReady ? '' : 'disabled' ?>><option value="">Sin clasificar</option><?php foreach ($clasificaciones as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (string)$option['id'] === $values['clasificacion_id'] ? 'selected' : '' ?>><?= n360_admin_h($option['nombre']) ?></option><?php endforeach; ?></select></label>
                <p class="sedeesp-field-help sedeesp-wide">Puedes elegir un área existente o escribir una nueva. Deja el espacio vacío para registrar solo el área.</p>
            </fieldset>
            <div class="sedeesp-preview" aria-live="polite"><span><i class="bi bi-geo-alt" aria-hidden="true"></i> Ubicación que se guardará</span><p id="sedeespCreatePreview">Selecciona una sede y completa el área.</p></div>
        </div>
        <footer class="sedeesp-dialog__footer"><button type="button" class="sedeesp-button sedeesp-button--soft" data-sedeesp-close>Cancelar</button><button type="submit" class="sedeesp-button" id="sedeespCreateSubmit" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-check2" aria-hidden="true"></i><span>Guardar espacio</span></button></footer>
    </form>
</dialog>
<dialog class="sedeesp-dialog" id="sedeespClassifyDialog" aria-labelledby="sedeespClassifyTitle" data-open="<?= $classifyRow ? '1' : '0' ?>">
    <header class="sedeesp-dialog__head"><div><span class="sedeesp-dialog__kicker"><i class="bi bi-diagram-3" aria-hidden="true"></i> Administración - Ubicaciones</span><h2 id="sedeespClassifyTitle">Clasificar ubicación</h2><p>Organiza este espacio para los filtros de trazabilidad.</p></div><button type="button" class="sedeesp-dialog__close" data-classify-close aria-label="Cerrar clasificación"><span aria-hidden="true">&times;</span></button></header>
    <form method="post" class="sedeesp-form" id="sedeespClassifyForm">
        <input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="classify"><input type="hidden" name="id" id="sedeespClassifyId" value="<?= (int)($classifyRow['id'] ?? 0) ?>"><input type="hidden" name="expected_clasificacion_id" id="sedeespClassifyExpected" value="<?= (int)($classifyRow['clasificacion_id'] ?? 0) ?>">
        <div class="sedeesp-dialog__body">
            <?php if ($classifyRow): ?><div class="sedeesp-form-error" role="alert"><?= n360_admin_h($error) ?></div><?php endif; ?>
            <div class="sedeesp-preview sedeesp-classify-location"><span>Ubicación</span><p id="sedeespClassifyLocation"><?= n360_admin_h($classifyRow['nombre'] ?? '') ?></p></div>
            <label for="sedeespClassifySelect">Clasificación<select name="clasificacion_id" id="sedeespClassifySelect" <?= $classReady ? '' : 'disabled' ?>><option value="">Sin clasificar</option><?php foreach ($clasificaciones as $option): ?><option value="<?= (int)$option['id'] ?>" <?= $classifyRow && (int)$classifyRow['clasificacion_id'] === (int)$option['id'] ? 'selected' : '' ?>><?= n360_admin_h($option['nombre']) ?></option><?php endforeach; ?></select></label>
            <p class="sedeesp-classify-help">Se aplica a la clasificación actual de esta ubicación. Conserva sus artículos e historial.</p>
        </div>
        <footer class="sedeesp-dialog__footer"><button type="button" class="sedeesp-button sedeesp-button--soft" data-classify-close>Cancelar</button><button type="submit" class="sedeesp-button" <?= $classReady ? '' : 'disabled' ?>>Guardar clasificación</button></footer>
    </form>
</dialog>
<datalist id="sedeespPavilions"><?php foreach (array_unique(array_merge(array_column($pavilions, 'nombre'), ['Pabellón de oficinas', 'Pabellón de almacén', 'Pabellón de encomiendas'])) as $name): ?><option value="<?= n360_admin_h($name) ?>"></option><?php endforeach; ?></datalist>
<dialog class="sedeesp-dialog" id="sedeespPavilionDialog" aria-labelledby="sedeespPavilionTitle">
    <header class="sedeesp-dialog__head"><div><span class="sedeesp-dialog__kicker"><i class="bi bi-building" aria-hidden="true"></i> Administración - Ubicaciones</span><h2 id="sedeespPavilionTitle">Definir pabellón</h2><p>Completa la ubicación pendiente de este espacio.</p></div><button type="button" class="sedeesp-dialog__close" data-pavilion-close aria-label="Cerrar">&times;</button></header>
    <form method="post" class="sedeesp-form">
        <input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="pabellon"><input type="hidden" name="id">
        <div class="sedeesp-dialog__body"><div class="sedeesp-preview sedeesp-classify-location"><span>Ubicación</span><p data-pavilion-location></p></div><label>Pabellón<input name="pabellon" required maxlength="100" list="sedeespPavilions"></label><p class="sedeesp-classify-help">La asignación completa la identificación física actual. Para redefinir después la ubicación, desactiva el espacio y crea otro.</p></div>
        <footer class="sedeesp-dialog__footer"><button type="button" class="sedeesp-button sedeesp-button--soft" data-pavilion-close>Cancelar</button><button type="submit" class="sedeesp-button">Guardar pabellón</button></footer>
    </form>
</dialog>
<script src="<?= n360_asset('assets/js/admin_espacios_sedes_n360.js?espacios=4') ?>"></script>
<?php if ($personnelReady) n360_render_space_personnel_dialog($csrf); ?>
<?php n360_render_footer(); n360_admin_render_close(); $conn->close(); ?>
