
document.addEventListener('DOMContentLoaded', function () {

    const menuWrapper = document.getElementById('admin-menu-wrapper');
    const menuTrigger = document.getElementById('admin-menu-trigger');
    const menuPanel = document.getElementById('admin-menu-panel');

    if (!menuWrapper || !menuTrigger || !menuPanel) {
        return;
    }

    // Open and close the menu.
    function openMenu() {
        menuPanel.classList.add('active');
        menuTrigger.setAttribute('aria-expanded', 'true');
        menuPanel.setAttribute('aria-hidden', 'false');
    }

    function closeMenu() {
        menuPanel.classList.remove('active');
        menuTrigger.setAttribute('aria-expanded', 'false');
        menuPanel.setAttribute('aria-hidden', 'true');
    }

    // Toggle menu when the button is clicked.
    menuTrigger.addEventListener('click', function (event) {
        event.stopPropagation();

        if (menuPanel.classList.contains('active')) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    // Close when clicking outside the menu.
    document.addEventListener('click', function (event) {
        if (!menuWrapper.contains(event.target)) {
            closeMenu();
        }
    });

    // Close when pressing Escape.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

});
