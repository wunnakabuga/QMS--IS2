<?php
/**
 * Authentication and role-based access control.
 * Implements FR-20 and NFR-07.
 */
require_once __DIR__ . '/helpers.php';

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

function auth_user(): ?array
{
    auth_start();
    return $_SESSION['user'] ?? null;
}

function auth_logout(): void
{
    auth_start();
    $_SESSION = [];
    session_destroy();
}

/** Require any authenticated staff or admin. */
function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        redirect('../login.php');
    }
    return $u;
}

/** Require a specific role. */
function require_role(string $role): array
{
    $u = require_login();
    if ($u['role'] !== $role) {
        http_response_code(403);
        die('Access denied: this page requires the ' . e($role) . ' role.');
    }
    return $u;
}

/** CSRF token helpers. */
function csrf_token(): string
{
    auth_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    auth_start();
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}
