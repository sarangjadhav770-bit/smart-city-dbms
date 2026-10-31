<?php
require_once __DIR__ . '/../includes/functions.php';
$user = need('citizen');

$statsQuery = $pdo->prepare("SELECT COUNT(*) total, SUM(status IN ('submitted','under_review','assigned','reopened')) pending, SUM(status = 'in_progress') in_progress, SUM(status = 'resolved') resolved FROM complaints WHERE citizen_id = ?");
$statsQuery->execute([$user['id']]);
$stats = $statsQuery->fetch();
$analyticsQuery = $pdo->prepare('SELECT status, COUNT(*) total FROM complaints WHERE citizen_id = ? GROUP BY status');
$analyticsQuery->execute([$user['id']]);
$analytics = $analyticsQuery->fetchAll();
$stmt = $pdo->prepare('SELECT id, tracking_id, title, category, status, priority, created_at FROM complaints WHERE citizen_id = ? ORDER BY created_at DESC LIMIT 6');
$stmt->execute([$user['id']]);
$complaints = $stmt->fetchAll();
$notesQuery = $pdo->prepare('SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 4');
$notesQuery->execute([$user['id']]);
$notifications = $notesQuery->fetchAll();
mark_notifications_read($user['id']);

$title = 'My dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="workspace-layout">
<?php require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
<div class="workspace-main">
<section class="dashboard-banner mb-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div><span class="eyebrow">Citizen dashboard</span><h1 class="h2 mt-2 mb-1">Hello, <?= e($user['name']) ?></h1><p class="mb-0">Here’s the latest on the issues you’ve reported.</p></div>
    <a class="btn btn-light btn-lg text-primary" href="<?= e(url('citizen/submit_complaint.php')) ?>"><i class="fa-solid fa-plus me-2"></i>Report an issue</a>
</section>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['All reports', (int)$stats['total'], 'fa-clipboard-list'],
        ['Pending review', (int)$stats['pending'], 'fa-hourglass-half'],
        ['In progress', (int)$stats['in_progress'], 'fa-person-digging'],
        ['Resolved', (int)$stats['resolved'], 'fa-circle-check'],
    ] as [$label, $count, $icon]): ?>
        <div class="col-6 col-xl-3"><div class="card stat-card d-flex flex-row align-items-center gap-3"><div class="stat-icon"><i class="fa-solid <?= e($icon) ?>"></i></div><div><div class="h3 fw-bold mb-0"><?= $count ?></div><div class="stat-label"><?= e($label) ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <section class="card p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><div><span class="section-kicker">Your reports</span><h2 class="h4 mt-1 mb-0">Recent activity</h2></div><a class="btn btn-outline-primary btn-sm" href="<?= e(url('citizen/complaints.php')) ?>">View all</a></div>
            <?php if (!$complaints): ?>
                <div class="text-center py-5"><div class="service-icon mx-auto"><i class="fa-solid fa-inbox"></i></div><h3 class="h5">Nothing reported yet</h3><p class="text-secondary">When you report an issue, its updates will show up here.</p><a class="btn btn-primary" href="<?= e(url('citizen/submit_complaint.php')) ?>">Submit your first report</a></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Report</th><th>Status</th><th class="d-none d-md-table-cell">Submitted</th><th></th></tr></thead><tbody>
                    <?php foreach ($complaints as $complaint): ?>
                        <tr><td><div class="fw-bold"><?= e($complaint['title']) ?></div><div class="small text-secondary"><?= e($complaint['tracking_id']) ?> · <?= e($complaint['category']) ?></div></td><td><?= badge($complaint['status']) ?></td><td class="d-none d-md-table-cell small text-secondary"><?= e(date('M j, Y', strtotime($complaint['created_at']))) ?></td><td><a class="btn btn-sm btn-outline-primary" aria-label="View <?= e($complaint['tracking_id']) ?>" href="<?= e(url('citizen/complaint_details.php?id=' . (int)$complaint['id'])) ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
    <aside class="col-lg-4">
        <section class="card p-4 mb-3"><span class="section-kicker">Updates</span><h2 class="h5 mt-1 mb-3">Notifications</h2>
            <?php if (!$notifications): ?><p class="text-secondary small mb-0">Updates about your reports will appear here.</p>
            <?php else: foreach ($notifications as $notification): ?><div class="notification-item"><i class="fa-solid fa-bell text-primary mt-1"></i><div><div><?= e($notification['message']) ?></div><small class="text-secondary"><?= e(date('M j, g:i a', strtotime($notification['created_at']))) ?></small></div></div><?php endforeach; endif; ?>
        </section>
        <section class="card p-4"><div class="service-icon"><i class="fa-solid fa-circle-info"></i></div><h2 class="h6 fw-bold">Need to report another issue?</h2><p class="small text-secondary">Choose a service category and include the closest address or map pin.</p><a href="<?= e(url('citizen/submit_complaint.php')) ?>" class="btn btn-outline-primary btn-sm">Start a report</a></section>
    </aside>
</div>
<section class="card p-3 p-md-4 mt-4">
    <div><span class="section-kicker">Your activity</span><h2 class="h5 mt-1">Complaint analytics</h2></div>
    <?php if (!$stats['total']): ?><p class="text-secondary mb-0">Your report activity will appear here after you submit your first complaint.</p><?php else: ?>
        <div class="dashboard-chart"><canvas id="citizenStatusChart" aria-label="Your complaints by status"></canvas></div>
    <?php endif; ?>
</section>
<?php if ($stats['total']): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('citizenStatusChart');
    if (canvas && window.Chart) {
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(static function ($status) { return STATUS_LABELS[$status] ?? ucfirst($status); }, array_column($analytics, 'status')), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{ label: 'Reports', data: <?= json_encode(array_map('intval', array_column($analytics, 'total'))) ?>, backgroundColor: '#38bdf8', borderRadius: 7, maxBarThickness: 42 }]
            },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }
});
</script>
<?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
