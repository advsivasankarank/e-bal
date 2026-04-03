<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../mapping_engine/mapping_engine.php';

if (!isset($_SESSION['classified_data'])) {
    die("No data available.");
}

$data = $_SESSION['classified_data'];
$company_id = $_SESSION['company_id'] ?? 1;

$bs = [];

/* 🔷 BUILD FROM MAPPING */
foreach ($data as $row) {

    if ($row['type'] != 'Balance Sheet') continue;

    $code = getMapping($pdo, $company_id, $row['name']);

    if (!$code) continue;

    if (!isset($bs[$code])) {
        $bs[$code] = 0;
    }

    $bs[$code] += $row['amount'];
}
?>

<?php require_once __DIR__ . '/../layouts/header.php'; ?>

<h2>Balance Sheet (Schedule III)</h2>

<table>
<tr>
<th>Particulars</th>
<th>Amount</th>
</tr>

<tr class="section"><td colspan="2">EQUITY & LIABILITIES</td></tr>

<tr><td>Share Capital</td><td><?php echo number_format($bs['SC'] ?? 0,2); ?></td></tr>
<tr><td>Reserves & Surplus</td><td><?php echo number_format($bs['RS'] ?? 0,2); ?></td></tr>
<tr><td>Borrowings</td><td><?php echo number_format(($bs['LT_BORR'] ?? 0)+($bs['ST_BORR'] ?? 0),2); ?></td></tr>
<tr><td>Trade Payables</td><td><?php echo number_format($bs['TP'] ?? 0,2); ?></td></tr>
<tr><td>Other Liabilities</td><td><?php echo number_format($bs['OCL'] ?? 0,2); ?></td></tr>

<tr class="section"><td colspan="2">ASSETS</td></tr>

<tr><td>Fixed Assets</td><td><?php echo number_format($bs['PPE'] ?? 0,2); ?></td></tr>
<tr><td>Investments</td><td><?php echo number_format($bs['INV'] ?? 0,2); ?></td></tr>
<tr><td>Trade Receivables</td><td><?php echo number_format($bs['TR'] ?? 0,2); ?></td></tr>
<tr><td>Cash & Bank</td><td><?php echo number_format($bs['CASH'] ?? 0,2); ?></td></tr>
<tr><td>Loans & Advances</td><td><?php echo number_format($bs['ADV'] ?? 0,2); ?></td></tr>
<tr><td>Inventory</td><td><?php echo number_format($bs['STOCK'] ?? 0,2); ?></td></tr>

</table>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>