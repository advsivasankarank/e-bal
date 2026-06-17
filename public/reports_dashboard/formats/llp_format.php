<?php
$grossProfitLlpp = $data['revenue'] + ($data['inventory'] ?? 0) - ($data['prev_inventory'] ?? 0) - $data['purchases'] - $data['direct_expenses'];
$prevGrossProfitLlpp = $data['prev_revenue'] + ($data['prev_inventory'] ?? 0) - 0 - $data['prev_purchases'] - $data['prev_direct_expenses'];
?>

<section class="report-page" id="trading-account">
<h2 class="report-page-title">Trading Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td colspan="3"><b>Dr. Side</b></td></tr>
<tr><td>Opening Stock</td><td class="figure"><?= format_inr($data['prev_inventory'] ?? 0) ?></td><td class="figure"><?= format_inr(0) ?></td></tr>
<tr><td>Purchases</td><td class="figure"><?= format_inr($data['purchases']) ?></td><td class="figure"><?= format_inr($data['prev_purchases']) ?></td></tr>
<tr><td>Direct Expenses</td><td class="figure"><?= format_inr($data['direct_expenses']) ?></td><td class="figure"><?= format_inr($data['prev_direct_expenses']) ?></td></tr>
<?php $isLlppGp = $grossProfitLlpp >= 0; $isLlppPrevGp = $prevGrossProfitLlpp >= 0; ?>
<?php if (!$isLlppGp): ?>
<tr><td><b>Gross Loss c/d</b></td><td class="figure"><b><?= format_inr(abs($grossProfitLlpp)) ?></b></td><td class="figure"><b><?= format_inr(abs($prevGrossProfitLlpp)) ?></b></td></tr>
<?php endif; ?>

<tr><td colspan="3"><b>Cr. Side</b></td></tr>
<tr><td>Revenue / Sales</td><td class="figure"><?= format_inr($data['revenue']) ?></td><td class="figure"><?= format_inr($data['prev_revenue']) ?></td></tr>
<tr><td>Closing Stock</td><td class="figure"><?= format_inr($data['inventory'] ?? 0) ?></td><td class="figure"><?= format_inr($data['prev_inventory'] ?? 0) ?></td></tr>
<?php if ($isLlppGp): ?>
<tr><td><b>Gross Profit c/d</b></td><td class="figure"><b><?= format_inr($grossProfitLlpp) ?></b></td><td class="figure"><b><?= format_inr($prevGrossProfitLlpp) ?></b></td></tr>
<?php endif; ?>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr><td style="border:0; text-align:right;">
    <strong>For <?= htmlspecialchars($company_meta['entity_type'] ?? 'Entity') ?></strong><br><br><br><br>
    <?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory') ?><br>
    <div class="signature-caption">Signature</div>
</td></tr>
</table>
</section>

<section class="report-page" id="profit-loss">
<h2 class="report-page-title">Profit &amp; Loss Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td><b>Gross Profit b/d</b></td><td></td><td class="figure"><?= format_inr(max($grossProfitLlpp, 0)) ?></td><td class="figure"><?= format_inr(max($prevGrossProfitLlpp, 0)) ?></td></tr>
<?php if (!$isLlppGp): ?>
<tr><td>Gross Loss b/d</td><td></td><td class="figure"><?= format_inr(abs($grossProfitLlpp)) ?></td><td class="figure"><?= format_inr(abs($prevGrossProfitLlpp)) ?></td></tr>
<?php endif; ?>

<tr><td>Expenses</td><td><a href="#note-8"><?= $data['note_refs']['Expenses'] ?? 8 ?></a></td><td class="figure"><?= format_inr($data['expenses']) ?></td><td class="figure"><?= format_inr($data['prev_expenses']) ?></td></tr>

<?php $llpNet = max($grossProfitLlpp, 0) - $data['expenses'] - ($isLlppGp ? 0 : abs($grossProfitLlpp)); ?>
<?php $prevLlpNet = max($prevGrossProfitLlpp, 0) - $data['prev_expenses'] - ($isLlppPrevGp ? 0 : abs($prevGrossProfitLlpp)); ?>
<tr><td>Profit before Remuneration</td><td></td><td class="figure"><?= format_inr($llpNet) ?></td><td class="figure"><?= format_inr($prevLlpNet) ?></td></tr>
<tr><td>Partners Remuneration</td><td></td><td class="figure"><?= format_inr($data['remuneration']) ?></td><td class="figure"><?= format_inr($data['prev_remuneration']) ?></td></tr>
<tr><td><b>Profit After Tax</b></td><td></td><td class="figure"><b><?= format_inr($data['pat']) ?></b></td><td class="figure"><b><?= format_inr($data['prev_pat']) ?></b></td></tr>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr>
    <td style="width:50%; vertical-align:top; border:0; padding:0 20px 0 0;">
        <strong>For Statutory Auditors</strong><br><br><br><br>
        <?= htmlspecialchars($company_meta['auditor_firm'] ?? '') ?><br>
        <?= htmlspecialchars($company_meta['auditor_name'] ?? 'Authorised Signatory') ?>
    </td>
    <td style="width:50%; vertical-align:top; border:0; padding:0; text-align:right;">
        <table class="signature-table" width="100%" style="border:0; border-collapse:collapse;">
            <tr>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Designated Partner 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Designated Partner') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Designated Partner 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Designated Partner') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>

