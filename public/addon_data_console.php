<?php
/**
 * e-BAL — Addon Data Console
 *
 * Hub page for supplementary computation tools that feed the financial
 * statements but sit outside the main Data Console workflow: manual
 * adjustments, the Depreciation Calculator, and the Deferred Tax
 * Calculator. Mirrors the tile pattern used by public/data/index.php.
 */
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/fixed_asset_helper.php';
require_once __DIR__ . '/../app/helpers/deferred_tax_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = (string) ($_SESSION['company_name'] ?? '');
$fyName = (string) ($_SESSION['fy_name'] ?? '');

$assetCount = count(getFixedAssets($pdo, $company_id, $fy_id));

ensureDeferredTaxItemsSchema($pdo);
$dtItemCount = count(getDeferredTaxItems($pdo, $company_id, $fy_id));

require_once __DIR__ . '/layouts/header_v2.php';

$tiles = [
    [
        'icon' => '📝',
        'label' => 'Manual Entry',
        'desc' => 'Manual adjustments and figures not sourced from Tally (opening balances, provisions, disclosures).',
        'href' => BASE_URL . 'statements/financials.php',
        'detail' => 'Opens the Manual Adjustments drawer on the Financial Statements page',
    ],
    [
        'icon' => '🏭',
        'label' => 'Depreciation Calculator',
        'desc' => 'Fixed Asset Register and Schedule II depreciation schedule (SLM/WDV).',
        'href' => BASE_URL . 'asset_register.php',
        'detail' => $assetCount > 0 ? $assetCount . ' asset(s) registered' : 'No assets registered yet',
    ],
    [
        'icon' => '⚖️',
        'label' => 'Deferred Tax Calculator',
        'desc' => 'AS 22 timing-difference computation -- book vs. Income-tax Act depreciation, plus manual timing differences.',
        'href' => BASE_URL . 'deferred_tax_calculator.php',
        'detail' => $dtItemCount > 0 ? $dtItemCount . ' manual item(s) recorded' : 'No manual items recorded yet',
    ],
];
?>

<?= uiBreadcrumb([
    ['label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
    ['label' => 'Addon Data Console'],
]) ?>

<?= uiPageHero('Addon Data Console', htmlspecialchars($companyName) . ' · FY ' . htmlspecialchars($fyName)) ?>

<div class="v2-dw-tiles">
    <?php foreach ($tiles as $tile): ?>
        <a href="<?= $tile['href'] ?>" class="v2-dw-tile">
            <div class="v2-dw-tile-header">
                <div class="v2-dw-tile-icon"><?= $tile['icon'] ?></div>
                <div class="v2-dw-tile-info">
                    <h3><?= htmlspecialchars($tile['label']) ?></h3>
                </div>
            </div>
            <p class="v2-dw-tile-desc"><?= htmlspecialchars($tile['desc']) ?></p>
            <div class="v2-dw-tile-detail"><?= htmlspecialchars($tile['detail']) ?></div>
            <div class="v2-dw-tile-action">Open →</div>
        </a>
    <?php endforeach; ?>
</div>

<div style="margin-top:20px;">
    <?= uiButton('← Back to Financial Statements', BASE_URL . 'reports.php', 'outline') ?>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
