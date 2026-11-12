<?php
// =========================================
// CAMPUSCART STUDENT NOTIFICATION PANEL
// =========================================

// Make sure a CSRF token exists.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get the student's latest notifications.
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
    ':user_id' => (int) $_SESSION['user_id']
]);

$student_notifications = $notification_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications.
// The red dot appears only when this count is greater than zero.
$unread_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id = :user_id
    AND is_read = 0
");

$unread_stmt->execute([
    ':user_id' => (int) $_SESSION['user_id']
]);

$unread_notifications = (int) $unread_stmt->fetchColumn();
?>

<div class="notification-wrapper" id="notification-wrapper">

    <!-- BELL BUTTON -->
    <button
        type="button"
        class="header-action notification-trigger"
        id="notification-trigger"
        title="Notifications"
        aria-label="Notifications"
        data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
        aria-expanded="false"
        aria-controls="notification-panel"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
            <path d="M10 21h4"/>
        </svg>

        <?php if ($unread_notifications > 0): ?>
            <span class="notification-dot" id="notification-dot"></span>
        <?php endif; ?>
    </button>

    <!-- NOTIFICATION POPUP -->
    <div
        class="notification-panel"
        id="notification-panel"
        role="dialog"
        aria-label="Student Notifications"
        aria-hidden="true"
    >

        <div class="notification-panel-header">
            <h3>Notifications</h3>

            <button
                type="button"
                class="notification-close"
                id="notification-close"
                aria-label="Close notifications"
            >
                &times;
            </button>
        </div>

        <div class="notification-list" id="notification-list">

            <?php if (empty($student_notifications)): ?>

                <div class="notification-empty">
                    <p>No notifications yet.</p>
                    <span>You're all caught up!</span>
                </div>

            <?php else: ?>

                <?php foreach ($student_notifications as $notification): ?>

                    <article
                        class="notification-item <?= (int) $notification['is_read'] === 0 ? 'unread' : 'read' ?>"
                        data-notification-id="<?= (int) $notification['notification_id'] ?>"
                    >

                        <div class="notification-item-content">

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
                            <span class="notification-unread-indicator"
                                  aria-label="Unread notification"></span>
                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

</div>
