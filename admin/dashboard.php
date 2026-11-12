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

        <a href="#online-orders" class="admin-header-icon"
           title="Online Orders" aria-label="Online Orders">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 7h16l1 14H3L4 7Z"/>
                <path d="M8 7a4 4 0 0 1 8 0"/>
            </svg>
        </a>

        <a href="#walk-in-sales" class="admin-header-icon"
           title="Walk-In Sales" aria-label="Walk-In Sales">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <rect x="5" y="3" width="14" height="18" rx="2"/>
                <path d="M9 8h6M9 12h6M9 16h3"/>
            </svg>
        </a>

        <a href="#online-orders" class="admin-header-icon"
           title="Notifications" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                <path d="M10 21h4"/>
            </svg>
        </a>

        <a href="../logout.php" class="admin-header-icon"
           title="Sign Out" aria-label="Sign Out">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h16M4 12h16M4 19h16"/>
            </svg>
        </a>

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
    <section class="admin-summary-grid">

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

                                        <small>
                                            Ref: <?= e($order['reference_number'] ?? 'Not provided') ?>
                                        </small>

                                        <?php if (!empty($order['payment_screenshot'])): ?>
                                            <a
                                                class="admin-screenshot-link"
                                                href="../<?= e($order['payment_screenshot']) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                View Screenshot
                                            </a>
                                        <?php endif; ?>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="admin-status-badge payment-<?= e($order['payment_status'] ?? 'unknown') ?>">
                                        <?= e(paymentStatusLabel($order['payment_status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="admin-status-badge order-<?= e($order['order_status']) ?>">
                                        <?= e(orderStatusLabel($order['order_status'])) ?>
                                    </span>
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

        <div class="admin-walkin-placeholder">

            <div class="admin-walkin-heading">
                <strong>New Transaction</strong>
                <span>Cooperative Counter Sale</span>
            </div>

            <p>
                Record purchases made directly at the cooperative.
            </p>

            <p class="admin-coming-soon">
                The walk-in sales transaction form will be connected
                in the next development step.
            </p>

        </div>

    </section>

</main>

<footer class="admin-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

</body>
</html>
