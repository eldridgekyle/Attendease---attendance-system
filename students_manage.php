<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_role(['admin','teacher']);
$pdo = db();
$user = current_user();
$role = $user['role'];
$classSql = 'SELECT c.id,c.class_code,c.subject_name,c.teacher_id,c.schedule_day,c.start_time,c.end_time,c.room,u.full_name AS teacher_name FROM classes c JOIN users u ON u.id=c.teacher_id';
if ($role === 'teacher') { $classSql .= ' WHERE c.teacher_id=?'; }
$classSql .= ' ORDER BY c.class_code';
$classQuery = $pdo->prepare($classSql);
$classQuery->execute($role === 'teacher' ? [$user['id']] : []);
$classes = $classQuery->fetchAll();
$managedIds = array_map('intval', array_column($classes, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['student_id'] ?? 0);
    try {
        if ($action === 'save_student') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $number = strtoupper(trim((string)($_POST['student_number'] ?? '')));
            $program = trim((string)($_POST['program_year'] ?? ''));
            $section = strtoupper(trim((string)($_POST['section'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $classIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['class_ids'] ?? [])), static fn(int $classId): bool => $classId > 0)));
            if (text_length($name) < 2 || text_length($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || $number === '' || $program === '' || $section === '' || !$classIds) {
                throw new RuntimeException('Complete the student details and assign at least one class.');
            }
            foreach ($classIds as $classId) {
                if (!in_array($classId, $managedIds, true)) {
                    throw new RuntimeException('Choose only classes assigned to your account.');
                }
            }
            $pdo->beginTransaction();
            if ($id > 0) {
                $ownerSql = $role === 'teacher' ? ' AND EXISTS(SELECT 1 FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=s.id AND c.teacher_id=?)' : '';
                $ownerParams = [$id];
                if ($role === 'teacher') { $ownerParams[] = $user['id']; }
                $owner = $pdo->prepare('SELECT s.user_id FROM students s WHERE s.id=?' . $ownerSql . ' FOR UPDATE');
                $owner->execute($ownerParams);
                $userId = $owner->fetchColumn();
                if (!$userId) { throw new RuntimeException('This student is outside your class roster.'); }
                $pdo->prepare('UPDATE users SET full_name=?,email=? WHERE id=?')->execute([$name,$email,$userId]);
                $pdo->prepare('UPDATE students SET student_number=?,program_year=?,section=? WHERE id=?')->execute([$number,$program,$section,$id]);
                if ($role === 'admin') {
                    $pdo->prepare('DELETE FROM enrollments WHERE student_id=?')->execute([$id]);
                    $insert = $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,?)');
                    foreach ($classIds as $classId) { $insert->execute([$id,$classId]); }
                } else {
                    $pdo->prepare('DELETE e FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=? AND c.teacher_id=?')->execute([$id,$user['id']]);
                    $insert = $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,?)');
                    foreach ($classIds as $classId) { $insert->execute([$id,$classId]); }
                }
                $pdo->commit();
                flash('success', 'Student details and class enrollments updated.');
            } else {
                if (text_length($password) < 10) { throw new RuntimeException('Choose an initial password with at least 10 characters.'); }
                $insertUser = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,'student')");
                $insertUser->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO students (user_id,student_number,program_year,section) VALUES (?,?,?,?)')->execute([$userId,$number,$program,$section]);
                $studentId = (int)$pdo->lastInsertId();
                $insert = $pdo->prepare('INSERT INTO enrollments (student_id,class_id) VALUES (?,?)');
                foreach ($classIds as $classId) { $insert->execute([$studentId,$classId]); }
                $pdo->commit();
                flash('success', 'Student account and class enrollments created.');
            }
        } elseif ($action === 'toggle_active' && $id > 0 && $role === 'admin') {
            $query = $pdo->prepare('SELECT u.is_active FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?');
            $query->execute([$id]);
            $active = $query->fetchColumn();
            if ($active === false) { throw new RuntimeException('Student record not found.'); }
            $pdo->prepare('UPDATE users SET is_active=? WHERE id=(SELECT user_id FROM students WHERE id=?)')->execute([(int)!((int)$active),$id]);
            flash('success', (int)$active ? 'Student account deactivated. Attendance history was retained.' : 'Student account reactivated.');
        } elseif ($action === 'remove_roster' && $id > 0 && $role === 'teacher') {
            $delete = $pdo->prepare('DELETE e FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=? AND c.teacher_id=?');
            $delete->execute([$id,$user['id']]);
            flash($delete->rowCount() ? 'success' : 'error', $delete->rowCount() ? 'Student was removed from your class roster.' : 'This student is not in your class roster.');
        } elseif ($action === 'save_class' && $role === 'admin') {
            $classId = (int)($_POST['class_id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['class_code'] ?? '')));
            $subject = trim((string)($_POST['subject_name'] ?? ''));
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $day = (string)($_POST['schedule_day'] ?? '');
            $start = (string)($_POST['start_time'] ?? '');
            $end = (string)($_POST['end_time'] ?? '');
            $room = trim((string)($_POST['room'] ?? ''));
            if ($code === '' || text_length($code) > 24 || $subject === '' || text_length($subject) > 140 || $room === '' || text_length($room) > 40
                || !in_array($day,['Monday','Tuesday','Wednesday','Thursday','Friday'],true)
                || !preg_match('/^\d{2}:\d{2}$/',$start) || !preg_match('/^\d{2}:\d{2}$/',$end) || $start >= $end) {
                throw new RuntimeException('Complete the class details and choose a valid schedule.');
            }
            $teacherQuery = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='teacher' AND is_active=1");
            $teacherQuery->execute([$teacherId]);
            if (!$teacherQuery->fetchColumn()) { throw new RuntimeException('Choose an active instructor account.'); }
            if ($classId > 0) {
                $pdo->prepare('UPDATE classes SET class_code=?,subject_name=?,teacher_id=?,schedule_day=?,start_time=?,end_time=?,room=? WHERE id=?')->execute([$code,$subject,$teacherId,$day,$start,$end,$room,$classId]);
                flash('success', 'Class details updated.');
            } else {
                $pdo->prepare('INSERT INTO classes (class_code,subject_name,teacher_id,schedule_day,start_time,end_time,room) VALUES (?,?,?,?,?,?,?)')->execute([$code,$subject,$teacherId,$day,$start,$end,$room]);
                flash('success', 'Class created.');
            }
        } else {
            throw new RuntimeException('That management action is not available to your account.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'Could not save the changes. Check for duplicate email, student number, or class code.';
        flash('error', $message);
    }
    header('Location: students_manage.php');
    exit;
}

$term = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($term !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR s.student_number LIKE ? OR s.program_year LIKE ? OR s.section LIKE ?)';
    $like = '%' . $term . '%';
    array_push($params,$like,$like,$like,$like,$like);
}
if ($role === 'teacher') {
    $where[] = 'EXISTS(SELECT 1 FROM enrollments et JOIN classes ct ON ct.id=et.class_id WHERE et.student_id=s.id AND ct.teacher_id=?)';
    $params[] = $user['id'];
}
$classJoinCondition = $role === 'teacher' ? ' AND c.teacher_id=' . (int)$user['id'] : '';
$sql = 'SELECT s.id,s.user_id,s.student_number,s.program_year,s.section,s.updated_at,u.full_name,u.email,u.is_active,GROUP_CONCAT(DISTINCT c.class_code ORDER BY c.class_code SEPARATOR ", ") AS classes,GROUP_CONCAT(DISTINCT e.class_id ORDER BY e.class_id) AS class_ids FROM students s JOIN users u ON u.id=s.user_id LEFT JOIN enrollments e ON e.student_id=s.id LEFT JOIN classes c ON c.id=e.class_id' . $classJoinCondition;
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' GROUP BY s.id,s.user_id,s.student_number,s.program_year,s.section,s.updated_at,u.full_name,u.email,u.is_active ORDER BY u.full_name LIMIT 200';
$list = $pdo->prepare($sql);
$list->execute($params);
$students = $list->fetchAll();
if ($role === 'teacher') {
    $studentStatsQuery = $pdo->prepare('SELECT COUNT(DISTINCT s.id) total,COUNT(DISTINCT CASE WHEN u.is_active=1 THEN s.id END) active,COUNT(DISTINCT CASE WHEN u.is_active=0 THEN s.id END) inactive FROM students s JOIN users u ON u.id=s.user_id JOIN enrollments e ON e.student_id=s.id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=?');
    $studentStatsQuery->execute([$user['id']]);
    $studentStats = $studentStatsQuery->fetch();
} else {
    $studentStats = $pdo->query('SELECT COUNT(*) total,SUM(u.is_active=1) active,SUM(u.is_active=0) inactive FROM students s JOIN users u ON u.id=s.user_id')->fetch();
}
$classCounts = [];
foreach ($classes as $class) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE class_id=?');
    $count->execute([$class['id']]);
    $classCounts[(int)$class['id']] = (int)$count->fetchColumn();
}
$instructors = $role === 'admin' ? $pdo->query("SELECT id,full_name FROM users WHERE role='teacher' AND is_active=1 ORDER BY full_name")->fetchAll() : [];
page_start('Students & classes','students_manage.php');
?>
<div class="page-heading"><div><p class="overline">CAMPUS DIRECTORY</p><h1>Students & classes</h1><p class="heading-subtitle">Manage student accounts, class enrollments, and teaching assignments.</p></div><div class="d-flex gap-2 flex-wrap"><?php if($role==='admin'): ?><button class="btn btn-outline-secondary heading-action" data-bs-toggle="modal" data-bs-target="#classModal" onclick="prepareClassModal()"><i class="bi bi-journal-plus me-1"></i> Add class</button><?php endif; ?><button class="btn btn-primary heading-action" data-bs-toggle="modal" data-bs-target="#studentModal" onclick="prepareStudentModal()"><i class="bi bi-person-plus me-1"></i> Add new student</button></div></div>
<div class="row g-3 mb-3"><div class="col-6 col-lg-4"><article class="stat-card"><div class="stat-top"><span>Students in directory</span><span class="stat-icon icon-blue"><i class="bi bi-people"></i></span></div><div class="stat-value"><?= number_format((int)$studentStats['total']) ?></div><div class="stat-foot">All student profiles</div></article></div><div class="col-6 col-lg-4"><article class="stat-card"><div class="stat-top"><span>Active accounts</span><span class="stat-icon icon-green"><i class="bi bi-person-check"></i></span></div><div class="stat-value"><?= number_format((int)$studentStats['active']) ?></div><div class="stat-foot">Can sign in to AttendEase</div></article></div><div class="col-12 col-lg-4"><article class="stat-card"><div class="stat-top"><span>Inactive accounts</span><span class="stat-icon icon-amber"><i class="bi bi-person-x"></i></span></div><div class="stat-value"><?= number_format((int)$studentStats['inactive']) ?></div><div class="stat-foot">Records are retained</div></article></div></div>
<section class="content-card report-card"><div class="card-heading"><div><h2>Student directory</h2><p><?= count($students) ?> matching student profiles</p></div><div class="record-chip"><i class="bi bi-book me-1"></i><?= count($classes) ?> managed classes</div></div><form class="filter-bar" method="get"><div class="search-field"><i class="bi bi-search"></i><input name="q" value="<?= e($term) ?>" placeholder="Search name, student number, program…"></div><button class="btn btn-primary filter-button">Search directory</button><?php if($term!==''): ?><a href="students_manage.php" class="clear-filter">Reset</a><?php endif; ?></form><div class="table-responsive"><table class="table activity-table"><thead><tr><th>STUDENT</th><th>STUDENT NUMBER</th><th>PROGRAM / SECTION</th><th>ENROLLED CLASSES</th><th>ACCOUNT</th><th>UPDATED</th><th>ACTIONS</th></tr></thead><tbody>
<?php if(!$students): ?><tr><td colspan="7" class="empty-state">No students match this search. Add a student to get started.</td></tr><?php else: foreach($students as $s): $classIds=array_values(array_filter(array_map('intval',explode(',',(string)$s['class_ids'])))); ?><tr><td><div class="student-cell"><span class="avatar student-avatar"><?= e(initials($s['full_name'])) ?></span><span><strong><?= e($s['full_name']) ?></strong><small class="d-block helper-copy"><?= e($s['email']) ?></small></span></div></td><td class="table-secondary-text"><?= e($s['student_number']) ?></td><td><strong><?= e($s['program_year']) ?></strong><small class="d-block helper-copy"><?= e($s['section']) ?></small></td><td class="table-secondary-text"><?= e($s['classes']?:'Not enrolled') ?></td><td><?= status_badge((int)$s['is_active']?'Active':'Inactive') ?></td><td class="table-secondary-text"><?= e(date('M j, Y',strtotime($s['updated_at']))) ?></td><td><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#studentModal" onclick='editStudent(<?= json_encode(['id'=>(int)$s['id'],'full_name'=>$s['full_name'],'email'=>$s['email'],'student_number'=>$s['student_number'],'program_year'=>$s['program_year'],'section'=>$s['section'],'class_ids'=>$classIds],JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)' aria-label="Edit <?= e($s['full_name']) ?>"><i class="bi bi-pencil"></i></button><?php if($role==='admin'): ?><form method="post" onsubmit="return confirm('<?= (int)$s['is_active']?'Deactivate':'Reactivate' ?> this student account?')"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm <?= (int)$s['is_active']?'btn-outline-danger':'btn-outline-success' ?>" aria-label="<?= (int)$s['is_active']?'Deactivate':'Reactivate' ?> <?= e($s['full_name']) ?>"><i class="bi <?= (int)$s['is_active']?'bi-person-x':'bi-person-check' ?>"></i></button></form><?php else: ?><form method="post" onsubmit="return confirm('Remove this student from your classes? Their account and attendance records will remain.')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_roster"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove <?= e($s['full_name']) ?> from your roster"><i class="bi bi-person-dash"></i></button></form><?php endif; ?></div></td></tr><?php endforeach; endif; ?></tbody></table></div><div class="table-footer"><span>Showing up to 200 student records</span><span>Student directory</span></div></section>

