<?php
require_once '../../app/context_check.php';
require_once '../../app/helpers/financial_year_helper.php';

/* Handle FY selection POST before any output */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['context_action']) && $_POST['context_action'] === 'select_fy') {
    requireCsrfToken();
    $selFyId = (int) ($_POST['fy_id'] ?? 0);
    if ($selFyId > 0 && hasCompanyContext()) {
        $fy = findFinancialYearById($pdo, $selFyId, $_SESSION['company_id']);
        if ($fy) {
            $_SESSION['fy_id'] = $fy['id'];
            $_SESSION['fy_name'] = $fy['fy_label'];
        }
    }
    header("Location: " . BASE_URL . "data_console/connector.php");
    exit;
}

requireFullContext();
require_once '../../config/app.php';
require_once '../../app/helpers/security_helper.php';
require_once '../../app/helpers/xml_sanitizer.php';
require_once '../../xml_engine/tally_connector.php';

$page_title = "Sync Result";
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$companyId = (int) ($_SESSION['company_id'] ?? 0);
$fyId = (int) ($_SESSION['fy_id'] ?? 0);
$sessionCookie = session_name() . '=' . session_id();
$bridgeMode = isset($_GET['bridge']) && $_GET['bridge'] === '1';
$bridgeToken = buildBridgeBrowserToken('sync');
$bridgeClientId = 'EBAL001';
$csrfToken = csrfToken();
$appOrigin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Release the session lock before calling the local API with the same cookie.
session_write_close();

/* ========= BRIDGE MODE ========= */
if ($bridgeMode) {
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <?= uiBreadcrumb([
        ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
        ['label' => 'Smart Bridge Connector'],
    ]) ?>

    <?= uiPageHero('e-BAL Smart Bridge', 'Click the button below to trigger the Smart Bridge running on this PC.') ?>

    <?= uiContextCard([
        'company' => $companyName,
        'fy' => $fyName,
    ]) ?>

    <?= uiWorkspaceStart() ?>

    <div class="card" style="margin-bottom:16px;">
        <strong>Status:</strong> <span id="bridgeStatus">Waiting</span><br>
        <strong>Last Response:</strong> <span id="bridgeResponse">-</span>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
        <button class="btn" type="button" onclick="runBridgeSync()">Start Bridge Sync</button>
        <button class="btn" type="button" onclick="pollStatus()">Refresh Status</button>
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>

    <script>
        const bridgeToken = <?= json_encode($bridgeToken) ?>;
        const bridgeClientId = <?= json_encode($bridgeClientId) ?>;
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const activeCompanyId = <?= (int) $companyId ?>;
        const activeFyId = <?= (int) $fyId ?>;
        const bridgeUrl = <?= json_encode(rtrim($bridgeUrl ?? 'http://127.0.0.1:9123', '/') . '/sync') ?>;
        const bridgeContextUrl = '<?= BASE_URL ?>bridge_context.php';
        const ledgerUploadUrl = <?= json_encode(BASE_URL . 'bridge_ledger.php') ?>;
        const tbUploadUrl = <?= json_encode(BASE_URL . 'bridge_tb.php') ?>;

        function setStatus(text) {
            const el = document.getElementById('bridgeStatus');
            if (el) el.textContent = text;
        }

        function setResponse(text) {
            const el = document.getElementById('bridgeResponse');
            if (el) el.textContent = text;
        }

        async function registerBridgeContext() {
            const response = await fetch(bridgeContextUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    client_id: bridgeClientId,
                    _csrf_token: csrfToken,
                    company_id: activeCompanyId,
                    fy_id: activeFyId
                })
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'Unable to register bridge context.');
            }
        }

        async function runBridgeSync() {
            setStatus('Requesting...');
            setResponse('Registering company context...');
            try {
                await registerBridgeContext();
                const resp = await fetch(bridgeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(bridgeToken ? { 'X-Bridge-Token': bridgeToken } : {})
                    },
                    body: JSON.stringify({
                        client_id: bridgeClientId,
                        site_origin: window.location.origin,
                        ledger_upload_url: ledgerUploadUrl,
                        tb_upload_url: tbUploadUrl
                    })
                });
                const data = await resp.json().catch(() => ({}));
                setStatus(data.ok ? 'Sync queued' : 'Failed');
                const targetInfo = data.targets?.ledger_upload_url ? ` -> ${data.targets.ledger_upload_url}` : '';
                setResponse((data.message || 'No response message') + targetInfo);
                if (data.ok) {
                    pollStatus(true);
                }
            } catch (err) {
                setStatus('Failed');
                setResponse(err.message || 'Bridge not reachable. Start the Smart Bridge app.');
            }
        }

        function pollStatus(auto) {
            const statusUrl = '<?= BASE_URL ?>data_console/bridge_status.php?ts=' + Date.now();
            fetch(statusUrl, { cache: 'no-store' })
                .then(resp => resp.json())
                .then(data => {
                    if (data.ok && data.ledger_fetched === 1) {
                        setStatus('Ledger sync completed');
                        setResponse('Ledgers imported. Continue to mapping.');
                        if (auto) {
                            setTimeout(() => {
                                window.location.href = '<?= BASE_URL ?>data_console/mapping_console.php';
                            }, 1200);
                        }
                    } else if (data.ok) {
                        setStatus('Waiting for sync...');
                        setResponse('Ledger sync not completed yet.');
                    } else {
                        setStatus('Unavailable');
                        setResponse(data.message || 'Status not available');
                    }
                })
                .catch(() => {
                    setStatus('Unavailable');
                    setResponse('Could not read status.');
                });
        }
    </script>
    <?= uiWorkspaceEnd() ?>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

