<?php
/** Landing page — Wireframe 1. */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/modules/prediction.php';
require_once __DIR__ . '/modules/service_delivery.php';

expire_lapsed_tickets();

$inQueue = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry WHERE status IN ("waiting","checked_in")', [], 0);
$openCounters = (int)q_val(
    'SELECT COUNT(*) FROM service_counter WHERE status = "open"', [], 0);
$avgWait = q_val(
    'SELECT AVG(predicted_wait_time) FROM queue_entry
      WHERE status IN ("waiting","checked_in") AND predicted_wait_time IS NOT NULL');

$serviceRows = q_all(
    'SELECT sc.service_type,
            SUM(sc.status = "open") AS open_counters,
            (SELECT COUNT(*) FROM queue_entry qe
              WHERE qe.service_type = sc.service_type
                AND qe.status IN ("waiting","checked_in")) AS waiting
       FROM service_counter sc
      GROUP BY sc.service_type
      ORDER BY sc.service_type');

page_head('Home', 'home');
?>
<div class="hero">
  <h1>Join the queue before you leave home</h1>
  <p>Get a ticket number and a waiting time predicted from live queue conditions
     and historical service patterns, so you know when to travel.</p>
  <div class="btn-row" style="justify-content:center;">
    <a class="btn ghost" href="join.php">Join queue now</a>
    <a class="btn on-dark" href="track.php">Track my ticket</a>
  </div>
</div>

<div class="grid g3" style="margin-bottom:18px;">
  <div class="stat">
    <div class="k">People in queue now</div>
    <div class="v"><?= $inQueue ?></div>
    <div class="d">across <?= $openCounters ?> open counter<?= $openCounters === 1 ? '' : 's' ?></div>
  </div>
  <div class="stat">
    <div class="k">Average predicted wait</div>
    <div class="v"><?= $avgWait !== null ? e(fmt_minutes($avgWait)) : '&mdash;' ?></div>
    <div class="d">recalculated on every queue event</div>
  </div>
  <div class="stat">
    <div class="k">Centre status</div>
    <div class="v" style="font-size:24px;"><?= centre_is_open() ? 'OPEN' : 'CLOSED' ?></div>
    <div class="d"><?= sprintf('%02d:00 &ndash; %02d:00 Mon&ndash;Fri', OPENING_HOUR, CLOSING_HOUR) ?></div>
  </div>
</div>

<div class="panel">
  <h2>Services offered at this centre</h2>
  <div class="service-grid">
    <?php foreach ($serviceRows as $row): ?>
      <div class="service-card">
        <?= e($row['service_type']) ?>
        <small>
          <?= (int)$row['waiting'] ?> waiting &middot;
          <?= (int)$row['open_counters'] ?> counter<?= (int)$row['open_counters'] === 1 ? '' : 's' ?> open
        </small>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php page_foot(); ?>
