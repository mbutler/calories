<?php
declare(strict_types=1);
require_once __DIR__ . '/domain.php';

$local = is_file(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];
$config = array_replace([
    'environment' => 'production',
    'timezone' => 'America/Chicago',
    'dsn' => 'mysql:host=127.0.0.1;dbname=calories;charset=utf8mb4',
    'db_user' => 'calories',
    'db_password' => '',
    'password_hash' => '',
], $local);
foreach (['APP_ENV' => 'environment', 'APP_TIMEZONE' => 'timezone', 'DB_DSN' => 'dsn',
    'DB_USER' => 'db_user', 'DB_PASSWORD' => 'db_password', 'APP_PASSWORD_HASH' => 'password_hash'] as $env => $key) {
    if (getenv($env) !== false) $config[$key] = getenv($env);
}
date_default_timezone_set($config['timezone']);

function db(): PDO
{
    global $config;
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function query(string $sql, array $values = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($values);
    return $statement;
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        respond(['error' => 'Request is too large.'], 413);
    }
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        respond(['error' => 'Send JSON data.'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) respond(['error' => 'Request is too large.'], 413);
    $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    if (!$object instanceof stdClass) {
        throw new InvalidArgumentException('Send a JSON object.');
    }
    return (array) $object;
}

function record(string $table, int $id): array
{
    // Table names originate only from literal internal calls.
    $row = query("SELECT * FROM $table WHERE id = ?", [$id])->fetch();
    if (!$row) respond(['error' => 'This item no longer exists.'], 404);
    return $row;
}
