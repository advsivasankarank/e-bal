<!-- Action Bar — Group Mode -->
<div class="wb-actions">
    <button class="btn btn-success" id="btnGroupSave" title="Save group mappings">&#128190; Save Changes</button>
    <button class="btn btn-outline" id="btnGroupReset" title="Reset group changes">&#8617; Reset</button>
    <div class="sep"></div>
    <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?mode=ledger" class="btn" style="background:#7c3aed;color:#fff;border-color:#7c3aed;text-decoration:none;">Ledger-wise Mapping &#8594;</a>
    <span class="status-text" id="statusText">Ready</span>
</div>

<div style="height:16px;"></div>

<?= uiWorkspaceEnd() ?>
