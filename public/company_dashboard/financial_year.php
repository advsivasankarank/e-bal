<?php
require_once '../../app/session_bootstrap.php';
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/financial_year_helper.php';

requireCompany();

$page_title = "Select Financial Year";
$financialYears = getFinancialYears($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        header("Location: " . BASE_URL . "dashboard_company.php");
        exit;
    }
}

include __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Select Financial Year</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<?php if (empty($financialYears)): ?>
    <div class="error-box">
        <p>No financial years found. Please add financial years in the database first.</p>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="error-box">
        <?php foreach ($errors as $error): ?>
            <p><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post">
    <div class="form-group">
        <label>Financial Year</label>
        <select name="fy_id" required>
            <option value="">Select Financial Year</option>
            <?php foreach ($financialYears as $fy): ?>
                <option value="<?= (int) $fy['id'] ?>" <?= ((int) ($fy['id'] ?? 0) === (int) ($_SESSION['fy_id'] ?? 0)) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($fy['fy_label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" <?= empty($financialYears) ? 'disabled' : '' ?>>Save Financial Year</button>
</form>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
