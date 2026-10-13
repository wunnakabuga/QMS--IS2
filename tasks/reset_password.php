<?php
/**
 * Password reset and account diagnostic (command line).
 *
 * Account passwords are normally changed from the administrator's User
 * accounts screen, or by the account holder under My account. Both of those
 * require being signed in, which leaves no way back in if the administrator
 * password is lost or a seeded hash does not match. This tool is that way
 * back in.
 *
 * It is deliberately command-line only: anyone who can run it already has
 * filesystem access to the server, so it grants no privilege they did not
 * already hold. It must never be reachable over the web.
 *
 * Usage:
 *   php tasks/reset_password.php                      list accounts and diagnose
 *   php tasks/reset_password.php <email> <password>   set a new password
 *   php tasks/reset_password.php --check <password>   test a password against
 *                                                     every stored hash
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool must be run from the command line.\n");
}

require_once __DIR__ . '/../includes/db.php';

$args = array_slice($argv, 1);

function line(string $s = ''): void
{
    echo $s . PHP_EOL;
}

function rule(): void
{
    line(str_repeat('-', 66));
}

// ------------------------------------------------------------- diagnose
if (!$args) {
    line('Queue Management System — accounts');
    rule();

    $rows = q_all('SELECT user_id, full_name, email, role, password_hash
                     FROM user WHERE role IN ("staff","admin")
                    ORDER BY role DESC, full_name');

    if (!$rows) {
        line('No staff or administrator accounts exist.');
        line('');
        line('The database may not have been seeded. Re-import');
        line('database/schema.sql, or create an account with:');
        line('  php tasks/reset_password.php admin@example.com YourPassword1 --create');
        exit(1);
    }

    foreach ($rows as $r) {
        $hash = (string)$r['password_hash'];
        $info = $hash !== '' ? password_get_info($hash) : ['algoName' => 'none'];
        $ok   = $hash !== '' && strlen($hash) === 60
                && str_starts_with($hash, '$2y$');

        line(sprintf('%-28s %-6s  %s', $r['email'], $r['role'],
                     $ok ? 'hash looks valid' : 'HASH LOOKS WRONG'));
        line(sprintf('    algorithm: %-10s length: %d (expected 60)',
                     $info['algoName'] ?? 'unknown', strlen($hash)));
        if (!$ok) {
            line('    The stored hash is not a complete bcrypt hash. This is the');
            line('    usual cause of a seeded password being rejected: the hash was');
            line('    truncated or altered on import. Reset it with the command');
            line('    at the bottom of this output.');
        }
        line('');
    }

    rule();
    line('To test whether a password matches any account:');
    line('  php tasks/reset_password.php --check Password123');
    line('');
    line('To set a new password:');
    line('  php tasks/reset_password.php admin@hudumacentre.go.ke NewPassword1');
    exit(0);
}

// ---------------------------------------------------------------- check
if ($args[0] === '--check') {
    $candidate = $args[1] ?? '';
    if ($candidate === '') {
        exit("Usage: php tasks/reset_password.php --check <password>\n");
    }

    line('Testing "' . $candidate . '" against every stored hash');
    rule();

    $rows = q_all('SELECT email, role, password_hash FROM user
                    WHERE role IN ("staff","admin") ORDER BY role DESC');
    $any = false;
    foreach ($rows as $r) {
        $match = $r['password_hash'] !== null
                 && password_verify($candidate, (string)$r['password_hash']);
        $any = $any || $match;
        line(sprintf('  %-30s %-6s %s', $r['email'], $r['role'],
                     $match ? 'MATCHES' : 'does not match'));
    }
    rule();
    line($any
        ? 'At least one account accepts that password.'
        : 'No account accepts that password. Reset one with:'
          . PHP_EOL . '  php tasks/reset_password.php <email> <new password>');
    exit($any ? 0 : 1);
}

// ---------------------------------------------------------------- reset
$email    = trim($args[0]);
$password = $args[1] ?? '';
$create   = in_array('--create', $args, true);

if ($password === '') {
    exit("Usage: php tasks/reset_password.php <email> <new password> [--create]\n");
}
if (strlen($password) < 8
    || !preg_match('/[A-Za-z]/', $password)
    || !preg_match('/[0-9]/', $password)) {
    exit("The password must be at least 8 characters and contain both "
         . "letters and numbers.\n");
}

$user = q_one('SELECT user_id, full_name, role FROM user WHERE email = :e',
              [':e' => $email]);

$hash = password_hash($password, PASSWORD_DEFAULT);

if (!$user) {
    if (!$create) {
        line('No account exists with the email ' . $email);
        line('');
        line('Existing accounts:');
        foreach (q_all('SELECT email, role FROM user
                         WHERE role IN ("staff","admin") ORDER BY role DESC') as $r) {
            line('  ' . $r['email'] . '  (' . $r['role'] . ')');
        }
        line('');
        line('To create this account instead, add --create:');
        line('  php tasks/reset_password.php ' . $email . ' <password> --create');
        exit(1);
    }

    q('INSERT INTO user (full_name, phone_number, email, password_hash, role)
       VALUES (:n, :p, :e, :h, "admin")',
      [':n' => 'Administrator', ':p' => '+254700000000',
       ':e' => $email, ':h' => $hash]);
    line('Created administrator account ' . $email);
} else {
    q('UPDATE user SET password_hash = :h WHERE user_id = :id',
      [':h' => $hash, ':id' => $user['user_id']]);
    line('Password reset for ' . $user['full_name']
         . ' (' . $email . ', ' . $user['role'] . ')');
}

// Prove it worked rather than assuming it did.
$stored = (string)q_val('SELECT password_hash FROM user WHERE email = :e',
                        [':e' => $email]);
$verified = password_verify($password, $stored);

rule();
line('Verification: ' . ($verified
    ? 'the new password was stored correctly and verifies.'
    : 'FAILED — the stored hash does not verify. Check that the '
      . 'password_hash column is VARCHAR(255).'));
line('');
line('You can now sign in at http://localhost/qms/login.php with:');
line('  Email:    ' . $email);
line('  Password: ' . $password);

exit($verified ? 0 : 1);
