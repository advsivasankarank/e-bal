<?php
/**
 * e-BAL V2 — Ledger Fetch (Legacy Landing Page)
 *
 * Ledger import is now handled through Smart Bridge Connector.
 * This page redirects to the working connector route.
 */
require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../config/app.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$page_title = 'Ledger Import';

require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Ledger Import'],
]) ?>

<?= uiPageHero('Ledger Import') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
]) ?>

<?= uiWorkspaceStart() ?>

<div class="card" style="max-width:640px;">
    <h3 style="font-size:1.1rem; margin-bottom:8px;">Ledger Import via Smart Bridge</h3>
    <p style="color:var(--muted); margin-bottom:20px; line-height:1.6;">
        Ledger import is now handled through the <strong>Smart Bridge Connector</strong>.
        Use the button below to open the connector and sync ledgers from Tally.
    </p>

    <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>data_console/connector.php?bridge=1&entity_id=<?= (int) $company_id ?>&fy_id=<?= (int) $fy_id ?>">
            Open Smart Bridge Connector
        </a>
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
