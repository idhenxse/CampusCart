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

// POST REQUEST ONLY
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory.php');
    exit;
}

// CSRF VALIDATION
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    header('Location: inventory.php?error=invalid_request');
    exit;
}

// VALIDATE INPUT
$inventory_id = filter_input(INPUT_POST, 'inventory_id', FILTER_VALIDATE_INT);
$adjustment_type = $_POST['adjustment_type'] ?? '';
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
$reason = trim($_POST['reason'] ?? '');

$allowed_types = ['addition', 'deduction', 'correction'];

if (
    !$inventory_id ||
    !in_array($adjustment_type, $allowed_types, true) ||
    $quantity === false ||
    $quantity === null ||
    $quantity < 0 ||
    ($adjustment_type !== 'correction' && $quantity < 1) ||
    $reason === ''
) {
    header('Location: inventory.php?error=invalid_adjustment');
    exit;
}

try {
    $pdo->beginTransaction();

    // LOCK THE INVENTORY RECORD
    $stockStmt = $pdo->prepare("
        SELECT inventory_id, stock_quantity
        FROM inventory
        WHERE inventory_id = ?
        FOR UPDATE
    ");

    $stockStmt->execute([$inventory_id]);
    $inventory = $stockStmt->fetch(PDO::FETCH_ASSOC);

    if (!$inventory) {
        $pdo->rollBack();
        header('Location: inventory.php?error=inventory_not_found');
        exit;
    }

    $previous_stock = (int) $inventory['stock_quantity'];

    // CALCULATE NEW STOCK
    if ($adjustment_type === 'addition') {
        $new_stock = $previous_stock + $quantity;
    } elseif ($adjustment_type === 'deduction') {
        if ($quantity > $previous_stock) {
            $pdo->rollBack();
            header('Location: inventory.php?error=insufficient_stock');
            exit;
        }

        $new_stock = $previous_stock - $quantity;
    } else {
        $new_stock = $quantity;
    }

    // UPDATE INVENTORY
    $updateStmt = $pdo->prepare("
        UPDATE inventory
        SET stock_quantity = ?, updated_at = NOW()
        WHERE inventory_id = ?
    ");

    $updateStmt->execute([
        $new_stock,
        $inventory_id
    ]);

    // RECORD STOCK ADJUSTMENT
    $historyStmt = $pdo->prepare("
        INSERT INTO stock_adjustments (
            inventory_id,
            admin_id,
            adjustment_type,
            quantity,
            previous_stock,
            new_stock,
            reason,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $historyStmt->execute([
        $inventory_id,
        (int) $_SESSION['user_id'],
        $adjustment_type,
        $quantity,
        $previous_stock,
        $new_stock,
        $reason
    ]);

    $pdo->commit();

    header('Location: inventory.php?adjustment_success=1');
    exit;

} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Stock adjustment error: ' . $exception->getMessage());

    header('Location: inventory.php?error=adjustment_failed');
    exit;
}
