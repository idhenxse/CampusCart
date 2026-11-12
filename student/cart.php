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
// CSRF TOKEN
// =========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getCartTotals(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(ci.quantity), 0) AS total_quantity,
            COALESCE(SUM(ci.quantity * pv.price), 0) AS cart_subtotal
        FROM carts c
        INNER JOIN cart_items ci ON ci.cart_id = c.cart_id
        INNER JOIN product_variants pv ON pv.variant_id = ci.variant_id
        WHERE c.user_id = ?
    ");

    $stmt->execute([$user_id]);

    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_quantity' => (int) ($totals['total_quantity'] ?? 0),
        'cart_subtotal' => (float) ($totals['cart_subtotal'] ?? 0)
    ];
}

// =========================================
// AUTOMATIC QUANTITY UPDATE (AJAX)
// =========================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_quantity'
) {
    header('Content-Type: application/json; charset=UTF-8');

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals($_SESSION['csrf_token'], $submitted_token)
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Your session has expired. Please refresh the page.'
        ]);

        exit;
    }

    $cart_item_id = filter_var(
        $_POST['cart_item_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    $quantity = filter_var(
        $_POST['quantity'] ?? '',
        FILTER_VALIDATE_INT
    );

    if (!$cart_item_id || !$quantity || $quantity < 1) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid quantity.'
        ]);

        exit;
    }

    try {
        $pdo->beginTransaction();

        // Confirm the cart item belongs to this student.
        $itemStmt = $pdo->prepare("
            SELECT
                ci.cart_item_id,
                pv.price,
                COALESCE(i.stock_quantity, 0) AS stock_quantity
            FROM cart_items ci
            INNER JOIN carts c ON c.cart_id = ci.cart_id
            INNER JOIN product_variants pv ON pv.variant_id = ci.variant_id
            LEFT JOIN inventory i ON i.variant_id = pv.variant_id
            WHERE ci.cart_item_id = ?
              AND c.user_id = ?
            FOR UPDATE
        ");

        $itemStmt->execute([$cart_item_id, $user_id]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('This cart item could not be found.');
        }

        $stock = (int) $item['stock_quantity'];

        if ($stock < 1) {
            throw new Exception('This item is currently out of stock.');
        }

        if ($quantity > $stock) {
            throw new Exception(
                'Only ' . $stock . ' item(s) are currently available.'
            );
        }

        // Save the new quantity.
        $updateStmt = $pdo->prepare("
            UPDATE cart_items
            SET quantity = ?, updated_at = CURRENT_TIMESTAMP
            WHERE cart_item_id = ?
        ");

        $updateStmt->execute([$quantity, $cart_item_id]);

        $item_subtotal = (float) $item['price'] * $quantity;

        $pdo->commit();

        // Get the updated totals for the entire cart.
        $totals = getCartTotals($pdo, $user_id);

        echo json_encode([
            'success' => true,
            'message' => 'Your cart has been updated.',
            'quantity' => $quantity,
            'stock' => $stock,
            'item_subtotal' => $item_subtotal,
            'total_quantity' => $totals['total_quantity'],
            'cart_subtotal' => $totals['cart_subtotal']
        ]);

        exit;

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($ex->getMessage());

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to update your cart right now. Please try again.'
        ]);

        exit;

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => $ex->getMessage()
        ]);

        exit;
    }
}

