<?php
/**
 * PluginWinupdatesDeploy
 * Crea paquetes y tareas de deploy en GLPI Inventory para forzar actualizaciones.
 *
 * Flujo:
 *   createOrGetPackage($os) → task → taskjob → taskjobstate
 *   El agente GLPI recoge la tarea en su próximo check-in (o inmediatamente via wake-up).
 */
class PluginWinupdatesDeploy {

    // Estados de taskjobstate
    const STATE_PREPARED = 0;
    const STATE_RUNNING  = 1;
    const STATE_OK       = 2;
    const STATE_ERROR    = 3;
    const STATE_OK_NOJOB = 4;

    // IDs de paquetes pre-creados (se guardan en config)
    const CFG_PKG_WINDOWS = 'deploy_pkg_windows';
    const CFG_PKG_LINUX   = 'deploy_pkg_linux';

    // ── JSON de acciones ──────────────────────────────────────────────────

    static function windowsPackageJson(): string {
        return json_encode([
            'jobs' => [
                [
                    'type'       => 'run',
                    'name'       => 'WU - Scan',
                    'exec'       => 'C:\\Windows\\System32\\UsoClient.exe',
                    'args'       => ['StartScan'],
                    'retChecks'  => [['type' => 'okCode', 'values' => [0]]],
                ],
                [
                    'type'       => 'run',
                    'name'       => 'WU - Download',
                    'exec'       => 'C:\\Windows\\System32\\UsoClient.exe',
                    'args'       => ['StartDownload'],
                    'retChecks'  => [['type' => 'okCode', 'values' => [0]]],
                ],
                [
                    'type'       => 'run',
                    'name'       => 'WU - Install',
                    'exec'       => 'C:\\Windows\\System32\\UsoClient.exe',
                    'args'       => ['StartInstall'],
                    'retChecks'  => [['type' => 'okCode', 'values' => [0]]],
                ],
            ],
            'associatedFiles' => [],
        ]);
    }

    static function linuxPackageJson(): string {
        return json_encode([
            'jobs' => [
                [
                    'type'       => 'run',
                    'name'       => 'APT - Update index',
                    'exec'       => '/usr/bin/apt-get',
                    'args'       => ['update', '-y'],
                    'retChecks'  => [['type' => 'okCode', 'values' => [0]]],
                ],
                [
                    'type'       => 'run',
                    'name'       => 'APT - Upgrade packages',
                    'exec'       => '/usr/bin/apt-get',
                    'args'       => [
                        '-o', 'Dpkg::Options::=--force-confdef',
                        '-o', 'Dpkg::Options::=--force-confold',
                        'upgrade', '-y', '--no-install-recommends',
                    ],
                    'retChecks'  => [['type' => 'okCode', 'values' => [0]]],
                ],
            ],
            'associatedFiles' => [],
        ]);
    }

    // ── Helpers de config ─────────────────────────────────────────────────

