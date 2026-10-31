<?php
require_once __DIR__ . '/../includes/functions.php';
$user = need('citizen');
$status = trim($_GET['status'] ?? '');
$trackingId = strtoupper(trim($_GET['tracking_id'] ?? ''));
if (strlen($trackingId) > 24) {
    $trackingId = substr($trackingId, 0, 24);
}
$allowedStatuses = array_merge([''], STATUSES);
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = 'SELECT id, tracking_id, title, category, priority, status, created_at FROM complaints WHERE citizen_id = ?';
$parameters = [$user['id']];
if ($status !== '') {
    $sql .= ' AND status = ?';
    $parameters[] = $status;
}
if ($trackingId !== '') {
    $sql .= ' AND tracking_id = ?';
    $parameters[] = $trackingId;
}
$sql .= ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($parameters);
$complaints = $stmt->fetchAll();

$title = 'My reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="workspace-layout">
<?php require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
<div class="workspace-main">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
    <div><span class="section-kicker">Citizen portal</span><h1 class="h2 mt-1 mb-1">My reports</h1><p class="text-secondary mb-0">Review every issue you’ve submitted and its current status.</p></div>
    <a class="btn btn-primary" href="<?= e(url('citizen/submit_complaint.php')) ?>"><i class="fa-solid fa-plus me-2"></i>New report</a>
</div>
<section class="card p-3 p-md-4">
    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-sm-8 col-md-5"><label class="form-label" for="tracking_id">Track by complaint ID</label><input class="form-control" id="tracking_id" name="tracking_id" value="<?= e($trackingId) ?>" placeholder="e.g. CP-2026-AB12CD" maxlength="24" autocomplete="off"></div>
        <div class="col-sm-6 col-md-4"><label class="form-label" for="status">Filter by status</label><select class="form-select" name="status" id="status"><option value="">All reports</option><?php foreach (STATUSES as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(STATUS_LABELS[$option]) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><button class="btn btn-outline-primary"><i class="fa-solid fa-magnifying-glass me-1"></i>Find report</button></div>
        <?php if ($status !== '' || $trackingId !== ''): ?><div class="col-auto"><a class="btn btn-link" href="<?= e(url('citizen/complaints.php')) ?>">Clear</a></div><?php endif; ?>
    </form>
    <?php if (!$complaints): ?><div class="text-center py-5"><i class="fa-solid fa-clipboard-check fa-2x text-primary mb-3"></i><h2 class="h5">No reports found</h2><p class="text-secondary mb-0">Try another status filter or submit a new report.</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Tracking ID</th><th>Issue</th><th>Priority</th><th>Status</th><th>Reported</th><th></th></tr></thead><tbody>
        <?php foreach ($complaints as $complaint): ?><tr>
            <td class="fw-bold"><?= e($complaint['tracking_id']) ?></td><td><?= e($complaint['title']) ?><div class="small text-secondary"><?= e($complaint['category']) ?></div></td><td><?= e(ucfirst($complaint['priority'])) ?></td><td><?= badge($complaint['status']) ?></td><td class="small"><?= e(date('M j, Y', strtotime($complaint['created_at']))) ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('citizen/complaint_details.php?id=' . (int)$complaint['id'])) ?>">Details</a></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
