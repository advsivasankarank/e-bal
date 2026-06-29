<?php
/**
 * e-BAL V2 — Company Workspace
 *
 * Enterprise workspace for managing companies, financial years,
 * and the active assignment context.
 *
 * Uses V2 layout (sidebar + topbar).
 */
$page_title = 'Company Workspace';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

/* ---- Resolve owner ---- */
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = $userId > 0 ? getOwnerUserId($pdo, $userId) : 0;

/* ---- Active company context ---- */
$activeCompanyId   = (int) ($_SESSION['company_id'] ?? 0);
$activeCompanyName = (string) ($_SESSION['company_name'] ?? '');
$activeFyId        = (int) ($_SESSION['fy_id'] ?? 0);
$activeFyName      = (string) ($_SESSION['fy_name'] ?? '');

$companyCategory   = '';
$companyPan        = '';
$companyCin        = '';
$companyLlpCode    = '';
$companyProfile    = 0;
$companyStatus     = 'No Active Company';

if ($activeCompanyId > 0) {
    try {
        $coStmt = $pdo->prepare("SELECT category, pan, cin, llp_code, profile_completeness FROM companies WHERE id = ?");
        $coStmt->execute([$activeCompanyId]);
        $coRow = $coStmt->fetch(PDO::FETCH_ASSOC);
        if ($coRow) {
            $companyCategory = (string) ($coRow['category'] ?? '');
            $companyPan      = (string) ($coRow['pan'] ?? '');
            $companyCin      = (string) ($coRow['cin'] ?? '');
            $companyLlpCode  = (string) ($coRow['llp_code'] ?? '');
            $companyProfile  = (int) ($coRow['profile_completeness'] ?? 0);
        }
    } catch (Throwable $e) { /* ignore */ }

    /* Determine entity type label */
    $entityLabelMap = [
        'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
        'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
        'trust' => 'Trust', 'society' => 'Society',
    ];
    $catKey = strtolower(str_replace(['-', ' '], '_', $companyCategory));
    $entityTypeLabel = $entityLabelMap[$catKey] ?? ucfirst($companyCategory);

    /* Determine workflow status */
    if ($activeFyId > 0) {
        $wfStmt = $pdo->prepare("SELECT tally_fetched, mapping_completed, ledger_fetched FROM workflow_status WHERE company_id=? AND fy_id=?");
        $wfStmt->execute([$activeCompanyId, $activeFyId]);
        $wfRow = $wfStmt->fetch(PDO::FETCH_ASSOC);
        if ($wfRow) {
            if ((int) ($wfRow['tally_fetched'] ?? 0) === 1) {
                $companyStatus = 'Reports Ready';
            } elseif ((int) ($wfRow['mapping_completed'] ?? 0) === 1) {
                $companyStatus = 'Trial Balance Pending';
            } elseif ((int) ($wfRow['ledger_fetched'] ?? 0) === 1) {
                $companyStatus = 'Mapping Pending';
            } else {
                $companyStatus = 'Data Import Pending';
            }
        } else {
            $companyStatus = 'Data Import Pending';
        }
    }
}

/* ---- KPI Counts ---- */
$companyScope = $ownerId > 0 ? " AND c.owner_user_id = {$ownerId}" : '';
$totalCompanies = (int) $pdo->query("SELECT COUNT(*) FROM companies c WHERE 1=1 {$companyScope}")->fetchColumn();

$currentMonth = (int) date('m');
$currentYear  = (int) date('Y');
$currentFYLabel = ($currentMonth >= 4 ? $currentYear : $currentYear - 1) . '-' . (($currentMonth >= 4 ? $currentYear : $currentYear - 1) + 1);

