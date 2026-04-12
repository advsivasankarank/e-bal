<?php

function renderNonCorporate($data) {
?>

<h2>Balance Sheet (Non-Corporate Entity)</h2>

<table border="1" width="100%">
<tr><th>Particulars</th><th>Amount</th></tr>

<tr><td><b>Capital</b></td><td><?= $data['capital'] ?></td></tr>

<tr><td><b>Liabilities</b></td><td><?= $data['liabilities'] ?></td></tr>

<tr><td><b>Assets</b></td><td><?= $data['assets'] ?></td></tr>

<tr><td><b>Total</b></td><td><?= $data['total'] ?></td></tr>
</table>

<br>

<h2>Income & Expenditure / Profit & Loss</h2>

<table border="1" width="100%">
<tr><td>Income</td><td><?= $data['income'] ?></td></tr>
<tr><td>Expenses</td><td><?= $data['expenses'] ?></td></tr>
<tr><td><b>Surplus / Deficit</b></td><td><?= $data['surplus'] ?></td></tr>
</table>

<br>

<h2>Notes to Accounts</h2>

<h3>1. Capital Account</h3>
<p>Opening + Profit – Drawings</p>

<h3>2. Loans & Advances</h3>
<p>Details of loans</p>

<h3>3. Fixed Assets</h3>
<p>Asset details</p>

<h3>4. Accounting Policies</h3>
<p>
- Accrual system followed<br>
- Assets at historical cost
</p>

<?php } ?>