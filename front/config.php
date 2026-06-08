<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

// Guardar tabla Linux
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_linux'])) {
    $table = [];
    $patterns = $_POST['linux_pattern']  ?? [];
    $mins     = $_POST['linux_min']      ?? [];
    $eols     = $_POST['linux_eol']      ?? [];
    $labels   = $_POST['linux_label']    ?? [];
    $ends     = $_POST['linux_end']      ?? [];
    foreach ($patterns as $i => $pat) {
        $pat = trim($pat);
        if (!$pat) continue;
        $table[$pat] = [
            'min_version' => trim($mins[$i] ?? '0'),
            'eol'         => isset($eols[$i]),
            'label'       => trim($labels[$i] ?? $pat),
            'support_end' => trim($ends[$i] ?? ''),
        ];
    }
    global $DB;
    $DB->delete('glpi_plugin_winupdates_config', ['name' => 'linux_table']);
    $DB->insert('glpi_plugin_winupdates_config', [
        'name'  => 'linux_table',
        'value' => json_encode($table, JSON_PRETTY_PRINT),
    ]);
    Session::addMessageAfterRedirect('✓ Tabla Linux guardada.', true, INFO);
    Html::redirect('config.php');
}

if (isset($_GET['reset_linux'])) {
    global $DB;
    $DB->delete('glpi_plugin_winupdates_config', ['name' => 'linux_table']);
    Session::addMessageAfterRedirect('✓ Tabla Linux restaurada a valores por defecto.', true, INFO);
    Html::redirect('config.php');
}

// Guardar cambios Windows
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_builds'])) {
    $table = [];
    $kernels = $_POST['kernel']   ?? [];
    $mins    = $_POST['min_build'] ?? [];
    $eols    = $_POST['eol']       ?? [];
    $labels  = $_POST['label']     ?? [];
    $ends    = $_POST['support_end'] ?? [];

    foreach ($kernels as $i => $kern) {
        $kern = trim($kern);
        if (!$kern) continue;
        $table[$kern] = [
            'min_build'   => trim($mins[$i] ?? '0'),
            'eol'         => isset($eols[$i]),
            'label'       => trim($labels[$i] ?? $kern),
            'support_end' => trim($ends[$i] ?? ''),
        ];
    }
    PluginWinupdatesReport::saveBuildTable($table);
    Session::addMessageAfterRedirect('✓ Configuración guardada correctamente.', true, INFO);
    Html::redirect('config.php');
}

if (isset($_GET['reset'])) {
    PluginWinupdatesReport::saveBuildTable(PluginWinupdatesReport::getDefaultBuildTable());
    Session::addMessageAfterRedirect('✓ Tabla restaurada a valores por defecto.', true, INFO);
    Html::redirect('config.php');
}

// Guardar tipos de actualización a verificar (para el cálculo de faltantes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_update_types'])) {
    $types = $_POST['check_type'] ?? [];
    PluginWinupdatesReport::saveCheckedTypes(is_array($types) ? array_map('strval', $types) : []);
    Session::addMessageAfterRedirect('✓ Tipos de actualización guardados.', true, INFO);
    Html::redirect('config.php');
}

// Guardar catálogo de actualizaciones de referencia (para detectar faltantes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_catalog'])) {
    $catalog = [];
    $kbases = $_POST['cat_kbase'] ?? [];
    $kbs    = $_POST['cat_kb']    ?? [];
    $types  = $_POST['cat_type']  ?? [];
    $labels = $_POST['cat_label'] ?? [];
    $dates  = $_POST['cat_date']  ?? [];
    foreach ($kbases as $i => $kbase) {
        $kbase = trim($kbase);
        $kb    = trim($kbs[$i] ?? '');
        if ($kbase === '' || $kb === '') continue;
        $catalog[] = [
            'kbase' => $kbase,
            'kb'    => strtoupper($kb),
            'type'  => trim($types[$i] ?? 'optional'),
            'label' => trim($labels[$i] ?? ''),
            'date'  => trim($dates[$i] ?? ''),
        ];
    }
    PluginWinupdatesReport::saveUpdateCatalog($catalog);
    Session::addMessageAfterRedirect('✓ Catálogo de actualizaciones guardado.', true, INFO);
    Html::redirect('config.php');
}

