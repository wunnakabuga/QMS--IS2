<?php
/**
 * FILE: includes/helpers.php
 * WHAT IT DOES: Small shared helpers used everywhere: output escaping (XSS
 * protection), redirects, JSON responses, one-time flash messages, Kenyan
 * phone number cleaning/validation, time formatting and opening-hours check.
 *
 * FIND: helpers, XSS protection, escape output, phone validation, flash message
 */
require_once __DIR__ . '/db.php';

/**
 * XSS PROTECTION. Escapes text before it is printed into HTML, so anything a
 * user typed (names, notes) is shown as text and can never run as script.
 * Used as e($value) in every page template.
 * FIND: XSS protection, cross-site scripting, escape output, htmlspecialchars
 */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Send the browser to another page and stop. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Send a JSON response (used by the api/ endpoints) and stop. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * One-time "flash" message. Call with a message to store it; call with no
 * arguments to read it once (it is then deleted). Shown at the top of the
 * next page, e.g. "Password changed".
 * FIND: flash message, success message, error message
 */
function flash(?string $msg = null, string $type = 'info')
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Clean up a Kenyan phone number into international form, e.g.
 * 0712345678 -> +254712345678, so the same person always matches.
 * FIND: phone number format, normalise phone
 */
function normalise_phone(string $raw): string
{
    $digits = preg_replace('/[^0-9+]/', '', $raw);
    if (str_starts_with($digits, '+')) {
        return $digits;
    }
    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        return '+254' . substr($digits, 1);
    }
    if (str_starts_with($digits, '254')) {
        return '+' . $digits;
    }
    return $digits;
}

/**
 * Validate a phone number: optional +, then 9-15 digits.
 * FIND: phone validation, input validation
 */
function valid_phone(string $phone): bool
{
    return (bool)preg_match('/^\+?[0-9]{9,15}$/', $phone);
}

/** Turn minutes into friendly text, e.g. 75 -> "1 hr 15 min". */
function fmt_minutes($minutes): string
{
    if ($minutes === null) {
        return '—';
    }
    $m = (int)round((float)$minutes);
    if ($m < 1) {
        return 'less than a minute';
    }
    if ($m < 60) {
        return $m . ' min';
    }
    return intdiv($m, 60) . ' hr ' . ($m % 60) . ' min';
}

/** Turn a status code into a label, e.g. "in_service" -> "In Service". */
function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

/**
 * Is the centre open right now? Monday-Friday, between OPENING_HOUR and
 * CLOSING_HOUR from config.php.
 * FIND: opening hours, centre open
 */
function centre_is_open(): bool
{
    $h = (int)date('G');
    $dow = (int)date('N');
    return $dow <= 5 && $h >= OPENING_HOUR && $h < CLOSING_HOUR;
}
