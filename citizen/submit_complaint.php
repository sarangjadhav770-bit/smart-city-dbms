<?php
require_once __DIR__ . '/../includes/functions.php';
$user = need('citizen');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check();
    $category = trim($_POST['category'] ?? '');
    $subject = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $latitude = is_numeric($_POST['lat'] ?? null) ? (float)$_POST['lat'] : null;
    $longitude = is_numeric($_POST['lng'] ?? null) ? (float)$_POST['lng'] : null;

    if (!isset(CATS[$category]) || strlen($subject) < 5 || strlen($subject) > 150 ||
        strlen($description) < 10 || strlen($location) < 3 || strlen($location) > 200 ||
        strlen($landmark) > 150 || !in_array($priority, ['low', 'medium', 'high', 'urgent'], true) ||
        ($latitude !== null && ($latitude < -90 || $latitude > 90)) ||
        ($longitude !== null && ($longitude < -180 || $longitude > 180)) ||
        (($latitude === null) !== ($longitude === null))) {
        $error = 'Check the category, title, description, address, priority, and map location.';
    } else {
        $imagePath = null;
        try {
            $imagePath = save_image($_FILES['image'] ?? null);
            $departmentId = category_department_id($category);
            $trackingId = complaint_tracking_id();

            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO complaints(tracking_id, citizen_id, department_id, category, title, description, location, landmark, priority, latitude, longitude) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$trackingId, $user['id'], $departmentId, $category, $subject, $description, $location, $landmark, $priority, $latitude, $longitude]);
            $complaintId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO complaint_status_history(complaint_id, status, note, changed_by) VALUES(?, ?, ?, ?)')->execute([$complaintId, 'submitted', 'Complaint submitted', $user['id']]);
            if ($imagePath !== null) {
                $pdo->prepare("INSERT INTO complaint_attachments(complaint_id, file_path, kind) VALUES(?, ?, 'evidence')")->execute([$complaintId, $imagePath]);
            }
            $officers = $pdo->prepare("SELECT id FROM users WHERE role IN ('officer', 'department') AND is_active = 1 AND department_id = ?");
            $officers->execute([$departmentId]);
            foreach ($officers->fetchAll() as $officer) {
                notify((int)$officer['id'], "New complaint $trackingId: $subject");
            }
            $pdo->commit();
            flash("Report submitted. Your tracking ID is $trackingId.");
            go('citizen/complaint_details.php?id=' . $complaintId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($imagePath !== null) {
                $storedImage = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
                if (is_file($storedImage)) {
                    unlink($storedImage);
                }
            }
            error_log('CivicPulse complaint submission failed: ' . $exception->getMessage());
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'We could not submit your report. Please try again.';
        }
    }
}

$title = 'Report an issue';
require __DIR__ . '/../includes/header.php';
?>
<section class="city-report-shell" id="city-report" data-citizen="<?= (int)$user['id'] ?>" data-server-posted="<?= $_SERVER['REQUEST_METHOD'] === 'POST' ? '1' : '0' ?>">
<div class="city-world" id="city-world">
    <canvas id="city-canvas" tabindex="0" aria-label="Interactive procedural smart city at night. Drag to orbit, hold Shift or right-drag to travel, and use two fingers to pan and pinch to zoom. Page scrolling remains available outside the city canvas."></canvas>
    <div class="city-fallback" id="city-fallback" aria-hidden="true"></div>
    <div class="city-world-shade" aria-hidden="true"></div>
    <div class="city-world-heading">
        <span class="city-world-kicker"><span class="live-dot"></span> CIVICPULSE · PUNE AFTER DARK</span>
        <h1>Every street has a story.</h1>
        <p>Find the place. Tell us what needs attention.</p>
        <div class="city-scene-status"><span class="scene-status-dot"></span><span id="city-scene-status">City scene loading</span><span class="scene-status-divider"></span><i class="fa-solid fa-cube"></i> Interactive city</div>
    </div>
    <div class="city-world-coordinates"><i class="fa-solid fa-location-dot"></i><span id="city-location-label">PUNE · 18.5204° N 73.8567° E</span></div>
    <div class="city-world-controls">
        <span><i class="fa-solid fa-arrows-left-right"></i> DRAG TO ORBIT</span>
        <span><i class="fa-solid fa-magnifying-glass-plus"></i> SCROLL TO EXPLORE</span>
        <span><i class="fa-solid fa-up-down-left-right"></i> SHIFT / 2-FINGER PAN + PINCH</span>
    </div>
    <div class="city-category-signal" id="city-category-signal" aria-live="polite"><i class="fa-solid fa-tower-broadcast"></i><span>Select a service to highlight its city network</span></div>
    <button class="city-reset-view" type="button" id="city-reset-view"><i class="fa-solid fa-compass"></i> Reset view</button>

