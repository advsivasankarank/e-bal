<?php
function renderCompanyBS($data) {
?>
<h2>Balance Sheet</h2>

<table border="1" width="100%">
<tr><th>Particulars</th><th>Current Year</th><th>Previous Year</th></tr>

<tr><td colspan="3"><b>I. EQUITY AND LIABILITIES</b></td></tr>

<tr><td><b>1. Shareholders Funds</b></td><td></td><td></td></tr>
<tr><td>Share Capital</td><td><?= $data['share_capital'] ?></td><td><?= $data['prev_share_capital'] ?></td></tr>
<tr><td>Reserves & Surplus</td><td><?= $data['reserves'] ?></td><td><?= $data['prev_reserves'] ?></td></tr>

<tr><td><b>2. Non-Current Liabilities</b></td><td></td><td></td></tr>
<tr><td>Long Term Borrowings</td><td><?= $data['lt_borrowings'] ?></td><td><?= $data['prev_lt_borrowings'] ?></td></tr>

<tr><td><b>3. Current Liabilities</b></td><td></td><td></td></tr>
<tr><td>Trade Payables</td><td><?= $data['trade_payables'] ?></td><td><?= $data['prev_trade_payables'] ?></td></tr>

<tr><td colspan="3"><b>II. ASSETS</b></td></tr>

<tr><td><b>Non Current Assets</b></td><td></td><td></td></tr>
<tr><td>Property, Plant & Equipment</td><td><?= $data['ppe'] ?></td><td><?= $data['prev_ppe'] ?></td></tr>

<tr><td><b>Current Assets</b></td><td></td><td></td></tr>
<tr><td>Inventories</td><td><?= $data['inventory'] ?></td><td><?= $data['prev_inventory'] ?></td></tr>
<tr><td>Trade Receivables</td><td><?= $data['receivables'] ?></td><td><?= $data['prev_receivables'] ?></td></tr>

<tr><td><b>Total</b></td><td><?= $data['total'] ?></td><td><?= $data['prev_total'] ?></td></tr>

</table>
<?php } ?>