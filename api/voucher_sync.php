<?php

$isBridgeRequest = false;
$allH = function_exists('getallheaders') ? getallheaders() : [];
$token = trim((string) (
    $allH['X-Bridge-Token'] ?? $allH['x-bridge-token']
    ?? $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''
));

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$expected = defined('TALLY_BRIDGE_TOKEN') ? trim((string) TALLY_BRIDGE_TOKEN) : '';

if ($token !== '' && $expected !== '' && hash_equals($expected, $token)) {
    $isBridgeRequest = true;
    require_once __DIR__ . '/../app/helpers/runtime_helper.php';
} else {
    require_once __DIR__ . '/../app/session_bootstrap.php';
    require_once __DIR__ . '/../app/helpers/runtime_helper.php';
}

require_once __DIR__ . '/../app/helpers/voucher_sync.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

header('Content-Type: application/json; charset=utf-8');

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
$companyId = (int) ($_GET['company_id'] ?? 0);
$fyId = (int) ($_GET['fy_id'] ?? 0);

if ($isBridgeRequest) {
    if ($companyId <= 0 || $fyId <= 0) {
        $clientId = trim((string) ($_GET['client_id'] ?? ''));
        if ($clientId !== '') {
            $stmt = $pdo->prepare("SELECT company_id, fy_id FROM bridge_clients WHERE client_id = ? AND active = 1 LIMIT 1");
            $stmt->execute([$clientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $companyId = (int) $row['company_id'];
                $fyId = (int) $row['fy_id'];
            }
        }
    }
} else {
    /* HARDENED: On session path, always use session values. Never override from GET. */
    require_once __DIR__ . '/../app/context_check.php';
    requireAssignmentAccess();
    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    $fyId = (int) ($_SESSION['fy_id'] ?? 0);
}

if ($companyId <= 0 || $fyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'company_id and fy_id are required.']);
    exit;
}

try {
    $result = match ($action) {
        'sync' => handleSyncAction($pdo, $companyId, $fyId),
        'status' => handleStatusAction($pdo, $companyId, $fyId),
        'summary' => handleSummaryAction($pdo, $companyId, $fyId),
        'details' => handleDetailsAction($pdo, $companyId, $fyId),
        'odbc_check' => handleOdbcCheckAction(),
        default => ['ok' => false, 'message' => "Unknown action: {$action}"],
    };
} catch (Throwable $e) {
    appLog('ERROR', 'Voucher sync API error', ['message' => $e->getMessage()]);
    http_response_code(500);
    $result = ['ok' => false, 'message' => 'Server error.'];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

function handleSyncAction(PDO $pdo, int $companyId, int $fyId): array
{
    $raw = file_get_contents('php://input');
    $body = [];
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $voucherType = trim((string) ($_GET['voucher_type'] ?? $body['voucher_type'] ?? '')) ?: null;

    $fyStmt = $pdo->prepare("SELECT fy_start, fy_end FROM financial_years WHERE id = ? AND company_id = ?");
    $fyStmt->execute([$fyId, $companyId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);

    if ($fy) {
        $fromDate = $fy['fy_start'];
        $toDate = $fy['fy_end'];
    } else {
        $fromDate = $body['from_date'] ?? '2024-04-01';
        $toDate = $body['to_date'] ?? '2025-03-31';
    }

    return syncVouchersIncremental($pdo, $companyId, $fyId, $fromDate, $toDate, $voucherType);
}

function handleStatusAction(PDO $pdo, int $companyId, int $fyId): array
{
    $summary = getVoucherSyncSummary($pdo, $companyId, $fyId);

    $wsStmt = $pdo->prepare("SELECT voucher_synced, updated_at FROM workflow_status WHERE company_id = ? AND fy_id = ?");
    $wsStmt->execute([$companyId, $fyId]);
    $ws = $wsStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'ok' => true,
        'voucher_synced' => (bool) ($ws['voucher_synced'] ?? false),
        'workflow_updated_at' => $ws['updated_at'] ?? null,
        'state' => $summary['state'],
        'stats' => $summary['stats'],
        'type_breakdown' => $summary['type_breakdown'],
    ];
}

function handleSummaryAction(PDO $pdo, int $companyId, int $fyId): array
{
    $summary = getVoucherSyncSummary($pdo, $companyId, $fyId);

    return [
        'ok' => true,
        'state' => $summary['state'],
        'stats' => $summary['stats'],
        'type_breakdown' => $summary['type_breakdown'],
    ];
}

function handleDetailsAction(PDO $pdo, int $companyId, int $fyId): array
{
    $filters = [
        'voucher_type' => trim((string) ($_GET['voucher_type'] ?? '')),
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        'search' => trim((string) ($_GET['search'] ?? '')),
        'limit' => (int) ($_GET['limit'] ?? 50),
        'offset' => (int) ($_GET['offset'] ?? 0),
    ];

    $vouchers = getVoucherDetail($pdo, $companyId, $fyId, $filters);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE company_id = ? AND fy_id = ?");
    $countStmt->execute([$companyId, $fyId]);
    $total = (int) $countStmt->fetchColumn();

    return [
        'ok' => true,
        'vouchers' => $vouchers,
        'total' => $total,
        'returned' => count($vouchers),
    ];
}

function handleOdbcCheckAction(): array
{
    require_once __DIR__ . '/../app/helpers/tally_odbc.php';

    $available = tallyOdbcIsAvailable();
    $dsn = '';
    if (extension_loaded('pdo_odbc')) {
        $dsn = getTallyOdbcDsn();
    }

    return [
        'ok' => true,
        'odbc_available' => $available,
        'pdo_odbc_loaded' => extension_loaded('pdo_odbc'),
        'dsn' => $dsn,
    ];
}
