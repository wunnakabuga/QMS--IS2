<?php
/**
 * Idempotent schema migration.
 *
 * database/schema.sql drops and recreates the database, which destroys data.
 * This script instead brings an existing database up to the current schema
 * without touching the records already in it, so an installation that has
 * been collecting queue data can be upgraded safely.
 *
 * Run:  php database/migrate.php
 * It is safe to run repeatedly; each step checks before it acts.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../modules/settings.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role('admin');
    header('Content-Type: text/plain; charset=utf-8');
}

$applied = [];
$skipped = [];

function column_exists(string $table, string $column): bool
{
    return (int)q_val(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        [':t' => $table, ':c' => $column], 0
    ) > 0;
}

function table_exists(string $table): bool
{
    return (int)q_val(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
        [':t' => $table], 0
    ) > 0;
}

function index_exists(string $table, string $index): bool
{
    return (int)q_val(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
        [':t' => $table, ':i' => $index], 0
    ) > 0;
}

function step(string $label, callable $needed, string $sql): void
{
    global $applied, $skipped;
    if ($needed()) {
        db()->exec($sql);
        $applied[] = $label;
    } else {
        $skipped[] = $label;
    }
}

echo "Queue Management System — schema migration\n";
echo str_repeat('-', 58) . "\n";

// ---------------------------------------------------- notification audit
step('notification.provider_reference',
     fn() => !column_exists('notification', 'provider_reference'),
     'ALTER TABLE notification
        ADD COLUMN provider_reference VARCHAR(120) DEFAULT NULL AFTER delivery_status');

step('notification.provider_detail',
     fn() => !column_exists('notification', 'provider_detail'),
     'ALTER TABLE notification
        ADD COLUMN provider_detail VARCHAR(255) DEFAULT NULL AFTER provider_reference');

step('notification.attempts',
     fn() => !column_exists('notification', 'attempts'),
     'ALTER TABLE notification
        ADD COLUMN attempts TINYINT NOT NULL DEFAULT 1 AFTER provider_detail');

step('notification.notification_type',
     fn() => !column_exists('notification', 'notification_type'),
     'ALTER TABLE notification
        ADD COLUMN notification_type ENUM("ticket","reminder","test")
        NOT NULL DEFAULT "ticket" AFTER channel');

// ---------------------------------------------------------- prediction
step('queue_entry.prediction_source',
     fn() => !column_exists('queue_entry', 'prediction_source'),
     'ALTER TABLE queue_entry
        ADD COLUMN prediction_source ENUM("model","fallback") DEFAULT NULL
        AFTER predicted_wait_time');

// -------------------------------------------------------- support tables
step('app_setting table',
     fn() => !table_exists('app_setting'),
     'CREATE TABLE app_setting (
        setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
        setting_value TEXT,
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB');

step('rate_limit table',
     fn() => !table_exists('rate_limit'),
     'CREATE TABLE rate_limit (
        rate_key   VARCHAR(120) NOT NULL,
        event_time DATETIME     NOT NULL,
        INDEX idx_rate (rate_key, event_time)
      ) ENGINE=InnoDB');

step('feedback table',
     fn() => !table_exists('feedback'),
     'CREATE TABLE feedback (
        feedback_id    INT AUTO_INCREMENT PRIMARY KEY,
        queue_entry_id INT DEFAULT NULL,
        rating         TINYINT NOT NULL,
        wait_accurate  TINYINT DEFAULT NULL,
        comment        TEXT,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_feedback_entry FOREIGN KEY (queue_entry_id)
            REFERENCES queue_entry (queue_entry_id) ON DELETE SET NULL,
        INDEX idx_feedback_time (created_at)
      ) ENGINE=InnoDB');

// -------------------------------------------------------------- indexes
step('index on notification.queue_entry_id',
     fn() => !index_exists('notification', 'idx_notif_entry'),
     'ALTER TABLE notification ADD INDEX idx_notif_entry (queue_entry_id)');

step('index on service_log.queue_entry_id',
     fn() => !index_exists('service_log', 'idx_log_entry'),
     'ALTER TABLE service_log ADD INDEX idx_log_entry (queue_entry_id)');

step('composite index on queue_entry',
     fn() => !index_exists('queue_entry', 'idx_entry_service_status_join'),
     'ALTER TABLE queue_entry
        ADD INDEX idx_entry_service_status_join (service_type, status, join_time)');

// ------------------------------------------------------------- results
foreach ($applied as $label) {
    echo "  applied  $label\n";
}
foreach ($skipped as $label) {
    echo "  current  $label\n";
}

echo str_repeat('-', 58) . "\n";
printf("%d applied, %d already current.\n", count($applied), count($skipped));

if ($applied) {
    echo "\nThe database is now up to date.\n";
} else {
    echo "\nNothing to do — the schema was already current.\n";
}
