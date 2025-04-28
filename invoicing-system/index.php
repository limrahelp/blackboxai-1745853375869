<?php
require 'config.php';

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'create_invoice') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO invoices (customer_name, customer_email, invoice_date, due_date, total) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['customer_name'],
                $data['customer_email'],
                $data['invoice_date'],
                $data['due_date'],
                $data['total']
            ]);
            $invoice_id = $pdo->lastInsertId();

            $stmt_item = $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
            foreach ($data['items'] as $item) {
                $stmt_item->execute([
                    $invoice_id,
                    $item['description'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total']
                ]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'invoice_id' => $invoice_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Serve frontend HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Invoicing System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded shadow">
        <h1 class="text-3xl font-bold mb-6">Create Invoice</h1>
        <form id="invoice-form" class="space-y-4">
            <div>
                <label class="block font-semibold mb-1" for="customer_name">Customer Name</label>
                <input type="text" id="customer_name" name="customer_name" required class="w-full border border-gray-300 rounded px-3 py-2" />
            </div>
            <div>
                <label class="block font-semibold mb-1" for="customer_email">Customer Email</label>
                <input type="email" id="customer_email" name="customer_email" class="w-full border border-gray-300 rounded px-3 py-2" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-semibold mb-1" for="invoice_date">Invoice Date</label>
                    <input type="date" id="invoice_date" name="invoice_date" required class="w-full border border-gray-300 rounded px-3 py-2" />
                </div>
                <div>
                    <label class="block font-semibold mb-1" for="due_date">Due Date</label>
                    <input type="date" id="due_date" name="due_date" required class="w-full border border-gray-300 rounded px-3 py-2" />
                </div>
            </div>
            <div>
                <label class="block font-semibold mb-1">Invoice Items</label>
                <table class="w-full border border-gray-300 rounded">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="border border-gray-300 px-2 py-1">Description</th>
                            <th class="border border-gray-300 px-2 py-1">Quantity</th>
                            <th class="border border-gray-300 px-2 py-1">Unit Price</th>
                            <th class="border border-gray-300 px-2 py-1">Total</th>
                            <th class="border border-gray-300 px-2 py-1">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        <!-- Items will be added here -->
                    </tbody>
                </table>
                <button type="button" id="add-item" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">Add Item</button>
            </div>
            <div class="text-right font-semibold text-lg">
                Total: $<span id="invoice-total">0.00</span>
            </div>
            <div class="flex space-x-4">
                <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">Save Invoice</button>
                <button type="button" id="generate-pdf" class="px-6 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition">Generate PDF</button>
            </div>
        </form>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        const { jsPDF } = window.jspdf;

        function createItemRow(description = '', quantity = 1, unitPrice = 0) {
            const tr = document.createElement('tr');

            tr.innerHTML = `
                <td class="border border-gray-300 px-2 py-1">
                    <input type="text" class="w-full border border-gray-300 rounded px-1 py-1 description" value="${description}" required />
                </td>
                <td class="border border-gray-300 px-2 py-1">
                    <input type="number" min="1" class="w-full border border-gray-300 rounded px-1 py-1 quantity" value="${quantity}" required />
                </td>
                <td class="border border-gray-300 px-2 py-1">
                    <input type="number" min="0" step="0.01" class="w-full border border-gray-300 rounded px-1 py-1 unit-price" value="${unitPrice}" required />
                </td>
                <td class="border border-gray-300 px-2 py-1 total-cell text-right font-semibold">$0.00</td>
                <td class="border border-gray-300 px-2 py-1 text-center">
                    <button type="button" class="remove-item text-red-600 hover:text-red-800"><i class="fas fa-trash"></i></button>
                </td>
            `;

            // Event listeners for recalculating totals
            tr.querySelector('.quantity').addEventListener('input', updateTotals);
            tr.querySelector('.unit-price').addEventListener('input', updateTotals);
            tr.querySelector('.description').addEventListener('input', updateTotals);
            tr.querySelector('.remove-item').addEventListener('click', () => {
                tr.remove();
                updateTotals();
            });

            return tr;
        }

        function updateTotals() {
            let total = 0;
            document.querySelectorAll('#items-body tr').forEach(tr => {
                const qty = parseFloat(tr.querySelector('.quantity').value) || 0;
                const price = parseFloat(tr.querySelector('.unit-price').value) || 0;
                const lineTotal = qty * price;
                tr.querySelector('.total-cell').textContent = '$' + lineTotal.toFixed(2);
                total += lineTotal;
            });
            document.getElementById('invoice-total').textContent = total.toFixed(2);
        }

        document.getElementById('add-item').addEventListener('click', () => {
            const newRow = createItemRow();
            document.getElementById('items-body').appendChild(newRow);
            updateTotals();
        });

        document.getElementById('invoice-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const customer_name = document.getElementById('customer_name').value.trim();
            const customer_email = document.getElementById('customer_email').value.trim();
            const invoice_date = document.getElementById('invoice_date').value;
            const due_date = document.getElementById('due_date').value;
            const total = parseFloat(document.getElementById('invoice-total').textContent);

            const items = [];
            document.querySelectorAll('#items-body tr').forEach(tr => {
                const description = tr.querySelector('.description').value.trim();
                const quantity = parseInt(tr.querySelector('.quantity').value);
                const unit_price = parseFloat(tr.querySelector('.unit-price').value);
                const item_total = quantity * unit_price;
                if (description && quantity > 0 && unit_price >= 0) {
                    items.push({
                        description,
                        quantity,
                        unit_price,
                        total: item_total
                    });
                }
            });

            if (items.length === 0) {
                alert('Please add at least one invoice item.');
                return;
            }

            const payload = {
                customer_name,
                customer_email,
                invoice_date,
                due_date,
                total,
                items
            };

            try {
                const response = await fetch('index.php?action=create_invoice', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();
                if (result.success) {
                    alert('Invoice saved successfully with ID: ' + result.invoice_id);
                    // Optionally reset form here
                } else {
                    alert('Error saving invoice: ' + result.message);
                }
            } catch (error) {
                alert('Error saving invoice: ' + error.message);
            }
        });

        document.getElementById('generate-pdf').addEventListener('click', () => {
            const doc = new jsPDF();

            doc.setFontSize(18);
            doc.text('Invoice', 14, 22);

            const customerName = document.getElementById('customer_name').value;
            const customerEmail = document.getElementById('customer_email').value;
            const invoiceDate = document.getElementById('invoice_date').value;
            const dueDate = document.getElementById('due_date').value;
            const total = document.getElementById('invoice-total').textContent;

            doc.setFontSize(12);
            doc.text(`Customer Name: ${customerName}`, 14, 32);
            doc.text(`Customer Email: ${customerEmail}`, 14, 40);
            doc.text(`Invoice Date: ${invoiceDate}`, 14, 48);
            doc.text(`Due Date: ${dueDate}`, 14, 56);

            let startY = 66;
            doc.text('Items:', 14, startY);
            startY += 6;

            const rows = [];
            document.querySelectorAll('#items-body tr').forEach(tr => {
                const description = tr.querySelector('.description').value;
                const quantity = tr.querySelector('.quantity').value;
                const unitPrice = tr.querySelector('.unit-price').value;
                const lineTotal = tr.querySelector('.total-cell').textContent;
                rows.push([description, quantity, unitPrice, lineTotal]);
            });

            doc.autoTable({
                head: [['Description', 'Quantity', 'Unit Price', 'Total']],
                body: rows,
                startY: startY,
                theme: 'grid',
                styles: { fontSize: 10 },
                headStyles: { fillColor: [220, 220, 220] }
            });

            doc.text(`Total: $${total}`, 14, doc.lastAutoTable.finalY + 10);

            doc.save('invoice.pdf');
        });
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
</body>
</html>
