document.addEventListener('DOMContentLoaded', function () {

    const wrapper = document.getElementById('admin-notification-wrapper');
    const trigger = document.getElementById('admin-notification-trigger');
    const panel = document.getElementById('admin-notification-panel');
    const closeButton = document.getElementById('admin-notification-close');

    if (!wrapper || !trigger || !panel || !closeButton) {
        return;
    }

    let markingAsRead = false;

    // =========================================
    // MARK NOTIFICATIONS AS READ
    // =========================================

    async function markNotificationsAsRead() {

        if (markingAsRead) {
            return;
        }

        markingAsRead = true;

        try {

            const response = await fetch('mark_notifications_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    csrf_token: trigger.dataset.csrfToken || ''
                })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error('Unable to mark notifications as read.');
            }

            // Remove the red dot from the notification bell.
            const notificationDot = trigger.querySelector('.admin-notification-dot');

            if (notificationDot) {
                notificationDot.remove();
            }

            // Remove unread styling from notification cards.
            panel.querySelectorAll('.admin-notification-item.unread').forEach(item => {
                item.classList.remove('unread');
                item.classList.add('read');
            });

            // Remove individual unread indicators.
            panel.querySelectorAll('.admin-notification-unread-indicator').forEach(indicator => {
                indicator.remove();
            });

        } catch (error) {

            console.error('Admin notification update failed:', error);

        } finally {

            markingAsRead = false;
        }
    }

    // =========================================
    // OPEN NOTIFICATIONS
    // =========================================

    function openNotifications() {

        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');

        markNotificationsAsRead();
    }

    // =========================================
    // CLOSE NOTIFICATIONS
    // =========================================

    function closeNotifications() {

        panel.classList.remove('active');
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
    }

    // =========================================
    // BELL BUTTON
    // =========================================

    trigger.addEventListener('click', function (event) {

        event.stopPropagation();

        if (panel.classList.contains('active')) {
            closeNotifications();
        } else {
            openNotifications();
        }
    });

    // =========================================
    // CLOSE BUTTON
    // =========================================

    closeButton.addEventListener('click', function (event) {

        event.stopPropagation();

        closeNotifications();
    });

    // =========================================
    // CLICK OUTSIDE TO CLOSE
    // =========================================

    document.addEventListener('click', function (event) {

        if (!wrapper.contains(event.target)) {
            closeNotifications();
        }
    });

    // =========================================
    // ESCAPE KEY TO CLOSE
    // =========================================

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeNotifications();
        }
    });

});
