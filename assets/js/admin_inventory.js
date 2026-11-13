document.addEventListener('DOMContentLoaded', function () {

    const modal = document.getElementById('admin-stock-modal');
    const closeButton = document.getElementById('admin-stock-modal-close');
    const cancelButton = document.getElementById('admin-stock-cancel');

    const inventoryIdInput = document.getElementById('admin-stock-inventory-id');
    const productName = document.getElementById('admin-stock-product-name');
    const currentStock = document.getElementById('admin-stock-current-quantity');

    const adjustmentType = document.getElementById('admin-stock-adjustment-type');
    const quantityLabel = document.getElementById('admin-stock-quantity-label');
    const quantityHelp = document.getElementById('admin-stock-quantity-help');
    const quantityInput = document.getElementById('admin-stock-quantity');

    // OPEN STOCK ADJUSTMENT POPUP
    document.querySelectorAll('.admin-stock-adjust-button').forEach(button => {

        button.addEventListener('click', function () {

            const inventoryId = this.dataset.inventoryId;
            const name = this.dataset.productName;
            const variant = this.dataset.variantName;
            const size = this.dataset.size;
            const stock = this.dataset.stock;

            inventoryIdInput.value = inventoryId;

            productName.textContent = name + ' - ' + variant +
                (size ? ' (' + size + ')' : '');

            currentStock.textContent = stock;

            quantityInput.value = '';
            adjustmentType.value = 'addition';

            updateQuantityLabel();

            modal.classList.add('active');
        });

    });

    // UPDATE QUANTITY LABEL AND HELP TEXT
    function updateQuantityLabel() {

        if (adjustmentType.value === 'addition') {
            quantityLabel.textContent = 'Quantity to Add';
            quantityHelp.textContent = 'Enter the number of units to add.';
            quantityInput.min = 1;

        } else if (adjustmentType.value === 'deduction') {
            quantityLabel.textContent = 'Quantity to Deduct';
            quantityHelp.textContent = 'Enter the number of units to deduct.';
            quantityInput.min = 1;

        } else {
            quantityLabel.textContent = 'New Stock Quantity';
            quantityHelp.textContent = 'Enter the new total stock quantity.';
            quantityInput.min = 0;
        }
    }

    adjustmentType.addEventListener('change', updateQuantityLabel);

    // CLOSE POPUP
    function closeModal() {
        modal.classList.remove('active');
    }

    closeButton.addEventListener('click', closeModal);
    cancelButton.addEventListener('click', closeModal);

    // CLOSE WHEN CLICKING OUTSIDE THE POPUP
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    // CLOSE WITH ESCAPE KEY
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });

});
