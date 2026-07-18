<?php
/**
 * e-BAL — Fixed Asset Register & Depreciation Schedule (Schedule II)
 *
 * Standalone page for maintaining the per-asset register that feeds
 * computeDepreciationSchedule() in fs_engine.php: upload a prior-year
 * depreciation schedule (Excel), sync this year's PPE/CWIP ledgers from
 * Tally (already-classified via ReconHub), classify each asset by
 * category/useful life/method, and preview the resulting Schedule III
 * Fixed Assets note before it flows into the actual financial statements.
 */
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/fixed_asset_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$userId = (int) ($_SESSION['user_id'] ?? 0);

ensureFixedAssetRegisterSchema($pdo);

$fyDatesStmt = $pdo->prepare('SELECT fy_start, fy_end FROM financial_years WHERE id = ? AND company_id = ?');
$fyDatesStmt->execute([$fy_id, $company_id]);
$fyDatesRow = $fyDatesStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$fyStart = (string) ($fyDatesRow['fy_start'] ?? '');
$fyEnd = (string) ($fyDatesRow['fy_end'] ?? '');

$infoMessage = '';
$warningMessage = '';
$errorMessages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['asset_action'] ?? '');

    if ($action === 'upload_excel') {
        if (empty($_FILES['prior_year_excel']) || $_FILES['prior_year_excel']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages[] = 'No file uploaded, or the upload failed.';
        } elseif ($_FILES['prior_year_excel']['size'] > 10 * 1024 * 1024) {
            $errorMessages[] = 'File too large. Maximum size is 10 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedType = $finfo->file($_FILES['prior_year_excel']['tmp_name']);
            $filename = (string) ($_FILES['prior_year_excel']['name'] ?? '');
            $extension = strtolower(substr($filename, strrpos($filename, '.') ?: 0));
            $allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
            if (!in_array($detectedType, $allowedTypes, true) && $extension !== '.xlsx') {
                $errorMessages[] = 'Only .xlsx files are accepted.';
            } else {
                $parsed = parseFixedAssetExcelUpload($_FILES['prior_year_excel']['tmp_name']);
                if ($parsed['rows'] !== []) {
                    saveFixedAssetExcelImport($pdo, $company_id, $fy_id, $parsed['rows'], $userId, $filename);
                    $infoMessage = count($parsed['rows']) . ' asset row(s) imported from "' . htmlspecialchars($filename) . '".';

                    /* Compare the just-uploaded opening balances against
                       Tally's own classified PPE/CWIP closing balance right
                       away, rather than waiting for the CA to separately
                       visit Review Centre -- this is exactly the gap that
                       produced the real ~54 lakh reconciliation surprise
                       found earlier, and it's cheap to check immediately. */
                    $classifiedForReconciliation = getClassifiedData($pdo, $company_id, $fy_id);
                    foreach (detectFixedAssetRegisterIssues($pdo, $company_id, $fy_id, $classifiedForReconciliation) as $warning) {
                        if ($warning['check'] === 'fixed_asset_excel_reconciliation') {
                            $errorMessages[] = $warning['message'];
                        }
                    }
                }
                $errorMessages = array_merge($errorMessages, $parsed['errors']);
            }
        }
    } elseif ($action === 'sync_tally') {
        $classified = getClassifiedData($pdo, $company_id, $fy_id);
        if ($fyStart === '' || $fyEnd === '') {
            $errorMessages[] = 'Financial year start/end dates are not set for this company -- cannot determine which vouchers fall in this year.';
        } else {
            $syncResult = syncFixedAssetVouchersFromTally($pdo, $company_id, $fy_id, $classified, $fyStart, $fyEnd);
            if (!$syncResult['ok']) {
                $errorMessages[] = $syncResult['message'];
            } elseif ($syncResult['created'] === 0) {
                $diag = $syncResult['diagnostics'] ?? [];
                $totalFromTally = (int) ($diag['total_vouchers_from_tally'] ?? 0);
                $ledgerCount = (int) ($diag['fixed_asset_ledger_count'] ?? 0);
                $matchedEntries = (int) ($diag['matched_voucher_entries'] ?? 0);
                $source = (string) ($diag['voucher_source'] ?? 'unknown');

                if ($totalFromTally === 0) {
                    $warningMessage = "Tally returned 0 vouchers for {$fyStart} to {$fyEnd} (via {$source}) -- nothing was fetched at all, so this isn't specific to Fixed Assets. Check that Tally is open to the correct company and financial year, and that the Smart Bridge / connection is actually able to reach it (not just showing \"Connected\").";
                } elseif ($matchedEntries === 0) {
                    $warningMessage = "Tally returned {$totalFromTally} voucher(s) via {$source}, but none of them touched any of the {$ledgerCount} ledger(s) classified as Fixed Assets/CWIP within {$fyStart} to {$fyEnd}. Either there genuinely were no Fixed Asset purchases/disposals this year, or the ledger names in those vouchers don't exactly match the names classified in ReconHub (check for trailing spaces or renamed ledgers).";
                } else {
                    $warningMessage = "Tally returned {$totalFromTally} voucher(s) via {$source}, and {$matchedEntries} touched a Fixed Asset/CWIP ledger, but all of them were classified as depreciation/revaluation journals and excluded -- see the excluded list below if one was expected to be a genuine addition or disposal.";
                }
            } else {
                $infoMessage = $syncResult['message'] . ' Classify category and useful life below.';
            }
            if (!empty($syncResult['ok']) && ($syncResult['excluded'] ?? []) !== []) {
                $excludedNames = array_map(
                    static fn (array $e): string => $e['ledger_name'] . ' (' . $e['voucher_type'] . ' #' . $e['voucher_number'] . ', ' . $e['date'] . ')',
                    $syncResult['excluded']
                );
                $excludedNote = count($syncResult['excluded']) . ' voucher(s) touching a Fixed Asset ledger were excluded as likely depreciation/revaluation journals, not genuine additions or disposals -- review manually if needed: ' . implode('; ', $excludedNames);
                if ($warningMessage !== '') {
                    $warningMessage .= ' ' . $excludedNote;
                } else {
                    $infoMessage .= ' ' . $excludedNote;
                }
            }
        }
    } elseif ($action === 'save_classification') {
        $ids = $_POST['asset_id'] ?? [];
        foreach ($ids as $index => $assetId) {
            updateFixedAssetRow($pdo, $company_id, $fy_id, (int) $assetId, [
                'asset_category' => trim((string) ($_POST['asset_category'][$index] ?? '')),
                'useful_life_years' => trim((string) ($_POST['useful_life_years'][$index] ?? '')) !== '' ? (float) $_POST['useful_life_years'][$index] : null,
                'residual_value_pct' => trim((string) ($_POST['residual_value_pct'][$index] ?? '')) !== '' ? (float) $_POST['residual_value_pct'][$index] : 5.0,
                'depreciation_method' => strtoupper((string) ($_POST['depreciation_method'][$index] ?? 'SLM')) === 'WDV' ? 'WDV' : 'SLM',
                'is_disposed' => !empty($_POST['is_disposed'][$index]) ? 1 : 0,
                'disposal_date' => trim((string) ($_POST['disposal_date'][$index] ?? '')) !== '' ? $_POST['disposal_date'][$index] : null,
                'addition_date' => trim((string) ($_POST['addition_date'][$index] ?? '')) !== '' ? $_POST['addition_date'][$index] : null,
            ]);
        }
        $infoMessage = 'Asset classification saved.';
    } elseif ($action === 'delete_asset') {
        deleteFixedAssetRow($pdo, $company_id, $fy_id, (int) ($_POST['asset_id'] ?? 0));
        $infoMessage = 'Asset removed from the register.';
    } elseif ($action === 'clear_legacy_tally') {
        $cleared = clearLegacyLedgerSyncedFixedAssets($pdo, $company_id, $fy_id);
        $infoMessage = "Removed {$cleared} row(s) created by the old ledger-balance sync. Use \"Sync from Tally\" above to rebuild the register from actual vouchers.";
    } elseif ($action === 'reset_register') {
        $cleared = resetFixedAssetRegister($pdo, $company_id, $fy_id);
        $infoMessage = "Register reset -- removed all {$cleared} row(s) (Excel, Tally, and manual). Start over with the Excel upload or Tally sync above.";
    }
}

