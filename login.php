<?php
require_once __DIR__ . '/includes/functions.php';

$err = '';
$selectedRole = $_POST['role'] ?? $_GET['role'] ?? 'citizen';
if (!in_array($selectedRole, ['citizen', 'officer', 'admin'], true)) {
    $selectedRole = 'citizen';
}
$nextPage = $_POST['next'] ?? $_GET['next'] ?? '';
if (!in_array($nextPage, ['citizen/complaints.php', 'citizen/submit_complaint.php'], true)) {
    $nextPage = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $selectedRole = $_POST['role'] ?? 'citizen';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '' ||
        !in_array($selectedRole, ['citizen', 'officer', 'admin'], true)) {
        $err = 'Choose your account type and enter a valid email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password, role, department_id, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            $err = 'The email or password is incorrect.';
        } elseif (empty($user['is_active'])) {
            $err = 'This account is inactive. Contact an administrator.';
        } elseif (!in_array($user['role'], ['admin', 'officer', 'department', 'citizen'], true)) {
            $err = 'This account has an unsupported role. Contact an administrator.';
        } elseif (($user['role'] === 'department' ? 'officer' : $user['role']) !== $selectedRole) {
            $err = 'Those credentials do not match the selected account type.';
        } else {
            $role = $user['role'] === 'department' ? 'officer' : $user['role'];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $role;
            $_SESSION['department_id'] = $user['department_id'];

            $destinations = [
                'admin' => 'admin/dashboard.php',
                'officer' => 'department/dashboard.php',
                'citizen' => 'citizen/dashboard.php',
            ];
            go($role === 'citizen' && $nextPage !== '' ? $nextPage : $destinations[$role]);
        }
    }
}

$title = 'Sign in';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout">
    <div class="auth-aside">
        <span class="eyebrow"><span class="live-dot"></span> CivicPulse · Pune city services</span>
        <h1>Your city.<br>In better hands.</h1>
        <p>One connected space for citizens and the people serving the city. Log in to pick up where you left off.</p>
        <div class="auth-points">
            <span><i class="fa-solid fa-location-dot"></i> Report with a precise location</span>
            <span><i class="fa-solid fa-list-check"></i> Follow every status update</span>
            <span><i class="fa-solid fa-people-group"></i> Connect with city teams</span>
        </div>
        <div class="auth-city-caption"><span class="live-dot"></span> City services connected <span>·</span> Pune, India</div>
    </div>
    <div class="auth-card card">
        <div class="auth-topline"><span class="section-kicker">Welcome back</span><span class="auth-lock"><i class="fa-solid fa-lock"></i> Secure sign in</span></div>
        <h2 class="mb-2">Sign in to CivicPulse</h2>
        <p class="text-secondary mb-4">Choose your account type, then enter your details.</p>
        <?php if ($err): ?><div class="alert alert-danger" role="alert"><?= e($err) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('login.php')) ?>">
            <?= csrf() ?>
            <?php if ($nextPage !== ''): ?><input type="hidden" name="next" value="<?= e($nextPage) ?>"><?php endif; ?>
            <div class="role-picker" role="group" aria-label="Choose account type">
                <?php foreach (['citizen' => 'Citizen', 'officer' => 'Officer', 'admin' => 'Admin'] as $roleValue => $roleLabel): ?>
                    <label class="role-option"><input type="radio" name="role" value="<?= e($roleValue) ?>" <?= $selectedRole === $roleValue ? 'checked' : '' ?>><span><?= e($roleLabel) ?></span></label>
                <?php endforeach; ?>
            </div>
            <label class="form-label" for="email">Email address</label>
            <input id="email" type="email" name="email" class="form-control form-control-lg mb-3" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
            <label class="form-label" for="password">Password</label>
            <div class="password-field mb-4"><input id="password" type="password" name="password" class="form-control form-control-lg" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye"></i></button></div>
            <p class="role-hint"><i class="fa-solid fa-circle-info"></i> Your account type is verified against your CivicPulse account.</p>
            <button type="submit" class="btn btn-primary btn-lg w-100">Sign in <i class="fa-solid fa-arrow-right ms-2"></i></button>
        </form>
        <p class="mt-4 mb-0 text-secondary">New to CivicPulse? <a href="<?= e(url('register.php')) ?>">Create a citizen account</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
