<?php
function renderCompanyPL($data) {
?>
<h2>Statement of Profit and Loss</h2>

<table border="1" width="100%">
<tr><th>Particulars</th><th>Amount</th></tr>

<tr><td>Revenue from Operations</td><td><?= $data['revenue'] ?></td></tr>
<tr><td>Other Income</td><td><?= $data['other_income'] ?></td></tr>

<tr><td><b>Total Income</b></td><td><?= $data['total_income'] ?></td></tr>

<tr><td>Cost of Materials</td><td><?= $data['materials'] ?></td></tr>
<tr><td>Employee Benefit Expense</td><td><?= $data['employee'] ?></td></tr>
<tr><td>Depreciation</td><td><?= $data['depreciation'] ?></td></tr>

<tr><td><b>Total Expenses</b></td><td><?= $data['total_expenses'] ?></td></tr>

<tr><td><b>Profit Before Tax</b></td><td><?= $data['pbt'] ?></td></tr>
<tr><td>Tax Expense</td><td><?= $data['tax'] ?></td></tr>

<tr><td><b>Profit After Tax</b></td><td><?= $data['pat'] ?></td></tr>

</table>
<?php } ?>