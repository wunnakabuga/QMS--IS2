<?php
/**
 * Create a congested-queue scenario for demonstrating load balancing.
 *
 * The demo seed produces a balanced floor, which is the normal state and so
 * shows nothing: load balancing only appears when one queue is backed up
 * while a counter elsewhere sits idle. This script arranges exactly that
 * situation so it can be shown deliberately rather than waited for.
 *
 * What it does:
 *   - clears the queue of the service type whose counters will be idle
 *   - adds enough clients to one other service type to push it past the
 *     congestion threshold
 *   - reports which counters are now idle and what each is being offered
 *
 * Run:  php tests/seed_congestion.php
 * Then: open admin/balancing.php, and the counter console of an idle counter.
 *
 * Nothing here is destructive beyond marking the idle service's waiting
 * clients as completed, which is what would have happened anyway had they
 * been served.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../modules/queue_registration.php';
require_once __DIR__ . '/../modules/load_balancing.php';
require_once __DIR__ . '/../modules/settings.php';

function out(string $s = ''): void
{
    echo $s . PHP_EOL;
}

out('Queue Management System — load balancing demonstration setup');
out(str_repeat('-', 62));

if (!overflow_is_enabled()) {
    out('Load balancing is currently switched off, so nothing will be');
    out('offered. Switch it on under Admin -> Load balancing -> Rules,');
    out('then run this again.');
    out('');
}

// Pick the busiest and quietest service types by number of open counters,
// so that the congested queue is the one with least capacity to absorb it.
$capacity = q_all(
    'SELECT service_type,
            SUM(status = "open") AS open_counters,
            COUNT(*)             AS total
       FROM service_counter
      GROUP BY service_type
     HAVING open_counters > 0
      ORDER BY open_counters ASC, service_type'
);

if (count($capacity) < 2) {
    out('At least two service types need an open counter for this');
    out('demonstration: one to be congested, one to stand idle.');
    out('Open more counters under Admin -> Service counters.');
    exit(1);
}

$target = $capacity[0]['service_type'];                        // fewest counters
$idleOne = $capacity[count($capacity) - 1]['service_type'];    // most counters

out("Congesting:  $target  ("
    . (int)$capacity[0]['open_counters'] . ' open counter'
    . ((int)$capacity[0]['open_counters'] === 1 ? '' : 's') . ')');
out("Freeing up:  $idleOne  ("
    . (int)$capacity[count($capacity) - 1]['open_counters'] . ' open counters)');
out('');

// Clear the queue that will be left idle.
$cleared = q('UPDATE queue_entry
                 SET status = "completed", service_end_time = NOW(),
                     service_start_time = COALESCE(service_start_time, join_time)
               WHERE service_type = :st
                 AND status IN ("waiting","checked_in")',
             [':st' => $idleOne]);
out("Cleared the $idleOne queue so its counters fall idle.");

// Add enough clients to push the target past the threshold.
$openTarget = max(1, (int)$capacity[0]['open_counters']);
$needed = (int)ceil(overflow_threshold() * $openTarget) + 2;
$existing = (int)q_val('SELECT COUNT(*) FROM queue_entry
                         WHERE service_type = :st
                           AND status IN ("waiting","checked_in")',
                       [':st' => $target], 0);
$toAdd = max(0, $needed - $existing);

out("Threshold is " . overflow_threshold() . " per counter, so $target needs "
    . "$needed waiting; it has $existing.");

$added = 0;
for ($i = 0; $i < $toAdd; $i++) {
    $r = join_queue(
        'Demo Client ' . ($i + 1),
        '07' . str_pad((string)random_int(10000000, 99999999), 8, '0'),
        null,
        $target
    );
    if (!empty($r['ok'])) {
        $added++;
    } else {
        out('  could not add a client: ' . ($r['error'] ?? 'unknown'));
        break;
    }
}
out("Added $added client" . ($added === 1 ? '' : 's') . " to $target.");
out('');

// Report the resulting state.
out('Floor load now');
out(str_repeat('-', 62));
printf("  %-22s %8s %8s %10s  %s\n",
       'Service type', 'Waiting', 'Open', 'Per counter', 'State');
foreach (service_load_snapshot() as $l) {
    printf("  %-22s %8d %8d %10s  %s\n",
        $l['service_type'], $l['waiting'], $l['open_counters'],
        $l['load_per_counter'] === null ? '-' : $l['load_per_counter'],
        $l['unstaffed'] ? 'UNSTAFFED' : ($l['congested'] ? 'CONGESTED' : 'normal'));
}

out('');
out('Idle counters and what each is offered');
out(str_repeat('-', 62));
$any = false;
foreach (q_all('SELECT counter_id, counter_name, service_type, overflow_enabled
                  FROM service_counter WHERE status = "open"
                 ORDER BY counter_id') as $c) {
    $id = (int)$c['counter_id'];
    if (!counter_is_idle($id)) {
        continue;
    }
    $any = true;
    $rec = overflow_recommendation($id);
    printf("  %-12s (%s)\n", $c['counter_name'], $c['service_type']);
    if ((int)$c['overflow_enabled'] !== 1) {
        out('      not cleared to take other services');
    } elseif ($rec) {
        out('      offered: ' . $rec['service_type']
            . ' — ' . $rec['waiting'] . ' waiting');
        out('      console: staff/counter.php?counter=' . $id);
    } else {
        out('      nothing congested to help with');
    }
}
if (!$any) {
    out('  No counter is idle. A counter is idle only when it is open, has');
    out('  no client in front of it, and has nobody waiting for its own');
    out('  service. Complete whatever is at the ' . $idleOne . ' counters');
    out('  and run this again.');
}

out('');
out(str_repeat('-', 62));
out('Next: open Admin -> Load balancing to see the floor, then open the');
out('counter console listed above and use the overflow button.');
