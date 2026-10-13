<?php
/** E-ticket — Wireframe 3. Implements FR-07 and FR-11. */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/modules/queue_registration.php';
require_once __DIR__ . '/modules/service_delivery.php';

expire_lapsed_tickets();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'check_in') {
        $r = check_in($id);
        flash($r['ok'] ? 'Attendance confirmed. Please wait to be called.'
                       : $r['error'], $r['ok'] ? 'success' : 'error');
    } elseif ($action === 'cancel') {
        $r = cancel_ticket($id);
        flash($r['ok'] ? 'Your ticket has been cancelled.' : $r['error'],
              $r['ok'] ? 'info' : 'error');
    } elseif ($action === 'resend') {
        $entry = ticket_view($id);
        if ($entry) {
            $msg = compose_ticket_message($entry, $entry['queue_position']);
            $res = sms_send($entry['phone_number'], $msg);
            record_notification($id, 'sms', $res['status'], $msg);
            flash('Ticket re-sent by SMS (' . $res['status'] . ').', 'success');
        }
    }
    redirect('ticket.php?id=' . $id);
}

$entry = $id ? ticket_view($id) : null;
if (!$entry) {
    page_head('Ticket not found');
    echo '<div class="alert error">That ticket could not be found. '
       . '<a href="track.php">Track a ticket</a> or <a href="join.php">join the queue</a>.</div>';
    page_foot();
    exit;
}

$nowServing = q_one(
    'SELECT qe.queue_number, sc.counter_name
       FROM queue_entry qe LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
      WHERE qe.service_type = :st AND qe.status IN ("called","in_service")
      ORDER BY qe.called_time DESC LIMIT 1',
    [':st' => $entry['service_type']]
);

$totalWaiting = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry
      WHERE service_type = :st AND status IN ("waiting","checked_in")',
    [':st' => $entry['service_type']], 0);

$position = $entry['queue_position'];
$progress = ($position && $totalWaiting > 0)
    ? (int)round((1 - ($position - 1) / max(1, $totalWaiting)) * 100) : 100;

$smsMessage = compose_ticket_message($entry, $position);
$isOpen = in_array($entry['status'], ['waiting', 'checked_in', 'called', 'in_service'], true);

// Absolute URL encoded in the QR code, so a phone scanning it at the centre
// lands on this ticket and can check in.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\');
$checkInUrl = sprintf('%s://%s%s/ticket.php?id=%d',
                      $scheme, $host, $dir, (int)$entry['queue_entry_id']);

page_head('Your ticket', 'join');
?>
<div class="page-title">
  <?= $entry['status'] === 'waiting' || $entry['status'] === 'checked_in'
        ? 'Your ticket has been issued' : 'Your ticket' ?>
</div>
<div class="page-sub">
  Step 3 of 3 &mdash; a copy has also been sent to <?= e($entry['phone_number']) ?>
</div>
<div class="steps"><span class="on">1</span><i></i><span class="on">2</span><i></i><span class="on">3</span></div>

<div class="split">
  <div class="ticket" id="ticket">
    <div class="ticket-head">
      <span class="lbl">Ticket number</span>
      <span class="chan"><?= $entry['delivery_channel'] === 'sms' ? 'SMS TICKET' : 'E-TICKET' ?></span>
    </div>
    <div class="ticket-body">
      <div class="ticket-number"><?= e($entry['queue_number']) ?></div>
      <div class="ticket-service">
        <?= e($entry['service_type']) ?>
        <?= $entry['counter_name'] ? ' &middot; ' . e($entry['counter_name']) : '' ?>
      </div>

      <hr class="dash">
      <div class="ticket-pair">
        <div>
          <div class="k">Predicted waiting time</div>
          <div class="v" data-live="wait"><?= e(fmt_minutes($entry['predicted_wait_time'])) ?></div>
        </div>
        <div>
          <div class="k">Position in queue</div>
          <div class="v" data-live="position">
            <?= $position !== null ? (int)$position : '&mdash;' ?>
          </div>
        </div>
      </div>

      <hr class="dash">
      <div class="ticket-meta">
        <b>Status:</b> <?= badge($entry['status']) ?><br>
        <b>Issued:</b> <?= e(date('d M Y, H:i', strtotime($entry['join_time']))) ?><br>
        <?php if ($entry['scheduled_time']): ?>
          <b>Expected to be called:</b> <?= e(date('H:i', strtotime($entry['scheduled_time']))) ?><br>
        <?php endif; ?>
        <?php if ($entry['expiry_time'] && $entry['status'] === 'called'): ?>
          <b>Check in before:</b> <?= e(date('H:i', strtotime($entry['expiry_time']))) ?>
          (<?= GRACE_PERIOD_MINUTES ?>-minute grace period)<br>
        <?php endif; ?>
        <?php if ($entry['prediction_source']): ?>
          <span class="tiny muted">Estimate produced by the
            <?= $entry['prediction_source'] === 'model'
                  ? 'Random Forest model' : 'queueing-theory fallback' ?></span>
        <?php endif; ?>
      </div>

      <?php if ($isOpen): ?>
        <hr class="dash">
        <div class="qr-block">
          <div id="qr" class="qr"
               data-value="<?= e($checkInUrl) ?>"></div>
          <p class="tiny muted">Scan at the centre to check in</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2>Live status</h2>
      <div class="stat" style="margin-bottom:14px;">
        <div class="k">Now serving</div>
        <div class="v" data-live="now"><?= $nowServing ? e($nowServing['queue_number']) : '&mdash;' ?></div>
        <div class="d" data-live="now-counter">
          <?= $nowServing && $nowServing['counter_name']
                ? e($nowServing['counter_name']) : 'No client currently called' ?>
        </div>
      </div>
      <label>Progress to your turn</label>
      <div class="progress"><i data-live="progress" style="width:<?= $progress ?>%"></i></div>
      <p class="tiny muted mt" data-live="stamp">This page refreshes automatically.</p>
    </div>

    <?php if (!$isOpen && $entry['status'] === 'completed'): ?>
      <div class="panel">
        <h2>How was your visit?</h2>
        <p class="tiny muted" style="margin-bottom:14px;">
          Two quick questions help us check whether the predicted waiting times
          are accurate.
        </p>
        <a class="btn block"
           href="feedback.php?id=<?= (int)$entry['queue_entry_id'] ?>">Give feedback</a>
      </div>
    <?php endif; ?>

    <?php if ($isOpen): ?>
    <div class="panel">
      <h2>Actions</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div style="display:flex; flex-direction:column; gap:10px;">
          <?php if (in_array($entry['status'], ['waiting', 'called'], true)): ?>
            <button class="btn" name="action" value="check_in">Confirm attendance / check in</button>
          <?php endif; ?>
          <button class="btn ghost" name="action" value="resend">Resend ticket by SMS</button>
          <button class="btn danger" name="action" value="cancel"
                  onclick="return confirm('Cancel this ticket?');">Cancel my ticket</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h2>SMS copy of this ticket</h2>
      <div class="sms-preview"><?= e($smsMessage) ?></div>
      <p class="tiny muted mt">
        Gateway mode: <?= e(SMS_MODE) ?>. The same information is carried on both channels.
      </p>
    </div>
  </div>
</div>

<script src="assets/js/vendor/qrcode.js"></script>
<script src="assets/js/app.js"></script>
<script>
  QMS.pollTicket(<?= (int)$entry['queue_entry_id'] ?>);
  QMS.renderQr('qr');
</script>
<?php page_foot(); ?>
