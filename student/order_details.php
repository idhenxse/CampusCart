<?php

session_start();

require_once __DIR__ . '/../config/database.php';

// =========================================
// STUDENT SESSION PROTECTION
// =========================================

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header('Location: ../index.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// =========================================
// HELPER FUNCTIONS
// =========================================

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function orderStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'ready_for_pickup' => 'Ready for Pickup',
        'completed' => 'Completed',
        default => ucwords(str_replace('_', ' ', $status))
    };
}

function paymentStatusLabel(?string $status): string
{
    return match ($status) {
        'pending' => 'Pending Verification',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
        default => 'Not Available'
    };
}

function paymentMethodLabel(string $method): string
{
    return match ($method) {
        'cash_on_pickup' => 'Cash on Pickup',
        'gcash' => 'Online Payment (GCash)',
        default => ucwords(str_replace('_', ' ', $method))
    };
}

function getPaymentNote(array $order): string
{
    if ($order['payment_method'] === 'cash_on_pickup') {
        return 'Please prepare the exact payment amount when collecting your order.';
    }

    return match ($order['payment_status'] ?? '') {
        'pending' => 'Your GCash payment is awaiting verification by the cooperative staff.',
        'paid' => 'Your GCash payment has been verified.',
        'rejected' => 'Your GCash payment was rejected. Please check your notifications for instructions.',
        default => 'Please check your payment information.'
    };
}

function getOrderStatusClass(string $status): string
{
    return match ($status) {
        'pending' => 'status-pending',
        'confirmed' => 'status-confirmed',
        'ready_for_pickup' => 'status-ready',
        'completed' => 'status-completed',
        default => 'status-default'
    };
}

function getPaymentStatusClass(?string $status): string
{
    return match ($status) {
        'pending' => 'payment-pending',
        'paid' => 'payment-paid',
        'rejected' => 'payment-rejected',
        default => 'payment-default'
    };
}

// =========================================
// GET SELECTED ORDER
// =========================================

$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

if (!$order_id || $order_id < 1) {
    header('Location: orders.php');
    exit;
}

