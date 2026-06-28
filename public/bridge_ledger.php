<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/xml_sanitizer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/helpers/runtime_helper.php';

header('Content-Type: application/json; charset=utf-8');

$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
$token = trim((string) (
    $allHeaders['X-Bridge-Token'] ?? $allHeaders['x-bridge-token']
    ?? $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''
));
$clientId = trim((string) ($_GET['client_id'] ?? $allHeaders['X-Client-Id'] ?? $_SERVER['HTTP_X_CLIENT_ID'] ?? ''));
$companyId = (int) ($allHeaders['X-Company-Id'] ?? $_SERVER['HTTP_X_COMPANY_ID'] ?? 0);
$fyId = (int) ($allHeaders['X-Fy-Id'] ?? $_SERVER['HTTP_X_FY_ID'] ?? 0);
$xmlRaw = file_get_contents('php://input');

$expected = defined('TALLY_BRIDGE_TOKEN') ? trim((string) TALLY_BRIDGE_TOKEN) : '';
if ($expected === '' || $token !== $expected) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($companyId <= 0 || $fyId <= 0) {
    try {
        if (appAllowsRuntimeSchema()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS bridge_clients (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id VARCHAR(50) NOT NULL UNIQUE,
                    company_id INT NOT NULL,
                    fy_id INT NOT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");
        } else {
            assertTableExists($pdo, 'bridge_clients');
        }
    } catch (Throwable $e) {
        appLog('ERROR', 'Bridge ledger context table unavailable', ['message' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Bridge configuration unavailable']);
        exit;
    }
    if ($clientId !== '') {
        $stmt = $pdo->prepare("
            SELECT company_id, fy_id
            FROM bridge_clients
            WHERE client_id = ? AND active = 1
            LIMIT 1
        ");
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $companyId = (int) $row['company_id'];
            $fyId = (int) $row['fy_id'];
        }
    }
}

if ($companyId <= 0 || $fyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'company_id and fy_id are required']);
    exit;
}

if ($clientId !== '' && $companyId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT company_id FROM bridge_clients WHERE client_id = ? AND active = 1 LIMIT 1");
        $stmt->execute([$clientId]);
        $registered = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($registered && (int) $registered['company_id'] !== $companyId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'company_id does not match registered client']);
            exit;
        }
    } catch (Throwable $e) {
        // bridge_clients table may not exist yet; skip binding check
    }
}

if (trim($xmlRaw) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'XML payload is empty']);
    exit;
}

$xmlRaw = sanitizeTallyXML($xmlRaw);
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlRaw);
if ($xml === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid XML']);
    exit;
}

$ledgers = $xml->xpath("//*[local-name()='LEDGER']");
if (!$ledgers || count($ledgers) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No ledger data found']);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM tally_ledger_master WHERE company_id=?")
        ->execute([$companyId]);

    $stmt = $pdo->prepare("
        INSERT INTO tally_ledger_master
        (company_id, ledger_name, parent_group)
        VALUES (?, ?, ?)
    ");

    $count = 0;
    foreach ($ledgers as $l) {
        $name = trim((string) ($l['NAME'] ?? ''));
        $parent = trim((string) ($l->PARENT ?? ''));
        if ($name === '') {
            continue;
        }
        $stmt->execute([$companyId, $name, $parent]);
        $count++;
    }

    $pdo->prepare("
        INSERT INTO workflow_status
        (company_id, fy_id, ledger_fetched, updated_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            ledger_fetched = 1,
            updated_at = NOW()
    ")->execute([$companyId, $fyId]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'ledgers' => $count, 'client_id' => $clientId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLog('ERROR', 'Bridge ledger upload failed', [
        'client_id' => $clientId,
        'company_id' => $companyId,
        'fy_id' => $fyId,
        'message' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
