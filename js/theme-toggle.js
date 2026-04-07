(function () {
    var STORAGE_KEY = 'theme';
    var root = document.documentElement;

    function currentTheme() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function setTheme(theme) {
        if (theme !== 'dark' && theme !== 'light') {
            return;
        }
        root.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {}
        syncButtons();
    }

    function syncButtons() {
        var active = currentTheme();
        document.querySelectorAll('[data-theme-choice]').forEach(function (btn) {
            var choice = btn.getAttribute('data-theme-choice');
            var isOn = choice === active;
            btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
            btn.classList.toggle('is-active', isOn);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncButtons();
        document.querySelectorAll('[data-theme-choice]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setTheme(btn.getAttribute('data-theme-choice'));
            });
        });
    });
})();
