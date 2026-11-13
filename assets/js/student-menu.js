
document.addEventListener('DOMContentLoaded', function () {

    const wrapper = document.getElementById('student-menu-wrapper');
    const trigger = document.getElementById('student-menu-trigger');
    const menuPanel = document.getElementById('student-menu-panel');

    const profileModal = document.getElementById('student-profile-modal');
    const passwordModal = document.getElementById('student-password-modal');
    const passwordForm = document.getElementById('student-change-password-form');
    const passwordMessage = document.getElementById('password-change-message');

    const openProfile = document.getElementById('open-student-profile');
    const closeProfile = document.getElementById('close-student-profile');

    const openPassword = document.getElementById('open-change-password');
    const backToProfile = document.getElementById('back-to-student-profile');

    const notificationsLink = document.getElementById('student-menu-notifications');

    if (
        !wrapper || !trigger || !menuPanel ||
        !profileModal || !passwordModal ||
        !passwordForm || !passwordMessage
    ) {
        return;
    }

    // =========================================
    // STUDENT MENU
    // =========================================

    function openStudentMenu() {
        menuPanel.classList.add('active');
        menuPanel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
    }

    function closeStudentMenu() {
        menuPanel.classList.remove('active');
        menuPanel.setAttribute('aria-hidden', 'true');
        trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (event) {
        event.stopPropagation();

        if (menuPanel.classList.contains('active')) {
            closeStudentMenu();
        } else {
            openStudentMenu();
        }
    });

    // =========================================
    // MY PROFILE
    // =========================================

    function showProfile() {
        closeStudentMenu();

        profileModal.classList.add('active');
        profileModal.setAttribute('aria-hidden', 'false');
    }

    function hideProfile() {
        profileModal.classList.remove('active');
        profileModal.setAttribute('aria-hidden', 'true');
    }

    if (openProfile) {
        openProfile.addEventListener('click', showProfile);
    }

    if (closeProfile) {
        closeProfile.addEventListener('click', function () {
            hideProfile();
            openStudentMenu();
        });
    }

    // =========================================
    // CHANGE PASSWORD POPUP
    // =========================================

    function showPassword() {
        hideProfile();

        passwordMessage.hidden = true;
        passwordMessage.textContent = '';
        passwordMessage.classList.remove('success', 'error');

        passwordModal.classList.add('active');
        passwordModal.setAttribute('aria-hidden', 'false');
    }

    function hidePassword() {
        passwordModal.classList.remove('active');
        passwordModal.setAttribute('aria-hidden', 'true');
    }

    function returnToProfile() {
        hidePassword();

        profileModal.classList.add('active');
        profileModal.setAttribute('aria-hidden', 'false');
    }

    if (openPassword) {
        openPassword.addEventListener('click', showPassword);
    }

    if (backToProfile) {
        backToProfile.addEventListener('click', returnToProfile);
    }

    // =========================================
    // CHANGE PASSWORD FORM SUBMISSION
    // =========================================

    passwordForm.addEventListener('submit', async function (event) {

        event.preventDefault();

        const submitButton = passwordForm.querySelector(
            '.student-password-submit'
        );

        if (!submitButton) {
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'CHANGING...';

        passwordMessage.hidden = true;
        passwordMessage.textContent = '';
        passwordMessage.classList.remove('success', 'error');

        try {

            const response = await fetch(passwordForm.action, {
                method: 'POST',
                body: new FormData(passwordForm),
                headers: {
                    'Accept': 'application/json'
                }
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(
                    result.message || 'Unable to change your password.'
                );
            }

            passwordMessage.textContent = result.success
                ? 'Password Changed Successfully!'
                : result.message;

            passwordMessage.classList.add(
                result.success ? 'success' : 'error'
            );

            passwordMessage.hidden = false;

            if (result.success) {
                passwordForm.reset();
            }

        } catch (error) {

            passwordMessage.textContent =
                error.message ||
                'Unable to process your request. Please try again.';

            passwordMessage.classList.add('error');
            passwordMessage.hidden = false;

        } finally {

            submitButton.disabled = false;
            submitButton.textContent = 'CHANGE';

        }

    });

    // =========================================
    // CLOSE POPUPS
    // =========================================

    document.addEventListener('click', function (event) {
        if (!wrapper.contains(event.target)) {
            closeStudentMenu();
        }
    });

    profileModal.addEventListener('click', function (event) {
        if (event.target === profileModal) {
            hideProfile();
        }
    });

    passwordModal.addEventListener('click', function (event) {
        if (event.target === passwordModal) {
            hidePassword();
        }
    });

    document.addEventListener('keydown', function (event) {

        if (event.key !== 'Escape') {
            return;
        }

        if (passwordModal.classList.contains('active')) {
            hidePassword();
        } else if (profileModal.classList.contains('active')) {
            hideProfile();
        } else {
            closeStudentMenu();
        }

    });

    // =========================================
    // NOTIFICATIONS
    // =========================================

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
