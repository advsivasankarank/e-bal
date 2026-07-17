<?php

require_once __DIR__ . '/../engines/classification_engine.php';
require_once __DIR__ . '/fy_closure_helper.php';
require_once __DIR__ . '/figure_helper.php';

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

    return [
        'can_generate' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'total_checks' => 10,
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
