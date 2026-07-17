<?php

require_once __DIR__ . '/../engines/classification_engine.php';
require_once __DIR__ . '/fy_closure_helper.php';
require_once __DIR__ . '/figure_helper.php';
require_once __DIR__ . '/report_manual_helper.php';

function validateReportGeneration(PDO $pdo, int $company_id, int $fy_id, array $fs): array
{
    $errors = [];
    $warnings = [];

    $data = $fs['data'] ?? [];
    $notes = $fs['notes'] ?? [];

    /* Uses the fully-built statement totals (post note-building, profit already
       folded into Reserves & Surplus) -- NOT $summary['assets_total']/['liabilities_total'],
       which are classification_engine.php's raw, intermediate bucket totals computed
       before that fold-in and will misreport a "difference" equal to the year's
       profit/loss even when the actual Balance Sheet balances. */
    $currentDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);
    $previousDiff = (float) ($fs['validation']['previous_balance_difference'] ?? 0);
    if (abs($currentDiff) > 0.01) {
        $assetsTotal = (float) ($data['total_assets'] ?? 0);
        $liabilitiesTotal = (float) ($data['total_liabilities'] ?? 0);
        $errors[] = [
            'check' => 'assets_equals_liabilities',
            'message' => 'Balance Sheet does not balance. Assets (₹' . format_inr_number($assetsTotal)
                . ') ≠ Liabilities (₹' . format_inr_number($liabilitiesTotal)
                . '). Difference: ₹' . format_inr_number($currentDiff) . '.',
        ];
    }

    if (!empty($fs['validation']['parent_group_conflicts'])) {
        $conflictCount = count($fs['validation']['parent_group_conflicts']);
        $errors[] = [
            'check' => 'parent_group_conflicts',
            'message' => $conflictCount . ' parent group validation conflict(s) found. '
                . 'Ledgers with conflicts are excluded from classification.',
        ];
    }

    /* $data['mapping_completed'] was never actually set anywhere in fs_engine.php --
       that key only exists on the unrelated workflow_status DB table -- so this check
       always fired regardless of real mapping state. Query the real unmapped count
       instead (same definition used elsewhere, e.g. ajax_mapping_save.php's pending
       count and bs_diagnostics_helper.php's unmapped-ledgers issue). */
    $unmappedCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM tally_ledgers tl
        LEFT JOIN ledger_mapping lm
            ON lm.company_id = tl.company_id AND lm.ledger_name = tl.ledger_name
        WHERE tl.company_id = ? AND tl.fy_id = ?
          AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
    ");
    $unmappedCountStmt->execute([$company_id, $fy_id]);
    $unmappedLedgerCount = (int) $unmappedCountStmt->fetchColumn();
    if ($unmappedLedgerCount > 0) {
        $errors[] = [
            'check' => 'mapping_completeness',
            'message' => $unmappedLedgerCount . ' ledger(s) have no note mapping and are excluded from the Balance Sheet.',
        ];
    }

    $noteComplete = $notes['validation']['note_completeness'] ?? $fs['validation']['note_completeness'] ?? [];
    if (isset($noteComplete['is_complete']) && !$noteComplete['is_complete']) {
        $missing = $noteComplete['missing'] ?? [];
        $warnings[] = [
            'check' => 'note_completeness',
            'message' => (count($missing) > 0
                ? 'Missing note sections: ' . implode(', ', array_map('htmlspecialchars', $missing))
                : 'Some expected note sections are missing.'),
        ];
    }

    if (abs($previousDiff) > 0.01) {
        $warnings[] = [
            'check' => 'previous_year_reconciliation',
            'message' => 'Previous year balance difference: ₹' . format_inr_number($previousDiff)
                . '. Opening balances may not reconcile with note totals.',
        ];
    }

    $isFirstYear = (bool) ($fs['is_first_year'] ?? false);
    if (!$isFirstYear) {
        $prevFYId = getPreviousFYId($pdo, $company_id, $fy_id);
        if ($prevFYId !== null) {
            $prevStatus = getFYStatus($pdo, $company_id, $prevFYId);
            if ($prevStatus !== 'closed') {
                $warnings[] = [
                    'check' => 'previous_year_not_closed',
                    'message' => 'Previous financial year is not closed. Comparative figures may not reflect finalized balances.',
                ];
            }
        }
    }

    /* Manual Override warning */
    $manualOverrides = getManualOverrides($pdo, $company_id);
    if (!empty($manualOverrides)) {
        $overrideCount = count($manualOverrides);
        $warnings[] = [
            'check' => 'manual_overrides_included',
            'message' => $overrideCount . ' ledger(s) included by manual override — review retained. '
                . 'Eliminate on consolidation where applicable.',
        ];
    }

    /* Branch / Divisions standalone warning */
    foreach (($notes['sections'] ?? []) as $section) {
        if (($section['custom_type'] ?? '') === 'branch_divisions') {
            $branchNet = (float) ($section['branch_div_net'] ?? 0);
            if ($branchNet != 0.0) {
                $warnings[] = [
                    'check' => 'branch_divisions_standalone',
                    'message' => 'Branch / Division balance of ₹' . format_inr_number($branchNet)
                        . ' included in standalone Balance Sheet — eliminate on consolidation.',
                ];
            }
            break;
        }
    }

    /* Company statutory details warning */
    $companyMeta = $fs['company_meta'] ?? [];
    $hasCin = !empty(trim((string) ($companyMeta['cin'] ?? '')));
    $hasAddress = !empty(trim((string) ($companyMeta['registered_address'] ?? '')));
    if (!$hasCin || !$hasAddress) {
        $missing = [];
        if (!$hasCin) $missing[] = 'CIN';
        if (!$hasAddress) $missing[] = 'Registered Office';
        $warnings[] = [
            'check' => 'company_statutory_details',
            'message' => 'Company statutory details incomplete: ' . implode(' / ', $missing) . ' not configured.',
        ];
    }

    /* Current / Non-Current maturity mismatch warning.
       Schedule III requires bifurcating borrowings by the 12-month rule, but
       classification here is purely whichever schedule code a preparer (or
       Tally's own group) assigned -- there is no actual maturity/date check
       anywhere in the app. This is a best-effort heuristic on ledger naming
       (mirrors isProfitLossLedgerName()'s approach) to catch the common case
       of a term loan sitting in Short-Term Borrowings or a cash-credit/OD
       facility sitting in Long-Term Borrowings -- advisory only, never
       blocking, since only the preparer can confirm actual maturity. */
    foreach (detectBorrowingMaturityMismatches($notes) as $mismatch) {
        $warnings[] = $mismatch;
    }

    /* Opening/closing pair gap warning (P1.1). A real incident this session:
       Note 24's opening Stock-in-Trade was entered but Closing was left
       blank -- Closing has no carry-forward fallback (unlike Opening, which
       inherits last year's Closing), so it silently defaulted to ₹0 and
       created a ₹24,537 phantom P&L movement with no matching Balance Sheet
       entry. Same risk shape exists for Note 24's other components, Note 16
       raw materials, and Note 2's P&L opening/closing. */
    if (($fs['entity_category'] ?? '') === 'corporate') {
        $fyLabel = (string) ($data['date'] ?? '');
        if ($fyLabel !== '') {
            $manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyLabel);
            foreach (detectOpeningClosingPairGaps($manualBundle) as $gap) {
                $warnings[] = $gap;
            }
        }

        /* Fixed Asset Register / Depreciation Schedule checks (Schedule II). */
        require_once __DIR__ . '/fixed_asset_helper.php';
        foreach (detectFixedAssetRegisterIssues($pdo, $company_id, $fy_id, getClassifiedData($pdo, $company_id, $fy_id)) as $issue) {
            $warnings[] = $issue;
        }
    }

    return [
        'can_generate' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'total_checks' => 11,
    ];
}

