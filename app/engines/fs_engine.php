<?php

require_once __DIR__ . '/classification_engine.php';
require_once __DIR__ . '/../helpers/company_reporting_helper.php';
require_once __DIR__ . '/../helpers/schedule3_master_helper.php';

function getEntityCategory(PDO $pdo, int $company_id): string
{
    $stmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
    $stmt->execute([$company_id]);
    $entity = strtolower((string) $stmt->fetchColumn());
    $entity = str_replace(['-', ' '], '_', $entity);

    return in_array($entity, ['corporate', 'llp', 'non_corporate'], true) ? $entity : 'non_corporate';
}

function getEntitySubcategory(PDO $pdo, int $company_id): string
{
    $meta = getCompanyReportingMeta($pdo, $company_id);
    return $meta['entity_subcategory'] ?? 'proprietorship';
}

function isTradingEntity(string $subcategory): bool
{
    return in_array($subcategory, ['proprietorship', 'partnership', 'llp'], true);
}

function isReceiptsPaymentsEntity(string $subcategory): bool
{
    return in_array($subcategory, ['trust', 'society'], true);
}

function classifiedAmount(array $classified, string $code): float
{
    return (float) ($classified['schedule_items'][$code]['amount'] ?? 0);
}

function classifiedPreviousAmount(array $classified, string $code): float
{
    return (float) ($classified['schedule_items'][$code]['previous_amount'] ?? 0);
}

function classifiedRows(array $classified, string $code): array
{
    return $classified['schedule_items'][$code]['rows'] ?? [];
}

function stringifyLedgerRows(array $rows): string
{
    if ($rows === []) {
        return '';
    }

    $parts = [];
    foreach ($rows as $row) {
        $parts[] = trim((string) ($row['ledger_name'] ?? ''));
    }

    return implode(', ', array_filter($parts));
}

function buildLedgerLines(array $classified, array $codes): array
{
    $lines = [];

    foreach ($codes as $code) {
        foreach (($classified['schedule_items'][$code]['rows'] ?? []) as $row) {
            $name = trim((string) ($row['ledger_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (!isset($lines[$name])) {
                $lines[$name] = [
                    'label' => $name,
                    'current' => 0.0,
                    'previous' => 0.0,
                ];
            }

            $lines[$name]['current'] += (float) ($row['amount'] ?? 0);
            $lines[$name]['previous'] += (float) ($row['previous_amount'] ?? 0);
        }
    }

    uasort($lines, static fn ($a, $b) => strcmp($a['label'], $b['label']));
    return array_values($lines);
}

function sumLines(array $lines, string $key): float
{
    $total = 0.0;
    foreach ($lines as $line) {
        $total += (float) ($line[$key] ?? 0);
    }
    return $total;
}

/**
 * True for the small set of ledger names Tally uses for the brought-forward
 * P&L balance itself (as opposed to other reserve components -- General
 * Reserve, Capital Reserve, Securities Premium, etc. -- that also classify
 * under the same 'reserves' schedule code but are NOT part of the P&L
 * roll-forward). Same canonical name list the ReconHub risk detector uses
 * (app/services/reconhub_data_loading_service.php) to flag a P&L ledger
 * that isn't mapped to 'reserves'.
 */
function isProfitLossLedgerName(string $name): bool
{
    static $variants = ['profit & loss a/c', 'profit and loss a/c', 'profit & loss account', 'profit and loss account', 'p&l a/c', 'p and l a/c', 'surplus in statement of profit and loss'];
    return in_array(strtolower(trim($name)), $variants, true);
}

/**
 * Surfaces the current year's P&L-account-style ledger from the Trial
 * Balance, if any, so the UI can ask the CA to explicitly confirm it as
 * Note 2's opening balance rather than silently guessing. This is the
 * same figure buildOtherEquitySection() would otherwise fall back to
 * automatically -- making it an explicit, saved confirmation instead
 * removes the ambiguity around whether a given Trial Balance figure is
 * this ledger's opening or closing position.
 *
 * @return array{ledger_name: string, amount: float}|null
 */
function detectProfitLossLedgerOpeningCandidate(array $classified): ?array
{
    $rows = $classified['schedule_items']['reserves']['rows'] ?? [];
    $matches = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['ledger_name'] ?? ''));
        if ($name === '' || !isProfitLossLedgerName($name)) {
            continue;
        }
        $matches[$name] = ($matches[$name] ?? 0.0) + (float) ($row['amount'] ?? 0);
    }

    if ($matches === []) {
        return null;
    }

    arsort($matches);
    $name = (string) array_key_first($matches);
    $amount = round((float) $matches[$name], 2);

    if ($amount == 0.0) {
        return null;
    }

    return ['ledger_name' => $name, 'amount' => $amount];
}

function buildDetailedNote(string $title, array $lines, string $emptyLabel = 'No ledger breakup available'): array
{
    if ($lines === []) {
        $lines = [[
            'label' => $emptyLabel,
            'current' => 0.0,
            'previous' => 0.0,
        ]];
    }

    return [
        'title' => $title,
        'lines' => $lines,
        'current_total' => sumLines($lines, 'current'),
        'previous_total' => sumLines($lines, 'previous'),
    ];
}

/**
 * Contingent Liabilities, Commitments, MSME Disclosure and Related Party
 * Transactions are narrative disclosures, not ledger-derived figures -- there
 * is nothing to classify a Trial Balance ledger into for any of them. Each
 * starts from a standard, editable boilerplate position (the common "nothing
 * to disclose" case) that the CA overrides via a manual-input textarea when
 * the company actually has one of these to report.
 */
function manualDisclosureDefaultText(string $masterCode): string
{
    switch ($masterCode) {
        case 'CL':
            return 'The Company does not have any contingent liabilities as at the Balance Sheet date, other than those disclosed above, if any.';
        case 'COM':
            return 'The Company does not have any capital or other commitments outstanding as at the Balance Sheet date, other than those disclosed above, if any.';
        case 'MSME':
            return 'Based on the information available with the Company regarding the status of suppliers as defined under the Micro, Small and Medium Enterprises Development Act, 2006 ("MSMED Act"), there are no amounts overdue to Micro and Small Enterprises as at the Balance Sheet date, and no interest has been paid or is payable under the MSMED Act during the year.';
        case 'RPT':
            return 'There were no related party transactions during the year that require disclosure under Accounting Standard (AS) 18, other than managerial remuneration (if any) disclosed elsewhere in these financial statements.';
        /* Segment Reporting (AS 17) and Financial Instruments disclosures are
           applicability-gated by size/listing status in a way this app has
           no threshold data to determine (unlike CL/COM/MSME/RPT above,
           which are genuinely "no ledger data exists" cases for any
           company). Render an explicit, editable "not applicable" position
           for the small-private-company segment this app targets, rather
           than a silent empty stub -- the CA can override if this specific
           company is large enough to actually require these disclosures. */
        case 'SEG':
            return 'Segment Reporting under Accounting Standard (AS) 17 is not applicable to the Company for the year, being a single-segment entity / not meeting the applicability thresholds prescribed thereunder. Confirm this remains correct if the Company\'s operations have diversified during the year.';
        case 'FI':
            return 'Detailed Financial Instruments disclosures are not applicable to the Company for the year, being a small private company below the applicability thresholds that ordinarily trigger such disclosure. Confirm this remains correct if the Company has since become a listed entity or otherwise falls within scope.';
        default:
            return '';
    }
}

function manualDisclosureInputKey(string $masterCode): string
{
    return 'note_disclosure_' . strtolower($masterCode);
}

function buildManualDisclosureSection(string $masterCode, string $title, int $noteNo, array $manualInputs): array
{
    $key = manualDisclosureInputKey($masterCode);
    $text = trim((string) ($manualInputs[$key] ?? ''));
    if ($text === '') {
        $text = manualDisclosureDefaultText($masterCode);
    }

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'master_code' => $masterCode,
        'custom_type' => 'manual_disclosure',
        'lines' => [],
        'current_total' => 0.0,
        'previous_total' => 0.0,
        'disclosure_text' => $text,
    ];
}

/**
 * Basic EPS (AS-20) = Profit After Tax attributable to equity shareholders
 * divided by the weighted average number of equity shares outstanding
 * during the year. Share issues/buybacks don't carry issue dates anywhere
 * in this app (Note 1's per-share-type rows only track year-total
 * movement), so the weighted average is approximated as the simple average
 * of opening and closing equity share counts -- exact for a company with
 * no share movement during the year, and disclosed as an approximation
 * otherwise rather than silently presented as precise.
 */
function buildEpsSection(float $pat, float $previousPat, array $shareCapitalClasses, array $previousShareCapitalClasses, int $noteNo, string $title): array
{
    $sumEquityShares = static function (array $classes, string $openingKey, string $closingKey): array {
        $opening = 0.0;
        $closing = 0.0;
        foreach ($classes as $row) {
            if (stripos((string) ($row['share_type'] ?? ''), 'equity') === false) {
                continue;
            }
            $opening += (float) ($row[$openingKey] ?? 0);
            $closing += (float) ($row[$closingKey] ?? 0);
        }
        return [$opening, $closing];
    };

    [$openingShares, $closingShares] = $sumEquityShares($shareCapitalClasses, 'opening_shares', 'closing_shares');
    $weightedAvgShares = $openingShares > 0.0 || $closingShares > 0.0 ? ($openingShares + $closingShares) / 2 : $closingShares;

    [$prevOpeningShares, $prevClosingShares] = $sumEquityShares($previousShareCapitalClasses, 'opening_shares', 'closing_shares');
    $prevWeightedAvgShares = $prevOpeningShares > 0.0 || $prevClosingShares > 0.0 ? ($prevOpeningShares + $prevClosingShares) / 2 : $prevClosingShares;

    $eps = $weightedAvgShares > 0.0 ? round($pat / $weightedAvgShares, 2) : 0.0;
    $prevEps = $prevWeightedAvgShares > 0.0 ? round($previousPat / $prevWeightedAvgShares, 2) : 0.0;

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'master_code' => 'EPS',
        'custom_type' => 'eps',
        'lines' => [
            ['label' => 'Profit After Tax attributable to Equity Shareholders (₹)', 'current' => $pat, 'previous' => $previousPat],
            ['label' => 'Weighted Average Number of Equity Shares Outstanding', 'current' => $weightedAvgShares, 'previous' => $prevWeightedAvgShares],
            ['label' => 'Basic & Diluted Earnings Per Share (₹)', 'current' => $eps, 'previous' => $prevEps],
        ],
        'current_total' => $eps,
        'previous_total' => $prevEps,
        'is_approximation' => true,
    ];
}

function assignSequentialNoteMetadata(array $sections, array $fallbackTitles = []): array
{
    foreach ($sections as $index => &$section) {
        $noteNo = (int) ($section['note_no'] ?? 0);
        if ($noteNo <= 0) {
            $section['note_no'] = $index + 1;
        }

        $title = trim((string) ($section['title'] ?? ''));
        if ($title === '') {
            $section['title'] = $fallbackTitles[$index] ?? ('Note ' . ($index + 1));
        }
    }
    unset($section);

    return $sections;
}

