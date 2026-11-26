<?php
/**
 * FILE: modules/queue_registration.php
 * Process 1.0 — Queue Registration Module
 *
 * WHAT IT DOES: Everything that happens when a client joins the queue -
 * validating their details, saving them, issuing a ticket number, choosing a
 * counter - plus check-in, cancelling and looking a ticket up.
 *
 * FIND: join queue, register client, ticket number, assign counter,
 *       check in, cancel ticket, find ticket
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

/**
 * DEPARTMENT LETTER. Each service gets its own ticket letter, taken from its
 * position in $SERVICE_TYPES (config.php): 1st = A, 2nd = B, 3rd = C ...
 * Currently: ID Registration A, Birth Certificate B, Business Permit C,
 * Licence Renewal D. A service added to the list gets the next letter.
 * FIND: ticket letter, department prefix, service prefix
 */
function service_prefix(string $serviceType): string
{
    global $SERVICE_TYPES;
    $index = array_search($serviceType, $SERVICE_TYPES ?? [], true);
    return $index === false ? QUEUE_PREFIX : chr(ord('A') + (int)$index);
}

/**
 * TICKET NUMBER (FR-02). Generates today's next number for a department,
 * e.g. ID Registration A-001, A-002; Birth Certificate B-001, B-002.
 * Each department counts separately and numbering restarts every day.
 * FIND: ticket number, queue number, generate number
 */
function next_queue_number(string $serviceType): string
{
    $prefix = service_prefix($serviceType);
    $lastNumber = q_val(
        'SELECT queue_number FROM queue_entry
          WHERE queue_date = CURDATE() AND queue_number LIKE :p
          ORDER BY queue_entry_id DESC LIMIT 1',
        [':p' => $prefix . '-%']
    );
    $seq = 1;
    if ($lastNumber && preg_match('/(\d+)$/', (string)$lastNumber, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('%s-%03d', $prefix, $seq);
}

/**
 * Save the client: update them if their phone number is already known,
 * otherwise create a new client record. Clients have no password.
 * FIND: save client, create client
 */
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
 * ASSIGN COUNTER (FR-03). Picks the open counter for this service with the
 * fewest people waiting, so work is spread evenly. Only counters that have
 * a staff member assigned are considered - a client is never sent to a
 * counter where nobody can call them.
 * FIND: assign counter, choose counter, spread load, staffed counter
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
            AND sc.assigned_staff_id IS NOT NULL
          ORDER BY load_count ASC, sc.counter_id ASC
          LIMIT 1',
        [':st' => $serviceType]
    );
    return $counter ? (int)$counter['counter_id'] : null;
}

/**
 * JOIN THE QUEUE - the main registration function. Validates name, phone,
 * email and service; refuses a second active ticket for the same phone;
 * then, inside one database transaction, saves the client, assigns a
 * counter and creates the ticket. Afterwards it recalculates predictions
 * and sends the ticket (e-ticket or SMS).
 * FIND: join queue, register, input validation, create ticket, transaction
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
    // Refuse the ticket unless an open counter for this service has staff.
    if (assign_counter($serviceType) === null) {
        return ['ok' => false,
                'error' => 'No counter is currently open for ' . $serviceType
                           . '. Please choose another service or try later.'];
    }

    // ---- one active ticket per phone number PER SERVICE --------------
    // A client may queue for several services at once (e.g. ID Registration
    // and a Business Permit), but not twice for the same service.
    // FIND: multiple services, one ticket per service, already have a ticket
    $active = q_one(
        'SELECT qe.queue_entry_id, qe.queue_number
           FROM queue_entry qe JOIN user u ON u.user_id = qe.user_id
          WHERE u.phone_number = :p
            AND qe.service_type = :st
            AND qe.status IN ("waiting","checked_in","called","in_service")
          LIMIT 1',
        [':p' => $phone, ':st' => $serviceType]
    );
    if ($active) {
        return ['ok' => false,
                'error' => 'You already have ticket ' . $active['queue_number']
                           . ' for ' . $serviceType . '. Track it instead, or choose a different service.',
                'entry_id' => (int)$active['queue_entry_id']];
    }

    // ---- create ------------------------------------------------------
    // If two people join at the same instant they can be handed the same
    // number; the database's unique (date, number) key rejects the second,
    // and we simply try again with the next number (up to 3 attempts).
    // FIND: duplicate ticket number, retry
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $userId    = upsert_client($name, $phone, $email);
            $counterId = assign_counter($serviceType);
            $number    = next_queue_number($serviceType);

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
            break;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $numberClash = $ex instanceof PDOException
                && ($ex->errorInfo[1] ?? 0) === 1062
                && str_contains($ex->getMessage(), 'uq_queue_number');
            if ($numberClash && $attempt < 3) {
                continue;
            }
            return ['ok' => false, 'error' => 'Could not create the ticket. '
                    . (APP_DEBUG ? $ex->getMessage() : 'Please try again.')];
        }
    }

    // ---- trigger prediction (2.0) then dispatch (3.0) ----------------
    recalculate_predictions($serviceType);
    dispatch_ticket($entryId);

    return ['ok' => true, 'entry_id' => $entryId, 'queue_number' => $number];
}

/**
 * CHECK IN (FR-11). The client confirms they are physically at the centre.
 * FIND: check in, confirm attendance
 */
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

/**
 * The client cancels their own ticket; everyone behind them gets an updated
 * prediction.
 * FIND: cancel ticket, leave queue
 */
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

/**
 * All of a client's open tickets (one per service), oldest first. Used by
 * the Track page to show every service a client is queueing for.
 * FIND: all my tickets, multiple tickets, other tickets
 */
function active_tickets_for_user(int $userId): array
{
    return q_all(
        'SELECT qe.queue_entry_id, qe.queue_number, qe.service_type, qe.status,
                qe.predicted_wait_time
           FROM queue_entry qe
          WHERE qe.user_id = :u
            AND qe.status IN ("waiting","checked_in","called","in_service")
          ORDER BY qe.join_time ASC',
        [':u' => $userId]
    );
}

/**
 * Look up a ticket by its number (e.g. A-012) or by phone number.
 * Used by the Track ticket page.
 * FIND: find ticket, track ticket, search ticket
 */
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