function detectBorrowingMaturityMismatches(array $notes): array
{
    /* "term loan" etc. is a much stronger structural signal than "working
       capital"/"cash credit" wording -- real facilities like a GECL "Working
       Capital Term Loan" are genuinely long-term despite the short-term-
       sounding product name, so a long-term keyword match always wins over
       a short-term one on the same ledger name to avoid flagging those. */
    $longTermKeywords = ['term loan', 'vehicle loan', 'car loan', 'machinery loan', 'equipment loan', 'housing loan', 'mortgage', 'debenture', 'hire purchase'];
    $shortTermKeywords = ['cash credit', 'overdraft', 'bank od', 'od a/c', 'od limit', 'cc limit', 'cc a/c', 'working capital'];

    $warnings = [];
    foreach (($notes['sections'] ?? []) as $section) {
        $masterCode = (string) ($section['master_code'] ?? '');
        if ($masterCode !== 'BOR' && $masterCode !== 'STB') {
            continue;
        }

        $noteLabel = $masterCode === 'STB' ? 'Short-Term Borrowings' : 'Long-Term Borrowings';
        $suggestedLabel = $masterCode === 'STB' ? 'Long-Term Borrowings' : 'Short-Term Borrowings';

        foreach (($section['lines'] ?? []) as $line) {
            $label = strtolower(trim((string) ($line['label'] ?? '')));
            if ($label === '') {
                continue;
            }

            $hasLongTermSignal = false;
            foreach ($longTermKeywords as $keyword) {
                if (strpos($label, $keyword) !== false) {
                    $hasLongTermSignal = true;
                    break;
                }
            }

            if ($masterCode === 'STB') {
                if ($hasLongTermSignal) {
                    $warnings[] = [
                        'check' => 'borrowing_maturity_mismatch',
                        'message' => 'Ledger "' . htmlspecialchars((string) ($line['label'] ?? '')) . '" is classified under '
                            . $noteLabel . ' but its name suggests it may belong under ' . $suggestedLabel
                            . ' -- verify the current/non-current split against actual maturity (Schedule III, 12-month rule).',
                    ];
                }
                continue;
            }

            // masterCode === 'BOR': only flag a short-term signal when there's no competing long-term signal
            if ($hasLongTermSignal) {
                continue;
            }
            foreach ($shortTermKeywords as $keyword) {
                if (strpos($label, $keyword) !== false) {
                    $warnings[] = [
                        'check' => 'borrowing_maturity_mismatch',
                        'message' => 'Ledger "' . htmlspecialchars((string) ($line['label'] ?? '')) . '" is classified under '
                            . $noteLabel . ' but its name suggests it may belong under ' . $suggestedLabel
                            . ' -- verify the current/non-current split against actual maturity (Schedule III, 12-month rule).',
                    ];
                    break;
                }
            }
        }
    }

    return $warnings;
}

