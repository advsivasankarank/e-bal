<?php
require_once '../../app/session_bootstrap.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/financial_year_helper.php';
require_once '../../app/helpers/plan_helper.php';
require_once '../../app/helpers/security_helper.php';

/* ---- LEGACY REDIRECT ---- */
/* If a company_id is supplied, redirect to Entity Home (new workflow). */
$legacyCompanyId = (int)($_GET['company_id'] ?? 0);
if ($legacyCompanyId > 0) {
    header('Location: ' . BASE_URL . 'entity_home.php?company_id=' . $legacyCompanyId);
    exit;
}

/* No company supplied — redirect to Entity Dashboard */
if (!isset($_GET['company_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

$page_title = "Select Company";

$userId = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = $userId > 0 ? getOwnerUserId($pdo, $userId) : 0;

/* Fetch companies */
if ($ownerId > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE owner_user_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
} else {
    $stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name");
}
$companies = $stmt->fetchAll();
$errors = [];
$successMessage = '';
$next = trim((string) ($_GET['next'] ?? $_POST['next'] ?? 'dashboard_company.php'));
$next = ltrim($next, '/');
$next = str_contains($next, '..') ? 'dashboard_company.php' : $next;

/* Handle selection */
$prefillCompanyId = (int) ($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$selectedCompanyId = $prefillCompanyId > 0 ? $prefillCompanyId : ((int) ($companies[0]['id'] ?? 0));
$financialYears = getFinancialYears($pdo, $selectedCompanyId > 0 ? $selectedCompanyId : null);
$prefillFyId = (int) ($_POST['fy_id'] ?? $_GET['fy_id'] ?? 0);
if ($prefillFyId <= 0) {
    $prefillFyId = getPreferredFinancialYearId($financialYears);
}
$manualFyLabel = trim((string) ($_POST['manual_fy_label'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $company_id = (int) ($_POST['company_id'] ?? 0);
    $action = trim((string) ($_POST['form_action'] ?? 'select'));
    $selectedCompanyId = $company_id;

    if ($company_id <= 0) {
        $errors[] = "Please select a company.";
    }

    if ($ownerId > 0) {
        $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE id = ? AND owner_user_id = ?");
        $stmt->execute([$company_id, $ownerId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE id = ?");
        $stmt->execute([$company_id]);
    }
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company && empty($errors)) {
        $errors[] = "Selected company was not found.";
    }

    if ($action === 'add_fy') {
        if ($manualFyLabel === '') {
            $errors[] = "Enter the missing financial year to add it.";
        }

        if (empty($errors)) {
            try {
                $createdFy = ensureFinancialYearRecord($pdo, $company_id, $manualFyLabel);
                $successMessage = 'Financial year ' . $createdFy['fy_label'] . ' is now available for this company.';
                $prefillFyId = (int) ($createdFy['id'] ?? 0);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } else {
        $fy_id = (int) ($_POST['fy_id'] ?? 0);
        if ($fy_id <= 0) {
            $errors[] = "Please select a financial year.";
        }

        $financialYear = findFinancialYearById($pdo, $fy_id, $company_id);
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

    $financialYears = getFinancialYears($pdo, $selectedCompanyId > 0 ? $selectedCompanyId : null);
    if ($prefillFyId <= 0) {
        $prefillFyId = getPreferredFinancialYearId($financialYears);
    }
}

include __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Select Company']
]) ?>

<?= uiPageHero('Select Company', 'Choose a company and financial year to work with') ?>

<?php if (empty($companies)): ?>
    <?= uiAlert('No companies found. Create a company first.', 'error') ?>
    <div style="margin:12px 0 20px;">
        <?= uiButton('Create Entity', BASE_URL . 'company_dashboard/company_create.php', 'primary', '➕') ?>
    </div>
<?php endif; ?>

<?php if (empty($financialYears)): ?>
    <?= uiAlert('No financial years are available right now. Please add or repair the financial year master.', 'warning') ?>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $error): ?>
        <?= uiAlert($error, 'error') ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($successMessage !== ''): ?>
    <?= uiAlert($successMessage, 'success') ?>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

<div class="ui-section-card" style="margin-bottom:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Company & Financial Year</div>
    </div>
    <div class="ui-section-card-body">
        <div class="ui-field" style="margin-bottom:14px;">
            <label for="company_id" class="ui-field-label">Company</label>
            <select name="company_id" id="company_id" required class="ui-input">
                <option value="">Select Company</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= ((int) ($c['id'] ?? 0) === $prefillCompanyId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ui-field" style="margin-bottom:14px;">
            <label class="ui-field-label">Financial Year</label>
            <select name="fy_id" required class="ui-input">
                <option value="">Select Financial Year</option>
                <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int) $fy['id'] ?>" <?= ((int) ($fy['id'] ?? 0) === $prefillFyId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fy['fy_label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($financialYears)): ?>
                <div class="ui-field-help">Current year is suggested automatically based on today's date. You can still switch to any other financial year when needed.</div>
            <?php endif; ?>
        </div>

        <?= uiButton('Select', '', 'primary') ?>
    </div>
</div>

<?php if (!empty($companies)): ?>
    <div class="ui-section-card" style="margin-bottom:16px;">
        <div class="ui-section-card-header">
            <div class="ui-section-card-title">Add Missing Financial Year</div>
        </div>
        <div class="ui-section-card-body">
            <p style="margin:0 0 14px; color:#5b6b79;">
                If an older year is missing, enter it here in `2018-2019` or `2018-19` format. It will be saved for the selected company.
            </p>
            <div class="ui-field" style="margin-bottom:14px;">
                <label class="ui-field-label">Manual Financial Year</label>
                <input type="text" name="manual_fy_label" value="<?= htmlspecialchars($manualFyLabel) ?>" placeholder="Example: 2018-2019" class="ui-input">
            </div>
            <?= uiButton('Add Financial Year', '', 'outline', '', 'name="form_action" value="add_fy"') ?>
        </div>
    </div>
<?php endif; ?>

</form>

<script>
    (function () {
        const companySelect = document.getElementById('company_id');
        if (!companySelect) {
            return;
        }

        companySelect.addEventListener('change', function () {
            const selectedCompanyId = this.value;
            const url = new URL(window.location.href);
            if (selectedCompanyId) {
                url.searchParams.set('company_id', selectedCompanyId);
            } else {
                url.searchParams.delete('company_id');
            }
            url.searchParams.set('next', <?= json_encode($next) ?>);
            window.location.href = url.toString();
        });
    })();
</script>

<?php include __DIR__ . '/../layouts/footer_v2.php'; ?>
