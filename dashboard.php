<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_login();
$pdo = db();
$user = current_user();
$role = $user['role'];
$student = $role === 'student' ? student_record() : null;
$scopeJoin = '';
$scopeWhere = '';
$scopeParams = [];
if ($role === 'teacher') {
    $scopeJoin = ' JOIN classes scope_class ON scope_class.id=a.class_id ';
    $scopeWhere = ' AND scope_class.teacher_id=?';
    $scopeParams[] = $user['id'];
} elseif ($role === 'student') {
    $scopeWhere = ' AND a.student_id=?';
    $scopeParams[] = $student['id'] ?? 0;
}

if ($role === 'admin') {
    $totalStudents = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
} elseif ($role === 'teacher') {
    $q = $pdo->prepare('SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=?');
    $q->execute([$user['id']]);
    $totalStudents = (int)$q->fetchColumn();
} else {
    $totalStudents = 1;
}
$daily = $pdo->prepare("SELECT a.status,COUNT(*) AS total FROM attendance_logs a $scopeJoin WHERE a.attendance_date=CURDATE() $scopeWhere GROUP BY a.status");
$daily->execute($scopeParams);
$counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0];
foreach ($daily->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['total'];
}
$yesterday = $pdo->prepare("SELECT a.status,COUNT(*) AS total FROM attendance_logs a $scopeJoin WHERE a.attendance_date=CURDATE()-INTERVAL 1 DAY $scopeWhere GROUP BY a.status");
$yesterday->execute($scopeParams);
$previousCounts = ['Present'=>0,'Late'=>0,'Absent'=>0,'Excused'=>0];
foreach($yesterday->fetchAll() as $row){$previousCounts[$row['status']]=(int)$row['total'];}
$percentageChange = static function (int $current, int $previous): string {
    if ($previous === 0) { return $current > 0 ? '+100%' : '0%'; }
    $change = (int)round((($current - $previous) / $previous) * 100);
    return ($change > 0 ? '+' : '') . $change . '%';
};
$rosterTrend = '0%';
$weekStart = date('Y-m-d',strtotime('monday this week'));
$lastWeekStart = date('Y-m-d',strtotime('monday last week'));
if($role==='admin'){
    $trendQuery=$pdo->prepare('SELECT SUM(created_at>=?) current_total,SUM(created_at>=? AND created_at<?) previous_total FROM users WHERE role="student"');
    $trendQuery->execute([$weekStart,$lastWeekStart,$weekStart]);$trendValues=$trendQuery->fetch();
    $rosterTrend=$percentageChange((int)$trendValues['current_total'],(int)$trendValues['previous_total']);
}elseif($role==='teacher'){
    $trendQuery=$pdo->prepare('SELECT SUM(u.created_at>=?) current_total,SUM(u.created_at>=? AND u.created_at<?) previous_total FROM students s JOIN users u ON u.id=s.user_id JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=?');
    $trendQuery->execute([$weekStart,$lastWeekStart,$weekStart,$user['id']]);$trendValues=$trendQuery->fetch();
    $rosterTrend=$percentageChange((int)$trendValues['current_total'],(int)$trendValues['previous_total']);
}

$trend = $pdo->prepare("SELECT a.attendance_date,SUM(a.status='Present') present,SUM(a.status='Late') late,SUM(a.status='Absent') absent FROM attendance_logs a $scopeJoin WHERE a.attendance_date >= CURDATE()-INTERVAL 6 DAY $scopeWhere GROUP BY a.attendance_date ORDER BY a.attendance_date");
$trend->execute($scopeParams);
$trendRows = $trend->fetchAll();
$trendMap = [];
foreach ($trendRows as $row) {
    $trendMap[$row['attendance_date']] = $row;
}
$chartLabels = $chartPresent = $chartLate = $chartAbsent = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($date));
    $chartPresent[] = (int)($trendMap[$date]['present'] ?? 0);
    $chartLate[] = (int)($trendMap[$date]['late'] ?? 0);
    $chartAbsent[] = (int)($trendMap[$date]['absent'] ?? 0);
}

$recentSql = "SELECT a.id,a.attendance_date,a.time_in,a.time_out,a.status,a.verification_method,s.student_number,s.program_year,u.full_name,c.class_code,c.subject_name FROM attendance_logs a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id JOIN classes c ON c.id=a.class_id $scopeWhere ORDER BY a.attendance_date DESC,a.time_in DESC,a.id DESC LIMIT 8";
$recent = $pdo->prepare($recentSql);
$recent->execute($scopeParams);
$recentRows = $recent->fetchAll();

