<?php
/**
 * E-ticket email delivery (FR-09, email channel).
 * Sends the client their e-ticket by email via SMTP, using the vendored
 * PHPMailer. Enabled only when SMTP credentials are configured
 * (EMAIL_ENABLED); otherwise every call is a no-op that reports 'skipped'.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/dispatch.php'; // record_notification()

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Compose and send the e-ticket email for one queue entry.
 * Returns ['status' => 'sent'|'failed'|'skipped', 'detail' => string].
 * Never throws: a mail failure must not block a client from joining.
 */
function send_eticket_email(int $queueEntryId): array
{
    $entry = q_one(
        'SELECT qe.queue_entry_id, qe.queue_number, qe.service_type,
                qe.predicted_wait_time, u.full_name, u.email,
                sc.counter_name
           FROM queue_entry qe
           JOIN user u ON u.user_id = qe.user_id
           LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
          WHERE qe.queue_entry_id = :id',
        [':id' => $queueEntryId]
    );
    if (!$entry || empty($entry['email'])) {
        return ['status' => 'skipped', 'detail' => 'No email address on file'];
    }
    if (!EMAIL_ENABLED) {
        return ['status' => 'skipped', 'detail' => 'Email delivery not configured'];
    }

    $wait = isset($entry['predicted_wait_time'])
        ? fmt_minutes($entry['predicted_wait_time']) : 'shortly';
    $html = eticket_email_html($entry, $wait);
    $text = 'Your Huduma Centre ticket is ' . $entry['queue_number'] . '. '
          . 'Service: ' . $entry['service_type'] . '. '
          . 'Estimated wait: ' . $wait . '. '
          . 'Track your place in real time on the queue website.';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout    = 12;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($entry['email'], $entry['full_name'] ?: '');
        $mail->isHTML(true);
        $mail->Subject = 'Your ticket ' . $entry['queue_number'] . ' — ' . CENTRE_NAME;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();

        if (function_exists('record_notification')) {
            record_notification($queueEntryId, 'email', 'sent', $text);
        }
        return ['status' => 'sent', 'detail' => $entry['email']];
    } catch (\Throwable $ex) {
        $why = $mail->ErrorInfo ?: $ex->getMessage();
        if (function_exists('record_notification')) {
            record_notification($queueEntryId, 'email', 'failed', $text, null, $why);
        }
        return ['status' => 'failed', 'detail' => $why];
    }
}

/** The branded HTML body. Table-based with inline styles for email clients. */
function eticket_email_html(array $entry, string $wait): string
{
    $num     = e($entry['queue_number']);
    $service = e($entry['service_type']);
    $name    = e($entry['full_name'] ?: 'there');
    $counter = e($entry['counter_name'] ?? '');
    $red     = '#c1272d';
    $ink     = '#1c1413';
    $soft    = '#55504c';
    return <<<HTML
<div style="margin:0;padding:24px;background:#f4ece9;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6ddd7;">
    <tr><td style="background:{$red};padding:22px 28px;">
      <div style="color:#ffffff;font-size:18px;font-weight:bold;">Huduma Centre Queue</div>
      <div style="color:#ffe7e5;font-size:13px;">Your e-ticket</div>
    </td></tr>
    <tr><td style="padding:28px;">
      <p style="margin:0 0 4px;color:{$soft};font-size:14px;">Hello {$name}, you're in the queue.</p>
      <div style="font-size:13px;color:{$soft};margin-top:14px;">Ticket number</div>
      <div style="font-size:46px;font-weight:bold;color:{$red};line-height:1.1;">{$num}</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;border-top:1px solid #eee;">
        <tr>
          <td style="padding:14px 0;color:{$soft};font-size:13px;">Service</td>
          <td style="padding:14px 0;text-align:right;color:{$ink};font-size:15px;font-weight:bold;">{$service}</td>
        </tr>
        <tr>
          <td style="padding:0 0 14px;color:{$soft};font-size:13px;">Estimated wait</td>
          <td style="padding:0 0 14px;text-align:right;color:{$ink};font-size:15px;font-weight:bold;">{$wait}</td>
        </tr>
      </table>
      <div style="background:#fbeceb;border:1px solid #f3cecd;border-radius:10px;padding:14px;color:{$red};font-size:13px;text-align:center;margin-top:6px;">
        Track your place in real time on the queue website. Please arrive before your number is called.
      </div>
    </td></tr>
    <tr><td style="padding:16px 28px;background:#faf7f5;color:#8a7f79;font-size:11px;text-align:center;">
      This is an automated message from the Huduma Centre queue system.
    </td></tr>
  </table>
</div>
HTML;
}
