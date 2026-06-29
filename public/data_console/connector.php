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

/* ---- Resolve Bridge URL from configuration ---- */
$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') {
    $bridgeUrl = 'http://127.0.0.1:9123';
}
$bridgeUrl = rtrim($bridgeUrl, '/');

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

    <!-- Status Panel -->
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:20px;">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:.88rem;">
            <strong>Bridge Status:</strong>
            <span id="bridgeStatus" style="font-weight:600;color:var(--muted);">Checking…</span>

            <strong>Tally Status:</strong>
            <span id="tallyStatus" style="font-weight:600;color:var(--muted);">Checking…</span>

            <strong>Last Response:</strong>
            <span id="bridgeResponse" style="color:var(--muted);">—</span>
        </div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
        <?= uiButton('Start Bridge Sync', 'javascript:void(0)', 'primary', '🔄', 'id="btn-sync"') ?>
        <?= uiButton('Refresh Status', 'javascript:void(0)', 'outline', '🔄', 'id="btn-refresh"') ?>
        <?= uiButton('Back to Ledger Import', BASE_URL . 'data_console/tally_online.php', 'outline', '←') ?>
    </div>

    <!-- Sync Result Panel -->
    <div id="sync-result" style="display:none;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:20px;">
        <div style="font-size:.88rem;font-weight:700;color:var(--text);margin-bottom:12px;">Bridge Response</div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 16px;font-size:.82rem;">
            <strong>Status:</strong> <span id="res-status">—</span>
            <strong>Tally:</strong> <span id="res-tally">—</span>
            <strong>Active Company:</strong> <span id="res-company">—</span>
            <strong>Ledger Count:</strong> <span id="res-ledgers">—</span>
            <strong>Trial Balance:</strong> <span id="res-tb">—</span>
            <strong>Last Sync:</strong> <span id="res-sync">—</span>
            <strong>Error:</strong> <span id="res-error" style="color:var(--danger);">—</span>
        </div>
    </div>

    <script>
        const bridgeToken = <?= json_encode($bridgeToken) ?>;
        const bridgeClientId = <?= json_encode($bridgeClientId) ?>;
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const activeCompanyId = <?= (int) $companyId ?>;
        const activeFyId = <?= (int) $fyId ?>;
        const companyNameStr = <?= json_encode($companyName) ?>;
        const bridgeUrl = <?= json_encode(rtrim($bridgeUrl, '/') . '/sync') ?>;
        const healthUrl = <?= json_encode(rtrim($bridgeUrl, '/') . '/health') ?>;
        const bridgeContextUrl = '<?= BASE_URL ?>bridge_context.php';
        const ledgerUploadUrl = <?= json_encode(BASE_URL . 'bridge_ledger.php') ?>;
        const tbUploadUrl = <?= json_encode(BASE_URL . 'bridge_tb.php') ?>;
        const statusUrl = '<?= BASE_URL ?>data_console/bridge_status.php';

        function setEl(id, text) {
            var el = document.getElementById(id);
            if (el) el.textContent = text;
        }
        function setElColor(id, color) {
            var el = document.getElementById(id);
            if (el) el.style.color = color;
        }

        function showResult() {
            var panel = document.getElementById('sync-result');
            if (panel) panel.style.display = 'block';
        }

        /* ---- Initial health check on page load ---- */
        function checkBridgeHealth() {
            setEl('bridgeStatus', 'Checking…');
            setElColor('bridgeStatus', 'var(--muted)');
            setEl('tallyStatus', 'Checking…');
            setElColor('tallyStatus', 'var(--muted)');

            fetch(healthUrl, { mode: 'cors', cache: 'no-store' })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(data) {
                    if (data && data.ok) {
                        setEl('bridgeStatus', 'Online');
                        setElColor('bridgeStatus', 'var(--success)');
                        var tallyState = data.tally || 'unknown';
                        setEl('tallyStatus', tallyState === 'connected' ? 'Connected' : 'Not Connected');
                        setElColor('tallyStatus', tallyState === 'connected' ? 'var(--success)' : 'var(--warning)');
                    } else {
                        setEl('bridgeStatus', 'Offline');
                        setElColor('bridgeStatus', 'var(--danger)');
                        setEl('tallyStatus', 'Unknown');
                        setElColor('tallyStatus', 'var(--muted)');
                    }
                })
                .catch(function() {
                    setEl('bridgeStatus', 'Offline');
                    setElColor('bridgeStatus', 'var(--danger)');
                    setEl('tallyStatus', 'Unknown');
                    setElColor('tallyStatus', 'var(--muted)');
                });
        }

        /* ---- Register context then trigger sync ---- */
        async function runBridgeSync() {
            var btn = document.getElementById('btn-sync');
            if (btn) { btn.disabled = true; btn.textContent = 'Syncing…'; }
            setEl('bridgeStatus', 'Syncing…');
            setElColor('bridgeStatus', 'var(--warning)');
            showResult();
            setEl('res-status', 'Registering company context…');
            setEl('res-tally', '—');
            setEl('res-error', '—');
            try {
                await fetch(bridgeContextUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ client_id: bridgeClientId, _csrf_token: csrfToken, company_id: activeCompanyId, fy_id: activeFyId })
                }).then(function(r) { return r.json(); }).then(function(d) { if (!d.ok) throw new Error(d.message || 'Context registration failed.'); });

                setEl('res-status', 'Sync triggered…');
                var resp = await fetch(bridgeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...(bridgeToken ? { 'X-Bridge-Token': bridgeToken } : {}) },
                    body: JSON.stringify({ client_id: bridgeClientId, site_origin: window.location.origin, ledger_upload_url: ledgerUploadUrl, tb_upload_url: tbUploadUrl })
                });
                var data = await resp.json().catch(function() { return {}; });
                setEl('res-status', data.ok ? 'Sync queued' : 'Sync failed');
                setEl('res-company', companyNameStr);
                setEl('bridgeStatus', data.ok ? 'Synced' : 'Failed');
                setElColor('bridgeStatus', data.ok ? 'var(--success)' : 'var(--danger)');
                setEl('res-error', data.ok ? '—' : (data.message || 'Bridge returned an error.'));
                setEl('res-sync', new Date().toLocaleString());
                if (data.ok) { pollForResult(true); }
            } catch (err) {
                setEl('bridgeStatus', 'Offline');
                setElColor('bridgeStatus', 'var(--danger)');
                setEl('res-status', 'Failed');
                setEl('res-error', err.message || 'Bridge not reachable. Start the Smart Bridge app.');
            }
            if (btn) { btn.disabled = false; btn.textContent = 'Start Bridge Sync'; }
        }

        /* ---- Poll workflow status for completion ---- */
        function pollForResult(auto) {
            var tries = 0;
            var maxTries = 20;
            function tick() {
                tries++;
                fetch(statusUrl + '?ts=' + Date.now(), { cache: 'no-store' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        setEl('res-ledgers', data.ledger_fetched === 1 ? 'Imported' : 'Pending');
                        setEl('res-tb', data.tally_fetched === 1 ? 'Imported' : 'Pending');
                        if (data.ok && data.ledger_fetched === 1) {
                            setEl('bridgeStatus', 'Sync Complete');
                            setElColor('bridgeStatus', 'var(--success)');
                            setEl('res-status', 'Ledger sync completed');
                            setEl('res-error', '—');
                            setEl('res-sync', new Date().toLocaleString());
                            return;
                        }
                        if (tries < maxTries) setTimeout(tick, 3000);
                    })
                    .catch(function() { if (tries < maxTries) setTimeout(tick, 3000); });
            }
            tick();
        }

        /* ---- Bind buttons ---- */
        document.getElementById('btn-sync').addEventListener('click', runBridgeSync);
        document.getElementById('btn-refresh').addEventListener('click', function() {
            checkBridgeHealth();
            fetch(statusUrl + '?ts=' + Date.now(), { cache: 'no-store' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    showResult();
                    setEl('res-status', data.ok ? 'Status loaded' : 'Unavailable');
                    setEl('res-ledgers', data.ledger_fetched === 1 ? 'Imported' : 'Pending');
                    setEl('res-tb', data.tally_fetched === 1 ? 'Imported' : 'Pending');
                    setEl('res-error', data.ok ? '—' : (data.message || 'Status not available'));
                });
        });

        /* ---- Init: run health check on load ---- */
        checkBridgeHealth();
    </script>

    <?= uiWorkspaceEnd() ?>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

