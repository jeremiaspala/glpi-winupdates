<?php
/**
 * AJAX endpoint: forzar actualización vía GLPI Deploy
 * POST: computer_ids[] (array de int) + _glpi_csrf_token
 * Devuelve JSON siempre, nunca redirecciona.
 */
include('../../../inc/includes.php');

// Responder siempre JSON aunque haya error de sesión
header('Content-Type: application/json; charset=utf-8');

// Verificar sesión sin redirigir
if (!isset($_SESSION['glpiID']) || (int)$_SESSION['glpiID'] <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada. Recargá la página.', 'results' => []]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.', 'results' => []]);
    exit;
}

global $DB;

// Aceptar un solo ID o array de IDs
$raw_ids = $_POST['computer_ids'] ?? ($_POST['computer_id'] ?? []);
if (!is_array($raw_ids)) {
    $raw_ids = [(int)$raw_ids];
}
$computer_ids = array_filter(array_map('intval', $raw_ids), fn($id) => $id > 0);

if (empty($computer_ids)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Sin IDs de equipo válidos.', 'results' => []]);
    exit;
}

$results = [];
$ok_count  = 0;
$err_count = 0;

foreach ($computer_ids as $computer_id) {
    // Verificar que el equipo existe
    $iter = $DB->request([
        'FROM'  => 'glpi_computers',
        'WHERE' => ['id' => $computer_id, 'is_deleted' => 0],
        'LIMIT' => 1,
    ]);
    if (!$iter->current()) {
        $results[] = ['id' => $computer_id, 'ok' => false, 'msg' => 'Equipo no encontrado'];
        $err_count++;
        continue;
    }

    $res = PluginWinupdatesDeploy::pushToComputer($computer_id);
    $results[] = array_merge(['id' => $computer_id], $res);
    if ($res['ok']) $ok_count++;
    else $err_count++;
}

echo json_encode([
    'ok'      => $err_count === 0,
    'ok_count'=> $ok_count,
    'err_count'=> $err_count,
    'msg'     => "{$ok_count} tarea(s) creada(s)" . ($err_count > 0 ? ", {$err_count} error(es)" : ''),
    'results' => $results,
]);
exit;
