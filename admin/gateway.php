<?php
/**
 * SMS gateway configuration and connection test (administrator).
 *
 * Lets an administrator select a provider, enter credentials and send a
 * real test message without editing config.php or restarting Apache.
 */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/sms_gateway.php';
require_once __DIR__ . '/../modules/settings.php';

$user = require_role('admin');

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $driver = $_POST['sms_driver'] ?? 'simulate';
        if (!in_array($driver, ['simulate', 'africastalking', 'twilio', 'generic'], true)) {
            $driver = 'simulate';
        }

        $pairs = [
            'sms_driver'        => $driver,
            'sms_username'      => trim($_POST['sms_username'] ?? ''),
            'sms_sender_id'     => trim($_POST['sms_sender_id'] ?? ''),
            'sms_endpoint'      => trim($_POST['sms_endpoint'] ?? ''),
            'sms_payload'       => ($_POST['sms_payload'] ?? 'form') === 'json' ? 'json' : 'form',
            'sms_field_to'      => trim($_POST['sms_field_to'] ?? 'to'),
            'sms_field_message' => trim($_POST['sms_field_message'] ?? 'message'),
            'sms_field_from'    => trim($_POST['sms_field_from'] ?? 'from'),
            'sms_field_key'     => trim($_POST['sms_field_key'] ?? 'api_key'),
            'sms_key_in_body'   => !empty($_POST['sms_key_in_body']) ? '1' : '0',
            'sms_auth_header'   => trim($_POST['sms_auth_header'] ?? 'Authorization: Bearer {key}'),
        ];

        // An empty API key field means "leave the stored key alone", so that
        // the key is never echoed back into the page.
        $key = trim($_POST['sms_api_key'] ?? '');
        if ($key !== '') {
            $pairs['sms_api_key'] = $key;
        }
        if (!empty($_POST['clear_key'])) {
            $pairs['sms_api_key'] = '';
        }

        setting_set_many($pairs);
        flash('Gateway settings saved. Driver is now '
              . sms_driver_label($driver) . '.', 'success');
        redirect('gateway.php');
    }

    if ($action === 'callback_token') {
        // A fresh token invalidates the URL already registered with the
        // provider, so the administrator is told to re-register it.
        setting_set('sms_callback_token', bin2hex(random_bytes(16)));
        flash('A new delivery-report token was generated. Update the callback '
              . 'URL in your provider dashboard, or reports will be refused.',
              'info');
        redirect('gateway.php');
    }

    if ($action === 'test') {
        $phone = normalise_phone(trim($_POST['test_phone'] ?? ''));
        if (!valid_phone($phone)) {
            flash('Enter a valid phone number to test with.', 'error');
            redirect('gateway.php');
        }
        $testResult = sms_test($phone);
        $testResult['phone'] = $phone;
    }
}

$cfg = sms_config();
$hasKey = setting_get('sms_api_key', SMS_API_KEY) !== '';

