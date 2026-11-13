<?php
// =========================================
// CAMPUSCART ADMIN MENU POPUP
// =========================================
?>

<div class="admin-menu-wrapper" id="admin-menu-wrapper">

    <!-- MENU TRIGGER -->
    <button
        type="button"
        class="admin-header-icon admin-menu-trigger"
        id="admin-menu-trigger"
        title="Admin Menu"
        aria-label="Open Admin Menu"
        aria-expanded="false"
        aria-controls="admin-menu-panel"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 6h16"/>
            <path d="M4 12h16"/>
            <path d="M4 18h16"/>
        </svg>
    </button>

    <!-- ADMIN MENU PANEL -->
    <div
        class="admin-menu-panel"
        id="admin-menu-panel"
        aria-hidden="true"
    >

        <!-- ADMIN INFORMATION -->
        <div class="admin-menu-user">

            <img
                src="../assets/images/plm-logo.png"
                alt="PLM Logo"
                class="admin-menu-avatar"
            >

            <div class="admin-menu-user-details">
                <strong>CampusCart Administration</strong>
            </div>

        </div>

        <!-- GENERAL NAVIGATION -->
        <div class="admin-menu-section">

            <span class="admin-menu-label">General</span>

            <a href="dashboard.php" class="admin-menu-link">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
                Dashboard
            </a>

            <a href="products.php" class="admin-menu-link">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 8h18l-1 13H4L3 8Z"/>
                    <path d="M8 8a4 4 0 0 1 8 0"/>
                </svg>
                Products
            </a>

            <a href="inventory.php" class="admin-menu-link">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="5" y="3" width="14" height="18" rx="2"/>
                    <path d="M9 8h6M9 12h6M9 16h3"/>
                </svg>
                Inventory
            </a>

            <a href="notifications.php" class="admin-menu-link">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                    <path d="M10 21h4"/>
                </svg>

                Notifications

                <?php if (!empty($admin_unread_notifications) && $admin_unread_notifications > 0): ?>
                    <span class="admin-menu-notification-dot"></span>
                <?php endif; ?>
            </a>

            <a href="walkin_sales_history.php" class="admin-menu-link">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 12a9 9 0 1 0 2.6-6.4"/>
                    <path d="M3 4v5h5"/>
                    <path d="M12 7v5l3 2"/>
                </svg>
                Walk-In Sales History
            </a>

        </div>

        <div class="admin-menu-divider"></div>

        <!-- SETTINGS -->
        <div class="admin-menu-section">

            <span class="admin-menu-label">Settings</span>

            <div class="admin-menu-theme">

                <span>Theme</span>

                <button
                    type="button"
                    id="admin-theme-toggle"
                    class="admin-theme-toggle"
                    aria-label="Toggle light and dark theme"
                    aria-pressed="false"
                >
                    <span class="admin-theme-icon">☀</span>

                    <span class="admin-theme-track">
                        <span class="admin-theme-thumb"></span>
                    </span>

                    <span class="admin-theme-icon">☾</span>
                </button>

            </div>

        </div>

        <div class="admin-menu-divider"></div>

        <!-- SIGN OUT -->
        <a href="../logout.php" class="admin-menu-signout">

            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M10 17l5-5-5-5"/>
                <path d="M15 12H3"/>
                <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>
            </svg>

            Sign Out

        </a>

    </div>

</div>
