<?php

require_once __DIR__ . '/../engines/classification_engine.php';
require_once __DIR__ . '/fy_closure_helper.php';
require_once __DIR__ . '/figure_helper.php';

function validateReportGeneration(PDO $pdo, int $company_id, int $fy_id, array $fs): array
{
    $errors = [];
    $warnings = [];

    $summary = $fs['summary'] ?? [];
    $data = $fs['data'] ?? [];
    $notes = $fs['notes'] ?? [];
    $scheduleItems = $fs['schedule_items'] ?? [];

    $assetsTotal = (float) ($summary['assets_total'] ?? 0);
    $liabilitiesTotal = (float) ($summary['liabilities_total'] ?? 0);
    $bsDiff = round($assetsTotal - $liabilitiesTotal, 2);
    if (abs($bsDiff) > 0.01) {
        $errors[] = [
            'check' => 'assets_equals_liabilities',
            'message' => 'Balance Sheet does not balance. Assets (₹' . format_inr_number($assetsTotal)
                . ') ≠ Liabilities (₹' . format_inr_number($liabilitiesTotal)
                . '). Difference: ₹' . format_inr_number($bsDiff) . '.',
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

    if (!($data['mapping_completed'] ?? false)) {
        $errors[] = [
            'check' => 'mapping_completeness',
            'message' => 'Ledger mapping is not yet complete. Some ledgers may be unmapped.',
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

    $currentDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);
    $previousDiff = (float) ($fs['validation']['previous_balance_difference'] ?? 0);
    if (abs($currentDiff) > 0.01) {
        $warnings[] = [
            'check' => 'current_year_reconciliation',
            'message' => 'Current year balance difference: ₹' . format_inr_number($currentDiff)
                . '. The trial balance totals may not match the classified note totals.',
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

    return [
        'can_generate' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'total_checks' => 10,
    ];
}
