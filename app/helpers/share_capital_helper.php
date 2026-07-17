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

function ensureShareCapitalClassesSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS share_capital_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            row_order INT NOT NULL DEFAULT 0,
            share_type VARCHAR(150) NOT NULL,
            face_value DECIMAL(18,2) NOT NULL DEFAULT 0,
            authorised_shares DECIMAL(18,2) NOT NULL DEFAULT 0,
            opening_shares DECIMAL(18,2) NOT NULL DEFAULT 0,
            issued_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
            bought_back_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
            closing_shares DECIMAL(18,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Replace the full share-class breakup (Equity, Preference, ...) for a
 * company/FY. Rows with a blank share type are dropped -- the caller
 * doesn't need to pre-filter empty form rows.
 *
 * @param array<int, array{share_type: string, face_value: float|string, authorised_shares: float|string, opening_shares: float|string, issued_during_year: float|string, bought_back_during_year: float|string, closing_shares: float|string}> $rows
 */
function saveShareCapitalClasses(PDO $pdo, int $company_id, int $fy_id, array $rows): void
{
    ensureShareCapitalClassesSchema($pdo);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM share_capital_classes WHERE company_id = ? AND fy_id = ?")
            ->execute([$company_id, $fy_id]);

        $insertStmt = $pdo->prepare("
            INSERT INTO share_capital_classes
                (company_id, fy_id, row_order, share_type, face_value, authorised_shares, opening_shares, issued_during_year, bought_back_during_year, closing_shares)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $order = 0;
        foreach ($rows as $row) {
            $shareType = trim((string) ($row['share_type'] ?? ''));
            if ($shareType === '') {
                continue;
            }
            $insertStmt->execute([
                $company_id,
                $fy_id,
                $order,
                $shareType,
                (float) ($row['face_value'] ?? 0),
                (float) ($row['authorised_shares'] ?? 0),
                (float) ($row['opening_shares'] ?? 0),
                (float) ($row['issued_during_year'] ?? 0),
                (float) ($row['bought_back_during_year'] ?? 0),
                (float) ($row['closing_shares'] ?? 0),
            ]);
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
 * @return array<int, array{share_type: string, face_value: float, authorised_shares: float, opening_shares: float, issued_during_year: float, bought_back_during_year: float, closing_shares: float}>
 */
function getShareCapitalClasses(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureShareCapitalClassesSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT share_type, face_value, authorised_shares, opening_shares, issued_during_year, bought_back_during_year, closing_shares
        FROM share_capital_classes
        WHERE company_id = ? AND fy_id = ?
        ORDER BY row_order ASC
    ");
    $stmt->execute([$company_id, $fy_id]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'share_type' => (string) $row['share_type'],
            'face_value' => (float) $row['face_value'],
            'authorised_shares' => (float) $row['authorised_shares'],
            'opening_shares' => (float) $row['opening_shares'],
            'issued_during_year' => (float) $row['issued_during_year'],
            'bought_back_during_year' => (float) $row['bought_back_during_year'],
            'closing_shares' => (float) $row['closing_shares'],
        ];
    }

    return $rows;
}
