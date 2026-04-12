<?php
function renderScheduleIII($d) {
?>

<h2 style="text-align:center;">BALANCE SHEET</h2>
<p style="text-align:center;">As at <?= $d['date'] ?></p>

<table border="1" width="100%" cellpadding="5">
<tr>
    <th>Particulars</th>
    <th>Note No</th>
    <th>Current Year</th>
    <th>Previous Year</th>
</tr>

<!-- EQUITY & LIABILITIES -->
<tr><td colspan="4"><b>I. EQUITY AND LIABILITIES</b></td></tr>

<tr><td colspan="4"><b>(1) Shareholders’ Funds</b></td></tr>
<tr><td>Share Capital</td><td>1</td><td><?= $d['share_capital'] ?></td><td><?= $d['p_share_capital'] ?></td></tr>
<tr><td>Reserves & Surplus</td><td>2</td><td><?= $d['reserves'] ?></td><td><?= $d['p_reserves'] ?></td></tr>

<tr><td colspan="4"><b>(2) Non-Current Liabilities</b></td></tr>
<tr><td>Long-term Borrowings</td><td>3</td><td><?= $d['lt_borrowings'] ?></td><td><?= $d['p_lt_borrowings'] ?></td></tr>
<tr><td>Deferred Tax Liabilities (Net)</td><td>4</td><td><?= $d['deferred_tax'] ?></td><td><?= $d['p_deferred_tax'] ?></td></tr>
<tr><td>Other Long-term Liabilities</td><td>5</td><td><?= $d['other_lt'] ?></td><td><?= $d['p_other_lt'] ?></td></tr>
<tr><td>Long-term Provisions</td><td>6</td><td><?= $d['lt_provisions'] ?></td><td><?= $d['p_lt_provisions'] ?></td></tr>

<tr><td colspan="4"><b>(3) Current Liabilities</b></td></tr>
<tr><td>Short-term Borrowings</td><td>7</td><td><?= $d['st_borrowings'] ?></td><td><?= $d['p_st_borrowings'] ?></td></tr>
<tr><td>Trade Payables</td><td>8</td><td><?= $d['trade_payables'] ?></td><td><?= $d['p_trade_payables'] ?></td></tr>
<tr><td>Other Current Liabilities</td><td>9</td><td><?= $d['other_cl'] ?></td><td><?= $d['p_other_cl'] ?></td></tr>
<tr><td>Short-term Provisions</td><td>10</td><td><?= $d['st_provisions'] ?></td><td><?= $d['p_st_provisions'] ?></td></tr>

<tr><td><b>Total Equity & Liabilities</b></td><td></td><td><?= $d['total_liab'] ?></td><td><?= $d['p_total_liab'] ?></td></tr>

<!-- ASSETS -->
<tr><td colspan="4"><b>II. ASSETS</b></td></tr>

<tr><td colspan="4"><b>(1) Non-Current Assets</b></td></tr>
<tr><td>Property, Plant & Equipment</td><td>11</td><td><?= $d['ppe'] ?></td><td><?= $d['p_ppe'] ?></td></tr>
<tr><td>Capital Work-in-Progress</td><td>12</td><td><?= $d['cwip'] ?></td><td><?= $d['p_cwip'] ?></td></tr>
<tr><td>Intangible Assets</td><td>13</td><td><?= $d['intangibles'] ?></td><td><?= $d['p_intangibles'] ?></td></tr>
<tr><td>Financial Assets - Investments</td><td>14</td><td><?= $d['investments'] ?></td><td><?= $d['p_investments'] ?></td></tr>
<tr><td>Loans</td><td>15</td><td><?= $d['loans'] ?></td><td><?= $d['p_loans'] ?></td></tr>
<tr><td>Deferred Tax Assets</td><td>16</td><td><?= $d['dta'] ?></td><td><?= $d['p_dta'] ?></td></tr>
<tr><td>Other Non-current Assets</td><td>17</td><td><?= $d['other_nca'] ?></td><td><?= $d['p_other_nca'] ?></td></tr>

<tr><td colspan="4"><b>(2) Current Assets</b></td></tr>
<tr><td>Inventories</td><td>18</td><td><?= $d['inventory'] ?></td><td><?= $d['p_inventory'] ?></td></tr>
<tr><td>Trade Receivables</td><td>19</td><td><?= $d['receivables'] ?></td><td><?= $d['p_receivables'] ?></td></tr>
<tr><td>Cash & Bank Balances</td><td>20</td><td><?= $d['cash'] ?></td><td><?= $d['p_cash'] ?></td></tr>
<tr><td>Short-term Loans & Advances</td><td>21</td><td><?= $d['st_loans'] ?></td><td><?= $d['p_st_loans'] ?></td></tr>
<tr><td>Other Current Assets</td><td>22</td><td><?= $d['other_ca'] ?></td><td><?= $d['p_other_ca'] ?></td></tr>

<tr><td><b>Total Assets</b></td><td></td><td><?= $d['total_assets'] ?></td><td><?= $d['p_total_assets'] ?></td></tr>

</table>

<br><br>

<!-- PROFIT & LOSS -->
<h2 style="text-align:center;">STATEMENT OF PROFIT AND LOSS</h2>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th>Amount</th></tr>

<tr><td>Revenue from Operations</td><td>23</td><td><?= $d['revenue'] ?></td></tr>
<tr><td>Other Income</td><td>24</td><td><?= $d['other_income'] ?></td></tr>

<tr><td><b>Total Income</b></td><td></td><td><?= $d['total_income'] ?></td></tr>

<tr><td>Cost of Materials Consumed</td><td>25</td><td><?= $d['materials'] ?></td></tr>
<tr><td>Employee Benefit Expenses</td><td>26</td><td><?= $d['employee'] ?></td></tr>
<tr><td>Finance Costs</td><td>27</td><td><?= $d['finance'] ?></td></tr>
<tr><td>Depreciation</td><td>28</td><td><?= $d['depreciation'] ?></td></tr>
<tr><td>Other Expenses</td><td>29</td><td><?= $d['other_exp'] ?></td></tr>

<tr><td><b>Total Expenses</b></td><td></td><td><?= $d['total_exp'] ?></td></tr>

<tr><td><b>Profit Before Tax</b></td><td></td><td><?= $d['pbt'] ?></td></tr>
<tr><td>Tax Expense</td><td></td><td><?= $d['tax'] ?></td></tr>
<tr><td><b>Profit After Tax</b></td><td></td><td><?= $d['pat'] ?></td></tr>

</table>

<br><br>

<!-- NOTES -->
<h2>NOTES TO ACCOUNTS</h2>

<h3>1. Share Capital</h3>
<p>Authorized, Issued, Subscribed and Paid-up capital with reconciliation.</p>

<h3>2. Reserves & Surplus</h3>
<p>Opening balance, additions, deductions, closing balance.</p>

<h3>3. Borrowings</h3>
<p>Secured / unsecured classification with terms.</p>

<h3>4. PPE</h3>
<p>Gross block, depreciation, net block reconciliation.</p>

<h3>5. Trade Receivables</h3>
<p>Ageing classification (as per Schedule III amendment).</p>

<h3>6. Trade Payables</h3>
<p>MSME and others bifurcation mandatory.</p>

<h3>7. Accounting Policies</h3>
<p>
- Accrual basis<br>
- Historical cost convention<br>
- Revenue recognition policy<br>
- Depreciation method
</p>

<?php } ?>