<?php
/**
 * e-BAL — Deferred Tax Calculator (AS 22)
 *
 * Standalone page for computing the AS-22 timing-difference Deferred Tax
 * position: an automatic book-vs-tax depreciation comparison (reusing the
 * Fixed Asset Register from the Depreciation Calculator) plus manually
 * entered "other" timing differences and carried-forward losses. Feeds
 * buildDeferredTaxSection() in fs_engine.php once data exists for this
 * company/FY -- see the sign-convention note there.
 */
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/deferred_tax_helper.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$userId = (int) ($_SESSION['user_id'] ?? 0);

ensureDeferredTaxItemsSchema($pdo);

$fyDatesStmt = $pdo->prepare('SELECT fy_start, fy_end FROM financial_years WHERE id = ? AND company_id = ?');
$fyDatesStmt->execute([$fy_id, $company_id]);
$fyDatesRow = $fyDatesStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$fyStart = (string) ($fyDatesRow['fy_start'] ?? '');
$fyEnd = (string) ($fyDatesRow['fy_end'] ?? '');

$infoMessage = '';
$errorMessages = [];

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, (string) $fyName);
$manualInputs = $manualBundle['current'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['dt_action'] ?? '');

    if ($action === 'save_rate') {
        $rate = (float) ($_POST['deferred_tax_rate_pct'] ?? 25.17);
        if ($rate < 0 || $rate > 100) {
            $errorMessages[] = 'Tax rate must be between 0 and 100.';
        } else {
            saveManualInputs($pdo, $company_id, $fy_id, ['deferred_tax_rate_pct' => $rate]);
            $manualInputs['deferred_tax_rate_pct'] = (string) $rate;
            $infoMessage = 'Tax rate saved.';
        }
    } elseif ($action === 'add_item') {
        $category = (string) ($_POST['category'] ?? 'other');
        $description = trim((string) ($_POST['description'] ?? ''));
        $bookAmount = (float) ($_POST['book_amount'] ?? 0);
        $taxAmount = (float) ($_POST['tax_amount'] ?? 0);
        $classification = (string) ($_POST['classification'] ?? 'DTA');
        $virtualCertainty = !empty($_POST['virtual_certainty_confirmed']);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($description === '') {
            $errorMessages[] = 'Description is required.';
        } else {
            addDeferredTaxItem($pdo, $company_id, $fy_id, $category, $description, $bookAmount, $taxAmount, $classification, $virtualCertainty, $notes, $userId);
            $infoMessage = 'Timing difference item added.';
        }
    } elseif ($action === 'delete_item') {
        deleteDeferredTaxItem($pdo, $company_id, $fy_id, (int) ($_POST['item_id'] ?? 0));
        $infoMessage = 'Item removed.';
    }
}

$taxRatePct = (float) manualAmount($manualInputs, 'deferred_tax_rate_pct', 25.17);
$items = getDeferredTaxItems($pdo, $company_id, $fy_id);

$computed = ($fyStart !== '' && $fyEnd !== '')
    ? computeNetDeferredTax($pdo, $company_id, $fy_id, $fyStart, $fyEnd, $taxRatePct)
    : ['has_data' => false, 'tax_rate_pct' => $taxRatePct, 'depreciation' => ['rows' => [], 'net_amount' => 0.0], 'other_items' => [], 'unrecognised_loss_dta' => 0.0, 'net_amount' => 0.0, 'net_classification' => 'DTA'];

require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
    ['label' => 'Deferred Tax Calculator'],
]) ?>

<?= uiPageHero('Deferred Tax Calculator', 'AS 22 -- Accounting for Taxes on Income (timing-difference method)') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => '',
    'profile' => 0,
    'status' => $computed['has_data']
        ? ('Net position: ' . $computed['net_classification'] . ' ' . format_inr(abs((float) $computed['net_amount'])))
        : 'No data yet -- populate the Asset Register or add a timing-difference item below',
    'edit_url' => '',
]) ?>

