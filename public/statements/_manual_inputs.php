<?php
/**
 * Manual Input Panel (slide-out drawer)
 * Corporate: Share capital, inventory, P&L opening fields
 * Others: Informational display
 * Expects: $isCorporate, $manualBundle, CSRF token
 */
if (!isset($isCorporate)) return;
?>
<div class="panel-header">
    <h3>Manual Adjustments</h3>
    <span class="toggle" id="fsTogglePanel">&#9654; Show</span>
</div>
<div class="panel-body">
    <?php if ($isCorporate): ?>
    <form method="post" id="manualInputForm">
        <?= csrfInput() ?>
        <input type="hidden" name="report_action" value="save_manual_company_note">
        <div class="form-group">
            <label for="share_capital_authorised">Authorised Capital</label>
            <input id="share_capital_authorised" name="share_capital_authorised" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_authorised'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_issued">Issued Capital</label>
            <input id="share_capital_issued" name="share_capital_issued" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_issued'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_paidup">Paid-up Capital</label>
            <input id="share_capital_paidup" name="share_capital_paidup" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_paidup'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note2_opening_profit_loss">Opening P&amp;L Balance</label>
            <input id="note2_opening_profit_loss" name="note2_opening_profit_loss" type="number" step="0.01" value="<?= htmlspecialchars((string) (($manualBundle['saved_current']['note2_opening_profit_loss'] ?? '') !== '' ? $manualBundle['saved_current']['note2_opening_profit_loss'] : ($fs['notes']['other_equity']['opening_balance'] ?? ''))) ?>">
        </div>
        <div class="form-group">
            <label for="note16_opening_raw_materials">Opening Raw Materials</label>
            <input id="note16_opening_raw_materials" name="note16_opening_raw_materials" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note16_opening_raw_materials'] ?? $manualBundle['previous']['note16_closing_raw_materials'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note16_closing_raw_materials">Closing Raw Materials</label>
            <input id="note16_closing_raw_materials" name="note16_closing_raw_materials" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note16_closing_raw_materials'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note24_opening_finished_goods">Opening Finished Goods</label>
            <input id="note24_opening_finished_goods" name="note24_opening_finished_goods" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_finished_goods'] ?? $manualBundle['previous']['note24_closing_finished_goods'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note24_opening_work_in_progress">Opening WIP</label>
            <input id="note24_opening_work_in_progress" name="note24_opening_work_in_progress" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_work_in_progress'] ?? $manualBundle['previous']['note24_closing_work_in_progress'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note24_closing_finished_goods">Closing Finished Goods</label>
            <input id="note24_closing_finished_goods" name="note24_closing_finished_goods" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_finished_goods'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note24_closing_work_in_progress">Closing WIP</label>
            <input id="note24_closing_work_in_progress" name="note24_closing_work_in_progress" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_work_in_progress'] ?? '')) ?>">
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;">Save Adjustments</button>
    </form>
    <?php else: ?>
    <div style="font-size:0.85rem;color:var(--muted);padding:8px 0;">Manual adjustments for this entity type are configured in the report engine.</div>
    <?php endif; ?>
</div>
