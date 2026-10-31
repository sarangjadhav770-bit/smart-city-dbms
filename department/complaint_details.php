<?php
require_once __DIR__ . '/../includes/functions.php';
$officer = need('department');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare('SELECT c.*, u.name AS citizen_name, u.email AS citizen_email, d.name AS department_name FROM complaints c JOIN users u ON u.id = c.citizen_id JOIN departments d ON d.id = c.department_id WHERE c.id = ? AND c.department_id = ?');
$stmt->execute([$id, $officer['department_id']]);
$complaint = $stmt->fetch();
if (!$complaint) {
    http_response_code(404);
    exit('Report not found in your department.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'status') {
            $status = $_POST['status'] ?? '';
            $note = trim($_POST['note'] ?? '');
            if (!in_array($status, STATUSES, true) || strlen($note) > 5000) {
                throw new RuntimeException('Choose a valid status and keep the note under 5,000 characters.');
            }
            $imagePath = save_image($_FILES['image'] ?? null);
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT status, citizen_id, tracking_id FROM complaints WHERE id = ? AND department_id = ? FOR UPDATE');
            $lock->execute([$id, $officer['department_id']]);
            $current = $lock->fetch();
            if (!$current) {
                throw new RuntimeException('This report is no longer available to your department.');
            }
            if ($current['status'] !== $status) {
                $pdo->prepare('UPDATE complaints SET status = ? WHERE id = ?')->execute([$status, $id]);
            }
            if ($current['status'] !== $status || $note !== '') {
                $historyNote = $note !== '' ? $note : "Status updated to $status.";
                $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([$id, $status, $historyNote, $officer['id']]);
            }
            if ($imagePath !== null) {
                $pdo->prepare("INSERT INTO complaint_attachments(complaint_id, file_path, kind) VALUES(?, ?, 'resolution')")->execute([$id, $imagePath]);
            }
            if ($current['status'] !== $status || $note !== '' || $imagePath !== null) {
                notify((int)$current['citizen_id'], "There is a new update on complaint {$current['tracking_id']}.");
            }
            $pdo->commit();
            flash('Report update saved.');
        } elseif ($action === 'remark') {
            $remark = trim($_POST['remark'] ?? '');
            if ($remark === '' || strlen($remark) > 5000) {
                throw new RuntimeException('Enter an internal note of up to 5,000 characters.');
            }
            $pdo->prepare('INSERT INTO internal_remarks(complaint_id, user_id, remark) VALUES(?, ?, ?)')->execute([$id, $officer['id'], $remark]);
            flash('Internal note added.');
        } elseif ($action === 'assign') {
            $staffId = filter_input(INPUT_POST, 'staff', FILTER_VALIDATE_INT) ?: 0;
            $staffQuery = $pdo->prepare('SELECT id FROM department_staff WHERE id = ? AND department_id = ?');
            $staffQuery->execute([$staffId, $officer['department_id']]);
            if (!$staffQuery->fetchColumn()) {
                throw new RuntimeException('Select a valid staff member from your department.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO complaint_assignments(complaint_id, staff_id, assigned_by) VALUES(?, ?, ?)')->execute([$id, $staffId, $officer['id']]);
            if (in_array($complaint['status'], ['submitted', 'under_review'], true)) {
                $pdo->prepare("UPDATE complaints SET status = 'assigned' WHERE id = ?")->execute([$id]);
                $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([$id, 'assigned', 'Assigned to field staff', $officer['id']]);
                notify((int)$complaint['citizen_id'], "Complaint {$complaint['tracking_id']} has been assigned to a field team.");
            }
            $pdo->commit();
            flash('Staff member assigned.');
        } else {
            throw new RuntimeException('Choose a valid action.');
        }
        go('department/complaint_details.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($imagePath) && $imagePath !== null) {
            $storedImage = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
            if (is_file($storedImage)) {
                unlink($storedImage);
            }
        }
        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('CivicPulse department complaint action failed: ' . $exception->getMessage());
            $error = 'The action could not be completed. Please try again.';
        }
    }
}

$historyQuery = $pdo->prepare('SELECT h.status, h.note, h.created_at, u.name AS changed_by FROM complaint_status_history h JOIN users u ON u.id = h.changed_by WHERE h.complaint_id = ? ORDER BY h.id DESC');
$historyQuery->execute([$id]);
$history = $historyQuery->fetchAll();
$remarksQuery = $pdo->prepare('SELECT r.remark, r.created_at, u.name FROM internal_remarks r JOIN users u ON u.id = r.user_id WHERE r.complaint_id = ? ORDER BY r.id DESC');
$remarksQuery->execute([$id]);
$remarks = $remarksQuery->fetchAll();
$attachmentsQuery = $pdo->prepare('SELECT file_path, kind FROM complaint_attachments WHERE complaint_id = ? ORDER BY id');
$attachmentsQuery->execute([$id]);
$attachments = $attachmentsQuery->fetchAll();
$assignmentsQuery = $pdo->prepare('SELECT s.name, a.created_at FROM complaint_assignments a JOIN department_staff s ON s.id = a.staff_id WHERE a.complaint_id = ? ORDER BY a.id DESC');
$assignmentsQuery->execute([$id]);
$assignments = $assignmentsQuery->fetchAll();
$staffQuery = $pdo->prepare('SELECT id, name FROM department_staff WHERE department_id = ? ORDER BY name');
$staffQuery->execute([$officer['department_id']]);
$staff = $staffQuery->fetchAll();

