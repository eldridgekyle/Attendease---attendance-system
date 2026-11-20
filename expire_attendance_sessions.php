<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/functions.php';

try {
    $pdo = db();
    $total = 0;
    do {
        $batch = finalize_expired_attendance_sessions($pdo, 100);
        $total += $batch;
    } while ($batch === 100);
    fwrite(STDOUT, sprintf("Finalized %d expired attendance session(s).\n", $total));
} catch (Throwable $error) {
    error_log('AttendEase expiration worker error: ' . $error->getMessage());
    fwrite(STDERR, "Attendance session finalization failed. Check the PHP error log.\n");
    exit(1);
}
