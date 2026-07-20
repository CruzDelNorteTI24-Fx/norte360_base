<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

if (!function_exists('n360_alm_h')) {
    function n360_alm_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('n360_render_almacen_product_catalog')) {
    function n360_render_almacen_product_catalog(): void {
        ?>
        <div class="alm-modal alm-catalog" id="almProductCatalog" aria-hidden="true" data-alm-modal="catalog">
            <div class="alm-modal__backdrop" data-alm-modal-close></div>
            <section class="alm-modal__panel alm-catalog__panel" role="dialog" aria-modal="true" aria-labelledby="almProductCatalogTitle">
                <header class="alm-modal__header">
                    <div class="alm-modal__mark">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <div>
                        <p class="alm-modal__eyebrow">Catalogo reutilizable</p>
                        <h2 id="almProductCatalogTitle">Seleccionar producto</h2>
                    </div>
                    <button class="alm-modal__close" type="button" aria-label="Cerrar catalogo" data-alm-modal-close>
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <div class="alm-catalog__tools">
                    <label class="alm-field alm-field--search" for="almCatalogSearch">
                        <span>Buscar</span>
                        <i class="bi bi-search"></i>
                        <input id="almCatalogSearch" type="search" autocomplete="off" placeholder="Codigo, producto, categoria...">
                    </label>
                    <div class="alm-catalog__hint">
                        <i class="bi bi-mouse2"></i>
                        <span>Doble click o seleccionar para usar el producto.</span>
                    </div>
                    <div class="alm-catalog__origin" id="almCatalogOrigin">
                        <i class="bi bi-compass"></i>
                        <span>Origen pendiente</span>
                    </div>
                    <button class="alm-btn alm-btn--primary alm-catalog__new" type="button" data-n360-product-create-open>
                        <i class="bi bi-plus-circle"></i>
                        <span>Nuevo producto</span>
                    </button>
                </div>

                <div class="alm-catalog__status" id="almCatalogStatus" role="status" aria-live="polite">
                    Abre el catalogo para cargar productos.
                </div>

                <div class="alm-table-wrap alm-catalog__table">
                    <table class="alm-table">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Producto</th>
                                <th>Categoria</th>
                                <th>Unidad</th>
                                <th>Stock</th>
                                <th>P. unit.</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="almCatalogRows">
                            <tr>
                                <td colspan="7" class="alm-table__empty">Sin busqueda activa.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
    }
}

