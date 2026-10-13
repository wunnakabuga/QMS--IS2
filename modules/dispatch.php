<?php
/**
 * Process 3.0 — Ticket and Notification Dispatch Module
 *
 * Implements FR-07 to FR-10. Routes confirmed ticket data to the browser
 * (e-ticket) or to the SMS gateway according to the delivery channel
 * recorded on the queue entry, and writes a NOTIFICATION record carrying
 * the channel used and the delivery status returned.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/sms_gateway.php';

/** Compose the plain-text ticket message used on the SMS channel. */
function compose_ticket_message(array $entry, ?int $position): string
{
    $lines = [
        strtoupper(CENTRE_NAME),
        'Ticket: ' . $entry['queue_number'],
        'Service: ' . $entry['service_type'],
    ];
    if ($position !== null) {
        $lines[] = 'Position: ' . $position;
    }
    if ($entry['predicted_wait_time'] !== null) {
        $lines[] = 'Predicted wait: ' . fmt_minutes($entry['predicted_wait_time']);
    }
    if (!empty($entry['counter_name'])) {
        $lines[] = 'Counter: ' . $entry['counter_name'];
    }
    $lines[] = 'Date: ' . date('d/m/Y H:i', strtotime($entry['join_time']));
    $lines[] = 'Reply HELP for assistance.';
    return implode("\n", $lines);
}

/** Compose the reminder message sent as the client's turn approaches. */
function compose_reminder_message(array $entry): string
{
    return "REMINDER\n"
        . 'Ticket ' . $entry['queue_number'] . ' is next in approx. '
        . fmt_minutes($entry['predicted_wait_time']) . ".\n"
        . 'Please report to ' . ($entry['counter_name'] ?? 'the service counter') . ".\n"
        . 'Ticket expires ' . GRACE_PERIOD_MINUTES . ' min after being called.';
}

/** Persist a notification record (FR-09). */
function record_notification(int $queueEntryId, string $channel,
                             string $status, string $message,
                             ?string $reference = null, ?string $detail = null,
                             int $attempts = 1): int
{
    q('INSERT INTO notification
         (queue_entry_id, channel, delivery_status, message_content,
          provider_reference, provider_detail, attempts)
       VALUES (:id, :ch, :st, :msg, :ref, :detail, :att)',
      [':id' => $queueEntryId, ':ch' => $channel, ':st' => $status,
       ':msg' => $message, ':ref' => $reference, ':detail' => $detail,
       ':att' => $attempts]);
    return (int)db()->lastInsertId();
}

/**
 * Send over SMS and record the result, retrying once on a transient failure.
 * A gateway refusing the message (bad number, no credit) is not retried,
 * because the second attempt would fail the same way and cost money.
 */
function send_and_record_sms(int $queueEntryId, string $phone, string $message): array
{
    $result = sms_send($phone, $message);
    $attempts = 1;

    $transient = $result['status'] === 'failed'
        && ($result['http_code'] === null || $result['http_code'] >= 500
            || $result['http_code'] === 0 || $result['http_code'] === 429);

    if ($transient) {
        sleep(1);
        $retry = sms_send($phone, $message);
        $attempts = 2;
        if ($retry['status'] !== 'failed') {
            $result = $retry;
        } else {
            $result['detail'] = 'attempt 1: ' . $result['detail']
                              . ' | attempt 2: ' . $retry['detail'];
        }
    }

    record_notification($queueEntryId, 'sms', $result['status'], $message,
                        $result['reference'] ?? null, $result['detail'] ?? null,
                        $attempts);
    return $result;
}

/**
 * Dispatch the ticket for a queue entry over its configured channel.
 * This is the branching rule documented in Wireframe 5.
 */
function dispatch_ticket(int $queueEntryId): array
{
    $entry = ticket_view($queueEntryId);
    if (!$entry) {
        return ['ok' => false, 'error' => 'Queue entry not found'];
    }

    $position = $entry['queue_position'];
    $message  = compose_ticket_message($entry, $position);

    if ($entry['delivery_channel'] === 'sms') {
        $result = send_and_record_sms($queueEntryId, $entry['phone_number'], $message);
        return ['ok' => true, 'channel' => 'sms', 'status' => $result['status'],
                'detail' => $result['detail'] ?? null, 'message' => $message];
    }

    // Browser channel: the ticket is rendered by ticket.php; the record is
    // written here so that both channels are auditable in the same table.
    record_notification($queueEntryId, 'e-ticket', 'delivered', $message,
                        null, 'Rendered in the client browser');
    return ['ok' => true, 'channel' => 'e-ticket', 'status' => 'delivered',
            'message' => $message];
}

/**
 * Dispatch reminders for entries whose predicted wait has fallen to the
 * threshold and that have not yet been reminded (FR-10).
 */
function dispatch_due_reminders(): int
{
    $due = q_all(
        'SELECT qe.queue_entry_id
           FROM queue_entry qe
          WHERE qe.status IN ("waiting","checked_in")
            AND qe.predicted_wait_time IS NOT NULL
            AND qe.predicted_wait_time <= :threshold
            AND NOT EXISTS (
                SELECT 1 FROM notification n
                 WHERE n.queue_entry_id = qe.queue_entry_id
                   AND n.message_content LIKE "REMINDER%")',
        [':threshold' => REMINDER_THRESHOLD_MINUTES]
    );

    $sent = 0;
    foreach ($due as $row) {
        $entry = ticket_view((int)$row['queue_entry_id']);
        if (!$entry) {
            continue;
        }
        $message = compose_reminder_message($entry);
        if ($entry['delivery_channel'] === 'sms') {
            send_and_record_sms((int)$row['queue_entry_id'],
                                $entry['phone_number'], $message);
        } else {
            record_notification((int)$row['queue_entry_id'], 'e-ticket',
                                'delivered', $message, null,
                                'Shown on the client ticket page');
        }
        $sent++;
    }
    return $sent;
}

/**
 * Full ticket view for a queue entry, joining the client and counter and
 * computing the derived queue_position attribute.
 */
function ticket_view(int $queueEntryId): ?array
{
    require_once __DIR__ . '/prediction.php';
    $entry = q_one(
        'SELECT qe.*, u.full_name, u.phone_number, u.email,
                sc.counter_name, sc.status AS counter_status
           FROM queue_entry qe
           JOIN user u  ON u.user_id = qe.user_id
      LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
          WHERE qe.queue_entry_id = :id',
        [':id' => $queueEntryId]
    );
    if (!$entry) {
        return null;
    }
    $entry['queue_position'] = queue_position($queueEntryId);
    return $entry;
}
