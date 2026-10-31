function showToast(message) {
    var toast = document.getElementById('toast');
    var body = document.getElementById('toastMsg');
    if (!toast || !body || !window.bootstrap) return;
    body.textContent = message;
    window.bootstrap.Toast.getOrCreateInstance(toast).show();
}

document.addEventListener('DOMContentLoaded', function () {
    var themeButton = document.getElementById('themeBtn');
    if (themeButton) {
        themeButton.addEventListener('click', function () {
            var nextTheme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', nextTheme);
            try {
                localStorage.setItem('theme', nextTheme);
            } catch (error) {
                console.warn('Theme preference could not be saved.', error);
            }
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) return;
            var showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            button.setAttribute('aria-pressed', showing ? 'true' : 'false');
            button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !showing);
                icon.classList.toggle('fa-eye-slash', showing);
            }
        });
    });

    document.querySelectorAll('[data-count]').forEach(function (element) {
        var target = Number(element.getAttribute('data-count')) || 0;
        var current = 0;
        var step = Math.max(1, Math.ceil(target / 35));
        var timer = window.setInterval(function () {
            current = Math.min(target, current + step);
            element.textContent = current.toLocaleString();
            if (current >= target) window.clearInterval(timer);
        }, 24);
    });

    var query = document.getElementById('q');
    var statusFilter = document.getElementById('fs');
    var complaintRows = document.querySelectorAll('.row-item');
    function filterRows() {
        complaintRows.forEach(function (row) {
            var matchesText = !query || row.textContent.toLowerCase().indexOf(query.value.toLowerCase()) !== -1;
            var matchesStatus = !statusFilter || !statusFilter.value || row.getAttribute('data-s') === statusFilter.value;
            row.hidden = !(matchesText && matchesStatus);
        });
    }
    if (query) query.addEventListener('input', filterRows);
    if (statusFilter) statusFilter.addEventListener('change', filterRows);
});
