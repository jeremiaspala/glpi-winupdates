<?php
/**
 * PluginWinupdatesReport — lógica central del plugin
 *
 * STATUS:
 *   eol    → Rojo   — SO sin soporte de Microsoft
 *   outdated → Amarillo — SO con soporte pero build desactualizado
 *   updated  → Verde  — SO con soporte y build al día
 *   unknown  → Gris   — Sin datos de inventario
 */
class PluginWinupdatesReport extends CommonGLPI {

    static $rightname = 'plugin_winupdates';

    public static function getTypeName($nb = 0) {
        return 'Windows Update Status';
    }

    public static function getMenuName() {
        return 'Win Updates';
    }

    public static function getIcon() {
        return 'ti ti-refresh-alert';
    }

    public static function getMenuContent() {
        return plugin_winupdates_getMenuContent();
    }

    // ── Tabla Linux: distro → {min_version, eol, label, support_end} ─────
    static function getDefaultLinuxTable(): array {
        return [
            // Debian
            'Debian GNU/Linux 12' => ['min_version' => '12.11', 'eol' => false,
                                      'label' => 'Debian 12 Bookworm', 'support_end' => '2028-06'],
            'Debian GNU/Linux 13' => ['min_version' => '13.1',  'eol' => false,
                                      'label' => 'Debian 13 Trixie',   'support_end' => '2030-06'],
            'Debian GNU/Linux 11' => ['min_version' => '0',     'eol' => true,
                                      'label' => 'Debian 11 Bullseye', 'support_end' => '2026-06'],
            'Debian GNU/Linux 10' => ['min_version' => '0',     'eol' => true,
                                      'label' => 'Debian 10 Buster',   'support_end' => '2024-06'],
            // Ubuntu LTS
            'Ubuntu 26.04'        => ['min_version' => '26.04.1', 'eol' => false,
                                      'label' => 'Ubuntu 26.04 LTS Resolute Raccoon', 'support_end' => '2031-04'],
            'Ubuntu 24.04'        => ['min_version' => '24.04.4', 'eol' => false,
                                      'label' => 'Ubuntu 24.04 LTS Noble Numbat',     'support_end' => '2029-04'],
            'Ubuntu 22.04'        => ['min_version' => '22.04.5', 'eol' => false,
                                      'label' => 'Ubuntu 22.04 LTS Jammy Jellyfish',  'support_end' => '2027-04'],
            'Ubuntu 20.04'        => ['min_version' => '0',       'eol' => true,
                                      'label' => 'Ubuntu 20.04 LTS Focal Fossa',      'support_end' => '2025-04'],
        ];
    }

    static function getLinuxTable(): array {
        global $DB;
        if ($DB->tableExists('glpi_plugin_winupdates_config')) {
            $iter = $DB->request(['FROM' => 'glpi_plugin_winupdates_config',
                                  'WHERE' => ['name' => 'linux_table'], 'LIMIT' => 1]);
            if ($row = $iter->current()) {
                $stored = json_decode($row['value'], true);
                if ($stored) return $stored;
            }
        }
        return self::getDefaultLinuxTable();
    }

