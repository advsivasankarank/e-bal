<?php
function renderNonCorpBS($data) {
?>
<h2>Balance Sheet (Non-Corporate)</h2>

<table border="1" width="100%">
<tr><th>Particulars</th><th>Amount</th></tr>

<tr><td><b>Capital</b></td><td><?= $data['capital'] ?></td></tr>
<tr><td><b>Liabilities</b></td><td><?= $data['liabilities'] ?></td></tr>

<tr><td><b>Assets</b></td><td><?= $data['assets'] ?></td></tr>

<tr><td><b>Total</b></td><td><?= $data['total'] ?></td></tr>

</table>
<?php } ?>