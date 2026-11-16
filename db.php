<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'attendease_db';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('ATTENDEASE_DB_HOST') ?: DB_HOST;
    $name = getenv('ATTENDEASE_DB_NAME') ?: DB_NAME;
    $user = getenv('ATTENDEASE_DB_USER') ?: DB_USER;
    $pass = getenv('ATTENDEASE_DB_PASS');
    if ($pass === false) {
        $pass = DB_PASS;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    return $pdo;
}
