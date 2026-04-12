<?php
require_once '../../app/context_check.php';
requireFullContext();
require_once '../../config/app.php';
require_once '../../app/helpers/xml_sanitizer.php';
require_once '../../xml_engine/tally_connector.php';

$page_title = "Sync Result";
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$sessionCookie = session_name() . '=' . session_id();

// Release the session lock before calling the local API with the same cookie.
session_write_close();

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
    require_once __DIR__ . '/../layouts/header.php';
    ?>
    <div class="page-title">e-BAL Sync Result</div>
    <div class="active-info">
        Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
        FY: <strong><?= htmlspecialchars($fyName) ?></strong>
    </div>
    <div class="error-box"><p><?= htmlspecialchars($errorMessage) ?></p></div>
    <div class="card">
        The live Tally bridge did not respond successfully. Check that your Tally Bridge is running, the XML interface is enabled in Tally, and the bridge URL is reachable from this machine.
    </div>
    <div style="margin-top:20px;">
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer.php';
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
    require_once __DIR__ . '/../layouts/header.php';
    ?>
    <div class="page-title">e-BAL Sync Result</div>
    <div class="active-info">
        Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
        FY: <strong><?= htmlspecialchars($fyName) ?></strong>
    </div>
    <div class="error-box"><p><?= htmlspecialchars($errorMessage) ?></p></div>
    <div class="card">
        Tally returned data, but the application could not complete the ledger import. Retry once, and if it persists we should inspect the local API logs.
    </div>
    <div style="margin-top:20px;">
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer.php';
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

require_once __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">e-BAL Sync Result</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fyName) ?></strong>
</div>

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

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
