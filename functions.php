<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $configuredTimezone = getenv('ATTENDEASE_TIMEZONE') ?: 'Asia/Manila';
    try {
        date_default_timezone_set($configuredTimezone);
    } catch (Throwable $error) {
        throw new RuntimeException('ATTENDEASE_TIMEZONE must be a valid PHP timezone identifier.', 0, $error);
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once __DIR__ . '/db.php';

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function text_initial(string $value): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
}

function text_preview(string $value, int $length): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $length, '…', 'UTF-8');
    }
    return strlen($value) > $length ? substr($value, 0, max(0, $length - 3)) . '...' : $value;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function safe_next_url(string $candidate): string
{
    $allowed = ['dashboard.php', 'attendance_logs.php', 'students_manage.php', 'profile.php', 'notifications.php'];
    return in_array($candidate, $allowed, true) ? $candidate : 'dashboard.php';
}

function require_login(): void
{
    static $validated = false;
    $user = current_user();
    if (!$user) {
        header('Location: index.php?next=' . rawurlencode(safe_next_url(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')))));
        exit;
    }
    if ($validated) {
        return;
    }
    $query = db()->prepare('SELECT id,full_name,email,role,avatar_url,created_at,auth_version FROM users WHERE id=? AND is_active=1 LIMIT 1');
    $query->execute([(int)($user['id'] ?? 0)]);
    $freshUser = $query->fetch();
    if (!$freshUser) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: index.php?error=inactive');
        exit;
    }
    if ((int)$freshUser['auth_version'] !== (int)($user['auth_version'] ?? 0)) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: index.php?error=reauthenticate');
        exit;
    }
    $_SESSION['user'] = $freshUser;
    $validated = true;
    finalize_expired_attendance_sessions(db());
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_user()['role'] ?? '', $roles, true)) {
        http_response_code(403);
        page_start('Access restricted', '');
        echo '<section class="content-card p-4"><h1 class="h4">Access restricted</h1><p class="mb-0">Your account does not have permission to view this page.</p></section>';
        page_end();
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function rotate_session_after_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = ['user' => $user, 'csrf' => bin2hex(random_bytes(32))];
}

function verify_csrf(): void
{
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Your session expired. Refresh the page and try again.');
    }
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function audit_admin_action(PDO $pdo, int $actorId, string $action, string $entityType, ?int $entityId, array $details = []): void
{
    $statement = $pdo->prepare('INSERT INTO admin_audit_logs (actor_user_id,action,entity_type,entity_id,details) VALUES (?,?,?,?,?)');
    $encoded = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
    $statement->execute([$actorId,$action,$entityType,$entityId,$encoded]);
}

function csv_safe_cell(mixed $value): string
{
    $cell = (string)$value;
    return preg_match('/^[\x00-\x20]*[=+@-]/', $cell) === 1 ? "'" . $cell : $cell;
}

function unread_count(): int
{
    if (!current_user()) {
        return 0;
    }
    $statement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $statement->execute([current_user()['id']]);
    return (int)$statement->fetchColumn();
}

function student_record(?int $userId = null): ?array
{
    $userId ??= (int)(current_user()['id'] ?? 0);
    $statement = db()->prepare('SELECT s.*,u.full_name,u.email,u.is_active FROM students s JOIN users u ON u.id=s.user_id WHERE s.user_id=?');
    $statement->execute([$userId]);
    $student = $statement->fetch();
    return $student ?: null;
}

