<?php
/**
 * Validation Centre - Sprint 4C
 * Runs all validation checks and renders grouped results
 * Expects: $pdo, $company_id, $fy_id, $fs, $entityCategory, $entitySubcategory, $companyMeta, $workflow
 */
if (!isset($fs)) return;

$checks = [];

// --- Category 1: Data Integrity ---
$tbDiff = abs((float)($fs['validation']['current_balance_difference'] ?? 0));
$checks[] = [
    'key' => 'tb_balanced', 'category' => 'Data Integrity', 'severity' => 'error',
    'impact' => 'financial', 'impact_value' => $tbDiff,
    'passed' => $tbDiff <= 0.01,
    'message' => 'Trial Balance is not balanced',
    'detail' => $tbDiff > 0.01 ? 'Difference: ' . number_format($tbDiff, 2) : '',
    'action_url' => BASE_URL . 'data/trial_balance.php', 'action_label' => 'Go to Data'
];
$conflicts = $fs['validation']['parent_group_conflicts'] ?? [];
$checks[] = [
    'key' => 'mapping_complete', 'category' => 'Data Integrity', 'severity' => 'error',
    'impact' => 'disclosure', 'impact_value' => 0,
    'passed' => ($fs['data']['mapping_completed'] ?? false),
    'message' => 'Some ledgers are not mapped to Schedule codes',
    'detail' => '', 'action_url' => BASE_URL . 'data/mapping.php', 'action_label' => 'Go to Mapping'
];
$checks[] = [
    'key' => 'parent_group_conflicts', 'category' => 'Data Integrity', 'severity' => 'error',
    'impact' => 'financial', 'impact_value' => 0,
    'passed' => empty($conflicts),
    'message' => 'Schedule code conflicts found in mapping',
    'detail' => count($conflicts) . ' conflict(s)',
    'action_url' => BASE_URL . 'data/mapping.php', 'action_label' => 'Go to Mapping'
];

// --- Category 2: Financial Statements ---
$currentDiff = abs((float)($fs['validation']['current_balance_difference'] ?? 0));
$previousDiff = abs((float)($fs['validation']['previous_balance_difference'] ?? 0));
$noteComplete = $fs['validation']['note_completeness'] ?? ['is_complete' => true, 'missing' => []];
$checks[] = [
    'key' => 'bs_identity', 'category' => 'Financial Statements', 'severity' => 'error',
    'impact' => 'financial', 'impact_value' => $currentDiff,
    'passed' => $currentDiff <= 0.01,
    'message' => 'Balance Sheet does not balance',
    'detail' => $currentDiff > 0.01 ? 'Difference: Rs. ' . number_format($currentDiff, 2) : '',
    'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
];
$checks[] = [
    'key' => 'notes_complete', 'category' => 'Financial Statements', 'severity' => 'warning',
    'impact' => 'disclosure', 'impact_value' => 0,
    'passed' => ($noteComplete['is_complete'] ?? true),
    'message' => 'Some expected notes are missing',
    'detail' => count($noteComplete['missing'] ?? []) . ' missing: ' . implode(', ', array_slice($noteComplete['missing'] ?? [], 0, 3)),
    'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
];
$checks[] = [
    'key' => 'current_year_recon', 'category' => 'Financial Statements', 'severity' => 'warning',
    'impact' => 'financial', 'impact_value' => $currentDiff,
    'passed' => $currentDiff <= 0.01,
    'message' => 'Current year reconciliation difference detected',
    'detail' => $currentDiff > 0.01 ? 'Diff: Rs. ' . number_format($currentDiff, 2) : '',
    'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
];
$checks[] = [
    'key' => 'previous_year_recon', 'category' => 'Financial Statements', 'severity' => 'warning',
    'impact' => 'financial', 'impact_value' => $previousDiff,
    'passed' => $previousDiff <= 0.01 || ($fs['is_first_year'] ?? true),
    'message' => 'Previous year reconciliation difference detected',
    'detail' => $previousDiff > 0.01 ? 'Diff: Rs. ' . number_format($previousDiff, 2) : '',
    'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
];
$prevFYClosed = true;
if (!($fs['is_first_year'] ?? true)) {
    require_once __DIR__ . '/../../app/helpers/financial_year_helper.php';
    $prevFyId = getPreviousFYId($pdo, $fy_id);
    if ($prevFyId) {
        $prevStatus = getFYStatus($pdo, $prevFyId);
        $prevFYClosed = ($prevStatus === 'closed');
    }
}
$checks[] = [
    'key' => 'previous_fy_closed', 'category' => 'Financial Statements', 'severity' => 'warning',
    'impact' => 'informational', 'impact_value' => 0,
    'passed' => $prevFYClosed,
    'message' => 'Previous financial year is not closed',
    'detail' => '', 'action_url' => BASE_URL . 'data_console/fy_closure.php', 'action_label' => 'Go to FY Closure'
];

