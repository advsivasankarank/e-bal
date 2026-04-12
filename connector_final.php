<?php

/* ========================
   1. FETCH FROM TALLY
======================== */

$url = "http://127.0.0.1:9000";

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

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/xml"
]);

$response = curl_exec($ch);
curl_close($ch);

/* ========================
   2. SEND TO API
======================== */

$ch = curl_init("http://127.0.0.1/e-bal/api/receive_data.php");

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $response);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/xml"
]);

$result = curl_exec($ch);
curl_close($ch);

echo "<h3>Connector Result:</h3>";
echo $result;