$assignmentCount = 0;
if ($ownerId > 0) {
    $assignmentCount = (int) $pdo->query("
        SELECT COUNT(DISTINCT CONCAT(c.id, '-', fy.id))
        FROM companies c
        INNER JOIN financial_years fy ON fy.company_id = c.id
        WHERE c.owner_user_id = {$ownerId}
    ")->fetchColumn();
}

$profileAvg = 0;
if ($totalCompanies > 0 && $ownerId > 0) {
    $avgStmt = $pdo->query("SELECT COALESCE(AVG(profile_completeness), 0) FROM companies WHERE owner_user_id = {$ownerId}");
    $profileAvg = (int) round((float) $avgStmt->fetchColumn());
}

/* ---- Recent Companies (last 5) ---- */
$recentCompanies = [];
if ($ownerId > 0) {
    $rcStmt = $pdo->prepare("
        SELECT c.id, c.name, c.category, c.profile_completeness, c.created_at,
               COALESCE(ws.tally_fetched, 0) AS tally_fetched
        FROM companies c
        LEFT JOIN workflow_status ws ON ws.company_id = c.id AND ws.fy_id = (SELECT MAX(fy_id) FROM workflow_status WHERE company_id = c.id)
        WHERE c.owner_user_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $rcStmt->execute([$ownerId]);
    $recentCompanies = $rcStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- Recent Activity (last 5 actions) ---- */
$recentActivity = [];
if ($activeCompanyId > 0) {
    try {
        $raStmt = $pdo->prepare("
            SELECT updated_at, company_id FROM workflow_status
            WHERE company_id = ? ORDER BY updated_at DESC LIMIT 5
        ");
        $raStmt->execute([$activeCompanyId]);
        $raRows = $raStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raRows as $ra) {
            $recentActivity[] = [
                'label' => 'Workflow updated',
                'date'  => $ra['updated_at'] ?? '',
                'icon'  => '🔄',
            ];
        }
    } catch (Throwable $e) { /* ignore */ }
}
/* Supplement with company creation events */
if ($ownerId > 0 && count($recentActivity) < 5) {
    $crStmt = $pdo->prepare("SELECT name, created_at FROM companies WHERE owner_user_id = ? ORDER BY created_at DESC LIMIT 3");
    $crStmt->execute([$ownerId]);
    foreach ($crStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $recentActivity[] = [
            'label' => 'Created ' . $cr['name'],
            'date'  => $cr['created_at'] ?? '',
            'icon'  => '➕',
        ];
    }
}
usort($recentActivity, fn($a, $b) => strcmp($b['date'], $a['date']));
$recentActivity = array_slice($recentActivity, 0, 5);

/* ---- Profile color ---- */
$pctColor = $companyProfile >= 80 ? '#047857' : ($companyProfile >= 40 ? '#d97706' : '#dc2626');
$pctBg    = $companyProfile >= 80 ? '#d1fae5' : ($companyProfile >= 40 ? '#fef3c7' : '#fee2e2');
?>

<!-- Breadcrumb -->
<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Company Workspace']
]) ?>

<!-- Page Title -->
<?= uiPageHero('Company Workspace', 'Manage companies, financial years, and the active assignment context.') ?>

<!-- Active Company Card -->
<?php if ($activeCompanyId > 0): ?>
<?= uiContextCard([
    'company'     => $activeCompanyName,
    'fy'          => $activeFyName ?: 'No FY Selected',
    'entity_type' => $entityTypeLabel,
    'profile'     => $companyProfile,
    'status'      => $companyStatus,
    'edit_url'    => BASE_URL . 'company_dashboard/company_edit.php?id=' . $activeCompanyId,
]) ?>
<?php else: ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:20px;text-align:center;">
    <div style="font-size:2rem;margin-bottom:8px;opacity:.3;">🏢</div>
    <div style="font-size:.92rem;font-weight:600;color:#475569;margin-bottom:4px;">No Active Company</div>
    <div style="font-size:.82rem;color:#94a3b8;margin-bottom:14px;">Create or select a company to begin working.</div>
    <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="v2-btn v2-btn--primary">Create Entity</a>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<?= uiKpiCards([
    ['value' => $totalCompanies,        'label' => 'Companies',          'href' => BASE_URL . 'company_dashboard/company_list.php', 'color' => 'var(--brand)'],
    ['value' => $currentFYLabel,         'label' => 'Current FY',         'color' => 'var(--brand)'],
    ['value' => $assignmentCount,        'label' => 'Assignments',        'color' => 'var(--brand)'],
    ['value' => $profileAvg . '%',       'label' => 'Profile Completion', 'color' => $pctColor],
]) ?>

<!-- Quick Actions -->
<div style="margin-bottom:24px;">
    <div style="font-size:.88rem;font-weight:700;color:#1e293b;margin-bottom:12px;">Quick Actions</div>
    <?= uiActionCards([
        [
            'label' => 'Create Entity',
            'desc'  => 'Add a new company',
            'href'  => BASE_URL . 'company_dashboard/company_create.php',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
            'color' => 'var(--brand)',
        ],
        [
            'label' => 'Fetch From Tally',
            'desc'  => 'Import via Smart Bridge',
            'href'  => BASE_URL . 'company_dashboard/company_create.php',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
            'color' => 'var(--success)',
        ],
        [
            'label' => 'Select Company',
            'desc'  => 'Choose active context',
            'href'  => BASE_URL . 'company_dashboard/company_select.php',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'color' => 'var(--warning)',
        ],
        [
            'label' => 'Change Financial Year',
            'desc'  => 'Switch April–March period',
            'href'  => BASE_URL . 'company_dashboard/financial_year.php',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'color' => '#7c3aed',
            'disabled' => $activeCompanyId <= 0,
        ],
        [
            'label' => 'Manage Companies',
            'desc'  => 'View, edit, or remove',
            'href'  => BASE_URL . 'company_dashboard/company_list.php',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'color' => 'var(--danger)',
        ],
        [
            'label' => 'Continue to Data Import',
            'desc'  => 'Start ledger sync workflow',
            'href'  => BASE_URL . 'data/index.php',
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
            'color' => 'var(--success)',
            'disabled' => empty($_SESSION['company_id']) || empty($_SESSION['fy_id']),
        ],
    ]) ?>
</div>

<!-- Recent Companies & Recent Activity -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

    <!-- Recent Companies -->
    <?php
    $recentCompanyItems = [];
    foreach ($recentCompanies as $rc):
        $rcCat   = strtolower(str_replace(['-', ' '], '_', $rc['category'] ?? ''));
        $rcLabel = $entityLabelMap[$rcCat] ?? ucfirst($rc['category'] ?? '');
        $rcDone  = (int) ($rc['tally_fetched'] ?? 0) === 1;
        $recentCompanyItems[] = [
            'name'        => $rc['name'] ?? '',
            'meta'        => $rcLabel,
            'badge'       => ['label' => $rcDone ? 'Active' : 'Pending', 'variant' => $rcDone ? 'success' : 'default'],
            'action_href' => BASE_URL . 'company_dashboard/company_select.php?company_id=' . (int) $rc['id'],
            'action_label' => 'Open',
        ];
    endforeach;
    ?>
    <?= uiRecentList($recentCompanyItems, BASE_URL . 'company_dashboard/company_list.php', 'View All') ?>

    <!-- Recent Activity -->
    <?= uiActivityFeed($recentActivity) ?>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
