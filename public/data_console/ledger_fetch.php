<?php
require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../xml_engine/tally_connector.php';
require_once '../../app/context_check.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$page_title = 'Ledger Fetch Result';
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

function renderLedgerFetchPage(string $title, string $message, bool $success = false, ?string $details = null): void
{
    global $page_title, $companyName, $fyName;

    require __DIR__ . '/../layouts/header.php';
    ?>
    <div class="page-title"><?= htmlspecialchars($title) ?></div>

    <div class="active-info">
        Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
        FY: <strong><?= htmlspecialchars($fyName) ?></strong>
    </div>

    <?php if ($success): ?>
        <div class="success-box"><p><?= htmlspecialchars($message) ?></p></div>
    <?php else: ?>
        <div class="error-box"><p><?= htmlspecialchars($message) ?></p></div>
    <?php endif; ?>

    <?php if ($details !== null && $details !== ''): ?>
        <div class="card"><pre style="white-space:pre-wrap; margin:0;"><?= htmlspecialchars($details) ?></pre></div>
    <?php endif; ?>

    <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
        <?php if ($success): ?>
            <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Next Process: Mapping Console</a>
        <?php endif; ?>
        <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
    </div>

    <?php
    require __DIR__ . '/../layouts/footer.php';
}

$xml = <<<XML
<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export Data</TALLYREQUEST>
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

if (!$response) {
    renderLedgerFetchPage('Ledger Fetch Result', 'No response was received from Tally.');
    exit;
}

$isUtf16 = false;
if (strncmp($response, "\xFF\xFE", 2) === 0 || strncmp($response, "\xFE\xFF", 2) === 0) {
    $isUtf16 = true;
} elseif (strpos(substr($response, 0, 200), "\x00") !== false) {
    $isUtf16 = true;
}

if ($isUtf16) {
    if (strncmp($response, "\xFF\xFE", 2) === 0) {
        $response = substr($response, 2);
        $converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', $response);
    } elseif (strncmp($response, "\xFE\xFF", 2) === 0) {
        $response = substr($response, 2);
        $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $response);
    } else {
        $converted = @iconv('UTF-16', 'UTF-8//IGNORE', $response);
    }

    if ($converted !== false) {
        $response = $converted;
    }
}

$response = preg_replace('/^\xEF\xBB\xBF/', '', $response);
$response = preg_replace('/<\?xml([^>]*?)encoding=["\']utf-16["\']([^>]*?)\?>/i', '<?xml$1encoding="utf-8"$2?>', $response, 1);
$response = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $response);

if (stripos($response, '<ENVELOPE') === false) {
    renderLedgerFetchPage('Ledger Fetch Result', 'Tally returned a response, but it is not valid XML.', false, substr($response, 0, 1000));
    exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadXML($response, LIBXML_NOERROR | LIBXML_NOWARNING);

if (!$loaded) {
    $messages = [];
    foreach (libxml_get_errors() as $error) {
        $messages[] = trim($error->message);
    }
    renderLedgerFetchPage('Ledger Fetch Result', 'Ledger XML could not be parsed.', false, implode(PHP_EOL, $messages));
    exit;
}

$xpath = new DOMXPath($dom);
$nodes = $xpath->query("//*[local-name()='LEDGER']");

if ($nodes->length === 0) {
    renderLedgerFetchPage('Ledger Fetch Result', 'No ledger data was found in the Tally response.');
    exit;
}

$pdo->beginTransaction();

try {
    $pdo->prepare("DELETE FROM tally_ledger_master WHERE company_id=?")->execute([$company_id]);

    $stmt = $pdo->prepare("
        INSERT INTO tally_ledger_master
        (company_id, ledger_name, parent_group)
        VALUES (?, ?, ?)
    ");

    $count = 0;
    foreach ($nodes as $node) {
        $name = trim($node->getAttribute('NAME'));
        $parentNode = $node->getElementsByTagName('PARENT')->item(0);
        $parent = $parentNode ? trim($parentNode->nodeValue) : '';

        if ($name === '') {
            continue;
        }

        $stmt->execute([$company_id, $name, $parent]);
        $count++;
    }

    $pdo->prepare("
        INSERT INTO workflow_status
        (company_id, fy_id, ledger_fetched, updated_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            ledger_fetched = 1,
            updated_at = NOW()
    ")->execute([$company_id, $fy_id]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    renderLedgerFetchPage('Ledger Fetch Result', 'Database error while saving ledgers.', false, $e->getMessage());
    exit;
}

require __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Ledger Fetch Result</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fyName) ?></strong>
</div>

<div class="success-box">
    <p>Ledger fetch completed successfully.</p>
</div>

<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= (int) $count ?></div>
        <div class="summary-label">Ledgers Imported</div>
    </div>
    <div class="summary-card">
        <div class="summary-number">1</div>
        <div class="summary-label">Step Completed</div>
    </div>
</div>

<div class="card">
    The ledger master is now ready. The next process is to review the mapping suggestions and confirm the note heads before fetching the trial balance.
</div>

<div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
    <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Continue</a>
    <a class="btn" href="<?= BASE_URL ?>data_console/ledger_fetch.php">Re-sync Ledgers</a>
    <a class="btn" href="<?= BASE_URL ?>data_console/tally_online.php">Back to Online Console</a>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
