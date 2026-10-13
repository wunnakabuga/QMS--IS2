<?php
/** Service counter configuration — Wireframe 9. Implements FR-17. */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/prediction.php';

$user = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $action    = $_POST['action'] ?? '';
    $counterId = (int)($_POST['counter_id'] ?? 0);

    if ($action === 'save') {
        $name    = trim($_POST['counter_name'] ?? '');
        $service = $_POST['service_type'] ?? '';
        $staff   = $_POST['assigned_staff_id'] ?? '';
        $status  = ($_POST['status'] ?? 'closed') === 'open' ? 'open' : 'closed';
        $staffId = $staff !== '' ? (int)$staff : null;

        if ($name === '' || !in_array($service, $SERVICE_TYPES, true)) {
            flash('Enter a counter name and choose a service type.', 'error');
        } else {
            if ($counterId > 0) {
                q('UPDATE service_counter
                      SET counter_name = :n, service_type = :st,
                          status = :s, assigned_staff_id = :a
                    WHERE counter_id = :c',
                  [':n' => $name, ':st' => $service, ':s' => $status,
                   ':a' => $staffId, ':c' => $counterId]);
                flash('Counter updated.', 'success');
            } else {
                q('INSERT INTO service_counter
                      (counter_name, service_type, status, assigned_staff_id)
                   VALUES (:n, :st, :s, :a)',
                  [':n' => $name, ':st' => $service, ':s' => $status, ':a' => $staffId]);
                flash('Counter created.', 'success');
            }
            recalculate_predictions($service);
        }
    } elseif ($action === 'toggle' && $counterId > 0) {
        $counter = q_one('SELECT * FROM service_counter WHERE counter_id = :c',
                         [':c' => $counterId]);
        if ($counter) {
            $newStatus = $counter['status'] === 'open' ? 'closed' : 'open';
            q('UPDATE service_counter SET status = :s WHERE counter_id = :c',
              [':s' => $newStatus, ':c' => $counterId]);
            recalculate_predictions($counter['service_type']);
            flash($counter['counter_name'] . ' is now ' . $newStatus
                  . '. Waiting times have been recalculated.', 'info');
        }
    }
    redirect('counters.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $editing = q_one('SELECT * FROM service_counter WHERE counter_id = :c',
                     [':c' => (int)$_GET['edit']]);
}

$counters = q_all(
    'SELECT sc.*, u.full_name AS staff_name,
            (SELECT COUNT(*) FROM queue_entry qe
              WHERE qe.counter_id = sc.counter_id
                AND qe.status IN ("waiting","checked_in","called","in_service")) AS load_count
       FROM service_counter sc
  LEFT JOIN user u ON u.user_id = sc.assigned_staff_id
   ORDER BY sc.counter_id');

$staffList = q_all('SELECT user_id, full_name FROM user WHERE role = "staff" ORDER BY full_name');
$openCount = 0;
foreach ($counters as $c) {
    if ($c['status'] === 'open') {
        $openCount++;
    }
}

page_head('Service counters', 'counters', [
    'heading'    => 'Service counters',
    'subheading' => 'Configure counters, service types and staff assignment',
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a class="on" href="counters.php">Service counters</a>
    <a href="reports.php">Reports &amp; analytics</a>
    <a href="model.php">Prediction model</a>
    <a href="notifications.php">Notifications log</a>
    <a href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <div class="page-title" style="font-size:20px;">Service counters</div>
        <div class="tiny muted"><?= count($counters) ?> configured &middot;
          <?= $openCount ?> currently open</div>
      </div>
      <a class="btn sm" href="counters.php#form">+ Add counter</a>
    </div>

    <div class="panel">
      <table>
        <thead><tr>
          <th>ID</th><th>Counter name</th><th>Service type</th><th>Assigned staff</th>
          <th>Queue</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($counters as $c): ?>
          <tr>
            <td><?= (int)$c['counter_id'] ?></td>
            <td><b><?= e($c['counter_name']) ?></b></td>
            <td><?= e($c['service_type']) ?></td>
            <td><?= e($c['staff_name'] ?? '—') ?></td>
            <td><?= (int)$c['load_count'] ?></td>
            <td><?= badge($c['status']) ?></td>
            <td>
              <div style="display:flex; gap:6px;">
                <a class="btn ghost sm" href="counters.php?edit=<?= (int)$c['counter_id'] ?>#form">Edit</a>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="counter_id" value="<?= (int)$c['counter_id'] ?>">
                  <button class="btn ghost sm" name="action" value="toggle">
                    <?= $c['status'] === 'open' ? 'Close' : 'Open' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel" id="form">
      <h2><?= $editing ? 'Edit counter' : 'Add counter' ?></h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="counter_id" value="<?= $editing ? (int)$editing['counter_id'] : 0 ?>">
        <div class="grid g2">
          <div>
            <label for="counter_name">Counter name</label>
            <input type="text" id="counter_name" name="counter_name" required
                   value="<?= e($editing['counter_name'] ?? '') ?>"
                   placeholder="e.g. Counter 5">
          </div>
          <div>
            <label for="service_type">Service type</label>
            <select id="service_type" name="service_type" required>
              <option value="">Select a service</option>
              <?php foreach ($SERVICE_TYPES as $svc): ?>
                <option value="<?= e($svc) ?>"
                  <?= ($editing['service_type'] ?? '') === $svc ? 'selected' : '' ?>>
                  <?= e($svc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="assigned_staff_id">Assigned staff</label>
            <select id="assigned_staff_id" name="assigned_staff_id">
              <option value="">Unassigned</option>
              <?php foreach ($staffList as $s): ?>
                <option value="<?= (int)$s['user_id'] ?>"
                  <?= (int)($editing['assigned_staff_id'] ?? 0) === (int)$s['user_id'] ? 'selected' : '' ?>>
                  <?= e($s['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="status">Status</label>
            <select id="status" name="status">
              <option value="open"   <?= ($editing['status'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option>
              <option value="closed" <?= ($editing['status'] ?? 'closed') === 'closed' ? 'selected' : '' ?>>Closed</option>
            </select>
          </div>
        </div>
        <div class="btn-row">
          <button class="btn" name="action" value="save">Save counter</button>
          <?php if ($editing): ?>
            <a class="btn ghost" href="counters.php">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
      <p class="tiny muted mt">
        The number of open counters is one of the five features supplied to the
        prediction module, so opening or closing a counter recalculates every
        outstanding waiting time for that service type.
      </p>
    </div>
  </div>
</div>
<?php page_foot(); ?>
