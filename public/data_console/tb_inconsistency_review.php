<?php
require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../config/app.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$unknowns = $_SESSION['pending_tb_unknowns'] ?? [];

if (empty($unknowns)) {
    header('Location: ' . BASE_URL . 'data_console/tally_connect.php');
    exit;
}

$company_id = $_SESSION['company_id'];
$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory);
$mappingOptions = $mappingEngine->getMappingOptions();

$page_title = "TB Inconsistency Review";
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Trial Balance Inconsistency Review</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="error-box" style="margin-bottom:20px;">
    <p>The trial balance contains ledgers that are not present in the synced ledger master. Review them carefully, then confirm whether they should be added to the ledger master and mapped before the TB import continues.</p>
</div>

<form method="post" action="<?= BASE_URL ?>data_console/tb_inconsistency_save.php">
    <table border="1" cellpadding="8" cellspacing="0" width="100%">
        <tr>
            <th>Approve</th>
            <th>Ledger</th>
            <th>Amount</th>
            <th>DR/CR</th>
            <th>Parent Group</th>
            <th>Schedule Head</th>
        </tr>

        <?php foreach ($unknowns as $row): ?>
            <?php
            $ledgerName = (string) ($row['ledger_name'] ?? '');
            $suggestion = $mappingEngine->mapLedger($ledgerName, '');
            $suggestedCode = ($suggestion['head'] ?? 'unmapped') !== 'unmapped' ? $suggestion['head'] : '';
            ?>
            <tr>
                <td><input type="checkbox" name="approve[<?= htmlspecialchars($ledgerName) ?>]" value="1"></td>
                <td><?= htmlspecialchars($ledgerName) ?></td>
                <td><?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                <td><?= htmlspecialchars($row['type'] ?? '-') ?></td>
                <td><input type="text" name="parent_group[<?= htmlspecialchars($ledgerName) ?>]" value="TB Added Ledger"></td>
                <td>
                    <select name="schedule_code[<?= htmlspecialchars($ledgerName) ?>]">
                        <option value="">Select Head</option>
                        <?php foreach ($mappingOptions as $optionCode => $optionLabel): ?>
                            <option value="<?= htmlspecialchars($optionCode) ?>" <?= $suggestedCode === $optionCode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
        <button type="submit" class="btn">Approve and Continue TB Import</button>
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
