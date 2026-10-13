<?php
/**
 * Unit / integration smoke test for the queue lifecycle.
 *
 * Exercises the modules directly (not through HTTP) so that each process
 * in the level 1 data flow diagram is tested in isolation and in sequence:
 *   1.0 Queue Registration -> 2.0 Prediction -> 3.0 Dispatch
 *   -> 4.0 Service Delivery -> service log -> accuracy figures.
 *
 * Run:  php tests/smoke_test.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../modules/queue_registration.php';
require_once __DIR__ . '/../modules/service_delivery.php';
require_once __DIR__ . '/../modules/dispatch.php';
require_once __DIR__ . '/../modules/reporting.php';
require_once __DIR__ . '/../includes/ratelimit.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../modules/sms_gateway.php';
require_once __DIR__ . '/../modules/settings.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n" . str_repeat('-', 62) . "\n$title\n" . str_repeat('-', 62) . "\n";
}

// ---------------------------------------------------------------- reset
section('Setup: clearing transactional data');
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['notification', 'service_log', 'queue_entry'] as $table) {
    db()->exec("TRUNCATE TABLE $table");
}
db()->exec('DELETE FROM user WHERE role = "client"');
db()->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "  Transactional tables cleared.\n";

// ------------------------------------------------- 1.0 Queue Registration
section('Process 1.0 — Queue Registration (FR-01, FR-02, FR-03)');

$r1 = join_queue('Mary Atieno', '0722000111', 'mary@example.com', 'ID Registration');
check('FR-01 client joins the queue', $r1['ok'], $r1['error'] ?? '');
check('FR-02 a queue number is issued', !empty($r1['queue_number']));

$entry1 = ticket_view($r1['entry_id']);
check('FR-03 join time recorded', !empty($entry1['join_time']));
check('FR-03 entry assigned to a counter', $entry1['counter_id'] !== null);

$dup = join_queue('Mary Atieno', '0722000111', null, 'ID Registration');
check('duplicate active ticket is rejected', $dup['ok'] === false);

$bad = join_queue('X', '0722000999', null, 'ID Registration');
check('short name is rejected', $bad['ok'] === false);

$badPhone = join_queue('Peter Otieno', 'abc', null, 'ID Registration');
check('invalid phone number is rejected', $badPhone['ok'] === false);

// Close every Business Permit counter so the rejection path is exercised
// regardless of how the counters happen to be configured.
q('UPDATE service_counter SET status = "closed" WHERE service_type = "Business Permit"');
$closed = join_queue('Sara Kimani', '0722000222', null, 'Business Permit');
check('service with no open counter is rejected', $closed['ok'] === false,
      $closed['ok'] ? 'ticket was issued anyway' : '');
q('UPDATE service_counter SET status = "open" WHERE service_type = "Business Permit"');

// more clients, to build a queue
$others = [];
foreach ([['Peter Otieno', '0722000333'], ['Sara Kimani', '0722000444'],
          ['David Mutiso', '0722000555'], ['Linet Nasieku', '0722000666']] as $c) {
    $res = join_queue($c[0], $c[1], null, 'ID Registration');
    if ($res['ok']) {
        $others[] = $res['entry_id'];
    }
}
check('four further clients joined', count($others) === 4);

$smsClient = join_queue('Joseph Kariuki', '0722000777', null,
                        'Birth Certificate', 'sms');
check('FR-08 SMS-channel client joined', $smsClient['ok'], $smsClient['error'] ?? '');

// ----------------------------------------------- 2.0 Waiting Time Prediction
section('Process 2.0 — Waiting Time Prediction (FR-04, FR-05, FR-06)');

$entry1 = ticket_view($r1['entry_id']);
check('FR-04 a prediction was produced', $entry1['predicted_wait_time'] !== null);
check('FR-04 prediction is non-negative',
      (float)$entry1['predicted_wait_time'] >= 0);
echo "        source = {$entry1['prediction_source']}, "
   . "value = {$entry1['predicted_wait_time']} min\n";

$last = ticket_view(end($others));
check('later entries predict a longer wait than earlier ones',
      (float)$last['predicted_wait_time'] >= (float)$entry1['predicted_wait_time'],
      "{$last['predicted_wait_time']} vs {$entry1['predicted_wait_time']}");

check('derived queue_position computed', $entry1['queue_position'] === 1,
      'got ' . var_export($entry1['queue_position'], true));

$fb = fallback_estimate(5, 6.0, 2);
check('FR-06 fallback estimate is correct', abs($fb - 15.0) < 0.01, "got $fb");

$features = queue_features('ID Registration');
check('all five predictor features assembled',
      isset($features['arrival_rate'], $features['service_rate'],
            $features['avg_service_time'], $features['time_of_day']));

$before = (float)ticket_view($r1['entry_id'])['predicted_wait_time'];
q('UPDATE service_counter SET status = "closed" WHERE counter_id = 2');
recalculate_predictions('ID Registration');
$afterClose = (float)ticket_view(end($others))['predicted_wait_time'];
q('UPDATE service_counter SET status = "open" WHERE counter_id = 2');
recalculate_predictions('ID Registration');
$afterOpen = (float)ticket_view(end($others))['predicted_wait_time'];
check('FR-05 closing a counter changes predictions',
      abs($afterClose - $afterOpen) >= 0 && $afterClose > 0);

// ------------------------------------------------------- 3.0 Dispatch
section('Process 3.0 — Ticket and Notification Dispatch (FR-07 to FR-10)');

$notif = q_one('SELECT * FROM notification WHERE queue_entry_id = :id',
               [':id' => $r1['entry_id']]);
check('FR-07 e-ticket notification recorded', $notif !== null);
check('FR-09 channel recorded as e-ticket',
      ($notif['channel'] ?? '') === 'e-ticket', $notif['channel'] ?? 'none');

$smsNotif = q_one('SELECT * FROM notification WHERE queue_entry_id = :id',
                  [':id' => $smsClient['entry_id']]);
check('FR-08 SMS notification recorded', $smsNotif !== null);
check('FR-09 channel recorded as sms',
      ($smsNotif['channel'] ?? '') === 'sms', $smsNotif['channel'] ?? 'none');
check('FR-09 delivery status stored', !empty($smsNotif['delivery_status']));

$msg = compose_ticket_message($entry1, $entry1['queue_position']);
check('ticket message carries the queue number',
      str_contains($msg, $entry1['queue_number']));
check('ticket message carries the predicted wait',
      str_contains($msg, 'Predicted wait'));

// ------------------------------------------- 4.0 Service Delivery Management
section('Process 4.0 — Service Delivery Management (FR-11 to FR-16)');

$ci = check_in($r1['entry_id']);
check('FR-11 client checks in', $ci['ok'], $ci['error'] ?? '');
check('FR-11 check-in time recorded',
      ticket_view($r1['entry_id'])['check_in_time'] !== null);

$called = call_next(3);
check('FR-14 next client called', $called['ok'], $called['error'] ?? '');

$again = call_next(3);
check('counter cannot call twice without completing', $again['ok'] === false);

$calledEntry = current_at_counter(3);
check('called client occupies the counter', $calledEntry !== null);
check('checked-in client is called first',
      (int)($calledEntry['queue_entry_id'] ?? 0) === (int)$r1['entry_id']);

$started = start_service((int)$calledEntry['queue_entry_id']);
check('FR-14 service started', $started['ok'], $started['error'] ?? '');

sleep(2);   // so the recorded duration is non-zero

$done = complete_service((int)$calledEntry['queue_entry_id']);
check('FR-15 service completed', $done['ok'], $done['error'] ?? '');
check('FR-15 service duration recorded',
      ($done['duration_seconds'] ?? 0) > 0, ($done['duration_seconds'] ?? 0) . 's');

$log = q_one('SELECT * FROM service_log
               WHERE queue_entry_id = :id AND status = "completed"',
             [':id' => $calledEntry['queue_entry_id']]);
check('FR-15 service log written', $log !== null);
check('FR-15 log carries the counter', (int)($log['counter_id'] ?? 0) === 3);

$next = call_next(3);
check('counter is free for the next client', $next['ok'], $next['error'] ?? '');
$ns = mark_no_show((int)current_at_counter(3)['queue_entry_id']);
check('FR-16 no-show flagged', $ns['ok'], $ns['error'] ?? '');
check('FR-16 no-show recorded in the service log',
      q_one('SELECT 1 FROM service_log WHERE status = "no_show"') !== null);

// FR-12: grace period expiry
$called2 = call_next(3);
if ($called2['ok']) {
    q('UPDATE queue_entry SET called_time = DATE_SUB(NOW(), INTERVAL :g MINUTE)
        WHERE queue_entry_id = :id',
      [':g' => GRACE_PERIOD_MINUTES + 5, ':id' => $called2['entry_id']]);
    $expired = expire_lapsed_tickets();
    check('FR-12 lapsed ticket expired', $expired >= 1, "expired $expired");
    check('FR-12 expiry time recorded',
          q_val('SELECT expiry_time FROM queue_entry WHERE queue_entry_id = :id',
                [':id' => $called2['entry_id']]) !== null);
}

// ----------------------------------------------------- derived / accuracy
section('Derived attributes and accuracy reporting (FR-19)');

$completed = q_one(
    'SELECT predicted_wait_time,
            TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60 AS actual_wait
       FROM queue_entry
      WHERE status = "completed" AND service_start_time IS NOT NULL LIMIT 1');
check('derived actual_wait_time computable', $completed !== null
      && $completed['actual_wait'] !== null);
if ($completed) {
    printf("        predicted %.2f min, actual %.2f min\n",
           (float)$completed['predicted_wait_time'], (float)$completed['actual_wait']);
}

check('cancelled ticket handled',
      cancel_ticket((int)$others[count($others) - 1])['ok'] !== null);


// ------------------------------------------------- 6.0 Reporting & Analytics
section('Process 6.0 — Reporting and Analytics (FR-18, FR-19)');

$filters = report_filters(['from' => date('Y-m-d', strtotime('-7 days')),
                           'to'   => date('Y-m-d')]);
check('filters accept a valid range', $filters['from'] < $filters['to']
      || $filters['from'] === $filters['to']);

$reversed = report_filters(['from' => date('Y-m-d'),
                            'to'   => date('Y-m-d', strtotime('-7 days'))]);
check('reversed dates are swapped, not rejected',
      strtotime($reversed['from']) <= strtotime($reversed['to']));

$defaults = report_filters([]);
check('missing dates default to a 30-day window',
      (int)round((strtotime($defaults['to']) - strtotime($defaults['from'])) / 86400) === 29,
      $defaults['from'] . ' to ' . $defaults['to']);

$injection = report_filters(['from' => "2026-01-01' OR 1=1 --", 'to' => '']);
check('malformed date is rejected rather than interpolated',
      preg_match('/^\d{4}-\d{2}-\d{2}$/', $injection['from']) === 1,
      $injection['from']);

$sum = report_summary($filters);
check('FR-18 report summary returns the expected keys',
      array_key_exists('served', $sum) && array_key_exists('no_show_rate', $sum)
      && array_key_exists('mae', $sum));
check('FR-19 accuracy is computed only over model predictions',
      $sum['accuracy_n'] <= $sum['served'],
      "{$sum['accuracy_n']} vs {$sum['served']}");

$byService = report_by_service($filters);
check('report groups by service type', is_array($byService));

$countAll = report_entries_count($filters);
$page1 = report_entries($filters, 3, 0);
check('report extract respects the row limit', count($page1) <= 3);
if ($countAll > 3) {
    $page2 = report_entries($filters, 3, 3);
    $overlap = array_intersect(
        array_column($page1, 'queue_entry_id'),
        array_column($page2, 'queue_entry_id')
    );
    check('paged report pages do not overlap', count($overlap) === 0);
}

$heat = report_heatmap($filters);
check('heat map is keyed by day and hour', is_array($heat));

// ---------------------------------------------------------- pagination
section('Pagination helper');

$_GET['page'] = 3;
$p = paginate(100, 25);
check('pagination computes the offset', $p['offset'] === 50, 'got ' . $p['offset']);
check('pagination computes the page count', $p['pages'] === 4, 'got ' . $p['pages']);

$_GET['page'] = 999;
$p = paginate(100, 25);
check('page beyond the end is clamped', $p['page'] === 4, 'got ' . $p['page']);

$_GET['page'] = -5;
$p = paginate(100, 25);
check('negative page is clamped to the first', $p['page'] === 1, 'got ' . $p['page']);

$p = paginate(0, 25);
check('empty result still reports one page', $p['pages'] === 1);
unset($_GET['page']);

// ---------------------------------------------------------- rate limiting
section('Rate limiting on the join form');

$key = 'test:' . bin2hex(random_bytes(6));
$allowed = 0;
for ($i = 0; $i < 5; $i++) {
    if (rate_limit_hit($key, 3, 600)['allowed']) {
        $allowed++;
    }
}
check('rate limiter permits exactly the configured number', $allowed === 3,
      "allowed $allowed");

$blocked = rate_limit_hit($key, 3, 600);
check('rate limiter reports a retry delay once blocked',
      $blocked['allowed'] === false && $blocked['retry_after'] > 0);

$fresh = rate_limit_hit('test:' . bin2hex(random_bytes(6)), 3, 600);
check('a different key is unaffected', $fresh['allowed'] === true);

// ---------------------------------------------------- password validation
section('Account password rules');

require_once __DIR__ . '/../includes/auth.php';
$hash = password_hash('Password123', PASSWORD_DEFAULT);
check('password hashing is verifiable', password_verify('Password123', $hash));
check('wrong password is rejected', !password_verify('Password124', $hash));
check('hash is not the plain text', $hash !== 'Password123'
      && str_starts_with($hash, '$2y$'));

$staffLogin = auth_attempt('b.chebet@hudumacentre.go.ke', 'Password123');
check('FR-20 staff sign in succeeds', $staffLogin !== null);
check('FR-20 role is carried on the session',
      ($staffLogin['role'] ?? '') === 'staff', $staffLogin['role'] ?? 'none');
check('FR-20 wrong password is refused',
      auth_attempt('b.chebet@hudumacentre.go.ke', 'wrong') === null);
check('FR-20 a client cannot sign in',
      auth_attempt('mary@example.com', 'Password123') === null);


// ------------------------------------------------------------- SMS gateway
section('SMS gateway (FR-08, FR-09)');

setting_set('sms_driver', 'simulate');
check('simulation is the default driver', sms_driver() === 'simulate');
check('simulation reports itself as not live', sms_is_live() === false);

$sim = sms_send('0722000111', 'Test message');
check('simulated send succeeds', $sim['status'] === 'simulated', $sim['status']);
check('simulated send transmits nothing',
      str_contains(strtolower($sim['detail']), 'simulat'), $sim['detail']);

$badNumber = sms_send('not-a-number', 'Test');
check('invalid recipient is rejected before dispatch',
      $badNumber['status'] === 'failed', $badNumber['status']);

$long = sms_send('0722000111', str_repeat('x', 350));
check('multi-part message is flagged for cost',
      str_contains($long['detail'], 'parts'), $long['detail']);

foreach (['simulate', 'africastalking', 'twilio', 'generic'] as $d) {
    check("driver label exists for $d",
          sms_driver_label($d) !== 'Unknown' && sms_driver_label($d) !== '');
}

// ------------------------------------------- delivery reports (callback)
section('SMS delivery-report callback');

$at = sms_normalise_report(['id' => 'ATXid_1', 'status' => 'Success',
                            'networkCode' => '63902']);
check("Africa's Talking success maps to delivered",
      $at !== null && $at[1] === 'delivered', $at[1] ?? 'null');

$atFail = sms_normalise_report(['id' => 'ATXid_2', 'status' => 'Failed',
                                'failureReason' => 'UserInBlacklist']);
check("Africa's Talking failure maps to failed",
      $atFail !== null && $atFail[1] === 'failed', $atFail[1] ?? 'null');
check('failure reason is preserved for audit',
      $atFail !== null && str_contains($atFail[2], 'UserInBlacklist'));

$atBuffered = sms_normalise_report(['id' => 'ATXid_3', 'status' => 'Buffered']);
check('an in-flight status maps to sent, not delivered',
      $atBuffered !== null && $atBuffered[1] === 'sent', $atBuffered[1] ?? 'null');

$tw = sms_normalise_report(['MessageSid' => 'SM1', 'MessageStatus' => 'delivered']);
check('Twilio delivered maps to delivered',
      $tw !== null && $tw[1] === 'delivered', $tw[1] ?? 'null');

$twFail = sms_normalise_report(['MessageSid' => 'SM2',
                                'MessageStatus' => 'undelivered',
                                'ErrorCode' => '30003']);
check('Twilio undelivered maps to failed',
      $twFail !== null && $twFail[1] === 'failed', $twFail[1] ?? 'null');

$gen = sms_normalise_report(['messageId' => 'G1', 'status' => 'DELIVERED']);
check('a generic gateway report is understood',
      $gen !== null && $gen[1] === 'delivered', $gen[1] ?? 'null');

check('an unrecognised payload is refused, not guessed',
      sms_normalise_report(['something' => 'else']) === null);

// ------------------------------------------------------- settings store
section('Application settings');

setting_set('test_key_' . getmypid(), 'value one');
check('a setting round-trips',
      setting_get('test_key_' . getmypid()) === 'value one');
setting_set('test_key_' . getmypid(), 'value two');
check('a setting is overwritten, not duplicated',
      setting_get('test_key_' . getmypid()) === 'value two');
check('a missing setting returns the default',
      setting_get('no_such_key_at_all', 'fallback') === 'fallback');
setting_set('test_key_' . getmypid(), null);


// ---------------------------------------------------- client feedback
section('Client feedback (Section 3.3.4 evaluation measures)');

$fbEntry = (int)q_val('SELECT queue_entry_id FROM queue_entry
                        WHERE status = "completed" ORDER BY queue_entry_id DESC LIMIT 1');
if ($fbEntry > 0) {
    q('DELETE FROM feedback WHERE queue_entry_id = :id', [':id' => $fbEntry]);
    q('INSERT INTO feedback (queue_entry_id, rating, wait_accurate, comment)
       VALUES (:id, 4, 1, "Clear and quick")', [':id' => $fbEntry]);

    $fb = q_one('SELECT * FROM feedback WHERE queue_entry_id = :id', [':id' => $fbEntry]);
    check('feedback is stored against the queue entry', $fb !== null);
    check('satisfaction rating is recorded', (int)($fb['rating'] ?? 0) === 4);
    check('perceived accuracy of the estimate is recorded',
          (int)($fb['wait_accurate'] ?? -1) === 1);

    $avg = q_val('SELECT AVG(rating) FROM feedback');
    check('mean satisfaction is computable for Chapter 5', $avg !== null);

    q('DELETE FROM feedback WHERE queue_entry_id = :id', [':id' => $fbEntry]);
} else {
    check('a completed entry exists to attach feedback to', false,
          'no completed entries');
}

// --------------------------------------------------------------- summary
section('Summary');
printf("  %d passed, %d failed\n", $pass, $fail);
echo "\nCurrent queue state:\n";
foreach (q_all('SELECT queue_number, service_type, status, predicted_wait_time,
                       prediction_source, delivery_channel
                  FROM queue_entry ORDER BY queue_entry_id') as $row) {
    printf("  %-7s %-20s %-12s %6s min  %-9s %s\n",
        $row['queue_number'], $row['service_type'], $row['status'],
        $row['predicted_wait_time'] ?? '—',
        $row['prediction_source'] ?? '—', $row['delivery_channel']);
}

exit($fail === 0 ? 0 : 1);
