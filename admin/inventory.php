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

$admin_id = (int) $_SESSION['user_id'];

// HELPER
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


// CSRF TOKEN
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// LOAD INVENTORY
$inventoryItems = [];
$activeItems = [];
$lowStockItems = [];
$outOfStockItems = [];
$archivedItems = [];

try {
    $inventoryStmt = $pdo->query("
        SELECT
            p.product_id,
            p.product_name,
            p.is_archived,
            c.category_name,
            pv.variant_id,
            pv.variant_name,
            pv.size,
            pv.price,
            i.inventory_id,
            i.stock_quantity,
            i.low_stock_threshold
        FROM inventory i
        INNER JOIN product_variants pv
            ON pv.variant_id = i.variant_id
        INNER JOIN products p
            ON p.product_id = pv.product_id
        INNER JOIN categories c
            ON c.category_id = p.category_id
        ORDER BY
            c.category_name,
            p.product_name,
            pv.variant_name
    ");

    $inventoryItems = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inventoryItems as $item) {

        // Archived products are separated from active inventory.
        if ((int) $item['is_archived'] === 1) {
            $archivedItems[] = $item;
            continue;
        }

        $stock = (int) $item['stock_quantity'];
        $threshold = (int) $item['low_stock_threshold'];

        // Separate active products by stock status.
        if ($stock === 0) {
            $outOfStockItems[] = $item;
        } elseif ($stock <= $threshold) {
            $lowStockItems[] = $item;
        } else {
            $activeItems[] = $item;
        }
    }

} catch (PDOException $exception) {
    error_log('Admin inventory error: ' . $exception->getMessage());
    $load_error = true;
}

    // INVENTORY SUMMARY
    $totalVariants = 0;
    $totalStock = 0;

    foreach ($inventoryItems as $item) {
        if ((int) $item['is_archived'] === 1) {
            continue;
        }

        $totalVariants++;
        $totalStock += (int) $item['stock_quantity'];
    }

    $lowStockCount = count($lowStockItems);
    $outOfStockCount = count($outOfStockItems);
    $archivedCount = count($archivedItems);
    
    // INVENTORY SEARCH AND FILTER
    $searchQuery = strtolower(trim($_GET['search'] ?? ''));
    $selectedFilter = $_GET['filter'] ?? 'all';

    $allowedFilters = [
        'all',
        'in_stock',
        'low_stock',
        'out_of_stock',
        'archived'
    ];

    if (!in_array($selectedFilter, $allowedFilters, true)) {
        $selectedFilter = 'all';
    }

    // Apply product-name search to all inventory sections.
    $filterBySearch = function ($items) use ($searchQuery) {
        if ($searchQuery === '') {
            return $items;
        }

        return array_values(array_filter($items, function ($item) use ($searchQuery) {
            return str_contains(
                strtolower($item['product_name']),
                $searchQuery
            );
        }));
    };

    // All active products for the main Product Inventory table.
    $productItems = array_values(array_filter($inventoryItems, function ($item) {
        return (int) $item['is_archived'] === 0;
    }));

    $productItems = $filterBySearch($productItems);

    // Products with normal stock levels.
    $activeItems = $filterBySearch($activeItems);
    $lowStockItems = $filterBySearch($lowStockItems);
    $outOfStockItems = $filterBySearch($outOfStockItems);
    $archivedItems = $filterBySearch($archivedItems);

    ?>
    
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Inventory Management | CampusCart</title>

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

        <!-- Menu -->
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
            <h2>Inventory Management</h2>
            <p>Monitor product availability and stock levels.</p>
        </section>

        <?php if (!empty($load_error)): ?>
            <div class="admin-alert admin-alert-error">
                Inventory information could not be loaded.
                Please check the database connection.
            </div>
        <?php endif; ?>

    <!-- INVENTORY SUMMARY -->
    <section class="admin-summary-grid admin-inventory-summary-grid">

        <article class="admin-summary-card">
            <h3>Total Variants</h3>
            <div class="admin-summary-value">
                <?= number_format($totalVariants) ?>
            </div>
        </article>

        <article class="admin-summary-card">
            <h3>Total Stock</h3>
            <div class="admin-summary-value">
                <?= number_format($totalStock) ?>
            </div>
        </article>

        <article class="admin-summary-card">
            <h3>Low Stock</h3>
            <div class="admin-summary-value">
                <?= number_format($lowStockCount) ?>
            </div>
        </article>

        <article class="admin-summary-card">
            <h3>Out of Stock</h3>
            <div class="admin-summary-value">
                <?= number_format($outOfStockCount) ?>
            </div>
        </article>

    </section>

    <!-- INVENTORY SEARCH AND FILTER -->
    <form class="admin-order-filters" method="GET" id="inventory-filter-form">

        <input
            type="search"
            name="search"
            placeholder="Search product name"
            value="<?= e($_GET['search'] ?? '') ?>"
            onchange="this.form.requestSubmit()"
        >

        <select
            name="filter"
            onchange="this.form.requestSubmit()"
        >
            <option value="all" <?= ($_GET['filter'] ?? 'all') === 'all' ? 'selected' : '' ?>>
                All Products
            </option>

            <option value="in_stock" <?= ($_GET['filter'] ?? '') === 'in_stock' ? 'selected' : '' ?>>
                In Stock
            </option>

            <option value="low_stock" <?= ($_GET['filter'] ?? '') === 'low_stock' ? 'selected' : '' ?>>
                Low Stock
            </option>

            <option value="out_of_stock" <?= ($_GET['filter'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>
                Out of Stock
            </option>

            <option value="archived" <?= ($_GET['filter'] ?? '') === 'archived' ? 'selected' : '' ?>>
                Archived
            </option>
        </select>

        <button type="submit">Search</button>

    </form>

<?php
$inventorySections = [
    [
        'title' => 'Product Inventory',
        'items' => $productItems,
        'refresh' => 'all',
        'empty' => 'No active products found.'
    ],
    [
        'title' => 'Low Stock Products',
        'items' => $lowStockItems,
        'refresh' => 'low_stock',
        'empty' => 'No low-stock products at the moment.'
    ],
    [
        'title' => 'Out of Stock Products',
        'items' => $outOfStockItems,
        'refresh' => 'out_of_stock',
        'empty' => 'No out-of-stock products at the moment.'
    ],
    [
        'title' => 'Archived Products',
        'items' => $archivedItems,
        'refresh' => 'archived',
        'empty' => 'No archived products.'
    ]
];

// SELECT WHICH SECTIONS TO DISPLAY
$displaySections = $inventorySections;

if ($selectedFilter === 'in_stock') {
    $displaySections = [[
        'title' => 'In Stock Products',
        'items' => $activeItems,
        'refresh' => 'in_stock',
        'empty' => 'No in-stock products found.'
    ]];

} elseif ($selectedFilter === 'low_stock') {
    $displaySections = [$inventorySections[1]];

} elseif ($selectedFilter === 'out_of_stock') {
    $displaySections = [$inventorySections[2]];

} elseif ($selectedFilter === 'archived') {
    $displaySections = [$inventorySections[3]];
}
?>

<?php foreach ($displaySections as $section): ?>

    <section class="admin-panel admin-inventory-section">

        <!-- INVENTORY SECTION TITLE -->
        <h2 class="admin-panel-title">
            <?= e($section['title']) ?>
        </h2>

        <div class="admin-table-wrapper">

            <table class="admin-orders-table">

                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Variant / Size</th>
                        <th>Price</th>
                        <th>Stock Quantity</th>
                        <th>Stock Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($section['items'])): ?>

                        <tr>
                            <td colspan="7" class="admin-empty-table">
                                <?= e($section['empty']) ?>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($section['items'] as $item): ?>

                            <?php
                            $stock = (int) $item['stock_quantity'];

                            if ((int) $item['is_archived'] === 1) {
                                $stockLabel = 'Archived';
                                $stockClass = 'archived';
                            } elseif ($stock === 0) {
                                $stockLabel = 'Out of Stock';
                                $stockClass = 'out';
                            } elseif ($stock <= (int) $item['low_stock_threshold']) {
                                $stockLabel = 'Low Stock';
                                $stockClass = 'low';
                            } else {
                                $stockLabel = 'In Stock';
                                $stockClass = 'available';
                            }
                            ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= e($item['product_name']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= e($item['category_name']) ?>
                                </td>

                                <td>
                                    <?= e($item['variant_name']) ?>

                                    <?php if (!empty($item['size'])): ?>
                                        (<?= e($item['size']) ?>)
                                    <?php endif; ?>
                                </td>

                                <td class="admin-table-total">
                                    ₱<?= number_format((float) $item['price'], 2) ?>
                                </td>

                                <td>
                                    <?= number_format($stock) ?>
                                </td>

                                <td>
                                    <span class="admin-stock-badge <?= e($stockClass) ?>">
                                        <?= e($stockLabel) ?>
                                    </span>
                                </td>

                                <td>

                                    <?php if ((int) $item['is_archived'] === 1): ?>

                                        <form method="POST" action="archive_product.php"
                                            onsubmit="return confirm('Are you sure you want to restore this product?');">

                                            <input type="hidden" name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>">

                                            <input type="hidden" name="product_id"
                                                value="<?= (int) $item['product_id'] ?>">

                                            <input type="hidden" name="action" value="restore">

                                            <button type="submit" class="admin-stock-restore-button">
                                                Restore
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <div class="admin-inventory-actions">

                                            <button
                                                type="button"
                                                class="admin-stock-adjust-button"
                                                data-inventory-id="<?= (int) $item['inventory_id'] ?>"
                                                data-product-name="<?= e($item['product_name']) ?>"
                                                data-variant-name="<?= e($item['variant_name']) ?>"
                                                data-size="<?= e($item['size'] ?? '') ?>"
                                                data-stock="<?= $stock ?>"
                                            >
                                                Adjust Stock
                                            </button>

                                            <form method="POST" action="archive_product.php"
                                                onsubmit="return confirm('Are you sure you want to archive this product? It will no longer be available to students.');">

                                                <input type="hidden" name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>">

                                                <input type="hidden" name="product_id"
                                                    value="<?= (int) $item['product_id'] ?>">

                                                <input type="hidden" name="action" value="archive">

                                                <button type="submit" class="admin-stock-archive-button">
                                                    Archive
                                                </button>

                                            </form>

                                        </div>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

<?php endforeach; ?>

</main>

<!-- STOCK ADJUSTMENT POPUP -->
<div class="admin-stock-modal" id="admin-stock-modal">

    <div class="admin-stock-modal-content">

        <div class="admin-stock-modal-header">
            <div>
                <h3>Adjust Stock</h3>
                <p id="admin-stock-product-name"></p>
            </div>

            <button type="button"
                    id="admin-stock-modal-close"
                    aria-label="Close">
                &times;
            </button>
        </div>

        <div class="admin-stock-current">
            <span>Current Stock</span>
            <strong id="admin-stock-current-quantity">0</strong>
        </div>

        <form method="POST" action="adjust_stock.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token'] ?? '') ?>"
            >

            <input
                type="hidden"
                name="inventory_id"
                id="admin-stock-inventory-id"
            >

            <div class="admin-stock-form-group">
                <label for="admin-stock-adjustment-type">
                    Adjustment Type
                </label>

                <select
                    name="adjustment_type"
                    id="admin-stock-adjustment-type"
                    required
                >
                    <option value="addition">Add Stock</option>
                    <option value="deduction">Deduct Stock</option>
                    <option value="correction">Correct Stock</option>
                </select>
            </div>

            <div class="admin-stock-form-group">
                <label for="admin-stock-quantity">
                    <span id="admin-stock-quantity-label">Quantity to Add</span>
                </label>

                <input
                    type="number"
                    name="quantity"
                    id="admin-stock-quantity"
                    min="1"
                    step="1"
                    required
                >

                <small id="admin-stock-quantity-help">
                    Enter the number of units to add.
                </small>
            </div>

            <div class="admin-stock-form-group">
                <label for="admin-stock-reason">
                    Reason for Adjustment
                </label>

                <textarea
                    name="reason"
                    id="admin-stock-reason"
                    rows="3"
                    placeholder="Enter the reason for this adjustment..."
                    required
                ></textarea>
            </div>

            <div class="admin-stock-modal-actions">
                <button type="button"
                        id="admin-stock-cancel">
                    Cancel
                </button>

                <button type="submit"
                        class="admin-stock-save">
                    Save Adjustment
                </button>
            </div>

        </form>

    </div>

</div>

<footer class="admin-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<script src="../assets/js/admin_inventory.js"></script>

</body>
</html>