if (!function_exists('n360_render_almacen_salida_modal')) {
    function n360_render_almacen_salida_modal(): void {
        ?>
        <div class="alm-modal alm-salida" id="almSalidaModal" aria-hidden="true" data-alm-modal="salida">
            <div class="alm-modal__backdrop" data-alm-modal-close></div>
            <section class="alm-modal__panel alm-salida__panel" role="dialog" aria-modal="true" aria-labelledby="almSalidaTitle">
                <header class="alm-modal__header">
                    <div class="alm-modal__mark alm-modal__mark--danger">
                        <i class="bi bi-box-arrow-up"></i>
                    </div>
                    <div>
                        <p class="alm-modal__eyebrow" id="almSalidaEyebrow">Nota de salida NS</p>
                        <h2 id="almSalidaTitle">Armar salida de almacen</h2>
                    </div>
                    <button class="alm-modal__close" type="button" aria-label="Cerrar salida" data-alm-modal-close>
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <form class="alm-salida__body" id="almSalidaForm" autocomplete="off">
                    <div class="alm-salida__grid">
                        <div class="alm-field alm-field--lookup" id="almSalidaBusField">
                            <span>Unidad / bus</span>
                            <div class="alm-bus-lock-row">
                                <input id="almSalidaBusInput" name="bus_texto" type="text" autocomplete="off" placeholder="Ej. 158 o ABC-321">
                                <button class="alm-bus-lock-btn" type="button" id="almSalidaBlockBus" aria-pressed="false">
                                    <i class="bi bi-slash-circle"></i>
                                    <span>Sin bus</span>
                                </button>
                            </div>
                            <input id="almSalidaPlacaId" name="placa_id" type="hidden">
                            <input id="almSalidaBusBloqueado" name="bus_bloqueado" type="hidden" value="0">
                            <small class="alm-help">Usa "Sin bus" cuando la nota no debe asociarse a una unidad.</small>
                            <div class="alm-suggest" id="almSalidaBusSuggest" hidden></div>
                        </div>
                        <div class="alm-field alm-field--worker">
                            <span>Entregado a</span>
                            <div class="alm-worker-input">
                                <input id="almSalidaEntregado" name="entregado_a" type="text" placeholder="Taller, responsable o destino" autocomplete="off">
                                <button class="alm-icon-btn" type="button" id="almOpenWorkerSearch" aria-label="Buscar trabajador">
                                    <i class="bi bi-person-check"></i>
                                </button>
                            </div>
                            <input id="almSalidaPersonalId" name="personal_id" type="hidden">
                            <aside class="alm-worker-panel" id="almWorkerPanel" hidden>
                                <div class="alm-worker-panel__head">
                                    <strong>Seleccionar personal</strong>
                                    <button type="button" class="alm-icon-btn" id="almCloseWorkerPanel" aria-label="Cerrar buscador de personal">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                                <label class="alm-field alm-field--search">
                                    <span>Buscar trabajador</span>
                                    <i class="bi bi-search"></i>
                                    <input id="almWorkerSearch" type="search" autocomplete="off" placeholder="Nombre, DNI o cargo...">
                                </label>
                                <div class="alm-worker-panel__rows" id="almWorkerRows">
                                    <p>Busca por nombre o DNI para asignar el responsable.</p>
                                </div>
                            </aside>
                        </div>
                        <label class="alm-field alm-field--wide">
                            <span>Motivo</span>
                            <textarea id="almSalidaMotivo" name="motivo" rows="2" placeholder="Motivo de la salida..."></textarea>
                        </label>
                    </div>

                    <div class="alm-salida__picker">
                        <div class="alm-selected-product alm-selected-product--compact" id="almSalidaProductBox">
                            <span class="alm-selected-product__empty">Selecciona un producto para agregarlo a la nota.</span>
                        </div>
                        <div class="alm-salida__qty">
                            <label class="alm-field">
                                <span>Cantidad</span>
                                <input id="almSalidaCantidad" type="number" min="0.001" step="0.001" placeholder="0">
                            </label>
                            <button class="alm-btn alm-btn--soft" type="button" data-alm-open-catalog data-target="salida">
                                <i class="bi bi-search"></i>
                                <span>Producto</span>
                            </button>
                            <button class="alm-btn alm-btn--primary" type="button" id="almSalidaAddItem">
                                <i class="bi bi-plus-lg"></i>
                                <span>Agregar</span>
                            </button>
                        </div>
                    </div>

                    <div class="alm-table-wrap alm-salida__items">
                        <table class="alm-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Producto</th>
                                    <th>Unidad</th>
                                    <th>Cantidad</th>
                                    <th>P. unit.</th>
                                    <th>Monto</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="almSalidaItems">
                                <tr>
                                    <td colspan="7" class="alm-table__empty">Aun no hay items en la salida.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="alm-salida__footer">
                        <label class="alm-check">
                            <input id="almSalidaConfirm" type="checkbox">
                            <span>Confirmo que los items y cantidades estan correctos.</span>
                        </label>
                        <div class="alm-salida__actions">
                            <button class="alm-btn alm-btn--soft alm-btn--debug" type="button" id="almSalidaDebug">
                                <i class="bi bi-terminal"></i>
                                <span>Pruebas</span>
                            </button>
                            <button class="alm-btn alm-btn--ghost" type="button" data-alm-modal-close>
                                <i class="bi bi-x-circle"></i>
                                <span>Cancelar</span>
                            </button>
                            <button class="alm-btn alm-btn--danger" type="submit" id="almSalidaSubmit" disabled>
                                <i class="bi bi-receipt-cutoff"></i>
                                <span>Registrar salida</span>
                            </button>
                        </div>
                    </footer>
                </form>
            </section>
        </div>
        <?php
    }
}
