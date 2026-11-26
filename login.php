<?php
/**
 * FILE: login.php
 * WHAT IT DOES: Staff and administrator sign-in page (Wireframe 6, FR-20).
 * Checks the CSRF token, then calls auth_attempt() in includes/auth.php,
 * which verifies the password against its bcrypt hash. Admins go to the
 * dashboard, staff to their counter. Has a "Forgot your password?" link to
 * forgot_password.php.
 *
 * FIND: login page, sign in, staff login
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';

$existing = auth_user();
if ($existing) {
    redirect($existing['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/counter.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please sign in again.';
    } else {
        // Password is checked with password_verify() inside auth_attempt().
        // FIND: login check
        $user = auth_attempt($email, $password);
        if ($user) {
            redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/counter.php');
        }
        $error = 'Those credentials were not recognised.';
    }
}

page_head('Staff sign in', 'login', ['narrow' => true]);
?>
<div style="max-width:420px; margin:28px auto;">
  <div class="panel">
    <div style="text-align:center; margin-bottom:22px;">
      <div class="brand-mark" style="margin:0 auto 12px; width:52px; height:52px; font-size:19px;">QMS</div>
      <div style="font-weight:700; font-size:18px;">Staff sign in</div>
      <div class="tiny muted">Queue Management System</div>
    </div>

    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required autofocus
             value="<?= e($email) ?>" placeholder="you@hudumacentre.go.ke">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required placeholder="••••••••">
      <button class="btn block" type="submit">Sign in</button>
    </form>

    <!-- FIND: forgot password link -->
    <p class="tiny mt" style="text-align:center;">
      <a href="forgot_password.php">Forgot your password?</a>
    </p>

    <p class="tiny muted mt" style="text-align:center;">
      Your role determines the module loaded: counter staff open the counter
      console, administrators the dashboard.
    </p>
  </div>
</div>
<?php page_foot(); ?>
