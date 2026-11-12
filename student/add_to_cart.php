
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
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'success' => false,
        'message' => 'Please log in to add products to your cart.'
    ]);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$user_id = (int) $_SESSION['user_id'];

// =========================================
// ACCEPT POST REQUESTS ONLY
// =========================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// =========================================
// CSRF VALIDATION
// =========================================
$submitted_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submitted_token)
) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired. Please refresh the page.'
    ]);
    exit;
}

// =========================================
// VALIDATE PRODUCT VARIANT AND QUANTITY
// =========================================
$variant_id = filter_var(
    $_POST['variant_id'] ?? '',
    FILTER_VALIDATE_INT
);

$quantity = filter_var(
    $_POST['quantity'] ?? '',
    FILTER_VALIDATE_INT
);

if (!$variant_id || !$quantity || $quantity < 1) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Please select a valid product and quantity.'
    ]);
    exit;
}

try {

    // =========================================
    // START DATABASE TRANSACTION
    // =========================================
    $pdo->beginTransaction();

    // =========================================
    // FIND OR CREATE STUDENT CART
    // =========================================
    $cartStmt = $pdo->prepare("
        INSERT INTO carts (user_id, created_at, updated_at)
        VALUES (?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            cart_id = LAST_INSERT_ID(cart_id),
            updated_at = CURRENT_TIMESTAMP
    ");

    $cartStmt->execute([$user_id]);

    $cart_id = (int) $pdo->lastInsertId();

    // =========================================
    // CHECK PRODUCT VARIANT AND AVAILABLE STOCK
    // Lock the inventory record during this transaction.
    // =========================================
    $variantStmt = $pdo->prepare("
        SELECT
            pv.variant_id,
            pv.price,
            pv.product_id,
            COALESCE(i.stock_quantity, 0) AS stock_quantity
        FROM product_variants pv
        LEFT JOIN inventory i
            ON pv.variant_id = i.variant_id
        WHERE pv.variant_id = ?
        FOR UPDATE
    ");

    $variantStmt->execute([$variant_id]);

    $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$variant) {
        throw new Exception('The selected product variant could not be found.');
    }

    $stock = (int) $variant['stock_quantity'];

    if ($stock < 1) {
        throw new Exception('Sorry, this product is currently out of stock.');
    }

    // =========================================
    // CHECK IF THIS VARIANT IS ALREADY IN CART
    // =========================================
    $existingStmt = $pdo->prepare("
        SELECT cart_item_id, quantity
        FROM cart_items
        WHERE cart_id = ?
          AND variant_id = ?
        FOR UPDATE
    ");

    $existingStmt->execute([$cart_id, $variant_id]);

    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);

    $current_quantity = $existingItem
        ? (int) $existingItem['quantity']
        : 0;

    $new_quantity = $current_quantity + $quantity;

    // =========================================
    // PREVENT QUANTITY FROM EXCEEDING STOCK
    // =========================================
    if ($new_quantity > $stock) {
        throw new Exception(
            'Only ' . $stock . ' item(s) are available. ' .
            'You already have ' . $current_quantity .
            ' in your cart.'
        );
    }

    // =========================================
    // UPDATE EXISTING CART ITEM OR INSERT NEW ONE
    // =========================================
    if ($existingItem) {

        $updateStmt = $pdo->prepare("
            UPDATE cart_items
            SET quantity = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE cart_item_id = ?
        ");

        $updateStmt->execute([
            $new_quantity,
            $existingItem['cart_item_id']
        ]);

    } else {

        $insertStmt = $pdo->prepare("
            INSERT INTO cart_items (
                cart_id,
                variant_id,
                quantity,
                added_at,
                updated_at
            )
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $insertStmt->execute([
            $cart_id,
            $variant_id,
            $quantity
        ]);
    }

    // =========================================
    // COMMIT TRANSACTION
    // =========================================
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Product successfully added to your cart!'
    ]);

} catch (Exception $ex) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($ex->getMessage());

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $ex->getMessage()
    ]);

} catch (PDOException $ex) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($ex->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong while adding the product. Please try again.'
    ]);
}