$orderStmt = $pdo->prepare("
    SELECT
        o.order_id,
        o.order_number,
        o.order_date,
        o.total_amount,
        o.payment_method,
        o.order_status,
        p.payment_status,
        p.reference_number,
        p.payment_screenshot
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.order_id
    WHERE o.order_id = ?
      AND o.user_id = ?
    LIMIT 1
");

$orderStmt->execute([$order_id, $user_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// =========================================
// GET ORDER ITEMS
// =========================================

$itemsStmt = $pdo->prepare("
    SELECT
        oi.order_item_id,
        oi.quantity,
        oi.unit_price,
        oi.subtotal,
        pv.variant_name,
        pv.size,
        pr.product_name,
        pr.product_image
    FROM order_items oi
    INNER JOIN product_variants pv
        ON pv.variant_id = oi.variant_id
    INNER JOIN products pr
        ON pr.product_id = pv.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.order_item_id ASC
");

$itemsStmt->execute([$order_id]);
$order_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================
// ORDER DATE AND TIME
// =========================================

$order_date = date('F j, Y', strtotime($order['order_date']));
$order_time = date('g:i A', strtotime($order['order_date']));

// =========================================
// ORDER PROGRESS
// =========================================

$progress_steps = [
    [
        'status' => 'pending',
        'title' => 'Pending',
        'description' => 'Your order has been submitted and is awaiting confirmation.'
    ],
    [
        'status' => 'confirmed',
        'title' => 'Confirmed',
        'description' => 'The cooperative staff has confirmed your order.'
    ],
    [
        'status' => 'ready_for_pickup',
        'title' => 'Ready for Pickup',
        'description' => 'Your order is ready. Collect it at the school cooperative.'
    ],
    [
        'status' => 'completed',
        'title' => 'Completed',
        'description' => 'Your order has been successfully completed.'
    ]
];

$status_order = [
    'pending' => 0,
    'confirmed' => 1,
    'ready_for_pickup' => 2,
    'completed' => 3
];

$current_step = $status_order[$order['order_status']] ?? 0;
$is_completed = $order['order_status'] === 'completed';

// =========================================
// PAYMENT SCREENSHOT PATH
// =========================================

$payment_screenshot_path = '';

if (!empty($order['payment_screenshot'])) {
    $payment_screenshot_path = '../' . ltrim($order['payment_screenshot'], '/');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details | CampusCart</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student.css">
    <link rel="stylesheet" href="../assets/css/order_details.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="../assets/css/student-menu.css">
    <link rel="stylesheet" href="../assets/css/dark_theme.css">
    <script src="../assets/js/theme.js"></script>
</head>

<body class="student-page">

<!-- =========================================
     CAMPUSCART HEADER
========================================= -->

<header class="student-header">

    <div class="student-header-brand">
        <img src="../assets/images/plm-logo.png" alt="PLM Logo">

        <div>
            <h1>CampusCart</h1>
            <p>PLM COOPERATIVE STORE</p>
        </div>
    </div>

    <nav class="student-header-actions" aria-label="Student navigation">

        <a href="dashboard.php" class="header-action"
           title="Home" aria-label="Home">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m3 10 9-7 9 7"/>
                <path d="M5 9v12h14V9"/>
                <path d="M9 21v-7h6v7"/>
            </svg>
        </a>

        <a href="cart.php" class="header-action"
           title="Shopping Cart" aria-label="Shopping Cart">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="9" cy="21" r="1"/>
                <circle cx="19" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                <path d="M8 10h12"/>
            </svg>
        </a>

        <a href="orders.php" class="header-action"
           title="My Orders" aria-label="My Orders">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 7h16l1 14H3L4 7Z"/>
                <path d="M8 7a4 4 0 0 1 8 0"/>
                <path d="M9 11h6"/>
            </svg>
        </a>

        <?php require __DIR__ . '/includes/notification_panel.php'; ?>

        <!-- Student Menu -->
        <?php require __DIR__ . '/includes/student_menu.php'; ?>

    </nav>

</header>

<!-- =========================================
     MAIN ORDER DETAILS CONTENT
========================================= -->

<main class="student-content order-details-page">

    <section class="order-details-heading">

        <div class="order-details-heading-text">
            <h2>Order Details</h2>
            <p>View the complete details of your order, including its status, items, and payment information.</p>
        </div>

        <a href="orders.php" class="order-details-back">
            ← Back to My Orders
        </a>

    </section>

    <div class="order-details-layout">

        <!-- LEFT COLUMN -->

        <div class="order-details-main-column">

            <!-- ORDER HEADER -->

            <section class="order-details-card order-summary-header">

                <div>
                    <h3>Order #<?= e($order['order_number']) ?></h3>

                    <p>
                        <?= e($order_date) ?>
                        <span>•</span>
                        <?= e($order_time) ?>
                    </p>
                </div>

                <span class="order-status-badge <?= e(getOrderStatusClass((string) $order['order_status'])) ?>">
                    <?= e(orderStatusLabel((string) $order['order_status'])) ?>
                </span>

            </section>

            <!-- ORDER ITEMS -->

            <section class="order-details-card order-items-card">

                <h3 class="order-section-title">Order Items</h3>

                <div class="order-details-items">

                    <?php foreach ($order_items as $item): ?>

                        <?php
                            $product_name = $item['product_name'];

                            if (str_starts_with($product_name, 'Tote Bag')) {
                                $product_name = 'Tote Bag';
                            }

                            $variant_parts = [];

                            if (!empty($item['size'])) {
                                $variant_parts[] = $item['size'];
                            }

                            if (
                                !empty($item['variant_name']) &&
                                strtolower($item['variant_name']) !== 'standard' &&
                                strtolower($item['variant_name']) !== strtolower($item['size'] ?? '')
                            ) {
                                $variant_parts[] = $item['variant_name'];
                            }

                            $variant_text = implode(' / ', $variant_parts);

                            $image_path = '';

                            if (!empty($item['product_image'])) {
                                $image_path = '../' . ltrim($item['product_image'], '/');
                            }
                        ?>

                        <article class="order-details-product">

                            <div class="order-details-product-image">

                                <?php if ($image_path !== ''): ?>

                                    <img
                                        src="<?= e($image_path) ?>"
                                        alt="<?= e($product_name) ?>"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                    >

                                <?php endif; ?>

                                <div
                                    class="order-details-image-placeholder"
                                    style="<?= $image_path !== '' ? 'display:none;' : '' ?>"
                                >
                                    Product Image
                                </div>

                            </div>

                            <div class="order-details-product-info">

                                <h4><?= e($product_name) ?></h4>

                                <?php if ($variant_text !== ''): ?>
                                    <p>
                                        <strong>Variant:</strong>
                                        <?= e($variant_text) ?>
                                    </p>
                                <?php endif; ?>

                                <p>
                                    <strong>Quantity:</strong>
                                    <?= (int) $item['quantity'] ?>
                                </p>

                            </div>

                            <div class="order-details-product-price">

                                <strong>
                                    ₱<?= number_format((float) $item['subtotal'], 2) ?>
                                </strong>

                                <small>
                                    ₱<?= number_format((float) $item['unit_price'], 2) ?> each
                                </small>

                            </div>

                        </article>

                    <?php endforeach; ?>

                    <?php if (empty($order_items)): ?>
                        <p class="order-details-empty">
                            No product details are available for this order.
                        </p>
                    <?php endif; ?>

                </div>

                <div class="order-details-total">

                    <span>Total Amount:</span>

                    <strong>
                        ₱<?= number_format((float) $order['total_amount'], 2) ?>
                    </strong>

                </div>

            </section>

            <!-- ORDER PROGRESS -->

            <section class="order-details-card order-progress-card">

                <h3 class="order-section-title">Order Progress</h3>

                <div class="order-progress-timeline">

                    <?php foreach ($progress_steps as $index => $step): ?>

                        <?php
                            $step_is_done = $is_completed || $index < $current_step;
                            $step_is_current = !$is_completed && $index === $current_step;
                        ?>

                        <div class="order-progress-step
                            <?= $step_is_done ? 'is-done' : '' ?>
                            <?= $step_is_current ? 'is-current' : '' ?>">

                            <div class="order-progress-marker">

                                <?php if ($step_is_done): ?>
                                    <span>✓</span>
                                <?php endif; ?>

                            </div>

                            <div class="order-progress-description">

                                <h4><?= e($step['title']) ?></h4>
                                <p><?= e($step['description']) ?></p>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <div class="order-progress-reminder">

                    <span class="order-reminder-icon">ⓘ</span>

                    <p>
                        Please wait until your order status changes to
                        <strong>Ready for Pickup</strong> before going to school
                        to claim your order (to avoid any inconvenience).
                    </p>

                </div>

            </section>

        </div>

        <!-- RIGHT COLUMN -->

        <aside class="order-details-sidebar">

            <!-- PAYMENT INFORMATION -->

            <section class="order-details-card order-sidebar-card">

                <h3 class="order-section-title">
                    <span class="order-sidebar-icon">▤</span>
                    Payment Information
                </h3>

                <div class="order-payment-row">

                    <span>Payment Method</span>

                    <strong>
                        <?= e(paymentMethodLabel((string) $order['payment_method'])) ?>
                    </strong>

                </div>

                <div class="order-payment-row">

                    <span>Payment Status</span>

                    <strong class="payment-status-badge <?= e(getPaymentStatusClass($order['payment_status'] ?? null)) ?>">
                        <?= e(paymentStatusLabel($order['payment_status'] ?? null)) ?>
                    </strong>

                </div>

                <?php if ($order['payment_method'] === 'gcash'): ?>

                    <?php if (!empty($order['reference_number'])): ?>

                        <div class="order-gcash-reference">

                            <span>GCash Reference Number</span>
                            <strong><?= e($order['reference_number']) ?></strong>

                        </div>

                    <?php endif; ?>

                    <div class="order-gcash-screenshot">

                        <h4>GCash Payment Screenshot</h4>

                        <?php if ($payment_screenshot_path !== ''): ?>

                            <button
                                type="button"
                                class="order-screenshot-link"
                                id="open-payment-screenshot"
                                data-image="<?= e($payment_screenshot_path) ?>"
                                aria-label="View GCash payment screenshot"
                            >
                                <img
                                    src="<?= e($payment_screenshot_path) ?>"
                                    alt="GCash payment screenshot"
                                >

                                <span>Click the image to view it in full size.</span>
                            </button>

                        <?php else: ?>

                            <p class="order-screenshot-unavailable">
                                No payment screenshot is available.
                            </p>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

                <div class="order-payment-note">

                    <span class="order-note-icon">ⓘ</span>

                    <p><?= e(getPaymentNote($order)) ?></p>

                </div>

            </section>

            <!-- PICKUP INFORMATION -->

            <section class="order-details-card order-sidebar-card">

                <h3 class="order-section-title">
                    <span class="order-sidebar-icon">⌖</span>
                    Pickup Information
                </h3>

                <p class="order-pickup-description">
                    All CampusCart orders must be collected at the school cooperative.
                    There is no delivery service.
                </p>

                <div class="order-pickup-details">
                    <p>
                        <strong>📍 Location:</strong>
                        <span>In front of the PLM Chapel</span>
                    </p>

                    <p>
                        <strong>📅 Operating Days:</strong>
                        <span>Tuesdays–Fridays</span>
                    </p>

                    <p>
                        <strong>🕒 Operating Hours:</strong>
                        <span>8:00 AM–5:00 PM</span>
                    </p>
                </div>

            </section>

        </aside>

    </div>

</main>

<!-- GCASH PAYMENT SCREENSHOT MODAL -->

<div class="payment-image-modal" id="payment-image-modal" hidden>

    <div class="payment-image-modal-content">

        <div class="payment-image-modal-header">

            <h3>Payment Screenshot</h3>

            <button
                type="button"
                class="payment-image-modal-close"
                id="close-payment-image-modal"
                aria-label="Close payment screenshot"
            >
                ×
            </button>

        </div>

        <div class="payment-image-modal-body">

            <img
                src=""
                alt="Full-size GCash payment screenshot"
                id="payment-image-preview"
            >

        </div>

    </div>

</div>

<footer class="student-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const openButton = document.getElementById('open-payment-screenshot');
    const modal = document.getElementById('payment-image-modal');
    const preview = document.getElementById('payment-image-preview');
    const closeButton = document.getElementById('close-payment-image-modal');

    if (!openButton || !modal || !preview || !closeButton) {
        return;
    }

    function openPaymentScreenshot() {

        const imagePath = openButton.dataset.image;

        if (!imagePath) {
            return;
        }

        preview.src = imagePath;
        modal.hidden = false;
        document.body.classList.add('modal-open');

    }

    function closePaymentScreenshot() {

        modal.hidden = true;
        preview.src = '';
        document.body.classList.remove('modal-open');

    }

    openButton.addEventListener('click', openPaymentScreenshot);
    closeButton.addEventListener('click', closePaymentScreenshot);

    modal.addEventListener('click', function (event) {

        if (event.target === modal) {
            closePaymentScreenshot();
        }

    });

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape' && !modal.hidden) {
            closePaymentScreenshot();
        }

    });

});

</script>

<script src="../assets/js/notification.js"></script>
<script src="../assets/js/student-menu.js"></script>

</body>
</html>
