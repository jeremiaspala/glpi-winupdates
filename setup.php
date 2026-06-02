<?php
/**
 * Windows Update Status Plugin for GLPI 11
 */

define('PLUGIN_WINUPDATES_VERSION',  '1.0.0');
define('PLUGIN_WINUPDATES_MIN_GLPI', '11.0.0');
define('PLUGIN_WINUPDATES_MAX_GLPI', '12.0.0');

function plugin_version_winupdates() {
    return [
        'name'         => 'Windows Update Status',
        'version'      => PLUGIN_WINUPDATES_VERSION,
        'author'       => 'TPR - Infraestructura IT',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_WINUPDATES_MIN_GLPI,
                'max' => PLUGIN_WINUPDATES_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_winupdates_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_WINUPDATES_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_WINUPDATES_MAX_GLPI, 'ge')) {
        echo "Este plugin requiere GLPI >= " . PLUGIN_WINUPDATES_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_winupdates_check_config() {
    return true;
}

// ── Función de menú (obligatoria para que aparezca en la barra lateral) ──
function plugin_winupdates_getMenuContent() {
    $menu = [];
    $menu['title'] = 'Win Updates';
    $menu['page']  = '/plugins/winupdates/front/report.php';
    $menu['icon']  = 'ti ti-refresh-alert';

    $menu['options']['report']['title']           = 'Estado de Actualizaciones';
    $menu['options']['report']['page']            = '/plugins/winupdates/front/report.php';
    $menu['options']['report']['icon']            = 'ti ti-refresh-alert';
    $menu['options']['report']['links']['search'] = '/plugins/winupdates/front/report.php';

    $menu['options']['pdf']['title']           = 'Exportar PDF';
    $menu['options']['pdf']['page']            = '/plugins/winupdates/front/pdf.php';
    $menu['options']['pdf']['icon']            = 'ti ti-file-type-pdf';
    $menu['options']['pdf']['links']['search'] = '/plugins/winupdates/front/pdf.php';

    $menu['options']['history']['title']           = 'Historial de deploys';
    $menu['options']['history']['page']            = '/plugins/winupdates/front/history.php';
    $menu['options']['history']['icon']            = 'ti ti-history';
    $menu['options']['history']['links']['search'] = '/plugins/winupdates/front/history.php';

    $menu['options']['config']['title']           = 'Configuración';
    $menu['options']['config']['page']            = '/plugins/winupdates/front/config.php';
    $menu['options']['config']['icon']            = 'ti ti-settings';
    $menu['options']['config']['links']['search'] = '/plugins/winupdates/front/config.php';

    return $menu;
}

// ── Init (GLPI llama a esta función al cargar el plugin) ─────────────────
function _plugin_winupdates_common_init() {
    global $PLUGIN_HOOKS;

    Plugin::registerClass('PluginWinupdatesReport');
    Plugin::registerClass('PluginWinupdatesDeploy');

    $PLUGIN_HOOKS['csrf_compliant']['winupdates'] = true;
    $PLUGIN_HOOKS['menu_toadd']['winupdates'] = [
        'winupdates' => ['PluginWinupdatesReport'],
    ];

    $PLUGIN_HOOKS['redefine_menus']['winupdates'] = function ($menu) {
        // Siempre aplicar estructura completa; preservar 'types' de menu_toadd
        $existing_types = $menu['winupdates']['types'] ?? [];
        {
            $menu['winupdates'] = [
                'title'   => 'Win Updates',
                'icon'    => 'ti ti-refresh-alert',
                'default' => '/plugins/winupdates/front/report.php',
                'content' => [
                    'report' => [
                        'title' => 'Estado de Actualizaciones',
                        'page'  => '/plugins/winupdates/front/report.php',
                        'icon'  => 'ti ti-refresh-alert',
                    ],
                    'pdf' => [
                        'title' => 'Exportar PDF',
                        'page'  => '/plugins/winupdates/front/pdf.php',
                        'icon'  => 'ti ti-file-type-pdf',
                    ],
                    'history' => [
                        'title' => 'Historial deploys',
                        'page'  => '/plugins/winupdates/front/history.php',
                        'icon'  => 'ti ti-history',
                    ],
                    'config' => [
                        'title' => 'Configuración',
                        'page'  => '/plugins/winupdates/front/config.php',
                        'icon'  => 'ti ti-settings',
                    ],
                ],
                'types' => $existing_types,
            ];
        }
        return $menu;
    };
}

// GLPI 10/11 usa plugin_{name}_init
function plugin_winupdates_init() {
    _plugin_winupdates_common_init();
}

// Algunas versiones de GLPI 11 llaman plugin_init_{name}
function plugin_init_winupdates() {
    _plugin_winupdates_common_init();
}
