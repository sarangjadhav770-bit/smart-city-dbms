</main>
<footer class="site-footer">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <span><strong>CivicPulse</strong> · Smart City Digital Management System</span>
        <span class="footer-note">Better neighborhoods start with being heard.</span>
    </div>
</footer>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="toast" class="toast border-0 shadow" role="status" aria-live="polite">
        <div class="d-flex"><div class="toast-body" id="toastMsg"></div><button class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php if (!empty($_SESSION['flash'])): ?>
<script>window.addEventListener('DOMContentLoaded',()=>showToast(<?= json_encode($_SESSION['flash'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>));</script>
<?php unset($_SESSION['flash']); endif; ?>
</body>
</html>
