document.addEventListener('DOMContentLoaded', function () {

    const wrapper = document.getElementById('student-menu-wrapper');
    const trigger = document.getElementById('student-menu-trigger');
    const menuPanel = document.getElementById('student-menu-panel');

    const profileModal = document.getElementById('student-profile-modal');
    const passwordModal = document.getElementById('student-password-modal');

    const openProfile = document.getElementById('open-student-profile');
    const closeProfile = document.getElementById('close-student-profile');

    const openPassword = document.getElementById('open-change-password');
    const backToProfile = document.getElementById('back-to-student-profile');

    const notificationsLink = document.getElementById('student-menu-notifications');

    if (
        !wrapper || !trigger || !menuPanel ||
        !profileModal || !passwordModal
    ) {
        return;
    }

    // Open Student Menu.
    function openStudentMenu() {
        menuPanel.classList.add('active');
        menuPanel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
    }

    // Close Student Menu.
    function closeStudentMenu() {
        menuPanel.classList.remove('active');
        menuPanel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
    }

    // Open My Profile.
    function showProfile() {
        closeStudentMenu();

        profileModal.classList.add('active');
        profileModal.setAttribute('aria-hidden', 'false');
    }

    // Close My Profile.
    function hideProfile() {
        profileModal.classList.remove('active');
        profileModal.setAttribute('aria-hidden', 'true');
    }

    // Open Change Password.
    function showPassword() {
        hideProfile();

        passwordModal.classList.add('active');
        passwordModal.setAttribute('aria-hidden', 'false');
    }

    // Return to My Profile.
    function returnToProfile() {
        passwordModal.classList.remove('active');
        passwordModal.setAttribute('aria-hidden', 'true');

        profileModal.classList.add('active');
        profileModal.setAttribute('aria-hidden', 'false');
    }

    // Toggle Student Menu.
    trigger.addEventListener('click', function (event) {
        event.stopPropagation();

        if (menuPanel.classList.contains('active')) {
            closeStudentMenu();
        } else {
            openStudentMenu();
        }
    });

    // Open My Profile.
    if (openProfile) {
        openProfile.addEventListener('click', showProfile);
    }

    // Back from My Profile to Student Menu.
    if (closeProfile) {
        closeProfile.addEventListener('click', function () {
            hideProfile();
            openStudentMenu();
        });
    }

    // Open Change Password.
    if (openPassword) {
        openPassword.addEventListener('click', showPassword);
    }

    // Back from Change Password to My Profile.
    if (backToProfile) {
        backToProfile.addEventListener('click', returnToProfile);
    }

    // Close menu when clicking outside.
    document.addEventListener('click', function (event) {
        if (!wrapper.contains(event.target)) {
            closeStudentMenu();
        }
    });

    // Close popups when clicking their backdrop.
    profileModal.addEventListener('click', function (event) {
        if (event.target === profileModal) {
            hideProfile();
        }
    });

    passwordModal.addEventListener('click', function (event) {
        if (event.target === passwordModal) {
            passwordModal.classList.remove('active');
            passwordModal.setAttribute('aria-hidden', 'true');
        }
    });

    // Close open popups with Escape.
    document.addEventListener('keydown', function (event) {

        if (event.key !== 'Escape') {
            return;
        }

        if (passwordModal.classList.contains('active')) {
            passwordModal.classList.remove('active');
            passwordModal.setAttribute('aria-hidden', 'true');
        } else if (profileModal.classList.contains('active')) {
            hideProfile();
        } else {
            closeStudentMenu();
        }
    });

    // Open the existing notification popup.
    if (notificationsLink) {
        notificationsLink.addEventListener('click', function () {

            closeStudentMenu();

            const notificationTrigger =
                document.getElementById('notification-trigger');

            if (notificationTrigger) {
                notificationTrigger.click();
            }
        });
    }

});
