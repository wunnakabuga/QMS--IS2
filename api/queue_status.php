<?php
/**
 * AJAX endpoint backing the live ticket view (FR-13).
 * Returns the current position, predicted waiting time and now-serving
 * number for one queue entry.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../modules/dispatch.php';
require_once __DIR__ . '/../modules/service_delivery.php';

expire_lapsed_tickets();
dispatch_due_reminders();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    json_out(['ok' => false, 'error' => 'Missing ticket id'], 400);
}

$entry = ticket_view($id);
if (!$entry) {
    json_out(['ok' => false, 'error' => 'Ticket not found'], 404);
}

$nowServing = q_one(
    'SELECT qe.queue_number, sc.counter_name
       FROM queue_entry qe LEFT JOIN service_counter sc ON sc.counter_id = qe.counter_id
      WHERE qe.service_type = :st AND qe.status IN ("called","in_service")
      ORDER BY qe.called_time DESC LIMIT 1',
    [':st' => $entry['service_type']]
);

$totalWaiting = (int)q_val(
    'SELECT COUNT(*) FROM queue_entry
      WHERE service_type = :st AND status IN ("waiting","checked_in")',
    [':st' => $entry['service_type']], 0);

$position = $entry['queue_position'];
$progress = ($position && $totalWaiting > 0)
    ? (int)round((1 - ($position - 1) / max(1, $totalWaiting)) * 100)
    : 100;

$clientStatus = $_GET['status'] ?? null;

json_out([
    'ok'                   => true,
    'queue_number'         => $entry['queue_number'],
    'status'               => $entry['status'],
    'status_changed'       => $clientStatus !== null && $clientStatus !== $entry['status'],
    'position'             => $position,
    'predicted_wait'       => $entry['predicted_wait_time'] !== null
                                ? (float)$entry['predicted_wait_time'] : null,
    'predicted_wait_label' => fmt_minutes($entry['predicted_wait_time']),
    'prediction_source'    => $entry['prediction_source'],
    'now_serving'          => $nowServing['queue_number'] ?? null,
    'now_serving_counter'  => $nowServing['counter_name'] ?? null,
    'total_waiting'        => $totalWaiting,
    'progress'             => max(0, min(100, $progress)),
    'server_time'          => date('c'),
]);
