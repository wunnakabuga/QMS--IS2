<?php
/**
 * User account management (administrator).
 *
 * Closes the gap where staff accounts could only be created by editing
 * database/schema.sql. An administrator can now create, edit, deactivate
 * and reset the password of staff and administrator accounts.
 */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/pagination.php';

$user = require_role('admin');

/** A password acceptable for a staff account (NFR-07). */
function password_problem(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        return 'Password must contain both letters and numbers.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'save') {
        $name  = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = normalise_phone(trim($_POST['phone_number'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', ['staff', 'admin'], true)
                   ? $_POST['role'] : 'staff';
        $pw    = (string)($_POST['password'] ?? '');

        $problem = null;
        if ($name === '' || mb_strlen($name) < 3) {
            $problem = 'Enter the full name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $problem = 'Enter a valid email address.';
        } elseif (!valid_phone($phone)) {
            $problem = 'Enter a valid phone number.';
        } elseif ($targetId === 0 && $pw === '') {
            $problem = 'Set a password for the new account.';
        } elseif ($pw !== '' && ($msg = password_problem($pw)) !== null) {
            $problem = $msg;
        } else {
            $clash = q_one('SELECT user_id FROM user WHERE email = :e AND user_id <> :id',
                           [':e' => $email, ':id' => $targetId]);
            if ($clash) {
                $problem = 'Another account already uses that email address.';
            }
        }

        if ($problem !== null) {
            flash($problem, 'error');
        } elseif ($targetId > 0) {
            // Do not let an administrator demote their own account and lock
            // everyone out of the administrative module.
            if ($targetId === $user['user_id'] && $role !== 'admin') {
                flash('You cannot remove your own administrator role.', 'error');
            } else {
                if ($pw !== '') {
                    q('UPDATE user SET full_name = :n, email = :e, phone_number = :p,
                              role = :r, password_hash = :h WHERE user_id = :id',
                      [':n' => $name, ':e' => $email, ':p' => $phone, ':r' => $role,
                       ':h' => password_hash($pw, PASSWORD_DEFAULT), ':id' => $targetId]);
                    flash('Account updated and password reset.', 'success');
                } else {
                    q('UPDATE user SET full_name = :n, email = :e, phone_number = :p,
                              role = :r WHERE user_id = :id',
                      [':n' => $name, ':e' => $email, ':p' => $phone, ':r' => $role,
                       ':id' => $targetId]);
                    flash('Account updated.', 'success');
                }
            }
        } else {
            q('INSERT INTO user (full_name, phone_number, email, password_hash, role)
               VALUES (:n, :p, :e, :h, :r)',
              [':n' => $name, ':p' => $phone, ':e' => $email,
               ':h' => password_hash($pw, PASSWORD_DEFAULT), ':r' => $role]);
            flash('Account created. The user can now sign in.', 'success');
        }
    } elseif ($action === 'delete' && $targetId > 0) {
        if ($targetId === $user['user_id']) {
            flash('You cannot delete your own account.', 'error');
        } else {
            $admins = (int)q_val('SELECT COUNT(*) FROM user WHERE role = "admin"', [], 0);
            $target = q_one('SELECT role, full_name FROM user WHERE user_id = :id',
                            [':id' => $targetId]);
            if ($target && $target['role'] === 'admin' && $admins <= 1) {
                flash('Cannot delete the last administrator account.', 'error');
            } elseif ($target) {
                // Counters keep working; the assignment is simply cleared by
                // the ON DELETE SET NULL constraint.
                q('DELETE FROM user WHERE user_id = :id', [':id' => $targetId]);
                flash($target['full_name'] . ' has been removed. Any counter they '
                      . 'were assigned to is now unassigned.', 'info');
            }
        }
    }
    redirect('users.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $editing = q_one('SELECT * FROM user WHERE user_id = :id AND role IN ("staff","admin")',
                     [':id' => (int)$_GET['edit']]);
}

$total = (int)q_val('SELECT COUNT(*) FROM user WHERE role IN ("staff","admin")', [], 0);
$pg = paginate($total, 25);
$accounts = q_all(
    'SELECT u.*,
            (SELECT GROUP_CONCAT(sc.counter_name SEPARATOR ", ")
               FROM service_counter sc WHERE sc.assigned_staff_id = u.user_id) AS counters
       FROM user u
      WHERE u.role IN ("staff","admin")
      ORDER BY u.role DESC, u.full_name
      LIMIT ' . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']
);

$clientCount = (int)q_val('SELECT COUNT(*) FROM user WHERE role = "client"', [], 0);

page_head('User accounts', 'users', [
    'heading'    => 'User accounts',
    'subheading' => 'Staff and administrator accounts',
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
    <a href="gateway.php">SMS gateway</a>
    <a class="on" href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <div class="page-title" style="font-size:20px;">Staff and administrators</div>
        <div class="tiny muted"><?= $total ?> account<?= $total === 1 ? '' : 's' ?>
          &middot; <?= number_format($clientCount) ?> client records (created automatically
          when clients join the queue)</div>
      </div>
      <a class="btn sm" href="users.php#form">+ Add account</a>
    </div>

    <div class="panel">
      <table>
        <thead><tr>
          <th>Name</th><th>Email</th><th>Phone</th><th>Role</th>
          <th>Counter</th><th>Created</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td><b><?= e($a['full_name']) ?></b>
              <?= (int)$a['user_id'] === $user['user_id']
                    ? ' <span class="tiny muted">(you)</span>' : '' ?></td>
            <td class="tiny"><?= e($a['email'] ?? '—') ?></td>
            <td class="tiny"><?= e($a['phone_number']) ?></td>
            <td><span class="badge <?= $a['role'] === 'admin' ? 'in_service' : 'checked_in' ?>">
                <?= e(strtoupper($a['role'])) ?></span></td>
            <td class="tiny"><?= e($a['counters'] ?? '—') ?></td>
            <td class="tiny"><?= e(date('d/m/Y', strtotime($a['created_at']))) ?></td>
            <td>
              <div style="display:flex; gap:6px;">
                <a class="btn ghost sm" href="users.php?edit=<?= (int)$a['user_id'] ?>#form">Edit</a>
                <?php if ((int)$a['user_id'] !== $user['user_id']): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                    <button class="btn danger sm" name="action" value="delete"
                            onclick="return confirm('Remove <?= e(addslashes($a['full_name'])) ?>?');">
                      Remove</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?= pager($pg) ?>
    </div>

    <div class="panel" id="form">
      <h2><?= $editing ? 'Edit account' : 'Add account' ?></h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $editing ? (int)$editing['user_id'] : 0 ?>">
        <div class="grid g2">
          <div>
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required
                   value="<?= e($editing['full_name'] ?? '') ?>" placeholder="e.g. Joan Wanjiru">
          </div>
          <div>
            <label for="email">Email address <span class="hint">used to sign in</span></label>
            <input type="email" id="email" name="email" required
                   value="<?= e($editing['email'] ?? '') ?>"
                   placeholder="name@hudumacentre.go.ke">
          </div>
          <div>
            <label for="phone_number">Phone number</label>
            <input type="tel" id="phone_number" name="phone_number" required
                   value="<?= e($editing['phone_number'] ?? '') ?>" placeholder="07XX XXX XXX">
          </div>
          <div>
            <label for="role">Role</label>
            <select id="role" name="role">
              <option value="staff" <?= ($editing['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>
                Service counter staff</option>
              <option value="admin" <?= ($editing['role'] ?? '') === 'admin' ? 'selected' : '' ?>>
                Administrator</option>
            </select>
          </div>
        </div>
        <label for="password">
          <?= $editing ? 'New password' : 'Password' ?>
          <span class="hint">
            <?= $editing
                  ? 'leave blank to keep the current password'
                  : 'at least 8 characters, letters and numbers' ?></span>
        </label>
        <input type="password" id="password" name="password"
               <?= $editing ? '' : 'required' ?> autocomplete="new-password"
               placeholder="<?= $editing ? 'Unchanged' : 'At least 8 characters' ?>">

        <div class="btn-row">
          <button class="btn" name="action" value="save">Save account</button>
          <?php if ($editing): ?><a class="btn ghost" href="users.php">Cancel</a><?php endif; ?>
        </div>
      </form>
      <p class="tiny muted mt">
        Passwords are stored as salted hashes, never in plain text (NFR-07).
        Removing an account does not delete the service history recorded against
        it; the counter assignment is simply cleared.
      </p>
    </div>
  </div>
</div>
<?php page_foot(); ?>
