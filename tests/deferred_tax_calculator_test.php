<?php
/**
 * Standalone, framework-free assertion script (this repo has no PHPUnit/tests
 * infra — see composer.json). Run with: php tests/deferred_tax_calculator_test.php
 *
 * Protects the core AS-22 timing-difference math in
 * app/helpers/deferred_tax_helper.php: the Income-tax Act WDV computation
 * (which differs from Schedule II in three material ways -- WDV-only,
 * the <180-days half-rate rule, no residual floor) and the book-vs-tax
 * DTA/DTL classification logic.
 */

require_once __DIR__ . '/../app/helpers/deferred_tax_helper.php';

$failures = 0;
$passed = 0;

function deferredTaxTestAssert(bool $condition, string $description): void
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

function dtApprox(float $a, float $b, float $tolerance = 0.5): bool
{
    return abs($a - $b) <= $tolerance;
}

echo "Deferred Tax Calculator — AS-22 timing-difference math test\n";
echo "================================================\n";

$fyStart = '2024-04-01';
$fyEnd = '2025-03-31';

/* 1. Income-tax WDV, full-year opening block, no additions. */
$a1 = ['opening_gross_block' => 150000, 'opening_accumulated_depreciation' => 50000, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'asset_category' => 'Plant & Machinery (general)'];
$r1 = computeAssetTaxDepreciation($a1, $fyStart, $fyEnd);
deferredTaxTestAssert(dtApprox($r1['depreciation_for_year'], 15000), 'Income-tax WDV: 15% of opening WDV 100000 = 15000');
deferredTaxTestAssert(dtApprox($r1['closing_wdv'], 85000), 'Income-tax WDV: closing WDV = 100000 - 15000 = 85000');

/* 2. The <180-days rule: an addition held >=180 days gets the FULL rate. */
$a2 = ['opening_gross_block' => 0, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 100000, 'addition_date' => '2024-06-01', 'disposals_during_year' => 0, 'is_disposed' => 0, 'asset_category' => 'Plant & Machinery (general)'];
$r2 = computeAssetTaxDepreciation($a2, $fyStart, $fyEnd);
deferredTaxTestAssert(dtApprox($r2['depreciation_for_year'], 15000), 'Addition held >=180 days (Jun 1 - Mar 31) gets the full 15% rate, not half');

/* 3. The <180-days rule: an addition held <180 days gets HALF the rate --
      not day-proportional like Schedule II, a binary Income-tax rule. */
$a3 = ['opening_gross_block' => 0, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 100000, 'addition_date' => '2025-01-15', 'disposals_during_year' => 0, 'is_disposed' => 0, 'asset_category' => 'Plant & Machinery (general)'];
$r3 = computeAssetTaxDepreciation($a3, $fyStart, $fyEnd);
deferredTaxTestAssert(dtApprox($r3['depreciation_for_year'], 7500), 'Addition held <180 days (Jan 15 - Mar 31) gets HALF the 15% rate = 7500');

/* 4. Computers get the 40% Income-tax block rate (vs a much shorter
      Schedule II book life driving a different book WDV entirely). */
$a4 = ['opening_gross_block' => 200000, 'opening_accumulated_depreciation' => 0, 'additions_during_year' => 0, 'disposals_during_year' => 0, 'is_disposed' => 0, 'asset_category' => 'Computers and Data Processing Units (Servers)'];
$r4 = computeAssetTaxDepreciation($a4, $fyStart, $fyEnd);
deferredTaxTestAssert(dtApprox($r4['depreciation_for_year'], 80000), 'Computers: 40% Income-tax block rate on 200000 = 80000');

/* 5. Whole block disposed -- no depreciation, WDV goes to 0. */
$a5 = ['opening_gross_block' => 100000, 'opening_accumulated_depreciation' => 20000, 'additions_during_year' => 0, 'disposals_during_year' => 100000, 'is_disposed' => 1, 'asset_category' => 'Plant & Machinery (general)'];
$r5 = computeAssetTaxDepreciation($a5, $fyStart, $fyEnd);
deferredTaxTestAssert($r5['closing_wdv'] == 0.0, 'Fully disposed block: closing WDV is 0, not negative');
deferredTaxTestAssert($r5['depreciation_for_year'] == 0.0, 'Fully disposed block: no depreciation charged in the year of full disposal');

/* 6. Tax WDV < Book WDV => Deferred Tax LIABILITY (tax has already used up
      more depreciation than books -- future years reverse the other way). */
$bookSchedule6 = ['by_category' => [['category' => 'Computers and Data Processing Units (Servers)', 'closing_wdv' => 150000]]];
$taxSchedule6 = ['by_category' => [['category' => 'Computers and Data Processing Units (Servers)', 'closing_wdv' => 60000]]];
$r6 = computeDeferredTaxOnDepreciation($bookSchedule6, $taxSchedule6, 25.17);
deferredTaxTestAssert($r6['rows'][0]['classification'] === 'DTL', 'Tax WDV < Book WDV produces a Deferred Tax LIABILITY');
deferredTaxTestAssert(dtApprox($r6['rows'][0]['amount'], 90000 * 0.2517, 1), 'DTL amount = (Book WDV - Tax WDV) x tax rate');
deferredTaxTestAssert($r6['net_amount'] < 0, 'Net amount is negative for a net DTL position');

/* 7. Tax WDV > Book WDV => Deferred Tax ASSET (books have depreciated more
      cumulatively than tax has allowed -- a prepaid-tax-like position). */
$bookSchedule7 = ['by_category' => [['category' => 'Plant & Machinery (general)', 'closing_wdv' => 50000]]];
$taxSchedule7 = ['by_category' => [['category' => 'Plant & Machinery (general)', 'closing_wdv' => 80000]]];
$r7 = computeDeferredTaxOnDepreciation($bookSchedule7, $taxSchedule7, 25.17);
deferredTaxTestAssert($r7['rows'][0]['classification'] === 'DTA', 'Tax WDV > Book WDV produces a Deferred Tax ASSET');
deferredTaxTestAssert($r7['net_amount'] > 0, 'Net amount is positive for a net DTA position');

echo "================================================\n";
echo "$passed passed, $failures failed\n";

exit($failures > 0 ? 1 : 0);
