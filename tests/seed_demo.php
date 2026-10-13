<?php
/**
 * Populate the database with a plausible day of activity so that the
 * dashboard, reports and prediction accuracy figures have something to
 * show during demonstration and evaluation.
 *
 * Run:  php tests/seed_demo.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../modules/queue_registration.php';
require_once __DIR__ . '/../modules/service_delivery.php';

mt_srand(20260921);

$names = [
    'Mary Atieno', 'Peter Otieno', 'Sara Kimani', 'David Mutiso', 'Linet Nasieku',
    'Joseph Kariuki', 'Grace Wambui', 'Samuel Kiptoo', 'Faith Chelangat',
    'Brian Omondi', 'Cynthia Njeri', 'Dennis Barasa', 'Esther Akinyi',
    'Felix Mwangi', 'Gladys Cherono', 'Henry Ouma', 'Irene Wairimu',
    'James Kimutai', 'Kevin Maina', 'Lucy Adhiambo', 'Moses Lelei',
    'Nancy Wanjiku', 'Oscar Juma', 'Priscilla Chebet', 'Quentin Mbugua',
    'Rose Nyambura', 'Simon Rotich', 'Tabitha Muthoni', 'Victor Kiplagat',
    'Winnie Anyango',
];
$services = ['ID Registration', 'Birth Certificate', 'Business Permit', 'Licence Renewal'];
$counterFor = [
    'ID Registration'   => [2, 3],
    'Birth Certificate' => [1],
    'Business Permit'   => [4],
    'Licence Renewal'   => [4],
];

echo "Clearing transactional data...\n";
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['notification', 'service_log', 'queue_entry'] as $t) {
    db()->exec("TRUNCATE TABLE $t");
}
db()->exec('DELETE FROM user WHERE role = "client"');
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

// Open every counter so all services are available.
db()->exec('UPDATE service_counter SET status = "open"');
db()->exec('UPDATE service_counter SET assigned_staff_id = 2 WHERE counter_id = 4
            AND assigned_staff_id IS NULL');

// ------------------------------------------------- historical completions
echo "Creating historical service records...\n";
$seq = 0;
$historical = 0;

for ($dayOffset = 3; $dayOffset >= 0; $dayOffset--) {
    $lastHour = $dayOffset === 0 ? max(8, min(16, (int)date('G') - 1)) : 16;
    foreach (range(8, $lastHour) as $hour) {
        $arrivals = (int)round((mt_rand(30, 90) / 100) * (in_array($hour, [9,10,11,14], true) ? 7 : 4));
        for ($i = 0; $i < $arrivals; $i++) {
            $service = $services[array_rand($services)];
            $counter = $counterFor[$service][array_rand($counterFor[$service])];
            $name    = $names[array_rand($names)];
            $phone   = '+2547' . str_pad((string)mt_rand(10000000, 99999999), 8, '0');

            $joinTs = strtotime("-{$dayOffset} days " . sprintf('%02d:%02d:00', $hour, mt_rand(0, 59)));
            $waitMinutes  = max(1, (int)round(mt_rand(300, 3200) / 100));
            $serviceStart = $joinTs + $waitMinutes * 60;
            $duration     = mt_rand(180, 900);          // 3 - 15 minutes
            $serviceEnd   = $serviceStart + $duration;

            // Predicted value sits near the actual, with realistic error:
            // mostly small, with an occasional larger miss when conditions
            // shifted after the prediction was issued.
            $error = mt_rand(-40, 40) / 10;
            if (mt_rand(1, 100) <= 22) {
                $error += (mt_rand(0, 1) ? 1 : -1) * mt_rand(45, 130) / 10;
            }
            $predicted = max(0, round($waitMinutes + $error, 2));

            q('INSERT INTO user (full_name, phone_number, role, created_at)
               VALUES (:n, :p, "client", FROM_UNIXTIME(:t))',
              [':n' => $name, ':p' => $phone, ':t' => $joinTs]);
            $userId = (int)db()->lastInsertId();

            $seq++;
            $number = sprintf('H%d-%03d', $dayOffset, $seq);

            q('INSERT INTO queue_entry
                 (user_id, counter_id, queue_number, service_type, status,
                  join_time, check_in_time, called_time, service_start_time,
                  service_end_time, predicted_wait_time, prediction_source,
                  delivery_channel)
               VALUES (:u, :c, :n, :st, "completed",
                  FROM_UNIXTIME(:jt), FROM_UNIXTIME(:ct), FROM_UNIXTIME(:cl),
                  FROM_UNIXTIME(:ss), FROM_UNIXTIME(:se), :pw, "model", :ch)',
              [':u' => $userId, ':c' => $counter, ':n' => $number, ':st' => $service,
               ':jt' => $joinTs, ':ct' => $joinTs + 120, ':cl' => $serviceStart - 30,
               ':ss' => $serviceStart, ':se' => $serviceEnd, ':pw' => $predicted,
               ':ch' => mt_rand(1, 100) <= 25 ? 'sms' : 'e-ticket']);
            $entryId = (int)db()->lastInsertId();

            q('INSERT INTO service_log (queue_entry_id, counter_id, status,
                                        event_time, service_duration)
               VALUES (:id, :c, "completed", FROM_UNIXTIME(:t), :d)',
              [':id' => $entryId, ':c' => $counter, ':t' => $serviceEnd, ':d' => $duration]);

            q('INSERT INTO notification (queue_entry_id, channel, sent_time,
                                         delivery_status, message_content)
               VALUES (:id, "e-ticket", FROM_UNIXTIME(:t), "delivered", "Ticket issued")',
              [':id' => $entryId, ':t' => $joinTs]);

            $historical++;
        }
    }
}
echo "  {$historical} historical entries created.\n";

// ------------------------------------------------------- a few no-shows
echo "Creating no-show records...\n";
$noShows = 0;
foreach (q_all('SELECT queue_entry_id, counter_id FROM queue_entry
                 ORDER BY RAND() LIMIT 9') as $row) {
    q('UPDATE queue_entry SET status = "expired", service_start_time = NULL,
             service_end_time = NULL, expiry_time = called_time
        WHERE queue_entry_id = :id', [':id' => $row['queue_entry_id']]);
    q('INSERT INTO service_log (queue_entry_id, counter_id, status, event_time)
       SELECT queue_entry_id, counter_id, "no_show", called_time FROM queue_entry
        WHERE queue_entry_id = :id', [':id' => $row['queue_entry_id']]);
    $noShows++;
}
echo "  {$noShows} no-shows created.\n";

// ------------------------------------------------------------ live queue
echo "Creating today's live queue...\n";
$live = 0;
foreach ([
    ['Mary Atieno',     '0722000111', 'ID Registration',   'e-ticket'],
    ['Peter Otieno',    '0722000222', 'ID Registration',   'e-ticket'],
    ['Sara Kimani',     '0722000333', 'Birth Certificate', 'sms'],
    ['David Mutiso',    '0722000444', 'ID Registration',   'e-ticket'],
    ['Linet Nasieku',   '0722000555', 'Business Permit',   'e-ticket'],
    ['Joseph Kariuki',  '0722000666', 'ID Registration',   'sms'],
    ['Grace Wambui',    '0722000777', 'Birth Certificate', 'e-ticket'],
    ['Samuel Kiptoo',   '0722000888', 'Licence Renewal',   'e-ticket'],
    ['Faith Chelangat', '0722000999', 'ID Registration',   'e-ticket'],
    ['Brian Omondi',    '0722001111', 'ID Registration',   'sms'],
] as $c) {
    $res = join_queue($c[0], $c[1], null, $c[2], $c[3]);
    if ($res['ok']) {
        $live++;
    } else {
        echo "  skipped {$c[0]}: {$res['error']}\n";
    }
}
echo "  {$live} live queue entries created.\n";

// Put one client in service at counter 2 so the dashboard shows activity.
$called = call_next(2);
if ($called['ok']) {
    start_service($called['entry_id']);
    echo "  Counter 2 is now serving a client.\n";
}
// And check one client in.
$toCheckIn = q_one('SELECT queue_entry_id FROM queue_entry
                     WHERE status = "waiting" ORDER BY join_time LIMIT 1');
if ($toCheckIn) {
    check_in((int)$toCheckIn['queue_entry_id']);
    echo "  One client has checked in.\n";
}

recalculate_all_predictions();

echo "\nDone.\n";
printf("  total entries   : %d\n", q_val('SELECT COUNT(*) FROM queue_entry'));
printf("  completed       : %d\n", q_val('SELECT COUNT(*) FROM queue_entry WHERE status="completed"'));
printf("  in queue now    : %d\n", q_val('SELECT COUNT(*) FROM queue_entry WHERE status IN ("waiting","checked_in")'));
printf("  service logs    : %d\n", q_val('SELECT COUNT(*) FROM service_log'));
printf("  notifications   : %d\n", q_val('SELECT COUNT(*) FROM notification'));
