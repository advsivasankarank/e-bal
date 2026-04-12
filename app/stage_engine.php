<?php
function getCompanyStage(PDO $pdo, int $company_id): string
{
    // 1. Check FY exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM financial_years WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $hasFY = $stmt->fetchColumn() > 0;

    if (!$hasFY) {
        return 'setup';
    }

    // 2. Check data imported (ledgers exist)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ledgers WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $ledgerCount = (int)$stmt->fetchColumn();

    if ($ledgerCount === 0) {
        return 'data';
    }

    // 3. Check mapping completeness
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM ledgers l
        LEFT JOIN ledger_mapping lm ON lm.ledger_id = l.id
        WHERE l.company_id = ? AND lm.ledger_id IS NULL
    ");
    $stmt->execute([$company_id]);
    $unmapped = (int)$stmt->fetchColumn();

    if ($unmapped > 0) {
        return 'mapping';
    }

    // 4. Check reports generated
    // (Adjust table/column names as per your system)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM reports 
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $hasReports = $stmt->fetchColumn() > 0;

    if (!$hasReports) {
        return 'reports';
    }

    // 5. Completed
    return 'completed';
}