<?php
/**
 * SMS gateway driver layer.
 *
 * The dispatch module (process 3.0) does not know or care which gateway is
 * in use. It calls sms_send() and receives a normalised result. Everything
 * provider-specific — endpoint, authentication scheme, payload shape,
 * response parsing — is confined to one driver function per provider.
 *
 * Adding a provider means writing one function and adding one case to
 * sms_send(). Nothing else in the system changes.
 *
 * Supported drivers:
 *   simulate    Compose and log, transmit nothing. Default; used for
 *               evaluation and demonstration without a funded account.
 *   africastalking  Africa's Talking (Kenya). apiKey header, form POST.
 *   twilio      Twilio. HTTP Basic auth, form POST.
 *   generic     Any gateway that accepts a JSON or form POST. Field names
 *               are configurable, so most local aggregators work without
 *               new code.
 *
 * Every driver returns:
 *   ['status' => 'sent'|'simulated'|'failed',
 *    'detail' => string,            human-readable, stored for audit
 *    'reference' => ?string,        provider message id where returned
 *    'http_code' => ?int]
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/settings.php';

/** Which driver is active, taking the database setting over the constant. */
function sms_driver(): string
{
    $driver = setting_get('sms_driver', SMS_MODE === 'live' ? 'africastalking' : 'simulate');
    $known = ['simulate', 'africastalking', 'twilio', 'generic'];
    return in_array($driver, $known, true) ? $driver : 'simulate';
}

/** True when the gateway will actually transmit. */
function sms_is_live(): bool
{
    return sms_driver() !== 'simulate';
}

/** Human-readable name for the active driver. */
function sms_driver_label(?string $driver = null): string
{
    return [
        'simulate'       => 'Simulation (nothing transmitted)',
        'africastalking' => "Africa's Talking",
        'twilio'         => 'Twilio',
        'generic'        => 'Generic HTTP gateway',
    ][$driver ?? sms_driver()] ?? 'Unknown';
}

/**
 * Post a request and return [body, httpCode, error].
 * Isolated so the drivers stay readable and so tests can observe it.
 */
function sms_http_post(string $url, array $headers, $body, int $timeout = 15): array
{
    if (!function_exists('curl_init')) {
        return [null, 0, 'The PHP cURL extension is not enabled. '
                       . 'Enable extension=curl in php.ini and restart Apache.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
    ]);

    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$response === false ? null : $response, $code, $error ?: null];
}

// ---------------------------------------------------------------- drivers

/** Africa's Talking: the gateway named in Section 3.5 of the report. */
function sms_driver_africastalking(string $phone, string $message, array $cfg): array
{
    $username = $cfg['username'] ?? '';
    $apiKey   = $cfg['api_key'] ?? '';
    $sender   = $cfg['sender_id'] ?? '';

    if ($username === '' || $apiKey === '') {
        return ['status' => 'failed', 'detail' => 'Username or API key not configured',
                'reference' => null, 'http_code' => null];
    }

    // The sandbox and live environments have different endpoints.
    $url = strtolower($username) === 'sandbox'
        ? 'https://api.sandbox.africastalking.com/version1/messaging'
        : 'https://api.africastalking.com/version1/messaging';

    $fields = ['username' => $username, 'to' => $phone, 'message' => $message];
    if ($sender !== '') {
        $fields['from'] = $sender;     // omit entirely when no sender id is approved
    }

    [$body, $code, $error] = sms_http_post($url, [
        'apiKey: ' . $apiKey,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], http_build_query($fields));

    if ($error !== null) {
        return ['status' => 'failed', 'detail' => 'Network error: ' . $error,
                'reference' => null, 'http_code' => $code];
    }

    $decoded = json_decode((string)$body, true);
    $recipients = $decoded['SMSMessageData']['Recipients'][0] ?? null;

    if ($code >= 200 && $code < 300 && $recipients) {
        $accepted = in_array($recipients['status'] ?? '', ['Success', 'Sent'], true);
        return [
            'status'    => $accepted ? 'sent' : 'failed',
            'detail'    => ($recipients['status'] ?? 'Unknown')
                           . ' (cost ' . ($recipients['cost'] ?? 'n/a') . ')',
            'reference' => $recipients['messageId'] ?? null,
            'http_code' => $code,
        ];
    }

    $why = $decoded['SMSMessageData']['Message']
        ?? substr((string)$body, 0, 200)
        ?: ('HTTP ' . $code);
    return ['status' => 'failed', 'detail' => $why,
            'reference' => null, 'http_code' => $code];
}

