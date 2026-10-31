<?php
require_once __DIR__ . '/includes/functions.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (strlen($name) < 2 || strlen($name) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 ||
        !preg_match('/^[0-9+() .-]{7,20}$/', $phone) ||
        !is_string($password) || strlen($password) < 8) {
        $err = 'Check your details. Use a valid email, phone number, and password with at least 8 characters.';
    } else {
        try {
            create_user($name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'citizen');
            flash('Your account is ready. Sign in to continue.');
            go('login.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $err = 'That email is already registered. Try signing in instead.';
            } else {
                error_log('CivicPulse registration failed: ' . $exception->getMessage());
                $err = 'We could not create your account right now. Please try again.';
            }
        }
    }
}

$title = 'Create account';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout">
    <div class="auth-aside">
        <span class="eyebrow"><span class="live-dot"></span> Make your voice count</span>
        <h1>Small reports.<br>Big city impact.</h1>
        <p>Join neighbors helping local teams spot problems sooner and make public services work better.</p>
        <div class="auth-points">
            <span><i class="fa-solid fa-shield-halved"></i> Your account is private</span>
            <span><i class="fa-solid fa-clock-rotate-left"></i> Updates stay in one place</span>
            <span><i class="fa-solid fa-mobile-screen"></i> Works on any device</span>
        </div>
    </div>
    <div class="auth-card card">
        <div class="auth-topline"><span class="section-kicker">Join CivicPulse</span><span class="auth-lock"><i class="fa-solid fa-user-shield"></i> Citizen account</span></div>
        <h2 class="mb-2">Create your account</h2>
        <p class="text-secondary mb-4">Citizen accounts can submit and track local complaints.</p>
        <?php if ($err): ?><div class="alert alert-danger" role="alert"><?= e($err) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('register.php')) ?>">
            <?= csrf() ?>
            <label class="form-label" for="name">Full name</label>
            <input id="name" class="form-control mb-3" name="name" maxlength="100" autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>" required>
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label" for="email">Email address</label>
                    <input id="email" type="email" class="form-control mb-3" name="email" maxlength="120" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="phone">Phone</label>
                    <input id="phone" class="form-control mb-3" name="phone" maxlength="20" pattern="[0-9+\(\) .\-]{7,20}" autocomplete="tel" value="<?= e($_POST['phone'] ?? '') ?>" required>
                </div>
            </div>
            <label class="form-label" for="password">Password</label>
            <div class="password-field mb-4"><input id="password" type="password" class="form-control" name="password" minlength="8" autocomplete="new-password" required><button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye"></i></button></div>
            <button class="btn btn-primary btn-lg w-100" type="submit">Create account <i class="fa-solid fa-arrow-right ms-2"></i></button>
        </form>
        <p class="mt-4 mb-0 text-secondary">Already registered? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