if ($role === 'student') {
    $classQuery = $pdo->prepare('SELECT c.*,u.full_name AS teacher_name FROM enrollments e JOIN classes c ON c.id=e.class_id JOIN users u ON u.id=c.teacher_id WHERE e.student_id=? ORDER BY FIELD(c.schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday"),c.start_time');
    $classQuery->execute([$student['id'] ?? 0]);
} elseif ($role === 'teacher') {
    $classQuery = $pdo->prepare('SELECT c.*,u.full_name AS teacher_name FROM classes c JOIN users u ON u.id=c.teacher_id WHERE c.teacher_id=? ORDER BY FIELD(c.schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday"),c.start_time');
    $classQuery->execute([$user['id']]);
} else {
    $classQuery = $pdo->query('SELECT c.*,u.full_name AS teacher_name FROM classes c JOIN users u ON u.id=c.teacher_id ORDER BY FIELD(c.schedule_day,"Monday","Tuesday","Wednesday","Thursday","Friday"),c.start_time');
}
$classes = $classQuery->fetchAll();
$schedule = array_fill_keys(['Monday','Tuesday','Wednesday','Thursday','Friday'], []);
foreach ($classes as $class) {
    $schedule[$class['schedule_day']][] = $class;
}

$feedSql = "SELECT a.status,a.time_in,a.attendance_date,u.full_name,s.student_number,c.class_code FROM attendance_logs a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id JOIN classes c ON c.id=a.class_id $scopeWhere ORDER BY a.attendance_date DESC,a.time_in DESC,a.id DESC LIMIT 4";
$feed = $pdo->prepare($feedSql);
$feed->execute($scopeParams);
$feedRows = $feed->fetchAll();
$faceCounts = ['Registered'=>0,'Pending'=>0];
if ($role === 'admin') {
    $faceRows=$pdo->query('SELECT face_status,COUNT(*) AS total FROM students GROUP BY face_status')->fetchAll();
} elseif ($role === 'teacher') {
    $faceQuery=$pdo->prepare('SELECT s.face_status,COUNT(DISTINCT s.id) AS total FROM students s JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? GROUP BY s.face_status');
    $faceQuery->execute([$user['id']]);$faceRows=$faceQuery->fetchAll();
} else {
    $faceRows=$student?[$student]:[];
    if($faceRows){$faceRows=[['face_status'=>$student['face_status'],'total'=>1]];}
}
foreach($faceRows as $faceRow){if(isset($faceCounts[$faceRow['face_status']]))$faceCounts[$faceRow['face_status']]=(int)$faceRow['total'];}
$title = $role === 'student' ? 'Your attendance' : ($role === 'teacher' ? 'Class overview' : 'Campus overview');
page_start('Overview', 'dashboard.php');
?>
<div class="page-heading"><div><p class="overline"><?= e(strtoupper(date('l, F j, Y'))) ?></p><h1>Good <?= (int)date('G') < 12 ? 'morning' : ((int)date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['full_name'])[0]) ?> <span class="wave">✦</span></h1><p class="heading-subtitle"><?= e($title) ?> · Your attendance picture at a glance.</p></div><a href="attendance_logs.php" class="btn btn-primary heading-action"><i class="bi bi-calendar2-check me-1"></i> <?= $role === 'student' ? 'View my records' : 'Manage attendance' ?></a></div>
<div class="section-heading"><div><h2><?= $role === 'student' ? 'Your attendance today' : 'Today at a glance' ?></h2><p>Live attendance overview</p></div><span class="live-indicator"><i></i> Live data</span></div>
<div class="row g-3 stats-row">
<?php foreach ([['Total enrolled students',$totalStudents,'vs last week '.$rosterTrend,'bi-people','blue',$rosterTrend],['Present today',$counts['Present'],'vs yesterday','bi-check2-circle','green',$percentageChange($counts['Present'],$previousCounts['Present'])],['Late arrivals',$counts['Late'],'vs yesterday','bi-clock-history','amber',$percentageChange($counts['Late'],$previousCounts['Late'])],['Absent today',$counts['Absent'],'vs yesterday','bi-person-x','red',$percentageChange($counts['Absent'],$previousCounts['Absent'])]] as [$label,$value,$note,$icon,$tone,$trendValue]): ?>
  <div class="col-6 col-xl-3"><article class="stat-card"><div class="stat-top"><span><?= e($label) ?></span><span class="stat-icon icon-<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span></div><div class="stat-value"><?= number_format($value) ?></div><div class="stat-foot"><span class="stat-<?= e($tone) ?>"><i class="bi bi-graph-up-arrow"></i> <?= e($trendValue) ?></span> <?= e($note) ?></div><div class="stat-accent accent-<?= e($tone) ?>"></div></article></div>
