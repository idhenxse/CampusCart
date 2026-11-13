<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';

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
// CSRF TOKEN
// =========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$error_message = '';
$selected_payment = $_POST['payment_method'] ?? 'cash_on_pickup';

// =========================================
// LOAD SELECTED STUDENT CART ITEMS
// =========================================

// Receive selected items when coming from cart.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_items'])) {

    $selected_item_ids = array_values(array_unique(array_filter(
        array_map('intval', (array) $_POST['selected_items']),
        fn($id) => $id > 0
    )));

    if (!empty($selected_item_ids)) {

        $_SESSION['checkout_selected_items'] = $selected_item_ids;

        // Redirect to Checkout using GET to prevent form resubmission.
        header('Location: checkout.php');
        exit;
    }
}

// Retrieve the selected items from the session.
$selected_item_ids = $_SESSION['checkout_selected_items'] ?? [];

if (empty($selected_item_ids)) {
    header('Location: cart.php');
    exit;
}

// Load only the selected items belonging to this student.
function loadCheckoutCart(PDO $pdo, int $user_id, array $selected_ids): array
{
    if (empty($selected_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

    $stmt = $pdo->prepare("
        SELECT
            ci.cart_item_id,
            ci.variant_id,
            ci.quantity,
            pv.price,
            p.product_name,
            p.product_image,
            p.is_archived,
            COALESCE(i.stock_quantity, 0) AS stock_quantity,
            i.inventory_id,
            i.low_stock_threshold
        FROM carts c
        INNER JOIN cart_items ci
            ON ci.cart_id = c.cart_id
        INNER JOIN product_variants pv
            ON pv.variant_id = ci.variant_id
        INNER JOIN products p
            ON p.product_id = pv.product_id
        LEFT JOIN inventory i
            ON i.variant_id = pv.variant_id
        WHERE c.user_id = ?
          AND ci.cart_item_id IN ($placeholders)
          AND p.is_archived = 0
        ORDER BY ci.added_at DESC
    ");

    $stmt->execute(array_merge([$user_id], $selected_ids));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$cart_items = loadCheckoutCart($pdo, $user_id, $selected_item_ids);

if (count($cart_items) !== count($selected_item_ids)) {
    unset($_SESSION['checkout_selected_items']);

    header('Location: cart.php');
    exit;
}

$total_quantity = 0;
$cart_subtotal = 0;

foreach ($cart_items as $item) {
    $total_quantity += (int) $item['quantity'];

    $cart_subtotal +=
        (float) $item['price'] * (int) $item['quantity'];
}

// =========================================
// CHECKOUT FORM SUBMISSION
// =========================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['payment_method'])
) {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {

        $error_message = 'Your session has expired. Please refresh the page and try again.';

    } else {

        $payment_method = $_POST['payment_method'] ?? '';

        if (!in_array(
            $payment_method,
            ['cash_on_pickup', 'gcash'],
            true
        )) {
            $error_message = 'Please select a valid payment method.';

        } elseif ($payment_method === 'gcash') {

            $reference_number = trim($_POST['reference_number'] ?? '');

            if ($reference_number === '') {
                $error_message = 'Please enter your GCash reference number.';

            } elseif (!preg_match('/^[0-9]{13}$/', $reference_number)) {
                $error_message = 'Your GCash reference number must contain exactly 13 digits.';

            } elseif (
                !isset($_FILES['payment_screenshot']) ||
                $_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK
            ) {
                $error_message = 'Please upload your GCash payment screenshot.';
            }
        }

        if ($error_message === '') {

            $screenshot_path = null;
            $saved_screenshot = null;

            // =====================================
            // VALIDATE GCASH SCREENSHOT
            // =====================================
            if ($payment_method === 'gcash') {

                $file = $_FILES['payment_screenshot'];

                if ($file['size'] > 5 * 1024 * 1024) {

                    $error_message = 'The screenshot must not exceed 5 MB.';

                } else {

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime_type = $finfo->file($file['tmp_name']);

                    $allowed_types = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png'
                    ];

                    if (!isset($allowed_types[$mime_type])) {

                        $error_message = 'Please upload a JPG or PNG image only.';

                    } else {

                        $upload_directory =
                            __DIR__ . '/../uploads/payment_screenshots/';

                        if (
                            !is_dir($upload_directory) &&
                            !mkdir($upload_directory, 0755, true)
                        ) {
                            $error_message = 'Unable to prepare the screenshot upload folder.';

                        } else {

                            $filename =
                                bin2hex(random_bytes(16)) .
                                '.' .
                                $allowed_types[$mime_type];

                            $destination = $upload_directory . $filename;

                            if (
                                move_uploaded_file(
                                    $file['tmp_name'],
                                    $destination
                                )
                            ) {
                                $saved_screenshot = $destination;

                                $screenshot_path =
                                    'uploads/payment_screenshots/' . $filename;

                            } else {
                                $error_message = 'Unable to upload your screenshot. Please try again.';
                            }
                        }
                    }
                }
            }

            // =====================================
            // CREATE ORDER
            // =====================================
            if ($error_message === '') {

                try {

                    $pdo->beginTransaction();

                    // Lock and retrieve the student's cart items.
                    $cartStmt = $pdo->prepare("
                        SELECT cart_id
                        FROM carts
                        WHERE user_id = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $cartStmt->execute([$user_id]);
                    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$cart) {
                        throw new Exception('Your cart could not be found.');
                    }

                    $cart_id = (int) $cart['cart_id'];

                    $selected_item_ids = $_SESSION['checkout_selected_items'] ?? [];

                    if (empty($selected_item_ids)) {
                        throw new Exception('Please return to your cart and select the items you want to purchase.');
                    }

                    $placeholders = implode(',', array_fill(0, count($selected_item_ids), '?'));

                    // Lock and retrieve only the selected cart items.
                    $itemsStmt = $pdo->prepare("
                        SELECT
                            ci.cart_item_id,
                            ci.variant_id,
                            ci.quantity,
                            pv.price,
                            p.product_name,
                            p.is_archived,
                            COALESCE(i.stock_quantity, 0) AS stock_quantity,
                            i.inventory_id,
                            i.low_stock_threshold
                        FROM cart_items ci
                        INNER JOIN product_variants pv
                            ON pv.variant_id = ci.variant_id
                        INNER JOIN products p
                            ON p.product_id = pv.product_id
                        LEFT JOIN inventory i
                            ON i.variant_id = pv.variant_id
                        WHERE ci.cart_id = ?
                        AND ci.cart_item_id IN ($placeholders)
                        ORDER BY ci.cart_item_id
                        FOR UPDATE
                    ");

                    $itemsStmt->execute(array_merge([$cart_id], $selected_item_ids));
                    $locked_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($locked_items) !== count($selected_item_ids)) {
                        throw new Exception('Some selected items are no longer in your cart. Please return and review your selection.');
                    }

                    if (empty($locked_items)) {
                        throw new Exception('Your cart is empty.');
                    }

                    $verified_total = 0;
                    $verified_quantity = 0;

                    foreach ($locked_items as $item) {

                        $quantity = (int) $item['quantity'];
                        $stock = (int) $item['stock_quantity'];

                        if ((int) $item['is_archived'] === 1) {
                            throw new Exception(
                                'One or more selected items are no longer available. Please return to your cart.'
                            );
                        }

                        if (empty($item['inventory_id'])) {
                            throw new Exception('An item in your cart has no inventory record.');
                        }

                        if ($stock < 1 || $quantity > $stock) {
                            throw new Exception(
                                'One or more products no longer have enough stock. Please return to your cart and review your quantities.'
                            );
                        }

                        $verified_total +=
                            (float) $item['price'] * $quantity;

                        $verified_quantity += $quantity;
                    }

                    // Generate a unique order number.
                    $order_number = 'CC-' .
                        date('Ymd') . '-' .
                        strtoupper(bin2hex(random_bytes(3)));

                    // Create the order.
                    $orderStmt = $pdo->prepare("
                        INSERT INTO orders (
                            user_id,
                            order_number,
                            total_amount,
                            payment_method,
                            order_status
                        )
                        VALUES (?, ?, ?, ?, 'pending')
                    ");

                    $orderStmt->execute([
                        $user_id,
                        $order_number,
                        $verified_total,
                        $payment_method
                    ]);

                    $order_id = (int) $pdo->lastInsertId();

                    // Save each order item.
                    $orderItemStmt = $pdo->prepare("
                        INSERT INTO order_items (
                            order_id,
                            variant_id,
                            quantity,
                            unit_price,
                            subtotal
                        )
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    // Deduct the ordered quantities from inventory.
                    $stockStmt = $pdo->prepare("
                        UPDATE inventory
                        SET
                            stock_quantity = stock_quantity - ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE variant_id = ?
                          AND stock_quantity >= ?
                    ");

                    foreach ($locked_items as $item) {

                        $quantity = (int) $item['quantity'];
                        $price = (float) $item['price'];
                        $subtotal = $price * $quantity;

                        $orderItemStmt->execute([
                            $order_id,
                            $item['variant_id'],
                            $quantity,
                            $price,
                            $subtotal
                        ]);

                        $stockStmt->execute([
                            $quantity,
                            $item['variant_id'],
                            $quantity
                        ]);

                        if ($stockStmt->rowCount() !== 1) {
                            throw new Exception(
                                'Stock changed while placing your order. Please review your cart.'
                            );
                        }

                        // Check whether the stock level crossed a notification threshold.
                        $previous_stock = (int) $item['stock_quantity'];
                        $new_stock = $previous_stock - $quantity;
                        $low_stock_threshold = (int) $item['low_stock_threshold'];

                        notifyAdminStockLevel(
                            $pdo,
                            (int) $item['inventory_id'],
                            $item['product_name'],
                            $previous_stock,
                            $new_stock,
                            $low_stock_threshold
                        );

                    }

                    // Create the payment record.
                    $paymentStmt = $pdo->prepare("
                        INSERT INTO payments (
                            order_id,
                            payment_status,
                            reference_number,
                            payment_screenshot
                        )
                        VALUES (?, 'pending', ?, ?)
                    ");

                    $paymentStmt->execute([
                        $order_id,
                        $payment_method === 'gcash'
                            ? $reference_number
                            : null,
                        $screenshot_path
                    ]);

                    // Create a notification for the student.
                    $notificationStmt = $pdo->prepare("
                        INSERT INTO notifications (
                            user_id,
                            title,
                            message,
                            notification_type,
                            related_id,
                            is_read
                        )
                        VALUES (?, ?, ?, ?, ?, 0)
                    ");

                    $notificationStmt->execute([
                        $user_id,
                        'Order Placed Successfully',
                        'Your order ' . $order_number . ' has been placed successfully and is now pending confirmation.',
                        'order_placed',
                        $order_id
                    ]);

                    // Notify Admin about the new order.
                    createAdminNotification(
                        $pdo,
                        'New Order Received',
                        'A new order ' . $order_number . ' has been placed and is awaiting confirmation.',
                        'new_order',
                        (int) $order_id
                    );

                    // Notify Admin when a GCash payment is submitted.
                    if ($payment_method === 'gcash') {
                        createAdminNotification(
                            $pdo,
                            'Payment Submitted',
                            'A GCash payment has been submitted for order ' . $order_number . ' and is awaiting verification.',
                            'payment_submitted',
                            (int) $order_id
                        );
                    }

                    // Remove only the selected items after successful order creation.
                    $clearCartStmt = $pdo->prepare("
                        DELETE FROM cart_items
                        WHERE cart_id = ?
                        AND cart_item_id IN ($placeholders)
                    ");

                    $clearCartStmt->execute(
                        array_merge([$cart_id], $selected_item_ids)
                    );

                    $pdo->commit();

                    // Clear the selected checkout items after a successful commit.
                    unset($_SESSION['checkout_selected_items']);

                    header(
                        'Location: orders.php?placed=1&order=' .
                        urlencode($order_number)
                    );

                    exit;

                } catch (Throwable $ex) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    // Remove an uploaded screenshot if order creation failed.
                    if (
                        $saved_screenshot !== null &&
                        file_exists($saved_screenshot)
                    ) {
                        unlink($saved_screenshot);
                    }

                    error_log($ex->getMessage());

                    $error_message = $ex instanceof PDOException
                        ? 'We could not place your order right now. Please try again.'
                        : $ex->getMessage();
                }
            }
        }
    }

    // Reload selected cart data if checkout submission failed.
    $cart_items = loadCheckoutCart(
        $pdo,
        $user_id,
        $_SESSION['checkout_selected_items'] ?? []
    );

    $total_quantity = 0;
    $cart_subtotal = 0;

    foreach ($cart_items as $item) {
        $total_quantity += (int) $item['quantity'];

        $cart_subtotal +=
            (float) $item['price'] * (int) $item['quantity'];
    }
}

// =========================================
// DISPLAY HELPERS
// =========================================
function checkoutProductName(array $item): string
{
    if (str_starts_with($item['product_name'], 'Tote Bag')) {
        return 'Tote Bag';
    }

    return $item['product_name'];
}

function checkoutVariantLabel(array $item): string
{
    $labels = [];

    if (!empty($item['size'])) {
        $labels[] = $item['size'];
    }

    if (
        !empty($item['variant_name']) &&
        strtolower($item['variant_name']) !== 'standard' &&
        strtolower($item['variant_name']) !== strtolower($item['size'] ?? '')
    ) {
        $labels[] = $item['variant_name'];
    }

    return implode(' / ', $labels);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Checkout | CampusCart</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student.css">
    <link rel="stylesheet" href="../assets/css/checkout.css">
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

        <a href="dashboard.php" class="header-action" title="Home">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m3 10 9-7 9 7"/>
                <path d="M5 9v12h14V9"/>
                <path d="M9 21v-7h6v7"/>
            </svg>
        </a>

        <a href="cart.php" class="header-action" title="Shopping Cart">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="9" cy="21" r="1"/>
                <circle cx="19" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                <path d="M8 10h12"/>
            </svg>
        </a>

        <a href="orders.php" class="header-action" title="My Orders">
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

<main class="student-content checkout-page-content">

    <!-- PAGE HEADING -->
    <section class="checkout-heading">
        <div>
            <h2>Checkout</h2>
            <p>Review your order and select your payment method.</p>
        </div>

        <a href="cart.php" class="checkout-continue-shopping">
            ← Back to Cart
        </a>
    </section>

    <?php if ($error_message !== ''): ?>
        <div class="checkout-message error" role="alert">
            <?= e($error_message) ?>
        </div>
    <?php endif; ?>

    <form
        method="POST"
        action="checkout.php"
        enctype="multipart/form-data"
        id="checkout-form"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <div class="checkout-layout">

            <!-- =================================
                 LEFT SIDE
            ================================== -->
            <div class="checkout-main-column">

                <!-- ORDER SUMMARY -->
                <section class="checkout-glass-card checkout-order-card">

                    <h3>Order Summary</h3>

                    <div class="checkout-order-list">

                        <?php foreach ($cart_items as $item): ?>

                            <?php
                                $image_path = !empty($item['product_image'])
                                    ? '../' . ltrim($item['product_image'], '/')
                                    : '../assets/images/products/Logo.jpg';

                                $quantity = (int) $item['quantity'];
                                $price = (float) $item['price'];
                                $subtotal = $price * $quantity;
                            ?>

                            <div class="checkout-order-item">

                                <div class="checkout-product-image">

                                    <img
                                        src="<?= e($image_path) ?>"
                                        alt="<?= e(checkoutProductName($item)) ?>"
                                        onerror="this.onerror=null; this.src='../assets/images/products/Logo.jpg';"
                                    >

                                </div>

                                <div class="checkout-product-details">

                                    <h4>
                                        <?= e(checkoutProductName($item)) ?>
                                    </h4>

                                    <?php if (checkoutVariantLabel($item) !== ''): ?>
                                        <p>
                                            <?= e(checkoutVariantLabel($item)) ?>
                                        </p>
                                    <?php endif; ?>

                                    <p>
                                        Quantity: <?= $quantity ?>
                                    </p>

                                </div>

                                <strong class="checkout-item-subtotal">
                                    ₱<?= number_format($subtotal, 2) ?>
                                </strong>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div class="checkout-total-row">
                        <span>Total Amount:</span>

                        <strong>
                            ₱<?= number_format($cart_subtotal, 2) ?>
                        </strong>
                    </div>

                </section>

                <!-- PICKUP INFORMATION -->
                <section class="checkout-glass-card pickup-information-card">

                    <h3>Pickup Information</h3>

                    <div class="pickup-information-content">

                        <p><strong>📍 Location:</strong> In front of the PLM Chapel</p>

                        <p><strong>📅 Operating Days:</strong> Tuesdays–Fridays</p>

                        <p><strong>🕗 Operating Hours:</strong> 8:00 AM–5:00 PM</p>

                        <p class="pickup-reminder">
                            Please proceed to the PLM Cooperative Store
                            during operating hours to collect your order.
                        </p>

                    </div>

                </section>

                <!-- GCASH ONLINE PAYMENT INFORMATION -->
                <section
                    class="checkout-glass-card online-payment-information"
                    id="online-payment-information"
                    hidden
                >

                    <h3>Online Payment</h3>

                    <div class="online-payment-content">

                        <p>💸 Please send your payment through the official CampusCart GCash account shown in the Payment Method section.</p>

                        <p>🧾 After sending your payment, enter your GCash reference number and upload a screenshot of the successful transaction.</p>

                        <p>⏳ Your payment will remain pending until it has been verified by the cooperative staff.</p>

                    </div>

                </section>

            </div>

            <!-- =================================
                 RIGHT SIDE: PAYMENT METHOD
            ================================== -->
            <aside class="checkout-payment-card checkout-glass-card">

                <h3>Payment Method</h3>

                <label for="payment-method" class="checkout-field-label">
                    Select Payment Method
                </label>

                <select
                    name="payment_method"
                    id="payment-method"
                    required
                >
                    <option
                        value="cash_on_pickup"
                        <?= $selected_payment === 'cash_on_pickup' ? 'selected' : '' ?>
                    >
                        Cash on Pickup
                    </option>

                    <option
                        value="gcash"
                        <?= $selected_payment === 'gcash' ? 'selected' : '' ?>
                    >
                        Online Payment (GCash)
                    </option>
                </select>

                <!-- CASH ON PICKUP DETAILS -->
                <div
                    class="cash-payment-details"
                    id="cash-payment-details"
                >

                    <h4>Cash on Pickup</h4>

                    <p>
                        Please prepare the exact payment amount when
                        collecting your order at the cooperative.
                    </p>

                    <p>
                        Payment will be collected by the cooperative
                        staff upon pickup.
                    </p>

                </div>

                <!-- GCASH DETAILS -->
                <div
                    class="gcash-payment-details"
                    id="gcash-payment-details"
                    hidden
                >
                    <div class="gcash-account-box">

                        <h4>CampusCart GCash Account</h4>

                        <p>Account Name</p>
                        <strong>CampusCart</strong>

                        <p>GCash Number</p>
                        <strong>09123456789</strong>

                    </div>

                    <div class="gcash-instructions">

                        <h4>Payment Instructions</h4>

                        <ol>
                            <li>Open your GCash application.</li>
                            <li>Select Send Money.</li>
                            <li>Enter the official CampusCart GCash number.</li>
                            <li>Enter the exact order total.</li>
                            <li>Confirm your payment.</li>
                            <li>Take a screenshot of the successful transaction.</li>
                        </ol>

                    </div>

                    <div class="checkout-form-field">

                        <label for="reference-number">
                            GCash Reference Number
                        </label>

                        <input
                            type="text"
                            name="reference_number"
                            id="reference-number"
                            maxlength="13"
                            minlength="13"
                            pattern="[0-9]{13}"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="Enter your reference number"
                            value="<?= e($_POST['reference_number'] ?? '') ?>"
                        >

                    </div>

                    <div class="checkout-form-field">

                        <label for="payment-screenshot">
                            Payment Screenshot
                        </label>

                        <input
                            type="file"
                            name="payment_screenshot"
                            id="payment-screenshot"
                            accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                        >

                        <small>
                            JPG or PNG only. Maximum file size: 5 MB.
                        </small>

                    </div>

                </div>

                <div class="checkout-payment-actions">

                    <button
                        type="submit"
                        class="checkout-confirm-button"
                        id="confirm-order-button"
                    >
                        CONFIRM ORDER
                    </button>

                    <a href="cart.php" class="checkout-return-button">
                        RETURN TO CART
                    </a>

                </div>

            </aside>

        </div>

    </form>

</main>

<footer class="student-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<script>
const paymentMethod = document.getElementById('payment-method');

const cashDetails = document.getElementById('cash-payment-details');
const gcashDetails = document.getElementById('gcash-payment-details');

const onlinePaymentInformation =
    document.getElementById('online-payment-information');

const referenceNumber =
    document.getElementById('reference-number');

const paymentScreenshot =
    document.getElementById('payment-screenshot');

const checkoutForm = document.getElementById('checkout-form');
const confirmButton = document.getElementById('confirm-order-button');

function updatePaymentDisplay() {

    const isGcash = paymentMethod.value === 'gcash';

    cashDetails.hidden = isGcash;
    gcashDetails.hidden = !isGcash;
    onlinePaymentInformation.hidden = !isGcash;

    referenceNumber.required = isGcash;
    paymentScreenshot.required = isGcash;
}

paymentMethod.addEventListener('change', updatePaymentDisplay);

// Set the correct payment layout when the page loads.
updatePaymentDisplay();

// Prevent accidental multiple submissions.
checkoutForm.addEventListener('submit', function () {

    if (checkoutForm.dataset.submitting === 'true') {
        return;
    }

    checkoutForm.dataset.submitting = 'true';

    confirmButton.disabled = true;
    confirmButton.textContent = 'PROCESSING ORDER...';
});

</script>

<script src="../assets/js/student-menu.js"></script>
<script src="../assets/js/notification.js"></script>

</body>
</html>
