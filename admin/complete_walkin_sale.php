<?php
session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));

    exit;
}

// ADMIN SESSION PROTECTION
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$admin_id = (int) $_SESSION['user_id'];

// POST ONLY
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

// READ JSON REQUEST
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(false, 'Invalid transaction data.', [], 400);
}

// CSRF VALIDATION
$csrfToken = $input['csrf_token'] ?? '';

if (
    !is_string($csrfToken) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    respond(false, 'Security verification failed. Please refresh the page and try again.', [], 403);
}

// VALIDATE PAYMENT METHOD
$paymentMethod = $input['payment_method'] ?? '';

if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
    respond(false, 'Please select a valid payment method.', [], 422);
}

// VALIDATE ITEMS
$items = $input['items'] ?? [];

if (!is_array($items) || empty($items) || count($items) > 100) {
    respond(false, 'Please add at least one product to the transaction.', [], 422);
}

// Combine duplicate variants and validate quantities.
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
        respond(false, 'One or more transaction items are invalid.', [], 422);
    }

    $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $quantity;
}

try {
    $pdo->beginTransaction();

    $saleItems = [];
    $totalCents = 0;

    // Lock and validate each inventory record.
    ksort($quantities);

    $stockStmt = $pdo->prepare("
        SELECT
            i.inventory_id,
            i.stock_quantity,
            pv.variant_id,
            pv.variant_name,
            pv.size,
            pv.price,
            p.product_id,
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
        $stockStmt->execute([$variantId]);
        $product = $stockStmt->fetch(PDO::FETCH_ASSOC);

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
                ' unit(s) available. Please review the transaction.'
            );
        }

        // Calculate using cents to avoid floating-point total errors.
        $unitPriceCents = (int) round((float) $product['price'] * 100);
        $subtotalCents = $unitPriceCents * $quantity;

        $totalCents += $subtotalCents;

        $saleItems[] = [
            'inventory_id' => (int) $product['inventory_id'],
            'variant_id' => (int) $product['variant_id'],
            'product_name' => $product['product_name'],
            'quantity' => $quantity,
            'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
            'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
            'previous_stock' => $availableStock,
            'new_stock' => $availableStock - $quantity
        ];
    }

    $totalAmount = number_format($totalCents / 100, 2, '.', '');

    // Generate a unique walk-in sale number.
    $saleNumber = 'WI-' . date('Ymd') . '-' .
        strtoupper(bin2hex(random_bytes(4)));

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

    // Prepare statements for sale items, inventory and stock history.
    $saleItemStmt = $pdo->prepare("
        INSERT INTO walkin_sale_items (
            sale_id,
            variant_id,
            quantity,
            unit_price,
            subtotal
        ) VALUES (?, ?, ?, ?, ?)
    ");

    $updateStockStmt = $pdo->prepare("
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

    foreach ($saleItems as $item) {
        // Save the purchased item.
        $saleItemStmt->execute([
            $saleId,
            $item['variant_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['subtotal']
        ]);

        // Deduct the sold quantity.
        $updateStockStmt->execute([
            $item['new_stock'],
            $item['inventory_id']
        ]);

        // Record the stock deduction.
        $reason = 'Walk-in sale ' . $saleNumber;

        $adjustmentStmt->execute([
            $item['inventory_id'],
            $admin_id,
            $item['quantity'],
            $item['previous_stock'],
            $item['new_stock'],
            $reason
        ]);
    }

    $pdo->commit();

    respond(true, 'Walk-in sale completed successfully.', [
        'sale_number' => $saleNumber,
        'total_amount' => $totalAmount
    ]);

} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(false, $exception->getMessage(), [], 422);

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Walk-in sale error: ' . $exception->getMessage());

    respond(
        false,
        'The transaction could not be completed. No stock was deducted. Please try again.',
        [],
        500
    );
}
