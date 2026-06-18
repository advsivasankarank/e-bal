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
        ['name' => 'Directors Report', 'ready' => ($workflow['directors_report_prepared'] ?? 0) == 1, 'formats' => ['pdf', 'docx']],
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

$readyCount = count(array_filter($docs, fn($d) => $d['ready']));
$totalCount = count($docs);
?>

<div class="dw-pkg-title"><?= htmlspecialchars($pkgName) ?></div>

<?php foreach ($docs as $doc): ?>
<div class="dw-doc-item">
    <span class="dw-doc-icon <?= $doc['ready'] ? 'ready' : 'not-ready' ?>"><?= $doc['ready'] ? '&#10003;' : '&#10007;' ?></span>
    <span class="dw-doc-name"><?= htmlspecialchars($doc['name']) ?></span>
    <div class="dw-doc-formats">
        <?php foreach ($doc['formats'] as $fmt): ?>
        <a href="<?= BASE_URL ?>report_download.php?format=<?= $fmt ?>" class="dw-format-btn <?= $doc['ready'] ? '' : 'disabled' ?>" title="Download <?= strtoupper($fmt) ?>"><?= strtoupper($fmt) ?></a>
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
