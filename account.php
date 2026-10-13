<?php
/**
 * Change my password.
 *
 * Available to any authenticated staff member or administrator, so that
 * seeded accounts are not stuck with the password they were created with.
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$done = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $record = q_one('SELECT password_hash FROM user WHERE user_id = :id',
                    [':id' => $user['user_id']]);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$record || !$record['password_hash']
              || !password_verify($current, $record['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif (strlen($new) < 8) {
        $error = 'The new password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        $error = 'The new password must contain both letters and numbers.';
    } elseif ($new !== $confirm) {
        $error = 'The two new passwords do not match.';
    } elseif (password_verify($new, $record['password_hash'])) {
        $error = 'The new password must be different from the current one.';
    } else {
        q('UPDATE user SET password_hash = :h WHERE user_id = :id',
          [':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['user_id']]);
        $done = true;
    }
}

page_head('My account', 'account', ['narrow' => true]);
?>
<div style="max-width:480px; margin:20px auto;">
  <div class="page-title">My account</div>
  <div class="page-sub">
    <?= e($user['full_name']) ?> &middot; <?= e($user['email']) ?>
    &middot; <?= e(ucfirst($user['role'])) ?>
  </div>

  <?php if ($done): ?>
    <div class="alert success">
      Your password has been changed. It takes effect the next time you sign in.
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="panel">
    <h2>Change password</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label for="current_password">Current password</label>
      <input type="password" id="current_password" name="current_password" required
             autocomplete="current-password">
      <label for="new_password">New password
        <span class="hint">at least 8 characters, letters and numbers</span></label>
      <input type="password" id="new_password" name="new_password" required
             autocomplete="new-password">
      <label for="confirm_password">Confirm new password</label>
      <input type="password" id="confirm_password" name="confirm_password" required
             autocomplete="new-password">
      <button class="btn block" type="submit">Change password</button>
    </form>
    <p class="tiny muted mt">
      Passwords are stored as salted hashes. Nobody, including an
      administrator, can read your password &mdash; an administrator can only
      reset it to a new one.
    </p>
  </div>

  <div class="btn-row">
    <a class="btn ghost" href="<?= $user['role'] === 'admin'
          ? 'admin/dashboard.php' : 'staff/counter.php' ?>">Back</a>
  </div>
</div>
<?php page_foot(); ?>
