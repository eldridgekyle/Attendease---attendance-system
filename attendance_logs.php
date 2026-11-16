<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_login();
$pdo = db();
$user = current_user();
$role = $user['role'];
$student = $role === 'student' ? student_record() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    verify_csrf();
    if (!in_array($role, ['admin','teacher'], true)) {
        http_response_code(403);
        exit('Only instructors and administrators can update attendance records.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $statusValue = (string)($_POST['status'] ?? '');
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    if ($id < 1 || !in_array($statusValue, ['Present','Late','Absent','Excused'], true)) {
        flash('error', 'Choose a valid attendance status.');
        header('Location: attendance_logs.php'); exit;
    }
    $sql = "UPDATE attendance_logs a JOIN classes c ON c.id=a.class_id SET a.status=?,a.remarks=?,a.verification_method='Manual Override' WHERE a.id=?";
    $params = [$statusValue, $remarks !== '' ? $remarks : null, $id];
    if ($role === 'teacher') { $sql .= ' AND c.teacher_id=?'; $params[] = $user['id']; }
    $update = $pdo->prepare($sql);
    $update->execute($params);
    flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Attendance record updated.' : 'That record was not found in your classes.');
    header('Location: attendance_logs.php'); exit;
}

if ($role === 'student') {
    $classQuery = $pdo->prepare('SELECT c.id,c.class_code,c.subject_name FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=? ORDER BY c.class_code');
    $classQuery->execute([$student['id'] ?? 0]);
} elseif ($role === 'teacher') {
    $classQuery = $pdo->prepare('SELECT id,class_code,subject_name FROM classes WHERE teacher_id=? ORDER BY class_code');
    $classQuery->execute([$user['id']]);
} else {
    $classQuery = $pdo->query('SELECT id,class_code,subject_name FROM classes ORDER BY class_code');
}
$classes = $classQuery->fetchAll();
$term = trim((string)($_GET['q'] ?? ''));
$classId = (int)($_GET['class_id'] ?? 0);
$status = (string)($_GET['status'] ?? '');
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$where = [];
$params = [];
if ($term !== '') { $where[] = '(u.full_name LIKE ? OR s.student_number LIKE ? OR s.program_year LIKE ? OR c.class_code LIKE ?)'; $like = '%'.$term.'%'; array_push($params,$like,$like,$like,$like); }
if ($classId > 0 && in_array($classId, array_map('intval', array_column($classes,'id')), true)) { $where[] = 'a.class_id=?'; $params[] = $classId; }
if (in_array($status,['Present','Late','Absent','Excused'],true)) { $where[] = 'a.status=?'; $params[] = $status; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) { $where[] = 'a.attendance_date>=?'; $params[] = $from; } else { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) { $where[] = 'a.attendance_date<=?'; $params[] = $to; } else { $to = ''; }
if ($role === 'teacher') { $where[] = 'c.teacher_id=?'; $params[] = $user['id']; }
if ($role === 'student') { $where[] = 's.id=?'; $params[] = $student['id'] ?? 0; }
$base = ' FROM attendance_logs a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id JOIN classes c ON c.id=a.class_id';
$whereSql = $where ? ' WHERE '.implode(' AND ',$where) : '';
$countQuery = $pdo->prepare('SELECT COUNT(*)'.$base.$whereSql);
$countQuery->execute($params);
$totalRows = (int)$countQuery->fetchColumn();
$totalPages = max(1,(int)ceil($totalRows/$perPage));
$page = min($page,$totalPages);
$select = 'SELECT a.id,a.student_id,a.class_id,a.attendance_date,a.time_in,a.time_out,a.status,a.verification_method,a.remarks,s.student_number,s.program_year,s.section,s.face_status,u.full_name,c.class_code,c.subject_name,c.room'.$base.$whereSql.' ORDER BY a.attendance_date DESC,a.time_in DESC,a.id DESC';
$export = (string)($_GET['export'] ?? '') === 'csv';
if ($export) {
    $exportQuery = $pdo->prepare($select);
    $exportQuery->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendease-attendance-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Student','Student number','Class code','Subject','Date','Time in','Time out','Status','Verification method','Remarks']);
    while ($record = $exportQuery->fetch()) { fputcsv($out,[$record['full_name'],$record['student_number'],$record['class_code'],$record['subject_name'],$record['attendance_date'],$record['time_in'],$record['time_out'],$record['status'],$record['verification_method'],$record['remarks']]); }
    fclose($out); exit;
}
$rowQuery = $pdo->prepare($select.' LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
$rowQuery->execute($params);
$rows = $rowQuery->fetchAll();
page_start($role === 'student' ? 'My attendance' : 'Attendance logs','attendance_logs.php');
?>
<div class="page-heading"><div><p class="overline">ATTENDANCE MANAGEMENT</p><h1><?= $role === 'student' ? 'My attendance' : 'Attendance logs' ?></h1><p class="heading-subtitle"><?= $role === 'student' ? 'Review your attendance and check in with a current class PIN.' : 'Review, update, and export verified class attendance.' ?></p></div><div class="directory-tools"><span class="record-chip"><i class="bi bi-list-check me-1"></i><?= number_format($totalRows) ?> records</span><?php if ($role !== 'student'): ?><button class="btn btn-primary heading-action" data-bs-toggle="modal" data-bs-target="#scanModal"><i class="bi bi-qr-code-scan me-1"></i> Launch live scanner</button><?php endif; ?></div></div>
<?php if ($role === 'student'): ?><section class="content-card p-3 mb-3"><form action="checkin.php" method="post" class="row align-items-end g-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="col-12 col-md-7"><label class="field-label" for="pin">ONE-TIME CLASS PIN</label><input id="pin" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="form-control" placeholder="Enter the 6-digit PIN shared by your instructor" required></div><div class="col-12 col-md-3"><label class="field-label" for="pinClass">CLASS</label><select id="pinClass" name="class_id" class="form-select" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><button class="btn btn-primary w-100">Check in <i class="bi bi-arrow-right ms-1"></i></button></div></form></section><?php endif; ?>
<section class="content-card report-card"><div class="card-heading report-heading"><div><h2>Attendance report</h2><p>Search by student, class, status, or date range</p></div><?php if ($role !== 'student'): ?><span class="record-chip"><i class="bi bi-shield-check me-1"></i>Staff controls enabled</span><?php endif; ?></div>
<form method="get" class="filter-bar"><div class="search-field"><i class="bi bi-search"></i><input name="q" type="search" placeholder="Search student, number, class…" value="<?= e($term) ?>"></div><div class="filter-control"><label for="class_id">CLASS</label><select id="class_id" name="class_id"><option value="0">All classes</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $classId===(int)$class['id']?'selected':'' ?>><?= e($class['class_code']) ?></option><?php endforeach; ?></select></div><div class="filter-control"><label for="from">FROM</label><input id="from" name="from" type="date" value="<?= e($from) ?>"></div><div class="filter-control"><label for="to">TO</label><input id="to" name="to" type="date" value="<?= e($to) ?>"></div><div class="filter-control status-filter"><label for="status">STATUS</label><select id="status" name="status"><option value="">All statuses</option><?php foreach (['Present','Late','Absent','Excused'] as $option): ?><option value="<?= e($option) ?>" <?= $status===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></select></div><button class="btn btn-primary filter-button" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button><?php if ($term||$classId||$status||$from||$to): ?><a class="clear-filter" href="attendance_logs.php">Clear</a><?php endif; ?><button class="btn btn-outline-secondary filter-button" name="export" value="csv" type="submit"><i class="bi bi-download me-1"></i>Export CSV</button></form>
<div class="table-responsive"><table class="table activity-table report-table"><thead><tr><th>STUDENT</th><th>STUDENT ID</th><th>CLASS</th><th>DATE</th><th>TIME IN</th><th>VERIFICATION</th><th>STATUS</th><?php if ($role !== 'student'): ?><th>ACTION</th><?php endif; ?></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="<?= $role==='student'?7:8 ?>" class="empty-state">No records match these filters.</td></tr><?php else: foreach ($rows as $row): ?><tr><td><div class="student-cell"><span class="avatar student-avatar"><?= e(initials($row['full_name'])) ?></span><strong><?= e($row['full_name']) ?></strong></div></td><td class="table-secondary-text"><?= e($row['student_number']) ?></td><td><strong><?= e($row['class_code']) ?></strong><small class="d-block camera-status"><?= e($row['subject_name']) ?></small></td><td class="table-secondary-text"><?= e(date('M j, Y',strtotime($row['attendance_date']))) ?></td><td class="table-secondary-text"><?= $row['time_in']?e(date('g:i A',strtotime($row['time_in']))):'—' ?></td><td class="table-secondary-text"><?= e($row['verification_method']) ?></td><td><?= status_badge($row['status']) ?></td><?php if ($role !== 'student'): ?><td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAttendance<?= (int)$row['id'] ?>" aria-label="Update attendance"><i class="bi bi-pencil-square"></i></button></td><?php endif; ?></tr>
<?php if ($role !== 'student'): ?><div class="modal fade" id="editAttendance<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><div class="modal-header"><div><h2 class="modal-title">Update attendance</h2><p class="camera-status mb-0 mt-1"><?= e($row['full_name'].' · '.$row['class_code'].' · '.date('M j, Y',strtotime($row['attendance_date']))) ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="field-label" for="status<?= (int)$row['id'] ?>">STATUS</label><select class="form-select mb-3" id="status<?= (int)$row['id'] ?>" name="status" required><?php foreach (['Present','Late','Absent','Excused'] as $option): ?><option <?= $row['status']===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></select><label class="field-label" for="remarks<?= (int)$row['id'] ?>">REMARKS</label><textarea class="form-control" id="remarks<?= (int)$row['id'] ?>" name="remarks" rows="3" maxlength="500" placeholder="Add a note for the record"><?= e($row['remarks'] ?? '') ?></textarea><div class="form-text">Changes are recorded as a manual override.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save update</button></div></form></div></div><?php endif; ?>
<?php endforeach; endif; ?></tbody></table></div>
<div class="table-footer"><span>Showing <?= count($rows) ?> of <?= number_format($totalRows) ?> records</span><nav aria-label="Attendance pages"><ul class="pagination pagination-sm mb-0"><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?><li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$p]))) ?>"><?= $p ?></a></li><?php endfor; ?></ul></nav></div></section>
<?php if ($role !== 'student'): ?>
<div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h2 class="modal-title">Launch live face scanner</h2><p class="camera-status mb-0 mt-1">Start the camera or generate an expiring PIN and QR for class check-in.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="camera-frame" id="cameraFrame"><video id="cameraVideo" autoplay playsinline></video><div class="camera-guide"><i class="bi bi-person-bounding-box"></i></div><div class="scanner-line"></div><div class="camera-caption" id="cameraCaption">Camera preview will appear here</div></div><div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-outline-secondary flex-fill" id="cameraButton"><i class="bi bi-camera-video me-1"></i> Start camera</button><a class="btn btn-primary flex-fill" href="attendance_logs.php"><i class="bi bi-calendar2-check me-1"></i> Review records</a></div><form id="sessionForm" class="row g-2 mt-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="col-8"><label class="field-label" for="sessionClass">CLASS</label><select class="form-select" id="sessionClass" name="class_id" required><option value="">Choose a class</option><?php foreach($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select></div><div class="col-4"><label class="field-label" for="sessionMinutes">EXPIRES</label><select class="form-select" id="sessionMinutes" name="minutes"><option value="2">2 min</option><option value="5" selected>5 min</option><option value="10">10 min</option></select></div><div class="col-12"><button class="btn btn-primary w-100 mt-2" type="submit"><i class="bi bi-qr-code me-1"></i> Generate check-in PIN / QR</button></div></form><div id="sessionResult" class="mt-3 d-none"><div class="text-center p-3 bg-light rounded-3"><div class="overline mb-1">ONE-TIME CLASS PIN</div><div id="sessionPin" class="display-6 fw-bold text-primary"></div><div id="sessionExpiry" class="camera-status"></div><div id="sessionQr" class="d-flex justify-content-center mt-3"></div><p class="camera-status mt-2 mb-0">Students enter the PIN or scan the QR while signed in.</p></div></div><div id="sessionError" class="alert alert-danger mt-3 d-none"></div></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
const form=document.getElementById('sessionForm');form?.addEventListener('submit',async e=>{e.preventDefault();const b=form.querySelector('button[type="submit"]');b.disabled=true;const d=new FormData(form);d.append('action','create');try{const r=await fetch('attendance_session.php',{method:'POST',body:d,headers:{'Accept':'application/json'}});const j=await r.json();if(!r.ok)throw new Error(j.error||'Could not create session');document.getElementById('sessionPin').textContent=j.pin;document.getElementById('sessionExpiry').textContent='Expires '+j.expires_label;const box=document.getElementById('sessionQr');box.innerHTML='';await QRCode.toCanvas(j.url,{width:175,margin:1,color:{dark:'#0A2540',light:'#FFFFFF'}}).then(c=>box.appendChild(c));document.getElementById('sessionResult').classList.remove('d-none');document.getElementById('sessionError').classList.add('d-none')}catch(err){const x=document.getElementById('sessionError');x.textContent=err.message;x.classList.remove('d-none')}finally{b.disabled=false}});
let cameraStream=null;const cameraButton=document.getElementById('cameraButton');cameraButton?.addEventListener('click',async()=>{const video=document.getElementById('cameraVideo'),frame=document.getElementById('cameraFrame'),caption=document.getElementById('cameraCaption');if(cameraStream){cameraStream.getTracks().forEach(t=>t.stop());cameraStream=null;video.srcObject=null;video.style.display='none';frame.classList.remove('scanner-running');cameraButton.innerHTML='<i class="bi bi-camera-video me-1"></i> Start camera';caption.textContent='Camera preview stopped';return}try{cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false});video.srcObject=cameraStream;video.style.display='block';frame.classList.add('scanner-running');caption.textContent='Camera connected · PIN and QR check-in are ready';cameraButton.innerHTML='<i class="bi bi-camera-video-off me-1"></i> Stop camera'}catch{frame.classList.add('scanner-running');caption.textContent='Camera unavailable · PIN and QR check-in remain available';cameraButton.innerHTML='<i class="bi bi-arrow-repeat me-1"></i> Simulated scanner active'}});document.getElementById('scanModal')?.addEventListener('hidden.bs.modal',()=>{if(cameraStream){cameraStream.getTracks().forEach(t=>t.stop());cameraStream=null}});
</script>
<?php endif; ?>
<?php page_end(); ?>
