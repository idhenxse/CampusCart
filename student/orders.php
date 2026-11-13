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
// HELPER FUNCTION
// =========================================
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// =========================================
// GET STUDENT ORDERS
// =========================================
$orderStmt = $pdo->prepare("
    SELECT
        o.order_id,
        o.order_number,
        o.order_date,
        o.total_amount,
        o.payment_method,
        o.order_status,
        p.payment_status,
        p.reference_number
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.order_id
    WHERE o.user_id = ?
    ORDER BY o.order_date DESC, o.order_id DESC
");

$orderStmt->execute([$user_id]);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================
// GET PRODUCTS FOR ALL STUDENT ORDERS
// =========================================
$order_items_by_order = [];

if (!empty($orders)) {
    $order_ids = array_map(
        fn($order) => (int) $order['order_id'],
        $orders
    );

    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));

    $itemsStmt = $pdo->prepare("
        SELECT
            oi.order_item_id,
            oi.order_id,
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
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_item_id ASC
    ");

    $itemsStmt->execute($order_ids);
    $all_order_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_order_items as $item) {
        $order_items_by_order[(int) $item['order_id']][] = $item;
    }
}

// =========================================
// DISPLAY HELPERS
// =========================================
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
// PRODUCT DISPLAY HELPER
// =========================================
function getOrderProductName(array $item): string
{
    if (str_starts_with($item['product_name'], 'Tote Bag')) {
        return 'Tote Bag';
    }

    return $item['product_name'];
}

