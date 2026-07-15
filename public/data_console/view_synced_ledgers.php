<?php
require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/figure_helper.php';

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
require_once __DIR__ . '/../layouts/header_v2.php';

/* ---- Pagination (page/per_page convention matches reconhub_context_resolver.php) ---- */
const VSL_MIN_PER_PAGE = 25;
const VSL_MAX_PER_PAGE = 100;
const VSL_DEFAULT_PER_PAGE = 50;
$perPage = max(VSL_MIN_PER_PAGE, min(VSL_MAX_PER_PAGE, (int) ($_GET['per_page'] ?? VSL_DEFAULT_PER_PAGE)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* ---- KPI counts via SQL aggregates, not a PHP loop over every row ---- */
$countStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_ledgers,
        SUM(CASE WHEN lm.schedule_code IS NOT NULL AND lm.schedule_code != '' THEN 1 ELSE 0 END) AS mapped_count,
        SUM(CASE WHEN tb.amount IS NOT NULL AND tb.amount != 0 AND tb.dr_cr IS NOT NULL AND tb.dr_cr != '' THEN 1 ELSE 0 END) AS tb_count
    FROM tally_ledger_master tlm
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = tlm.company_id
        AND lm.ledger_name = tlm.ledger_name
    LEFT JOIN tally_ledgers tb
        ON tb.company_id = tlm.company_id
        AND tb.fy_id = ?
        AND tb.ledger_name = tlm.ledger_name
    WHERE tlm.company_id = ?
");
$countStmt->execute([$fy_id, $company_id]);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalLedgers = (int) ($counts['total_ledgers'] ?? 0);
$mappedCount = (int) ($counts['mapped_count'] ?? 0);
$tbCount = (int) ($counts['tb_count'] ?? 0);
$totalPages = max(1, (int) ceil($totalLedgers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

/* ---- Current page of rows only ---- */
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
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $fy_id, PDO::PARAM_INT);
$stmt->bindValue(2, $company_id, PDO::PARAM_INT);
$stmt->bindValue(3, $perPage, PDO::PARAM_INT);
$stmt->bindValue(4, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'dashboard_data.php'],
    ['label' => 'View Synced Ledgers']
]) ?>

<?= uiPageHero('Synced Ledgers') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy'      => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<div class="card" style="margin-bottom:20px;">
    This view shows the synced ledger master for the selected company, the saved mapping head, and any live trial balance value already fetched for the active financial year. The counts below cover all synced ledgers; the table is paginated.
</div>

<?= uiKpiCards([
    ['value' => $totalLedgers, 'label' => 'Synced Ledgers'],
    ['value' => $mappedCount, 'label' => 'Mapped Ledgers'],
    ['value' => $tbCount, 'label' => 'Ledgers With TB Value'],
]) ?>

<div class="card" style="margin-bottom:16px;">
    <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Back to Mapping</a>
    <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php?bridge=1">Go to Trial Balance</a>
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
            <td><?= (float) ($row['amount'] ?? 0) != 0.0 ? format_inr_number((float) $row['amount']) : '-' ?></td>
            <td><?= htmlspecialchars($row['dr_cr'] ?: '-') ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($totalPages > 1): ?>
<div class="card" style="margin-top:16px; display:flex; align-items:center; gap:12px;">
    <?php if ($page > 1): ?>
        <a class="btn" href="?page=<?= $page - 1 ?>&per_page=<?= $perPage ?>">&larr; Previous</a>
    <?php endif; ?>
    <span>Page <?= $page ?> of <?= $totalPages ?> (<?= $totalLedgers ?> ledgers total, <?= $perPage ?> per page)</span>
    <?php if ($page < $totalPages): ?>
        <a class="btn" href="?page=<?= $page + 1 ?>&per_page=<?= $perPage ?>">Next &rarr;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
