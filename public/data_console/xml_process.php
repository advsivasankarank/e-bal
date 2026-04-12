<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/xml_sanitizer.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

/* =========================
   FILE VALIDATION
========================= */
if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = "File upload failed";
    $_SESSION['process_stats'] = ['total'=>0,'dr_total'=>0,'cr_total'=>0,'type'=>'xml'];
    header("Location: /e-bal/public/data_console/process_result.php");
    exit;
}

/* =========================
   LOAD XML
========================= */
libxml_use_internal_errors(true);

// Read raw file
$rawXml = file_get_contents($_FILES['xml_file']['tmp_name']);

// Convert and sanitize XML
$rawXml = sanitizeTallyXML($rawXml);

// Now parse
$xmlObj = simplexml_load_string($rawXml);

if (!$xmlObj) {
    $errors = libxml_get_errors();
    $_SESSION['error'] = "Invalid XML format. Parser errors: " . print_r($errors, true);
    $_SESSION['process_stats'] = ['total'=>0,'dr_total'=>0,'cr_total'=>0,'type'=>'xml'];
    header("Location: /e-bal/public/data_console/process_result.php");
    exit;
}


/* =========================
   TRANSACTION START
========================= */
$pdo->beginTransaction();

try {
    // Clean old data
    $pdo->prepare("DELETE FROM tally_ledgers WHERE company_id=? AND fy_id=?")
        ->execute([$company_id, $fy_id]);

    $stmt = $pdo->prepare("
        INSERT INTO tally_ledgers 
        (company_id, fy_id, ledger_name, parent_group, amount, dr_cr, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $dataInserted = 0;
    $drTotal = 0;
    $crTotal = 0;

    function processXmlNode($node, $stmt, $company_id, $fy_id, &$dataInserted, &$drTotal, &$crTotal)
    {
        $name = isset($node->dspaccname->dspdispname) 
            ? trim((string)$node->dspaccname->dspdispname) 
            : '';

        if ($name !== '' && isset($node->dspaccinfo[0])) {
            $info = $node->dspaccinfo[0];
            $dr   = (float)($info->dspcldramt->dspcldramta ?? 0);
            $cr   = (float)($info->dspclcramt->dspclcramta ?? 0);

            $amount = 0;
            $dr_cr  = null;

            if ($dr != 0) {
                $amount = abs($dr);
                $dr_cr  = "DR";
                $drTotal += $amount;
            } elseif ($cr != 0) {
                $amount = abs($cr);
                $dr_cr  = "CR";
                $crTotal += $amount;
            }

            if ($amount > 0 && $dr_cr) {
                $stmt->execute([$company_id, $fy_id, $name, "ROOT", $amount, $dr_cr]);
                $dataInserted++;
            }
        }

        // Recurse into children
        if (isset($node->grpexplosion->dspaccline)) {
            foreach ($node->grpexplosion->dspaccline as $child) {
                processXmlNode($child, $stmt, $company_id, $fy_id, $dataInserted, $drTotal, $crTotal);
            }
        }
    }

    // Root parsing
    if (isset($xmlObj->dspaccbody->dspaccline)) {
        foreach ($xmlObj->dspaccbody->dspaccline as $node) {
            processXmlNode($node, $stmt, $company_id, $fy_id, $dataInserted, $drTotal, $crTotal);
        }
    }

    // Workflow update
    $pdo->prepare("
        INSERT INTO workflow_status 
        (company_id, fy_id, tally_fetched, mapping_completed, verified, reports_generated, updated_at)
        VALUES (?, ?, 0, 0, 0, 0, NOW())
        ON DUPLICATE KEY UPDATE updated_at=NOW()
    ")->execute([$company_id, $fy_id]);

    updateWorkflow($company_id, $fy_id, 'tally_fetched');

    // Result session
    $_SESSION['process_stats'] = [
        'total'    => $dataInserted,
        'dr_total' => $drTotal,
        'cr_total' => $crTotal,
        'type'     => 'xml'
    ];

    if ($dataInserted === 0) {
        $pdo->rollBack();
        $_SESSION['error'] = "No valid data found in XML";
    } else {
        $pdo->commit();
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = "Processing error: " . $e->getMessage();
    $_SESSION['process_stats'] = ['total'=>0,'dr_total'=>0,'cr_total'=>0,'type'=>'xml'];
}

/* =========================
   FINAL REDIRECT
========================= */
session_write_close();
header("Location: /e-bal/public/data_console/process_result.php");
exit;
