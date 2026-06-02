<?php
/**
 * Exportación a PDF del reporte de Windows Update Status
 * Genera HTML optimizado para impresión / guardado como PDF
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

$filters = [
    'status'      => $_GET['status']      ?? 'all',
    'entities_id' => (int)($_GET['entities_id'] ?? 0),
];

$computers = PluginWinupdatesReport::getAllComputers($filters);
$summary   = PluginWinupdatesReport::getSummary(
    PluginWinupdatesReport::getAllComputers(['entities_id' => $filters['entities_id']])
);
$total = array_sum($summary);

$statusLabels = [
    'all'      => 'Todos los equipos',
    'eol'      => 'Sin soporte (EOL)',
    'outdated' => 'Actualizaciones pendientes',
    'updated'  => 'Al día',
    'unknown'  => 'Sin datos',
    'linux'    => 'Linux / No Windows',
];
$currentFilter = $statusLabels[$filters['status']] ?? 'Todos';
$generatedAt   = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Windows Update Status — TPR — <?= $generatedAt ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Calibri, Arial, sans-serif; font-size: 10pt;
           color: #222; background: #fff; }

    /* ── Cabecera de documento ── */
    .doc-header { background: #1a3a5c; color: #fff; padding: 18px 24px;
                  display: flex; justify-content: space-between; align-items: center; }
    .doc-header h1 { font-size: 16pt; font-weight: 700; }
    .doc-header .meta { font-size: 8.5pt; opacity: .85; text-align: right; line-height: 1.6; }
    .doc-subheader { background: #2e6da4; color: #fff; padding: 6px 24px;
                     font-size: 8.5pt; display: flex; justify-content: space-between; }

    /* ── KPI grid ── */
    .kpi-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: 8px;
                padding: 14px 24px; background: #f8f9fa; border-bottom: 2px solid #dee2e6; }
    .kpi-card { border-radius: 6px; padding: 10px 8px; text-align: center; border: 1px solid #dee2e6; }
    .kpi-card .num  { font-size: 22pt; font-weight: 800; line-height: 1; }
    .kpi-card .lbl  { font-size: 7.5pt; font-weight: 600; margin-top: 2px; }
    .kpi-card .pct  { font-size: 7pt; color: #666; }
    .kpi-green   { background:#d1e7dd; border-color:#a3cfbb; }
    .kpi-yellow  { background:#fff3cd; border-color:#ffe69c; }
    .kpi-red     { background:#ffeaea; border-color:#f5c2c7; }
    .kpi-blue    { background:#cfe2ff; border-color:#b6d4fe; }
    .kpi-gray    { background:#e9ecef; border-color:#ced4da; }

    /* ── Tabla ── */
    .section-title { padding: 10px 24px 4px; font-size: 10pt; font-weight: 700;
                     color: #1a3a5c; border-bottom: 2px solid #1a3a5c; margin: 0 16px 8px; }
    table { width: calc(100% - 48px); margin: 0 24px 20px; border-collapse: collapse;
            font-size: 8.5pt; }
    thead th { background: #1a3a5c; color: #fff; padding: 6px 8px;
               text-align: left; font-weight: 600; }
    tbody tr:nth-child(even) td { background: #f8f9fa; }
    tbody td { padding: 5px 8px; border-bottom: 1px solid #e9ecef; vertical-align: middle; }

    /* ── Status badges ── */
    .badge { display: inline-block; border-radius: 4px; padding: 2px 7px;
             font-size: 7.5pt; font-weight: 700; color: #fff; }
    .badge-danger   { background: #dc3545; }
    .badge-warning  { background: #ffc107; color: #333; }
    .badge-success  { background: #198754; }
    .badge-info     { background: #0dcaf0; color: #333; }
    .badge-secondary{ background: #6c757d; }

    .row-eol     td { background: #fff5f5 !important; }
    .row-outdated td { background: #fffdf0 !important; }
    .row-updated  td { background: #f0fff4 !important; }

    /* ── Footer ── */
    .doc-footer { margin: 20px 24px 0; padding: 10px 0; border-top: 1px solid #dee2e6;
                  font-size: 7.5pt; color: #888; display: flex;
                  justify-content: space-between; }

    /* ── Print ── */
    @media print {
      .no-print { display: none !important; }
      body { font-size: 8.5pt; }
      .doc-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .kpi-grid { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .row-eol td, .row-outdated td, .row-updated td {
        -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      table { font-size: 7.5pt; }
      @page { margin: 1cm; size: A4 landscape; }
    }
  </style>
</head>
<body>

<!-- Botón imprimir (no se imprime) -->
<div class="no-print" style="padding:10px 24px; background:#f8f9fa; border-bottom:1px solid #dee2e6;
     display:flex; gap:10px; align-items:center;">
  <button onclick="window.print()"
          style="background:#dc3545;color:#fff;border:none;padding:8px 18px;
                 border-radius:4px;font-size:10pt;cursor:pointer;font-weight:600;">
    🖨 Imprimir / Guardar PDF
  </button>
  <a href="report.php" style="color:#1a3a5c;font-size:10pt;">← Volver al reporte</a>
  <span style="color:#888;font-size:9pt;margin-left:auto">
    Para guardar como PDF: Imprimir → Destino: "Guardar como PDF" → Orientación: Horizontal
  </span>
</div>

<!-- Cabecera del documento -->
<div class="doc-header">
  <div>
    <h1>🛡 Windows Update Status — Compliance Report</h1>
    <div style="font-size:9pt;opacity:.85;margin-top:4px">
      Terminal Puerto Rosario S.A. &nbsp;|&nbsp; Infraestructura IT
    </div>
  </div>
  <div class="meta">
    Generado: <?= $generatedAt ?><br>
    Filtro aplicado: <?= htmlspecialchars($currentFilter) ?><br>
    Equipos mostrados: <?= count($computers) ?> / <?= $total ?>
  </div>
</div>
<div class="doc-subheader">
  <span>Fuente de datos: GLPI Agent (inventario automático)</span>
  <span>Referencia: Microsoft Security Update Guide &nbsp;|&nbsp; Uso: Compliance interno</span>
</div>

<!-- KPIs -->
<div class="kpi-grid">
  <?php
  $kpis = [
    ['count'=>$total,              'label'=>'Total equipos',       'class'=>'kpi-blue'],
    ['count'=>$summary['updated'], 'label'=>'Al día',              'class'=>'kpi-green'],
    ['count'=>$summary['outdated'],'label'=>'Actualiz. pendientes','class'=>'kpi-yellow'],
    ['count'=>$summary['eol'],     'label'=>'Sin soporte (EOL)',   'class'=>'kpi-red'],
    ['count'=>($summary['unknown']+$summary['linux']),
                                   'label'=>'Sin datos / Linux',   'class'=>'kpi-gray'],
  ];
  foreach ($kpis as $k):
    $pct = $total > 0 ? round($k['count']/$total*100) : 0;
  ?>
  <div class="kpi-card <?= $k['class'] ?>">
    <div class="num"><?= $k['count'] ?></div>
    <div class="lbl"><?= $k['label'] ?></div>
    <div class="pct"><?= $pct ?>% del total</div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Sección tabla -->
<div class="section-title" style="margin-top:14px">
  Detalle por equipo
  <?php if ($filters['status'] !== 'all'): ?>
    — Filtrado: <?= htmlspecialchars($currentFilter) ?>
  <?php endif; ?>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Equipo</th>
      <th>Sistema Operativo</th>
      <th>Versión</th>
      <th>Build instalado</th>
      <th>Build mínimo</th>
      <th>Estado</th>
      <th>Fin de soporte</th>
      <th>Último inventario</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($computers)): ?>
    <tr><td colspan="9" style="text-align:center;padding:20px;color:#888">
      Sin equipos para los filtros seleccionados.
    </td></tr>
  <?php else: ?>
    <?php foreach ($computers as $i => $row):
        $s = $row['status_data'];
        $rowClass = match($s['status']) {
            'eol'      => 'row-eol',
            'outdated' => 'row-outdated',
            'updated'  => 'row-updated',
            default    => '',
        };
        $badge = match($s['status']) {
            'eol'      => ['danger',    'Sin soporte (EOL)'],
            'outdated' => ['warning',   'Actualiz. pendientes'],
            'updated'  => ['success',   'Al día'],
            'linux'    => ['info',      'Linux'],
            default    => ['secondary', 'Sin datos'],
        };
        $eolDate = !empty($s['support_end'])
            ? \DateTime::createFromFormat('Y-m', $s['support_end'])
            : null;
        $eolPast = $eolDate && $eolDate < new \DateTime();
    ?>
    <tr class="<?= $rowClass ?>">
      <td style="color:#888"><?= $i + 1 ?></td>
      <td style="font-weight:600"><?= htmlspecialchars($row['equipo']) ?>
        <?php if ($row['ubicacion']): ?>
          <br><span style="font-size:7.5pt;color:#888;font-weight:400">
            <?= htmlspecialchars($row['ubicacion']) ?>
          </span>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars($row['os_name'] ?? '—') ?></td>
      <td><?= htmlspecialchars($row['os_version'] ?? '—') ?></td>
      <td style="font-family:monospace"><?= htmlspecialchars($row['service_pack'] ?: ($row['os_kernel'] ?? '—')) ?></td>
      <td style="font-family:monospace;color:#888"><?= htmlspecialchars($s['min_build'] ?? '—') ?></td>
      <td><span class="badge badge-<?= $badge[0] ?>"><?= $badge[1] ?></span></td>
      <td style="<?= $eolPast ? 'color:#dc3545;font-weight:700' : 'color:#198754' ?>">
        <?= htmlspecialchars($s['support_end'] ?? '—') ?>
        <?= $eolPast ? ' ⚠' : '' ?>
      </td>
      <td style="color:#888">
        <?= $row['ultimo_inventario']
            ? date('d/m/Y', strtotime($row['ultimo_inventario']))
            : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<!-- Leyenda -->
<div style="margin:0 24px 16px; padding:10px 14px; background:#f8f9fa; border:1px solid #dee2e6;
     border-radius:6px; font-size:8pt;">
  <strong>Leyenda:</strong> &nbsp;
  <span class="badge badge-success">Al día</span> SO soportado y build ≥ mínimo requerido &nbsp;|&nbsp;
  <span class="badge badge-warning">Actualiz. pendientes</span> SO soportado pero build desactualizado &nbsp;|&nbsp;
  <span class="badge badge-danger">Sin soporte (EOL)</span> SO sin actualizaciones de seguridad de Microsoft
</div>

<div class="doc-footer">
  <span>Terminal Puerto Rosario S.A. — Confidencial — Uso interno</span>
  <span>Datos provistos por GLPI Agent. Builds de referencia actualizables en Configuración del plugin.</span>
  <span>Pág. 1</span>
</div>

</body>
</html>
