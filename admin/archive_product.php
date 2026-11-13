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

// ONLY ALLOW POST REQUESTS
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory.php');
    exit;
}

// CSRF VALIDATION
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    header('Location: inventory.php?archive_error=invalid_request');
    exit;
}

// VALIDATE INPUT
$product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (
    !$product_id ||
    !in_array($action, ['archive', 'restore'], true)
) {
    header('Location: inventory.php?archive_error=invalid_input');
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock the product record before changing its archive status.
    $stmt = $pdo->prepare("
        SELECT product_id, is_archived
        FROM products
        WHERE product_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException('Product not found.');
    }

    if ($action === 'archive') {
        $newStatus = 1;
    } else {
        $newStatus = 0;
    }

    // Update only the archive status.
    $updateStmt = $pdo->prepare("
        UPDATE products
        SET is_archived = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ");

    $updateStmt->execute([$newStatus, $product_id]);

    $pdo->commit();

    if ($action === 'archive') {
        header('Location: inventory.php?archive_success=archived');
    } else {
        header('Location: inventory.php?archive_success=restored');
    }

    exit;

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Product archive error: ' . $exception->getMessage());

    header('Location: inventory.php?archive_error=failed');
    exit;
}
