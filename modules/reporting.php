<?php
/**
 * Process 6.0 — Reporting and Analytics Module
 *
 * Implements FR-18 and FR-19. Every function here reads only; no reporting
 * operation writes to the operational tables, which preserves the integrity
 * of the records used for model evaluation in Chapter 5.
 */
require_once __DIR__ . '/../includes/db.php';

/**
 * Normalise the report filters supplied by the administrator.
 *
 * @return array{from:string, to:string, service:string, counter:int}
 */
function report_filters(array $input): array
{
    $from = $input['from'] ?? '';
    $to   = $input['to'] ?? '';

    // Default to the trailing 30 days.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y-m-d', strtotime('-29 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }
    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }

    return [
        'from'    => $from,
        'to'      => $to,
        'service' => trim((string)($input['service'] ?? '')),
        'counter' => (int)($input['counter'] ?? 0),
    ];
}

/** Build the shared WHERE clause and bindings for a filtered report. */
function report_where(array $f, string $alias = 'qe'): array
{
    $sql = " WHERE DATE($alias.join_time) BETWEEN :from AND :to ";
    $params = [':from' => $f['from'], ':to' => $f['to']];

    if ($f['service'] !== '') {
        $sql .= " AND $alias.service_type = :service ";
        $params[':service'] = $f['service'];
    }
    if ($f['counter'] > 0) {
        $sql .= " AND $alias.counter_id = :counter ";
        $params[':counter'] = $f['counter'];
    }
    return [$sql, $params];
}

/** Headline figures for the filtered period. */
function report_summary(array $f): array
{
    [$where, $params] = report_where($f);

    $row = q_one(
        'SELECT
            COUNT(*)                                            AS total_entries,
            SUM(qe.status = "completed")                        AS served,
            SUM(qe.status = "expired")                          AS no_shows,
            SUM(qe.status = "cancelled")                        AS cancelled,
            SUM(qe.delivery_channel = "sms")                    AS sms_tickets,
            AVG(CASE WHEN qe.status = "completed"
                      AND qe.service_start_time IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, qe.join_time,
                                        qe.service_start_time)/60 END) AS avg_actual_wait,
            AVG(CASE WHEN qe.status = "completed"
                     THEN qe.predicted_wait_time END)           AS avg_predicted_wait
         FROM queue_entry qe' . $where,
        $params
    ) ?: [];

    // Accuracy is computed only over model-produced predictions.
    [$accWhere, $accParams] = report_where($f);
    $accWhere .= ' AND qe.status = "completed"
                   AND qe.prediction_source = "model"
                   AND qe.predicted_wait_time IS NOT NULL
                   AND qe.service_start_time IS NOT NULL ';

    $acc = q_one(
        'SELECT COUNT(*) AS n,
                AVG(ABS(qe.predicted_wait_time -
                        TIMESTAMPDIFF(SECOND, qe.join_time, qe.service_start_time)/60)) AS mae,
                AVG(ABS(qe.predicted_wait_time -
                        TIMESTAMPDIFF(SECOND, qe.join_time, qe.service_start_time)/60) <= 5) * 100
                    AS within_5
           FROM queue_entry qe' . $accWhere,
        $accParams
    ) ?: [];

    $total = (int)($row['total_entries'] ?? 0);
    $noShows = (int)($row['no_shows'] ?? 0);

    return [
        'total_entries'      => $total,
        'served'             => (int)($row['served'] ?? 0),
        'no_shows'           => $noShows,
        'cancelled'          => (int)($row['cancelled'] ?? 0),
        'sms_tickets'        => (int)($row['sms_tickets'] ?? 0),
        'no_show_rate'       => $total > 0 ? round($noShows / $total * 100, 1) : null,
        'avg_actual_wait'    => $row['avg_actual_wait'] !== null
                                  ? round((float)$row['avg_actual_wait'], 1) : null,
        'avg_predicted_wait' => $row['avg_predicted_wait'] !== null
                                  ? round((float)$row['avg_predicted_wait'], 1) : null,
        'accuracy_n'         => (int)($acc['n'] ?? 0),
        'mae'                => $acc['mae'] !== null ? round((float)$acc['mae'], 2) : null,
        'within_5'           => $acc['within_5'] !== null
                                  ? round((float)$acc['within_5'], 1) : null,
    ];
}

