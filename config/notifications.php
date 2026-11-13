<?php

/**
 * CAMPUSCART NOTIFICATION HELPERS
 *
 * Shared notification functions for Student and Admin processes.
 */

/**
 * Retrieve the designated Admin account.
 */
function getCampusCartAdminId(PDO $pdo): ?int
{
    $stmt = $pdo->query("
        SELECT user_id
        FROM users
        WHERE role = 'admin'
        ORDER BY user_id ASC
        LIMIT 1
    ");

    $adminId = $stmt->fetchColumn();

    return $adminId !== false ? (int) $adminId : null;
}

/**
 * Create a notification for the Admin.
 */
function createAdminNotification(
    PDO $pdo,
    string $title,
    string $message,
    string $type,
    ?int $relatedId = null
): void {

    $adminId = getCampusCartAdminId($pdo);

    if ($adminId === null) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            title,
            message,
            notification_type,
            related_id,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");

    $stmt->execute([
        $adminId,
        $title,
        $message,
        $type,
        $relatedId
    ]);
}

/**
 * Notify Admin when stock crosses a low-stock or out-of-stock threshold.
 *
 * Notifications are generated only when stock crosses the threshold,
 * preventing repeated alerts every time the inventory is updated.
 */
function notifyAdminStockLevel(
    PDO $pdo,
    int $inventoryId,
    string $productName,
    int $previousStock,
    int $newStock,
    int $threshold
): void {

    // Out of stock.
    if ($previousStock > 0 && $newStock === 0) {

        createAdminNotification(
            $pdo,
            'Product Out of Stock',
            $productName . ' is now out of stock.',
            'out_of_stock',
            $inventoryId
        );

        return;
    }

    // Low stock: crossed from above the threshold to at or below it.
    if (
        $previousStock > $threshold &&
        $newStock > 0 &&
        $newStock <= $threshold
    ) {

        createAdminNotification(
            $pdo,
            'Low Stock Alert',
            $productName . ' has only ' . $newStock .
                ' item(s) remaining.',
            'low_stock',
            $inventoryId
        );
    }
}
