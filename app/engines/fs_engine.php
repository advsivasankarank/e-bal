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

function buildCompanyProfitAfterTax(array $classified, array $manualInputs = [], array $previousManualInputs = [], bool $usePrevious = false): float
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
    $expenses = $sumCodes([
        'purchase_stock',
        'employee_cost',
        'finance_cost',
        'depreciation',
        'other_expenses',
    ]);

    return ($revenue + $otherIncome) - ($materialsConsumed + $expenses + $inventoryChange);
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

function buildOtherEquitySection(array $classified, array $manualInputs, array $previousManualInputs, int $noteNo, string $title): array
{
    $openingFromTally = classifiedPreviousAmount($classified, 'reserves');
    if ($openingFromTally == 0.0) {
        $openingFromTally = classifiedAmount($classified, 'reserves');
    }
    $previousOpening = manualAmount($previousManualInputs, 'note2_opening_profit_loss', 0);
    $previousMovement = buildCompanyProfitAfterTax($classified, $manualInputs, $previousManualInputs, true);
    $previousClosing = manualAmount($previousManualInputs, 'note2_closing_profit_loss', $previousOpening + $previousMovement);

    $currentOpening = manualAmount($manualInputs, 'note2_opening_profit_loss', $openingFromTally !== 0.0 ? $openingFromTally : $previousClosing);
    $currentMovement = buildCompanyProfitAfterTax($classified, $manualInputs, $previousManualInputs, false);
    $currentClosing = $currentOpening + $currentMovement;
    $reserveLines = buildLedgerLines($classified, ['reserves']);
    $reserveCurrent = sumLines($reserveLines, 'current');
    $reservePrevious = sumLines($reserveLines, 'previous');
    $lines = $reserveLines;
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

    return [
        'title' => $title,
        'note_no' => $noteNo,
        'custom_type' => 'other_equity',
        'opening_balance' => $currentOpening,
        'previous_opening_balance' => $previousOpening,
        'movement' => $currentMovement,
        'previous_movement' => $previousMovement,
        'lines' => $lines,
        'current_total' => $reserveCurrent + $currentClosing,
        'previous_total' => $reservePrevious + $previousClosing,
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
    ];
}

