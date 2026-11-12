document.addEventListener('DOMContentLoaded', function () {
    const wrapper = document.getElementById('notification-wrapper');
    const trigger = document.getElementById('notification-trigger');
    const panel = document.getElementById('notification-panel');
    const closeButton = document.getElementById('notification-close');

    if (!wrapper || !trigger || !panel || !closeButton) {
        return;
    }

    let markingAsRead = false;

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
            const notificationDot = trigger.querySelector('.notification-dot');

            if (notificationDot) {
                notificationDot.remove();
            }

            // Remove unread styling from individual notifications.
            panel.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
                item.classList.add('read');
            });

            // Remove individual unread indicators.
            panel.querySelectorAll('.notification-unread-indicator').forEach(indicator => {
                indicator.remove();
            });

        } catch (error) {
            console.error('Notification update failed:', error);

        } finally {
            markingAsRead = false;
        }
    }

    function openNotifications() {
        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');

        markNotificationsAsRead();
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
