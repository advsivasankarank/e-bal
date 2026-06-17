<?php
require_once '../../app/context_check.php';
require_once '../../xml_engine/tally_connector.php';
require_once '../../config/database.php';
require_once '../../app/helpers/xml_sanitizer.php';
require_once '../../config/app.php';
require_once '../../app/helpers/security_helper.php';

requireFullContext();

$page_title = "Tally Connect";
require_once __DIR__ . '/../layouts/header.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$fy_label = $_SESSION['fy_name'] ?? '';
$selectedCompanyName = $_SESSION['company_name'] ?? 'Not Selected';
$pageError = $_SESSION['error'] ?? '';
$bridgeClientId = 'EBAL001';
$appOrigin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$stmt = $pdo->prepare("SELECT ledger_fetched, mapping_completed, tally_fetched FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$mappingDone = (int) ($workflow['mapping_completed'] ?? 0);
$tallyFetched = (int) ($workflow['tally_fetched'] ?? 0);
$bridgeMode = isset($_GET['bridge']) && $_GET['bridge'] === '1';
$bridgeToken = buildBridgeBrowserToken('sync');
$csrfToken = csrfToken();
$tallyContext = $bridgeMode ? null : fetchTallyLiveContext();
$tallyConnected = $bridgeMode ? false : ($tallyContext !== null);

$selectedCompanyNormalized = strtolower(trim(preg_replace('/\s+/', ' ', (string) $selectedCompanyName)));
$liveCompanyNormalized = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($tallyContext['company_name'] ?? ''))));
$companyMismatch = $tallyContext && $liveCompanyNormalized !== '' && $selectedCompanyNormalized !== '' && $selectedCompanyNormalized !== $liveCompanyNormalized;
$hasLiveCompanyName = $tallyContext && $liveCompanyNormalized !== '';
$hasLivePeriod = $tallyContext && !empty($tallyContext['period_from']) && !empty($tallyContext['period_to']);
?>

<div class="page-title">Tally Integration</div>

<?php if ($bridgeMode): ?>
    <div class="card" style="margin-bottom:20px;">
        Smart Bridge mode is enabled. The sync will be triggered on this PC and the bridge will upload the trial balance to e-BAL.
    </div>
<?php endif; ?>

<?php if (!$bridgeMode && $tallyConnected): ?>
    <div class="success">✅ Tally Connected</div>
<?php elseif (!$bridgeMode): ?>
    <div class="error-box">
        <p>Tally is not reachable right now. Check that Tally is running with XML over HTTP enabled on port 9000, then retry.</p>
    </div>
<?php endif; ?>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($selectedCompanyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fy_label) ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    Trial balance will be fetched for the active FY. Make sure mapping is complete before starting the fetch.
</div>

<?php if ($tallyFetched === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Trial balance is already fetched for this company and financial year. Do you want to continue with review/reports, or fetch the trial balance again?
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>dashboard_report.php">Go to Reports</a>
        </div>
    </div>
<?php endif; ?>

<?php if ($pageError !== ''): ?>
    <div class="error-box" style="margin-bottom:20px;">
        <p><?= htmlspecialchars($pageError) ?></p>
    </div>
<?php endif; ?>

<?php if (!$bridgeMode): ?>
<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= $tallyConnected ? 'Connected' : 'Offline' ?></div>
        <div class="summary-label">Tally Status</div>
    </div>
    <?php if ($hasLiveCompanyName): ?>
        <div class="summary-card">
            <div class="summary-number" style="font-size:1.1rem;"><?= htmlspecialchars($tallyContext['company_name']) ?></div>
            <div class="summary-label">Current Tally Company</div>
        </div>
    <?php endif; ?>
    <?php if ($hasLivePeriod): ?>
        <div class="summary-card">
            <div class="summary-number" style="font-size:1rem;"><?= htmlspecialchars($tallyContext['period_from'] . ' to ' . $tallyContext['period_to']) ?></div>
            <div class="summary-label">Current Tally Period</div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$bridgeMode): ?>
