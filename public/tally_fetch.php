<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../xml_engine/tally_connector.php';

if (!isset($_GET['company_id']) || !isset($_GET['fy_id'])) {
    die("Invalid access.");
}

$company_id = intval($_GET['company_id']);
$fy_id = intval($_GET['fy_id']);

// Fetch FY
$stmt = $pdo->prepare("SELECT * FROM financial_years WHERE id = ?");
$stmt->execute([$fy_id]);
$fy = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fy) {
    die("Financial Year not found.");
}

$from_date = date('Ymd', strtotime($fy['fy_start']));
$to_date   = date('Ymd', strtotime($fy['fy_end']));

/* =====================================================
   🔷 STEP 1: FETCH TRIAL BALANCE
===================================================== */
$xmlTB = '
<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Data</TYPE>
  <ID>Trial Balance</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVFROMDATE>'.$from_date.'</SVFROMDATE>
    <SVTODATE>'.$to_date.'</SVTODATE>
   </STATICVARIABLES>
  </DESC>
 </BODY>
</ENVELOPE>';

$responseTB = fetchFromTally($xmlTB);

if (!$responseTB) {
    die("Trial Balance fetch failed.");
}

$xmlTBObj = simplexml_load_string($responseTB);

if (!$xmlTBObj) {
    die("Invalid Trial Balance XML.");
}

/* =====================================================
   🔷 STEP 2: FETCH GROUP MASTER
===================================================== */
$xmlGroup = '
<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>Group</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
     <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
  </DESC>
 </BODY>
</ENVELOPE>';

$responseGroup = fetchFromTally($xmlGroup);

if (!$responseGroup) {
    die("Group Master fetch failed.");
}

$xmlGroupObj = simplexml_load_string($responseGroup);

if (!$xmlGroupObj) {
    die("Invalid Group XML.");
}

/* =====================================================
   🔷 STEP 3: BUILD GROUP MASTER MAP
===================================================== */

$groupMaster = [];

if (isset($xmlGroupObj->BODY->DATA->COLLECTION->GROUP)) {
    foreach ($xmlGroupObj->BODY->DATA->COLLECTION->GROUP as $g) {

        $name = (string)$g->NAME;

        $groupMaster[$name] = [
            'parent' => (string)$g->PARENT,
            'is_revenue' => ((string)$g->ISREVENUE == 'Yes')
        ];
    }
}

/* =====================================================
   🔷 STEP 4: PARSE TRIAL BALANCE
===================================================== */

$data = [];

$names = $xmlTBObj->DSPACCNAME;
$infos = $xmlTBObj->DSPACCINFO;

$total = min(count($names), count($infos));

for ($i = 0; $i < $total; $i++) {

    $name = trim((string)$names[$i]->DSPDISPNAME);

    $dr = (float)$infos[$i]->DSPCLDRAMT->DSPCLDRAMTA;
    $cr = (float)$infos[$i]->DSPCLCRAMT->DSPCLCRAMTA;

    $amount = $cr != 0 ? $cr : $dr;

    if ($amount == 0) continue;

    /* =====================================================
       🔷 STEP 5: CLASSIFICATION USING GROUP MASTER
    ===================================================== */

    $isRevenue = false;

    if (isset($groupMaster[$name])) {
        $isRevenue = $groupMaster[$name]['is_revenue'];
    }

    $type = $isRevenue ? 'P&L' : 'Balance Sheet';

    $data[] = [
        'name' => $name,
        'amount' => abs($amount),
        'type' => $type
    ];
}

/* =====================================================
   🔷 STORE SESSION
===================================================== */
session_start();
$_SESSION['classified_data'] = $data;
$_SESSION['company_id'] = $company_id;
$_SESSION['fy_id'] = $fy_id;

/* =====================================================
   🔷 UPDATE WORKFLOW STATUS (tally_fetched)
===================================================== */
$stmt = $pdo->prepare("SELECT id FROM workflow_status WHERE company_id = ? AND fy_id = ?");
$stmt->execute([$company_id, $fy_id]);
$wsId = $stmt->fetchColumn();

if ($wsId) {
    $pdo->prepare("UPDATE workflow_status SET tally_fetched = 1 WHERE company_id = ? AND fy_id = ?")
        ->execute([$company_id, $fy_id]);
} else {
    $pdo->prepare("INSERT INTO workflow_status (company_id, fy_id, tally_fetched) VALUES (?, ?, 1)")
        ->execute([$company_id, $fy_id]);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Tally Data (Classified)</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 8px; }
        th { background: #f2f2f2; }
        .bs { background: #d4edda; }
        .pl { background: #f8d7da; }
        .btn {
            padding: 10px 15px;
            background: green;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<h2>Classified Financial Data</h2>

<table>
<tr>
    <th>Name</th>
    <th>Amount</th>
    <th>Type</th>
</tr>

<?php foreach ($data as $row): ?>
<tr class="<?php echo $row['type'] == 'Balance Sheet' ? 'bs' : 'pl'; ?>">
    <td><?php echo htmlspecialchars($row['name']); ?></td>
    <td><?php echo number_format($row['amount'], 2); ?></td>
    <td><?php echo $row['type']; ?></td>
</tr>
<?php endforeach; ?>

</table>

<br>

<a class="btn" href="generate_bs.php">Generate Balance Sheet</a>
<a class="btn" href="generate_pl.php">Generate P&L</a>

</body>
</html>