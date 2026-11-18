<?php
$user = current_user();
$unread = unread_count();
$noticeQuery = db()->prepare('SELECT id,title,message,type,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5');
$noticeQuery->execute([$user['id']]);
$headerNotices = $noticeQuery->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> · AttendEase</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="app-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="main-area">
  <header class="topbar">
    <button class="mobile-menu" type="button" aria-label="Toggle navigation" onclick="document.body.classList.toggle('sidebar-open')"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><span class="breadcrumb-muted">Workspace</span><i class="bi bi-chevron-right"></i><span class="breadcrumb-current"><?= e($pageTitle) ?></span></div>
    <div class="topbar-right">
      <form class="top-search" action="attendance_logs.php" method="get"><i class="bi bi-search"></i><input name="q" type="search" aria-label="Search attendance" placeholder="Search attendance…"><kbd>/</kbd></form>
      <div class="dropdown">
        <button class="top-icon-button position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications"><i class="bi bi-bell"></i><?php if ($unread): ?><span class="bell-count"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></button>
        <div class="dropdown-menu dropdown-menu-end notification-dropdown">
          <div class="dropdown-head"><strong>Notifications</strong><a href="notifications.php">View all</a></div>
          <?php if (!$headerNotices): ?><div class="dropdown-empty">You’re all caught up.</div><?php else: foreach ($headerNotices as $notice): ?>
            <a class="dropdown-notice" href="notifications.php"><span class="dropdown-notice-icon type-<?= e($notice['type']) ?>"><i class="bi <?= $notice['type'] === 'alert' ? 'bi-exclamation-triangle' : ($notice['type'] === 'success' ? 'bi-check2' : 'bi-info-circle') ?>"></i></span><span><strong><?= e($notice['title']) ?></strong><small><?= e(text_preview($notice['message'], 66)) ?></small></span></a>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <div class="dropdown">
        <button class="profile-menu-button" data-bs-toggle="dropdown" aria-expanded="false"><span class="avatar top-avatar"><?= e(initials($user['full_name'])) ?></span><span class="top-user-copy"><strong><?= e($user['full_name']) ?></strong><small><?= e(role_label($user['role'])) ?></small></span><i class="bi bi-chevron-down"></i></button>
        <ul class="dropdown-menu dropdown-menu-end profile-dropdown"><li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Account profile</a></li><li><hr class="dropdown-divider"></li><li><form action="logout.php" method="post" class="px-2"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></li></ul>
      </div>
    </div>
  </header>
  <div class="page-content">
    <?php if ($message = flash('success')): ?><div class="alert alert-success app-alert alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($message = flash('error')): ?><div class="alert alert-danger app-alert alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
