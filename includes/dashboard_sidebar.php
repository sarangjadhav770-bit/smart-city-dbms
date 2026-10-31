<?php
$dashboardUser = me();
$dashboardRole = $dashboardUser['role'] ?? '';
$dashboardPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$dashboardNavigation = [
    'citizen' => [
        ['Overview', 'citizen/dashboard.php', 'fa-house'],
        ['Track complaints', 'citizen/complaints.php', 'fa-location-crosshairs'],
        ['Raise a complaint', 'citizen/submit_complaint.php', 'fa-plus'],
    ],
    'officer' => [
        ['Overview', 'department/dashboard.php', 'fa-house'],
        ['Assigned complaints', 'department/dashboard.php#complaint-queue', 'fa-clipboard-list'],
    ],
    'department' => [
        ['Overview', 'department/dashboard.php', 'fa-house'],
        ['Assigned complaints', 'department/dashboard.php#complaint-queue', 'fa-clipboard-list'],
    ],
    'admin' => [
        ['Overview', 'admin/dashboard.php', 'fa-house'],
        ['Complaint management', 'admin/dashboard.php#complaint-management', 'fa-clipboard-list'],
        ['User management', 'admin/dashboard.php#account-management', 'fa-users-gear'],
        ['Departments', 'admin/dashboard.php#department-management', 'fa-building'],
    ],
];
?>
<aside class="workspace-sidebar">
    <div class="workspace-profile">
        <span class="avatar workspace-avatar"><?= e(strtoupper(substr($dashboardUser['name'] ?? 'U', 0, 1))) ?></span>
        <span><strong><?= e($dashboardUser['name'] ?? 'User') ?></strong><small><?= e(ucfirst($dashboardRole === 'department' ? 'officer' : $dashboardRole)) ?> workspace</small></span>
    </div>
    <div class="workspace-label">WORKSPACE</div>
    <nav class="workspace-nav" aria-label="Workspace navigation">
        <?php foreach ($dashboardNavigation[$dashboardRole] ?? [] as [$label, $destination, $icon]): ?>
            <?php $destinationPage = basename(parse_url($destination, PHP_URL_PATH) ?: ''); ?>
            <?php $isActive = strpos($destination, '#') === false && $destinationPage === $dashboardPage; ?>
            <a class="<?= $isActive ? 'active' : '' ?>" href="<?= e(url($destination)) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span><?php if ($label === 'Raise a complaint'): ?><i class="fa-solid fa-arrow-up-right-from-square ms-auto"></i><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="workspace-sidebar-bottom">
        <div class="sidebar-help"><i class="fa-regular fa-circle-question"></i><span><strong>Need a hand?</strong><small>Every report creates a better city.</small></span></div>
        <a class="sidebar-home" href="<?= e(url()) ?>"><i class="fa-solid fa-arrow-left"></i> Back to CivicPulse</a>
    </div>
</aside>