function managed_class_ids(): array
{
    $user = current_user();
    if (!$user || $user['role'] === 'admin') {
        return [];
    }
    if ($user['role'] === 'teacher') {
        $statement = db()->prepare('SELECT id FROM classes WHERE teacher_id=?');
        $statement->execute([$user['id']]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
    $statement = db()->prepare('SELECT class_id FROM enrollments e JOIN students s ON s.id=e.student_id WHERE s.user_id=?');
    $statement->execute([$user['id']]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function status_badge(string $status): string
{
    $class = match ($status) {
        'Present', 'Registered', 'success', 'Active' => 'success',
        'Late', 'Pending', 'warning' => 'warning',
        'Absent', 'alert', 'danger', 'Inactive' => 'danger',
        'Excused', 'info' => 'info',
        default => 'muted',
    };
    return '<span class="status-pill status-' . $class . '"><i></i>' . e($status) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= text_initial($part);
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($letters ?: 'AE', 'UTF-8') : strtoupper($letters ?: 'AE');
}

function avatar_contents(array $user): string
{
    $avatarUrl = (string)($user['avatar_url'] ?? '');
    if (preg_match('~^/uploads/avatars/[a-f0-9]{32}\.(?:jpg|png|webp)$~D', $avatarUrl) === 1) {
        return '<img src="' . e($avatarUrl) . '" alt="Profile photo for ' . e($user['full_name'] ?? 'user') . '">';
    }
    return e(initials((string)($user['full_name'] ?? '')));
}

function role_label(string $role): string
{
    return $role === 'teacher' ? 'Instructor' : ucfirst($role);
}

function app_key(): string
{
    $key = getenv('ATTENDEASE_APP_KEY');
    if ($key === false || strlen($key) < 32) {
        throw new RuntimeException('Set ATTENDEASE_APP_KEY to a random value of at least 32 characters.');
    }
    return hash('sha256', $key, true);
}

function encrypt_totp_secret(string $secret): string
{
    $key = app_key();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Could not protect the attendance session secret.');
    }
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decrypt_totp_secret(string $encoded): string
{
    if (!str_starts_with($encoded, 'v1:')) {
        throw new RuntimeException('Unsupported attendance session secret format.');
    }
    $packed = base64_decode(substr($encoded, 3), true);
    if ($packed === false || strlen($packed) < 29) {
        throw new RuntimeException('Attendance session secret is corrupt.');
    }
    $plain = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16));
    if ($plain === false) {
        throw new RuntimeException('Could not decrypt the attendance session secret. Check ATTENDEASE_APP_KEY.');
    }
    return $plain;
}

function base32_encode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $encoded = '';
    foreach (unpack('C*', $input) ?: [] as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $encoded .= $alphabet[($buffer >> $bits) & 31];
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits > 0) {
        $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $encoded;
}

function base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $decoded = '';
    foreach (str_split(strtoupper(rtrim($input, '='))) as $character) {
        $value = strpos($alphabet, $character);
        if ($value === false) {
            throw new RuntimeException('Invalid TOTP secret.');
        }
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $decoded .= chr(($buffer >> $bits) & 255);
            $buffer &= (1 << $bits) - 1;
        }
    }
    return $decoded;
}

function generate_totp_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $counter = intdiv($timestamp, 30);
    $message = pack('N2', intdiv($counter, 4294967296), $counter & 0xffffffff);
    $hash = hash_hmac('sha1', $message, base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function verify_totp(string $secret, string $code, ?int $timestamp = null): bool
{
    return preg_match('/^\d{6}$/D', $code) === 1 && hash_equals(totp_code($secret, $timestamp), $code);
}

/**
 * Close a TOTP attendance session and add one System/Absent row for each
 * currently enrolled student without a record for that session.
 * The caller may already hold a transaction; in that case the caller owns it.
 */
function finalize_attendance_session(PDO $pdo, int $sessionId, bool $force = false): bool
{
    if ($sessionId < 1) {
        return false;
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $query = $pdo->prepare('SELECT id,class_id,created_at,expires_at,ended_at,finalized_at,secret_ciphertext,NOW() AS db_now FROM attendance_sessions WHERE id=? FOR UPDATE');
        $query->execute([$sessionId]);
        $session = $query->fetch();
        if (!$session || $session['finalized_at'] !== null) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }
        $isDue = $force || $session['ended_at'] !== null || (string)$session['expires_at'] <= (string)$session['db_now'];
        if (!$isDue) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }

        // Legacy sessions have no TOTP secret; close them without inventing absences.
        if ($session['secret_ciphertext'] !== null) {
            $insert = $pdo->prepare("INSERT INTO attendance_logs (student_id,class_id,session_id,attendance_date,status,verification_method,remarks)
                SELECT e.student_id,s.class_id,s.id,DATE(s.created_at),'Absent','System','No check-in was recorded before the attendance session closed.'
                FROM enrollments e JOIN attendance_sessions s ON s.class_id=e.class_id
                WHERE s.id=? AND NOT EXISTS (SELECT 1 FROM attendance_logs a WHERE a.student_id=e.student_id AND a.session_id=s.id)");
            $insert->execute([$sessionId]);
        }
        $close = $pdo->prepare('UPDATE attendance_sessions SET ended_at=COALESCE(ended_at,CASE WHEN expires_at<=NOW() THEN expires_at ELSE NOW() END),secret_ciphertext=NULL,finalized_at=NOW() WHERE id=? AND finalized_at IS NULL');
        $close->execute([$sessionId]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function finalize_expired_attendance_sessions(PDO $pdo, int $limit = 100): int
{
    $limit = max(1, min(500, $limit));
    $query = $pdo->query('SELECT id FROM attendance_sessions WHERE finalized_at IS NULL AND secret_ciphertext IS NOT NULL AND (ended_at IS NOT NULL OR expires_at<=NOW()) ORDER BY expires_at,id LIMIT ' . $limit);
    $finalized = 0;
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
        if (finalize_attendance_session($pdo, (int)$sessionId)) {
            $finalized++;
        }
    }
    return $finalized;
}

function page_start(string $title, string $active): void
{
    require_login();
    $pageTitle = $title;
    $activePage = $active;
    require __DIR__ . '/includes/header.php';
}

function page_end(): void
{
    require __DIR__ . '/includes/footer.php';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
