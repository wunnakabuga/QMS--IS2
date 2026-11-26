<?php
/**
 * FILE: reset_password.php
 * WHAT IT DOES: The page the emailed reset link opens. Checks the link's
 * token is valid, unused and not expired, then lets the staff member choose
 * a new password (8+ characters, letters and numbers), which is saved as a
 * bcrypt hash. The link stops working once used.
 * (Not to be confused with tasks/reset_password.php, the command-line tool.)
 *
 * FIND: reset password page, new password, reset link
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/modules/password_reset.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$reset = find_valid_reset($token);
$error = null;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $error = complete_password_reset(
            $token,
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? '')
        );
        if ($error === null) {
            flash('Your password has been changed. Please sign in with the new password.', 'success');
            redirect('login.php');
        }
    }
}

page_head('Choose a new password', 'login', ['narrow' => true]);
?>
<div style="max-width:420px; margin:28px auto;">
  <div class="panel">
    <div style="text-align:center; margin-bottom:22px;">
      <div class="brand-mark" style="margin:0 auto 12px; width:52px; height:52px; font-size:19px;">QMS</div>
      <h1 style="font-weight:700; font-size:18px;">Choose a new password</h1>
    </div>

    <?php if (!$reset): ?>
      <div class="alert error" role="alert">
        This reset link is invalid, has already been used, or has expired.
      </div>
      <a class="btn block" href="forgot_password.php">Request a new link</a>
    <?php else: ?>
      <p class="tiny muted" style="margin-bottom:16px;">
        Setting a new password for <b><?= e($reset['email']) ?></b>.
      </p>
      <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label for="new_password">New password
          <span class="hint">at least 8 characters, letters and numbers</span></label>
        <input type="password" id="new_password" name="new_password" required
               minlength="8" autocomplete="new-password" autofocus>
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required
               minlength="8" autocomplete="new-password">
        <button class="btn block" type="submit">Save new password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); ?>
