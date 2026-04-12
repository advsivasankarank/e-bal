<?php
function renderCashFlow($data) {
?>
<h2>Cash Flow Statement</h2>

<table border="1" width="100%">
<tr><td>Operating Activities</td><td><?= $data['operating'] ?></td></tr>
<tr><td>Investing Activities</td><td><?= $data['investing'] ?></td></tr>
<tr><td>Financing Activities</td><td><?= $data['financing'] ?></td></tr>

<tr><td><b>Net Cash Flow</b></td><td><?= $data['net'] ?></td></tr>
</table>
<?php } ?>