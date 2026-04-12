<?php
require_once '../../app/session_bootstrap.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/financial_year_helper.php';

$page_title = "Select Company";

/* Fetch companies */
$stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name");
$companies = $stmt->fetchAll();
$financialYears = getFinancialYears($pdo);
$errors = [];
$next = trim((string) ($_GET['next'] ?? $_POST['next'] ?? 'dashboard_company.php'));
$next = ltrim($next, '/');
$next = str_contains($next, '..') ? 'dashboard_company.php' : $next;

/* Handle selection */
$prefillCompanyId = (int) ($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$prefillFyId = (int) ($_POST['fy_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $company_id = (int) ($_POST['company_id'] ?? 0);
    $fy_id = (int) ($_POST['fy_id'] ?? 0);

    if ($company_id <= 0) {
        $errors[] = "Please select a company.";
    }

    if ($fy_id <= 0) {
        $errors[] = "Please select a financial year.";
    }

    $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE id = ?");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company && empty($errors)) {
        $errors[] = "Selected company was not found.";
    }

    $financialYear = findFinancialYearById($pdo, $fy_id);
    if (!$financialYear && empty($errors)) {
        $errors[] = "Selected financial year was not found.";
    }

    if (empty($errors)) {
        $_SESSION['company_id'] = $company['id'];
        $_SESSION['company_name'] = $company['name'];
        $_SESSION['fy_id'] = $financialYear['id'];
        $_SESSION['fy_name'] = $financialYear['fy_label'];

        header("Location: " . BASE_URL . $next);
        exit;
    }
}

include __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Select Company</div>

<?php if (empty($companies)): ?>
    <div class="error-box">
        <p>No companies found. Create a company first.</p>
    </div>
<?php endif; ?>

<?php if (empty($financialYears)): ?>
    <div class="error-box">
        <p>No financial years found. Add at least one financial year before selecting a company.</p>
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
<input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

<div class="form-group">
    <label>Company</label>
    <select name="company_id" required>
        <option value="">Select Company</option>

        <?php foreach ($companies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int) ($c['id'] ?? 0) === $prefillCompanyId) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label>Financial Year</label>
    <select name="fy_id" required>
        <option value="">Select Financial Year</option>

        <?php foreach ($financialYears as $fy): ?>
            <option value="<?= (int) $fy['id'] ?>" <?= ((int) ($fy['id'] ?? 0) === $prefillFyId) ? 'selected' : '' ?>>
                <?= htmlspecialchars($fy['fy_label']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<button type="submit" <?= empty($companies) || empty($financialYears) ? 'disabled' : '' ?>>Select</button>

</form>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
