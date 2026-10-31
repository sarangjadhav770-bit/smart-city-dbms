<?php
require_once __DIR__ . '/includes/functions.php';

function civicpulse_upgrade_database(): array
{
    global $pdo;
    $requiredTables = ['users', 'departments', 'complaints'];
    foreach ($requiredTables as $table) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $check->execute([$table]);
        if (!(int)$check->fetchColumn()) {
            throw new RuntimeException("Required table '$table' is missing. Import database.sql first.");
        }
    }

    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($hasColumn, $pdo): void {
        if (!$hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    };
    $columnType = static function (string $table, string $column) use ($pdo): string {
        $stmt = $pdo->prepare('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        $type = $stmt->fetchColumn();
        if (!$type) {
            throw new RuntimeException("Required key '$table.$column' is missing.");
        }
        return $type;
    };

    $addColumn('users', 'password', 'VARCHAR(255) NULL');
    if ($hasColumn('users', 'password_hash')) {
        $pdo->exec("UPDATE users SET password = password_hash WHERE (password IS NULL OR password = '') AND password_hash IS NOT NULL AND password_hash <> ''");
    }
    $addColumn('users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn('departments', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('citizen','officer','department','admin') NOT NULL DEFAULT 'citizen'");

    $addColumn('complaints', 'tracking_id', 'VARCHAR(24) NULL');
    $addColumn('complaints', 'category', 'VARCHAR(40) NULL');
    $addColumn('complaints', 'landmark', 'VARCHAR(150) NULL');
    $addColumn('complaints', 'priority', "ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium'");
    $addColumn('complaints', 'latitude', 'DECIMAL(10,7) NULL');
    $addColumn('complaints', 'longitude', 'DECIMAL(10,7) NULL');
    $addColumn('complaints', 'is_sample', 'TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->exec("ALTER TABLE complaints MODIFY status VARCHAR(30) NOT NULL DEFAULT 'submitted'");
    $pdo->exec("UPDATE complaints SET status = CASE status WHEN 'Submitted' THEN 'submitted' WHEN 'Under Review' THEN 'under_review' WHEN 'Assigned' THEN 'assigned' WHEN 'In Progress' THEN 'in_progress' WHEN 'Resolved' THEN 'resolved' WHEN 'Rejected' THEN 'rejected' WHEN 'Reopened' THEN 'reopened' ELSE status END");
    $pdo->exec("UPDATE complaints c LEFT JOIN departments d ON d.id = c.department_id SET c.category = CASE WHEN LOWER(d.name) LIKE '%road%' OR LOWER(d.name) LIKE '%transport%' THEN 'Roads' WHEN LOWER(d.name) LIKE '%sanitation%' OR LOWER(d.name) LIKE '%waste%' THEN 'Garbage' WHEN LOWER(d.name) LIKE '%water%' THEN 'Water' WHEN LOWER(d.name) LIKE '%electric%' OR LOWER(d.name) LIKE '%utility%' THEN 'Electricity' WHEN LOWER(d.name) LIKE '%health%' THEN 'Public Health' WHEN LOWER(d.name) LIKE '%police%' OR LOWER(d.name) LIKE '%safety%' THEN 'Police' ELSE 'Drainage' END WHERE c.category IS NULL OR c.category = ''");
    $pdo->exec("UPDATE complaints SET tracking_id = CONCAT('CPLEGACY', LPAD(id, 16, '0')) WHERE tracking_id IS NULL OR tracking_id = ''");
    $index = $pdo->query("SHOW INDEX FROM complaints WHERE Column_name = 'tracking_id' AND Non_unique = 0")->fetch();
    if (!$index) {
        $pdo->exec('ALTER TABLE complaints ADD UNIQUE INDEX uq_complaints_tracking_id (tracking_id)');
    }

    $addColumn('notifications', 'message', 'VARCHAR(255) NULL');
    $addColumn('notifications', 'is_read', 'TINYINT(1) NOT NULL DEFAULT 0');
    if ($hasColumn('notifications', 'title') && $hasColumn('notifications', 'body')) {
        $pdo->exec("UPDATE notifications SET message = LEFT(CONCAT_WS(': ', NULLIF(title, ''), NULLIF(body, '')), 255) WHERE message IS NULL OR message = ''");
    }
    if ($hasColumn('notifications', 'read_at')) {
        $pdo->exec('UPDATE notifications SET is_read = IF(read_at IS NULL, 0, 1)');
    }
    $pdo->exec("UPDATE notifications SET message = '' WHERE message IS NULL");
    $pdo->exec("ALTER TABLE notifications MODIFY message VARCHAR(255) NOT NULL DEFAULT ''");

    $departmentIdType = $columnType('departments', 'id');
    $userIdType = $columnType('users', 'id');
    $complaintIdType = $columnType('complaints', 'id');
    $pdo->exec("CREATE TABLE IF NOT EXISTS department_staff (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, department_id $departmentIdType NOT NULL, name VARCHAR(100) NOT NULL, phone VARCHAR(24) NULL, FOREIGN KEY (department_id) REFERENCES departments(id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaint_assignments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, complaint_id $complaintIdType NOT NULL, staff_id BIGINT UNSIGNED NOT NULL, assigned_by $userIdType NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE, FOREIGN KEY (staff_id) REFERENCES department_staff(id), FOREIGN KEY (assigned_by) REFERENCES users(id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaint_status_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, complaint_id $complaintIdType NOT NULL, status VARCHAR(30) NOT NULL, note TEXT NULL, changed_by $userIdType NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (complaint_id), FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE, FOREIGN KEY (changed_by) REFERENCES users(id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaint_attachments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, complaint_id $complaintIdType NOT NULL, file_path VARCHAR(255) NOT NULL, kind ENUM('evidence','resolution') NOT NULL DEFAULT 'evidence', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS internal_remarks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, complaint_id $complaintIdType NOT NULL, user_id $userIdType NOT NULL, remark TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB");

    if ($hasColumn('complaints', 'assigned_to')) {
        $pdo->exec("INSERT INTO department_staff(department_id, name, phone) SELECT DISTINCT u.department_id, u.name, u.phone FROM complaints c JOIN users u ON u.id = c.assigned_to WHERE c.assigned_to IS NOT NULL AND u.department_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM department_staff s WHERE s.department_id = u.department_id AND s.name = u.name)");
        $adminId = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId) {
            $pdo->exec("INSERT INTO complaint_assignments(complaint_id, staff_id, assigned_by) SELECT c.id, s.id, " . (int)$adminId . " FROM complaints c JOIN users u ON u.id = c.assigned_to JOIN department_staff s ON s.department_id = u.department_id AND s.name = u.name WHERE c.assigned_to IS NOT NULL AND NOT EXISTS (SELECT 1 FROM complaint_assignments a WHERE a.complaint_id = c.id AND a.staff_id = s.id)");
        }
    }

    if ($pdo->query("SHOW TABLES LIKE 'complaint_updates'")->fetchColumn()) {
        $updates = $pdo->query('SELECT complaint_id, user_id, old_status, new_status, note, created_at FROM complaint_updates')->fetchAll();
        $insertHistory = $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by, created_at) SELECT ?, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM complaint_status_history WHERE complaint_id = ? AND status = ? AND changed_by = ? AND created_at = ?)');
        foreach ($updates as $update) {
            $status = strtolower(str_replace(' ', '_', $update['new_status']));
            if (!in_array($status, STATUSES, true)) {
                continue;
            }
            $insertHistory->execute([
                $update['complaint_id'], $status, $update['note'], $update['user_id'], $update['created_at'],
                $update['complaint_id'], $status, $update['user_id'], $update['created_at'],
            ]);
        }
    }
    $pdo->exec("INSERT INTO complaint_status_history(complaint_id, status, note, changed_by, created_at) SELECT c.id, c.status, 'Existing report history', c.citizen_id, c.created_at FROM complaints c WHERE NOT EXISTS (SELECT 1 FROM complaint_status_history h WHERE h.complaint_id = c.id)");

    return [
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'complaints' => (int)$pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn(),
        'departments' => (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn(),
    ];
}

$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$result = null;
$error = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    if (!$isLocal) {
        http_response_code(403);
        exit('Database upgrades can only be run from this computer.');
    }
    check();
    try {
        $result = civicpulse_upgrade_database();
    } catch (Throwable $exception) {
        error_log('CivicPulse database upgrade failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$title = 'Database upgrade';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-8"><section class="card p-4 p-md-5">
    <span class="section-kicker">Existing data is preserved</span><h1 class="h3 mt-2">CivicPulse database upgrade</h1>
    <?php if ($result): ?>
        <div class="alert alert-success">Upgrade complete. Preserved <?= (int)$result['users'] ?> accounts, <?= (int)$result['complaints'] ?> complaints, and <?= (int)$result['departments'] ?> departments. Added the fields and workflow tables this version needs.</div>
        <p class="text-secondary">You can now sign in and use the app. Keep a database backup before running updates in the future.</p>
        <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Continue to sign in</a>
    <?php else: ?>
        <p class="text-secondary">Your current CivicPulse database uses an older expanded schema. This local-only, additive upgrade adds the complaint fields and workflow tables required by this project, copies existing password hashes, keeps user roles and records, and brings old complaint statuses into the current format.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$isLocal): ?><div class="alert alert-warning">Open this page from localhost on the computer running XAMPP.</div><?php else: ?>
        <form method="post"><?= csrf() ?><button type="submit" class="btn btn-primary btn-lg">Upgrade database</button></form>
        <?php endif; ?>
        <p class="small text-secondary mt-3 mb-0">For safety, back up <code>smart_city_db</code> in phpMyAdmin before continuing. This upgrade does not delete existing accounts, complaints, or other CivicPulse module tables.</p>
    <?php endif; ?>
</section></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