/** Twilio: useful where Africa's Talking is unavailable. */
function sms_driver_twilio(string $phone, string $message, array $cfg): array
{
    $sid   = $cfg['username'] ?? '';        // Account SID
    $token = $cfg['api_key'] ?? '';         // Auth Token
    $from  = $cfg['sender_id'] ?? '';       // Twilio number, E.164

    if ($sid === '' || $token === '' || $from === '') {
        return ['status' => 'failed',
                'detail' => 'Account SID, auth token or sending number not configured',
                'reference' => null, 'http_code' => null];
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/'
         . rawurlencode($sid) . '/Messages.json';

    [$body, $code, $error] = sms_http_post($url, [
        'Authorization: Basic ' . base64_encode($sid . ':' . $token),
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query(['To' => $phone, 'From' => $from, 'Body' => $message]));

    if ($error !== null) {
        return ['status' => 'failed', 'detail' => 'Network error: ' . $error,
                'reference' => null, 'http_code' => $code];
    }

    $decoded = json_decode((string)$body, true);
    if ($code >= 200 && $code < 300 && !empty($decoded['sid'])) {
        return ['status' => 'sent',
                'detail' => 'queued as ' . $decoded['status'],
                'reference' => $decoded['sid'], 'http_code' => $code];
    }

    return ['status' => 'failed',
            'detail' => $decoded['message'] ?? ('HTTP ' . $code),
            'reference' => null, 'http_code' => $code];
}

/**
 * Generic HTTP gateway.
 *
 * Most local aggregators expose a simple POST endpoint. Rather than write a
 * driver for each, the field names are configurable so the same code adapts.
 */
function sms_driver_generic(string $phone, string $message, array $cfg): array
{
    $url = $cfg['endpoint'] ?? '';
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'failed', 'detail' => 'Endpoint URL not configured',
                'reference' => null, 'http_code' => null];
    }

    $toField   = $cfg['field_to'] ?? 'to';
    $msgField  = $cfg['field_message'] ?? 'message';
    $fromField = $cfg['field_from'] ?? 'from';
    $asJson    = ($cfg['payload'] ?? 'form') === 'json';

    $fields = [$toField => $phone, $msgField => $message];
    if (($cfg['sender_id'] ?? '') !== '') {
        $fields[$fromField] = $cfg['sender_id'];
    }
    // Some gateways expect the key in the body rather than a header.
    if (($cfg['api_key'] ?? '') !== '' && ($cfg['key_in_body'] ?? '') === '1') {
        $fields[$cfg['field_key'] ?? 'api_key'] = $cfg['api_key'];
    }

    $headers = [$asJson ? 'Content-Type: application/json'
                        : 'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'];
    if (($cfg['api_key'] ?? '') !== '' && ($cfg['key_in_body'] ?? '') !== '1') {
        $scheme = $cfg['auth_header'] ?? 'Authorization: Bearer {key}';
        $headers[] = str_replace('{key}', $cfg['api_key'], $scheme);
    }

    [$body, $code, $error] = sms_http_post(
        $url, $headers, $asJson ? json_encode($fields) : http_build_query($fields)
    );

    if ($error !== null) {
        return ['status' => 'failed', 'detail' => 'Network error: ' . $error,
                'reference' => null, 'http_code' => $code];
    }
    if ($code >= 200 && $code < 300) {
        $decoded = json_decode((string)$body, true);
        $reference = is_array($decoded)
            ? ($decoded['id'] ?? $decoded['messageId'] ?? $decoded['message_id'] ?? null)
            : null;
        return ['status' => 'sent', 'detail' => 'HTTP ' . $code,
                'reference' => $reference, 'http_code' => $code];
    }

    return ['status' => 'failed', 'detail' => 'HTTP ' . $code . ': '
            . substr((string)$body, 0, 160), 'reference' => null, 'http_code' => $code];
}

/** Simulation: compose and record, transmit nothing. */
function sms_driver_simulate(string $phone, string $message, array $cfg): array
{
    return ['status' => 'simulated',
            'detail' => 'Gateway in simulation mode; message composed but not transmitted',
            'reference' => null, 'http_code' => null];
}

// ------------------------------------------------------------ public API

