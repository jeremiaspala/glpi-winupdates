<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Html::header('Win Updates — Historial de Deploys', $_SERVER['PHP_SELF'], 'winupdates', 'winupdates');

global $DB;

// Filtros
$filter_state    = $_GET['state']    ?? 'all';
$filter_computer = trim($_GET['q']   ?? '');
$limit           = max(10, min(200, (int)($_GET['limit'] ?? 50)));

// Consulta de historial
$where = ['t.name' => ['LIKE', '[WinUpdates]%']];
if ($filter_state !== 'all') {
    $where['tjs.state'] = (int)$filter_state;
}

$query = $DB->request([
    'SELECT' => [
        'tjs.id AS state_id',
        'tjs.state',
        'tjs.date_start',
        'tjs.nb_retry',
        't.id AS task_id',
        't.name AS task_name',
        't.date_creation AS task_created',
        'tj.id AS job_id',
        'a.name AS agent_name',
        'a.remote_addr',
        'a.last_contact',
        'c.id AS computer_id',
        'c.name AS computer_name',
        'os.name AS os_name',
        'osv.name AS os_version',
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
            'FKEY' => ['c' => 'id', 'a' => 'items_id'],
            'AND'  => ['a.itemtype' => 'Computer'],
        ],
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
    ],
    'WHERE'  => $where,
    'ORDER'  => ['t.date_creation DESC', 'tjs.id DESC'],
    'LIMIT'  => $limit,
]);

$rows = iterator_to_array($query);

// Filtro por nombre de equipo (post-query)
if ($filter_computer !== '') {
    $rows = array_filter($rows, fn($r) =>
        stripos($r['computer_name'] ?? '', $filter_computer) !== false
    );
}

// Último log de cada state
$state_ids = array_column($rows, 'state_id');
$logs = [];
if ($state_ids) {
    $log_q = $DB->request([
        'SELECT'  => ['plugin_glpiinventory_taskjobstates_id', 'comment', 'date'],
        'FROM'    => 'glpi_plugin_glpiinventory_taskjoblogs',
        'WHERE'   => ['plugin_glpiinventory_taskjobstates_id' => $state_ids],
        'ORDER'   => ['date DESC'],
    ]);
    foreach ($log_q as $l) {
        $sid = $l['plugin_glpiinventory_taskjobstates_id'];
        if (!isset($logs[$sid])) $logs[$sid] = $l;
    }
}

// Estadísticas rápidas
$stats = ['total' => count($rows), 'ok' => 0, 'error' => 0, 'pending' => 0, 'running' => 0];
foreach ($rows as $r) {
    match ((int)$r['state']) {
        2, 4    => $stats['ok']++,
        3       => $stats['error']++,
        1       => $stats['running']++,
        default => $stats['pending']++,
    };
}

$REPORT_URL = 'report.php'; // relativo, mismo origen que el browser