<section class="content-card mt-3"><div class="card-heading"><div><h2>Class directory</h2><p>Course schedule and teaching assignments</p></div></div><div class="table-responsive"><table class="table activity-table"><thead><tr><th>CLASS</th><th>SUBJECT</th><th>INSTRUCTOR</th><th>SCHEDULE</th><th>ROOM</th><th>ENROLLED</th><?php if($role==='admin'): ?><th>ACTIONS</th><?php endif; ?></tr></thead><tbody><?php if(!$classes): ?><tr><td colspan="7" class="empty-state">No classes have been assigned.</td></tr><?php else: foreach($classes as $class): ?><tr><td><strong><?= e($class['class_code']) ?></strong></td><td><?= e($class['subject_name']) ?></td><td class="table-secondary-text"><?= e($class['teacher_name']) ?></td><td class="table-secondary-text"><?= e($class['schedule_day'].' · '.date('g:i A',strtotime($class['start_time'])).'–'.date('g:i A',strtotime($class['end_time']))) ?></td><td class="table-secondary-text"><?= e($class['room']) ?></td><td><?= $classCounts[(int)$class['id']] ?></td><?php if($role==='admin'): ?><td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#classModal" onclick='editClass(<?= json_encode(['id'=>(int)$class['id'],'class_code'=>$class['class_code'],'subject_name'=>$class['subject_name'],'teacher_id'=>(int)$class['teacher_id'],'schedule_day'=>$class['schedule_day'],'start_time'=>substr($class['start_time'],0,5),'end_time'=>substr($class['end_time'],0,5),'room'=>$class['room']],JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)' aria-label="Edit <?= e($class['class_code']) ?>"><i class="bi bi-pencil"></i></button></td><?php endif; ?></tr><?php endforeach; endif; ?></tbody></table></div></section>

