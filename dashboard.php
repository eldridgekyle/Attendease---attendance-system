<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_login();
$pdo = db();
$user = current_user();
$role = $user['role'];
$student = $role === 'student' ? student_record() : null;
$adminOverview = null;
if ($role === 'admin') {
    $adminOverview = $pdo->query("SELECT
      (SELECT COUNT(*) FROM users WHERE role='teacher' AND is_active=1) AS instructors,
      (SELECT COUNT(*) FROM classes) AS classes,
      (SELECT COUNT(*) FROM attendance_sessions) AS sessions,
      (SELECT COUNT(*) FROM users WHERE role='teacher' AND is_active=0) AS pending_instructors")->fetch();
}
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
    $totalStudents = (int)$pdo->query('SELECT COUNT(*) FROM students s JOIN users u ON u.id=s.user_id WHERE u.is_active=1')->fetchColumn();
} elseif ($role === 'teacher') {
    $q = $pdo->prepare('SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN classes c ON c.id=e.class_id JOIN students s ON s.id=e.student_id JOIN users u ON u.id=s.user_id WHERE c.teacher_id=? AND u.is_active=1');
    $q->execute([$user['id']]);
    $totalStudents = (int)$q->fetchColumn();
} else {
    $q = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id=?');
    $q->execute([$student['id'] ?? 0]);
    $totalStudents = (int)$q->fetchColumn();
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
    $trendQuery=$pdo->prepare('SELECT SUM(created_at>=?) current_total,SUM(created_at>=? AND created_at<?) previous_total FROM users WHERE role="student" AND is_active=1');
    $trendQuery->execute([$weekStart,$lastWeekStart,$weekStart]);$trendValues=$trendQuery->fetch();
    $rosterTrend=$percentageChange((int)$trendValues['current_total'],(int)$trendValues['previous_total']);
}elseif($role==='teacher'){
    $trendQuery=$pdo->prepare('SELECT COUNT(DISTINCT CASE WHEN u.created_at>=? THEN u.id END) current_total,COUNT(DISTINCT CASE WHEN u.created_at>=? AND u.created_at<? THEN u.id END) previous_total FROM students s JOIN users u ON u.id=s.user_id JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? AND u.is_active=1');
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

$recentSql = "SELECT a.id,a.attendance_date,a.time_in,a.time_out,a.status,a.verification_method,s.student_number,s.program_year,u.full_name,c.class_code,c.subject_name FROM attendance_logs a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id JOIN classes c ON c.id=a.class_id $scopeJoin WHERE 1=1 $scopeWhere ORDER BY a.attendance_date DESC,a.time_in DESC,a.id DESC LIMIT 8";
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

$feedSql = "SELECT a.status,a.time_in,a.attendance_date,u.full_name,s.student_number,c.class_code FROM attendance_logs a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id JOIN classes c ON c.id=a.class_id $scopeJoin WHERE 1=1 $scopeWhere ORDER BY a.attendance_date DESC,a.time_in DESC,a.id DESC LIMIT 4";
$feed = $pdo->prepare($feedSql);
$feed->execute($scopeParams);
$feedRows = $feed->fetchAll();
$activeSessionSql = "SELECT COUNT(DISTINCT a.id) FROM attendance_sessions a JOIN classes c ON c.id=a.class_id WHERE a.secret_ciphertext IS NOT NULL AND a.ended_at IS NULL AND a.expires_at>NOW()";
if ($role === 'teacher') {
    $activeSessionQuery = $pdo->prepare($activeSessionSql . ' AND c.teacher_id=?');
    $activeSessionQuery->execute([$user['id']]);
} elseif ($role === 'student') {
    $activeSessionQuery = $pdo->prepare($activeSessionSql . ' AND c.id IN (SELECT class_id FROM enrollments WHERE student_id=?)');
    $activeSessionQuery->execute([$student['id'] ?? 0]);
} else {
    $activeSessionQuery = $pdo->query($activeSessionSql);
}
$activeSessions = (int)$activeSessionQuery->fetchColumn();
$title = $role === 'student' ? 'Your attendance' : ($role === 'teacher' ? 'Class overview' : 'Campus overview');
page_start('Overview', 'dashboard.php');
?>
<div class="page-heading"><div><p class="overline"><?= e(strtoupper(date('l, F j, Y'))) ?></p><h1>Good <?= (int)date('G') < 12 ? 'morning' : ((int)date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['full_name'])[0]) ?> <span class="wave">✦</span></h1><p class="heading-subtitle"><?= e($title) ?> · Your attendance picture at a glance.</p></div><a href="attendance_logs.php" class="btn btn-primary heading-action"><i class="bi bi-calendar2-check me-1"></i> <?= $role === 'student' ? 'View my records' : 'Manage attendance' ?></a></div>
<div class="section-heading"><div><h2><?= $role === 'student' ? 'Your attendance today' : 'Today at a glance' ?></h2><p>Live attendance overview</p></div><span class="live-indicator"><i></i> Live data</span></div>
<div class="row g-3 stats-row">
<?php foreach ([[$role==='student'?'My classes':'Active students',$totalStudents,$role==='student'?'Enrolled classes':'vs last week '.$rosterTrend,'bi-people','blue',$role==='student'?(string)$totalStudents:$rosterTrend],['Present today',$counts['Present'],'vs yesterday','bi-check2-circle','green',$percentageChange($counts['Present'],$previousCounts['Present'])],['Late arrivals',$counts['Late'],'vs yesterday','bi-clock-history','amber',$percentageChange($counts['Late'],$previousCounts['Late'])],['Absent today',$counts['Absent'],'vs yesterday','bi-person-x','red',$percentageChange($counts['Absent'],$previousCounts['Absent'])]] as [$label,$value,$note,$icon,$tone,$trendValue]): ?>
  <div class="col-6 col-xl-3"><article class="stat-card"><div class="stat-top"><span><?= e($label) ?></span><span class="stat-icon icon-<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span></div><div class="stat-value"><?= number_format($value) ?></div><div class="stat-foot"><span class="stat-<?= e($tone) ?>"><i class="bi bi-graph-up-arrow"></i> <?= e($trendValue) ?></span> <?= e($note) ?></div><div class="stat-accent accent-<?= e($tone) ?>"></div></article></div>