    // ── Determinar status para Linux ──────────────────────────────────────
    static function getLinuxStatus(string $osName, string $osVersion): array {
        $linuxTable = self::getLinuxTable();
        $entry = null;

        // Buscar por prefijo del nombre del SO
        foreach ($linuxTable as $pattern => $data) {
            if (str_starts_with($osName, $pattern)) {
                $entry = $data;
                break;
            }
        }

        if (!$entry) {
            return ['status' => 'linux', 'label' => $osName,
                    'color' => '#6c757d', 'bg' => '#f8f9fa',
                    'icon' => 'ti-brand-ubuntu', 'badge_class' => 'secondary',
                    'text' => 'Distribución Linux — sin datos de referencia',
                    'support_end' => ''];
        }

        if ($entry['eol']) {
            return ['status' => 'eol', 'label' => $entry['label'],
                    'color' => '#dc3545', 'bg' => '#ffeaea',
                    'icon' => 'ti-circle-x', 'badge_class' => 'danger',
                    'text' => 'Sin soporte — EOL ' . $entry['support_end'],
                    'support_end' => $entry['support_end'],
                    'min_build' => '—'];
        }

        // Comparar versiones: extraer número de minor release
        $cur  = self::parseLinuxVersion($osVersion);
        $min  = self::parseLinuxVersion($entry['min_version']);

        if ($cur >= $min && $min > 0) {
            return ['status' => 'updated', 'label' => $entry['label'],
                    'color' => '#198754', 'bg' => '#d1e7dd',
                    'icon' => 'ti-circle-check', 'badge_class' => 'success',
                    'text' => 'Al día — ' . $osVersion,
                    'support_end' => $entry['support_end'],
                    'min_build' => $entry['min_version']];
        }

        return ['status' => 'outdated', 'label' => $entry['label'],
                'color' => '#ffc107', 'bg' => '#fff3cd',
                'icon' => 'ti-alert-triangle', 'badge_class' => 'warning',
                'text' => 'Paquetes pendientes — ' . $osVersion . ' / mín. ' . $entry['min_version'],
                'support_end' => $entry['support_end'],
                'build' => $osVersion, 'min_build' => $entry['min_version']];
    }

    // Extrae un número comparable de una versión Linux (ej. "12.13" → 1213, "24.04.4" → 240404)
    static function parseLinuxVersion(string $v): int {
        // Quitar sufijos como " LTS (Noble Numbat)"
        $v = preg_replace('/\s+.*/', '', trim($v));
        $parts = explode('.', $v);
        $result = 0;
        foreach (array_slice($parts, 0, 3) as $i => $p) {
            $result += (int)$p * pow(1000, 2 - $i);
        }
        return $result;
    }

    // ── Tabla de referencia de builds (se puede editar vía config) ────────
    // Clave: base de kernel (ej. "10.0.26100"), valor: build mínimo "al día"
    // 'EOL' indica que ese kernel/versión ya no tiene soporte.
    static function getDefaultBuildTable(): array {
        return [
            // Windows 11 25H2 — soportado
            '10.0.26200' => ['min_build' => '26200.8457', 'eol' => false,
                             'label' => 'Windows 11 25H2', 'support_end' => '2027-10'],
            // Windows 11 24H2 — soportado (cliente)
            '10.0.26100' => ['min_build' => '26100.8457', 'eol' => false,
                             'label' => 'Windows 11 24H2 / Server 2025', 'support_end' => '2026-10'],
            // Windows 11 23H2 — EOL (Home/Pro: nov 2025)
            '10.0.22631' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 11 23H2', 'support_end' => '2025-11'],
            // Windows 11 22H2 — EOL (Home/Pro: oct 2024)
            '10.0.22621' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 11 22H2', 'support_end' => '2024-10'],
            // Windows 11 21H2 — EOL (oct 2023)
            '10.0.22000' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 11 21H2', 'support_end' => '2023-10'],
            // Windows 10 22H2 — EOL (oct 2025) — todo Win10 no-LTSC
            '10.0.19045' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 10 22H2', 'support_end' => '2025-10'],
            '10.0.19044' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 10 LTSC 2021 / 21H2', 'support_end' => '2027-01'],
            '10.0.19043' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 10 21H1', 'support_end' => '2022-12'],
            '10.0.17763' => ['min_build' => '17763.8755', 'eol' => false,
                             'label' => 'Windows Server 2019 / Win10 1809', 'support_end' => '2029-01'],
            '10.0.14393' => ['min_build' => '14393.9140', 'eol' => false,
                             'label' => 'Windows Server 2016 / Win10 1607', 'support_end' => '2027-10'],
            '6.3.9600'   => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows Server 2012 R2', 'support_end' => '2023-10'],
            '6.1'        => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 7 / Server 2008 R2', 'support_end' => '2020-01'],
            // Windows 10 antiguo (1511, etc.)
            '10.0.10586' => ['min_build' => '0', 'eol' => true,
                             'label' => 'Windows 10 1511', 'support_end' => '2017-10'],
        ];
    }

