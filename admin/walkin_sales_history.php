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

// =========================================
// CSRF TOKEN
// =========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =========================================
// SEARCH AND STATUS FILTER
// =========================================
$searchQuery = trim($_GET['search'] ?? '');
$selectedStatus = $_GET['status'] ?? '';

$allowedStatuses = [
    '',
    'completed',
    'cancelled'
];

if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}

// =========================================
// DEFAULT VALUES
// =========================================
$summary = [
    'total_transactions' => 0,
    'total_sales' => 0,
    'completed_transactions' => 0,
    'cancelled_transactions' => 0
];

$sales = [];
$saleDetails = [];
$load_error = false;

// =========================================
// LOAD WALK-IN SALES HISTORY
// =========================================
try {

    // -------------------------------------
    // TRANSACTION SUMMARY
    // -------------------------------------
    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_transactions,

            COALESCE(
                SUM(
                    CASE
                        WHEN sale_status = 'completed'
                        THEN total_amount
                        ELSE 0
                    END
                ), 0
            ) AS total_sales,

            SUM(
                CASE
                    WHEN sale_status = 'completed'
                    THEN 1
                    ELSE 0
                END
            ) AS completed_transactions,

            SUM(
                CASE
                    WHEN sale_status = 'cancelled'
                    THEN 1
                    ELSE 0
                END
            ) AS cancelled_transactions

        FROM walkin_sales
    ");

    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    if ($summaryData) {
        $summary = [
            'total_transactions' =>
                (int) $summaryData['total_transactions'],

            'total_sales' =>
                (float) $summaryData['total_sales'],

            'completed_transactions' =>
                (int) $summaryData['completed_transactions'],

            'cancelled_transactions' =>
                (int) $summaryData['cancelled_transactions']
        ];
    }

    // -------------------------------------
    // LOAD TRANSACTION LIST
    // -------------------------------------
    $sql = "
        SELECT
            ws.sale_id,
            ws.sale_number,
            ws.sale_date,
            ws.total_amount,
            ws.payment_method,
            ws.sale_status,
            ws.cancellation_reason,

            COALESCE(SUM(wsi.quantity), 0) AS total_quantity,

            GROUP_CONCAT(
                DISTINCT p.product_name
                ORDER BY p.product_name
                SEPARATOR ', '
            ) AS products

        FROM walkin_sales ws

        LEFT JOIN walkin_sale_items wsi
            ON wsi.sale_id = ws.sale_id

        LEFT JOIN product_variants pv
            ON pv.variant_id = wsi.variant_id

        LEFT JOIN products p
            ON p.product_id = pv.product_id

        WHERE 1 = 1
    ";

    $params = [];

    // Search by transaction number or product name.
    if ($searchQuery !== '') {

        $sql .= "
            AND (
                ws.sale_number LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM walkin_sale_items search_items
                    INNER JOIN product_variants search_variants
                        ON search_variants.variant_id = search_items.variant_id
                    INNER JOIN products search_products
                        ON search_products.product_id = search_variants.product_id
                    WHERE search_items.sale_id = ws.sale_id
                    AND search_products.product_name LIKE ?
                )
            )
        ";

        $searchValue = '%' . $searchQuery . '%';

        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    // Filter by transaction status.
    if ($selectedStatus !== '') {
        $sql .= " AND ws.sale_status = ?";
        $params[] = $selectedStatus;
    }

    $sql .= "
        GROUP BY
            ws.sale_id,
            ws.sale_number,
            ws.sale_date,
            ws.total_amount,
            ws.payment_method,
            ws.sale_status,
            ws.cancellation_reason

        ORDER BY
            ws.sale_date DESC,
            ws.sale_id DESC
    ";

    $salesStmt = $pdo->prepare($sql);
    $salesStmt->execute($params);

    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------
    // LOAD DETAILS FOR DISPLAYED SALES
    // -------------------------------------
    if (!empty($sales)) {

        $saleIds = array_column($sales, 'sale_id');

        $placeholders = implode(
            ',',
            array_fill(0, count($saleIds), '?')
        );

        $detailsStmt = $pdo->prepare("
            SELECT
                wsi.sale_id,
                p.product_name,
                pv.variant_name,
                pv.size,
                wsi.quantity,
                wsi.unit_price,
                wsi.subtotal

            FROM walkin_sale_items wsi

            INNER JOIN product_variants pv
                ON pv.variant_id = wsi.variant_id

            INNER JOIN products p
                ON p.product_id = pv.product_id

            WHERE wsi.sale_id IN ($placeholders)

            ORDER BY
                wsi.sale_id,
                p.product_name,
                pv.variant_name
        ");

        $detailsStmt->execute($saleIds);

        $detailRows = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detailRows as $detail) {

            $saleId = (int) $detail['sale_id'];

            $saleDetails[$saleId][] = $detail;
        }
    }

} catch (PDOException $exception) {

    error_log(
        'Walk-in sales history error: ' . $exception->getMessage()
    );

    $load_error = true;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Walk-In Sales History | CampusCart</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/admin_notification.css">
    <link rel="stylesheet" href="../assets/css/admin_menu.css">
    <link rel="stylesheet" href="../assets/css/admin_dark_theme.css">

    <style>
        /* WALK-IN SALES HISTORY */

        .admin-history-panel {
            margin-top: 20px;
        }

        .admin-history-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .admin-history-heading h2 {
            margin: 0;
        }

        .admin-history-refresh {
            padding: 9px 20px;
            border: none;
            border-radius: 8px;
            background: var(--admin-blue, #55A9E8);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        .admin-history-table {
            min-width: 850px;
        }

        .admin-history-table td {
            vertical-align: middle;
        }

        .admin-history-table small {
            display: block;
            margin-top: 4px;
            color: var(--admin-muted, #B5B2CC);
        }

        .admin-history-details-button {
            padding: 7px 14px;
            border: 1px solid var(--admin-border);
            border-radius: 7px;
            background: transparent;
            color: var(--admin-text);
            cursor: pointer;
        }

        .admin-history-details-button:hover {
            background: var(--admin-blue);
            color: #fff;
        }

        .admin-history-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .admin-history-status.completed {
            background: rgba(46, 204, 113, 0.15);
            color: #36c982;
        }

        .admin-history-status.cancelled {
            background: rgba(231, 76, 60, 0.15);
            color: #f07870;
        }


    /* DETAILS MODAL */

    .admin-history-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        padding: 20px;
        align-items: center;
        justify-content: center;
        background: rgba(0, 0, 0, 0.65);
    }

    .admin-history-modal.active {
        display: flex;
    }

    .admin-history-modal-content {
        width: 100%;
        max-width: 820px;
        max-height: 88vh;
        overflow-y: auto;
        padding: 28px;
        border: 1px solid var(--admin-border);
        border-radius: 16px;
        background: var(--admin-panel-solid, #211D3B);
        color: var(--admin-text);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.30);
    }

    /* MODAL HEADER */

    .admin-history-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 22px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--admin-border);
    }

    .admin-history-modal-header h3 {
        margin: 0;
        font-size: 21px;
        font-weight: 700;
    }

    .admin-history-modal-close {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 8px;
        background: transparent;
        color: var(--admin-text);
        font-size: 27px;
        cursor: pointer;
    }

    .admin-history-modal-close:hover {
        background: var(--admin-blue);
        color: #fff;
    }

    /* TRANSACTION INFORMATION */

    .admin-history-sale-info {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 25px;
    }

    .admin-history-sale-info p {
        margin: 0;
        padding: 13px 15px;
        border: 1px solid var(--admin-border);
        border-radius: 10px;
        background: rgba(128, 128, 128, 0.07);
        line-height: 1.6;
        overflow-wrap: anywhere;
    }

    .admin-history-sale-info strong {
        display: block;
        margin-bottom: 3px;
        color: var(--admin-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    /* PURCHASED ITEMS TABLE */

    .admin-history-detail-table {
        display: table;
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin: 0 0 20px;
    }

    .admin-history-detail-table th,
    .admin-history-detail-table td {
        width: 25%;
        padding: 14px 12px;
        border-bottom: 1px solid var(--admin-border);
        text-align: left;
        vertical-align: middle;
        box-sizing: border-box;
        overflow-wrap: anywhere;
    }

    .admin-history-detail-table th {
        color: var(--admin-muted);
        font-size: 13px;
        font-weight: 700;
    }

    .admin-history-detail-table td {
        color: var(--admin-text);
    }

    .admin-history-detail-table small {
        display: block;
        margin-top: 5px;
        color: var(--admin-muted);
        font-size: 12px;
    }

    /* TOTAL AMOUNT */

    .admin-history-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-top: 20px;
        padding: 18px 0 4px;
        border-top: 2px solid var(--admin-border);
        font-size: 18px;
        font-weight: 700;
    }

    .admin-history-total span:last-child {
        color: var(--admin-blue);
        font-size: 22px;
    }

    /* CANCELLATION INFORMATION */

    .admin-history-cancellation {
        margin-top: 20px;
        padding: 15px;
        border: 1px solid rgba(231, 76, 60, 0.25);
        border-radius: 10px;
        background: rgba(231, 76, 60, 0.10);
    }

    .admin-history-cancellation strong {
        color: #f07870;
    }

    .admin-history-cancellation p {
        margin: 8px 0 0;
        line-height: 1.6;
        overflow-wrap: anywhere;
    }

    /* RESPONSIVE MODAL */

    @media (max-width: 600px) {
        .admin-history-modal {
            padding: 12px;
        }

        .admin-history-modal-content {
            padding: 18px;
            max-height: 92vh;
        }

        .admin-history-modal-header h3 {
            font-size: 18px;
        }

        .admin-history-sale-info {
            grid-template-columns: 1fr;
            gap: 9px;
        }

        .admin-history-total {
            font-size: 16px;
        }

        .admin-history-total span:last-child {
            font-size: 19px;
        }
    }

    </style>

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
        <a href="dashboard.php"
           class="admin-header-icon"
           title="Dashboard"
           aria-label="Dashboard">

            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 10.5 12 3l9 7.5"/>
                <path d="M5 9.5V21h14V9.5M9 21v-7h6v7"/>
            </svg>

        </a>

        <!-- Products -->
        <a href="products.php"
           class="admin-header-icon"
           title="Products"
           aria-label="Products">

            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 8h18l-1 13H4L3 8Z"/>
                <path d="M8 8a4 4 0 0 1 8 0"/>
            </svg>

        </a>

        <!-- Inventory -->
        <a href="inventory.php"
           class="admin-header-icon"
           title="Inventory"
           aria-label="Inventory">

            <svg viewBox="0 0 24 24" aria-hidden="true">
                <rect x="5" y="3" width="14" height="18" rx="2"/>
                <path d="M9 8h6M9 12h6M9 16h3"/>
            </svg>

        </a>

        <!-- Notifications Popup -->
        <?php include __DIR__ . '/includes/notification_panel.php'; ?>

        <!-- Admin Menu Popup -->
        <?php include __DIR__ . '/includes/admin_menu.php'; ?>

    </nav>

</header>

<main class="admin-content">

    <!-- PAGE HEADER -->
    <section class="admin-welcome-banner">

        <h2>Walk-In Sales History</h2>

        <p>
            View and manage recorded cooperative counter transactions.
        </p>

    </section>

    <?php if ($load_error): ?>

        <div class="admin-alert admin-alert-error">
            Walk-in sales history could not be loaded.
            Please check the database connection.
        </div>

    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <section class="admin-summary-grid admin-history-summary-grid">

        <article class="admin-summary-card">

            <h3>Total Transactions</h3>

            <div class="admin-summary-value">
                <?= number_format($summary['total_transactions']) ?>
            </div>

        </article>

        <article class="admin-summary-card">

            <h3>Total Completed Sales</h3>

            <div class="admin-summary-value">
                <?= e(peso($summary['total_sales'])) ?>
            </div>

        </article>

        <article class="admin-summary-card">

            <h3>Completed Transactions</h3>

            <div class="admin-summary-value">
                <?= number_format($summary['completed_transactions']) ?>
            </div>

        </article>

        <article class="admin-summary-card">

            <h3>Cancelled Transactions</h3>

            <div class="admin-summary-value">
                <?= number_format($summary['cancelled_transactions']) ?>
            </div>

        </article>

    </section>

    <!-- SEARCH AND FILTER -->
    <form class="admin-order-filters"
          method="GET"
          id="walkin-history-filter-form">

        <input
            type="search"
            name="search"
            placeholder="Search transaction or product"
            value="<?= e($searchQuery) ?>"
        >

        <select
            name="status"
            onchange="this.form.submit()"
        >

            <option value=""
                <?= $selectedStatus === '' ? 'selected' : '' ?>>
                All Transaction Statuses
            </option>

            <option value="completed"
                <?= $selectedStatus === 'completed' ? 'selected' : '' ?>>
                Completed
            </option>

            <option value="cancelled"
                <?= $selectedStatus === 'cancelled' ? 'selected' : '' ?>>
                Cancelled
            </option>

        </select>

        <button type="submit">Search</button>

    </form>

    <!-- TRANSACTION TABLE -->
    <section class="admin-panel admin-history-panel">

        <div class="admin-history-heading">

            <h2 class="admin-panel-title">
                Walk-In Transactions
            </h2>

            <button
                type="button"
                class="admin-history-refresh"
                onclick="window.location.href='walkin_sales_history.php'"
            >
                Refresh
            </button>

        </div>

        <div class="admin-table-wrapper">

            <table class="admin-orders-table admin-history-table">

                <thead>

                    <tr>
                        <th>Transaction ID</th>
                        <th>Date & Time</th>
                        <th>Products</th>
                        <th>Total Quantity</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (empty($sales)): ?>

                        <tr>
                            <td colspan="7" class="admin-empty-table">
                                No walk-in transactions found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($sales as $sale): ?>

                            <?php
                                $saleId = (int) $sale['sale_id'];
                                $status = $sale['sale_status'];
                            ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= e($sale['sale_number']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= e(date(
                                        'M j, Y',
                                        strtotime($sale['sale_date'])
                                    )) ?>

                                    <small>
                                        <?= e(date(
                                            'g:i A',
                                            strtotime($sale['sale_date'])
                                        )) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= e($sale['products'] ?? 'No product details') ?>
                                </td>

                                <td>
                                    <?= number_format((int) $sale['total_quantity']) ?>
                                </td>

                                <td class="admin-table-total">
                                    <?= e(peso($sale['total_amount'])) ?>
                                </td>

                                <td>
                                    <span class="admin-history-status <?= e($status) ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td>

                                    <button
                                        type="button"
                                        class="admin-history-details-button"
                                        data-sale-id="<?= $saleId ?>"
                                    >
                                        View
                                    </button>

                                </td>

                            </tr>

                            <?php
                                $details = $saleDetails[$saleId] ?? [];
                            ?>

                            <!-- HIDDEN DETAILS TEMPLATE -->
                            <template id="sale-details-<?= $saleId ?>">

                                <div class="admin-history-sale-info">

                                    <p>
                                        <strong>Transaction ID:</strong>
                                        <?= e($sale['sale_number']) ?>
                                    </p>

                                    <p>
                                        <strong>Date:</strong>
                                        <?= e(date(
                                            'M j, Y g:i A',
                                            strtotime($sale['sale_date'])
                                        )) ?>
                                    </p>

                                    <p>
                                        <strong>Payment Method:</strong>
                                        <?= e(strtoupper($sale['payment_method'])) ?>
                                    </p>

                                    <p>
                                        <strong>Status:</strong>
                                        <?= e(ucfirst($status)) ?>
                                    </p>

                                </div>

                                <table class="admin-history-detail-table">

                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($details as $detail): ?>

                                            <tr>

                                                <td>
                                                    <?= e($detail['product_name']) ?>

                                                    <small>
                                                        <?= e($detail['variant_name']) ?>

                                                        <?php if (!empty($detail['size'])): ?>
                                                            (<?= e($detail['size']) ?>)
                                                        <?php endif; ?>
                                                    </small>
                                                </td>

                                                <td>
                                                    <?= number_format((int) $detail['quantity']) ?>
                                                </td>

                                                <td>
                                                    <?= e(peso($detail['unit_price'])) ?>
                                                </td>

                                                <td>
                                                    <?= e(peso($detail['subtotal'])) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                                <div class="admin-history-total">

                                    <span>Total Amount</span>

                                    <span>
                                        <?= e(peso($sale['total_amount'])) ?>
                                    </span>

                                </div>

                                <?php if ($status === 'cancelled' && !empty($sale['cancellation_reason'])): ?>

                                    <div class="admin-history-cancellation">

                                        <strong>Cancellation Reason</strong>

                                        <p>
                                            <?= e($sale['cancellation_reason']) ?>
                                        </p>

                                    </div>

                                <?php endif; ?>

                            </template>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<!-- TRANSACTION DETAILS MODAL -->
<div
    class="admin-history-modal"
    id="admin-history-modal"
    aria-hidden="true"
>

    <div class="admin-history-modal-content">

        <div class="admin-history-modal-header">

            <h3>Transaction Details</h3>

            <button
                type="button"
                class="admin-history-modal-close"
                id="admin-history-modal-close"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div id="admin-history-modal-body"></div>

    </div>

</div>

<footer class="admin-footer">

    © <?= date('Y') ?> CampusCart |
    Pamantasan ng Lungsod ng Maynila

</footer>

<script src="../assets/js/admin_notification.js"></script>
<script src="../assets/js/admin_menu.js"></script>
<script src="../assets/js/theme.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const modal = document.getElementById('admin-history-modal');
    const modalBody = document.getElementById('admin-history-modal-body');
    const closeButton = document.getElementById('admin-history-modal-close');

    // OPEN TRANSACTION DETAILS
    document.querySelectorAll('.admin-history-details-button').forEach(button => {

        button.addEventListener('click', function () {

            const saleId = this.dataset.saleId;
            const template = document.getElementById('sale-details-' + saleId);

            if (!template) {
                return;
            }

            modalBody.replaceChildren(
                template.content.cloneNode(true)
            );

            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');

        });

    });

    // CLOSE MODAL
    function closeModal() {

        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');

        modalBody.innerHTML = '';

    }

    closeButton.addEventListener('click', closeModal);

    // CLOSE WHEN CLICKING OUTSIDE
    modal.addEventListener('click', function (event) {

        if (event.target === modal) {
            closeModal();
        }

    });

    // CLOSE WITH ESCAPE KEY
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }

    });

});
</script>

</body>
</html>
