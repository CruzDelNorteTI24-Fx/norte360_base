<?php
ob_start();
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit;
}

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';
require_once __DIR__ . '/../layout/quick_scan_n360.php';
require_once __DIR__ . '/../layout/bus_lookup_n360.php';

if (!n360_puede_modulo(9) || !n360_puede_vista('registdialga-combust')) {
    header('Location: ../login/none_permisos.php?vista=' . urlencode('Registro de combustible'));
    exit;
}

define('ACCESS_GRANTED', true);
define('N360_COMB_REG', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
require_once __DIR__ . '/lib/combustible_registro_helpers.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('No se pudo conectar a la base de datos.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

if (empty($_SESSION['comb_reg_csrf'])) {
    $_SESSION['comb_reg_csrf'] = bin2hex(random_bytes(24));
}

$productos = [];
$grifos = [];
$stats = [
    'movimientos' => 0,
    'entradas' => 0.0,
    'salidas' => 0.0,
    'balance' => 0.0,
    'monto_entradas' => 0.0,
    'monto_salidas' => 0.0,
];
$recent = [];
$fuelStocks = [];
$buses = [];
$pageError = '';
$isAdmin = comb_reg_is_admin();

try {
    $productos = comb_reg_productos($conn);
    $grifos = comb_reg_grifos($conn);
    $stats = array_merge($stats, comb_reg_stats($conn));

    $firstGrifoCandidate = $grifos[0] ?? null;
    if ($firstGrifoCandidate) {
        $fuelStocks = comb_reg_stocks_by_grifo($conn, (int)$firstGrifoCandidate['id']);
    }

    if ($isAdmin) {
        $recent = comb_reg_recent($conn);
    }

    $buses = comb_reg_buses_catalog($conn);
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}

$csrf = (string)$_SESSION['comb_reg_csrf'];
$firstProduct = $productos[0] ?? null;
$firstGrifo = $grifos[0] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de combustible | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/loader_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/dialog_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/inventario_stock_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/combustible_registro_n360.css') ?>">
</head>
<body>
<?php n360_render_header(['title' => 'Combustible', 'subtitle' => 'Registro operativo']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module">
    <?php n360_render_content_separator('top'); ?>

    <div class="n360-main__inner n360-stock-page comb-reg-page"
         id="combRegPage"
         data-api="<?= comb_reg_h(n360_base_url('01_fcombustible/api/registro_combustible_api.php')) ?>"
         data-csrf="<?= comb_reg_h($csrf) ?>"
         data-is-admin="<?= $isAdmin ? '1' : '0' ?>">
        <section class="stock-hero comb-reg-hero">
            <div class="comb-reg-hero__main">
                <span class="comb-reg-logo" aria-hidden="true">
                    <img src="<?= comb_reg_h(n360_base_url('img/icons/combustible.png')) ?>" alt="">
                </span>
                <div>
                    <span class="stock-eyebrow"><i class="bi bi-fuel-pump-fill"></i> Combustible - movimiento operativo</span>
                    <h1>Registro de combustible</h1>
                </div>
            </div>
            <div class="stock-hero-actions comb-reg-hero__actions">
                <?php if ($isAdmin): ?>
                    <button class="stock-btn stock-btn--soft" type="button" data-recent-open>
                        <i class="bi bi-list-check"></i> Ultimos movimientos
                    </button>
                <?php endif; ?>
                <a class="stock-btn stock-btn--soft" href="<?= comb_reg_h(n360_base_url('01_fcombustible/historial_combustible.php')) ?>">
                    <i class="bi bi-clock-history"></i> Historial
                </a>
            </div>
        </section>

        <?php if ($pageError !== ''): ?>
            <div class="stock-alert stock-alert--danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= comb_reg_h($pageError) ?>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <section class="stock-kpis comb-reg-kpis" aria-label="Resumen de combustible de hoy">
                <article class="stock-kpi">
                    <span>Movimientos hoy</span>
                    <strong data-stat="movimientos"><?= number_format((int)$stats['movimientos']) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--green">
                    <span>Entradas hoy</span>
                    <strong data-stat="entradas"><?= comb_reg_h(comb_reg_fmt_qty($stats['entradas'])) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--red">
                    <span>Salidas hoy</span>
                    <strong data-stat="salidas"><?= comb_reg_h(comb_reg_fmt_qty($stats['salidas'])) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--blue">
                    <span>Balance hoy</span>
                    <strong data-stat="balance"><?= comb_reg_h(comb_reg_fmt_qty($stats['balance'])) ?></strong>
                </article>
                <article class="stock-kpi stock-kpi--amber">
                    <span>Monto salidas</span>
                    <strong data-stat="monto_salidas"><?= comb_reg_h(comb_reg_fmt_money($stats['monto_salidas'])) ?></strong>
                </article>
            </section>
        <?php endif; ?>

        <div class="comb-reg-toolbar" role="tablist" aria-label="Tipo de movimiento de combustible">
            <button type="button" class="comb-reg-mode is-active" data-mode-btn="entrada">
                <i class="bi bi-arrow-down-circle"></i>
                <span>Abastecimiento</span>
                <small>AB - Entrada</small>
            </button>
            <button type="button" class="comb-reg-mode" data-mode-btn="salida">
                <i class="bi bi-arrow-up-circle"></i>
                <span>Tanqueada</span>
                <small>CM - Salida</small>
            </button>
        </div>
        
        <section class="comb-reg-workbench">
            <aside class="comb-reg-card comb-reg-fuels">
                <header class="comb-reg-card__head">
                    <div>
                        <span>Combustibles del grifo</span>
                        <h2>Seleccion rapida</h2>
                    </div>
                    <i class="bi bi-droplet-half"></i>
                </header>

                <div class="comb-reg-search">
                    <i class="bi bi-search"></i>
                    <input type="search" id="fuelSearch" placeholder="Filtrar combustible..." autocomplete="off">
                </div>

                <select id="combProducto" class="comb-reg-native-control" aria-hidden="true" tabindex="-1">
                    <option value="">Selecciona combustible</option>
                    <?php foreach ($productos as $producto): ?>
                        <option value="<?= (int)$producto['id'] ?>"
                                data-code="<?= comb_reg_h($producto['codigo']) ?>"
                                data-name="<?= comb_reg_h($producto['nombre']) ?>"
                                data-unit="<?= comb_reg_h($producto['unidad']) ?>"
                                data-price="<?= comb_reg_h((string)$producto['precio_unitario']) ?>"
                                <?= $firstProduct && (int)$producto['id'] === (int)$firstProduct['id'] ? 'selected' : '' ?>>
                            <?= comb_reg_h('(' . $producto['codigo'] . ') ' . $producto['nombre'] . ' - ' . $producto['unidad']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="comb-reg-fuel-list" data-fuel-list>
                    <?php if (!$productos): ?>
                        <div class="stock-empty">No hay combustibles configurados.</div>
                    <?php else: ?>
                        <?php foreach ($productos as $producto): ?>
                            <?php
                                $pid = (int)$producto['id'];
                                $stockProducto = $fuelStocks[$pid] ?? 0;
                                $isActiveProduct = $firstProduct && $pid === (int)$firstProduct['id'];
                            ?>
                            <button type="button"
                                    class="comb-reg-fuel-btn<?= $isActiveProduct ? ' is-active' : '' ?>"
                                    data-product-button
                                    data-product-id="<?= $pid ?>"
                                    data-code="<?= comb_reg_h($producto['codigo']) ?>"
                                    data-name="<?= comb_reg_h($producto['nombre']) ?>"
                                    data-unit="<?= comb_reg_h($producto['unidad']) ?>"
                                    data-price="<?= comb_reg_h((string)$producto['precio_unitario']) ?>">
                                <span><?= comb_reg_h($producto['codigo']) ?></span>
                                <strong><?= comb_reg_h($producto['nombre']) ?></strong>
                                <small><?= comb_reg_h($producto['unidad']) ?></small>
                                <em><i class="bi bi-fuel-pump"></i> <b data-product-stock-badge="<?= $pid ?>"><?= comb_reg_h(comb_reg_fmt_qty($stockProducto)) ?></b></em>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="comb-reg-card comb-reg-center">


                <section class="comb-reg-grifos">
                    <div class="comb-reg-mini-title">
                        <span>Grifo / punto operativo</span>
                        <strong data-grifo-current><?= comb_reg_h($firstGrifo['label'] ?? 'Selecciona grifo') ?></strong>
                    </div>
                    <select id="combGrifo" class="comb-reg-native-control" aria-hidden="true" tabindex="-1">
                        <option value="">Selecciona grifo</option>
                        <?php foreach ($grifos as $grifo): ?>
                            <option value="<?= (int)$grifo['id'] ?>"
                                    data-label="<?= comb_reg_h($grifo['label']) ?>"
                                    <?= $firstGrifo && (int)$grifo['id'] === (int)$firstGrifo['id'] ? 'selected' : '' ?>>
                                <?= comb_reg_h($grifo['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="comb-reg-grifo-buttons" data-grifo-list>
                        <?php foreach ($grifos as $grifo): ?>
                            <?php $isActiveGrifo = $firstGrifo && (int)$grifo['id'] === (int)$firstGrifo['id']; ?>
                            <button type="button"
                                    class="comb-reg-grifo-btn<?= $isActiveGrifo ? ' is-active' : '' ?>"
                                    data-grifo-button
                                    data-grifo-id="<?= (int)$grifo['id'] ?>"
                                    data-label="<?= comb_reg_h($grifo['label']) ?>">
                                <i class="bi bi-geo-alt"></i>
                                <?= comb_reg_h($grifo['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="comb-reg-product-card">
                    <span class="comb-reg-product-card__code" data-product-code>--</span>
                    <div>
                        <strong data-product-name>Selecciona un combustible</strong>
                        <small data-product-unit>Unidad</small>
                    </div>
                </section>

                <form class="comb-reg-process is-active" id="entradaForm" autocomplete="off" data-process="entrada">
                    <header class="comb-reg-section-head">
                        <div>
                            <span>AB - Entrada</span>
                            <h2>Registrar abastecimiento</h2>
                        </div>
                        <span class="comb-reg-badge comb-reg-badge--entrada">Ingreso a grifo</span>
                    </header>

                    <div class="comb-reg-fields comb-reg-fields--entrada">
                        <label class="stock-field">
                            <span>Cantidad</span>
                            <input type="number" id="entradaCantidad" min="0" step="0.0001" inputmode="decimal" placeholder="0.0000">
                        </label>
                        <label class="stock-field comb-reg-field-wide">
                            <span>Suministrador</span>
                            <input type="text" id="entradaAbastecedor" autocomplete="off" placeholder="Proveedor o responsable del abastecimiento">
                        </label>
                        <label class="stock-field comb-reg-field-wide">
                            <span>Observacion / motivo</span>
                            <textarea id="entradaObs" rows="3" placeholder="Detalle operativo del abastecimiento"></textarea>
                        </label>
                    </div>

                    <footer class="comb-reg-actions">
                        <button type="button" class="stock-btn stock-btn--soft" data-reset-form="entrada">
                            <i class="bi bi-eraser"></i> Limpiar
                        </button>
                        <button type="submit" class="stock-btn stock-btn--primary" id="btnGuardarEntrada">
                            <i class="bi bi-save2"></i> Guardar AB
                        </button>
                    </footer>
                </form>

                <form class="comb-reg-process" id="salidaForm" autocomplete="off" data-process="salida">
                    <header class="comb-reg-section-head">
                        <div>
                            <span>CM - Salida</span>
                            <h2>Registrar tanqueada</h2>
                        </div>
                        <span class="comb-reg-badge comb-reg-badge--salida">Salida a unidad</span>
                    </header>

                    <div class="comb-reg-fields comb-reg-fields--salida">
                        <label class="stock-field comb-reg-lookup">
                            <span>Unidad / bus</span>
                            <div class="comb-reg-input-action">
                                <input type="text" id="salidaBusSearch" autocomplete="off" placeholder="Ej. 158 o ABC-321">
                                <button type="button" class="comb-reg-icon-btn" data-open-chooser="bus" title="Buscar unidad">
                                    <i class="bi bi-bus-front"></i>
                                </button>
                            </div>
                            <input type="hidden" id="salidaBusId">
                            <div class="comb-reg-results" data-bus-results hidden></div>
                        </label>
                        <label class="stock-field comb-reg-lookup">
                            <span>Conductor</span>
                            <div class="comb-reg-input-action">
                                <input type="text" id="salidaConductorSearch" autocomplete="off" placeholder="Nombre o DNI">
                                <button type="button" class="comb-reg-icon-btn" data-open-chooser="conductor" title="Buscar conductor">
                                    <i class="bi bi-person-vcard"></i>
                                </button>
                            </div>
                            <input type="hidden" id="salidaConductorId">
                            <div class="comb-reg-results" data-conductor-results hidden></div>
                        </label>
                        <label class="stock-field">
                            <span>Cantidad</span>
                            <input type="number" id="salidaCantidad" min="0" step="0.0001" inputmode="decimal" placeholder="0.0000">
                        </label>
                        <label class="stock-field comb-reg-field-wide">
                            <span>Observacion</span>
                            <textarea id="salidaObs" rows="3" placeholder="Detalle visible en la nota CM"></textarea>
                        </label>
                    </div>

                    <div class="comb-reg-selected">
                        <article>
                            <span>Unidad seleccionada</span>
                            <strong data-selected-bus>Sin seleccionar</strong>
                        </article>
                        <article>
                            <span>Conductor seleccionado</span>
                            <strong data-selected-conductor>Sin seleccionar</strong>
                        </article>
                    </div>

                    <footer class="comb-reg-actions">
                        <button type="button" class="stock-btn stock-btn--soft" data-reset-form="salida">
                            <i class="bi bi-eraser"></i> Limpiar
                        </button>
                        <button type="submit" class="stock-btn stock-btn--primary" id="btnGuardarSalida">
                            <i class="bi bi-save2"></i> Guardar CM
                        </button>
                    </footer>
                </form>
            </section>

            <aside class="comb-reg-money">
                <section class="comb-reg-card comb-reg-money-card">
                    <header class="comb-reg-card__head">
                        <div>
                            <span>Stock operativo</span>
                            <h2>Cantidades</h2>
                        </div>
                        <i class="bi bi-speedometer2"></i>
                    </header>
                    <div class="comb-reg-metrics">
                        <article>
                            <span>Stock producto/grifo</span>
                            <strong data-stock-producto>0</strong>
                        </article>
                        <article>
                            <span>Stock total grifo</span>
                            <strong data-stock-grifo>0</strong>
                        </article>
                        <article class="comb-reg-stock-estimate is-active" data-stock-estimate="entrada">
                            <span>Saldo estimado tras entrada</span>
                            <strong class="comb-reg-estimate-value" id="entradaSaldoEstimado">0</strong>
                        </article>
                        <article class="comb-reg-stock-estimate" data-stock-estimate="salida">
                            <span>Saldo estimado tras salida</span>
                            <strong class="comb-reg-estimate-value" id="salidaSaldoEstimado">0</strong>
                        </article>
                    </div>
                </section>

                <section class="comb-reg-card comb-reg-money-card is-active" data-money-process="entrada">
                    <header class="comb-reg-card__head">
                        <div>
                            <span>Valores AB</span>
                            <h2>Compra / abastecimiento</h2>
                        </div>
                        <i class="bi bi-cash-coin"></i>
                    </header>
                    <label class="stock-field">
                        <span>Precio unitario</span>
                        <input type="number" id="entradaPrecio" min="0" step="0.0001" inputmode="decimal" placeholder="0.0000">
                    </label>
                    <div class="comb-reg-account-line" aria-hidden="true"></div>
                    <label class="stock-field">
                        <span>Monto total entrada</span>
                        <input type="text" id="entradaMonto" readonly value="S/ 0.0000">
                    </label>
                </section>

                <section class="comb-reg-card comb-reg-money-card" data-money-process="salida">
                    <header class="comb-reg-card__head">
                        <div>
                            <span>Valores CM</span>
                            <h2>Salida / tanqueada</h2>
                        </div>
                        <i class="bi bi-calculator"></i>
                    </header>
                    <label class="stock-field">
                        <span>Precio Unit. (ref.)</span>
                        <input type="text" id="salidaPuRef" readonly value="S/ 0.0000">
                    </label>
                    <label class="stock-field">
                        <span>Precio Unit. (add.)</span>
                        <input type="number" id="salidaPuExtra" min="0" step="0.0001" inputmode="decimal" placeholder="0.0000">
                    </label>
                    <label class="stock-field">
                        <span>Precio Unit. final</span>
                        <input type="text" id="salidaPuFinal" readonly value="S/ 0.0000">
                    </label>
                    <div class="comb-reg-account-line" aria-hidden="true"></div>
                    <label class="stock-field comb-reg-total-field">
                        <span>Monto (Total) salida</span>
                        <input type="text" id="salidaMonto" readonly value="S/ 0.0000">
                    </label>
                </section>
            </aside>
        </section>
    </div>

    <?php n360_render_content_separator('bottom'); ?>
</main>

<?php if ($isAdmin): ?>
    <div class="comb-reg-modal comb-reg-plate-modal" id="combPlateModal" aria-hidden="true">
        <div class="comb-reg-modal__backdrop" data-plate-close></div>
        <section class="comb-reg-modal__panel comb-reg-plate-modal__panel" role="dialog" aria-modal="true" aria-labelledby="combPlateTitle">
            <header class="comb-reg-modal__head">
                <div>
                    <span>Unidad no encontrada</span>
                    <h2 id="combPlateTitle">Agregar nueva placa</h2>
                </div>
                <button type="button" class="comb-reg-modal__close" data-plate-close aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>
            <form class="comb-reg-plate-form" id="combPlateForm" autocomplete="off">
                <p class="comb-reg-plate-form__hint">
                    Se creara la unidad activa y quedara seleccionada para la tanqueada actual.
                </p>
                <label class="stock-field">
                    <span>Placa</span>
                    <input type="text" id="combPlateInput" maxlength="10" autocomplete="new-password" placeholder="ABC-123">
                </label>
                <label class="stock-field">
                    <span>Nombre / bus</span>
                    <input type="text" id="combPlateName" maxlength="80" autocomplete="new-password" placeholder="ABC123">
                </label>
                <div class="comb-reg-plate-form__status" id="combPlateStatus">Formato sugerido: letras y numeros. Ejemplo ABC-123.</div>
                <footer class="comb-reg-actions">
                    <button type="button" class="stock-btn stock-btn--soft" data-plate-close>
                        Cancelar
                    </button>
                    <button type="submit" class="stock-btn stock-btn--primary" id="combPlateSave">
                        <i class="bi bi-plus-circle"></i> Crear placa
                    </button>
                </footer>
            </form>
        </section>
    </div>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <div class="comb-reg-modal" id="combRecentModal" aria-hidden="true">
        <div class="comb-reg-modal__backdrop" data-recent-close></div>
        <section class="comb-reg-modal__panel" role="dialog" aria-modal="true" aria-labelledby="combRecentTitle">
            <header class="comb-reg-modal__head">
                <div>
                    <span>Solo administradores</span>
                    <h2 id="combRecentTitle">Ultimos movimientos</h2>
                </div>
                <div class="comb-reg-modal__actions">
                    <button type="button" class="stock-btn stock-btn--soft" data-recent-refresh>
                        <i class="bi bi-arrow-clockwise"></i> Actualizar
                    </button>
                    <button type="button" class="comb-reg-modal__close" data-recent-close aria-label="Cerrar">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </header>
            <div class="stock-table-wrap comb-reg-modal__table">
                <table class="stock-table comb-reg-recent-table">
                    <thead>
                        <tr>
                            <th>Nota</th>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Grifo</th>
                            <th>Combustible</th>
                            <th class="stock-num">Cant.</th>
                            <th class="stock-num">Monto</th>
                        </tr>
                    </thead>
                    <tbody data-recent-body>
                        <?php if (!$recent): ?>
                            <tr><td colspan="7" class="stock-empty">Aun no hay movimientos recientes.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent as $row): ?>
                                <tr>
                                    <td><span class="stock-code"><?= comb_reg_h($row['nota'] ?? '-') ?></span></td>
                                    <td><?= comb_reg_h(comb_reg_fecha_display($row['fecha'] ?? '')) ?></td>
                                    <td><span class="comb-reg-chip comb-reg-chip--<?= comb_reg_h(strtolower((string)($row['tipo'] ?? ''))) ?>"><?= comb_reg_h($row['tipo'] ?? '-') ?></span></td>
                                    <td><?= comb_reg_h(trim((string)($row['grifo_codigo'] ?? '') . ' ' . (string)($row['grifo_nombre'] ?? ''))) ?></td>
                                    <td><?= comb_reg_h(trim((string)($row['codigo'] ?? '') . ' ' . (string)($row['producto'] ?? ''))) ?></td>
                                    <td class="stock-num"><?= comb_reg_h(comb_reg_fmt_qty($row['cantidad'] ?? 0)) ?></td>
                                    <td class="stock-num"><?= comb_reg_h(comb_reg_fmt_money($row['monto'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php endif; ?>

<div class="comb-reg-modal comb-reg-chooser" id="combChooserModal" aria-hidden="true">
    <div class="comb-reg-modal__backdrop" data-chooser-close></div>
    <section class="comb-reg-modal__panel comb-reg-chooser__panel" role="dialog" aria-modal="true" aria-labelledby="combChooserTitle">
        <header class="comb-reg-modal__head">
            <div>
                <span data-chooser-eyebrow>Seleccion operativa</span>
                <h2 id="combChooserTitle">Buscar</h2>
            </div>
            <button type="button" class="comb-reg-modal__close" data-chooser-close aria-label="Cerrar">
                <i class="bi bi-x-lg"></i>
            </button>
        </header>
        <div class="comb-reg-chooser__search">
            <i class="bi bi-search"></i>
            <input type="search" id="combChooserInput" autocomplete="off" placeholder="Buscar...">
        </div>
        <div class="comb-reg-chooser__status" id="combChooserStatus">Escribe para buscar.</div>
        <div class="comb-reg-chooser__results" id="combChooserResults"></div>
    </section>
</div>

<?php n360_render_quick_scan(); ?>
<?php n360_render_bus_lookup(); ?>
<?php n360_render_footer(); ?>

<script>
window.N360_COMB_REG_BOOTSTRAP = {
    productos: <?= json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    grifos: <?= json_encode($grifos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    buses: <?= json_encode($buses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    stats: <?= json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    recent: <?= json_encode($recent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    fuelStocks: <?= json_encode($fuelStocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    isAdmin: <?= $isAdmin ? 'true' : 'false' ?>
};
window.N360_NOTA_PDF_CONFIG = {
    endpoint: '<?= comb_reg_h(n360_base_url('php/nota_pdf_data.php')) ?>',
    userName: <?= json_encode((string)($_SESSION['usuario'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dni: <?= json_encode((string)($_SESSION['DNI'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    logoTicket: '<?= comb_reg_h(n360_base_url('img/completo.png')) ?>',
    footerLabel: 'NORTE 360'
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_notas_common.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_tanqueada.js') ?>"></script>
<script src="<?= n360_asset('assets/js/formatos/notas/n360_nota_abastecimiento.js') ?>"></script>
<script src="<?= n360_asset('assets/js/nota_pdf_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/loader_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/dialog_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/combustible_registro_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/quick_scan_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
</body>
</html>
