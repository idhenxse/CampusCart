<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// =========================================
// STUDENT SESSION PROTECTION
// =========================================
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    header("Location: ../index.php");
    exit;
}

// =========================================
// CART SECURITY TOKEN
// =========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =========================================
// APPROVED PRODUCT CATEGORIES
// =========================================
$category_labels = [
    'School Uniform' => 'School Uniforms',
    'P.E. Uniforms' => 'P.E. Uniforms',
    'Accessories' => 'Accessories'
];

$search = trim($_GET['search'] ?? '');
$selected_category = trim($_GET['category'] ?? '');

$products_by_category = [];

foreach ($category_labels as $category_name => $label) {
    $products_by_category[$category_name] = [];
}

$unread_notifications = 0;
$load_error = false;

// =========================================
// LOAD DASHBOARD DATA
// =========================================
try {
    // Count unread notifications.
    $notification_stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = :user_id
         AND is_read = 0"
    );

    $notification_stmt->execute([
        ':user_id' => $_SESSION['user_id']
    ]);

    $unread_notifications = (int) $notification_stmt->fetchColumn();

    // Load products, prices and stock.
    $sql = "
        SELECT
            p.product_id,
            p.product_name,
            p.description,
            p.product_image,
            p.price,
            c.category_name,
            COALESCE(MIN(pv.price), p.price) AS display_price,
            COALESCE(SUM(i.stock_quantity), 0) AS total_stock
        FROM products p
        INNER JOIN categories c
            ON p.category_id = c.category_id
        LEFT JOIN product_variants pv
            ON p.product_id = pv.product_id
        LEFT JOIN inventory i
            ON pv.variant_id = i.variant_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= " AND p.product_name LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    if (
        $selected_category !== '' &&
        array_key_exists($selected_category, $category_labels)
    ) {
        $sql .= " AND c.category_name = :category";
        $params[':category'] = $selected_category;
    }

    $sql .= "
        GROUP BY
            p.product_id,
            p.product_name,
            p.description,
            p.product_image,
            p.price,
            c.category_name
        ORDER BY
            CASE c.category_name
                WHEN 'School Uniform' THEN 1
                WHEN 'P.E. Uniforms' THEN 2
                WHEN 'Accessories' THEN 3
                ELSE 4
            END,
            CASE
                WHEN c.category_name = 'School Uniform' THEN
                    FIELD(
                        p.product_name,
                        'Blouse',
                        'Polo',
                        'Skirt',
                        'Pants',
                        'Set (Blouse and Skirt)',
                        'Set (Polo and Pants)',
                        'Pin',
                        'Lace',
                        'Patch'
                    )
                WHEN c.category_name = 'P.E. Uniforms' THEN
                    FIELD(
                        p.product_name,
                        'T-Shirt',
                        'Short',
                        'Set (T-Shirt and Short)',
                        'Paditas'
                    )
                ELSE 0
            END,
            CASE
                WHEN c.category_name = 'Accessories'
                THEN p.product_name
            END ASC
    ";

    $product_stmt = $pdo->prepare($sql);
    $product_stmt->execute($params);
    $products = $product_stmt->fetchAll();

    // Load all variants and their individual stock quantities.
    $variants_by_product = [];

    if (!empty($products)) {
        $product_ids = array_column($products, 'product_id');

        // Create one placeholder for each product ID.
        $placeholders = implode(
            ',',
            array_fill(0, count($product_ids), '?')
        );

        $variant_sql = "
            SELECT
                pv.variant_id,
                pv.product_id,
                pv.variant_name,
                pv.size,
                pv.price,
                COALESCE(i.stock_quantity, 0) AS stock_quantity
            FROM product_variants pv
            LEFT JOIN inventory i
                ON pv.variant_id = i.variant_id
            WHERE pv.product_id IN ($placeholders)
            ORDER BY
                pv.product_id,
                pv.size,
                pv.variant_name
        ";

        $variant_stmt = $pdo->prepare($variant_sql);
        $variant_stmt->execute($product_ids);
        $variants = $variant_stmt->fetchAll();

        // Group variants under their corresponding product.
        foreach ($variants as $variant) {
            $product_id = $variant['product_id'];

            if (!isset($variants_by_product[$product_id])) {
                $variants_by_product[$product_id] = [];
            }

            $variants_by_product[$product_id][] = $variant;
        }
    }

        // =========================================
        // COMBINE THE TWO TOTE BAG PRODUCTS
        // Display only — no database records are deleted.
        // =========================================

        $tote_variants = [];
        $tote_total_stock = 0;
        $tote_display_price = null;
        $tote_product_id = null;
        $tote_image = null;

        $combined_tote = null;

        // Collect the existing Black and Cream Tote Bag variants.
        foreach ($products as $product) {

            if (
                $product['product_name'] === 'Tote Bag (Black)' ||
                $product['product_name'] === 'Tote Bag (Cream)'
            ) {

                if ($tote_product_id === null) {
                    $tote_product_id = (int) $product['product_id'];
                }

                $tote_total_stock += (int) $product['total_stock'];

                if (
                    $tote_display_price === null ||
                    (float) $product['display_price'] < $tote_display_price
                ) {
                    $tote_display_price = (float) $product['display_price'];
                }

                if (!empty($product['product_image'])) {
                    $tote_image = $product['product_image'];
                }

                // Keep each existing variant and its stock.
                foreach (
                    $variants_by_product[$product['product_id']] ?? []
                    as $variant
                ) {

                    $variant['variant_name'] =
                        $product['product_name'] === 'Tote Bag (Black)'
                            ? 'Black'
                            : 'Cream';

                    $variant['size'] = null;

                    $tote_variants[] = $variant;
                }
            }
        }

        // Create one combined Tote Bag card.
        if (!empty($tote_variants)) {

            $combined_tote = [
                'product_id' => $tote_product_id,
                'product_name' => 'Tote Bag',
                'description' => 'Tote bag available in Black and Cream.',
                'product_image' => $tote_image,
                'price' => $tote_display_price,
                'category_name' => 'Accessories',
                'display_price' => $tote_display_price,
                'total_stock' => $tote_total_stock
            ];

            $variants_by_product[$tote_product_id] = $tote_variants;
        }

        // Replace the two individual Tote Bag cards with one combined card.
        if ($combined_tote !== null) {

            $updated_products = [];
            $tote_inserted = false;

            foreach ($products as $product) {

                if (
                    $product['product_name'] === 'Tote Bag (Black)' ||
                    $product['product_name'] === 'Tote Bag (Cream)'
                ) {

                    if (!$tote_inserted) {
                        $updated_products[] = $combined_tote;
                        $tote_inserted = true;
                    }

                    continue;
                }

                $updated_products[] = $product;
            }

            $products = $updated_products;
        }

    // Group products by category.
    foreach ($products as $product) {
        $category_name = $product['category_name'];

        if (isset($products_by_category[$category_name])) {
            $products_by_category[$category_name][] = $product;
        }
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    $load_error = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | CampusCart</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
</head>
<body>

<div class="student-page">

    <!-- =====================================
         CAMPUSCART HEADER
    ====================================== -->
    <header class="student-header">
        <div class="student-header-brand">
            <img
                src="../assets/images/plm-logo.png"
                alt="PLM Seal"
            >
            <div>
                <h1>CampusCart</h1>
                <p>SCHOOL COOPERATIVE PORTAL</p>
            </div>
        </div>

        <nav class="student-header-actions" aria-label="Student navigation">

            <!-- Shopping Cart -->
            <a
                href="cart.php"
                class="header-action"
                title="Shopping Cart"
                aria-label="Shopping Cart"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="9" cy="21" r="1"/>
                    <circle cx="19" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    <path d="M8 10h12"/>
                </svg>
            </a>

            <!-- My Orders -->
            <a
                href="orders.php"
                class="header-action"
                title="My Orders"
                aria-label="My Orders"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16l1 14H3L4 7Z"/>
                    <path d="M8 7a4 4 0 0 1 8 0"/>
                    <path d="M9 11h6"/>
                </svg>
            </a>

            <?php require __DIR__ . '/includes/notification_panel.php'; ?>

            <!-- Student Menu -->
            <a
                href="menu.php"
                class="header-action"
                title="Student Menu"
                aria-label="Student Menu"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 6h16"/>
                    <path d="M4 12h16"/>
                    <path d="M4 18h16"/>
                </svg>
            </a>

        </nav>
    </header>

    <!-- =====================================
         MAIN DASHBOARD CONTENT
    ====================================== -->
    <main class="student-content">

        <!-- WELCOME BANNER -->
        <section class="welcome-section">
            <h2>Welcome Haribon!</h2>
            <p>Karunungan, Kaunlaran, Kadakilaan</p>
        </section>

        <!-- =================================
             TWO-COLUMN DASHBOARD
        ================================== -->
        <div class="dashboard-layout">

            <!-- =================================
                 LEFT: PRODUCT CATALOGUE
            ================================== -->
            <section class="catalogue-panel">

                <h2 class="catalogue-title">
                    Product Catalogue
                </h2>

                <!-- SEARCH AND CATEGORY FILTER -->
                <form
                    action="dashboard.php"
                    method="GET"
                    class="catalogue-search"
                >
                    <input
                        type="search"
                        name="search"
                        placeholder="Search products..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <select name="category">
                        <option value="">
                            All Categories
                        </option>

                        <?php foreach ($category_labels as $category_name => $label): ?>
                            <option
                                value="<?= htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $selected_category === $category_name ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">
                        SEARCH
                    </button>
                </form>

                <!-- PRODUCT CATEGORY SECTIONS -->
                <?php foreach ($category_labels as $category_name => $label): ?>

                    <?php
                    if (
                        $selected_category !== '' &&
                        $selected_category !== $category_name
                    ) {
                        continue;
                    }
                    ?>

                    <section class="product-category">
                        <h3>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </h3>

                        <div class="category-products">

                            <?php if ($load_error): ?>

                                <div class="no-category-products">
                                    Unable to load products.
                                    Please refresh the page.
                                </div>

                            <?php elseif (empty($products_by_category[$category_name])): ?>

                                <div class="no-category-products">
                                    No products available in this category yet.
                                </div>

                            <?php else: ?>

                                <?php foreach ($products_by_category[$category_name] as $product): ?>

                                    <article
                                        class="product-card"
                                        role="button"
                                        tabindex="0"
                                        aria-label="View details for <?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-product-id="<?= (int) $product['product_id'] ?>"
                                        data-product-name="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-product-description="<?= htmlspecialchars($product['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-product-image="<?= htmlspecialchars($product['product_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-product-variants="<?= htmlspecialchars(
                                            json_encode(
                                                $variants_by_product[$product['product_id']] ?? [],
                                                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >

                                        <!-- PRODUCT IMAGE -->
                                        <div class="product-image">
                                            <?php if (!empty($product['product_image'])): ?>
                                                <img
                                                    src="../<?= htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8') ?>"
                                                    alt="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                >
                                            <?php else: ?>
                                                <span>Product Image</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- PRODUCT DETAILS -->
                                        <div class="product-details">
                                            <h4>
                                                <?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </h4>

                                            <p class="product-price">
                                                ₱<?= number_format((float) $product['display_price'], 2) ?>
                                            </p>

                                            <?php if ((int) $product['total_stock'] > 0): ?>
                                                <span class="stock-label stock-available">
                                                    In Stock
                                                </span>
                                            <?php else: ?>
                                                <span class="stock-label stock-unavailable">
                                                    Out of Stock
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                    </article>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>
                    </section>

                <?php endforeach; ?>

            </section>

            <!-- =================================
                 RIGHT: STUDENT SIDEBAR
            ================================== -->
            <aside class="student-sidebar">

                <!-- =================================
                     MONTHLY CALENDAR AND CLOCK
                ================================== -->
                <section class="sidebar-panel datetime-panel">

                    <div class="calendar-display">

                        <!-- CALENDAR HEADER -->
                        <div class="calendar-header">

                            <button
                                type="button"
                                id="previous-month"
                                aria-label="Previous month"
                            >
                                ‹
                            </button>

                            <span
                                class="calendar-month-year"
                                id="calendar-month-year"
                            >
                                Loading...
                            </span>

                            <button
                                type="button"
                                id="next-month"
                                aria-label="Next month"
                            >
                                ›
                            </button>

                        </div>

                        <!-- MONTHLY CALENDAR -->
                        <div
                            class="month-calendar"
                            id="month-calendar"
                        >
                            <!-- Dates generated by JavaScript -->
                        </div>

                        <!-- DIGITAL CLOCK -->
                        <div
                            class="calendar-clock"
                            id="calendar-clock"
                        >
                            --:-- --
                        </div>

                    </div>

                </section>

                <!-- =================================
                     COOPERATIVE PICKUP REMINDER
                ================================== -->
                <section class="sidebar-panel pickup-panel">

                    <h3>
                        Cooperative Pickup Reminder
                    </h3>

                    <!-- CLICKABLE CAMPUS MAP -->
                    <button
                        type="button"
                        class="pickup-map-link"
                        id="open-map"
                        aria-label="Open full campus map"
                    >
                        <img
                            src="../assets/images/coop-location.jpg"
                            alt="Campus map showing the cooperative location"
                            class="pickup-map"
                        >
                    </button>

                    <!-- CAMPUS MAP POPUP -->
                    <div
                        class="map-modal"
                        id="map-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Campus map"
                        aria-hidden="true"
                    >
                        <div class="map-modal-content">

                            <!-- Popup Header -->
                            <div class="map-modal-header">

                                <div>
                                    <h2>Campus Map</h2>
                                    <p>Find the PLM Cooperative</p>
                                </div>

                                <button
                                    type="button"
                                    class="map-modal-close"
                                    id="close-map"
                                    aria-label="Close map"
                                >
                                    &times;
                                </button>

                            </div>

                            <!-- Campus Map Image -->
                            <div class="map-modal-image">
                                <img
                                    src="../assets/images/coop-location.jpg"
                                    alt="Campus map showing the PLM Cooperative location"
                                >
                            </div>

                            <!-- Popup Footer -->
                            <div class="map-modal-footer">
                                <span>📍</span>
                                <p>PLM Cooperative – In front of the University Chapel</p>
                            </div>

                        </div>
                    </div>

                    <!-- PICKUP INFORMATION -->
                    <div class="pickup-info">

                        <p>
                            📍 <strong>Location:</strong><br>
                            In front of the University Chapel
                        </p>

                        <p>
                            📅 <strong>Operating Days:</strong><br>
                            Tuesdays – Fridays
                        </p>

                        <p>
                            🕒 <strong>Operating Hours:</strong><br>
                            8:00 AM – 5:00 PM
                        </p>

                        <p>
                            🛍️ Please collect your orders at the
                            school cooperative.
                        </p>

                    </div>

                </section>

                <!-- =================================
                     OTHER SERVICES
                ================================== -->
                <section class="sidebar-panel">

                    <h3>
                        Other Services
                    </h3>

                    <ul class="services-list">

                        <li>
                            <span class="service-emoji">🖨️</span>
                            Printing
                        </li>

                        <li>
                            <span class="service-emoji">📄</span>
                            Xerox
                        </li>

                        <li>
                            <span class="service-emoji">📚</span>
                            School Supplies
                        </li>

                    </ul>

                </section>

            </aside>

        </div>

    </main>

    <!-- =====================================
         FOOTER
    ====================================== -->
    <footer class="student-footer">
        &copy; <?= date('Y') ?> CampusCart. All rights reserved.
    </footer>

</div>


<!-- =====================================
     PRODUCT DETAILS POPUP
====================================== -->
<div
    class="product-modal"
    id="product-modal"
    role="dialog"
    aria-modal="true"
    aria-label="Product details"
    aria-hidden="true"
>
    <div class="product-modal-content">

        <div class="product-modal-header">
            <h2>Product Details</h2>

            <button
                type="button"
                class="product-modal-close"
                id="close-product-modal"
                aria-label="Close product details"
            >
                &times;
            </button>
        </div>

        <div class="product-modal-body">

            <div class="product-modal-image">
                <img
                    id="detail-product-image"
                    src=""
                    alt=""
                    hidden
                >

                <div id="detail-product-placeholder">
                    Product Image
                </div>
            </div>

            <div class="product-modal-info">

                <h3 id="detail-product-name"></h3>

                <p id="detail-product-description"></p>

                <div class="product-modal-price">
                    <span>Price</span>
                    <strong id="detail-product-price">₱0.00</strong>
                </div>

                <div class="product-modal-variant">
                    <label for="detail-variant-select">
                        Select Size / Variant
                    </label>

                    <select id="detail-variant-select">
                        <option value="">Select an option</option>
                    </select>
                </div>

                <!-- CSRF SECURITY TOKEN -->
                <input
                    type="hidden"
                    id="csrf-token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <!-- PRODUCT QUANTITY -->
                <div class="product-modal-quantity">
                    <label for="detail-product-quantity">
                        Quantity
                    </label>

                    <input
                        type="number"
                        id="detail-product-quantity"
                        value="1"
                        min="1"
                        max="1"
                    >
                </div>

                <div class="product-modal-stock">
                    <span>Available Stock:</span>
                    <strong id="detail-product-stock">-</strong>
                </div>

                <button
                    type="button"
                    id="detail-add-to-cart"
                    class="product-modal-add-button"
                    disabled
                >
                    Add to Cart
                </button>

            </div>

        </div>

    </div>
</div>


<script>


// =========================================
// PRODUCT DETAILS POPUP
// =========================================

const productModal = document.getElementById('product-modal');
const closeProductModal = document.getElementById('close-product-modal');

const detailProductImage = document.getElementById('detail-product-image');
const detailProductPlaceholder = document.getElementById('detail-product-placeholder');
const detailProductName = document.getElementById('detail-product-name');
const detailProductDescription = document.getElementById('detail-product-description');
const detailProductPrice = document.getElementById('detail-product-price');
const detailVariantSelect = document.getElementById('detail-variant-select');
const detailProductStock = document.getElementById('detail-product-stock');
const detailAddToCart = document.getElementById('detail-add-to-cart');

const detailProductQuantity = document.getElementById('detail-product-quantity');
const csrfToken = document.getElementById('csrf-token');

let selectedProduct = null;

// Format prices in Philippine pesos.
function formatProductPrice(price) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(Number(price));
}

// Display selected variant price and stock.
function updateProductVariant() {

    const selectedVariantId = detailVariantSelect.value;

    const variant = selectedProduct?.variants.find(item =>
        String(item.variant_id) === selectedVariantId
    );

    if (!variant) {
        detailProductPrice.textContent = 'Select a variant';
        detailProductStock.textContent = '-';
        detailAddToCart.disabled = true;
        detailProductQuantity.value = 1;
        detailProductQuantity.max = 1;
        return;
    }

    detailProductPrice.textContent =
        formatProductPrice(variant.price);

    const stock = Number(variant.stock_quantity);

    detailProductQuantity.max = Math.max(stock, 1);
    detailProductQuantity.value = 1;

    if (stock > 0) {
        detailProductStock.textContent = `${stock} available`;
        detailProductStock.classList.remove('out-of-stock');
        detailAddToCart.disabled = false;
    } else {
        detailProductStock.textContent = 'Out of Stock';
        detailProductStock.classList.add('out-of-stock');
        detailAddToCart.disabled = true;
    }
}

// Open Product Details popup.
function openProductDetails(productCard) {

    const variants = JSON.parse(
        productCard.dataset.productVariants || '[]'
    );

    selectedProduct = {
        id: productCard.dataset.productId,
        name: productCard.dataset.productName,
        description: productCard.dataset.productDescription,
        image: productCard.dataset.productImage,
        variants: variants
    };

    detailProductName.textContent = selectedProduct.name;

    detailProductDescription.textContent =
        selectedProduct.description || 'No description available.';

    // Product image.
    if (selectedProduct.image) {

        detailProductImage.src =
            '../' + selectedProduct.image.replace(/^\/+/, '');

        detailProductImage.alt = selectedProduct.name;
        detailProductImage.hidden = false;
        detailProductPlaceholder.hidden = true;

    } else {

        detailProductImage.hidden = true;
        detailProductPlaceholder.hidden = false;
    }

    // Reset variant dropdown.
    detailVariantSelect.innerHTML =
        '<option value="">Select an option</option>';

    // Approved product size ordering.
    const sizeOrder = [
        'small',
        'medium',
        'large',
        'small to large',
        'xl',
        '2xl',
        '3xl',
        '4xl',
        '5xl'
    ];

    const sortedVariants = [...selectedProduct.variants].sort((a, b) => {

        const sizeA = String(a.size || '').trim().toLowerCase();
        const sizeB = String(b.size || '').trim().toLowerCase();

        const indexA = sizeOrder.indexOf(sizeA);
        const indexB = sizeOrder.indexOf(sizeB);

        const orderA = indexA === -1 ? 999 : indexA;
        const orderB = indexB === -1 ? 999 : indexB;

        return orderA - orderB;
    });

    sortedVariants.forEach(variant => {

        const option = document.createElement('option');

        option.value = variant.variant_id;

        const variantLabel =
            variant.size && variant.variant_name &&
            variant.size !== variant.variant_name
                ? `${variant.size} - ${variant.variant_name}`
                : (variant.size || variant.variant_name || 'Standard');

        option.textContent = variantLabel;

        detailVariantSelect.appendChild(option);
    });

    // Automatically select Small when available.
    const smallVariant = selectedProduct.variants.find(variant =>
        String(variant.size || '').trim().toLowerCase() === 'small'
    );

    if (smallVariant) {

        detailVariantSelect.value = String(smallVariant.variant_id);
        updateProductVariant();

    } else if (selectedProduct.variants.length > 0) {

        detailVariantSelect.selectedIndex = 1;
        updateProductVariant();

    } else {

        detailProductPrice.textContent = 'Price unavailable';
        detailProductStock.textContent = 'Stock unavailable';
        detailAddToCart.disabled = true;
    }

    detailProductQuantity.value = 1;

    productModal.classList.add('active');
    productModal.setAttribute('aria-hidden', 'false');

    closeProductModal.focus();
}

// Make product cards clickable.
document.querySelectorAll('.product-card').forEach(card => {

    card.addEventListener('click', function() {
        openProductDetails(this);
    });

    card.addEventListener('keydown', function(event) {

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openProductDetails(this);
        }

    });

});

// Update price and stock when a variant is selected.
detailVariantSelect.addEventListener('change', updateProductVariant);

// Validate quantity against the selected variant's stock.
detailProductQuantity.addEventListener('input', function() {

    const selectedVariant = selectedProduct?.variants.find(item =>
        String(item.variant_id) === detailVariantSelect.value
    );

    if (!selectedVariant) return;

    const stock = Number(selectedVariant.stock_quantity);
    const quantity = Number(this.value);

    if (quantity > stock) {
        this.setCustomValidity(
            `Only ${stock} item(s) are available.`
        );
    } else if (quantity < 1 || !Number.isInteger(quantity)) {
        this.setCustomValidity('Please enter a valid quantity.');
    } else {
        this.setCustomValidity('');
    }
});

// =========================================
// ADD TO CART
// =========================================

detailAddToCart.addEventListener('click', async function() {

    const variantId = detailVariantSelect.value;
    const quantity = Number(detailProductQuantity.value);

    const selectedVariant = selectedProduct?.variants.find(item =>
        String(item.variant_id) === variantId
    );

    if (!selectedVariant) {
        alert('Please select a product variant.');
        return;
    }

    if (
        !Number.isInteger(quantity) ||
        quantity < 1 ||
        quantity > Number(selectedVariant.stock_quantity)
    ) {
        alert('Please enter a valid quantity within the available stock.');
        return;
    }

    const formData = new FormData();

    formData.append('variant_id', variantId);
    formData.append('quantity', quantity);
    formData.append('csrf_token', csrfToken.value);

    detailAddToCart.disabled = true;
    detailAddToCart.textContent = 'Adding...';

    try {

        const response = await fetch('add_to_cart.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Unable to add this product.'
            );
        }

        alert(result.message);

        hideProductDetails();

    } catch (error) {

        alert(error.message);

    } finally {

        detailAddToCart.textContent = 'Add to Cart';

        const currentVariant = selectedProduct?.variants.find(item =>
            String(item.variant_id) === detailVariantSelect.value
        );

        detailAddToCart.disabled =
            !currentVariant ||
            Number(currentVariant.stock_quantity) < 1;
    }

});

// Close Product Details popup.
function hideProductDetails() {
    productModal.classList.remove('active');
    productModal.setAttribute('aria-hidden', 'true');
}

closeProductModal.addEventListener('click', hideProductDetails);

productModal.addEventListener('click', function(event) {
    if (event.target === productModal) {
        hideProductDetails();
    }
});

document.addEventListener('keydown', function(event) {

    if (
        event.key === 'Escape' &&
        productModal.classList.contains('active')
    ) {
        hideProductDetails();
    }

});

// =========================================
// CALENDAR ELEMENTS
// =========================================

const monthCalendar = document.getElementById('month-calendar');
const monthYearLabel = document.getElementById('calendar-month-year');
const clockDisplay = document.getElementById('calendar-clock');

const today = new Date();

let displayedMonth = today.getMonth();
let displayedYear = today.getFullYear();

// =========================================
// RENDER MONTHLY CALENDAR
// =========================================

function renderCalendar() {

    const firstDay = new Date(
        displayedYear,
        displayedMonth,
        1
    ).getDay();

    const daysInMonth = new Date(
        displayedYear,
        displayedMonth + 1,
        0
    ).getDate();

    const monthName = new Date(
        displayedYear,
        displayedMonth
    ).toLocaleDateString('en-PH', {
        month: 'long'
    });

    monthYearLabel.textContent =
        `${monthName} ${displayedYear}`;

    const weekdays = [
        'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'
    ];

    let calendarHTML = '';

    weekdays.forEach(day => {
        calendarHTML += `
            <div class="calendar-weekday">${day}</div>
        `;
    });

    for (let i = 0; i < firstDay; i++) {
        calendarHTML += `
            <div class="calendar-date empty"></div>
        `;
    }

    for (let day = 1; day <= daysInMonth; day++) {

        const isToday =
            day === today.getDate() &&
            displayedMonth === today.getMonth() &&
            displayedYear === today.getFullYear();

        calendarHTML += `
            <div class="calendar-date ${isToday ? 'today' : ''}">
                ${day}
            </div>
        `;
    }

    monthCalendar.innerHTML = calendarHTML;
}

// =========================================
// DIGITAL CLOCK
// =========================================

function updateClock() {

    const now = new Date();

    clockDisplay.textContent = now.toLocaleTimeString(
        'en-PH',
        {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        }
    );
}

// Previous month.
document.getElementById('previous-month')
    .addEventListener('click', () => {

        displayedMonth--;

        if (displayedMonth < 0) {
            displayedMonth = 11;
            displayedYear--;
        }

        renderCalendar();
    });

// Next month.
document.getElementById('next-month')
    .addEventListener('click', () => {

        displayedMonth++;

        if (displayedMonth > 11) {
            displayedMonth = 0;
            displayedYear++;
        }

        renderCalendar();
    });

// Initialize calendar and clock.
renderCalendar();
updateClock();

setInterval(updateClock, 60000);

// =========================================
// CAMPUS MAP POPUP
// =========================================

const mapModal = document.getElementById('map-modal');
const openMap = document.getElementById('open-map');
const closeMap = document.getElementById('close-map');

function showMap() {

    mapModal.classList.add('active');
    mapModal.setAttribute('aria-hidden', 'false');

    closeMap.focus();
}

function hideMap() {

    mapModal.classList.remove('active');
    mapModal.setAttribute('aria-hidden', 'true');

    openMap.focus();
}

openMap.addEventListener('click', showMap);

closeMap.addEventListener('click', hideMap);

mapModal.addEventListener('click', function(event) {

    if (event.target === mapModal) {
        hideMap();
    }

});

document.addEventListener('keydown', function(event) {

    if (
        event.key === 'Escape' &&
        mapModal.classList.contains('active')
    ) {
        hideMap();
    }

});

</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const wrapper = document.getElementById('notification-wrapper');
    const trigger = document.getElementById('notification-trigger');
    const panel = document.getElementById('notification-panel');
    const closeButton = document.getElementById('notification-close');

    if (!wrapper || !trigger || !panel || !closeButton) {
        return;
    }

    function openNotifications() {
        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
    }

    function closeNotifications() {
        panel.classList.remove('active');
        panel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (event) {
        event.stopPropagation();

        if (panel.classList.contains('active')) {
            closeNotifications();
        } else {
            openNotifications();
        }
    });

    closeButton.addEventListener('click', function (event) {
        event.stopPropagation();
        closeNotifications();
    });

    document.addEventListener('click', function (event) {
        if (!wrapper.contains(event.target)) {
            closeNotifications();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeNotifications();
        }
    });
});
</script>

</body>
</html>