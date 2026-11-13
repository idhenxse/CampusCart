<?php
// Get the logged-in student's information.
$menu_student_stmt = $pdo->prepare("
    SELECT full_name, student_number, email
    FROM users
    WHERE user_id = :user_id
      AND role = 'student'
    LIMIT 1
");

$menu_student_stmt->execute([
    ':user_id' => $_SESSION['user_id']
]);

$menu_student = $menu_student_stmt->fetch(PDO::FETCH_ASSOC);

$menu_student_name = $menu_student['full_name'] ?? 'Student';
$menu_student_number = $menu_student['student_number'] ?? '';
$menu_student_email = $menu_student['email'] ?? '';

// Default avatar.
$menu_student_avatar = '../assets/images/student-avatar.png';
$show_password_modal = !empty($_SESSION['password_change_message']);

?>

<!-- =====================================
     STUDENT MENU POPUP
====================================== -->

<div class="student-menu-wrapper" id="student-menu-wrapper">

    <button
        type="button"
        class="header-action student-menu-trigger"
        id="student-menu-trigger"
        title="Student Menu"
        aria-label="Open Student Menu"
        aria-expanded="false"
        aria-controls="student-menu-panel"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 6h16"/>
            <path d="M4 12h16"/>
            <path d="M4 18h16"/>
        </svg>
    </button>

    <div
        class="student-menu-panel"
        id="student-menu-panel"
        aria-hidden="true"
    >

        <!-- STUDENT INFORMATION -->
        <div class="student-menu-user">

            <img
                src="<?= htmlspecialchars($menu_student_avatar, ENT_QUOTES, 'UTF-8') ?>"
                alt="Student Avatar"
                class="student-menu-avatar"
            >

            <div class="student-menu-user-details">
                <strong>
                    <?= htmlspecialchars($menu_student_name, ENT_QUOTES, 'UTF-8') ?>
                </strong>

                <span>
                    <?= htmlspecialchars($menu_student_number, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

        </div>

        <!-- GENERAL NAVIGATION -->
        <div class="student-menu-section">

            <span class="student-menu-label">General</span>

            <a href="dashboard.php" class="student-menu-link">
                <span>⌂</span>
                Dashboard
            </a>

            <a href="cart.php" class="student-menu-link">
                <span>🛒</span>
                Cart
            </a>

            <a href="orders.php" class="student-menu-link">
                <span>▤</span>
                Orders
            </a>

        </div>

        <div class="student-menu-divider"></div>

        <!-- SETTINGS -->
        <div class="student-menu-section">

            <span class="student-menu-label">Settings</span>

            <button
                type="button"
                class="student-menu-link"
                id="open-student-profile"
            >
                <span>♙</span>
                My Profile
            </button>

            <div class="student-menu-theme">

                <button
                    type="button"
                    id="student-theme-toggle"
                    class="student-theme-toggle"
                    aria-label="Toggle light and dark theme"
                    aria-pressed="false"
                >
                    <span class="theme-toggle-icon">☀</span>
                    <span class="theme-toggle-track">
                        <span class="theme-toggle-thumb"></span>
                    </span>
                    <span class="theme-toggle-icon">☾</span>
                </button>

                <span>Theme</span>

            </div>

        </div>

        <div class="student-menu-divider"></div>

        <!-- SIGN OUT -->
        <a href="../logout.php" class="student-menu-signout">
            <span>⇥</span>
            Sign Out
        </a>

    </div>

</div>


<!-- =====================================
     MY PROFILE POPUP
====================================== -->

<div
    class="student-account-modal"
    id="student-profile-modal"
    aria-hidden="true"
>

    <div class="student-account-card">

        <div class="student-account-header">

            <h2>My Profile</h2>

        </div>

        <div class="student-profile-avatar-wrapper">

            <img
                src="<?= htmlspecialchars($menu_student_avatar, ENT_QUOTES, 'UTF-8') ?>"
                alt="Student Profile Picture"
                class="student-profile-avatar"
            >

            <span>Profile Picture</span>

        </div>

        <div class="student-profile-fields">

            <div class="student-profile-field">
                <label>Full Name</label>
                <input
                    type="text"
                    value="<?= htmlspecialchars($menu_student_name, ENT_QUOTES, 'UTF-8') ?>"
                    readonly
                >
            </div>

            <div class="student-profile-field">
                <label>Student Number</label>
                <input
                    type="text"
                    value="<?= htmlspecialchars($menu_student_number, ENT_QUOTES, 'UTF-8') ?>"
                    readonly
                >
            </div>

            <div class="student-profile-field">
                <label>Email Address</label>
                <input
                    type="email"
                    value="<?= htmlspecialchars($menu_student_email, ENT_QUOTES, 'UTF-8') ?>"
                    readonly
                >
            </div>

            <div class="student-profile-field">
                <label>Password</label>
                <input
                    type="password"
                    value=""
                    placeholder="••••••••"
                    readonly
                >
            </div>

        </div>

        <button
            type="button"
            class="student-change-password-link"
            id="open-change-password"
        >
            Change Password
        </button>

        <button
            type="button"
            class="student-account-back"
            id="close-student-profile"
            aria-label="Back to Student Menu"
        >
            ←
        </button>

    </div>

</div>


<!-- =====================================
     CHANGE PASSWORD POPUP
====================================== -->

<div
    class="student-account-modal"
    id="student-password-modal"
    aria-hidden="true"
    data-open-after-message="<?= $show_password_modal ? 'true' : 'false' ?>"
>

    <div class="student-account-card student-password-card">

        <div class="student-account-header">

            <h2>Change Password</h2>

        </div>

        <!-- PASSWORD CHANGE MESSAGE -->
        <div
            id="password-change-message"
            class="password-change-message"
            role="status"
            aria-live="polite"
            hidden
        ></div>

        <form
            id="student-change-password-form"
            method="POST"
            action="includes/change_password.php"
        >

            <!-- CSRF TOKEN -->
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
            >

            <div class="student-profile-field">
                <label for="old-password">Old Password</label>
                <input
                    type="password"
                    id="old-password"
                    name="old_password"
                    placeholder="Enter Old Password"
                    required
                    autocomplete="current-password"
                >
            </div>

            <div class="student-profile-field">
                <label for="new-password">New Password</label>
                <input
                    type="password"
                    id="new-password"
                    name="new_password"
                    placeholder="Enter New Password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >
            </div>

            <div class="student-profile-field">
                <label for="confirm-password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm-password"
                    name="confirm_password"
                    placeholder="Enter New Password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >
            </div>

            <button
                type="submit"
                class="student-password-submit"
            >
                CHANGE
            </button>

        </form>

        <button
            type="button"
            class="student-account-back"
            id="back-to-student-profile"
            aria-label="Back to My Profile"
        >
            ←
        </button>

    </div>

</div>