if (isset($_GET['reset_catalog'])) {
    PluginWinupdatesReport::saveUpdateCatalog([]);
    Session::addMessageAfterRedirect('✓ Catálogo de actualizaciones vaciado.', true, INFO);
    Html::redirect('config.php');
}

$buildTable   = PluginWinupdatesReport::getBuildTable();
$linuxTable   = PluginWinupdatesReport::getLinuxTable();
$updateTypes  = PluginWinupdatesReport::getUpdateTypes();
$checkedTypes = PluginWinupdatesReport::getCheckedTypes();
$catalog      = PluginWinupdatesReport::getUpdateCatalog();
Html::header('Win Updates — Configuración', $_SERVER['PHP_SELF'], 'winupdates', 'winupdates');
?>
<div class="container-fluid p-3" style="max-width:900px">
  <h2 class="mb-3">
    <i class="ti ti-settings me-2 text-secondary"></i>
    Configuración — Tabla de builds de referencia
  </h2>
  <p class="text-muted mb-3">
    Actualizá los <strong>builds mínimos requeridos</strong> después de cada Patch Tuesday de Microsoft.
    El <strong>build mínimo</strong> es el número de revisión esperado en equipos correctamente parcheados.
  </p>

  <?php Html::displayMessageAfterRedirect(); ?>

  <form method="POST">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="ti ti-table me-1"></i> Versiones de Windows y builds de referencia
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-dark">
            <tr>
              <th>Kernel / Build base</th>
              <th>Nombre legible</th>
              <th>Build mínimo requerido</th>
              <th>¿EOL?</th>
              <th>Fin de soporte (AAAA-MM)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($buildTable as $kernel => $data): ?>
          <tr>
            <td>
              <input type="text" name="kernel[]" value="<?= htmlspecialchars($kernel) ?>"
                     class="form-control form-control-sm font-monospace">
            </td>
            <td>
              <input type="text" name="label[]" value="<?= htmlspecialchars($data['label'] ?? '') ?>"
                     class="form-control form-control-sm">
            </td>
            <td>
              <input type="text" name="min_build[]" value="<?= htmlspecialchars($data['min_build'] ?? '0') ?>"
                     class="form-control form-control-sm font-monospace"
                     placeholder="ej. 19045.6456"
                     <?= !empty($data['eol']) ? 'disabled' : '' ?>>
            </td>
            <td class="text-center">
              <input type="checkbox" name="eol[<?= $loop ?? 0 ?>]" class="form-check-input eol-check"
                     <?= !empty($data['eol']) ? 'checked' : '' ?>>
              <?php $loop = ($loop ?? 0) + 1; ?>
            </td>
            <td>
              <input type="text" name="support_end[]" value="<?= htmlspecialchars($data['support_end'] ?? '') ?>"
                     class="form-control form-control-sm"
                     placeholder="ej. 2027-10">
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex gap-2">
        <button type="submit" name="save_builds" class="btn btn-primary btn-sm">
          <i class="ti ti-device-floppy me-1"></i> Guardar cambios
        </button>
        <a href="?reset=1"
           onclick="return confirm('¿Restaurar valores por defecto?')"
           class="btn btn-outline-secondary btn-sm">
          <i class="ti ti-restore me-1"></i> Restaurar defaults
        </a>
        <a href="report.php" class="btn btn-outline-primary btn-sm ms-auto">
          <i class="ti ti-arrow-left me-1"></i> Volver al reporte
        </a>
      </div>
    </div>
  </form>

  <!-- ── Tabla Linux ── -->
  <form method="POST" class="mt-4">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="ti ti-brand-ubuntu me-1"></i> Versiones Linux de referencia
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-dark">
            <tr>
              <th>Nombre del SO (prefijo)</th>
              <th>Nombre legible</th>
              <th>Versión mínima</th>
              <th>¿EOL?</th>
              <th>Fin soporte (AAAA-MM)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($linuxTable as $pattern => $data): ?>
          <tr>
            <td><input type="text" name="linux_pattern[]" value="<?= htmlspecialchars($pattern) ?>"
                       class="form-control form-control-sm"></td>
            <td><input type="text" name="linux_label[]" value="<?= htmlspecialchars($data['label'] ?? '') ?>"
                       class="form-control form-control-sm"></td>
            <td><input type="text" name="linux_min[]" value="<?= htmlspecialchars($data['min_version'] ?? '0') ?>"
                       class="form-control form-control-sm font-monospace"
                       placeholder="ej. 12.11"
                       <?= !empty($data['eol']) ? 'disabled' : '' ?>></td>
            <td class="text-center">
              <input type="checkbox" name="linux_eol[<?= $linuxLoop ?? 0 ?>]"
                     class="form-check-input linux-eol-check"
                     <?= !empty($data['eol']) ? 'checked' : '' ?>>
              <?php $linuxLoop = ($linuxLoop ?? 0) + 1; ?>
            </td>
            <td><input type="text" name="linux_end[]" value="<?= htmlspecialchars($data['support_end'] ?? '') ?>"
                       class="form-control form-control-sm" placeholder="ej. 2028-06"></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex gap-2">
        <button type="submit" name="save_linux" class="btn btn-primary btn-sm">
          <i class="ti ti-device-floppy me-1"></i>Guardar Linux
        </button>
        <a href="?reset_linux=1" onclick="return confirm('¿Restaurar tabla Linux?')"
           class="btn btn-outline-secondary btn-sm">
          <i class="ti ti-restore me-1"></i>Restaurar defaults
        </a>
      </div>
    </div>
  </form>

  <!-- ── Tipos de actualización a verificar ── -->
  <form method="POST" class="mt-4">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="ti ti-filter me-1"></i> Tipos de actualización a verificar
      </div>
      <div class="card-body">
        <p class="text-muted small mb-2">
          Elegí qué tipos de actualización del <strong>catálogo de referencia</strong> (abajo) se
          tienen en cuenta para calcular las <strong>faltantes</strong> de cada equipo. Por ejemplo,
          podés auditar solo Seguridad y Críticas, e ignorar Definiciones de antivirus o Funcionalidades.
        </p>
        <div class="d-flex flex-wrap gap-3">
          <?php foreach ($updateTypes as $key => $info): ?>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="check_type[]" id="ct-<?= $key ?>"
                     value="<?= htmlspecialchars($key) ?>"
                     <?= in_array($key, $checkedTypes, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ct-<?= $key ?>">
                <span class="badge bg-<?= $info['badge'] ?>"><?= htmlspecialchars($info['label']) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-footer">
        <button type="submit" name="save_update_types" class="btn btn-primary btn-sm">
          <i class="ti ti-device-floppy me-1"></i> Guardar tipos
        </button>
      </div>
    </div>
  </form>

  <!-- ── Catálogo de actualizaciones de referencia ── -->
  <form method="POST" class="mt-4" id="catalog-form">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="ti ti-list-details me-1"></i> Catálogo de actualizaciones de referencia (para detectar faltantes)
      </div>
      <div class="card-body p-0">
        <p class="text-muted small px-3 pt-3 mb-2">
          No existe forma de bajar el catálogo completo de Microsoft Update a una instalación on-premise.
          Cargá acá, mes a mes, los <strong>KB que esperás ver instalados</strong> en cada versión de
          Windows (kernel base, igual que en la tabla de arriba) con su tipo. El plugin marcará como
          <strong>faltante</strong> cualquier KB de un tipo verificado que no aparezca en el inventario
          del equipo.
        </p>
        <table class="table table-sm mb-0" id="catalog-table">
          <thead class="table-dark">
            <tr>
              <th>Kernel / Build base</th>
              <th>KB</th>
              <th>Tipo</th>
              <th>Descripción</th>
              <th>Publicación (AAAA-MM-DD)</th>
              <th style="width:40px"></th>
            </tr>
          </thead>
          <tbody>
          <?php
            // Fila molde para clonar al agregar nuevas (oculta si ya hay catálogo cargado)
            $catalogRows = !empty($catalog) ? $catalog : [[]];
          ?>
          <?php foreach ($catalogRows as $ci => $entry): ?>
          <tr<?= empty($catalog) ? ' class="catalog-template-row d-none"' : '' ?>>
            <td><input type="text" name="cat_kbase[]" value="<?= htmlspecialchars($entry['kbase'] ?? '') ?>"
                       class="form-control form-control-sm font-monospace" placeholder="ej. 10.0.26100"></td>
            <td><input type="text" name="cat_kb[]" value="<?= htmlspecialchars($entry['kb'] ?? '') ?>"
                       class="form-control form-control-sm font-monospace" placeholder="ej. KB5044284"></td>
            <td>
              <select name="cat_type[]" class="form-select form-select-sm">
                <?php foreach ($updateTypes as $key => $info): ?>
                  <option value="<?= htmlspecialchars($key) ?>"
                          <?= ($entry['type'] ?? '') === $key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($info['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="cat_label[]" value="<?= htmlspecialchars($entry['label'] ?? '') ?>"
                       class="form-control form-control-sm" placeholder="ej. Acumulativa octubre 2025"></td>
            <td><input type="text" name="cat_date[]" value="<?= htmlspecialchars($entry['date'] ?? '') ?>"
                       class="form-control form-control-sm" placeholder="2025-10-14"></td>
            <td class="text-center">
              <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 catalog-row-del" title="Eliminar fila">
                <i class="ti ti-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex gap-2">
        <button type="button" id="catalog-add-row" class="btn btn-outline-secondary btn-sm">
          <i class="ti ti-plus me-1"></i> Agregar fila
        </button>
        <button type="submit" name="save_catalog" class="btn btn-primary btn-sm">
          <i class="ti ti-device-floppy me-1"></i> Guardar catálogo
        </button>
        <a href="?reset_catalog=1" onclick="return confirm('¿Vaciar el catálogo de actualizaciones?')"
           class="btn btn-outline-secondary btn-sm">
          <i class="ti ti-trash me-1"></i> Vaciar catálogo
        </a>
      </div>
    </div>
  </form>

  <div class="card mt-3">
    <div class="card-header fw-bold">
      <i class="ti ti-info-circle me-1"></i> Cómo actualizar después de Patch Tuesday
    </div>
    <div class="card-body small">
      <ol class="mb-0">
        <li>El segundo martes de cada mes Microsoft publica actualizaciones acumulativas.</li>
        <li>Consultá el <a href="https://support.microsoft.com/en-us/help/4498140" target="_blank">
          Microsoft Security Update Guide</a> o buscá el KB del mes.</li>
        <li>El número de build actualizado aparece en el artículo de soporte (ej. <code>19045.6789</code>).</li>
        <li>Actualizá el campo <strong>Build mínimo requerido</strong> de cada versión y guardá.</li>
        <li>Los equipos con un build menor aparecerán en <span class="badge bg-warning text-dark">Actualiz. pendientes</span>.</li>
      </ol>
    </div>
  </div>
</div>

<script>
// Deshabilitar campo min_build si es EOL
document.querySelectorAll('.eol-check').forEach(chk => {
    const row = chk.closest('tr');
    chk.addEventListener('change', () => {
        row.querySelector('input[name^="min_build"]').disabled = chk.checked;
    });
});

// ── Catálogo de actualizaciones: agregar / eliminar filas ──
const catalogBody = document.querySelector('#catalog-table tbody');

function bindCatalogRowDelete(row) {
    row.querySelector('.catalog-row-del')?.addEventListener('click', () => row.remove());
}
catalogBody.querySelectorAll('tr').forEach(bindCatalogRowDelete);

document.getElementById('catalog-add-row')?.addEventListener('click', () => {
    const tpl = catalogBody.querySelector('tr');
    const row = tpl ? tpl.cloneNode(true) : null;
    if (!row) return;
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    row.classList.remove('catalog-template-row', 'd-none');
    catalogBody.appendChild(row);
    bindCatalogRowDelete(row);
});
</script>
<?php Html::footer(); ?>
