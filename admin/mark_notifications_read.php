<?php

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

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
        'message' => 'Unauthorized access.'
    ]);

    exit;
}

// =========================================
// POST REQUEST VALIDATION
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
// CSRF TOKEN VALIDATION
// =========================================

$submitted_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submitted_token)
) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token.'
    ]);

    exit;
}

// =========================================
// MARK ADMIN NOTIFICATIONS AS READ
// =========================================

try {

    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = :user_id
        AND is_read = 0
    ");

    $stmt->execute([
        ':user_id' => $admin_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Notifications marked as read.'
    ]);

} catch (PDOException $exception) {

    error_log(
        'Admin notification update error: ' . $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update notifications.'
    ]);
}
