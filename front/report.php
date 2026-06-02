<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Html::header('Windows Update Status', $_SERVER['PHP_SELF'], 'winupdates', 'winupdates');

$filters = [
    'status'      => $_GET['status']      ?? 'all',
    'os_type'     => $_GET['os_type']     ?? 'all',
    'entities_id' => (int)($_GET['entities_id'] ?? 0),
];

$all_computers = PluginWinupdatesReport::getAllComputers(['entities_id' => $filters['entities_id']]);
$computers     = array_filter($all_computers, function($row) use ($filters) {
    $s = $row['status_data']['status'];
    if ($filters['status'] !== 'all' && $s !== $filters['status']) return false;
    if ($filters['os_type'] === 'windows' && $s === 'linux') return false;
    if ($filters['os_type'] === 'linux'   && $s !== 'linux' && $s !== 'eol_linux') return false;
    return true;
});
$computers = array_values($computers);

$summary   = PluginWinupdatesReport::getSummary($all_computers);
$total     = array_sum($summary);

// Contar Linux vs Windows por estado
$linux_count   = $summary['linux'] ?? 0;
$linux_eol     = 0;
$linux_ok      = 0;
$linux_outdated = 0;
foreach ($all_computers as $c) {
    $s = $c['status_data'];
    $os = strtolower($c['os_name'] ?? '');
    if (!str_contains($os, 'windows')) {
        if ($s['status'] === 'eol')     $linux_eol++;
        elseif ($s['status'] === 'updated')  $linux_ok++;
        elseif ($s['status'] === 'outdated') $linux_outdated++;
        else $linux_count++;
    }
}

// Rutas relativas al plugin — el JS las convierte a absolutas desde window.location
// Esto evita problemas con url_base (gestion.tpr.com.ar vs IP directa)
$PLUGIN_REL = Plugin::getWebDir('winupdates', false, false); // solo el path, sin dominio
$PDF_URL    = $PLUGIN_REL . '/front/pdf.php?status=' . urlencode($filters['status']) . '&os_type=' . urlencode($filters['os_type']);
$CFG_URL    = $PLUGIN_REL . '/front/config.php';
// PUSH_URL se construye en JS desde window.location para ser siempre same-origin

