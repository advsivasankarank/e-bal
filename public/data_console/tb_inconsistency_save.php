<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/tb_import_helper.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rows = $_SESSION['pending_tb_rows'] ?? [];
$unknowns = $_SESSION['pending_tb_unknowns'] ?? [];

if (empty($rows) || empty($unknowns)) {
    $_SESSION['error'] = 'No pending trial balance inconsistencies to review.';
    header('Location: ' . BASE_URL . 'data_console/tally_connect.php');
    exit;
}

$approvedMappings = [];
$approvals = $_POST['approve'] ?? [];
$parentGroups = $_POST['parent_group'] ?? [];
$scheduleCodes = $_POST['schedule_code'] ?? [];

foreach ($unknowns as $row) {
    $ledgerName = (string) ($row['ledger_name'] ?? '');

    if (($approvals[$ledgerName] ?? '') !== '1') {
        $_SESSION['error'] = 'Approve all listed inconsistent ledgers before continuing.';
        header('Location: ' . BASE_URL . 'data_console/tb_inconsistency_review.php');
        exit;
    }

    $parentGroup = trim((string) ($parentGroups[$ledgerName] ?? ''));
    $scheduleCode = trim((string) ($scheduleCodes[$ledgerName] ?? ''));

    if ($parentGroup === '' || $scheduleCode === '') {
        $_SESSION['error'] = 'Parent group and schedule head are required for every inconsistent ledger.';
        header('Location: ' . BASE_URL . 'data_console/tb_inconsistency_review.php');
        exit;
    }

    $approvedMappings[$ledgerName] = [
        'parent_group' => $parentGroup,
        'schedule_code' => $scheduleCode,
    ];
}

try {
    $result = importTrialBalanceRows(
        $pdo,
        (int) $_SESSION['company_id'],
        (int) $_SESSION['fy_id'],
        $rows,
        $approvedMappings
    );

    if (!(bool) ($result['ok'] ?? false)) {
        $_SESSION['error'] = 'Trial balance import still contains unresolved inconsistent ledgers.';
        header('Location: ' . BASE_URL . 'data_console/tb_inconsistency_review.php');
        exit;
    }

    $_SESSION['process_stats'] = $result['stats'];
    $_SESSION['success'] = 'Inconsistent ledgers were added, mapped, and the trial balance import was completed.';

    unset($_SESSION['pending_tb_rows'], $_SESSION['pending_tb_unknowns']);

    header('Location: ' . BASE_URL . 'data_console/process_result.php');
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: ' . BASE_URL . 'data_console/tb_inconsistency_review.php');
    exit;
}
