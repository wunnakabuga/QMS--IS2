<?php
/**
 * Runtime settings held in the database.
 *
 * Gateway credentials and queue rules can be changed by an administrator
 * from the interface rather than by editing config/config.php and
 * restarting Apache. Values in config.php remain the defaults; a row here
 * overrides one.
 */
require_once __DIR__ . '/../includes/db.php';

function settings_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS app_setting (
            setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
            setting_value TEXT,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB'
    );
    $done = true;
}

/**
 * The settings cache, held in one place so that a write can genuinely
 * invalidate it. Returns the cache by reference.
 */
function &setting_cache(): ?array
{
    static $cache = null;
    return $cache;
}

/** Read one setting, falling back to the supplied default. */
function setting_get(string $key, $default = null)
{
    $cache = &setting_cache();
    if ($cache === null) {
        settings_init();
        $cache = [];
        foreach (q_all('SELECT setting_key, setting_value FROM app_setting') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    $value = $cache[$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

/** Write one setting. */
function setting_set(string $key, ?string $value): void
{
    settings_init();
    q('INSERT INTO app_setting (setting_key, setting_value) VALUES (:k, :v)
       ON DUPLICATE KEY UPDATE setting_value = :v2',
      [':k' => $key, ':v' => $value, ':v2' => $value]);
    setting_flush();
}

/** Write several settings at once. */
function setting_set_many(array $pairs): void
{
    foreach ($pairs as $key => $value) {
        setting_set($key, $value === null ? null : (string)$value);
    }
}

/** Drop the in-request cache so the next read reloads from the database. */
function setting_flush(): void
{
    $cache = &setting_cache();
    $cache = null;
}

/** Everything, for the settings screen. */
function settings_all(): array
{
    settings_init();
    $out = [];
    foreach (q_all('SELECT setting_key, setting_value, updated_at FROM app_setting
                     ORDER BY setting_key') as $row) {
        $out[$row['setting_key']] = $row;
    }
    return $out;
}