function buildNoteIndex(array $sections): array
{
    $index = [];

    foreach ($sections as $section) {
        $title = (string) ($section['title'] ?? '');
        $noteNo = (int) ($section['note_no'] ?? 0);
        if ($title !== '') {
            $index[$title] = $noteNo > 0 ? $noteNo : (count($index) + 1);
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

function buildCompanyNotesPayload(array $classified, array $manualInputs = [], array $previousManualInputs = []): array
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

        if ($masterCode === 'SC') {
            $sections[] = [
                'title' => $title,
                'note_no' => $noteNo,
                'master_code' => $masterCode,
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
            ];
            continue;
        }

        if ($masterCode === 'OE') {
            $section = buildOtherEquitySection(
                $classified,
                $manualInputs,
                $previousManualInputs,
                (int) $noteNo,
                $title
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

        $section = buildDetailedNote($title, buildLedgerLines($classified, $supportMap[$masterCode] ?? []));
        $section['note_no'] = $noteNo;
        $section['master_code'] = $masterCode;
        $sections[] = $section;
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
        'sections' => $sections,
    ];
}

function buildLLPNotesPayload(array $classified): array
{
    return [
        'sections' => [
            buildDetailedNote('Partners Capital', buildLedgerLines($classified, ['share_capital'])),
            buildDetailedNote('Partners Current Account / Reserves', buildLedgerLines($classified, ['reserves'])),
            buildDetailedNote('Borrowings', buildLedgerLines($classified, ['lt_borrowings', 'st_borrowings'])),
            buildDetailedNote('Trade Payables', buildLedgerLines($classified, ['trade_payables', 'trade_payables_msme'])),
            buildDetailedNote('Fixed Assets', buildLedgerLines($classified, ['ppe', 'intangible_assets'])),
            buildDetailedNote('Current Assets', buildLedgerLines($classified, ['inventory', 'receivables', 'cash', 'other_current_assets'])),
            buildDetailedNote('Revenue', buildLedgerLines($classified, ['revenue', 'other_income'])),
            buildDetailedNote('Expenses', buildLedgerLines($classified, ['materials', 'purchase_stock', 'inventory_change', 'employee_cost', 'finance_cost', 'depreciation', 'other_expenses'])),
        ],
    ];
}

function buildNonCorpNotesPayload(array $classified): array
{
    return [
        'sections' => [
            buildDetailedNote('Capital', buildLedgerLines($classified, ['share_capital', 'reserves'])),
            buildDetailedNote('Borrowings', buildLedgerLines($classified, ['lt_borrowings', 'st_borrowings'])),
            buildDetailedNote('Payables', buildLedgerLines($classified, ['trade_payables', 'trade_payables_msme', 'other_current_liabilities', 'other_financial_liabilities'])),
            buildDetailedNote('Fixed Assets', buildLedgerLines($classified, ['ppe', 'intangible_assets', 'other_non_current_assets'])),
            buildDetailedNote('Inventory', buildLedgerLines($classified, ['inventory'])),
            buildDetailedNote('Receivables', buildLedgerLines($classified, ['receivables'])),
            buildDetailedNote('Cash and Bank', buildLedgerLines($classified, ['cash', 'bank_balances_other'])),
            buildDetailedNote('Revenue', buildLedgerLines($classified, ['revenue', 'other_income'])),
            buildDetailedNote('Expenses', buildLedgerLines($classified, ['materials', 'purchase_stock', 'inventory_change', 'employee_cost', 'finance_cost', 'depreciation', 'other_expenses'])),
        ],
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
        'inventory_change' => $sectionTotalsByCode['INVCHG']['current'] ?? ($sectionTotals['Changes in Inventory']['current'] ?? 0),
        'prev_inventory_change' => $sectionTotalsByCode['INVCHG']['previous'] ?? ($sectionTotals['Changes in Inventory']['previous'] ?? 0),
        'employee_cost' => $sectionTotalsByCode['EMP']['current'] ?? ($sectionTotals['Employee Benefits Expense']['current'] ?? 0),
        'prev_employee_cost' => $sectionTotalsByCode['EMP']['previous'] ?? ($sectionTotals['Employee Benefits Expense']['previous'] ?? 0),
        'finance_cost' => $sectionTotalsByCode['FIN']['current'] ?? ($sectionTotals['Finance Cost']['current'] ?? 0),
        'prev_finance_cost' => $sectionTotalsByCode['FIN']['previous'] ?? ($sectionTotals['Finance Cost']['previous'] ?? 0),
        'depreciation' => $sectionTotalsByCode['DEP']['current'] ?? ($sectionTotals['Depreciation & Amortisation']['current'] ?? 0),
        'prev_depreciation' => $sectionTotalsByCode['DEP']['previous'] ?? ($sectionTotals['Depreciation & Amortisation']['previous'] ?? 0),
        'other_expenses' => $sectionTotalsByCode['EXP']['current'] ?? ($sectionTotals['Other Expenses']['current'] ?? 0),
        'prev_other_expenses' => $sectionTotalsByCode['EXP']['previous'] ?? ($sectionTotals['Other Expenses']['previous'] ?? 0),
        'tax' => 0,
        'prev_tax' => 0,
    ];

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
        'borrowings' => ($sectionTotals['Borrowings']['current'] ?? 0) + ($sectionTotals['Trade Payables']['current'] ?? 0),
        'prev_borrowings' => ($sectionTotals['Borrowings']['previous'] ?? 0) + ($sectionTotals['Trade Payables']['previous'] ?? 0),
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
    ];

    $data['pbr'] = $data['revenue'] - $data['expenses'];
    $data['prev_pbr'] = $data['prev_revenue'] - $data['prev_expenses'];
    $data['pat'] = $data['pbr'] - $data['remuneration'];
    $data['prev_pat'] = $data['prev_pbr'] - $data['prev_remuneration'];
    $data['total'] = $data['capital'] + $data['current_accounts'] + $data['borrowings'];
    $data['prev_total'] = $data['prev_capital'] + $data['prev_current_accounts'] + $data['prev_borrowings'];
    $data['note_refs'] = buildNoteIndex($notes['sections'] ?? []);

    return $data;
}

function buildNonCorpSummaryFromNotes(array $classified, array $notes, string $fyLabel): array
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
        'capital' => $sectionTotals['Capital']['current'] ?? 0,
        'prev_capital' => $sectionTotals['Capital']['previous'] ?? 0,
        'reserves' => 0,
        'prev_reserves' => 0,
        'borrowings' => ($sectionTotals['Borrowings']['current'] ?? 0),
        'prev_borrowings' => ($sectionTotals['Borrowings']['previous'] ?? 0),
        'payables' => ($sectionTotals['Payables']['current'] ?? 0),
        'prev_payables' => ($sectionTotals['Payables']['previous'] ?? 0),
        'fixed_assets' => $sectionTotals['Fixed Assets']['current'] ?? 0,
        'prev_fixed_assets' => $sectionTotals['Fixed Assets']['previous'] ?? 0,
        'inventory' => $sectionTotals['Inventory']['current'] ?? 0,
        'prev_inventory' => $sectionTotals['Inventory']['previous'] ?? 0,
        'receivables' => $sectionTotals['Receivables']['current'] ?? 0,
        'prev_receivables' => $sectionTotals['Receivables']['previous'] ?? 0,
        'cash' => $sectionTotals['Cash and Bank']['current'] ?? 0,
        'prev_cash' => $sectionTotals['Cash and Bank']['previous'] ?? 0,
        'revenue' => $sectionTotals['Revenue']['current'] ?? 0,
        'prev_revenue' => $sectionTotals['Revenue']['previous'] ?? 0,
        'other_income' => 0,
        'prev_other_income' => 0,
        'expenses' => $sectionTotals['Expenses']['current'] ?? 0,
        'prev_expenses' => $sectionTotals['Expenses']['previous'] ?? 0,
        'tax' => 0,
        'prev_tax' => 0,
    ];

    $data['pbt'] = $data['revenue'] + $data['other_income'] - $data['expenses'];
    $data['prev_pbt'] = $data['prev_revenue'] + $data['prev_other_income'] - $data['prev_expenses'];
    $data['pat'] = $data['pbt'] - $data['tax'];
    $data['prev_pat'] = $data['prev_pbt'] - $data['prev_tax'];
    $data['total'] = $data['capital'] + $data['reserves'] + $data['borrowings'] + $data['payables'];
    $data['prev_total'] = $data['prev_capital'] + $data['prev_reserves'] + $data['prev_borrowings'] + $data['prev_payables'];
    $data['note_refs'] = buildNoteIndex($notes['sections'] ?? []);

    return $data;
}

