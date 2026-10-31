<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

const CATS = [
    'Roads' => [null, 'fa-road'],
    'Garbage' => [null, 'fa-trash'],
    'Water' => [null, 'fa-droplet'],
    'Water Leakage' => [null, 'fa-faucet-drip'],
    'Electricity' => [null, 'fa-bolt'],
    'Streetlight' => [null, 'fa-lightbulb'],
    'Drainage' => [null, 'fa-water'],
    'Traffic' => [null, 'fa-traffic-light'],
    'Public Health' => [null, 'fa-heart-pulse'],
    'Police' => [null, 'fa-shield-halved'],
];

const STATUSES = [
    'submitted',
    'under_review',
    'assigned',
    'in_progress',
    'resolved',
    'rejected',
    'reopened',
];

const STATUS_LABELS = [
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'assigned' => 'Assigned',
    'in_progress' => 'In Progress',
    'resolved' => 'Resolved',
    'rejected' => 'Rejected',
    'reopened' => 'Reopened',
];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function appBasePath(): string
{
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = preg_replace('~/(admin|citizen|department)$~', '', $scriptDirectory);
    return rtrim($base === '.' ? '' : $base, '/');
}

function url(string $path = ''): string
{
    $base = appBasePath();
    $path = ltrim($path, '/');
    return $base . ($path === '' ? '/' : '/' . $path);
}

function go(string $path): void
{
    $target = preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, '/')
        ? $path
        : url($path);
    header('Location: ' . $target);
    exit;
}

function csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !is_string($sessionToken) ||
        $submittedToken === '' || $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

function isOfficer(): bool
{
    return isLoggedIn() && in_array($_SESSION['role'] ?? '', ['officer', 'department'], true);
}

function isCitizen(): bool
{
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'citizen';
}

function me(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => (int)$_SESSION['user_id'],
        'name' => (string)($_SESSION['name'] ?? ''),
        'email' => (string)($_SESSION['email'] ?? ''),
        'role' => (string)($_SESSION['role'] ?? ''),
        'department_id' => isset($_SESSION['department_id']) ? (int)$_SESSION['department_id'] : null,
    ];
}

function need(string $role): array
{
    global $pdo;
    $user = me();
    if (!$user) {
        go('login.php');
    }
    $account = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
    $account->execute([$user['id']]);
    if (!$account->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        go('login.php');
    }
    if ($role === 'department') {
        $role = 'officer';
    }
    $currentRole = $user['role'] === 'department' ? 'officer' : $user['role'];
    if ($currentRole !== $role) {
        http_response_code(403);
        exit('Access denied. This page is not available for your account.');
    }
    return $user;
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function create_user(string $name, string $email, ?string $phone, string $passwordHash, string $role, ?int $departmentId = null): int
{
    global $pdo;
    $availableColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $columns = ['name', 'email', 'phone', 'role', 'department_id'];
    $values = [$name, $email, $phone, $role, $departmentId];
    if (in_array('password', $availableColumns, true)) {
        array_splice($columns, 3, 0, ['password']);
        array_splice($values, 3, 0, [$passwordHash]);
    }
    if (in_array('password_hash', $availableColumns, true)) {
        array_splice($columns, 3, 0, ['password_hash']);
        array_splice($values, 3, 0, [$passwordHash]);
    }
    $quotedColumns = implode(', ', array_map(static function ($column) {
        return '`' . $column . '`';
    }, $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare("INSERT INTO users($quotedColumns) VALUES($placeholders)");
    $stmt->execute($values);
    return (int)$pdo->lastInsertId();
}

function notify(int $userId, string $message): void
{
    global $pdo;
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 255) : substr($message, 0, 255);
    $columns = $pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('title', $columns, true) && in_array('body', $columns, true)) {
        $stmt = $pdo->prepare('INSERT INTO notifications(user_id, title, body, message) VALUES(?, ?, ?, ?)');
        $stmt->execute([$userId, substr($message, 0, 160), $message, $message]);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO notifications(user_id, message) VALUES(?, ?)');
    $stmt->execute([$userId, $message]);
}

function mark_notifications_read(int $userId): void
{
    global $pdo;
    $columns = $pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN);
    $sql = in_array('read_at', $columns, true)
        ? 'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = ?'
        : 'UPDATE notifications SET is_read = 1 WHERE user_id = ?';
    $pdo->prepare($sql)->execute([$userId]);
}

function badge(string $status): string
{
    $styles = [
        'submitted' => 'text-bg-primary',
        'under_review' => 'text-bg-info',
        'assigned' => 'text-bg-secondary',
        'in_progress' => 'text-bg-warning',
        'resolved' => 'text-bg-success',
        'rejected' => 'text-bg-danger',
        'reopened' => 'text-bg-dark',
    ];
    $class = $styles[$status] ?? 'text-bg-secondary';
    return '<span class="badge rounded-pill ' . $class . '">' . e(STATUS_LABELS[$status] ?? ucfirst($status)) . '</span>';
}

function category_department_id(string $category): int
{
    global $pdo;
    $candidates = [
        'Roads' => ['road', 'transport'],
        'Garbage' => ['sanitation', 'waste'],
        'Water' => ['water'],
        'Water Leakage' => ['water'],
        'Electricity' => ['electric', 'utility'],
        'Streetlight' => ['electric', 'streetlight', 'utility'],
        'Drainage' => ['drainage', 'municipal'],
        'Traffic' => ['road', 'transport', 'traffic'],
        'Public Health' => ['health'],
        'Police' => ['police', 'safety'],
    ];
    if (!isset($candidates[$category])) {
        throw new InvalidArgumentException('Choose a valid city service category.');
    }

    $departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY id')->fetchAll();
    foreach ($candidates[$category] as $term) {
        foreach ($departments as $department) {
            if (stripos($department['name'], $term) !== false) {
                return (int)$department['id'];
            }
        }
    }
    throw new RuntimeException("No department is configured for the $category category.");
}

function save_image(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image could not be uploaded. Please try again.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Images must be smaller than 2 MB.');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    $mime = $imageInfo['mime'] ?? '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Upload a valid JPG, PNG, or WEBP image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'complaints';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The upload directory could not be created.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }
    return 'uploads/complaints/' . $filename;
}

function complaint_tracking_id(): string
{
    global $pdo;
    do {
        $id = 'CP' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare('SELECT 1 FROM complaints WHERE tracking_id = ?');
        $stmt->execute([$id]);
    } while ($stmt->fetchColumn());
    return $id;
}
