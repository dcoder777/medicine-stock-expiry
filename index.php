<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$errors = [];
$messages = [];
$dbError = '';
$conn = null;

try {
    $conn = db();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if ($conn instanceof mysqli && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_medicine') {
        $name = clean($_POST['name'] ?? '');
        $brand = clean($_POST['brand'] ?? '');
        $category = clean($_POST['category'] ?? '');
        $batchNumber = clean($_POST['batch_number'] ?? '');
        $manufactureDate = clean($_POST['manufacture_date'] ?? '');
        $expiryDate = clean($_POST['expiry_date'] ?? '');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $supplier = clean($_POST['supplier'] ?? '');

        if ($name === '' || $brand === '' || $category === '' || $batchNumber === '' || $supplier === '') {
            $errors[] = 'All text fields are required.';
        }

        if (!is_valid_date($manufactureDate) || !is_valid_date($expiryDate)) {
            $errors[] = 'Please enter valid dates in YYYY-MM-DD format.';
        }

        if ($quantity < 0) {
            $errors[] = 'Quantity must be 0 or more.';
        }

        if (is_valid_date($manufactureDate) && is_valid_date($expiryDate) && $expiryDate <= $manufactureDate) {
            $errors[] = 'Expiry date must be later than manufacture date.';
        }

        if (!$errors) {
            $stmt = $conn->prepare('INSERT INTO medicines (name, brand, category, batch_number, manufacture_date, expiry_date, quantity, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssis', $name, $brand, $category, $batchNumber, $manufactureDate, $expiryDate, $quantity, $supplier);
            if ($stmt->execute()) {
                $messages[] = 'Medicine added successfully.';
            } else {
                $errors[] = 'Could not add medicine. Batch number may already exist.';
            }
            $stmt->close();
        }
    }

    if ($action === 'adjust_stock') {
        $medicineId = (int) ($_POST['medicine_id'] ?? 0);
        $adjustmentType = clean($_POST['adjustment_type'] ?? 'sale');
        $adjustmentQty = (int) ($_POST['adjustment_qty'] ?? 0);
        $reason = clean($_POST['reason'] ?? '');

        if ($medicineId <= 0) {
            $errors[] = 'Please select a medicine.';
        }

        if ($adjustmentQty <= 0) {
            $errors[] = 'Adjustment quantity must be greater than 0.';
        }

        if (!in_array($adjustmentType, ['sale', 'manual'], true)) {
            $errors[] = 'Invalid adjustment type.';
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                $lockStmt = $conn->prepare('SELECT quantity FROM medicines WHERE id = ? FOR UPDATE');
                $lockStmt->bind_param('i', $medicineId);
                $lockStmt->execute();
                $result = $lockStmt->get_result();
                $row = $result->fetch_assoc();
                $lockStmt->close();

                if (!$row) {
                    throw new RuntimeException('Medicine not found.');
                }

                $currentQty = (int) $row['quantity'];
                $newQty = $adjustmentType === 'sale' ? $currentQty - $adjustmentQty : $adjustmentQty;

                if ($adjustmentType === 'sale' && $newQty < 0) {
                    throw new RuntimeException('Cannot reduce below 0.');
                }

                $updateStmt = $conn->prepare('UPDATE medicines SET quantity = ? WHERE id = ?');
                $updateStmt->bind_param('ii', $newQty, $medicineId);
                $updateStmt->execute();
                $updateStmt->close();

                $changeValue = $adjustmentType === 'sale' ? -$adjustmentQty : $newQty - $currentQty;

                $logStmt = $conn->prepare('INSERT INTO stock_adjustments (medicine_id, adjustment_type, change_amount, reason) VALUES (?, ?, ?, ?)');
                $logStmt->bind_param('isis', $medicineId, $adjustmentType, $changeValue, $reason);
                $logStmt->execute();
                $logStmt->close();

                $conn->commit();
                $messages[] = 'Stock adjusted successfully.';
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = 'Stock adjustment failed: ' . $e->getMessage();
            }
        }
    }
}

