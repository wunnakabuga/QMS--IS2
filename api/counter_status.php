<?php
/** AJAX endpoint backing the counter console's live queue panel. */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/service_delivery.php';

$user = auth_user();
if (!$user || !in_array($user['role'], ['staff', 'admin'], true)) {
    json_out(['ok' => false, 'error' => 'Not authorised'], 403);
}

expire_lapsed_tickets();

$counterId = (int)($_GET['counter'] ?? 0);
$counter = q_one('SELECT * FROM service_counter WHERE counter_id = :c',
                 [':c' => $counterId]);
if (!$counter) {
    json_out(['ok' => false, 'error' => 'Counter not found'], 404);
}

$current = current_at_counter($counterId);
$stats   = counter_stats($counterId);
$clientCurrent = $_GET['current'] ?? null;

json_out([
    'ok'            => true,
    'waiting'       => $stats['waiting'],
    'served_today'  => $stats['served_today'],
    'current'       => $current['queue_number'] ?? null,
    'current_status'=> $current['status'] ?? null,
    'reload'        => $clientCurrent !== null
                       && $clientCurrent !== (string)($current['queue_number'] ?? ''),
    'upcoming'      => array_map(static function (array $row): array {
        return [
            'queue_number'    => $row['queue_number'],
            'full_name'       => $row['full_name'],
            'status'          => $row['status'],
            'waiting_minutes' => (int)$row['waiting_minutes'],
        ];
    }, upcoming_for_counter($counterId, 6)),
    'server_time'   => date('c'),
]);