// --- Category 3-6: Entity-Specific ---
if ($entityCategory === 'corporate') {
    $cm = $companyMeta ?? [];
    $checks[] = [
        'key' => 'share_capital_entered', 'category' => 'Corporate Requirements', 'severity' => 'warning',
        'impact' => 'disclosure', 'impact_value' => 0,
        'passed' => !empty($fs['summary']['share_capital']),
        'message' => 'Authorised share capital not entered',
        'detail' => '', 'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
    ];
    $checks[] = [
        'key' => 'dr_complete', 'category' => 'Corporate Requirements', 'severity' => 'warning',
        'impact' => 'disclosure', 'impact_value' => 0,
        'passed' => ($workflow['directors_report_prepared'] ?? 0) == 1,
        'message' => 'Directors Report not prepared',
        'detail' => '', 'action_url' => BASE_URL . 'directors_report.php', 'action_label' => 'Go to DR'
    ];
    $checks[] = [
        'key' => 'auditor_details', 'category' => 'Corporate Requirements', 'severity' => 'info',
        'impact' => 'informational', 'impact_value' => 0,
        'passed' => !empty($cm['statutory_auditor_name']),
        'message' => 'Statutory auditor details not filled',
        'detail' => '', 'action_url' => BASE_URL . 'company_dashboard/company_edit.php', 'action_label' => 'Edit Company'
    ];
    $checks[] = [
        'key' => 'signatory_details', 'category' => 'Corporate Requirements', 'severity' => 'info',
        'impact' => 'informational', 'impact_value' => 0,
        'passed' => !empty($cm['signatory_1_name']),
        'message' => 'Director signatory details not filled',
        'detail' => '', 'action_url' => BASE_URL . 'company_dashboard/company_edit.php', 'action_label' => 'Edit Company'
    ];
    $checks[] = [
        'key' => 'cash_flow_present', 'category' => 'Corporate Requirements', 'severity' => 'warning',
        'impact' => 'disclosure', 'impact_value' => 0,
        'passed' => !empty($fs['summary']['cash_flow_total']),
        'message' => 'Cash Flow statement not prepared',
        'detail' => '', 'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
    ];
} elseif ($entitySubcategory === 'llp') {
    $checks[] = [
        'key' => 'partner_capital', 'category' => 'LLP Requirements', 'severity' => 'warning',
        'impact' => 'disclosure', 'impact_value' => 0,
        'passed' => !empty($fs['summary']['capital']),
        'message' => 'Partner capital accounts not populated',
        'detail' => '', 'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
    ];
    $checks[] = [
        'key' => 'partner_designated', 'category' => 'LLP Requirements', 'severity' => 'info',
        'impact' => 'informational', 'impact_value' => 0,
        'passed' => !empty(($companyMeta ?? [])['signatory_1_name']),
        'message' => 'Designated Partner details not filled',
        'detail' => '', 'action_url' => BASE_URL . 'company_dashboard/company_edit.php', 'action_label' => 'Edit Company'
    ];
} elseif (in_array($entitySubcategory, ['trust', 'society'], true)) {
    $checks[] = [
        'key' => 'capital_fund', 'category' => 'Trust/Society Requirements', 'severity' => 'warning',
        'impact' => 'disclosure', 'impact_value' => 0,
        'passed' => !empty($fs['summary']['capital']),
        'message' => 'Capital Fund not populated',
        'detail' => '', 'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
    ];
    $checks[] = [
        'key' => 'trustee_details', 'category' => 'Trust/Society Requirements', 'severity' => 'info',
        'impact' => 'informational', 'impact_value' => 0,
        'passed' => !empty(($companyMeta ?? [])['signatory_1_name']),
        'message' => 'Trustee/office bearer details not filled',
        'detail' => '', 'action_url' => BASE_URL . 'company_dashboard/company_edit.php', 'action_label' => 'Edit Company'
    ];
} else {
    $checks[] = [
        'key' => 'capital_account', 'category' => 'Entity Requirements', 'severity' => 'warning',
        'impact' => 'disclosure', 'impact_value' => 0,
        'passed' => !empty($fs['summary']['capital']),
        'message' => 'Capital Account not populated',
        'detail' => '', 'action_url' => BASE_URL . 'statements/financials.php', 'action_label' => 'Go to Financials'
    ];
}

