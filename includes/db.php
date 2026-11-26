<?php
/**
 * FILE: includes/db.php
 * WHAT IT DOES: The database access layer. Opens one shared connection to
 * MySQL (using PDO) and provides four small helpers - q(), q_one(), q_all()
 * and q_val() - that every page uses to run queries.
 *
 * SECURITY: Every query in this system goes through a prepared statement
 * with bound parameters (NFR-07). User input is never pasted into SQL text,
 * which is what protects the system against SQL injection.
 *
 * FIND: database connection, PDO, prepared statements, SQL injection protection
 */
require_once __DIR__ . '/../config/config.php';

/**
 * Open (once) and return the shared PDO connection to MySQL.
 * EMULATE_PREPARES is switched off so MySQL itself handles the parameter
 * binding - true prepared statements, not string escaping.
 * FIND: database connection, connect to MySQL
 */
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

/**
 * Run any SQL as a prepared statement. Values are passed separately in
 * $params (e.g. [':email' => $email]) and are never joined into the SQL.
 * FIND: prepared statement, SQL injection protection, run query
 */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Run a prepared query and return the first row, or null if none. */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Run a prepared query and return every row as an array. */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Run a prepared query and return a single value (e.g. a COUNT). */
function q_val(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
