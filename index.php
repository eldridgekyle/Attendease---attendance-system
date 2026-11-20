<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$next = safe_next_url((string)($_GET['next'] ?? $_POST['next'] ?? 'dashboard.php'));
if (current_user()) {
    header('Location: ' . $next);
    exit;
}
$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? 'login');
$mode = in_array($mode, ['login', 'signup'], true) ? $mode : 'login';
$error = match ((string)($_GET['error'] ?? '')) {
    'inactive' => 'This account is inactive. Contact an administrator.',
    'reauthenticate' => 'Your account security details changed. Sign in again.',
    'password_updated' => 'Your password was updated. Sign in with your new password.',
    default => '',
};
$signupClasses = db()->query('SELECT id,class_code,subject_name FROM classes ORDER BY class_code')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'login');
    if ($action === 'signup') {
        $mode = 'signup';
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $studentNumber = strtoupper(trim((string)($_POST['student_number'] ?? '')));
        $programYear = trim((string)($_POST['program_year'] ?? ''));
        $section = strtoupper(trim((string)($_POST['section'] ?? '')));
        $classId = (int)($_POST['class_id'] ?? 0);
        $validClassIds = array_map('intval', array_column($signupClasses, 'id'));
        $emailDomain = strtolower(trim((string)(getenv('ATTENDEASE_EMAIL_DOMAIN') ?: '')));
        $emailMatchesInstitution = $emailDomain === '' || strtolower((string)substr(strrchr($email, '@') ?: '', 1)) === ltrim($emailDomain, '@');
        if (text_length($name) < 2 || text_length($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$emailMatchesInstitution
            || text_length($password) < 10 || $studentNumber === '' || $programYear === '' || $section === ''
            || !in_array($classId, $validClassIds, true)) {
            $error = $emailMatchesInstitution ? 'Complete each field, choose a class, and use a password with at least 10 characters.' : 'Use your institutional email address.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'The password and confirmation do not match.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $insertUser = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,'student')");
                $insertUser->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();
                $insertStudent = $pdo->prepare('INSERT INTO students (user_id,student_number,program_year,section) VALUES (?,?,?,?)');
                $insertStudent->execute([$userId, $studentNumber, $programYear, $section]);
                $studentId = (int)$pdo->lastInsertId();
                $insertEnrollment = $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,?)');
                $insertEnrollment->execute([$studentId, $classId]);
                $notice = $pdo->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')");
                $notice->execute([$userId, 'Welcome to AttendEase', 'Your account is ready. Review your classes and check in with the current code shared by your instructor.']);
                $pdo->commit();
                rotate_session_after_login(['id'=>$userId,'full_name'=>$name,'email'=>$email,'role'=>'student','avatar_url'=>null,'created_at'=>date('Y-m-d H:i:s'),'auth_version'=>0]);
                header('Location: dashboard.php');
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = (string)$exception->getCode() === '23000'
                    ? 'That email or student number is already registered.'
                    : 'We could not create your account. Check the details and try again.';
            }
        }
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address and password.';
        } else {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $ipHash = hash_hmac('sha256', $ip, app_key());
            $pdo = db();
            $rate = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_hash=? AND attempted_at>=NOW()-INTERVAL 15 MINUTE');
            $rate->execute([$email, $ipHash]);
            if ((int)$rate->fetchColumn() >= 8) {
                $error = 'Too many sign-in attempts. Try again in 15 minutes.';
            } else {
                $query = $pdo->prepare('SELECT id,full_name,email,password_hash,role,avatar_url,created_at,auth_version FROM users WHERE email=? AND is_active=1 LIMIT 1');
                $query->execute([$email]);
                $account = $query->fetch();
                $dummyHash = '$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.';
                $hash = $account ? (string)$account['password_hash'] : $dummyHash;
                $valid = password_verify($password, $hash);
                if ($account && $valid) {
                    $pdo->prepare('DELETE FROM login_attempts WHERE email=? AND ip_hash=?')->execute([$email, $ipHash]);
                    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
                    }
                    unset($account['password_hash']);
                    rotate_session_after_login($account);
                    header('Location: ' . $next);
                    exit;
                }
                $pdo->prepare('INSERT INTO login_attempts (email,ip_hash) VALUES (?,?)')->execute([$email, $ipHash]);
                $error = 'That email and password combination doesn’t look right.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $mode === 'signup' ? 'Create account' : 'Sign in' ?> · AttendEase</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="login-page">
  <section class="login-brand-panel">
    <a class="brand login-brand" href="index.php"><span class="brand-mark"><i class="bi bi-check2-circle"></i></span><span>Attend<span class="brand-light">Ease</span></span></a>
    <div class="login-brand-content">
      <div class="eyebrow-light"><span></span> SMART ATTENDANCE PLATFORM</div>
      <h1>Attendance made simple and reliable.</h1>
      <p>Spend less time tracking check-ins and more time focused on learning. Your classes, attendance, and updates stay in sync.</p>
      <div class="login-feature-list">
        <div class="login-feature"><i class="bi bi-clock-history"></i> Current six-digit attendance codes</div>
        <div class="login-feature"><i class="bi bi-graph-up-arrow"></i> Clear attendance trends for every class</div>
        <div class="login-feature"><i class="bi bi-shield-check"></i> Role-based access for students and staff</div>
      </div>
      <div class="login-illustration"><div class="orbit"></div><div class="illustration-card"><div class="illus-check"><i class="bi bi-check-lg"></i></div><div class="illus-caption">You’re all checked in</div><div class="illus-time">08:02 <small>AM</small></div></div></div>
    </div>
    <div class="login-panel-footer"><span>Simple, secure, seamless.</span><span><i class="bi bi-stars"></i></span></div>
  </section>
  <section class="login-form-panel">
    <div class="login-form-wrap">
      <a class="mobile-login-brand brand" href="index.php"><span class="brand-mark"><i class="bi bi-check2-circle"></i></span><span>Attend<span class="brand-light">Ease</span></span></a>
      <div class="login-welcome"><div class="welcome-icon"><i class="bi <?= $mode === 'signup' ? 'bi-person-plus' : 'bi-stars' ?>"></i></div><p class="overline"><?= $mode === 'signup' ? 'GET STARTED' : 'WELCOME BACK' ?></p><h2><?= $mode === 'signup' ? 'Create your student account' : 'Sign in to AttendEase' ?></h2><p class="muted-copy"><?= $mode === 'signup' ? 'Join your classes and keep your attendance in view.' : 'Your classroom, all in one place.' ?></p></div>
      <?php if ($error): ?><div class="alert alert-danger py-2 app-alert"><?= e($error) ?></div><?php endif; ?>
      <?php if ($mode === 'signup'): ?>
        <form method="post" class="login-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="signup"><input type="hidden" name="next" value="<?= e($next) ?>">
          <label class="form-label" for="full_name">Full name</label><div class="input-with-icon"><i class="bi bi-person"></i><input class="form-control" id="full_name" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" autocomplete="name" placeholder="Your full name" maxlength="120" required></div>
          <label class="form-label" for="email">School email</label><div class="input-with-icon"><i class="bi bi-envelope"></i><input class="form-control" id="email" name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" placeholder="you@school.edu" required></div>
          <div class="row g-2"><div class="col-7"><label class="form-label" for="student_number">Student number</label><input class="form-control mb-3" id="student_number" name="student_number" value="<?= e($_POST['student_number'] ?? '') ?>" placeholder="2026-00123" required></div><div class="col-5"><label class="form-label" for="section">Section</label><input class="form-control mb-3" id="section" name="section" value="<?= e($_POST['section'] ?? '') ?>" placeholder="IT-2A" required></div></div>
          <label class="form-label" for="program_year">Program and year</label><input class="form-control mb-3" id="program_year" name="program_year" value="<?= e($_POST['program_year'] ?? '') ?>" placeholder="BSIT · Year 2" required>
          <label class="form-label" for="class_id">First class</label><select class="form-select mb-3" id="class_id" name="class_id" required><option value="">Choose your class</option><?php foreach ($signupClasses as $class): ?><option value="<?= (int)$class['id'] ?>" <?= (int)($_POST['class_id']??0)===(int)$class['id']?'selected':'' ?>><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select>
          <label class="form-label" for="password">Create password</label><div class="input-with-icon"><i class="bi bi-lock"></i><input class="form-control" id="password" name="password" type="password" minlength="10" autocomplete="new-password" placeholder="At least 10 characters" required></div>
          <label class="form-label" for="password_confirm">Confirm password</label><div class="input-with-icon mb-3"><i class="bi bi-lock-fill"></i><input class="form-control" id="password_confirm" name="password_confirm" type="password" minlength="10" autocomplete="new-password" placeholder="Enter the same password" required></div>
          <button class="btn btn-primary w-100 sign-in-btn" type="submit">Create student account <span>→</span></button>
        </form>
        <p class="signup-prompt">Already have an account? <a href="index.php">Sign in</a></p>
      <?php else: ?>
        <form method="post" class="login-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login"><input type="hidden" name="next" value="<?= e($next) ?>">
          <label for="email" class="form-label">Email address</label><div class="input-with-icon"><i class="bi bi-envelope"></i><input id="email" name="email" type="email" class="form-control" placeholder="you@school.edu" autocomplete="username" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <label for="password" class="form-label">Password</label><div class="input-with-icon"><i class="bi bi-lock"></i><input id="password" name="password" type="password" class="form-control" placeholder="Enter your password" autocomplete="current-password" required></div>
          <div class="remember-row"><span>Secure sign-in</span><a href="password_reset.php">Forgot password?</a></div>
          <button class="btn btn-primary w-100 sign-in-btn" type="submit">Sign in <span>→</span></button>
        </form>
        <div class="login-secure"><i class="bi bi-shield-lock"></i> Your information is protected and secure</div>
        <p class="signup-prompt">New student? <a href="index.php?mode=signup">Create an account</a></p>
      <?php endif; ?>
    </div>
    <div class="login-copyright">© <?= date('Y') ?> AttendEase. All rights reserved.</div>
  </section>
</body>
</html>