// --- Category 7: Completeness ---
$checks[] = [
    'key' => 'reports_generated', 'category' => 'Completeness', 'severity' => 'info',
    'impact' => 'informational', 'impact_value' => 0,
    'passed' => ($workflow['reports_generated'] ?? 0) == 1,
    'message' => 'Reports not yet exported',
    'detail' => '', 'action_url' => BASE_URL . 'deliverables/', 'action_label' => 'Go to Deliverables'
];

// --- Compute counts ---
$errorCount = 0; $warningCount = 0; $infoCount = 0; $passedCount = 0;
foreach ($checks as $c) {
    if ($c['passed']) { $passedCount++; continue; }
    if ($c['severity'] === 'error') $errorCount++;
    elseif ($c['severity'] === 'warning') $warningCount++;
    else $infoCount++;
}

// --- Group by category ---
$grouped = [];
foreach ($checks as $c) { $grouped[$c['category']][] = $c; }
?>

<div class="rw-validation-summary">
    <?php if ($errorCount > 0): ?><span class="rw-v-badge error"><?= $errorCount ?> Error<?= $errorCount !== 1 ? 's' : '' ?></span><?php endif; ?>
    <?php if ($warningCount > 0): ?><span class="rw-v-badge warning"><?= $warningCount ?> Warning<?= $warningCount !== 1 ? 's' : '' ?></span><?php endif; ?>
    <?php if ($infoCount > 0): ?><span class="rw-v-badge info"><?= $infoCount ?> Info</span><?php endif; ?>
    <span class="rw-v-badge passed"><?= $passedCount ?> Passed</span>
</div>

<?php foreach ($grouped as $catName => $catChecks): ?>
<?php
    $catErrors = count(array_filter($catChecks, fn($c) => !$c['passed'] && $c['severity'] === 'error'));
    $catWarnings = count(array_filter($catChecks, fn($c) => !$c['passed'] && $c['severity'] === 'warning'));
    $catTotal = count($catChecks);
    $catFailed = $catErrors + $catWarnings;
?>
<div class="rw-v-category">
    <div class="rw-v-category-header">
        <span><?= htmlspecialchars($catName) ?></span>
        <span class="rw-count"><?= $catFailed ?> issue<?= $catFailed !== 1 ? 's' : '' ?> / <?= $catTotal ?> checks</span>
    </div>
    <div class="rw-v-category-items">
        <?php foreach ($catChecks as $check): ?>
        <?php if ($check['passed']): ?>
        <div class="rw-v-item">
            <span class="rw-icon ok">&#10003;</span>
            <span class="rw-msg"><?= htmlspecialchars($check['message']) ?></span>
        </div>
        <?php else: ?>
        <div class="rw-v-item">
            <span class="rw-icon <?= $check['severity'] === 'error' ? 'err' : ($check['severity'] === 'warning' ? 'warn' : 'info') ?>">
                <?= $check['severity'] === 'error' ? '&#10007;' : ($check['severity'] === 'warning' ? '&#9888;' : '&#8505;') ?>
            </span>
            <div class="rw-msg">
                <?= htmlspecialchars($check['message']) ?>
                <?php if ($check['detail']): ?><div class="rw-impact"><?= htmlspecialchars($check['detail']) ?></div><?php endif; ?>
                <?php if ($check['impact'] === 'financial' && $check['impact_value'] > 0): ?>
                <div class="rw-impact">Financial Impact: Rs. <?= number_format($check['impact_value'], 2) ?></div>
                <?php elseif ($check['impact'] === 'disclosure'): ?>
                <div class="rw-impact">Disclosure Impact</div>
                <?php endif; ?>
            </div>
            <?php if ($check['action_url']): ?>
            <div class="rw-action"><a href="<?= $check['action_url'] ?>"><?= htmlspecialchars($check['action_label']) ?> &rarr;</a></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
