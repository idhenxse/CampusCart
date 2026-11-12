document.addEventListener('DOMContentLoaded', function () {
    const wrapper = document.getElementById('notification-wrapper');
    const trigger = document.getElementById('notification-trigger');
    const panel = document.getElementById('notification-panel');
    const closeButton = document.getElementById('notification-close');

    if (!wrapper || !trigger || !panel || !closeButton) {
        return;
    }

    function openNotifications() {
        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
    }

    function closeNotifications() {
        panel.classList.remove('active');
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (event) {
        event.stopPropagation();

        if (panel.classList.contains('active')) {
            closeNotifications();
        } else {
            openNotifications();
        }
    });

    closeButton.addEventListener('click', function (event) {
        event.stopPropagation();
        closeNotifications();
    });

    document.addEventListener('click', function (event) {
        if (!wrapper.contains(event.target)) {
            closeNotifications();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeNotifications();
        }
    });
});
