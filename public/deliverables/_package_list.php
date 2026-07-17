<?php
/**
 * Client Package List - Sprint 4D
 * Entity-aware document list for client deliverables
 * Expects: $entityCategory, $entitySubcategory, $hasReportData, $workflow, $fs
 */
if (!isset($entityCategory)) return;

$docs = [];
$pkgName = '';

if ($entityCategory === 'corporate') {
    $pkgName = 'Corporate Annual Financial Statements';
    $docs = [
        ['name' => 'Balance Sheet', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Statement of Profit & Loss', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Cash Flow Statement', 'ready' => $hasReportData && !empty($fs['summary']['cash_flow_total']), 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Notes to Accounts', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Directors Report', 'ready' => ($workflow['directors_report_prepared'] ?? 0) == 1, 'formats' => ['pdf', 'docx'], 'fix_url' => BASE_URL . 'directors_report.php', 'fix_label' => 'Go to Directors Report'],
    ];
} elseif ($entitySubcategory === 'llp') {
    $pkgName = 'LLP Annual Financial Statements';
    $docs = [
        ['name' => 'Balance Sheet', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Trading Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Profit & Loss Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Notes to Accounts', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Partners Capital Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
    ];
} elseif (in_array($entitySubcategory, ['trust', 'society'], true)) {
    $pkgName = ($entitySubcategory === 'trust' ? 'Trust' : 'Society') . ' Annual Financial Statements';
    $docs = [
        ['name' => 'Balance Sheet', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Income & Expenditure Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Receipts & Payments Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Notes to Accounts', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
    ];
} else {
    $pkgName = ($entitySubcategory === 'partnership' ? 'Partnership' : 'Proprietorship') . ' Financial Statements';
    $docs = [
        ['name' => 'Balance Sheet', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Trading Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Profit & Loss Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Notes to Accounts', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
        ['name' => 'Capital Account', 'ready' => $hasReportData, 'formats' => ['pdf', 'docx', 'xlsx']],
    ];
}

/* Management Observations Report -- applies to every entity type (findings
   detection isn't corporate-specific), and is deliberately independent of
   $hasReportData/statement readiness: it exists partly to disclose an
   unbalanced Balance Sheet, so it must stay offerable even then. "Ready"
   here means the CA has triaged every auto-detected finding (none left
   pending), not that anything was necessarily included -- an empty report
   is a valid, reviewed position. */
require_once __DIR__ . '/../../app/helpers/management_findings_helper.php';
$pendingFindingsCount = count(getManagementFindings($pdo, $company_id, $fy_id, 'pending_review'));
$docs[] = [
    'name' => 'Management Observations Report',
    'ready' => $pendingFindingsCount === 0,
    'formats' => ['pdf', 'docx'],
    'fix_url' => BASE_URL . 'findings_recommendations.php',
    'fix_label' => $pendingFindingsCount > 0 ? ($pendingFindingsCount . ' finding(s) awaiting review') : 'Review Findings',
];

$readyCount = count(array_filter($docs, fn($d) => $d['ready']));
$totalCount = count($docs);
?>

<div class="dw-pkg-title"><?= htmlspecialchars($pkgName) ?></div>

<?php foreach ($docs as $doc): ?>
<?php
$docParam = $doc['name'] === 'Directors Report' ? 'directors_report'
    : ($doc['name'] === 'Management Observations Report' ? 'management_observations' : 'financial_statements');
$fixUrl = $doc['fix_url'] ?? (BASE_URL . 'reports.php#balance-sheet');
$fixLabel = $doc['fix_label'] ?? 'Go to Financial Statements';
?>
<div class="dw-doc-item">
    <span class="dw-doc-icon <?= $doc['ready'] ? 'ready' : 'not-ready' ?>"><?= $doc['ready'] ? '&#10003;' : '&#10007;' ?></span>
    <div style="flex:1;">
        <span class="dw-doc-name"><?= htmlspecialchars($doc['name']) ?></span>
        <?php if (!$doc['ready']): ?>
        <div><a href="<?= htmlspecialchars($fixUrl) ?>" style="font-size:0.75rem;">&rarr; <?= htmlspecialchars($fixLabel) ?></a></div>
        <?php endif; ?>
    </div>
    <div class="dw-doc-formats">
        <?php foreach ($doc['formats'] as $fmt): ?>
        <?php if ($doc['ready']): ?>
        <a href="<?= BASE_URL ?>report_download.php?doc=<?= $docParam ?>&format=<?= $fmt ?>" class="dw-format-btn" title="Download <?= strtoupper($fmt) ?>"><?= strtoupper($fmt) ?></a>
        <?php else: ?>
        <span class="dw-format-btn disabled" title="Not ready" style="opacity:0.4;cursor:not-allowed;"><?= strtoupper($fmt) ?></span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div style="margin-top:12px;font-size:0.8rem;color:var(--muted,#6b7280);">
    <?= $readyCount ?>/<?= $totalCount ?> documents ready
    <?php if ($readyCount < $totalCount): ?>
    &middot; <?= $totalCount - $readyCount ?> document(s) not yet available
    <?php endif; ?>
</div>