<?php endforeach; ?>
</div>
<?php if ($role === 'admin'): ?><div class="row g-3 mb-3 admin-overview-row"><?php foreach ([['Instructors',$adminOverview['instructors'],'bi-person-badge','blue'],['Classes',$adminOverview['classes'],'bi-journal-bookmark','green'],['Attendance sessions',$adminOverview['sessions'],'bi-clock-history','amber'],['Pending approvals',$adminOverview['pending_instructors'],'bi-person-check','red']] as [$adminLabel,$adminValue,$adminIcon,$adminTone]): ?><div class="col-6 col-xl-3"><article class="stat-card"><div class="stat-top"><span><?= e($adminLabel) ?></span><span class="stat-icon icon-<?= e($adminTone) ?>"><i class="bi <?= e($adminIcon) ?>"></i></span></div><div class="stat-value"><?= number_format((int)$adminValue) ?></div><div class="stat-foot">Current database total</div></article></div><?php endforeach; ?></div><?php endif; ?>
<div class="row g-3 dashboard-mid">
  <div class="col-12 col-xl-8">
    <section class="content-card mb-3"><div class="card-heading"><div><h2>Attendance analytics</h2><p>Daily attendance trends for the last 7 days</p></div><div class="attendance-meta"><span><i class="bi bi-circle-fill text-success me-1"></i>Present</span><span><i class="bi bi-circle-fill text-warning me-1"></i>Late</span><span><i class="bi bi-circle-fill text-danger me-1"></i>Absent</span></div></div><div class="chart-wrap"><canvas id="attendanceChart" aria-label="Attendance trend chart"></canvas></div></section>
    <section class="content-card schedule-card"><div class="card-heading"><div><h2>Class schedule</h2><p>Monday to Friday timetable</p></div><span class="record-chip"><i class="bi bi-calendar-week me-1"></i><?= count($classes) ?> classes</span></div><div class="class-grid px-3 pb-2">
      <?php foreach ($schedule as $day => $items): ?><div class="day-column"><h3><?= e($day) ?></h3><?php if (!$items): ?><div class="helper-copy">No classes scheduled</div><?php else: foreach ($items as $class): ?><div class="class-item"><strong><?= e($class['class_code']) ?> · <?= e($class['subject_name']) ?></strong><span><i class="bi bi-clock me-1"></i><?= e(date('g:i A',strtotime($class['start_time']))) ?>–<?= e(date('g:i A',strtotime($class['end_time']))) ?></span><span><i class="bi bi-geo-alt me-1"></i><?= e($class['room']) ?></span></div><?php endforeach; endif; ?></div><?php endforeach; ?>
    </div></section>
  </div>
  <div class="col-12 col-xl-4">
    <section class="content-card mb-3"><div class="card-heading"><div><h2><?= $role === 'student' ? 'Recent check-ins' : 'Live attendance feed' ?></h2><p>Latest class activity</p></div><span class="live-indicator"><i></i> Active</span></div><div class="px-3 pb-2">
      <?php if (!$feedRows): ?><p class="empty-state mb-0">Attendance check-ins will appear here.</p><?php else: foreach ($feedRows as $item): ?><div class="scan-feed"><span class="avatar"><?= e(initials($item['full_name'])) ?></span><span class="scan-feed-copy"><strong><?= e($item['full_name']) ?></strong><small><?= e($item['class_code']) ?> · <?= e($item['student_number']) ?></small></span><span class="text-end"><?= status_badge($item['status']) ?><small class="d-block helper-copy mt-1"><?= $item['time_in'] ? e(date('g:i A',strtotime($item['time_in']))) : e(date('M j',strtotime($item['attendance_date']))) ?></small></span></div><?php endforeach; endif; ?>
      <?php if ($role !== 'student'): ?><a class="quick-action" href="attendance_logs.php"><i class="bi bi-play-circle"></i> Start an attendance session</a><?php endif; ?>
      <a class="quick-action" href="attendance_logs.php"><i class="bi bi-file-earmark-bar-graph"></i> <?= $role === 'student' ? 'Check in or review records' : 'Open attendance reports' ?></a>
    </div></section>
    <section class="content-card"><div class="card-heading"><div><h2>Attendance sessions</h2><p>Current classroom activity</p></div><span class="stat-icon icon-blue"><i class="bi bi-clock-history"></i></span></div><div class="px-3 pb-3"><div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span class="helper-copy">Active now</span><strong><?= number_format($activeSessions) ?></strong></div><?php if ($role === 'student'): ?><a href="attendance_logs.php" class="quick-action"><i class="bi bi-keyboard"></i> Enter your class code</a><?php else: ?><a href="attendance_logs.php" class="quick-action"><i class="bi bi-play-circle"></i> Manage class sessions</a><?php endif; ?></div></section>
  </div>