function getOrderVariantText(array $item): string
{
    $parts = [];

    if (!empty($item['size'])) {
        $parts[] = $item['size'];
    }

    if (
        !empty($item['variant_name']) &&
        strtolower($item['variant_name']) !== 'standard' &&
        strtolower($item['variant_name']) !== strtolower($item['size'] ?? '')
    ) {
        $parts[] = $item['variant_name'];
    }

    return implode(' / ', $parts);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Orders | CampusCart</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student.css">
    <link rel="stylesheet" href="../assets/css/orders.css">
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
     MAIN ORDERS CONTENT
========================================= -->
<main class="student-content orders-page-content">

    <section class="orders-heading">

        <div>
            <h2>My Orders</h2>
            <p>View your orders and keep track of their status.</p>
        </div>

        <a href="dashboard.php" class="orders-continue-shopping">
            ← Continue Shopping
        </a>

    </section>

    <?php if (empty($orders)): ?>

        <!-- EMPTY ORDERS -->
        <section class="orders-empty-state">

            <div class="orders-empty-inner">
                <div class="orders-empty-icon">📦</div>

                <h3>Your Orders Are Empty</h3>

                <p>
                    You haven't placed any orders yet.
                    Browse the cooperative store and find what you need!
                </p>
            </div>

            <a href="dashboard.php" class="orders-browse-button">
                BROWSE PRODUCTS
            </a>

        </section>

    <?php else: ?>

        <!-- STUDENT ORDER LIST -->
        <section class="orders-list">

            <?php foreach ($orders as $order): ?>

                <?php
                    $order_id = (int) $order['order_id'];
                    $order_items = $order_items_by_order[$order_id] ?? [];

                    $order_status = (string) $order['order_status'];
                    $payment_status = $order['payment_status'] ?? null;

                    $order_timestamp = strtotime($order['order_date']);

                    $order_date = date('F j, Y', $order_timestamp);
                    $order_time = date('g:i A', $order_timestamp);
                ?>

                <article class="student-order-card">

                    <!-- ORDER HEADER -->
                    <div class="student-order-header">

                        <div class="student-order-heading">

                            <h3>
                                Order #<?= e($order['order_number']) ?>
                            </h3>

                            <p>
                                <?= e($order_date) ?>
                                <span>•</span>
                                <?= e($order_time) ?>
                            </p>

                        </div>

                        <div class="order-status-group">

                            <span class="order-status-badge <?= e(getOrderStatusClass($order_status)) ?>">
                                <?= e(orderStatusLabel($order_status)) ?>
                            </span>

                        </div>

                    </div>

                    <!-- =================================
                         TRUE THREE-COLUMN ORDER LAYOUT
                    ================================== -->
                    <div class="student-order-main-layout">

                        <!-- LEFT COLUMN: PRODUCT IMAGES -->
                        <div class="student-order-image-column">

                            <?php foreach ($order_items as $item): ?>

                                <?php
                                    $product_name = getOrderProductName($item);

                                    $image_path = !empty($item['product_image'])
                                        ? '../' . ltrim($item['product_image'], '/')
                                        : '../assets/images/products/Logo.jpg';
                                ?>

                                <div class="student-order-image-row">

                                    <div class="student-order-product-image">

                                        <?php if ($image_path !== ''): ?>
                                            <img
                                                src="<?= e($image_path) ?>"
                                                alt="<?= e($product_name) ?>"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                            >
                                        <?php endif; ?>

                                        <div
                                            class="order-image-placeholder"
                                            style="<?= $image_path !== '' ? 'display:none;' : '' ?>"
                                        >
                                            Product Image
                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                            <?php if (empty($order_items)): ?>
                                <div class="student-order-image-row">
                                    <div class="student-order-product-image">
                                        <div class="order-image-placeholder">
                                            No Image
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>

                        <!-- MIDDLE COLUMN: PRODUCT INFORMATION AND TOTAL -->
                        <div class="student-order-middle-column">

                            <div class="student-order-products">

                                <?php foreach ($order_items as $item): ?>

                                    <?php
                                        $product_name = getOrderProductName($item);
                                        $variant_text = getOrderVariantText($item);
                                    ?>

                                    <div class="student-order-product-details-row">

                                        <!-- PRODUCT NAME, VARIANT AND QUANTITY -->
                                        <div class="student-order-product-details">

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

                                        <!-- PRODUCT PRICE -->
                                        <div class="student-order-product-price">

                                            <strong>
                                                ₱<?= number_format((float) $item['subtotal'], 2) ?>
                                            </strong>

                                            <small>
                                                ₱<?= number_format((float) $item['unit_price'], 2) ?> each
                                            </small>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                                <?php if (empty($order_items)): ?>
                                    <p class="order-no-items">
                                        No product details are available for this order.
                                    </p>
                                <?php endif; ?>

                            </div>

                            <!-- ORDER TOTAL -->
                            <div class="student-order-total">

                                <span>Total Amount:</span>

                                <strong>
                                    ₱<?= number_format((float) $order['total_amount'], 2) ?>
                                </strong>

                            </div>

                        </div>

                        <!-- RIGHT COLUMN: PAYMENT INFORMATION -->
                        <aside class="student-order-payment-column">

                            <div class="order-information-item">

                                <span>Payment Method:</span>

                                <strong>
                                    <?= e(paymentMethodLabel((string) $order['payment_method'])) ?>
                                </strong>

                            </div>

                            <div class="order-information-item">

                                <span>Payment Status:</span>

                                <strong class="payment-status-badge <?= e(getPaymentStatusClass($payment_status)) ?>">
                                    <?= e(paymentStatusLabel($payment_status)) ?>
                                </strong>

                            </div>

                        </aside>

                    </div>

                    <!-- PICKUP REMINDER AND PAYMENT NOTE -->
                    <div class="student-order-notes">

                        <p>
                            <strong>📍 Pickup Reminder:</strong>
                            Collect your order in front of the PLM Chapel,
                            Tuesdays–Fridays, 8:00 AM–5:00 PM.
                        </p>

                        <p>
                            <strong>💳 Payment Note:</strong>
                            <?= e(getPaymentNote($order)) ?>
                        </p>

                    </div>

                    <!-- VIEW ORDER DETAILS -->
                    <a
                        href="order_details.php?order_id=<?= (int) $order['order_id'] ?>"
                        class="order-details-toggle"
                    >
                        VIEW ORDER DETAILS
                    </a>

                </article>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

</main>

<!-- FOOTER -->
<footer class="student-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<script src="../assets/js/notification.js"></script>
<script src="../assets/js/student-menu.js"></script>

</body>
</html>
