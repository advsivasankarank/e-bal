<?php

function getTrialBalanceXML($from_date, $to_date)
{
    return '
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
}


function fetchTrialBalance($response)
{
    $xml = simplexml_load_string($response);

    if (!$xml) return [];

    $data = [];

    $names = $xml->DSPACCNAME;
    $infos = $xml->DSPACCINFO;

    $total = min(count($names), count($infos));

    for ($i = 0; $i < $total; $i++) {

        $name = (string)$names[$i]->DSPDISPNAME;

        $dr = (float)$infos[$i]->DSPCLDRAMT->DSPCLDRAMTA;
        $cr = (float)$infos[$i]->DSPCLCRAMT->DSPCLCRAMTA;

        $amount = $cr != 0 ? $cr : $dr;

        if ($amount == 0) continue;

        $data[] = [
            'name' => $name,
            'amount' => abs($amount)
        ];
    }

    return $data;
}