$statusLabels = [
    'all'      => 'Todos',
    'eol'      => 'Sin soporte (EOL)',
    'outdated' => 'Actualizaciones pendientes',
    'updated'  => 'Al día',
    'unknown'  => 'Sin datos',
    'linux'    => 'Linux',
];
?>
<div class="container-fluid p-3">

  <!-- Cabecera -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="mb-0">
        <i class="ti ti-refresh-alert me-2 text-primary"></i>Windows Update Status
      </h2>
      <small class="text-muted">
        Inventario GLPI Agent — <?= date('d/m/Y H:i') ?>
      </small>
    </div>
    <div class="d-flex gap-2">
      <a href="pdf.php?status=<?= urlencode($filters['status']) ?>&os_type=<?= urlencode($filters['os_type']) ?>"
         target="_blank" class="btn btn-outline-danger btn-sm">
        <i class="ti ti-file-type-pdf me-1"></i>Exportar PDF
      </a>
      <a href="config.php" class="btn btn-outline-secondary btn-sm">
        <i class="ti ti-settings me-1"></i>Configuración
      </a>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="row g-2 mb-3">
    <?php
    $cards = [
        ['status'=>'all',     'ost'=>'all',     'count'=>$total,              'label'=>'Total equipos',      'color'=>'primary',   'icon'=>'ti-devices',        'bg'=>'#e7f1ff'],
        ['status'=>'updated', 'ost'=>'all',     'count'=>$summary['updated'], 'label'=>'Al día',             'color'=>'success',   'icon'=>'ti-circle-check',   'bg'=>'#d1e7dd'],
        ['status'=>'outdated','ost'=>'all',     'count'=>$summary['outdated'],'label'=>'Actualiz. pendientes','color'=>'warning',  'icon'=>'ti-alert-triangle', 'bg'=>'#fff3cd'],
        ['status'=>'eol',     'ost'=>'all',     'count'=>$summary['eol'],     'label'=>'Sin soporte (EOL)',   'color'=>'danger',    'icon'=>'ti-circle-x',       'bg'=>'#ffeaea'],
        ['status'=>'all',     'ost'=>'linux',   'count'=>$linux_eol+$linux_ok+$linux_outdated+$linux_count,
                                                                               'label'=>'Linux',              'color'=>'info',      'icon'=>'ti-brand-ubuntu',   'bg'=>'#cff4fc'],
        ['status'=>'unknown', 'ost'=>'all',     'count'=>$summary['unknown'], 'label'=>'Sin inventario',     'color'=>'secondary', 'icon'=>'ti-question-mark',  'bg'=>'#e9ecef'],
    ];
    foreach ($cards as $card):
        $isActive = ($filters['status'] === $card['status'] && $filters['os_type'] === $card['ost']);
        $pct = $total > 0 ? round($card['count'] / $total * 100) : 0;
        $url = '?status=' . $card['status'] . '&os_type=' . $card['ost'] . '&entities_id=' . $filters['entities_id'];
    ?>
    <div class="col-6 col-md-4 col-xl-2">
      <a href="<?= $url ?>" class="text-decoration-none">
        <div class="card h-100 border-2 <?= $isActive ? 'border-' . $card['color'] . ' shadow' : '' ?>"
             style="background:<?= $card['bg'] ?>">
          <div class="card-body p-2 text-center">
            <i class="ti <?= $card['icon'] ?> fs-3 text-<?= $card['color'] ?>"></i>
            <div class="fs-2 fw-bold text-<?= $card['color'] ?> lh-1 mt-1"><?= $card['count'] ?></div>
            <div class="small fw-semibold text-<?= $card['color'] ?>"><?= $card['label'] ?></div>
            <div class="progress mt-1" style="height:3px">
              <div class="progress-bar bg-<?= $card['color'] ?>" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filtros + Bulk action -->
  <form method="GET" class="card card-body mb-3 py-2" id="filter-form">
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1 small fw-bold">Estado</label>
        <select name="status" class="form-select form-select-sm">
          <?php foreach ($statusLabels as $v => $l): ?>
            <option value="<?= $v ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small fw-bold">Sistema</label>
        <select name="os_type" class="form-select form-select-sm">
          <option value="all"     <?= $filters['os_type']==='all'     ? 'selected':'' ?>>Todos</option>
          <option value="windows" <?= $filters['os_type']==='windows' ? 'selected':'' ?>>Solo Windows</option>
          <option value="linux"   <?= $filters['os_type']==='linux'   ? 'selected':'' ?>>Solo Linux</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="ti ti-filter me-1"></i>Filtrar
        </button>
        <a href="?" class="btn btn-outline-secondary btn-sm ms-1">
          <i class="ti ti-x"></i>
        </a>
      </div>
      <div class="col-auto ms-auto d-flex gap-2 align-items-center">
        <span class="small text-muted"><?= count($computers) ?> equipos</span>
        <button type="button" id="btn-bulk-update"
                class="btn btn-warning btn-sm d-none"
                onclick="bulkUpdate()">
          <i class="ti ti-player-play me-1"></i>
          Actualizar seleccionados (<span id="sel-count">0</span>)
        </button>
      </div>
    </div>
  </form>

  <!-- Tabla -->
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0" id="wu-table">
          <thead class="table-dark">
            <tr>
              <th style="width:36px">
                <input type="checkbox" class="form-check-input" id="chk-all"
                       title="Seleccionar todos" onchange="toggleAll(this)">
              </th>
              <th>Equipo</th>
              <th>Sistema Operativo</th>
              <th>Versión / Build</th>
              <th>Mínimo requerido</th>
              <th>Estado</th>
              <th>Fin soporte</th>
              <th>Último inventario</th>
              <th style="width:110px">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($computers)): ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-4">
                <i class="ti ti-mood-empty fs-3 d-block"></i>
                Sin equipos para los filtros aplicados.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($computers as $i => $row):
                $s     = $row['status_data'];
                $isWin = str_contains(strtolower($row['os_name'] ?? ''), 'windows');
                $rowBg = match($s['status']) {
                    'eol'      => '#fff5f5',
                    'outdated' => '#fffdf0',
                    'updated'  => '#f0fff4',
                    default    => '#fff',
                };
                $badge = match($s['status']) {
                    'eol'      => ['danger',   'ti-circle-x',       'Sin soporte (EOL)'],
                    'outdated' => ['warning',  'ti-alert-triangle',  'Actualiz. pendientes'],
                    'updated'  => ['success',  'ti-circle-check',   'Al día'],
                    'linux'    => ['info',     'ti-brand-ubuntu',    'Linux'],
                    default    => ['secondary','ti-question-mark',  'Sin datos'],
                };
                $pushStatus = PluginWinupdatesDeploy::getLastPushStatus((int)$row['id']);
            ?>
            <tr style="background:<?= $rowBg ?>" data-computer-id="<?= (int)$row['id'] ?>">
              <td>
                <input type="checkbox" class="form-check-input row-chk"
                       value="<?= (int)$row['id'] ?>"
                       data-name="<?= Html::cleanInputText($row['equipo']) ?>"
                       onchange="updateBulkBtn()">
              </td>
              <td>
                <a href="<?= Computer::getFormURL() ?>?id=<?= (int)$row['id'] ?>"
                   class="fw-semibold text-decoration-none">
                  <i class="ti <?= $isWin ? 'ti-device-laptop' : 'ti-server' ?> me-1"></i>
                  <?= Html::cleanInputText($row['equipo']) ?>
                </a>
                <?php if ($row['ubicacion']): ?>
                  <br><small class="text-muted">
                    <i class="ti ti-map-pin me-1"></i><?= Html::cleanInputText($row['ubicacion']) ?>
                  </small>
                <?php endif; ?>
              </td>
              <td>
                <small><?= Html::cleanInputText($row['os_name'] ?? '—') ?></small>
                <?php if ($row['os_version']): ?>
                  <span class="badge bg-secondary ms-1 small"><?= Html::cleanInputText($row['os_version']) ?></span>
                <?php endif; ?>
              </td>
              <td class="font-monospace small">
                <?= Html::cleanInputText($row['service_pack'] ?: ($row['os_kernel'] ?? '—')) ?>
              </td>
              <td class="font-monospace small text-muted">
                <?= Html::cleanInputText($s['min_build'] ?? '—') ?>
              </td>
              <td>
                <span class="badge bg-<?= $badge[0] ?> d-inline-flex align-items-center gap-1 px-2 py-1">
                  <i class="ti <?= $badge[1] ?>"></i> <?= $badge[2] ?>
                </span>
                <?php if (!empty($s['text'])): ?>
                  <br><small class="text-muted" style="font-size:.7rem">
                    <?= Html::cleanInputText($s['text']) ?>
                  </small>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($s['support_end'])): ?>
                  <?php $past = (new \DateTime()) > \DateTime::createFromFormat('Y-m', $s['support_end']); ?>
                  <span class="small <?= $past ? 'text-danger fw-bold' : 'text-success' ?>">
                    <i class="ti <?= $past ? 'ti-calendar-x' : 'ti-calendar-check' ?> me-1"></i>
                    <?= $s['support_end'] ?>
                    <?= $past ? '<span class="badge bg-danger ms-1">VENCIDO</span>' : '' ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small text-muted">
                <?= $row['ultimo_inventario']
                    ? date('d/m/Y', strtotime($row['ultimo_inventario']))
                    : '—' ?>
              </td>
              <td>
                <div class="d-flex gap-1 align-items-center">
                  <a href="<?= Computer::getFormURL() ?>?id=<?= (int)$row['id'] ?>"
                     class="btn btn-outline-primary btn-sm py-0 px-1" title="Ver equipo">
                    <i class="ti ti-eye"></i>
                  </a>

                  <?php /* Botón deploy: siempre visible, EOL también puede actualizarse */ ?>
                  <button type="button"
                          class="btn btn-sm py-0 px-1 btn-deploy <?= $s['status']==='updated' ? 'btn-outline-success' : 'btn-outline-warning' ?>"
                          onclick="pushUpdate(<?= (int)$row['id'] ?>, '<?= Html::cleanInputText($row['equipo']) ?>')"
                          title="Forzar actualización vía GLPI Deploy">
                    <i class="ti ti-player-play"></i>
                  </button>

                  <?php if ($pushStatus): ?>
                    <?php $si = $pushStatus['state_info'] ?? ['class'=>'secondary','icon'=>'ti-clock','label'=>'Pendiente']; ?>
                    <span class="badge bg-<?= $si['class'] ?> py-0 px-1"
                          title="<?= Html::cleanInputText($pushStatus['pushed_at'] ?? '') ?>&#10;<?= Html::cleanInputText($pushStatus['last_log'] ?? '') ?>">
                      <i class="ti <?= $si['icon'] ?>"></i>
                    </span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div><!-- /card tabla -->

  <!-- Modal de confirmación de deploy -->
  <div class="modal fade" id="deployModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title">
            <i class="ti ti-player-play me-2"></i>Forzar actualización
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Se creará una tarea de deploy en <strong id="deploy-equipo-name"></strong>.</p>
          <ul class="small text-muted">
            <li><strong>Windows:</strong> UsoClient StartScan → StartDownload → StartInstall</li>
            <li><strong>Linux:</strong> apt-get update && apt-get upgrade -y</li>
          </ul>
          <div class="alert alert-info small mb-0">
            <i class="ti ti-info-circle me-1"></i>
            El agente GLPI ejecutará la tarea en su próximo check-in.
            Si está accesible en red, se intentará un wake-up inmediato.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-warning btn-sm" id="btn-confirm-deploy">
            <i class="ti ti-player-play me-1"></i>Confirmar
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast de resultado -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="deploy-toast" class="toast align-items-center text-bg-success border-0" role="alert">
      <div class="d-flex">
        <div class="toast-body" id="deploy-toast-msg">OK</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  </div>

