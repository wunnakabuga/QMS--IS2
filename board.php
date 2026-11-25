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
  body { background: #1a0c0d; color: #fff; }
  .board { padding: 28px 34px; min-height: 100vh; }
  .board-head { display: flex; justify-content: space-between;
                align-items: baseline; margin-bottom: 26px; gap: 16px; }
  .board-head h1 { font-size: 30px; font-weight: 800; letter-spacing: -.4px; }
  .board-head .sub { color: #e2a6a8; font-size: 15px; letter-spacing: .5px; }
  .board-head .clock { font-size: 32px; font-weight: 700; color: #f2d4d5; }
  .board-mute { background: #2a1517; color: #f2d4d5; border: 1px solid #5c2a2d;
                border-radius: 8px; padding: 8px 14px; font-size: 14px;
                font-weight: 600; cursor: pointer; }
  .board-mute:focus-visible { outline: 3px solid #e05a5f; outline-offset: 2px; }
  .serving { display: grid; gap: 18px; margin-bottom: 30px;
             grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
  .scard { background: #2a1517; border: 2px solid #5c2a2d; border-radius: 16px;
           padding: 22px; text-align: center; }
  .scard.live { background: #a81f26; border-color: #e05a5f; }
  .scard .counter { font-size: 14px; letter-spacing: 1.6px; color: #e6abad;
                    text-transform: uppercase; font-weight: 700; }
  .scard.live .counter { color: #ffd9da; }
  .scard .num { font-size: 62px; font-weight: 800; line-height: 1.05; margin: 8px 0 4px; }
  .scard .svc { font-size: 14px; color: #eccccd; }
  .board h2 { font-size: 15px; letter-spacing: 1.6px; text-transform: uppercase;
              color: #cf9193; margin-bottom: 14px; font-weight: 700; }
  .next { display: grid; gap: 12px;
          grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
  .ncard { background: #231315; border: 1px solid #4a2628; border-radius: 11px;
           padding: 14px; text-align: center; }
  .ncard .n { font-size: 27px; font-weight: 700; }
  .ncard .w { font-size: 12px; color: #d6a4a6; margin-top: 3px; }
  .board-foot { margin-top: 30px; display: flex; justify-content: space-between;
                color: #cf9193; font-size: 14px; flex-wrap: wrap; gap: 12px; }
  .empty-board { color: #cf9193; padding: 40px; text-align: center; font-size: 18px; }
</style>
</head>
<body>
<div class="board">
  <p id="board-live" class="sr-only" role="status" aria-live="assertive" aria-atomic="true"></p>
  <div class="board-head">
    <div>
      <h1><?= e(CENTRE_NAME) ?></h1>
      <div class="sub">Now serving</div>
    </div>
    <div style="display:flex; align-items:center; gap:16px;">
      <button type="button" id="mute" class="board-mute" aria-pressed="false">Announcements: on</button>
      <div class="clock" id="clock" aria-label="Current time"><?= date('H:i') ?></div>
    </div>
  </div>

  <?php if (!$serving): ?>
    <div class="empty-board">No client is currently being called.</div>
  <?php else: ?>
    <div class="serving" role="region" aria-label="Now serving">
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
(function () {
  // Live clock.
  function tick() {
    document.getElementById('clock').textContent =
      new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  setInterval(tick, 1000);

  // Announcement mute toggle, remembered per display.
  var btn = document.getElementById('mute');
  function isMuted() { return localStorage.getItem('qms_board_mute') === '1'; }
  function paint() {
    if (!btn) return;
    btn.textContent = isMuted() ? 'Announcements: off' : 'Announcements: on';
    btn.setAttribute('aria-pressed', isMuted() ? 'true' : 'false');
  }
  if (btn) {
    btn.addEventListener('click', function () {
      localStorage.setItem('qms_board_mute', isMuted() ? '0' : '1');
      paint();
      // Clicking also satisfies the browser gesture needed to unlock audio.
      if (!isMuted() && 'speechSynthesis' in window) {
        window.speechSynthesis.cancel();
      }
    });
  }
  paint();

  // Which tickets are being called right now, with their counter.
  var current = [].slice.call(document.querySelectorAll('.scard')).map(function (c) {
    var num = (c.querySelector('.num') || {}).textContent || '';
    var ctr = (c.querySelector('.counter') || {}).textContent || '';
    return ctr.trim() + '|' + num.trim();
  });

  // Compare against what was on screen before this refresh, so we only
  // announce a genuinely new call rather than re-reading the whole board.
  var seen = [];
  try { seen = JSON.parse(localStorage.getItem('qms_board_seen') || '[]'); } catch (e) {}
  var fresh = current.filter(function (x) { return seen.indexOf(x) === -1; });

  if (fresh.length) {
    var phrases = fresh.map(function (x) {
      var p = x.split('|');
      return 'Now serving ticket ' + p[1] + ' at ' + p[0];
    });
    // Screen-reader announcement.
    var live = document.getElementById('board-live');
    if (live) live.textContent = phrases.join('. ') + '.';
    // Spoken announcement over the waiting-area speakers.
    var reduce = window.matchMedia
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!isMuted() && !reduce && 'speechSynthesis' in window) {
      fresh.forEach(function (x) {
        var p = x.split('|');
        var say = 'Now serving ticket ' + p[1].replace(/-/g, ' ') + ', at ' + p[0];
        var u = new SpeechSynthesisUtterance(say);
        u.rate = 0.95;
        window.speechSynthesis.speak(u);
      });
    }
  }
  try { localStorage.setItem('qms_board_seen', JSON.stringify(current)); } catch (e) {}

  setTimeout(function () { window.location.reload(); }, 15000);
})();
</script>
</body>
</html>
