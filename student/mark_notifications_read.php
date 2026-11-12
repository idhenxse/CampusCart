<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Allow only logged-in students.
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized request.'
    ]);
    exit;
}

// Accept POST requests only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Verify the CSRF token.
$submitted_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submitted_token)
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token.'
    ]);
    exit;
}

try {
    // Mark this student's unread notifications as read.
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = :user_id
          AND is_read = 0
    ");

    $stmt->execute([
        ':user_id' => (int) $_SESSION['user_id']
    ]);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true
    ]);

} catch (Throwable $ex) {
    error_log($ex->getMessage());

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Unable to update notifications.'
    ]);
}
