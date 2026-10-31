<?php
require_once __DIR__ . '/../includes/functions.php';
$officer = need('department');
$departmentId = (int)$officer['department_id'];

$departmentQuery = $pdo->prepare('SELECT name, is_active FROM departments WHERE id = ?');
$departmentQuery->execute([$departmentId]);
$department = $departmentQuery->fetch();
if (!$department) {
    http_response_code(403);
    exit('Your account is not assigned to a department.');
}
$departmentName = $department['name'];

$statusCountsQuery = $pdo->prepare('SELECT status, COUNT(*) AS total FROM complaints WHERE department_id = ? GROUP BY status');
$statusCountsQuery->execute([$departmentId]);
$statusCounts = [];
foreach ($statusCountsQuery->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['total'];
}
$totalComplaints = array_sum($statusCounts);
$pendingCount = $totalComplaints - ($statusCounts['resolved'] ?? 0) - ($statusCounts['rejected'] ?? 0);

$search = trim($_GET['q'] ?? '');
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}
$statusFilter = trim($_GET['status'] ?? '');
if (!in_array($statusFilter, array_merge([''], STATUSES), true)) {
    $statusFilter = '';
}
$where = ['c.department_id = ?'];
$queryParameters = [$departmentId];
if ($search !== '') {
    $where[] = '(c.title LIKE ? OR c.tracking_id LIKE ? OR c.category LIKE ? OR u.name LIKE ?)';
    $searchTerm = '%' . $search . '%';
    array_push($queryParameters, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
if ($statusFilter !== '') {
    $where[] = 'c.status = ?';
    $queryParameters[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);
$filteredCountQuery = $pdo->prepare("SELECT COUNT(*) FROM complaints c JOIN users u ON u.id = c.citizen_id WHERE $whereSql");
$filteredCountQuery->execute($queryParameters);
$filteredCount = (int)$filteredCountQuery->fetchColumn();
$pageSize = 25;
$totalPages = max(1, (int)ceil($filteredCount / $pageSize));
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page === false || $page === null ? 1 : max(1, min($page, $totalPages));
$offset = ($page - 1) * $pageSize;
$complaintsQuery = $pdo->prepare(
    "SELECT c.id, c.tracking_id, c.title, c.category, c.priority, c.status, c.created_at, u.name AS citizen_name
     FROM complaints c JOIN users u ON u.id = c.citizen_id
     WHERE $whereSql
     ORDER BY FIELD(c.priority, 'urgent', 'high', 'medium', 'low'), c.created_at DESC, c.id DESC
     LIMIT ? OFFSET ?"
);
foreach ($queryParameters as $index => $parameter) {
    $complaintsQuery->bindValue(
        $index + 1,
        $parameter,
        $index === 0 ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$complaintsQuery->bindValue(count($queryParameters) + 1, $pageSize, PDO::PARAM_INT);
$complaintsQuery->bindValue(count($queryParameters) + 2, $offset, PDO::PARAM_INT);
$complaintsQuery->execute();
$complaints = $complaintsQuery->fetchAll();

$notificationQuery = $pdo->prepare('SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5');
$notificationQuery->execute([$officer['id']]);
$notifications = $notificationQuery->fetchAll();
mark_notifications_read($officer['id']);

$title = 'Department queue';
require __DIR__ . '/../includes/header.php';
?>
<div class="workspace-layout">
<?php require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
<div class="workspace-main">
<section class="dashboard-banner mb-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div><span class="eyebrow">Department workspace</span><h1 class="h2 mt-2 mb-1"><?= e($departmentName) ?></h1><p class="mb-0">Review incoming reports and keep citizens informed as work progresses.</p></div>
    <div class="small text-white-50">Officer · <?= e($officer['name']) ?></div>
</section>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Assigned reports', $totalComplaints, 'fa-inbox'],
        ['Open work', $pendingCount, 'fa-hourglass-half'],
        ['In progress', (int)($statusCounts['in_progress'] ?? 0), 'fa-person-digging'],
        ['Resolved', (int)($statusCounts['resolved'] ?? 0), 'fa-circle-check'],
    ] as [$label, $count, $icon]): ?><div class="col-6 col-xl-3"><div class="card stat-card d-flex flex-row align-items-center gap-3"><div class="stat-icon"><i class="fa-solid <?= e($icon) ?>"></i></div><div><div class="h3 fw-bold mb-0"><?= $count ?></div><div class="stat-label"><?= e($label) ?></div></div></div></div><?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-xl-9">
        <section class="card p-3 p-md-4" id="complaint-queue">
            <form method="get" action="<?= e(url('department/dashboard.php')) ?>" class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-3">
                <div><span class="section-kicker">Department queue</span><h2 class="h4 mt-1 mb-0">Reports to review</h2></div>
                <div class="d-flex gap-2">
                    <input id="q" name="q" class="form-control" type="search" placeholder="Search reports" aria-label="Search reports" value="<?= e($search) ?>" maxlength="100">
                    <select id="fs" name="status" class="form-select" aria-label="Filter by status"><option value="">All statuses</option><?php foreach (STATUSES as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(STATUS_LABELS[$status]) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-outline-primary" type="submit">Search</button>
                </div>
            </form>
            <?php if (!$complaints): ?><div class="text-center py-5"><div class="service-icon mx-auto"><i class="fa-solid fa-inbox"></i></div><h3 class="h5"><?= $search !== '' || $statusFilter !== '' ? 'No matching reports' : 'Your queue is clear' ?></h3><p class="text-secondary mb-0"><?= $search !== '' || $statusFilter !== '' ? 'Try changing your search or status filter.' : 'New reports for your department will appear here.' ?></p><?php if ($search !== '' || $statusFilter !== ''): ?><a class="btn btn-outline-primary btn-sm mt-2" href="<?= e(url('department/dashboard.php')) ?>">Clear filters</a><?php endif; ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Report</th><th>Citizen</th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody>
                <?php foreach ($complaints as $complaint): ?><tr>
                    <td><div class="fw-bold"><?= e($complaint['title']) ?></div><div class="small text-secondary"><?= e($complaint['tracking_id']) ?> · <?= e($complaint['category']) ?></div></td><td><?= e($complaint['citizen_name']) ?></td><td><?= e(ucfirst($complaint['priority'])) ?></td><td><?= badge($complaint['status']) ?></td><td><a class="btn btn-sm btn-primary" href="<?= e(url('department/complaint_details.php?id=' . (int)$complaint['id'])) ?>">Manage</a></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
                <?php
                $pageUrl = static function (int $targetPage) use ($search, $statusFilter): string {
                    return url('department/dashboard.php?' . http_build_query(array_filter([
                        'q' => $search,
                        'status' => $statusFilter,
                        'page' => $targetPage,
                    ], static function ($value) { return $value !== ''; })));
                };
                $firstItem = $offset + 1;
                $lastItem = min($offset + count($complaints), $filteredCount);
                ?>
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mt-3">
                    <p class="small text-secondary mb-0">Showing <?= $firstItem ?>–<?= $lastItem ?> of <?= $filteredCount ?> reports</p>
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Department queue pages">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>" aria-label="Previous page">Previous</a></li>
                            <?php
                            $startPage = max(1, min($page - 2, $totalPages - 4));
                            $endPage = min($totalPages, $startPage + 4);
                            for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                            ?>
                            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($pageNumber)) ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(min($totalPages, $page + 1))) ?>" aria-label="Next page">Next</a></li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <aside class="col-xl-3">
        <section class="card p-3 mb-3"><span class="section-kicker">Workload</span><h2 class="h5 mt-1">Status breakdown</h2><canvas id="statusChart" aria-label="Complaints by status"></canvas></section>
        <section class="card p-3"><span class="section-kicker">Activity</span><h2 class="h5 mt-1">Notifications</h2>
            <?php if (!$notifications): ?><p class="small text-secondary mb-0">No new notifications.</p><?php endif; ?>
            <?php foreach ($notifications as $notification): ?><div class="notification-item"><i class="fa-solid fa-bell text-primary mt-1"></i><div><?= e($notification['message']) ?><small class="d-block text-secondary"><?= e(date('M j, g:i a', strtotime($notification['created_at']))) ?></small></div></div><?php endforeach; ?>
        </section>
    </aside>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('statusChart');
    if (canvas && window.Chart) {
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(static function ($status) { return STATUS_LABELS[$status] ?? ucfirst($status); }, array_keys($statusCounts)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{ data: <?= json_encode(array_values($statusCounts)) ?>, backgroundColor: ['#3478e5', '#55b6cf', '#74839a', '#f0b547', '#36a477', '#db6272', '#435a79'] }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    }
});
</script>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