// =========================================
// REMOVE CART ITEM
// =========================================
$message = '';
$message_type = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'remove'
) {
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $message = 'Your session has expired. Please refresh the page.';
        $message_type = 'error';

    } else {
        $cart_item_id = filter_var(
            $_POST['cart_item_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$cart_item_id) {
            $message = 'Invalid cart item.';
            $message_type = 'error';

        } else {
            try {
                $deleteStmt = $pdo->prepare("
                    DELETE ci
                    FROM cart_items ci
                    INNER JOIN carts c ON c.cart_id = ci.cart_id
                    WHERE ci.cart_item_id = ?
                      AND c.user_id = ?
                ");

                $deleteStmt->execute([$cart_item_id, $user_id]);

                if ($deleteStmt->rowCount() > 0) {
                    header('Location: cart.php?removed=1');
                    exit;
                }

                $message = 'The cart item could not be found.';
                $message_type = 'error';

            } catch (PDOException $ex) {
                error_log($ex->getMessage());

                $message = 'Unable to remove this item right now.';
                $message_type = 'error';
            }
        }
    }
}

if (isset($_GET['removed'])) {
    $message = 'Item removed from your cart.';
    $message_type = 'success';
}

// =========================================
// GET STUDENT CART
// =========================================
$cartStmt = $pdo->prepare("
    SELECT cart_id
    FROM carts
    WHERE user_id = ?
    LIMIT 1
");

$cartStmt->execute([$user_id]);
$cart = $cartStmt->fetch(PDO::FETCH_ASSOC);

$cart_items = [];

if ($cart) {
    $cart_id = (int) $cart['cart_id'];

    $itemsStmt = $pdo->prepare("
        SELECT
            ci.cart_item_id,
            ci.quantity,
            ci.added_at,
            pv.variant_id,
            pv.variant_name,
            pv.size,
            pv.price,
            p.product_name,
            p.product_image,
            COALESCE(i.stock_quantity, 0) AS stock_quantity
        FROM cart_items ci
        INNER JOIN product_variants pv
            ON pv.variant_id = ci.variant_id
        INNER JOIN products p
            ON p.product_id = pv.product_id
        LEFT JOIN inventory i
            ON i.variant_id = pv.variant_id
        WHERE ci.cart_id = ?
        ORDER BY ci.added_at DESC
    ");

    $itemsStmt->execute([$cart_id]);
    $cart_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$totals = getCartTotals($pdo, $user_id);

$total_quantity = $totals['total_quantity'];
$cart_subtotal = $totals['cart_subtotal'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Shopping Cart | CampusCart</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student.css">
    <link rel="stylesheet" href="../assets/css/cart.css">
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

        <a href="notifications.php" class="header-action"
           title="Notifications" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                <path d="M10 21h4"/>
            </svg>
        </a>

        <a href="menu.php" class="header-action"
           title="Student Menu" aria-label="Student Menu">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 6h16"/>
                <path d="M4 12h16"/>
                <path d="M4 18h16"/>
            </svg>
        </a>

    </nav>
</header>

<!-- =========================================
     MAIN CART CONTENT
========================================= -->
<main class="student-content cart-page-content">

    <!-- PAGE TITLE -->
    <section class="cart-heading">

        <div>
            <h2>My Shopping Cart</h2>
            <p>Review your selected items before checkout.</p>
        </div>

        <a href="dashboard.php" class="continue-shopping">
            ← Continue Shopping
        </a>

    </section>

    <!-- FEEDBACK MESSAGE -->
    <?php if ($message !== ''): ?>
        <div class="cart-message <?= e($message_type) ?>" role="status">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div
        class="cart-message cart-live-message"
        id="cart-live-message"
        role="status"
        aria-live="polite"
        hidden
    ></div>

    <!-- =====================================
         EMPTY CART
    ====================================== -->
    <?php if (empty($cart_items)): ?>

        <section class="empty-cart">

            <div class="empty-cart-inner">

                <div class="empty-cart-icon">🛒</div>

                <h3>Your Cart is Empty</h3>

                <p>
                    You haven't added any products to your cart yet.
                    Browse the cooperative store and find what you need!
                </p>

            </div>

            <a href="dashboard.php" class="cart-primary-button">
                BROWSE PRODUCTS
            </a>

        </section>

    <?php else: ?>

        <!-- =================================
             PRODUCT AND SUMMARY PAIRS
        ================================== -->
        <section class="cart-layout" id="cart-layout">

            <?php foreach ($cart_items as $item): ?>

                <?php
                    $item_id = (int) $item['cart_item_id'];
                    $item_quantity = (int) $item['quantity'];
                    $item_stock = (int) $item['stock_quantity'];
                    $item_price = (float) $item['price'];
                    $item_subtotal = $item_price * $item_quantity;

                    $item_name = $item['product_name'];

                    // Display the combined Tote Bag product name.
                    if (str_starts_with($item_name, 'Tote Bag')) {
                        $item_name = 'Tote Bag';
                    }

                    $variant_label = [];

                    if (!empty($item['size'])) {
                        $variant_label[] = $item['size'];
                    }

                    if (
                        !empty($item['variant_name']) &&
                        strtolower($item['variant_name']) !== 'standard' &&
                        strtolower($item['variant_name']) !== strtolower($item['size'] ?? '')
                    ) {
                        $variant_label[] = $item['variant_name'];
                    }

                    $image_path = '';

                    if (!empty($item['product_image'])) {
                        $image_path = '../' . ltrim($item['product_image'], '/');
                    }
                ?>

                <div class="cart-item-row" data-cart-row="<?= $item_id ?>">

                    <!-- PRODUCT CARD -->
                    <article class="cart-item">

                        <div class="cart-item-image">

                            <?php if ($image_path !== ''): ?>
                                <img
                                    src="<?= e($image_path) ?>"
                                    alt="<?= e($item_name) ?>"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                >
                            <?php endif; ?>

                            <div
                                class="cart-image-placeholder"
                                style="<?= $image_path !== '' ? 'display:none;' : '' ?>"
                            >
                                Product Image
                            </div>

                        </div>

                        <div class="cart-item-details">

                            <h4><?= e($item_name) ?></h4>

                            <?php if (!empty($variant_label)): ?>
                                <p class="cart-item-variant">
                                    <?= e(implode(' / ', $variant_label)) ?>
                                </p>
                            <?php endif; ?>

                            <p class="cart-item-price">
                                ₱<?= number_format($item_price, 2) ?>
                            </p>

                            <p class="cart-stock">
                                Available Stock: <?= $item_stock ?>
                            </p>

                            <strong
                                class="cart-item-subtotal"
                                id="item-subtotal-<?= $item_id ?>"
                            >
                                Subtotal: ₱<?= number_format($item_subtotal, 2) ?>
                            </strong>

                            <div class="cart-item-controls">

                                <div class="cart-quantity">
                                    <label for="quantity-<?= $item_id ?>">
                                        Quantity
                                    </label>

                                    <input
                                        type="number"
                                        class="cart-quantity-input"
                                        id="quantity-<?= $item_id ?>"
                                        data-cart-item-id="<?= $item_id ?>"
                                        data-original-quantity="<?= $item_quantity ?>"
                                        value="<?= $item_quantity ?>"
                                        min="1"
                                        max="<?= $item_stock ?>"
                                        <?= $item_stock < 1 ? 'disabled' : '' ?>
                                    >
                                </div>

                                <form
                                    method="POST"
                                    action="cart.php"
                                    onsubmit="return confirm('Remove this item from your cart?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input type="hidden" name="action" value="remove">

                                    <input
                                        type="hidden"
                                        name="cart_item_id"
                                        value="<?= $item_id ?>"
                                    >

                                    <button type="submit" class="cart-remove-button">
                                        REMOVE
                                    </button>
                                </form>

                            </div>

                            <?php if ($item_stock < 1): ?>
                                <p class="cart-stock-warning">
                                    This item is currently out of stock.
                                </p>
                            <?php endif; ?>

                        </div>

                    </article>

                    <!-- ORDER SUMMARY CARD -->
                    <aside class="cart-summary-panel">

                        <h3>Order Summary</h3>

                        <div class="cart-summary-row">
                            <span>Total Quantity</span>
                            <strong id="summary-quantity-<?= $item_id ?>">
                                <?= $item_quantity ?>
                            </strong>
                        </div>

                        <div class="cart-summary-total">
                            <span>Subtotal:</span>
                            <strong id="summary-subtotal-<?= $item_id ?>">
                                ₱<?= number_format($item_subtotal, 2) ?>
                            </strong>
                        </div>

                        <a
                            href="checkout.php"
                            class="cart-checkout-button <?= $item_stock < 1 ? 'checkout-disabled' : '' ?>"
                        >
                            PROCEED TO CHECKOUT
                        </a>

                        <p class="cart-summary-note">
                            Note: Pickup at School Coop
                        </p>

                    </aside>

                </div>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

</main>

<!-- =========================================
     FOOTER
========================================= -->
<footer class="student-footer">
    © <?= date('Y') ?> CampusCart | Pamantasan ng Lungsod ng Maynila
</footer>

<!-- =========================================
     AUTOMATIC CART QUANTITY UPDATE
========================================= -->
<script>
const cartCsrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;

const cartLiveMessage = document.getElementById('cart-live-message');

function formatCartPrice(amount) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(Number(amount));
}

function showCartMessage(message, type = 'success') {
    if (!cartLiveMessage) return;

    cartLiveMessage.textContent = message;
    cartLiveMessage.className =
        `cart-message cart-live-message ${type}`;

    cartLiveMessage.hidden = false;
}

document.querySelectorAll('.cart-quantity-input').forEach(input => {

    input.addEventListener('change', async function () {

        const cartItemId = this.dataset.cartItemId;
        const previousQuantity = Number(this.dataset.originalQuantity);
        const quantity = Number(this.value);
        const stock = Number(this.max);

        if (!Number.isInteger(quantity) || quantity < 1) {
            this.value = previousQuantity;

            showCartMessage(
                'Please enter a quantity of at least 1.',
                'error'
            );

            return;
        }

        if (quantity > stock) {
            this.value = previousQuantity;

            showCartMessage(
                `Only ${stock} item(s) are currently available.`,
                'error'
            );

            return;
        }

        if (quantity === previousQuantity) return;

        this.disabled = true;

        const formData = new FormData();

        formData.append('action', 'update_quantity');
        formData.append('cart_item_id', cartItemId);
        formData.append('quantity', quantity);
        formData.append('csrf_token', cartCsrfToken);

        try {
            const response = await fetch('cart.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Unable to update your cart.'
                );
            }

            // Save the updated quantity in the input.
            this.dataset.originalQuantity = String(result.quantity);
            this.value = result.quantity;
            this.max = result.stock;

            // Update the product's own subtotal.
            const itemSubtotal = document.getElementById(
                `item-subtotal-${cartItemId}`
            );

            if (itemSubtotal) {
                itemSubtotal.textContent =
                    `Subtotal: ${formatCartPrice(result.item_subtotal)}`;
            }

            // Update only the Order Summary beside the changed product.
            const summaryQuantity = document.getElementById(
                `summary-quantity-${cartItemId}`
            );

            const summarySubtotal = document.getElementById(
                `summary-subtotal-${cartItemId}`
            );

            if (summaryQuantity) {
                summaryQuantity.textContent = result.quantity;
            }

            if (summarySubtotal) {
                summarySubtotal.textContent =
                    formatCartPrice(result.item_subtotal);
            }

            showCartMessage('Your cart has been updated.', 'success');

        } catch (error) {
            this.value = previousQuantity;

            showCartMessage(error.message, 'error');

        } finally {
            this.disabled = false;
        }
    });
});
</script>

</body>
</html>
