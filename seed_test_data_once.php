<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this one-time test-data script from the PHP command line only.\n");
}

require_once __DIR__ . '/includes/functions.php';

$firstNames = [
    'Rahul', 'Sarthak', 'Aarav', 'Arjun', 'Vihaan', 'Aditya', 'Rohan', 'Ananya',
    'Aadhya', 'Isha', 'Diya', 'Meera', 'Kavya', 'Priya', 'Neha', 'Anika',
    'Dev', 'Krishna', 'Simran', 'Aditi',
];
$lastNames = [
    'Sharma', 'Patil', 'Deshmukh', 'Kulkarni', 'Jadhav', 'Shinde', 'Pawar',
    'Joshi', 'More', 'Chavan', 'Kadam', 'Thakur', 'Gupta', 'Verma', 'Singh',
    'Reddy', 'Nair', 'Iyer', 'Mehta', 'Kapoor', 'Malhotra', 'Naik',
    'Salunkhe', 'Gokhale', 'Bhosale',
];
$categories = array_keys(CATS);
$titles = [
    'Roads' => 'Pothole needs repair',
    'Garbage' => 'Missed waste collection',
    'Water' => 'Low water supply',
    'Water Leakage' => 'Water pipe leakage',
    'Electricity' => 'Power supply issue',
    'Streetlight' => 'Streetlight not working',
    'Drainage' => 'Blocked roadside drain',
    'Traffic' => 'Traffic signal issue',
    'Public Health' => 'Public health concern',
    'Police' => 'Public safety concern',
];
$statuses = STATUSES;
$priorities = ['low', 'medium', 'high', 'urgent'];
$landmarks = [
    'near the bus stop', 'beside the public garden', 'near the school entrance',
    'beside the market', 'near the community center', 'by the main crossing',
    'near the health clinic', 'beside the library', 'near the railway station',
    'along the residential lane',
];
$accountPrefix = 'cp.test.citizen.';
$trackingPrefix = 'CPTEST';
$citizenCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE email LIKE 'cp.test.citizen.%@civicpulse.test'"
)->fetchColumn();
$complaintCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM complaints WHERE tracking_id LIKE 'CPTEST%'"
)->fetchColumn();

function printSeedVerification(PDO $pdo): void
{
    $citizens = (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE email LIKE 'cp.test.citizen.%@civicpulse.test' AND role = 'citizen'"
    )->fetchColumn();
    $complaints = (int)$pdo->query(
        "SELECT COUNT(*) FROM complaints WHERE tracking_id LIKE 'CPTEST%' AND is_sample = 1"
    )->fetchColumn();
    $linked = (int)$pdo->query(
        "SELECT COUNT(*) FROM complaints c JOIN users u ON u.id = c.citizen_id WHERE c.tracking_id LIKE 'CPTEST%' AND u.email LIKE 'cp.test.citizen.%@civicpulse.test'"
    )->fetchColumn();
    $statusGroups = (int)$pdo->query(
        "SELECT COUNT(DISTINCT status) FROM complaints WHERE tracking_id LIKE 'CPTEST%'"
    )->fetchColumn();
    echo "Verified test citizens: $citizens; sample complaints: $complaints; linked complaints: $linked; statuses represented: $statusGroups.\n";
    echo "Database totals: " . (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()
        . " users; " . (int)$pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn() . " complaints.\n";
}

if ($citizenCount === 500 && $complaintCount === 500) {
    echo "The 500 test citizens and complaints are already present; no changes made.\n";
    printSeedVerification($pdo);
    exit(0);
}
if ($citizenCount !== 0 || $complaintCount !== 0) {
    fwrite(STDERR, "Partial test data already exists. No changes made; inspect cp.test.citizen.* and CPTEST* rows first.\n");
    exit(1);
}

$columnNames = $pdo->query('SHOW COLUMNS FROM complaints')->fetchAll(PDO::FETCH_COLUMN);
foreach (['tracking_id', 'citizen_id', 'department_id', 'category', 'title', 'description',
    'location', 'landmark', 'priority', 'latitude', 'longitude', 'status', 'is_sample'] as $requiredColumn) {
    if (!in_array($requiredColumn, $columnNames, true)) {
        throw new RuntimeException("The complaints table is missing required column: $requiredColumn");
    }
}

$departmentByCategory = [];
foreach ($categories as $category) {
    $departmentByCategory[$category] = category_department_id($category);
}
$adminId = $pdo->query(
    "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"
)->fetchColumn();

$userInsert = $pdo->prepare(
    'INSERT INTO users(name, email, phone, password, role, department_id, is_active) VALUES(?, ?, ?, ?, ?, NULL, 1)'
);
$complaintInsert = $pdo->prepare(
    'INSERT INTO complaints(tracking_id, citizen_id, department_id, category, title, description, location, landmark, priority, latitude, longitude, status, is_sample) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$historyInsert = $pdo->prepare(
    'INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)'
);
$passwordHash = password_hash('TestCitizen@123', PASSWORD_DEFAULT);
$historyActor = $adminId ? (int)$adminId : null;
$pdo->beginTransaction();

try {
    for ($number = 1; $number <= 500; $number++) {
        $firstName = $firstNames[($number - 1) % count($firstNames)];
        $lastName = $lastNames[intdiv($number - 1, count($firstNames))];
        $name = $firstName . ' ' . $lastName;
        $email = $accountPrefix . str_pad((string)$number, 4, '0', STR_PAD_LEFT) . '@civicpulse.test';
        $phone = '989' . str_pad((string)(1000000 + $number), 7, '0', STR_PAD_LEFT);
        $userInsert->execute([$name, $email, $phone, $passwordHash, 'citizen']);
        $citizenId = (int)$pdo->lastInsertId();

        $category = $categories[($number - 1) % count($categories)];
        $status = $statuses[($number - 1) % count($statuses)];
        $latitude = round(18.45 + (($number * 37) % 1800) / 10000, 7);
        $longitude = round(73.75 + (($number * 53) % 2200) / 10000, 7);
        $trackingId = $trackingPrefix . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
        $complaintInsert->execute([
            $trackingId,
            $citizenId,
            $departmentByCategory[$category],
            $category,
            '[TEST] ' . $titles[$category],
            'Fictional test report for checking CivicPulse complaint routing, tracking, and dashboard workflows.',
            'Ward ' . ((($number - 1) % 30) + 1) . ', Pune',
            $landmarks[($number - 1) % count($landmarks)],
            $priorities[($number - 1) % count($priorities)],
            $latitude,
            $longitude,
            $status,
        ]);
        $complaintId = (int)$pdo->lastInsertId();
        $historyInsert->execute([$complaintId, 'submitted', 'Fictional test report submitted.', $citizenId]);
        if ($status !== 'submitted') {
            $historyInsert->execute([
                $complaintId,
                $status,
                'Fictional test status update.',
                $historyActor ?? $citizenId,
            ]);
        }
    }

    $pdo->commit();
    echo "Created 500 fictional citizen accounts and 500 linked sample complaints.\n";
    echo "Email format: cp.test.citizen.0001@civicpulse.test through cp.test.citizen.0500@civicpulse.test\n";
    echo "Shared local test password: TestCitizen@123\n";
    echo "All generated complaints have [TEST] titles and is_sample=1.\n";
    printSeedVerification($pdo);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Test data generation failed; the database transaction was rolled back: " . $exception->getMessage() . "\n");
    exit(1);
}
