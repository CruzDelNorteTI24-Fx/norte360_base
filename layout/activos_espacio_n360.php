<?php
function n360_render_asset_space_select(array $rows, string $id, bool $enabled, string $class, string $form = ''): void {
    $h = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    ?>
    <label class="<?= $h($class) ?>">
        <span>Área (opcional)</span>
        <select id="<?= $h($id) ?>Area" <?= $form !== '' ? 'form="' . $h($form) . '"' : '' ?> disabled>
            <option value="">Selecciona primero una sede</option>
        </select>
    </label>
    <label class="<?= $h($class) ?>">
        <span>Espacio</span>
        <select name="espacio_id" id="<?= $h($id) ?>" data-spaces-enabled="<?= $enabled ? '1' : '0' ?>"
                aria-describedby="<?= $h($id) ?>Help" <?= $form !== '' ? 'form="' . $h($form) . '"' : '' ?> disabled>
            <option value="">Selecciona primero un área</option>
            <?php foreach ($rows as $row): ?>
                <option value="<?= (int)$row['id'] ?>" data-sede="<?= (int)$row['sede_id'] ?>" data-active="<?= (int)$row['estado'] ?>"
                        data-area="<?= $h($row['area']) ?>" data-space="<?= $h($row['espacio'] ?? '') ?>"
                        data-personal="<?= $h(json_encode($row['personal'] ?? null, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"
                        data-name="<?= $h(alm_activos_espacio_nombre($row)) ?>"><?= $h(trim((string)($row['espacio'] ?? '')) !== '' ? $row['espacio'] : 'Solo área (sin espacio específico)') ?><?= (int)$row['estado'] !== 1 ? ' (inactivo)' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <small class="alm-help" id="<?= $h($id) ?>Help"><?= $enabled ? 'Elige sede, área y espacio.' : 'Los espacios por sede aún no están habilitados. Contacta al administrador.' ?></small>
    </label>
    <div class="n360-space-personnel" id="<?= $h($id) ?>Personal" data-title="Personal del espacio elegido" aria-live="polite" hidden></div>
    <?php
}
