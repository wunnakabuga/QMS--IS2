<?php
/** Reports and analytics — Wireframe 10. Implements FR-18 and FR-19. */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../modules/reporting.php';
require_once __DIR__ . '/../modules/service_delivery.php';

$user = require_role('admin');
expire_lapsed_tickets();

/**
 * Write one CSV row.
 * PHP 8.4 deprecates calling fputcsv() without an explicit $escape, and an
 * empty escape string is the RFC 4180 behaviour that Excel expects.
 */
function csv_row($handle, array $fields): void
{
    if (PHP_VERSION_ID >= 70400) {
        fputcsv($handle, $fields, ',', '"', '');
    } else {
        fputcsv($handle, $fields);
    }
}

$f = report_filters($_GET);

// ------------------------------------------------------------ CSV export
if (($_GET['export'] ?? '') === 'csv') {
    // A downloaded file must contain nothing but the file. With display_errors
    // on for development, a stray notice would otherwise be written into the
    // CSV itself and break it in Excel.
    ini_set('display_errors', '0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $rows = report_entries($f);
    $name = sprintf('qms_report_%s_to_%s.csv', $f['from'], $f['to']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    $out = fopen('php://output', 'w');

    // Excel only recognises UTF-8 in a CSV when the byte-order mark is present.
    fwrite($out, "\xEF\xBB\xBF");

    // The escape argument is passed explicitly: PHP 8.4 deprecates relying on
    // its default, and an empty escape is the RFC 4180 behaviour Excel expects.
    csv_row($out, [
        'Queue number', 'Service type', 'Counter', 'Status', 'Join time',
        'Service start', 'Service end', 'Predicted wait (min)',
        'Actual wait (min)', 'Variance (min)', 'Service duration (min)',
        'Prediction source', 'Delivery channel',
    ]);
    foreach ($rows as $r) {
        $pred = $r['predicted_wait_time'] !== null ? (float)$r['predicted_wait_time'] : null;
        $act  = $r['actual_wait'] !== null ? round((float)$r['actual_wait'], 2) : null;
        csv_row($out, [
            $r['queue_number'], $r['service_type'], $r['counter_name'] ?? '',
            $r['status'], $r['join_time'], $r['service_start_time'] ?? '',
            $r['service_end_time'] ?? '',
            $pred !== null ? number_format($pred, 2, '.', '') : '',
            $act !== null ? number_format($act, 2, '.', '') : '',
            ($pred !== null && $act !== null)
                ? number_format($act - $pred, 2, '.', '') : '',
            $r['service_minutes'] !== null
                ? number_format((float)$r['service_minutes'], 2, '.', '') : '',
            $r['prediction_source'] ?? '', $r['delivery_channel'],
        ]);
    }
    fclose($out);
    exit;
}

$printMode = ($_GET['print'] ?? '') === '1';

$summary  = report_summary($f);
$byService = report_by_service($f);
$byHour    = report_by_hour($f);
$heatmap   = report_heatmap($f);
$byCounter = report_by_counter($f);

$total = report_entries_count($f);
$pg    = paginate($total, $printMode ? 500 : 25);
$rows  = report_entries($f, $pg['per'], $pg['offset']);

$counters = q_all('SELECT counter_id, counter_name, service_type
                     FROM service_counter ORDER BY counter_id');
$maxHour = $byHour ? max($byHour) : 0;
$maxCell = 0;
foreach ($heatmap as $day) {
    foreach ($day as $n) {
        $maxCell = max($maxCell, $n);
    }
}
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

page_head('Reports and analytics', 'reports', [
    'heading'    => 'Reports and analytics',
    'subheading' => 'Service performance and prediction accuracy',
]);
?>
<div class="side-layout <?= $printMode ? 'print-mode' : '' ?>">
  <?php if (!$printMode): ?>
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a href="counters.php">Service counters</a>
    <a class="on" href="reports.php">Reports &amp; analytics</a>
    <a href="model.php">Prediction model</a>
    <a href="notifications.php">Notifications log</a>
    <a href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>
  <?php endif; ?>

  <div>
    <?php if ($printMode): ?>
      <div class="print-head">
        <h1><?= e(CENTRE_NAME) ?> &mdash; Queue Performance Report</h1>
        <p><?= e(date('d M Y', strtotime($f['from']))) ?> to
           <?= e(date('d M Y', strtotime($f['to']))) ?>
           <?= $f['service'] !== '' ? ' &middot; ' . e($f['service']) : ' &middot; all services' ?>
           &middot; generated <?= e(date('d M Y H:i')) ?></p>
      </div>
    <?php else: ?>
    <div class="panel no-print">
      <h2>Report filters</h2>
      <form method="get">
        <div class="grid g4">
          <div>
            <label for="from">Date from</label>
            <input type="date" id="from" name="from" value="<?= e($f['from']) ?>">
          </div>
          <div>
            <label for="to">Date to</label>
            <input type="date" id="to" name="to" value="<?= e($f['to']) ?>">
          </div>
          <div>
            <label for="service">Service type</label>
            <select id="service" name="service">
              <option value="">All services</option>
              <?php foreach ($SERVICE_TYPES as $svc): ?>
                <option value="<?= e($svc) ?>" <?= $f['service'] === $svc ? 'selected' : '' ?>>
                  <?= e($svc) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="counter">Counter</label>
            <select id="counter" name="counter">
              <option value="0">All counters</option>
              <?php foreach ($counters as $c): ?>
                <option value="<?= (int)$c['counter_id'] ?>"
                  <?= $f['counter'] === (int)$c['counter_id'] ? 'selected' : '' ?>>
                  <?= e($c['counter_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="btn-row">
          <button class="btn" type="submit">Generate report</button>
          <a class="btn ghost" href="reports.php">Reset</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="grid g4" style="margin-bottom:18px;">
      <div class="stat"><div class="k">Total clients served</div>
        <div class="v"><?= number_format($summary['served']) ?></div>
        <div class="d"><?= number_format($summary['total_entries']) ?> joined the queue</div></div>
      <div class="stat"><div class="k">Avg waiting time</div>
        <div class="v"><?= $summary['avg_actual_wait'] !== null
              ? e((string)$summary['avg_actual_wait']) : '—' ?><small> min</small></div>
        <div class="d"><?= $summary['avg_predicted_wait'] !== null
              ? 'predicted: ' . e((string)$summary['avg_predicted_wait']) . ' min' : '' ?></div></div>
      <div class="stat"><div class="k">No-show rate</div>
        <div class="v"><?= $summary['no_show_rate'] !== null
              ? e((string)$summary['no_show_rate']) : '—' ?><small>%</small></div>
        <div class="d"><?= number_format($summary['no_shows']) ?> expired tickets</div></div>
      <div class="stat"><div class="k">Prediction MAE</div>
        <div class="v"><?= $summary['mae'] !== null ? e((string)$summary['mae']) : '—' ?><small> min</small></div>
        <div class="d"><?= $summary['within_5'] !== null
              ? e((string)$summary['within_5']) . '% within 5 min &middot; n=' . $summary['accuracy_n']
              : 'no model predictions in range' ?></div></div>
    </div>

    <div class="grid g2">
      <div class="panel">
        <h2>Average waiting time by service type</h2>
        <?php if (!$byService): ?>
          <div class="empty">No data in the selected period.</div>
        <?php else: ?>
          <?php $maxWait = 0;
                foreach ($byService as $s) { $maxWait = max($maxWait, (float)$s['avg_wait']); } ?>
          <div class="bars-wrap">
            <div class="bars">
              <?php foreach ($byService as $s):
                  $v = (float)$s['avg_wait'];
                  $pct = $maxWait > 0 ? max(3, (int)round($v / $maxWait * 100)) : 3; ?>
                <div class="b" style="height:<?= $pct ?>%"
                     title="<?= e($s['service_type']) ?>: <?= number_format($v, 1) ?> min">
                  <i><?= number_format($v, 0) ?></i>
                  <b><?= e(substr($s['service_type'], 0, 10)) ?></b>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <p class="tiny muted">Minutes waited before service began.</p>
        <?php endif; ?>
      </div>

      <div class="panel">
        <h2>Queue volume by hour</h2>
        <div class="bars-wrap">
          <div class="bars">
            <?php for ($h = OPENING_HOUR; $h < CLOSING_HOUR; $h++):
                $n = $byHour[$h] ?? 0;
                $pct = $maxHour > 0 ? max(3, (int)round($n / $maxHour * 100)) : 3; ?>
              <div class="b" style="height:<?= $pct ?>%" title="<?= $n ?> clients">
                <b><?= sprintf('%02d', $h) ?></b>
              </div>
            <?php endfor; ?>
          </div>
        </div>
        <p class="tiny muted">Peak windows identify where counter capacity is short.</p>
      </div>
    </div>

    <div class="panel">
      <h2>Peak demand heat map</h2>
      <?php if ($maxCell === 0): ?>
        <div class="empty">No data in the selected period.</div>
      <?php else: ?>
        <table class="heat">
          <thead>
            <tr><th></th>
              <?php for ($h = OPENING_HOUR; $h < CLOSING_HOUR; $h++): ?>
                <th><?= sprintf('%02d', $h) ?></th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($days as $i => $dayName): ?>
              <tr>
                <th class="rowhead"><?= e($dayName) ?></th>
                <?php for ($h = OPENING_HOUR; $h < CLOSING_HOUR; $h++):
                    $n = $heatmap[$i][$h] ?? 0;
                    $intensity = $maxCell > 0 ? $n / $maxCell : 0; ?>
                  <td class="cell" title="<?= e($dayName) ?> <?= sprintf('%02d:00', $h) ?>: <?= $n ?>"
                      style="background: rgba(18,96,63,<?= number_format($intensity * 0.85, 2) ?>);
                             color: <?= $intensity > 0.55 ? '#fff' : '#33414f' ?>;">
                    <?= $n > 0 ? $n : '' ?>
                  </td>
                <?php endfor; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt">Darker cells carry more arrivals. Busiest cell:
          <?= $maxCell ?> clients.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Counter performance</h2>
      <table>
        <thead><tr>
          <th>Counter</th><th>Staff</th><th>Service type</th><th>Entries</th>
          <th>Served</th><th>No-shows</th><th>Avg service time</th>
        </tr></thead>
        <tbody>
        <?php foreach ($byCounter as $c): ?>
          <tr>
            <td><b><?= e($c['counter_name']) ?></b></td>
            <td><?= e($c['staff_name'] ?? '—') ?></td>
            <td><?= e($c['service_type']) ?></td>
            <td><?= (int)$c['entries'] ?></td>
            <td><?= (int)$c['served'] ?></td>
            <td><?= (int)$c['no_shows'] ?></td>
            <td><?= $c['avg_service'] !== null
                    ? number_format((float)$c['avg_service'], 1) . ' min' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <h2>Service log extract &mdash; predicted against actual</h2>
      <?php if (!$rows): ?>
        <div class="empty">No queue entries in the selected period.</div>
      <?php else: ?>
        <table>
          <thead><tr>
            <th>Date</th><th>Ticket</th><th>Service</th><th>Counter</th><th>Status</th>
            <th>Predicted</th><th>Actual</th><th>Variance</th><th>Channel</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $pred = $r['predicted_wait_time'] !== null ? (float)$r['predicted_wait_time'] : null;
              $act  = $r['actual_wait'] !== null ? (float)$r['actual_wait'] : null;
              $var  = ($pred !== null && $act !== null) ? $act - $pred : null; ?>
            <tr>
              <td class="tiny"><?= e(date('d/m/Y H:i', strtotime($r['join_time']))) ?></td>
              <td><b><?= e($r['queue_number']) ?></b></td>
              <td><?= e($r['service_type']) ?></td>
              <td><?= e($r['counter_name'] ?? '—') ?></td>
              <td><?= badge($r['status']) ?></td>
              <td><?= $pred !== null ? number_format($pred, 1) : '—' ?>
                  <?php if ($r['prediction_source'] === 'fallback'): ?>
                    <span class="tiny muted">(fb)</span>
                  <?php endif; ?></td>
              <td><?= $act !== null ? number_format($act, 1) : '—' ?></td>
              <td><?= $var !== null
                    ? '<span class="' . ($var > 0 ? 'over' : 'under') . '">'
                      . ($var >= 0 ? '+' : '') . number_format($var, 1) . '</span>'
                    : '—' ?></td>
              <td class="tiny"><?= e($r['delivery_channel']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?= pager($pg) ?>
        <p class="tiny muted">
          Actual waiting time is the derived interval between join_time and
          service_start_time. Rows marked (fb) were produced by the
          deterministic fallback and are excluded from the accuracy figures above.
        </p>
      <?php endif; ?>

      <?php if (!$printMode): ?>
      <div class="btn-row mt no-print">
        <?php $qs = http_build_query(array_merge($f, ['export' => 'csv'])); ?>
        <a class="btn ghost sm" href="reports.php?<?= e($qs) ?>">Export CSV</a>
        <?php $qsp = http_build_query(array_merge($f, ['print' => '1'])); ?>
        <a class="btn ghost sm" href="reports.php?<?= e($qsp) ?>" target="_blank">
          Printable version / save as PDF</a>
      </div>
      <p class="tiny muted no-print">
        CSV opens in Excel and contains every row in the selected range, not just
        this page. The printable version opens in a new tab &mdash; use your
        browser's Print dialog and choose &ldquo;Save as PDF&rdquo;.
      </p>
      <?php endif; ?>
    </div>

    <?php if ($printMode): ?>
      <script>window.addEventListener('load', () => window.print());</script>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); ?>
