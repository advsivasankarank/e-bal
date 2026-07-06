<?php
/**
 * Export Formats - Sprint 4D
 * Format selection cards for package export
 * Expects: $baseUrl (report_download.php path)
 */
if (!isset($baseUrl)) $baseUrl = BASE_URL . 'report_download.php';

require_once __DIR__ . '/../../app/helpers/demo_helper.php';
$_demoExport = isDemoUser($pdo ?? null);
?>

<div class="dw-export-row">
    <a href="<?= $baseUrl ?>?format=pdf" class="dw-export-card">
        <div class="dw-ext">PDF</div>
        <div class="dw-label">Portable Document</div>
        <?php if ($_demoExport): ?><div style="font-size:9px;color:#047857;font-weight:600;margin-top:2px;">Demo Copy</div><?php endif; ?>
    </a>
    <?php if ($_demoExport): ?>
    <div class="dw-export-card" style="opacity:0.5;cursor:not-allowed;position:relative;" title="Upgrade to unlock Word export">
        <div class="dw-ext">Word</div>
        <div class="dw-label">Editable Document</div>
        <div style="position:absolute;top:6px;right:6px;background:#dc2626;color:#fff;font-size:8px;padding:2px 5px;border-radius:4px;font-weight:700;">PRO</div>
    </div>
    <div class="dw-export-card" style="opacity:0.5;cursor:not-allowed;position:relative;" title="Upgrade to unlock Excel export">
        <div class="dw-ext">Excel</div>
        <div class="dw-label">Spreadsheet</div>
        <div style="position:absolute;top:6px;right:6px;background:#dc2626;color:#fff;font-size:8px;padding:2px 5px;border-radius:4px;font-weight:700;">PRO</div>
    </div>
    <div class="dw-export-card" style="opacity:0.5;cursor:not-allowed;position:relative;" title="Upgrade to unlock HTML export">
        <div class="dw-ext">HTML</div>
        <div class="dw-label">Preview</div>
        <div style="position:absolute;top:6px;right:6px;background:#dc2626;color:#fff;font-size:8px;padding:2px 5px;border-radius:4px;font-weight:700;">PRO</div>
    </div>
    <?php else: ?>
    <a href="<?= $baseUrl ?>?format=docx" class="dw-export-card">
        <div class="dw-ext">Word</div>
        <div class="dw-label">Editable Document</div>
    </a>
    <a href="<?= $baseUrl ?>?format=xlsx" class="dw-export-card">
        <div class="dw-ext">Excel</div>
        <div class="dw-label">Spreadsheet</div>
    </a>
    <a href="<?= $baseUrl ?>?format=html" class="dw-export-card" target="_blank">
        <div class="dw-ext">HTML</div>
        <div class="dw-label">Preview</div>
    </a>
    <?php endif; ?>
</div>
<div style="margin-top:8px;font-size:0.78rem;color:var(--muted,#6b7280);text-align:center;">
    <?php if ($_demoExport): ?>
        Demo mode: PDF demo copy only. Upgrade to unlock all export formats.
    <?php else: ?>
        Full package export: all statements, notes, and signatories in a single file.
    <?php endif; ?>
</div>
