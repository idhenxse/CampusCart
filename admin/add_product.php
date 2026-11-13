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

// HELPER
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// CSRF TOKEN
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$categories = [];
$errors = [];

$productName = '';
$description = '';
$variantName = 'Standard';
$price = '';
$initialStock = '25';
$lowStockThreshold = '5';

// LOAD CATEGORIES
try {
    $categoryStmt = $pdo->query("
        SELECT category_id, category_name
        FROM categories
        ORDER BY category_name
    ");

    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log('Add product category error: ' . $exception->getMessage());
    $errors[] = 'Categories could not be loaded. Please try again.';
}

// PROCESS FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // VERIFY CSRF TOKEN
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    }

    $productName = trim($_POST['product_name'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $variantName = trim($_POST['variant_name'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $initialStock = trim($_POST['initial_stock'] ?? '');
    $lowStockThreshold = trim($_POST['low_stock_threshold'] ?? '');

    // VALIDATE PRODUCT NAME
    if ($productName === '' || mb_strlen($productName) > 150) {
        $errors[] = 'Please enter a product name (maximum 150 characters).';
    }

    // VALIDATE CATEGORY
    $validCategoryIds = array_map(
        'intval',
        array_column($categories, 'category_id')
    );

    if (!in_array($categoryId, $validCategoryIds, true)) {
        $errors[] = 'Please select a valid product category.';
    }

    // VALIDATE DESCRIPTION
    if (mb_strlen($description) > 5000) {
        $errors[] = 'The product description is too long.';
    }

    // VALIDATE INITIAL VARIANT
    if ($variantName === '' || mb_strlen($variantName) > 100) {
        $errors[] = 'Please enter a variant name (maximum 100 characters).';
    }

    // VALIDATE PRICE
    if (
        !is_numeric($price) ||
        (float) $price <= 0 ||
        (float) $price > 99999999.99
    ) {
        $errors[] = 'Please enter a valid product price.';
    }

    // VALIDATE INITIAL STOCK
    if (
        filter_var($initialStock, FILTER_VALIDATE_INT) === false ||
        (int) $initialStock < 0
    ) {
        $errors[] = 'Initial stock must be a whole number of zero or more.';
    }

    // VALIDATE LOW STOCK THRESHOLD
    if (
        filter_var($lowStockThreshold, FILTER_VALIDATE_INT) === false ||
        (int) $lowStockThreshold < 0
    ) {
        $errors[] = 'Low-stock threshold must be a whole number of zero or more.';
    }

    // IMAGE UPLOAD VALIDATION
    $uploadedImagePath = null;
    $temporaryImagePath = null;

    if (
        isset($_FILES['product_image']) &&
        $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $image = $_FILES['product_image'];

        if ($image['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The image could not be uploaded. Please try again.';
        } elseif ($image['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The image must not exceed 5 MB.';
        } else {

            $imageInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $imageInfo->file($image['tmp_name']);

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = 'Only JPG, PNG, and WebP images are allowed.';
            } else {

                $imageDirectory = __DIR__ . '/../assets/images/products/';

                if (
                    !is_dir($imageDirectory) &&
                    !mkdir($imageDirectory, 0755, true)
                ) {
                    $errors[] = 'The product image folder could not be created.';
                } else {

                    $fileName = bin2hex(random_bytes(16))
                        . '.'
                        . $allowedTypes[$mimeType];

                    $temporaryImagePath = $imageDirectory . $fileName;

                    if (move_uploaded_file(
                        $image['tmp_name'],
                        $temporaryImagePath
                    )) {
                        $uploadedImagePath = 'assets/images/products/' . $fileName;
                    } else {
                        $errors[] = 'The product image could not be saved.';
                        $temporaryImagePath = null;
                    }
                }
            }
        }
    }

    // SAVE PRODUCT, VARIANT, AND INVENTORY
    if (empty($errors)) {

        try {
            $pdo->beginTransaction();

            // INSERT PRODUCT
            $productStmt = $pdo->prepare("
                INSERT INTO products (
                    category_id,
                    product_name,
                    description,
                    product_image
                )
                VALUES (?, ?, ?, ?)
            ");

            $productStmt->execute([
                $categoryId,
                $productName,
                $description,
                $uploadedImagePath
            ]);

            $productId = (int) $pdo->lastInsertId();

            // INSERT INITIAL VARIANT
            $variantStmt = $pdo->prepare("
                INSERT INTO product_variants (
                    product_id,
                    variant_name,
                    price,
                    size
                )
                VALUES (?, ?, ?, NULL)
            ");

            $variantStmt->execute([
                $productId,
                $variantName,
                $price
            ]);

            $variantId = (int) $pdo->lastInsertId();

            // INSERT INITIAL INVENTORY
            $inventoryStmt = $pdo->prepare("
                INSERT INTO inventory (
                    variant_id,
                    stock_quantity,
                    low_stock_threshold
                )
                VALUES (?, ?, ?)
            ");

            $inventoryStmt->execute([
                $variantId,
                (int) $initialStock,
                (int) $lowStockThreshold
            ]);

            $pdo->commit();

            header('Location: products.php?added=1');
            exit;

        } catch (PDOException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Remove uploaded image if database saving failed.
            if (
                $temporaryImagePath !== null &&
                file_exists($temporaryImagePath)
            ) {
                unlink($temporaryImagePath);
            }

            error_log('Add product error: ' . $exception->getMessage());

            $errors[] = 'The product could not be saved. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Product | CampusCart</title>

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

        <a href="dashboard.php" class="admin-header-icon"
           title="Dashboard" aria-label="Dashboard">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 10.5 12 3l9 7.5"/>
                <path d="M5 9.5V21h14V9.5M9 21v-7h6v7"/>
            </svg>
        </a>

        <a href="products.php" class="admin-header-icon"
           title="Products" aria-label="Products">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 8h18l-1 13H4L3 8Z"/>
                <path d="M8 8a4 4 0 0 1 8 0"/>
            </svg>
        </a>

        <a href="inventory.php" class="admin-header-icon"
           title="Inventory" aria-label="Inventory">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <rect x="5" y="3" width="14" height="18" rx="2"/>
                <path d="M9 8h6M9 12h6M9 16h3"/>
            </svg>
        </a>

        <a href="notifications.php" class="admin-header-icon"
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
        <h2>Add New Product</h2>
        <p>Enter the product details and initial inventory information.</p>
    </section>

    <section class="admin-panel admin-product-form-panel">

        <h2 class="admin-panel-title">Product Information</h2>

        <?php if (!empty($errors)): ?>
            <div class="admin-alert admin-alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <div class="admin-product-form-grid">

                <div class="admin-product-form-group">
                    <label for="product_name">Product Name</label>

                    <input
                        type="text"
                        id="product_name"
                        name="product_name"
                        maxlength="150"
                        value="<?= e($productName) ?>"
                        placeholder="Enter product name"
                        required
                    >
                </div>

                <div class="admin-product-form-group">
                    <label for="category_id">Category</label>

                    <select id="category_id" name="category_id" required>
                        <option value="">Select Category</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int) $category['category_id'] ?>"
                                <?= (int) ($_POST['category_id'] ?? 0) ===
                                    (int) $category['category_id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-product-form-group admin-product-form-full">
                    <label for="description">Product Description</label>

                    <textarea
                        id="description"
                        name="description"
                        rows="4"
                        placeholder="Enter product description..."
                    ><?= e($description) ?></textarea>
                </div>

                <div class="admin-product-form-group admin-product-form-full">
                    <label for="product_image">Product Image (Optional)</label>

                    <input
                        type="file"
                        id="product_image"
                        name="product_image"
                        accept=".jpg,.jpeg,.png,.webp"
                    >

                    <small>Accepted formats: JPG, PNG, and WebP. Maximum size: 5 MB.</small>
                </div>

            </div>

            <div class="admin-product-form-divider"></div>

            <h3 class="admin-product-form-subtitle">
                Initial Variant and Inventory
            </h3>

            <div class="admin-product-form-grid">

                <div class="admin-product-form-group">
                    <label for="variant_name">Initial Variant Name</label>

                    <input
                        type="text"
                        id="variant_name"
                        name="variant_name"
                        maxlength="100"
                        value="<?= e($variantName) ?>"
                        placeholder="e.g. Standard"
                        required
                    >

                    <small>
                        Products with sizes or colors can have additional
                        variants added later.
                    </small>
                </div>

                <div class="admin-product-form-group">
                    <label for="price">Price (₱)</label>

                    <input
                        type="number"
                        id="price"
                        name="price"
                        min="0.01"
                        max="99999999.99"
                        step="0.01"
                        value="<?= e($price) ?>"
                        placeholder="0.00"
                        required
                    >
                </div>

                <div class="admin-product-form-group">
                    <label for="initial_stock">Initial Stock Quantity</label>

                    <input
                        type="number"
                        id="initial_stock"
                        name="initial_stock"
                        min="0"
                        step="1"
                        value="<?= e($initialStock) ?>"
                        required
                    >
                </div>

                <div class="admin-product-form-group">
                    <label for="low_stock_threshold">Low Stock Threshold</label>

                    <input
                        type="number"
                        id="low_stock_threshold"
                        name="low_stock_threshold"
                        min="0"
                        step="1"
                        value="<?= e($lowStockThreshold) ?>"
                        required
                    >

                    <small>
                        The product will be flagged as low stock when its
                        quantity reaches this level.
                    </small>
                </div>

            </div>

            <div class="admin-product-form-actions">

                <a href="products.php" class="admin-product-cancel-button">
                    Cancel
                </a>

                <button type="submit" class="admin-product-save-button">
                    Save Product
                </button>

            </div>

        </form>

    </section>

</main>

<footer class="admin-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

</body>
</html>
