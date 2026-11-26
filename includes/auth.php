<?php
/**
 * FILE: includes/auth.php
 * WHAT IT DOES: Staff/admin login, logout, sessions, role-based access
 * control (staff vs admin pages) and CSRF protection for forms.
 * Implements FR-20 and NFR-07.
 *
 * Passwords are never stored in plain text. They are hashed with PHP's
 * password_hash() (bcrypt) when an account is created or a password is
 * changed - see admin/users.php and account.php - and checked here with
 * password_verify() at login.
 *
 * FIND: login, authentication, password verification, session, logout,
 *       role based access control, CSRF token
 */
require_once __DIR__ . '/helpers.php';

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
 * LOGIN CHECK. Looks up the staff/admin account by email and compares the
 * typed password against the stored bcrypt hash with password_verify().
 * The plain password is never stored or compared directly. On success the
 * session id is regenerated (prevents session fixation) and the user is
 * saved in the session. Returns the user, or null if login failed.
 * FIND: login, password verification, password_verify, check password,
 *       session fixation
 */
function auth_attempt(string $email, string $password): ?array
{
    $user = q_one(
        'SELECT user_id, full_name, email, role, password_hash
           FROM user WHERE email = :email AND role IN ("staff","admin") LIMIT 1',
        [':email' => $email]
    );
    if (!$user || !$user['password_hash']
        || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    auth_start();
    if (PHP_SAPI !== 'cli') {
        // Guards against session fixation: the caller gets a fresh session id
        // the moment their privileges change.
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'user_id'   => (int)$user['user_id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];
    return $_SESSION['user'];
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
