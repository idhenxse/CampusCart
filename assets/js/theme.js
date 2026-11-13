
/* =========================================
   CAMPUSCART - SHARED THEME
   Saves and applies the selected theme
   for Student and Admin pages
========================================= */

(function () {
    const THEME_KEY = 'campuscart_theme';
    const DARK_THEME = 'dark';
    const LIGHT_THEME = 'light';

    // Apply the selected theme
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);

        const themeToggles = document.querySelectorAll(
            '#student-theme-toggle, .admin-theme-toggle'
        );

        themeToggles.forEach(function (themeToggle) {
            const isDark = theme === DARK_THEME;

            themeToggle.setAttribute(
                'aria-pressed',
                isDark ? 'true' : 'false'
            );

            themeToggle.setAttribute(
                'aria-label',
                isDark ? 'Switch to light theme' : 'Switch to dark theme'
            );
        });
    }

    // Load saved theme. Light is the default.
    let savedTheme = LIGHT_THEME;

    try {
        const storedTheme = localStorage.getItem(THEME_KEY);

        if (storedTheme === DARK_THEME || storedTheme === LIGHT_THEME) {
            savedTheme = storedTheme;
        }
    } catch (error) {
        console.warn('Unable to access saved theme preference.');
    }

    // Apply theme immediately
    applyTheme(savedTheme);

    // Connect the theme toggle buttons
    document.addEventListener('DOMContentLoaded', function () {
        const themeToggles = document.querySelectorAll(
            '#student-theme-toggle, .admin-theme-toggle'
        );

        themeToggles.forEach(function (themeToggle) {
            themeToggle.addEventListener('click', function () {
                const currentTheme =
                    document.documentElement.getAttribute('data-theme');

                const newTheme =
                    currentTheme === DARK_THEME ? LIGHT_THEME : DARK_THEME;

                applyTheme(newTheme);

                try {
                    localStorage.setItem(THEME_KEY, newTheme);
                } catch (error) {
                    console.warn('Unable to save theme preference.');
                }
            });
        });
    });
})();
