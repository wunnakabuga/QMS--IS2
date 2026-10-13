<?php
/** Administrator dashboard — Wireframe 8. Implements FR-18 and FR-19. */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/service_delivery.php';
require_once __DIR__ . '/../modules/prediction.php';

$user = require_role('admin');
expire_lapsed_tickets();

// ---------------------------------------------------------------- KPIs
$inQueue = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry WHERE status IN ("waiting","checked_in")', [], 0);
$peakToday = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry WHERE DATE(join_time) = CURDATE()', [], 0);
$avgPredicted = q_val(
    'SELECT AVG(predicted_wait_time) FROM queue_entry
      WHERE status IN ("waiting","checked_in") AND predicted_wait_time IS NOT NULL');
$servedToday = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry
      WHERE status = "completed" AND DATE(service_end_time) = CURDATE()', [], 0);
$noShowsToday = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry
      WHERE status = "expired" AND DATE(expiry_time) = CURDATE()', [], 0);

// Prediction accuracy: predicted against the derived actual waiting time.
$accuracy = q_one(
    'SELECT COUNT(*) AS n,
            AVG(ABS(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60)) AS mae,
            AVG(TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60) AS actual_avg
       FROM queue_entry
      WHERE status = "completed"
        AND predicted_wait_time IS NOT NULL
        AND service_start_time IS NOT NULL
        AND prediction_source = "model"');

$withinTolerance = q_val(
    'SELECT AVG(ABS(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60) <= 5) * 100
       FROM queue_entry
      WHERE status = "completed" AND predicted_wait_time IS NOT NULL
        AND service_start_time IS NOT NULL AND prediction_source = "model"');

// ------------------------------------------------------- hourly volume
$hourly = q_all(
    'SELECT HOUR(join_time) AS h, COUNT(*) AS n
       FROM queue_entry
      WHERE join_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      GROUP BY HOUR(join_time)');
$byHour = [];
foreach ($hourly as $row) {
    $byHour[(int)$row['h']] = (int)$row['n'];
}
$maxHour = $byHour ? max($byHour) : 0;

// ------------------------------------------------------ counter table
$counters = q_all(
    'SELECT sc.*, u.full_name AS staff_name,
            (SELECT qe.queue_number FROM queue_entry qe
              WHERE qe.counter_id = sc.counter_id
                AND qe.status IN ("called","in_service")
              ORDER BY qe.called_time DESC LIMIT 1) AS now_serving,
            (SELECT COUNT(*) FROM queue_entry qe
              WHERE qe.counter_id = sc.counter_id AND qe.status = "completed") AS served,
            (SELECT AVG(sl.service_duration)/60 FROM service_log sl
              WHERE sl.counter_id = sc.counter_id AND sl.status = "completed") AS avg_minutes
       FROM service_counter sc
  LEFT JOIN user u ON u.user_id = sc.assigned_staff_id
   ORDER BY sc.counter_id');

// ------------------------------------------------------ recent entries
$recent = q_all(
    'SELECT qe.queue_number, qe.service_type, qe.status, qe.predicted_wait_time,
            qe.prediction_source, qe.delivery_channel, qe.join_time,
            TIMESTAMPDIFF(SECOND, qe.join_time, qe.service_start_time)/60 AS actual_wait,
            sc.counter_name
       FROM queue_entry qe
  LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
   ORDER BY qe.queue_entry_id DESC LIMIT 12');

$modelTrained = is_file(MODEL_FILE);
$metrics = null;
if (is_file(ML_DIR . '/model/training_metrics.json')) {
    $metrics = json_decode(file_get_contents(ML_DIR . '/model/training_metrics.json'), true);
}

page_head('Dashboard', 'dashboard', [
    'heading'    => 'Administrator dashboard',
    'subheading' => CENTRE_NAME . ' · ' . date('d M Y'),
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a class="on" href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a href="counters.php">Service counters</a>
    <a href="reports.php">Reports &amp; analytics</a>
    <a href="model.php">Prediction model</a>
    <a href="notifications.php">Notifications log</a>
    <a href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <div class="grid g4" style="margin-bottom:18px;">
      <div class="stat"><div class="k">Clients in queue</div>
        <div class="v"><?= $inQueue ?></div>
        <div class="d"><?= $peakToday ?> joined today</div></div>
      <div class="stat"><div class="k">Avg predicted wait</div>
        <div class="v"><?= $avgPredicted !== null
              ? number_format((float)$avgPredicted, 0) : '—' ?><small> min</small></div>
        <div class="d"><?= $accuracy && $accuracy['actual_avg'] !== null
              ? 'actual: ' . number_format((float)$accuracy['actual_avg'], 1) . ' min'
              : 'no completed services yet' ?></div></div>
      <div class="stat"><div class="k">Served today</div>
        <div class="v"><?= $servedToday ?></div>
        <div class="d">no-shows: <?= $noShowsToday ?></div></div>
      <div class="stat"><div class="k">Prediction accuracy</div>
        <div class="v"><?= $withinTolerance !== null
              ? number_format((float)$withinTolerance, 0) : '—' ?><small>%</small></div>
        <div class="d"><?= $accuracy && $accuracy['mae'] !== null
              ? 'MAE ' . number_format((float)$accuracy['mae'], 1) . ' min over '
                . (int)$accuracy['n'] . ' services'
              : 'awaiting completed services' ?></div></div>
    </div>

    <?php if (!$modelTrained): ?>
      <div class="alert info">
        The Random Forest model has not been trained yet, so waiting times are
        being produced by the deterministic fallback. Run
        <code>python3 ml/generate_dataset.py</code> then
        <code>python3 ml/train_model.py</code> to train it.
      </div>
    <?php endif; ?>

    <div class="grid g2">
      <div class="panel">
        <h2>Queue volume by hour (last 7 days)</h2>
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
        <p class="tiny muted">Peak hours identify where counter capacity is short.</p>
      </div>

      <div class="panel">
        <h2>Prediction model</h2>
        <?php if ($metrics): ?>
          <table>
            <tr><td>Training records</td><td class="right"><b><?= (int)$metrics['records'] ?></b></td></tr>
            <tr><td>Held-out test MAE</td><td class="right"><b><?= e($metrics['mae_minutes']) ?> min</b></td></tr>
            <tr><td>R-squared</td><td class="right"><b><?= e($metrics['r2']) ?></b></td></tr>
            <tr><td>Within 5 minutes</td><td class="right"><b><?= e($metrics['within_5_minutes_percent']) ?>%</b></td></tr>
            <tr><td>Baseline (mean) MAE</td><td class="right"><?= e($metrics['baseline_mae_minutes']) ?> min</td></tr>
          </table>
          <p class="tiny muted mt">
            Most influential feature:
            <b><?= e(array_key_first($metrics['feature_importance'])) ?></b>
          </p>
        <?php else: ?>
          <div class="empty">No training metrics found. Train the model to populate this panel.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h2>Counter performance — live</h2>
      <table>
        <thead><tr>
          <th>Counter</th><th>Staff</th><th>Service type</th><th>Status</th>
          <th>Now serving</th><th>Served</th><th>Avg time</th>
        </tr></thead>
        <tbody>
        <?php foreach ($counters as $c): ?>
          <tr>
            <td><b><?= e($c['counter_name']) ?></b></td>
            <td><?= e($c['staff_name'] ?? '—') ?></td>
            <td><?= e($c['service_type']) ?></td>
            <td><?= badge($c['status']) ?></td>
            <td><?= e($c['now_serving'] ?? '—') ?></td>
            <td><?= (int)$c['served'] ?></td>
            <td><?= $c['avg_minutes'] !== null
                    ? number_format((float)$c['avg_minutes'], 1) . ' min' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <h2>Recent queue entries — predicted against actual</h2>
      <?php if (!$recent): ?>
        <div class="empty">No queue entries yet.</div>
      <?php else: ?>
        <table>
          <thead><tr>
            <th>Ticket</th><th>Service</th><th>Counter</th><th>Status</th>
            <th>Predicted</th><th>Actual</th><th>Variance</th><th>Channel</th>
          </tr></thead>
          <tbody>
          <?php foreach ($recent as $r):
              $pred = $r['predicted_wait_time'] !== null ? (float)$r['predicted_wait_time'] : null;
              $act  = $r['actual_wait'] !== null ? (float)$r['actual_wait'] : null;
              $var  = ($pred !== null && $act !== null) ? $act - $pred : null; ?>
            <tr>
              <td><b><?= e($r['queue_number']) ?></b></td>
              <td><?= e($r['service_type']) ?></td>
              <td><?= e($r['counter_name'] ?? '—') ?></td>
              <td><?= badge($r['status']) ?></td>
              <td><?= $pred !== null ? number_format($pred, 1) . ' min' : '—' ?>
                  <?php if ($r['prediction_source'] === 'fallback'): ?>
                    <span class="tiny muted">(fallback)</span>
                  <?php endif; ?></td>
              <td><?= $act !== null ? number_format($act, 1) . ' min' : '—' ?></td>
              <td><?= $var !== null
                    ? ($var >= 0 ? '+' : '') . number_format($var, 1) : '—' ?></td>
              <td class="tiny"><?= e($r['delivery_channel']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt">
          Actual waiting time is the derived interval between join_time and
          service_start_time. Entries produced by the fallback are excluded from
          the accuracy figures above.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php page_foot(); ?>
