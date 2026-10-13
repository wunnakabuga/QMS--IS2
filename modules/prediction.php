<?php
/**
 * Process 2.0 — Waiting Time Prediction Module
 *
 * Implements FR-04, FR-05 and FR-06 and the design set out in Chapter 4,
 * Section 4.6. Five predictor features are assembled from live queue state
 * and trailing service history, passed to the trained Random Forest
 * Regression model, and written back to the affected queue entries.
 * Where the model is unavailable the deterministic queueing-theory
 * fallback in fallback_estimate() is used instead.
 */
require_once __DIR__ . '/../includes/db.php';

/** Number of counters currently open for a service type. */
function open_counter_count(string $serviceType): int
{
    return (int)q_val(
        'SELECT COUNT(*) FROM service_counter
          WHERE service_type = :st AND status = "open"',
        [':st' => $serviceType],
        0
    );
}

/**
 * Assemble the shared (queue-level) predictor features for a service type.
 * Per-entry queue_length is added later, because it differs by position.
 */
function queue_features(string $serviceType): array
{
    // arrival_rate: entries created in the trailing 60 minutes
    $arrivalRate = (float)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE service_type = :st
            AND join_time >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)',
        [':st' => $serviceType],
        0
    );

    // avg_service_time: mean completed service duration in minutes
    $avgServiceTime = q_val(
        'SELECT AVG(sl.service_duration) / 60
           FROM service_log sl
           JOIN queue_entry qe ON qe.queue_entry_id = sl.queue_entry_id
          WHERE sl.status = "completed"
            AND sl.service_duration IS NOT NULL
            AND qe.service_type = :st
            AND sl.event_time >= DATE_SUB(NOW(), INTERVAL 14 DAY)',
        [':st' => $serviceType]
    );
    $avgServiceTime = ($avgServiceTime === null || (float)$avgServiceTime <= 0)
        ? 6.0                       // documented default before history exists
        : round((float)$avgServiceTime, 2);

    $counters = max(1, open_counter_count($serviceType));

    // service_rate: clients served per counter per hour
    $serviceRate = round(60.0 / $avgServiceTime, 2);

    return [
        'arrival_rate'     => $arrivalRate,
        'service_rate'     => $serviceRate,
        'avg_service_time' => $avgServiceTime,
        'time_of_day'      => (int)date('G'),
        'open_counters'    => $counters,
    ];
}

/**
 * Deterministic queueing-theory fallback (FR-06).
 * Expected wait = (entries ahead x mean service time) / open counters.
 */
function fallback_estimate(int $ahead, float $avgServiceTime, int $counters): float
{
    $counters = max(1, $counters);
    return round(($ahead * $avgServiceTime) / $counters, 2);
}

/**
 * Invoke the trained Random Forest model for a batch of feature rows.
 * Returns an array of predictions, or null when the model is unreachable.
 */
function model_predict(array $rows): ?array
{
    if (!is_file(MODEL_FILE) || !is_file(PREDICT_SCRIPT)) {
        return null;
    }
    $payload = json_encode(['rows' => $rows]);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    // escapeshellarg is used for the interpreter as well as the script,
    // because on Windows both may contain spaces and backslashes
    // (for example C:\Users\Name\AppData\Local\Programs\Python\python.exe).
    $cmd = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg(PREDICT_SCRIPT);
    if (PHP_OS_FAMILY === 'Windows') {
        // cmd.exe strips the outer quotes from a quoted command unless the
        // whole string is wrapped again.
        $cmd = '"' . $cmd . '"';
    }
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return null;
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    stream_set_timeout($pipes[1], PREDICTION_TIMEOUT);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode((string)$out, true);
    if (!is_array($decoded) || !isset($decoded['predictions'])
        || count($decoded['predictions']) !== count($rows)) {
        return null;
    }
    return $decoded['predictions'];
}

/**
 * Recalculate predicted waiting times for every outstanding entry of a
 * service type. Called on every queue event (FR-05).
 *
 * @return array{updated:int, source:string}
 */
function recalculate_predictions(string $serviceType): array
{
    $base = queue_features($serviceType);

    $entries = q_all(
        'SELECT queue_entry_id
           FROM queue_entry
          WHERE service_type = :st
            AND status IN ("waiting","checked_in")
          ORDER BY join_time ASC, queue_entry_id ASC',
        [':st' => $serviceType]
    );
    if (!$entries) {
        return ['updated' => 0, 'source' => 'none'];
    }

    // Build one feature row per entry; queue_length is the number ahead.
    $rows = [];
    foreach ($entries as $i => $entry) {
        $rows[] = [
            'queue_length'     => $i,
            'arrival_rate'     => $base['arrival_rate'],
            'service_rate'     => $base['service_rate'],
            'avg_service_time' => $base['avg_service_time'],
            'time_of_day'      => $base['time_of_day'],
        ];
    }

    $predictions = model_predict($rows);
    $source = $predictions === null ? 'fallback' : 'model';

    if ($predictions === null) {
        $predictions = [];
        foreach ($entries as $i => $entry) {
            $predictions[] = fallback_estimate(
                $i, $base['avg_service_time'], $base['open_counters']
            );
        }
    }

    $sql = 'UPDATE queue_entry
               SET predicted_wait_time = :p,
                   prediction_source   = :src,
                   scheduled_time      = DATE_ADD(NOW(), INTERVAL :mins MINUTE)
             WHERE queue_entry_id = :id';
    $stmt = db()->prepare($sql);
    foreach ($entries as $i => $entry) {
        $value = max(0.0, round((float)$predictions[$i], 2));
        $stmt->execute([
            ':p'    => $value,
            ':src'  => $source,
            ':mins' => (int)round($value),
            ':id'   => $entry['queue_entry_id'],
        ]);
    }

    return ['updated' => count($entries), 'source' => $source];
}

/** Recalculate every service type at once. */
function recalculate_all_predictions(): array
{
    $types = q_all('SELECT DISTINCT service_type FROM queue_entry
                     WHERE status IN ("waiting","checked_in")');
    $result = [];
    foreach ($types as $t) {
        $result[$t['service_type']] = recalculate_predictions($t['service_type']);
    }
    return $result;
}

/** Derived attribute: 1-based position of an entry within its queue. */
function queue_position(int $queueEntryId): ?int
{
    $entry = q_one(
        'SELECT service_type, join_time, status
           FROM queue_entry WHERE queue_entry_id = :id',
        [':id' => $queueEntryId]
    );
    if (!$entry || !in_array($entry['status'], ['waiting', 'checked_in'], true)) {
        return null;
    }
    $ahead = (int)q_val(
        'SELECT COUNT(*) FROM queue_entry
          WHERE service_type = :st
            AND status IN ("waiting","checked_in")
            AND join_time < :jt',
        [':st' => $entry['service_type'], ':jt' => $entry['join_time']],
        0
    );
    return $ahead + 1;
}
