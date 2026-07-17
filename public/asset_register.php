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
                }
                $errorMessages = array_merge($errorMessages, $parsed['errors']);
            }
        }
    } elseif ($action === 'sync_tally') {
        $classified = getClassifiedData($pdo, $company_id, $fy_id);
        $syncResult = syncFixedAssetsFromTallyClassification($pdo, $company_id, $fy_id, $classified);
        $infoMessage = $syncResult['created'] . ' asset(s) synced from Tally\'s classified Fixed Assets/CWIP ledgers. Classify category and useful life below.';
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
    }
}

$assets = getFixedAssets($pdo, $company_id, $fy_id);
$schedule = ($fyStart !== '' && $fyEnd !== '')
    ? computeDepreciationSchedule($pdo, $company_id, $fy_id, $fyStart, $fyEnd)
    : ['by_category' => [], 'totals' => [], 'asset_count' => 0, 'has_data' => false];
$categoryList = fixedAssetCategoryList();

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
<?php foreach ($errorMessages as $err): ?>
    <div class="card section-card" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card section-card">
    <h3 style="margin-top:0;">1. Upload Prior-Year Depreciation Schedule (Optional)</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Excel (.xlsx) with columns: Asset Category, Description, Opening Gross Block, Opening Accumulated Depreciation, Useful Life (Years) [optional], Method [optional, SLM/WDV]. These become this year's opening balances.</p>
    <form method="post" enctype="multipart/form-data">
        <?= csrfInput() ?>
        <input type="hidden" name="asset_action" value="upload_excel">
        <input type="file" name="prior_year_excel" accept=".xlsx" required>
        <button class="btn-primary" type="submit">Upload &amp; Import</button>
    </form>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">2. Sync Additions/Disposals from Tally</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Pulls this year's classified Property, Plant &amp; Equipment and Capital Work-in-Progress ledgers (already mapped via ReconHub) that aren't yet in the register, using each ledger's balance movement as this year's addition or disposal.</p>
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
                <td>
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

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
