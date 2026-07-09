<?php
/**
 * e-BAL V2 — Mapping Workbench (Legacy Redirect)
 *
 * This page redirects to the Bulk Mapping Workbench.
 * The old mapping console has been superseded by mapping_workbench.php.
 */
$page_title = 'ReconHub';

require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'] ?? 0;
$fy_id      = $_SESSION['fy_id'] ?? 0;
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

require_once __DIR__ . '/layouts/header_v2.php';

/* Build workbench URL with context */
$workbenchUrl = BASE_URL . 'data_console/mapping_workbench.php';
$params = [];
if ($company_id > 0) $params['entity_id'] = $company_id;
if ($fy_id > 0) $params['fy_id'] = $fy_id;
if (!empty($params)) {
    $workbenchUrl .= '?' . http_build_query($params);
}
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'ReconHub'],
]) ?>

<?= uiPageHero('ReconHub', 'Ledger mapping has moved to the ReconHub workspace.') ?>

<?= uiWorkspaceStart() ?>

<div style="max-width:640px;margin:0 auto;text-align:center;padding:40px 20px;">
    <div style="font-size:3rem;margin-bottom:16px;">🗂️</div>
    <h2 style="font-size:1.3rem;font-weight:700;margin-bottom:8px;color:var(--text);">Mapping has moved to ReconHub</h2>
    <p style="font-size:0.9rem;color:var(--muted);margin-bottom:24px;line-height:1.6;">
        The legacy mapping console has been replaced by <strong>ReconHub</strong>
        which provides an Excel-like interface with DR/CR columns, Schedule III Group Mapping,
        AI suggestions, risk review, and bulk save capabilities.
    </p>

    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px;">
        <div style="font-size:0.85rem;color:var(--muted);margin-bottom:8px;">Current Context</div>
        <div style="font-size:0.95rem;font-weight:600;"><?= htmlspecialchars($companyName) ?></div>
        <div style="font-size:0.85rem;color:var(--muted);">FY <?= htmlspecialchars($fyName) ?></div>
    </div>

    <a href="<?= htmlspecialchars($workbenchUrl) ?>" class="v2-btn v2-btn--primary" style="display:inline-flex;align-items:center;gap:8px;padding:14px 32px;font-size:1rem;">
        Open ReconHub →
    </a>

    <div style="margin-top:24px;font-size:0.8rem;color:var(--muted);">
        <p>Features available in the new workbench:</p>
        <ul style="text-align:left;display:inline-block;margin-top:8px;line-height:1.8;">
            <li>Excel-like ledger grid with DR/CR columns</li>
            <li>Schedule III Group Mapping panel</li>
            <li>AI-powered mapping suggestions</li>
            <li>Risk warnings for DR/CR mismatches</li>
            <li>Bulk accept and save functionality</li>
            <li>Export/Import from Excel</li>
        </ul>
    </div>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
