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
    if ($idModulo <= 0) return true;

    return n360_is_admin() || in_array($idModulo, n360_permisos());
}

function n360_puede_vista(string $vista): bool {
    return n360_is_admin() || in_array($vista, n360_vistas());
}

function n360_puede_alguna_vista(array $vistas): bool {
    if (n360_is_admin()) return true;

    foreach ($vistas as $vista) {
        if (n360_puede_vista((string)$vista)) {
            return true;
        }
    }

    return false;
}

function n360_puede_item(array $item): bool {
    if (n360_is_admin()) return true;

    if (!empty($item['publico'])) {
        return true;
    }

    if (!empty($item['admin'])) {
        return false;
    }

    if (!empty($item['vistas'])) {
        return n360_puede_alguna_vista((array)$item['vistas']);
    }

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
                'id' => 'panel',
                'titulo' => 'Panel principal',
                'icono' => 'bi bi-speedometer2',
                'modulo' => 0,
                'grupos' => [
                    [
                        'titulo' => 'Inicio',
                        'items' => [
                            [
                                'titulo' => 'Panel principal',
                                'icono' => 'bi bi-house-door-fill',
                                'url' => 'index.php',
                                'publico' => true
                            ],
                        ]
                    ],
                ]
            ],
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
                                'vistas' => ['r-gen'],
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Buscar trabajador',
                                'icono' => 'bi bi-search',
                                'url' => '01_contratos/nlaskdrcdn_h.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Trabajadores',
                                'icono' => 'bi bi-people-fill',
                                'url' => '01_contratos/trabajadores/ver_personal.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Tabla de trabajadores',
                                'icono' => 'bi bi-table',
                                'url' => '01_contratos/trabajadores/ver_listatrab.php',
                                'modulo' => 6
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Consultas',
                        'items' => [
                            [
                                'titulo' => 'Licencias',
                                'icono' => 'bi bi-award-fill',
                                'url' => '01_contratos/trabajadores/ver_licencias.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Cumpleaños',
                                'icono' => 'bi bi-calendar2-event-fill',
                                'url' => '01_contratos/trabajadores/ver_cumpleanos.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Cargos',
                                'icono' => 'bi bi-briefcase-fill',
                                'url' => '01_contratos/trabajadores/ver_cargos.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Emergencia',
                                'icono' => 'bi bi-telephone-inbound-fill',
                                'url' => '01_contratos/trabajadores/ver_emergencia.php',
                                'modulo' => 6
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Procesos',
                        'items' => [
                            [
                                'titulo' => 'Capacitaciones',
                                'icono' => 'bi bi-mortarboard-fill',
                                'url' => '01_contratos/ncapacitaciones.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Solicitud para trabajador',
                                'icono' => 'bi bi-file-earmark-person-fill',
                                'url' => '01_contratos/tbvistacontratadosent.php',
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
                                'vistas' => ['r-gen', 'e-gen'],
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Ver entrevistas',
                                'icono' => 'bi bi-clipboard-data-fill',
                                'url' => '01_entrevistas/bvisentrevisaf.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Etapas de entrevistas',
                                'icono' => 'bi bi-kanban-fill',
                                'url' => '01_entrevistas/propukanban.php',
                                'modulo' => 6
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Documentación',
                        'items' => [
                            [
                                'titulo' => 'Agregar documento',
                                'icono' => 'bi bi-file-earmark-plus-fill',
                                'url' => '01_contratos/documentacion/agregadocu.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Generar documento',
                                'icono' => 'bi bi-journal-plus',
                                'url' => '01_contratos/documentacion/generdocuplant.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Ver documentos',
                                'icono' => 'bi bi-folder2-open',
                                'url' => '01_contratos/documentacion/ver.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Documentación general',
                                'icono' => 'bi bi-folder-fill',
                                'url' => '01_contratos/dorrhcdn.php',
                                'modulo' => 6
                            ],
                            [
                                'titulo' => 'Tipos documentos',
                                'icono' => 'bi bi-archive-fill',
                                'url' => '01_contratos/documentacion/tipo_docu.php',
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
                        'titulo' => 'Generar checklist',
                        'items' => [
                            [
                                'titulo' => 'Nueva limpieza',
                                'icono' => 'bi bi-droplet-fill',
                                'url' => '01_amantenimiento/limpieza/mantcdn.php?id_tipo=1',
                                'vistas' => ['c-limp', 'c-lalu'],
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Nuevo alcoholimetro',
                                'icono' => 'bi bi-clipboard2-pulse-fill',
                                'url' => '01_amantenimiento/limpieza/mantcdn.php?id_tipo=3',
                                'vistas' => ['c-lalu'],
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Nueva fumigacion',
                                'icono' => 'bi bi-shield-check',
                                'url' => '01_amantenimiento/limpieza/mantcdn.php?id_tipo=4',
                                'vistas' => ['c-lalu'],
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Nuevo embarque',
                                'icono' => 'bi bi-box-arrow-in-right',
                                'url' => '01_amantenimiento/limpieza/mantcdn.php?id_tipo=2',
                                'vistas' => ['c-sab'],
                                'modulo' => 5
                            ],
                        ]
                    ],
                    [
                        'titulo' => 'Gestion',
                        'items' => [
                            [
                                'titulo' => 'Ver checklist',
                                'icono' => 'bi bi-ui-checks-grid',
                                'url' => '01_amantenimiento/lista_cheklist.php',
                                'vistas' => ['c-limp', 'c-sab', 'c-lalu'],
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Generar ruta',
                                'icono' => 'bi bi-signpost-split-fill',
                                'url' => '01_amantenimiento/interbus_vld.php',
                                'vistas' => ['c-limp', 'c-sab'],
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Viajes',
                                'icono' => 'bi bi-map-fill',
                                'url' => '01_amantenimiento/viajes.php',
                                'admin' => true,
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Calendario checklist',
                                'icono' => 'bi bi-calendar-check-fill',
                                'url' => '01_amantenimiento/calendario_cheklist.php',
                                'admin' => true,
                                'modulo' => 5
                            ],
                            [
                                'titulo' => 'Categorias e items',
                                'icono' => 'bi bi-tags-fill',
                                'url' => '01_amantenimiento/categorias_items.php',
                                'admin' => true,
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
                            [
                                'titulo' => 'Registrar movimiento',
                                'icono' => 'bi bi-box-arrow-in-down',
                                'url' => '01_almacen/formulario_movalm.php',
                                'vistas' => ['a-formulreg'],
                                'modulo' => 3
                            ],
                            [
                                'titulo' => 'Movimientos de almacen',
                                'icono' => 'bi bi-arrow-left-right',
                                'url' => '01_almacen/movimientos_ofi.php',
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
                                'vistas' => ['f-placas', 'f-flotas']
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

    echo '<button class="sidebar-mobile-btn" type="button" onclick="n360ToggleSidebarMobile()" data-sidebar-mobile-toggle aria-controls="sidebarN360" aria-expanded="false" aria-label="Abrir menu"><i class="bi bi-list"></i></button>';
    echo '<button class="sidebar-show-btn" id="sidebarShowBtn" type="button" onclick="n360ToggleSidebarDesktop()"><i class="bi bi-chevron-right"></i></button>';

    echo '<aside class="sidebar-n360" id="sidebarN360">';

    echo '
        <button class="sidebar-collapse-btn" type="button" onclick="n360ToggleSidebarDesktop()">
            <i class="bi bi-chevron-left"></i>
        </button>

        <div class="sidebar-brand">
            <img src="'.htmlspecialchars(n360_base_url('img/norte360_black.png')).'" alt="Norte 360">
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
    echo '<div class="sidebar-overlay" id="sidebarOverlay" onclick="n360ToggleSidebarMobile()" aria-hidden="true"></div>';
}
