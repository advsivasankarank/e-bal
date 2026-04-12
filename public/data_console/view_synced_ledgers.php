<?php
require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory);

$page_title = "Synced Ledgers";
require_once __DIR__ . '/../layouts/header.php';

$stmt = $pdo->prepare("
    SELECT
        tlm.ledger_name,
        tlm.parent_group,
        lm.schedule_code,
        tb.amount,
        tb.dr_cr
    FROM tally_ledger_master tlm
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = tlm.company_id
        AND lm.ledger_name = tlm.ledger_name
    LEFT JOIN tally_ledgers tb
        ON tb.company_id = tlm.company_id
        AND tb.fy_id = ?
        AND tb.ledger_name = tlm.ledger_name
    WHERE tlm.company_id = ?
    ORDER BY tlm.ledger_name
");
$stmt->execute([$fy_id, $company_id]);
$rows = $stmt->fetchAll();

$totalLedgers = count($rows);
$mappedCount = 0;
$tbCount = 0;

foreach ($rows as $row) {
    if (!empty($row['schedule_code'])) {
        $mappedCount++;
    }

    if ((float) ($row['amount'] ?? 0) != 0.0 && !empty($row['dr_cr'])) {
        $tbCount++;
    }
}
?>

<div class="page-title">Synced Ledgers</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    This view shows the synced ledger master for the selected company, the saved mapping head, and any live trial balance value already fetched for the active financial year.
</div>

<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= $totalLedgers ?></div>
        <div class="summary-label">Synced Ledgers</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= $mappedCount ?></div>
        <div class="summary-label">Mapped Ledgers</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= $tbCount ?></div>
        <div class="summary-label">Ledgers With TB Value</div>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Back to Mapping</a>
    <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php">Go to Trial Balance</a>
</div>

<table border="1" cellpadding="8" cellspacing="0" width="100%">
    <tr>
        <th>Ledger</th>
        <th>Parent Group</th>
        <th>Mapped Head</th>
        <th>Closing Balance</th>
        <th>DR/CR</th>
    </tr>

    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['ledger_name']) ?></td>
            <td><?= htmlspecialchars($row['parent_group'] ?: '-') ?></td>
            <td>
                <?php if (!empty($row['schedule_code'])): ?>
                    <?= htmlspecialchars($mappingEngine->getLabel($row['schedule_code'])) ?>
                <?php else: ?>
                    Unmapped
                <?php endif; ?>
            </td>
            <td><?= (float) ($row['amount'] ?? 0) != 0.0 ? number_format((float) $row['amount'], 2) : '-' ?></td>
            <td><?= htmlspecialchars($row['dr_cr'] ?: '-') ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