function buildCompanyProfitAfterTax(array $classified, array $manualInputs = [], array $previousManualInputs = [], bool $usePrevious = false, ?float $depreciationOverride = null): float
{
    $inventoryChangeSection = buildInventoryChangeSection($manualInputs, $previousManualInputs, 24, 'Changes in Inventories');
    $inventoryChange = (float) ($usePrevious ? ($inventoryChangeSection['previous_total'] ?? 0) : ($inventoryChangeSection['current_total'] ?? 0));
    $inventorySection = buildInventoriesSectionFromInventoryChange($inventoryChangeSection, 16, 'Inventories');
    $materialsConsumedSection = buildCostOfMaterialsConsumedSection($classified, $inventorySection, $inventoryChangeSection, 22, 'Cost of Materials Consumed');
    $materialsConsumed = (float) ($usePrevious ? ($materialsConsumedSection['previous_total'] ?? 0) : ($materialsConsumedSection['current_total'] ?? 0));
    $valueKey = $usePrevious ? 'previous_amount' : 'amount';

    $sumCodes = static function (array $codes) use ($classified, $valueKey): float {
        $total = 0.0;
        foreach ($codes as $code) {
            foreach (($classified['schedule_items'][$code]['rows'] ?? []) as $row) {
                $total += (float) ($row[$valueKey] ?? 0);
            }
        }
        return $total;
    };

    $revenue = $sumCodes(['revenue']);
    $otherIncome = $sumCodes(['other_income']);
    /* Depreciation defaults to the flat TB ledger balance, but when the
       Asset Register has a computed Schedule II figure for the current
       year (see the $depreciationOverride caller in buildOtherEquitySection()
       below), that figure must be used here too -- otherwise the P&L's
       depreciation line and the Reserves & Surplus roll-forward (which is
       built from THIS function's return value) would silently disagree,
       exactly the class of bug already found and fixed once this session
       for tax_provision. Only applies to the current year: the register
       may not have comparative-year data, so previous-year depreciation
       always falls back to the TB figure. */
    $depreciation = (!$usePrevious && $depreciationOverride !== null) ? $depreciationOverride : $sumCodes(['depreciation']);
    $expenses = $sumCodes([
        'purchase_stock',
        'employee_cost',
        'finance_cost',
        'other_expenses',
    ]) + $depreciation;

    $pbt = ($revenue + $otherIncome) - ($materialsConsumed + $expenses + $inventoryChange);
    $tax = manualAmount($usePrevious ? $previousManualInputs : $manualInputs, 'tax_provision', 0);

    return $pbt - $tax;
}

function buildInventoryChangeSection(array $manualInputs, array $previousManualInputs, int $noteNo, string $title): array
{
    $currentOpening = [
        'finished_goods' => manualAmount($manualInputs, 'note24_opening_finished_goods', manualAmount($previousManualInputs, 'note24_closing_finished_goods', 0)),
        'work_in_progress' => manualAmount($manualInputs, 'note24_opening_work_in_progress', manualAmount($previousManualInputs, 'note24_closing_work_in_progress', 0)),
        'stock_in_trade' => manualAmount($manualInputs, 'note24_opening_stock_in_trade', manualAmount($previousManualInputs, 'note24_closing_stock_in_trade', 0)),
    ];
    $currentClosing = [
        'finished_goods' => manualAmount($manualInputs, 'note24_closing_finished_goods', 0),
        'work_in_progress' => manualAmount($manualInputs, 'note24_closing_work_in_progress', 0),
        'stock_in_trade' => manualAmount($manualInputs, 'note24_closing_stock_in_trade', 0),
    ];
    $previousOpening = [
        'finished_goods' => manualAmount($previousManualInputs, 'note24_opening_finished_goods', 0),
        'work_in_progress' => manualAmount($previousManualInputs, 'note24_opening_work_in_progress', 0),
        'stock_in_trade' => manualAmount($previousManualInputs, 'note24_opening_stock_in_trade', 0),
    ];
    $previousClosing = [
        'finished_goods' => manualAmount($previousManualInputs, 'note24_closing_finished_goods', 0),
        'work_in_progress' => manualAmount($previousManualInputs, 'note24_closing_work_in_progress', 0),
        'stock_in_trade' => manualAmount($previousManualInputs, 'note24_closing_stock_in_trade', 0),
    ];

    $openingTotal = array_sum($currentOpening);
    $closingTotal = array_sum($currentClosing);
    $previousOpeningTotal = array_sum($previousOpening);
    $previousClosingTotal = array_sum($previousClosing);

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'custom_type' => 'inventory_change',
        'opening' => $currentOpening,
        'closing' => $currentClosing,
        'previous_opening' => $previousOpening,
        'previous_closing' => $previousClosing,
        'current_total' => $openingTotal - $closingTotal,
        'previous_total' => $previousOpeningTotal - $previousClosingTotal,
        'lines' => [],
    ];
}

function buildOtherEquitySection(array $classified, array $manualInputs, array $previousManualInputs, int $noteNo, string $title, ?float $depreciationOverride = null): array
{
    /* The 'reserves' schedule code lumps together every reserve component --
       General Reserve, Capital Reserve, Securities Premium, AND the P&L
       Account ledger itself. Note 2's "Opening balance in P&L Account" must
       come from just the P&L-account ledger(s), not the whole group total,
       or it silently absorbs unrelated reserves whenever a company has more
       than one reserve ledger. */
    $reserveLines = buildLedgerLines($classified, ['reserves']);
    $plLines = array_values(array_filter($reserveLines, static fn (array $line): bool => isProfitLossLedgerName((string) $line['label'])));
    $otherReserveLines = array_values(array_filter($reserveLines, static fn (array $line): bool => !isProfitLossLedgerName((string) $line['label'])));

    /* Tally's own current balance for the P&L ledger is used as the
       OPENING balance when no previous-FY link exists in e-BAL yet (a
       company's first year imported into the app). This matches normal
       Tally practice: many companies only pass the year-end P&L-to-reserves
       transfer journal during audit finalisation, well after the FY ends --
       so the trial balance exported mid-year (or even at year-end, before
       that closing entry is posted) still shows LAST year's closing figure
       on this ledger, i.e. it is still functioning as this year's opening.
       Confirmed empirically: treating it as a closing figure and
       back-deriving the opening (closing - this year's computed movement)
       broke the Balance Sheet reconciliation for real client data; treating
       it as the opening and adding this year's movement on top balances
       correctly, because the asset side already reflects the cash/other
       effects of this year's profit while the ledger itself does not yet. */
    $plOpeningCurrent = sumLines($plLines, 'previous');
    if ($plOpeningCurrent == 0.0) {
        $plOpeningCurrent = sumLines($plLines, 'current');
    }

    $currentMovement = buildCompanyProfitAfterTax($classified, $manualInputs, $previousManualInputs, false, $depreciationOverride);
    $previousMovement = buildCompanyProfitAfterTax($classified, $manualInputs, $previousManualInputs, true);

    $previousOpening = manualAmount($previousManualInputs, 'note2_opening_profit_loss', 0);
    $previousClosing = manualAmount($previousManualInputs, 'note2_closing_profit_loss', $previousOpening + $previousMovement);

    $currentOpening = manualAmount($manualInputs, 'note2_opening_profit_loss', $plOpeningCurrent !== 0.0 ? $plOpeningCurrent : $previousClosing);
    $currentOpeningManual = trim((string) ($manualInputs['note2_opening_profit_loss'] ?? ''));
    $currentClosing = $currentOpening + $currentMovement;

    $otherReserveCurrent = sumLines($otherReserveLines, 'current');
    $otherReservePrevious = sumLines($otherReserveLines, 'previous');
    $lines = $otherReserveLines;
    $lines[] = [
        'label' => 'Opening balance in Profit and Loss Account',
        'current' => $currentOpening,
        'previous' => $previousOpening,
    ];
    $lines[] = [
        'label' => 'Profit / (Loss) for the year',
        'current' => $currentMovement,
        'previous' => $previousMovement,
    ];
    $lines[] = [
        'label' => 'Closing balance in Profit and Loss Account',
        'current' => $currentClosing,
        'previous' => $previousClosing,
    ];

    /* Only fold the carried-forward opening balance into the Balance Sheet
       total when this FY actually has some reserves activity of its own
       (current-year P&L ledger data, other reserves, computed movement, or
       an explicit manual entry). A brand-new FY with no trial balance
       imported yet has $currentOpening carried forward from last year but
       nothing on the asset side to match it -- folding it in there would
       manufacture a Balance Sheet mismatch out of an FY nobody has started
       populating yet. */
    $hasCurrentYearReserveActivity = sumLines($plLines, 'current') != 0.0
        || $otherReserveCurrent != 0.0
        || $currentMovement != 0.0
        || $currentOpeningManual !== '';
    $currentTotal = $hasCurrentYearReserveActivity ? ($otherReserveCurrent + $currentClosing) : $otherReserveCurrent;

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'custom_type' => 'other_equity',
        'opening_balance' => $currentOpening,
        'previous_opening_balance' => $previousOpening,
        'movement' => $currentMovement,
        'previous_movement' => $previousMovement,
        'lines' => $lines,
        'current_total' => $currentTotal,
        'previous_total' => $otherReservePrevious + $previousClosing,
    ];
}

function buildInventoriesSectionFromInventoryChange(array $inventoryChangeSection, int $noteNo, string $title): array
{
    $currentOpeningRawMaterials = manualAmount(
        $inventoryChangeSection['manual_inputs'] ?? [],
        'note16_opening_raw_materials',
        manualAmount($inventoryChangeSection['previous_manual_inputs'] ?? [], 'note16_closing_raw_materials', 0)
    );
    $currentClosingRawMaterials = manualAmount(
        $inventoryChangeSection['manual_inputs'] ?? [],
        'note16_closing_raw_materials',
        0
    );
    $previousOpeningRawMaterials = manualAmount(
        $inventoryChangeSection['previous_manual_inputs'] ?? [],
        'note16_opening_raw_materials',
        0
    );
    $previousClosingRawMaterials = manualAmount(
        $inventoryChangeSection['previous_manual_inputs'] ?? [],
        'note16_closing_raw_materials',
        0
    );

    $currentOpening = $inventoryChangeSection['opening'] ?? [
        'finished_goods' => 0.0,
        'work_in_progress' => 0.0,
        'stock_in_trade' => 0.0,
    ];
    $currentClosing = $inventoryChangeSection['closing'] ?? [
        'finished_goods' => 0.0,
        'work_in_progress' => 0.0,
        'stock_in_trade' => 0.0,
    ];

    $lines = [
        [
            'label' => 'Stock - Raw Materials',
            'current' => $currentClosingRawMaterials,
            'previous' => $currentOpeningRawMaterials,
        ],
        [
            'label' => 'Stock - Work In Progress',
            'current' => $currentClosing['work_in_progress'],
            'previous' => $currentOpening['work_in_progress'],
        ],
        [
            'label' => 'Stock - Finished Goods',
            'current' => $currentClosing['finished_goods'],
            'previous' => $currentOpening['finished_goods'],
        ],
        [
            'label' => 'Stock - Stock in Trade',
            'current' => $currentClosing['stock_in_trade'],
            'previous' => $currentOpening['stock_in_trade'],
        ],
    ];

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'lines' => $lines,
        'current_total' => $currentClosingRawMaterials + array_sum($currentClosing),
        'previous_total' => $currentOpeningRawMaterials + array_sum($currentOpening),
        'raw_material_opening_current' => $currentOpeningRawMaterials,
        'raw_material_closing_current' => $currentClosingRawMaterials,
        'raw_material_opening_previous' => $previousOpeningRawMaterials,
        'raw_material_closing_previous' => $previousClosingRawMaterials,
    ];
}

