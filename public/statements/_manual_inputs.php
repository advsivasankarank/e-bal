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
    <span class="toggle" id="fsTogglePanel"><?= !empty($manualNoteDataIncomplete) ? '&#9664; Hide' : '&#9654; Show' ?></span>
</div>
<div class="panel-body">
    <?php if ($isCorporate): ?>
    <?php if (!empty($showShareCapitalCarryForwardPrompt)): ?>
    <div class="fs-carry-forward-prompt" id="shareCapitalCarryForwardPrompt" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:14px;font-size:0.85rem;">
        <div style="font-weight:600;margin-bottom:6px;">Share Capital details found for FY <?= htmlspecialchars($prevFyLabel) ?></div>
        <div style="color:#475569;margin-bottom:10px;">No Note 1 (Share Capital) details are saved yet for this year. Carry forward last year's share-type breakup (Equity/Preference etc.) and shareholder list as-is, or enter fresh values below.</div>
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

        <h4 style="margin:16px 0 8px;font-size:0.85rem;color:var(--muted);">Share Capital Details by Share Type (Note 1)</h4>
        <div style="font-size:0.78rem;color:var(--muted);margin-bottom:8px;">Add one row per share type (e.g. Equity Shares, 8% Preference Shares). Authorised/Issued/Paid-up amounts are computed as Face Value &times; Number of Shares.</div>
        <div id="shareClassRows">
            <?php $shareClassRowsData = $shareCapitalClasses ?: [['share_type' => '', 'face_value' => '', 'authorised_shares' => '', 'opening_shares' => '', 'issued_during_year' => '', 'bought_back_during_year' => '', 'closing_shares' => '']]; ?>
            <?php foreach ($shareClassRowsData as $cls): ?>
            <div class="form-group share-class-row" style="border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:10px;margin-bottom:10px;">
                <div style="display:flex;gap:6px;align-items:flex-end;margin-bottom:6px;">
                    <div style="flex:2;">
                        <label>Share Type</label>
                        <input type="text" name="share_class_type[]" placeholder="e.g. Equity Shares" value="<?= htmlspecialchars((string) ($cls['share_type'] ?? '')) ?>">
                    </div>
                    <div style="flex:1;">
                        <label>Face Value</label>
                        <input type="number" step="0.01" name="share_class_face_value[]" value="<?= htmlspecialchars((string) ($cls['face_value'] ?? '')) ?>">
                    </div>
                    <button type="button" class="btn-outline btn-sm remove-share-class-row" style="padding:6px 10px;">&times;</button>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:110px;">
                        <label>Authorised Shares</label>
                        <input type="number" step="1" name="share_class_authorised_shares[]" value="<?= htmlspecialchars((string) ($cls['authorised_shares'] ?? '')) ?>">
                    </div>
                    <div style="flex:1;min-width:110px;">
                        <label>Opening Shares</label>
                        <input type="number" step="1" name="share_class_opening_shares[]" value="<?= htmlspecialchars((string) ($cls['opening_shares'] ?? '')) ?>">
                    </div>
                    <div style="flex:1;min-width:110px;">
                        <label>Issued During Year</label>
                        <input type="number" step="1" name="share_class_issued_during_year[]" value="<?= htmlspecialchars((string) ($cls['issued_during_year'] ?? '')) ?>">
                    </div>
                    <div style="flex:1;min-width:110px;">
                        <label>Bought Back During Year</label>
                        <input type="number" step="1" name="share_class_bought_back_during_year[]" value="<?= htmlspecialchars((string) ($cls['bought_back_during_year'] ?? '')) ?>">
                    </div>
                    <div style="flex:1;min-width:110px;">
                        <label>Closing Shares (Paid-up)</label>
                        <input type="number" step="1" name="share_class_closing_shares[]" value="<?= htmlspecialchars((string) ($cls['closing_shares'] ?? '')) ?>">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="addShareClassRow" class="btn-outline btn-sm" style="margin-bottom:12px;">+ Add Share Type</button>

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

        <h4 style="margin:16px 0 8px;font-size:0.85rem;color:var(--muted);">Disclosure Notes</h4>
        <div style="font-size:0.78rem;color:var(--muted);margin-bottom:8px;">Each starts from a standard "nothing to disclose" position -- edit only if this company actually has one of these.</div>
        <div class="form-group">
            <label for="note_disclosure_cl">Contingent Liabilities</label>
            <textarea id="note_disclosure_cl" name="note_disclosure_cl" rows="2"><?= htmlspecialchars((string) ($manualBundle['current']['note_disclosure_cl'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
            <label for="note_disclosure_com">Commitments</label>
            <textarea id="note_disclosure_com" name="note_disclosure_com" rows="2"><?= htmlspecialchars((string) ($manualBundle['current']['note_disclosure_com'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
            <label for="note_disclosure_msme">MSME Disclosure</label>
            <textarea id="note_disclosure_msme" name="note_disclosure_msme" rows="2"><?= htmlspecialchars((string) ($manualBundle['current']['note_disclosure_msme'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
            <label for="note_disclosure_rpt">Related Party Transactions</label>
            <textarea id="note_disclosure_rpt" name="note_disclosure_rpt" rows="2"><?= htmlspecialchars((string) ($manualBundle['current']['note_disclosure_rpt'] ?? '')) ?></textarea>
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
        var rowsContainer = document.getElementById('shareClassRows');
        var addBtn = document.getElementById('addShareClassRow');
        if (!rowsContainer || !addBtn) return;

        function wireRemove(row) {
            var btn = row.querySelector('.remove-share-class-row');
            if (!btn) return;
            btn.addEventListener('click', function () {
                if (rowsContainer.querySelectorAll('.share-class-row').length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                }
            });
        }

        rowsContainer.querySelectorAll('.share-class-row').forEach(wireRemove);

        addBtn.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'form-group share-class-row';
            row.style.cssText = 'border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:10px;margin-bottom:10px;';
            row.innerHTML =
                '<div style="display:flex;gap:6px;align-items:flex-end;margin-bottom:6px;">' +
                '<div style="flex:2;"><label>Share Type</label><input type="text" name="share_class_type[]" placeholder="e.g. Equity Shares" value=""></div>' +
                '<div style="flex:1;"><label>Face Value</label><input type="number" step="0.01" name="share_class_face_value[]" value=""></div>' +
                '<button type="button" class="btn-outline btn-sm remove-share-class-row" style="padding:6px 10px;">&times;</button>' +
                '</div>' +
                '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
                '<div style="flex:1;min-width:110px;"><label>Authorised Shares</label><input type="number" step="1" name="share_class_authorised_shares[]" value=""></div>' +
                '<div style="flex:1;min-width:110px;"><label>Opening Shares</label><input type="number" step="1" name="share_class_opening_shares[]" value=""></div>' +
                '<div style="flex:1;min-width:110px;"><label>Issued During Year</label><input type="number" step="1" name="share_class_issued_during_year[]" value=""></div>' +
                '<div style="flex:1;min-width:110px;"><label>Bought Back During Year</label><input type="number" step="1" name="share_class_bought_back_during_year[]" value=""></div>' +
                '<div style="flex:1;min-width:110px;"><label>Closing Shares (Paid-up)</label><input type="number" step="1" name="share_class_closing_shares[]" value=""></div>' +
                '</div>';
            rowsContainer.appendChild(row);
            wireRemove(row);
        });
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
