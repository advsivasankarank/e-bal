<?php

function renderLLP($data) {
?>

<h2>Balance Sheet (LLP)</h2>
<table border="1" width="100%">
<tr><th>Particulars</th><th>Amount</th></tr>

<tr><td><b>Partners’ Capital</b></td><td><?= $data['partners_capital'] ?></td></tr>

<tr><td><b>Reserves</b></td><td><?= $data['reserves'] ?></td></tr>

<tr><td><b>Liabilities</b></td><td><?= $data['liabilities'] ?></td></tr>

<tr><td><b>Assets</b></td><td><?= $data['assets'] ?></td></tr>

<tr><td><b>Total</b></td><td><?= $data['total'] ?></td></tr>
</table>

<br>

<h2>Statement of Profit & Loss</h2>
<table border="1" width="100%">
<tr><td>Total Income</td><td><?= $data['income'] ?></td></tr>
<tr><td>Total Expenses</td><td><?= $data['expenses'] ?></td></tr>
<tr><td><b>Net Profit</b></td><td><?= $data['profit'] ?></td></tr>
</table>

<br>

<h2>Notes to Accounts</h2>

<h3>1. Partners’ Capital</h3>
<p>Opening, Additions, Drawings, Closing Balance</p>

<h3>2. Loans & Liabilities</h3>
<p>Details of borrowings</p>

<h3>3. Fixed Assets</h3>
<p>Asset movement & depreciation</p>

<h3>4. Accounting Policies</h3>
<p>
- LLP accounts prepared as per ICAI AS<br>
- Accrual basis followed
</p>

<?php } ?>