<?php
/** Shared helper functions. */
require_once __DIR__ . '/db.php';

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

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

/** Normalise a Kenyan phone number to +2547XXXXXXXX where possible. */
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

function valid_phone(string $phone): bool
{
    return (bool)preg_match('/^\+?[0-9]{9,15}$/', $phone);
}

/** Human-readable minutes. */
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

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

/** Is the centre currently open? */
function centre_is_open(): bool
{
    $h = (int)date('G');
    $dow = (int)date('N');
    return $dow <= 5 && $h >= OPENING_HOUR && $h < CLOSING_HOUR;
}
