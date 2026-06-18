<?php
/**
 * e-BAL V2 — Assignment Home
 *
 * Central workspace page for a single assignment.
 * Opens when a user clicks "Continue" on an assignment card.
 *
 * Reads: company_id + fy_id from GET (or session fallback)
 * Queries: companies, financial_years, workflow_status
 * No database changes. No engine changes.
 */
$page_title = 'Assignment Home';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/app/workflow_engine.php';
require_once __DIR__ . '/app/context_check.php';

ensureWorkflowColumns();

/* ---- Resolve assignment context ---- */
$v2CompanyId = (int) ($_GET['company_id'] ?? $_SESSION['company_id'] ?? 0);
$v2FyId      = (int) ($_GET['fy_id']      ?? $_SESSION['fy_id']      ?? 0);

if ($v2CompanyId <= 0 || $v2FyId <= 0) {
    header('Location: ' . BASE_URL . 'my_assignments.php');
    exit;
}

/* Set session context from GET params, then validate ownership */
$_SESSION['company_id'] = $v2CompanyId;
$_SESSION['fy_id'] = $v2FyId;
requireAssignmentAccess();

/* ---- Query assignment data ---- */
$v2Stmt = $pdo->prepare("
    SELECT
        c.id AS company_id,
        c.name AS company_name,
        c.category,
        c.pan,
        c.cin,
        c.llp_code,
        c.registered_address,
        c.state_code,
        fy.id AS fy_id,
        fy.fy_label,
        fy.fy_start,
        fy.fy_end,
        COALESCE(ws.ledger_fetched, 0) AS ledger_fetched,
        COALESCE(ws.mapping_completed, 0) AS mapping_completed,
        COALESCE(ws.tally_fetched, 0) AS tally_fetched,
        COALESCE(ws.notes_prepared, 0) AS notes_prepared,
        COALESCE(ws.profit_loss_prepared, 0) AS profit_loss_prepared,
        COALESCE(ws.balance_sheet_prepared, 0) AS balance_sheet_prepared,
        COALESCE(ws.directors_report_prepared, 0) AS directors_report_prepared,
        COALESCE(ws.verified, 0) AS verified
    FROM companies c
    INNER JOIN financial_years fy ON fy.id = ? AND fy.company_id = c.id
    LEFT JOIN workflow_status ws ON ws.company_id = c.id AND ws.fy_id = fy.id
    WHERE c.id = ?
");
$v2Stmt->execute([$v2FyId, $v2CompanyId]);
$v2Row = $v2Stmt->fetch(PDO::FETCH_ASSOC);

if (!$v2Row) {
    header('Location: ' . BASE_URL . 'my_assignments.php');
    exit;
}

/* ---- Set session context ---- */
$_SESSION['company_id']   = $v2Row['company_id'];
$_SESSION['company_name'] = $v2Row['company_name'];
$_SESSION['fy_id']        = $v2Row['fy_id'];
$_SESSION['fy_name']      = $v2Row['fy_label'];

/* ---- Compute entity flags ---- */
$v2NormCat = strtolower(str_replace(['-', ' '], '_', $v2Row['category']));
$v2IsCorporate     = $v2NormCat === 'corporate';
$v2IsLLP           = $v2NormCat === 'llp';
$v2IsTrust         = $v2NormCat === 'trust';
$v2IsPartnership   = $v2NormCat === 'partnership';
$v2IsProprietorship = $v2NormCat === 'proprietorship';
$v2IsSociety       = $v2NormCat === 'society';
$v2IsNonCorporate  = $v2NormCat === 'non_corporate';

$v2EntityLabel = ucfirst(str_replace(['_', '-'], ' ', $v2NormCat));

/* ---- Derive identifier ---- */
$v2Identifier = '';
$v2IdType = '';
if ($v2IsCorporate && !empty($v2Row['cin'])) {
    $v2Identifier = $v2Row['cin'];
    $v2IdType = 'CIN';
} elseif ($v2IsLLP && !empty($v2Row['llp_code'])) {
    $v2Identifier = $v2Row['llp_code'];
    $v2IdType = 'LLPIN';
} elseif (!empty($v2Row['pan'])) {
    $v2Identifier = $v2Row['pan'];
    $v2IdType = 'PAN';
}

/* ---- Build workflow steps ---- */
$v2Steps = [];
$v2Steps[] = ['key' => 'ledger_fetched',       'label' => 'Data',    'done' => (bool) $v2Row['ledger_fetched'],       'section' => 'data'];
$v2Steps[] = ['key' => 'mapping_completed',     'label' => 'Mapping', 'done' => (bool) $v2Row['mapping_completed'],     'section' => 'data'];
$v2Steps[] = ['key' => 'tally_fetched',         'label' => 'TB',      'done' => (bool) $v2Row['tally_fetched'],         'section' => 'data'];
$v2Steps[] = ['key' => 'notes_prepared',        'label' => 'Notes',   'done' => (bool) $v2Row['notes_prepared'],        'section' => 'financials'];
$v2Steps[] = ['key' => 'profit_loss_prepared',  'label' => 'P&L',     'done' => (bool) $v2Row['profit_loss_prepared'],  'section' => 'financials'];
$v2Steps[] = ['key' => 'balance_sheet_prepared','label' => 'BS',      'done' => (bool) $v2Row['balance_sheet_prepared'],'section' => 'financials'];

if ($v2IsCorporate) {
    $v2Steps[] = ['key' => 'directors_report_prepared', 'label' => 'Directors Report', 'done' => (bool) $v2Row['directors_report_prepared'], 'section' => 'financials'];
}

$v2TotalSteps = count($v2Steps);
$v2CompletedSteps = 0;
foreach ($v2Steps as $s) {
    if ($s['done']) $v2CompletedSteps++;
}
$v2ProgressPct = $v2TotalSteps > 0 ? (int) round($v2CompletedSteps / $v2TotalSteps * 100) : 0;

/* ---- Determine overall status ---- */
$v2Verified = (bool) $v2Row['verified'];
$v2AllDone = $v2CompletedSteps === $v2TotalSteps;

if ($v2Verified) {
    $v2OverallStatus = 'reviewed';
    $v2OverallLabel = 'Reviewed';
} elseif ($v2AllDone) {
    $v2OverallStatus = 'ready_for_review';
    $v2OverallLabel = 'Ready for Review';
} elseif ($v2CompletedSteps === 0) {
    $v2OverallStatus = 'not_started';
    $v2OverallLabel = 'Not Started';
} else {
    $v2OverallStatus = 'in_progress';
    $v2OverallLabel = 'In Progress';
}

/* ---- Find next action ---- */
$v2NextAction = '';
$v2NextSection = '';
$v2LastDoneIdx = -1;
for ($i = 0; $i < $v2TotalSteps; $i++) {
    if ($v2Steps[$i]['done']) $v2LastDoneIdx = $i;
}
$v2NextIdx = $v2LastDoneIdx + 1;
if ($v2NextIdx < $v2TotalSteps) {
    $v2NextAction = $v2Steps[$v2NextIdx]['label'];
    $v2NextSection = $v2Steps[$v2NextIdx]['section'];
} elseif ($v2AllDone) {
    if ($v2Verified) {
        $v2NextAction = 'Export';
        $v2NextSection = 'deliverables';
    } else {
        $v2NextAction = 'Review';
        $v2NextSection = 'review';
    }
}

/* ---- Section status computation ---- */
/* DATA: needs ledger_fetched, mapping_completed, tally_fetched */
$v2DataDone = $v2Row['ledger_fetched'] && $v2Row['mapping_completed'] && $v2Row['tally_fetched'];
$v2DataItems = [
    ['label' => 'Ledgers imported',  'done' => (bool) $v2Row['ledger_fetched']],
    ['label' => 'Mapping complete',  'done' => (bool) $v2Row['mapping_completed']],
    ['label' => 'Trial balance loaded', 'done' => (bool) $v2Row['tally_fetched']],
];
$v2DataCompleted = 0;
foreach ($v2DataItems as $d) { if ($d['done']) $v2DataCompleted++; }

/* FINANCIALS: needs notes, P&L, BS (+ DR for corporate) */
$v2FinItems = [
    ['label' => 'Notes to Accounts', 'done' => (bool) $v2Row['notes_prepared']],
    ['label' => 'Profit & Loss',     'done' => (bool) $v2Row['profit_loss_prepared']],
    ['label' => 'Balance Sheet',     'done' => (bool) $v2Row['balance_sheet_prepared']],
];
if ($v2IsCorporate) {
    $v2FinItems[] = ['label' => 'Directors Report', 'done' => (bool) $v2Row['directors_report_prepared']];
}
$v2FinDone = true;
foreach ($v2FinItems as $f) { if (!$f['done']) $v2FinDone = false; }
$v2FinCompleted = 0;
foreach ($v2FinItems as $f) { if ($f['done']) $v2FinCompleted++; }

/* REVIEW: needs verified */
$v2ReviewDone = $v2Verified;
$v2ReviewItems = [
    ['label' => 'Validation passed', 'done' => $v2DataDone && $v2FinDone],
    ['label' => 'Review complete',   'done' => $v2Verified],
];
$v2ReviewCompleted = 0;
foreach ($v2ReviewItems as $r) { if ($r['done']) $v2ReviewCompleted++; }

/* DELIVERABLES: ready when reviewed */
$v2DeliverDone = $v2Verified;
$v2DeliverItems = [
    ['label' => 'Ready for export', 'done' => $v2Verified],
];
$v2DeliverCompleted = $v2Verified ? 1 : 0;

/* ---- Section statuses ---- */
$v2SectionStatus = [
    'data' => [
        'label' => $v2DataDone ? 'Complete' : ($v2DataCompleted > 0 ? 'In Progress' : 'Not Started'),
        'class' => $v2DataDone ? 'complete' : ($v2DataCompleted > 0 ? 'active' : 'pending'),
    ],
    'financials' => [
        'label' => $v2FinDone ? 'Complete' : ($v2FinCompleted > 0 ? 'In Progress' : 'Not Started'),
        'class' => $v2FinDone ? 'complete' : ($v2FinCompleted > 0 ? 'active' : 'pending'),
    ],
    'review' => [
        'label' => $v2ReviewDone ? 'Complete' : ($v2ReviewCompleted > 0 ? 'In Progress' : 'Not Started'),
        'class' => $v2ReviewDone ? 'complete' : ($v2ReviewCompleted > 0 ? 'active' : 'pending'),
    ],
    'deliverables' => [
        'label' => $v2DeliverDone ? 'Complete' : 'Pending',
        'class' => $v2DeliverDone ? 'complete' : 'pending',
    ],
];
?>

<!-- Context Header -->
<div class="v2-ah-header">
    <div class="v2-ah-header-left">
        <a href="<?= BASE_URL ?>my_assignments.php" class="v2-ah-back">← My Assignments</a>
        <h1 class="v2-ah-company"><?= htmlspecialchars($v2Row['company_name']) ?></h1>
        <div class="v2-ah-meta">
            <span class="v2-ah-tag v2-ah-tag-entity"><?= htmlspecialchars($v2EntityLabel) ?></span>
            <?php if ($v2Identifier): ?>
                <span class="v2-ah-tag v2-amp-tag-id" title="<?= htmlspecialchars($v2IdType . ': ' . $v2Identifier) ?>"><?= htmlspecialchars($v2IdType) ?>: <?= htmlspecialchars($v2Identifier) ?></span>
            <?php endif; ?>
            <span class="v2-ah-tag v2-ah-tag-fy">FY <?= htmlspecialchars($v2Row['fy_label']) ?></span>
        </div>
    </div>
    <div class="v2-ah-header-right">
        <div class="v2-ah-status-pill v2-ah-status-<?= $v2OverallStatus ?>">
            <?= htmlspecialchars($v2OverallLabel) ?>
        </div>
    </div>
</div>

<!-- Progress Bar -->
<div class="v2-ah-progress-section">
    <div class="v2-ah-progress-bar">
        <div class="v2-ah-progress-fill" style="width: <?= $v2ProgressPct ?>%"></div>
    </div>
    <div class="v2-ah-progress-labels">
        <span class="v2-ah-progress-pct"><?= $v2ProgressPct ?>% complete</span>
        <span class="v2-ah-progress-count"><?= $v2CompletedSteps ?> of <?= $v2TotalSteps ?> steps</span>
    </div>
</div>

<!-- Status Summary -->
<div class="v2-ah-summary">
    <div class="v2-ah-summary-col">
        <h3 class="v2-ah-summary-title">Completed</h3>
        <?php if ($v2CompletedSteps === 0): ?>
            <p class="v2-ah-summary-empty">No steps completed yet</p>
        <?php else: ?>
            <ul class="v2-ah-summary-list">
                <?php foreach ($v2Steps as $s): ?>
                    <?php if ($s['done']): ?>
                        <li class="v2-ah-summary-item done">
                            <span class="v2-ah-check">✓</span>
                            <?= htmlspecialchars($s['label']) ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="v2-ah-summary-col">
        <h3 class="v2-ah-summary-title">Pending</h3>
        <?php if ($v2CompletedSteps === $v2TotalSteps): ?>
            <p class="v2-ah-summary-empty">All steps completed</p>
        <?php else: ?>
            <ul class="v2-ah-summary-list">
                <?php foreach ($v2Steps as $s): ?>
                    <?php if (!$s['done']): ?>
                        <li class="v2-ah-summary-item pending">
                            <span class="v2-ah-check">○</span>
                            <?= htmlspecialchars($s['label']) ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="v2-ah-summary-col v2-ah-summary-action">
        <h3 class="v2-ah-summary-title">Next Action</h3>
        <?php if ($v2NextAction): ?>
            <p class="v2-ah-next-label">Complete the <strong><?= htmlspecialchars($v2NextAction) ?></strong> step</p>
            <a href="<?= BASE_URL . $v2NextSection ?>/"
               class="v2-btn v2-btn-primary">
                <?= htmlspecialchars($v2NextAction) ?> →
            </a>
        <?php else: ?>
            <p class="v2-ah-next-label">All steps completed</p>
        <?php endif; ?>
    </div>
</div>

<!-- Section Cards -->
<div class="v2-ah-sections">

    <!-- DATA -->
    <a href="<?= BASE_URL ?>data/" class="v2-ah-section-card">
        <div class="v2-ah-section-icon">📥</div>
        <div class="v2-ah-section-body">
            <h3>Data</h3>
            <p class="v2-ah-section-desc">Import ledgers, map to Schedule III, load trial balance</p>
            <div class="v2-ah-section-items">
                <?php foreach ($v2DataItems as $d): ?>
                    <span class="v2-ah-section-item <?= $d['done'] ? 'done' : 'pending' ?>">
                        <span class="v2-ah-section-check"><?= $d['done'] ? '✓' : '○' ?></span>
                        <?= htmlspecialchars($d['label']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="v2-ah-section-status">
            <span class="v2-ah-section-badge v2-ah-badge-<?= $v2SectionStatus['data']['class'] ?>">
                <?= htmlspecialchars($v2SectionStatus['data']['label']) ?>
            </span>
            <span class="v2-ah-section-arrow">→</span>
        </div>
    </a>

    <!-- FINANCIALS -->
    <a href="<?= BASE_URL ?>statements/" class="v2-ah-section-card">
        <div class="v2-ah-section-icon">📊</div>
        <div class="v2-ah-section-body">
            <h3>Financials</h3>
            <p class="v2-ah-section-desc">
                Balance Sheet, P&L, Notes to Accounts
                <?= $v2IsCorporate ? ', Directors Report' : '' ?>
                <?= $v2IsLLP ? ', Partner Capital Accounts' : '' ?>
            </p>
            <div class="v2-ah-section-items">
                <?php foreach ($v2FinItems as $f): ?>
                    <span class="v2-ah-section-item <?= $f['done'] ? 'done' : 'pending' ?>">
                        <span class="v2-ah-section-check"><?= $f['done'] ? '✓' : '○' ?></span>
                        <?= htmlspecialchars($f['label']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="v2-ah-section-status">
            <span class="v2-ah-section-badge v2-ah-badge-<?= $v2SectionStatus['financials']['class'] ?>">
                <?= htmlspecialchars($v2SectionStatus['financials']['label']) ?>
            </span>
            <span class="v2-ah-section-arrow">→</span>
        </div>
    </a>

    <!-- REVIEW -->
    <a href="<?= BASE_URL ?>review/" class="v2-ah-section-card">
        <div class="v2-ah-section-icon">✅</div>
        <div class="v2-ah-section-body">
            <h3>Review</h3>
            <p class="v2-ah-section-desc">Validate data integrity, reconcile balances</p>
            <div class="v2-ah-section-items">
                <?php foreach ($v2ReviewItems as $r): ?>
                    <span class="v2-ah-section-item <?= $r['done'] ? 'done' : 'pending' ?>">
                        <span class="v2-ah-section-check"><?= $r['done'] ? '✓' : '○' ?></span>
                        <?= htmlspecialchars($r['label']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="v2-ah-section-status">
            <span class="v2-ah-section-badge v2-ah-badge-<?= $v2SectionStatus['review']['class'] ?>">
                <?= htmlspecialchars($v2SectionStatus['review']['label']) ?>
            </span>
            <span class="v2-ah-section-arrow">→</span>
        </div>
    </a>

    <!-- DELIVERABLES -->
    <a href="<?= BASE_URL ?>deliverables/" class="v2-ah-section-card">
        <div class="v2-ah-section-icon">📤</div>
        <div class="v2-ah-section-body">
            <h3>Deliverables</h3>
            <p class="v2-ah-section-desc">Export financial statements in multiple formats</p>
            <div class="v2-ah-section-items">
                <?php foreach ($v2DeliverItems as $dl): ?>
                    <span class="v2-ah-section-item <?= $dl['done'] ? 'done' : 'pending' ?>">
                        <span class="v2-ah-section-check"><?= $dl['done'] ? '✓' : '○' ?></span>
                        <?= htmlspecialchars($dl['label']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="v2-ah-section-status">
            <span class="v2-ah-section-badge v2-ah-badge-<?= $v2SectionStatus['deliverables']['class'] ?>">
                <?= htmlspecialchars($v2SectionStatus['deliverables']['label']) ?>
            </span>
            <span class="v2-ah-section-arrow">→</span>
        </div>
    </a>

</div>

<!-- Entity-Specific Compliance Panel -->
<?php if ($v2IsCorporate || $v2IsLLP || $v2IsTrust || $v2IsSociety): ?>
<div class="v2-ah-compliance">
    <h3 class="v2-ah-compliance-title">
        <?php if ($v2IsCorporate): ?>
            Corporate Compliance
        <?php elseif ($v2IsLLP): ?>
            LLP Compliance
        <?php elseif ($v2IsTrust): ?>
            Trust Compliance
        <?php elseif ($v2IsSociety): ?>
            Society Compliance
        <?php endif; ?>
    </h3>
    <div class="v2-ah-compliance-grid">
        <?php if ($v2IsCorporate): ?>
            <div class="v2-ah-compliance-item <?= $v2Row['directors_report_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['directors_report_prepared'] ? '✓' : '○' ?></span>
                <span>Directors Report</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['balance_sheet_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['balance_sheet_prepared'] ? '✓' : '○' ?></span>
                <span>Schedule III Balance Sheet</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['profit_loss_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['profit_loss_prepared'] ? '✓' : '○' ?></span>
                <span>Schedule III P&L</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['notes_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['notes_prepared'] ? '✓' : '○' ?></span>
                <span>Notes to Accounts (14+ notes)</span>
            </div>
        <?php elseif ($v2IsLLP): ?>
            <div class="v2-ah-compliance-item <?= $v2Row['balance_sheet_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['balance_sheet_prepared'] ? '✓' : '○' ?></span>
                <span>LLP Balance Sheet</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['profit_loss_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['profit_loss_prepared'] ? '✓' : '○' ?></span>
                <span>LLP Profit & Loss</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['notes_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['notes_prepared'] ? '✓' : '○' ?></span>
                <span>Notes to Accounts</span>
            </div>
            <div class="v2-ah-compliance-item pending">
                <span class="v2-ah-compliance-check">○</span>
                <span>Partner Capital Accounts</span>
            </div>
        <?php elseif ($v2IsTrust): ?>
            <div class="v2-ah-compliance-item <?= $v2Row['balance_sheet_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['balance_sheet_prepared'] ? '✓' : '○' ?></span>
                <span>Trust Balance Sheet</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['profit_loss_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['profit_loss_prepared'] ? '✓' : '○' ?></span>
                <span>Income & Expenditure Account</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['notes_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['notes_prepared'] ? '✓' : '○' ?></span>
                <span>Notes to Accounts</span>
            </div>
        <?php elseif ($v2IsSociety): ?>
            <div class="v2-ah-compliance-item <?= $v2Row['balance_sheet_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['balance_sheet_prepared'] ? '✓' : '○' ?></span>
                <span>Society Balance Sheet</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['profit_loss_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['profit_loss_prepared'] ? '✓' : '○' ?></span>
                <span>Income & Expenditure Account</span>
            </div>
            <div class="v2-ah-compliance-item <?= $v2Row['notes_prepared'] ? 'done' : 'pending' ?>">
                <span class="v2-ah-compliance-check"><?= $v2Row['notes_prepared'] ? '✓' : '○' ?></span>
                <span>Notes to Accounts</span>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="v2-ah-actions">
    <h3 class="v2-ah-actions-title">Quick Actions</h3>
    <div class="v2-ah-actions-row">
        <a href="<?= BASE_URL ?>company_dashboard/company_edit.php?id=<?= $v2CompanyId ?>" class="v2-btn v2-btn-outline">Edit Company</a>
        <a href="<?= BASE_URL ?>company_dashboard/financial_year.php" class="v2-btn v2-btn-outline">Manage FY</a>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
