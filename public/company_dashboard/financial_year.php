<?php
require_once '../../app/session_bootstrap.php';
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/financial_year_helper.php';

requireCompany();

$page_title = "Select Financial Year";
$financialYears = getFinancialYears($pdo, $_SESSION['company_id'] ?? null);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $fy_id = (int) ($_POST['fy_id'] ?? 0);

    if ($fy_id <= 0) {
        $errors[] = "Please select a financial year.";
    }

    $financialYear = findFinancialYearById($pdo, $fy_id);
    if (!$financialYear && empty($errors)) {
        $errors[] = "Selected financial year was not found.";
    }

    if (empty($errors)) {
        $_SESSION['fy_id'] = $financialYear['id'];
        $_SESSION['fy_name'] = $financialYear['fy_label'];
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        if ($companyId > 0) {
            header("Location: " . BASE_URL . "assignment_home.php?company_id=" . $companyId . "&fy_id=" . $financialYear['id']);
        } else {
            header("Location: " . BASE_URL . "my_assignments.php");
        }
        exit;
    }
}

include __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Financial Year']
]) ?>

<?= uiPageHero('Select Financial Year', 'Switch the active financial year for the current company') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
    'entity_type' => '',
    'profile' => 0,
    'status' => '',
    'edit_url' => '',
]) ?>

<?php if (empty($financialYears)): ?>
    <?= uiAlert('No financial years found for this company yet.', 'warning') ?>
    <div style="margin:12px 0 20px;">
        <?= uiButton('Create Financial Year', BASE_URL . 'entity_home.php?company_id=' . (int) ($_SESSION['company_id'] ?? 0), 'primary', '➕') ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $error): ?>
        <?= uiAlert($error, 'error') ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="ui-section-card" style="margin-bottom:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Financial Year Selection</div>
    </div>
    <div class="ui-section-card-body">
        <form method="post">
            <?= csrfInput() ?>
            <div class="ui-field" style="margin-bottom:14px;">
                <label class="ui-field-label">Financial Year</label>
                <select name="fy_id" required class="ui-input">
                    <option value="">Select Financial Year</option>
                    <?php foreach ($financialYears as $fy): ?>
                        <option value="<?= (int) $fy['id'] ?>" <?= ((int) ($fy['id'] ?? 0) === (int) ($_SESSION['fy_id'] ?? 0)) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fy['fy_label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?= uiButton('Save Financial Year', '', 'primary', '', empty($financialYears) ? 'disabled' : '') ?>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer_v2.php'; ?>