</div><!-- /container -->

<script>
// Construir PUSH_URL desde la URL actual del browser (same-origin, sin depender de url_base)
const PUSH_URL = new URL('push.php', window.location.href).href;
let pendingComputerId = null;

// Leer CSRF token de GLPI (meta tag generado por el framework)
function getCsrfToken() {
    return document.querySelector('meta[property="glpi:csrf_token"]')?.content ?? '';
}

function pushUpdate(computerId, equipoName) {
    pendingComputerId = computerId;
    document.getElementById('deploy-equipo-name').textContent = equipoName;
    new bootstrap.Modal(document.getElementById('deployModal')).show();
}

document.getElementById('btn-confirm-deploy')?.addEventListener('click', async () => {
    if (!pendingComputerId) return;
    bootstrap.Modal.getInstance(document.getElementById('deployModal'))?.hide();
    await doDeployRequest([pendingComputerId]);
});

async function bulkUpdate() {
    const ids = Array.from(document.querySelectorAll('.row-chk:checked')).map(c => parseInt(c.value));
    if (!ids.length) return;
    if (!confirm(`¿Confirmar actualización en ${ids.length} equipo(s)?`)) return;
    await doDeployRequest(ids);
}

async function doDeployRequest(computerIds) {
    const toast    = document.getElementById('deploy-toast');
    const toastMsg = document.getElementById('deploy-toast-msg');
    const bsToast  = new bootstrap.Toast(toast);

    // Una sola request con todos los IDs + CSRF token
    const fd = new FormData();
    fd.append('_glpi_csrf_token', getCsrfToken());
    computerIds.forEach(id => fd.append('computer_ids[]', id));

    let okCount = 0, errCount = 0;
    try {
        const r = await fetch(PUSH_URL, {method: 'POST', body: fd});

        // Si la respuesta no es JSON (ej. redirección a login), tratar como error
        const ct = r.headers.get('Content-Type') ?? '';
        if (!ct.includes('application/json')) {
            throw new Error(`Respuesta inesperada del servidor (HTTP ${r.status}). ` +
                            'Recargá la página y volvé a intentar.');
        }

        const j = await r.json();
        okCount  = j.ok_count  ?? (j.ok ? computerIds.length : 0);
        errCount = j.err_count ?? (j.ok ? 0 : computerIds.length);

        // Actualizar badges en cada fila
        if (j.results) {
            j.results.forEach(res => {
                updateRowBadge(res.id,
                    res.ok ? 'secondary' : 'danger',
                    res.ok ? 'ti-clock'  : 'ti-circle-x');
            });
        }
    } catch(e) {
        errCount = computerIds.length;
        toastMsg.textContent = e.message || 'Error al comunicarse con el servidor.';
        toast.className = 'toast align-items-center text-bg-danger border-0';
        bsToast.show();
        return;
    }

    toast.className = `toast align-items-center text-bg-${errCount > 0 && okCount === 0 ? 'danger' : errCount > 0 ? 'warning' : 'success'} border-0`;
    toastMsg.textContent = `Deploy: ${okCount} creada(s)${errCount > 0 ? ', ' + errCount + ' con error' : ''}`;
    bsToast.show();
}

