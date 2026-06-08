<?php
/**
 * AJAX endpoint: detalle de actualizaciones (KB) instaladas en un equipo
 * GET: id (int, computers_id)
 * Devuelve JSON siempre.
 */
include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['glpiID']) || (int)$_SESSION['glpiID'] <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada. Recargá la página.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'ID de equipo inválido.']);
    exit;
}

global $DB;
$iter = $DB->request([
    'SELECT' => ['c.id', 'c.name', 'oskv.name AS os_kernel'],
    'FROM'   => 'glpi_computers AS c',
    'LEFT JOIN' => [
        'glpi_items_operatingsystems AS ios' => [
            'FKEY' => ['ios' => 'items_id', 'c' => 'id'],
            'AND'  => ['ios.itemtype' => 'Computer', 'ios.is_deleted' => 0],
        ],
        'glpi_operatingsystemkernelversions AS oskv' => [
            'FKEY' => ['oskv' => 'id', 'ios' => 'operatingsystemkernelversions_id'],
        ],
    ],
    'WHERE' => ['c.id' => $id, 'c.is_deleted' => 0],
    'LIMIT' => 1,
]);
$computer = $iter->current();
if (!$computer) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Equipo no encontrado.']);
    exit;
}

$updates   = PluginWinupdatesReport::getInstalledUpdates($id);
$freshness = PluginWinupdatesReport::getUpdatesFreshness($updates[0]['fecha'] ?? null);

$kernelBase   = trim($computer['os_kernel'] ?? '');
$missing      = $kernelBase !== '' ? PluginWinupdatesReport::getMissingUpdates($kernelBase, array_column($updates, 'kb')) : [];
$catalog      = PluginWinupdatesReport::getUpdateCatalog();
$hasCatalogForKernel = false;
foreach ($catalog as $entry) {
    if (!empty($entry['kbase']) && str_starts_with($kernelBase, trim($entry['kbase']))) {
        $hasCatalogForKernel = true;
        break;
    }
}
$updateTypes = PluginWinupdatesReport::getUpdateTypes();
$checkedTypes = PluginWinupdatesReport::getCheckedTypes();

echo json_encode([
    'ok'                   => true,
    'computer'             => $computer['name'],
    'count'                => count($updates),
    'updates'              => $updates,
    'freshness'            => $freshness,
    'missing'              => $missing,
    'has_catalog_for_os'   => $hasCatalogForKernel,
    'update_types'         => $updateTypes,
    'checked_types'        => $checkedTypes,
]);
exit;
