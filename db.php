<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('ATTENDEASE_DB_HOST') ?: '127.0.0.1';
    $name = getenv('ATTENDEASE_DB_NAME') ?: 'attendease_db';
    $socket = getenv('ATTENDEASE_DB_SOCKET');
    $user = getenv('ATTENDEASE_DB_USER');
    $pass = getenv('ATTENDEASE_DB_PASS');
    if ($user === false || trim($user) === '') {
        throw new RuntimeException('Set ATTENDEASE_DB_USER and ATTENDEASE_DB_PASS before starting AttendEase.');
    }
    if ($pass === false) {
        $pass = '';
    }

    $dsn = $socket !== false && $socket !== ''
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
        : sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $timezone = new DateTimeZone(date_default_timezone_get());
    $timezoneOffset = (new DateTimeImmutable('now', $timezone))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($timezoneOffset));
    return $pdo;
}
