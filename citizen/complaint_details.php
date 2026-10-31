<?php
require_once __DIR__ . '/../includes/functions.php';
$user = need('citizen');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare('SELECT c.*, d.name AS department_name FROM complaints c JOIN departments d ON d.id = c.department_id WHERE c.id = ? AND c.citizen_id = ?');
$stmt->execute([$id, $user['id']]);
$complaint = $stmt->fetch();
if (!$complaint) {
    http_response_code(404);
    exit('Report not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    if ($complaint['status'] !== 'resolved') {
        http_response_code(409);
        exit('Only resolved reports can be reopened.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE complaints SET status = 'reopened' WHERE id = ? AND citizen_id = ? AND status = 'resolved'")->execute([$id, $user['id']]);
        if ($pdo->rowCount() !== 1) {
            throw new RuntimeException('This report has already changed. Refresh and try again.');
        }
        $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([$id, 'reopened', 'Reopened by citizen', $user['id']]);
        $officers = $pdo->prepare("SELECT id FROM users WHERE role IN ('officer', 'department') AND is_active = 1 AND department_id = ?");
        $officers->execute([$complaint['department_id']]);
        foreach ($officers->fetchAll() as $officer) {
            notify((int)$officer['id'], "Complaint {$complaint['tracking_id']} was reopened.");
        }
        $pdo->commit();
        flash('Your report has been reopened.');
        go('citizen/complaint_details.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('CivicPulse complaint reopen failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('The report could not be reopened. Please try again.');
    }
}

$historyQuery = $pdo->prepare('SELECT status, note, created_at FROM complaint_status_history WHERE complaint_id = ? ORDER BY id');
$historyQuery->execute([$id]);
$history = $historyQuery->fetchAll();
$attachmentQuery = $pdo->prepare('SELECT file_path, kind FROM complaint_attachments WHERE complaint_id = ? ORDER BY id');
$attachmentQuery->execute([$id]);
$attachments = $attachmentQuery->fetchAll();

$title = 'Report ' . $complaint['tracking_id'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><a class="text-decoration-none" href="<?= e(url('citizen/complaints.php')) ?>"><i class="fa-solid fa-arrow-left me-2"></i>All reports</a><?= badge($complaint['status']) ?></div>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="card p-3 p-md-4">
            <div class="section-kicker"><?= e($complaint['category']) ?> · <?= e(ucfirst($complaint['priority'])) ?> priority</div>
            <h1 class="h3 mt-2"><?= e($complaint['title']) ?></h1>
            <p class="text-secondary">Tracking ID <strong><?= e($complaint['tracking_id']) ?></strong> · submitted <?= e(date('M j, Y', strtotime($complaint['created_at']))) ?></p>
            <p><?= nl2br(e($complaint['description'])) ?></p>
            <div class="rounded-3 bg-body-secondary p-3 mb-3"><i class="fa-solid fa-location-dot text-primary me-2"></i><?= e($complaint['location']) ?><?php if ($complaint['landmark']): ?><span class="text-secondary"> · Near <?= e($complaint['landmark']) ?></span><?php endif; ?><div class="small text-secondary mt-1">Handled by <?= e($complaint['department_name']) ?></div></div>
            <?php foreach ($attachments as $attachment): ?><figure class="mb-3"><img src="<?= e(url($attachment['file_path'])) ?>" class="img-fluid rounded-3" style="max-height:300px" alt="<?= $attachment['kind'] === 'resolution' ? 'Resolution evidence' : 'Complaint evidence' ?>"><figcaption class="small text-secondary mt-1"><?= $attachment['kind'] === 'resolution' ? 'Department update' : 'Your photo' ?></figcaption></figure><?php endforeach; ?>
            <?php if ($complaint['status'] === 'resolved'): ?><form method="post" class="border-top pt-3 mt-2"><?= csrf() ?><p class="small text-secondary">Still seeing the problem? Reopen this report so the department can review it again.</p><button class="btn btn-outline-danger" type="submit"><i class="fa-solid fa-rotate-left me-2"></i>Reopen report</button></form><?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card p-3 p-md-4"><span class="section-kicker">Report journey</span><h2 class="h5 mt-1 mb-4">Status updates</h2>
            <?php if (!$history): ?><p class="text-secondary">No updates yet.</p><?php endif; ?>
            <?php foreach ($history as $event): ?><div class="timeline-item"><div class="timeline-dot"></div><div class="d-flex flex-wrap justify-content-between gap-2"><?= badge($event['status']) ?><small class="text-secondary"><?= e(date('M j, Y · g:i a', strtotime($event['created_at']))) ?></small></div><?php if ($event['note']): ?><p class="small mt-2 mb-0"><?= e($event['note']) ?></p><?php endif; ?></div><?php endforeach; ?>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
