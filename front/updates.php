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
    'FROM'  => 'glpi_computers',
    'WHERE' => ['id' => $id, 'is_deleted' => 0],
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

echo json_encode([
    'ok'        => true,
    'computer'  => $computer['name'],
    'count'     => count($updates),
    'updates'   => $updates,
    'freshness' => $freshness,
]);
exit;
