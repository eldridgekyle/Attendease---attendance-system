<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_login();
$pdo = db();
$user = current_user();
$student = $user['role'] === 'student' ? student_record() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = (string)($_POST['current_password'] ?? '');
    $next = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $check = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND is_active=1');
    $check->execute([$user['id']]);
    $hash = $check->fetchColumn();
    if (!$hash || !password_verify($current, (string)$hash)) {
        flash('error', 'Your current password is incorrect.');
    } elseif (text_length($next) < 10) {
        flash('error', 'Use at least 10 characters for your new password.');
    } elseif ($next !== $confirm) {
        flash('error', 'The new password and confirmation do not match.');
    } else {
        $update = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $update->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);
        flash('success', 'Your password has been updated.');
    }
    header('Location: profile.php');
    exit;
}

if ($student) {
    $courseQuery = $pdo->prepare('SELECT c.class_code,c.subject_name,c.schedule_day,c.start_time,c.end_time,c.room,u.full_name AS teacher_name FROM enrollments e JOIN classes c ON c.id=e.class_id JOIN users u ON u.id=c.teacher_id WHERE e.student_id=? ORDER BY FIELD(c.schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday"),c.start_time');
    $courseQuery->execute([$student['id']]);
    $courses = $courseQuery->fetchAll();
    $summaryQuery = $pdo->prepare("SELECT COUNT(*) total,SUM(status='Present') present,SUM(status='Late') late,SUM(status='Absent') absent FROM attendance_logs WHERE student_id=?");
    $summaryQuery->execute([$student['id']]);
    $summary = $summaryQuery->fetch();
} elseif ($user['role'] === 'student') {
    $courses = [];
    $summary = ['total'=>0,'present'=>0,'late'=>0,'absent'=>0];
} elseif ($user['role'] === 'teacher') {
    $courseQuery = $pdo->prepare('SELECT class_code,subject_name,schedule_day,start_time,end_time,room FROM classes WHERE teacher_id=? ORDER BY FIELD(schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday"),start_time');
    $courseQuery->execute([$user['id']]);
    $courses = $courseQuery->fetchAll();
    $summary = ['total'=>0,'present'=>0,'late'=>0,'absent'=>0];
} else {
    $courseQuery = $pdo->query('SELECT class_code,subject_name,schedule_day,start_time,end_time,room FROM classes ORDER BY FIELD(schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday"),start_time');
    $courses = $courseQuery->fetchAll();
    $summary = ['total'=>0,'present'=>0,'late'=>0,'absent'=>0];
}
page_start('Profile & security','profile.php');
?>
<div class="page-heading"><div><p class="overline">ACCOUNT SETTINGS</p><h1>Profile & security</h1><p class="heading-subtitle">Manage your account details and password.</p></div></div>
<div class="profile-layout">
  <section class="content-card profile-card"><div class="profile-cover"><div class="profile-photo"><?= e(initials($user['full_name'])) ?></div><span class="profile-status"><?= $student ? status_badge((int)$student['is_active'] ? 'Active' : 'Inactive') : '<span class="status-pill status-info"><i></i>'.e(role_label($user['role'])).' account</span>' ?></span></div><div class="profile-main"><p class="overline">ACCOUNT PROFILE</p><h2><?= e($user['full_name']) ?></h2><p class="profile-email"><?= e($user['email']) ?></p><div class="profile-divider"></div><div class="profile-detail"><span>Account role</span><strong><?= e(role_label($user['role'])) ?></strong></div><?php if($student): ?><div class="profile-detail"><span>Student number</span><strong><?= e($student['student_number']) ?></strong></div><div class="profile-detail"><span>Program and year</span><strong><?= e($student['program_year']) ?></strong></div><div class="profile-detail"><span>Section</span><strong><?= e($student['section']) ?></strong></div><?php endif; ?><div class="profile-detail"><span>Member since</span><strong><?= e(date('F Y',strtotime($user['created_at']??'now'))) ?></strong></div>
    <div class="profile-divider mt-3"></div><h3 class="h6 mt-3 mb-2">Update password</h3><form method="post"><?= csrf_field() ?><div class="mb-2"><label class="field-label" for="currentPassword">CURRENT PASSWORD</label><input id="currentPassword" name="current_password" type="password" class="form-control" autocomplete="current-password" required></div><div class="mb-2"><label class="field-label" for="newPassword">NEW PASSWORD</label><input id="newPassword" name="new_password" type="password" minlength="10" class="form-control" autocomplete="new-password" required></div><div class="mb-3"><label class="field-label" for="confirmPassword">CONFIRM NEW PASSWORD</label><input id="confirmPassword" name="confirm_password" type="password" minlength="10" class="form-control" autocomplete="new-password" required></div><button class="btn btn-outline-secondary w-100">Update password <i class="bi bi-arrow-right ms-1"></i></button></form>
  </div></section>
  <div class="d-grid gap-3"><section class="content-card p-3"><div class="card-heading p-0 pb-2"><div><h2><?= $student?'Enrolled courses':($user['role']==='teacher'?'Assigned courses':'Class directory') ?></h2><p><?= count($courses) ?> courses in your schedule</p></div><span class="stat-icon icon-blue"><i class="bi bi-journal-bookmark"></i></span></div><?php if(!$courses): ?><p class="helper-copy mb-0 pt-2">No courses have been assigned to this account.</p><?php else: foreach($courses as $course): ?><div class="scan-feed"><span class="avatar"><i class="bi bi-book"></i></span><span class="scan-feed-copy"><strong><?= e($course['class_code'].' · '.$course['subject_name']) ?></strong><small><?= e($course['schedule_day'].' · '.date('g:i A',strtotime($course['start_time'])).'–'.date('g:i A',strtotime($course['end_time'])).' · '.$course['room']) ?><?php if(isset($course['teacher_name'])): ?> · <?= e($course['teacher_name']) ?><?php endif; ?></small></span></div><?php endforeach; endif; ?></section>
  <?php if($student): ?><section class="content-card profile-summary"><h2>Your attendance</h2><div class="summary-metric"><strong><?= number_format((int)($summary['total']??0)) ?></strong><span>Total records</span></div><div class="summary-metric"><strong><?= number_format((int)($summary['present']??0)) ?></strong><span>Present</span></div><div class="summary-metric"><strong><?= number_format((int)($summary['late']??0)) ?></strong><span>Late</span></div><div class="summary-metric"><strong><?= number_format((int)($summary['absent']??0)) ?></strong><span>Absent</span></div></section><?php endif; ?></div>
</div>
<?php page_end(); ?>