    // Obtener tabla de builds (desde DB de config o default)
    static function getBuildTable(): array {
        global $DB;
        $stored = null;
        if ($DB->tableExists('glpi_plugin_winupdates_config')) {
            $iter = $DB->request(['FROM' => 'glpi_plugin_winupdates_config',
                                  'WHERE' => ['name' => 'build_table'], 'LIMIT' => 1]);
            if ($row = $iter->current()) {
                $stored = json_decode($row['value'], true);
            }
        }
        return $stored ?? self::getDefaultBuildTable();
    }

    // Guardar tabla de builds
    static function saveBuildTable(array $table): void {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_winupdates_config')) return;
        $DB->delete('glpi_plugin_winupdates_config', ['name' => 'build_table']);
        $DB->insert('glpi_plugin_winupdates_config', [
            'name'  => 'build_table',
            'value' => json_encode($table, JSON_PRETTY_PRINT),
        ]);
    }

    // ── Determinar status de un equipo ─────────────────────────────────────
    static function getStatus(string $osName, string $osKernel, string $servicePack, string $osVersion = ''): array {
        $buildTable = self::getBuildTable();

        // Linux: delegar a lógica específica
        $osLower = strtolower($osName);
        if (!str_contains($osLower, 'windows')) {
            return self::getLinuxStatus($osName, $osVersion);
        }

        // Extraer base del kernel (ej. "10.0.19045" → "10.0.19045")
        $kernelBase = trim($osKernel);

        // Buscar en la tabla de builds
        $entry = null;
        foreach ($buildTable as $kbase => $data) {
            if (str_starts_with($kernelBase, $kbase)) {
                $entry = $data;
                $entry['kbase'] = $kbase;
                break;
            }
        }

        if (!$entry) {
            return ['status' => 'unknown', 'label' => $osName,
                    'color' => '#6c757d', 'icon' => 'ti-question-mark',
                    'text' => 'Versión desconocida — verificar inventario'];
        }

        // Ajuste Server 2025: comparte kernel 10.0.26100 con Win11 24H2
        if (str_contains($osName, 'Server 2025') && $entry['kbase'] === '10.0.26100') {
            // Server 2025 tiene builds diferentes (3xxxx)
            $entry['label'] = 'Windows Server 2025';
            $entry['eol']   = false;
            $entry['support_end'] = '2034-10';
            $entry['min_build']   = '26100.32860';
        }

        if ($entry['eol']) {
            return [
                'status'      => 'eol',
                'label'       => $entry['label'],
                'support_end' => $entry['support_end'],
                'color'       => '#dc3545',
                'bg'          => '#ffeaea',
                'icon'        => 'ti-circle-x',
                'badge_class' => 'danger',
                'text'        => 'Sin soporte — EOL ' . $entry['support_end'],
            ];
        }

        // Comparar build: extraer número de revisión
        $curBuild  = self::parseBuildRevision($servicePack ?: $osKernel);
        $minBuild  = self::parseBuildRevision($entry['min_build']);

        if ($curBuild >= $minBuild && $minBuild > 0) {
            return [
                'status'      => 'updated',
                'label'       => $entry['label'],
                'support_end' => $entry['support_end'],
                'color'       => '#198754',
                'bg'          => '#d1e7dd',
                'icon'        => 'ti-circle-check',
                'badge_class' => 'success',
                'text'        => 'Al día — build ' . $servicePack,
                'build'       => $servicePack,
                'min_build'   => $entry['min_build'],
            ];
        }

        return [
            'status'      => 'outdated',
            'label'       => $entry['label'],
            'support_end' => $entry['support_end'],
            'color'       => '#ffc107',
            'bg'          => '#fff3cd',
            'icon'        => 'ti-alert-triangle',
            'badge_class' => 'warning',
            'text'        => 'Actualizaciones pendientes — build ' . $servicePack . ' / mín. ' . $entry['min_build'],
            'build'       => $servicePack,
            'min_build'   => $entry['min_build'],
        ];
    }

