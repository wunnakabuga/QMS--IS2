<?php
/**
 * FILE: login.php
 * WHAT IT DOES: Staff and administrator sign-in page (Wireframe 6, FR-20).
 * Step 1: email + password, checked against the bcrypt hash by
 * auth_verify_credentials() in includes/auth.php. Step 2 (when
 * TWOFA_ENABLED): a one-time 6-digit code sent by SMS. Alternatively
 * "Sign in with Google" (auth/google_start.php) when Google is configured.
 * Admins go to the dashboard, staff to their counter. Has a "Forgot your
 * password?" link to forgot_password.php.
 *
 * FIND: login page, sign in, staff login, two-step verification, Google sign in
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/modules/sms_gateway.php';

$existing = auth_user();
if ($existing) {
    redirect($existing['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/counter.php');
}

$twofa   = TWOFA_ENABLED;
$error   = null;
$notice  = null;
$email   = '';
$stage   = 'password';
$pending = $twofa ? auth_2fa_pending() : null;
if ($pending) {
    $stage = 'code';
    $email = $pending['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please sign in again.';
        $stage = 'password';
        auth_2fa_clear();
        $pending = null;
    } else {
        $action = $_POST['action'] ?? 'password';

        if ($action === 'cancel') {
            auth_2fa_clear();
            unset($_SESSION['otp_sim']);
            $pending = null;
            $stage = 'password';
        } elseif ($action === 'verify' && $twofa) {
            $result = auth_complete_2fa($_POST['code'] ?? '');
            if (!empty($result['ok'])) {
                unset($_SESSION['otp_sim']);
                redirect($result['user']['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/counter.php');
            }
            $error   = $result['error'];
            $pending = auth_2fa_pending();
            if ($pending) { $stage = 'code'; $email = $pending['email']; }
            else { $stage = 'password'; }
        } else {
            // Password stage.
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            if (!$twofa) {
                $user = auth_attempt($email, $password);
                if ($user) {
                    redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/counter.php');
                }
                $error = 'Those credentials were not recognised.';
            } else {
                $begin = auth_begin_2fa($email, $password);
                if ($begin) {
                    $msg = 'Huduma QMS verification code: ' . $begin['code']
                         . '. Valid for 5 minutes. Do not share it.';
                    $sent = sms_send($begin['phone'], $msg);
                    $pending = auth_2fa_pending();
                    $stage = 'code';
                    if (!sms_is_live()) {
                        // Simulation mode: surface the code so the flow is
                        // demonstrable without a live SMS provider.
                        $_SESSION['otp_sim'] = $begin['code'];
                    } elseif (($sent['status'] ?? 'failed') === 'failed') {
                        $notice = 'We could not send the code by SMS just now. '
                                . 'Use “Start over” to try again.';
                    }
                } else {
                    $error = 'Those credentials were not recognised.';
                }
            }
        }
    }
}

if (!$error && isset($_GET['google'])) {
    $g = $_GET['google'];
    if ($g === 'unknown') {
        $error = 'That Google account is not registered as staff. '
               . 'Use your email and password, or ask an administrator to add it.';
    } elseif ($g === 'failed') {
        $error = 'Google sign-in did not complete. Please try again.';
    } elseif ($g === 'unavailable') {
        $notice = 'Google sign-in is not configured on this server.';
    }
}

$simCode = $_SESSION['otp_sim'] ?? null;

page_head('Staff sign in', 'login', ['narrow' => true]);
?>
<div style="max-width:420px; margin:28px auto;">
  <div class="panel">
    <div style="text-align:center; margin-bottom:22px;">
      <div class="brand-mark" style="margin:0 auto 12px; width:52px; height:52px; font-size:19px;">QMS</div>
      <div style="font-weight:700; font-size:18px;"><?= $stage === 'code' ? 'Verify it\'s you' : 'Staff sign in' ?></div>
      <div class="tiny muted">Queue Management System</div>
    </div>

    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert"><?= e($notice) ?></div><?php endif; ?>

    <?php if ($stage === 'code' && $pending): ?>
      <div class="alert success" role="status">
        We sent a 6-digit code to <strong><?= e($pending['masked_phone']) ?></strong>.
        Enter it below to finish signing in.
      </div>
      <?php if ($simCode): ?>
        <div class="alert" style="text-align:center;">
          <span class="tiny muted">Simulation mode — your code is</span><br>
          <span style="font-size:24px; font-weight:700; letter-spacing:4px;"><?= e($simCode) ?></span>
        </div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify">
        <label for="code">6-digit code</label>
        <input type="text" id="code" name="code" required autofocus inputmode="numeric"
               pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
               placeholder="••••••" style="text-align:center; letter-spacing:6px; font-size:20px;">
        <button class="btn block" type="submit">Verify and sign in</button>
      </form>
      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="cancel">
        <button class="btn ghost block" type="submit">Start over</button>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autofocus
               value="<?= e($email) ?>" placeholder="you@hudumacentre.go.ke">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required placeholder="••••••••">
        <button class="btn block" type="submit"><?= $twofa ? 'Continue' : 'Sign in' ?></button>
      </form>
      <!-- FIND: forgot password link -->
      <p class="tiny mt" style="text-align:center;">
        <a href="forgot_password.php">Forgot your password?</a>
      </p>
      <p class="tiny muted mt" style="text-align:center;">
        <?php if ($twofa): ?>
          For security, we'll send a one-time code to your registered phone before signing you in.
        <?php else: ?>
          Your role determines the module loaded: counter staff open the counter
          console, administrators the dashboard.
        <?php endif; ?>
      </p>
      <?php if (GOOGLE_OAUTH_ENABLED): ?>
        <div style="text-align:center; margin:16px 0 10px; color:#9a938e; font-size:13px;">or</div>
        <a class="btn ghost block" href="auth/google_start.php" style="text-align:center;">Sign in with Google</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); ?>
