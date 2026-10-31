<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = need('admin');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare('SELECT c.*, u.name AS citizen_name, u.email AS citizen_email, d.name AS department_name FROM complaints c JOIN users u ON u.id = c.citizen_id JOIN departments d ON d.id = c.department_id WHERE c.id = ?');
$stmt->execute([$id]);
$complaint = $stmt->fetch();
if (!$complaint) {
    http_response_code(404);
    exit('Report not found.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');
    if (!in_array($status, STATUSES, true)) {
        $error = 'Choose a valid status.';
    } elseif (strlen($note) > 5000) {
        $error = 'The update note is too long.';
    } else {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status, citizen_id, tracking_id FROM complaints WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current) {
                throw new RuntimeException('This report no longer exists.');
            }
            if ($current['status'] !== $status) {
                $pdo->prepare('UPDATE complaints SET status = ? WHERE id = ?')->execute([$status, $id]);
                $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([
                    $id, $status, $note !== '' ? $note : "Status updated to $status by an administrator.", $admin['id'],
                ]);
                notify((int)$current['citizen_id'], "Complaint {$current['tracking_id']} is now: $status");
            } elseif ($note !== '') {
                $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([$id, $status, $note, $admin['id']]);
                notify((int)$current['citizen_id'], "There is a new update on complaint {$current['tracking_id']}.");
            }
            $pdo->commit();
            flash('Report update saved.');
            go('admin/complaint_details.php?id=' . $id);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                $error = $exception->getMessage();
            } else {
                error_log('CivicPulse administrator complaint update failed: ' . $exception->getMessage());
                $error = 'The update could not be saved. Please try again.';
            }
        }
    }
}

$historyQuery = $pdo->prepare('SELECT h.status, h.note, h.created_at, u.name AS changed_by FROM complaint_status_history h JOIN users u ON u.id = h.changed_by WHERE h.complaint_id = ? ORDER BY h.id DESC');
$historyQuery->execute([$id]);
$history = $historyQuery->fetchAll();
$attachmentQuery = $pdo->prepare('SELECT file_path, kind FROM complaint_attachments WHERE complaint_id = ? ORDER BY id');
$attachmentQuery->execute([$id]);
$attachments = $attachmentQuery->fetchAll();
$title = 'Manage ' . $complaint['tracking_id'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><a href="<?= e(url('admin/dashboard.php')) ?>" class="text-decoration-none"><i class="fa-solid fa-arrow-left me-2"></i>Operations overview</a><?= badge($complaint['status']) ?></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="card p-3 p-md-4">
            <div class="section-kicker"><?= e($complaint['category']) ?> · <?= e(ucfirst($complaint['priority'])) ?> priority</div><h1 class="h3 mt-2"><?= e($complaint['title']) ?></h1>
            <p class="text-secondary">Tracking ID <strong><?= e($complaint['tracking_id']) ?></strong></p><p><?= nl2br(e($complaint['description'])) ?></p>
            <div class="rounded-3 bg-body-secondary p-3 mb-3"><i class="fa-solid fa-location-dot text-primary me-2"></i><?= e($complaint['location']) ?><?php if ($complaint['landmark']): ?> · <?= e($complaint['landmark']) ?><?php endif; ?><div class="small text-secondary mt-1"><?= e($complaint['department_name']) ?> · Reported by <?= e($complaint['citizen_name']) ?> (<?= e($complaint['citizen_email']) ?>)</div></div>
            <?php foreach ($attachments as $attachment): ?><figure><img src="<?= e(url($attachment['file_path'])) ?>" class="img-fluid rounded-3" style="max-height:300px" alt="Complaint attachment"><figcaption class="small text-secondary"><?= e(ucfirst($attachment['kind'])) ?> attachment</figcaption></figure><?php endforeach; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card p-3 p-md-4 mb-3"><span class="section-kicker">Administrator tools</span><h2 class="h5 mt-1">Update report</h2>
            <form method="post"><?= csrf() ?><label class="form-label" for="status">Status</label><select class="form-select mb-3" id="status" name="status"><?php foreach (STATUSES as $option): ?><option value="<?= e($option) ?>" <?= $complaint['status'] === $option ? 'selected' : '' ?>><?= e(STATUS_LABELS[$option]) ?></option><?php endforeach; ?></select><label class="form-label" for="note">Public note</label><textarea class="form-control mb-3" rows="3" maxlength="5000" id="note" name="note" placeholder="Optional update visible to the citizen"></textarea><button class="btn btn-primary w-100">Save update</button></form>
        </section>
        <section class="card p-3 p-md-4"><h2 class="h5">Report timeline</h2>
            <?php foreach ($history as $event): ?><div class="timeline-item"><div class="timeline-dot"></div><div class="d-flex flex-wrap justify-content-between gap-2"><?= badge($event['status']) ?><small class="text-secondary"><?= e(date('M j, Y · g:i a', strtotime($event['created_at']))) ?></small></div><p class="small mt-2 mb-1"><?= e($event['note']) ?></p><small class="text-secondary">By <?= e($event['changed_by']) ?></small></div><?php endforeach; ?>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
