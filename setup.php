<?php
require_once __DIR__ . '/includes/functions.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Run the demo setup from this computer only.');
}

$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$error = '';
$complete = false;
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST' && $userCount === 0) {
    check();
    $departments = $pdo->query('SELECT id, name FROM departments ORDER BY id')->fetchAll();
    if (count($departments) < 7) {
        $error = 'Import database.sql first so all seven departments exist.';
    } else {
        $pdo->beginTransaction();
        try {
            $passwordHash = static function (string $password): string {
                return password_hash($password, PASSWORD_DEFAULT);
            };
            $adminId = create_user('Super Admin', 'admin@civicpulse.test', '9000000000', $passwordHash('Admin@123'), 'admin');
            $citizenId = create_user('Demo Citizen', 'demo@civicpulse.test', '9111111111', $passwordHash('Citizen@123'), 'citizen');

            $staffInsert = $pdo->prepare('INSERT INTO department_staff(department_id, name, phone) VALUES(?, ?, ?)');
            foreach ($departments as $index => $department) {
                $name = strtolower($department['name']);
                $key = 'service' . ($index + 1);
                foreach ([
                    'municipal' => ['municipal', 'city service'],
                    'road' => ['road', 'transport'],
                    'water' => ['water'],
                    'sanitation' => ['sanitation', 'waste'],
                    'electricity' => ['electric', 'utility'],
                    'health' => ['health'],
                    'police' => ['police', 'safety'],
                ] as $candidate => $terms) {
                    foreach ($terms as $term) {
                        if (strpos($name, $term) !== false) {
                            $key = $candidate;
                            break 2;
                        }
                    }
                }
                $displayName = ucfirst($key);
                create_user($displayName . ' Officer', $key . '@civicpulse.test', '9222222222', $passwordHash('Dept@123'), 'officer', (int)$department['id']);
                $staffInsert->execute([$department['id'], $displayName . ' Field Staff 1', '9333333333']);
                $staffInsert->execute([$department['id'], $displayName . ' Field Staff 2', '9444444444']);
            }

            $samples = [
                ['Roads', 'Pothole on main road', 18.5204, 73.8567, 'resolved', 40],
                ['Garbage', 'Garbage not collected', 18.5314, 73.8446, 'in_progress', 25],
                ['Water', 'No water supply', 18.5074, 73.8077, 'assigned', 18],
                ['Electricity', 'Streetlight not working', 18.5590, 73.7868, 'submitted', 9],
                ['Drainage', 'Blocked drain', 18.4900, 73.8200, 'under_review', 6],
                ['Traffic', 'Signal malfunction', 18.5167, 73.8563, 'resolved', 60],
                ['Public Health', 'Mosquito breeding', 18.5362, 73.8940, 'submitted', 3],
                ['Roads', 'Broken footpath', 18.5018, 73.8636, 'rejected', 30],
            ];
            $insertComplaint = $pdo->prepare('INSERT INTO complaints(tracking_id, citizen_id, department_id, category, title, description, location, priority, latitude, longitude, status, is_sample, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL ? DAY))');
            $insertHistory = $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by, created_at) VALUES(?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))');
            foreach ($samples as $index => [$category, $subject, $latitude, $longitude, $status, $daysAgo]) {
                $departmentId = category_department_id($category);
                $insertComplaint->execute([
                    'CPDEMO' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT),
                    $citizenId,
                    $departmentId,
                    $category,
                    '[Sample] ' . $subject,
                    'Sample demo complaint for presentation purposes.',
                    'Sample location, Pune',
                    'medium',
                    $latitude,
                    $longitude,
                    $status,
                    $daysAgo,
                ]);
                $complaintId = (int)$pdo->lastInsertId();
                $insertHistory->execute([$complaintId, 'submitted', 'Complaint submitted', $citizenId, $daysAgo]);
                if ($status !== 'submitted') {
                    $insertHistory->execute([$complaintId, $status, "Status updated to $status", $adminId, max(0, $daysAgo - 2)]);
                }
            }
            $pdo->commit();
            $complete = true;
            $userCount = 1;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('CivicPulse demo setup failed: ' . $exception->getMessage());
            $error = 'Setup could not be completed. Check database.sql and the PHP error log, then try again.';
        }
    }
}
$title = 'Demo setup';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-7"><section class="card p-4 p-md-5">
    <span class="section-kicker">Local development</span><h1 class="h3 mt-2">CivicPulse demo setup</h1>
    <?php if ($complete): ?>
        <div class="alert alert-success">Setup completed successfully. Demo accounts and sample reports are ready.</div>
        <p>For security, delete <code>setup.php</code> after this page.</p>
        <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Go to sign in</a>
        <hr><p class="mb-1"><strong>Citizen:</strong> demo@civicpulse.test · Citizen@123</p><p class="mb-1"><strong>Admin:</strong> admin@civicpulse.test · Admin@123</p><p class="mb-0"><strong>Department officers:</strong> municipal / road / water / sanitation / electricity / health / police @civicpulse.test · Dept@123</p>
    <?php elseif ($userCount > 0): ?>
        <div class="alert alert-info">Setup has already been run because user accounts exist. This script will not add duplicate demo records.</div>
        <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Go to sign in</a>
        <p class="small text-secondary mt-3 mb-0">Delete <code>setup.php</code> from your project after setup.</p>
    <?php else: ?>
        <p class="text-secondary">This creates local demo accounts, department staff, and sample complaints. Import <code>database.sql</code> first. It can only be run from this computer and only while there are no user accounts.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post"><?= csrf() ?><button type="submit" class="btn btn-primary btn-lg">Create demo data</button></form>
    <?php endif; ?>
</section></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