<?php endforeach; ?>
</div>
<div class="row g-3 dashboard-mid">
  <div class="col-12 col-xl-8">
    <section class="content-card mb-3"><div class="card-heading"><div><h2>Attendance analytics</h2><p>Daily attendance trends for the last 7 days</p></div><div class="attendance-meta"><span><i class="bi bi-circle-fill text-success me-1"></i>Present</span><span><i class="bi bi-circle-fill text-warning me-1"></i>Late</span><span><i class="bi bi-circle-fill text-danger me-1"></i>Absent</span></div></div><div class="chart-wrap"><canvas id="attendanceChart" aria-label="Attendance trend chart"></canvas></div></section>
    <section class="content-card schedule-card"><div class="card-heading"><div><h2>Class schedule</h2><p>Monday to Friday timetable</p></div><span class="record-chip"><i class="bi bi-calendar-week me-1"></i><?= count($classes) ?> classes</span></div><div class="class-grid px-3 pb-2">
      <?php foreach ($schedule as $day => $items): ?><div class="day-column"><h3><?= e($day) ?></h3><?php if (!$items): ?><div class="camera-status">No classes scheduled</div><?php else: foreach ($items as $class): ?><div class="class-item"><strong><?= e($class['class_code']) ?> · <?= e($class['subject_name']) ?></strong><span><i class="bi bi-clock me-1"></i><?= e(date('g:i A',strtotime($class['start_time']))) ?>–<?= e(date('g:i A',strtotime($class['end_time']))) ?></span><span><i class="bi bi-geo-alt me-1"></i><?= e($class['room']) ?></span></div><?php endforeach; endif; ?></div><?php endforeach; ?>
    </div></section>
  </div>
  <div class="col-12 col-xl-4">
    <section class="content-card mb-3"><div class="card-heading"><div><h2><?= $role === 'student' ? 'Recent check-ins' : 'Live attendance feed' ?></h2><p>Latest class activity</p></div><span class="live-indicator"><i></i> Active</span></div><div class="px-3 pb-2">
      <?php if (!$feedRows): ?><p class="empty-state mb-0">Attendance scans will appear here.</p><?php else: foreach ($feedRows as $item): ?><div class="scan-feed"><span class="avatar"><?= e(initials($item['full_name'])) ?></span><span class="scan-feed-copy"><strong><?= e($item['full_name']) ?></strong><small><?= e($item['class_code']) ?> · <?= e($item['student_number']) ?></small></span><span class="text-end"><?= status_badge($item['status']) ?><small class="d-block camera-status mt-1"><?= $item['time_in'] ? e(date('g:i A',strtotime($item['time_in']))) : e(date('M j',strtotime($item['attendance_date']))) ?></small></span></div><?php endforeach; endif; ?>
      <?php if ($role !== 'student'): ?><button class="quick-action w-100 text-start bg-white" data-bs-toggle="modal" data-bs-target="#scanModal"><i class="bi bi-qr-code-scan"></i> Generate one-time PIN / QR check-in</button><?php endif; ?>
      <a class="quick-action" href="attendance_logs.php"><i class="bi bi-file-earmark-bar-graph"></i> Open attendance reports</a>
    </div></section>
    <section class="content-card"><div class="card-heading"><div><h2>Face registration</h2><p>Student verification readiness</p></div><i class="bi bi-person-bounding-box text-primary"></i></div><div class="px-3 pb-3"><div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span class="camera-status">Registered</span><strong><?= $faceCounts['Registered'] ?></strong></div><div class="d-flex justify-content-between align-items-center py-2"><span class="camera-status">Pending setup</span><strong><?= $faceCounts['Pending'] ?></strong></div><?php if ($role === 'student'): ?><a href="profile_set_face.php" class="quick-action"><i class="bi bi-camera"></i> Set up your face profile</a><?php else: ?><a href="students_manage.php" class="quick-action"><i class="bi bi-people"></i> Review student directory</a><?php endif; ?></div></section>
  </div>
