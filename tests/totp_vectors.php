<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions.php';

$secret = base32_encode('12345678901234567890');
$vectors = [
    59 => '287082',
    1111111109 => '081804',
    1111111111 => '050471',
    1234567890 => '005924',
    2000000000 => '279037',
    20000000000 => '353130',
];

foreach ($vectors as $timestamp => $expected) {
    $actual = totp_code($secret, (int)$timestamp);
    if ($actual !== $expected) {
        throw new RuntimeException("RFC 6238 vector failed at {$timestamp}: expected {$expected}, got {$actual}");
    }
}

if (base32_decode($secret) !== '12345678901234567890') {
    throw new RuntimeException('Base32 secret round-trip failed.');
}

echo "RFC 6238 SHA-1 vectors passed.\n";