function updateRowBadge(computerId, cls, icon) {
    const row = document.querySelector(`tr[data-computer-id="${computerId}"]`);
    if (!row) return;
    const cell = row.querySelector('td:last-child .d-flex');
    let badge = cell.querySelector('.badge-deploy-status');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'badge-deploy-status badge py-0 px-1';
        cell.appendChild(badge);
    }
    badge.className = `badge-deploy-status badge bg-${cls} py-0 px-1`;
    badge.innerHTML = `<i class="ti ${icon}"></i>`;
}

function toggleAll(chk) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = chk.checked);
    updateBulkBtn();
}

function updateBulkBtn() {
    const n = document.querySelectorAll('.row-chk:checked').length;
    const btn = document.getElementById('btn-bulk-update');
    document.getElementById('sel-count').textContent = n;
    btn.classList.toggle('d-none', n === 0);
    document.getElementById('chk-all').indeterminate =
        n > 0 && n < document.querySelectorAll('.row-chk').length;
}

// Ordenar tabla por columna
document.querySelectorAll('#wu-table thead th:not(:first-child)').forEach((th, idx) => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
        const colIdx = idx + 1;
        const tbody = document.querySelector('#wu-table tbody');
        const rows  = Array.from(tbody.querySelectorAll('tr'));
        const asc   = th.dataset.asc !== 'true';
        th.dataset.asc = asc;
        rows.sort((a, b) => {
            const va = a.cells[colIdx]?.innerText.trim() ?? '';
            const vb = b.cells[colIdx]?.innerText.trim() ?? '';
            return asc ? va.localeCompare(vb,'es',{numeric:true})
                       : vb.localeCompare(va,'es',{numeric:true});
        });
        rows.forEach(r => tbody.appendChild(r));
    });
});
</script>

<?php Html::footer(); ?>
