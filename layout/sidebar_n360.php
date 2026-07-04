<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

function n360_base_url(string $path = ''): string {
    $base = defined('N360_BASE_URL') ? N360_BASE_URL : './';
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function n360_is_admin(): bool {
    return ($_SESSION['web_rol'] ?? '') === 'Admin';
}

function n360_permisos(): array {
    return ($_SESSION['permisos'] ?? []) === 'all'
        ? []
        : (array)($_SESSION['permisos'] ?? []);
}

function n360_vistas(): array {
    return ($_SESSION['permisos'] ?? []) === 'all'
        ? []
        : (array)($_SESSION['vistas'] ?? []);
}

function n360_puede_modulo(int $idModulo): bool {
    return n360_is_admin() || in_array($idModulo, n360_permisos());
}

function n360_puede_vista(string $vista): bool {
    return n360_is_admin() || in_array($vista, n360_vistas());
}

function n360_puede_item(array $item): bool {
    if (n360_is_admin()) return true;

    if (!empty($item['vista'])) {
        return n360_puede_vista($item['vista']);
    }

    if (!empty($item['modulo'])) {
        return n360_puede_modulo((int)$item['modulo']);
    }

    return false;
}

function n360_menu_config(): array {
    return [
            [
                'id' => 'rrhh',
                'titulo' => 'Recursos Humanos',
                'icono' => 'bi bi-people-fill',
                'modulo' => 6,
                'grupos' => [
                    [
                        'titulo' => 'Personal',
                        'items' => [
                            [
                                'titulo' => 'Nuevo trabajador',
                                'icono' => 'bi bi-person-plus-fill',
                                'url' => '01_contratos/nregrcdn_h.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Trabajadores',
                                'icono' => 'bi bi-person-badge-fill',
                                'url' => '01_contratos/nlaskdrcdn_h.php',
                                'modulo' => 6
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Entrevistas',
                        'items' => [
                            [
                                'titulo' => 'Nueva entrevista',
                                'icono' => 'bi bi-clipboard-plus-fill',
                                'url' => '01_entrevistas/reentrev.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Ver entrevistas',
                                'icono' => 'bi bi-clipboard-data-fill',
                                'url' => '01_entrevistas/bvisentrevisaf.php',
                                'modulo' => 6
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Documentación',
                        'items' => [
                            [
                                'titulo' => 'Nueva documentación',
                                'icono' => 'bi bi-file-earmark-plus-fill',
                                'url' => '01_contratos/documentacion/agregadocu.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Ver documentación',
                                'icono' => 'bi bi-folder-fill',
                                'url' => '01_contratos/dorrhcdn.php',
                                'modulo' => 6
                            ],
                        ]
                    ],
                ]
            ],
            [
                'id' => 'mantenimiento',
                'titulo' => 'Mantenimiento',
                'icono' => 'bi bi-tools',
                'modulo' => 5,
                'grupos' => [
                    [
                        'titulo' => 'Gestión',
                        'items' => [
                            [
                                'titulo' => 'Checklist',
                                'icono' => 'bi bi-ui-checks-grid',
                                'url' => '01_amantenimiento/lista_cheklist.php',
                                'modulo' => 5
                            ],
                        ]
                    ],
                ]
            ],
            [
                'id' => 'inventario',
                'titulo' => 'Inventario',
                'icono' => 'bi bi-box-seam-fill',
                'modulo' => 3,
                'grupos' => [
                    [
                        'titulo' => 'Almacén',
                        'items' => [
                            [
                                'titulo' => 'Código de barra',
                                'icono' => 'bi bi-upc-scan',
                                'url' => '01_almacen/scanner.php',
                                'modulo' => 3
                            ],
                            [
                                'titulo' => 'Catálogo productos',
                                'icono' => 'bi bi-card-list',
                                'url' => '01_almacen/gen_np9823.php',
                                'modulo' => 3
                            ],
                        ]
                    ],
                ]
            ],
            [
                'id' => 'flota',
                'titulo' => 'Flota y Operaciones',
                'icono' => 'bi bi-bus-front-fill',
                'modulo' => 10,
                'grupos' => [
                    [
                        'titulo' => 'Programación',
                        'items' => [
                            [
                                'titulo' => 'Programación de conductores',
                                'icono' => 'bi bi-person-vcard-fill',
                                'url' => '01_flota/programacion_condt.php',
                                'vista' => 'f-progcond'
                            ],
                            [
                                'titulo' => 'Programación de horarios',
                                'icono' => 'bi bi-calendar2-week-fill',
                                'url' => '01_flota/programacion_horarios.php',
                                'vista' => 'f-proghor'
                            ],
                            [
                                'titulo' => 'Historial gerencial',
                                'icono' => 'bi bi-bar-chart-line-fill',
                                'url' => '01_flota/gest_prog_horarios.php',
                                'vista' => 'f-proghist'
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Vehículos',
                        'items' => [
                            [
                                'titulo' => 'Gestionar placas',
                                'icono' => 'bi bi-truck-front-fill',
                                'url' => '01_flota/gest_plac.php',
                                'vista' => 'f-flotas'
                            ],
                        ]
                    ],
                ]
            ],
    ];
}

function n360_render_sidebar(): void {
    $menu = n360_menu_config();
    $currentUri = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');

    echo '<button class="sidebar-mobile-btn" type="button" onclick="n360ToggleSidebarMobile()"><i class="bi bi-list"></i></button>';
    echo '<button class="sidebar-show-btn" id="sidebarShowBtn" type="button" onclick="n360ToggleSidebarDesktop()"><i class="bi bi-chevron-right"></i></button>';

    echo '<aside class="sidebar-n360" id="sidebarN360">';

    echo '
        <button class="sidebar-collapse-btn" type="button" onclick="n360ToggleSidebarDesktop()">
            <i class="bi bi-chevron-left"></i>
        </button>

        <div class="sidebar-brand">
            <img src="'.htmlspecialchars(n360_base_url('img/norte360.png')).'" alt="Norte 360">
            <div>
                <strong>Norte 360°</strong>
                <span>Panel operativo</span>
            </div>
        </div>

        <div class="sidebar-search">
            <input type="text" id="sidebarSearch" placeholder="Buscar vista...">
        </div>
    ';

    foreach ($menu as $modulo) {
        if (!n360_puede_modulo((int)$modulo['modulo'])) {
            continue;
        }

        $itemsVisibles = 0;

        foreach ($modulo['grupos'] as $grupo) {
            foreach ($grupo['items'] as $item) {
                if (n360_puede_item($item)) {
                    $itemsVisibles++;
                }
            }
        }

        if ($itemsVisibles === 0) {
            continue;
        }

        $moduloId = htmlspecialchars($modulo['id']);
        $titulo = htmlspecialchars($modulo['titulo']);
        $icono = htmlspecialchars($modulo['icono']);

        echo '
            <div class="sidebar-module" data-module="'.$moduloId.'">
                <button class="sidebar-module-btn" type="button" onclick="n360ToggleModule(\''.$moduloId.'\')">
                    <span class="module-left">
                        <span class="module-icon"><i class="'.$icono.'"></i></span>
                        <span class="module-title">'.$titulo.'</span>
                    </span>
                    <span class="module-count">'.$itemsVisibles.'</span>
                    <span class="module-arrow"><i class="bi bi-chevron-down"></i></span>
                </button>

                <div class="sidebar-module-content">
        ';

        foreach ($modulo['grupos'] as $grupo) {
            $itemsHtml = '';

            foreach ($grupo['items'] as $item) {
                if (!n360_puede_item($item)) {
                    continue;
                }

                $url = n360_base_url($item['url']);
                $itemUrlNormal = str_replace('\\', '/', $item['url']);
                $active = strpos($currentUri, $itemUrlNormal) !== false ? ' active' : '';

                $itemsHtml .= '
                    <a class="sidebar-link'.$active.'" 
                       href="'.htmlspecialchars($url).'"
                       data-search="'.htmlspecialchars(mb_strtolower($item['titulo'], 'UTF-8')).'">
                        <i class="'.htmlspecialchars($item['icono']).'"></i>
                        <span>'.htmlspecialchars($item['titulo']).'</span>
                    </a>
                ';
            }

            if ($itemsHtml !== '') {
                echo '<div class="sidebar-group">';
                echo '<div class="sidebar-group-title">'.htmlspecialchars($grupo['titulo']).'</div>';
                echo $itemsHtml;
                echo '</div>';
            }
        }

        echo '
                </div>
            </div>
        ';
    }

    echo '</aside>';
    echo '<div class="sidebar-overlay" id="sidebarOverlay" onclick="n360ToggleSidebarMobile()"></div>';
}