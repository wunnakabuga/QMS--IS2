<?php
/**
 * Public queue display board.
 *
 * Intended for a screen in the waiting area. Section 1.6.2 of the report
 * delimits hardware queue boards out of scope; this is the software
 * equivalent, which needs only a browser on any spare monitor and so stays
 * within the stated scope.
 *
 * No authentication: it shows ticket numbers and counters, never names or
 * phone numbers.
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/modules/service_delivery.php';

expire_lapsed_tickets();

$serving = q_all(
    'SELECT qe.queue_number, qe.status, sc.counter_name, sc.service_type
       FROM queue_entry qe
       JOIN service_counter sc ON sc.counter_id = qe.counter_id
      WHERE qe.status IN ("called","in_service")
      ORDER BY qe.called_time DESC'
);

$upcoming = q_all(
    'SELECT queue_number, service_type, predicted_wait_time
       FROM queue_entry
      WHERE status IN ("waiting","checked_in")
      ORDER BY join_time ASC LIMIT 12'
);

$waiting = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry WHERE status IN ("waiting","checked_in")', [], 0);
$avgWait = q_val(
    'SELECT AVG(predicted_wait_time) FROM queue_entry
      WHERE status IN ("waiting","checked_in") AND predicted_wait_time IS NOT NULL');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Now serving &middot; <?= e(CENTRE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
  body { background: #0e1a14; color: #fff; }
  .board { padding: 28px 34px; min-height: 100vh; }
  .board-head { display: flex; justify-content: space-between;
                align-items: baseline; margin-bottom: 26px; }
  .board-head h1 { font-size: 30px; font-weight: 800; letter-spacing: -.4px; }
  .board-head .sub { color: #8fbfa6; font-size: 15px; letter-spacing: .5px; }
  .board-head .clock { font-size: 32px; font-weight: 700; color: #cfe8db; }
  .serving { display: grid; gap: 18px; margin-bottom: 30px;
             grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
  .scard { background: #16291f; border: 2px solid #2b5741; border-radius: 16px;
           padding: 22px; text-align: center; }
  .scard.live { background: #1d6b45; border-color: #35a06c; }
  .scard .counter { font-size: 14px; letter-spacing: 1.6px; color: #9fd7bb;
                    text-transform: uppercase; font-weight: 700; }
  .scard .num { font-size: 62px; font-weight: 800; line-height: 1.05; margin: 8px 0 4px; }
  .scard .svc { font-size: 14px; color: #bfe3d1; }
  .board h2 { font-size: 15px; letter-spacing: 1.6px; text-transform: uppercase;
              color: #7fb79a; margin-bottom: 14px; font-weight: 700; }
  .next { display: grid; gap: 12px;
          grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
  .ncard { background: #14231b; border: 1px solid #274534; border-radius: 11px;
           padding: 14px; text-align: center; }
  .ncard .n { font-size: 27px; font-weight: 700; }
  .ncard .w { font-size: 12px; color: #8fbfa6; margin-top: 3px; }
  .board-foot { margin-top: 30px; display: flex; justify-content: space-between;
                color: #7fb79a; font-size: 14px; flex-wrap: wrap; gap: 12px; }
  .empty-board { color: #7fb79a; padding: 40px; text-align: center; font-size: 18px; }
</style>
</head>
<body>
<div class="board">
  <div class="board-head">
    <div>
      <h1><?= e(CENTRE_NAME) ?></h1>
      <div class="sub">Now serving</div>
    </div>
    <div class="clock" id="clock"><?= date('H:i') ?></div>
  </div>

  <?php if (!$serving): ?>
    <div class="empty-board">No client is currently being called.</div>
  <?php else: ?>
    <div class="serving">
      <?php foreach ($serving as $s): ?>
        <div class="scard <?= $s['status'] === 'in_service' ? 'live' : '' ?>">
          <div class="counter"><?= e($s['counter_name']) ?></div>
          <div class="num"><?= e($s['queue_number']) ?></div>
          <div class="svc"><?= e($s['service_type']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($upcoming): ?>
    <h2>Next in line</h2>
    <div class="next">
      <?php foreach ($upcoming as $u): ?>
        <div class="ncard">
          <div class="n"><?= e($u['queue_number']) ?></div>
          <div class="w"><?= e(fmt_minutes($u['predicted_wait_time'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="board-foot">
    <div><?= $waiting ?> waiting
      <?= $avgWait !== null ? ' &middot; average predicted wait '
            . e(fmt_minutes($avgWait)) : '' ?></div>
    <div>Join the queue from your phone &mdash; no need to wait here</div>
  </div>
</div>

<script>
  setInterval(() => {
    document.getElementById('clock').textContent =
      new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }, 1000);
  setTimeout(() => window.location.reload(), 15000);
</script>
</body>
</html>