/** Average actual waiting time grouped by service type. */
function report_by_service(array $f): array
{
    [$where, $params] = report_where($f);
    return q_all(
        'SELECT qe.service_type,
                COUNT(*) AS entries,
                SUM(qe.status = "completed") AS served,
                AVG(CASE WHEN qe.status = "completed"
                          AND qe.service_start_time IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, qe.join_time,
                                            qe.service_start_time)/60 END) AS avg_wait,
                AVG(CASE WHEN qe.status = "completed"
                         THEN qe.predicted_wait_time END) AS avg_predicted
           FROM queue_entry qe' . $where . '
          GROUP BY qe.service_type
          ORDER BY avg_wait DESC',
        $params
    );
}

/** Demand by hour of day across the filtered period. */
function report_by_hour(array $f): array
{
    [$where, $params] = report_where($f);
    $rows = q_all(
        'SELECT HOUR(qe.join_time) AS h, COUNT(*) AS n
           FROM queue_entry qe' . $where . '
          GROUP BY HOUR(qe.join_time)',
        $params
    );
    $byHour = [];
    foreach ($rows as $r) {
        $byHour[(int)$r['h']] = (int)$r['n'];
    }
    return $byHour;
}

/** Demand by day of week and hour — the heat map in Wireframe 10. */
function report_heatmap(array $f): array
{
    [$where, $params] = report_where($f);
    $rows = q_all(
        'SELECT DAYOFWEEK(qe.join_time) AS dow, HOUR(qe.join_time) AS h,
                COUNT(*) AS n
           FROM queue_entry qe' . $where . '
          GROUP BY DAYOFWEEK(qe.join_time), HOUR(qe.join_time)',
        $params
    );
    $grid = [];
    foreach ($rows as $r) {
        // MySQL DAYOFWEEK: 1 = Sunday. Shift so 1 = Monday.
        $dow = ((int)$r['dow'] + 5) % 7;
        $grid[$dow][(int)$r['h']] = (int)$r['n'];
    }
    return $grid;
}

/** Counter performance over the filtered period. */
function report_by_counter(array $f): array
{
    [$where, $params] = report_where($f);
    return q_all(
        'SELECT sc.counter_id, sc.counter_name, sc.service_type, sc.status,
                u.full_name AS staff_name,
                COUNT(qe.queue_entry_id) AS entries,
                SUM(qe.status = "completed") AS served,
                SUM(qe.status = "expired")   AS no_shows,
                AVG(CASE WHEN qe.status = "completed"
                         THEN TIMESTAMPDIFF(SECOND, qe.service_start_time,
                                            qe.service_end_time)/60 END) AS avg_service
           FROM service_counter sc
      LEFT JOIN user u ON u.user_id = sc.assigned_staff_id
      LEFT JOIN queue_entry qe ON qe.counter_id = sc.counter_id
                AND DATE(qe.join_time) BETWEEN :from AND :to
          GROUP BY sc.counter_id, sc.counter_name, sc.service_type, sc.status, u.full_name
          ORDER BY sc.counter_id',
        [':from' => $f['from'], ':to' => $f['to']]
    );
}

/**
 * The service log extract: one row per queue entry with predicted, actual
 * and variance. This is the table exported for Chapter 5 analysis.
 */
function report_entries(array $f, int $limit = 0, int $offset = 0): array
{
    [$where, $params] = report_where($f);
    $sql =
        'SELECT qe.queue_entry_id, qe.queue_number, qe.service_type, qe.status,
                qe.join_time, qe.service_start_time, qe.service_end_time,
                qe.predicted_wait_time, qe.prediction_source, qe.delivery_channel,
                sc.counter_name,
                TIMESTAMPDIFF(SECOND, qe.join_time, qe.service_start_time)/60
                    AS actual_wait,
                TIMESTAMPDIFF(SECOND, qe.service_start_time, qe.service_end_time)/60
                    AS service_minutes
           FROM queue_entry qe
      LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id' . $where . '
          ORDER BY qe.join_time DESC, qe.queue_entry_id DESC';

    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    }
    return q_all($sql, $params);
}

/** Row count for the extract, used for pagination. */
function report_entries_count(array $f): int
{
    [$where, $params] = report_where($f);
    return (int)q_val('SELECT COUNT(*) FROM queue_entry qe' . $where, $params, 0);
}
