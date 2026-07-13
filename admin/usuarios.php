<?php
define('N360_ADMIN_CATALOG', true);
require_once __DIR__ . '/_admin_catalogos.php';

$usuarios = n360_admin_query_all($conn, "
    SELECT
        u.id_usuario,
        u.usuario,
        u.nombre,
        u.DNI,
        u.web_rol,
        u.clm_usuarios_sede,
        u.clm_tra_imagen AS foto_usuario,
        s.clm_sedes_name AS sede_nombre,
        (
            SELECT t2.clm_tra_imagen
            FROM tb_trabajador t2
            WHERE t2.clm_tra_dni = u.DNI
              AND t2.clm_tra_imagen IS NOT NULL
              AND t2.clm_tra_imagen <> ''
            ORDER BY t2.clm_tra_id DESC
            LIMIT 1
        ) AS foto_trabajador
    FROM tb_usuarios u
    LEFT JOIN tb_sedes s ON s.clm_sedes_id = u.clm_usuarios_sede
    ORDER BY u.web_rol = 'Admin' DESC, u.nombre ASC, u.usuario ASC
");

$permisosPorUsuario = n360_admin_permissions_by_user($conn);
$admins = array_filter($usuarios, static fn($user) => (string)($user['web_rol'] ?? '') === 'Admin');
$conFoto = array_filter($usuarios, static fn($user) => !empty($user['foto_usuario']) || !empty($user['foto_trabajador']));

n360_admin_render_head('Usuarios');
?>
<?php n360_render_header(['title' => 'Usuarios', 'subtitle' => 'Administracion']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module n360-main--compact-access" role="main">
    <div class="n360-main__inner admin-cat-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="admin-cat-hero">
            <div>
                <span class="admin-cat-kicker"><i class="bi bi-people-fill" aria-hidden="true"></i> Administracion - Maestros</span>
                <h1>Usuarios</h1>
                <p>Consulta general de usuarios, rol, sede, documento, foto de perfil y permisos asignados.</p>
            </div>
        </section>

        <section class="admin-cat-kpis">
            <article class="admin-cat-kpi"><span>Usuarios</span><strong><?= count($usuarios) ?></strong></article>
            <article class="admin-cat-kpi"><span>Administradores</span><strong><?= count($admins) ?></strong></article>
            <article class="admin-cat-kpi"><span>Con foto</span><strong><?= count($conFoto) ?></strong></article>
            <article class="admin-cat-kpi"><span>Asignaciones</span><strong><?= array_sum(array_map('count', $permisosPorUsuario)) ?></strong></article>
        </section>

        <section class="admin-cat-panel">
            <div class="admin-cat-panel__head">
                <div>
                    <h2>Usuarios registrados</h2>
                    <p>Vista de consulta para administradores.</p>
                </div>
            </div>
            <div class="admin-cat-table-wrap">
                <table class="admin-cat-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>DNI</th>
                            <th>Sede</th>
                            <th>Permisos</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$usuarios): ?>
                        <tr><td colspan="5" class="admin-cat-empty">No se encontraron usuarios.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($usuarios as $usuario): ?>
                        <?php
                        $userId = (int)($usuario['id_usuario'] ?? 0);
                        $nombre = trim((string)($usuario['nombre'] ?? ''));
                        $username = trim((string)($usuario['usuario'] ?? ''));
                        $displayName = $nombre !== '' ? $nombre : $username;
                        $photo = n360_admin_photo_data_uri($usuario['foto_usuario'] ?: ($usuario['foto_trabajador'] ?? ''));
                        $isAdmin = (string)($usuario['web_rol'] ?? '') === 'Admin';
                        $permisos = $permisosPorUsuario[$userId] ?? [];
                        ?>
                        <tr>
                            <td>
                                <div class="admin-cat-user">
                                    <span class="admin-cat-avatar">
                                        <?php if ($photo !== ''): ?>
                                            <img src="<?= $photo ?>" alt="Foto de <?= n360_admin_h($displayName) ?>">
                                        <?php else: ?>
                                            <?= n360_admin_h(n360_admin_initials($displayName)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span>
                                        <strong><?= n360_admin_h($displayName) ?></strong>
                                        <span>@<?= n360_admin_h($username) ?> · ID <?= $userId ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><span class="admin-cat-chip <?= $isAdmin ? 'admin-cat-chip--admin' : '' ?>"><?= n360_admin_h($usuario['web_rol'] ?? 'Usuario') ?></span></td>
                            <td><?= n360_admin_h($usuario['DNI'] ?? 'No registrado') ?></td>
                            <td><?= n360_admin_h($usuario['sede_nombre'] ?: ($usuario['clm_usuarios_sede'] ?? 'No asignada')) ?></td>
                            <td>
                                <div class="admin-cat-chip-list">
                                    <?php if ($isAdmin): ?>
                                        <span class="admin-cat-chip admin-cat-chip--admin">Acceso administrador</span>
                                    <?php elseif (!$permisos): ?>
                                        <span class="admin-cat-chip admin-cat-chip--muted">Sin asignaciones</span>
                                    <?php else: ?>
                                        <?php foreach ($permisos as $permiso): ?>
                                            <span class="admin-cat-chip">
                                                <?= n360_admin_h($permiso['module']) ?> · <?= n360_admin_h($permiso['view']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<?php n360_render_footer(); ?>
<?php n360_admin_render_close(); ?>
<?php $conn->close(); ?>
