<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/helpers/fy_closure_helper.php';

function requireCompany() {
    if (empty($_SESSION['company_id'])) {
        require_once __DIR__ . '/../config/app.php';
        header("Location: " . BASE_URL . "company_dashboard/company_list.php?error=select_company");
        exit;
    }
}

function requireFY() {
    if (empty($_SESSION['fy_id'])) {
        require_once __DIR__ . '/../config/app.php';
        header("Location: " . BASE_URL . "company_dashboard/financial_year.php?error=select_fy");
        exit;
    }
    // Verify FY belongs to current company
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['company_id']) && !empty($_SESSION['fy_id'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM financial_years WHERE id = ? AND company_id = ?");
        $stmt->execute([(int)$_SESSION['fy_id'], (int)$_SESSION['company_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            unset($_SESSION['fy_id'], $_SESSION['fy_name']);
            require_once __DIR__ . '/../config/app.php';
            header("Location: " . BASE_URL . "company_dashboard/financial_year.php?error=invalid_fy");
            exit;
        }
    }
}

function requireAssignmentAccess() {
    requireCompany();
    requireFY();
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $fyId = (int) ($_SESSION['fy_id'] ?? 0);
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($companyId > 0 && $fyId > 0 && $userId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE id = ? AND owner_user_id = ?");
            $stmt->execute([$companyId, $userId]);
            if ((int)$stmt->fetchColumn() === 0) {
                unset($_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['fy_id'], $_SESSION['fy_name']);
                require_once __DIR__ . '/../config/app.php';
                header("Location: " . BASE_URL . "my_assignments.php?error=access_denied");
                exit;
            }
        }
    }
    ensureFYClosureSchema($pdo);
}

function requireFullContext() {
    requireCompany();
    requireFY();
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        ensureFYClosureSchema($pdo);
    }
}

/* ============================================================
   NON-BLOCKING CONTEXT CHECKS
   ============================================================ */
function hasCompanyContext(): bool {
    return !empty($_SESSION['company_id']);
}

function hasFyContext(): bool {
    return !empty($_SESSION['fy_id']);
}

function hasFullContext(): bool {
    return hasCompanyContext() && hasFyContext();
}
