<?php
/**
 * FILE: includes/auth.php
 * WHAT IT DOES: Staff/admin login (with optional two-step verification by
 * SMS code), logout, sessions, role-based access control (staff vs admin
 * pages), password rules and CSRF protection for forms.
 * Implements FR-20 and NFR-07.
 *
 * Passwords are never stored in plain text. They are hashed with PHP's
 * password_hash() (bcrypt) when an account is created or a password is
 * changed - see admin/users.php, account.php and modules/password_reset.php
 * - and checked here with password_verify() at login.
 *
 * FIND: login, authentication, password verification, session, logout,
 *       two-step verification, 2FA, OTP, role based access control, CSRF token
 */
require_once __DIR__ . '/helpers.php';

// Two-step verification defaults, used when config.php does not set them.
if (!defined('TWOFA_ENABLED')) {
    define('TWOFA_ENABLED', (getenv('QMS_2FA') ?: 'off') !== 'off');
}
if (!defined('OTP_TTL_SECONDS')) {
    define('OTP_TTL_SECONDS', 300);
}
if (!defined('OTP_MAX_ATTEMPTS')) {
    define('OTP_MAX_ATTEMPTS', 5);
}
// Google sign-in defaults: off unless a client ID and secret are supplied
// (in config.php or the QMS_GOOGLE_* environment variables).
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('QMS_GOOGLE_ID') ?: '');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('QMS_GOOGLE_SECRET') ?: '');
}
if (!defined('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', getenv('QMS_GOOGLE_REDIRECT')
        ?: 'http://localhost/qms/auth/google_callback.php');
}
if (!defined('GOOGLE_OAUTH_ENABLED')) {
    define('GOOGLE_OAUTH_ENABLED', GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '');
}

/**
 * Start the PHP session if one is not already running (skipped on the
 * command line, where tests and scheduled tasks run).
 * FIND: start session
 */
