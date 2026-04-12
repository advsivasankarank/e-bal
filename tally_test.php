<?php

$url = "http://127.0.0.1:9000";

/* SIMPLE LEDGER FETCH XML */
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

/* SHOW RAW RESPONSE */
echo "<pre>";
echo htmlspecialchars(substr($response, 0, 2000));
echo "</pre>";