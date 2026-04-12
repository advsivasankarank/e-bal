<?php

require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../app/services/tally_bridge_service.php';

header('Content-Type: application/json; charset=utf-8');

$service = new TallyBridgeService();
$action = strtolower(trim((string) ($_GET['action'] ?? 'health')));

try {
    switch ($action) {
        case 'health':
            $result = $service->health();
            break;

        case 'company':
            $result = $service->company();
            break;

        case 'ledger_master':
            $result = $service->ledgerMaster();
            break;

        case 'trial_balance':
            $fyLabel = (string) ($_GET['fy'] ?? ($_SESSION['fy_name'] ?? ''));
            $result = $service->trialBalance($fyLabel);
            break;

        default:
            http_response_code(400);
            $result = [
                'ok' => false,
                'message' => 'Unsupported tally bridge action.',
            ];
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    $result = [
        'ok' => false,
        'message' => $e->getMessage(),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
