<?php
session_start();

require_once __DIR__ . "/config/database.php";

$errors = [];
$email = "";

// Redirect users who are already logged in.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {

    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'student') {
        header("Location: student/dashboard.php");
        exit;
    }
}

// Generate a CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Verify the CSRF token.
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $errors[] = "Invalid form submission. Please refresh the page and try again.";
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs.
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if ($password === '') {
        $errors[] = "Please enter your password.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "SELECT user_id, full_name, email, password, role
                 FROM users
                 WHERE email = :email
                 LIMIT 1"
            );

            $stmt->execute([':email' => $email]);

            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                // Prevent session fixation.
                session_regenerate_id(true);

                // Store authenticated user information.
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                // Generate a new CSRF token after login.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Redirect according to the user's role.
                if ($user['role'] === 'admin') {
                    header("Location: admin/dashboard.php");
                    exit;
                }

                header("Location: student/dashboard.php");
                exit;

            } else {
                $errors[] = "Incorrect email address or password.";
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = "Unable to process your login. Please try again.";
        }
    }
}

$registered = isset($_GET['registered']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Login | CampusCart</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <main class="auth-page">

    <header class="site-header">

    <div class="site-brand">
        <img src="assets/images/plm-logo.png" alt="Pamantasan ng Lungsod ng Maynila seal">

        <div class="site-brand-text">
            <h1>CampusCart</h1>
            <p>SCHOOL COOPERATIVE PORTAL</p>
        </div>
    </div>

    <div class="site-institution">
        Pamantasan ng Lungsod ng Maynila
    </div>

</header>

        <div class="auth-landscape"></div>

        <section class="auth-card">

            <h2>Student Login</h2>
            <p class="auth-subtitle">
                Welcome back! Log in to your CampusCart account.
            </p>

            <?php if ($registered): ?>
                <div class="auth-message auth-success" role="status">
                    Registration successful! You can now log in.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="auth-message auth-error" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="auth-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="100"
                        placeholder="Enter your email address"
                        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                    >
                </div>

                <button type="submit" class="auth-button">
                    Login
                </button>

            </form>

            <div class="auth-switch">
                Don't have an account?
                <a href="register.php">Register here</a>
            </div>

        </section>

        <footer class="auth-footer">
            &copy; <?= date('Y') ?> CampusCart. All rights reserved.
        </footer>

    </main>

</body>
</html>
