document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('admin-image-modal');
    const modalImage = document.getElementById('admin-modal-image');
    const modalReference = document.getElementById('admin-modal-reference');
    const closeButton = document.getElementById('admin-image-modal-close');
    const screenshotButtons = document.querySelectorAll('.admin-screenshot-link');

    if (!modal || !modalImage || !modalReference || !closeButton) {
        return;
    }

    // Open the popup when a screenshot button is clicked.
    screenshotButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            modalImage.src = button.dataset.image;
            modalReference.textContent = button.dataset.reference || 'Not provided';

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    });

    // Close the popup.
    function closeModal() {
        modal.classList.remove('active');
        modalImage.src = '';
        document.body.style.overflow = '';
    }

    closeButton.addEventListener('click', closeModal);

    // Close when clicking the dark backdrop.
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    // Close when pressing Escape.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });
});