<section class="report-page" id="capital-account">
<h2 class="report-page-title">Capital Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td>Opening Capital</td><td class="figure"><?= format_inr($data['prev_capital']) ?></td><td class="figure"><?= format_inr(0) ?></td></tr>
<tr><td>Add: Capital Introduced</td><td class="figure"><?= format_inr($data['capital_introduced'] ?? 0) ?></td><td class="figure"><?= format_inr($data['prev_capital_introduced'] ?? 0) ?></td></tr>
<tr><td>Add: Net Profit</td><td class="figure"><?= format_inr(max($data['pat'], 0)) ?></td><td class="figure"><?= format_inr(max($data['prev_pat'], 0)) ?></td></tr>
<tr><td>Less: Drawings</td><td class="figure"><?= format_inr($data['drawings'] ?? 0) ?></td><td class="figure"><?= format_inr($data['prev_drawings'] ?? 0) ?></td></tr>
<?php if ($data['pat'] < 0): ?>
<tr><td>Less: Net Loss</td><td class="figure"><?= format_inr(abs($data['pat'])) ?></td><td class="figure"><?= format_inr(abs($data['prev_pat'])) ?></td></tr>
<?php endif; ?>
<tr><td><b>Closing Capital</b></td><td class="figure"><b><?= format_inr($data['capital']) ?></b></td><td class="figure"><b><?= format_inr($data['prev_capital']) ?></b></td></tr>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr><td style="border:0; text-align:right;">
    <strong>For <?= htmlspecialchars($company_meta['entity_type'] ?? 'Entity') ?></strong><br><br><br><br>
    <?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Designated Partner') ?><br>
    <div class="signature-caption">Signature</div>
</td></tr>
</table>
</section>

<section class="report-page" id="balance-sheet">
<h2 class="report-page-title">Balance Sheet (LLP) as at <?= htmlspecialchars($data['date']) ?></h2>
<p class="report-page-subtitle">All amounts are presented in Indian Rupees.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td colspan="4"><b>Partners' Funds</b></td></tr>
<tr><td>Capital Accounts</td><td><a href="#note-1"><?= $data['note_refs']['Partners Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['capital']) ?></td><td class="figure"><?= format_inr($data['prev_capital']) ?></td></tr>
<tr><td>Current Accounts</td><td><a href="#note-2"><?= $data['note_refs']['Partners Current Account / Reserves'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['current_accounts']) ?></td><td class="figure"><?= format_inr($data['prev_current_accounts']) ?></td></tr>

<tr><td colspan="4"><b>Liabilities</b></td></tr>
<tr><td>Borrowings</td><td><a href="#note-3"><?= $data['note_refs']['Borrowings'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['borrowings']) ?></td><td class="figure"><?= format_inr($data['prev_borrowings']) ?></td></tr>

<tr><td colspan="4"><b>Assets</b></td></tr>
<tr><td>Fixed Assets</td><td><a href="#note-5"><?= $data['note_refs']['Fixed Assets'] ?? 5 ?></a></td><td class="figure"><?= format_inr($data['fixed_assets']) ?></td><td class="figure"><?= format_inr($data['prev_fixed_assets']) ?></td></tr>
<tr><td>Current Assets</td><td><a href="#note-6"><?= $data['note_refs']['Current Assets'] ?? 6 ?></a></td><td class="figure"><?= format_inr($data['current_assets']) ?></td><td class="figure"><?= format_inr($data['prev_current_assets']) ?></td></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total']) ?></td><td class="figure"><?= format_inr($data['prev_total']) ?></td></tr>
</table>

<table class="signature-table signature-block" width="100%" style="border:0; border-collapse:collapse;">
<tr>
    <td style="width:50%; vertical-align:top; border:0; padding:0 20px 0 0;">
        <strong>For Statutory Auditors</strong><br><br><br><br>
        <?= htmlspecialchars($company_meta['auditor_firm'] ?? '') ?><br>
        <?= htmlspecialchars($company_meta['auditor_name'] ?? 'Authorised Signatory') ?>
        <div class="signature-caption">Authorised Signatory</div>
    </td>
    <td style="width:50%; vertical-align:top; border:0; padding:0; text-align:right;">
        <table class="signature-table" width="100%" style="border:0; border-collapse:collapse;">
            <tr>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Designated Partner 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Designated Partner') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Designated Partner 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Designated Partner') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>