    static function getConfig(string $key): ?string {
        global $DB;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_winupdates_config',
            'WHERE' => ['name' => $key],
            'LIMIT' => 1,
        ]);
        return ($r = $iter->current()) ? $r['value'] : null;
    }

    static function setConfig(string $key, string $val): void {
        global $DB;
        $DB->delete('glpi_plugin_winupdates_config', ['name' => $key]);
        $DB->insert('glpi_plugin_winupdates_config', ['name' => $key, 'value' => $val]);
    }

    // ── Crear o reutilizar paquete de deploy ──────────────────────────────

    static function createOrGetPackage(string $os_type): int {
        global $DB;

        $cfg_key = ($os_type === 'linux') ? self::CFG_PKG_LINUX : self::CFG_PKG_WINDOWS;
        $existing_id = (int)self::getConfig($cfg_key);

        if ($existing_id > 0) {
            // Verificar que aún existe en la DB
            $iter = $DB->request([
                'FROM'  => 'glpi_plugin_glpiinventory_deploypackages',
                'WHERE' => ['id' => $existing_id],
                'LIMIT' => 1,
            ]);
            if ($iter->current()) {
                return $existing_id;
            }
        }

        // Crear paquete nuevo
        $label = ($os_type === 'linux')
            ? '[WinUpdates] Linux - APT Upgrade'
            : '[WinUpdates] Windows - Force Windows Update';
        $json = ($os_type === 'linux')
            ? self::linuxPackageJson()
            : self::windowsPackageJson();

        $DB->insert('glpi_plugin_glpiinventory_deploypackages', [
            'name'         => $label,
            'comment'      => 'Creado automáticamente por el plugin Windows Update Status.',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'date_mod'     => date('Y-m-d H:i:s'),
            'uuid'         => self::generateUuid(),
            'json'         => $json,
            'plugin_glpiinventory_deploygroups_id' => 0,
        ]);
        $pkg_id = $DB->insertId();
        self::setConfig($cfg_key, (string)$pkg_id);
        return $pkg_id;
    }

    // ── Forzar actualización en un equipo ─────────────────────────────────

    /**
     * @return array ['ok' => bool, 'msg' => string, 'task_id' => int, 'woken' => bool]
     */
    static function pushToComputer(int $computer_id): array {
        global $DB;

        // 1. Obtener el agente asociado al equipo
        $agent_iter = $DB->request([
            'FROM'  => 'glpi_agents',
            'WHERE' => ['items_id' => $computer_id, 'itemtype' => 'Computer'],
            'LIMIT' => 1,
        ]);
        $agent = $agent_iter->current();
        if (!$agent) {
            return ['ok' => false, 'msg' => 'No se encontró agente GLPI para este equipo.'];
        }
        $agent_id = (int)$agent['id'];

        // 2. Determinar SO del equipo
        $os_type = self::detectOsType($computer_id);

        // 3. Obtener/crear paquete
        $pkg_id = self::createOrGetPackage($os_type);

        // 4. Crear tarea
        $task_name = '[WinUpdates] ' . date('Y-m-d H:i') . ' - Computer#' . $computer_id;
        $DB->insert('glpi_plugin_glpiinventory_tasks', [
            'entities_id'                          => 0,
            'name'                                 => $task_name,
            'date_creation'                        => date('Y-m-d H:i:s'),
            'is_active'                            => 1,
            'datetime_start'                       => null,
            'datetime_end'                         => null,
            'plugin_glpiinventory_timeslots_prep_id' => 0,
            'plugin_glpiinventory_timeslots_exec_id' => 0,
            'wakeup_agent_counter'                 => 0,
            'wakeup_agent_time'                    => 0,
            'reprepare_if_successful'              => 0,
            'is_deploy_on_demand'                  => 0,
        ]);
        $task_id = $DB->insertId();

        // 5. Crear taskjob (vincula paquete → agente)
        $DB->insert('glpi_plugin_glpiinventory_taskjobs', [
            'plugin_glpiinventory_tasks_id' => $task_id,
            'entities_id'                   => 0,
            'name'                          => 'Deploy update',
            'date_creation'                 => date('Y-m-d H:i:s'),
            'method'                        => 'deployinstall',
            'targets'                       => json_encode([
                ['PluginGlpiinventoryDeployPackage' => (string)$pkg_id],
            ]),
            'actors'                        => json_encode([
                ['Agent' => (string)$agent_id],
            ]),
            'restrict_to_task_entity'       => 0,
        ]);
        $job_id = $DB->insertId();

        // 6. Crear estado inicial para el agente (PREPARED)
        $uniqid = self::generateUuid();
        $DB->insert('glpi_plugin_glpiinventory_taskjobstates', [
            'plugin_glpiinventory_taskjobs_id' => $job_id,
            'items_id'   => $pkg_id,
            'itemtype'   => 'PluginGlpiinventoryDeployPackage',
            'state'      => self::STATE_PREPARED,
            'agents_id'  => $agent_id,
            'specificity'=> '',
            'uniqid'     => $uniqid,
            'nb_retry'   => 0,
            'max_retry'  => 3,
        ]);

        // 7. Log inicial
        $state_id = $DB->insertId();
        $DB->insert('glpi_plugin_glpiinventory_taskjoblogs', [
            'plugin_glpiinventory_taskjobstates_id' => $state_id,
            'date'     => date('Y-m-d H:i:s'),
            'items_id' => 0,
            'itemtype' => 'PluginGlpiinventoryDeployPackage',
            'state'    => self::STATE_PREPARED,
            'comment'  => 'Tarea creada desde plugin Windows Update Status.',
        ]);

        // 8. Intentar wake-up del agente (no bloqueante)
        $woken = self::wakeUpAgent($agent);

        // 9. Registrar en config el último push para este equipo
        self::setConfig('last_push_' . $computer_id, json_encode([
            'task_id'   => $task_id,
            'job_id'    => $job_id,
            'state_id'  => $state_id,
            'pushed_at' => date('Y-m-d H:i:s'),
            'os_type'   => $os_type,
        ]));

        return [
            'ok'      => true,
            'msg'     => 'Tarea creada. El agente la ejecutará en su próximo check-in.' . ($woken ? ' (Wake-up enviado)' : ''),
            'task_id' => $task_id,
            'woken'   => $woken,
        ];
    }

    // ── Obtener estado del último push para un equipo ─────────────────────

    static function getLastPushStatus(int $computer_id): ?array {
        global $DB;

        $cfg = self::getConfig('last_push_' . $computer_id);
        if (!$cfg) return null;

        $info = json_decode($cfg, true);
        if (!$info) return null;

        // Leer estado actual del taskjobstate
        $iter = $DB->request([
            'SELECT' => ['tjs.state', 'tjs.date_start', 'tjl.comment', 'tjl.date'],
            'FROM'   => ['glpi_plugin_glpiinventory_taskjobstates AS tjs'],
            'LEFT JOIN' => [
                'glpi_plugin_glpiinventory_taskjoblogs AS tjl' => [
                    'ON' => 'tjl.plugin_glpiinventory_taskjobstates_id = tjs.id',
                ],
            ],
            'WHERE'  => ['tjs.id' => $info['state_id']],
            'ORDER'  => ['tjl.date DESC'],
            'LIMIT'  => 1,
        ]);

        $row = $iter->current();
        if (!$row) return $info;

        $state_labels = [
            self::STATE_PREPARED => ['label' => 'Pendiente', 'class' => 'secondary', 'icon' => 'ti-clock'],
            self::STATE_RUNNING  => ['label' => 'Ejecutando', 'class' => 'info',     'icon' => 'ti-loader'],
            self::STATE_OK       => ['label' => 'Completado', 'class' => 'success',  'icon' => 'ti-circle-check'],
            self::STATE_ERROR    => ['label' => 'Error',      'class' => 'danger',   'icon' => 'ti-circle-x'],
            self::STATE_OK_NOJOB => ['label' => 'OK (sin job)', 'class' => 'success', 'icon' => 'ti-check'],
        ];

        $state = (int)$row['state'];
        return array_merge($info, [
            'state'       => $state,
            'state_info'  => $state_labels[$state] ?? ['label' => 'Desconocido', 'class' => 'secondary', 'icon' => 'ti-question-mark'],
            'last_log'    => $row['comment'] ?? '',
            'last_date'   => $row['date']    ?? '',
        ]);
    }

    // ── Wake-up del agente via HTTP ───────────────────────────────────────

    static function wakeUpAgent(array $agent): bool {
        $ip   = $agent['remote_addr'] ?? '';
        $port = $agent['port']        ?? 62354;
        if (!$ip || !$port) return false;

        $url = "http://{$ip}:{$port}/";
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 2,
            'ignore_errors'   => true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        return $result !== false;
    }

    // ── Detectar SO del equipo ────────────────────────────────────────────

    static function detectOsType(int $computer_id): string {
        global $DB;
        $iter = $DB->request([
            'SELECT' => ['os.name'],
            'FROM'   => ['glpi_items_operatingsystems AS ios'],
            'JOIN'   => [
                'glpi_operatingsystems AS os' => [
                    'FKEY' => ['os' => 'id', 'ios' => 'operatingsystems_id'],
                ],
            ],
            'WHERE'  => ['ios.items_id' => $computer_id, 'ios.itemtype' => 'Computer'],
            'LIMIT'  => 1,
        ]);
        $row = $iter->current();
        if (!$row) return 'windows';
        $os = strtolower($row['name']);
        return (str_contains($os, 'linux') || str_contains($os, 'debian') ||
                str_contains($os, 'ubuntu') || str_contains($os, 'centos') ||
                str_contains($os, 'rhel')   || str_contains($os, 'fedora'))
            ? 'linux' : 'windows';
    }

    // ── Historial reciente de deploys ─────────────────────────────────────

    static function getRecentPushes(int $limit = 20): array {
        global $DB;

        $iter = $DB->request([
            'SELECT' => [
                'tjs.id AS state_id',
                'tjs.state',
                'tjs.date_start',
                'tjl.comment',
                'tjl.date AS log_date',
                'tj.name AS job_name',
                't.name AS task_name',
                'a.name AS agent_name',
                'c.name AS computer_name',
                'c.id AS computer_id',
            ],
            'FROM'      => ['glpi_plugin_glpiinventory_taskjobstates AS tjs'],
            'LEFT JOIN' => [
                'glpi_plugin_glpiinventory_taskjobs AS tj' => [
                    'FKEY' => ['tj' => 'id', 'tjs' => 'plugin_glpiinventory_taskjobs_id'],
                ],
                'glpi_plugin_glpiinventory_tasks AS t' => [
                    'FKEY' => ['t' => 'id', 'tj' => 'plugin_glpiinventory_tasks_id'],
                ],
                'glpi_agents AS a' => [
                    'FKEY' => ['a' => 'id', 'tjs' => 'agents_id'],
                ],
                'glpi_computers AS c' => [
                    'FKEY'  => ['c' => 'id', 'a' => 'items_id'],
                    'AND'   => ['a.itemtype' => 'Computer'],
                ],
                'glpi_plugin_glpiinventory_taskjoblogs AS tjl' => [
                    'ON' => 'tjl.plugin_glpiinventory_taskjobstates_id = tjs.id',
                ],
            ],
            'WHERE'  => ['t.name' => ['LIKE', '[WinUpdates]%']],
            'ORDER'  => ['tjl.date DESC'],
            'LIMIT'  => $limit,
        ]);

        $result = [];
        foreach ($iter as $row) {
            $result[] = $row;
        }
        return $result;
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    static function generateUuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
