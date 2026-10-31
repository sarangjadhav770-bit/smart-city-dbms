<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = need('admin');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    $action = $_POST['action'] ?? 'complaint_status';
    if ($action === 'department_create') {
        $departmentName = trim($_POST['department_name'] ?? '');
        $description = trim($_POST['department_description'] ?? '');
        if (strlen($departmentName) < 2 || strlen($departmentName) > 100 || strlen($description) > 500) {
            $error = 'Enter a department name (2–100 characters) and a description no longer than 500 characters.';
        } else {
            $duplicate = $pdo->prepare('SELECT id FROM departments WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $duplicate->execute([$departmentName]);
            if ($duplicate->fetchColumn()) {
                $error = 'A department with that name already exists.';
            } else {
                $pdo->prepare('INSERT INTO departments(name, description, is_active) VALUES(?, ?, 1)')->execute([$departmentName, $description !== '' ? $description : null]);
                flash('Department added and enabled for report routing.');
                go('admin/dashboard.php#department-management');
            }
        }
    } elseif ($action === 'department_status') {
        $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: 0;
        $active = $_POST['active'] ?? '';
        if ($departmentId < 1 || !in_array($active, ['0', '1'], true)) {
            $error = 'Choose a valid department and routing state.';
        } else {
            $departmentUpdate = $pdo->prepare('UPDATE departments SET is_active = ? WHERE id = ?');
            $departmentUpdate->execute([(int)$active, $departmentId]);
            if ($departmentUpdate->rowCount() === 0) {
                $departmentCheck = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
                $departmentCheck->execute([$departmentId]);
                if (!$departmentCheck->fetchColumn()) {
                    $error = 'That department could not be found.';
                }
            }
            if (!$error) {
                flash($active === '1' ? 'Department enabled for new report routing.' : 'Department disabled for new report routing.');
                go('admin/dashboard.php#department-management');
            }
        }
    } elseif ($action === 'account_status') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: 0;
        $active = $_POST['active'] ?? '';
        $targetQuery = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
        $targetQuery->execute([$userId]);
        $target = $targetQuery->fetch();
        if (!$target || !in_array($active, ['0', '1'], true)) {
            $error = 'Choose a valid account and access state.';
        } elseif ((int)$target['id'] === $admin['id'] && $active === '0') {
            $error = 'You cannot deactivate the account you are currently using.';
        } elseif ($target['role'] === 'admin' && (int)$target['is_active'] === 1 && $active === '0' &&
            (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) {
            $error = 'At least one active administrator must remain.';
        } else {
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([(int)$active, $userId]);
            flash($active === '1' ? 'Account activated.' : 'Account deactivated.');
            go('admin/dashboard.php#account-management');
        }
    } else {
        $complaintId = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT) ?: 0;
        $newStatus = $_POST['status'] ?? '';
        $note = trim($_POST['note'] ?? '');
        if ($complaintId < 1 || !in_array($newStatus, STATUSES, true)) {
            $error = 'Choose a valid report and status.';
        } else {
            $pdo->beginTransaction();
            try {
                $find = $pdo->prepare('SELECT id, tracking_id, citizen_id, status FROM complaints WHERE id = ? FOR UPDATE');
                $find->execute([$complaintId]);
                $complaint = $find->fetch();
                if (!$complaint) {
                    throw new RuntimeException('That report could not be found.');
                }
                if ($complaint['status'] !== $newStatus) {
                    $pdo->prepare('UPDATE complaints SET status = ? WHERE id = ?')->execute([$newStatus, $complaintId]);
                    $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([
                        $complaintId,
                        $newStatus,
                        $note !== '' ? $note : "Status updated to " . (STATUS_LABELS[$newStatus] ?? $newStatus) . ' by an administrator.',
                        $admin['id'],
                    ]);
                    notify((int)$complaint['citizen_id'], "Complaint {$complaint['tracking_id']} is now: " . (STATUS_LABELS[$newStatus] ?? $newStatus));
                }
                $pdo->commit();
                flash('Report updated.');
                go('admin/dashboard.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('CivicPulse admin update failed: ' . $exception->getMessage());
                    $error = 'The report could not be updated. Please try again.';
                }
            }
        }
    }
}

