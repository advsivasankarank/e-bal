<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/xml_sanitizer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/helpers/tb_import_helper.php';
require_once __DIR__ . '/../app/helpers/runtime_helper.php';
require_once __DIR__ . '/../app/helpers/financial_year_helper.php';

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
if ($expected === '' || !hash_equals($expected, $token)) {
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
        appLog('ERROR', 'Bridge TB context table unavailable', ['message' => $e->getMessage()]);
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
$xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NONET);
if ($xml === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid XML']);
    exit;
}

function extractTrialBalanceRows(SimpleXMLElement $xmlObj): array
{
    $rows = [];

    $nameNodes = $xmlObj->xpath("//*[local-name()='DSPACCNAME']");
    $infoNodes = $xmlObj->xpath("//*[local-name()='DSPACCINFO']");

    if (!empty($nameNodes) && !empty($infoNodes)) {
        $total = min(count($nameNodes), count($infoNodes));

        for ($i = 0; $i < $total; $i++) {
            $name = trim((string) ($nameNodes[$i]->DSPDISPNAME ?? ''));
            $dr = (float) ($infoNodes[$i]->DSPCLDRAMT->DSPCLDRAMTA ?? 0);
            $cr = (float) ($infoNodes[$i]->DSPCLCRAMT->DSPCLCRAMTA ?? 0);
            $openDr = (float) ($infoNodes[$i]->DSPOPDRAMT->DSPOPDRAMTA ?? 0);
            $openCr = (float) ($infoNodes[$i]->DSPOPCRAMT->DSPOPCRAMTA ?? 0);

            if ($name === '' || ($dr == 0.0 && $cr == 0.0)) {
                continue;
            }

            $rows[] = [
                'ledger_name' => $name,
                'parent_group' => '',
                'amount' => $dr != 0.0 ? abs($dr) : abs($cr),
                'type' => $dr != 0.0 ? 'DR' : 'CR',
                'opening_amount' => $openDr != 0.0 ? abs($openDr) : abs($openCr),
                'opening_type' => $openDr != 0.0 ? 'DR' : ($openCr != 0.0 ? 'CR' : ''),
            ];
        }

        return $rows;
    }

    $lineNodes = $xmlObj->xpath("//*[local-name()='DSPACCLINE']");
    foreach ($lineNodes as $node) {
        $name = trim((string) ($node->DSPACCNAME->DSPDISPNAME ?? ''));
        $dr = (float) ($node->DSPACCINFO->DSPCLDRAMT->DSPCLDRAMTA ?? 0);
        $cr = (float) ($node->DSPACCINFO->DSPCLCRAMT->DSPCLCRAMTA ?? 0);
        $openDr = (float) ($node->DSPACCINFO->DSPOPDRAMT->DSPOPDRAMTA ?? 0);
        $openCr = (float) ($node->DSPACCINFO->DSPOPCRAMT->DSPOPCRAMTA ?? 0);

        if ($name !== '' && ($dr != 0.0 || $cr != 0.0)) {
            $rows[] = [
                'ledger_name' => $name,
                'parent_group' => '',
                'amount' => $dr != 0.0 ? abs($dr) : abs($cr),
                'type' => $dr != 0.0 ? 'DR' : 'CR',
                'opening_amount' => $openDr != 0.0 ? abs($openDr) : abs($openCr),
                'opening_type' => $openDr != 0.0 ? 'DR' : ($openCr != 0.0 ? 'CR' : ''),
            ];
        }

        if (isset($node->GRPEXplosion->DSPACCLINE)) {
            foreach ($node->GRPEXplosion->DSPACCLINE as $childNode) {
                $childName = trim((string) ($childNode->DSPACCNAME->DSPDISPNAME ?? ''));
                $childDr = (float) ($childNode->DSPACCINFO->DSPCLDRAMT->DSPCLDRAMTA ?? 0);
                $childCr = (float) ($childNode->DSPACCINFO->DSPCLCRAMT->DSPCLCRAMTA ?? 0);
                $childOpenDr = (float) ($childNode->DSPACCINFO->DSPOPDRAMT->DSPOPDRAMTA ?? 0);
                $childOpenCr = (float) ($childNode->DSPACCINFO->DSPOPCRAMT->DSPOPCRAMTA ?? 0);
                if ($childName !== '' && ($childDr != 0.0 || $childCr != 0.0)) {
                    $rows[] = [
                        'ledger_name' => $childName,
                        'parent_group' => '',
                        'amount' => $childDr != 0.0 ? abs($childDr) : abs($childCr),
                        'type' => $childDr != 0.0 ? 'DR' : 'CR',
                        'opening_amount' => $childOpenDr != 0.0 ? abs($childOpenDr) : abs($childOpenCr),
                        'opening_type' => $childOpenDr != 0.0 ? 'DR' : ($childOpenCr != 0.0 ? 'CR' : ''),
                    ];
                }
            }
        }
    }

    if (!empty($rows)) {
        return $rows;
    }

    // Fallback: Ledger collection with closing balance.
    $ledgerNodes = $xmlObj->xpath("//*[local-name()='LEDGER']");
    if (!empty($ledgerNodes)) {
        foreach ($ledgerNodes as $ledger) {
            $name = trim((string) ($ledger->NAME ?? $ledger['NAME'] ?? ''));
            if ($name === '') {
                continue;
            }
            $closing = (float) ($ledger->CLOSINGBALANCE ?? 0);
            if ($closing == 0.0) {
                continue;
            }
            $opening = (float) ($ledger->OPENINGBALANCE ?? 0);
            $rows[] = [
                'ledger_name' => $name,
                'parent_group' => trim((string) ($ledger->PARENT ?? '')),
                'amount' => abs($closing),
                'type' => $closing < 0 ? 'CR' : 'DR',
                'opening_amount' => abs($opening),
                'opening_type' => $opening != 0.0 ? ($opening < 0 ? 'CR' : 'DR') : '',
            ];
        }
    }

    return $rows;
}

