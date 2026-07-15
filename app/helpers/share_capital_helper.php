<?php
/**
 * e-BAL — Share Capital shareholders (Schedule III Note 1, >5% holding disclosure)
 */

function ensureShareCapitalShareholdersSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS share_capital_shareholders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            row_order INT NOT NULL DEFAULT 0,
            shareholder_name VARCHAR(255) NOT NULL,
            shares_held DECIMAL(18,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Replace the full shareholder list for a company/FY. Rows with a blank
 * name are dropped -- the caller doesn't need to pre-filter empty form rows.
 *
 * @param array<int, array{name: string, shares: float|string}> $rows
 */
function saveShareholders(PDO $pdo, int $company_id, int $fy_id, array $rows): void
{
    ensureShareCapitalShareholdersSchema($pdo);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM share_capital_shareholders WHERE company_id = ? AND fy_id = ?")
            ->execute([$company_id, $fy_id]);

        $insertStmt = $pdo->prepare("
            INSERT INTO share_capital_shareholders (company_id, fy_id, row_order, shareholder_name, shares_held)
            VALUES (?, ?, ?, ?, ?)
        ");

        $order = 0;
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $shares = (float) ($row['shares'] ?? 0);
            if ($name === '' || $shares <= 0.0) {
                continue;
            }
            $insertStmt->execute([$company_id, $fy_id, $order, $name, $shares]);
            $order++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array<int, array{name: string, shares: float}>
 */
function getShareholders(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureShareCapitalShareholdersSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT shareholder_name, shares_held
        FROM share_capital_shareholders
        WHERE company_id = ? AND fy_id = ?
        ORDER BY row_order ASC
    ");
    $stmt->execute([$company_id, $fy_id]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'name' => (string) $row['shareholder_name'],
            'shares' => (float) $row['shares_held'],
        ];
    }

    return $rows;
}
