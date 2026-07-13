<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/assets_n360.php';

if (!function_exists('n360_bus_lookup_allowed')) {
    function n360_bus_lookup_allowed(): bool {
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
        return in_array(10, $permisos, true) || in_array(5, $permisos, true);
    }
}

if (!function_exists('n360_render_bus_lookup')) {
    function n360_render_bus_lookup(): void {
        static $rendered = false;

        if ($rendered || !n360_bus_lookup_allowed()) {
            return;
        }

        $rendered = true;
        $endpoint = htmlspecialchars(n360_base_url('01_flota/bus_lookup_api.php'), ENT_QUOTES, 'UTF-8');

        echo '
            <link rel="stylesheet" href="' . n360_asset('assets/css/bus_lookup_n360.css') . '">

            <button class="n360-bus-lookup-fab" type="button" aria-label="Consultar unidad" data-n360-bus-open>
                <span class="n360-bus-lookup-fab__road"></span>
                <span class="n360-bus-lookup-fab__icon"><i class="bi bi-bus-front-fill"></i></span>
            </button>

            <div class="n360-bus-lookup" id="n360BusLookupModal" aria-hidden="true" data-endpoint="' . $endpoint . '">
                <div class="n360-bus-lookup__backdrop" data-n360-bus-close></div>
                <section class="n360-bus-lookup__panel" role="dialog" aria-modal="true" aria-labelledby="n360BusLookupTitle">
                    <header class="n360-bus-lookup__header">
                        <div class="n360-bus-lookup__mark">
                            <i class="bi bi-bus-front-fill"></i>
                        </div>
                        <div>
                            <p class="n360-bus-lookup__eyebrow">Operacion en vivo</p>
                            <h2 id="n360BusLookupTitle" style="background-color: #0000; color: white; padding: 10px;">Consultar unidad</h2>
                        </div>
                        <button class="n360-bus-lookup__close" type="button" aria-label="Cerrar consulta" data-n360-bus-close>
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <form class="n360-bus-lookup__form" id="n360BusLookupForm" autocomplete="off">
                        <label class="n360-bus-lookup__label" for="n360BusLookupInput">Bus, placa o dueno</label>
                        <div class="n360-bus-lookup__input-wrap">
                            <i class="bi bi-search"></i>
                            <input id="n360BusLookupInput" name="q" type="text" inputmode="text" autocomplete="off" placeholder="Ej. BUS 158, ABC-321..." spellcheck="false">
                            <button type="submit">
                                <i class="bi bi-arrow-return-left"></i>
                                <span>Consultar</span>
                            </button>
                        </div>
                    </form>

                    <div class="n360-bus-lookup__status" id="n360BusLookupStatus" role="status" aria-live="polite">
                        Busca una unidad para ver su estado operativo.
                    </div>

                    <div class="n360-bus-lookup__result" id="n360BusLookupResult"></div>
                </section>
            </div>

            <script src="' . n360_asset('assets/js/bus_lookup_n360.js') . '"></script>
        ';
    }
}
