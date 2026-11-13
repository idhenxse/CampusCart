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

$admin_id = (int) $_SESSION['user_id'];

// =========================================
// CSRF TOKEN
// =========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =========================================
// HELPER
// =========================================
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

// Load the Admin's name from the database.
$nameStmt = $pdo->prepare(
    "SELECT full_name FROM users WHERE user_id = ? AND role = 'admin'"
);
$nameStmt->execute([$admin_id]);
$admin = $nameStmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$admin_name = $admin['full_name'];


// =========================================
// WALK-IN SALES PRODUCT OPTIONS
// =========================================
$walkinProducts = [];

try {
    $walkinStmt = $pdo->query("
        SELECT
            p.product_id,
            p.product_name,
            pv.variant_id,
            pv.variant_name,
            pv.size,
            pv.price,
            i.stock_quantity

        FROM products p

        INNER JOIN product_variants pv
            ON pv.product_id = p.product_id

        INNER JOIN inventory i
            ON i.variant_id = pv.variant_id

        WHERE p.is_archived = 0
          AND i.stock_quantity > 0

        ORDER BY
            p.product_name,
            pv.variant_name,
            pv.size
    ");

    $walkinProducts = $walkinStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log('Walk-in product loading error: ' . $exception->getMessage());
}


// =========================================
// DASHBOARD SUMMARY
// =========================================
$summary = [
    'total_products' => 0,
    'total_stocks' => 0,
    'total_sales' => 0,
    'total_orders' => 0,
    'pending_orders' => 0,
    'ready_orders' => 0,
    'completed_orders' => 0
];

try {
    // Total products.
    $summary['total_products'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM products"
    )->fetchColumn();

    // Total units currently in inventory.
    $summary['total_stocks'] = (int) $pdo->query(
        "SELECT COALESCE(SUM(stock_quantity), 0) FROM inventory"
    )->fetchColumn();

    // Online order counts.
    $orderCounts = $pdo->query("
        SELECT
            COUNT(*) AS total_orders,
            SUM(order_status = 'pending') AS pending_orders,
            SUM(order_status = 'ready_for_pickup') AS ready_orders,
            SUM(order_status = 'completed') AS completed_orders
        FROM orders
    ")->fetch(PDO::FETCH_ASSOC);

    $summary['total_orders'] = (int) ($orderCounts['total_orders'] ?? 0);
    $summary['pending_orders'] = (int) ($orderCounts['pending_orders'] ?? 0);
    $summary['ready_orders'] = (int) ($orderCounts['ready_orders'] ?? 0);
    $summary['completed_orders'] = (int) ($orderCounts['completed_orders'] ?? 0);

    // Total sales from completed online orders and recorded walk-in sales.
    $onlineSales = (float) $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM orders
        WHERE order_status = 'completed'
    ")->fetchColumn();

    $walkinSales = (float) $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM walkin_sales
    ")->fetchColumn();

    $summary['total_sales'] = $onlineSales + $walkinSales;

    // =====================================
    // RECENT ONLINE ORDERS
    // =====================================
    $ordersStmt = $pdo->query("
        SELECT
            o.order_id,
            o.order_number,
            o.order_date,
            o.total_amount,
            o.payment_method,
            o.order_status,
            u.full_name,
            u.student_number,
            p.payment_status,
            p.reference_number,
            p.payment_screenshot,

            (
                SELECT GROUP_CONCAT(
                    CONCAT(
                        pr.product_name,
                        CASE
                            WHEN pv.size IS NOT NULL AND pv.size <> ''
                            THEN CONCAT(' (', pv.size, ')')
                            ELSE ''
                        END,
                        ' × ',
                        oi.quantity
                    )
                    SEPARATOR ', '
                )
                FROM order_items oi
                INNER JOIN product_variants pv
                    ON pv.variant_id = oi.variant_id
                INNER JOIN products pr
                    ON pr.product_id = pv.product_id
                WHERE oi.order_id = o.order_id
            ) AS ordered_items

        FROM orders o

        INNER JOIN users u
            ON u.user_id = o.user_id

        LEFT JOIN payments p
            ON p.order_id = o.order_id

        ORDER BY o.order_date DESC, o.order_id DESC
        LIMIT 10
    ");

    $recent_orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log('Admin dashboard error: ' . $exception->getMessage());
    $load_error = true;
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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | CampusCart</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/admin_menu.css">
    <link rel="stylesheet" href="../assets/css/admin_dark_theme.css">
</head>

<body class="admin-page">

<header class="admin-header">

    <div class="admin-brand">
        <img src="../assets/images/plm-logo.png" alt="PLM Logo">

        <div>
            <h1>CampusCart</h1>
            <p>PLM COOPERATIVE STORE</p>
        </div>
    </div>

<nav class="admin-header-actions" aria-label="Admin navigation">

    <!-- Dashboard -->
    <a href="dashboard.php" class="admin-header-icon"
       title="Dashboard" aria-label="Dashboard">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 10.5 12 3l9 7.5"/>
            <path d="M5 9.5V21h14V9.5M9 21v-7h6v7"/>
        </svg>
    </a>

    <!-- Products -->
    <a href="products.php" class="admin-header-icon"
       title="Products" aria-label="Products">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 8h18l-1 13H4L3 8Z"/>
            <path d="M8 8a4 4 0 0 1 8 0"/>
        </svg>
    </a>

    <!-- Inventory -->
    <a href="inventory.php" class="admin-header-icon"
       title="Inventory" aria-label="Inventory">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="5" y="3" width="14" height="18" rx="2"/>
            <path d="M9 8h6M9 12h6M9 16h3"/>
        </svg>
    </a>

    <!-- Notifications -->
    <a href="notifications.php" class="admin-header-icon"
       title="Notifications" aria-label="Notifications">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
            <path d="M10 21h4"/>
        </svg>
    </a>

    <?php include __DIR__ . '/includes/admin_menu.php'; ?>

</nav>

</header>

<main class="admin-content">

    <section class="admin-welcome-banner">
        <h2>CampusCart Administration</h2>
        <p>Welcome, <?= e($admin_name) ?>!</p>
    </section>

    <?php if (!empty($load_error)): ?>
        <div class="admin-alert admin-alert-error">
            Some dashboard information could not be loaded.
            Please check the database connection.
        </div>
    <?php endif; ?>

    <!-- DASHBOARD SUMMARY -->
    <section class="admin-summary-grid admin-dashboard-summary-grid">

        <article class="admin-summary-card">
            <h3>Total Products</h3>
            <div class="admin-summary-value">
                <?= number_format($summary['total_products']) ?>
            </div>
        </article>

        <article class="admin-summary-card">
            <h3>Stocks</h3>
            <div class="admin-summary-value">
                <?= number_format($summary['total_stocks']) ?>
            </div>
        </article>

        <article class="admin-summary-card">
            <h3>Total Sales</h3>
            <div class="admin-summary-value">
                <?= e(peso($summary['total_sales'])) ?>
            </div>
        </article>

    </section>

    <!-- ONLINE ORDERS -->
    <section class="admin-panel" id="online-orders">

        <h2 class="admin-panel-title">Online Orders</h2>

        <div class="admin-order-summary-grid">

            <article class="admin-order-summary-card">
                <h3>Total Orders</h3>
                <strong><?= number_format($summary['total_orders']) ?></strong>
            </article>

            <article class="admin-order-summary-card">
                <h3>Pending Orders</h3>
                <strong><?= number_format($summary['pending_orders']) ?></strong>
            </article>

            <article class="admin-order-summary-card">
                <h3>Ready for Pickup</h3>
                <strong><?= number_format($summary['ready_orders']) ?></strong>
            </article>

            <article class="admin-order-summary-card">
                <h3>Completed Orders</h3>
                <strong><?= number_format($summary['completed_orders']) ?></strong>
            </article>

        </div>

        <form class="admin-order-filters" method="GET">

            <input
                type="search"
                name="search"
                placeholder="Search order or student"
                value="<?= e($_GET['search'] ?? '') ?>"
            >

            <select name="status" onchange="this.form.submit()">
                <option value="">All Order Statuses</option>

                <option value="pending"
                    <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>
                    Pending
                </option>

                <option value="confirmed"
                    <?= ($_GET['status'] ?? '') === 'confirmed' ? 'selected' : '' ?>>
                    Confirmed
                </option>

                <option value="ready_for_pickup"
                    <?= ($_GET['status'] ?? '') === 'ready_for_pickup' ? 'selected' : '' ?>>
                    Ready for Pickup
                </option>

                <option value="completed"
                    <?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>>
                    Completed
                </option>
            </select>

            <button type="submit">Search</button>

        </form>

        <div class="admin-table-wrapper">

            <table class="admin-orders-table">

                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Student No.</th>
                        <th>Ordered Items</th>
                        <th>Total</th>
                        <th>Payment Details</th>
                        <th>Payment Status</th>
                        <th>Order Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php $displayed_orders = 0; ?>
                    
                    <?php if (empty($recent_orders)): ?>

                        <tr>
                            <td colspan="7" class="admin-empty-table">
                                No online orders have been placed yet.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recent_orders as $order): ?>

                            <?php
                            $searchText = strtolower(
                                $order['order_number'] . ' ' .
                                $order['student_number'] . ' ' .
                                $order['full_name'] . ' ' .
                                ($order['ordered_items'] ?? '')
                            );

                            $searchQuery = strtolower(trim($_GET['search'] ?? ''));
                            $selectedStatus = $_GET['status'] ?? '';

                            if (
                                ($searchQuery !== '' &&
                                 !str_contains($searchText, $searchQuery)) ||
                                ($selectedStatus !== '' &&
                                 $order['order_status'] !== $selectedStatus)
                            ) {
                                continue;
                            }
                            ?>

                            <?php $displayed_orders++; ?>

                            <tr>

                                <td>
                                    <strong><?= e($order['order_number']) ?></strong>
                                    <small><?= e(date('M j, Y g:i A', strtotime($order['order_date']))) ?></small>
                                </td>

                                <td>
                                    <?= e($order['student_number'] ?? '—') ?>
                                    <small><?= e($order['full_name']) ?></small>
                                </td>

                                <td>
                                    <?= e($order['ordered_items'] ?? 'No item details') ?>
                                </td>

                                <td class="admin-table-total">
                                    <?= e(peso($order['total_amount'])) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= $order['payment_method'] === 'gcash'
                                            ? 'GCash'
                                            : 'Cash on Pickup' ?>
                                    </strong>

                                    <?php if ($order['payment_method'] === 'gcash'): ?>

                                            <small class="admin-reference-number">
                                                Ref: <?= e($order['reference_number'] ?? 'Not provided') ?>
                                            </small>

                                        <?php if (!empty($order['payment_screenshot'])): ?>
                                            <button
                                                type="button"
                                                class="admin-screenshot-link"
                                                data-image="../<?= e($order['payment_screenshot']) ?>"
                                                data-reference="<?= e($order['reference_number'] ?? 'Not provided') ?>"
                                            >
                                                View Screenshot
                                            </button>
                                        <?php endif; ?>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="admin-status-badge payment-<?= e($order['payment_status'] ?? 'unknown') ?>">
                                        <?= e(paymentStatusLabel($order['payment_status'])) ?>
                                    </span>

                                    <?php if (
                                        $order['payment_method'] === 'gcash' &&
                                        $order['payment_status'] === 'pending'
                                    ): ?>
                                        <div class="admin-payment-actions">

                                            <form method="POST" action="update_payment_status.php">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="order_id"
                                                    value="<?= (int) $order['order_id'] ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="payment_status"
                                                    value="paid"
                                                >
                                                <button
                                                    type="submit"
                                                    class="admin-payment-approve"
                                                    onclick="return confirm('Are you sure you want to approve this GCash payment?');"
                                                >
                                                    Approve
                                                </button>
                                            </form>

                                            <form method="POST" action="update_payment_status.php">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="order_id"
                                                    value="<?= (int) $order['order_id'] ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="payment_status"
                                                    value="rejected"
                                                >
                                                <button
                                                    type="submit"
                                                    class="admin-payment-reject"
                                                    onclick="return confirm('Are you sure you want to reject this GCash payment?');"
                                                >
                                                    Reject
                                                </button>
                                            </form>

                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <form method="POST" action="update_order_status.php"
                                        class="admin-order-status-form">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="order_id"
                                            value="<?= (int) $order['order_id'] ?>"
                                        >

                                        <select
                                            name="order_status"
                                            class="admin-order-status-select"
                                            onchange="this.form.submit()"
                                        >
                                            <option value="pending"
                                                <?= $order['order_status'] === 'pending' ? 'selected' : '' ?>>
                                                Pending
                                            </option>

                                            <option value="confirmed"
                                                <?= $order['order_status'] === 'confirmed' ? 'selected' : '' ?>>
                                                Confirmed
                                            </option>

                                            <option value="ready_for_pickup"
                                                <?= $order['order_status'] === 'ready_for_pickup' ? 'selected' : '' ?>>
                                                Ready for Pickup
                                            </option>

                                            <option value="completed"
                                                <?= $order['order_status'] === 'completed' ? 'selected' : '' ?>>
                                                Completed
                                            </option>
                                        </select>

                                    </form>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <?php if ($displayed_orders === 0): ?>
                            <tr>
                                <td colspan="7" class="admin-empty-table">
                                    <?php if (!empty($_GET['search'])): ?>
                                        No orders found matching your search.
                                    <?php elseif (!empty($_GET['status'])): ?>
                                        No <?= e(orderStatusLabel($_GET['status'])) ?> orders at the moment.
                                    <?php else: ?>
                                        No online orders have been placed yet.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- WALK-IN SALES -->
    <section class="admin-panel" id="walk-in-sales">

        <h2 class="admin-panel-title">Walk-In Sales</h2>

    <div class="admin-walkin-form">

        <div class="admin-walkin-heading">
            <strong>New Transaction</strong>
            <span>Cooperative Counter Sale</span>
        </div>

        <div class="admin-walkin-products">

            <h3>Product Selection</h3>

            <div class="admin-walkin-product-row">

                <div class="admin-walkin-form-group">
                    <label for="walkin-product">Product</label>
                    <select id="walkin-product">
                        <option value="">Select Product</option>
                        <?php
                        $uniqueProducts = [];

                        foreach ($walkinProducts as $product) {
                            $uniqueProducts[$product['product_id']] =
                                $product['product_name'];
                        }
                        ?>

                        <?php foreach ($uniqueProducts as $productId => $productName): ?>
                            <option value="<?= (int) $productId ?>">
                                <?= e($productName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-walkin-form-group">
                    <label for="walkin-variant">Variant / Size</label>
                    <select id="walkin-variant" disabled>
                        <option value="">Select Variant</option>
                    </select>
                </div>

                <div class="admin-walkin-form-group">
                    <label for="walkin-quantity">Quantity</label>
                    <input
                        type="number"
                        id="walkin-quantity"
                        min="1"
                        step="1"
                        value="1"
                    >
                </div>

                <button type="button" id="walkin-add-product">
                    + Add Product
                </button>

            </div>

            <div class="admin-table-wrapper">
                <table class="admin-orders-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Variant / Size</th>
                            <th>Unit Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody id="walkin-cart-items">
                        <tr>
                            <td colspan="6" class="admin-empty-table">
                                No products added yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>

        <div class="admin-walkin-summary">

            <h3>Transaction Summary</h3>

            <div>
                <span>Total Items</span>
                <strong id="walkin-total-items">0</strong>
            </div>

            <div>
                <span>Total Amount</span>
                <strong id="walkin-total-amount">₱0.00</strong>
            </div>

            <div class="admin-walkin-form-group">
                <label for="walkin-payment-method">Payment Method</label>
                <select id="walkin-payment-method">
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                </select>
            </div>

            <input
                type="hidden"
                id="walkin-csrf-token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >
            
            <button type="button" id="walkin-complete-transaction" disabled>
                Complete Transaction
            </button>

        </div>

    </div>

    </section>

</main>

<!-- GCASH PAYMENT SCREENSHOT POPUP -->
<div class="admin-image-modal" id="admin-image-modal">
    <div class="admin-image-modal-content">

        <div class="admin-image-modal-header">
            <h3>GCash Payment Screenshot</h3>
            <button type="button" id="admin-image-modal-close" aria-label="Close">
                &times;
            </button>
        </div>

        <div class="admin-image-reference">
            <strong>Reference Number:</strong>
            <span id="admin-modal-reference"></span>
        </div>

        <div class="admin-image-preview">
            <img id="admin-modal-image" src="" alt="GCash Payment Screenshot">
        </div>

    </div>
</div>

<footer class="admin-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<script type="application/json" id="walkin-products-data">
<?= json_encode(
    $walkinProducts,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>
</script>

<script src="../assets/js/admin_payment_popup.js"></script>
<script src="../assets/js/admin_walkin_sales.js"></script>
<script src="../assets/js/admin_menu.js"></script>
<script src="../assets/js/theme.js"></script>

</body>
</html>
