<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';
require_once __DIR__ . '/../01_almacen/movimiento_backend.php';
$conn->set_charset('utf8mb4');

if (empty($_SESSION['sede_espacios_csrf'])) $_SESSION['sede_espacios_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['sede_espacios_csrf'];
$ready = alm_activos_espacios_disponibles($conn);
$error = '';
$success = (string)($_SESSION['sede_espacios_flash'] ?? '');
unset($_SESSION['sede_espacios_flash']);
$values = ['sede_id' => '', 'area' => '', 'espacio' => ''];

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
            if (!$sedeId || $area === '') throw new InvalidArgumentException('Selecciona la sede e indica el nombre del área.');
            if (alm_activos_longitud($area) > 150 || alm_activos_longitud($space) > 150) {
                throw new InvalidArgumentException('Área y espacio admiten hasta 150 caracteres cada uno.');
            }
            $conn->begin_transaction();
            // Serializa las altas de una misma sede para evitar duplicados concurrentes.
            $sedeRow = alm_fetch_one($conn, 'SELECT clm_sedes_id FROM tb_sedes WHERE clm_sedes_id = ? FOR UPDATE', 'i', [$sedeId]);
            if (!$sedeRow) throw new InvalidArgumentException('La sede seleccionada no existe.');
            $duplicate = alm_fetch_one($conn, "SELECT clm_sedeesp_id FROM tb_sedes_espacios
                WHERE clm_sedeesp_idsede = ? AND clm_sedeesp_area = ? AND COALESCE(clm_sedeesp_espacio, '') = ? LIMIT 1", 'iss', [$sedeId, $area, $space]);
            if ($duplicate) throw new RuntimeException('El área y espacio ya existen en la sede. Puedes activar el registro existente.', 409);
            // Referencias reservadas para una futura actualización de personal.
            // Esta interfaz no las lee del POST ni modifica valores existentes.
            $params = [$sedeId, $area, $space !== '' ? $space : null];
            $stmt = $conn->prepare('INSERT INTO tb_sedes_espacios
                (clm_sedeesp_idsede, clm_sedeesp_area, clm_sedeesp_espacio)
                VALUES (?, ?, ?)');
            alm_bind($stmt, 'iss', $params);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo crear el espacio.');
            $stmt->close();
            $conn->commit();
            $_SESSION['sede_espacios_flash'] = 'Espacio creado correctamente.';
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
        } else {
            throw new InvalidArgumentException('Operación inválida.');
        }
        header('Location: espacios_sedes.php');
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $known = $e instanceof InvalidArgumentException || in_array($e->getCode(), [409, 419], true);
        if (!$known) error_log('espacios_sedes.php: ' . $e->getMessage());
        $error = $known ? $e->getMessage() : 'No se pudo guardar el espacio. Inténtalo nuevamente.';
    }
}