    // Extrae el número de revisión de un string de build (ej. "19045.6456" → 6456)
    static function parseBuildRevision(string $build): int {
        $parts = explode('.', trim($build));
        return (int)end($parts);
    }

    // ── Obtener todos los equipos Windows con su estado ───────────────────
    static function getAllComputers(array $filters = []): array {
        global $DB;

        $where = ['c.is_deleted' => 0, 'ios.is_deleted' => 0];
        if (!empty($filters['status'])) {
            // filtro por status se aplica post-query
        }
        if (!empty($filters['entities_id'])) {
            $where['c.entities_id'] = (int)$filters['entities_id'];
        }

        $query = $DB->request([
            'SELECT' => [
                'c.id',
                'c.name AS equipo',
                'c.entities_id',
                'e.completename AS entidad',
                'l.name AS ubicacion',
                'ios.owner AS propietario',
                'ios.install_date',
                'os.name AS os_name',
                'osv.name AS os_version',
                'oskv.name AS os_kernel',
                'ossp.name AS service_pack',
                'ios.date_mod AS ultimo_inventario',
            ],
            'FROM'       => ['glpi_computers AS c'],
            'LEFT JOIN'  => [
                'glpi_items_operatingsystems AS ios' => [
                    'FKEY' => ['ios' => 'items_id', 'c' => 'id'],
                    'AND'  => ['ios.itemtype' => 'Computer', 'ios.is_deleted' => 0],
                ],
                'glpi_operatingsystems AS os' => [
                    'FKEY' => ['os' => 'id', 'ios' => 'operatingsystems_id'],
                ],
                'glpi_operatingsystemversions AS osv' => [
                    'FKEY' => ['osv' => 'id', 'ios' => 'operatingsystemversions_id'],
                ],
                'glpi_operatingsystemkernelversions AS oskv' => [
                    'FKEY' => ['oskv' => 'id', 'ios' => 'operatingsystemkernelversions_id'],
                ],
                'glpi_operatingsystemservicepacks AS ossp' => [
                    'FKEY' => ['ossp' => 'id', 'ios' => 'operatingsystemservicepacks_id'],
                ],
                'glpi_entities AS e' => [
                    'FKEY' => ['e' => 'id', 'c' => 'entities_id'],
                ],
                'glpi_locations AS l' => [
                    'FKEY' => ['l' => 'id', 'c' => 'locations_id'],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => ['os.name ASC', 'c.name ASC'],
        ]);

        $results = [];
        foreach ($query as $row) {
            $osName  = $row['os_name']     ?? '';
            $kernel  = $row['os_kernel']   ?? '';
            $sp      = $row['service_pack'] ?? '';

            $status = self::getStatus($osName, $kernel, $sp, $row['os_version'] ?? '');
            $row['status_data'] = $status;

            // Filtrar por status si se pidió
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                if ($status['status'] !== $filters['status']) continue;
            }

            $results[] = $row;
        }

        return $results;
    }

    // ── Estadísticas resumen ──────────────────────────────────────────────
    static function getSummary(array $computers): array {
        $counts = ['eol' => 0, 'outdated' => 0, 'updated' => 0, 'unknown' => 0, 'linux' => 0];
        foreach ($computers as $c) {
            $s = $c['status_data']['status'];
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        return $counts;
    }

    // Instalar tablas al activar el plugin
    static function install(): bool {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_winupdates_config')) {
            $DB->queryOrDie("
                CREATE TABLE `glpi_plugin_winupdates_config` (
                    `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name`  VARCHAR(100) NOT NULL,
                    `value` LONGTEXT,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        return true;
    }

    static function uninstall(): bool {
        global $DB;
        $DB->queryOrDie("DROP TABLE IF EXISTS `glpi_plugin_winupdates_config`");
        return true;
    }
}
