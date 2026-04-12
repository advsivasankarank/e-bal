<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$page_title = "Dashboard Company";
require_once __DIR__ . '/layouts/header.php';

$companyCount = (int) $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$selectedCompany = $_SESSION['company_name'] ?? 'Not Selected';
$selectedFy = $_SESSION['fy_name'] ?? 'Not Selected';
?>

<div class="page-title">Dashboard Company</div>

<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= $companyCount ?></div>
        <div class="summary-label">Companies Created</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= empty($_SESSION['company_id']) ? 0 : 1 ?></div>
        <div class="summary-label">Company Selected</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= empty($_SESSION['fy_id']) ? 0 : 1 ?></div>
        <div class="summary-label">FY Selected</div>
    </div>
</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($selectedCompany) ?></strong><br>
    FY: <strong><?= htmlspecialchars($selectedFy) ?></strong>
</div>

<div class="card section-card">
    Set the working context here before moving into data import. The selected company and financial year are used across the sync, mapping, and reporting workflow.
</div>

<div class="tile-container">
    <div class="tile is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_create.php">
        <h3>Create Company</h3>
        <p>Add a new client company with category, CIN or LLP code, and basic profile details.</p>
        <div class="status">New Company</div>
    </div>

    <div class="tile is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_select.php">
        <h3>Select Company</h3>
        <p>Choose the active company and financial year together before starting any import work.</p>
        <div class="status">Choose Context</div>
    </div>

    <div class="tile <?= empty($_SESSION['company_id']) ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= !empty($_SESSION['company_id']) ? ' data-nav="' . BASE_URL . 'company_dashboard/financial_year.php"' : '' ?>>
        <h3>Change Financial Year</h3>
        <p>Switch the working April to March financial year for the selected company whenever needed.</p>
        <div class="status"><?= empty($_SESSION['company_id']) ? 'Select Company First' : 'Update FY' ?></div>
    </div>

    <div class="tile is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php">
        <h3>Manage Companies</h3>
        <p>Review the company list, edit details, or remove unused companies from the workspace.</p>
        <div class="status">Open List</div>
    </div>

    <div class="tile <?= empty($_SESSION['company_id']) || empty($_SESSION['fy_id']) ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= !empty($_SESSION['company_id']) && !empty($_SESSION['fy_id']) ? ' data-nav="' . BASE_URL . 'dashboard_data.php"' : '' ?>>
        <h3>Go To Data Dashboard</h3>
        <p>Continue into the import and mapping workflow with the active company and FY context.</p>
        <div class="status"><?= !empty($_SESSION['company_id']) && !empty($_SESSION['fy_id']) ? 'Ready To Continue' : 'Company and FY Required' ?></div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