function buildCostOfMaterialsConsumedSection(array $classified, array $inventorySection, array $inventoryChangeSection, int $noteNo, string $title): array
{
    $purchaseLines = buildLedgerLines($classified, ['materials']);
    $purchaseCurrent = sumLines($purchaseLines, 'current');
    $purchasePrevious = sumLines($purchaseLines, 'previous');

    $currentOpening = (float) ($inventorySection['raw_material_opening_current'] ?? 0);
    $currentClosing = (float) ($inventorySection['raw_material_closing_current'] ?? 0);
    $previousOpening = (float) ($inventorySection['raw_material_opening_previous'] ?? 0);
    $previousClosing = (float) ($inventorySection['raw_material_closing_previous'] ?? 0);

    $currentTotal = $currentOpening + $purchaseCurrent - $currentClosing;
    $previousTotal = $previousOpening + $purchasePrevious - $previousClosing;

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'custom_type' => 'materials_consumed',
        'lines' => [
            [
                'label' => 'Opening Stock - Raw Materials',
                'current' => $currentOpening,
                'previous' => $previousOpening,
            ],
            [
                'label' => 'Add: Purchases',
                'current' => $purchaseCurrent,
                'previous' => $purchasePrevious,
            ],
            [
                'label' => 'Less: Closing Stock - Raw Materials',
                'current' => $currentClosing,
                'previous' => $previousClosing,
            ],
            [
                'label' => 'Cost of Materials Consumed',
                'current' => $currentTotal,
                'previous' => $previousTotal,
            ],
        ],
        'current_total' => $currentTotal,
        'previous_total' => $previousTotal,
    ];
}

