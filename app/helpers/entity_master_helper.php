<?php
/**
 * Entity Management Master Helper
 * CRUD for auditor_master, director_master, company_fy_auditors, company_directors,
 * company_fy_signatories, partner_master, company_partners, partner_capital_movements.
 */

/* =========================
   AUDITOR MASTER
========================= */

function searchAuditors(PDO $pdo, int $ownerId, string $query = '', bool $activeOnly = true): array
{
    $sql = "SELECT auditor_id, firm_name, frn, partner_name, membership_no, email, mobile, address, peer_review_no, peer_review_valid_upto
            FROM auditor_master WHERE owner_user_id = ?";
    $params = [$ownerId];
    if ($activeOnly) $sql .= " AND active_status = 1";
    if ($query !== '') {
        $sql .= " AND (firm_name LIKE ? OR partner_name LIKE ? OR frn LIKE ? OR membership_no LIKE ?)";
        $like = "%{$query}%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY firm_name ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAuditor(PDO $pdo, int $auditorId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM auditor_master WHERE auditor_id = ?");
    $stmt->execute([$auditorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createAuditor(PDO $pdo, int $ownerId, array $data): int
{
    $stmt = $pdo->prepare("
        INSERT INTO auditor_master (owner_user_id, firm_name, frn, partner_name, membership_no, address, city, state, email, mobile, peer_review_no, peer_review_valid_upto, active_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $ownerId, $data['firm_name'] ?? '', $data['frn'] ?? '', $data['partner_name'] ?? '',
        $data['membership_no'] ?? '', $data['address'] ?? null, $data['city'] ?? '', $data['state'] ?? '',
        $data['email'] ?? '', $data['mobile'] ?? '', $data['peer_review_no'] ?? '', $data['peer_review_valid_upto'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/* =========================
   DIRECTOR MASTER
========================= */

function searchDirectors(PDO $pdo, int $ownerId, string $query = '', bool $activeOnly = true): array
{
    $sql = "SELECT director_id, director_name, din, pan, email, mobile FROM director_master WHERE owner_user_id = ?";
    $params = [$ownerId];
    if ($activeOnly) $sql .= " AND active_status = 1";
    if ($query !== '') {
        $sql .= " AND (director_name LIKE ? OR din LIKE ? OR pan LIKE ?)";
        $like = "%{$query}%"; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY director_name ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDirector(PDO $pdo, int $directorId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM director_master WHERE director_id = ?");
    $stmt->execute([$directorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createDirector(PDO $pdo, int $ownerId, array $data): int
{
    $stmt = $pdo->prepare("INSERT INTO director_master (owner_user_id, director_name, din, pan, email, mobile, address, active_status) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
    $stmt->execute([$ownerId, $data['director_name'] ?? '', $data['din'] ?? '', $data['pan'] ?? '', $data['email'] ?? '', $data['mobile'] ?? '', $data['address'] ?? null]);
    return (int) $pdo->lastInsertId();
}

/* =========================
   COMPANY FY AUDITORS (renamed from company_fy_governance)
========================= */

function getCompanyAuditor(PDO $pdo, int $companyId, int $fyId): ?array
{
    $stmt = $pdo->prepare("
        SELECT g.*, a.firm_name AS auditor_firm_name, a.partner_name AS auditor_partner_name,
               a.frn AS auditor_frn, a.membership_no AS auditor_membership_no, a.email AS auditor_email, a.mobile AS auditor_mobile
        FROM company_fy_auditors g
        LEFT JOIN auditor_master a ON a.auditor_id = g.auditor_id
        WHERE g.company_id = ? AND g.fy_id = ?
    ");
    $stmt->execute([$companyId, $fyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function saveCompanyAuditor(PDO $pdo, int $companyId, int $fyId, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO company_fy_auditors (company_id, fy_id, auditor_id, appointment_date, report_date, audit_type, signed_partner, signed_membership_no, udin, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            auditor_id=VALUES(auditor_id), appointment_date=VALUES(appointment_date), report_date=VALUES(report_date),
            audit_type=VALUES(audit_type), signed_partner=VALUES(signed_partner), signed_membership_no=VALUES(signed_membership_no),
            udin=VALUES(udin), remarks=VALUES(remarks), updated_at=NOW()
    ");
    $stmt->execute([
        $companyId, $fyId, $data['auditor_id'] ?? null, $data['appointment_date'] ?? null,
        $data['report_date'] ?? null, $data['audit_type'] ?? 'statutory', $data['signed_partner'] ?? '',
        $data['signed_membership_no'] ?? '', $data['udin'] ?? '', $data['remarks'] ?? '',
    ]);
}

function getCompanyAuditorHistory(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare("
        SELECT g.*, a.firm_name AS auditor_firm_name, a.partner_name AS auditor_partner_name, fy.fy_label
        FROM company_fy_auditors g
        LEFT JOIN auditor_master a ON a.auditor_id = g.auditor_id
        LEFT JOIN financial_years fy ON fy.id = g.fy_id
        WHERE g.company_id = ?
        ORDER BY fy.fy_start DESC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   COMPANY DIRECTORS
========================= */

function getCompanyDirectors(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare("
        SELECT cd.*, dm.director_name, dm.din, dm.pan, dm.email AS director_email, dm.mobile AS director_mobile
        FROM company_directors cd INNER JOIN director_master dm ON dm.director_id = cd.director_id
        WHERE cd.company_id = ? ORDER BY cd.signing_order ASC, cd.appointment_date DESC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addCompanyDirector(PDO $pdo, int $companyId, int $directorId, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO company_directors (company_id, director_id, designation, appointment_date, cessation_date, signing_authority, signing_order, active_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE designation=VALUES(designation), appointment_date=VALUES(appointment_date),
            cessation_date=VALUES(cessation_date), signing_authority=VALUES(signing_authority),
            signing_order=VALUES(signing_order), active_status=VALUES(active_status), updated_at=NOW()
    ");
    $stmt->execute([$companyId, $directorId, $data['designation'] ?? '', $data['appointment_date'] ?? null,
        $data['cessation_date'] ?? null, $data['signing_authority'] ?? '', $data['signing_order'] ?? 0]);
}

/* =========================
   COMPANY FY SIGNATORIES
========================= */

function getCompanySignatories(PDO $pdo, int $companyId, int $fyId): array
{
    $stmt = $pdo->prepare("SELECT cfs.*, dm.director_name, dm.din FROM company_fy_signatories cfs
        INNER JOIN director_master dm ON dm.director_id = cfs.director_id
        WHERE cfs.company_id = ? AND cfs.fy_id = ? ORDER BY cfs.signing_order ASC");
    $stmt->execute([$companyId, $fyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   PARTNER MASTER
========================= */

function searchPartners(PDO $pdo, int $ownerId, string $query = '', bool $activeOnly = true): array
{
    $sql = "SELECT partner_id, partner_name, pan, email, mobile FROM partner_master WHERE owner_user_id = ?";
    $params = [$ownerId];
    if ($activeOnly) $sql .= " AND active_status = 1";
    if ($query !== '') {
        $sql .= " AND (partner_name LIKE ? OR pan LIKE ?)";
        $like = "%{$query}%"; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY partner_name ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPartner(PDO $pdo, int $partnerId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM partner_master WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createPartner(PDO $pdo, int $ownerId, array $data): int
{
    $stmt = $pdo->prepare("INSERT INTO partner_master (owner_user_id, partner_name, pan, email, mobile, address, active_status) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmt->execute([$ownerId, $data['partner_name'] ?? '', $data['pan'] ?? '', $data['email'] ?? '', $data['mobile'] ?? '', $data['address'] ?? null]);
    return (int) $pdo->lastInsertId();
}

/* =========================
   COMPANY PARTNERS
========================= */

function getCompanyPartners(PDO $pdo, int $companyId, bool $activeOnly = true): array
{
    $sql = "
        SELECT cp.*, pm.partner_name, pm.pan, pm.email AS partner_email, pm.mobile AS partner_mobile
        FROM company_partners cp INNER JOIN partner_master pm ON pm.partner_id = cp.partner_id
        WHERE cp.company_id = ?";
    if ($activeOnly) $sql .= " AND cp.active_status = 1";
    $sql .= " ORDER BY cp.appointment_date ASC, pm.partner_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addCompanyPartner(PDO $pdo, int $companyId, int $partnerId, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO company_partners (company_id, partner_id, designation, appointment_date, cessation_date, active_status)
        VALUES (?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE designation=VALUES(designation), appointment_date=VALUES(appointment_date),
            cessation_date=VALUES(cessation_date), active_status=VALUES(active_status), updated_at=NOW()
    ");
    $stmt->execute([$companyId, $partnerId, $data['designation'] ?? 'Partner', $data['appointment_date'] ?? null,
        $data['cessation_date'] ?? null]);
}

/* =========================
   PARTNER CAPITAL MOVEMENTS (per FY)
========================= */

/**
 * Load this FY's capital-movement rows for a company, one per active partner.
 * Opening balance is pre-filled from last FY's closing balance (carry-forward,
 * same convention as loadManualInputsWithCarryForward()) when this FY has no
 * saved row of its own yet. Share % also carries forward as a default, since
 * ratios usually only change on partner admission/retirement/deed revision,
 * not every year.
 */
function getPartnerCapitalSchedule(PDO $pdo, int $companyId, int $fyId, ?int $previousFyId): array
{
    $partners = getCompanyPartners($pdo, $companyId, true);
    if (empty($partners)) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT * FROM partner_capital_movements WHERE company_id = ? AND fy_id = ?");
    $stmt->execute([$companyId, $fyId]);
    $currentByPartner = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $currentByPartner[(int) $row['partner_id']] = $row;
    }

    $previousByPartner = [];
    if ($previousFyId !== null) {
        $prevStmt = $pdo->prepare("SELECT * FROM partner_capital_movements WHERE company_id = ? AND fy_id = ?");
        $prevStmt->execute([$companyId, $previousFyId]);
        foreach ($prevStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $previousByPartner[(int) $row['partner_id']] = $row;
        }
    }

    $schedule = [];
    foreach ($partners as $partner) {
        $partnerId = (int) $partner['partner_id'];
        $current = $currentByPartner[$partnerId] ?? null;
        $previous = $previousByPartner[$partnerId] ?? null;

        $previousClosing = $previous !== null ? partnerCapitalClosingBalance($previous) : 0.0;

        $schedule[] = [
            'partner_id' => $partnerId,
            'partner_name' => $partner['partner_name'],
            'designation' => $partner['designation'],
            'share_percentage' => $current !== null
                ? (float) $current['share_percentage']
                : (float) ($previous['share_percentage'] ?? 0),
            'opening_balance' => $current !== null ? (float) $current['opening_balance'] : $previousClosing,
            'capital_introduced' => (float) ($current['capital_introduced'] ?? 0),
            'remuneration' => (float) ($current['remuneration'] ?? 0),
            'interest_on_capital' => (float) ($current['interest_on_capital'] ?? 0),
            'withdrawals' => (float) ($current['withdrawals'] ?? 0),
            'is_saved' => $current !== null,
        ];
    }

    return $schedule;
}

/**
 * closing = opening + capital_introduced + remuneration + interest_on_capital
 *           + share_of_profit - withdrawals
 * share_of_profit is not stored on the row -- pass it in explicitly so this
 * stays correct whether called with a stored row (no share_of_profit key,
 * defaults to 0) or a schedule entry that already has it computed.
 */
function partnerCapitalClosingBalance(array $row, float $shareOfProfit = 0.0): float
{
    return (float) ($row['opening_balance'] ?? 0)
        + (float) ($row['capital_introduced'] ?? 0)
        + (float) ($row['remuneration'] ?? 0)
        + (float) ($row['interest_on_capital'] ?? 0)
        + $shareOfProfit
        - (float) ($row['withdrawals'] ?? 0);
}

function savePartnerCapitalMovement(PDO $pdo, int $companyId, int $fyId, int $partnerId, array $data, ?int $userId = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO partner_capital_movements
            (company_id, fy_id, partner_id, share_percentage, opening_balance, capital_introduced, remuneration, interest_on_capital, withdrawals, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            share_percentage = VALUES(share_percentage),
            opening_balance = VALUES(opening_balance),
            capital_introduced = VALUES(capital_introduced),
            remuneration = VALUES(remuneration),
            interest_on_capital = VALUES(interest_on_capital),
            withdrawals = VALUES(withdrawals),
            updated_at = NOW()
    ");
    $stmt->execute([
        $companyId, $fyId, $partnerId,
        (float) ($data['share_percentage'] ?? 0),
        (float) ($data['opening_balance'] ?? 0),
        (float) ($data['capital_introduced'] ?? 0),
        (float) ($data['remuneration'] ?? 0),
        (float) ($data['interest_on_capital'] ?? 0),
        (float) ($data['withdrawals'] ?? 0),
        $userId,
    ]);
}

/* =========================
   ENSURE SCHEMA (idempotent)
========================= */

function ensureEntityMasterSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        if (!appAllowsRuntimeSchema()) {
            assertTableExists($pdo, 'auditor_master');
            assertTableExists($pdo, 'director_master');
            assertTableExists($pdo, 'company_fy_auditors');
            assertTableExists($pdo, 'company_directors');
            assertTableExists($pdo, 'company_fy_signatories');
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS auditor_master (
            auditor_id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NULL,
            firm_name VARCHAR(255) NOT NULL DEFAULT '', frn VARCHAR(50) NOT NULL DEFAULT '',
            partner_name VARCHAR(255) NOT NULL DEFAULT '', membership_no VARCHAR(50) NOT NULL DEFAULT '',
            address TEXT NULL, city VARCHAR(100) NOT NULL DEFAULT '', state VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '', mobile VARCHAR(20) NOT NULL DEFAULT '',
            peer_review_no VARCHAR(50) NOT NULL DEFAULT '', peer_review_valid_upto DATE NULL,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_auditor_owner (owner_user_id), INDEX idx_auditor_firm (firm_name))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS director_master (
            director_id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NULL,
            director_name VARCHAR(255) NOT NULL DEFAULT '', din VARCHAR(50) NOT NULL DEFAULT '',
            pan VARCHAR(20) NOT NULL DEFAULT '', email VARCHAR(255) NOT NULL DEFAULT '',
            mobile VARCHAR(20) NOT NULL DEFAULT '', address TEXT NULL,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_director_owner (owner_user_id), INDEX idx_director_name (director_name))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_fy_auditors (
            id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, fy_id INT NOT NULL,
            auditor_id INT NULL, appointment_date DATE NULL, report_date DATE NULL,
            audit_type VARCHAR(50) NOT NULL DEFAULT 'statutory',
            signed_partner VARCHAR(255) NOT NULL DEFAULT '', signed_membership_no VARCHAR(50) NOT NULL DEFAULT '',
            udin VARCHAR(50) NOT NULL DEFAULT '', remarks TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_auditor_fy (company_id, fy_id), INDEX idx_auditor_company (company_id))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_directors (
            id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, director_id INT NOT NULL,
            designation VARCHAR(100) NOT NULL DEFAULT '', appointment_date DATE NULL, cessation_date DATE NULL,
            signing_authority VARCHAR(255) NOT NULL DEFAULT '', signing_order INT NOT NULL DEFAULT 0,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_director (company_id, director_id), INDEX idx_cd_company (company_id))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_fy_signatories (
            id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, fy_id INT NOT NULL, director_id INT NOT NULL,
            is_signing_person TINYINT(1) NOT NULL DEFAULT 1, signing_order INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fy_signatory (company_id, fy_id, director_id), INDEX idx_fys_company (company_id))");
    } catch (Throwable $e) {}
}

/**
 * Deliberately separate from ensureEntityMasterSchema() above, which is
 * called from company_create.php for EVERY company type. If partner tables
 * were added to that shared assert list, a production database that hasn't
 * run migration 009 yet would fail company creation entirely -- not just
 * the partner feature. This function is only called from partner-specific
 * code paths, so a missing migration only affects those.
 */
function ensurePartnerCapitalSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        if (!appAllowsRuntimeSchema()) {
            assertTableExists($pdo, 'partner_master');
            assertTableExists($pdo, 'company_partners');
            assertTableExists($pdo, 'partner_capital_movements');
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_master (
            partner_id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NULL,
            partner_name VARCHAR(255) NOT NULL DEFAULT '', pan VARCHAR(20) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '', mobile VARCHAR(20) NOT NULL DEFAULT '', address TEXT NULL,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_partner_owner (owner_user_id), INDEX idx_partner_name (partner_name))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_partners (
            id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, partner_id INT NOT NULL,
            designation VARCHAR(100) NOT NULL DEFAULT 'Partner', appointment_date DATE NULL, cessation_date DATE NULL,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_partner (company_id, partner_id), INDEX idx_cp_company (company_id))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_capital_movements (
            id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, fy_id INT NOT NULL, partner_id INT NOT NULL,
            share_percentage DECIMAL(5,2) NOT NULL DEFAULT 0, opening_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
            capital_introduced DECIMAL(18,2) NOT NULL DEFAULT 0, remuneration DECIMAL(18,2) NOT NULL DEFAULT 0,
            interest_on_capital DECIMAL(18,2) NOT NULL DEFAULT 0, withdrawals DECIMAL(18,2) NOT NULL DEFAULT 0,
            created_by_user_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_partner_fy (company_id, fy_id, partner_id), INDEX idx_pcm_company_fy (company_id, fy_id))");
    } catch (Throwable $e) {}
}