<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" class="modal-content" id="studentForm"><?= csrf_field() ?><input type="hidden" name="action" value="save_student"><input type="hidden" name="student_id" id="student_id" value="0"><div class="modal-header"><div><h2 class="modal-title" id="studentModalTitle">Add student</h2><p class="helper-copy mb-0 mt-1">Create a student profile and assign one or more classes.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3"><div class="col-12"><label class="field-label" for="studentName">FULL NAME</label><input class="form-control" id="studentName" name="full_name" maxlength="120" required></div><div class="col-12"><label class="field-label" for="studentEmail">EMAIL</label><input class="form-control" id="studentEmail" name="email" type="email" required></div><div class="col-md-6"><label class="field-label" for="studentNumber">STUDENT NUMBER</label><input class="form-control" id="studentNumber" name="student_number" required></div><div class="col-md-6"><label class="field-label" for="studentSection">SECTION</label><input class="form-control" id="studentSection" name="section" required></div><div class="col-12"><label class="field-label" for="studentProgram">PROGRAM AND YEAR</label><input class="form-control" id="studentProgram" name="program_year" placeholder="BSIT · Year 2" required></div><div class="col-12"><label class="field-label" for="studentClass">ASSIGN TO CLASSES</label><select class="form-select enrollment-select" id="studentClass" name="class_ids[]" multiple required><?php foreach($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= e($class['class_code'].' · '.$class['subject_name']) ?></option><?php endforeach; ?></select><div class="form-text">Select every class this student should be enrolled in.</div></div><div class="col-12" id="passwordField"><label class="field-label" for="studentPassword">INITIAL PASSWORD</label><input class="form-control" id="studentPassword" name="password" type="password" minlength="10" placeholder="At least 10 characters"><div class="form-text">Share this initial password securely. The student can change it in their profile.</div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="studentSave">Create student</button></div></form></div></div>

<?php if($role==='admin'): ?><div class="modal fade" id="classModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" id="classForm"><?= csrf_field() ?><input type="hidden" name="action" value="save_class"><input type="hidden" name="class_id" id="classId" value="0"><div class="modal-header"><div><h2 class="modal-title" id="classModalTitle">Add class</h2><p class="helper-copy mb-0 mt-1">Set the course schedule and instructor.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-5"><label class="field-label" for="classCode">CLASS CODE</label><input class="form-control" id="classCode" name="class_code" maxlength="24" required></div><div class="col-md-7"><label class="field-label" for="classSubject">SUBJECT</label><input class="form-control" id="classSubject" name="subject_name" maxlength="140" required></div><div class="col-12"><label class="field-label" for="classTeacher">INSTRUCTOR</label><select class="form-select" id="classTeacher" name="teacher_id" required><option value="">Choose instructor</option><?php foreach($instructors as $instructor): ?><option value="<?= (int)$instructor['id'] ?>"><?= e($instructor['full_name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="field-label" for="classDay">DAY</label><select class="form-select" id="classDay" name="schedule_day" required><?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday'] as $day): ?><option><?= e($day) ?></option><?php endforeach; ?></select></div><div class="col-6"><label class="field-label" for="classStart">START</label><input class="form-control" id="classStart" name="start_time" type="time" required></div><div class="col-6"><label class="field-label" for="classEnd">END</label><input class="form-control" id="classEnd" name="end_time" type="time" required></div><div class="col-12"><label class="field-label" for="classRoom">ROOM</label><input class="form-control" id="classRoom" name="room" maxlength="40" required></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="classSave">Save class</button></div></form></div></div><?php endif; ?>

<script>
const managedClassIds=<?= json_encode($managedIds) ?>;
function prepareStudentModal(){const f=document.getElementById('studentForm');if(f.dataset.editing==='1')return;f.reset();f.dataset.editing='0';document.getElementById('student_id').value='0';document.getElementById('studentModalTitle').textContent='Add student';document.getElementById('studentSave').textContent='Create student';document.getElementById('passwordField').classList.remove('d-none');document.getElementById('studentPassword').required=true;for(const option of document.getElementById('studentClass').options)option.selected=false}
function editStudent(s){const f=document.getElementById('studentForm');f.dataset.editing='1';document.getElementById('student_id').value=s.id;document.getElementById('studentName').value=s.full_name;document.getElementById('studentEmail').value=s.email;document.getElementById('studentNumber').value=s.student_number;document.getElementById('studentProgram').value=s.program_year;document.getElementById('studentSection').value=s.section;for(const option of document.getElementById('studentClass').options)option.selected=s.class_ids.includes(Number(option.value));document.getElementById('studentPassword').required=false;document.getElementById('passwordField').classList.add('d-none');document.getElementById('studentModalTitle').textContent='Edit student';document.getElementById('studentSave').textContent='Save changes'}
document.getElementById('studentModal')?.addEventListener('hidden.bs.modal',()=>{document.getElementById('studentForm').dataset.editing='0'});
function prepareClassModal(){const f=document.getElementById('classForm');f.reset();document.getElementById('classId').value='0';document.getElementById('classModalTitle').textContent='Add class';document.getElementById('classSave').textContent='Create class'}
function editClass(c){document.getElementById('classId').value=c.id;document.getElementById('classCode').value=c.class_code;document.getElementById('classSubject').value=c.subject_name;document.getElementById('classTeacher').value=c.teacher_id;document.getElementById('classDay').value=c.schedule_day;document.getElementById('classStart').value=c.start_time;document.getElementById('classEnd').value=c.end_time;document.getElementById('classRoom').value=c.room;document.getElementById('classModalTitle').textContent='Edit class';document.getElementById('classSave').textContent='Save changes'}
</script>
<?php page_end(); ?>
