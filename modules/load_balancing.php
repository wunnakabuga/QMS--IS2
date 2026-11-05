<?php
/**
 * Process 5.0 — Counter Load Balancing Module
 *
 * Implements FR-21 and FR-22. Public service centres allocate counters to
 * service types in advance, which means a counter can sit idle while a
 * queue for a different service grows beside it. The centre has the
 * capacity to clear that queue; what it lacks is a mechanism to move the
 * capacity to where the demand is.
 *
 * This module supplies that mechanism. It measures the load on every
 * service type, identifies counters that are idle because their own queue
 * is empty, and allows such a counter to take the longest-waiting client
 * from a congested service type instead of waiting for its own.
 *
 * Two design decisions are worth stating, because both were deliberate:
 *
 *   1. A counter never takes overflow while clients wait for its own
 *      service. Its own queue is always served first. Overflow uses spare
 *      capacity; it does not redistribute scarcity.
 *
 *   2. Overflow is offered to the clerk rather than imposed. A clerk may
 *      not be trained or equipped for every service, and only the person
 *      at the counter knows that. An automatic mode exists and is off by
 *      default; where it is enabled the fall-through happens silently
 *      inside call_next().
 *
 * Every overflow assignment is flagged on the queue entry and written to
 * the service log, so the benefit of the mechanism can be measured rather
 * than asserted.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/prediction.php';

/** Queue depth per open counter at which a service type counts as congested. */
function overflow_threshold(): float
{
    return max(1.0, (float)setting_get('overflow_threshold', '3'));
}

/** Minimum number of clients waiting before overflow is considered at all. */
function overflow_min_waiting(): int
{
    return max(1, (int)setting_get('overflow_min_waiting', '2'));
}

/** Master switch for the whole mechanism. */
function overflow_is_enabled(): bool
{
    return (string)setting_get('overflow_enabled', '1') === '1';
}

/** Whether call_next() falls through to overflow without a clerk's click. */
function overflow_is_automatic(): bool
{
    return (string)setting_get('overflow_auto', '0') === '1';
}

/**
 * Load on every service type that currently has either a queue or an open
 * counter, most congested first.
 *
 * load_per_counter is the queue depth divided by the number of open
 * counters, which is the figure that matters: ten clients across four
 * counters is comfortable, ten across one is not. A service type with a
 * queue and no open counter is reported with a null load and flagged
 * unstaffed, since dividing by zero would otherwise hide the worst case
 * of all.
 */
function service_load_snapshot(): array
{
    $rows = q_all(
        'SELECT t.service_type,
                t.waiting,
                t.longest_wait_minutes,
                COALESCE(c.open_counters, 0)  AS open_counters,
                COALESCE(c.total_counters, 0) AS total_counters
           FROM (
                SELECT service_type,
                       COUNT(*) AS waiting,
                       MAX(TIMESTAMPDIFF(MINUTE, join_time, NOW()))
                           AS longest_wait_minutes
                  FROM queue_entry
                 WHERE status IN ("waiting","checked_in")
                 GROUP BY service_type
           ) t
           LEFT JOIN (
                SELECT service_type,
                       SUM(status = "open") AS open_counters,
                       COUNT(*)             AS total_counters
                  FROM service_counter
                 GROUP BY service_type
           ) c ON c.service_type = t.service_type

          UNION

         SELECT c.service_type, 0, 0,
                SUM(c.status = "open"), COUNT(*)
           FROM service_counter c
          WHERE c.service_type NOT IN (
                SELECT DISTINCT service_type FROM queue_entry
                 WHERE status IN ("waiting","checked_in"))
          GROUP BY c.service_type'
    );

    $out = [];
    foreach ($rows as $r) {
        $waiting = (int)$r['waiting'];
        $open    = (int)$r['open_counters'];
        $load    = $open > 0 ? $waiting / $open : null;

        $out[] = [
            'service_type'         => $r['service_type'],
            'waiting'              => $waiting,
            'open_counters'        => $open,
            'total_counters'       => (int)$r['total_counters'],
            'longest_wait_minutes' => (int)$r['longest_wait_minutes'],
            'load_per_counter'     => $load === null ? null : round($load, 2),
            'unstaffed'            => $open === 0 && $waiting > 0,
            'congested'            => $load !== null
                                      && $waiting >= overflow_min_waiting()
                                      && $load >= overflow_threshold(),
        ];
    }

    // Unstaffed queues first, then by load. An unstaffed queue is the most
    // urgent thing on the floor and must not be sorted below a merely busy
    // one just because its load is not a number.
    usort($out, function (array $a, array $b): int {
        if ($a['unstaffed'] !== $b['unstaffed']) {
            return $a['unstaffed'] ? -1 : 1;
        }
        return ($b['load_per_counter'] ?? 0) <=> ($a['load_per_counter'] ?? 0);
    });

    return $out;
}

