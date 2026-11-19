<?php if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.'); ?>
<section class="enc-panel enc-kupos-panel" data-enc-kupos-panel hidden>
    <div class="enc-panel__head"><div><span>Reporte Kupos</span><h2>Importar encomiendas</h2></div>
        </div>
    <?php if (!$inventoryReady): ?>
        <div class="stock-alert stock-alert--warning">La actualización de inventario e importaciones está pendiente de instalación.</div>
    <?php endif; ?>
    <form data-enc-kupos-form enctype="multipart/form-data" action="actions/kupos.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>">
        <div class="enc-kupos-upload">
            <label class="stock-field"><span>Archivo CSV de Kupos *</span>
                <input type="file" name="archivo" accept=".csv,text/csv" required <?= !$inventoryReady ? 'disabled' : '' ?>>
                </label>
            <label class="stock-field"><span>Fecha y hora de corte del reporte *</span>
                <input type="datetime-local" name="fecha_corte" required max="<?= enc_h(date('Y-m-d\TH:i')) ?>" <?= !$inventoryReady ? 'disabled' : '' ?>>
                </label>
        </div>
        <div class="enc-kupos-toolbar">
            <p>La importación crea un control CE y actualiza el inventario conservando el código de cada envío.</p>
            <div><button class="stock-btn stock-btn--soft" type="button" data-kupos-validate disabled><i class="bi bi-check2-square"></i> Validar</button>
                <button class="stock-btn stock-btn--primary" type="submit" data-kupos-import disabled><i class="bi bi-cloud-arrow-up"></i> Importar</button></div>
        </div>
    </form>
    <div data-kupos-message class="enc-kupos-message" role="status" aria-live="polite"></div>
    <section data-kupos-preview hidden>
        <div class="enc-kupos-counts" data-kupos-counts></div>
        <div data-kupos-branch-note class="stock-alert stock-alert--warning" role="status" hidden></div>
        <div data-kupos-errors class="stock-alert stock-alert--danger" role="alert" hidden></div>
        <details data-kupos-warnings hidden><summary>Oficinas y avisos de validación</summary>
            <p>Los nombres originales se conservan. Las oficinas sin equivalencia siguen disponibles por su nombre en el inventario.</p>
            <ul data-kupos-warning-list></ul>
            <?php if (enc_is_admin()): ?><a href="../admin/encomiendas_oficinas.php" target="_blank" rel="noopener">Gestionar equivalencias en Administración</a><?php endif; ?>
        </details>
        <div class="enc-kupos-preview-filters">
            <label class="stock-field"><span>Resultado</span><select data-kupos-state><option value="">Todos</option><option value="NUEVO">Nuevos</option><option value="MODIFICADO">Modificados</option><option value="SIN_CAMBIOS">Sin cambios</option></select></label>
            <label class="stock-field"><span>Sucursal</span><select data-kupos-branch><option value="">Todas</option></select></label>
            <label class="stock-field"><span>Buscar</span><input type="search" data-kupos-search placeholder="Código, remitente o destinatario..."></label>
        </div>
        <div class="enc-kupos-table-wrap" tabindex="0" aria-label="Previsualización de los datos del CSV">
            <table class="stock-table enc-kupos-table"><thead><tr><th>Resultado</th><th>Sucursal</th>
                <?php foreach (enc_kupos_columns() as $label): ?><th><?= enc_h($label) ?></th><?php endforeach; ?>
            </tr></thead><tbody data-kupos-rows></tbody></table>
        </div>
        <div class="enc-kupos-pagination"><span data-kupos-page-info></span><div>
            <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-kupos-prev>Anterior</button>
            <button class="stock-btn stock-btn--soft stock-btn--sm" type="button" data-kupos-next>Siguiente</button>
        </div></div>
    </section>
</section>
<script type="application/json" id="encKuposColumns"><?= json_encode(enc_kupos_columns(),JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
