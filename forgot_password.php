<?php
/**
 * FILE: forgot_password.php
 * WHAT IT DOES: "Forgot password?" page, linked from the staff sign-in page.
 * The staff member enters their email; if it matches a staff/admin account
 * a one-time reset link is emailed (see modules/password_reset.php). The
 * same confirmation is shown either way, so the page never reveals which
 * email addresses have accounts. Requests are rate limited.
 *
 * FIND: forgot password page, request reset link
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ratelimit.php';
require_once __DIR__ . '/modules/password_reset.php';

$error = null;
$sent  = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    // Rate limit: 5 requests per device per 15 minutes, 3 per email per hour.
    // FIND: rate limiting
    $ipLimit    = rate_limit_hit('reset:ip:' . client_ip(), 5, 900);
    $emailLimit = $email !== ''
        ? rate_limit_hit('reset:email:' . strtolower($email), 3, 3600)
        : ['allowed' => true, 'retry_after' => 0];

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$ipLimit['allowed'] || !$emailLimit['allowed']) {
        $error = 'Too many reset requests. Please wait '
               . retry_phrase(max($ipLimit['retry_after'], $emailLimit['retry_after']))
               . ' and try again.';
    } else {
        request_password_reset($email);
        $sent = true;
    }
}

page_head('Forgot password', 'login', ['narrow' => true]);
?>
<div style="max-width:420px; margin:28px auto;">
  <div class="panel">
    <div style="text-align:center; margin-bottom:22px;">
      <div class="brand-mark" style="margin:0 auto 12px; width:52px; height:52px; font-size:19px;">QMS</div>
      <h1 style="font-weight:700; font-size:18px;">Reset your password</h1>
      <div class="tiny muted">Staff and administrator accounts</div>
    </div>

    <?php if ($sent): ?>
      <div class="alert success" role="status">
        If <b><?= e($email) ?></b> belongs to a staff account, a reset link is on
        its way. It expires in <?= RESET_TTL_MINUTES ?> minutes. Check your spam
        folder if it does not arrive.
      </div>
      <a class="btn block ghost" href="login.php">Back to sign in</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <p class="tiny muted" style="margin-bottom:16px;">
        Enter the email address you sign in with and we will email you a link
        to choose a new password.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autofocus
               autocomplete="email" value="<?= e($email) ?>">
        <button class="btn block" type="submit">Send reset link</button>
      </form>
      <p class="tiny mt" style="text-align:center;"><a href="login.php">Back to sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); ?>
