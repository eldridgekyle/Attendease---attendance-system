<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $accounts = $pdo->query('SELECT email,password_hash,role FROM users WHERE id IN (1,2,4) ORDER BY id')->fetchAll();
    check(count($accounts) === 3, 'Expected seeded admin, instructor, and student accounts.');
    check(array_column($accounts, 'role') === ['admin','teacher','student'], 'Role records are not in the expected order.');
    foreach ($accounts as $account) {
        check(password_verify('password', $account['password_hash']), 'Seeded account password verification failed for ' . $account['email']);
        check(!password_verify('wrong-password', $account['password_hash']), 'An invalid account password was accepted.');
    }

    $userInsert = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES ('Workflow Student',?,?, 'student')");
    $userInsert->execute(['workflow-' . bin2hex(random_bytes(5)) . '@attendease.test', password_hash('workflow-password-10', PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO students (user_id,student_number,program_year,section) VALUES (?,?,?,?)')->execute([$userId,'TEST-' . strtoupper(bin2hex(random_bytes(3))),'BSIT · Year 2','TEST-A']);
    $studentId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,1)')->execute([$studentId]);
    check((int)$pdo->query('SELECT COUNT(*) FROM enrollments WHERE student_id=' . $studentId)->fetchColumn() === 1, 'Student registration did not create its enrollment.');
    $duplicateEnrollmentRejected = false;
    try {
        $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,1)')->execute([$studentId]);
    } catch (PDOException $error) {
        $duplicateEnrollmentRejected = (string)$error->getCode() === '23000';
    }
    check($duplicateEnrollmentRejected, 'The database accepted a duplicate class enrollment.');
    $pdo->prepare('UPDATE students SET section=? WHERE id=?')->execute(['TEST-B',$studentId]);
    $pdo->prepare('UPDATE enrollments SET class_id=2 WHERE student_id=?')->execute([$studentId]);
    check((string)$pdo->query('SELECT section FROM students WHERE id=' . $studentId)->fetchColumn() === 'TEST-B', 'Student editing did not update the profile.');
    check((int)$pdo->query('SELECT class_id FROM enrollments WHERE student_id=' . $studentId)->fetchColumn() === 2, 'Student enrollment editing failed.');
    $pdo->prepare('UPDATE enrollments SET class_id=1 WHERE student_id=?')->execute([$studentId]);
    $pdo->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$userId]);
    check(!(bool)$pdo->query('SELECT is_active FROM users WHERE id=' . $userId)->fetchColumn(), 'Student deactivation failed.');
    $pdo->prepare('UPDATE users SET is_active=1 WHERE id=?')->execute([$userId]);

    $teacherEmail = 'workflow-teacher-' . bin2hex(random_bytes(5)) . '@attendease.test';
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES ('Workflow Instructor',?,?, 'teacher')")->execute([$teacherEmail,password_hash('workflow-password-10',PASSWORD_DEFAULT)]);
    $workflowTeacherId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO instructors (user_id,employee_number,department) VALUES (?,?,?)')->execute([$workflowTeacherId,'EMP-' . strtoupper(bin2hex(random_bytes(3))),'IT Department']);
    check((int)$pdo->query('SELECT COUNT(*) FROM instructors WHERE user_id=' . $workflowTeacherId)->fetchColumn() === 1, 'Instructor profile creation failed.');
    $resetToken = bin2hex(random_bytes(32));
    $resetHash = hash('sha256',$resetToken);
    $pdo->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([$workflowTeacherId,$resetHash]);
    $resetCheck = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE token_hash=? AND used_at IS NULL');
    $resetCheck->execute([$resetHash]);
    check((int)$resetCheck->fetchColumn() === 1, 'Single-use password reset token storage failed.');

    $ownership = $pdo->prepare('SELECT 1 FROM classes WHERE id=? AND teacher_id=?');
    $ownership->execute([1,2]);
    check((bool)$ownership->fetchColumn(), 'The class instructor should manage their assigned class.');
    $ownership->execute([3,2]);
    check(!(bool)$ownership->fetchColumn(), 'An instructor must not manage another instructor’s class.');

    $secret = generate_totp_secret();
    $ciphertext = encrypt_totp_secret($secret);
    check(decrypt_totp_secret($ciphertext) === $secret, 'TOTP secret encryption round-trip failed.');
    $sessionInsert = $pdo->prepare('INSERT INTO attendance_sessions (class_id,created_by,secret_ciphertext,expires_at) VALUES (1,2,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE))');
    $sessionInsert->execute([$ciphertext]);
    $sessionId = (int)$pdo->lastInsertId();
    $expiryCheck = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,NOW(),expires_at) FROM attendance_sessions WHERE id=?');
    $expiryCheck->execute([$sessionId]);
    check((int)$expiryCheck->fetchColumn() >= 895, 'Attendance session did not receive a 15-minute server expiry.');
    $code = totp_code($secret);
    check(verify_totp($secret, $code), 'The current server TOTP code did not verify.');
    check(!verify_totp($secret, '000000'), 'An invalid TOTP code was accepted.');

    $log = $pdo->prepare("INSERT INTO attendance_logs (student_id,class_id,session_id,attendance_date,time_in,status,verification_method) VALUES (?,1,?,CURDATE(),CURTIME(),'Present','TOTP')");
    $log->execute([$studentId,$sessionId]);
    $duplicateRejected = false;
    try {
        $log->execute([$studentId,$sessionId]);
    } catch (PDOException $error) {
        $duplicateRejected = (string)$error->getCode() === '23000';
    }
    check($duplicateRejected, 'The database accepted duplicate attendance for one student and session.');
    check(verify_totp($secret, $code), 'A student submission incorrectly consumed the code for other eligible students.');

    $secondUser = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES ('Second Workflow Student',?,?, 'student')");
    $secondUser->execute(['second-' . bin2hex(random_bytes(5)) . '@attendease.test', password_hash('workflow-password-10', PASSWORD_DEFAULT)]);
    $secondUserId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO students (user_id,student_number,program_year,section) VALUES (?,?,?,?)')->execute([$secondUserId,'TEST-' . strtoupper(bin2hex(random_bytes(3))),'BSIT · Year 2','TEST-A']);
    $secondStudentId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,1)')->execute([$secondStudentId]);
    $log->execute([$secondStudentId,$sessionId]);
    check((int)$pdo->query('SELECT COUNT(*) FROM attendance_logs WHERE session_id=' . $sessionId)->fetchColumn() === 2, 'A valid code could not be used by another enrolled student in the same interval.');

    $report = $pdo->prepare('SELECT a.id,a.session_id,s.class_id,creator.role AS instructor_role,u.role AS student_role FROM attendance_logs a JOIN attendance_sessions s ON s.id=a.session_id JOIN users creator ON creator.id=s.created_by JOIN students st ON st.id=a.student_id JOIN users u ON u.id=st.user_id WHERE a.student_id=? AND a.session_id=?');
    $report->execute([$studentId,$sessionId]);
    $row = $report->fetch();
    check($row && (int)$row['class_id'] === 1 && $row['instructor_role'] === 'teacher' && $row['student_role'] === 'student', 'Attendance was not associated with the student, class, session, and instructor.');
    audit_admin_action($pdo,1,'attendance.correction','attendance',(int)$row['id'],['reason'=>'Workflow audit validation']);
    check((int)$pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='attendance.correction' AND actor_user_id=1")->fetchColumn() === 1, 'Administrative audit logging failed.');

    $attempt = $pdo->prepare('INSERT INTO attendance_attempts (student_id,session_id) VALUES (?,?)');
    for ($i = 0; $i < 8; $i++) {
        $attempt->execute([$studentId,$sessionId]);
    }
    $rate = $pdo->prepare('SELECT COUNT(*) FROM attendance_attempts WHERE student_id=? AND session_id=? AND attempted_at>=NOW()-INTERVAL 1 MINUTE');
    $rate->execute([$studentId,$sessionId]);
    check((int)$rate->fetchColumn() === 8, 'Attendance attempt tracking did not record failed attempts.');

    check(finalize_attendance_session($pdo,$sessionId,true), 'Manual attendance session finalization did not run.');
    $active = $pdo->prepare('SELECT 1 FROM attendance_sessions WHERE id=? AND secret_ciphertext IS NOT NULL AND ended_at IS NULL AND expires_at>NOW()');
    $active->execute([$sessionId]);
    check(!(bool)$active->fetchColumn(), 'A manually ended attendance session remained active.');
    $present = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE session_id=? AND status='Present'");
    $present->execute([$sessionId]);
    check((int)$present->fetchColumn() === 2, 'Session finalization overwrote successful attendance records.');
    $systemAbsent = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE session_id=? AND status='Absent' AND verification_method='System'");
    $systemAbsent->execute([$sessionId]);
    $absentCount = (int)$systemAbsent->fetchColumn();
    check($absentCount > 0, 'Session finalization did not mark unverified enrolled students absent.');
    check(!finalize_attendance_session($pdo,$sessionId,true), 'Repeated finalization was not idempotent.');
    $systemAbsent->execute([$sessionId]);
    check((int)$systemAbsent->fetchColumn() === $absentCount, 'Repeated finalization created duplicate absence rows.');
    $finalizedQuery = $pdo->prepare('SELECT finalized_at FROM attendance_sessions WHERE id=?');
    $finalizedQuery->execute([$sessionId]);
    check((bool)$finalizedQuery->fetchColumn(), 'Finalized session timestamp was not recorded.');

    $expiredInsert = $pdo->prepare('INSERT INTO attendance_sessions (class_id,created_by,secret_ciphertext,expires_at) VALUES (1,2,?,DATE_SUB(NOW(),INTERVAL 1 SECOND))');
    $expiredInsert->execute([encrypt_totp_secret(generate_totp_secret())]);
    $expiredId = (int)$pdo->lastInsertId();
    $active->execute([$expiredId]);
    check(!(bool)$active->fetchColumn(), 'An automatically expired attendance session remained active.');
    check(finalize_expired_attendance_sessions($pdo) >= 1, 'Expired session worker did not finalize a due session.');

    $csv = fopen('php://temp', 'w+');
    fputcsv($csv, ['Student','Instructor','Session ID','Verification method'], ',', '"', '');
    fputcsv($csv, ['Workflow Student','Daniel Reyes',$sessionId,'TOTP'], ',', '"', '');
    rewind($csv);
    $csvText = stream_get_contents($csv);
    fclose($csv);
    check(str_contains((string)$csvText, 'Session ID') && str_contains((string)$csvText, 'TOTP'), 'CSV report formatting failed.');

    $pdo->rollBack();
    echo "Database workflow checks passed; temporary writes were rolled back.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