// Delivery-report callback: the URL the provider posts to when it learns
// whether a message actually reached the handset.
$callbackToken = (string)setting_get('sms_callback_token', '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appDir = rtrim(dirname(dirname($_SERVER['PHP_SELF'] ?? '/')), '/\\');
$callbackUrl = $callbackToken === '' ? null : sprintf(
    '%s://%s%s/api/sms_callback.php?token=%s', $scheme, $host, $appDir, $callbackToken
);
$callbackIsLocal = in_array(strtolower(explode(':', $host)[0]),
                            ['localhost', '127.0.0.1', '::1'], true);

$dlrCount = (int)q_val(
    'SELECT COUNT(*) FROM notification
      WHERE channel = "sms" AND delivery_status IN ("delivered","failed")', [], 0);

$recent = q_all(
    'SELECT n.*, qe.queue_number, u.phone_number
       FROM notification n
       JOIN queue_entry qe ON qe.queue_entry_id = n.queue_entry_id
       JOIN user u ON u.user_id = qe.user_id
      WHERE n.channel = "sms"
      ORDER BY n.notification_id DESC LIMIT 10'
);

$stats = q_one(
    'SELECT COUNT(*) AS total,
            SUM(delivery_status = "sent") AS sent,
            SUM(delivery_status = "simulated") AS simulated,
            SUM(delivery_status = "failed") AS failed
       FROM notification WHERE channel = "sms"') ?: [];

page_head('SMS gateway', 'gateway', [
    'heading'    => 'SMS gateway',
    'subheading' => 'Provider configuration and connection test',
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a href="counters.php">Service counters</a>
    <a href="reports.php">Reports &amp; analytics</a>
    <a href="model.php">Prediction model</a>
    <a href="notifications.php">Notifications log</a>
    <a class="on" href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <div class="grid g4" style="margin-bottom:18px;">
      <div class="stat"><div class="k">Active driver</div>
        <div class="v" style="font-size:17px; padding-top:8px;">
          <span class="badge <?= sms_is_live() ? 'open' : 'waiting' ?>">
            <?= e(sms_driver_label()) ?></span></div></div>
      <div class="stat"><div class="k">Messages sent</div>
        <div class="v"><?= number_format((int)($stats['sent'] ?? 0)) ?></div>
        <div class="d">transmitted by a live gateway</div></div>
      <div class="stat"><div class="k">Simulated</div>
        <div class="v"><?= number_format((int)($stats['simulated'] ?? 0)) ?></div>
        <div class="d">composed but not transmitted</div></div>
      <div class="stat"><div class="k">Failed</div>
        <div class="v"><?= number_format((int)($stats['failed'] ?? 0)) ?></div>
        <div class="d">rejected by the gateway</div></div>
    </div>

    <?php if ($testResult !== null): ?>
      <div class="alert <?= $testResult['status'] === 'failed' ? 'error'
            : ($testResult['status'] === 'sent' ? 'success' : 'info') ?>">
        <b>Test to <?= e($testResult['phone']) ?>:
          <?= e(strtoupper($testResult['status'])) ?></b><br>
        <?= e($testResult['detail']) ?>
        <?php if (!empty($testResult['reference'])): ?>
          <br>Provider reference: <code><?= e($testResult['reference']) ?></code>
        <?php endif; ?>
        <?php if ($testResult['status'] === 'simulated'): ?>
          <br>The driver is set to simulation, so nothing was transmitted.
          Choose a provider below and save before testing again.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!sms_is_live()): ?>
      <div class="alert info">
        The gateway is in <b>simulation mode</b>. Tickets for SMS-only clients
        are composed and recorded in the notifications log with a status of
        <b>simulated</b>, but nothing reaches a handset. This is a valid
        configuration for evaluation &mdash; the dual-channel logic, the message
        content and the audit trail are all exercised. Select a provider below
        when you have credentials.
      </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Provider configuration</h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label for="sms_driver">Gateway provider</label>
        <select id="sms_driver" name="sms_driver" onchange="showDriver(this.value)">
          <option value="simulate" <?= $cfg['driver'] === 'simulate' ? 'selected' : '' ?>>
            Simulation — compose and log only</option>
          <option value="africastalking" <?= $cfg['driver'] === 'africastalking' ? 'selected' : '' ?>>
            Africa's Talking</option>
          <option value="twilio" <?= $cfg['driver'] === 'twilio' ? 'selected' : '' ?>>
            Twilio</option>
          <option value="generic" <?= $cfg['driver'] === 'generic' ? 'selected' : '' ?>>
            Generic HTTP gateway</option>
        </select>

        <div class="grid g2 drv drv-africastalking drv-twilio drv-generic">
          <div>
            <label for="sms_username">
              <span class="lbl-at">Username</span>
              <span class="lbl-tw">Account SID</span>
              <span class="lbl-gen">Username / account (optional)</span>
            </label>
            <input type="text" id="sms_username" name="sms_username"
                   value="<?= e($cfg['username']) ?>"
                   placeholder="sandbox">
          </div>
          <div>
            <label for="sms_sender_id">
              <span class="lbl-at">Sender ID (leave blank if none approved)</span>
              <span class="lbl-tw">Twilio number, E.164 format</span>
              <span class="lbl-gen">Sender ID (optional)</span>
            </label>
            <input type="text" id="sms_sender_id" name="sms_sender_id"
                   value="<?= e($cfg['sender_id']) ?>" placeholder="HUDUMA">
          </div>
        </div>

        <div class="drv drv-africastalking drv-twilio drv-generic">
          <label for="sms_api_key">
            <span class="lbl-at">API key</span>
            <span class="lbl-tw">Auth token</span>
            <span class="lbl-gen">API key or token</span>
            <span class="hint">
              <?= $hasKey ? 'a key is stored — leave blank to keep it'
                          : 'not yet set' ?></span>
          </label>
          <input type="password" id="sms_api_key" name="sms_api_key"
                 autocomplete="new-password"
                 placeholder="<?= $hasKey ? 'Unchanged' : 'Paste your key' ?>">
          <?php if ($hasKey): ?>
            <div class="check">
              <input type="checkbox" id="clear_key" name="clear_key" value="1">
              <span><label for="clear_key" style="display:inline;margin:0;">
                Remove the stored key</label></span>
            </div>
          <?php endif; ?>
        </div>

        <div class="drv drv-generic">
          <hr style="border:none;border-top:1px solid var(--line);margin:18px 0;">
          <p class="tiny muted" style="margin-bottom:14px;">
            Generic settings — use these to talk to a local aggregator without
            writing new code. Field names map your gateway's parameter names
            onto the message.
          </p>
          <label for="sms_endpoint">Endpoint URL</label>
          <input type="url" id="sms_endpoint" name="sms_endpoint"
                 value="<?= e($cfg['endpoint']) ?>"
                 placeholder="https://sms.example.co.ke/api/v1/send">
          <div class="grid g4">
            <div><label for="sms_field_to">Recipient field</label>
              <input type="text" id="sms_field_to" name="sms_field_to"
                     value="<?= e($cfg['field_to']) ?>"></div>
            <div><label for="sms_field_message">Message field</label>
              <input type="text" id="sms_field_message" name="sms_field_message"
                     value="<?= e($cfg['field_message']) ?>"></div>
            <div><label for="sms_field_from">Sender field</label>
              <input type="text" id="sms_field_from" name="sms_field_from"
                     value="<?= e($cfg['field_from']) ?>"></div>
            <div><label for="sms_payload">Payload format</label>
              <select id="sms_payload" name="sms_payload">
                <option value="form" <?= $cfg['payload'] === 'form' ? 'selected' : '' ?>>Form encoded</option>
                <option value="json" <?= $cfg['payload'] === 'json' ? 'selected' : '' ?>>JSON</option>
              </select></div>
          </div>
          <label for="sms_auth_header">Authorisation header template</label>
          <input type="text" id="sms_auth_header" name="sms_auth_header"
                 value="<?= e($cfg['auth_header']) ?>"
                 placeholder="Authorization: Bearer {key}">
          <div class="check">
            <input type="checkbox" id="sms_key_in_body" name="sms_key_in_body" value="1"
                   <?= $cfg['key_in_body'] === '1' ? 'checked' : '' ?>>
            <span><label for="sms_key_in_body" style="display:inline;margin:0;">
              Send the key in the request body instead of a header</label></span>
          </div>
          <label for="sms_field_key">Key field name (when sent in the body)</label>
          <input type="text" id="sms_field_key" name="sms_field_key"
                 value="<?= e($cfg['field_key']) ?>">
        </div>

        <div class="btn-row">
          <button class="btn" name="action" value="save">Save gateway settings</button>
        </div>
      </form>
    </div>

    <div class="panel">
      <h2>Delivery reports (callback URL)</h2>
      <p class="tiny muted" style="margin-bottom:14px;">
        The send request only confirms that the gateway <em>accepted</em> a
        message. Whether it reached the handset is reported back minutes later
        to a callback URL you register with the provider. Without it every SMS
        stays at &ldquo;sent&rdquo; and is never confirmed as delivered.
      </p>

      <?php if ($callbackToken === ''): ?>
        <div class="alert info">
          No callback token has been generated yet, so delivery reports are
          refused. Generate one, then register the URL with your provider.
        </div>
      <?php else: ?>
        <label>Callback URL &mdash; paste this into your provider dashboard</label>
        <div class="sms-preview" style="word-break:break-all;"><?= e($callbackUrl) ?></div>
        <p class="tiny muted mt">
          <b><?= number_format($dlrCount) ?></b> message<?= $dlrCount === 1 ? '' : 's' ?>
          have had a delivery report applied.
          <?php if ($callbackIsLocal): ?>
            <br><b>Note:</b> this URL points at <code><?= e($host) ?></code>, which
            the provider cannot reach from the internet. While developing, expose
            it with a tunnel such as ngrok and register the tunnel URL instead.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <form method="post" class="mt">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn ghost sm" name="action" value="callback_token"
                onclick="return confirm('Generate a new token? The URL currently registered with your provider will stop working until you update it.');">
          <?= $callbackToken === '' ? 'Generate callback token' : 'Regenerate token' ?>
        </button>
      </form>

      <table class="mt">
        <tr><th>Provider</th><th>Where to register the URL</th></tr>
        <tr><td>Africa's Talking</td>
            <td class="tiny">Dashboard &rarr; SMS &rarr; SMS Callbacks &rarr;
                Delivery Reports &rarr; paste the URL</td></tr>
        <tr><td>Twilio</td>
            <td class="tiny">Set it as the <code>StatusCallback</code> on the
                messaging service, or per-message</td></tr>
        <tr><td>Generic</td>
            <td class="tiny">Whatever your aggregator calls DLR, delivery
                receipt or webhook URL</td></tr>
      </table>
    </div>

    <div class="panel">
      <h2>Send a test message</h2>
      <form method="post" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div style="flex:1; min-width:240px;">
          <label for="test_phone">Phone number</label>
          <input type="tel" id="test_phone" name="test_phone" required
                 style="margin-bottom:0;" placeholder="07XX XXX XXX">
        </div>
        <button class="btn" name="action" value="test">Send test</button>
      </form>
      <p class="tiny muted mt">
        The test goes straight to the gateway and does not create a queue entry.
        On Africa's Talking sandbox, the number must first be registered as a
        sandbox test number, and the message appears in the simulator rather
        than on a real handset.
      </p>
    </div>

    <div class="panel">
      <h2>Recent SMS dispatches</h2>
      <?php if (!$recent): ?>
        <div class="empty">No SMS messages have been dispatched yet.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Sent</th><th>Ticket</th><th>To</th><th>Status</th>
            <th>Attempts</th><th>Provider detail</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="tiny"><?= e(date('d/m H:i', strtotime($r['sent_time']))) ?></td>
              <td><b><?= e($r['queue_number']) ?></b></td>
              <td class="tiny"><?= e($r['phone_number']) ?></td>
              <td><span class="badge <?= $r['delivery_status'] === 'sent' ? 'completed'
                    : ($r['delivery_status'] === 'failed' ? 'expired' : 'waiting') ?>">
                  <?= e($r['delivery_status']) ?></span></td>
              <td><?= (int)($r['attempts'] ?? 1) ?></td>
              <td class="tiny muted"><?= e($r['provider_detail'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <p class="tiny muted mt">
        Full message bodies are in the <a href="notifications.php">notifications log</a>.
      </p>
    </div>
  </div>
</div>

<style>
  .drv { display: none; }
  .lbl-at, .lbl-tw, .lbl-gen { display: none; }
</style>
<script>
  function showDriver(driver) {
    document.querySelectorAll('.drv').forEach(el => { el.style.display = 'none'; });
    document.querySelectorAll('.drv-' + driver).forEach(el => {
      el.style.display = el.classList.contains('grid') ? 'grid' : 'block';
    });
    const labels = { africastalking: 'at', twilio: 'tw', generic: 'gen' };
    document.querySelectorAll('.lbl-at, .lbl-tw, .lbl-gen')
      .forEach(el => { el.style.display = 'none'; });
    if (labels[driver]) {
      document.querySelectorAll('.lbl-' + labels[driver])
        .forEach(el => { el.style.display = 'inline'; });
    }
  }
  showDriver(document.getElementById('sms_driver').value);
</script>
<?php page_foot(); ?>