<div class="card" style="margin-bottom:20px;">
    Selected app FY: <strong><?= htmlspecialchars($fy_label) ?></strong><br>
    <?php if ($hasLiveCompanyName && $hasLivePeriod): ?>
        Live Tally company: <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong><br>
        Live Tally books appear open from <strong><?= htmlspecialchars($tallyContext['period_from']) ?></strong> to <strong><?= htmlspecialchars($tallyContext['period_to']) ?></strong>.
    <?php elseif ($hasLiveCompanyName): ?>
        Live Tally company: <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong><br>
        Tally is reachable, but the current books period could not be read from the live session.
    <?php elseif ($hasLivePeriod): ?>
        Live Tally books appear open from <strong><?= htmlspecialchars($tallyContext['period_from']) ?></strong> to <strong><?= htmlspecialchars($tallyContext['period_to']) ?></strong>.<br>
        Tally is reachable, but the current company name could not be read from the live session.
    <?php else: ?>
        Tally is reachable, but the current company name and books period could not be read from the live session.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$bridgeMode && $companyMismatch): ?>
    <div class="error-box">
        <p>Selected company in e-BAL is <strong><?= htmlspecialchars($selectedCompanyName) ?></strong>, but the live company open in Tally is <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong>. Review this carefully before fetching the trial balance.</p>
    </div>
<?php endif; ?>

<?php if (!$mappingDone): ?>
    <div class="error-box">
        <p>Complete ledger mapping before fetching the trial balance.</p>
    </div>
<?php endif; ?>

<?php if ($bridgeMode): ?>
    <div class="card" style="margin-bottom:20px;">
        <strong>Status:</strong> <span id="bridgeStatus">Waiting</span><br>
        <strong>Last Response:</strong> <span id="bridgeResponse">-</span>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <button type="button" class="btn" onclick="runBridgeSync()" <?= !$mappingDone ? 'disabled' : '' ?>><?= $tallyFetched === 1 ? 'Re-fetch via Bridge' : 'Fetch via Bridge' ?></button>
        <button type="button" class="btn" onclick="pollStatus()">Refresh Status</button>
    </div>

    <script>
        const bridgeToken = <?= json_encode($bridgeToken) ?>;
        const bridgeClientId = <?= json_encode($bridgeClientId) ?>;
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const activeCompanyId = <?= (int) $company_id ?>;
        const activeFyId = <?= (int) $fy_id ?>;
        const bridgeUrl = 'http://127.0.0.1:9123/sync';
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
                const targetInfo = data.targets?.tb_upload_url ? ` -> ${data.targets.tb_upload_url}` : '';
                setResponse((data.message || 'No response message') + targetInfo);
                if (data.ok) {
                    pollStatus(true);
                }
            } catch (error) {
                setStatus('Failed');
                setResponse(error.message || 'Bridge not reachable. Start the Smart Bridge app.');
            }
        }

        function pollStatus(auto) {
            const statusUrl = '<?= BASE_URL ?>data_console/bridge_status.php?ts=' + Date.now();
            fetch(statusUrl, { cache: 'no-store' })
                .then(resp => resp.json())
                .then(data => {
                    if (data.ok && data.tally_fetched === 1) {
                        setStatus('Trial balance fetched');
                        setResponse('TB imported. You can review now.');
                        if (auto) {
                            setTimeout(() => {
                                window.location.href = '<?= BASE_URL ?>data_console/trial_balance_preview.php';
                            }, 1200);
                        }
                    } else if (data.ok) {
                        setStatus('Waiting for sync...');
                        setResponse('Trial balance not imported yet.');
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
<?php else: ?>

<form method="post" action="<?= BASE_URL ?>data_console/fetch_process.php">

    <input type="hidden" name="confirmed" value="1">
    <input type="hidden" name="live_company_name" value="<?= htmlspecialchars($tallyContext['company_name'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="live_period_from" value="<?= htmlspecialchars($tallyContext['period_from'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="live_period_to" value="<?= htmlspecialchars($tallyContext['period_to'] ?? '', ENT_QUOTES) ?>">
    <?php if ($companyMismatch): ?>
        <label style="display:flex; align-items:flex-start; gap:10px; margin:16px 0;">
            <input type="checkbox" name="company_mismatch_confirmed" value="1" required>
            <span>I confirm that I still want to fetch the trial balance from the live Tally company <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong> even though it does not match the selected e-BAL company <strong><?= htmlspecialchars($selectedCompanyName) ?></strong>.</span>
        </label>
    <?php endif; ?>

    <button type="submit" class="btn" <?= (!$mappingDone || !$tallyConnected) ? 'disabled' : '' ?>><?= $tallyFetched === 1 ? 'Re-fetch Trial Balance' : 'Fetch Trial Balance' ?></button>

</form>
<?php endif; ?>

<?php unset($_SESSION['error']); ?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
