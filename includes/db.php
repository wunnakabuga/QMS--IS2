<?php
/**
 * Database access layer.
 * Every query in this system goes through a prepared statement (NFR-07).
 */
require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed. Check config/config.php. '
                . (APP_DEBUG ? htmlspecialchars($e->getMessage()) : ''));
        }
    }
    return $pdo;
}

/** Run a prepared statement and return the statement handle. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row, or null. */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch a single scalar value. */
function q_val(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
