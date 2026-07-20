<?php
/**
 * Standalone, framework-free assertion script (this repo has no PHPUnit/tests
 * infra — see composer.json). Run with: php tests/fixed_asset_depreciation_test.php
 *
 * Protects the core Schedule II depreciation math in
 * app/helpers/fixed_asset_helper.php against regressions — SLM/WDV
 * formulas, pro-rata for mid-year additions/disposals, the residual-value
 * floor, and the disposed-asset accumulated-depreciation removal (which
 * previously produced a negative closing WDV for a fully-disposed asset
 * before being fixed — see computeAssetDepreciation()'s disposedAccumDepShare
 * comment for why the opening-balance-only version was wrong).
 */

require_once __DIR__ . '/../app/helpers/fixed_asset_helper.php';

$failures = 0;
$passed = 0;

function fixedAssetTestAssert(bool $condition, string $description): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS  $description\n";
    } else {
        $failures++;
        echo "  FAIL  $description\n";
    }
}

function approx(float $a, float $b, float $tolerance = 0.5): bool
{
    return abs($a - $b) <= $tolerance;
}

echo "Fixed Asset Depreciation — Schedule II math test\n";
echo "================================================\n";

$fyStart = '2024-04-01';
$fyEnd = '2025-03-31';

/* 1. SLM, full-year asset, no additions/disposals. */
$asset1 = ['opening_gross_block' => 100000, 'opening_accumulated_depreciation' => 20000, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'depreciation_method' => 'SLM', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r1 = computeAssetDepreciation($asset1, $fyStart, $fyEnd);
fixedAssetTestAssert(approx($r1['depreciation_for_year'], 9500), 'SLM full-year: (100000 - 5% residual) / 10 years = ~9500 depreciation');
fixedAssetTestAssert(approx($r1['closing_wdv'], 70500), 'SLM full-year: closing WDV = 100000 - 20000 - 9500 = ~70500');

/* 2. WDV, full-year asset — rate derived from useful life/residual. */
$asset2 = ['opening_gross_block' => 100000, 'opening_accumulated_depreciation' => 20000, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'depreciation_method' => 'WDV', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r2 = computeAssetDepreciation($asset2, $fyStart, $fyEnd);
fixedAssetTestAssert(approx($r2['depreciation_for_year'], 20710, 5), 'WDV full-year: rate = 1-(0.05)^(1/10) applied to opening WDV of 80000');

/* 3. Pro-rata for a mid-year addition (Note 3 to Schedule II). */
$asset3 = ['opening_gross_block' => 0, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 50000, 'addition_date' => '2024-10-01', 'disposals_during_year' => 0, 'is_disposed' => 0, 'depreciation_method' => 'SLM', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r3 = computeAssetDepreciation($asset3, $fyStart, $fyEnd);
$fullYearDep = (50000 - 50000 * 0.05) / 10;
fixedAssetTestAssert($r3['depreciation_for_year'] > 0 && $r3['depreciation_for_year'] < $fullYearDep, 'Mid-year addition depreciation is pro-rated below a full year\'s charge');

/* 4. Fully disposed asset: closing WDV must land at exactly 0, not negative.
      This is the real bug caught during development -- removing only the
      opening accumulated depreciation (not this year's pre-disposal charge
      too) left a residual accumulated-depreciation balance with no asset
      behind it. */
$asset4 = ['opening_gross_block' => 100000, 'opening_accumulated_depreciation' => 20000, 'additions_during_year' => 0, 'disposals_during_year' => 100000, 'disposal_date' => '2024-09-30', 'is_disposed' => 1, 'depreciation_method' => 'WDV', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r4 = computeAssetDepreciation($asset4, $fyStart, $fyEnd);
fixedAssetTestAssert($r4['closing_gross_block'] == 0.0, 'Fully disposed asset: closing gross block is 0');
fixedAssetTestAssert(approx($r4['closing_wdv'], 0.0, 0.01), 'Fully disposed asset: closing WDV is exactly 0, not negative');

/* 5. Depreciation never exceeds the residual-value floor. */
$asset5 = ['opening_gross_block' => 100000, 'opening_accumulated_depreciation' => 94000, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'depreciation_method' => 'SLM', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r5 = computeAssetDepreciation($asset5, $fyStart, $fyEnd);
fixedAssetTestAssert(approx($r5['closing_wdv'], 5000), 'Depreciation is capped so closing WDV never falls below the 5% residual value');
fixedAssetTestAssert($r5['depreciation_for_year'] < 9500, 'Capped depreciation charge (1000) is less than the uncapped formula result (9500)');

/* 6. Partial disposal within a category-level aggregate row still leaves a
      sane, non-negative WDV on the remaining balance. */
$asset6 = ['opening_gross_block' => 200000, 'opening_accumulated_depreciation' => 40000, 'additions_during_year' => 0, 'disposals_during_year' => 100000, 'disposal_date' => '2024-09-30', 'is_disposed' => 1, 'depreciation_method' => 'SLM', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r6 = computeAssetDepreciation($asset6, $fyStart, $fyEnd);
fixedAssetTestAssert($r6['closing_gross_block'] == 100000.0, 'Partial disposal: closing gross block reflects only the remaining half');
fixedAssetTestAssert($r6['closing_wdv'] > 0 && $r6['closing_wdv'] < 100000, 'Partial disposal: closing WDV is positive and less than closing gross block');

/* 7. Schedule II useful-life lookup returns the prescribed life for a known
      category and flags intangibles as not Schedule-II-prescribed. */
fixedAssetTestAssert(scheduleIIUsefulLife('Plant & Machinery (general)')['years'] == 15.0, 'Schedule II useful life for Plant & Machinery (general) is 15 years');
fixedAssetTestAssert(scheduleIIUsefulLife('Intangible Assets')['is_prescribed'] === false, 'Intangible Assets are flagged as not Schedule-II-prescribed (governed by AS 26 instead)');

/* 8. Voucher-type classification for the voucher-level Tally sync -- decides
      whether a voucher touching a Fixed Asset ledger counts as a genuine
      addition/disposal or gets excluded as a depreciation/revaluation
      journal. */
fixedAssetTestAssert(classifyFixedAssetVoucherType('Purchase') === 'purchase', 'Voucher type "Purchase" classifies as purchase');
fixedAssetTestAssert(classifyFixedAssetVoucherType('Purchase (GST)') === 'purchase', 'Voucher type containing "Purchase" classifies as purchase regardless of suffix');
fixedAssetTestAssert(classifyFixedAssetVoucherType('Sales') === 'sale', 'Voucher type "Sales" classifies as sale');
fixedAssetTestAssert(classifyFixedAssetVoucherType('Depreciation Journal') === 'depreciation_journal', 'Voucher type "Depreciation Journal" is excluded as a depreciation journal, not a purchase/disposal');
fixedAssetTestAssert(classifyFixedAssetVoucherType('Payment') === 'other', 'Voucher type "Payment" (cash purchase without a bill) classifies as other -- still eligible as an addition via its Dr/Cr direction');
fixedAssetTestAssert(classifyFixedAssetVoucherType('Journal') === 'other', 'Voucher type "Journal" classifies as other, not auto-excluded');

/* 9. A voucher-synced disposal-only row (no opening balance or addition of
      its own on THIS row -- the asset being disposed was added in a prior
      year or via a different voucher row) must not derive a phantom
      depreciation charge from the negative closingGross this produces
      internally -- caught while designing the voucher sync: the un-guarded
      SLM formula turned a negative closingGross into a negative residual
      value, which then produced a spurious positive depreciable amount. */
$asset9 = ['opening_gross_block' => 0, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 0, 'disposals_during_year' => 50000, 'disposal_date' => '2024-09-30', 'is_disposed' => 1, 'depreciation_method' => 'SLM', 'residual_value_pct' => 5, 'useful_life_years' => 10];
$r9 = computeAssetDepreciation($asset9, $fyStart, $fyEnd);
fixedAssetTestAssert($r9['depreciation_for_year'] == 0.0, 'Disposal-only row (no opening/addition base) charges zero depreciation, not a phantom amount derived from a negative residual value');
fixedAssetTestAssert($r9['closing_gross_block'] == -50000.0, 'Disposal-only row still reports the true negative closing gross block, so category-level aggregation nets correctly against the category\'s other rows');

/* 10. An asset with an EXPLICIT useful_life_years of 0 (Land, confirmed
       against a real Schedule III Note 11 export) must charge zero
       depreciation forever -- not silently fall back to a Schedule II
       default life for whatever category it's tagged with. This is
       different from useful_life_years being genuinely unset (null),
       which SHOULD still fall back to the category default. */
$asset10 = ['opening_gross_block' => 13701064, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'depreciation_method' => 'WDV', 'residual_value_pct' => 5, 'useful_life_years' => 0, 'asset_category' => 'Other'];
$r10 = computeAssetDepreciation($asset10, $fyStart, $fyEnd);
fixedAssetTestAssert($r10['depreciation_for_year'] == 0.0, 'Explicit useful_life_years=0 (Land) charges zero depreciation, not a Schedule II default life for its category');
fixedAssetTestAssert($r10['closing_wdv'] == 13701064.0, 'Explicit useful_life_years=0 (Land) leaves the closing WDV exactly equal to opening gross block, unreduced');

$asset10b = ['opening_gross_block' => 100000, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'depreciation_method' => 'SLM', 'residual_value_pct' => 5, 'useful_life_years' => null, 'asset_category' => 'Plant & Machinery (general)'];
$r10b = computeAssetDepreciation($asset10b, $fyStart, $fyEnd);
fixedAssetTestAssert($r10b['depreciation_for_year'] > 0.0, 'useful_life_years genuinely unset (null) still falls back to the Schedule II default life for its category, unlike an explicit 0');

/* 11. Real Schedule III Note 11 Excel import -- category headers,
       subtotals, and CWIP/intangible-under-development entries must be
       excluded (none carry a numeric Useful Life), while a genuine
       Land row (useful life = "0", never a blank/non-numeric value) must
       be imported and preserved as an explicit 0. */
fixedAssetTestAssert(guessFixedAssetCategoryFromDescription('Civil Works- Hospital') === 'Buildings (RCC, other than factory)', 'Category guess: "Civil Works" description matches Buildings');
fixedAssetTestAssert(guessFixedAssetCategoryFromDescription('Dell Server Rack') === 'Computers and Data Processing Units (Servers)', 'Category guess: description containing "Server" matches Servers category');
fixedAssetTestAssert(guessFixedAssetCategoryFromDescription('Generator') === '', 'Category guess: no confident match falls back to blank, not a wrong guess');

/* 12. Individual-asset detail workpaper enrichment -- each named asset-type
       row from the audited Note 11 summary is expanded into one register
       row per physical unit found in the "Statement showing assets wise
       calculation" detail blocks, carrying a real purchase date, but the
       group's amounts are always rescaled to tie exactly back to that
       row's own audited totals (never the detail workpaper's own totals,
       which can drift from the audited summary -- confirmed against a
       real export where a live working file had since diverged slightly
       from the frozen, signed Note 11 figures). */
$topRows12 = [
    'generator' => [
        'asset_category' => 'Plant & Machinery (general)',
        'asset_description' => 'Generator',
        'opening_gross_block' => 100000.0,
        'opening_accumulated_depreciation' => 40000.0,
        'useful_life_years' => 15.0,
        'depreciation_method' => 'WDV',
    ],
    'land' => [
        'asset_category' => '',
        'asset_description' => 'Land',
        'opening_gross_block' => 500000.0,
        'opening_accumulated_depreciation' => 0.0,
        'useful_life_years' => 0.0,
        'depreciation_method' => 'WDV',
    ],
];
$detailBlocks12 = [
    'generator' => [
        'group' => 'Plant and Machinery',
        'units' => [
            ['cost' => 60000.0, 'wdv' => 30000.0, 'purchase_date' => '2022-01-09'],
            ['cost' => 40000.0, 'wdv' => 20000.0, 'purchase_date' => '2023-04-25'],
        ],
    ],
    'unlisted new asset' => [
        'group' => 'Office equipment',
        'units' => [['cost' => 15000.0, 'wdv' => 15000.0, 'purchase_date' => '2024-06-01']],
    ],
];
$errors12 = [];
$expanded12 = expandFixedAssetRowsWithDetail($topRows12, $detailBlocks12, $errors12);
fixedAssetTestAssert(count($expanded12) === 3, 'Detail expansion: 2 Generator units + 1 passthrough Land row = 3 register rows');
$generatorRows = array_values(array_filter($expanded12, static fn ($r) => str_starts_with($r['asset_description'], 'Generator')));
fixedAssetTestAssert(count($generatorRows) === 2, 'Detail expansion: Generator expands into one row per physical unit');
$genGrossSum = array_sum(array_column($generatorRows, 'opening_gross_block'));
$genAccumDepSum = array_sum(array_column($generatorRows, 'opening_accumulated_depreciation'));
fixedAssetTestAssert(approx($genGrossSum, 100000.0, 0.05), 'Detail expansion: Generator unit rows sum back to the audited opening gross block exactly');
fixedAssetTestAssert(approx($genAccumDepSum, 40000.0, 0.05), 'Detail expansion: Generator unit rows sum back to the audited opening accumulated depreciation exactly');
fixedAssetTestAssert(in_array('2022-01-09', array_column($generatorRows, 'addition_date'), true), 'Detail expansion: real purchase date from the detail block is preserved as addition_date');
$landRow = $expanded12[array_search('Land', array_column($expanded12, 'asset_description'), true)] ?? null;
fixedAssetTestAssert($landRow !== null && $landRow['opening_gross_block'] == 500000.0 && ($landRow['addition_date'] ?? null) === null, 'Detail expansion: Land has no matching detail block, so it passes through unchanged with no addition_date');
$orphanError = current(array_filter($errors12, static fn ($e) => str_contains($e, '15,000.00')));
fixedAssetTestAssert($orphanError !== false, 'Detail expansion: a detail-only asset with no audited summary row is excluded and surfaced as a warning, not silently imported');
fixedAssetTestAssert(!in_array('unlisted new asset', array_column($expanded12, 'asset_description'), true), 'Detail expansion: the excluded detail-only asset never makes it into the returned register rows');
$unmatchedError = current(array_filter($errors12, static fn ($e) => str_contains($e, 'could not be linked to a purchase date')));
fixedAssetTestAssert($unmatchedError !== false && str_contains($unmatchedError, 'Land'), 'Detail expansion: a summary row with no detail match at all (not just a name mismatch) is separately noted as missing a purchase date');

/* 13. A whitespace-only difference between an asset's name in the summary
       and in the detail workpaper (confirmed against a real export: one
       asset's name was missing a single space in the detail sheet) is
       safely auto-resolved -- unlike a genuine wording difference, which
       must not be guessed at since it could be two distinct assets. */
$topRows13 = ['tourniquet auto electronic-diamond.t rdg511' => [
    'asset_category' => 'Plant & Machinery (general)',
    'asset_description' => 'Tourniquet Auto Electronic-Diamond.T RDG511',
    'opening_gross_block' => 32960.0,
    'opening_accumulated_depreciation' => 10000.0,
    'useful_life_years' => 13.0,
    'depreciation_method' => 'WDV',
]];
$detailBlocks13 = ['tourniquet auto electronic-diamond.trdg511' => [
    'group' => 'Plant and Machinery',
    'units' => [['cost' => 32960.0, 'wdv' => 22960.0, 'purchase_date' => '2019-07-15']],
]];
$errors13 = [];
$expanded13 = expandFixedAssetRowsWithDetail($topRows13, $detailBlocks13, $errors13);
fixedAssetTestAssert(count($expanded13) === 1 && ($expanded13[0]['addition_date'] ?? null) === '2019-07-15', 'Whitespace-only name mismatch between the summary and detail sheets is auto-resolved, purchase date attached');
fixedAssetTestAssert($errors13 === [], 'Whitespace-only name mismatch produces no spurious orphan or unmatched-name warning once resolved');

echo "================================================\n";
echo "$passed passed, $failures failed\n";

exit($failures > 0 ? 1 : 0);