function generateFinancialStatements(PDO $pdo, int $company_id, int $fy_id, string $fyLabel = '', array $manualInputs = [], array $previousManualInputs = []): array
{
    $entity = getEntityCategory($pdo, $company_id);
    $classified = getClassifiedData($pdo, $company_id, $fy_id);
    $companyMeta = getCompanyReportingMeta($pdo, $company_id);
    $fyDisplay = $fyLabel !== '' ? $fyLabel : ('FY ' . $fy_id);

    switch ($entity) {
        case 'corporate':
            $notes = buildCompanyNotesPayload($classified, $manualInputs, $previousManualInputs);
            $data = buildCompanySummaryFromNotes($classified, $notes, $fyDisplay);
            $formatTemplate = __DIR__ . '/../../public/reports_dashboard/formats/company_format.php';
            $notesTemplate = __DIR__ . '/../../public/reports_dashboard/formats/notes_company.php';
            $title = 'Schedule III Financial Statements';
            break;

        case 'llp':
            $notes = buildLLPNotesPayload($classified);
            $data = buildLLPSummaryFromNotes($classified, $notes, $fyDisplay);
            $formatTemplate = __DIR__ . '/../../public/reports_dashboard/formats/llp_format.php';
            $notesTemplate = __DIR__ . '/../../public/reports_dashboard/formats/notes_llp.php';
            $title = 'LLP Financial Statements';
            break;

        case 'non_corporate':
        default:
            $notes = buildNonCorpNotesPayload($classified);
            $data = buildNonCorpSummaryFromNotes($classified, $notes, $fyDisplay);
            $formatTemplate = __DIR__ . '/../../public/reports_dashboard/formats/noncorporate_format.php';
            $notesTemplate = __DIR__ . '/../../public/reports_dashboard/formats/notes_noncorp.php';
            $title = 'Non-Corporate Financial Statements';
            break;
    }

    return [
        'title' => $title,
        'entity_category' => $entity,
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
        ],
        'has_data' => array_sum(array_map(static fn ($value) => abs((float) $value), $classified['summary'])) > 0,
    ];
}
