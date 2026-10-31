<?php
require_once __DIR__ . '/includes/functions.php';

$stats = [
    'Citizens' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'citizen' AND is_active = 1")->fetchColumn(),
    'Issues reported' => (int)$pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn(),
    'Resolved' => (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'resolved'")->fetchColumn(),
    'City departments' => (int)$pdo->query('SELECT COUNT(*) FROM departments WHERE is_active = 1')->fetchColumn(),
];
$title = 'A Smarter City Starts With Your Voice';
require __DIR__ . '/includes/header.php';
?>
<section class="landing-hero">
    <div class="landing-copy">
        <span class="eyebrow"><span class="live-dot"></span> Pune · Connected city services</span>
        <h1>A Smarter City Starts With Your Voice.</h1>
        <p>Report neighborhood issues, reach the right city department, and follow every update—all in one transparent place.</p>
        <div class="hero-actions">
            <?php if (isCitizen()): ?>
                <a href="<?= e(url('citizen/submit_complaint.php')) ?>" class="btn btn-cyan btn-lg">Report an Issue <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i></a>
                <a href="<?= e(url('citizen/complaints.php')) ?>" class="btn btn-glass btn-lg"><i class="fa-solid fa-magnifying-glass me-2"></i>Track Complaint</a>
            <?php else: ?>
                <a href="<?= e(url('login.php?role=citizen&next=citizen%2Fsubmit_complaint.php')) ?>" class="btn btn-cyan btn-lg">Report an Issue <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i></a>
                <a href="<?= e(url('login.php?role=citizen&next=citizen%2Fcomplaints.php')) ?>" class="btn btn-glass btn-lg"><i class="fa-solid fa-magnifying-glass me-2"></i>Track Complaint</a>
            <?php endif; ?>
        </div>
        <div class="hero-trust"><div class="trust-avatars"><span>CP</span><span>PC</span><span>PN</span></div><span>City teams and citizens, working together.</span></div>
    </div>
    <div class="city-visual" aria-label="Illustration of Pune as a connected smart city">
        <img src="<?= e(url('assets/img/pune-smart-city.svg')) ?>" alt="Futuristic blue illustration of a connected city skyline" class="city-illustration">
        <div class="city-coordinates"><i class="fa-solid fa-location-dot"></i><span>PUNE, INDIA<small>18.5204° N · 73.8567° E</small></span></div>
        <div class="city-status"><i class="fa-solid fa-building-circle-check text-info"></i><span>Pune civic services<small>One place for every report</small></span></div>
        <div class="city-metric"><span class="metric-icon"><i class="fa-solid fa-circle-check"></i></span><span><strong><?= number_format($stats['Resolved']) ?></strong><small>community issues resolved</small></span></div>
    </div>
</section>

<section class="metric-ribbon row g-0" aria-label="CivicPulse at a glance">
    <?php foreach ([
        ['Complaints resolved', $stats['Resolved'], 'fa-circle-check'],
        ['Reports received', $stats['Issues reported'], 'fa-bullhorn'],
        ['Departments connected', $stats['City departments'], 'fa-building'],
        ['Citizens in the network', $stats['Citizens'], 'fa-people-group'],
    ] as [$label, $value, $icon]): ?>
        <div class="col-6 col-lg-3"><div class="metric-cell"><span class="metric-icon"><i class="fa-solid <?= e($icon) ?>"></i></span><span><strong data-count="<?= (int)$value ?>"><?= number_format((int)$value) ?></strong><small><?= e($label) ?></small></span></div></div>
    <?php endforeach; ?>
</section>

<section class="feature-section">
    <div class="section-heading"><div><span class="section-kicker">One city. One connected system.</span><h2 class="h3 mt-2 mb-1">Your concern, moving in the right direction</h2><p>Every report reaches the people who can make a difference.</p></div></div>
    <div class="row g-3">
        <div class="col-md-4"><a class="feature-card card" href="<?= e(url(isCitizen() ? 'citizen/submit_complaint.php' : 'login.php?role=citizen&next=citizen%2Fsubmit_complaint.php')) ?>"><span class="feature-icon"><i class="fa-solid fa-location-crosshairs"></i></span><span class="feature-step">01 / REPORT</span><h3>Make your voice count</h3><p>Send a clear report with its location and an optional photo, right from your phone.</p><span class="feature-link">Report a city issue <i class="fa-solid fa-arrow-right"></i></span></a></div>
        <div class="col-md-4"><a class="feature-card card" href="<?= e(url(isCitizen() ? 'citizen/complaints.php' : 'login.php?role=citizen&next=citizen%2Fcomplaints.php')) ?>"><span class="feature-icon"><i class="fa-solid fa-route"></i></span><span class="feature-step">02 / FOLLOW</span><h3>See what happens next</h3><p>Follow department updates in a clear timeline—from the first review through resolution.</p><span class="feature-link">Track your report <i class="fa-solid fa-arrow-right"></i></span></a></div>
        <div class="col-md-4"><a class="feature-card card" href="#city-services"><span class="feature-icon"><i class="fa-solid fa-tower-broadcast"></i></span><span class="feature-step">03 / CONNECT</span><h3>One city, working together</h3><p>Roads, water, sanitation and more—each report connects with the right service team.</p><span class="feature-link">Explore city services <i class="fa-solid fa-arrow-right"></i></span></a></div>
    </div>
</section>

<section id="city-services" class="service-section">
    <div class="section-heading"><div><span class="section-kicker">Departments in your corner</span><h2 class="h3 mt-2 mb-1">Support for everyday city life</h2><p>Choose a service category when you submit your report.</p></div></div>
    <div class="row g-3">
        <?php foreach (CATS as $category => $details): ?>
            <div class="col-6 col-md-4 col-lg-3"><div class="service-card card">
                <div class="service-icon"><i class="fa-solid <?= e($details[1]) ?>"></i></div>
                <h3><?= e($category) ?></h3><i class="fa-solid fa-arrow-up-right-from-square service-arrow"></i>
            </div></div>
        <?php endforeach; ?>
    </div>
</section>

<section id="how-it-works" class="process-section">
    <div class="section-heading"><div><span class="section-kicker">Simple by design</span><h2 class="h3 mt-2 mb-1">From concern to progress</h2><p>Every report has a clear place in the process.</p></div></div>
    <div class="row g-3">
        <?php foreach ([
            ['01', 'Tell us what happened', 'Share the details, add an address, and pin the location on a map.'],
            ['02', 'The right team takes over', 'Your report is routed to its city department for review and assignment.'],
            ['03', 'Follow the progress', 'Check the timeline as the status changes, until the issue is resolved.'],
        ] as [$number, $heading, $copy]): ?>
            <div class="col-md-4"><div class="process-card"><span><?= e($number) ?></span><h3><?= e($heading) ?></h3><p><?= e($copy) ?></p></div></div>
        <?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