$rows = extractTrialBalanceRows($xml);
if (empty($rows)) {
    if (isLocalEnv()) {
        $logDir = __DIR__ . '/../bridge_logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents($logDir . '/tb_debug.xml', $xmlRaw);
    }
    $dspNames = $xml->xpath("//*[local-name()='DSPACCNAME']") ?: [];
    $ledgers = $xml->xpath("//*[local-name()='LEDGER']") ?: [];
    $lines = $xml->xpath("//*[local-name()='DSPACCLINE']") ?: [];
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'No trial balance data found',
        'counts' => [
            'dspaccname' => count($dspNames),
            'ledger' => count($ledgers),
            'dsplaceline' => count($lines),
        ],
    ]);
    exit;
}

/* ---- Resolve opening balances from previous FY closing ---- */
$openingRows = [];
$openingSource = 'none';
$openingWarning = null;
$openingApplied = 0;

try {
    $fyStmt = $pdo->prepare("SELECT fy_label FROM financial_years WHERE id = ? AND company_id = ? LIMIT 1");
    $fyStmt->execute([$fyId, $companyId]);
    $fyLabel = (string) $fyStmt->fetchColumn();

    if ($fyLabel !== '') {
        $previousFyLabel = getPreviousFinancialYearLabel($fyLabel);

        if ($previousFyLabel !== '') {
            $previousFy = findFinancialYearByLabel($pdo, $previousFyLabel, $companyId);

            if ($previousFy !== null) {
                $previousFyId = (int) ($previousFy['id'] ?? 0);

                if ($previousFyId > 0) {
                    $prevCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tally_ledgers WHERE company_id = ? AND fy_id = ?");
                    $prevCountStmt->execute([$companyId, $previousFyId]);
                    $hasStoredPreviousYear = (int) $prevCountStmt->fetchColumn() > 0;

                    if ($hasStoredPreviousYear) {
                        $openingRows = loadOpeningRowsFromStoredYear($pdo, $companyId, $previousFyId);
                        $openingSource = 'previous_fy_closing';
                        $openingApplied = count($openingRows);
                    } else {
                        $openingWarning = 'Previous financial year exists but no stored trial balance was found; opening balances set to zero.';
                    }
                } else {
                    $openingWarning = 'Previous financial year ID is invalid; opening balances set to zero.';
                }
            } else {
                $openingWarning = 'No previous financial year trial balance found; opening balances set to zero.';
            }
        } else {
            $openingWarning = 'Could not determine previous financial year; opening balances set to zero.';
        }
    } else {
        $openingWarning = 'Current financial year label not found; opening balances set to zero.';
    }
} catch (Throwable $e) {
    $openingWarning = 'Opening balance resolution failed: ' . $e->getMessage() . '. Opening balances set to zero.';
    $openingRows = [];
    $openingSource = 'none';
    $openingApplied = 0;
}

try {
    $result = importTrialBalanceRows($pdo, $companyId, $fyId, $rows, [], $openingRows, true);

    if (!(bool) ($result['ok'] ?? false)) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'message' => 'Trial balance has unmapped ledgers.',
            'unknowns' => $result['unknowns'] ?? [],
        ]);
        exit;
    }

    $stats = $result['stats'] ?? [];
    echo json_encode([
        'ok' => true,
        'rows' => (int) ($stats['total'] ?? count($rows)),
        'client_id' => $clientId,
        'dr_total' => $stats['dr_total'] ?? 0,
        'cr_total' => $stats['cr_total'] ?? 0,
        'opening_rows_applied' => $openingApplied,
        'opening_source' => $openingSource,
        'opening_warning' => $openingWarning,
    ]);
} catch (Throwable $e) {
    appLog('ERROR', 'Bridge TB upload failed', [
        'client_id' => $clientId,
        'company_id' => $companyId,
        'fy_id' => $fyId,
        'message' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
