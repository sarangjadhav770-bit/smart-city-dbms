<?php
require_once __DIR__ . '/functions.php';
$currentUser = me();
$dashboardLinks = [
    'citizen' => 'citizen/dashboard.php',
    'department' => 'department/dashboard.php',
    'officer' => 'department/dashboard.php',
    'admin' => 'admin/dashboard.php',
];
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102a56">
    <title><?= e($title ?? 'CivicPulse') ?> · CivicPulse</title>
    <script>try{document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('theme')||'light')}catch(e){document.documentElement.setAttribute('data-bs-theme','light')}</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg cp-nav">
    <div class="container">
        <a class="navbar-brand" href="<?= e(url()) ?>"><span class="brand-mark"><i class="fa-solid fa-location-dot"></i></span><span>Civic<span class="brand-light">Pulse</span><small>SMART CITY SERVICES</small></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="<?= e(url()) ?>">Home</a></li>
                <?php if ($currentUser): ?>
                    <?php $home = $dashboardLinks[$currentUser['role']] ?? 'login.php'; ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url($home)) ?>">Dashboard</a></li>
                    <?php if ($currentUser['role'] === 'citizen'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= e(url('citizen/submit_complaint.php')) ?>">Report an issue</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><span class="nav-user"><span class="avatar"><?= e(strtoupper(substr($currentUser['name'], 0, 1))) ?></span><?= e($currentUser['name']) ?></span></li>
                    <li class="nav-item"><a class="btn btn-nav" href="<?= e(url('logout.php')) ?>">Sign out</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('register.php')) ?>">Create account</a></li>
                    <li class="nav-item"><a class="btn btn-nav" href="<?= e(url('login.php')) ?>">Sign in <i class="fa-solid fa-arrow-right ms-1"></i></a></li>
                <?php endif; ?>
                <li class="nav-item"><button class="theme-button" id="themeBtn" type="button" title="Toggle color theme" aria-label="Toggle color theme"><i class="fa-solid fa-circle-half-stroke"></i></button></li>
            </ul>
        </div>
    </div>
</nav>
<main class="container py-4 py-lg-5 fade-in">
