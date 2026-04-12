<?php
function renderLLPBS($data) {
?>
<h2>Balance Sheet (LLP)</h2>

<table border="1" width="100%">
<tr><th>Particulars</th><th>Amount</th></tr>

<tr><td><b>Partners’ Funds</b></td><td><?= $data['partners_capital'] ?></td></tr>

<tr><td><b>Liabilities</b></td><td></td></tr>
<tr><td>Borrowings</td><td><?= $data['borrowings'] ?></td></tr>

<tr><td><b>Assets</b></td><td></td></tr>
<tr><td>Fixed Assets</td><td><?= $data['fixed_assets'] ?></td></tr>
<tr><td>Current Assets</td><td><?= $data['current_assets'] ?></td></tr>

<tr><td><b>Total</b></td><td><?= $data['total'] ?></td></tr>

</table>
<?php } ?>