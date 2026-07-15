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
    <?php if (!empty($showShareCapitalCarryForwardPrompt)): ?>
    <div class="fs-carry-forward-prompt" id="shareCapitalCarryForwardPrompt" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:14px;font-size:0.85rem;">
        <div style="font-weight:600;margin-bottom:6px;">Share Capital details found for FY <?= htmlspecialchars($prevFyLabel) ?></div>
        <div style="color:#475569;margin-bottom:10px;">No Note 1 (Share Capital) details are saved yet for this year. Carry forward last year's authorised/issued shares and shareholder list as-is, or enter fresh values below.</div>
        <div style="display:flex;gap:8px;">
            <form method="post" style="margin:0;" onsubmit="return confirm('Carry forward Note 1 Share Capital details from FY <?= htmlspecialchars(addslashes($prevFyLabel)) ?> as-is? You can still edit them afterward.');">
                <?= csrfInput() ?>
                <input type="hidden" name="report_action" value="carry_forward_share_capital">
                <button type="submit" class="btn btn-primary" style="font-size:0.8rem;padding:6px 12px;">Carry Forward As-Is</button>
            </form>
            <button type="button" id="shareCapitalEnterFresh" class="btn-outline btn-sm" style="font-size:0.8rem;padding:6px 12px;">Enter Fresh</button>
        </div>
    </div>
    <?php endif; ?>
    <form method="post" id="manualInputForm"<?= !empty($showShareCapitalCarryForwardPrompt) ? ' style="display:none;"' : '' ?>>
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

        <h4 style="margin:16px 0 8px;font-size:0.85rem;color:var(--muted);">Share Capital Details (Note 1)</h4>
        <div class="form-group">
            <label for="share_capital_authorised_shares">Authorised Shares (Number)</label>
            <input id="share_capital_authorised_shares" name="share_capital_authorised_shares" type="number" step="1" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_authorised_shares'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_face_value">Face Value per Share</label>
            <input id="share_capital_face_value" name="share_capital_face_value" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_face_value'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_opening_shares">Opening Shares (Number)</label>
            <input id="share_capital_opening_shares" name="share_capital_opening_shares" type="number" step="1" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_opening_shares'] ?? $manualBundle['previous']['share_capital_issued_shares'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_shares_issued_during_year">Shares Issued During Year</label>
            <input id="share_capital_shares_issued_during_year" name="share_capital_shares_issued_during_year" type="number" step="1" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_shares_issued_during_year'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_shares_bought_back_during_year">Shares Bought Back During Year</label>
            <input id="share_capital_shares_bought_back_during_year" name="share_capital_shares_bought_back_during_year" type="number" step="1" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_shares_bought_back_during_year'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="share_capital_issued_shares">Closing Shares (Issued &amp; Fully Paid)</label>
            <input id="share_capital_issued_shares" name="share_capital_issued_shares" type="number" step="1" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_issued_shares'] ?? '')) ?>">
        </div>

        <h4 style="margin:16px 0 8px;font-size:0.85rem;color:var(--muted);">Shareholders Holding &gt;5% (Note 1)</h4>
        <div id="shareholderRows">
            <?php $shareholderRows = $shareholders ?: [['name' => '', 'shares' => '']]; ?>
            <?php foreach ($shareholderRows as $sh): ?>
            <div class="form-group shareholder-row" style="display:flex;gap:6px;align-items:flex-end;">
                <div style="flex:2;">
                    <label>Name</label>
                    <input type="text" name="shareholder_name[]" value="<?= htmlspecialchars((string) ($sh['name'] ?? '')) ?>">
                </div>
                <div style="flex:1;">
                    <label>Shares</label>
                    <input type="number" step="1" name="shareholder_shares[]" value="<?= htmlspecialchars((string) ($sh['shares'] ?? '')) ?>">
                </div>
                <button type="button" class="btn-outline btn-sm remove-shareholder-row" style="padding:6px 10px;">&times;</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="addShareholderRow" class="btn-outline btn-sm" style="margin-bottom:12px;">+ Add Shareholder</button>

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
        <div class="form-group">
            <label for="tax_provision">Provision for Tax (Current Year)</label>
            <input id="tax_provision" name="tax_provision" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['tax_provision'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note24_opening_stock_in_trade">Opening Stock-in-Trade</label>
            <input id="note24_opening_stock_in_trade" name="note24_opening_stock_in_trade" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_stock_in_trade'] ?? $manualBundle['previous']['note24_closing_stock_in_trade'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="note24_closing_stock_in_trade">Closing Stock-in-Trade</label>
            <input id="note24_closing_stock_in_trade" name="note24_closing_stock_in_trade" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_stock_in_trade'] ?? '')) ?>">
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;">Save Adjustments</button>
    </form>
    <script>
    (function () {
        var enterFreshBtn = document.getElementById('shareCapitalEnterFresh');
        var carryForwardPrompt = document.getElementById('shareCapitalCarryForwardPrompt');
        var manualForm = document.getElementById('manualInputForm');
        if (enterFreshBtn && carryForwardPrompt && manualForm) {
            enterFreshBtn.addEventListener('click', function () {
                carryForwardPrompt.style.display = 'none';
                manualForm.style.display = '';
            });
        }
    })();
    (function () {
        var rowsContainer = document.getElementById('shareholderRows');
        var addBtn = document.getElementById('addShareholderRow');
        if (!rowsContainer || !addBtn) return;

        function wireRemove(row) {
            var btn = row.querySelector('.remove-shareholder-row');
            if (!btn) return;
            btn.addEventListener('click', function () {
                if (rowsContainer.querySelectorAll('.shareholder-row').length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                }
            });
        }

        rowsContainer.querySelectorAll('.shareholder-row').forEach(wireRemove);

        addBtn.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'form-group shareholder-row';
            row.style.cssText = 'display:flex;gap:6px;align-items:flex-end;';
            row.innerHTML =
                '<div style="flex:2;"><label>Name</label><input type="text" name="shareholder_name[]" value=""></div>' +
                '<div style="flex:1;"><label>Shares</label><input type="number" step="1" name="shareholder_shares[]" value=""></div>' +
                '<button type="button" class="btn-outline btn-sm remove-shareholder-row" style="padding:6px 10px;">&times;</button>';
            rowsContainer.appendChild(row);
            wireRemove(row);
        });
    })();
    </script>
    <?php else: ?>
    <div style="font-size:0.85rem;color:var(--muted);padding:8px 0;">Manual adjustments for this entity type are configured in the report engine.</div>
    <?php endif; ?>
</div>
