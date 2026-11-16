<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
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

function require_login(): void
{
    if (!current_user()) {
        $next = 'dashboard.php';
        if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'checkin.php') {
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            if (preg_match('/^session=\d+&token=[A-Za-z0-9_-]+$/D', $query)) {
                $next = 'checkin.php?' . $query;
            }
        }
        header('Location: index.php?next=' . rawurlencode($next));
        exit;
    }
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
    $statement = db()->prepare('SELECT s.*,u.full_name,u.email FROM students s JOIN users u ON u.id=s.user_id WHERE s.user_id=?');
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
        'Present', 'Registered', 'success' => 'success',
        'Late', 'Pending', 'warning' => 'warning',
        'Absent', 'alert', 'danger' => 'danger',
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
