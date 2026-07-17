<?php
/**
 * e-BAL V2 — Entity Home
 *
 * Financial Year selection for a specific entity.
 * After selecting FY, saves context and redirects to My Assignments.
 */
$page_title = 'Entity Home';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/financial_year_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';

/* ---- Resolve parameters ---- */
$companyId = (int) ($_GET['company_id'] ?? $_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

/* ---- Load entity details ---- */
$stmt = $pdo->prepare("SELECT id, name, category, pan, cin, llp_code, profile_completeness, owner_user_id FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

/* Ownership check — this page sets session company/FY context from a raw
   $_GET company_id, so it must independently verify the company belongs to
   the logged-in user rather than relying on requireAssignmentAccess() (which
   only validates whatever is already IN the session, and hasn't run yet here). */
if ((int) ($company['owner_user_id'] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)) {
    header('Location: ' . BASE_URL . 'dashboard_company.php?error=access_denied');
    exit;
}

$catKey = strtolower(str_replace(['-', ' '], '_', $company['category'] ?? ''));
$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
    'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
    'trust' => 'Trust', 'society' => 'Society',
];
$entityLabel = $entityLabelMap[$catKey] ?? ucfirst($company['category'] ?? '');
$identifier = '';
if ($catKey === 'corporate' && !empty($company['cin'])) $identifier = $company['cin'];
elseif ($catKey === 'llp' && !empty($company['llp_code'])) $identifier = $company['llp_code'];
elseif (!empty($company['pan'])) $identifier = $company['pan'];
$pct = (int) ($company['profile_completeness'] ?? 0);
$pctColor = $pct >= 80 ? 'var(--success)' : ($pct >= 40 ? 'var(--warning)' : 'var(--danger)');

/* ---- Load Financial Years ---- */
$financialYears = getFinancialYears($pdo, $companyId);

/* ---- Load workflow status for each FY ---- */
$fyData = [];
foreach ($financialYears as $fy) {
    $wfStmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id = ? AND fy_id = ?");
    $wfStmt->execute([$companyId, $fy['id']]);
    $wf = $wfStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $fyData[] = [
        'id' => $fy['id'],
        'label' => $fy['fy_label'],
        'start' => $fy['fy_start'] ?? '',
        'end' => $fy['fy_end'] ?? '',
        'ledger_fetched' => (int) ($wf['ledger_fetched'] ?? 0),
        'mapping_completed' => (int) ($wf['mapping_completed'] ?? 0),
        'tally_fetched' => (int) ($wf['tally_fetched'] ?? 0),
        'verified' => (int) ($wf['verified'] ?? 0),
        'updated_at' => $wf['updated_at'] ?? '',
    ];
}

/* ---- Handle FY selection / quick create POST ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['entity_action'] ?? '';

    if ($action === 'select_fy') {
        $selFyId = (int) ($_POST['fy_id'] ?? 0);
        if ($selFyId > 0) {
            $fy = findFinancialYearById($pdo, $selFyId, $companyId);
            if ($fy) {
                $_SESSION['company_id'] = $companyId;
                $_SESSION['company_name'] = $company['name'];
                $_SESSION['fy_id'] = $fy['id'];
                $_SESSION['fy_name'] = $fy['fy_label'];
                $_SESSION['entity_type'] = $company['category'];
                header('Location: ' . BASE_URL . 'my_assignments.php');
                exit;
            }
        }
    }

    if ($action === 'quick_create_fy') {
        try {
            $newFy = ensureFinancialYearRecord($pdo, $companyId, '2024-2025');
            header('Location: ' . BASE_URL . 'entity_home.php?company_id=' . $companyId);
            exit;
        } catch (Throwable $e) {
            // Fall through to render page with error
        }
    }
}

/* ---- Load layout (header opens HTML shell, sidebar, main content area) ---- */
require_once __DIR__ . '/layouts/header_v2.php';

?>

<?= uiBreadcrumb([
    ['label' => 'e-BAL Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => htmlspecialchars($company['name'])],
]) ?>

<?= uiPageHero(htmlspecialchars($company['name']), 'Select a Financial Year to continue to assignments.') ?>

<!-- Entity Info Card -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,var(--brand),#1a6ba0);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;">
            <?= strtoupper(substr($company['name'], 0, 1)) ?>
        </div>
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--text);"><?= htmlspecialchars($company['name']) ?></div>
            <div style="font-size:.78rem;color:var(--muted);display:flex;gap:8px;margin-top:2px;">
                <?= uiStatusBadge($entityLabel, 'brand') ?>
                <?php if ($identifier): ?>
                    <span><?= htmlspecialchars($identifier) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:16px;">
        <div style="text-align:center;">
            <div style="width:52px;height:52px;border-radius:50%;border:3px solid <?= $pctColor ?>;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:<?= $pctColor ?>;"><?= $pct ?>%</div>
            <div style="font-size:.65rem;color:var(--muted);margin-top:2px;">Profile</div>
        </div>
        <a href="<?= BASE_URL ?>entity_edit.php?id=<?= $companyId ?>" class="v2-btn v2-btn--outline" style="font-size:.78rem;">Edit Profile</a>
    </div>
</div>

<?= uiWorkspaceStart() ?>

<!-- Financial Year Selection -->
<div style="margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div style="font-size:.88rem;font-weight:700;color:var(--text);">Financial Years (<?= count($fyData) ?>)</div>
        <?= uiButton('Create Financial Year', BASE_URL . 'company_dashboard/financial_year.php?company_id=' . $companyId, 'primary', '➕') ?>
    </div>

    <?php if (empty($fyData)): ?>
        <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="entity_action" value="quick_create_fy">
            <div style="text-align:center;padding:40px 20px;">
                <div style="font-size:2rem;margin-bottom:12px;opacity:0.4;">📅</div>
                <div style="font-size:1.05rem;font-weight:600;color:var(--text);margin-bottom:6px;">
                    No Financial Year has been created for this entity.
                </div>
                <div style="font-size:.85rem;color:var(--muted);margin-bottom:24px;">
                    Create a Financial Year to continue to assignments.
                </div>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <?= uiButton('Create FY 2024-2025', '', 'primary', '➕') ?>
                </div>
                <div style="margin-top:16px;">
                    <?= uiButton('Back to e-BAL Gateway', BASE_URL . 'dashboard_company.php', 'outline', '←') ?>
                </div>
            </div>
        </form>
    <?php else: ?>
        <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="entity_action" value="select_fy">

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;">
                <?php foreach ($fyData as $fy):
                    $wfComplete = $fy['ledger_fetched'] && $fy['mapping_completed'] && $fy['tally_fetched'];
                    $isVerified = $fy['verified'];
                    $fyStatus = $isVerified ? 'completed' : ($wfComplete ? 'ready' : 'open');
                    $statusColor = $fyStatus === 'completed' ? 'var(--success)' : ($fyStatus === 'ready' ? 'var(--brand)' : 'var(--warning)');
                    $lastUpdate = $fy['updated_at'] ? date('d M Y', strtotime($fy['updated_at'])) : 'Never';
                ?>
                <label style="background:var(--panel);border:2px solid var(--border);border-radius:var(--radius-lg);padding:18px;cursor:pointer;transition:all .15s;display:block;" onmouseover="this.style.borderColor='var(--brand)'" onmouseout="this.style.borderColor='var(--border)'">
                    <input type="radio" name="fy_id" value="<?= (int) $fy['id'] ?>" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                        <div>
                            <div style="font-size:1rem;font-weight:700;color:var(--text);"><?= htmlspecialchars($fy['label']) ?></div>
                            <div style="font-size:.72rem;color:var(--muted);"><?= $fy['start'] && $fy['end'] ? htmlspecialchars(date('d M Y', strtotime($fy['start'])) . ' — ' . date('d M Y', strtotime($fy['end']))) : '' ?></div>
                        </div>
                        <?= uiStatusBadge(ucfirst($fyStatus), $fyStatus === 'completed' ? 'success' : ($fyStatus === 'ready' ? 'brand' : 'default')) ?>
                    </div>
                    <div style="font-size:.72rem;color:var(--muted);">Last Updated: <?= $lastUpdate ?></div>
                </label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:20px;display:flex;gap:12px;">
                <?= uiButton('Continue to Assignments', '', 'primary', '→') ?>
                <?= uiButton('Back to Entities', BASE_URL . 'dashboard_company.php', 'outline', '←') ?>
            </div>
        </form>
    <?php endif; ?>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