$summary = $pdo->query("SELECT COUNT(*) total, SUM(status IN ('submitted','under_review','assigned','in_progress','reopened')) active, SUM(status = 'resolved') resolved FROM complaints")->fetch();
$counts = $pdo->query("SELECT CASE WHEN role = 'department' THEN 'officer' ELSE role END AS role, COUNT(*) total FROM users GROUP BY CASE WHEN role = 'department' THEN 'officer' ELSE role END")->fetchAll(PDO::FETCH_KEY_PAIR);
$departments = $pdo->query("SELECT d.id, d.name, d.description, d.is_active, COUNT(DISTINCT u.id) officers, COUNT(DISTINCT c.id) complaints FROM departments d LEFT JOIN users u ON u.department_id = d.id AND u.role IN ('officer','department') AND u.is_active = 1 LEFT JOIN complaints c ON c.department_id = d.id GROUP BY d.id, d.name, d.description, d.is_active ORDER BY d.name")->fetchAll();
$statusAnalytics = $pdo->query('SELECT status, COUNT(*) total FROM complaints GROUP BY status')->fetchAll();
$mappedComplaints = $pdo->query('SELECT tracking_id, title, location, status, latitude, longitude FROM complaints WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY created_at DESC LIMIT 500')->fetchAll();
$statusFilter = trim($_GET['status'] ?? '');
if (!in_array($statusFilter, array_merge([''], STATUSES), true)) {
    $statusFilter = '';
}
$sql = 'SELECT c.id, c.tracking_id, c.title, c.category, c.priority, c.status, c.created_at, u.name AS citizen_name, d.name AS department_name FROM complaints c JOIN users u ON u.id = c.citizen_id JOIN departments d ON d.id = c.department_id';
$params = [];
if ($statusFilter !== '') {
    $sql .= ' WHERE c.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY c.created_at DESC LIMIT 100';
$complaintQuery = $pdo->prepare($sql);
$complaintQuery->execute($params);
$complaints = $complaintQuery->fetchAll();
$accountQuery = $pdo->query("SELECT u.id, u.name, u.email, u.role, u.department_id, u.is_active, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY u.id DESC LIMIT 25");
$accounts = $accountQuery->fetchAll();

$title = 'Administration';
require __DIR__ . '/../includes/header.php';
?>
<div class="workspace-layout">
<?php require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
<div class="workspace-main">
<section class="dashboard-banner mb-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div><span class="eyebrow">CivicPulse administration</span><h1 class="h2 mt-2 mb-1">Operations overview</h1><p class="mb-0">Review city reports and monitor service activity.</p></div>
    <div class="small text-white-50">Signed in as <?= e($admin['name']) ?></div>
</section>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['All reports', (int)$summary['total'], 'fa-clipboard-list'],
        ['Open reports', (int)$summary['active'], 'fa-hourglass-half'],
        ['Resolved', (int)$summary['resolved'], 'fa-circle-check'],
        ['Citizens', (int)($counts['citizen'] ?? 0), 'fa-people-group'],
    ] as [$label, $count, $icon]): ?><div class="col-6 col-xl-3"><div class="card stat-card d-flex flex-row align-items-center gap-3"><div class="stat-icon"><i class="fa-solid <?= e($icon) ?>"></i></div><div><div class="h3 fw-bold mb-0"><?= $count ?></div><div class="stat-label"><?= e($label) ?></div></div></div></div><?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-xl-9">
        <section class="card p-3 p-md-4" id="complaint-management">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
                <div><span class="section-kicker">City-wide queue</span><h2 class="h4 mt-1 mb-0">Complaint management</h2></div>
                <form method="get" class="d-flex gap-2"><select class="form-select" name="status" aria-label="Filter reports by status"><option value="">All statuses</option><?php foreach (STATUSES as $option): ?><option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= e(STATUS_LABELS[$option]) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary">Filter</button></form>
            </div>
            <?php if (!$complaints): ?><div class="text-center text-secondary py-5"><i class="fa-solid fa-inbox fa-2x mb-3"></i><p class="mb-0">No reports match this filter.</p></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Report</th><th>Department</th><th>Priority</th><th>Status</th><th>Update status</th></tr></thead><tbody>
                <?php foreach ($complaints as $complaint): ?><tr class="row-item" data-s="<?= e($complaint['status']) ?>">
                    <td><a class="fw-bold text-decoration-none" href="<?= e(url('admin/complaint_details.php?id=' . (int)$complaint['id'])) ?>"><?= e($complaint['title']) ?></a><div class="small text-secondary"><?= e($complaint['tracking_id']) ?> · <?= e($complaint['citizen_name']) ?></div></td>
                    <td class="small"><?= e($complaint['department_name']) ?></td><td><?= e(ucfirst($complaint['priority'])) ?></td><td><?= badge($complaint['status']) ?></td>
                    <td><form method="post" class="d-flex gap-1 align-items-center"><?= csrf() ?><input type="hidden" name="action" value="complaint_status"><input type="hidden" name="complaint_id" value="<?= (int)$complaint['id'] ?>"><select class="form-select form-select-sm" name="status" aria-label="New status for <?= e($complaint['tracking_id']) ?>"><?php foreach (STATUSES as $option): ?><option value="<?= e($option) ?>" <?= $complaint['status'] === $option ? 'selected' : '' ?>><?= e(STATUS_LABELS[$option]) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" aria-label="Save status">Save</button></form></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
                <p class="small text-secondary mb-0">Showing up to 100 most recent reports.</p>
            <?php endif; ?>
        </section>
    </div>
    <aside class="col-xl-3">
        <section class="card p-3 mb-3"><span class="section-kicker">Service teams</span><h2 class="h5 mt-1">Departments</h2>
            <?php foreach ($departments as $department): ?><div class="department-row"><div class="d-flex justify-content-between gap-2"><strong><?= e($department['name']) ?></strong><span class="small text-secondary"><?= (int)$department['complaints'] ?></span></div><div class="small text-secondary"><?= (int)$department['officers'] ?> department officers</div></div><?php endforeach; ?>
        </section>
        <section class="card p-3"><span class="section-kicker">Accounts</span><h2 class="h5 mt-1">User accounts</h2>
            <?php foreach (['citizen' => 'Citizens', 'officer' => 'Department officers', 'admin' => 'Administrators'] as $role => $label): ?><div class="d-flex justify-content-between py-2 border-bottom"><span><?= e($label) ?></span><strong><?= (int)($counts[$role] ?? 0) ?></strong></div><?php endforeach; ?>
            <a class="btn btn-outline-primary btn-sm mt-3" href="#account-management">Manage access</a>
        </section>
        <section class="card p-3 mt-3"><span class="section-kicker">City activity</span><h2 class="h5 mt-1">Reports by status</h2><div class="dashboard-chart dashboard-chart-compact"><canvas id="adminStatusChart" aria-label="All reports by status"></canvas></div></section>
    </aside>
