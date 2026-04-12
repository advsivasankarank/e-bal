<?php
// Envelope to request your custom TDL report
$xmlRequest = "
<ENVELOPE>
  <HEADER>
    <TALLYREQUEST>Export Data</TALLYREQUEST>
  </HEADER>
  <BODY>
    <EXPORTDATA>
      <REQUESTDESC>
        <REPORTNAME>LedgerwiseTrialBalanceFlat</REPORTNAME>
        <STATICVARIABLES>
          <SVFROMDATE>01-Apr-2025</SVFROMDATE>
          <SVTODATE>31-Mar-2026</SVTODATE>
          <SVEXPORTFORMAT>XML</SVEXPORTFORMAT>
        </STATICVARIABLES>
      </REQUESTDESC>
    </EXPORTDATA>
  </BODY>
</ENVELOPE>";

// Send request to Tally HTTP server
$ch = curl_init("http://127.0.0.1:9000");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

// Parse XML response
$xml = simplexml_load_string($response);

echo "<table border='1' cellpadding='5'>";
echo "<tr>
        <th>Ledger</th>
        <th>Opening (Dr/Cr)</th>
        <th>Closing (Dr/Cr)</th>
      </tr>";

// Loop through each LINE in the report
foreach ($xml->xpath("//LINE") as $line) {
    $ledger  = (string)$line->FldLedgerNameFlat;
    $opening = (string)$line->FldOpeningFlat;
    $closing = (string)$line->FldClosingFlat;

    echo "<tr>
            <td>{$ledger}</td>
            <td>{$opening}</td>
            <td>{$closing}</td>
          </tr>";
}

echo "</table>";
?>