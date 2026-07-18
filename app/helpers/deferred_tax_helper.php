<?php
/**
 * e-BAL — Deferred Tax Calculator (AS-22 timing differences)
 *
 * AS-22 requires deferred tax to be recognised on TIMING DIFFERENCES
 * between accounting income and taxable income that will reverse in
 * future periods -- not merely carried forward as whatever DTA/DTL ledger
 * balance exists in Tally (the prior, disclosed-as-a-limitation behaviour
 * in fs_engine.php's buildDeferredTaxSection()).
 *
 * The single most common, and often only material, timing difference for
 * a small/mid-size Indian private company is DEPRECIATION: Schedule II
 * (books) and the Income-tax Act, 1961 (Section 32, WDV method, rates
 * under Income-tax Rules 1962 Appendix I) almost never agree. This module
 * computes that automatically by running a SECOND, parallel WDV schedule
 * over the SAME asset register (fixed_assets, from the Depreciation
 * Calculator) using Income-tax rates and the Income-tax Act's own <180-day
 * half-rate rule (a different, binary pro-rata rule from Schedule II's
 * exact-day pro-rata) -- never stored, always computed live so it can
 * never drift from the asset register it's derived from.
 *
 * Other timing differences (Section 43B disallowances -- unpaid PF/ESI,
 * bonus, leave encashment; provisions for doubtful debts/gratuity; etc.)
 * and carried-forward business losses/unabsorbed depreciation are entered
 * manually, since none of that is derivable from Tally at all. Per AS-22,
 * a DTA on carried-forward losses may only be recognised where there is
 * VIRTUAL CERTAINTY (a materially higher bar than "probable") of future
 * taxable income -- enforced here as an explicit confirmation checkbox,
 * not assumed.
 */

require_once __DIR__ . '/fixed_asset_helper.php';

function ensureDeferredTaxItemsSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deferred_tax_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'other',
            description VARCHAR(255) NOT NULL DEFAULT '',
            book_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            classification VARCHAR(3) NOT NULL DEFAULT 'DTA',
            virtual_certainty_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            notes VARCHAR(500) NOT NULL DEFAULT '',
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Income-tax Act, 1961 (Income-tax Rules 1962, Appendix I) block-of-assets
 * WDV rates -- deliberately mapped from the SAME categories
 * fixedAssetCategoryList() already uses for Schedule II, so every asset in
 * the register automatically has both a book and a tax rate without asking
 * the CA to classify twice. These are the common/general rates; a CA
 * dealing with a special-rate asset (e.g. energy-saving plant at a higher
 * rate) should adjust via the manual "Other" timing-difference entry
 * instead of expecting this table to cover every special case.
 */
function incomeTaxBlockRate(string $category): float
{
    $table = [
        'Buildings (RCC, other than factory)' => 10.0,
        'Buildings (other than RCC)' => 10.0,
        'Factory Buildings' => 10.0,
        'Plant & Machinery (general)' => 15.0,
        'Furniture and Fixtures' => 10.0,
        'Office Equipment' => 15.0,
        'Computers and Data Processing Units (Servers)' => 40.0,
        'Computers and Data Processing Units (End User Devices)' => 40.0,
        'Vehicles (Motor Cars, other than for hire)' => 15.0,
        'Vehicles (used in a business of running on hire)' => 30.0,
        'Intangible Assets' => 25.0,
        'Other' => 15.0,
    ];

    return $table[$category] ?? 15.0;
}

/**
 * Income-tax WDV depreciation for one asset/ledger row over the year --
 * parallels computeAssetDepreciation() in fixed_asset_helper.php but with
 * Income-tax's own rules, which differ from Schedule II in three material
 * ways: (1) WDV only, no SLM option; (2) the <180-days rule -- an asset
 * put to use for less than 180 days in the year gets HALF the block rate
 * on ITS addition for that year, not day-proportional pro-rata; (3) no
 * residual value floor -- the Income-tax block simply continues until
 * written down to zero or the block ceases to exist.
 */
