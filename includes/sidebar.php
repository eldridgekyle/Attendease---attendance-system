<?php
$user = current_user();
$role = $user['role'] ?? 'student';
$nav = [
    ['dashboard.php', 'Overview', 'bi-grid-1x2'],
];
if (in_array($role, ['admin', 'teacher'], true)) {
    $nav[] = ['attendance_logs.php', 'Attendance logs', 'bi-calendar2-check'];
    $nav[] = ['students_manage.php', 'Students & classes', 'bi-people'];
} else {
    $nav[] = ['attendance_logs.php', 'My attendance', 'bi-calendar2-check'];
}
$nav[] = ['profile.php', 'Profile & security', 'bi-person-gear'];
$nav[] = ['notifications.php', 'Notifications', 'bi-bell'];
?>
<aside class="sidebar" id="appSidebar">
  <a class="brand" href="dashboard.php"><span class="brand-mark"><i class="bi bi-check2-circle"></i></span><span>Attend<span class="brand-light">Ease</span></span></a>
  <div class="sidebar-role"><span class="role-dot"></span><?= e(role_label($role)) ?> workspace</div>
  <div class="side-label">WORKSPACE</div>
  <nav class="side-nav" aria-label="Main navigation">
    <?php foreach ($nav as [$href, $label, $icon]): ?>
      <a class="nav-link <?= $activePage === $href ? 'active' : '' ?>" href="<?= e($href) ?>">
        <i class="bi <?= e($icon) ?> nav-icon"></i><span><?= e($label) ?></span>
        <?php if ($href === 'notifications.php' && unread_count() > 0): ?><span class="nav-count"><?= unread_count() ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom">
    <div class="help-card"><span class="help-icon"><i class="bi bi-stars"></i></span><strong>Need a hand?</strong><span>Our campus team is ready to help.</span><a href="mailto:support@attendease.test">Contact support <i class="bi bi-arrow-up-right"></i></a></div>
    <div class="side-user">
      <span class="avatar avatar-small"><?= avatar_contents($user) ?></span>
      <div class="side-user-copy"><strong><?= e($user['full_name']) ?></strong><span><?= e(role_label($role)) ?> account</span></div>
      <form action="logout.php" method="post" class="m-0"><?= csrf_field() ?><button class="icon-button" aria-label="Sign out" title="Sign out"><i class="bi bi-box-arrow-right"></i></button></form>
    </div>
  </div>
</aside>
