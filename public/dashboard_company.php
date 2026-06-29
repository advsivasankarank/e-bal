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

<div class="v2-content">

    <!-- Breadcrumb -->
    <nav style="margin-bottom:12px;font-size:.78rem;color:#64748b;">
        <a href="<?= BASE_URL ?>dashboard_main.php" style="color:#12355b;text-decoration:none;">Dashboard</a>
        <span style="margin:0 6px;">›</span>
        <span style="color:#1e293b;font-weight:600;">Company Workspace</span>
    </nav>

    <!-- Page Title -->
    <div class="v2-page-title">
        <h1>Company Workspace</h1>
        <p>Manage companies, financial years, and the active assignment context.</p>
    </div>

    <!-- Active Company Card -->
    <?php if ($activeCompanyId > 0): ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div style="display:flex;align-items:center;gap:16px;min-width:0;">
            <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#12355b,#1e5aa8);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;flex-shrink:0;">
                <?= strtoupper(substr($activeCompanyName, 0, 1)) ?>
            </div>
            <div style="min-width:0;">
                <div style="font-size:1.05rem;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($activeCompanyName) ?></div>
                <div style="font-size:.78rem;color:#64748b;display:flex;gap:12px;flex-wrap:wrap;margin-top:2px;">
                    <span><?= htmlspecialchars($entityTypeLabel) ?></span>
                    <span>•</span>
                    <span><?= htmlspecialchars($activeFyName ?: 'No FY Selected') ?></span>
                    <?php if ($companyPan): ?>
                        <span>•</span>
                        <span>PAN: <?= htmlspecialchars($companyPan) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
            <div style="text-align:center;">
                <div style="width:56px;height:56px;border-radius:50%;border:3px solid <?= $pctColor ?>;display:flex;align-items:center;justify-content:center;font-size:.88rem;font-weight:700;color:<?= $pctColor ?>;"><?= $companyProfile ?>%</div>
                <div style="font-size:.65rem;color:#64748b;margin-top:2px;">Profile</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.72rem;font-weight:600;color:<?= $companyStatus === 'Reports Ready' ? '#047857' : '#d97706' ?>;"><?= htmlspecialchars($companyStatus) ?></div>
                <div style="font-size:.68rem;color:#94a3b8;margin-top:2px;">Workflow Status</div>
            </div>
            <a href="<?= BASE_URL ?>company_dashboard/company_edit.php?id=<?= $activeCompanyId ?>" class="v2-btn v2-btn--outline" style="font-size:.78rem;">Edit Profile</a>
        </div>
    </div>
    <?php else: ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:20px;text-align:center;">
        <div style="font-size:2rem;margin-bottom:8px;opacity:.3;">🏢</div>
        <div style="font-size:.92rem;font-weight:600;color:#475569;margin-bottom:4px;">No Active Company</div>
        <div style="font-size:.82rem;color:#94a3b8;margin-bottom:14px;">Create or select a company to begin working.</div>
        <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="v2-btn v2-btn--primary">Create Entity</a>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;">
        <a href="<?= BASE_URL ?>company_dashboard/company_list.php" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:block;transition:box-shadow .15s;" onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,.06)'" onmouseout="this.style.boxShadow='none'">
            <div style="font-size:1.4rem;font-weight:700;color:#12355b;"><?= $totalCompanies ?></div>
            <div style="font-size:.78rem;color:#64748b;">Companies</div>
        </a>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
            <div style="font-size:1.4rem;font-weight:700;color:#12355b;"><?= htmlspecialchars($currentFYLabel) ?></div>
            <div style="font-size:.78rem;color:#64748b;">Current FY</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
            <div style="font-size:1.4rem;font-weight:700;color:#12355b;"><?= $assignmentCount ?></div>
            <div style="font-size:.78rem;color:#64748b;">Assignments</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
            <div style="font-size:1.4rem;font-weight:700;color:<?= $pctColor ?>;"><?= $profileAvg ?>%</div>
            <div style="font-size:.78rem;color:#64748b;">Profile Completion</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div style="margin-bottom:24px;">
        <div style="font-size:.88rem;font-weight:700;color:#1e293b;margin-bottom:12px;">Quick Actions</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
            <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="v2-tile-link" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;transition:all .15s;" onmouseover="this.style.borderColor='#12355b'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="width:36px;height:36px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#12355b" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:#1e293b;">Create Entity</div>
                    <div style="font-size:.72rem;color:#64748b;">Add a new company</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="v2-tile-link" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;transition:all .15s;" onmouseover="this.style.borderColor='#12355b'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="width:36px;height:36px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:#1e293b;">Fetch From Tally</div>
                    <div style="font-size:.72rem;color:#64748b;">Import via Smart Bridge</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>company_dashboard/company_select.php" class="v2-tile-link" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;transition:all .15s;" onmouseover="this.style.borderColor='#12355b'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="width:36px;height:36px;border-radius:8px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:#1e293b;">Select Company</div>
                    <div style="font-size:.72rem;color:#64748b;">Choose active context</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>company_dashboard/financial_year.php" class="v2-tile-link <?= $activeCompanyId <= 0 ? 'disabled' : '' ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;transition:all .15s;<?= $activeCompanyId <= 0 ? 'opacity:.5;pointer-events:none;' : '' ?>" onmouseover="if(!this.classList.contains('disabled'))this.style.borderColor='#12355b'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="width:36px;height:36px;border-radius:8px;background:#f5f3ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:#1e293b;">Change Financial Year</div>
                    <div style="font-size:.72rem;color:#64748b;">Switch April–March period</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>company_dashboard/company_list.php" class="v2-tile-link" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;transition:all .15s;" onmouseover="this.style.borderColor='#12355b'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="width:36px;height:36px;border-radius:8px;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:#1e293b;">Manage Companies</div>
                    <div style="font-size:.72rem;color:#64748b;">View, edit, or remove</div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>data/index.php" class="v2-tile-link <?= empty($_SESSION['company_id']) || empty($_SESSION['fy_id']) ? 'disabled' : '' ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;transition:all .15s;<?= empty($_SESSION['company_id']) || empty($_SESSION['fy_id']) ? 'opacity:.5;pointer-events:none;' : '' ?>" onmouseover="if(!this.classList.contains('disabled'))this.style.borderColor='#12355b'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="width:36px;height:36px;border-radius:8px;background:#ecfdf5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </div>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:#1e293b;">Continue to Data Import</div>
                    <div style="font-size:.72rem;color:#64748b;">Start ledger sync workflow</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Companies & Recent Activity -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- Recent Companies -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                <div style="font-size:.88rem;font-weight:700;color:#1e293b;">Recent Companies</div>
                <a href="<?= BASE_URL ?>company_dashboard/company_list.php" style="font-size:.75rem;color:#12355b;text-decoration:none;font-weight:600;">View All</a>
            </div>
            <?php if (empty($recentCompanies)): ?>
                <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.82rem;">No companies yet.</div>
            <?php else: ?>
                <?php foreach ($recentCompanies as $rc):
                    $rcCat = strtolower(str_replace(['-', ' '], '_', $rc['category'] ?? ''));
                    $rcLabel = $entityLabelMap[$rcCat] ?? ucfirst($rc['category'] ?? '');
                    $rcDone = (int) ($rc['tally_fetched'] ?? 0) === 1;
                ?>
                <div style="padding:12px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                    <div style="min-width:0;">
                        <div style="font-size:.85rem;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($rc['name'] ?? '') ?></div>
                        <div style="font-size:.72rem;color:#64748b;display:flex;gap:8px;margin-top:2px;">
                            <span><?= htmlspecialchars($rcLabel) ?></span>
                            <span style="color:#d1d5db;">•</span>
                            <span style="color:<?= $rcDone ? '#047857' : '#94a3b8' ?>"><?= $rcDone ? 'Active' : 'Pending' ?></span>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>company_dashboard/company_select.php?company_id=<?= (int) $rc['id'] ?>" class="v2-btn v2-btn--outline" style="font-size:.72rem;padding:4px 10px;">Open</a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;">
                <div style="font-size:.88rem;font-weight:700;color:#1e293b;">Recent Activity</div>
            </div>
            <?php if (empty($recentActivity)): ?>
                <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.82rem;">No recent activity.</div>
            <?php else: ?>
                <?php foreach ($recentActivity as $ra): ?>
                <div style="padding:12px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;">
                    <div style="width:28px;height:28px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;"><?= $ra['icon'] ?? '📋' ?></div>
                    <div style="min-width:0;flex:1;">
                        <div style="font-size:.82rem;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($ra['label'] ?? '') ?></div>
                        <div style="font-size:.68rem;color:#94a3b8;"><?= htmlspecialchars($ra['date'] ? date('d M Y', strtotime($ra['date'])) : '') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
