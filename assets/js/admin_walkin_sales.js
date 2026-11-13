document.addEventListener('DOMContentLoaded', function () {
    const productSelect = document.getElementById('walkin-product');
    const variantSelect = document.getElementById('walkin-variant');
    const quantityInput = document.getElementById('walkin-quantity');
    const addButton = document.getElementById('walkin-add-product');

    const cartItems = document.getElementById('walkin-cart-items');
    const totalItemsDisplay = document.getElementById('walkin-total-items');
    const totalAmountDisplay = document.getElementById('walkin-total-amount');
    const completeButton = document.getElementById('walkin-complete-transaction');

    const productsData = document.getElementById('walkin-products-data');

    if (!productSelect || !variantSelect || !productsData) {
        return;
    }

    const walkinProducts = JSON.parse(productsData.textContent);
    let transactionItems = [];

    const pesoFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2
    });

    // Load variants when a product is selected.
    productSelect.addEventListener('change', function () {
        const selectedProductId = this.value;

        variantSelect.innerHTML = '<option value="">Select Variant</option>';
        variantSelect.disabled = true;
        quantityInput.value = 1;
        quantityInput.removeAttribute('max');

        if (!selectedProductId) {
            return;
        }

        const matchingVariants = walkinProducts.filter(function (product) {
            return String(product.product_id) === selectedProductId;
        });

        const sizeOrder = [
            'small',
            'medium',
            'large',
            'small to large',
            'xl',
            '1xl',
            '2xl',
            '3xl',
            '4xl',
            '5xl'
        ];

        matchingVariants.sort(function (a, b) {
            const sizeA = String(a.size || a.variant_name).trim().toLowerCase();
            const sizeB = String(b.size || b.variant_name).trim().toLowerCase();

            const indexA = sizeOrder.indexOf(sizeA);
            const indexB = sizeOrder.indexOf(sizeB);

            return (indexA === -1 ? 999 : indexA) -
                (indexB === -1 ? 999 : indexB);
        });

        matchingVariants.forEach(function (product) {

            const option = document.createElement('option');

            option.value = product.variant_id;

            let label = product.variant_name;

            if (product.size) {
                label += ' (' + product.size + ')';
            }

            option.textContent = label;
            option.dataset.price = product.price;
            option.dataset.stock = product.stock_quantity;

            variantSelect.appendChild(option);
        });

        variantSelect.disabled = matchingVariants.length === 0;
    });

    // Update available quantity when a variant is selected.
    variantSelect.addEventListener('change', function () {
        const selectedOption = this.options[this.selectedIndex];

        if (!selectedOption.value) {
            quantityInput.removeAttribute('max');
            quantityInput.value = 1;
            return;
        }

        quantityInput.max = selectedOption.dataset.stock;
        quantityInput.value = 1;
    });

    // Add selected product to the transaction.
    addButton.addEventListener('click', function () {
        const selectedProductId = productSelect.value;
        const selectedVariantId = variantSelect.value;
        const quantity = Number(quantityInput.value);

        if (!selectedProductId || !selectedVariantId) {
            alert('Please select a product and variant.');
            return;
        }

        if (!Number.isInteger(quantity) || quantity < 1) {
            alert('Please enter a valid quantity.');
            return;
        }

        const product = walkinProducts.find(function (item) {
            return String(item.variant_id) === selectedVariantId &&
                   String(item.product_id) === selectedProductId;
        });

        if (!product) {
            alert('The selected product variant could not be found.');
            return;
        }

        const existingItem = transactionItems.find(function (item) {
            return String(item.variant_id) === selectedVariantId;
        });

        const currentQuantity = existingItem ? existingItem.quantity : 0;
        const newQuantity = currentQuantity + quantity;
        const availableStock = Number(product.stock_quantity);

        if (newQuantity > availableStock) {
            alert('Only ' + availableStock + ' unit(s) are currently available.');
            return;
        }

        if (existingItem) {
            existingItem.quantity = newQuantity;
        } else {
            transactionItems.push({
                product_id: product.product_id,
                product_name: product.product_name,
                variant_id: product.variant_id,
                variant_name: product.variant_name,
                size: product.size,
                price: Number(product.price),
                quantity: quantity,
                stock: availableStock
            });
        }

        renderTransaction();

        // Reset the selection fields.
        productSelect.value = '';
        variantSelect.innerHTML = '<option value="">Select Variant</option>';
        variantSelect.disabled = true;
        quantityInput.value = 1;
        quantityInput.removeAttribute('max');
    });

    // Display transaction items and calculate totals.
    function renderTransaction() {
        cartItems.innerHTML = '';

        if (transactionItems.length === 0) {
            cartItems.innerHTML = `
                <tr>
                    <td colspan="6" class="admin-empty-table">
                        No products added yet.
                    </td>
                </tr>
            `;
        } else {
            transactionItems.forEach(function (item) {
                const row = document.createElement('tr');

                const productCell = document.createElement('td');
                productCell.textContent = item.product_name;

                const variantCell = document.createElement('td');
                variantCell.textContent = item.variant_name +
                    (item.size ? ' (' + item.size + ')' : '');

                const priceCell = document.createElement('td');
                priceCell.textContent = pesoFormatter.format(item.price);

                const quantityCell = document.createElement('td');
                quantityCell.textContent = item.quantity;

                const subtotalCell = document.createElement('td');
                subtotalCell.textContent = pesoFormatter.format(
                    item.price * item.quantity
                );

                const actionCell = document.createElement('td');
                const removeButton = document.createElement('button');

                removeButton.type = 'button';
                removeButton.className = 'admin-walkin-remove-button';
                removeButton.textContent = 'Remove';
                removeButton.dataset.variantId = item.variant_id;

                actionCell.appendChild(removeButton);

                row.append(
                    productCell,
                    variantCell,
                    priceCell,
                    quantityCell,
                    subtotalCell,
                    actionCell
                );

                cartItems.appendChild(row);
            });
        }

        const totalItems = transactionItems.reduce(function (total, item) {
            return total + item.quantity;
        }, 0);

        const totalAmount = transactionItems.reduce(function (total, item) {
            return total + (item.price * item.quantity);
        }, 0);

        totalItemsDisplay.textContent = totalItems;
        totalAmountDisplay.textContent = pesoFormatter.format(totalAmount);

        completeButton.disabled = transactionItems.length === 0;
    }

    // Remove an item from the transaction.
    cartItems.addEventListener('click', function (event) {
        const removeButton = event.target.closest('.admin-walkin-remove-button');

        if (!removeButton) {
            return;
        }

        const variantId = removeButton.dataset.variantId;

        transactionItems = transactionItems.filter(function (item) {
            return String(item.variant_id) !== String(variantId);
        });

        renderTransaction();
    });
    

    // Complete and save the walk-in transaction.
    completeButton.addEventListener('click', async function () {
        if (transactionItems.length === 0) {
            alert('Please add at least one product.');
            return;
        }

        const csrfToken = document.getElementById('walkin-csrf-token');
        const paymentMethod = document.getElementById('walkin-payment-method');

        if (!csrfToken || !paymentMethod) {
            alert('Transaction information is missing. Please refresh the page.');
            return;
        }

        const originalText = completeButton.textContent;

        completeButton.disabled = true;
        completeButton.textContent = 'Processing...';

        try {
            const response = await fetch('process_walkin_sale.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken.value,
                    payment_method: paymentMethod.value,
                    items: transactionItems.map(function (item) {
                        return {
                            variant_id: item.variant_id,
                            quantity: item.quantity
                        };
                    })
                })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Unable to complete the transaction.'
                );
            }

            alert(
                'Walk-in sale completed successfully!\n\n' +
                'Sale Number: ' + result.sale_number + '\n' +
                'Total Amount: ' + pesoFormatter.format(result.total_amount)
            );

            // Reload the dashboard to refresh stock and sales summaries.
            window.location.reload();

        } catch (error) {
            alert(error.message || 'Something went wrong. Please try again.');

            completeButton.disabled = false;
            completeButton.textContent = originalText;
        }
    });
    
});
