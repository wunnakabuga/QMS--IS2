<?php
/**
 * Live queue monitor (administrator).
 *
 * Named in the navigation of Wireframe 8. Shows the whole centre at once:
 * every open counter, who is being served, and the queue behind each
 * service. Refreshes itself so it can be left on a screen.
 */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/service_delivery.php';
require_once __DIR__ . '/../modules/prediction.php';

$user = require_role('admin');
expire_lapsed_tickets();

$counters = q_all(
    'SELECT sc.*, u.full_name AS staff_name,
            (SELECT qe.queue_number FROM queue_entry qe
              WHERE qe.counter_id = sc.counter_id
                AND qe.status IN ("called","in_service")
              ORDER BY qe.called_time DESC LIMIT 1) AS now_serving,
            (SELECT qe.status FROM queue_entry qe
              WHERE qe.counter_id = sc.counter_id
                AND qe.status IN ("called","in_service")
              ORDER BY qe.called_time DESC LIMIT 1) AS now_status,
            (SELECT u2.full_name FROM queue_entry qe
               JOIN user u2 ON u2.user_id = qe.user_id
              WHERE qe.counter_id = sc.counter_id
                AND qe.status IN ("called","in_service")
              ORDER BY qe.called_time DESC LIMIT 1) AS client_name,
            (SELECT COUNT(*) FROM queue_entry qe
              WHERE qe.counter_id = sc.counter_id AND qe.status = "completed"
                AND DATE(qe.service_end_time) = CURDATE()) AS served_today
       FROM service_counter sc
  LEFT JOIN user u ON u.user_id = sc.assigned_staff_id
   ORDER BY sc.counter_id');

$services = q_all(
    'SELECT service_type,
            COUNT(*) AS waiting,
            SUM(status = "checked_in") AS checked_in,
            AVG(predicted_wait_time) AS avg_wait,
            MAX(predicted_wait_time) AS max_wait,
            MIN(join_time) AS oldest
       FROM queue_entry
      WHERE status IN ("waiting","checked_in")
      GROUP BY service_type
      ORDER BY waiting DESC');

$queue = q_all(
    'SELECT qe.queue_number, qe.service_type, qe.status, qe.join_time,
            qe.predicted_wait_time, qe.delivery_channel, u.full_name,
            sc.counter_name,
            TIMESTAMPDIFF(MINUTE, qe.join_time, NOW()) AS waiting_minutes
       FROM queue_entry qe
       JOIN user u ON u.user_id = qe.user_id
  LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
      WHERE qe.status IN ("waiting","checked_in","called","in_service")
      ORDER BY FIELD(qe.status,"in_service","called","checked_in","waiting"),
               qe.join_time ASC');

$totalWaiting = 0;
foreach ($services as $s) {
    $totalWaiting += (int)$s['waiting'];
}
$openCounters = 0;
foreach ($counters as $c) {
    if ($c['status'] === 'open') {
        $openCounters++;
    }
}
$longest = $queue ? max(array_map(fn($r) => (int)$r['waiting_minutes'], $queue)) : 0;

page_head('Live queue monitor', 'monitor', [
    'heading'    => 'Live queue monitor',
    'subheading' => 'Whole-centre view, refreshing automatically',
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a class="on" href="monitor.php">Live queue monitor</a>
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
      <div class="stat"><div class="k">Waiting now</div>
        <div class="v"><?= $totalWaiting ?></div>
        <div class="d">across all services</div></div>
      <div class="stat"><div class="k">Counters open</div>
        <div class="v"><?= $openCounters ?><small> / <?= count($counters) ?></small></div></div>
      <div class="stat"><div class="k">Longest wait so far</div>
        <div class="v"><?= $longest ?><small> min</small></div>
        <div class="d">client still in the queue</div></div>
      <div class="stat"><div class="k">Updated</div>
        <div class="v" style="font-size:20px; padding-top:8px;"><?= date('H:i:s') ?></div>
        <div class="d">refreshes every 20 seconds</div></div>
    </div>

    <div class="panel">
      <h2>Counters</h2>
      <div class="monitor-grid">
        <?php foreach ($counters as $c): ?>
          <div class="monitor-card <?= $c['status'] === 'open' ? '' : 'closed' ?>">
            <div class="mc-head">
              <b><?= e($c['counter_name']) ?></b>
              <?= badge($c['status']) ?>
            </div>
            <div class="mc-service"><?= e($c['service_type']) ?></div>
            <?php if ($c['now_serving']): ?>
              <div class="mc-number"><?= e($c['now_serving']) ?></div>
              <div class="mc-client"><?= e($c['client_name'] ?? '') ?></div>
              <div class="tiny muted"><?= badge($c['now_status']) ?></div>
            <?php else: ?>
              <div class="mc-number idle">&mdash;</div>
              <div class="mc-client muted">
                <?= $c['status'] === 'open' ? 'No client called' : 'Counter closed' ?></div>
            <?php endif; ?>
            <div class="mc-foot tiny muted">
              <?= e($c['staff_name'] ?? 'Unassigned') ?>
              &middot; <?= (int)$c['served_today'] ?> served today
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel">
      <h2>Queue by service</h2>
      <?php if (!$services): ?>
        <div class="empty">Nobody is waiting.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Service</th><th>Waiting</th><th>Checked in</th>
            <th>Avg predicted wait</th><th>Longest predicted</th>
            <th>Waiting since</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td><b><?= e($s['service_type']) ?></b></td>
              <td><?= (int)$s['waiting'] ?></td>
              <td><?= (int)$s['checked_in'] ?></td>
              <td><?= $s['avg_wait'] !== null
                    ? e(fmt_minutes($s['avg_wait'])) : '—' ?></td>
              <td><?= $s['max_wait'] !== null
                    ? e(fmt_minutes($s['max_wait'])) : '—' ?></td>
              <td class="tiny"><?= e(date('H:i', strtotime($s['oldest']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Everyone currently in the system</h2>
      <?php if (!$queue): ?>
        <div class="empty">The centre is empty.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Ticket</th><th>Client</th><th>Service</th><th>Counter</th>
            <th>Status</th><th>Waiting</th><th>Predicted</th><th>Channel</th></tr></thead>
          <tbody>
          <?php foreach ($queue as $r): ?>
            <tr>
              <td><b><?= e($r['queue_number']) ?></b></td>
              <td><?= e($r['full_name']) ?></td>
              <td><?= e($r['service_type']) ?></td>
              <td><?= e($r['counter_name'] ?? '—') ?></td>
              <td><?= badge($r['status']) ?></td>
              <td><?= (int)$r['waiting_minutes'] ?> min</td>
              <td><?= in_array($r['status'], ['called','in_service'], true)
                    ? '—' : e(fmt_minutes($r['predicted_wait_time'])) ?></td>
              <td class="tiny"><?= e($r['delivery_channel']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <p class="tiny muted">
      A client-facing version of this view, suitable for a display screen in
      the waiting area, is at <a href="../board.php" target="_blank">board.php</a>.
    </p>
  </div>
</div>
<script src="../assets/js/app.js"></script>
<script>QMS.autoReload(20000);</script>
<?php page_foot(); ?>
