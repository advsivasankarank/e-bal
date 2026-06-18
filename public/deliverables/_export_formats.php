<?php
/**
 * Export Formats - Sprint 4D
 * Format selection cards for package export
 * Expects: $baseUrl (report_download.php path)
 */
if (!isset($baseUrl)) $baseUrl = BASE_URL . 'report_download.php';
?>

<div class="dw-export-row">
    <a href="<?= $baseUrl ?>?format=pdf" class="dw-export-card">
        <div class="dw-ext">PDF</div>
        <div class="dw-label">Portable Document</div>
    </a>
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
</div>
<div style="margin-top:8px;font-size:0.78rem;color:var(--muted,#6b7280);text-align:center;">
    Full package export: all statements, notes, and signatories in a single file.
</div>