</div>
<section class="card p-3 p-md-4 mt-4" id="account-management">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3"><div><span class="section-kicker">Account administration</span><h2 class="h4 mt-1 mb-0">Manage user access</h2></div><p class="small text-secondary mb-0">Showing the 25 most recently created accounts.</p></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>User</th><th>Role</th><th>Department</th><th>Access</th><th></th></tr></thead><tbody>
        <?php foreach ($accounts as $account): ?>
            <?php $accountRole = $account['role'] === 'department' ? 'officer' : $account['role']; ?>
            <tr><td><strong><?= e($account['name']) ?></strong><div class="small text-secondary"><?= e($account['email']) ?></div></td><td><?= e(ucfirst($accountRole)) ?></td><td><?= e($account['department_name'] ?? '—') ?></td><td><span class="badge rounded-pill <?= $account['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $account['is_active'] ? 'Active' : 'Inactive' ?></span></td><td>
                <?php if ((int)$account['id'] !== $admin['id']): ?><form method="post"><?= csrf() ?><input type="hidden" name="action" value="account_status"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="active" value="<?= $account['is_active'] ? '0' : '1' ?>"><button class="btn btn-sm <?= $account['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $account['is_active'] ? 'Deactivate' : 'Activate' ?></button></form><?php else: ?><span class="small text-secondary">Current account</span><?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card p-3 p-md-4 mt-4" id="department-management">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3"><div><span class="section-kicker">Service configuration</span><h2 class="h4 mt-1 mb-0">Manage departments</h2></div><p class="small text-secondary mb-0">Active departments receive new reports matching their service category.</p></div>
    <form method="post" class="row g-2 align-items-end mb-4">
        <?= csrf() ?><input type="hidden" name="action" value="department_create">
        <div class="col-md-4"><label class="form-label" for="department_name">Department name</label><input class="form-control" id="department_name" name="department_name" maxlength="100" required></div>
        <div class="col-md-6"><label class="form-label" for="department_description">Description <span class="text-secondary">(optional)</span></label><input class="form-control" id="department_description" name="department_description" maxlength="500"></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i>Add department</button></div>
    </form>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Department</th><th>Active officers</th><th>Reports handled</th><th>New report routing</th><th></th></tr></thead><tbody>
        <?php foreach ($departments as $department): ?>
            <tr><td><strong><?= e($department['name']) ?></strong><?php if (!empty($department['description'])): ?><div class="small text-secondary"><?= e($department['description']) ?></div><?php endif; ?></td><td><?= (int)$department['officers'] ?></td><td><?= (int)$department['complaints'] ?></td><td><span class="badge rounded-pill <?= $department['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $department['is_active'] ? 'Enabled' : 'Paused' ?></span></td><td><form method="post"><?= csrf() ?><input type="hidden" name="action" value="department_status"><input type="hidden" name="department_id" value="<?= (int)$department['id'] ?>"><input type="hidden" name="active" value="<?= $department['is_active'] ? '0' : '1' ?>"><button class="btn btn-sm <?= $department['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $department['is_active'] ? 'Pause routing' : 'Enable routing' ?></button></form></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card p-3 p-md-4 mt-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3"><div><span class="section-kicker">Location intelligence</span><h2 class="h4 mt-1 mb-0">Complaint heatmap</h2></div><p class="small text-secondary mb-0">Recent reports with a location pin, up to 500.</p></div>
    <?php if (!$mappedComplaints): ?><div class="map-empty"><i class="fa-solid fa-map-location-dot"></i><div><strong>No location pins yet</strong><span>Reports with a selected map location will appear here.</span></div></div>
    <?php else: ?><div id="adminComplaintMap" class="admin-complaint-map" role="application" aria-label="Map of recent complaints"></div><?php endif; ?>
</section>
<?php if ($statusAnalytics): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('adminStatusChart');
    if (canvas && window.Chart) {
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(static function ($status) { return STATUS_LABELS[$status] ?? ucfirst($status); }, array_column($statusAnalytics, 'status')), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                datasets: [{ data: <?= json_encode(array_map('intval', array_column($statusAnalytics, 'total'))) ?>, backgroundColor: ['#3478e5', '#55b6cf', '#74839a', '#f0b547', '#36a477', '#db6272', '#435a79'] }]
            },
            options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }
});
</script>
<?php endif; ?>
<?php if ($mappedComplaints): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var mapElement = document.getElementById('adminComplaintMap');
    if (!mapElement || !window.L) return;
    var reports = <?= json_encode(array_map(static function ($complaint) {
        return [
            'trackingId' => $complaint['tracking_id'],
            'title' => $complaint['title'],
            'location' => $complaint['location'],
            'status' => STATUS_LABELS[$complaint['status']] ?? ucfirst($complaint['status']),
            'latitude' => (float)$complaint['latitude'],
            'longitude' => (float)$complaint['longitude'],
        ];
    }, $mappedComplaints), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var map = L.map(mapElement).setView([18.5204, 73.8567], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    var markers = [];
    reports.forEach(function (report) {
        var marker = L.marker([report.latitude, report.longitude]).addTo(map);
        var popup = document.createElement('div');
        var heading = document.createElement('strong');
        heading.textContent = report.trackingId + ' · ' + report.title;
        var details = document.createElement('p');
        details.className = 'mb-1';
        details.textContent = report.location;
        var status = document.createElement('small');
        status.textContent = report.status;
        popup.append(heading, details, status);
        marker.bindPopup(popup);
        markers.push(marker);
    });
    if (markers.length > 1) {
        map.fitBounds(L.featureGroup(markers).getBounds().pad(0.15));
    }
});
</script>
<?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