/**
 * True when a counter is open, has no client in front of it, and has
 * nobody waiting for its own service type. This is the only state in
 * which overflow applies.
 */
function counter_is_idle(int $counterId): bool
{
    $counter = q_one('SELECT service_type, status FROM service_counter
                       WHERE counter_id = :c', [':c' => $counterId]);
    if (!$counter || $counter['status'] !== 'open') {
        return false;
    }
    $busy = (int)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE counter_id = :c AND status IN ("called","in_service")',
        [':c' => $counterId], 0
    );
    if ($busy > 0) {
        return false;
    }
    $ownQueue = (int)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE service_type = :st AND status IN ("waiting","checked_in")',
        [':st' => $counter['service_type']], 0
    );
    return $ownQueue === 0;
}

/**
 * Congested service types an idle counter could take work from, most
 * congested first. An empty array means there is nothing useful for this
 * counter to do right now, which is a legitimate answer.
 */
function overflow_candidates(int $counterId): array
{
    if (!overflow_is_enabled()) {
        return [];
    }

    $counter = q_one('SELECT service_type, status, overflow_enabled
                        FROM service_counter WHERE counter_id = :c',
                     [':c' => $counterId]);
    if (!$counter || (int)$counter['overflow_enabled'] !== 1) {
        return [];
    }
    if (!counter_is_idle($counterId)) {
        return [];
    }

    $out = [];
    foreach (service_load_snapshot() as $load) {
        if ($load['service_type'] === $counter['service_type']) {
            continue;
        }
        if ($load['waiting'] < overflow_min_waiting()) {
            continue;
        }
        // An unstaffed queue qualifies regardless of load: nobody is
        // serving it at all, so any help is an improvement.
        if ($load['unstaffed'] || $load['congested']) {
            $out[] = $load;
        }
    }
    return $out;
}

/** The single best overflow option for a counter, or null if there is none. */
function overflow_recommendation(int $counterId): ?array
{
    $candidates = overflow_candidates($counterId);
    return $candidates ? $candidates[0] : null;
}

/**
 * FR-22: call the longest-waiting client of another service type to an
 * idle counter.
 *
 * The guards are repeated here rather than trusted from the caller,
 * because this function is reachable from the counter console, from the
 * automatic fall-through in call_next(), and from the test suite.
 */
function call_next_overflow(int $counterId, string $serviceType): array
{
    require_once __DIR__ . '/service_delivery.php';
    expire_lapsed_tickets();

    if (!overflow_is_enabled()) {
        return ['ok' => false, 'error' => 'Load balancing is switched off.'];
    }

    $counter = q_one('SELECT * FROM service_counter WHERE counter_id = :c',
                     [':c' => $counterId]);
    if (!$counter) {
        return ['ok' => false, 'error' => 'Counter not found.'];
    }
    if ($counter['status'] !== 'open') {
        return ['ok' => false, 'error' => 'This counter is closed.'];
    }
    if ((int)$counter['overflow_enabled'] !== 1) {
        return ['ok' => false,
                'error' => 'This counter is not cleared to take other services.'];
    }
    if ($serviceType === $counter['service_type']) {
        return ['ok' => false,
                'error' => 'That is this counter\'s own service — use Call next.'];
    }
    if (current_at_counter($counterId)) {
        return ['ok' => false,
                'error' => 'Complete or release the current client first.'];
    }

    // Own queue first, always.
    $ownQueue = (int)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE service_type = :st AND status IN ("waiting","checked_in")',
        [':st' => $counter['service_type']], 0
    );
    if ($ownQueue > 0) {
        return ['ok' => false,
                'error' => 'There are clients waiting for this counter\'s own '
                         . 'service. Serve them first.'];
    }

    // The target must still be worth helping at the moment of the click,
    // not merely when the page was rendered.
    $eligible = false;
    foreach (overflow_candidates($counterId) as $c) {
        if ($c['service_type'] === $serviceType) {
            $eligible = true;
            break;
        }
    }
    if (!$eligible) {
        return ['ok' => false,
                'error' => 'That queue is no longer congested enough to need help.'];
    }

    // Same ordering rule as call_next(), including the queue_entry_id
    // tiebreak, so a client moved to another counter keeps exactly the place
    // in line they would have had.
    $next = q_one(
        'SELECT queue_entry_id FROM queue_entry
          WHERE service_type = :st AND status IN ("waiting","checked_in")
          ORDER BY (status = "checked_in") DESC, join_time ASC,
                   queue_entry_id ASC
          LIMIT 1',
        [':st' => $serviceType]
    );
    if (!$next) {
        return ['ok' => false, 'error' => 'There is nobody waiting for that service.'];
    }

    $entryId = (int)$next['queue_entry_id'];
    q('UPDATE queue_entry
         SET status = "called", called_time = NOW(), counter_id = :c,
             served_via_overflow = 1,
             expiry_time = DATE_ADD(NOW(), INTERVAL :g MINUTE)
       WHERE queue_entry_id = :id',
      [':c' => $counterId, ':g' => GRACE_PERIOD_MINUTES, ':id' => $entryId]);

    q('INSERT INTO service_log (queue_entry_id, counter_id, status)
       VALUES (:id, :c, "called_overflow")',
      [':id' => $entryId, ':c' => $counterId]);

    // Both queues change shape: the one losing a client and the one whose
    // counter is now occupied.
    recalculate_predictions($serviceType);
    recalculate_predictions($counter['service_type']);

    return ['ok' => true, 'entry_id' => $entryId,
            'service_type' => $serviceType,
            'counter_name' => $counter['counter_name']];
}