/**
 * Flags an opening/closing manual-input pair where Opening has an effective
 * (possibly carried-forward) nonzero value but Closing was never entered for
 * the current year. Closing figures have no carry-forward fallback anywhere
 * in fs_engine.php -- they always default to 0 -- so an unentered Closing
 * silently manufactures a full-opening-balance "movement" in the P&L with
 * nothing on the Balance Sheet to match it. Non-blocking: a genuinely nil
 * closing balance is a valid real-world position, this only flags the
 * ambiguous case of "never entered" so the CA can confirm it either way.
 */
function detectOpeningClosingPairGaps(array $manualBundle): array
{
    $pairs = [
        ['open' => 'note24_opening_finished_goods', 'close' => 'note24_closing_finished_goods', 'label' => 'Finished Goods (Note 24 -- Changes in Inventories)'],
        ['open' => 'note24_opening_work_in_progress', 'close' => 'note24_closing_work_in_progress', 'label' => 'Work-in-Progress (Note 24 -- Changes in Inventories)'],
        ['open' => 'note24_opening_stock_in_trade', 'close' => 'note24_closing_stock_in_trade', 'label' => 'Stock-in-Trade (Note 24 -- Changes in Inventories)'],
        ['open' => 'note16_opening_raw_materials', 'close' => 'note16_closing_raw_materials', 'label' => 'Raw Materials (Note 16 / Cost of Materials Consumed)'],
        ['open' => 'note2_opening_profit_loss', 'close' => 'note2_closing_profit_loss', 'label' => 'Profit and Loss Account balance (Note 2 -- Reserves & Surplus)'],
    ];

    $warnings = [];
    foreach ($pairs as $pair) {
        $savedCurrent = $manualBundle['saved_current'] ?? [];
        $previous = $manualBundle['previous'] ?? [];

        $openingCurrent = trim((string) ($savedCurrent[$pair['open']] ?? ''));
        $openingCarriedForward = trim((string) ($previous[$pair['close']] ?? ''));
        $effectiveOpening = $openingCurrent !== '' ? (float) $openingCurrent : (float) $openingCarriedForward;

        $closingCurrent = trim((string) ($savedCurrent[$pair['close']] ?? ''));

        if ($effectiveOpening != 0.0 && $closingCurrent === '') {
            $warnings[] = [
                'check' => 'opening_closing_pair_gap',
                'message' => $pair['label'] . ' has an opening balance of ₹' . format_inr_number($effectiveOpening)
                    . ' but no Closing figure has been entered for this year. Closing defaults to ₹0 when left blank, which '
                    . 'silently treats the entire opening balance as consumed/utilised during the year. Enter the actual '
                    . 'Closing figure (even if genuinely nil) to confirm this is correct.',
            ];
        }
    }

    return $warnings;
}