function auth_start(): void
{
    // Sessions are meaningless on the command line, where the test suite and
    // the scheduled maintenance task run. Starting one there only produces
    // "headers already sent" warnings.
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * PASSWORD CHECK. Looks up the staff/admin account by email and compares the
 * typed password against the stored bcrypt hash with password_verify().
 * The plain password is never stored or compared directly. Returns the
 * full staff/admin row (including phone_number) or null. Both the direct
 * login path and the two-step path below build on this.
 * FIND: login, password verification, password_verify, check password
 */
function auth_verify_credentials(string $email, string $password): ?array
{
    $user = q_one(
        'SELECT user_id, full_name, email, phone_number, role, password_hash
           FROM user WHERE email = :email AND role IN ("staff","admin") LIMIT 1',
        [':email' => $email]
    );
    if (!$user || !$user['password_hash']
        || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

/**
 * Log the verified user in: new session id (prevents session fixation) and
 * the user saved in the session.
 * FIND: session fixation, start login session
 */
function auth_finalise(array $user): array
{
    auth_start();
    if (PHP_SAPI !== 'cli') {
        // Guards against session fixation: a fresh session id the moment
        // privileges change.
        session_regenerate_id(true);
    }
    unset($_SESSION['pending_2fa']);
    $_SESSION['user'] = [
        'user_id'   => (int)$user['user_id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];
    return $_SESSION['user'];
}

/** Direct login (no second step) — used when 2FA is turned off, and by tests. */
function auth_attempt(string $email, string $password): ?array
{
    $user = auth_verify_credentials($email, $password);
    return $user ? auth_finalise($user) : null;
}

/** Mask a phone number for display: 0712 345 678 -> 0712 *** 678. */
function auth_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 6) {
        return 'your registered number';
    }
    return substr($digits, 0, 4) . ' *** ' . substr($digits, -3);
}

/**
 * TWO-STEP LOGIN, STEP 1: verify the password, mint a random 6-digit code,
 * keep only its bcrypt hash in the session (valid OTP_TTL_SECONDS), and hand
 * the plain code back to the caller to deliver by SMS. Returns null if the
 * password is wrong.
 * FIND: two-step verification, 2FA, OTP, one-time code
 */
function auth_begin_2fa(string $email, string $password): ?array
{
    $user = auth_verify_credentials($email, $password);
    if (!$user) {
        return null;
    }
    auth_start();
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['pending_2fa'] = [
        'user'      => $user,
        'code_hash' => password_hash($code, PASSWORD_BCRYPT),
        'expires'   => time() + OTP_TTL_SECONDS,
        'attempts'  => 0,
    ];
    return ['user' => $user, 'code' => $code, 'phone' => $user['phone_number']];
}

/** The pending second-step challenge, if one is in progress, else null. */
function auth_2fa_pending(): ?array
{
    auth_start();
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p) {
        return null;
    }
    if (time() > $p['expires']) {
        unset($_SESSION['pending_2fa']);
        return null;
    }
    return [
        'email'        => $p['user']['email'],
        'masked_phone' => auth_mask_phone($p['user']['phone_number']),
        'expires'      => $p['expires'],
    ];
}

/**
 * TWO-STEP LOGIN, STEP 2: check the typed code against the stored hash.
 * Expires after OTP_TTL_SECONDS and is burned after OTP_MAX_ATTEMPTS wrong
 * tries. Returns ['ok'=>bool,'error'=>?string].
 * FIND: verify OTP, check code, 2FA
 */
function auth_complete_2fa(string $code): array
{
    auth_start();
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p) {
        return ['ok' => false, 'error' => 'Your sign-in request has expired. Please start again.'];
    }
    if (time() > $p['expires']) {
        unset($_SESSION['pending_2fa']);
        return ['ok' => false, 'error' => 'That code has expired. Please sign in again.'];
    }
    if ($p['attempts'] >= OTP_MAX_ATTEMPTS) {
        unset($_SESSION['pending_2fa']);
        return ['ok' => false, 'error' => 'Too many attempts. Please sign in again.'];
    }
    if (!password_verify(trim($code), $p['code_hash'])) {
        $_SESSION['pending_2fa']['attempts']++;
        $left = OTP_MAX_ATTEMPTS - $_SESSION['pending_2fa']['attempts'];
        return ['ok' => false, 'error' => 'Incorrect code.' . ($left > 0 ? " $left attempt(s) left." : '')];
    }
    return ['ok' => true, 'user' => auth_finalise($p['user'])];
}

/** Abandon a pending second-step challenge. */
function auth_2fa_clear(): void
{
    auth_start();
    unset($_SESSION['pending_2fa']);
}

/** Return the currently logged-in user from the session, or null. */
function auth_user(): ?array
{
    auth_start();
    return $_SESSION['user'] ?? null;
}

/**
 * Log out: clear the session data and destroy the session.
 * FIND: logout, sign out, destroy session
 */
function auth_logout(): void
{
    auth_start();
    $_SESSION = [];
    session_destroy();
}

/**
 * Page guard: send visitors who are not logged in to the login page.
 * FIND: require login, protect page
 */
function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        redirect('../login.php');
    }
    return $u;
}

/**
 * ROLE-BASED ACCESS CONTROL. Page guard that only lets a user with the
 * given role ("staff" or "admin") through; anyone else gets HTTP 403.
 * FIND: role based access control, authorisation, admin only, 403
 */
function require_role(string $role): array
{
    $u = require_login();
    if ($u['role'] !== $role) {
        http_response_code(403);
        die('Access denied: this page requires the ' . e($role) . ' role.');
    }
    return $u;
}

/**
 * PASSWORD RULES (NFR-07): at least 8 characters, with letters and numbers.
 * Returns an error message, or null if the password is acceptable. Used when
 * an admin creates/resets an account and on the forgot-password reset page.
 * FIND: password rules, password strength, password policy
 */
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

/**
 * CSRF PROTECTION. Creates one random 64-character token per session. Every
 * form includes it as a hidden field so a malicious site cannot submit forms
 * on a logged-in user's behalf.
 * FIND: CSRF token, cross-site request forgery
 */
function csrf_token(): string
{
    auth_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Check a submitted form's CSRF token against the session's token.
 * hash_equals() is used so the comparison takes constant time.
 * FIND: CSRF check, verify token
 */
function csrf_check(?string $token): bool
{
    auth_start();
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}