$stateInfo = [
    0 => ['label' => 'Pendiente',  'class' => 'secondary', 'icon' => 'ti-clock'],
    1 => ['label' => 'Ejecutando', 'class' => 'info',      'icon' => 'ti-loader-2'],
    2 => ['label' => 'OK',         'class' => 'success',   'icon' => 'ti-circle-check'],
    3 => ['label' => 'Error',      'class' => 'danger',    'icon' => 'ti-circle-x'],
    4 => ['label' => 'OK',         'class' => 'success',   'icon' => 'ti-check'],
];
?>
<div class="container-fluid p-3">

  <!-- Cabecera -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="mb-0">
        <i class="ti ti-history me-2 text-primary"></i>Historial de Deploys
      </h2>
      <small class="text-muted">
        Tareas de actualización creadas por el plugin
      </small>
    </div>
    <a href="<?= Html::cleanInputText($REPORT_URL) ?>"
       class="btn btn-outline-primary btn-sm">
      <i class="ti ti-arrow-left me-1"></i>Volver al reporte
    </a>
  </div>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <?php
    $kcards = [
        ['count' => $stats['total'],   'label' => 'Total',     'color' => 'primary',   'icon' => 'ti-list'],
        ['count' => $stats['ok'],      'label' => 'Exitosos',  'color' => 'success',   'icon' => 'ti-circle-check'],
        ['count' => $stats['pending'], 'label' => 'Pendientes','color' => 'secondary', 'icon' => 'ti-clock'],
        ['count' => $stats['running'], 'label' => 'Ejecutando','color' => 'info',      'icon' => 'ti-loader-2'],
        ['count' => $stats['error'],   'label' => 'Errores',   'color' => 'danger',    'icon' => 'ti-circle-x'],
    ];
    foreach ($kcards as $k):
    ?>
    <div class="col-6 col-md-2">
      <div class="card text-center py-2">
        <i class="ti <?= $k['icon'] ?> fs-2 text-<?= $k['color'] ?>"></i>
        <div class="fs-2 fw-bold text-<?= $k['color'] ?>"><?= $k['count'] ?></div>
        <div class="small text-muted"><?= $k['label'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filtros -->
  <form method="GET" class="card card-body py-2 mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1 small fw-bold">Estado</label>
        <select name="state" class="form-select form-select-sm">
          <option value="all" <?= $filter_state==='all' ? 'selected':'' ?>>Todos</option>
          <option value="0"   <?= $filter_state==='0'   ? 'selected':'' ?>>Pendiente</option>
          <option value="1"   <?= $filter_state==='1'   ? 'selected':'' ?>>Ejecutando</option>
          <option value="2"   <?= $filter_state==='2'   ? 'selected':'' ?>>OK</option>
          <option value="3"   <?= $filter_state==='3'   ? 'selected':'' ?>>Error</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small fw-bold">Equipo</label>
        <input type="text" name="q" value="<?= Html::cleanInputText($filter_computer) ?>"
               class="form-control form-control-sm" placeholder="Buscar nombre...">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small fw-bold">Mostrar</label>
        <select name="limit" class="form-select form-select-sm">
          <?php foreach ([25, 50, 100, 200] as $l): ?>
            <option value="<?= $l ?>" <?= $limit==$l ? 'selected':'' ?>><?= $l ?> filas</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="ti ti-filter me-1"></i>Filtrar
        </button>
        <a href="history.php" class="btn btn-outline-secondary btn-sm ms-1">
          <i class="ti ti-x"></i>
        </a>
        <button type="button" class="btn btn-outline-info btn-sm ms-1"
                onclick="location.reload()">
          <i class="ti ti-refresh me-1"></i>Actualizar
        </button>
      </div>
    </div>
  </form>

  <!-- Tabla de historial -->
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
          <thead class="table-dark">
            <tr>
              <th>Fecha creación</th>
              <th>Equipo</th>
              <th>SO</th>
              <th>Agente / IP</th>
              <th>Estado</th>
              <th>Inicio ejecución</th>
              <th>Último log</th>
              <th>Reintentos</th>
              <th>Tarea GLPI</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-4">
                <i class="ti ti-mood-empty fs-3 d-block"></i>
                No hay tareas de deploy registradas todavía.
                <br><small>Usá el botón <strong>▶</strong> en el reporte para crear una.</small>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row):
                $state  = (int)$row['state'];
                $si     = $stateInfo[$state] ?? $stateInfo[0];
                $log    = $logs[$row['state_id']] ?? null;
                $rowBg  = match($state) {
                    2, 4 => '#f0fff4',
                    3    => '#fff5f5',
                    1    => '#e8f4ff',
                    default => '#fff',
                };
            ?>
            <tr style="background:<?= $rowBg ?>">
              <td class="small text-muted">
                <?= $row['task_created']
                    ? date('d/m/Y H:i', strtotime($row['task_created']))
                    : '—' ?>
              </td>
              <td>
                <?php if ($row['computer_id']): ?>
                  <a href="<?= Computer::getFormURL() ?>?id=<?= (int)$row['computer_id'] ?>"
                     class="fw-semibold text-decoration-none">
                    <i class="ti ti-device-laptop me-1"></i>
                    <?= Html::cleanInputText($row['computer_name'] ?? '—') ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?= Html::cleanInputText($row['os_name'] ?? '—') ?>
                <?php if ($row['os_version']): ?>
                  <span class="badge bg-secondary"><?= Html::cleanInputText($row['os_version']) ?></span>
                <?php endif; ?>
              </td>
              <td class="small text-muted">
                <?= Html::cleanInputText($row['agent_name'] ?? '—') ?>
                <br><span class="font-monospace"><?= Html::cleanInputText($row['remote_addr'] ?? '') ?></span>
              </td>
              <td>
                <span class="badge bg-<?= $si['class'] ?> d-inline-flex align-items-center gap-1">
                  <i class="ti <?= $si['icon'] ?>"></i> <?= $si['label'] ?>
                </span>
              </td>
              <td class="small text-muted">
                <?= $row['date_start']
                    ? date('d/m/Y H:i', strtotime($row['date_start']))
                    : '—' ?>
              </td>
              <td class="small" style="max-width:300px">
                <?php if ($log): ?>
                  <span title="<?= Html::cleanInputText($log['date']) ?>">
                    <?= Html::cleanInputText(mb_substr($log['comment'] ?? '', 0, 120)) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-center small">
                <?= (int)$row['nb_retry'] ?>
              </td>
              <td class="small">
                <span class="text-muted font-monospace" title="<?= Html::cleanInputText($row['task_name']) ?>">
                  #<?= (int)$row['task_id'] ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if (count($rows) >= $limit): ?>
    <div class="card-footer text-center small text-muted">
      Mostrando <?= $limit ?> resultados.
      <a href="?<?= http_build_query(array_merge($_GET, ['limit' => $limit * 2])) ?>">
        Ver más
      </a>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php Html::footer(); ?>
