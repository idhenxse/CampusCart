
<?php
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

// =========================================
// ADMIN SESSION PROTECTION
// =========================================
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired. Please log in again.'
    ]);
    exit;
}

$admin_id = (int) $_SESSION['user_id'];

// =========================================
// READ REQUEST
// =========================================
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid transaction data.'
    ]);
    exit;
}

// =========================================
// CSRF VALIDATION
// =========================================
$csrfToken = $data['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($csrfToken) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Security validation failed. Please refresh the page and try again.'
    ]);
    exit;
}

// =========================================
// VALIDATE PAYMENT METHOD AND ITEMS
// =========================================
$paymentMethod = $data['payment_method'] ?? '';
$items = $data['items'] ?? [];

if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please select a valid payment method.'
    ]);
    exit;
}

if (!is_array($items) || empty($items)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please add at least one product.'
    ]);
    exit;
}

// Combine repeated variant IDs and validate quantities.
$quantities = [];

foreach ($items as $item) {
    $variantId = filter_var(
        $item['variant_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $quantity = filter_var(
        $item['quantity'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$variantId || !$quantity || $quantity < 1) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'One or more product quantities are invalid.'
        ]);
        exit;
    }

    $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $quantity;
}

ksort($quantities);

// =========================================
// SAVE WALK-IN SALE
// =========================================
try {
    $pdo->beginTransaction();

    $validatedItems = [];
    $totalAmount = 0;

    // Lock and validate each inventory record.
    $itemStmt = $pdo->prepare("
        SELECT
            i.inventory_id,
            i.stock_quantity,
            pv.variant_id,
            pv.price,
            p.product_name,
            p.is_archived
        FROM inventory i
        INNER JOIN product_variants pv
            ON pv.variant_id = i.variant_id
        INNER JOIN products p
            ON p.product_id = pv.product_id
        WHERE pv.variant_id = ?
        FOR UPDATE
    ");

    foreach ($quantities as $variantId => $quantity) {
        $itemStmt->execute([$variantId]);
        $product = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int) $product['is_archived'] === 1) {
            throw new RuntimeException(
                'One of the selected products is no longer available.'
            );
        }

        $availableStock = (int) $product['stock_quantity'];

        if ($quantity > $availableStock) {
            throw new RuntimeException(
                $product['product_name'] .
                ' has only ' . $availableStock .
                ' unit(s) available.'
            );
        }

        $unitPrice = (float) $product['price'];
        $subtotal = $unitPrice * $quantity;

        $validatedItems[] = [
            'inventory_id' => (int) $product['inventory_id'],
            'variant_id' => (int) $product['variant_id'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'previous_stock' => $availableStock,
            'new_stock' => $availableStock - $quantity
        ];

        $totalAmount += $subtotal;
    }

    // Generate a unique walk-in sale number.
    $saleNumber = 'WI-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

    // Save the main sale record.
    $saleStmt = $pdo->prepare("
        INSERT INTO walkin_sales (
            admin_id,
            sale_number,
            sale_date,
            total_amount,
            payment_method
        ) VALUES (?, ?, NOW(), ?, ?)
    ");

    $saleStmt->execute([
        $admin_id,
        $saleNumber,
        $totalAmount,
        $paymentMethod
    ]);

    $saleId = (int) $pdo->lastInsertId();

    // Prepare statements for sale items and inventory updates.
    $saleItemStmt = $pdo->prepare("
        INSERT INTO walkin_sale_items (
            sale_id,
            variant_id,
            quantity,
            unit_price,
            subtotal
        ) VALUES (?, ?, ?, ?, ?)
    ");

    $stockUpdateStmt = $pdo->prepare("
        UPDATE inventory
        SET stock_quantity = ?, updated_at = NOW()
        WHERE inventory_id = ?
    ");

    $adjustmentStmt = $pdo->prepare("
        INSERT INTO stock_adjustments (
            inventory_id,
            admin_id,
            adjustment_type,
            quantity,
            previous_stock,
            new_stock,
            reason
        ) VALUES (?, ?, 'deduction', ?, ?, ?, ?)
    ");

    foreach ($validatedItems as $item) {
        // Save the item sold.
        $saleItemStmt->execute([
            $saleId,
            $item['variant_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['subtotal']
        ]);

        // Deduct the sold quantity from inventory.
        $stockUpdateStmt->execute([
            $item['new_stock'],
            $item['inventory_id']
        ]);

        // Record the deduction in stock adjustment history.
        $adjustmentStmt->execute([
            $item['inventory_id'],
            $admin_id,
            $item['quantity'],
            $item['previous_stock'],
            $item['new_stock'],
            'Walk-in sale ' . $saleNumber
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Walk-in sale completed successfully.',
        'sale_number' => $saleNumber,
        'total_amount' => $totalAmount
    ]);

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Walk-in sale error: ' . $exception->getMessage());

    http_response_code(
        $exception instanceof RuntimeException ? 422 : 500
    );

    echo json_encode([
        'success' => false,
        'message' => $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'The transaction could not be completed. Please try again.'
    ]);
}
