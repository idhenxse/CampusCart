<?php
session_start();

require_once __DIR__ . "/config/database.php";

// Generate a CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

$full_name = "";
$student_number = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Verify the CSRF token.
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $errors[] = "Invalid form submission. Please refresh the page and try again.";
    }

    // Get and clean form values.
    $full_name = trim($_POST['full_name'] ?? '');
    $student_number = trim($_POST['student_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate required fields.
    if ($full_name === '') {
        $errors[] = "Please enter your full name.";
    }

    if ($student_number === '') {
        $errors[] = "Please enter your student number.";
    }

    if ($email === '') {
        $errors[] = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (strlen($password) < 8) {
        $errors[] = "Your password must be at least 8 characters long.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Your passwords do not match.";
    }

    // Check for duplicate email or student number.
    if (empty($errors)) {
        try {
            $check = $pdo->prepare(
                "SELECT user_id
                 FROM users
                 WHERE email = :email
                    OR student_number = :student_number
                 LIMIT 1"
            );

            $check->execute([
                ':email' => $email,
                ':student_number' => $student_number
            ]);

            if ($check->fetch()) {
                $errors[] = "That email address or student number is already registered.";
            } else {

                // Hash the password before saving it.
                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Public registration creates Student accounts only.
                $insert = $pdo->prepare(
                    "INSERT INTO users
                    (full_name, student_number, email, password, role)
                    VALUES
                    (:full_name, :student_number, :email, :password, 'student')"
                );

                $insert->execute([
                    ':full_name' => $full_name,
                    ':student_number' => $student_number,
                    ':email' => $email,
                    ':password' => $hashed_password
                ]);

                // Return the student to the Login page.
                header("Location: index.php?registered=1");
                exit;
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = "Something went wrong while creating your account. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Registration | CampusCart</title>

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

            <h2>Student Registration</h2>
            <p class="auth-subtitle">
                Create your student account to get started.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="auth-message auth-error" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" class="auth-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="100"
                        placeholder="Enter your full name"
                        value="<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="student_number">Student Number</label>
                    <input
                        type="text"
                        id="student_number"
                        name="student_number"
                        maxlength="30"
                        placeholder="Enter your student number"
                        value="<?= htmlspecialchars($student_number, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

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
                        minlength="8"
                        placeholder="Create a password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        placeholder="Confirm your password"
                        required
                    >
                </div>

                <button type="submit" class="auth-button">
                    Create Account
                </button>

            </form>

            <div class="auth-switch">
                Already have an account?
                <a href="index.php">Log In</a>
            </div>

        </section>

        <footer class="auth-footer">
            &copy; <?= date('Y') ?> CampusCart. All rights reserved.
        </footer>

    </main>

</body>
</html>
