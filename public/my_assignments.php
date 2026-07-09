<?php
/**
 * e-BAL V2 — My Assignments (Operational Dashboard)
 *
 * Shows the active assignment context and workflow status.
 * Context must be established before reaching this page.
 */
$page_title = 'My Assignments';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

/* ---- Verify user is authenticated ---- */
$v2UserId = (int) ($_SESSION['user_id'] ?? 0);
if ($v2UserId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

/* ---- Check if context is established ---- */
$activeCompanyId = (int) ($_SESSION['company_id'] ?? 0);
$activeFyId      = (int) ($_SESSION['fy_id'] ?? 0);

if ($activeCompanyId <= 0) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

if ($activeFyId <= 0) {
    header('Location: ' . BASE_URL . 'entity_home.php?company_id=' . $activeCompanyId);
    exit;
}

/* ---- Verify company exists and FY belongs to company ---- */
$companyStmt = $pdo->prepare("SELECT id, name, category, pan, cin, llp_code, profile_completeness, owner_user_id FROM companies WHERE id = ?");
$companyStmt->execute([$activeCompanyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

$fyStmt = $pdo->prepare("SELECT id, fy_label, fy_start, fy_end FROM financial_years WHERE id = ? AND company_id = ?");
$fyStmt->execute([$activeFyId, $activeCompanyId]);
$fy = $fyStmt->fetch(PDO::FETCH_ASSOC);

if (!$company || !$fy) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

/* ---- Verify user owns the company (unless superadmin) ---- */
$v2OwnerId = $v2UserId > 0 ? getOwnerUserId($pdo, $v2UserId) : 0;
$effectiveOwner = $v2OwnerId > 0 ? $v2OwnerId : $v2UserId;
$isSuperadmin = ($company['owner_user_id'] ?? 0) === 0 && isSuperAdmin($pdo, $v2UserId);

if (!$isSuperadmin && (int) ($company['owner_user_id'] ?? 0) !== $effectiveOwner && (int) ($company['owner_user_id'] ?? 0) !== $v2UserId) {
    /* Unauthorized — clear context and redirect */
    unset($_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['fy_id'], $_SESSION['fy_name']);
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

/* ---- Load workflow status ---- */
$wfStmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id = ? AND fy_id = ?");
$wfStmt->execute([$activeCompanyId, $activeFyId]);
$wf = $wfStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$catKey = strtolower(str_replace(['-', ' '], '_', $company['category'] ?? ''));
$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
    'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
    'trust' => 'Trust', 'society' => 'Society',
];
$entityLabel = $entityLabelMap[$catKey] ?? ucfirst($company['category'] ?? '');

/* ---- Workflow steps ---- */
$steps = [
    ['key' => 'ledger_fetched', 'label' => 'Data Import', 'icon' => '📥', 'done' => (bool) ($wf['ledger_fetched'] ?? 0)],
    ['key' => 'mapping_completed', 'label' => 'Ledger Mapping', 'icon' => '🗂️', 'done' => (bool) ($wf['mapping_completed'] ?? 0)],
    ['key' => 'tally_fetched', 'label' => 'Trial Balance', 'icon' => '📋', 'done' => (bool) ($wf['tally_fetched'] ?? 0)],
    ['key' => 'notes_prepared', 'label' => 'Notes', 'icon' => '📝', 'done' => (bool) ($wf['notes_prepared'] ?? 0)],
    ['key' => 'profit_loss_prepared', 'label' => 'P&L', 'icon' => '📊', 'done' => (bool) ($wf['profit_loss_prepared'] ?? 0)],
    ['key' => 'balance_sheet_prepared', 'label' => 'Balance Sheet', 'icon' => '📑', 'done' => (bool) ($wf['balance_sheet_prepared'] ?? 0)],
];
if ($catKey === 'corporate') {
    $steps[] = ['key' => 'directors_report_prepared', 'label' => 'Directors Report', 'icon' => '👔', 'done' => (bool) ($wf['directors_report_prepared'] ?? 0)];
}
$steps[] = ['key' => 'verified', 'label' => 'Review', 'icon' => '✅', 'done' => (bool) ($wf['verified'] ?? 0)];

$totalSteps = count($steps);
$completedSteps = 0;
foreach ($steps as $s) { if ($s['done']) $completedSteps++; }
$progressPct = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;

/* ---- Determine status ---- */
$isVerified = (bool) ($wf['verified'] ?? 0);
$allDone = $completedSteps === $totalSteps;
if ($isVerified || $allDone) $status = 'completed';
elseif ($completedSteps === 0) $status = 'new';
else $status = 'in_progress';

/* ---- Find next action ---- */
$nextAction = '';
$nextHref = '';
$lastDoneIdx = -1;
for ($i = 0; $i < $totalSteps; $i++) { if ($steps[$i]['done']) $lastDoneIdx = $i; }
$nextIdx = $lastDoneIdx + 1;

if ($status === 'completed') {
    $nextAction = 'Generate Deliverables';
    $nextHref = BASE_URL . 'deliverables/';
} elseif ($status === 'new') {
    $nextAction = 'Go to Data Console';
    $nextHref = BASE_URL . 'data/';
} elseif ($nextIdx < $totalSteps) {
    $nextAction = 'Continue to ' . $steps[$nextIdx]['label'];
    if ($steps[$nextIdx]['key'] === 'ledger_fetched' || $steps[$nextIdx]['key'] === 'mapping_completed' || $steps[$nextIdx]['key'] === 'tally_fetched') {
        $nextHref = BASE_URL . 'data/';
    } elseif ($steps[$nextIdx]['key'] === 'notes_prepared' || $steps[$nextIdx]['key'] === 'profit_loss_prepared' || $steps[$nextIdx]['key'] === 'balance_sheet_prepared' || $steps[$nextIdx]['key'] === 'directors_report_prepared') {
        $nextHref = BASE_URL . 'statements/financials.php';
    } elseif ($steps[$nextIdx]['key'] === 'verified') {
        $nextHref = BASE_URL . 'review/';
    }
}
?>

<?= uiBreadcrumb([
    ['label' => 'e-BAL Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => htmlspecialchars($company['name']), 'href' => BASE_URL . 'entity_home.php?company_id=' . $activeCompanyId],
    ['label' => 'My Assignments'],
]) ?>

<?= uiPageHero('My Assignments', htmlspecialchars($company['name']) . ' — ' . htmlspecialchars($fy['fy_label'])) ?>

<!-- Assignment Context Card -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,var(--brand),#1a6ba0);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;">
            <?= strtoupper(substr($company['name'], 0, 1)) ?>
        </div>
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--text);"><?= htmlspecialchars($company['name']) ?></div>
            <div style="font-size:.78rem;color:var(--muted);display:flex;gap:8px;margin-top:2px;">
                <?= uiStatusBadge($entityLabel, 'brand') ?>
                <span>•</span>
                <span><?= htmlspecialchars($fy['fy_label']) ?></span>
                <?php if (!empty($company['pan'])): ?>
                    <span>•</span>
                    <span>PAN: <?= htmlspecialchars($company['pan']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="text-align:center;">
            <div style="width:52px;height:52px;border-radius:50%;border:3px solid <?= $progressPct >= 80 ? 'var(--success)' : ($progressPct >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:<?= $progressPct >= 80 ? 'var(--success)' : ($progressPct >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $progressPct ?>%</div>
            <div style="font-size:.65rem;color:var(--muted);margin-top:2px;">Progress</div>
        </div>
        <div>
            <?= uiStatusBadge(ucfirst($status), $status === 'completed' ? 'success' : ($status === 'in_progress' ? 'brand' : 'default')) ?>
        </div>
    </div>
</div>

<?= uiWorkspaceStart() ?>

<!-- Progress Bar -->
<div style="margin-bottom:20px;">
    <?= uiProgressSteps($steps) ?>
</div>

<!-- Quick Actions -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
    <?php if ($nextHref): ?>
        <?= uiButton($nextAction, $nextHref, 'primary', '→') ?>
    <?php endif; ?>
    <?= uiButton('Data Console', BASE_URL . 'data/', 'outline', '📥') ?>
    <?= uiButton('Financial Statements', BASE_URL . 'statements/financials.php', 'outline', '📊') ?>
    <?= uiButton('Review', BASE_URL . 'review/', 'outline', '✅') ?>
    <?= uiButton('Deliverables', BASE_URL . 'deliverables/', 'outline', '📦') ?>
</div>

<!-- Workflow Steps Detail -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:24px;">
    <?php foreach ($steps as $step): ?>
        <div style="background:var(--panel);border:1px solid <?= $step['done'] ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:16px;display:flex;align-items:center;gap:12px;<?= $step['done'] ? 'background:#f0fdf4;' : '' ?>">
            <div style="width:36px;height:36px;border-radius:8px;background:<?= $step['done'] ? 'var(--success-light)' : '#f1f5f9' ?>;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">
                <?= $step['done'] ? '✓' : $step['icon'] ?>
            </div>
            <div>
                <div style="font-size:.85rem;font-weight:600;color:<?= $step['done'] ? 'var(--success)' : 'var(--text)' ?>;"><?= htmlspecialchars($step['label']) ?></div>
                <div style="font-size:.72rem;color:var(--muted);"><?= $step['done'] ? 'Completed' : 'Pending' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Context Actions -->
<div style="display:flex;gap:12px;flex-wrap:wrap;">
    <?= uiButton('Change Entity', BASE_URL . 'dashboard_company.php', 'outline', '🔄') ?>
    <?= uiButton('Change FY', BASE_URL . 'entity_home.php?company_id=' . $activeCompanyId, 'outline', '📅') ?>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