$sedes = alm_fetch_all($conn, 'SELECT clm_sedes_id AS id, clm_sedes_name AS nombre FROM tb_sedes ORDER BY clm_sedes_name');
$filterSede = max(0, (int)(is_string($_GET['sede'] ?? null) ? $_GET['sede'] : 0));
$filterState = is_string($_GET['estado'] ?? null) && in_array($_GET['estado'], ['0', '1', 'todos'], true) ? $_GET['estado'] : '1';
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$rows = [];
$areas = [];
if ($ready) {
    $areas = alm_fetch_all($conn, 'SELECT DISTINCT clm_sedeesp_idsede AS sede_id, clm_sedeesp_area AS area
        FROM tb_sedes_espacios ORDER BY clm_sedeesp_area');
    $where = ['1 = 1']; $types = ''; $params = [];
    if ($filterSede) { $where[] = 'e.clm_sedeesp_idsede = ?'; $types .= 'i'; $params[] = $filterSede; }
    if ($filterState !== 'todos') { $where[] = 'e.clm_sedeesp_estado = ?'; $types .= 'i'; $params[] = (int)$filterState; }
    if ($query !== '') {
        $where[] = "CONCAT_WS(' ', s.clm_sedes_name, e.clm_sedeesp_area, e.clm_sedeesp_espacio) LIKE CONCAT('%', ?, '%')";
        $types .= 's'; $params[] = $query;
    }
    $rows = alm_fetch_all($conn, 'SELECT e.clm_sedeesp_id, e.clm_sedeesp_area, e.clm_sedeesp_espacio,
        e.clm_sedeesp_estado, s.clm_sedes_name AS sede_nombre FROM tb_sedes_espacios e
        JOIN tb_sedes s ON s.clm_sedes_id = e.clm_sedeesp_idsede WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.clm_sedes_name, e.clm_sedeesp_area, e.clm_sedeesp_espacio, e.clm_sedeesp_id LIMIT 1000', $types, $params);
}
$createError = $ready && $error !== '' && ($_POST['action'] ?? '') === 'create';
n360_admin_render_head('Espacios por sede');
?>
<link rel="stylesheet" href="<?= n360_asset('assets/css/admin_espacios_sedes_n360.css?espacios=2') ?>">
<?php n360_render_header(['title' => 'Espacios por sede', 'subtitle' => 'Administración']); ?>
<?php n360_render_sidebar(); ?>
<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
<div class="n360-main__inner admin-cat-shell sedeesp-page">
    <?php n360_render_content_separator('top'); ?>
    <section class="admin-cat-hero"><div>
        <span class="admin-cat-kicker"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Administración · Maestros</span>
        <h1>Espacios por sede</h1><p>Organiza las áreas, ambientes y puestos donde se ubican los artículos etiquetados.</p>
    </div></section>
    <?php if ($success !== ''): ?><div class="admin-cat-note sedeesp-success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= n360_admin_h($success) ?></div><?php endif; ?>
    <?php if ($error !== '' && !$createError): ?><div class="admin-cat-note sedeesp-error" role="alert"><?= n360_admin_h($error) ?></div><?php endif; ?>
    <?php if (!$ready): ?><div class="admin-cat-note" role="status">La gestión de espacios por sede aún no está habilitada. Contacta al administrador.</div><?php endif; ?>
    <section class="admin-cat-panel sedeesp-catalog">
        <div class="sedeesp-catalog__head">
            <div class="sedeesp-catalog__title"><span class="sedeesp-icon"><i class="bi bi-layout-text-sidebar-reverse" aria-hidden="true"></i></span><div><h2>Espacios registrados <span class="sedeesp-total"><?= count($rows) ?></span></h2><p>Consulta las áreas y puestos de cada sede.</p></div></div>
            <button type="button" class="sedeesp-button" data-sedeesp-open <?= $ready ? '' : 'disabled' ?>><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo espacio</button>
        </div>
        <form method="get" class="sedeesp-filters" id="sedeespFilters">
            <label>Sede / oficina<select name="sede" id="sedeespFilterSede"><option value="0">Todas las sedes</option><?php foreach ($sedes as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $filterSede ? 'selected' : '' ?>><?= n360_admin_h($s['nombre']) ?></option><?php endforeach; ?></select></label>
            <label>Estado<select name="estado"><?php foreach (['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'] as $key => $name): ?><option value="<?= $key ?>" <?= (string)$key === $filterState ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?></select></label>
            <label>Buscar<div class="sedeesp-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= n360_admin_h($query) ?>" placeholder="Sede, área o espacio..."></div></label>
            <div class="sedeesp-filter-actions"><button class="sedeesp-button sedeesp-button--soft" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button><a href="espacios_sedes.php" class="sedeesp-reset" aria-label="Limpiar filtros" title="Limpiar filtros"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a></div>
        </form>
        <?php if (!$rows): ?>
            <div class="sedeesp-empty"><span class="sedeesp-empty__icon"><i class="bi bi-pin-map" aria-hidden="true"></i></span><h3>No hay espacios para mostrar</h3><p><?= $query !== '' || $filterSede || $filterState !== '1' ? 'Prueba con otros filtros para encontrar una ubicación.' : 'Empieza con una sede y su área. Luego puedes indicar el escritorio o ambiente.' ?></p>
                <?php if ($ready): ?><button type="button" class="sedeesp-button sedeesp-button--soft" data-sedeesp-open><i class="bi bi-plus-lg" aria-hidden="true"></i> Crear espacio</button><?php endif; ?>
            </div>
        <?php else: ?>
        <div class="admin-cat-table-wrap"><table class="admin-cat-table sedeesp-table"><thead><tr><th>Sede / oficina</th><th>Área</th><th>Espacio</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr>
            <td><span class="sedeesp-sede"><i class="bi bi-building" aria-hidden="true"></i><?= n360_admin_h($row['sede_nombre']) ?></span></td><td><strong><?= n360_admin_h($row['clm_sedeesp_area']) ?></strong></td><td><?= n360_admin_h($row['clm_sedeesp_espacio'] ?? 'Sin espacio específico') ?></td>
            <td><span class="sedeesp-status <?= (int)$row['clm_sedeesp_estado'] === 1 ? 'is-active' : '' ?>"><span aria-hidden="true"></span><?= (int)$row['clm_sedeesp_estado'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
            <td><form method="post"><input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="state"><input type="hidden" name="id" value="<?= (int)$row['clm_sedeesp_id'] ?>"><input type="hidden" name="expected_estado" value="<?= (int)$row['clm_sedeesp_estado'] ?>"><button class="sedeesp-state-button" type="submit"><?= (int)$row['clm_sedeesp_estado'] === 1 ? 'Desactivar' : 'Activar' ?></button></form></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <p class="sedeesp-catalog__note"><i class="bi bi-info-circle" aria-hidden="true"></i> Desactivar un espacio conserva sus asignaciones e historial.<?= count($rows) === 1000 ? ' Se muestran hasta 1000 espacios; ajusta los filtros para consultar otros.' : '' ?></p>
    </section>
    <?php n360_render_content_separator('bottom'); ?>
</div></main>
<dialog class="sedeesp-dialog" id="sedeespCreateDialog" aria-labelledby="sedeespCreateTitle" aria-describedby="sedeespCreateDescription" data-open="<?= $createError ? '1' : '0' ?>">
    <header class="sedeesp-dialog__head">
        <span class="sedeesp-icon"><i class="bi bi-geo-alt" aria-hidden="true"></i></span>
        <div><h2 id="sedeespCreateTitle">Nuevo espacio</h2><p id="sedeespCreateDescription">Define una ubicación dentro de la sede.</p></div>
        <button type="button" class="sedeesp-dialog__close" data-sedeesp-close aria-label="Cerrar formulario"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <form method="post" id="sedeespCreateForm" class="sedeesp-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="action" value="create">
        <div class="sedeesp-dialog__body">
            <?php if ($createError): ?><div class="sedeesp-form-error" id="sedeespCreateError" role="alert"><?= n360_admin_h($error) ?></div><?php endif; ?>
            <fieldset <?= $ready ? '' : 'disabled' ?>>
                <label class="sedeesp-wide" for="sedeespCreateSede">Sede / oficina <select name="sede_id" id="sedeespCreateSede" required autofocus><option value="">Seleccionar sede...</option>
                    <?php foreach ($sedes as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $values['sede_id'] ? 'selected' : '' ?>><?= n360_admin_h($s['nombre']) ?></option><?php endforeach; ?>
                </select></label>
                <label for="sedeespCreateArea">Área<input name="area" id="sedeespCreateArea" maxlength="150" required list="sedeespAreas" placeholder="Ej. Contabilidad" value="<?= n360_admin_h($values['area']) ?>">
                    <datalist id="sedeespAreas"><?php foreach ($areas as $area): ?><option value="<?= n360_admin_h($area['area']) ?>" data-sede="<?= (int)$area['sede_id'] ?>"></option><?php endforeach; ?></datalist>
                </label>
                <label for="sedeespCreateEspacio">Espacio <span class="sedeesp-optional">Opcional</span><input name="espacio" id="sedeespCreateEspacio" maxlength="150" placeholder="Ej. Escritorio 1" value="<?= n360_admin_h($values['espacio']) ?>"></label>
                <p class="sedeesp-field-help sedeesp-wide">Puedes elegir un área existente o escribir una nueva. Deja el espacio vacío para registrar solo el área.</p>
            </fieldset>
            <div class="sedeesp-preview" aria-live="polite"><span>Así quedará la ubicación</span><p id="sedeespCreatePreview">Selecciona una sede y completa el área.</p></div>
        </div>
        <footer class="sedeesp-dialog__footer"><button type="button" class="sedeesp-button sedeesp-button--soft" data-sedeesp-close>Cancelar</button><button type="submit" class="sedeesp-button" id="sedeespCreateSubmit" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-check2" aria-hidden="true"></i><span>Guardar espacio</span></button></footer>
    </form>
</dialog>
<script src="<?= n360_asset('assets/js/admin_espacios_sedes_n360.js?espacios=2') ?>"></script>
<?php n360_render_footer(); n360_admin_render_close(); $conn->close(); ?>