<form method="post" enctype="multipart/form-data" class="city-form-panel" id="complaint-form">
    <?= csrf() ?>
    <div class="city-form-heading">
        <div><span class="city-panel-kicker">YOUR CITY, YOUR VOICE</span><h2>Report an issue</h2><p>We’ll get it to the right team.</p></div>
        <span class="city-form-step"><i class="fa-solid fa-location-dot"></i> 01 / 04</span>
    </div>
    <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="city-form-section">
        <div class="city-section-label"><span>01</span><div><strong>Choose a service</strong><small>What needs attention?</small></div></div>
        <div class="city-category-grid">
        <?php foreach (CATS as $category => $details): ?>
            <label class="city-category-option" for="category-<?= e(strtolower(str_replace(' ', '-', $category))) ?>">
                <input type="radio" name="category" id="category-<?= e(strtolower(str_replace(' ', '-', $category))) ?>" value="<?= e($category) ?>" <?= ($_POST['category'] ?? '') === $category ? 'checked' : '' ?> required>
                <span class="city-category-icon"><i class="fa-solid <?= e($details[1]) ?>"></i></span><span class="city-category-name"><?= e($category) ?></span>
            </label>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="city-form-section">
        <div class="city-section-label"><span>02</span><div><strong>Describe the issue</strong><small>Give the city team some context.</small></div></div>
        <label class="city-field-label" for="title">Short title</label>
        <input id="title" class="form-control city-control" name="title" maxlength="150" minlength="5" value="<?= e($_POST['title'] ?? '') ?>" placeholder="e.g. Damaged road near the crossing" required>
        <label class="city-field-label mt-3" for="description">What happened?</label>
        <textarea class="form-control city-control" name="description" id="description" rows="3" minlength="10" placeholder="Describe what you noticed and how it affects the neighborhood." required><?= e($_POST['description'] ?? '') ?></textarea>
        <label class="city-field-label mt-3" for="priority">Priority</label>
        <select class="form-select city-control" name="priority" id="priority"><?php foreach (['low', 'medium', 'high', 'urgent'] as $option): ?><option value="<?= e($option) ?>" <?= ($_POST['priority'] ?? 'medium') === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select>
    </div>
    <div class="city-form-section">
        <div class="city-section-label"><span>03</span><div><strong>Pin the location</strong><small>Tap the map or add an address.</small></div></div>
        <label class="city-field-label" for="location">Address or location description</label>
        <input class="form-control city-control" name="location" id="location" maxlength="200" value="<?= e($_POST['location'] ?? '') ?>" placeholder="Street, neighborhood or nearby address" required>
        <label class="city-field-label mt-3" for="landmark">Nearby landmark <span>(optional)</span></label>
        <input class="form-control city-control" name="landmark" id="landmark" maxlength="150" value="<?= e($_POST['landmark'] ?? '') ?>" placeholder="A shop, park or junction">
        <div id="map" class="city-leaflet-map mt-3" aria-label="Map to select issue location"></div>
        <div class="city-pin-caption"><i class="fa-solid fa-crosshairs"></i><span id="city-pin-status">Map pin optional · Pune city center</span></div>
        <input type="hidden" name="lat" id="lat" value="<?= e($_POST['lat'] ?? '') ?>"><input type="hidden" name="lng" id="lng" value="<?= e($_POST['lng'] ?? '') ?>">
    </div>
    <div class="city-form-section">
        <div class="city-section-label"><span>04</span><div><strong>Add a photo</strong><small>JPG, PNG or WEBP · up to 2 MB · optional</small></div></div>
        <input type="file" class="form-control city-control city-file-input" name="image" id="image" accept="image/jpeg,image/png,image/webp">
        <img id="image-preview" class="img-fluid rounded mt-2 d-none" style="max-height:150px;object-fit:contain;object-position:left" alt="Selected image preview">
    </div>
    <div class="city-form-actions">
        <span><i class="fa-solid fa-lock"></i> Shared with the responsible city team.</span>
        <div class="city-action-buttons">
            <button class="btn city-save-draft" type="button" id="save-draft"><i class="fa-regular fa-floppy-disk"></i> Save draft</button>
            <button class="btn city-submit-button" type="submit">Submit report <i class="fa-solid fa-arrow-right"></i></button>
        </div>
        <p class="city-draft-status" id="draft-status" role="status" aria-live="polite"></p>
    </div>
