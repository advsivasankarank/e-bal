<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/services/tally_bridge_service.php';
require_once '../../app/helpers/tb_import_helper.php';
require_once '../../app/helpers/financial_year_helper.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function normalizeCompanyComparisonName($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
}

$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];
$fy_label   = $_SESSION['fy_name'] ?? '';
$selectedCompanyName = $_SESSION['company_name'] ?? '';

$workflowStmt = $pdo->prepare("SELECT mapping_completed FROM workflow_status WHERE company_id=? AND fy_id=?");
$workflowStmt->execute([$company_id, $fy_id]);
$workflow = $workflowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

if ((int) ($workflow['mapping_completed'] ?? 0) !== 1) {
    $_SESSION['error'] = "Complete mapping before fetching the trial balance.";
    header("Location: tally_online.php");
    exit;
}

$liveCompanyName = trim((string) ($_POST['live_company_name'] ?? ''));
$selectedCompanyNormalized = normalizeCompanyComparisonName($selectedCompanyName);
$liveCompanyNormalized = normalizeCompanyComparisonName($liveCompanyName);
$companyMismatch = $liveCompanyNormalized !== '' && $selectedCompanyNormalized !== '' && $selectedCompanyNormalized !== $liveCompanyNormalized;

if ($companyMismatch && ($_POST['company_mismatch_confirmed'] ?? '') !== '1') {
    $_SESSION['error'] = "The live company open in Tally does not match the selected e-BAL company. Please confirm before fetching the trial balance.";
    header("Location: tally_connect.php");
    exit;
}

if (!preg_match('/^(\d{4})-(\d{4})$/', $fy_label)) {
    $_SESSION['error'] = "Invalid financial year in session";
    header("Location: tally_connect.php");
    exit;
}

$bridge = new TallyBridgeService();
$tbResult = $bridge->trialBalance($fy_label);

if (!(bool) ($tbResult['ok'] ?? false)) {
    $_SESSION['error'] = $tbResult['message'] ?? 'Trial balance fetch failed.';
    header("Location: tally_connect.php");
    exit;
}

$rows = $tbResult['rows'] ?? [];
$openingRows = [];
$comparativeSource = 'none';

$previousFyLabel = getPreviousFinancialYearLabel($fy_label);
if ($previousFyLabel !== '') {
    $previousFy = findFinancialYearByLabel($pdo, $previousFyLabel);

    if ($previousFy !== null) {
        $previousFyId = (int) ($previousFy['id'] ?? 0);

        if ($previousFyId > 0) {
            $previousCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM tally_ledgers
                WHERE company_id = ? AND fy_id = ?
            ");
            $previousCountStmt->execute([$company_id, $previousFyId]);
            $hasStoredPreviousYear = (int) $previousCountStmt->fetchColumn() > 0;

            if ($hasStoredPreviousYear) {
                $openingRows = loadOpeningRowsFromStoredYear($pdo, $company_id, $previousFyId);
                $comparativeSource = 'app';
            } else {
                $openingResult = $bridge->trialBalance($previousFyLabel);

                if (!(bool) ($openingResult['ok'] ?? false)) {
                    $_SESSION['error'] = 'Current year trial balance was available, but previous-year comparative figures could not be fetched for ' . $previousFyLabel . '.';
                    header("Location: tally_connect.php");
                    exit;
                }

                $openingRows = $openingResult['rows'] ?? [];
                $comparativeSource = 'tally';
            }
        }
    }
}

try {
    $result = importTrialBalanceRows($pdo, $company_id, $fy_id, $rows, [], $openingRows);

    if (!(bool) ($result['ok'] ?? false)) {
        $_SESSION['pending_tb_rows'] = $rows;
        $_SESSION['pending_tb_unknowns'] = $result['unknowns'] ?? [];
        header("Location: " . BASE_URL . "data_console/tb_inconsistency_review.php");
        exit;
    }

    $_SESSION['process_stats'] = array_merge($result['stats'], [
        'comparative_source' => $comparativeSource,
        'comparative_fy' => $previousFyLabel,
    ]);

} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['process_stats'] = [
        'total' => 0,
        'dr_total' => 0,
        'cr_total' => 0,
        'type' => 'tally bridge'
    ];
}

/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/
header("Location: " . BASE_URL . "data_console/process_result.php");
exit;
