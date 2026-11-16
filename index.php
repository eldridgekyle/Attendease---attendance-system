<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$nextInput = (string)($_GET['next'] ?? $_POST['next'] ?? '');
$next = preg_match('/^checkin\.php\?session=\d+&token=[A-Za-z0-9_-]+$/D', $nextInput) ? $nextInput : 'dashboard.php';
if (current_user()) {
    header('Location: ' . $next);
    exit;
}
$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? 'login');
$mode = in_array($mode, ['login', 'signup'], true) ? $mode : 'login';
$error = '';
$signupClasses = db()->query('SELECT id,class_code,subject_name FROM classes ORDER BY class_code')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'login');
    if ($action === 'signup') {
        $mode = 'signup';
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $studentNumber = strtoupper(trim((string)($_POST['student_number'] ?? '')));
        $programYear = trim((string)($_POST['program_year'] ?? ''));
        $section = strtoupper(trim((string)($_POST['section'] ?? '')));
        $classId = (int)($_POST['class_id'] ?? 0);
        $validClassIds = array_map('intval', array_column($signupClasses, 'id'));
        if (text_length($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($password) < 8 || $studentNumber === '' || $programYear === '' || $section === '' || !in_array($classId, $validClassIds, true)) {
            $error = 'Complete each field and use a password with at least 8 characters.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $insertUser = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,'student')");
                $insertUser->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();
                $insertStudent = $pdo->prepare("INSERT INTO students (user_id,student_number,program_year,section,face_status) VALUES (?,?,?,?, 'Pending')");
                $insertStudent->execute([$userId, $studentNumber, $programYear, $section]);
                $insertEnrollment = $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,?)');
                $insertEnrollment->execute([(int)$pdo->lastInsertId(), $classId]);
                $notice = $pdo->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')");
                $notice->execute([$userId, 'Welcome to AttendEase', 'Your account is ready. Join your classes and check in with a one-time PIN or QR code.']);
                $pdo->commit();
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => $userId, 'full_name' => $name, 'email' => $email, 'role' => 'student', 'created_at' => date('Y-m-d H:i:s')];
                header('Location: dashboard.php');
                exit;
            } catch (PDOException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = (string)$exception->getCode() === '23000' ? 'That email or student number is already registered.' : 'We could not create your account. Check the details and try again.';
            }
        }
    } else {
        $demoRole = (string)($_POST['demo_role'] ?? '');
        if (in_array($demoRole, ['admin', 'teacher', 'student'], true)) {
            $statement = db()->prepare('SELECT id,full_name,email,password_hash,role,avatar_url,created_at FROM users WHERE role=? ORDER BY id LIMIT 1');
            $statement->execute([$demoRole]);
        } else {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $statement = db()->prepare('SELECT id,full_name,email,password_hash,role,avatar_url,created_at FROM users WHERE email=? LIMIT 1');
            $statement->execute([$email]);
        }
        $account = $statement->fetch();
        $valid = $demoRole !== '' ? (bool)$account : ($account && password_verify((string)($_POST['password'] ?? ''), $account['password_hash']));
        if ($valid && $account) {
            session_regenerate_id(true);
            unset($account['password_hash']);
            $_SESSION['user'] = $account;
            if (!empty($_POST['remember'])) {
                $lifetime = 60 * 60 * 24 * 30;
                ini_set('session.gc_maxlifetime', (string)$lifetime);
                $cookie = session_get_cookie_params();
                setcookie(session_name(), session_id(), ['expires'=>time()+$lifetime,'path'=>$cookie['path'],'domain'=>$cookie['domain'],'secure'=>$cookie['secure'],'httponly'=>true,'samesite'=>'Lax']);
            }
            header('Location: ' . $next);
            exit;
        }
        $error = 'That email and password combination doesn’t look right.';
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
        <div class="login-feature"><i class="bi bi-qr-code-scan"></i> Quick check-in with an expiring PIN or QR code</div>
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
          <label class="form-label" for="full_name">Full name</label><div class="input-with-icon"><i class="bi bi-person"></i><input class="form-control" id="full_name" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" autocomplete="name" placeholder="Your full name" required></div>
          <label class="form-label" for="email">School email</label><div class="input-with-icon"><i class="bi bi-envelope"></i><input class="form-control" id="email" name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" placeholder="you@school.edu" required></div>
          <div class="row g-2"><div class="col-7"><label class="form-label" for="student_number">Student number</label><input class="form-control mb-3" id="student_number" name="student_number" value="<?= e($_POST['student_number'] ?? '') ?>" placeholder="2026-00123" required></div><div class="col-5"><label class="form-label" for="section">Section</label><input class="form-control mb-3" id="section" name="section" value="<?= e($_POST['section'] ?? '') ?>" placeholder="IT-2A" required></div></div>
          <label class="form-label" for="program_year">Program and year</label><input class="form-control mb-3" id="program_year" name="program_year" value="<?= e($_POST['program_year'] ?? '') ?>" placeholder="BSIT · Year 2" required>
          <label class="form-label" for="class_id">First class</label><select class="form-select mb-3" id="class_id" name="class_id" required><option value="">Choose your class</option><?php foreach ($signupClasses as $class): ?><option value="<?= (int)$class['id'] ?>" <?= (int)($_POST['class_id']??0)===(int)$class['id']?'selected':'' ?>><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select>
          <label class="form-label" for="password">Create password</label><div class="input-with-icon"><i class="bi bi-lock"></i><input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required></div>
          <button class="btn btn-primary w-100 sign-in-btn" type="submit">Create student account <span>→</span></button>
        </form>
        <p class="signup-prompt">Already have an account? <a href="index.php">Sign in</a></p>
      <?php else: ?>
        <form method="post" class="login-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login"><input type="hidden" name="next" value="<?= e($next) ?>">
          <label for="email" class="form-label">Email address</label><div class="input-with-icon"><i class="bi bi-envelope"></i><input id="email" name="email" type="email" class="form-control" placeholder="you@school.edu" autocomplete="username" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div class="password-label"><label for="password" class="form-label">Password</label><a href="mailto:support@attendease.test?subject=Password%20reset">Forgot password?</a></div><div class="input-with-icon"><i class="bi bi-lock"></i><input id="password" name="password" type="password" class="form-control" placeholder="Enter your password" autocomplete="current-password" required></div>
          <div class="remember-row"><label class="form-check"><input class="form-check-input" type="checkbox" name="remember" value="1"><span class="form-check-label">Remember me</span></label><span>Secure sign-in</span></div>
          <button class="btn btn-primary w-100 sign-in-btn" type="submit">Sign in <span>→</span></button>
        </form>
        <div class="login-secure"><i class="bi bi-shield-lock"></i> Your information is protected and secure</div>
        <div class="demo-login"><div class="demo-login-title">Try a demo account</div><div class="demo-login-buttons">
          <?php foreach ([['admin','Admin','bi-shield-lock'],['teacher','Teacher','bi-easel2'],['student','Student','bi-mortarboard']] as [$role,$label,$icon]): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="next" value="<?= e($next) ?>"><input type="hidden" name="demo_role" value="<?= e($role) ?>"><button type="submit"><i class="bi <?= e($icon) ?>"></i>Login as <?= e($label) ?></button></form><?php endforeach; ?>
        </div></div>
        <p class="signup-prompt">New student? <a href="index.php?mode=signup">Create an account</a></p>
      <?php endif; ?>
    </div>
    <div class="login-copyright">© <?= date('Y') ?> AttendEase. All rights reserved.</div>
  </section>
</body>
</html>
