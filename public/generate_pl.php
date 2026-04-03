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

$pl = [];

/* 🔷 BUILD FROM MAPPING */
foreach ($data as $row) {

    if ($row['type'] != 'P&L') continue;

    $code = getMapping($pdo, $company_id, $row['name']);

    if (!$code) continue;

    if (!isset($pl[$code])) {
        $pl[$code] = 0;
    }

    $pl[$code] += $row['amount'];
}

$total_revenue = ($pl['REV'] ?? 0) + ($pl['OTH_INC'] ?? 0);
$total_expense =
    ($pl['PUR'] ?? 0) +
    ($pl['EMP'] ?? 0) +
    ($pl['FIN'] ?? 0) +
    ($pl['DEP'] ?? 0) +
    ($pl['EXP'] ?? 0);

$profit = $total_revenue - $total_expense;
?>

<?php require_once __DIR__ . '/../layouts/header.php'; ?>

<h2>Statement of Profit & Loss</h2>

<table>

<tr class="section"><td colspan="2">Revenue</td></tr>
<tr><td>Revenue from Operations</td><td><?php echo number_format($pl['REV'] ?? 0,2); ?></td></tr>
<tr><td>Other Income</td><td><?php echo number_format($pl['OTH_INC'] ?? 0,2); ?></td></tr>

<tr class="section"><td>Total Revenue</td><td><?php echo number_format($total_revenue,2); ?></td></tr>

<tr class="section"><td colspan="2">Expenses</td></tr>
<tr><td>Purchase</td><td><?php echo number_format($pl['PUR'] ?? 0,2); ?></td></tr>
<tr><td>Employee Cost</td><td><?php echo number_format($pl['EMP'] ?? 0,2); ?></td></tr>
<tr><td>Finance Cost</td><td><?php echo number_format($pl['FIN'] ?? 0,2); ?></td></tr>
<tr><td>Depreciation</td><td><?php echo number_format($pl['DEP'] ?? 0,2); ?></td></tr>
<tr><td>Other Expenses</td><td><?php echo number_format($pl['EXP'] ?? 0,2); ?></td></tr>

<tr class="section"><td>Total Expenses</td><td><?php echo number_format($total_expense,2); ?></td></tr>

<tr class="section"><td>Profit / (Loss)</td><td><?php echo number_format($profit,2); ?></td></tr>

</table>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>