<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'ReconHub'],
]) ?>

<?= uiPageHero('ReconHub', 'Ledger mapping, Schedule III group tagging, risk review and reconciliation readiness workspace.') ?>

<!-- Compact Page Heading -->
<div class="rh-page-heading">
    <h2>ReconHub — <?= $isLedgerMode ? 'Ledger Mapping' : 'Group Mapping' ?></h2>
</div>

<?php if (!empty($pageWarning)): ?>
    <?= uiAlert($pageWarning, 'warning') ?>
<?php endif; ?>