/* ========= FALLBACK: FETCH FROM TALLY DIRECT (non-bridge) ========= */
/* This code path is reached when ?bridge=1 is NOT set.
   It performs a direct PHP cURL call to Tally — not recommended. */

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
    <?= uiContextCard(['company' => $companyName, 'fy' => $fyName]) ?>
    <?= uiWorkspaceStart() ?>
    <div class="error-box"><p><?= htmlspecialchars($errorMessage) ?></p></div>
    <div class="card">The live Tally bridge did not respond successfully. Check that your Tally Bridge is running.</div>
    <div style="margin-top:20px;">
        <?= uiButton('Back to Online Console', BASE_URL . 'data_console/tally_online.php', 'outline', '←') ?>
    </div>
    <?= uiWorkspaceEnd() ?>
    <?php require_once __DIR__ . '/../layouts/footer_v2.php'; exit; }
    $response = sanitizeTallyXML($response);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $apiUrl = $scheme . '://' . $host . BASE_URL . 'api/receive_data.php';
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $response, CURLOPT_HTTPHEADER => ["Content-Type: application/xml"],
        CURLOPT_COOKIE => $sessionCookie, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        $errorMessage = "Error contacting e-BAL API: " . curl_error($ch);
        curl_close($ch);
        require_once __DIR__ . '/../layouts/header_v2.php';
        ?>
        <?= uiBreadcrumb([['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'], ['label' => 'Smart Bridge Connector']]) ?>
        <?= uiPageHero('e-BAL Sync Result') ?>
        <?= uiContextCard(['company' => $companyName, 'fy' => $fyName]) ?>
        <?= uiWorkspaceStart() ?>
        <div class="error-box"><p><?= htmlspecialchars($errorMessage) ?></p></div>
        <div class="card">Tally returned data, but the application could not complete the ledger import.</div>
        <div style="margin-top:20px;">
            <?= uiButton('Back to Online Console', BASE_URL . 'data_console/tally_online.php', 'outline', '←') ?>
        </div>
        <?= uiWorkspaceEnd() ?>
        <?php require_once __DIR__ . '/../layouts/footer_v2.php'; exit; }
    curl_close($ch);
    $resultText = trim((string) $result);
    $isSuccess = stripos($resultText, 'SUCCESS:') === 0;
    if (preg_match('/SUCCESS:\s*([0-9]+)\s+ledgers inserted/i', $resultText, $matches)) {
        $ledgerCount = (int) $matches[1];
    } else { $ledgerCount = null; }

    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <?= uiBreadcrumb([['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'], ['label' => 'Smart Bridge Connector']]) ?>
    <?= uiPageHero('e-BAL Sync Result') ?>
    <?= uiContextCard(['company' => $companyName, 'fy' => $fyName]) ?>
    <?= uiWorkspaceStart() ?>
    <?php if ($isSuccess): ?>
        <div class="success-box"><p>Ledger sync completed successfully<?= $ledgerCount !== null ? ' with ' . $ledgerCount . ' ledgers imported' : '' ?>.</p></div>
        <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">
            <?= uiButton('Continue to Mapping', BASE_URL . 'data_console/mapping_console.php', 'primary') ?>
            <?= uiButton('Re-sync Ledgers', BASE_URL . 'data_console/connector.php', 'outline') ?>
            <?= uiButton('Back to Online Console', BASE_URL . 'data_console/tally_online.php', 'outline', '←') ?>
        </div>
    <?php else: ?>
        <div class="error-box"><p>The sync completed with an application response that needs attention.</p></div>
        <div class="card"><pre style="white-space:pre-wrap;margin:0;font-family:Consolas,monospace;color:#17312f;"><?= htmlspecialchars($resultText) ?></pre></div>
        <div style="margin-top:20px;"><?= uiButton('Back to Online Console', BASE_URL . 'data_console/tally_online.php', 'outline', '←') ?></div>
    <?php endif; ?>
    <?= uiWorkspaceEnd() ?>
    <?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