</form>
</div>
</section>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
window.civicPulseCityState = {
    category: <?= json_encode($_POST['category'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    lat: <?= json_encode($_POST['lat'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    lng: <?= json_encode($_POST['lng'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
};
</script>
<script type="module" src="<?= e(url('assets/js/city-experience.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var latInput = document.getElementById('lat');
    var lngInput = document.getElementById('lng');
    var locationLabel = document.getElementById('city-location-label');
    var pinStatus = document.getElementById('city-pin-status');
    var reportForm = document.getElementById('complaint-form');
    var categorySignal = document.getElementById('city-category-signal');
    var marker;

    if (window.L) {
        var map = L.map('map', { zoomControl: true, scrollWheelZoom: false }).setView([18.5204, 73.8567], 13);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
        window.civicPulseComplaintMap = map;
        map.on('click', function (event) {
            if (marker) marker.setLatLng(event.latlng);
            else marker = L.marker(event.latlng).addTo(map);
            latInput.value = event.latlng.lat.toFixed(7);
            lngInput.value = event.latlng.lng.toFixed(7);
            window.civicPulseCityState.lat = latInput.value;
            window.civicPulseCityState.lng = lngInput.value;
            locationLabel.textContent = 'PIN SET · ' + event.latlng.lat.toFixed(4) + '° N ' + event.latlng.lng.toFixed(4) + '° E';
            pinStatus.textContent = event.latlng.lat.toFixed(5) + ', ' + event.latlng.lng.toFixed(5) + ' · City camera focused';
            document.dispatchEvent(new CustomEvent('civicpulse:location-selected', { detail: { lat: event.latlng.lat, lng: event.latlng.lng } }));
        });
        if (latInput.value && lngInput.value) {
            var initialPosition = [Number(latInput.value), Number(lngInput.value)];
            if (initialPosition.every(Number.isFinite)) {
                marker = L.marker(initialPosition).addTo(map);
                map.setView(initialPosition, 15);
                locationLabel.textContent = 'PIN SET · ' + initialPosition[0].toFixed(4) + '° N ' + initialPosition[1].toFixed(4) + '° E';
                pinStatus.textContent = initialPosition[0].toFixed(5) + ', ' + initialPosition[1].toFixed(5) + ' · City camera focused';
                window.civicPulseCityState.lat = latInput.value;
                window.civicPulseCityState.lng = lngInput.value;
            }
        }
        window.setTimeout(function () { map.invalidateSize(); }, 250);
    } else {
        pinStatus.textContent = 'Map service unavailable. You can still add an address.';
    }

    var fileInput = document.getElementById('image');
    fileInput.addEventListener('change', function () {
        var file = fileInput.files[0];
        var preview = document.getElementById('image-preview');
        if (!file) return;
        if (file.size > 2097152 || ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
            window.alert('Choose a JPG, PNG or WEBP image under 2 MB.');
            fileInput.value = '';
            preview.classList.add('d-none');
            return;
        }
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('d-none');
    });

    document.querySelectorAll('input[name="category"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var category = input.value;
            window.civicPulseCityState.category = category;
            categorySignal.querySelector('span').textContent = 'Highlighting ' + category + ' infrastructure across the city';
            document.dispatchEvent(new CustomEvent('civicpulse:category-selected', { detail: { category: category } }));
        });
    });
    var selectedCategory = reportForm.querySelector('input[name="category"]:checked');
    if (selectedCategory) selectedCategory.dispatchEvent(new Event('change'));

    var draftKey = 'civicpulse_complaint_draft_' + document.getElementById('city-report').dataset.citizen;
    var draftStatus = document.getElementById('draft-status');
    try {
        var savedDraft = localStorage.getItem(draftKey);
        if (savedDraft) {
            var draft = JSON.parse(savedDraft);
            ['title', 'description', 'location', 'landmark', 'priority'].forEach(function (name) {
                var field = reportForm.elements.namedItem(name);
                if (!document.getElementById('city-report').dataset.serverPosted && field && draft[name]) field.value = draft[name];
            });
            if (!document.getElementById('city-report').dataset.serverPosted && draft.category && !reportForm.querySelector('input[name="category"]:checked')) {
                var categoryInput = reportForm.querySelector('input[name="category"][value="' + CSS.escape(draft.category) + '"]');
                if (categoryInput) {
                    categoryInput.checked = true;
                    categoryInput.dispatchEvent(new Event('change'));
                }
            }
            if (!document.getElementById('city-report').dataset.serverPosted && draft.lat && draft.lng && window.civicPulseComplaintMap) {
                latInput.value = draft.lat;
                lngInput.value = draft.lng;
                window.civicPulseCityState.lat = draft.lat;
                window.civicPulseCityState.lng = draft.lng;
                marker = L.marker([draft.lat, draft.lng]).addTo(window.civicPulseComplaintMap);
                window.civicPulseComplaintMap.setView([draft.lat, draft.lng], 15);
                locationLabel.textContent = 'PIN SET · ' + Number(draft.lat).toFixed(4) + '° N ' + Number(draft.lng).toFixed(4) + '° E';
                document.dispatchEvent(new CustomEvent('civicpulse:location-selected', { detail: { lat: Number(draft.lat), lng: Number(draft.lng) } }));
            }
            draftStatus.textContent = 'Saved draft restored in this browser.';
        }
    } catch (draftError) {
        console.warn('CivicPulse draft could not be restored.', draftError);
        draftStatus.textContent = 'A saved draft could not be restored.';
    }

    document.getElementById('save-draft').addEventListener('click', function () {
        var draft = {};
        ['title', 'description', 'location', 'landmark', 'priority'].forEach(function (name) {
            var field = reportForm.elements.namedItem(name);
            draft[name] = field ? field.value : '';
        });
        var checkedCategory = reportForm.querySelector('input[name="category"]:checked');
        draft.category = checkedCategory ? checkedCategory.value : '';
        draft.lat = latInput.value;
        draft.lng = lngInput.value;
        try {
            localStorage.setItem(draftKey, JSON.stringify(draft));
            draftStatus.textContent = 'Draft saved in this browser.';
        } catch (draftError) {
            console.error('CivicPulse draft could not be saved.', draftError);
            draftStatus.textContent = 'Draft could not be saved. Check browser storage settings.';
        }
    });

    reportForm.addEventListener('submit', function () {
        try {
            localStorage.removeItem(draftKey);
        } catch (draftError) {
            console.warn('Submitted draft could not be cleared from this browser.', draftError);
        }
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
