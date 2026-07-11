<!-- Grid with horizontal scroll (ledger mode only) -->
<div class="recon-grid-wrap">
    <div class="hot-container">
        <div id="hot"></div>
    </div>
</div>

<!-- Action Bar — Ledger Mode -->
<div class="wb-actions">
    <button class="btn btn-success" id="btnAcceptHigh" title="Accept all suggestions with confidence >= 90%">Accept High Confidence</button>
    <button class="btn" id="btnAcceptGroup" title="Accept parent group rule suggestions for visible rows" style="background:#7c3aed;color:#fff;border-color:#7c3aed;">Accept Group Suggestions</button>
    <button class="btn" id="btnAcceptSelected" title="Accept suggestions for selected rows">Accept Selected</button>
    <div class="sep"></div>
    <select id="bulkGroupSelect" style="padding:6px 10px;border:1px solid var(--border-strong);border-radius:6px;font-size:0.8rem;min-width:160px;">
        <option value="">Set Selected To&hellip;</option>
        <?php foreach ($mappingOptions as $code => $label): ?>
            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" id="btnBulkApply" title="Apply selected group to checked rows">Apply</button>
    <div class="sep"></div>
    <button class="btn btn-outline" id="btnReset" title="Reset all unsaved changes">&#8617; Reset</button>
    <button class="btn btn-success" id="btnSave" title="Save only changed rows">&#128190; Save Changes</button>
    <div class="sep"></div>
    <button class="btn btn-outline" id="btnExport" title="Export mapping to Excel">&#128229; Export</button>
    <button class="btn btn-outline" id="btnImport" title="Import mapping from Excel">&#128228; Import</button>
    <span class="status-text" id="statusText">Ready</span>
</div>

<!-- Hidden form for import -->
<form id="importForm" class="hidden-input" enctype="multipart/form-data">
    <?= $csrfInputHtml ?>
    <input type="hidden" name="action" value="validate">
    <input type="file" name="file" id="importFile" accept=".xlsx,.xls">
</form>

<div style="height:16px;"></div>

<?= uiWorkspaceEnd() ?>