/* ========= FETCH FROM TALLY ========= */

$xml = <<<XML
<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>LedgerList</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
     <SVEXPORTFORMAT>XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="LedgerList">
      <TYPE>Ledger</TYPE>
      <FETCH>Name, Parent</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
XML;

$response = fetchFromTally($xml);
if ($response === false) {
    $errorMessage = "Error contacting Tally.";
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <?= uiBreadcrumb([
        ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
        ['label' => 'Smart Bridge Connector'],
    ]) ?>

    <?= uiPageHero('e-BAL Sync Result') ?>

    <?= uiContextCard([
        'company' => $companyName,
        'fy' => $fyName,
    ]) ?>

    <?= uiWorkspaceStart() ?>

    <div class="error-box"><p><?= htmlspecialchars($errorMessage) ?></p></div>
    <div class="card">
        The live Tally bridge did not respond successfully. Check that your Tally Bridge is running, the XML interface is enabled in Tally, and the bridge URL is reachable from this machine.
    </div>
    <div style="margin-top:20px;">
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>

    <?= uiWorkspaceEnd() ?>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

/* ========= SANITIZE ========= */
$response = sanitizeTallyXML($response);

/* ========= PUSH TO API ========= */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiUrl = $scheme . '://' . $host . BASE_URL . 'api/receive_data.php';
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $response,
    CURLOPT_HTTPHEADER => ["Content-Type: application/xml"],
    CURLOPT_COOKIE => $sessionCookie,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
]);
$result = curl_exec($ch);
if ($result === false) {
    $errorMessage = "Error contacting e-BAL API: " . curl_error($ch);
    curl_close($ch);
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <?= uiBreadcrumb([
        ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
        ['label' => 'Smart Bridge Connector'],
    ]) ?>

    <?= uiPageHero('e-BAL Sync Result') ?>

    <?= uiContextCard([
        'company' => $companyName,
        'fy' => $fyName,
    ]) ?>

    <?= uiWorkspaceStart() ?>

    <div class="error-box"><p><?= htmlspecialchars($errorMessage) ?></p></div>
    <div class="card">
        Tally returned data, but the application could not complete the ledger import. Retry once, and if it persists we should inspect the local API logs.
    </div>
    <div style="margin-top:20px;">
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>

    <?= uiWorkspaceEnd() ?>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}
curl_close($ch);

$resultText = trim((string) $result);
$isSuccess = stripos($resultText, 'SUCCESS:') === 0;

if (preg_match('/SUCCESS:\s*([0-9]+)\s+ledgers inserted/i', $resultText, $matches)) {
    $ledgerCount = (int) $matches[1];
} else {
    $ledgerCount = null;
}

require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Smart Bridge Connector'],
]) ?>

<?= uiPageHero('e-BAL Sync Result') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
]) ?>

<?= uiWorkspaceStart() ?>

<?php if ($isSuccess): ?>
    <div class="success-box">
        <p>Ledger sync completed successfully<?= $ledgerCount !== null ? ' with ' . $ledgerCount . ' ledgers imported' : '' ?>.</p>
    </div>

    <div class="summary-bar">
        <div class="summary-card">
            <div class="summary-number"><?= $ledgerCount !== null ? $ledgerCount : '-' ?></div>
            <div class="summary-label">Ledgers Imported</div>
        </div>
        <div class="summary-card">
            <div class="summary-number">1</div>
            <div class="summary-label">Sync Run Completed</div>
        </div>
    </div>

    <div class="card">
        The ledger master is now available for this company and financial year. The next step is to review mapping suggestions and confirm the schedule heads before trial balance fetch.
    </div>

    <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Continue</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/connector.php">Re-sync Ledgers</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>
<?php else: ?>
    <div class="error-box">
        <p>The sync completed with an application response that needs attention.</p>
    </div>

    <div class="card">
        <pre style="white-space:pre-wrap; margin:0; font-family:Consolas, monospace; color:#17312f;"><?= htmlspecialchars($resultText) ?></pre>
    </div>

    <div style="margin-top:20px;">
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>
<?php endif; ?>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
