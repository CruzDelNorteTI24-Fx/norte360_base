<?php
if (!defined('N360_LAYOUT')) exit('Acceso no permitido.');

function n360_render_admin_catalogo_productos(string $label = 'Controles de productos'): void {
    ?>
    <dialog class="controles-catalog" id="controlesCatalogDialog" aria-labelledby="controlesCatalogTitle" data-api="<?= n360_admin_h(n360_base_url('admin/controles_productos_api.php')) ?>">
        <header class="controles-catalog__head"><div><span class="controles-catalog__kicker"><i class="bi bi-search" aria-hidden="true"></i> <?= n360_admin_h($label) ?></span><h2 id="controlesCatalogTitle">Catálogo de productos</h2><p id="controlesCatalogContext"></p></div><button type="button" class="controles-catalog__close" data-catalog-close aria-label="Cerrar catálogo"><span aria-hidden="true">&times;</span></button></header>
        <div class="controles-catalog__body">
            <div class="controles-catalog__filters" id="controlesCatalogFilters"></div>
            <form class="controles-catalog__search" id="controlesCatalogSearch" role="search"><label for="controlesCatalogQuery">Buscar en este catálogo</label><div><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="controlesCatalogQuery" maxlength="120" placeholder="Código, producto o categoría..." autocomplete="off"><button type="submit">Buscar</button></div></form>
            <p class="controles-catalog__status" id="controlesCatalogStatus" role="status" aria-live="polite"></p>
            <div class="controles-catalog__error" id="controlesCatalogError" role="alert" hidden><span></span><button type="button" id="controlesCatalogRetry">Reintentar</button></div>
            <div class="controles-catalog__table-wrap" tabindex="0" role="region" aria-label="Productos del catálogo">
                <table class="controles-catalog__table"><thead><tr><th scope="col">Código</th><th scope="col">Producto / categoría</th><th scope="col">Unidad</th><th scope="col">Área</th><th scope="col">Tipo</th></tr></thead><tbody id="controlesCatalogRows"></tbody></table>
            </div>
        </div>
        <footer class="controles-catalog__footer"><div class="controles-catalog__pagination"><button type="button" id="controlesCatalogPrev" aria-label="Página anterior">Anterior</button><span id="controlesCatalogPage"></span><button type="button" id="controlesCatalogNext" aria-label="Página siguiente">Siguiente</button></div><button type="button" data-catalog-close>Cerrar</button></footer>
    </dialog>
    <script src="<?= n360_asset('assets/js/admin_controles_productos_n360.js?controles=2') ?>"></script>
    <?php
}
