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

// SEARCH AND CATEGORY FILTER
$searchQuery = trim($_GET['search'] ?? '');
$selectedCategory = $_GET['category'] ?? '';

$allowedCategories = [
    'School Uniform',
    'P.E. Uniforms',
    'Accessories'
];

if (
    $selectedCategory !== '' &&
    !in_array($selectedCategory, $allowedCategories, true)
) {
    $selectedCategory = '';
}

$products = [];
$categories = [];
$load_error = false;

try {
    // LOAD CATEGORIES
    $categoryStmt = $pdo->query("
        SELECT category_id, category_name
        FROM categories
        ORDER BY category_name
    ");

    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    // LOAD PRODUCTS AND STOCK AVAILABILITY
    $sql = "
        SELECT
            p.product_id,
            p.product_name,
            p.description,
            p.product_image,
            p.is_archived,
            c.category_name,

            COALESCE(SUM(i.stock_quantity), 0) AS total_stock,
            COALESCE(SUM(i.low_stock_threshold), 0) AS total_threshold

        FROM products p

        INNER JOIN categories c
            ON c.category_id = p.category_id

        LEFT JOIN product_variants pv
            ON pv.product_id = p.product_id

        LEFT JOIN inventory i
            ON i.variant_id = pv.variant_id

        WHERE 1 = 1
    ";

    $params = [];

    if ($searchQuery !== '') {
        $sql .= " AND p.product_name LIKE ?";
        $params[] = '%' . $searchQuery . '%';
    }

    if ($selectedCategory !== '') {
        $sql .= " AND c.category_name = ?";
        $params[] = $selectedCategory;
    }

    $sql .= "
        GROUP BY
            p.product_id,
            p.product_name,
            p.description,
            p.product_image,
            p.is_archived,
            c.category_name

        ORDER BY
            c.category_name,
            p.product_name
    ";

    $productStmt = $pdo->prepare($sql);
    $productStmt->execute($params);

    $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log('Admin products error: ' . $exception->getMessage());
    $load_error = true;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Products | CampusCart</title>

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
        <h2>Manage Products</h2>
        <p>View and manage products available in the cooperative store.</p>
    </section>

    <?php if ($load_error): ?>
        <div class="admin-alert admin-alert-error">
            Products could not be loaded. Please check the database connection.
        </div>
    <?php endif; ?>

    <section class="admin-panel admin-products-panel">

        <div class="admin-products-heading">
            <h2>Product List</h2>

            <a href="add_product.php" class="admin-product-add-button">
                + Add New Product
            </a>
        </div>

        <form class="admin-order-filters" method="GET">

            <input
                type="search"
                name="search"
                placeholder="Search product name"
                value="<?= e($searchQuery) ?>"
            >

            <select name="category">
                <option value="">All Categories</option>

                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= e($category['category_name']) ?>"
                        <?= $selectedCategory === $category['category_name']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($category['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Search</button>

        </form>

        <div class="admin-table-wrapper">

            <table class="admin-orders-table admin-products-table">

                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Availability</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($products)): ?>

                        <tr>
                            <td colspan="6" class="admin-empty-table">
                                No products found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($products as $product): ?>

                            <?php
                            $stock = (int) $product['total_stock'];
                            $threshold = (int) $product['total_threshold'];

                            if ((int) $product['is_archived'] === 1) {
                                $availability = 'Archived';
                                $availabilityClass = 'archived';
                            } elseif ($stock === 0) {
                                $availability = 'Out of Stock';
                                $availabilityClass = 'out';
                            } elseif ($stock <= $threshold) {
                                $availability = 'Low Stock';
                                $availabilityClass = 'low';
                            } else {
                                $availability = 'In Stock';
                                $availabilityClass = 'available';
                            }
                            ?>

                            <tr>

                                <td>
                                    <?php if (!empty($product['product_image'])): ?>
                                        <img
                                            src="../<?= e($product['product_image']) ?>"
                                            alt="<?= e($product['product_name']) ?>"
                                            class="admin-product-thumbnail"
                                        >
                                    <?php else: ?>
                                        <div class="admin-product-image-placeholder">
                                            No Image
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= e($product['product_name']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= e($product['category_name']) ?>
                                </td>

                                <td class="admin-product-description">
                                    <?= e($product['description'] ?: '—') ?>
                                </td>

                                <td>
                                    <span class="admin-stock-badge <?= e($availabilityClass) ?>">
                                        <?= e($availability) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="admin-product-actions">

                                        <a
                                            href="edit_product.php?id=<?= (int) $product['product_id'] ?>"
                                            class="admin-product-edit-button"
                                        >
                                            Edit
                                        </a>

                                        <?php if ((int) $product['is_archived'] === 1): ?>

                                            <form method="POST" action="archive_product.php"
                                                onsubmit="return confirm('Restore this product?');">

                                                <input type="hidden" name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>">

                                                <input type="hidden" name="product_id"
                                                    value="<?= (int) $product['product_id'] ?>">

                                                <input type="hidden" name="action" value="restore">

                                                <button type="submit"
                                                    class="admin-product-restore-button">
                                                    Restore
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <form method="POST" action="archive_product.php"
                                                onsubmit="return confirm('Are you sure you want to archive this product?');">

                                                <input type="hidden" name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>">

                                                <input type="hidden" name="product_id"
                                                    value="<?= (int) $product['product_id'] ?>">

                                                <input type="hidden" name="action" value="archive">

                                                <button type="submit"
                                                    class="admin-product-archive-button">
                                                    Archive
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<footer class="admin-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<script src="../assets/js/admin_menu.js"></script>
<script src="../assets/js/theme.js"></script>

</body>
</html>