function computeAssetTaxDepreciation(array $asset, string $fyStart, string $fyEnd): array
{
    $openingGross = (float) ($asset['opening_gross_block'] ?? 0);
    $openingAccumDep = (float) ($asset['opening_accumulated_depreciation'] ?? 0);
    $openingWdv = max(0.0, $openingGross - $openingAccumDep);
    $additions = (float) ($asset['additions_during_year'] ?? 0);
    $disposalsCost = (float) ($asset['disposals_during_year'] ?? 0);
    $isDisposed = !empty($asset['is_disposed']);
    $rate = incomeTaxBlockRate((string) ($asset['asset_category'] ?? '')) / 100;

    $blockAfterDisposal = max(0.0, $openingWdv - $disposalsCost);

    if ($isDisposed && $disposalsCost >= $openingWdv + $additions) {
        // Whole block ceases to exist -- no depreciation, block goes to 0.
        return ['opening_wdv' => $openingWdv, 'closing_wdv' => 0.0, 'depreciation_for_year' => 0.0];
    }

    $additionDays = null;
    if ($additions > 0.0 && !empty($asset['addition_date'])) {
        $end = new DateTimeImmutable($fyEnd);
        $add = new DateTimeImmutable((string) $asset['addition_date']);
        $additionDays = $add > $end ? 0 : ($end->diff($add > new DateTimeImmutable($fyStart) ? $add : new DateTimeImmutable($fyStart))->days + 1);
    }
    // <180 days in use this year => half rate on the addition only.
    $additionRateFactor = ($additionDays !== null && $additionDays < 180) ? 0.5 : 1.0;

    $depreciationOnOpening = $blockAfterDisposal * $rate;
    $depreciationOnAddition = $additions * $rate * $additionRateFactor;
    $totalDepreciation = $depreciationOnOpening + $depreciationOnAddition;

    $closingWdv = max(0.0, $blockAfterDisposal + $additions - $totalDepreciation);

    return [
        'opening_wdv' => $openingWdv,
        'closing_wdv' => round($closingWdv, 2),
        'depreciation_for_year' => round($totalDepreciation, 2),
    ];
}

/**
 * Aggregates the Income-tax WDV schedule by asset category, mirroring
 * computeDepreciationSchedule()'s shape in fixed_asset_helper.php so the
 * two can be compared category-by-category.
 */
function computeTaxDepreciationSchedule(PDO $pdo, int $company_id, int $fy_id, string $fyStart, string $fyEnd): array
{
    $assets = getFixedAssets($pdo, $company_id, $fy_id);

    $byCategory = [];
    foreach ($assets as $asset) {
        $category = trim((string) ($asset['asset_category'] ?? '')) ?: 'Uncategorised';
        $computed = computeAssetTaxDepreciation($asset, $fyStart, $fyEnd);

        if (!isset($byCategory[$category])) {
            $byCategory[$category] = ['category' => $category, 'opening_wdv' => 0.0, 'closing_wdv' => 0.0, 'depreciation_for_year' => 0.0];
        }
        $byCategory[$category]['opening_wdv'] += $computed['opening_wdv'];
        $byCategory[$category]['closing_wdv'] += $computed['closing_wdv'];
        $byCategory[$category]['depreciation_for_year'] += $computed['depreciation_for_year'];
    }
    ksort($byCategory);

    return ['by_category' => array_values($byCategory), 'has_data' => $assets !== []];
}

/**
 * Compares book WDV (Schedule II, from the Depreciation Calculator)
 * against tax WDV (Income-tax Act, computed above) per asset category.
 * Per standard AS-22 treatment: where Tax WDV > Book WDV, books have
 * cumulatively depreciated MORE than tax has allowed so far -- taxable
 * income has been correspondingly HIGHER than book income, i.e. more tax
 * has effectively been paid already than the books show as expense, a
 * prepaid-tax-like position => Deferred Tax ASSET. Where Tax WDV < Book
 * WDV (the more common case -- Income-tax rates are usually higher than
 * Schedule II lives imply, e.g. Computers at 40% vs a 3-6 year book life),
 * tax has already used up more of the depreciation than books have =>
 * Deferred Tax LIABILITY, since future years will show book depreciation
 * exceeding tax depreciation (book profit will then be lower than taxable
 * profit reversing the other way).
 */
