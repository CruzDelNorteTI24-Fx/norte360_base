<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

if (!function_exists('n360_prod_h')) {
    function n360_prod_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('n360_render_product_create_config')) {
    function n360_render_product_create_config(array $options = []): void {
        $api = $options['api'] ?? (function_exists('n360_base_url') ? n360_base_url('01_almacen/movimiento_api.php') : 'movimiento_api.php');
        $csrf = $options['csrf'] ?? ($_SESSION['alm_mov_csrf'] ?? '');
        $originId = (int)($options['origin_id'] ?? 1);
        $originLabel = $options['origin_label'] ?? ($originId === 4 ? 'RRHH' : ($originId === 12 ? 'CONTABILIDAD' : 'ALMACEN (ALM)'));
        $originArea = $options['origin_area'] ?? ($originId === 4 ? 'RRHH' : ($originId === 12 ? 'ACTIVOS' : 'ALMACEN'));
        $originTipo = $options['origin_tipo'] ?? ($originId === 4 ? 'BIEN_CONTROLADO' : ($originId === 12 ? 'ACTIVO_FIJO' : 'CONSUMIBLE'));
        $isAdmin = !empty($options['is_admin']);
        $afterCreate = $options['after_create'] ?? 'event';
        ?>
        <div id="n360ProductCreateConfig"
             hidden
             data-product-api="<?= n360_prod_h($api) ?>"
             data-product-csrf="<?= n360_prod_h($csrf) ?>"
             data-product-origin-id="<?= n360_prod_h($originId) ?>"
             data-product-origin-label="<?= n360_prod_h($originLabel) ?>"
             data-product-origin-area="<?= n360_prod_h($originArea) ?>"
             data-product-origin-tipo="<?= n360_prod_h($originTipo) ?>"
             data-product-is-admin="<?= $isAdmin ? '1' : '0' ?>"
             data-product-after-create="<?= n360_prod_h($afterCreate) ?>"></div>
        <?php
    }
}

if (!function_exists('n360_render_product_create_modal')) {
    function n360_render_product_create_modal(): void {
        ?>
        <div class="alm-modal n360-product-create" id="n360ProductCreateModal" aria-hidden="true" data-alm-modal="product-create">
            <div class="alm-modal__backdrop" data-alm-modal-close data-n360-product-create-close></div>
            <section class="alm-modal__panel n360-product-create__panel" role="dialog" aria-modal="true" aria-labelledby="n360ProductCreateTitle">
                <header class="alm-modal__header">
                    <div class="alm-modal__mark">
                        <i class="bi bi-plus-square-dotted"></i>
                    </div>
                    <div>
                        <p class="alm-modal__eyebrow">Catalogo reutilizable</p>
                        <h2 id="n360ProductCreateTitle">Nuevo producto</h2>
                    </div>
                    <button class="alm-modal__close" type="button" aria-label="Cerrar nuevo producto" data-alm-modal-close data-n360-product-create-close>
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <form class="n360-product-create__form" id="n360ProductCreateForm" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="csrf" id="n360ProductCreateCsrf">
                    <input type="hidden" name="orgn_id" id="n360ProductCreateOriginId">
                    <input type="hidden" name="area_control" id="n360ProductCreateArea">
                    <input type="hidden" name="tipo_control" id="n360ProductCreateTipo">

                    <div class="n360-product-create__context">
                        <span><i class="bi bi-compass"></i> Origen: <strong id="n360ProductCreateOriginText">ALMACEN</strong></span>
                        <span><i class="bi bi-sliders"></i> Control: <strong id="n360ProductCreateControlText">ALMACEN / CONSUMIBLE</strong></span>
                    </div>

                    <div class="n360-product-create__grid">
                        <label class="alm-field alm-field--span-6">
                            <span>Categoria *</span>
                            <select name="categoria_id" id="n360ProductCreateCategoria" required>
                                <option value="">Cargando categorias...</option>
                            </select>
                            <small class="alm-help">La categoria combustible no se muestra en este registro.</small>
                        </label>

                        <label class="alm-field alm-field--span-6">
                            <span>Unidad *</span>
                            <input name="unidad" id="n360ProductCreateUnidad" type="text" maxlength="30" placeholder="UN, GLN, UND..." required>
                        </label>

                        <label class="alm-field alm-field--span-8">
                            <span>Nombre del producto *</span>
                            <input name="nombre" id="n360ProductCreateNombre" type="text" maxlength="220" placeholder="Nombre operativo del producto" required>
                        </label>

                        <label class="alm-field alm-field--span-4">
                            <span>Stock minimo *</span>
                            <input name="stock_minimo" id="n360ProductCreateStockMin" type="number" min="0" step="0.001" value="0" required>
                        </label>

                        <label class="alm-field alm-field--span-12">
                            <span>Descripcion</span>
                            <textarea name="descripcion" id="n360ProductCreateDescripcion" rows="3" maxlength="900" placeholder="Detalle, marca, modelo o especificacion util..."></textarea>
                        </label>

                        <label class="alm-field alm-field--span-12 n360-product-create__file">
                            <span>Imagen del producto</span>
                            <input name="imagen" id="n360ProductCreateImagen" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                            <small class="alm-help" id="n360ProductCreateFileHint">Opcional. Maximo 4 MB.</small>
                        </label>
                    </div>

                    <div class="n360-product-create__status" id="n360ProductCreateStatus" role="status" aria-live="polite">
                        Completa los datos para crear el producto.
                    </div>

                    <footer class="n360-product-create__actions">
                        <button class="alm-btn alm-btn--ghost" type="button" data-alm-modal-close data-n360-product-create-close>
                            <i class="bi bi-x-circle"></i>
                            <span>Cancelar</span>
                        </button>
                        <button class="alm-btn alm-btn--primary" type="submit" id="n360ProductCreateSubmit">
                            <i class="bi bi-save2"></i>
                            <span>Guardar producto</span>
                        </button>
                    </footer>
                </form>
            </section>
        </div>
        <?php
    }
}