</div>
<section class="content-card activity-card"><div class="card-heading"><div><h2>Recent monitoring logs</h2><p>The latest student attendance records</p></div><a class="subtle-link" href="attendance_logs.php">View all activity <i class="bi bi-arrow-right"></i></a></div><div class="table-responsive"><table class="table activity-table"><thead><tr><th>STUDENT</th><th>STUDENT ID</th><th>SUBJECT</th><th>DATE</th><th>TIME IN</th><th>METHOD</th><th>STATUS</th></tr></thead><tbody>
<?php if (!$recentRows): ?><tr><td colspan="7" class="empty-state">No attendance activity yet.</td></tr><?php else: foreach ($recentRows as $row): ?><tr><td><div class="student-cell"><span class="avatar student-avatar"><?= e(initials($row['full_name'])) ?></span><strong><?= e($row['full_name']) ?></strong></div></td><td class="table-secondary-text"><?= e($row['student_number']) ?></td><td><strong><?= e($row['class_code']) ?></strong><small class="d-block camera-status"><?= e($row['subject_name']) ?></small></td><td class="table-secondary-text"><?= e(date('M j, Y',strtotime($row['attendance_date']))) ?></td><td class="table-secondary-text"><?= $row['time_in'] ? e(date('g:i A',strtotime($row['time_in']))) : '—' ?></td><td class="table-secondary-text"><?= e($row['verification_method']) ?></td><td><?= status_badge($row['status']) ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div><div class="table-footer"><span>Showing latest <?= count($recentRows) ?> records</span><a href="attendance_logs.php">Open attendance report <i class="bi bi-arrow-right"></i></a></div></section>
<?php if ($role !== 'student'): ?>
<div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h2 class="modal-title">Create a time-limited check-in</h2><p class="camera-status mb-0 mt-1">Students can enter the PIN or scan the QR code before it expires.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="sessionForm" class="row g-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="col-8"><label class="field-label" for="sessionClass">CLASS</label><select class="form-select" id="sessionClass" name="class_id" required><option value="">Choose a class</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select></div><div class="col-4"><label class="field-label" for="sessionMinutes">EXPIRES IN</label><select class="form-select" id="sessionMinutes" name="minutes"><option value="2">2 min</option><option value="5" selected>5 min</option><option value="10">10 min</option></select></div><div class="col-12"><button class="btn btn-primary w-100 mt-2" type="submit"><i class="bi bi-lightning-charge me-1"></i> Generate PIN and QR</button></div></form><div id="sessionResult" class="mt-3 d-none"><div class="text-center p-3 bg-light rounded-3"><div class="overline mb-1">ONE-TIME CLASS PIN</div><div id="sessionPin" class="display-6 fw-bold text-primary"></div><div id="sessionExpiry" class="camera-status"></div><div id="sessionQr" class="d-flex justify-content-center mt-3"></div></div></div><div id="sessionError" class="alert alert-danger mt-3 d-none"></div></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
const sessionForm=document.getElementById('sessionForm');sessionForm?.addEventListener('submit',async(e)=>{e.preventDefault();const button=sessionForm.querySelector('button[type="submit"]');button.disabled=true;const data=new FormData(sessionForm);data.append('action','create');try{const response=await fetch('attendance_session.php',{method:'POST',body:data,headers:{'Accept':'application/json'}});const result=await response.json();if(!response.ok)throw new Error(result.error||'Could not create the check-in session.');document.getElementById('sessionPin').textContent=result.pin;document.getElementById('sessionExpiry').textContent='Expires '+result.expires_label;const box=document.getElementById('sessionQr');box.innerHTML='';await QRCode.toCanvas(result.url,{width:180,margin:1,color:{dark:'#0A2540',light:'#FFFFFF'}}).then(canvas=>box.appendChild(canvas));document.getElementById('sessionResult').classList.remove('d-none');document.getElementById('sessionError').classList.add('d-none');}catch(error){const box=document.getElementById('sessionError');box.textContent=error.message;box.classList.remove('d-none');}finally{button.disabled=false;}});
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>const ctx=document.getElementById('attendanceChart');if(ctx){new Chart(ctx,{type:'line',data:{labels:<?= json_encode($chartLabels) ?>,datasets:[{label:'Present',data:<?= json_encode($chartPresent) ?>,borderColor:'#059669',backgroundColor:'#05966914',fill:true,tension:.38,pointRadius:3},{label:'Late',data:<?= json_encode($chartLate) ?>,borderColor:'#D97706',backgroundColor:'#D9770610',fill:false,tension:.38,pointRadius:3},{label:'Absent',data:<?= json_encode($chartAbsent) ?>,borderColor:'#E11D48',backgroundColor:'#E11D4810',fill:false,tension:.38,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},plugins:{legend:{display:false},tooltip:{backgroundColor:'#0A2540',padding:10,displayColors:true}},scales:{x:{grid:{display:false},ticks:{color:'#8291a4',font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0,color:'#8291a4',font:{size:9}},grid:{color:'#edf1f5'}}}}});}</script>
<?php page_end(); ?>