/** Current gateway configuration, database settings over config constants. */
function sms_config(): array
{
    return [
        'driver'        => sms_driver(),
        'username'      => setting_get('sms_username', SMS_USERNAME),
        'api_key'       => setting_get('sms_api_key', SMS_API_KEY),
        'sender_id'     => setting_get('sms_sender_id', SMS_SENDER_ID),
        'endpoint'      => setting_get('sms_endpoint', ''),
        'payload'       => setting_get('sms_payload', 'form'),
        'field_to'      => setting_get('sms_field_to', 'to'),
        'field_message' => setting_get('sms_field_message', 'message'),
        'field_from'    => setting_get('sms_field_from', 'from'),
        'field_key'     => setting_get('sms_field_key', 'api_key'),
        'key_in_body'   => setting_get('sms_key_in_body', '0'),
        'auth_header'   => setting_get('sms_auth_header', 'Authorization: Bearer {key}'),
    ];
}

/**
 * Send one message through the active gateway.
 * This is the only function the rest of the system calls.
 */
function sms_send(string $phone, string $message): array
{
    $cfg = sms_config();
    $phone = normalise_phone($phone);

    if (!valid_phone($phone)) {
        return ['status' => 'failed', 'detail' => 'Invalid recipient number',
                'reference' => null, 'http_code' => null];
    }

    // A message longer than one part still sends, but the cost multiplies,
    // so it is recorded for the administrator to see.
    $parts = (int)ceil(max(1, mb_strlen($message)) / 160);

    $result = match ($cfg['driver']) {
        'africastalking' => sms_driver_africastalking($phone, $message, $cfg),
        'twilio'         => sms_driver_twilio($phone, $message, $cfg),
        'generic'        => sms_driver_generic($phone, $message, $cfg),
        default          => sms_driver_simulate($phone, $message, $cfg),
    };

    if ($parts > 1) {
        $result['detail'] .= sprintf(' [%d SMS parts]', $parts);
    }
    return $result;
}

/**
 * Send a test message and record the outcome, without touching the queue.
 * Used by the administrator gateway settings page.
 */
function sms_test(string $phone): array
{
    $message = sprintf(
        "%s\nGateway test message sent %s.\nIf you received this, the SMS "
        . "gateway is configured correctly.",
        strtoupper(CENTRE_NAME), date('d/m/Y H:i')
    );
    $result = sms_send($phone, $message);
    $result['message'] = $message;
    return $result;
}

/**
 * Normalise a provider's delivery report into (reference, status, detail).
 *
 * Africa's Talking posts: id, status, phoneNumber, networkCode,
 *   failureReason, retryCount. Status is one of Success, Sent, Submitted,
 *   Buffered, Rejected, Failed.
 *
 * Twilio posts: MessageSid, MessageStatus, ErrorCode. MessageStatus is one
 *   of queued, sending, sent, delivered, undelivered, failed.
 *
 * A generic gateway is accepted on the widest plausible field names.
 */
function sms_normalise_report(array $p): ?array
{
    // ---- Africa's Talking
    if (isset($p['id']) && isset($p['status'])) {
        $status = strtolower((string)$p['status']);
        $mapped = match ($status) {
            'success', 'delivered'        => 'delivered',
            'sent', 'submitted', 'buffered' => 'sent',
            'rejected', 'failed'          => 'failed',
            default                       => 'sent',
        };
        $detail = 'Africa\'s Talking: ' . $p['status'];
        if (!empty($p['failureReason'])) {
            $detail .= ' — ' . $p['failureReason'];
        }
        if (!empty($p['networkCode'])) {
            $detail .= ' (network ' . $p['networkCode'] . ')';
        }
        return [(string)$p['id'], $mapped, $detail];
    }

    // ---- Twilio
    if (isset($p['MessageSid']) && isset($p['MessageStatus'])) {
        $status = strtolower((string)$p['MessageStatus']);
        $mapped = match ($status) {
            'delivered'             => 'delivered',
            'sent', 'sending', 'queued' => 'sent',
            'undelivered', 'failed' => 'failed',
            default                 => 'sent',
        };
        $detail = 'Twilio: ' . $p['MessageStatus'];
        if (!empty($p['ErrorCode'])) {
            $detail .= ' — error ' . $p['ErrorCode'];
        }
        return [(string)$p['MessageSid'], $mapped, $detail];
    }

    // ---- Generic gateway
    foreach ([['messageId', 'status'], ['message_id', 'status'],
              ['reference', 'state'], ['msgId', 'dlr']] as [$refKey, $stKey]) {
        if (isset($p[$refKey], $p[$stKey])) {
            $status = strtolower((string)$p[$stKey]);
            $mapped = str_contains($status, 'deliver') ? 'delivered'
                : (str_contains($status, 'fail') || str_contains($status, 'reject')
                    ? 'failed' : 'sent');
            return [(string)$p[$refKey], $mapped, 'Gateway: ' . $p[$stKey]];
        }
    }

    return null;
}