</div>
<section class="content-card activity-card"><div class="card-heading"><div><h2>Recent attendance records</h2><p>The latest student attendance records</p></div><a class="subtle-link" href="attendance_logs.php">View all activity <i class="bi bi-arrow-right"></i></a></div><div class="table-responsive"><table class="table activity-table"><thead><tr><th>STUDENT</th><th>STUDENT ID</th><th>SUBJECT</th><th>DATE</th><th>TIME IN</th><th>METHOD</th><th>STATUS</th></tr></thead><tbody>
<?php if (!$recentRows): ?><tr><td colspan="7" class="empty-state">No attendance activity yet.</td></tr><?php else: foreach ($recentRows as $row): ?><tr><td><div class="student-cell"><span class="avatar student-avatar"><?= e(initials($row['full_name'])) ?></span><strong><?= e($row['full_name']) ?></strong></div></td><td class="table-secondary-text"><?= e($row['student_number']) ?></td><td><strong><?= e($row['class_code']) ?></strong><small class="d-block helper-copy"><?= e($row['subject_name']) ?></small></td><td class="table-secondary-text"><?= e(date('M j, Y',strtotime($row['attendance_date']))) ?></td><td class="table-secondary-text"><?= $row['time_in'] ? e(date('g:i A',strtotime($row['time_in']))) : '—' ?></td><td class="table-secondary-text"><?= e($row['verification_method']) ?></td><td><?= status_badge($row['status']) ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div><div class="table-footer"><span>Showing latest <?= count($recentRows) ?> records</span><a href="attendance_logs.php">Open attendance report <i class="bi bi-arrow-right"></i></a></div></section>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>const ctx=document.getElementById('attendanceChart');if(ctx){new Chart(ctx,{type:'line',data:{labels:<?= json_encode($chartLabels) ?>,datasets:[{label:'Present',data:<?= json_encode($chartPresent) ?>,borderColor:'#059669',backgroundColor:'#05966914',fill:true,tension:.38,pointRadius:3},{label:'Late',data:<?= json_encode($chartLate) ?>,borderColor:'#D97706',backgroundColor:'#D9770610',fill:false,tension:.38,pointRadius:3},{label:'Absent',data:<?= json_encode($chartAbsent) ?>,borderColor:'#E11D48',backgroundColor:'#E11D4810',fill:false,tension:.38,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},plugins:{legend:{display:false},tooltip:{backgroundColor:'#0A2540',padding:10,displayColors:true}},scales:{x:{grid:{display:false},ticks:{color:'#8291a4',font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0,color:'#8291a4',font:{size:9}},grid:{color:'#edf1f5'}}}}});}</script>
<?php page_end(); ?>
