<?php
/**
 * Entity Management Master Helper
 * CRUD operations for auditor_master, director_master,
 * company_fy_governance, company_directors, company_fy_signatories.
 */

/* =========================
   AUDITOR MASTER
========================= */

function searchAuditors(PDO $pdo, int $ownerId, string $query = '', bool $activeOnly = true): array
{
    $sql = "SELECT auditor_id, firm_name, frn, partner_name, membership_no, city, state, email, mobile
            FROM auditor_master WHERE owner_user_id = ?";
    $params = [$ownerId];

    if ($activeOnly) {
        $sql .= " AND active_status = 1";
    }
    if ($query !== '') {
        $sql .= " AND (firm_name LIKE ? OR partner_name LIKE ? OR frn LIKE ? OR membership_no LIKE ?)";
        $like = "%{$query}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
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
        $ownerId,
        $data['firm_name'] ?? '',
        $data['frn'] ?? '',
        $data['partner_name'] ?? '',
        $data['membership_no'] ?? '',
        $data['address'] ?? null,
        $data['city'] ?? '',
        $data['state'] ?? '',
        $data['email'] ?? '',
        $data['mobile'] ?? '',
        $data['peer_review_no'] ?? '',
        $data['peer_review_valid_upto'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function updateAuditor(PDO $pdo, int $auditorId, array $data): bool
{
    $stmt = $pdo->prepare("
        UPDATE auditor_master SET firm_name=?, frn=?, partner_name=?, membership_no=?, address=?, city=?, state=?, email=?, mobile=?, peer_review_no=?, peer_review_valid_upto=?, active_status=?
        WHERE auditor_id=?
    ");
    return $stmt->execute([
        $data['firm_name'] ?? '',
        $data['frn'] ?? '',
        $data['partner_name'] ?? '',
        $data['membership_no'] ?? '',
        $data['address'] ?? null,
        $data['city'] ?? '',
        $data['state'] ?? '',
        $data['email'] ?? '',
        $data['mobile'] ?? '',
        $data['peer_review_no'] ?? '',
        $data['peer_review_valid_upto'] ?? null,
        $data['active_status'] ?? 1,
        $auditorId,
    ]);
}

/* =========================
   DIRECTOR MASTER
========================= */

function searchDirectors(PDO $pdo, int $ownerId, string $query = '', bool $activeOnly = true): array
{
    $sql = "SELECT director_id, director_name, din, pan, email, mobile
            FROM director_master WHERE owner_user_id = ?";
    $params = [$ownerId];

    if ($activeOnly) {
        $sql .= " AND active_status = 1";
    }
    if ($query !== '') {
        $sql .= " AND (director_name LIKE ? OR din LIKE ? OR pan LIKE ?)";
        $like = "%{$query}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
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
    $stmt = $pdo->prepare("
        INSERT INTO director_master (owner_user_id, director_name, din, pan, email, mobile, address, active_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $ownerId,
        $data['director_name'] ?? '',
        $data['din'] ?? '',
        $data['pan'] ?? '',
        $data['email'] ?? '',
        $data['mobile'] ?? '',
        $data['address'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function updateDirector(PDO $pdo, int $directorId, array $data): bool
{
    $stmt = $pdo->prepare("
        UPDATE director_master SET director_name=?, din=?, pan=?, email=?, mobile=?, address=?, active_status=?
        WHERE director_id=?
    ");
    return $stmt->execute([
        $data['director_name'] ?? '',
        $data['din'] ?? '',
        $data['pan'] ?? '',
        $data['email'] ?? '',
        $data['mobile'] ?? '',
        $data['address'] ?? null,
        $data['active_status'] ?? 1,
        $directorId,
    ]);
}

/* =========================
   COMPANY FY GOVERNANCE
========================= */

function getCompanyGovernance(PDO $pdo, int $companyId, int $fyId): ?array
{
    $stmt = $pdo->prepare("
        SELECT g.*, a.firm_name AS auditor_firm_name, a.partner_name AS auditor_partner_name,
               a.frn AS auditor_frn, a.membership_no AS auditor_membership_no
        FROM company_fy_governance g
        LEFT JOIN auditor_master a ON a.auditor_id = g.auditor_id
        WHERE g.company_id = ? AND g.fy_id = ?
    ");
    $stmt->execute([$companyId, $fyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function saveCompanyGovernance(PDO $pdo, int $companyId, int $fyId, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO company_fy_governance (company_id, fy_id, auditor_id, appointment_date, report_date, audit_type, signed_partner, signed_membership_no, udin)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            auditor_id = VALUES(auditor_id),
            appointment_date = VALUES(appointment_date),
            report_date = VALUES(report_date),
            audit_type = VALUES(audit_type),
            signed_partner = VALUES(signed_partner),
            signed_membership_no = VALUES(signed_membership_no),
            udin = VALUES(udin),
            updated_at = NOW()
    ");
    $stmt->execute([
        $companyId, $fyId,
        $data['auditor_id'] ?? null,
        $data['appointment_date'] ?? null,
        $data['report_date'] ?? null,
        $data['audit_type'] ?? 'statutory',
        $data['signed_partner'] ?? '',
        $data['signed_membership_no'] ?? '',
        $data['udin'] ?? '',
    ]);
}

function getCompanyGovernanceHistory(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare("
        SELECT g.*, a.firm_name AS auditor_firm_name, a.partner_name AS auditor_partner_name,
               fy.fy_label
        FROM company_fy_governance g
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
        FROM company_directors cd
        INNER JOIN director_master dm ON dm.director_id = cd.director_id
        WHERE cd.company_id = ?
        ORDER BY cd.signing_order ASC, cd.appointment_date DESC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addCompanyDirector(PDO $pdo, int $companyId, int $directorId, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO company_directors (company_id, director_id, designation, appointment_date, cessation_date, signing_authority, signing_order, active_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            designation = VALUES(designation),
            appointment_date = VALUES(appointment_date),
            cessation_date = VALUES(cessation_date),
            signing_authority = VALUES(signing_authority),
            signing_order = VALUES(signing_order),
            active_status = VALUES(active_status),
            updated_at = NOW()
    ");
    $stmt->execute([
        $companyId, $directorId,
        $data['designation'] ?? '',
        $data['appointment_date'] ?? null,
        $data['cessation_date'] ?? null,
        $data['signing_authority'] ?? '',
        $data['signing_order'] ?? 0,
    ]);
}

function removeCompanyDirector(PDO $pdo, int $companyId, int $directorId): void
{
    $stmt = $pdo->prepare("DELETE FROM company_directors WHERE company_id = ? AND director_id = ?");
    $stmt->execute([$companyId, $directorId]);
}

/* =========================
   COMPANY FY SIGNATORIES
========================= */

function getCompanySignatories(PDO $pdo, int $companyId, int $fyId): array
{
    $stmt = $pdo->prepare("
        SELECT cfs.*, dm.director_name, dm.din
        FROM company_fy_signatories cfs
        INNER JOIN director_master dm ON dm.director_id = cfs.director_id
        WHERE cfs.company_id = ? AND cfs.fy_id = ?
        ORDER BY cfs.signing_order ASC
    ");
    $stmt->execute([$companyId, $fyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveCompanySignatories(PDO $pdo, int $companyId, int $fyId, array $signatories): void
{
    /* Clear existing */
    $pdo->prepare("DELETE FROM company_fy_signatories WHERE company_id = ? AND fy_id = ?")
        ->execute([$companyId, $fyId]);

    $order = 1;
    foreach ($signatories as $sig) {
        if (empty($sig['director_id'])) continue;
        $stmt = $pdo->prepare("
            INSERT INTO company_fy_signatories (company_id, fy_id, director_id, is_signing_person, signing_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId, $fyId,
            (int) $sig['director_id'],
            !empty($sig['is_signing_person']) ? 1 : 0,
            $order++,
        ]);
    }
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
            assertTableExists($pdo, 'company_fy_governance');
            assertTableExists($pdo, 'company_directors');
            assertTableExists($pdo, 'company_fy_signatories');
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS auditor_master (
            auditor_id INT AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT NULL,
            firm_name VARCHAR(255) NOT NULL DEFAULT '',
            frn VARCHAR(50) NOT NULL DEFAULT '',
            partner_name VARCHAR(255) NOT NULL DEFAULT '',
            membership_no VARCHAR(50) NOT NULL DEFAULT '',
            address TEXT NULL,
            city VARCHAR(100) NOT NULL DEFAULT '',
            state VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            mobile VARCHAR(20) NOT NULL DEFAULT '',
            peer_review_no VARCHAR(50) NOT NULL DEFAULT '',
            peer_review_valid_upto DATE NULL,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_auditor_owner (owner_user_id),
            INDEX idx_auditor_firm (firm_name)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS director_master (
            director_id INT AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT NULL,
            director_name VARCHAR(255) NOT NULL DEFAULT '',
            din VARCHAR(50) NOT NULL DEFAULT '',
            pan VARCHAR(20) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            mobile VARCHAR(20) NOT NULL DEFAULT '',
            address TEXT NULL,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_director_owner (owner_user_id),
            INDEX idx_director_name (director_name)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_fy_governance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            auditor_id INT NULL,
            appointment_date DATE NULL,
            report_date DATE NULL,
            audit_type VARCHAR(50) NOT NULL DEFAULT 'statutory',
            signed_partner VARCHAR(255) NOT NULL DEFAULT '',
            signed_membership_no VARCHAR(50) NOT NULL DEFAULT '',
            udin VARCHAR(50) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_governance_fy (company_id, fy_id),
            INDEX idx_governance_company (company_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_directors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            director_id INT NOT NULL,
            designation VARCHAR(100) NOT NULL DEFAULT '',
            appointment_date DATE NULL,
            cessation_date DATE NULL,
            signing_authority VARCHAR(255) NOT NULL DEFAULT '',
            signing_order INT NOT NULL DEFAULT 0,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_director (company_id, director_id),
            INDEX idx_cd_company (company_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS company_fy_signatories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            director_id INT NOT NULL,
            is_signing_person TINYINT(1) NOT NULL DEFAULT 1,
            signing_order INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fy_signatory (company_id, fy_id, director_id),
            INDEX idx_fys_company (company_id)
        )");
    } catch (Throwable $e) {
        /* Schema creation failed — safe to ignore in runtime if tables exist */
    }
}