$assets = getFixedAssets($pdo, $company_id, $fy_id);
$schedule = ($fyStart !== '' && $fyEnd !== '')
    ? computeDepreciationSchedule($pdo, $company_id, $fy_id, $fyStart, $fyEnd)
    : ['by_category' => [], 'totals' => [], 'asset_count' => 0, 'has_data' => false];
$categoryList = fixedAssetCategoryList();
$legacyCount = countLegacyLedgerSyncedFixedAssets($pdo, $company_id, $fy_id);

require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
    ['label' => 'Asset Register'],
]) ?>

<?= uiPageHero('Fixed Asset Register', 'Depreciation Schedule under Schedule II to the Companies Act, 2013') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => '',
    'profile' => 0,
    'status' => $schedule['has_data'] ? ($schedule['asset_count'] . ' asset(s) registered') : 'No assets registered yet',
    'edit_url' => '',
]) ?>

<?php if ($infoMessage !== ''): ?>
    <div class="card section-card"><?= htmlspecialchars($infoMessage) ?></div>
<?php endif; ?>
<?php if ($warningMessage !== ''): ?>
    <div class="card section-card" style="background:#fffbeb;border:1px solid #fcd34d;">
        <strong style="color:#92400e;">⚠ Sync from Tally found nothing to add</strong>
        <p style="font-size:0.85rem;color:#475569;margin:6px 0 0;"><?= htmlspecialchars($warningMessage) ?></p>
    </div>
