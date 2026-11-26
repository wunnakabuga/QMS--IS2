<?php
/**
 * FILE: modules/service_delivery.php
 * Process 4.0 — Service Delivery Management Module
 *
 * WHAT IT DOES: The logic behind the staff counter screen - call next
 * client, start service, complete service (recording how long it took),
 * mark no-shows, and expire tickets whose grace period has run out.
 *
 * FIND: call next client, start service, complete service, no show,
 *       expire ticket, grace period, service duration, counter statistics
 *
 * Implements FR-12, FR-14, FR-15 and FR-16. Drives the counter console:
 * calling the next client, starting and completing service, flagging
 * no-shows, and expiring tickets whose grace period has lapsed.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/prediction.php';

/**
 * Find the counter assigned to a staff member.
 * FIND: staff counter, assigned counter
 */
function staff_counter(int $staffUserId): ?array
{
    return q_one(
        'SELECT * FROM service_counter WHERE assigned_staff_id = :s LIMIT 1',
        [':s' => $staffUserId]
    );
}

/**
 * EXPIRE TICKETS (FR-12). Marks clients as expired if they were called more
 * than GRACE_PERIOD_MINUTES ago and never came to the counter.
 * FIND: expire ticket, grace period, timeout
 */
function expire_lapsed_tickets(): int
{
    // End of day first: nothing from a previous day stays in the queue.
    $stale = expire_stale_tickets();

    $lapsed = q_all(
        'SELECT queue_entry_id, service_type FROM queue_entry
          WHERE status = "called"
            AND called_time IS NOT NULL
            AND called_time < DATE_SUB(NOW(), INTERVAL :g MINUTE)',
        [':g' => GRACE_PERIOD_MINUTES]
    );
    foreach ($lapsed as $row) {
        q('UPDATE queue_entry SET status = "expired", expiry_time = NOW()
            WHERE queue_entry_id = :id', [':id' => $row['queue_entry_id']]);
        q('INSERT INTO service_log (queue_entry_id, counter_id, status)
           SELECT queue_entry_id, counter_id, "expired" FROM queue_entry
            WHERE queue_entry_id = :id', [':id' => $row['queue_entry_id']]);
        recalculate_predictions($row['service_type']);
    }
    return $stale + count($lapsed);
}

/**
 * END-OF-DAY CLEAR-OUT. Expires every ticket still open (waiting, checked in,
 * called or in service) from a previous day. The centre closes at
 * CLOSING_HOUR, so these clients have gone home. Without this they would be
 * called first the next morning and recorded as having waited all night,
 * which wrecks the waiting-time and prediction-accuracy figures.
 * Runs from the scheduled task (tasks/run_maintenance.php) and on page
 * loads, via expire_lapsed_tickets().
 * FIND: end of day, stale tickets, previous day tickets, clear queue
 */
function expire_stale_tickets(): int
{
    $stale = q_all(
        'SELECT queue_entry_id, service_type FROM queue_entry
          WHERE status IN ("waiting","checked_in","called","in_service")
            AND queue_date < CURDATE()'
    );
    if (!$stale) {
        return 0;
    }
    foreach ($stale as $row) {
        q('UPDATE queue_entry SET status = "expired", expiry_time = NOW()
            WHERE queue_entry_id = :id', [':id' => $row['queue_entry_id']]);
        q('INSERT INTO service_log (queue_entry_id, counter_id, status)
           SELECT queue_entry_id, counter_id, "expired" FROM queue_entry
            WHERE queue_entry_id = :id', [':id' => $row['queue_entry_id']]);
    }
    foreach (array_unique(array_column($stale, 'service_type')) as $service) {
        recalculate_predictions($service);
    }
    return count($stale);
}

/** The client currently being served or called at a counter, if any. */
function current_at_counter(int $counterId): ?array
{
    return q_one(
        'SELECT qe.*, u.full_name, u.phone_number
           FROM queue_entry qe JOIN user u ON u.user_id = qe.user_id
          WHERE qe.counter_id = :c AND qe.status IN ("called","in_service")
          ORDER BY qe.called_time DESC LIMIT 1',
        [':c' => $counterId]
    );
}

/** The next clients waiting for this counter's service, in order. */
function upcoming_for_counter(int $counterId, int $limit = 8): array
{
    $counter = q_one('SELECT service_type FROM service_counter WHERE counter_id = :c',
                     [':c' => $counterId]);
    if (!$counter) {
        return [];
    }
    $rows = q_all(
        'SELECT qe.queue_entry_id, qe.queue_number, qe.status, qe.join_time,
                qe.predicted_wait_time, u.full_name,
                TIMESTAMPDIFF(MINUTE, qe.join_time, NOW()) AS waiting_minutes
           FROM queue_entry qe JOIN user u ON u.user_id = qe.user_id
          WHERE qe.service_type = :st
            AND qe.status IN ("waiting","checked_in")
          ORDER BY qe.join_time ASC
          LIMIT ' . (int)$limit,
        [':st' => $counter['service_type']]
    );
    return $rows;
}

/**
 * CALL NEXT CLIENT (FR-14). Calls the client who has waited longest
 * (first come, first served) to this counter. If the counter's own queue is
 * empty and automatic load balancing is on, it takes a client from a busier
 * service instead.
 * FIND: call next, next client, first come first served, FIFO
 */
function call_next(int $counterId): array
{
    expire_lapsed_tickets();

    $counter = q_one('SELECT * FROM service_counter WHERE counter_id = :c',
                     [':c' => $counterId]);
    if (!$counter) {
        return ['ok' => false, 'error' => 'Counter not found.'];
    }
    if ($counter['status'] !== 'open') {
        return ['ok' => false, 'error' => 'This counter is closed.'];
    }
    if (current_at_counter($counterId)) {
        return ['ok' => false,
                'error' => 'Complete or release the current client first.'];
    }

    // Checked-in clients are called ahead of clients who have not yet
    // arrived, then in join order. queue_entry_id breaks ties: join_time has
    // one-second resolution, and on a busy morning several clients share a
    // second, which would otherwise leave the call order to the database.
    // Two clients who joined in the same second are then served in the order
    // their tickets were issued, which is the order they can see.
    $next = q_one(
        'SELECT queue_entry_id FROM queue_entry
          WHERE service_type = :st AND status IN ("waiting","checked_in")
          ORDER BY (status = "checked_in") DESC, join_time ASC,
                   queue_entry_id ASC
          LIMIT 1',
        [':st' => $counter['service_type']]
    );
    if (!$next) {
        // FR-22: nobody waits for this counter's own service. Where the
        // administrator has enabled automatic load balancing, fall through
        // to the most congested other queue rather than leaving the
        // counter idle. Where it is not enabled, the console offers the
        // same move to the clerk as an explicit choice.
        require_once __DIR__ . '/load_balancing.php';
        if (overflow_is_automatic()) {
            $rec = overflow_recommendation($counterId);
            if ($rec) {
                $result = call_next_overflow($counterId, $rec['service_type']);
                if ($result['ok']) {
                    $result['overflow'] = true;
                    return $result;
                }
            }
        }
        return ['ok' => false, 'error' => 'There is nobody waiting.'];
    }

    $entryId = (int)$next['queue_entry_id'];
    q('UPDATE queue_entry
         SET status = "called", called_time = NOW(), counter_id = :c,
             expiry_time = DATE_ADD(NOW(), INTERVAL :g MINUTE)
       WHERE queue_entry_id = :id',
      [':c' => $counterId, ':g' => GRACE_PERIOD_MINUTES, ':id' => $entryId]);
    q('INSERT INTO service_log (queue_entry_id, counter_id, status)
       VALUES (:id, :c, "called")', [':id' => $entryId, ':c' => $counterId]);

    recalculate_predictions($counter['service_type']);
    return ['ok' => true, 'entry_id' => $entryId];
}

/**
 * Mark the called client as now being served (records service start time).
 * FIND: start service
 */
function start_service(int $queueEntryId): array
{
    $entry = q_one('SELECT status, counter_id FROM queue_entry
                     WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    if (!$entry || $entry['status'] !== 'called') {
        return ['ok' => false, 'error' => 'This client has not been called.'];
    }
    q('UPDATE queue_entry SET status = "in_service", service_start_time = NOW()
        WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    q('INSERT INTO service_log (queue_entry_id, counter_id, status)
       VALUES (:id, :c, "in_service")',
      [':id' => $queueEntryId, ':c' => $entry['counter_id']]);
    return ['ok' => true];
}

/**
 * COMPLETE SERVICE (FR-15). Marks the client as done and saves how long the
 * service took in the service log. These durations feed the average service
 * time used by the prediction model.
 * FIND: complete service, finish service, service duration, service log
 */
function complete_service(int $queueEntryId): array
{
    $entry = q_one('SELECT status, counter_id, service_type, service_start_time
                      FROM queue_entry WHERE queue_entry_id = :id',
                   [':id' => $queueEntryId]);
    if (!$entry || !in_array($entry['status'], ['called', 'in_service'], true)) {
        return ['ok' => false, 'error' => 'This client is not currently being served.'];
    }

    // A service completed straight from "called" is treated as having
    // started when it was called.
    if ($entry['service_start_time'] === null) {
        q('UPDATE queue_entry SET service_start_time = called_time
            WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    }

    q('UPDATE queue_entry SET status = "completed", service_end_time = NOW()
        WHERE queue_entry_id = :id', [':id' => $queueEntryId]);

    $duration = (int)q_val(
        'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(service_start_time,
                called_time, join_time), service_end_time))
           FROM queue_entry WHERE queue_entry_id = :id',
        [':id' => $queueEntryId], 0
    );

    q('INSERT INTO service_log (queue_entry_id, counter_id, status, service_duration)
       VALUES (:id, :c, "completed", :d)',
      [':id' => $queueEntryId, ':c' => $entry['counter_id'], ':d' => $duration]);

    recalculate_predictions($entry['service_type']);
    return ['ok' => true, 'duration_seconds' => $duration];
}

/**
 * NO-SHOW (FR-16). Flags a called client who never came to the counter.
 * FIND: no show, did not attend
 */
function mark_no_show(int $queueEntryId): array
{
    $entry = q_one('SELECT status, counter_id, service_type FROM queue_entry
                     WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    if (!$entry || !in_array($entry['status'], ['called', 'in_service'], true)) {
        return ['ok' => false, 'error' => 'This client cannot be flagged.'];
    }
    q('UPDATE queue_entry SET status = "expired", expiry_time = NOW()
        WHERE queue_entry_id = :id', [':id' => $queueEntryId]);
    q('INSERT INTO service_log (queue_entry_id, counter_id, status)
       VALUES (:id, :c, "no_show")',
      [':id' => $queueEntryId, ':c' => $entry['counter_id']]);
    recalculate_predictions($entry['service_type']);
    return ['ok' => true];
}

/**
 * Today's figures for one counter (served, average time, waiting).
 * FIND: counter statistics
 */
function counter_stats(int $counterId): array
{
    $counter = q_one('SELECT service_type FROM service_counter WHERE counter_id = :c',
                     [':c' => $counterId]);
    $serviceType = $counter['service_type'] ?? '';

    return [
        'waiting' => (int)q_val(
            'SELECT COUNT(*) FROM queue_entry
              WHERE service_type = :st AND status IN ("waiting","checked_in")',
            [':st' => $serviceType], 0),
        'served_today' => (int)q_val(
            'SELECT COUNT(*) FROM queue_entry
              WHERE counter_id = :c AND status = "completed"
                AND DATE(service_end_time) = CURDATE()',
            [':c' => $counterId], 0),
        'avg_service_minutes' => round((float)q_val(
            'SELECT AVG(service_duration)/60 FROM service_log
              WHERE counter_id = :c AND status = "completed"
                AND service_duration IS NOT NULL',
            [':c' => $counterId], 0), 1),
    ];
}
