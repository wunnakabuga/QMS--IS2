<?php
/**
 * Process 1.0 — Queue Registration Module
 *
 * Implements FR-01, FR-02, FR-03 and FR-11. Creates or updates the client
 * record, issues a sequential queue number, assigns the entry to a counter
 * configured for the requested service, then triggers prediction (2.0) and
 * dispatch (3.0).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/prediction.php';
require_once __DIR__ . '/dispatch.php';

/** FR-02: generate the next sequential queue number for today. */
function next_queue_number(): string
{
    $lastNumber = q_val(
        'SELECT queue_number FROM queue_entry
          WHERE DATE(join_time) = CURDATE()
          ORDER BY queue_entry_id DESC LIMIT 1'
    );
    $seq = 1;
    if ($lastNumber && preg_match('/(\d+)$/', (string)$lastNumber, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('%s-%03d', QUEUE_PREFIX, $seq);
}

/** Find or create the client user record. */
function upsert_client(string $name, string $phone, ?string $email): int
{
    $existing = q_one(
        'SELECT user_id FROM user WHERE phone_number = :p AND role = "client" LIMIT 1',
        [':p' => $phone]
    );
    if ($existing) {
        q('UPDATE user SET full_name = :n, email = COALESCE(NULLIF(:e, ""), email)
            WHERE user_id = :id',
          [':n' => $name, ':e' => $email ?? '', ':id' => $existing['user_id']]);
        return (int)$existing['user_id'];
    }
    q('INSERT INTO user (full_name, phone_number, email, role)
       VALUES (:n, :p, NULLIF(:e, ""), "client")',
      [':n' => $name, ':p' => $phone, ':e' => $email ?? '']);
    return (int)db()->lastInsertId();
}

/**
 * FR-03: pick the open counter for the service type with the fewest
 * outstanding entries, so that load is spread across counters.
 */
function assign_counter(string $serviceType): ?int
{
    $counter = q_one(
        'SELECT sc.counter_id,
                (SELECT COUNT(*) FROM queue_entry qe
                  WHERE qe.counter_id = sc.counter_id
                    AND qe.status IN ("waiting","checked_in","called","in_service")
                ) AS load_count
           FROM service_counter sc
          WHERE sc.service_type = :st AND sc.status = "open"
          ORDER BY load_count ASC, sc.counter_id ASC
          LIMIT 1',
        [':st' => $serviceType]
    );
    return $counter ? (int)$counter['counter_id'] : null;
}

/**
 * Join the queue. Returns the created entry, or an error.
 *
 * @return array{ok:bool, entry_id?:int, queue_number?:string, error?:string}
 */
function join_queue(string $name, string $phoneRaw, ?string $email,
                    string $serviceType, string $channel = 'e-ticket'): array
{
    global $SERVICE_TYPES;

    $name  = trim($name);
    $phone = normalise_phone(trim($phoneRaw));
    $email = $email !== null ? trim($email) : null;
    $channel = $channel === 'sms' ? 'sms' : 'e-ticket';

    // ---- validation -------------------------------------------------
    if ($name === '' || mb_strlen($name) < 3) {
        return ['ok' => false, 'error' => 'Please enter your full name.'];
    }
    if (!valid_phone($phone)) {
        return ['ok' => false, 'error' => 'Please enter a valid phone number.'];
    }
    if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (!in_array($serviceType, $SERVICE_TYPES, true)) {
        return ['ok' => false, 'error' => 'Please select a service.'];
    }
    if (open_counter_count($serviceType) === 0) {
        return ['ok' => false,
                'error' => 'No counter is currently open for ' . $serviceType
                           . '. Please choose another service or try later.'];
    }

    // ---- one active ticket per phone number --------------------------
    $active = q_one(
        'SELECT qe.queue_entry_id, qe.queue_number
           FROM queue_entry qe JOIN user u ON u.user_id = qe.user_id
          WHERE u.phone_number = :p
            AND qe.status IN ("waiting","checked_in","called","in_service")
          LIMIT 1',
        [':p' => $phone]
    );
    if ($active) {
        return ['ok' => false,
                'error' => 'You already hold an active ticket ('
                           . $active['queue_number'] . '). Track it instead.',
                'entry_id' => (int)$active['queue_entry_id']];
    }

    // ---- create ------------------------------------------------------
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userId    = upsert_client($name, $phone, $email);
        $counterId = assign_counter($serviceType);
        $number    = next_queue_number();

        q('INSERT INTO queue_entry
             (user_id, counter_id, queue_number, service_type, status,
              join_time, delivery_channel)
           VALUES (:u, :c, :n, :st, "waiting", NOW(), :ch)',
          [':u' => $userId, ':c' => $counterId, ':n' => $number,
           ':st' => $serviceType, ':ch' => $channel]);

        $entryId = (int)$pdo->lastInsertId();

        q('INSERT INTO service_log (queue_entry_id, counter_id, status)
           VALUES (:id, :c, "joined")',
          [':id' => $entryId, ':c' => $counterId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Could not create the ticket. '
                . (APP_DEBUG ? $ex->getMessage() : 'Please try again.')];
    }

    // ---- trigger prediction (2.0) then dispatch (3.0) ----------------
    recalculate_predictions($serviceType);
    dispatch_ticket($entryId);

    return ['ok' => true, 'entry_id' => $entryId, 'queue_number' => $number];
}

/** FR-11: client confirms attendance. */
function check_in(int $queueEntryId): array
{
    $entry = q_one('SELECT status, service_type FROM queue_entry
                     WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    if (!$entry) {
        return ['ok' => false, 'error' => 'Ticket not found.'];
    }
    if (!in_array($entry['status'], ['waiting', 'called'], true)) {
        return ['ok' => false, 'error' => 'This ticket cannot be checked in.'];
    }
    q('UPDATE queue_entry SET status = "checked_in", check_in_time = NOW()
        WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    q('INSERT INTO service_log (queue_entry_id, counter_id, status)
       SELECT queue_entry_id, counter_id, "checked_in" FROM queue_entry
        WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    return ['ok' => true];
}

/** Client cancels their own ticket. */
function cancel_ticket(int $queueEntryId): array
{
    $entry = q_one('SELECT status, service_type FROM queue_entry
                     WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    if (!$entry) {
        return ['ok' => false, 'error' => 'Ticket not found.'];
    }
    if (in_array($entry['status'], ['completed', 'cancelled', 'expired'], true)) {
        return ['ok' => false, 'error' => 'This ticket is already closed.'];
    }
    q('UPDATE queue_entry SET status = "cancelled" WHERE queue_entry_id = :id',
      [':id' => $queueEntryId]);
    q('INSERT INTO service_log (queue_entry_id, counter_id, status)
       SELECT queue_entry_id, counter_id, "cancelled" FROM queue_entry
        WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    recalculate_predictions($entry['service_type']);
    return ['ok' => true];
}

/** Locate a ticket by queue number or phone number (used by track.php). */
function find_ticket(string $reference): ?array
{
    $reference = trim($reference);
    if ($reference === '') {
        return null;
    }
    $entry = q_one(
        'SELECT queue_entry_id FROM queue_entry
          WHERE queue_number = :r
          ORDER BY queue_entry_id DESC LIMIT 1',
        [':r' => strtoupper($reference)]
    );
    if (!$entry) {
        $phone = normalise_phone($reference);
        $entry = q_one(
            'SELECT qe.queue_entry_id
               FROM queue_entry qe JOIN user u ON u.user_id = qe.user_id
              WHERE u.phone_number = :p
              ORDER BY qe.queue_entry_id DESC LIMIT 1',
            [':p' => $phone]
        );
    }
    return $entry ? ticket_view((int)$entry['queue_entry_id']) : null;
}
