<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_role(['student']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendance_logs.php');
    exit;
}
verify_csrf();
$student = student_record();
if (!$student) {
    flash('error', 'Your student profile is not linked yet. Contact your administrator.');
    header('Location: attendance_logs.php');
    exit;
}

$classId = (int)($_POST['class_id'] ?? 0);
$code = trim((string)($_POST['code'] ?? ''));
if ($classId < 1 || preg_match('/^\d{6}$/D', $code) !== 1) {
    flash('error', 'Choose your enrolled class and enter the current six-digit code.');
    header('Location: attendance_logs.php');
    exit;
}

$pdo = db();
try {
    $enrolled = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id=? AND class_id=?');
    $enrolled->execute([$student['id'], $classId]);
    if (!$enrolled->fetchColumn()) {
        flash('error', 'You are not enrolled in that class.');
        header('Location: attendance_logs.php');
        exit;
    }

    $pdo->beginTransaction();
    $sessionQuery = $pdo->prepare("SELECT s.id,s.class_id,s.secret_ciphertext,s.expires_at,s.ended_at,c.start_time FROM attendance_sessions s JOIN classes c ON c.id=s.class_id WHERE s.class_id=? AND s.secret_ciphertext IS NOT NULL AND s.ended_at IS NULL AND s.expires_at>NOW() ORDER BY s.id DESC LIMIT 1 FOR UPDATE");
    $sessionQuery->execute([$classId]);
    $session = $sessionQuery->fetch();
    if (!$session) {
        $pdo->rollBack();
        flash('error', 'There is no active attendance session for this class. Ask your instructor to start one.');
        header('Location: attendance_logs.php');
        exit;
    }

    $existing = $pdo->prepare('SELECT id FROM attendance_logs WHERE student_id=? AND session_id=? LIMIT 1');
    $existing->execute([$student['id'], $session['id']]);
    if ($existing->fetchColumn()) {
        $pdo->commit();
        flash('success', 'Your attendance is already recorded for this session.');
        header('Location: attendance_logs.php');
        exit;
    }

    $attempts = $pdo->prepare('SELECT COUNT(*) FROM attendance_attempts WHERE student_id=? AND session_id=? AND attempted_at>=NOW()-INTERVAL 1 MINUTE');
    $attempts->execute([$student['id'], $session['id']]);
    if ((int)$attempts->fetchColumn() >= 8) {
        $pdo->rollBack();
        flash('error', 'Too many incorrect codes. Wait one minute and try again.');
        header('Location: attendance_logs.php');
        exit;
    }

    $secret = decrypt_totp_secret((string)$session['secret_ciphertext']);
    if (!verify_totp($secret, $code)) {
        $pdo->prepare('INSERT INTO attendance_attempts (student_id,session_id) VALUES (?,?)')->execute([$student['id'], $session['id']]);
        $pdo->commit();
        flash('error', 'That code is invalid or has expired. Enter the current code displayed by your instructor.');
        header('Location: attendance_logs.php');
        exit;
    }

    $now = $pdo->query('SELECT CURDATE(),CURTIME()')->fetch(PDO::FETCH_NUM);
    $activeCheck = $pdo->prepare('SELECT 1 FROM attendance_sessions WHERE id=? AND secret_ciphertext IS NOT NULL AND ended_at IS NULL AND expires_at>NOW()');
    $activeCheck->execute([$session['id']]);
    if (!$activeCheck->fetchColumn()) {
        $pdo->rollBack();
        flash('error', 'This attendance session ended or expired before your check-in was saved.');
        header('Location: attendance_logs.php');
        exit;
    }
    if (!verify_totp($secret, $code)) {
        $pdo->prepare('INSERT INTO attendance_attempts (student_id,session_id) VALUES (?,?)')->execute([$student['id'], $session['id']]);
        $pdo->commit();
        flash('error', 'That code expired while you were checking in. Enter the new code displayed by your instructor.');
        header('Location: attendance_logs.php');
        exit;
    }
    $status = (string)$now[1] > (string)$session['start_time'] ? 'Late' : 'Present';
    $legacyAbsent = $pdo->prepare("SELECT id FROM attendance_logs WHERE student_id=? AND class_id=? AND attendance_date=? AND session_id IS NULL AND status='Absent' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $legacyAbsent->execute([$student['id'],$classId,$now[0]]);
    $legacyId = $legacyAbsent->fetchColumn();
    if ($legacyId) {
        $save = $pdo->prepare("UPDATE attendance_logs SET session_id=?,time_in=?,time_out=NULL,status=?,verification_method='TOTP',remarks=NULL WHERE id=?");
        $save->execute([$session['id'],$now[1],$status,$legacyId]);
    } else {
        $save = $pdo->prepare("INSERT INTO attendance_logs (student_id,class_id,session_id,attendance_date,time_in,status,verification_method) VALUES (?,?,?,?,?,?,'TOTP')");
        $save->execute([$student['id'], $classId, $session['id'], $now[0], $now[1], $status]);
    }
    $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,\'success\')')
        ->execute([$student['user_id'], 'Attendance recorded', 'Your attendance was recorded for this class session.']);
    $pdo->commit();
    flash('success', 'Check-in complete. You were marked ' . $status . '.');
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((string)$error->getCode() === '23000') {
        flash('success', 'Your attendance is already recorded for this session.');
    } else {
        error_log('AttendEase check-in error: ' . $error->getMessage());
        flash('error', 'Attendance could not be recorded. Please try again.');
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('AttendEase check-in error: ' . $error->getMessage());
    flash('error', 'Attendance could not be verified. Check the server configuration and try again.');
}
header('Location: attendance_logs.php');
exit;
