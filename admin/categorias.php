<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';
require_once __DIR__ . '/../layout/admin_catalogo_productos_n360.php';
require_once __DIR__ . '/../php/categorias_modulos.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit('Método no permitido.');
}
$conn->set_charset('utf8mb4');
if (empty($_SESSION['categorias_modulos_csrf'])) $_SESSION['categorias_modulos_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['categorias_modulos_csrf'];
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($query, 'UTF-8') > 120) $query = mb_substr($query, 0, 120, 'UTF-8');
$page = is_string($_GET['page'] ?? null) ? filter_var($_GET['page'], FILTER_VALIDATE_INT) : 1;
$page = $page && $page > 0 ? min($page, 1000000) : 1;
$pageSize = 25;
$ready = false; $error = ''; $rows = []; $settings = []; $modules = [];
$total = 0; $pages = 1;
$success = (string)($_SESSION['categorias_modulos_flash'] ?? '');
unset($_SESSION['categorias_modulos_flash']);

try {
    $ready = n360_categorias_disponible($conn);
    foreach (n360_categorias_rows($conn, 'SELECT id_modulo, nombre_modulo FROM tb_modulos
        WHERE id_modulo IN (3, 6, 12) ORDER BY id_modulo') as $module) {
        $modules[(int)$module['id_modulo']] = (int)$module['id_modulo'] === 6 ? 'RRHH' : ucfirst($module['nombre_modulo']);
    }
    if (count($modules) !== 3) throw new RuntimeException('Relaciones de módulos incompletas.');

    if ($method === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            throw new RuntimeException('La sesión del formulario venció. Actualiza la página antes de guardar.', 419);
        }
        if (!$ready) throw new RuntimeException('La gestión de categorías está pendiente de habilitación.', 409);
        $id = is_string($_POST['categoria_id'] ?? null) ? filter_var($_POST['categoria_id'], FILTER_VALIDATE_INT) : false;
        $expected = $_POST['expected'] ?? null;
        $selected = $_POST['modulos'] ?? [];
        if (!$id || $id < 1 || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)
            || !is_array($selected) || count($selected) > 3) {
            throw new InvalidArgumentException('Revisa la categoría y los módulos seleccionados.');
        }
        if ($id === 11) throw new InvalidArgumentException('La categoría de Combustible mantiene su uso protegido.');
        foreach ($selected as $value) {
            if (!is_string($value) || !in_array($value, ['3', '6', '12'], true)) {
                throw new InvalidArgumentException('Módulo no disponible para esta configuración.');
            }
        }
        if (count(array_unique($selected)) !== count($selected)) throw new InvalidArgumentException('Los módulos no deben repetirse.');

        $conn->begin_transaction();
        $category = n360_categorias_rows($conn, 'SELECT clm_alm_categoria_id FROM tb_alm_categoria
            WHERE clm_alm_categoria_id = ? FOR UPDATE', 'i', [$id]);
        if (!$category) throw new InvalidArgumentException('La categoría ya no existe.');
        $current = [];
        foreach (n360_categorias_rows($conn, 'SELECT modulo_id, habilitado, version FROM tb_alm_categoria_modulo
            WHERE categoria_id = ? AND modulo_id IN (3, 6, 12) FOR UPDATE', 'i', [$id]) as $setting) {
            $current[(int)$setting['modulo_id']] = $setting;
        }
        if (!hash_equals(n360_categorias_version($id, $current), $expected)) {
            throw new RuntimeException('La disponibilidad cambió mientras tenías abierto el formulario. Revisa los valores actuales y vuelve a guardar.', 409);
        }
        $userId = null;
        foreach (['id_usuario', 'web_id_usuario', 'usuario_id'] as $key) {
            if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
                $userId = (int)$_SESSION[$key]; break;
            }
        }
        $stmt = $conn->prepare('INSERT INTO tb_alm_categoria_modulo
            (categoria_id, modulo_id, habilitado, actualizado_por) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE habilitado = VALUES(habilitado), version = version + 1,
                actualizado_por = VALUES(actualizado_por), actualizado_en = CURRENT_TIMESTAMP');
        if (!$stmt) throw new RuntimeException('No se pudo preparar la actualización.');
        foreach ([3, 6, 12] as $moduleId) {
            $enabled = in_array((string)$moduleId, $selected, true) ? 1 : 0;
            if (isset($current[$moduleId]) && (int)$current[$moduleId]['habilitado'] === $enabled) continue;
            $stmt->bind_param('iiii', $id, $moduleId, $enabled, $userId);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo guardar la disponibilidad.');
        }
        $stmt->close();
        $conn->commit();
        $_SESSION['categorias_modulos_flash'] = 'Disponibilidad guardada. Los productos existentes siguen disponibles.';
        header('Location: categorias.php?' . http_build_query(['q' => $query, 'page' => $page]), true, 303);
        exit;
    }
} catch (Throwable $e) {
    if ($method === 'POST') {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    $known = $e instanceof InvalidArgumentException || in_array($e->getCode(), [409, 419], true);
    if (!$known) error_log('categorias.php: ' . $e->getMessage());
    $error = $known ? $e->getMessage() : 'No se pudo consultar o guardar la disponibilidad. Inténtalo nuevamente.';
    http_response_code($known ? ($e instanceof InvalidArgumentException ? 422 : $e->getCode()) : 500);
    if (!$known) $ready = false;
}

try {
    $where = ''; $params = []; $types = '';
    if ($query !== '') {
        $where = " WHERE CONCAT_WS(' ', c.clm_alm_categoria_NOMBRE, c.clm_alm_categoria_DESCRIPCION,
            g.clm_alm_codigo_NOMBRE, g.clm_alm_codigo_DESCRIPCION) LIKE ?";
        $types = 's'; $params = ['%' . $query . '%'];
    }
    $from = ' FROM tb_alm_categoria c LEFT JOIN tb_alm_codigo g ON g.clm_alm_codigo_id = c.clm_alm_categoria_idCODIGO';
    $count = n360_categorias_rows($conn, 'SELECT COUNT(*) AS total' . $from . $where, $types, $params);
    $total = (int)$count[0]['total']; $pages = max(1, (int)ceil($total / $pageSize)); $page = min($page, $pages);
    $rows = n360_categorias_rows($conn, "SELECT c.clm_alm_categoria_id AS id, c.clm_alm_categoria_NOMBRE AS codigo,
        c.clm_alm_categoria_DESCRIPCION AS nombre,
        COALESCE(NULLIF(g.clm_alm_codigo_DESCRIPCION, ''), g.clm_alm_codigo_NOMBRE, 'Sin grupo') AS grupo,
        (SELECT COUNT(*) FROM tb_alm_producto p WHERE p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id) AS productos"
        . $from . $where . ' ORDER BY c.clm_alm_categoria_DESCRIPCION, c.clm_alm_categoria_id LIMIT '
        . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize), $types, $params);
    if ($ready && $rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        foreach (n360_categorias_rows($conn, 'SELECT categoria_id, modulo_id, habilitado, version
            FROM tb_alm_categoria_modulo WHERE categoria_id IN (' . $ids . ') AND modulo_id IN (3, 6, 12)') as $setting) {
            $settings[(int)$setting['categoria_id']][(int)$setting['modulo_id']] = $setting;
        }
    }
} catch (Throwable $e) {
    error_log('categorias.php listado: ' . $e->getMessage());
    $error = 'No se pudo consultar el listado de categorías. Inténtalo nuevamente.';
    $ready = false; $rows = []; http_response_code(500);
}

n360_admin_render_head('Categorías');
?>
<link rel="stylesheet" href="<?= n360_asset('assets/css/admin_controles_productos_n360.css?controles=2') ?>">
<link rel="stylesheet" href="<?= n360_asset('assets/css/admin_categorias_n360.css?categorias=2') ?>">
<?php n360_render_header(['title' => 'Categorías', 'subtitle' => 'Administración']); n360_render_sidebar(); ?>
<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
<div class="n360-main__inner admin-cat-shell categorias-page">
    <?php n360_render_content_separator('top'); ?>
    <section class="admin-cat-hero"><div>
        <span class="admin-cat-kicker"><i class="bi bi-tags" aria-hidden="true"></i> Administración - Maestros</span>
        <h1>Categorías</h1><p>Decide qué categorías se ofrecen al crear productos en cada módulo.</p>
    </div><span class="categorias-hero-tag"><i class="bi bi-plus-circle" aria-hidden="true"></i> Nuevas altas</span></section>
    <?php if ($error !== ''): ?><div class="admin-cat-note categorias-error" role="alert"><?= n360_admin_h($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="admin-cat-note categorias-success" role="status"><?= n360_admin_h($success) ?></div><?php endif; ?>
    <?php if (!$ready && $error === ''): ?><div class="admin-cat-note" role="status">La configuración por módulo está pendiente de habilitación. Las categorías conservan su disponibilidad actual.</div><?php endif; ?>
    <aside class="categorias-explanation"><i class="bi bi-info-circle" aria-hidden="true"></i><div><strong>Los productos existentes siguen disponibles</strong><p>Desmarcar un módulo solo impide nuevas altas en esa categoría. Sus productos, movimientos, existencias y trazabilidad se conservan.</p></div></aside>
    <section class="admin-cat-panel">
        <div class="admin-cat-panel__head"><div><h2>Disponibilidad por módulo</h2><p>Marca los módulos donde se podrán crear productos y guarda cada categoría.</p></div><span class="categorias-count"><?= $total ?> <?= $total === 1 ? 'categoría' : 'categorías' ?></span></div>
        <form method="get" class="categorias-search" role="search"><label for="categoriasQuery">Buscar categoría o grupo</label><div><input type="search" id="categoriasQuery" name="q" value="<?= n360_admin_h($query) ?>" maxlength="120" placeholder="Código, categoría o grupo..."><button type="submit">Buscar</button><a href="categorias.php">Limpiar</a></div></form>
        <div class="admin-cat-table-wrap" tabindex="0" role="region" aria-label="Categorías y disponibilidad para nuevas altas">
            <table class="admin-cat-table categorias-table"><thead><tr><th scope="col">Categoría</th><th scope="col">Grupo</th>
                <?php foreach ([3, 6, 12] as $moduleId): ?><th scope="col"><?= n360_admin_h($modules[$moduleId] ?? 'Módulo no disponible') ?></th><?php endforeach; ?>
                <th scope="col">Productos</th><th scope="col">Acción</th></tr></thead><tbody>
                <?php if (!$rows): ?><tr><td colspan="7" class="admin-cat-empty"><?= $error !== '' ? 'El listado no está disponible temporalmente.' : 'No hay categorías para esta búsqueda.' ?></td></tr>
                <?php else: foreach ($rows as $row): $id = (int)$row['id']; $protected = $id === 11; $formId = 'categoriaForm' . $id; ?>
                    <tr data-categoria-id="<?= $id ?>"><td><span class="categorias-code"><?= n360_admin_h($row['codigo']) ?></span><strong><?= n360_admin_h($row['nombre'] ?: $row['codigo']) ?></strong><?php if ($protected): ?><small class="categorias-protected"><i class="bi bi-lock" aria-hidden="true"></i> Combustible · uso protegido</small><?php endif; ?></td>
                        <td class="categorias-group"><?= n360_admin_h($row['grupo']) ?></td>
                        <?php foreach ([3, 6, 12] as $moduleId): $enabled = !$protected && (int)($settings[$id][$moduleId]['habilitado'] ?? 1) === 1; ?><td>
                            <?php if ($protected): ?><span class="categorias-na">No aplica</span><?php else: ?><label class="categorias-choice"><input type="checkbox" name="modulos[]" value="<?= $moduleId ?>" form="<?= $formId ?>" <?= $enabled ? 'checked' : '' ?> <?= !$ready ? 'disabled' : '' ?> aria-label="Permitir nuevas altas de <?= n360_admin_h($row['codigo']) ?> en <?= n360_admin_h($modules[$moduleId] ?? '') ?>"><span>Mostrar</span></label><?php endif; ?>
                        </td><?php endforeach; ?>
                        <td><div class="categorias-product-actions"><span class="categorias-products"><?= (int)$row['productos'] ?></span><button type="button" class="controles-search-button" data-control-catalog data-control-scope="categorias" data-control-id="<?= $id ?>" data-control-label="<?= n360_admin_h(trim($row['codigo'] . ' - ' . ($row['nombre'] ?: $row['codigo']))) ?>" title="Ver productos de esta categoría" aria-label="Ver productos de la categoría <?= n360_admin_h($row['nombre'] ?: $row['codigo']) ?>"><i class="bi bi-search" aria-hidden="true"></i></button></div></td><td>
                            <?php if ($protected): ?><span class="categorias-na">Protegida</span><?php else: ?><form method="post" id="<?= $formId ?>" action="categorias.php?<?= n360_admin_h(http_build_query(['q' => $query, 'page' => $page])) ?>">
                                <input type="hidden" name="csrf" value="<?= n360_admin_h($csrf) ?>"><input type="hidden" name="categoria_id" value="<?= $id ?>"><input type="hidden" name="expected" value="<?= n360_categorias_version($id, $settings[$id] ?? []) ?>">
                                <button type="submit" class="categorias-save" <?= !$ready ? 'disabled' : '' ?> aria-label="Guardar disponibilidad de <?= n360_admin_h($row['codigo']) ?>"><i class="bi bi-check2" aria-hidden="true"></i> Guardar</button>
                            </form><?php endif; ?>
                        </td></tr>
                <?php endforeach; endif; ?>
            </tbody></table>
        </div>
        <footer class="categorias-footer"><p>Combustible conserva su categoría y funcionamiento actuales.</p><nav aria-label="Páginas de categorías">
            <?php if ($page > 1): ?><a href="?<?= n360_admin_h(http_build_query(['q' => $query, 'page' => $page - 1])) ?>">Anterior</a><?php endif; ?>
            <span>Página <?= $page ?> de <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="?<?= n360_admin_h(http_build_query(['q' => $query, 'page' => $page + 1])) ?>">Siguiente</a><?php endif; ?>
        </nav></footer>
    </section>
    <?php n360_render_content_separator('bottom'); ?>
</div></main>
<?php n360_render_admin_catalogo_productos('Categorías de productos'); ?>
<?php n360_render_footer(); n360_admin_render_close(); $conn->close(); ?>