/**
 * Fixed Asset Register / Depreciation Schedule checks (Schedule II).
 * Non-blocking -- these are all things a CA should review, not conditions
 * that should stop statement generation, since the register is an optional
 * enhancement over the flat TB "Depreciation" ledger figure.
 */
function detectFixedAssetRegisterIssues(PDO $pdo, int $company_id, int $fy_id, array $classified): array
{
    $assets = getFixedAssets($pdo, $company_id, $fy_id);
    if ($assets === []) {
        return [];
    }

    $warnings = [];

    foreach ($assets as $asset) {
        $label = trim((string) ($asset['asset_description'] ?? '')) ?: ('Asset #' . ($asset['id'] ?? '?'));

        /* 1. Residual value exceeding 5% of cost -- Schedule II guidance
              (carried over from the pre-2013 Companies Act practice, still
              commonly applied) treats 5% as the ordinary ceiling; a higher
              residual should be a deliberate, documented management estimate,
              not a default left unexamined. */
        $residualPct = (float) ($asset['residual_value_pct'] ?? 5);
        if ($residualPct > 5.0) {
            $warnings[] = [
                'check' => 'fixed_asset_residual_value_high',
                'message' => "\"{$label}\": residual value is set to " . number_format($residualPct, 2)
                    . '% of cost, above the 5% ordinarily expected under Schedule II guidance -- confirm this is a deliberate, documented management estimate.',
            ];
        }

        /* 2. Missing category means no sensible useful-life default and no
              way to group this asset into a Schedule III class in the note. */
        if (trim((string) ($asset['asset_category'] ?? '')) === '') {
            $warnings[] = [
                'check' => 'fixed_asset_missing_category',
                'message' => "\"{$label}\": no asset category assigned yet -- classify it in the Asset Register so a Schedule II useful life can be applied and it groups correctly in the Fixed Assets note.",
            ];
        }

        /* 3. A disposed asset with no disposal date can't be pro-rated --
              computeAssetDepreciation() falls back to treating it as held
              the whole year in that case, which is very likely wrong for
              an asset flagged as disposed. */
        if (!empty($asset['is_disposed']) && trim((string) ($asset['disposal_date'] ?? '')) === '') {
            $warnings[] = [
                'check' => 'fixed_asset_disposed_missing_date',
                'message' => "\"{$label}\" is marked as disposed but has no disposal date -- depreciation is being calculated as if it were held for the full year. Enter the disposal date for an accurate pro-rata charge.",
            ];
        }
    }

    /* 4. Reconcile Excel-imported opening balances against what Tally's
          current-year TB actually reports for classified PPE/CWIP ledgers.
          A mismatch commonly means the uploaded schedule's opening figures
          don't correspond to the same asset base Tally is now tracking
          (assets since disposed and removed from Tally, ledgers renamed,
          or the accumulated depreciation in the upload not matching what
          Tally's own books show) -- worth flagging even though the two
          are legitimately allowed to differ (Tally often has no formal
          accumulated-depreciation ledger at all). */
    $excelImportedOpeningNet = 0.0;
    $hasExcelImport = false;
    foreach ($assets as $asset) {
        if (($asset['source'] ?? '') === 'excel_import') {
            $hasExcelImport = true;
            $excelImportedOpeningNet += (float) ($asset['opening_gross_block'] ?? 0) - (float) ($asset['opening_accumulated_depreciation'] ?? 0);
        }
    }
    if ($hasExcelImport) {
        $tallyPreviousPpeNet = classifiedPreviousAmount($classified, 'ppe') + classifiedPreviousAmount($classified, 'cwip');
        $difference = $excelImportedOpeningNet - $tallyPreviousPpeNet;
        if (abs($difference) > 1000 && $tallyPreviousPpeNet != 0.0) {
            $warnings[] = [
                'check' => 'fixed_asset_excel_reconciliation',
                'message' => 'Excel-uploaded opening net block (₹' . format_inr_number($excelImportedOpeningNet)
                    . ') does not match Tally\'s previous-year closing PPE/CWIP balance (₹' . format_inr_number($tallyPreviousPpeNet)
                    . ', difference ₹' . format_inr_number($difference) . '). This can be legitimate (Tally may not separately '
                    . 'track accumulated depreciation), but verify the uploaded schedule corresponds to the same asset base before relying on it.',
            ];
        }
    }

    return $warnings;
}
