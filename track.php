<?php
/** Live queue tracking — Wireframe 4. Implements FR-13. */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/modules/queue_registration.php';
require_once __DIR__ . '/modules/service_delivery.php';

expire_lapsed_tickets();

$reference = trim($_GET['ref'] ?? '');
$entry = $reference !== '' ? find_ticket($reference) : null;
$notFound = $reference !== '' && !$entry;

$queue = [];
if ($entry) {
    $queue = q_all(
        'SELECT qe.queue_entry_id, qe.queue_number, qe.service_type, qe.status,
                qe.predicted_wait_time, sc.counter_name
           FROM queue_entry qe
      LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
          WHERE qe.service_type = :st
            AND qe.status IN ("waiting","checked_in","called","in_service")
          ORDER BY FIELD(qe.status,"in_service","called","checked_in","waiting"),
                   qe.join_time ASC
          LIMIT 15',
        [':st' => $entry['service_type']]
    );
}

page_head('Track ticket', 'track');
?>
<div class="page-title">Track your ticket</div>
<div class="page-sub">Enter your ticket number or the phone number you registered with</div>

<div class="panel">
  <form method="get" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
    <div style="flex:1; min-width:240px;">
      <label for="ref">Ticket number or phone number</label>
      <input type="text" id="ref" name="ref" value="<?= e($reference) ?>"
             placeholder="A-001 or 07XX XXX XXX" style="margin-bottom:0;">
    </div>
    <button class="btn" type="submit">Track</button>
  </form>
</div>

<?php if ($notFound): ?>
  <div class="alert error">No ticket found for &ldquo;<?= e($reference) ?>&rdquo;.
    <a href="join.php">Join the queue</a> to get one.</div>
<?php endif; ?>

<?php if ($entry): ?>
  <div class="grid g4" style="margin-bottom:18px;">
    <div class="stat"><div class="k">Your ticket</div>
      <div class="v"><?= e($entry['queue_number']) ?></div></div>
    <div class="stat"><div class="k">Position</div>
      <div class="v"><?= $entry['queue_position'] !== null ? (int)$entry['queue_position'] : '&mdash;' ?></div></div>
    <div class="stat"><div class="k">Predicted wait</div>
      <div class="v" style="font-size:24px;"><?= e(fmt_minutes($entry['predicted_wait_time'])) ?></div></div>
    <div class="stat"><div class="k">Status</div>
      <div class="v" style="font-size:18px; padding-top:6px;"><?= badge($entry['status']) ?></div></div>
  </div>

  <div class="panel">
    <h2><?= e($entry['service_type']) ?> queue</h2>
    <?php if (!$queue): ?>
      <div class="empty">The queue is currently empty.</div>
    <?php else: ?>
      <table>
        <thead><tr>
          <th>Ticket</th><th>Counter</th><th>Status</th><th>Predicted wait</th>
        </tr></thead>
        <tbody>
        <?php foreach ($queue as $row): ?>
          <tr class="<?= (int)$row['queue_entry_id'] === (int)$entry['queue_entry_id'] ? 'me' : '' ?>">
            <td><?= e($row['queue_number']) ?>
              <?= (int)$row['queue_entry_id'] === (int)$entry['queue_entry_id']
                    ? ' &larr; your ticket' : '' ?></td>
            <td><?= e($row['counter_name'] ?? '—') ?></td>
            <td><?= badge($row['status']) ?></td>
            <td><?= in_array($row['status'], ['called','in_service'], true)
                      ? '&mdash;' : e(fmt_minutes($row['predicted_wait_time'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p class="tiny muted mt">Waiting times refresh automatically every 15 seconds.</p>
  </div>

  <div class="btn-row">
    <a class="btn ghost" href="ticket.php?id=<?= (int)$entry['queue_entry_id'] ?>">Open my ticket</a>
  </div>

  <script src="assets/js/app.js"></script>
  <script>QMS.autoReload(15000);</script>
<?php endif; ?>
<?php page_foot(); ?>