<?php if ($infoMessage !== ''): ?>
    <div class="card section-card"><?= htmlspecialchars($infoMessage) ?></div>
<?php endif; ?>
<?php foreach ($errorMessages as $err): ?>
    <div class="card section-card" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card section-card">
    <h3 style="margin-top:0;">1. Applicable Tax Rate</h3>
    <p style="font-size:0.85rem;color:var(--muted);">The effective corporate tax rate used to convert timing differences into a deferred tax amount.</p>
    <form method="post" style="display:flex;gap:12px;align-items:end;">
        <?= csrfInput() ?>
        <input type="hidden" name="dt_action" value="save_rate">
        <div>
            <label style="display:block;font-size:0.8rem;color:var(--muted);">Tax Rate (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="deferred_tax_rate_pct" value="<?= htmlspecialchars((string) $taxRatePct) ?>" style="width:120px;">
        </div>
        <button class="btn-primary" type="submit">Save Rate</button>
    </form>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">2. Depreciation Timing Difference (Automatic)</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Compares the book WDV (Schedule II, from the <a href="<?= BASE_URL ?>asset_register.php">Fixed Asset Register</a>) against a parallel WDV computed under the Income-tax Act, 1961 (Section 32, block-of-assets method with the &lt;180-days half-rate rule) for the same assets, category by category.</p>
    <?php if ($computed['depreciation']['rows'] === []): ?>
        <p style="font-size:0.85rem;color:var(--muted);">No assets registered yet -- populate the <a href="<?= BASE_URL ?>asset_register.php">Fixed Asset Register</a> first.</p>
    <?php else: ?>
        <table class="note-table" border="1" width="100%" cellpadding="5" style="font-size:0.82rem;">
            <thead>
            <tr>
                <th>Category</th>
                <th>Book WDV</th>
                <th>Tax WDV</th>
                <th>Difference (Tax - Book)</th>
                <th>Classification</th>
                <th>Deferred Tax Amount</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($computed['depreciation']['rows'] as $row): ?>
            <tr>
                <td><?= htmlspecialchars((string) $row['category']) ?></td>
                <td class="figure"><?= format_inr((float) $row['book_wdv']) ?></td>
                <td class="figure"><?= format_inr((float) $row['tax_wdv']) ?></td>
                <td class="figure"><?= format_inr((float) $row['difference']) ?></td>
                <td><?= htmlspecialchars((string) $row['classification']) ?></td>
                <td class="figure"><?= format_inr((float) $row['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="5"><strong>Net (Depreciation only)</strong></td>
                <td class="figure"><strong><?= format_inr(abs((float) $computed['depreciation']['net_amount'])) ?> <?= $computed['depreciation']['net_amount'] >= 0 ? 'DTA' : 'DTL' ?></strong></td>
            </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">3. Other Timing Differences &amp; Carried-Forward Losses</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Section 43B disallowances (unpaid PF/ESI, bonus, leave encashment), provisions for doubtful debts/gratuity, and carried-forward business losses / unabsorbed depreciation. A Deferred Tax Asset on carried-forward losses may only be recognised where there is <strong>virtual certainty</strong> (a higher bar than "probable") of future taxable income, per AS 22 -- confirm explicitly rather than assuming.</p>

    <?php if ($items !== []): ?>
    <table class="note-table" border="1" width="100%" cellpadding="5" style="font-size:0.82rem;margin-bottom:16px;">
        <thead>
        <tr>
            <th>Description</th>
            <th>Category</th>
            <th>Book Amount</th>
            <th>Tax Amount</th>
            <th>Classification</th>
            <th>Virtual Certainty?</th>
            <th>Recognised Amount</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($computed['other_items'] as $item): ?>
        <tr>
            <td><?= htmlspecialchars((string) $item['description']) ?><?= $item['notes'] !== '' ? '<br><span style="color:var(--muted);font-size:0.78rem;">' . htmlspecialchars((string) $item['notes']) . '</span>' : '' ?></td>
            <td><?= $item['category'] === 'carry_forward_loss' ? 'Carried-Forward Loss' : 'Other' ?></td>
            <td class="figure"><?= format_inr((float) $item['book_amount']) ?></td>
            <td class="figure"><?= format_inr((float) $item['tax_amount']) ?></td>
            <td><?= htmlspecialchars((string) $item['classification']) ?></td>
            <td style="text-align:center;"><?= !empty($item['virtual_certainty_confirmed']) ? 'Yes' : ($item['category'] === 'carry_forward_loss' ? 'No' : '--') ?></td>
            <td class="figure"><?= $item['recognised'] ? format_inr((float) $item['amount']) : '<span style="color:#b45309;">Not recognised</span>' ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Remove this item?');">
                    <?= csrfInput() ?>
                    <input type="hidden" name="dt_action" value="delete_item">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <button type="submit" class="btn-outline btn-sm">&times;</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ((float) $computed['unrecognised_loss_dta'] > 0): ?>
        <div class="card section-card" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
            A Deferred Tax Asset of <?= format_inr((float) $computed['unrecognised_loss_dta']) ?> on carried-forward losses has NOT been recognised in the net position above, pending virtual certainty of future taxable income as required under AS 22.
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post" style="margin-top:12px;">
        <?= csrfInput() ?>
        <input type="hidden" name="dt_action" value="add_item">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:900px;">
            <div>
                <label style="display:block;font-size:0.8rem;color:var(--muted);">Category</label>
                <select name="category" style="width:100%;">
                    <option value="other">Other Timing Difference</option>
                    <option value="carry_forward_loss">Carried-Forward Loss</option>
                </select>
            </div>
            <div style="grid-column:span 2;">
                <label style="display:block;font-size:0.8rem;color:var(--muted);">Description</label>
                <input type="text" name="description" style="width:100%;" placeholder="e.g. Provision for gratuity (unfunded)">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;color:var(--muted);">Book Amount</label>
                <input type="number" step="0.01" name="book_amount" style="width:100%;" value="0">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;color:var(--muted);">Tax Amount</label>
                <input type="number" step="0.01" name="tax_amount" style="width:100%;" value="0">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;color:var(--muted);">Classification</label>
                <select name="classification" style="width:100%;">
                    <option value="DTA">Deferred Tax Asset</option>
                    <option value="DTL">Deferred Tax Liability</option>
                </select>
            </div>
            <div style="grid-column:span 3;">
                <label style="display:block;font-size:0.8rem;"><input type="checkbox" name="virtual_certainty_confirmed" value="1"> I confirm virtual certainty of future taxable income (required for a carried-forward-loss DTA to be recognised, per AS 22)</label>
            </div>
            <div style="grid-column:span 3;">
                <label style="display:block;font-size:0.8rem;color:var(--muted);">Notes (optional)</label>
                <input type="text" name="notes" style="width:100%;">
            </div>
        </div>
        <button class="btn-primary" type="submit" style="margin-top:12px;">Add Item</button>
    </form>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">4. Net Deferred Tax Position</h3>
    <?php if (!$computed['has_data']): ?>
        <p style="font-size:0.85rem;color:var(--muted);">Nothing to summarise yet.</p>
    <?php else: ?>
        <p style="font-size:1rem;"><strong>Net Deferred Tax <?= $computed['net_classification'] === 'DTA' ? 'Asset' : 'Liability' ?>: <?= format_inr(abs((float) $computed['net_amount'])) ?></strong></p>
        <p style="font-size:0.8rem;color:var(--muted);">This figure feeds Note on Deferred Tax once saved -- review <a href="<?= BASE_URL ?>review/index.php">Review Centre</a> for any reconciliation warnings before relying on it.</p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
    <strong>Next Step</strong><br>
    Once the tax rate and any manual items are set, return to Financial Statements to see the Deferred Tax note reflect this computation.<br><br>
    <a class="btn" href="<?= BASE_URL ?>reports.php#notes-to-accounts">Back to Financial Statements</a>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
