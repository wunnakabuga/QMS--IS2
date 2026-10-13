<?php
/** Join queue — Wireframe 2. Implements FR-01 to FR-03. */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ratelimit.php';
require_once __DIR__ . '/modules/queue_registration.php';

$error = null;
$old = ['name' => '', 'phone' => '', 'email' => '', 'service' => '', 'sms' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'name'    => $_POST['full_name']  ?? '',
        'phone'   => $_POST['phone']      ?? '',
        'email'   => $_POST['email']      ?? '',
        'service' => $_POST['service']    ?? '',
        'sms'     => !empty($_POST['sms_only']),
    ];
    // Rate limit: at most 5 ticket attempts from one address in 10 minutes,
    // and 3 attempts against one phone number in the same window.
    $ipLimit = rate_limit_hit('join:ip:' . client_ip(), 5, 600);
    $phoneKey = 'join:phone:' . normalise_phone(trim($old['phone']));
    $phoneLimit = $old['phone'] !== ''
        ? rate_limit_hit($phoneKey, 3, 600)
        : ['allowed' => true, 'retry_after' => 0];

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif (!$ipLimit['allowed'] || !$phoneLimit['allowed']) {
        $wait = max($ipLimit['retry_after'], $phoneLimit['retry_after']);
        $error = 'Too many ticket requests from this device. Please wait '
               . retry_phrase($wait) . ' and try again.';
    } else {
        $result = join_queue(
            $old['name'], $old['phone'], $old['email'], $old['service'],
            $old['sms'] ? 'sms' : 'e-ticket'
        );
        if ($result['ok']) {
            redirect('ticket.php?id=' . $result['entry_id']);
        }
        $error = $result['error'];
        if (!empty($result['entry_id'])) {
            $error .= ' ';
        }
    }
}

$inQueue = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry WHERE status IN ("waiting","checked_in")', [], 0);
$openCounters = (int)q_val(
    'SELECT COUNT(*) FROM service_counter WHERE status = "open"', [], 0);
$avgService = q_val(
    'SELECT AVG(service_duration)/60 FROM service_log
      WHERE status = "completed" AND service_duration IS NOT NULL');
$load = min(100, $openCounters > 0 ? (int)round($inQueue / ($openCounters * 8) * 100) : 100);

page_head('Join queue', 'join');
?>
<div class="page-title">Join the queue</div>
<div class="page-sub">Step 1 of 3 &mdash; enter your details</div>
<div class="steps"><span class="on">1</span><i></i><span>2</span><i></i><span>3</span></div>

<?php if ($error): ?>
  <div class="alert error"><?= e($error) ?>
    <a href="track.php">Track an existing ticket</a>.</div>
<?php endif; ?>

<div class="split">
  <div class="panel">
    <h2>Your details</h2>
    <form method="post" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <label for="full_name">Full name <span class="hint">required</span></label>
      <input type="text" id="full_name" name="full_name" required
             placeholder="e.g. Charles Kabuga" value="<?= e($old['name']) ?>">

      <label for="phone">Phone number
        <span class="hint">required &mdash; your ticket is also sent here by SMS</span></label>
      <input type="tel" id="phone" name="phone" required
             placeholder="07XX XXX XXX" value="<?= e($old['phone']) ?>">

      <label for="email">Email address <span class="hint">optional</span></label>
      <input type="email" id="email" name="email"
             placeholder="you@example.com" value="<?= e($old['email']) ?>">

      <label for="service">Service required <span class="hint">required</span></label>
      <select id="service" name="service" required>
        <option value="">Select a service</option>
        <?php foreach ($SERVICE_TYPES as $svc): ?>
          <?php $open = open_counter_count($svc); ?>
          <option value="<?= e($svc) ?>" <?= $old['service'] === $svc ? 'selected' : '' ?>
                  <?= $open === 0 ? 'disabled' : '' ?>>
            <?= e($svc) ?><?= $open === 0 ? ' — no counter open' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="check">
        <input type="checkbox" id="sms_only" name="sms_only" value="1"
               <?= $old['sms'] ? 'checked' : '' ?>>
        <span><label for="sms_only" style="margin:0;display:inline;">
          I do not have a smartphone</label> &mdash; send my ticket by SMS only</span>
      </div>

      <button type="submit" class="btn block">Get my ticket</button>
      <p class="tiny muted mt">Your ticket number and predicted waiting time are
         issued immediately.</p>
    </form>
  </div>

  <div>
    <div class="panel">
      <h2>Live queue conditions</h2>
      <div class="grid g2">
        <div class="stat"><div class="k">In queue</div><div class="v"><?= $inQueue ?></div></div>
        <div class="stat"><div class="k">Open counters</div><div class="v"><?= $openCounters ?></div></div>
      </div>
      <div class="mt">
        <label>Current queue load</label>
        <div class="progress"><i style="width:<?= $load ?>%"></i></div>
        <p class="tiny muted mt">
          <?= $load < 40 ? 'Light' : ($load < 75 ? 'Moderate' : 'Heavy') ?>
          <?php if ($avgService): ?>
            &mdash; average service time <?= e(number_format((float)$avgService, 1)) ?> min per client
          <?php else: ?>
            &mdash; no service history yet, estimates use the documented default
          <?php endif; ?>
        </p>
      </div>
    </div>

    <div class="panel">
      <h2>What happens next</h2>
      <ol style="padding-left:18px; font-size:14px; color:#4a5866; line-height:2;">
        <li>You receive a ticket number and a predicted waiting time</li>
        <li>A reminder is sent as your turn approaches</li>
        <li>Check in at the centre within <?= GRACE_PERIOD_MINUTES ?> minutes of being called</li>
      </ol>
    </div>
  </div>
</div>
<?php page_foot(); ?>
