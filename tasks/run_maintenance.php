<?php
/**
 * Scheduled maintenance task.
 *
 * Without this, ticket expiry (FR-12) and reminder dispatch (FR-10) only run
 * when somebody happens to load a page. That is acceptable for a busy centre
 * but not defensible: if nobody touches the system for an hour, grace periods
 * silently fail to enforce and reminders never go out.
 *
 * Run this every minute from Windows Task Scheduler or cron:
 *
 *   Windows:  C:\xampp\php\php.exe C:\xampp\htdocs\qms\tasks\run_maintenance.php
 *   Linux:    * * * * * /usr/bin/php /opt/lampp/htdocs/qms/tasks/run_maintenance.php
 *
 * It is safe to run concurrently with normal traffic and safe to run when
 * there is nothing to do.
 */

// Refuse to run from a browser: this is a command-line task.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This task must be run from the command line.\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../modules/service_delivery.php';
require_once __DIR__ . '/../modules/dispatch.php';
require_once __DIR__ . '/../modules/prediction.php';

$started = microtime(true);
$stamp   = date('Y-m-d H:i:s');
$quiet   = in_array('--quiet', $argv, true);

function out(string $line, bool $quiet): void
{
    if (!$quiet) {
        echo $line . PHP_EOL;
    }
}

try {
    // 1. Expire tickets whose grace period has lapsed (FR-12).
    $expired = expire_lapsed_tickets();

    // 2. Dispatch reminders for clients whose turn is approaching (FR-10).
    $reminders = dispatch_due_reminders();

    // 3. Refresh predictions so estimates do not go stale while the system
    //    is idle (FR-05).
    $recalculated = 0;
    foreach (recalculate_all_predictions() as $service => $result) {
        $recalculated += (int)($result['updated'] ?? 0);
    }

    $elapsed = round((microtime(true) - $started) * 1000);

    out(sprintf(
        '[%s] expired=%d reminders=%d predictions=%d (%dms)',
        $stamp, $expired, $reminders, $recalculated, $elapsed
    ), $quiet);

    // Keep a rolling log so the task can be audited without watching it run.
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/maintenance.log';
    @file_put_contents($logFile, sprintf(
        "[%s] expired=%d reminders=%d predictions=%d (%dms)\n",
        $stamp, $expired, $reminders, $recalculated, $elapsed
    ), FILE_APPEND);

    // Trim the log so it cannot grow without bound.
    if (is_file($logFile) && filesize($logFile) > 1048576) {
        $lines = file($logFile);
        @file_put_contents($logFile, implode('', array_slice($lines, -2000)));
    }

    exit(0);

} catch (Throwable $ex) {
    fwrite(STDERR, sprintf("[%s] maintenance failed: %s\n", $stamp, $ex->getMessage()));
    exit(1);
}
