<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/assets_n360.php';

if (!function_exists('n360_quick_scan_allowed')) {
    function n360_quick_scan_allowed(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if (($_SESSION['web_rol'] ?? '') === 'Admin') {
            return true;
        }

        $permisos = $_SESSION['permisos'] ?? [];
        if ($permisos === 'all') {
            return true;
        }

        $permisos = array_map('intval', (array)$permisos);
        return in_array(3, $permisos, true);
    }
}

if (!function_exists('n360_render_quick_scan')) {
    function n360_render_quick_scan(): void {
        static $rendered = false;

        if ($rendered || !n360_quick_scan_allowed()) {
            return;
        }

        $rendered = true;
        $endpoint = htmlspecialchars(n360_base_url('01_almacen/quick_scan_api.php'), ENT_QUOTES, 'UTF-8');
        $imageEndpoint = htmlspecialchars(n360_base_url('01_almacen/scanner.php?img_prod='), ENT_QUOTES, 'UTF-8');
        $barcodeLogo = htmlspecialchars(n360_base_url('img/completo.png'), ENT_QUOTES, 'UTF-8');

        echo '
            <link rel="stylesheet" href="' . n360_asset('assets/css/barcode_n360.css') . '">
            <link rel="stylesheet" href="' . n360_asset('assets/css/quick_scan_n360.css') . '">
            <div class="n360-quick-scan" id="n360QuickScanModal" aria-hidden="true" data-endpoint="' . $endpoint . '" data-image-endpoint="' . $imageEndpoint . '" data-barcode-logo="' . $barcodeLogo . '">
                <div class="n360-quick-scan__backdrop" data-n360-qs-close></div>
                <section class="n360-quick-scan__panel" role="dialog" aria-modal="true" aria-labelledby="n360QuickScanTitle">
                    <header class="n360-quick-scan__header">
                        <div class="n360-quick-scan__mark">
                            <i class="bi bi-upc-scan"></i>
                        </div>
                        <div>
                            <p class="n360-quick-scan__eyebrow">Lector rapido</p>
                            <h2 id="n360QuickScanTitle">Consultar producto</h2>
                        </div>
                        <button class="n360-quick-scan__close" type="button" aria-label="Cerrar lector" data-n360-qs-close>
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <form class="n360-quick-scan__form" id="n360QuickScanForm" autocomplete="off">
                        <label class="n360-quick-scan__label" for="n360QuickScanInput">Codigo o etiqueta</label>
                        <div class="n360-quick-scan__input-wrap">
                            <i class="bi bi-search"></i>
                            <input id="n360QuickScanInput" name="codigo" type="text" inputmode="text" autocomplete="off" placeholder="Escanea o escribe el codigo..." spellcheck="false">
                            <button type="submit">
                                <i class="bi bi-arrow-return-left"></i>
                                <span>Consultar</span>
                            </button>
                        </div>
                        <div class="n360-quick-scan__hint">
                            <span><kbd>F2</kbd> abre este lector</span>
                            <span><kbd>Enter</kbd> consulta el producto</span>
                        </div>
                    </form>

                    <div class="n360-quick-scan__status" id="n360QuickScanStatus" role="status" aria-live="polite">
                        Listo para escanear.
                    </div>

                    <div class="n360-quick-scan__result" id="n360QuickScanResult"></div>
                </section>
            </div>
            <script src="' . n360_asset('assets/js/barcode_n360.js') . '"></script>
            <script src="' . n360_asset('assets/js/quick_scan_n360.js') . '"></script>
        ';
    }
}
