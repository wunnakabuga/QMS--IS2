<?php
/** Service counter console — Wireframe 7. Implements FR-14 to FR-16. */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/service_delivery.php';
require_once __DIR__ . '/../modules/dispatch.php';

$user = require_login();
if ($user['role'] === 'admin' && isset($_GET['counter'])) {
    $counter = q_one('SELECT * FROM service_counter WHERE counter_id = :c',
                     [':c' => (int)$_GET['counter']]);
} else {
    $counter = staff_counter($user['user_id']);
}

if (!$counter) {
    page_head('Counter console', 'counter');
    echo '<div class="alert info">You are not currently assigned to a service counter. '
       . 'Ask an administrator to assign you on the counters page.</div>';
    page_foot();
    exit;
}

$counterId = (int)$counter['counter_id'];
expire_lapsed_tickets();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $action  = $_POST['action'] ?? '';
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $result  = ['ok' => false, 'error' => 'Unknown action.'];

    switch ($action) {
        case 'call_next':  $result = call_next($counterId);        break;
        case 'start':      $result = start_service($entryId);      break;
        case 'complete':   $result = complete_service($entryId);   break;
        case 'no_show':    $result = mark_no_show($entryId);       break;
        case 'toggle':
            $newStatus = $counter['status'] === 'open' ? 'closed' : 'open';
            q('UPDATE service_counter SET status = :s WHERE counter_id = :c',
              [':s' => $newStatus, ':c' => $counterId]);
            recalculate_predictions($counter['service_type']);
            $result = ['ok' => true];
            flash('Counter is now ' . $newStatus . '.', 'info');
            break;
    }
    if (!$result['ok'] && !empty($result['error'])) {
        flash($result['error'], 'error');
    } elseif ($result['ok'] && $action === 'complete') {
        flash('Service completed in '
              . gmdate('i:s', (int)($result['duration_seconds'] ?? 0)) . '.', 'success');
    }
    redirect('counter.php' . ($user['role'] === 'admin' ? '?counter=' . $counterId : ''));
}

$current  = current_at_counter($counterId);
$upcoming = upcoming_for_counter($counterId, 8);
$stats    = counter_stats($counterId);

$elapsed = null;
if ($current) {
    $from = $current['service_start_time'] ?: $current['called_time'];
    $elapsed = $from ? max(0, time() - strtotime($from)) : 0;
}

page_head('Counter console', 'counter', [
    'heading'    => $counter['counter_name'] . ' · ' . $counter['service_type'],
    'subheading' => 'Signed in as ' . $user['full_name'],
]);
?>
<div class="grid g4" style="margin-bottom:18px;">
  <div class="stat"><div class="k">Waiting</div>
    <div class="v" data-live="waiting-count"><?= $stats['waiting'] ?></div>
    <div class="d">in the <?= e($counter['service_type']) ?> queue</div></div>
  <div class="stat"><div class="k">Served today</div>
    <div class="v"><?= $stats['served_today'] ?></div></div>
  <div class="stat"><div class="k">Avg service time</div>
    <div class="v"><?= $stats['avg_service_minutes'] ?: '—' ?><small> min</small></div></div>
  <div class="stat"><div class="k">Counter status</div>
    <div class="v" style="font-size:18px; padding-top:6px;"><?= badge($counter['status']) ?></div></div>
</div>

<div class="split">
  <div class="panel tint">
    <h2>Now serving</h2>
    <?php if ($current): ?>
      <div style="text-align:center; padding:8px 0 4px;">
        <div class="ticket-number"><?= e($current['queue_number']) ?></div>
        <div class="ticket-service">
          <?= e($current['full_name']) ?> &middot; <?= e($current['service_type']) ?>
        </div>
        <div class="tiny muted mt">
          <?= badge($current['status']) ?>
          &middot; <?= $current['called_time']
                        ? 'called ' . e(date('H:i', strtotime($current['called_time']))) : '' ?>
          <?= $elapsed !== null ? ' &middot; elapsed ' . gmdate('i:s', $elapsed) : '' ?>
        </div>
      </div>
      <form method="post" class="mt">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="entry_id" value="<?= (int)$current['queue_entry_id'] ?>">
        <div class="btn-row" style="margin-bottom:10px;">
          <?php if ($current['status'] === 'called'): ?>
            <button class="btn" name="action" value="start" style="flex:1;">Start service</button>
          <?php endif; ?>
          <button class="btn" name="action" value="complete" style="flex:1;">Complete service</button>
          <button class="btn danger" name="action" value="no_show" style="flex:1;"
                  onclick="return confirm('Flag this client as a no-show?');">Mark no-show</button>
        </div>
      </form>
    <?php else: ?>
      <div class="empty" style="padding:34px 20px;">
        No client at this counter.<br>
        <span class="tiny">Call the next client to begin.</span>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn block" name="action" value="call_next"
                <?= $counter['status'] !== 'open' || !$upcoming ? 'disabled' : '' ?>>
          Call next client
        </button>
      </form>
      <?php if ($counter['status'] !== 'open'): ?>
        <p class="tiny muted mt">This counter is closed. Open it below to start serving.</p>
      <?php elseif (!$upcoming): ?>
        <p class="tiny muted mt">There is nobody waiting for
          <?= e($counter['service_type']) ?> right now.</p>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="mt">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn ghost block sm" name="action" value="toggle">
        <?= $counter['status'] === 'open' ? 'Close this counter' : 'Open this counter' ?>
      </button>
    </form>
  </div>

  <div class="panel">
    <h2>Next in line</h2>
    <?php if (!$upcoming): ?>
      <div class="empty">The queue is empty.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>Ticket</th><th>Client</th><th>Waiting</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($upcoming as $i => $row): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($row['queue_number']) ?></b></td>
            <td><?= e($row['full_name']) ?></td>
            <td><?= (int)$row['waiting_minutes'] ?> min</td>
            <td><?= badge($row['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="tiny muted mt">
        Checked-in clients are called first. Tickets not checked in within
        <?= GRACE_PERIOD_MINUTES ?> minutes of being called expire automatically.
      </p>
    <?php endif; ?>
  </div>
</div>

<script src="../assets/js/app.js"></script>
<script>QMS.pollCounter(<?= $counterId ?>);</script>
<?php page_foot(); ?>