$title = 'Manage ' . $complaint['tracking_id'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><a href="<?= e(url('department/dashboard.php')) ?>" class="text-decoration-none"><i class="fa-solid fa-arrow-left me-2"></i>Department queue</a><?= badge($complaint['status']) ?></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="card p-3 p-md-4 mb-3">
            <div class="section-kicker"><?= e($complaint['tracking_id']) ?> · <?= e(ucfirst($complaint['priority'])) ?> priority</div><h1 class="h3 mt-2"><?= e($complaint['title']) ?></h1>
            <p class="text-secondary">Submitted by <?= e($complaint['citizen_name']) ?> · <?= e($complaint['citizen_email']) ?></p><p><?= nl2br(e($complaint['description'])) ?></p>
            <div class="rounded-3 bg-body-secondary p-3 mb-3"><i class="fa-solid fa-location-dot text-primary me-2"></i><?= e($complaint['location']) ?><?php if ($complaint['landmark']): ?> · <?= e($complaint['landmark']) ?><?php endif; ?></div>
            <?php foreach ($attachments as $attachment): ?><figure><img src="<?= e(url($attachment['file_path'])) ?>" class="img-fluid rounded-3" style="max-height:300px" alt="Complaint attachment"><figcaption class="small text-secondary"><?= e(ucfirst($attachment['kind'])) ?> attachment</figcaption></figure><?php endforeach; ?>
            <?php foreach ($assignments as $assignment): ?><div class="small border-top py-2"><i class="fa-solid fa-user-check text-primary me-2"></i><?= e($assignment['name']) ?> · <?= e(date('M j, Y', strtotime($assignment['created_at']))) ?></div><?php endforeach; ?>
        </section>
        <section class="card p-3 p-md-4"><span class="section-kicker">Private to your department</span><h2 class="h5 mt-1">Internal notes</h2>
            <form method="post" class="d-flex gap-2 mb-3"><?= csrf() ?><input type="hidden" name="action" value="remark"><input class="form-control" name="remark" maxlength="5000" placeholder="Add a note for your team" required><button class="btn btn-outline-primary">Add</button></form>
            <?php if (!$remarks): ?><p class="small text-secondary mb-0">No internal notes yet.</p><?php endif; ?>
            <?php foreach ($remarks as $remark): ?><div class="border-top py-2 small"><strong><?= e($remark['name']) ?></strong> · <?= e(date('M j, Y g:i a', strtotime($remark['created_at']))) ?><div><?= nl2br(e($remark['remark'])) ?></div></div><?php endforeach; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card p-3 p-md-4 mb-3"><span class="section-kicker">Citizen-facing</span><h2 class="h5 mt-1">Post an update</h2>
            <form method="post" enctype="multipart/form-data"><?= csrf() ?><input type="hidden" name="action" value="status"><label class="form-label" for="status">Status</label><select class="form-select mb-3" id="status" name="status"><?php foreach (STATUSES as $option): ?><option value="<?= e($option) ?>" <?= $complaint['status'] === $option ? 'selected' : '' ?>><?= e(STATUS_LABELS[$option]) ?></option><?php endforeach; ?></select><label class="form-label" for="note">Public note</label><textarea class="form-control mb-3" name="note" id="note" rows="3" maxlength="5000" placeholder="Tell the citizen what has changed"></textarea><label class="form-label" for="image">Optional photo evidence</label><input type="file" class="form-control mb-3" id="image" name="image" accept="image/jpeg,image/png,image/webp"><button class="btn btn-primary w-100">Save update</button></form>
        </section>
        <section class="card p-3 p-md-4 mb-3"><h2 class="h5">Assign field staff</h2>
            <?php if ($staff): ?><form method="post" class="d-flex gap-2"><?= csrf() ?><input type="hidden" name="action" value="assign"><select class="form-select" name="staff" aria-label="Select field staff"><?php foreach ($staff as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e($member['name']) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary">Assign</button></form>
            <?php else: ?><p class="small text-secondary mb-0">No field staff are registered for this department.</p><?php endif; ?>
        </section>
        <section class="card p-3 p-md-4"><h2 class="h5">Status timeline</h2>
            <?php foreach ($history as $event): ?><div class="timeline-item"><div class="timeline-dot"></div><div class="d-flex flex-wrap justify-content-between gap-2"><?= badge($event['status']) ?><small class="text-secondary"><?= e(date('M j, Y · g:i a', strtotime($event['created_at']))) ?></small></div><p class="small mt-2 mb-1"><?= e($event['note']) ?></p><small class="text-secondary"><?= e($event['changed_by']) ?></small></div><?php endforeach; ?>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
