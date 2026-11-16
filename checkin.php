<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_login();
$user = current_user();
if ($user['role'] !== 'student') {
    http_response_code(403);
    page_start('Student check-in','attendance_logs.php');
    echo '<section class="content-card p-4"><h1 class="h4">Student check-in</h1><p>Sign in with a student account to submit attendance.</p><a class="btn btn-primary" href="attendance_logs.php">Return to attendance</a></section>';
    page_end();
    exit;
}
$student = student_record();
$sessionId = (int)($_POST['session_id'] ?? $_GET['session'] ?? 0);
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$classId = (int)($_POST['class_id'] ?? 0);
$pin = trim((string)($_POST['pin'] ?? ''));
$message = '';
$messageType = 'info';
$sessionRow = null;
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$student) {
        $message = 'Your student profile is not linked yet. Contact your administrator.';
        $messageType = 'danger';
    } else {
        if ($sessionId > 0 && $token !== '') {
            $query = $pdo->prepare('SELECT id,class_id FROM attendance_sessions WHERE id=? AND qr_token_hash=? AND expires_at>NOW()');
            $query->execute([$sessionId,hash('sha256',$token)]);
            $sessionRow = $query->fetch();
        } elseif ($classId > 0 && preg_match('/^\d{6}$/',$pin)) {
            $query = $pdo->prepare('SELECT id,class_id,pin_hash FROM attendance_sessions WHERE class_id=? AND expires_at>NOW() ORDER BY created_at DESC LIMIT 25');
            $query->execute([$classId]);
            foreach ($query->fetchAll() as $candidate) {
                if (password_verify($pin,$candidate['pin_hash'])) { $sessionRow = $candidate; break; }
            }
        }
        if (!$sessionRow) {
            $message = 'That PIN or QR session has expired or is not valid for this class.';
            $messageType = 'danger';
        } else {
            $enrolled = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id=? AND class_id=?');
            $enrolled->execute([$student['id'],$sessionRow['class_id']]);
            if (!$enrolled->fetchColumn()) {
                $message = 'You are not enrolled in the class linked to this check-in.';
                $messageType = 'danger';
            } else {
                $pdo->beginTransaction();
                $existing = $pdo->prepare('SELECT id,status FROM attendance_logs WHERE student_id=? AND class_id=? AND attendance_date=CURDATE() FOR UPDATE');
                $existing->execute([$student['id'],$sessionRow['class_id']]);
                $record = $existing->fetch();
                if ($record && $record['status'] !== 'Absent') {
                    $pdo->commit();
                    $message = 'Your attendance is already recorded for this class today.';
                    $messageType = 'success';
                } else {
                    $classQuery = $pdo->prepare('SELECT start_time FROM classes WHERE id=?');
                    $classQuery->execute([$sessionRow['class_id']]);
                    $startTime = (string)$classQuery->fetchColumn();
                    $today = date('H:i:s');
                    $status = $today > $startTime ? 'Late' : 'Present';
                    if ($record) {
                        $save = $pdo->prepare("UPDATE attendance_logs SET time_in=CURTIME(),status=?,verification_method='Manual Override',remarks='One-time PIN or QR check-in' WHERE id=?");
                        $save->execute([$status,$record['id']]);
                    } else {
                        $save = $pdo->prepare("INSERT INTO attendance_logs (student_id,class_id,attendance_date,time_in,status,verification_method,remarks) VALUES (?,?,CURDATE(),CURTIME(),?,'Manual Override','One-time PIN or QR check-in')");
                        $save->execute([$student['id'],$sessionRow['class_id'],$status]);
                    }
                    $pdo->commit();
                    $message = 'Check-in complete. You were marked '.$status.'.';
                    $messageType = 'success';
                }
            }
        }
    }
} elseif ($sessionId > 0 && $token !== '') {
    $query = $pdo->prepare('SELECT s.id,s.class_id,c.class_code,c.subject_name,c.start_time,c.end_time,c.room FROM attendance_sessions s JOIN classes c ON c.id=s.class_id WHERE s.id=? AND s.qr_token_hash=? AND s.expires_at>NOW()');
    $query->execute([$sessionId,hash('sha256',$token)]);
    $sessionRow = $query->fetch();
    if (!$sessionRow) { $message = 'This QR check-in session has expired. Ask your instructor for a new PIN or QR code.'; $messageType = 'danger'; }
}
if ($sessionRow && empty($sessionRow['class_code'])) {
    $classQuery = $pdo->prepare('SELECT class_code,subject_name,start_time,end_time,room FROM classes WHERE id=?');
    $classQuery->execute([$sessionRow['class_id']]);
    $sessionRow = array_merge($sessionRow,$classQuery->fetch() ?: []);
}
page_start('Student check-in','attendance_logs.php');
?>
<div class="page-heading"><div><p class="overline">QUICK ATTENDANCE</p><h1>Class check-in</h1><p class="heading-subtitle">Confirm your attendance using your instructor’s active PIN or QR code.</p></div></div>
<div class="row justify-content-center"><div class="col-12 col-xl-8">
<?php if ($message): ?><div class="alert alert-<?= e($messageType) ?> app-alert"><i class="bi bi-<?= $messageType==='success'?'check-circle':'info-circle' ?> me-2"></i><?= e($message) ?><?php if ($messageType==='success'): ?><a class="alert-link ms-2" href="attendance_logs.php">View your records</a><?php endif; ?></div><?php endif; ?>
<?php if ($sessionRow && $messageType !== 'success'): ?><section class="content-card p-4 mb-3"><div class="d-flex align-items-start gap-3"><span class="face-icon"><i class="bi bi-qr-code-scan"></i></span><div><p class="overline mb-1">ACTIVE CLASS SESSION</p><h2 class="h5 mb-1"><?= e($sessionRow['class_code']) ?> · <?= e($sessionRow['subject_name']) ?></h2><p class="camera-status mb-0"><?= e(date('g:i A',strtotime($sessionRow['start_time'])).'–'.date('g:i A',strtotime($sessionRow['end_time'])).' · '.$sessionRow['room']) ?></p></div></div><form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="session_id" value="<?= (int)$sessionRow['id'] ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1"></i> Confirm class check-in</button></form></section><?php endif; ?>
<?php if (!$sessionRow || $messageType === 'danger'): ?><section class="content-card p-4"><div class="card-heading px-0 pt-0"><div><h2>Enter your class PIN</h2><p>Ask your teacher for a six-digit code that is currently active.</p></div><span class="face-icon"><i class="bi bi-key"></i></span></div><form method="post" class="row g-3"><?= csrf_field() ?><div class="col-12 col-md-7"><label class="field-label" for="pin">SIX-DIGIT PIN</label><input class="form-control" id="pin" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div><div class="col-12 col-md-5"><label class="field-label" for="class_id">CLASS</label><select class="form-select" id="class_id" name="class_id" required><option value="">Choose class</option><?php $classList=$pdo->prepare('SELECT c.id,c.class_code,c.subject_name FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=? ORDER BY c.class_code');$classList->execute([$student['id']??0]);foreach($classList->fetchAll() as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1"></i> Check in</button></div></form></section><?php endif; ?>
</div></div>
<?php page_end(); ?>
