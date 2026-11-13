
<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

// =========================================
// JSON RESPONSE HELPER
// =========================================

function sendPasswordResponse(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);

    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);

    exit;
}

// =========================================
// STUDENT SESSION PROTECTION
// =========================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    sendPasswordResponse(false, 'Your session has expired. Please log in again.', 401);
}

// =========================================
// PROCESS CHANGE PASSWORD REQUEST
// =========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendPasswordResponse(false, 'Invalid request method.', 405);
}

// Verify CSRF token.
$submitted_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submitted_token)
) {
    sendPasswordResponse(false, 'Invalid form submission. Please try again.', 403);
}

$old_password = $_POST['old_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

try {

    if (
        $old_password === '' ||
        $new_password === '' ||
        $confirm_password === ''
    ) {
        throw new Exception('Please complete all password fields.');
    }

    if (strlen($new_password) < 8) {
        throw new Exception('Your new password must be at least 8 characters long.');
    }

    if ($new_password !== $confirm_password) {
        throw new Exception('Your new passwords do not match.');
    }

    if ($old_password === $new_password) {
        throw new Exception('Your new password must be different from your old password.');
    }

    // Retrieve current password.
    $stmt = $pdo->prepare("
        SELECT password
        FROM users
        WHERE user_id = :user_id
          AND role = 'student'
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id']
    ]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student || !password_verify($old_password, $student['password'])) {
        throw new Exception('Your old password is incorrect.');
    }

    // Hash the new password.
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Update password.
    $update_stmt = $pdo->prepare("
        UPDATE users
        SET password = :password
        WHERE user_id = :user_id
          AND role = 'student'
    ");

    $update_stmt->execute([
        ':password' => $hashed_password,
        ':user_id' => $_SESSION['user_id']
    ]);

    sendPasswordResponse(
        true,
        'Your password has been successfully changed!'
    );

} catch (PDOException $e) {

    error_log($e->getMessage());

    sendPasswordResponse(
        false,
        'Unable to change your password right now. Please try again.',
        500
    );

} catch (Exception $e) {

    sendPasswordResponse(false, $e->getMessage(), 400);

}