$medicines = [];
$expiredList = [];
$nearExpiryList = [];
$outOfStockList = [];

if ($conn instanceof mysqli) {
    $medicines = $conn->query('SELECT * FROM medicines ORDER BY expiry_date ASC, name ASC')->fetch_all(MYSQLI_ASSOC);
    $expiredList = $conn->query('SELECT * FROM medicines WHERE expiry_date < CURDATE() ORDER BY expiry_date ASC')->fetch_all(MYSQLI_ASSOC);
    $nearExpiryList = $conn->query('SELECT * FROM medicines WHERE expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY expiry_date ASC')->fetch_all(MYSQLI_ASSOC);
    $outOfStockList = $conn->query('SELECT * FROM medicines WHERE quantity = 0 ORDER BY name ASC')->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Stock & Expiry Manager</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --text: #0e1a2a;
            --muted: #5b6b80;
            --accent: #1f6feb;
            --danger: #c62828;
            --warn30: #ffebee;
            --warn60: #fff4e5;
            --warn90: #fffde7;
            --ok: #e9f7ef;
            --border: #d7e0ea;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.4;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 12px;
        }

        h1 { font-size: 1.35rem; margin: 0 0 12px; }
        h2 { font-size: 1.1rem; margin: 0 0 10px; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .grid {
            display: grid;
            gap: 8px;
            grid-template-columns: 1fr;
        }

        input, select, button {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
        }

        button {
            background: var(--accent);
            color: #fff;
            border: none;
            font-weight: bold;
            cursor: pointer;
        }

        .alert {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .alert.error { background: #fdecea; color: var(--danger); }
        .alert.success { background: #edf7ed; color: #146c2e; }

        .medicine-item {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
            background: #fff;
        }

        .meta { color: var(--muted); font-size: 0.9rem; }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-top: 6px;
        }

        .expired { background: #ffe5e5; color: #940000; }
        .warn-30 { background: var(--warn30); color: #ad1457; }
        .warn-60 { background: var(--warn60); color: #b26a00; }
        .warn-90 { background: var(--warn90); color: #756100; }
        .safe { background: var(--ok); color: #1e7c3b; }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .count-pill {
            font-size: 0.8rem;
            padding: 2px 8px;
            border-radius: 999px;
            background: #e5eef9;
            color: #285e9f;
        }

        @media (min-width: 760px) {
            h1 { font-size: 1.7rem; }
            .grid.two { grid-template-columns: 1fr 1fr; }
            .grid.three { grid-template-columns: 1fr 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Medicine Stock &amp; Expiry Manager</h1>

    <?php if ($dbError !== ''): ?>
        <div class="alert error">
            <?= htmlspecialchars($dbError) ?>. Please check <code>config.php</code> and import <code>database.sql</code>.
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endforeach; ?>

    <section class="card">
        <h2>Add Medicine</h2>
        <?php if (!($conn instanceof mysqli)): ?><p class="meta">Database unavailable: form disabled.</p><?php endif; ?>
        <form method="post" class="grid two" <?= !($conn instanceof mysqli) ? 'aria-disabled="true"' : '' ?>>
            <input type="hidden" name="action" value="add_medicine">
            <input name="name" placeholder="Medicine Name" required <?= !($conn instanceof mysqli) ? 'disabled' : '' ?>>
            <input name="brand" placeholder="Brand" required>
            <input name="category" placeholder="Category" required>
            <input name="batch_number" placeholder="Batch Number" required>
            <input type="date" name="manufacture_date" required>
            <input type="date" name="expiry_date" required>
            <input type="number" min="0" name="quantity" placeholder="Quantity" required>
            <input name="supplier" placeholder="Supplier" required>
            <div style="grid-column:1/-1;"><button type="submit">Save Medicine</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Stock Adjustment</h2>
        <?php if (!($conn instanceof mysqli)): ?><p class="meta">Database unavailable: form disabled.</p><?php endif; ?>
        <form method="post" class="grid two" <?= !($conn instanceof mysqli) ? 'aria-disabled="true"' : '' ?>>
            <input type="hidden" name="action" value="adjust_stock">
            <select name="medicine_id" required>
                <option value="">Select Medicine</option>
                <?php foreach ($medicines as $medicine): ?>
                    <option value="<?= (int) $medicine['id'] ?>">
                        <?= htmlspecialchars($medicine['name']) ?> (Batch: <?= htmlspecialchars($medicine['batch_number']) ?>, Qty: <?= (int) $medicine['quantity'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="adjustment_type" required>
                <option value="sale">Reduce on Sale</option>
                <option value="manual">Manual Correction (Set Exact Qty)</option>
            </select>
            <input type="number" min="1" name="adjustment_qty" placeholder="Qty Sold / New Qty" required>
            <input name="reason" placeholder="Reason (optional)">
            <div style="grid-column:1/-1;"><button type="submit">Apply Adjustment</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>All Medicines</h2>
            <span class="count-pill"><?= count($medicines) ?> items</span>
        </div>
        <?php if (!$medicines): ?>
            <p class="meta">No medicines added yet.</p>
        <?php endif; ?>
        <?php foreach ($medicines as $medicine): ?>
            <?php $daysLeft = date_diff_days($medicine['expiry_date']); ?>
            <div class="medicine-item <?= expiry_badge_class($daysLeft) ?>">
                <strong><?= htmlspecialchars($medicine['name']) ?></strong> - <?= htmlspecialchars($medicine['brand']) ?>
                <div class="meta">Category: <?= htmlspecialchars($medicine['category']) ?> | Batch: <?= htmlspecialchars($medicine['batch_number']) ?></div>
                <div class="meta">MFG: <?= htmlspecialchars($medicine['manufacture_date']) ?> | EXP: <?= htmlspecialchars($medicine['expiry_date']) ?></div>
                <div class="meta">Qty: <?= (int) $medicine['quantity'] ?> | Supplier: <?= htmlspecialchars($medicine['supplier']) ?></div>
                <span class="badge <?= expiry_badge_class($daysLeft) ?>"><?= expiry_text($daysLeft) ?></span>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>Near-Expiry Report (90 Days)</h2>
            <span class="count-pill"><?= count($nearExpiryList) ?> items</span>
        </div>
        <?php foreach ($nearExpiryList as $medicine): ?>
            <?php $daysLeft = date_diff_days($medicine['expiry_date']); ?>
            <div class="medicine-item">
                <strong><?= htmlspecialchars($medicine['name']) ?></strong>
                <div class="meta">EXP: <?= htmlspecialchars($medicine['expiry_date']) ?> | <?= expiry_text($daysLeft) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$nearExpiryList): ?><p class="meta">No near-expiry stock.</p><?php endif; ?>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>Expired Stock</h2>
            <span class="count-pill"><?= count($expiredList) ?> items</span>
        </div>
        <?php foreach ($expiredList as $medicine): ?>
            <?php $daysLeft = date_diff_days($medicine['expiry_date']); ?>
            <div class="medicine-item expired">
                <strong><?= htmlspecialchars($medicine['name']) ?></strong>
                <div class="meta">Batch: <?= htmlspecialchars($medicine['batch_number']) ?> | EXP: <?= htmlspecialchars($medicine['expiry_date']) ?></div>
                <span class="badge expired"><?= expiry_text($daysLeft) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if (!$expiredList): ?><p class="meta">No expired medicines.</p><?php endif; ?>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>Out-of-Stock Report</h2>
            <span class="count-pill"><?= count($outOfStockList) ?> items</span>
        </div>
        <?php foreach ($outOfStockList as $medicine): ?>
            <div class="medicine-item">
                <strong><?= htmlspecialchars($medicine['name']) ?></strong>
                <div class="meta">Batch: <?= htmlspecialchars($medicine['batch_number']) ?> | Qty: 0</div>
            </div>
        <?php endforeach; ?>
        <?php if (!$outOfStockList): ?><p class="meta">No out-of-stock medicines.</p><?php endif; ?>
    </section>
</div>
</body>
</html>
