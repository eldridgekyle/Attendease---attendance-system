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
    $checkSql = 'SELECT a.id FROM attendance_logs a JOIN classes c ON c.id=a.class_id WHERE a.id=?';
    $checkParams = [$id];
    if ($role === 'teacher') { $checkSql .= ' AND c.teacher_id=?'; $checkParams[] = $user['id']; }
    $check = $pdo->prepare($checkSql);
    $check->execute($checkParams);
    if (!$check->fetchColumn()) {
        flash('error', 'That record was not found in your classes.');
        header('Location: attendance_logs.php'); exit;
    }
    $update = $pdo->prepare("UPDATE attendance_logs SET status=?,remarks=?,verification_method='Manual Override' WHERE id=?");
    $update->execute([$statusValue, $remarks !== '' ? $remarks : null, $id]);
    flash('success', 'Attendance record updated.');
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
$base = ' FROM attendance_logs a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id JOIN classes c ON c.id=a.class_id JOIN users class_instructor ON class_instructor.id=c.teacher_id LEFT JOIN attendance_sessions sess ON sess.id=a.session_id LEFT JOIN users session_instructor ON session_instructor.id=sess.created_by';
$whereSql = $where ? ' WHERE '.implode(' AND ',$where) : '';
$countQuery = $pdo->prepare('SELECT COUNT(*)'.$base.$whereSql);
$countQuery->execute($params);
$totalRows = (int)$countQuery->fetchColumn();
$totalPages = max(1,(int)ceil($totalRows/$perPage));
$page = min($page,$totalPages);
$select = 'SELECT a.id,a.student_id,a.class_id,a.session_id,a.attendance_date,a.time_in,a.time_out,a.status,a.verification_method,a.remarks,s.student_number,s.program_year,s.section,u.full_name,c.class_code,c.subject_name,c.room,COALESCE(session_instructor.full_name,class_instructor.full_name) AS instructor_name'.$base.$whereSql.' ORDER BY a.attendance_date DESC,a.time_in DESC,a.id DESC';
$export = (string)($_GET['export'] ?? '') === 'csv';
if ($export) {
    $exportQuery = $pdo->prepare($select);
    $exportQuery->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendease-attendance-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Student','Student number','Class code','Subject','Instructor','Session ID','Date','Time in','Time out','Status','Verification method','Remarks'], ',', '"', '');
    while ($record = $exportQuery->fetch()) { fputcsv($out,[$record['full_name'],$record['student_number'],$record['class_code'],$record['subject_name'],$record['instructor_name'],$record['session_id'],$record['attendance_date'],$record['time_in'],$record['time_out'],$record['status'],$record['verification_method'],$record['remarks']], ',', '"', ''); }
    fclose($out); exit;
}
$rowQuery = $pdo->prepare($select.' LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
$rowQuery->execute($params);
$rows = $rowQuery->fetchAll();
page_start($role === 'student' ? 'My attendance' : 'Attendance logs','attendance_logs.php');
?>
<div class="page-heading"><div><p class="overline">ATTENDANCE MANAGEMENT</p><h1><?= $role === 'student' ? 'My attendance' : 'Attendance logs' ?></h1><p class="heading-subtitle"><?= $role === 'student' ? 'Review your attendance and check in with the current class code.' : 'Review, update, and export verified class attendance.' ?></p></div><div class="directory-tools"><span class="record-chip"><i class="bi bi-list-check me-1"></i><?= number_format($totalRows) ?> records</span></div></div>

<?php if ($role === 'student'): ?>
<section class="content-card p-3 mb-3"><form action="checkin.php" method="post" class="row align-items-end g-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="col-12 col-md-7"><label class="field-label" for="attendanceCode">CURRENT SIX-DIGIT CODE</label><input id="attendanceCode" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="form-control" placeholder="Enter the current code from your instructor" autocomplete="one-time-code" required></div><div class="col-12 col-md-3"><label class="field-label" for="attendanceClass">CLASS</label><select id="attendanceClass" name="class_id" class="form-select" required><option value="">Choose class</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2"><button class="btn btn-primary w-100">Check in <i class="bi bi-arrow-right ms-1"></i></button></div></form><div class="student-session-status mt-3" id="studentSessionStatus" aria-live="polite">Choose a class to check for an active attendance session.</div></section>
<script>
(()=>{const select=document.getElementById('attendanceClass'),status=document.getElementById('studentSessionStatus');let timer=0;async function refresh(){clearTimeout(timer);if(!select.value){status.textContent='Choose a class to check for an active attendance session.';return}try{const response=await fetch(`attendance_session.php?action=student_status&class_id=${encodeURIComponent(select.value)}`,{cache:'no-store'});const result=await response.json();if(!response.ok)throw new Error(result.error||'Could not check the class session.');status.textContent=result.active?'Attendance is active. Enter the current code displayed by your instructor. Session ends at '+new Date(result.expires_at.replace(' ','T')).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'})+'.':'No attendance session is active for this class.'}catch(error){status.textContent=error.message}timer=setTimeout(refresh,15000)}select.addEventListener('change',refresh);setTimeout(refresh,0)})();
</script>
<?php else: ?>
<section class="content-card p-3 mb-3" id="attendanceSessionPanel"><div class="card-heading"><div><h2>Class attendance session</h2><p>Start a session manually. Its six-digit code changes every 30 seconds and expires after 15 minutes.</p></div><span class="stat-icon icon-blue"><i class="bi bi-shield-check"></i></span></div><form id="sessionStartForm" class="row align-items-end g-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="col-12 col-md-8"><label class="field-label" for="sessionClass">CLASS</label><select class="form-select" id="sessionClass" name="class_id" required><option value="">Choose a class</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><button class="btn btn-primary w-100" id="startAttendance" type="submit"><i class="bi bi-play-circle me-1"></i> Start Attendance</button></div></form><div id="sessionNotice" class="alert alert-danger mt-3 d-none" role="alert"></div><div id="activeSession" class="totp-session-card mt-3 d-none"><div class="totp-session-info"><p class="overline mb-1">CURRENT ATTENDANCE CODE</p><div id="totpCode" class="totp-code" aria-live="polite">------</div><p class="helper-copy mb-1">Refreshes in <strong id="totpCountdown">30</strong> seconds · session ends at <strong id="sessionExpiry">—</strong></p><p class="helper-copy mb-0">Students enter this code from their enrolled class page.</p></div><button class="btn btn-outline-danger" type="button" id="endAttendance"><i class="bi bi-stop-circle me-1"></i> End Attendance</button></div></section>
<?php endif; ?>

<section class="content-card report-card"><div class="card-heading report-heading"><div><h2>Attendance report</h2><p>Search by student, class, status, or date range</p></div><?php if ($role !== 'student'): ?><span class="record-chip"><i class="bi bi-shield-check me-1"></i>Staff controls enabled</span><?php endif; ?></div>
<form method="get" class="filter-bar"><div class="search-field"><i class="bi bi-search"></i><input name="q" type="search" placeholder="Search student, number, class…" value="<?= e($term) ?>"></div><div class="filter-control"><label for="class_id">CLASS</label><select id="class_id" name="class_id"><option value="0">All classes</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $classId===(int)$class['id']?'selected':'' ?>><?= e($class['class_code']) ?></option><?php endforeach; ?></select></div><div class="filter-control"><label for="from">FROM</label><input id="from" name="from" type="date" value="<?= e($from) ?>"></div><div class="filter-control"><label for="to">TO</label><input id="to" name="to" type="date" value="<?= e($to) ?>"></div><div class="filter-control status-filter"><label for="status">STATUS</label><select id="status" name="status"><option value="">All statuses</option><?php foreach (['Present','Late','Absent','Excused'] as $option): ?><option value="<?= e($option) ?>" <?= $status===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></select></div><button class="btn btn-primary filter-button" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button><?php if ($term||$classId||$status||$from||$to): ?><a class="clear-filter" href="attendance_logs.php">Clear</a><?php endif; ?><button class="btn btn-outline-secondary filter-button" name="export" value="csv" type="submit"><i class="bi bi-download me-1"></i>Export CSV</button></form>
<div class="table-responsive"><table class="table activity-table report-table"><thead><tr><th>STUDENT</th><th>STUDENT ID</th><th>CLASS</th><th>INSTRUCTOR</th><th>SESSION</th><th>DATE</th><th>TIME IN</th><th>VERIFICATION</th><th>STATUS</th><?php if ($role !== 'student'): ?><th>ACTION</th><?php endif; ?></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="<?= $role==='student'?9:10 ?>" class="empty-state">No records match these filters.</td></tr><?php else: foreach ($rows as $row): ?><tr><td><div class="student-cell"><span class="avatar student-avatar"><?= e(initials($row['full_name'])) ?></span><strong><?= e($row['full_name']) ?></strong></div></td><td class="table-secondary-text"><?= e($row['student_number']) ?></td><td><strong><?= e($row['class_code']) ?></strong><small class="d-block helper-copy"><?= e($row['subject_name']) ?></small></td><td class="table-secondary-text"><?= e($row['instructor_name']) ?></td><td class="table-secondary-text"><?= $row['session_id'] ? '#'.(int)$row['session_id'] : 'Legacy' ?></td><td class="table-secondary-text"><?= e(date('M j, Y',strtotime($row['attendance_date']))) ?></td><td class="table-secondary-text"><?= $row['time_in']?e(date('g:i A',strtotime($row['time_in']))):'—' ?></td><td class="table-secondary-text"><?= e($row['verification_method']) ?></td><td><?= status_badge($row['status']) ?></td><?php if ($role !== 'student'): ?><td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAttendance<?= (int)$row['id'] ?>" aria-label="Update attendance"><i class="bi bi-pencil-square"></i></button></td><?php endif; ?></tr>
<?php if ($role !== 'student'): ?><div class="modal fade" id="editAttendance<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><div class="modal-header"><div><h2 class="modal-title">Update attendance</h2><p class="helper-copy mb-0 mt-1"><?= e($row['full_name'].' · '.$row['class_code'].' · '.date('M j, Y',strtotime($row['attendance_date']))) ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="field-label" for="status<?= (int)$row['id'] ?>">STATUS</label><select class="form-select mb-3" id="status<?= (int)$row['id'] ?>" name="status" required><?php foreach (['Present','Late','Absent','Excused'] as $option): ?><option <?= $row['status']===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></select><label class="field-label" for="remarks<?= (int)$row['id'] ?>">REMARKS</label><textarea class="form-control" id="remarks<?= (int)$row['id'] ?>" name="remarks" rows="3" maxlength="500" placeholder="Add a note for the record"><?= e($row['remarks'] ?? '') ?></textarea><div class="form-text">Changes are recorded as a manual override.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save update</button></div></form></div></div><?php endif; ?>
<?php endforeach; endif; ?></tbody></table></div>
<div class="table-footer"><span>Showing <?= count($rows) ?> of <?= number_format($totalRows) ?> records</span><nav aria-label="Attendance pages"><ul class="pagination pagination-sm mb-0"><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?><li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$p]))) ?>"><?= $p ?></a></li><?php endfor; ?></ul></nav></div></section>

<?php if ($role !== 'student'): ?>
<script>
(()=>{
  const form=document.getElementById('sessionStartForm'),classSelect=document.getElementById('sessionClass'),card=document.getElementById('activeSession'),codeBox=document.getElementById('totpCode'),countdown=document.getElementById('totpCountdown'),expiry=document.getElementById('sessionExpiry'),notice=document.getElementById('sessionNotice'),startButton=document.getElementById('startAttendance'),endButton=document.getElementById('endAttendance');
  let sessionId=0,secondsLeft=30,pollTimer=null,tickTimer=null;
  const showError=message=>{notice.textContent=message;notice.className='alert alert-danger mt-3'};
  const clearTimers=()=>{clearTimeout(pollTimer);clearInterval(tickTimer);pollTimer=null;tickTimer=null};
  const hideSession=()=>{clearTimers();sessionId=0;card.classList.add('d-none')};
  const request=async(url,data)=>{const response=await fetch(url,data?{method:'POST',body:data,headers:{'Accept':'application/json'}}:{headers:{'Accept':'application/json'},cache:'no-store'});const result=await response.json();if(!response.ok)throw new Error(result.error||'The attendance session could not be loaded.');return result};
  const showSession=result=>{sessionId=Number(result.session_id);secondsLeft=Number(result.seconds_remaining||30);codeBox.textContent=result.code;expiry.textContent=result.expires_label||new Date(result.expires_at.replace(' ','T')).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});notice.classList.add('d-none');card.classList.remove('d-none');clearTimers();tickTimer=setInterval(()=>{secondsLeft=Math.max(0,secondsLeft-1);countdown.textContent=secondsLeft;if(secondsLeft===0){codeBox.textContent='------';clearInterval(tickTimer);pollCode()}},1000);pollTimer=setTimeout(pollCode,Math.max(1000,secondsLeft*1000))};
  async function pollCode(){if(!sessionId)return;try{showSession(await request(`attendance_session.php?action=code&session_id=${sessionId}`))}catch(error){hideSession();showError(error.message)}}
  async function loadActive(){hideSession();notice.classList.add('d-none');if(!classSelect.value)return;try{const result=await request(`attendance_session.php?action=active&class_id=${encodeURIComponent(classSelect.value)}`);if(result.active){sessionId=Number(result.session_id);await pollCode()}}catch(error){showError(error.message)}}
  form?.addEventListener('submit',async event=>{event.preventDefault();startButton.disabled=true;notice.classList.add('d-none');const data=new FormData(form);data.append('action','start');try{const result=await request('attendance_session.php',data);showSession(result)}catch(error){showError(error.message)}finally{startButton.disabled=false}});
  classSelect?.addEventListener('change',loadActive);
  endButton?.addEventListener('click',async()=>{if(!sessionId)return;endButton.disabled=true;const data=new FormData(form);data.set('action','end');data.set('session_id',String(sessionId));try{await request('attendance_session.php',data);hideSession();notice.textContent='Attendance session ended.';notice.className='alert alert-success mt-3'}catch(error){showError(error.message)}finally{endButton.disabled=false}});
  loadActive();
})();
</script>
<?php endif; ?>
<?php page_end(); ?>
