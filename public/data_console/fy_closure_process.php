<?php
require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/helpers/fy_closure_helper.php';

requireFullContext();
requireCsrfToken();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$action     = (string) ($_POST['action'] ?? '');

if ($company_id <= 0 || $fy_id <= 0) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

if (!userCanCloseFY($pdo, $userId)) {
    $_SESSION['closure_error'] = 'You do not have permission to close or reopen financial years.';
    header('Location: fy_closure.php');
    exit;
}

ensureFYClosureSchema($pdo);

$reason = (string) ($_POST['reason'] ?? '');
$result = [];

if ($action === 'close') {
    $result = closeFinancialYear($pdo, $company_id, $fy_id, $userId, $reason);
} elseif ($action === 'reopen') {
    $result = reopenFinancialYear($pdo, $company_id, $fy_id, $userId, $reason);
} elseif ($action === 'regenerate_snapshot') {
    $targetFYId = (int) ($_POST['target_fy_id'] ?? 0);
    if ($targetFYId <= 0) {
        $_SESSION['closure_error'] = 'Invalid target FY for snapshot regeneration.';
        header('Location: fy_closure.php');
        exit;
    }
    $result = regenerateSnapshot($pdo, $company_id, $targetFYId, $userId);
} else {
    $_SESSION['closure_error'] = 'Invalid action.';
    header('Location: fy_closure.php');
    exit;
}

if ($result['success']) {
    $_SESSION['closure_message'] = $result['message'];
} else {
    $_SESSION['closure_error'] = $result['message'];
}

header('Location: fy_closure.php');
exit;