function buildDeferredTaxSection(array $classified, int $noteNo, string $title): array
{
    $lines = [];
    $currentTotal = 0.0;
    $previousTotal = 0.0;
    $codeBuckets = [
        'deferred_tax_liability' => 1,
        'deferred_tax_asset' => -1,
    ];

    foreach ($codeBuckets as $code => $multiplier) {
        foreach (($classified['schedule_items'][$code]['rows'] ?? []) as $row) {
            $name = trim((string) ($row['ledger_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (!isset($lines[$name])) {
                $lines[$name] = [
                    'label' => $name,
                    'current' => 0.0,
                    'previous' => 0.0,
                ];
            }

            $current = (float) ($row['amount'] ?? 0) * $multiplier;
            $previous = (float) ($row['previous_amount'] ?? 0) * $multiplier;
            $lines[$name]['current'] += $current;
            $lines[$name]['previous'] += $previous;
            $currentTotal += $current;
            $previousTotal += $previous;
        }
    }

    if ($lines === []) {
        $lines[] = [
            'label' => 'No deferred tax balances',
            'current' => 0.0,
            'previous' => 0.0,
        ];
    } else {
        uasort($lines, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        $lines = array_values($lines);
    }

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'custom_type' => 'deferred_tax',
        'lines' => $lines,
        'current_total' => $currentTotal,
        'previous_total' => $previousTotal,
        /* AS-22 requires deferred tax to be recognised on actual timing
           differences between accounting and taxable income -- this figure
           is a pass-through net of whatever DTA/DTL ledger balances already
           exist in the Trial Balance, not an independent recomputation by
           this app. Correct only insofar as the CA's own Tally entries were
           themselves computed correctly outside the system; disclosed here
           rather than presented as independently verified. */
        'disclosure' => $lines !== [] && ($lines[0]['label'] ?? '') !== 'No deferred tax balances'
            ? 'Deferred tax figures above are carried forward as recorded in the Trial Balance and are not independently recomputed by this system under AS 22 (timing-difference method). The preparer should confirm these balances were correctly computed outside the system before finalisation.'
            : '',
    ];
}

function buildNoteIndex(array $sections): array
{
    $index = [];
    $aliases = [
        'fixed assets' => ['fixed asset'],
        'cash and bank' => ['cash & bank', 'cash and bank balances'],
        'revenue from operations' => ['revenue'],
        'depreciation & amortisation' => ['depreciation and amortisation'],
        'reserves & surplus' => ['reserves and surplus'],
        'property, plant & equipment' => ['property plant equipment', 'fixed assets'],
        'trade payables' => ['payables'],
        'trade receivables' => ['receivables'],
        'inventories' => ['inventory'],
    ];

    foreach ($sections as $section) {
        $title = (string) ($section['title'] ?? '');
        $noteNo = (int) ($section['note_no'] ?? 0);
        if ($title !== '') {
            $resolvedNoteNo = $noteNo > 0 ? $noteNo : (count($index) + 1);
            $index[$title] = $resolvedNoteNo;

            $normalized = strtolower(trim($title));
            foreach ($aliases[$normalized] ?? [] as $alias) {
                $index[$alias] = $resolvedNoteNo;
            }
        }
    }

    return $index;
}

function manualAmount(array $manualInputs, string $key, float $fallback = 0.0): float
{
    $value = $manualInputs[$key] ?? '';
    if ($value === '' || $value === null) {
        return $fallback;
    }

    return (float) $value;
}

function buildCompanyNotesPayload(array $classified, array $manualInputs = [], array $previousManualInputs = [], array $shareholders = [], array $shareCapitalClasses = [], array $previousShareCapitalClasses = [], ?array $depreciationSchedule = null): array
{
    $currentPaidUp = manualAmount($manualInputs, 'share_capital_paidup', classifiedAmount($classified, 'share_capital'));
    $previousPaidUp = manualAmount($previousManualInputs, 'share_capital_paidup', classifiedPreviousAmount($classified, 'share_capital'));
    $sections = [];
    $notesMaster = getSchedule3NotesMaster();
    $supportMap = schedule3MasterCodeToScheduleCodes();
    $inventoryChangeSection = null;
    $inventorySection = null;

    foreach ($notesMaster as $noteNo => $noteDef) {
        if (((string) ($noteDef['code'] ?? '')) !== 'INVCHG') {
            continue;
        }

        $inventoryChangeSection = buildInventoryChangeSection(
            $manualInputs,
            $previousManualInputs,
            (int) $noteNo,
            (string) ($noteDef['title'] ?? 'Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade')
        );
        $inventoryChangeSection['manual_inputs'] = $manualInputs;
        $inventoryChangeSection['previous_manual_inputs'] = $previousManualInputs;
        break;
    }

    foreach ($notesMaster as $noteNo => $noteDef) {
        $masterCode = (string) ($noteDef['code'] ?? '');
        $title = (string) ($noteDef['title'] ?? ('Note ' . $noteNo));

        if (in_array($masterCode, ['POL', 'GEN'], true)) {
            continue;
        }

        if ($masterCode === 'PPE' && $depreciationSchedule !== null) {
            $lines = [];
            foreach ($depreciationSchedule['by_category'] as $cat) {
                $lines[] = [
                    'label' => $cat['category'],
                    'current' => $cat['closing_wdv'],
                    'previous' => $cat['opening_gross_block'] - $cat['opening_accumulated_depreciation'],
                ];
            }
            $sections[] = [
                'title' => $title,
                'note_no' => $noteNo,
                'master_code' => $masterCode,
                'custom_type' => 'depreciation_schedule',
                'lines' => $lines,
                'current_total' => $depreciationSchedule['totals']['closing_wdv'],
                'previous_total' => $depreciationSchedule['totals']['opening_gross_block'] - $depreciationSchedule['totals']['opening_accumulated_depreciation'],
                'schedule' => $depreciationSchedule,
            ];
            continue;
        }

        if ($masterCode === 'SC') {
            $prevClassesByType = [];
            foreach ($previousShareCapitalClasses as $prevRow) {
                $prevClassesByType[(string) ($prevRow['share_type'] ?? '')] = $prevRow;
            }

            $shareClassRows = [];
            $closingShares = 0.0;
            foreach ($shareCapitalClasses as $row) {
                $shareType = (string) ($row['share_type'] ?? '');
                $faceValue = (float) ($row['face_value'] ?? 0);
                $authorisedShares = (float) ($row['authorised_shares'] ?? 0);
                $rowClosingShares = (float) ($row['closing_shares'] ?? 0);
                $prevRow = $prevClassesByType[$shareType] ?? null;
                $prevFaceValue = $prevRow !== null ? (float) ($prevRow['face_value'] ?? 0) : 0.0;
                $prevAuthorisedShares = $prevRow !== null ? (float) ($prevRow['authorised_shares'] ?? 0) : 0.0;
                $prevClosingShares = $prevRow !== null ? (float) ($prevRow['closing_shares'] ?? 0) : 0.0;

                $shareClassRows[] = [
                    'share_type' => $shareType,
                    'face_value' => $faceValue,
                    'authorised_shares' => $authorisedShares,
                    'authorised_amount' => $authorisedShares * $faceValue,
                    'opening_shares' => (float) ($row['opening_shares'] ?? 0),
                    'issued_during_year' => (float) ($row['issued_during_year'] ?? 0),
                    'bought_back_during_year' => (float) ($row['bought_back_during_year'] ?? 0),
                    'closing_shares' => $rowClosingShares,
                    'closing_amount' => $rowClosingShares * $faceValue,
                    'previous_face_value' => $prevFaceValue,
                    'previous_authorised_shares' => $prevAuthorisedShares,
                    'previous_authorised_amount' => $prevAuthorisedShares * $prevFaceValue,
                    'previous_closing_shares' => $prevClosingShares,
                    'previous_closing_amount' => $prevClosingShares * $prevFaceValue,
                ];
                $closingShares += $rowClosingShares;
            }

            $shareholdersAbove5Pct = [];
            foreach ($shareholders as $shareholder) {
                $shares = (float) ($shareholder['shares'] ?? 0);
                $percent = $closingShares > 0 ? round(($shares / $closingShares) * 100, 2) : 0.0;
                if ($percent > 5.0) {
                    $shareholdersAbove5Pct[] = [
                        'name' => (string) ($shareholder['name'] ?? ''),
                        'shares' => $shares,
                        'percent' => $percent,
                    ];
                }
            }

            $sections[] = [
                'title' => $title,
                'note_no' => $noteNo,
                'master_code' => $masterCode,
                'custom_type' => 'share_capital',
                'lines' => [
                    [
                        'label' => 'Authorised Capital',
                        'current' => manualAmount($manualInputs, 'share_capital_authorised', $currentPaidUp),
                        'previous' => manualAmount($previousManualInputs, 'share_capital_authorised', $previousPaidUp),
                    ],
                    [
                        'label' => 'Issued Capital',
                        'current' => manualAmount($manualInputs, 'share_capital_issued', $currentPaidUp),
                        'previous' => manualAmount($previousManualInputs, 'share_capital_issued', $previousPaidUp),
                    ],
                    [
                        'label' => 'Paid-up Capital',
                        'current' => $currentPaidUp,
                        'previous' => $previousPaidUp,
                    ],
                ],
                'current_total' => $currentPaidUp,
                'previous_total' => $previousPaidUp,
                'share_classes' => $shareClassRows,
                'shareholders_above_5pct' => $shareholdersAbove5Pct,
            ];
            continue;
        }

        if ($masterCode === 'OE') {
            $section = buildOtherEquitySection(
                $classified,
                $manualInputs,
                $previousManualInputs,
                (int) $noteNo,
                $title,
                $depreciationSchedule !== null ? (float) $depreciationSchedule['totals']['depreciation_for_year'] : null
            );
            $section['master_code'] = $masterCode;
            $sections[] = $section;
            continue;
        }

        if ($masterCode === 'DT') {
            $section = buildDeferredTaxSection($classified, (int) $noteNo, $title);
            $section['master_code'] = $masterCode;
            $sections[] = $section;
            continue;
        }

        if ($masterCode === 'INVCHG') {
            $section = $inventoryChangeSection ?? buildInventoryChangeSection(
                $manualInputs,
                $previousManualInputs,
                (int) $noteNo,
                $title
            );
            $section['master_code'] = $masterCode;
            $sections[] = $section;
            continue;
        }

        if ($masterCode === 'INVTRY') {
            $inventorySection = buildInventoriesSectionFromInventoryChange(
                $inventoryChangeSection ?? buildInventoryChangeSection(
                    $manualInputs,
                    $previousManualInputs,
                    24,
                    'Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade'
                ),
                (int) $noteNo,
                $title
            );
            if ((float) ($inventorySection['current_total'] ?? 0) == 0.0) {
                $classifiedInventory = classifiedAmount($classified, 'inventory');
                if (abs($classifiedInventory) > 0.00001) {
                    $inventorySection['current_total'] = $classifiedInventory;
                    $inventorySection['previous_total'] = classifiedPreviousAmount($classified, 'inventory');
                    $inventorySection['lines'] = [[
                        'label' => 'Closing Stock',
                        'current' => $classifiedInventory,
                        'previous' => classifiedPreviousAmount($classified, 'inventory'),
                    ]];
                }
            }
            $inventorySection['master_code'] = $masterCode;
            $sections[] = $inventorySection;
            continue;
        }

        if ($masterCode === 'MAT') {
            $section = buildCostOfMaterialsConsumedSection(
                $classified,
                $inventorySection ?? buildInventoriesSectionFromInventoryChange(
                    $inventoryChangeSection ?? buildInventoryChangeSection(
                        $manualInputs,
                        $previousManualInputs,
                        24,
                        'Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade'
                    ),
                    16,
                    'Inventories'
                ),
                $inventoryChangeSection ?? buildInventoryChangeSection(
                    $manualInputs,
                    $previousManualInputs,
                    24,
                    'Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade'
                ),
                (int) $noteNo,
                $title
            );
            $section['master_code'] = $masterCode;
            $sections[] = $section;
            continue;
        }

        if (in_array($masterCode, ['CL', 'COM', 'MSME', 'RPT', 'SEG', 'FI'], true)) {
            $sections[] = buildManualDisclosureSection($masterCode, $title, (int) $noteNo, $manualInputs);
            continue;
        }

        $section = buildDetailedNote($title, buildLedgerLines($classified, $supportMap[$masterCode] ?? []));
        $section['note_no'] = $noteNo;
        $section['master_code'] = $masterCode;
        $sections[] = $section;
    }

    /* ---- Branch / Divisions — Standalone Treatment ---- */
    // TODO: Future consolidation module should eliminate Branch / Division balances
    // across HO and branch books. For now, include in standalone Balance Sheet
    // with proper disclosure and warning.
    $branchDivCurrent = 0.0;
    $branchDivPrevious = 0.0;
    $branchDivLines = [];
    foreach ($classified['schedule_items'] as $code => $item) {
        foreach (($item['rows'] ?? []) as $row) {
            $pg = strtolower(trim((string) ($row['parent_group'] ?? '')));
            $pgNorm = str_replace(['&', '-', '_', '/', '.', ','], ' ', $pg);
            $pgNorm = preg_replace('/\s+/', ' ', $pgNorm);
            if (in_array($pgNorm, ['branch divisions', 'branch division', 'branch/divisions', 'branch/division'], true)) {
                $ledgerName = trim((string) ($row['ledger_name'] ?? ''));
                if ($ledgerName === '') continue;
                $drAmt = (float) ($row['amount'] ?? 0);
                $crAmt = (float) ($row['previous_amount'] ?? 0);
                $branchDivCurrent += $drAmt;
                $branchDivPrevious += $crAmt;
                $branchDivLines[] = [
                    'label' => $ledgerName,
                    'current' => $drAmt,
                    'previous' => $crAmt,
                ];
            }
        }
    }
    if ($branchDivCurrent != 0.0 || $branchDivPrevious != 0.0) {
        $branchDivNet = $branchDivCurrent - $branchDivPrevious;
        $branchNote = [
            'title' => 'Branch / Division Account — Standalone',
            'note_no' => count($sections) + 1,
            'master_code' => 'BRDIV',
            'custom_type' => 'branch_divisions',
            'lines' => $branchDivLines,
            'current_total' => $branchDivCurrent,
            'previous_total' => $branchDivPrevious,
            'branch_div_net' => $branchDivNet,
            'disclosure' => 'This represents inter-unit balance considered only for standalone Head Office financial statements and is subject to reconciliation/elimination in consolidated financial statements.',
        ];
        if (empty($branchDivLines)) {
            $branchNote['lines'] = [['label' => 'No Branch / Division ledger breakup available', 'current' => 0.0, 'previous' => 0.0]];
        }
        $sections[] = $branchNote;
    }

    return [
        'share_capital' => [
            'authorised' => manualAmount($manualInputs, 'share_capital_authorised', $currentPaidUp),
            'issued' => manualAmount($manualInputs, 'share_capital_issued', $currentPaidUp),
            'paidup' => $currentPaidUp,
            'prev_authorised' => manualAmount($previousManualInputs, 'share_capital_authorised', $previousPaidUp),
            'prev_issued' => manualAmount($previousManualInputs, 'share_capital_issued', $previousPaidUp),
            'prev_paidup' => $previousPaidUp,
        ],
        'other_equity' => [
            'opening_balance' => manualAmount(
                $manualInputs,
                'note2_opening_profit_loss',
                manualAmount(
                    $previousManualInputs,
                    'note2_closing_profit_loss',
                    manualAmount($previousManualInputs, 'note2_opening_profit_loss', 0) + buildCompanyProfitAfterTax($classified, $manualInputs, $previousManualInputs, true)
                )
            ),
        ],
        'tax_provision' => [
            'current' => manualAmount($manualInputs, 'tax_provision', 0),
            'previous' => manualAmount($previousManualInputs, 'tax_provision', 0),
        ],
        'sections' => $sections,
    ];
}

/**
 * Adds computed share_of_profit and closing_balance to each partner schedule
 * row (from getPartnerCapitalSchedule()) plus a totals row, ready for the
 * notes_llp.php / notes_noncorp.php templates to render directly. Kept out
 * of entity_master_helper.php since it's presentation logic (Note 3a
 * formatting), not data access.
 */
function withPartnerScheduleTotals(array $partnerSchedule, float $totalProfit): array
{
    if (empty($partnerSchedule)) {
        return ['rows' => [], 'totals' => null];
    }

    $rows = [];
    $totals = [
        'share_percentage' => 0.0, 'opening_balance' => 0.0, 'capital_introduced' => 0.0,
        'remuneration' => 0.0, 'interest_on_capital' => 0.0, 'withdrawals' => 0.0,
        'share_of_profit' => 0.0, 'closing_balance' => 0.0,
    ];

    foreach ($partnerSchedule as $row) {
        $shareOfProfit = $totalProfit * ((float) $row['share_percentage'] / 100);
        $closing = partnerCapitalClosingBalance($row, $shareOfProfit);
        $row['share_of_profit'] = $shareOfProfit;
        $row['closing_balance'] = $closing;
        $rows[] = $row;

        $totals['share_percentage'] += (float) $row['share_percentage'];
        $totals['opening_balance'] += (float) $row['opening_balance'];
        $totals['capital_introduced'] += (float) $row['capital_introduced'];
        $totals['remuneration'] += (float) $row['remuneration'];
        $totals['interest_on_capital'] += (float) $row['interest_on_capital'];
        $totals['withdrawals'] += (float) $row['withdrawals'];
        $totals['share_of_profit'] += $shareOfProfit;
        $totals['closing_balance'] += $closing;
    }

    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * @param array $partnerSchedule Rows from getPartnerCapitalSchedule(), or []
 *        if no partners have been added yet for this company.
 * @param float $totalProfit Current-year profit from $classified['summary']
 *        ['profit'], used to compute each partner's share_of_profit so it
 *        can never drift from the actual P&L figure.
 */
function buildLLPNotesPayload(array $classified, array $partnerSchedule = [], float $totalProfit = 0.0): array
{
    /* Notes 7-10 per the ICAI illustrative LLP format (Statutory/guidance
       note on LLP.pdf, Appendix B): Other Long-term Liabilities,
       Long/Short-term Provisions and Other Current Liabilities were
       previously invisible in the notes to accounts even though their
       amounts were already counted correctly in the balance sheet totals via
       classification -- these schedule codes existed but nothing routed them
       into a disclosed note, only into "Borrowings"/"Current Assets" via
       whichever bucket they happened to sum into.

       Deliberately NOT including Note 6 (Deferred Tax) despite it appearing
       in the ICAI illustrative format -- deferred tax accounting (AS 22
       book-vs-tax timing differences) doesn't apply to LLPs in practice;
       they're taxed as flat-rate pass-through entities, not on the accrual
       basis that gives rise to deferred tax. */
    $sections = [
            buildDetailedNote('Partners Capital', buildLedgerLines($classified, ['share_capital'])),
            buildDetailedNote('Partners Current Account / Reserves', buildLedgerLines($classified, ['reserves'])),
            buildDetailedNote('Borrowings', buildLedgerLines($classified, ['lt_borrowings', 'st_borrowings'])),
            buildDetailedNote('Other Long-term Liabilities', buildLedgerLines($classified, ['other_non_current_liabilities'])),
            buildDetailedNote('Long-term Provisions', buildLedgerLines($classified, ['long_term_provisions'])),
            buildDetailedNote('Trade Payables', buildLedgerLines($classified, ['trade_payables', 'trade_payables_msme'])),
            buildDetailedNote('Other Current Liabilities', buildLedgerLines($classified, ['other_current_liabilities', 'other_financial_liabilities'])),
            buildDetailedNote('Short-term Provisions', buildLedgerLines($classified, ['short_term_provisions'])),
            buildDetailedNote('Fixed Assets', buildLedgerLines($classified, ['ppe', 'cwip', 'intangible_assets'])),
            buildDetailedNote('Loans', buildLedgerLines($classified, ['loans_non_current', 'loans_current'])),
            buildDetailedNote('Investments', buildLedgerLines($classified, ['investments_non_current', 'investments_current'])),
            buildDetailedNote('Current Assets', buildLedgerLines($classified, ['inventory', 'receivables', 'cash', 'bank_balances_other', 'other_current_assets'])),
            buildDetailedNote('Revenue', buildLedgerLines($classified, ['revenue', 'other_income'])),
            buildDetailedNote('Expenses', buildLedgerLines($classified, ['materials', 'purchases', 'purchase_stock', 'inventory_change', 'direct_expenses', 'employee_cost', 'finance_cost', 'depreciation', 'other_expenses'])),
        ];

    return [
        'sections' => assignSequentialNoteMetadata($sections, [
            'Partners Capital',
            'Partners Current Account / Reserves',
            'Borrowings',
            'Other Long-term Liabilities',
            'Long-term Provisions',
            'Trade Payables',
            'Other Current Liabilities',
            'Short-term Provisions',
            'Fixed Assets',
            'Loans',
            'Investments',
            'Current Assets',
            'Revenue',
            'Expenses',
        ]),
        'partner_capital_schedule' => withPartnerScheduleTotals($partnerSchedule, $totalProfit),
    ];
}

function buildNonCorpNotesPayload(array $classified, string $subcategory = 'proprietorship', array $partnerSchedule = [], float $totalProfit = 0.0): array
{
    $baseSections = [
        'Capital' => buildDetailedNote('Capital', buildLedgerLines($classified, ['share_capital', 'reserves', 'corpus_fund'])),
        'Borrowings' => buildDetailedNote('Borrowings', buildLedgerLines($classified, ['lt_borrowings', 'st_borrowings'])),
        'Payables' => buildDetailedNote('Payables', buildLedgerLines($classified, ['trade_payables', 'trade_payables_msme', 'other_financial_liabilities'])),
        'Current Liabilities' => buildDetailedNote('Current Liabilities', buildLedgerLines($classified, ['other_current_liabilities', 'short_term_provisions'])),
        'Fixed Assets' => buildDetailedNote('Fixed Assets', buildLedgerLines($classified, ['ppe', 'cwip', 'intangible_assets'])),
        'Loans' => buildDetailedNote('Loans', buildLedgerLines($classified, ['loans_non_current', 'loans_current'])),
        'Investments' => buildDetailedNote('Investments', buildLedgerLines($classified, ['investments_non_current', 'investments_current'])),
        'Inventory' => buildDetailedNote('Inventory', buildLedgerLines($classified, ['inventory'])),
        'Receivables' => buildDetailedNote('Receivables', buildLedgerLines($classified, ['receivables'])),
        'Cash and Bank' => buildDetailedNote('Cash and Bank', buildLedgerLines($classified, ['cash', 'bank_balances_other'])),
        'Other Current Assets' => buildDetailedNote('Other Current Assets', buildLedgerLines($classified, ['other_current_assets'])),
        'Revenue' => buildDetailedNote('Revenue', buildLedgerLines($classified, ['revenue', 'sales', 'direct_income', 'donations', 'grants', 'membership_fees'])),
        'Expenses' => buildDetailedNote('Expenses', buildLedgerLines($classified, ['materials', 'purchases', 'purchase_stock', 'inventory_change', 'direct_expenses', 'employee_cost', 'finance_cost', 'depreciation', 'administrative_expenses', 'programme_expenses', 'other_expenses'])),
    ];

    $order = [
        'Capital', 'Borrowings', 'Payables', 'Current Liabilities',
        'Fixed Assets', 'Loans', 'Investments', 'Inventory',
        'Receivables', 'Cash and Bank', 'Other Current Assets',
        'Revenue', 'Expenses',
    ];

    if (isReceiptsPaymentsEntity($subcategory)) {
        $order = [
            'Capital', 'Borrowings', 'Payables', 'Current Liabilities',
            'Fixed Assets', 'Loans', 'Investments', 'Inventory',
            'Receivables', 'Cash and Bank', 'Other Current Assets',
            'Revenue', 'Expenses',
        ];
    }

    $sections = [];
    foreach ($order as $key) {
        if (isset($baseSections[$key])) {
            $sections[] = $baseSections[$key];
        }
    }

    return [
        'sections' => assignSequentialNoteMetadata($sections, $order),
        'partner_capital_schedule' => withPartnerScheduleTotals($partnerSchedule, $totalProfit),
    ];
}

function buildCompanySummaryFromNotes(array $classified, array $notes, string $fyLabel): array
{
    $sectionTotals = [];
    $sectionTotalsByCode = [];
    foreach (($notes['sections'] ?? []) as $section) {
        $sectionTotals[$section['title']] = [
            'current' => (float) ($section['current_total'] ?? sumLines($section['lines'] ?? [], 'current')),
            'previous' => (float) ($section['previous_total'] ?? sumLines($section['lines'] ?? [], 'previous')),
        ];
        $masterCode = (string) ($section['master_code'] ?? '');
        if ($masterCode !== '') {
            $sectionTotalsByCode[$masterCode] = $sectionTotals[$section['title']];
        }
    }

    $data = [
        'date' => $fyLabel,
        'share_capital' => $notes['share_capital']['paidup'],
        'prev_share_capital' => (float) ($notes['share_capital']['prev_paidup'] ?? classifiedPreviousAmount($classified, 'share_capital')),
        'reserves' => $sectionTotalsByCode['OE']['current'] ?? ($sectionTotals['Reserves & Surplus']['current'] ?? 0),
        'prev_reserves' => $sectionTotalsByCode['OE']['previous'] ?? ($sectionTotals['Reserves & Surplus']['previous'] ?? 0),
        'lt_borrowings' => ($sectionTotalsByCode['BOR']['current'] ?? 0),
        'prev_lt_borrowings' => ($sectionTotalsByCode['BOR']['previous'] ?? 0),
        'deferred_tax' => ($sectionTotalsByCode['DT']['current'] ?? 0),
        'prev_deferred_tax' => ($sectionTotalsByCode['DT']['previous'] ?? 0),
        'other_non_current_liabilities' => ($sectionTotalsByCode['ONCL']['current'] ?? 0),
        'prev_other_non_current_liabilities' => ($sectionTotalsByCode['ONCL']['previous'] ?? 0),
        'long_term_provisions' => ($sectionTotalsByCode['LTP']['current'] ?? 0),
        'prev_long_term_provisions' => ($sectionTotalsByCode['LTP']['previous'] ?? 0),
        'st_borrowings' => ($sectionTotalsByCode['STB']['current'] ?? 0),
        'prev_st_borrowings' => ($sectionTotalsByCode['STB']['previous'] ?? 0),
        'trade_payables' => ($sectionTotalsByCode['TP']['current'] ?? 0),
        'prev_trade_payables' => ($sectionTotalsByCode['TP']['previous'] ?? 0),
        'other_current_liabilities' => ($sectionTotalsByCode['OCL']['current'] ?? 0),
        'prev_other_current_liabilities' => ($sectionTotalsByCode['OCL']['previous'] ?? 0),
        'short_term_provisions' => ($sectionTotalsByCode['STP']['current'] ?? 0),
        'prev_short_term_provisions' => ($sectionTotalsByCode['STP']['previous'] ?? 0),
        'fixed_assets' => $sectionTotalsByCode['PPE']['current'] ?? ($sectionTotals['Property, Plant & Equipment']['current'] ?? 0),
        'prev_fixed_assets' => $sectionTotalsByCode['PPE']['previous'] ?? ($sectionTotals['Property, Plant & Equipment']['previous'] ?? 0),
        'intangible_assets' => $sectionTotalsByCode['INT']['current'] ?? ($sectionTotals['Intangible Assets']['current'] ?? 0),
        'prev_intangible_assets' => $sectionTotalsByCode['INT']['previous'] ?? ($sectionTotals['Intangible Assets']['previous'] ?? 0),
        'investments' => $sectionTotalsByCode['INV']['current'] ?? ($sectionTotals['Investments']['current'] ?? 0),
        'prev_investments' => $sectionTotalsByCode['INV']['previous'] ?? ($sectionTotals['Investments']['previous'] ?? 0),
        'loans' => $sectionTotalsByCode['LOAN']['current'] ?? ($sectionTotals['Loans']['current'] ?? 0),
        'prev_loans' => $sectionTotalsByCode['LOAN']['previous'] ?? ($sectionTotals['Loans']['previous'] ?? 0),
        'other_financial_assets' => $sectionTotalsByCode['OFA']['current'] ?? ($sectionTotals['Other Financial Assets']['current'] ?? 0),
        'prev_other_financial_assets' => $sectionTotalsByCode['OFA']['previous'] ?? ($sectionTotals['Other Financial Assets']['previous'] ?? 0),
        'inventory' => $sectionTotalsByCode['INVTRY']['current'] ?? ($sectionTotals['Inventories']['current'] ?? 0),
        'prev_inventory' => $sectionTotalsByCode['INVTRY']['previous'] ?? ($sectionTotals['Inventories']['previous'] ?? 0),
        'receivables' => $sectionTotalsByCode['TR']['current'] ?? ($sectionTotals['Trade Receivables']['current'] ?? 0),
        'prev_receivables' => $sectionTotalsByCode['TR']['previous'] ?? ($sectionTotals['Trade Receivables']['previous'] ?? 0),
        'cash' => ($sectionTotalsByCode['CASH']['current'] ?? 0),
        'prev_cash' => ($sectionTotalsByCode['CASH']['previous'] ?? 0),
        'other_current_assets' => ($sectionTotalsByCode['OCA']['current'] ?? 0),
        'prev_other_current_assets' => ($sectionTotalsByCode['OCA']['previous'] ?? 0),
        'revenue' => $sectionTotalsByCode['REV']['current'] ?? ($sectionTotals['Revenue from Operations']['current'] ?? 0),
        'prev_revenue' => $sectionTotalsByCode['REV']['previous'] ?? ($sectionTotals['Revenue from Operations']['previous'] ?? 0),
        'other_income' => $sectionTotalsByCode['OIN']['current'] ?? ($sectionTotals['Other Income']['current'] ?? 0),
        'prev_other_income' => $sectionTotalsByCode['OIN']['previous'] ?? ($sectionTotals['Other Income']['previous'] ?? 0),
        'materials' => $sectionTotalsByCode['MAT']['current'] ?? ($sectionTotals['Cost of Materials Consumed']['current'] ?? 0),
        'prev_materials' => $sectionTotalsByCode['MAT']['previous'] ?? ($sectionTotals['Cost of Materials Consumed']['previous'] ?? 0),
        'purchase_stock' => $sectionTotalsByCode['PUR']['current'] ?? ($sectionTotals['Purchase of Stock-in-Trade']['current'] ?? 0),
        'prev_purchase_stock' => $sectionTotalsByCode['PUR']['previous'] ?? ($sectionTotals['Purchase of Stock-in-Trade']['previous'] ?? 0),
        'inventory_change' => $sectionTotalsByCode['INVCHG']['current'] ?? ($sectionTotals['Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade']['current'] ?? 0),
        'prev_inventory_change' => $sectionTotalsByCode['INVCHG']['previous'] ?? ($sectionTotals['Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade']['previous'] ?? 0),
        'employee_cost' => $sectionTotalsByCode['EMP']['current'] ?? ($sectionTotals['Employee Benefits Expense']['current'] ?? 0),
        'prev_employee_cost' => $sectionTotalsByCode['EMP']['previous'] ?? ($sectionTotals['Employee Benefits Expense']['previous'] ?? 0),
        'finance_cost' => $sectionTotalsByCode['FIN']['current'] ?? ($sectionTotals['Finance Cost']['current'] ?? 0),
        'prev_finance_cost' => $sectionTotalsByCode['FIN']['previous'] ?? ($sectionTotals['Finance Cost']['previous'] ?? 0),
        'depreciation' => $sectionTotalsByCode['DEP']['current'] ?? ($sectionTotals['Depreciation & Amortisation']['current'] ?? 0),
        'prev_depreciation' => $sectionTotalsByCode['DEP']['previous'] ?? ($sectionTotals['Depreciation & Amortisation']['previous'] ?? 0),
        'other_expenses' => $sectionTotalsByCode['EXP']['current'] ?? ($sectionTotals['Other Expenses']['current'] ?? 0),
        'prev_other_expenses' => $sectionTotalsByCode['EXP']['previous'] ?? ($sectionTotals['Other Expenses']['previous'] ?? 0),
        'tax' => (float) ($notes['tax_provision']['current'] ?? 0),
        'prev_tax' => (float) ($notes['tax_provision']['previous'] ?? 0),
    ];

    /* When the Asset Register (public/asset_register.php) has been
       populated for this FY, use its computed Schedule II depreciation
       figure for the P&L instead of the flat TB "Depreciation" ledger
       balance -- the register's SLM/WDV/pro-rata computation is the more
       accurate figure once it exists. Previous-year depreciation is left
       as the TB-derived figure since the register may not have been
       populated for the comparative year. */
    foreach (($notes['sections'] ?? []) as $section) {
        if (($section['custom_type'] ?? '') === 'depreciation_schedule') {
            $data['depreciation'] = (float) ($section['schedule']['totals']['depreciation_for_year'] ?? $data['depreciation']);
            break;
        }
    }

    /* ---- Branch / Divisions — Standalone Balance Sheet Treatment ---- */
    // TODO: Future consolidation module should eliminate Branch / Division balances
    // across HO and branch books. For now, include in standalone Balance Sheet.
    $branchDivNote = null;
    foreach (($notes['sections'] ?? []) as $section) {
        if (($section['custom_type'] ?? '') === 'branch_divisions') {
            $branchDivNote = $section;
            break;
        }
    }
    if ($branchDivNote !== null) {
        $branchDivCurrent = (float) ($branchDivNote['current_total'] ?? 0);
        $branchDivPrevious = (float) ($branchDivNote['previous_total'] ?? 0);
        if ($branchDivCurrent > 0) {
            // Debit balance → include as asset
            $data['other_current_assets'] += $branchDivCurrent;
            $data['prev_other_current_assets'] += $branchDivPrevious;
        } elseif ($branchDivCurrent < 0) {
            // Credit balance → include as liability
            $data['other_current_liabilities'] += abs($branchDivCurrent);
            $data['prev_other_current_liabilities'] += abs($branchDivPrevious);
        }
        $data['branch_divisions_note'] = [
            'current' => $branchDivCurrent,
            'previous' => $branchDivPrevious,
            'disclosure' => $branchDivNote['disclosure'] ?? '',
        ];
    }

    $data['total_income'] = $data['revenue'] + $data['other_income'];
    $data['prev_total_income'] = $data['prev_revenue'] + $data['prev_other_income'];
    $data['expenses'] =
        $data['materials'] +
        $data['purchase_stock'] +
        $data['inventory_change'] +
        $data['employee_cost'] +
        $data['finance_cost'] +
        $data['depreciation'] +
        $data['other_expenses'];
    $data['prev_expenses'] =
        $data['prev_materials'] +
        $data['prev_purchase_stock'] +
        $data['prev_inventory_change'] +
        $data['prev_employee_cost'] +
        $data['prev_finance_cost'] +
        $data['prev_depreciation'] +
        $data['prev_other_expenses'];
    $data['pbt'] = $data['total_income'] - $data['expenses'];
    $data['prev_pbt'] = $data['prev_total_income'] - $data['prev_expenses'];
    $data['pat'] = $data['pbt'] - $data['tax'];
    $data['prev_pat'] = $data['prev_pbt'] - $data['prev_tax'];
    $data['total_liabilities'] =
        $data['share_capital']
        + $data['reserves']
        + $data['lt_borrowings']
        + $data['deferred_tax']
        + $data['other_non_current_liabilities']
        + $data['long_term_provisions']
        + $data['st_borrowings']
        + $data['trade_payables']
        + $data['other_current_liabilities']
        + $data['short_term_provisions'];
    $data['prev_total_liabilities'] =
        $data['prev_share_capital']
        + $data['prev_reserves']
        + $data['prev_lt_borrowings']
        + $data['prev_deferred_tax']
        + $data['prev_other_non_current_liabilities']
        + $data['prev_long_term_provisions']
        + $data['prev_st_borrowings']
        + $data['prev_trade_payables']
        + $data['prev_other_current_liabilities']
        + $data['prev_short_term_provisions'];
    $data['total_assets'] =
        $data['fixed_assets']
        + $data['intangible_assets']
        + $data['investments']
        + $data['loans']
        + $data['other_financial_assets']
        + $data['inventory']
        + $data['receivables']
        + $data['cash']
        + $data['other_current_assets'];
    $data['prev_total_assets'] =
        $data['prev_fixed_assets']
        + $data['prev_intangible_assets']
        + $data['prev_investments']
        + $data['prev_loans']
        + $data['prev_other_financial_assets']
        + $data['prev_inventory']
        + $data['prev_receivables']
        + $data['prev_cash']
        + $data['prev_other_current_assets'];
    $data['note_refs'] = buildNoteIndex($notes['sections'] ?? []);

    return $data;
}

function buildLLPSummaryFromNotes(array $classified, array $notes, string $fyLabel): array
{
    $sectionTotals = [];
    foreach (($notes['sections'] ?? []) as $section) {
        $sectionTotals[$section['title']] = [
            'current' => sumLines($section['lines'] ?? [], 'current'),
            'previous' => sumLines($section['lines'] ?? [], 'previous'),
        ];
    }

    $data = [
        'date' => $fyLabel,
        'capital' => $sectionTotals['Partners Capital']['current'] ?? 0,
        'prev_capital' => $sectionTotals['Partners Capital']['previous'] ?? 0,
        'current_accounts' => $sectionTotals['Partners Current Account / Reserves']['current'] ?? 0,
        'prev_current_accounts' => $sectionTotals['Partners Current Account / Reserves']['previous'] ?? 0,
        /* Folds in the Notes 7-10 line items (Other Long-term Liabilities,
           Long/Short-term Provisions, Other Current Liabilities) added
           alongside Borrowings/Trade Payables -- their rupee amounts must
           reach this total even though the balance sheet face still shows
           one lump "Borrowings" line (readers get the itemized breakdown
           from the notes themselves, standard Schedule III practice).
           Without this, the notes would disclose real amounts the face
           total silently excluded. */
        'borrowings' => ($sectionTotals['Borrowings']['current'] ?? 0)
            + ($sectionTotals['Trade Payables']['current'] ?? 0)
            + ($sectionTotals['Other Long-term Liabilities']['current'] ?? 0)
            + ($sectionTotals['Long-term Provisions']['current'] ?? 0)
            + ($sectionTotals['Other Current Liabilities']['current'] ?? 0)
            + ($sectionTotals['Short-term Provisions']['current'] ?? 0),
        'prev_borrowings' => ($sectionTotals['Borrowings']['previous'] ?? 0)
            + ($sectionTotals['Trade Payables']['previous'] ?? 0)
            + ($sectionTotals['Other Long-term Liabilities']['previous'] ?? 0)
            + ($sectionTotals['Long-term Provisions']['previous'] ?? 0)
            + ($sectionTotals['Other Current Liabilities']['previous'] ?? 0)
            + ($sectionTotals['Short-term Provisions']['previous'] ?? 0),
        'fixed_assets' => $sectionTotals['Fixed Assets']['current'] ?? 0,
        'prev_fixed_assets' => $sectionTotals['Fixed Assets']['previous'] ?? 0,
        'current_assets' => $sectionTotals['Current Assets']['current'] ?? 0,
        'prev_current_assets' => $sectionTotals['Current Assets']['previous'] ?? 0,
        'revenue' => $sectionTotals['Revenue']['current'] ?? 0,
        'prev_revenue' => $sectionTotals['Revenue']['previous'] ?? 0,
        'expenses' => $sectionTotals['Expenses']['current'] ?? 0,
        'prev_expenses' => $sectionTotals['Expenses']['previous'] ?? 0,
        'remuneration' => 0,
        'prev_remuneration' => 0,
        'purchases' => classifiedAmount($classified, 'purchases') ?: classifiedAmount($classified, 'purchase_stock'),
        'prev_purchases' => classifiedPreviousAmount($classified, 'purchases') ?: classifiedPreviousAmount($classified, 'purchase_stock'),
        'direct_expenses' => classifiedAmount($classified, 'direct_expenses') ?: classifiedAmount($classified, 'materials'),
        'prev_direct_expenses' => classifiedPreviousAmount($classified, 'direct_expenses') ?: classifiedPreviousAmount($classified, 'materials'),
        'capital_introduced' => classifiedAmount($classified, 'capital_introduced'),
        'prev_capital_introduced' => classifiedPreviousAmount($classified, 'capital_introduced'),
        'drawings' => classifiedAmount($classified, 'drawings'),
        'prev_drawings' => classifiedPreviousAmount($classified, 'drawings'),
        'inventory' => $sectionTotals['Current Assets']['current'] ?? 0,
        'prev_inventory' => $sectionTotals['Current Assets']['previous'] ?? 0,
    ];

    $data['pbr'] = $data['revenue'] - $data['expenses'];
    $data['prev_pbr'] = $data['prev_revenue'] - $data['prev_expenses'];
    $data['pat'] = $data['pbr'] - $data['remuneration'];
    $data['prev_pat'] = $data['prev_pbr'] - $data['prev_remuneration'];
    $data['current_accounts'] = ($sectionTotals['Partners Current Account / Reserves']['current'] ?? 0) + $data['pat'];
    $data['prev_current_accounts'] = ($sectionTotals['Partners Current Account / Reserves']['previous'] ?? 0) + $data['prev_pat'];
    $data['total'] = $data['capital'] + $data['current_accounts'] + $data['borrowings'];
    $data['prev_total'] = $data['prev_capital'] + $data['prev_current_accounts'] + $data['prev_borrowings'];
    $data['note_refs'] = buildNoteIndex($notes['sections'] ?? []);

    return $data;
}

function buildNonCorpSummaryFromNotes(array $classified, array $notes, string $fyLabel, string $subcategory = 'proprietorship'): array
{
    $sectionTotals = [];
    foreach (($notes['sections'] ?? []) as $section) {
        $sectionTotals[$section['title']] = [
            'current' => sumLines($section['lines'] ?? [], 'current'),
            'previous' => sumLines($section['lines'] ?? [], 'previous'),
        ];
    }

    $capital = $sectionTotals['Capital']['current'] ?? 0;
    $prevCapital = $sectionTotals['Capital']['previous'] ?? 0;
    $borrowings = ($sectionTotals['Borrowings']['current'] ?? 0);
    $prevBorrowings = ($sectionTotals['Borrowings']['previous'] ?? 0);
    $payables = ($sectionTotals['Payables']['current'] ?? 0);
    $prevPayables = ($sectionTotals['Payables']['previous'] ?? 0);
    $currentLiabilities = ($sectionTotals['Current Liabilities']['current'] ?? 0);
    $prevCurrentLiabilities = ($sectionTotals['Current Liabilities']['previous'] ?? 0);
    $fixedAssets = $sectionTotals['Fixed Assets']['current'] ?? 0;
    $prevFixedAssets = $sectionTotals['Fixed Assets']['previous'] ?? 0;
    $loans = $sectionTotals['Loans']['current'] ?? 0;
    $prevLoans = $sectionTotals['Loans']['previous'] ?? 0;
    $investments = $sectionTotals['Investments']['current'] ?? 0;
    $prevInvestments = $sectionTotals['Investments']['previous'] ?? 0;
    $inventory = $sectionTotals['Inventory']['current'] ?? 0;
    $prevInventory = $sectionTotals['Inventory']['previous'] ?? 0;
    $receivables = $sectionTotals['Receivables']['current'] ?? 0;
    $prevReceivables = $sectionTotals['Receivables']['previous'] ?? 0;
    $cash = $sectionTotals['Cash and Bank']['current'] ?? 0;
    $prevCash = $sectionTotals['Cash and Bank']['previous'] ?? 0;
    $otherCurrentAssets = $sectionTotals['Other Current Assets']['current'] ?? 0;
    $prevOtherCurrentAssets = $sectionTotals['Other Current Assets']['previous'] ?? 0;
    $revenue = $sectionTotals['Revenue']['current'] ?? 0;
    $prevRevenue = $sectionTotals['Revenue']['previous'] ?? 0;
    $expenses = $sectionTotals['Expenses']['current'] ?? 0;
    $prevExpenses = $sectionTotals['Expenses']['previous'] ?? 0;

    $data = [
        'date' => $fyLabel,
        'capital' => $capital,
        'prev_capital' => $prevCapital,
        'reserves' => 0,
        'prev_reserves' => 0,
        'borrowings' => $borrowings,
        'prev_borrowings' => $prevBorrowings,
        'payables' => $payables,
        'prev_payables' => $prevPayables,
        'current_liabilities' => $currentLiabilities,
        'prev_current_liabilities' => $prevCurrentLiabilities,
        'fixed_assets' => $fixedAssets,
        'prev_fixed_assets' => $prevFixedAssets,
        'loans' => $loans,
        'prev_loans' => $prevLoans,
        'investments' => $investments,
        'prev_investments' => $prevInvestments,
        'inventory' => $inventory,
        'prev_inventory' => $prevInventory,
        'receivables' => $receivables,
        'prev_receivables' => $prevReceivables,
        'cash' => $cash,
        'prev_cash' => $prevCash,
        'other_current_assets' => $otherCurrentAssets,
        'prev_other_current_assets' => $prevOtherCurrentAssets,
        'current_assets' => $inventory + $receivables + $cash + $otherCurrentAssets,
        'prev_current_assets' => $prevInventory + $prevReceivables + $prevCash + $prevOtherCurrentAssets,
        'revenue' => $revenue,
        'prev_revenue' => $prevRevenue,
        'expenses' => $expenses,
        'prev_expenses' => $prevExpenses,
        /* Correct as 0 for proprietorship (taxed on the individual, not the firm).
           Not yet correct for partnership/trust/society, which do have entity-level
           tax — buildNonCorpNotesPayload() has no manual-input wiring or UI field
           for it yet, unlike buildCompanyNotesPayload()'s tax_provision. Needs its
           own manual-input UI before this can be sourced correctly per subcategory. */
        'tax' => 0,
        'prev_tax' => 0,
        'other_income' => classifiedAmount($classified, 'other_income') ?: 0,
        'prev_other_income' => classifiedPreviousAmount($classified, 'other_income') ?: 0,
        'employee_cost' => classifiedAmount($classified, 'employee_cost') ?: 0,
        'prev_employee_cost' => classifiedPreviousAmount($classified, 'employee_cost') ?: 0,
        'finance_cost' => classifiedAmount($classified, 'finance_cost') ?: 0,
        'prev_finance_cost' => classifiedPreviousAmount($classified, 'finance_cost') ?: 0,
        'depreciation' => classifiedAmount($classified, 'depreciation') ?: 0,
        'prev_depreciation' => classifiedPreviousAmount($classified, 'depreciation') ?: 0,
        'other_expenses' => classifiedAmount($classified, 'other_expenses') ?: 0,
        'prev_other_expenses' => classifiedPreviousAmount($classified, 'other_expenses') ?: 0,
    ];

    $data['sales'] = classifiedAmount($classified, 'sales') ?: $revenue;
    $data['prev_sales'] = classifiedPreviousAmount($classified, 'sales') ?: $prevRevenue;
    $data['purchases'] = classifiedAmount($classified, 'purchases') ?: classifiedAmount($classified, 'purchase_stock');
    $data['prev_purchases'] = classifiedPreviousAmount($classified, 'purchases') ?: classifiedPreviousAmount($classified, 'purchase_stock');
    $data['direct_expenses'] = classifiedAmount($classified, 'direct_expenses') ?: classifiedAmount($classified, 'materials');
    $data['prev_direct_expenses'] = classifiedPreviousAmount($classified, 'direct_expenses') ?: classifiedPreviousAmount($classified, 'materials');
    $data['opening_stock_val'] = classifiedAmount($classified, 'opening_stock');
    $data['prev_opening_stock_val'] = classifiedPreviousAmount($classified, 'opening_stock');
    $data['closing_stock_val'] = $inventory;
    $data['prev_closing_stock_val'] = $prevInventory;

    $data['capital_introduced'] = classifiedAmount($classified, 'capital_introduced');
    $data['prev_capital_introduced'] = classifiedPreviousAmount($classified, 'capital_introduced');
    $data['drawings'] = classifiedAmount($classified, 'drawings');
    $data['prev_drawings'] = classifiedPreviousAmount($classified, 'drawings');

    $data['corpus_fund'] = classifiedAmount($classified, 'corpus_fund') ?: 0;
    $data['prev_corpus_fund'] = classifiedPreviousAmount($classified, 'corpus_fund') ?: 0;

    $data['donations'] = classifiedAmount($classified, 'donations');
    $data['prev_donations'] = classifiedPreviousAmount($classified, 'donations');
    $data['grants'] = classifiedAmount($classified, 'grants');
    $data['prev_grants'] = classifiedPreviousAmount($classified, 'grants');
    $data['membership_fees'] = classifiedAmount($classified, 'membership_fees');
    $data['prev_membership_fees'] = classifiedPreviousAmount($classified, 'membership_fees');
    $data['administrative_expenses'] = classifiedAmount($classified, 'administrative_expenses');
    $data['prev_administrative_expenses'] = classifiedPreviousAmount($classified, 'administrative_expenses');
    $data['programme_expenses'] = classifiedAmount($classified, 'programme_expenses');
    $data['prev_programme_expenses'] = classifiedPreviousAmount($classified, 'programme_expenses');

    $operatingExpenses = $data['employee_cost'] + $data['finance_cost'] + $data['depreciation'] + $data['other_expenses'];
    $prevOperatingExpenses = $data['prev_employee_cost'] + $data['prev_finance_cost'] + $data['prev_depreciation'] + $data['prev_other_expenses'];
    $data['operating_expenses'] = $operatingExpenses;
    $data['prev_operating_expenses'] = $prevOperatingExpenses;

    $isTradingEntity = in_array($subcategory, ['proprietorship', 'partnership', 'llp'], true);
    if ($isTradingEntity) {
        /* Pilot defect D03: closing stock was being added here to compute
           gross profit (standard trading-account convention: GP = Sales +
           Closing Stock - Opening Stock - Purchases - Direct Expenses) AND
           separately counted in current_assets a few lines above -- since
           this PBT flows into capital -> total_liabilities while
           current_assets independently already includes the same closing
           stock value, the balance sheet showed a gap exactly equal to
           inventory (confirmed against Nivedha's pilot data: ₹450,000 gap =
           inventory value). Closing stock is treated as purely a BS item
           here per the pilot's diagnosed fix -- it must not also inflate
           the P&L side that feeds capital. */
        $gp = $data['sales'] - $data['opening_stock_val'] - $data['purchases'] - $data['direct_expenses'];
        $prevGp = $data['prev_sales'] - $data['prev_opening_stock_val'] - $data['prev_purchases'] - $data['prev_direct_expenses'];
        $data['pbt'] = max($gp, 0) + $data['other_income'] - $operatingExpenses - ($gp < 0 ? abs($gp) : 0);
        $data['prev_pbt'] = max($prevGp, 0) + $data['prev_other_income'] - $prevOperatingExpenses - ($prevGp < 0 ? abs($prevGp) : 0);
    } else {
        $data['pbt'] = $revenue + $data['other_income'] - $expenses;
        $data['prev_pbt'] = $prevRevenue + $data['prev_other_income'] - $prevExpenses;
    }
    $data['pat'] = $data['pbt'];
    $data['prev_pat'] = $data['prev_pbt'];
    $data['capital'] = $capital + $prevCapital + $data['pat'];
    $data['prev_capital'] = $prevCapital + $data['prev_pat'];
    $data['total_liabilities'] = $data['capital'] + $borrowings + $payables + $currentLiabilities;
    $data['prev_total_liabilities'] = $data['prev_capital'] + $prevBorrowings + $prevPayables + $prevCurrentLiabilities;
    $data['total_assets'] = $fixedAssets + $loans + $investments + $data['current_assets'];
    $data['prev_total_assets'] = $prevFixedAssets + $prevLoans + $prevInvestments + $data['prev_current_assets'];
    $data['total'] = $data['total_liabilities'];
    $data['prev_total'] = $data['prev_total_liabilities'];

    $data['note_refs'] = buildNoteIndex($notes['sections'] ?? []);

    $data['subcategory'] = $subcategory;

    return $data;
}

function buildExpectedNoteTitles(string $entity, string $subcategory = ''): array
{
    if ($entity === 'corporate') {
        return [
            'Share Capital',
            'Reserves & Surplus',
            'Borrowings',
            'Deferred Tax',
            'Other Non-Current Liabilities',
            'Long-Term Provisions',
            'Short-Term Borrowings',
            'Trade Payables',
            'Other Current Liabilities',
            'Short-Term Provisions',
            'Property, Plant & Equipment',
            'Intangible Assets',
            'Investments',
            'Loans',
            'Other Financial Assets',
            'Inventories',
            'Trade Receivables',
            'Cash & Cash Equivalents',
            'Other Current Assets',
            'Revenue from Operations',
            'Other Income',
            'Cost of Materials Consumed',
            'Purchase of Stock-in-Trade',
            'Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade',
            'Employee Benefits Expense',
            'Finance Cost',
            'Depreciation & Amortisation',
            'Other Expenses',
        ];
    }

    if ($entity === 'llp') {
        return [
            'Partners Capital',
            'Partners Current Account / Reserves',
            'Borrowings',
            'Trade Payables',
            'Fixed Assets',
            'Loans',
            'Investments',
            'Current Assets',
            'Revenue',
            'Expenses',
        ];
    }

    $titles = [
        'Capital',
        'Borrowings',
        'Payables',
        'Current Liabilities',
        'Fixed Assets',
        'Loans',
        'Investments',
        'Inventory',
        'Receivables',
        'Cash and Bank',
        'Other Current Assets',
        'Revenue',
        'Expenses',
    ];

    return $titles;
}

function normalizeNoteAuditTitle(string $title): string
{
    $normalized = strtolower(trim($title));
    $normalized = str_replace(['&', '-', '/', ',', '.', '(', ')'], ['and', ' ', ' ', ' ', ' ', '', ''], $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return trim((string) $normalized);
}

function buildNoteCompletenessAudit(string $entity, array $sections, string $subcategory = ''): array
{
    $expected = buildExpectedNoteTitles($entity, $subcategory);
    $availableMap = [];

    foreach ($sections as $section) {
        $title = trim((string) ($section['title'] ?? ''));
        if ($title === '') {
            continue;
        }

        $availableMap[normalizeNoteAuditTitle($title)] = $title;
    }

    $missing = [];
    foreach ($expected as $title) {
        if (!isset($availableMap[normalizeNoteAuditTitle($title)])) {
            $missing[] = $title;
        }
    }

    return [
        'expected' => $expected,
        'available' => array_values($availableMap),
        'missing' => $missing,
        'is_complete' => $missing === [],
    ];
}

function generateFinancialStatements(PDO $pdo, int $company_id, int $fy_id, string $fyLabel = '', array $manualInputs = [], array $previousManualInputs = []): array
{
    $entity = getEntityCategory($pdo, $company_id);
    $classified = getClassifiedData($pdo, $company_id, $fy_id);
    $companyMeta = getCompanyReportingMeta($pdo, $company_id);
    $subcategory = $companyMeta['entity_subcategory'] ?? 'proprietorship';
    $fyDisplay = $fyLabel !== '' ? $fyLabel : ('FY ' . $fy_id);

    require_once __DIR__ . '/../helpers/fy_closure_helper.php';
    ensureFYClosureSchema($pdo);
    $hasPrevData = hasPreviousYearData($pdo, $company_id, $fy_id);
    $isFirstYear = !$hasPrevData;
    $prevFYId = getPreviousFYId($pdo, $company_id, $fy_id);

    /* Partner Capital Schedule (LLP / Partnership only) — fetched here rather
       than inside the note builders below because it needs $pdo, and those
       builders otherwise only ever receive already-classified data. Empty
       array for every other entity type, and for LLP/Partnership entities
       that haven't added any partners via statements/partner_capital.php yet
       (the note builders fall back to the pre-existing aggregate treatment
       in that case). */
    $partnerSchedule = [];
    if ($entity === 'llp' || ($entity === 'non_corporate' && $subcategory === 'partnership')) {
        require_once __DIR__ . '/../helpers/entity_master_helper.php';
        ensurePartnerCapitalSchema($pdo);
        $partnerSchedule = getPartnerCapitalSchedule($pdo, $company_id, $fy_id, $prevFYId);
    }
    $totalProfitForSchedule = (float) ($classified['summary']['profit'] ?? 0);

    switch ($entity) {
        case 'corporate':
            require_once __DIR__ . '/../helpers/share_capital_helper.php';
            $shareholders = getShareholders($pdo, $company_id, $fy_id);
            $shareCapitalClasses = getShareCapitalClasses($pdo, $company_id, $fy_id);
            $previousShareCapitalClasses = $prevFYId > 0 ? getShareCapitalClasses($pdo, $company_id, $prevFYId) : [];

            /* Depreciation schedule (Schedule II) -- fetched here rather than
               inside the note builders below because it needs $pdo, same
               reasoning as the Partner Capital Schedule above. Only replaces
               the generic Fixed Assets ledger-list note when the CA has
               actually populated the Asset Register (public/asset_register.php)
               for this company/FY -- otherwise every existing company would
               suddenly lose its Fixed Assets note the moment this feature
               shipped, before anyone had a chance to set up their register. */
            require_once __DIR__ . '/../helpers/fixed_asset_helper.php';
            $depreciationSchedule = null;
            $fyDatesStmt = $pdo->prepare('SELECT fy_start, fy_end FROM financial_years WHERE id = ? AND company_id = ?');
            $fyDatesStmt->execute([$fy_id, $company_id]);
            $fyDatesRow = $fyDatesStmt->fetch(PDO::FETCH_ASSOC);
            if ($fyDatesRow !== false && !empty($fyDatesRow['fy_start']) && !empty($fyDatesRow['fy_end'])) {
                $candidateSchedule = computeDepreciationSchedule($pdo, $company_id, $fy_id, (string) $fyDatesRow['fy_start'], (string) $fyDatesRow['fy_end']);
                if ($candidateSchedule['has_data']) {
                    $depreciationSchedule = $candidateSchedule;
                }
            }

            $notes = buildCompanyNotesPayload($classified, $manualInputs, $previousManualInputs, $shareholders, $shareCapitalClasses, $previousShareCapitalClasses, $depreciationSchedule);
            $data = buildCompanySummaryFromNotes($classified, $notes, $fyDisplay);
            foreach ($notes['sections'] as $sectionIndex => $sectionRow) {
                if (($sectionRow['master_code'] ?? '') === 'EPS') {
                    $notes['sections'][$sectionIndex] = buildEpsSection(
                        (float) ($data['pat'] ?? 0),
                        (float) ($data['prev_pat'] ?? 0),
                        $shareCapitalClasses,
                        $previousShareCapitalClasses,
                        (int) ($sectionRow['note_no'] ?? 33),
                        (string) ($sectionRow['title'] ?? 'Earnings Per Share')
                    );
                    break;
                }
            }
            $formatTemplate = __DIR__ . '/../../public/reports_dashboard/formats/company_format.php';
            $notesTemplate = __DIR__ . '/../../public/reports_dashboard/formats/notes_company.php';
            $title = 'Schedule III Financial Statements';
            break;

        case 'llp':
            $notes = buildLLPNotesPayload($classified, $partnerSchedule, $totalProfitForSchedule);
            $data = buildLLPSummaryFromNotes($classified, $notes, $fyDisplay);
            $formatTemplate = __DIR__ . '/../../public/reports_dashboard/formats/llp_format.php';
            $notesTemplate = __DIR__ . '/../../public/reports_dashboard/formats/notes_llp.php';
            $title = 'LLP Financial Statements';
            break;

        case 'non_corporate':
        default:
            $notes = buildNonCorpNotesPayload($classified, $subcategory, $partnerSchedule, $totalProfitForSchedule);
            $data = buildNonCorpSummaryFromNotes($classified, $notes, $fyDisplay, $subcategory);
            $formatTemplate = __DIR__ . '/../../public/reports_dashboard/formats/noncorporate_format.php';
            $notesTemplate = __DIR__ . '/../../public/reports_dashboard/formats/notes_noncorp.php';
            $title = 'Non-Corporate Financial Statements';
            break;
    }

    $data['entity_subcategory'] = $subcategory;

    return [
        'title' => $title,
        'entity_category' => $entity,
        'entity_subcategory' => $subcategory,
        'format_template' => $formatTemplate,
        'notes_template' => $notesTemplate,
        'data' => $data,
        'notes' => $notes,
        'summary' => $classified['summary'],
        'previous_summary' => $classified['previous_summary'],
        'schedule_items' => $classified['schedule_items'],
        'company_meta' => $companyMeta,
        'validation' => [
            'current_balance_difference' => round((float) (($data['total_assets'] ?? $data['total'] ?? 0) - ($data['total_liabilities'] ?? $data['total'] ?? 0)), 2),
            'previous_balance_difference' => round((float) (($data['prev_total_assets'] ?? $data['prev_total'] ?? 0) - ($data['prev_total_liabilities'] ?? $data['prev_total'] ?? 0)), 2),
            'parent_group_conflicts' => $classified['validation']['parent_group_conflicts'] ?? [],
            'note_completeness' => buildNoteCompletenessAudit($entity, $notes['sections'] ?? [], $subcategory),
        ],
        'has_data' => array_sum(array_map(static fn ($value) => abs((float) $value), $classified['summary'])) > 0,
        'is_first_year' => $isFirstYear,
        'previous_fy_id' => $prevFYId,
    ];
}
