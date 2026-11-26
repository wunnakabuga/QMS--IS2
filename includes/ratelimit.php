<?php
/**
 * FILE: includes/ratelimit.php
 * WHAT IT DOES: A simple database-backed rate limiter (anti-spam). Used on
 * the join queue form (join.php) and the feedback form (feedback.php).
 *
 * FIND: rate limiting, spam protection, too many attempts
 *
 * Prevents one person flooding the queue with tickets from the join form.
 * Counts are kept in a table rather than the session, because a session is
 * trivially discarded by clearing cookies.
 */
require_once __DIR__ . '/db.php';

/** Create the table on first use so no extra migration step is needed. */
function rate_limit_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS rate_limit (
            rate_key   VARCHAR(120) NOT NULL,
            event_time DATETIME     NOT NULL,
            INDEX idx_rate (rate_key, event_time)
         ) ENGINE=InnoDB'
    );
    $done = true;
}

/**
 * RATE LIMIT CHECK. Record an attempt and report whether the caller is over
 * the limit, e.g. at most N tickets from one IP address in 10 minutes.
 * FIND: rate limiting, too many attempts, block spam
 *
 * @param string $key     What is being limited, e.g. "join:197.248.1.1"
 * @param int    $limit   Attempts permitted within the window
 * @param int    $seconds Length of the window
 * @return array{allowed:bool, used:int, retry_after:int}
 */
function rate_limit_hit(string $key, int $limit, int $seconds): array
{
    rate_limit_init();

    // Housekeeping: drop anything older than a day.
    if (random_int(1, 20) === 1) {
        q('DELETE FROM rate_limit WHERE event_time < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }

    $used = (int)q_val(
        'SELECT COUNT(*) FROM rate_limit
          WHERE rate_key = :k AND event_time > DATE_SUB(NOW(), INTERVAL :s SECOND)',
        [':k' => $key, ':s' => $seconds],
        0
    );

    if ($used >= $limit) {
        $oldest = q_val(
            'SELECT MIN(event_time) FROM rate_limit
              WHERE rate_key = :k AND event_time > DATE_SUB(NOW(), INTERVAL :s SECOND)',
            [':k' => $key, ':s' => $seconds]
        );
        $retry = $oldest ? max(1, $seconds - (time() - strtotime((string)$oldest))) : $seconds;
        return ['allowed' => false, 'used' => $used, 'retry_after' => (int)$retry];
    }

    q('INSERT INTO rate_limit (rate_key, event_time) VALUES (:k, NOW())',
      [':k' => $key]);

    return ['allowed' => true, 'used' => $used + 1, 'retry_after' => 0];
}

/**
 * Best-effort visitor IP address (respects a reverse proxy if present).
 * Used as the key for rate limiting.
 * FIND: IP address
 */
function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $value = explode(',', (string)$_SERVER[$header])[0];
            $value = trim($value);
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }
    }
    return 'unknown';
}

/** Friendly wording for how long to wait, e.g. "about 3 minutes". */
function retry_phrase(int $seconds): string
{
    if ($seconds < 60) {
        return 'about ' . max(1, $seconds) . ' seconds';
    }
    return 'about ' . (int)ceil($seconds / 60) . ' minutes';
}
