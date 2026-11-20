<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function session_json_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function can_manage_class(PDO $pdo, array $user, int $classId): bool
{
    $sql = 'SELECT 1 FROM classes WHERE id=?';
    $params = [$classId];
    if ($user['role'] === 'teacher') {
        $sql .= ' AND teacher_id=?';
        $params[] = $user['id'];
    }
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return (bool)$query->fetchColumn();
}

function authorized_session(PDO $pdo, array $user, int $sessionId): ?array
{
    $sql = 'SELECT s.*,TIMESTAMPDIFF(SECOND,NOW(),s.expires_at) AS seconds_left FROM attendance_sessions s JOIN classes c ON c.id=s.class_id WHERE s.id=?';
    $params = [$sessionId];
    if ($user['role'] === 'teacher') {
        $sql .= ' AND c.teacher_id=?';
        $params[] = $user['id'];
    }
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return $query->fetch() ?: null;
}

if (!current_user()) {
    session_json_error(401, 'Sign in to manage attendance sessions.');
}
$storedUser = current_user();
$freshUserQuery = db()->prepare('SELECT id,full_name,email,role,avatar_url,created_at,auth_version FROM users WHERE id=? AND is_active=1');
$freshUserQuery->execute([(int)$storedUser['id']]);
$user = $freshUserQuery->fetch();
if (!$user) {
    $_SESSION = [];
    session_json_error(401, 'This account is inactive. Sign in with an active account.');
}
if ((int)$user['auth_version'] !== (int)($storedUser['auth_version'] ?? 0)) {
    $_SESSION = [];
    session_json_error(401, 'Your account security details changed. Sign in again.');
}
$_SESSION['user'] = $user;
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$pdo = db();
try {
    finalize_expired_attendance_sessions($pdo);
} catch (Throwable $error) {
    error_log('AttendEase expired session finalization error: ' . $error->getMessage());
}

if ($user['role'] === 'student' && $_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'student_status') {
    try {
        $student = student_record((int)$user['id']);
        $classId = (int)($_GET['class_id'] ?? 0);
        $enrollment = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id=? AND class_id=?');
        $enrollment->execute([(int)($student['id'] ?? 0), $classId]);
        if (!$student || !$enrollment->fetchColumn()) {
            session_json_error(403, 'Choose a class you are enrolled in.');
        }
        $active = $pdo->prepare('SELECT id,expires_at FROM attendance_sessions WHERE class_id=? AND secret_ciphertext IS NOT NULL AND ended_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
        $active->execute([$classId]);
        $session = $active->fetch();
        echo json_encode(['active'=>(bool)$session,'expires_at'=>$session['expires_at']??null]);
        exit;
    } catch (Throwable $error) {
        error_log('AttendEase student session status error: ' . $error->getMessage());
        session_json_error(500, 'Could not check this class session. Try again.');
    }
}

if (!in_array($user['role'], ['admin', 'teacher'], true)) {
    session_json_error(403, 'Only instructors and administrators can manage attendance sessions.');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'active') {
        $classId = (int)($_GET['class_id'] ?? 0);
        if ($classId < 1 || !can_manage_class($pdo, $user, $classId)) {
            session_json_error(403, 'Choose a class you are allowed to manage.');
        }
        $query = $pdo->prepare('SELECT id,expires_at FROM attendance_sessions WHERE class_id=? AND secret_ciphertext IS NOT NULL AND ended_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
        $query->execute([$classId]);
        $session = $query->fetch();
        echo json_encode(['active' => (bool)$session, 'session_id' => $session ? (int)$session['id'] : null, 'expires_at' => $session['expires_at'] ?? null]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'code') {
        $sessionId = (int)($_GET['session_id'] ?? 0);
        $session = $sessionId > 0 ? authorized_session($pdo, $user, $sessionId) : null;
        if (!$session || $session['ended_at'] !== null || (int)$session['seconds_left'] <= 0 || !$session['secret_ciphertext']) {
            session_json_error(410, 'This attendance session has ended or expired.');
        }
        $secret = decrypt_totp_secret((string)$session['secret_ciphertext']);
        echo json_encode([
            'active' => true,
            'code' => totp_code($secret),
            'seconds_remaining' => 30 - (time() % 30),
            'expires_at' => $session['expires_at'],
            'expires_in' => (int)$session['seconds_left'],
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        session_json_error(405, 'Use POST to start or end an attendance session.');
    }
    verify_csrf();

    if ($action === 'start') {
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($classId < 1 || !can_manage_class($pdo, $user, $classId)) {
            session_json_error(403, 'Choose a class you are allowed to manage.');
        }
        finalize_expired_attendance_sessions($pdo);
        $pdo->beginTransaction();
        $lockSql = 'SELECT id FROM classes WHERE id=?' . ($user['role'] === 'teacher' ? ' AND teacher_id=?' : '') . ' FOR UPDATE';
        $lock = $pdo->prepare($lockSql);
        $lock->execute($user['role'] === 'teacher' ? [$classId, $user['id']] : [$classId]);
        if (!$lock->fetchColumn()) {
            $pdo->rollBack();
            session_json_error(403, 'Choose a class you are allowed to manage.');
        }
        $activeQuery = $pdo->prepare('SELECT id,secret_ciphertext,expires_at FROM attendance_sessions WHERE class_id=? AND secret_ciphertext IS NOT NULL AND finalized_at IS NULL AND ended_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $activeQuery->execute([$classId]);
        $activeSession = $activeQuery->fetch();
        if ($activeSession) {
            $pdo->commit();
            $activeSecret = decrypt_totp_secret((string)$activeSession['secret_ciphertext']);
            echo json_encode([
                'active' => true,
                'existing' => true,
                'session_id' => (int)$activeSession['id'],
                'code' => totp_code($activeSecret),
                'seconds_remaining' => 30 - (time() % 30),
                'expires_at' => $activeSession['expires_at'],
                'expires_label' => date('g:i A', strtotime($activeSession['expires_at'])),
            ]);
            exit;
        }
        $secret = generate_totp_secret();
        $expiresQuery = $pdo->query('SELECT DATE_ADD(NOW(), INTERVAL 15 MINUTE)');
        $expiresAt = (string)$expiresQuery->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO attendance_sessions (class_id,created_by,secret_ciphertext,expires_at) VALUES (?,?,?,?)');
        $insert->execute([$classId, $user['id'], encrypt_totp_secret($secret), $expiresAt]);
        $sessionId = (int)$pdo->lastInsertId();
        $pdo->commit();
        echo json_encode([
            'active' => true,
            'session_id' => $sessionId,
            'code' => totp_code($secret),
            'seconds_remaining' => 30 - (time() % 30),
            'expires_at' => $expiresAt,
            'expires_label' => date('g:i A', strtotime($expiresAt)),
        ]);
        exit;
    }

    if ($action === 'end') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $session = $sessionId > 0 ? authorized_session($pdo, $user, $sessionId) : null;
        if (!$session) {
            session_json_error(404, 'Attendance session not found.');
        }
        if ($session['finalized_at'] === null) {
            finalize_attendance_session($pdo, $sessionId, true);
        }
        echo json_encode(['active' => false, 'ended' => true]);
        exit;
    }

    session_json_error(400, 'Choose a valid attendance session action.');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('AttendEase session error: ' . $error->getMessage());
    session_json_error(500, 'The attendance session could not be processed. Check the server configuration and try again.');
}
