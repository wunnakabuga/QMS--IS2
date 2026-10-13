<?php
/**
 * Notifications log (administrator).
 *
 * Surfaces the NOTIFICATION table so that dispatch can be audited: which
 * channel carried each ticket and reminder, and what delivery status the
 * gateway returned. Supports FR-09 and the dual-channel accessibility claim.
 */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../modules/sms_gateway.php';

$user = require_role('admin');

$channel = in_array($_GET['channel'] ?? '', ['e-ticket', 'sms'], true)
    ? $_GET['channel'] : '';
$status = trim((string)($_GET['status'] ?? ''));

$where = ' WHERE 1=1 ';
$params = [];
if ($channel !== '') {
    $where .= ' AND n.channel = :ch ';
    $params[':ch'] = $channel;
}
if ($status !== '') {
    $where .= ' AND n.delivery_status = :st ';
    $params[':st'] = $status;
}

$total = (int)q_val('SELECT COUNT(*) FROM notification n' . $where, $params, 0);
$pg = paginate($total, 30);

$rows = q_all(
    'SELECT n.*, qe.queue_number, qe.service_type, u.full_name, u.phone_number
       FROM notification n
       JOIN queue_entry qe ON qe.queue_entry_id = n.queue_entry_id
       JOIN user u ON u.user_id = qe.user_id' . $where . '
      ORDER BY n.sent_time DESC, n.notification_id DESC
      LIMIT ' . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset'],
    $params
);

$byChannel = q_all(
    'SELECT channel, COUNT(*) AS n FROM notification GROUP BY channel');
$byStatus = q_all(
    'SELECT delivery_status, COUNT(*) AS n FROM notification
      GROUP BY delivery_status ORDER BY n DESC');

$counts = ['e-ticket' => 0, 'sms' => 0];
foreach ($byChannel as $c) {
    $counts[$c['channel']] = (int)$c['n'];
}
$grand = array_sum($counts);

page_head('Notifications log', 'notifications', [
    'heading'    => 'Notifications log',
    'subheading' => 'Ticket and reminder dispatch audit',
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a href="counters.php">Service counters</a>
    <a href="reports.php">Reports &amp; analytics</a>
    <a href="model.php">Prediction model</a>
    <a class="on" href="notifications.php">Notifications log</a>
    <a href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <div class="grid g4" style="margin-bottom:18px;">
      <div class="stat"><div class="k">Total dispatched</div>
        <div class="v"><?= number_format($grand) ?></div></div>
      <div class="stat"><div class="k">Browser e-tickets</div>
        <div class="v"><?= number_format($counts['e-ticket']) ?></div>
        <div class="d"><?= $grand > 0
              ? round($counts['e-ticket'] / $grand * 100) . '% of all tickets' : '' ?></div></div>
      <div class="stat"><div class="k">SMS tickets</div>
        <div class="v"><?= number_format($counts['sms']) ?></div>
        <div class="d"><?= $grand > 0
              ? round($counts['sms'] / $grand * 100) . '% of all tickets' : '' ?></div></div>
      <div class="stat"><div class="k">Gateway mode</div>
        <div class="v" style="font-size:20px; padding-top:6px;">
          <span class="badge <?= sms_is_live() ? 'open' : 'waiting' ?>">
            <?= e(sms_driver_label()) ?></span></div>
        <div class="d"><?= sms_is_live()
              ? 'messages are being transmitted'
              : 'messages are logged, not transmitted' ?></div></div>
    </div>

    <?php if (!sms_is_live()): ?>
      <div class="alert info">
        The SMS gateway is in simulation mode. Messages are composed and
        recorded here with a delivery status of <b>simulated</b>, but nothing is
        transmitted. Choose a provider and enter credentials on the
        <a href="gateway.php">SMS gateway</a> page to send real messages.
      </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Filter</h2>
      <form method="get" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
        <div style="min-width:180px;">
          <label for="channel">Channel</label>
          <select id="channel" name="channel" style="margin-bottom:0;">
            <option value="">All channels</option>
            <option value="e-ticket" <?= $channel === 'e-ticket' ? 'selected' : '' ?>>E-ticket</option>
            <option value="sms" <?= $channel === 'sms' ? 'selected' : '' ?>>SMS</option>
          </select>
        </div>
        <div style="min-width:180px;">
          <label for="status">Delivery status</label>
          <select id="status" name="status" style="margin-bottom:0;">
            <option value="">All statuses</option>
            <?php foreach ($byStatus as $s): ?>
              <option value="<?= e($s['delivery_status']) ?>"
                <?= $status === $s['delivery_status'] ? 'selected' : '' ?>>
                <?= e($s['delivery_status']) ?> (<?= (int)$s['n'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn ghost" href="notifications.php">Reset</a>
      </form>
    </div>

    <div class="panel">
      <h2>Dispatch records</h2>
      <?php if (!$rows): ?>
        <div class="empty">No notifications match the current filter.</div>
      <?php else: ?>
        <table>
          <thead><tr>
            <th>Sent</th><th>Ticket</th><th>Recipient</th><th>Channel</th>
            <th>Status</th><th>Detail</th><th>Message</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $isReminder = str_starts_with((string)$r['message_content'], 'REMINDER'); ?>
            <tr>
              <td class="tiny"><?= e(date('d/m/Y H:i', strtotime($r['sent_time']))) ?></td>
              <td><b><?= e($r['queue_number']) ?></b><br>
                <span class="tiny muted"><?= e($r['service_type']) ?></span></td>
              <td class="tiny"><?= e($r['full_name']) ?><br>
                <span class="muted"><?= e($r['phone_number']) ?></span></td>
              <td>
                <span class="badge <?= $r['channel'] === 'sms' ? 'called' : 'checked_in' ?>">
                  <?= e(strtoupper($r['channel'])) ?></span>
                <?php if ($isReminder): ?>
                  <br><span class="tiny muted">reminder</span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= in_array($r['delivery_status'], ['sent','delivered'], true)
                    ? 'completed' : ($r['delivery_status'] === 'failed' ? 'expired' : 'waiting') ?>">
                  <?= e($r['delivery_status']) ?></span>
                  <?php if ((int)($r['attempts'] ?? 1) > 1): ?>
                    <br><span class="tiny muted"><?= (int)$r['attempts'] ?> attempts</span>
                  <?php endif; ?></td>
              <td class="tiny muted"><?= e($r['provider_detail'] ?? '—') ?></td>
              <td><details><summary class="tiny">View</summary>
                  <div class="sms-preview" style="margin-top:8px;"><?= e((string)$r['message_content']) ?></div>
                  </details></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?= pager($pg) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php page_foot(); ?>
