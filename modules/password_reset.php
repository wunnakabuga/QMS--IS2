<?php
/**
 * FILE: modules/password_reset.php
 * WHAT IT DOES: The "Forgot password?" flow for staff and administrators.
 *   1. request_password_reset(): makes a random one-time token, stores only
 *      its SHA-256 hash with a 30-minute expiry, and emails the reset link.
 *   2. find_valid_reset(): checks a link's token is real, unused and in time.
 *   3. complete_password_reset(): saves the new password as a bcrypt hash and
 *      burns the token so the link cannot be used again.
 *
 * SECURITY:
 *   - The token is 64 random hex characters (random_bytes). Only its hash is
 *     stored, so someone reading the database cannot use a pending link.
 *   - Links expire after RESET_TTL_MINUTES and work once.
 *   - The forgot-password page shows the same message whether or not the
 *     email exists, so it cannot be used to discover staff email addresses.
 *   - Requests are rate limited (see forgot_password.php).
 *
 * FIND: forgot password, password reset, reset link, reset token, reset email
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

const RESET_TTL_MINUTES = 30;

// Public address of the site, used to build the link in the email. Set
// QMS_APP_URL (or define APP_URL in config.php) when hosted elsewhere. It is
// deliberately NOT taken from the browser's Host header, which an attacker
// could fake to make the emailed link point at their own site.
if (!defined('APP_URL')) {
    define('APP_URL', rtrim(getenv('QMS_APP_URL') ?: 'http://localhost/qms', '/'));
}

/** Create the password_reset table the first time it is needed. */
function password_reset_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS password_reset (
            reset_id    INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT          NOT NULL,
            token_hash  CHAR(64)     NOT NULL,
            expires_at  DATETIME     NOT NULL,
            used_at     DATETIME     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reset_token (token_hash),
            INDEX idx_reset_user (user_id),
            CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
                REFERENCES user (user_id) ON DELETE CASCADE
         ) ENGINE=InnoDB'
    );
    $done = true;
}

/**
 * STEP 1 - REQUEST A RESET. If the email belongs to a staff/admin account,
 * create a one-time token and email the link. Says nothing to the caller
 * about whether the account exists.
 * FIND: request password reset, send reset link
 */
function request_password_reset(string $email): void
{
    password_reset_init();
    $user = q_one(
        'SELECT user_id, full_name, email FROM user
          WHERE email = :e AND role IN ("staff","admin") AND password_hash IS NOT NULL
          LIMIT 1',
        [':e' => trim($email)]
    );
    if (!$user) {
        return;
    }

    // Any older unused link for this account stops working.
    q('DELETE FROM password_reset WHERE user_id = :u', [':u' => $user['user_id']]);

    $token = bin2hex(random_bytes(32));      // what goes in the link
    q('INSERT INTO password_reset (user_id, token_hash, expires_at)
       VALUES (:u, :h, DATE_ADD(NOW(), INTERVAL :m MINUTE))',
      [':u' => $user['user_id'], ':h' => hash('sha256', $token), ':m' => RESET_TTL_MINUTES]);

    $link = APP_URL . '/reset_password.php?token=' . $token;
    send_reset_email($user['email'], $user['full_name'], $link);
}

/**
 * STEP 2 - CHECK A LINK. Returns the reset row (with the user's name and
 * email) if the token is genuine, unused and not expired; otherwise null.
 * FIND: validate reset token, reset link expired
 */
function find_valid_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    password_reset_init();
    return q_one(
        'SELECT pr.reset_id, pr.user_id, u.full_name, u.email
           FROM password_reset pr JOIN user u ON u.user_id = pr.user_id
          WHERE pr.token_hash = :h AND pr.used_at IS NULL AND pr.expires_at > NOW()
          LIMIT 1',
        [':h' => hash('sha256', $token)]
    );
}

/**
 * STEP 3 - SET THE NEW PASSWORD. Stores it as a bcrypt hash with
 * password_hash() and marks the token used. Returns an error message, or
 * null on success.
 * FIND: complete password reset, save new password, password hashing
 */
function complete_password_reset(string $token, string $new, string $confirm): ?string
{
    $reset = find_valid_reset($token);
    if (!$reset) {
        return 'This reset link is invalid or has expired. Please request a new one.';
    }
    if (($problem = password_problem($new)) !== null) {
        return $problem;
    }
    if ($new !== $confirm) {
        return 'The two passwords do not match.';
    }
    q('UPDATE user SET password_hash = :h WHERE user_id = :u',
      [':h' => password_hash($new, PASSWORD_DEFAULT), ':u' => $reset['user_id']]);
    q('UPDATE password_reset SET used_at = NOW() WHERE reset_id = :r',
      [':r' => $reset['reset_id']]);
    return null;
}

/**
 * Email the reset link through the same SMTP settings as the e-ticket.
 * When email is not configured, the link is written to
 * logs/password_reset.log instead (a server file only the operator can
 * read), so the flow can still be demonstrated - it is never shown on screen.
 * FIND: reset email, send email
 */
function send_reset_email(string $to, string $name, string $link): void
{
    $minutes = RESET_TTL_MINUTES;
    $text = "Hello {$name},\n\nUse this link to set a new password for the "
          . CENTRE_NAME . " queue system. It expires in {$minutes} minutes and "
          . "works once:\n\n{$link}\n\nIf you did not ask for this, ignore this "
          . "email - your password has not changed.";

    $emailOn = defined('EMAIL_ENABLED') && EMAIL_ENABLED;
    if ($emailOn) {
        require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
        require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->Timeout    = 12;
            $mail->setFrom(SMTP_FROM, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : CENTRE_NAME);
            $mail->addAddress($to, $name);
            $mail->isHTML(true);
            $mail->Subject = 'Reset your password - ' . CENTRE_NAME;
            $mail->Body    = reset_email_html($name, $link, $minutes);
            $mail->AltBody = $text;
            $mail->send();
            return;
        } catch (\Throwable $ex) {
            $failure = $mail->ErrorInfo ?: $ex->getMessage();
        }
    }

    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/password_reset.log',
        sprintf("[%s] %s for %s: %s\n", date('Y-m-d H:i:s'),
                $emailOn ? 'EMAIL FAILED (' . ($failure ?? '?') . ')' : 'email not configured',
                $to, $link),
        FILE_APPEND
    );
}

/** The branded HTML body of the reset email (inline styles for email clients). */
function reset_email_html(string $name, string $link, int $minutes): string
{
    $name = e($name);
    $href = e($link);
    return <<<HTML
<div style="margin:0;padding:24px;background:#f4ece9;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6ddd7;">
    <tr><td style="background:#c1272d;padding:22px 28px;">
      <div style="color:#ffffff;font-size:18px;font-weight:bold;">Huduma Centre Queue</div>
      <div style="color:#ffe7e5;font-size:13px;">Password reset</div>
    </td></tr>
    <tr><td style="padding:28px;color:#33414f;font-size:14px;line-height:1.6;">
      <p style="margin:0 0 14px;">Hello {$name},</p>
      <p style="margin:0 0 20px;">Someone asked to reset the password for your staff account. Click below to choose a new one.</p>
      <p style="text-align:center;margin:0 0 20px;">
        <a href="{$href}" style="display:inline-block;background:#c1272d;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:8px;">Set a new password</a>
      </p>
      <p style="margin:0;color:#64748b;font-size:12.5px;">The link expires in {$minutes} minutes and works once. If you did not ask for this, ignore this email; your password has not changed.</p>
    </td></tr>
  </table>
</div>
HTML;
}