/**
 * Efficiency produced by load balancing over a date range.
 *
 * The honest comparison is between overflow-served clients and clients of
 * the same service type served normally in the same window, because
 * service types differ in how long they take. Where there is nothing to
 * compare against, the figure is returned as null rather than zero.
 */
function overflow_stats(?string $from = null, ?string $to = null): array
{
    $from = $from ?: date('Y-m-d', strtotime('-29 days'));
    $to   = $to   ?: date('Y-m-d');
    $p = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];

    $served = (int)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE served_via_overflow = 1 AND status = "completed"
            AND service_end_time BETWEEN :from AND :to', $p, 0);

    $totalCompleted = (int)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE status = "completed"
            AND service_end_time BETWEEN :from AND :to', $p, 0);

    // Counter time that would otherwise have been idle.
    $minutesRecovered = round((float)q_val(
        'SELECT COALESCE(SUM(sl.service_duration), 0) / 60
           FROM service_log sl
           JOIN queue_entry qe ON qe.queue_entry_id = sl.queue_entry_id
          WHERE sl.status = "completed" AND qe.served_via_overflow = 1
            AND sl.event_time BETWEEN :from AND :to', $p, 0), 1);

    // Waiting time of overflow-served clients against their peers.
    $perType = q_all(
        'SELECT qe.service_type,
                SUM(qe.served_via_overflow = 1) AS overflow_count,
                AVG(CASE WHEN qe.served_via_overflow = 1
                    THEN TIMESTAMPDIFF(MINUTE, qe.join_time, qe.called_time)
                    END) AS overflow_wait,
                AVG(CASE WHEN qe.served_via_overflow = 0
                    THEN TIMESTAMPDIFF(MINUTE, qe.join_time, qe.called_time)
                    END) AS normal_wait
           FROM queue_entry qe
          WHERE qe.status = "completed" AND qe.called_time IS NOT NULL
            AND qe.service_end_time BETWEEN :from AND :to
          GROUP BY qe.service_type
         HAVING overflow_count > 0', $p);

    $rows = [];
    foreach ($perType as $r) {
        $ov = $r['overflow_wait'] === null ? null : round((float)$r['overflow_wait'], 1);
        $nm = $r['normal_wait']   === null ? null : round((float)$r['normal_wait'], 1);
        $rows[] = [
            'service_type'   => $r['service_type'],
            'overflow_count' => (int)$r['overflow_count'],
            'overflow_wait'  => $ov,
            'normal_wait'    => $nm,
            'saving'         => ($ov !== null && $nm !== null)
                                ? round($nm - $ov, 1) : null,
        ];
    }

    return [
        'from'               => $from,
        'to'                 => $to,
        'served_by_overflow' => $served,
        'total_completed'    => $totalCompleted,
        'share_percent'      => $totalCompleted > 0
                                ? round($served / $totalCompleted * 100, 1) : 0.0,
        'minutes_recovered'  => $minutesRecovered,
        'by_service_type'    => $rows,
    ];
}
