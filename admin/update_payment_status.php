<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// ADMIN SESSION PROTECTION
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

// ACCEPT POST REQUESTS ONLY
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

// CSRF VALIDATION
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    header('Location: dashboard.php?error=invalid_request');
    exit;
}

// VALIDATE PAYMENT DETAILS
$order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$new_status = $_POST['payment_status'] ?? '';

$allowed_statuses = ['paid', 'rejected'];

if (
    !$order_id ||
    !in_array($new_status, $allowed_statuses, true)
) {
    header('Location: dashboard.php?error=invalid_payment_status');
    exit;
}

try {
    $pdo->beginTransaction();

    // Find and lock the payment and its order.
    $paymentStmt = $pdo->prepare("
        SELECT
            p.payment_id,
            p.payment_status,
            o.user_id,
            o.payment_method,
            o.order_number
        FROM payments p
        INNER JOIN orders o
            ON o.order_id = p.order_id
        WHERE p.order_id = ?
        FOR UPDATE
    ");

    $paymentStmt->execute([$order_id]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $pdo->rollBack();
        header('Location: dashboard.php?error=payment_not_found');
        exit;
    }

    // Only GCash payments can be verified here.
    if ($payment['payment_method'] !== 'gcash') {
        $pdo->rollBack();
        header('Location: dashboard.php?error=invalid_payment_method');
        exit;
    }

    // Only pending payments can be verified.
    if ($payment['payment_status'] !== 'pending') {
        $pdo->rollBack();
        header('Location: dashboard.php?error=payment_already_processed');
        exit;
    }

    // Update payment verification details.
    $updateStmt = $pdo->prepare("
        UPDATE payments
        SET
            payment_status = ?,
            verified_by = ?,
            verified_at = NOW(),
            updated_at = NOW()
        WHERE payment_id = ?
    ");

    $updateStmt->execute([
        $new_status,
        (int) $_SESSION['user_id'],
        $payment['payment_id']
    ]);

    // Notify the student when payment is rejected.
    if ($new_status === 'rejected') {
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
            VALUES (?, ?, ?, 'payment_rejected', ?, 0, NOW())
        ");

        $notificationStmt->execute([
            $payment['user_id'],
            'GCash Payment Rejected',
            'Your GCash payment for order ' .
                $payment['order_number'] .
                ' was rejected. Please contact the cooperative for assistance.',
            $order_id
        ]);
    }

    $pdo->commit();

    header('Location: dashboard.php?payment_updated=1');
    exit;

} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Payment verification error: ' . $exception->getMessage());

    header('Location: dashboard.php?error=payment_update_failed');
    exit;
}
