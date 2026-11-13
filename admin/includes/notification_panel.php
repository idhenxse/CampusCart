<?php
// =========================================
// CAMPUSCART ADMIN NOTIFICATION PANEL
// =========================================

// Make sure the CSRF token exists.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get the logged-in Admin ID.
$admin_id = (int) $_SESSION['user_id'];

// Default values.
$admin_notifications = [];
$admin_unread_notifications = 0;

try {

    // Get the Admin's latest 10 notifications.
    $notification_stmt = $pdo->prepare("
        SELECT
            notification_id,
            title,
            message,
            notification_type,
            is_read,
            created_at
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY created_at DESC, notification_id DESC
        LIMIT 10
    ");

    $notification_stmt->execute([
        ':user_id' => $admin_id
    ]);

    $admin_notifications = $notification_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count unread notifications.
    $unread_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = :user_id
        AND is_read = 0
    ");

    $unread_stmt->execute([
        ':user_id' => $admin_id
    ]);

    $admin_unread_notifications = (int) $unread_stmt->fetchColumn();

} catch (PDOException $exception) {

    error_log(
        'Admin notification panel error: ' . $exception->getMessage()
    );
}
?>

<div class="admin-notification-wrapper" id="admin-notification-wrapper">

    <!-- NOTIFICATION BELL -->
    <button
        type="button"
        class="admin-header-icon admin-notification-trigger"
        id="admin-notification-trigger"
        title="Notifications"
        aria-label="Notifications"
        data-csrf-token="<?= htmlspecialchars(
            $_SESSION['csrf_token'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        aria-expanded="false"
        aria-controls="admin-notification-panel"
    >

        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
            <path d="M10 21h4"/>
        </svg>

        <?php if ($admin_unread_notifications > 0): ?>
            <span
                class="admin-notification-dot"
                id="admin-notification-dot"
            ></span>
        <?php endif; ?>

    </button>


    <!-- NOTIFICATION POPUP -->
    <div
        class="admin-notification-panel"
        id="admin-notification-panel"
        role="dialog"
        aria-label="Admin Notifications"
        aria-hidden="true"
    >

        <!-- PANEL HEADER -->
        <div class="admin-notification-panel-header">

            <h3>Notifications</h3>

            <button
                type="button"
                class="admin-notification-close"
                id="admin-notification-close"
                aria-label="Close notifications"
            >
                &times;
            </button>

        </div>


        <!-- NOTIFICATION LIST -->
        <div
            class="admin-notification-list"
            id="admin-notification-list"
        >

            <?php if (empty($admin_notifications)): ?>

                <div class="admin-notification-empty">
                    <p>No notifications yet.</p>
                    <span>You're all caught up!</span>
                </div>

            <?php else: ?>

                <?php foreach ($admin_notifications as $notification): ?>

                    <article
                        class="admin-notification-item <?= (int) $notification['is_read'] === 0 ? 'unread' : 'read' ?>"
                        data-notification-id="<?= (int) $notification['notification_id'] ?>"
                    >

                        <div class="admin-notification-item-content">

                            <h4>
                                <?= htmlspecialchars(
                                    $notification['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h4>

                            <p>
                                <?= htmlspecialchars(
                                    $notification['message'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>

                            <time>
                                <?= date(
                                    'M j, Y · g:i A',
                                    strtotime($notification['created_at'])
                                ) ?>
                            </time>

                        </div>

                        <?php if ((int) $notification['is_read'] === 0): ?>

                            <span
                                class="admin-notification-unread-indicator"
                                aria-label="Unread notification"
                            ></span>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

</div>
