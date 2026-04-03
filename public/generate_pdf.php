<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/core/mapping_engine.php';
require_once __DIR__ . '/../app/core/notes_engine.php';
require_once __DIR__ . '/../app/core/pdf_engine.php';

$data = $_SESSION['classified_data'] ?? [];
$company_id = 1;

/* ===============================
   🔷 BUILD BS DATA
================================ */

$bs = [];

foreach ($data as $row) {

    if ($row['type'] != 'Balance Sheet') continue;

    $code = getMapping($pdo, $company_id, trim($row['name']));

    if (!$code) continue;

    if (!isset($bs[$code])) $bs[$code] = 0;

    $bs[$code] += $row['amount'];
}

/* ===============================
   🔷 BUILD PL DATA
================================ */

$pl = [];

foreach ($data as $row) {

    if ($row['type'] != 'P&L') continue;

    $code = getMapping($pdo, $company_id, trim($row['name']));

    if (!$code) continue;

    if (!isset($pl[$code])) $pl[$code] = 0;

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

/* ===============================
   🔷 BUILD NOTES
================================ */

$notes = buildNotes($pdo, $company_id, $data);

/* ===============================
   🔷 PROFESSIONAL HTML TEMPLATE
================================ */

$html = "
<style>
body { font-family: DejaVu Sans; font-size: 12px; }

h2, h3 { text-align:center; margin:5px; }

table { width:100%; border-collapse: collapse; margin-top:10px; }

th, td { border:1px solid #000; padding:6px; }

.section { font-weight:bold; background:#eee; }

.right { text-align:right; }
</style>

<h2>ABC PRIVATE LIMITED</h2>
<h3>Balance Sheet as at 31 March 2025</h3>

<table>
<tr>
<th>Particulars</th>
<th>Note No</th>
<th>Amount</th>
</tr>

<tr class='section'><td colspan='3'>EQUITY & LIABILITIES</td></tr>

<tr><td>Share Capital</td><td>1</td><td class='right'>".number_format($bs['SC'] ?? 0,2)."</td></tr>
<tr><td>Reserves & Surplus</td><td>2</td><td class='right'>".number_format($bs['RS'] ?? 0,2)."</td></tr>
<tr><td>Borrowings</td><td>3</td><td class='right'>".number_format(($bs['LT_BORR'] ?? 0)+($bs['ST_BORR'] ?? 0),2)."</td></tr>
<tr><td>Trade Payables</td><td>4</td><td class='right'>".number_format($bs['TP'] ?? 0,2)."</td></tr>

<tr class='section'><td colspan='3'>ASSETS</td></tr>

<tr><td>Fixed Assets</td><td>5</td><td class='right'>".number_format($bs['PPE'] ?? 0,2)."</td></tr>
<tr><td>Trade Receivables</td><td>6</td><td class='right'>".number_format($bs['TR'] ?? 0,2)."</td></tr>
<tr><td>Cash & Bank</td><td>7</td><td class='right'>".number_format($bs['CASH'] ?? 0,2)."</td></tr>
<tr><td>Loans & Advances</td><td>8</td><td class='right'>".number_format($bs['ADV'] ?? 0,2)."</td></tr>

</table>

<br><br>

<h3>Statement of Profit & Loss</h3>

<table>
<tr><th>Particulars</th><th>Amount</th></tr>

<tr><td>Revenue from Operations</td><td class='right'>".number_format($pl['REV'] ?? 0,2)."</td></tr>
<tr><td>Other Income</td><td class='right'>".number_format($pl['OTH_INC'] ?? 0,2)."</td></tr>
<tr><td><b>Total Revenue</b></td><td class='right'><b>".number_format($total_revenue,2)."</b></td></tr>

<tr><td>Purchase</td><td class='right'>".number_format($pl['PUR'] ?? 0,2)."</td></tr>
<tr><td>Employee Cost</td><td class='right'>".number_format($pl['EMP'] ?? 0,2)."</td></tr>
<tr><td>Finance Cost</td><td class='right'>".number_format($pl['FIN'] ?? 0,2)."</td></tr>
<tr><td>Depreciation</td><td class='right'>".number_format($pl['DEP'] ?? 0,2)."</td></tr>
<tr><td>Other Expenses</td><td class='right'>".number_format($pl['EXP'] ?? 0,2)."</td></tr>

<tr><td><b>Profit / (Loss)</b></td><td class='right'><b>".number_format($profit,2)."</b></td></tr>
</table>

<br><br>

<h3>Notes to Accounts</h3>
";

/* ===============================
   🔷 NOTES WITH NUMBERING
================================ */

$noteNo = 1;

foreach ($notes as $code => $rows) {

    $html .= "<h4>Note $noteNo</h4>";
    $html .= "<table>";

    foreach ($rows as $r) {
        $html .= "<tr>
                    <td>{$r['name']}</td>
                    <td class='right'>".number_format($r['amount'],2)."</td>
                  </tr>";
    }

    $html .= "</table><br>";

    $noteNo++;
}

/* ===============================
   🔷 SIGNATURE BLOCK
================================ */

$html .= "
<br><br><br>

<table width='100%' style='border:none;'>
<tr style='border:none;'>
<td style='border:none;'>Director</td>
<td style='border:none; text-align:right;'>Director</td>
</tr>
</table>
";

/* ===============================
   🔷 GENERATE PDF
================================ */

generatePDF($html);