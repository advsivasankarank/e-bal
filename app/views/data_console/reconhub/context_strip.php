<!-- Compact Context Strip -->
<div class="recon-context-strip">
    <?php if ($isLedgerMode): ?>
    <a href="<?= BASE_URL ?>data_console/mapping_workbench.php" class="btn btn-outline" style="padding:4px 12px;font-size:0.78rem;text-decoration:none;">&#8592; Back to ReconHub</a>
    <span class="rcs-sep">|</span>
    <?php endif; ?>
    <span class="rcs-label">Entity:</span> <span class="rcs-value"><?= htmlspecialchars($sessionCompanyName) ?></span>
    <span class="rcs-sep">|</span>
    <span class="rcs-label">FY:</span> <span class="rcs-value"><?= htmlspecialchars($sessionFyName) ?></span>
    <span class="rcs-sep">|</span>
    <span class="rcs-value" style="color:var(--info);"><?= $isLedgerMode ? 'Ledger-wise Mapping' : ($defaultViewTbImpact ? 'TB Impact View' : 'All Master View') ?></span>
    <span class="rcs-sep">|</span>
    <span class="rcs-value"><?= number_format($stats['total']) ?> ledgers</span>
    <?php if ($isGroupMode): ?>
    <span class="rcs-sep">|</span>
    <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?mode=ledger" class="btn btn-primary" style="padding:4px 12px;font-size:0.78rem;text-decoration:none;">Ledger-wise Mapping &#8594;</a>
    <?php endif; ?>
</div>

<?php if (!empty($sessionSuccess)): ?>
    <div class="success-box"><p><?= htmlspecialchars($sessionSuccess) ?></p></div>
<?php endif; ?>
<?php if (!empty($sessionError)): ?>
    <div class="error-box"><p><?= htmlspecialchars($sessionError) ?></p></div>
<?php endif; ?>
<?php if (!empty($processingError)): ?>
    <div class="error-box"><p><strong>Processing Warning:</strong> <?= htmlspecialchars($processingError) ?>. Some data may be incomplete.</p></div>
<?php endif; ?>