<?php endif; ?>
<?php foreach ($errorMessages as $err): ?>
    <div class="card section-card" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<?php if ($legacyCount > 0): ?>
<div class="card section-card" style="background:#fffbeb;border:1px solid #fcd34d;">
    <strong style="color:#92400e;">⚠ <?= $legacyCount ?> row(s) in this register were created by the old "Sync from Tally"</strong>
    <p style="font-size:0.85rem;color:#475569;margin:6px 0 10px;">That method approximated each ledger's addition/disposal from its Trial Balance movement for the year (zero opening balance, no addition date) -- it's been replaced by a real voucher-level sync above. These old rows won't self-correct; clear them and re-run "Sync from Tally" to rebuild the register from actual purchase/sale vouchers with accurate dates.</p>
    <form method="post" onsubmit="return confirm('Remove all <?= $legacyCount ?> row(s) created by the old ledger-based sync? This does not affect Excel-imported or manually-entered rows.');">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="clear_legacy_tally">
        <button class="btn-primary" type="submit">Clear <?= $legacyCount ?> Old Row(s)</button>
    </form>
</div>
<?php endif; ?>

<div class="card section-card">
    <h3 style="margin-top:0;">1. Upload Prior-Year Depreciation Schedule (Optional)</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Excel (.xlsx) with columns: Asset Category, Description, Opening Gross Block, Opening Accumulated Depreciation, Useful Life (Years) [optional], Method [optional, SLM/WDV]. These become this year's opening balances -- the only source for opening balances; Tally sync below never overrides them. The upload is immediately compared against Tally's own classified PPE/CWIP closing balance for last year, and any mismatch is flagged below.</p>
    <form method="post" enctype="multipart/form-data">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="upload_excel">
        <input type="file" name="prior_year_excel" accept=".xlsx" required>
        <button class="btn-primary" type="submit">Upload &amp; Import</button>
    </form>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">2. Sync Additions/Disposals from Tally</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Not a Trial Balance fetch -- pulls this year's actual Tally <strong>vouchers</strong> (Purchase, Journal, Payment, Sales, Receipt) that debit or credit a Property, Plant &amp; Equipment or Capital Work-in-Progress ledger (already classified via ReconHub), and creates one register row per voucher using that voucher's own Tally date as the addition/disposal date -- for accurate day-wise pro-rata under Schedule II, instead of one row per ledger with a single approximated date. Opening balances are never touched here; they only ever come from the Excel upload above. Re-running this is safe -- it updates existing rows from the same voucher rather than duplicating them.</p>
    <form method="post">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="sync_tally">
        <button class="btn-primary" type="submit">Sync from Tally</button>
    </form>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">3. Classify Assets</h3>
    <?php if ($assets === []): ?>
        <p style="font-size:0.85rem;color:var(--muted);">No assets in the register yet -- upload a prior-year schedule or sync from Tally above.</p>
    <?php else: ?>
    <form method="post">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="save_classification">
        <div style="overflow-x:auto;">
        <table class="note-table" border="1" width="100%" cellpadding="5" style="font-size:0.82rem;">
            <thead>
            <tr>
                <th>Description</th>
                <th>Source</th>
                <th>Opening Gross</th>
                <th>Additions</th>
                <th>Category</th>
                <th>Useful Life (Yrs)</th>
                <th>Residual %</th>
                <th>Method</th>
                <th>Addition Date</th>
                <th>Disposed?</th>
                <th>Disposal Date</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($assets as $i => $asset): ?>
            <tr>
                <td<?= !empty($asset['voucher_narration']) ? ' title="' . htmlspecialchars((string) $asset['voucher_narration']) . '"' : '' ?>>
                    <input type="hidden" name="asset_id[]" value="<?= (int) $asset['id'] ?>">
                    <?= htmlspecialchars((string) $asset['asset_description']) ?>
                </td>
                <td><?= htmlspecialchars((string) $asset['source']) ?></td>
                <td class="figure"><?= format_inr((float) $asset['opening_gross_block']) ?></td>
                <td class="figure"><?= format_inr((float) $asset['additions_during_year']) ?></td>
                <td>
                    <select name="asset_category[]">
                        <option value="">-- Select --</option>
                        <?php foreach ($categoryList as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $asset['asset_category'] === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" step="0.5" name="useful_life_years[]" value="<?= htmlspecialchars((string) ($asset['useful_life_years'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) scheduleIIUsefulLife((string) $asset['asset_category'])['years']) ?>" style="width:70px;"></td>
                <td><input type="number" step="0.01" name="residual_value_pct[]" value="<?= htmlspecialchars((string) $asset['residual_value_pct']) ?>" style="width:60px;"></td>
                <td>
                    <select name="depreciation_method[]">
                        <option value="SLM" <?= $asset['depreciation_method'] === 'SLM' ? 'selected' : '' ?>>SLM</option>
                        <option value="WDV" <?= $asset['depreciation_method'] === 'WDV' ? 'selected' : '' ?>>WDV</option>
                    </select>
                </td>
                <td><input type="date" name="addition_date[]" value="<?= htmlspecialchars((string) ($asset['addition_date'] ?? '')) ?>"></td>
                <td style="text-align:center;"><input type="checkbox" name="is_disposed[<?= $i ?>]" value="1" <?= !empty($asset['is_disposed']) ? 'checked' : '' ?>></td>
                <td><input type="date" name="disposal_date[]" value="<?= htmlspecialchars((string) ($asset['disposal_date'] ?? '')) ?>"></td>
                <td>
                    <button type="button" class="btn-outline btn-sm" onclick="document.getElementById('delAsset<?= (int) $asset['id'] ?>').submit();">&times;</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button class="btn-primary" type="submit" style="margin-top:12px;">Save Classification</button>
    </form>
    <?php foreach ($assets as $asset): ?>
    <form method="post" id="delAsset<?= (int) $asset['id'] ?>" style="display:none;">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="delete_asset">
        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
    </form>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">4. Computed Depreciation Schedule Preview</h3>
    <?php if (!$schedule['has_data']): ?>
        <p style="font-size:0.85rem;color:var(--muted);">Nothing to preview yet.</p>
    <?php else: ?>
        <table class="note-table" border="1" width="100%" cellpadding="5" style="font-size:0.82rem;">
            <thead>
            <tr>
                <th>Category</th>
                <th>Opening Gross</th>
                <th>Additions</th>
                <th>Disposals</th>
                <th>Closing Gross</th>
                <th>Dep. for Year</th>
                <th>Closing Accum. Dep.</th>
                <th>Closing WDV</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($schedule['by_category'] as $cat): ?>
            <tr>
                <td><?= htmlspecialchars((string) $cat['category']) ?></td>
                <td class="figure"><?= format_inr((float) $cat['opening_gross_block']) ?></td>
                <td class="figure"><?= format_inr((float) $cat['additions']) ?></td>
                <td class="figure"><?= format_inr((float) $cat['disposals']) ?></td>
                <td class="figure"><?= format_inr((float) $cat['closing_gross_block']) ?></td>
                <td class="figure"><?= format_inr((float) $cat['depreciation_for_year']) ?></td>
                <td class="figure"><?= format_inr((float) $cat['closing_accumulated_depreciation']) ?></td>
                <td class="figure"><b><?= format_inr((float) $cat['closing_wdv']) ?></b></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['opening_gross_block']) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['additions']) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['disposals']) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['closing_gross_block']) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['depreciation_for_year']) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['closing_accumulated_depreciation']) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) $schedule['totals']['closing_wdv']) ?></strong></td>
            </tr>
            </tfoot>
        </table>
        <p style="font-size:0.8rem;color:var(--muted);margin-top:8px;">This total feeds the Fixed Assets note and the P&amp;L Depreciation line once saved -- review <a href="<?= BASE_URL ?>review/index.php">Review Centre</a> for any reconciliation warnings before relying on it.</p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
    <strong>Next Step</strong><br>
    Once the register is classified, return to Financial Statements to see the Fixed Assets note and Depreciation figure reflect this schedule.<br><br>
    <a class="btn" href="<?= BASE_URL ?>reports.php#notes-to-accounts">Back to Financial Statements</a>
</div>

<div class="card section-card" style="margin-top:16px;background:#fef2f2;border:1px solid #fecaca;">
    <h3 style="margin-top:0;color:#991b1b;">Reset Register</h3>
    <p style="font-size:0.85rem;color:#475569;">Removes every row in this register -- Excel-imported, Tally-synced, and manually entered alike -- so you can start over from scratch. This does not undo depreciation figures already saved into the Financial Statements; re-save from a rebuilt register afterwards.</p>
    <?php if ($assets === []): ?>
        <button class="btn-outline" type="button" disabled style="color:#991b1b;border-color:#fecaca;opacity:0.5;cursor:not-allowed;">Reset Register (Nothing to Reset)</button>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('This will permanently remove all <?= count($assets) ?> row(s) currently in this register (Excel, Tally, and manual). This cannot be undone. Continue?');">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="reset_register">
        <button class="btn-outline" type="submit" style="color:#991b1b;border-color:#fecaca;">Reset Register (Delete All <?= count($assets) ?> Row<?= count($assets) === 1 ? '' : 's' ?>)</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
