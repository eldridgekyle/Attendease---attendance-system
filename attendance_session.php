<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_user() || !in_array(current_user()['role'], ['admin','teacher'], true)) {
    http_response_code(403); echo json_encode(['error' => 'Only instructors and administrators can open attendance sessions.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Use POST to create an attendance session.']); exit;
}
try {
    verify_csrf();
    $user = current_user();
    $classId = (int)($_POST['class_id'] ?? 0);
    $minutes = (int)($_POST['minutes'] ?? 5);
    if (!in_array($minutes, [2,5,10], true)) { $minutes = 5; }
    $classQuery = db()->prepare('SELECT id FROM classes WHERE id=?'.($user['role']==='teacher'?' AND teacher_id=?':''));
    $classQuery->execute($user['role']==='teacher' ? [$classId,$user['id']] : [$classId]);
    if (!$classQuery->fetchColumn()) { http_response_code(422); echo json_encode(['error' => 'Choose a class you are allowed to manage.']); exit; }
    $pin = (string)random_int(100000,999999);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = (new DateTimeImmutable('now'))->modify('+'.$minutes.' minutes');
    $insert = db()->prepare('INSERT INTO attendance_sessions (class_id,created_by,pin_hash,qr_token_hash,expires_at) VALUES (?,?,?,?,?)');
    $insert->execute([$classId,$user['id'],password_hash($pin,PASSWORD_DEFAULT),hash('sha256',$token),$expires->format('Y-m-d H:i:s')]);
    $sessionId = (int)db()->lastInsertId();
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))),'/');
    $url = $scheme.'://'.$host.$basePath.'/checkin.php?session='.$sessionId.'&token='.rawurlencode($token);
    echo json_encode(['pin'=>$pin,'url'=>$url,'expires_at'=>$expires->format(DATE_ATOM),'expires_label'=>$expires->format('g:i A'),'session_id'=>$sessionId]);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['error'=>'The session could not be created. Refresh the page and try again.']);
}