function computeDeferredTaxOnDepreciation(array $bookSchedule, array $taxSchedule, float $taxRatePct): array
{
    $taxByCategory = [];
    foreach ($taxSchedule['by_category'] as $row) {
        $taxByCategory[$row['category']] = $row;
    }

    $rows = [];
    $netAmount = 0.0; // positive = net DTA, negative = net DTL
    foreach ($bookSchedule['by_category'] as $bookRow) {
        $category = $bookRow['category'];
        $bookWdv = (float) ($bookRow['closing_wdv'] ?? 0);
        $taxWdv = (float) ($taxByCategory[$category]['closing_wdv'] ?? 0);
        $difference = $taxWdv - $bookWdv;
        $amount = round(abs($difference) * ($taxRatePct / 100), 2);

        $rows[] = [
            'category' => $category,
            'book_wdv' => $bookWdv,
            'tax_wdv' => $taxWdv,
            'difference' => round($difference, 2),
            'classification' => $difference >= 0 ? 'DTA' : 'DTL',
            'amount' => $amount,
        ];
        $netAmount += $difference >= 0 ? $amount : -$amount;
    }

    return ['rows' => $rows, 'net_amount' => round($netAmount, 2)];
}

function getDeferredTaxItems(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureDeferredTaxItemsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM deferred_tax_items WHERE company_id = ? AND fy_id = ? ORDER BY category, id');
    $stmt->execute([$company_id, $fy_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function addDeferredTaxItem(PDO $pdo, int $company_id, int $fy_id, string $category, string $description, float $bookAmount, float $taxAmount, string $classification, bool $virtualCertainty, string $notes, ?int $userId): void
{
    ensureDeferredTaxItemsSchema($pdo);
    if (!in_array($category, ['other', 'carry_forward_loss'], true)) {
        $category = 'other';
    }
    if (!in_array($classification, ['DTA', 'DTL'], true)) {
        $classification = 'DTA';
    }
    $pdo->prepare('
        INSERT INTO deferred_tax_items (company_id, fy_id, category, description, book_amount, tax_amount, classification, virtual_certainty_confirmed, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$company_id, $fy_id, $category, $description, $bookAmount, $taxAmount, $classification, $virtualCertainty ? 1 : 0, $notes, $userId]);
}

function deleteDeferredTaxItem(PDO $pdo, int $company_id, int $fy_id, int $itemId): void
{
    $pdo->prepare('DELETE FROM deferred_tax_items WHERE company_id = ? AND fy_id = ? AND id = ?')->execute([$company_id, $fy_id, $itemId]);
}

/**
 * Full computation: depreciation timing difference (automatic) + manual
 * "other" items + carried-forward losses (only counted toward the net
 * figure when virtual certainty is confirmed -- unconfirmed loss DTAs are
 * still listed, for transparency, but excluded from the recognised total,
 * per AS-22's higher recognition bar for loss-driven DTAs).
 */
function computeNetDeferredTax(PDO $pdo, int $company_id, int $fy_id, string $fyStart, string $fyEnd, float $taxRatePct): array
{
    $bookSchedule = computeDepreciationSchedule($pdo, $company_id, $fy_id, $fyStart, $fyEnd);
    $taxSchedule = computeTaxDepreciationSchedule($pdo, $company_id, $fy_id, $fyStart, $fyEnd);
    $depreciationDT = computeDeferredTaxOnDepreciation($bookSchedule, $taxSchedule, $taxRatePct);

    $items = getDeferredTaxItems($pdo, $company_id, $fy_id);
    $netAmount = $depreciationDT['net_amount'];
    $unrecognisedLossDta = 0.0;
    $itemRows = [];

    foreach ($items as $item) {
        $timingDifference = round(abs((float) $item['book_amount'] - (float) $item['tax_amount']), 2);
        $amount = round($timingDifference * ($taxRatePct / 100), 2);
        $signedAmount = $item['classification'] === 'DTA' ? $amount : -$amount;

        $recognised = true;
        if ($item['category'] === 'carry_forward_loss' && !$item['virtual_certainty_confirmed']) {
            $recognised = false;
            $unrecognisedLossDta += $amount;
        }

        if ($recognised) {
            $netAmount += $signedAmount;
        }

        $itemRows[] = $item + ['amount' => $amount, 'recognised' => $recognised];
    }

    return [
        'has_data' => $bookSchedule['has_data'] || $items !== [],
        'tax_rate_pct' => $taxRatePct,
        'depreciation' => $depreciationDT,
        'other_items' => $itemRows,
        'unrecognised_loss_dta' => round($unrecognisedLossDta, 2),
        'net_amount' => round($netAmount, 2),
        'net_classification' => $netAmount >= 0 ? 'DTA' : 'DTL',
    ];
}
