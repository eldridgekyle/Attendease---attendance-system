<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$pdo = db();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'request');
    if ($action === 'reset') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            $error = 'This reset link is invalid or has expired. Request a new one.';
        } elseif (text_length($password) < 10 || $password !== $confirm) {
            $error = $password !== $confirm ? 'The passwords do not match.' : 'Use at least 10 characters for your new password.';
        } else {
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare('SELECT r.id,r.user_id FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>NOW() AND u.is_active=1 FOR UPDATE');
                $query->execute([hash('sha256', $token)]);
                $reset = $query->fetch();
                if (!$reset) {
                    $pdo->rollBack();
                    $error = 'This reset link is invalid or has expired. Request a new one.';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT),$reset['user_id']]);
                    $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?')->execute([$reset['id']]);
                    $pdo->prepare('UPDATE password_resets SET used_at=COALESCE(used_at,NOW()) WHERE user_id=?')->execute([$reset['user_id']]);
                    $pdo->commit();
                    $notice = 'Your password has been reset. You can now sign in.';
                    $token = '';
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('AttendEase password reset error: ' . $exception->getMessage());
                $error = 'The password could not be reset. Request a new link and try again.';
            }
        }
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ipHash = hash_hmac('sha256', $ip, app_key());
        $emailHash = hash_hmac('sha256', $email, app_key());
        $rate = $pdo->prepare('SELECT COUNT(*) FROM password_reset_attempts WHERE (ip_hash=? OR email_hash=?) AND attempted_at>=NOW()-INTERVAL 1 HOUR');
        $rate->execute([$ipHash,$emailHash]);
        if ((int)$rate->fetchColumn() < 5) {
            $pdo->prepare('INSERT INTO password_reset_attempts (email_hash,ip_hash) VALUES (?,?)')->execute([$emailHash,$ipHash]);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $accountQuery = $pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');
                $accountQuery->execute([$email]);
                $userId = $accountQuery->fetchColumn();
                if ($userId) {
                    $baseUrl = rtrim((string)(getenv('ATTENDEASE_BASE_URL') ?: ''), '/');
                    $parts = parse_url($baseUrl);
                    if ($baseUrl !== '' && is_array($parts) && in_array($parts['scheme'] ?? '', ['http','https'], true) && isset($parts['host'])) {
                        try {
                            $rawToken = bin2hex(random_bytes(32));
                            $pdo->beginTransaction();
                            $pdo->prepare('UPDATE password_resets SET used_at=COALESCE(used_at,NOW()) WHERE user_id=?')->execute([$userId]);
                            $pdo->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([$userId,hash('sha256',$rawToken)]);
                            $pdo->commit();
                            $url = $baseUrl . '/password_reset.php?token=' . rawurlencode($rawToken);
                            $subject = 'Reset your AttendEase password';
                            $body = "Use this link within one hour to reset your AttendEase password:\n\n" . $url . "\n\nIf you did not request this, you can ignore this email.";
                            $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
                            $from = (string)(getenv('ATTENDEASE_MAIL_FROM') ?: '');
                            if (filter_var($from, FILTER_VALIDATE_EMAIL)) { $headers .= 'From: AttendEase <' . $from . ">\r\n"; }
                            if (!mail($email, $subject, $body, $headers)) {
                                $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$rawToken)]);
                                error_log('AttendEase could not send a password reset email. Check PHP mail configuration.');
                            }
                        } catch (Throwable $exception) {
                            if ($pdo->inTransaction()) { $pdo->rollBack(); }
                            error_log('AttendEase reset email error: ' . $exception->getMessage());
                        }
                    } else {
                        error_log('AttendEase password reset is unavailable until ATTENDEASE_BASE_URL is configured.');
                    }
                }
            }
        }
        $notice = 'If an active account uses that email address, a password reset link will arrive shortly.';
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Password recovery · AttendEase</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="login-page">
  <section class="login-brand-panel"><a class="brand login-brand" href="index.php"><span class="brand-mark"><i class="bi bi-check2-circle"></i></span><span>Attend<span class="brand-light">Ease</span></span></a><div class="login-brand-content"><div class="eyebrow-light"><span></span> ACCOUNT RECOVERY</div><h1>Get back to your classes.</h1><p>Reset your password securely with a one-time link sent to your account email.</p></div></section>
  <section class="login-form-panel"><div class="login-form-wrap"><div class="login-welcome"><div class="welcome-icon"><i class="bi bi-key"></i></div><p class="overline">PASSWORD HELP</p><h2><?= $token !== '' ? 'Choose a new password' : 'Reset your password' ?></h2><p class="muted-copy"><?= $token !== '' ? 'The link is valid for one hour and can only be used once.' : 'Enter your account email and we’ll send a reset link if it matches an active account.' ?></p></div>
    <?php if ($error): ?><div class="alert alert-danger py-2 app-alert"><?= e($error) ?></div><?php endif; ?><?php if ($notice): ?><div class="alert alert-success py-2 app-alert"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($token !== '' && $notice === ''): ?><form method="post" class="login-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="token" value="<?= e($token) ?>"><label class="form-label" for="password">New password</label><input class="form-control mb-3" id="password" name="password" type="password" minlength="10" autocomplete="new-password" required><label class="form-label" for="confirmPassword">Confirm new password</label><input class="form-control mb-3" id="confirmPassword" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required><button class="btn btn-primary w-100 sign-in-btn" type="submit">Save new password <span>→</span></button></form>
    <?php elseif ($notice === ''): ?><form method="post" class="login-form"><?= csrf_field() ?><input type="hidden" name="action" value="request"><label class="form-label" for="email">Account email</label><input class="form-control mb-3" id="email" name="email" type="email" autocomplete="email" required><button class="btn btn-primary w-100 sign-in-btn" type="submit">Send reset link <span>→</span></button></form><?php endif; ?>
    <p class="signup-prompt"><a href="index.php">Return to sign in</a></p></div></section>
</body>
</html>
