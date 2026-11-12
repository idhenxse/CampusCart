<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// =========================================
// ADMIN SESSION PROTECTION
// =========================================
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

// =========================================
// ACCEPT POST REQUESTS ONLY
// =========================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

// =========================================
// CSRF VALIDATION
// =========================================
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    header('Location: dashboard.php?error=invalid_request');
    exit;
}

// =========================================
// VALIDATE ORDER DETAILS
// =========================================
$order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$new_status = $_POST['order_status'] ?? '';

$allowed_statuses = [
    'pending',
    'confirmed',
    'ready_for_pickup',
    'completed'
];

if (
    !$order_id ||
    !in_array($new_status, $allowed_statuses, true)
) {
    header('Location: dashboard.php?error=invalid_status');
    exit;
}

try {
    $pdo->beginTransaction();

    // Get the order and its student.
    $orderStmt = $pdo->prepare("
        SELECT order_id, user_id, order_status
        FROM orders
        WHERE order_id = ?
        FOR UPDATE
    ");

    $orderStmt->execute([$order_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        header('Location: dashboard.php?error=order_not_found');
        exit;
    }

    $old_status = $order['order_status'];

    // Update only when the status has changed.
    if ($old_status !== $new_status) {

        $updateStmt = $pdo->prepare("
            UPDATE orders
            SET order_status = ?, updated_at = NOW()
            WHERE order_id = ?
        ");

        $updateStmt->execute([
            $new_status,
            $order_id
        ]);

        // Prepare the student notification.
        $status_labels = [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed' => 'Completed'
        ];

        $notification_title = 'Order Status Updated';

        $notification_message =
            'Your order status has been updated to ' .
            $status_labels[$new_status] . '.';

        $notificationStmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id,
                title,
                message,
                notification_type,
                related_id,
                is_read,
                created_at
            )
            VALUES (?, ?, ?, 'order_status', ?, 0, NOW())
        ");

        $notificationStmt->execute([
            $order['user_id'],
            $notification_title,
            $notification_message,
            $order_id
        ]);
    }

    $pdo->commit();

    header('Location: dashboard.php?status_updated=1');
    exit;

} catch (PDOException $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Order status update error: ' . $exception->getMessage());

    header('Location: dashboard.php?error=update_failed');
    exit;
}
