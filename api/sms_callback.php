<?php
/**
 * SMS delivery-report (DLR) callback endpoint.
 *
 * When a message is handed to a gateway, the send API only confirms that the
 * gateway accepted it. Whether it actually reached the handset is reported
 * asynchronously, minutes later, by the gateway posting back to a URL that
 * you register in its dashboard. Without this endpoint the delivery status
 * recorded against every SMS stays at "sent" forever, and the accessibility
 * claim the report makes for the SMS channel cannot be evidenced.
 *
 * Register this URL with your provider:
 *
 *   https://your-host/qms/api/sms_callback.php?token=<callback token>
 *
 * The token is generated on the administrator gateway page and must match,
 * otherwise the request is refused. Neither Africa's Talking nor Twilio signs
 * these callbacks in a way that is practical to verify on a student project,
 * so a secret in the URL is the protection used here. It is not a substitute
 * for HTTPS: over plain HTTP the token is visible in transit.
 *
 * The endpoint is deliberately forgiving. Gateways retry on a non-200, so it
 * answers 200 for anything it has understood, and only refuses outright when
 * the token is wrong.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../modules/settings.php';
require_once __DIR__ . '/../modules/sms_gateway.php';

header('Content-Type: text/plain; charset=utf-8');

/** Record every callback so failures can be diagnosed after the fact. */
function callback_log(string $line): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/sms_callback.log',
        sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line),
        FILE_APPEND
    );
}

// ------------------------------------------------------------ authorisation
$expected = (string)setting_get('sms_callback_token', '');
$supplied = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if ($expected === '') {
    http_response_code(503);
    callback_log('refused: no callback token configured');
    exit("Callback not configured.\n");
}
if (!hash_equals($expected, $supplied)) {
    http_response_code(403);
    callback_log('refused: bad token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit("Forbidden.\n");
}

// ---------------------------------------------------------------- payload
$raw = file_get_contents('php://input') ?: '';
$payload = $_POST;

// Some gateways post JSON rather than form fields.
if (!$payload && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (!$payload) {
    http_response_code(400);
    callback_log('empty payload; raw=' . substr($raw, 0, 200));
    exit("No payload.\n");
}

$report = sms_normalise_report($payload);

if ($report === null) {
    http_response_code(400);
    callback_log('unrecognised payload: ' . substr(json_encode($payload), 0, 300));
    exit("Unrecognised payload.\n");
}

[$reference, $status, $detail] = $report;

// ------------------------------------------------------------ apply update
$row = q_one(
    'SELECT notification_id, delivery_status FROM notification
      WHERE provider_reference = :ref
      ORDER BY notification_id DESC LIMIT 1',
    [':ref' => $reference]
);

if (!$row) {
    // A report for a message this system did not send. Answer 200 so the
    // gateway stops retrying, but record it: it usually means two systems
    // share one gateway account.
    callback_log(sprintf('no matching notification for reference %s (%s)',
                         $reference, $status));
    exit("Unknown reference.\n");
}

// Never move a final state backwards: a late "sent" must not overwrite an
// earlier "delivered".
$final = ['delivered', 'failed'];
if (in_array($row['delivery_status'], $final, true)
    && !in_array($status, $final, true)) {
    callback_log(sprintf('ignored regression for %s: %s -> %s',
                         $reference, $row['delivery_status'], $status));
    exit("Ignored.\n");
}

q('UPDATE notification
      SET delivery_status = :st, provider_detail = :d
    WHERE notification_id = :id',
  [':st' => $status, ':d' => mb_substr($detail, 0, 255),
   ':id' => $row['notification_id']]);

callback_log(sprintf('notification %d reference %s: %s -> %s',
                     $row['notification_id'], $reference,
                     $row['delivery_status'], $status));

echo "OK\n";
